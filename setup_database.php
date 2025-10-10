<?php
echo "<h1>Database Setup & Connection Resolver</h1>";

// Try to find working connection
$working_conn = null;
$working_db = null;
$working_pass = null;

$databases = ['student_db', 'student_management_system', 'postgres'];
$passwords = ['0998', 'root', 'postgres', '', 'admin'];

echo "<h2>Testing Connections...</h2>";

foreach ($databases as $db) {
    foreach ($passwords as $pass) {
        echo "Testing $db with password '" . ($pass === '' ? '(empty)' : $pass) . "'... ";
        $conn = @pg_connect("host=localhost dbname=$db user=postgres password=$pass");
        if ($conn) {
            echo "<span style='color: green;'>✅ SUCCESS</span><br>";
            if (!$working_conn) {
                $working_conn = $conn;
                $working_db = $db;
                $working_pass = $pass;
            } else {
                pg_close($conn);
            }
        } else {
            echo "<span style='color: red;'>❌ Failed</span><br>";
        }
    }
}

if (!$working_conn) {
    echo "<h2 style='color: red;'>No Working Connection Found</h2>";
    echo "<p>Please check:</p>";
    echo "<ul>";
    echo "<li>PostgreSQL service is running</li>";
    echo "<li>Username and password are correct</li>";
    echo "<li>Database exists</li>";
    echo "</ul>";
    exit;
}

echo "<h2 style='color: green;'>✅ Working Connection Found!</h2>";
echo "<p><strong>Database:</strong> $working_db</p>";
echo "<p><strong>Password:</strong> " . ($working_pass === '' ? '(empty)' : $working_pass) . "</p>";

// Check if students table exists
echo "<h2>Checking Database Structure...</h2>";

$result = @pg_query($working_conn, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
if ($result) {
    $tables = [];
    while ($row = pg_fetch_assoc($result)) {
        $tables[] = $row['table_name'];
    }
    
    echo "<h3>Existing Tables:</h3>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // Check if we have students table
    if (in_array('students', $tables)) {
        echo "<h3>Students Table Found ✅</h3>";
        
        // Check students data
        $result = pg_query($working_conn, "SELECT COUNT(*) as count FROM students");
        if ($result) {
            $row = pg_fetch_assoc($result);
            echo "<p>Students count: {$row['count']}</p>";
            
            if ($row['count'] > 0) {
                echo "<h4>Sample Students:</h4>";
                $result = pg_query($working_conn, "SELECT id, name, email FROM students LIMIT 5");
                echo "<table border='1' cellpadding='5'>";
                echo "<tr><th>ID</th><th>Name</th><th>Email</th></tr>";
                while ($student = pg_fetch_assoc($result)) {
                    echo "<tr><td>{$student['id']}</td><td>{$student['name']}</td><td>{$student['email']}</td></tr>";
                }
                echo "</table>";
            }
        }
    } else {
        echo "<h3 style='color: red;'>Students Table NOT Found ❌</h3>";
        echo "<p>The database exists but doesn't have the students table.</p>";
    }
} else {
    echo "<p style='color: red;'>Could not check database structure</p>";
}

// Show the correct db.php content
echo "<h2>Correct db.php Configuration:</h2>";
echo "<pre style='background: #f0f0f0; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars("<?php\n\$conn = pg_connect(\"host=localhost dbname=$working_db user=postgres password=$working_pass\");\nif (!\$conn) {\n    die(\"Connection failed: \" . pg_last_error());\n}\n?>");
echo "</pre>";

echo "<h2>Action Required:</h2>";
if (in_array('students', $tables ?? [])) {
    echo "<p style='color: green;'>✅ Database is ready! Copy the db.php configuration above.</p>";
    
    // Update db.php automatically
    $db_content = "<?php\n\$conn = pg_connect(\"host=localhost dbname=$working_db user=postgres password=$working_pass\");\nif (!\$conn) {\n    die(\"Connection failed: \" . pg_last_error());\n}\n?>";
    
    if (file_put_contents('db.php', $db_content)) {
        echo "<p style='color: green;'>✅ db.php has been automatically updated!</p>";
        echo "<p><a href='index.php'>Test the student system now</a></p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Could not automatically update db.php. Please copy the configuration manually.</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ Database connected but missing student data. You may need to import your database or recreate tables.</p>";
}

if ($working_conn) {
    pg_close($working_conn);
}
?>