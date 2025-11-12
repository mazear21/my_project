<?php
// Database Debug & Analysis Tool
include 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Debug Tool</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f5f5f5; }
        h1 { color: #1e3a8a; }
        h2 { color: #3b82f6; margin-top: 30px; border-bottom: 2px solid #3b82f6; padding-bottom: 5px; }
        h3 { color: #6366f1; margin-top: 20px; }
        .table-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th { background: #3b82f6; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        tr:hover { background: #f0f9ff; }
        .count { background: #10b981; color: white; padding: 2px 8px; border-radius: 12px; font-weight: bold; }
        .warning { background: #f59e0b; color: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { background: #ef4444; color: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .success { background: #10b981; color: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { background: #3b82f6; color: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
        .unused { color: #ef4444; font-weight: bold; }
        .used { color: #10b981; font-weight: bold; }
    </style>
</head>
<body>
    <h1>🔍 Database Structure Analysis</h1>
    <p><strong>Database:</strong> student_db | <strong>Connection:</strong> <?= $conn ? '✅ Connected' : '❌ Failed' ?></p>

    <?php
    // Get all tables
    $tables_query = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename";
    $tables_result = pg_query($conn, $tables_query);
    
    echo "<h2>📊 All Tables in Database</h2>";
    echo "<div class='table-section'>";
    
    $all_tables = [];
    while ($table = pg_fetch_assoc($tables_result)) {
        $all_tables[] = $table['tablename'];
    }
    
    echo "<p><strong>Total Tables:</strong> " . count($all_tables) . "</p>";
    echo "<ul>";
    foreach ($all_tables as $table_name) {
        echo "<li><code>$table_name</code></li>";
    }
    echo "</ul>";
    echo "</div>";
    
    // Analyze each table
    echo "<h2>📋 Detailed Table Analysis</h2>";
    
    foreach ($all_tables as $table_name) {
        echo "<div class='table-section'>";
        echo "<h3>Table: <code>$table_name</code></h3>";
        
        // Get row count
        $count_query = "SELECT COUNT(*) as count FROM \"$table_name\"";
        $count_result = pg_query($conn, $count_query);
        $count = pg_fetch_assoc($count_result)['count'];
        echo "<p><strong>Row Count:</strong> <span class='count'>$count</span></p>";
        
        // Get columns
        $columns_query = "SELECT column_name, data_type, is_nullable, column_default 
                         FROM information_schema.columns 
                         WHERE table_name = '$table_name' 
                         ORDER BY ordinal_position";
        $columns_result = pg_query($conn, $columns_query);
        
        echo "<h4>Columns:</h4>";
        echo "<table>";
        echo "<tr><th>Column Name</th><th>Data Type</th><th>Nullable</th><th>Default</th></tr>";
        
        while ($col = pg_fetch_assoc($columns_result)) {
            $default = $col['column_default'] ?: 'None';
            echo "<tr>";
            echo "<td><code>{$col['column_name']}</code></td>";
            echo "<td>{$col['data_type']}</td>";
            echo "<td>{$col['is_nullable']}</td>";
            echo "<td>" . htmlspecialchars($default) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Show sample data if rows exist
        if ($count > 0 && $count <= 10) {
            echo "<h4>Sample Data (All $count rows):</h4>";
            $sample_query = "SELECT * FROM \"$table_name\" LIMIT 10";
            $sample_result = pg_query($conn, $sample_query);
            
            echo "<table>";
            // Header
            $fields = pg_num_fields($sample_result);
            echo "<tr>";
            for ($i = 0; $i < $fields; $i++) {
                echo "<th>" . pg_field_name($sample_result, $i) . "</th>";
            }
            echo "</tr>";
            
            // Data
            while ($row = pg_fetch_assoc($sample_result)) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars(substr($value, 0, 100)) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } elseif ($count > 10) {
            echo "<h4>Sample Data (First 5 rows):</h4>";
            $sample_query = "SELECT * FROM \"$table_name\" LIMIT 5";
            $sample_result = pg_query($conn, $sample_query);
            
            echo "<table>";
            // Header
            $fields = pg_num_fields($sample_result);
            echo "<tr>";
            for ($i = 0; $i < $fields; $i++) {
                echo "<th>" . pg_field_name($sample_result, $i) . "</th>";
            }
            echo "</tr>";
            
            // Data
            while ($row = pg_fetch_assoc($sample_result)) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars(substr($value, 0, 100)) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        
        echo "</div>";
    }
    
    // Check for foreign key relationships
    echo "<h2>🔗 Foreign Key Relationships</h2>";
    echo "<div class='table-section'>";
    $fk_query = "SELECT
        tc.table_name, 
        kcu.column_name, 
        ccu.table_name AS foreign_table_name,
        ccu.column_name AS foreign_column_name 
    FROM information_schema.table_constraints AS tc 
    JOIN information_schema.key_column_usage AS kcu
        ON tc.constraint_name = kcu.constraint_name
        AND tc.table_schema = kcu.table_schema
    JOIN information_schema.constraint_column_usage AS ccu
        ON ccu.constraint_name = tc.constraint_name
        AND ccu.table_schema = tc.table_schema
    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema='public'";
    
    $fk_result = pg_query($conn, $fk_query);
    
    if (pg_num_rows($fk_result) > 0) {
        echo "<table>";
        echo "<tr><th>Table</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
        while ($fk = pg_fetch_assoc($fk_result)) {
            echo "<tr>";
            echo "<td><code>{$fk['table_name']}</code></td>";
            echo "<td><code>{$fk['column_name']}</code></td>";
            echo "<td><code>{$fk['foreign_table_name']}</code></td>";
            echo "<td><code>{$fk['foreign_column_name']}</code></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ No foreign key constraints found. Tables may not have enforced relationships.</p>";
    }
    echo "</div>";
    
    // Analysis and Recommendations
    echo "<h2>💡 Analysis & Recommendations</h2>";
    echo "<div class='table-section'>";
    
    // Check for empty tables
    echo "<h3>Empty Tables:</h3>";
    $empty_tables = [];
    foreach ($all_tables as $table_name) {
        $count_query = "SELECT COUNT(*) as count FROM \"$table_name\"";
        $count_result = pg_query($conn, $count_query);
        $count = pg_fetch_assoc($count_result)['count'];
        if ($count == 0) {
            $empty_tables[] = $table_name;
        }
    }
    
    if (count($empty_tables) > 0) {
        echo "<p class='warning'>⚠️ Found " . count($empty_tables) . " empty table(s):</p>";
        echo "<ul>";
        foreach ($empty_tables as $empty) {
            echo "<li><code>$empty</code> - <span class='unused'>Unused (0 rows)</span></li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='success'>✅ All tables contain data!</p>";
    }
    
    // Check for missing student_subjects table
    echo "<h3>Missing Tables Check:</h3>";
    if (!in_array('student_subjects', $all_tables)) {
        echo "<p class='info'>ℹ️ <code>student_subjects</code> table does not exist. Student enrollment is managed through the <code>marks</code> table.</p>";
    }
    
    // Check teachers count
    echo "<h3>Teachers Table Analysis:</h3>";
    $teachers_count = pg_query($conn, "SELECT COUNT(*) as count FROM teachers");
    $teachers_num = pg_fetch_assoc($teachers_count)['count'];
    
    $teachers_with_login = pg_query($conn, "SELECT COUNT(*) as count FROM teachers WHERE username IS NOT NULL AND password IS NOT NULL");
    $teachers_login_num = pg_fetch_assoc($teachers_with_login)['count'];
    
    echo "<p>📊 <strong>Total Teachers:</strong> $teachers_num</p>";
    echo "<p>🔐 <strong>Teachers with Login:</strong> $teachers_login_num</p>";
    
    if ($teachers_num > 5) {
        echo "<p class='warning'>⚠️ You have $teachers_num teachers in the database. If you only expect 2, consider deleting the old/unused records.</p>";
    }
    
    echo "</div>";
    
    // Check for unused columns
    echo "<h2>🗑️ Potentially Unused Columns</h2>";
    echo "<div class='table-section'>";
    echo "<p>Checking for columns that might be unused based on NULL values...</p>";
    
    foreach ($all_tables as $table_name) {
        $count_query = "SELECT COUNT(*) as count FROM \"$table_name\"";
        $count_result = pg_query($conn, $count_query);
        $total_rows = pg_fetch_assoc($count_result)['count'];
        
        if ($total_rows > 0) {
            $columns_query = "SELECT column_name FROM information_schema.columns WHERE table_name = '$table_name'";
            $columns_result = pg_query($conn, $columns_query);
            
            $unused_cols = [];
            while ($col = pg_fetch_assoc($columns_result)) {
                $col_name = $col['column_name'];
                $null_count_query = "SELECT COUNT(*) as count FROM \"$table_name\" WHERE \"$col_name\" IS NULL";
                $null_count_result = pg_query($conn, $null_count_query);
                $null_count = pg_fetch_assoc($null_count_result)['count'];
                
                if ($null_count == $total_rows) {
                    $unused_cols[] = $col_name;
                }
            }
            
            if (count($unused_cols) > 0) {
                echo "<p><strong>Table <code>$table_name</code>:</strong></p>";
                echo "<ul>";
                foreach ($unused_cols as $ucol) {
                    echo "<li><code>$ucol</code> - <span class='unused'>All values are NULL</span></li>";
                }
                echo "</ul>";
            }
        }
    }
    
    echo "</div>";
    ?>
    
    <div class='table-section'>
        <h3>🎯 Summary</h3>
        <p><strong>Total Tables:</strong> <?= count($all_tables) ?></p>
        <p><strong>Empty Tables:</strong> <?= count($empty_tables) ?></p>
        <p><strong>Database Size:</strong> 
        <?php
        $size_query = "SELECT pg_size_pretty(pg_database_size('student_db')) as size";
        $size_result = pg_query($conn, $size_query);
        echo pg_fetch_assoc($size_result)['size'];
        ?>
        </p>
    </div>
</body>
</html>
