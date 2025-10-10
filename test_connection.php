<?php
echo "<h1>PostgreSQL Connection Test</h1>";

// Test different combinations
$databases = ['student_management_system', 'student_db', 'postgres'];
$passwords = ['root', '0998', 'postgres', '', 'admin', 'password', '123456'];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Database</th><th>Password</th><th>Status</th><th>Action</th></tr>";

foreach ($databases as $db) {
    foreach ($passwords as $pass) {
        $conn = @pg_connect("host=localhost dbname=$db user=postgres password=$pass");
        if ($conn) {
            echo "<tr style='background: lightgreen;'>";
            echo "<td>$db</td>";
            echo "<td>" . ($pass === '' ? '(empty)' : $pass) . "</td>";
            echo "<td>✅ SUCCESS</td>";
            echo "<td><button onclick=\"useThisConnection('$db', '$pass')\">Use This</button></td>";
            echo "</tr>";
            pg_close($conn);
        } else {
            echo "<tr style='background: lightcoral;'>";
            echo "<td>$db</td>";
            echo "<td>" . ($pass === '' ? '(empty)' : $pass) . "</td>";
            echo "<td>❌ Failed</td>";
            echo "<td>-</td>";
            echo "</tr>";
        }
    }
}

echo "</table>";

echo "<br><h2>Manual Test</h2>";
echo "<form method='POST'>";
echo "Database: <input type='text' name='manual_db' value='student_management_system'><br><br>";
echo "Password: <input type='text' name='manual_pass' value=''><br><br>";
echo "<input type='submit' name='test_manual' value='Test Connection'>";
echo "</form>";

if (isset($_POST['test_manual'])) {
    $db = $_POST['manual_db'];
    $pass = $_POST['manual_pass'];
    
    echo "<h3>Testing: Database='$db', Password='" . ($pass === '' ? '(empty)' : $pass) . "'</h3>";
    
    $conn = @pg_connect("host=localhost dbname=$db user=postgres password=$pass");
    if ($conn) {
        echo "<p style='color: green;'>✅ CONNECTION SUCCESSFUL!</p>";
        echo "<p>To use this connection, update db.php with:</p>";
        echo "<pre>\$conn = pg_connect(\"host=localhost dbname=$db user=postgres password=$pass\");</pre>";
        pg_close($conn);
    } else {
        echo "<p style='color: red;'>❌ Connection failed</p>";
    }
}
?>

<script>
function useThisConnection(db, pass) {
    if (confirm('Update db.php to use database "' + db + '" with password "' + (pass === '' ? '(empty)' : pass) + '"?')) {
        // You can implement AJAX call here to update the file
        alert('Please manually update db.php with these credentials:\nDatabase: ' + db + '\nPassword: ' + (pass === '' ? '(empty)' : pass));
    }
}
</script>