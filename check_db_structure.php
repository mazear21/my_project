<?php
echo "<h1>Database Structure Analysis</h1>";

// Include database connection
include 'db.php';

if (!$conn) {
    die("Could not connect to database");
}

echo "<h2>✅ Database Connected Successfully</h2>";

// Check students table structure
echo "<h2>Students Table Structure:</h2>";
$result = pg_query($conn, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position");

if ($result) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr style='background: #f0f0f0;'><th>Column Name</th><th>Data Type</th><th>Nullable</th></tr>";
    
    $has_year_column = false;
    while ($row = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td><strong>{$row['column_name']}</strong></td>";
        echo "<td>{$row['data_type']}</td>";
        echo "<td>{$row['is_nullable']}</td>";
        echo "</tr>";
        
        if ($row['column_name'] === 'year') {
            $has_year_column = true;
        }
    }
    echo "</table>";
    
    if (!$has_year_column) {
        echo "<p style='color: red; font-weight: bold;'>❌ ISSUE FOUND: 'year' column is missing from students table!</p>";
        echo "<p>The KPI filtering requires a 'year' column but it doesn't exist.</p>";
        
        echo "<h3>Solution: Add 'year' column to students table</h3>";
        echo "<pre style='background: #f0f0f0; padding: 10px;'>";
        echo "ALTER TABLE students ADD COLUMN year INTEGER DEFAULT 1;";
        echo "</pre>";
        
        echo "<p><a href='?fix_db=1' style='background: #007cba; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>🔧 Fix Database Structure Now</a></p>";
    } else {
        echo "<p style='color: green; font-weight: bold;'>✅ 'year' column exists in students table</p>";
        
        // Check sample data
        echo "<h3>Sample students with year data:</h3>";
        $sample = pg_query($conn, "SELECT id, name, year, status FROM students LIMIT 10");
        if ($sample && pg_num_rows($sample) > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Name</th><th>Year</th><th>Status</th></tr>";
            while ($student = pg_fetch_assoc($sample)) {
                echo "<tr>";
                echo "<td>{$student['id']}</td>";
                echo "<td>{$student['name']}</td>";
                echo "<td><strong>{$student['year']}</strong></td>";
                echo "<td>{$student['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
} else {
    echo "<p style='color: red;'>Could not retrieve students table structure</p>";
}

// Check subjects table structure
echo "<h2>Subjects Table Structure:</h2>";
$result = pg_query($conn, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'subjects' ORDER BY ordinal_position");

if ($result) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr style='background: #f0f0f0;'><th>Column Name</th><th>Data Type</th><th>Nullable</th></tr>";
    
    $has_year_column_subjects = false;
    while ($row = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td><strong>{$row['column_name']}</strong></td>";
        echo "<td>{$row['data_type']}</td>";
        echo "<td>{$row['is_nullable']}</td>";
        echo "</tr>";
        
        if ($row['column_name'] === 'year') {
            $has_year_column_subjects = true;
        }
    }
    echo "</table>";
    
    if (!$has_year_column_subjects) {
        echo "<p style='color: red; font-weight: bold;'>❌ ISSUE FOUND: 'year' column is missing from subjects table!</p>";
        echo "<p>The filtering requires a 'year' column in subjects table but it doesn't exist.</p>";
    } else {
        echo "<p style='color: green; font-weight: bold;'>✅ 'year' column exists in subjects table</p>";
    }
}

// Auto-fix database if requested
if (isset($_GET['fix_db']) && $_GET['fix_db'] == '1') {
    echo "<h2>🔧 Fixing Database Structure...</h2>";
    
    // Add year column to students table if it doesn't exist
    $fix_students = pg_query($conn, "
        DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'students' AND column_name = 'year') THEN
                ALTER TABLE students ADD COLUMN year INTEGER DEFAULT 1;
            END IF;
        END $$;
    ");
    
    if ($fix_students) {
        echo "<p style='color: green;'>✅ Added 'year' column to students table</p>";
        
        // Update existing students with reasonable year values based on class_level
        $update_years = pg_query($conn, "
            UPDATE students 
            SET year = CASE 
                WHEN class_level ILIKE '%A%' OR class_level ILIKE '%1%' THEN 1
                WHEN class_level ILIKE '%B%' OR class_level ILIKE '%2%' THEN 2
                ELSE 1
            END
            WHERE year IS NULL OR year = 0
        ");
        
        if ($update_years) {
            echo "<p style='color: green;'>✅ Updated existing students with year values based on class_level</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Failed to add year column to students table</p>";
    }
    
    // Add year column to subjects table if it doesn't exist
    $fix_subjects = pg_query($conn, "
        DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'subjects' AND column_name = 'year') THEN
                ALTER TABLE subjects ADD COLUMN year INTEGER DEFAULT 1;
            END IF;
        END $$;
    ");
    
    if ($fix_subjects) {
        echo "<p style='color: green;'>✅ Added 'year' column to subjects table</p>";
        
        // Update existing subjects with reasonable year values
        $update_subject_years = pg_query($conn, "
            UPDATE subjects 
            SET year = CASE 
                WHEN subject_name ILIKE '%advanced%' OR subject_name ILIKE '%II%' OR subject_name ILIKE '%2%' THEN 2
                ELSE 1
            END
            WHERE year IS NULL OR year = 0
        ");
        
        if ($update_subject_years) {
            echo "<p style='color: green;'>✅ Updated existing subjects with year values</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Failed to add year column to subjects table</p>";
    }
    
    echo "<h3>🎉 Database Fix Complete!</h3>";
    echo "<p><a href='index.php?page=reports' style='background: #28a745; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>Test Year Filtering Now</a></p>";
}

echo "<h2>Current Database Tables:</h2>";
$tables_result = pg_query($conn, "SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
if ($tables_result) {
    echo "<ul>";
    while ($table = pg_fetch_assoc($tables_result)) {
        echo "<li>{$table['tablename']}</li>";
    }
    echo "</ul>";
}

pg_close($conn);
?>