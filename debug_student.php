<?php
include("db.php");

echo "<h2>Student Table Debug</h2>";

// Check table structure
echo "<h3>Table Structure:</h3>";
$result = pg_query($conn, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position");
echo "<table border='1'><tr><th>Column</th><th>Type</th></tr>";
while($row = pg_fetch_assoc($result)) {
    echo "<tr><td>" . $row['column_name'] . "</td><td>" . $row['data_type'] . "</td></tr>";
}
echo "</table>";

// Check sample data
echo "<h3>Sample Student Data:</h3>";
$result = pg_query($conn, "SELECT * FROM students LIMIT 3");
if($result && pg_num_rows($result) > 0) {
    echo "<table border='1'>";
    $first = true;
    while($row = pg_fetch_assoc($result)) {
        if($first) {
            echo "<tr>";
            foreach(array_keys($row) as $col) {
                echo "<th>$col</th>";
            }
            echo "</tr>";
            $first = false;
        }
        echo "<tr>";
        foreach($row as $value) {
            echo "<td>" . htmlspecialchars($value ?: 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No students found</p>";
}

// Test specific student
if(isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    echo "<h3>Student ID $id Details:</h3>";
    $result = pg_query($conn, "SELECT * FROM students WHERE id = $id");
    if($result && pg_num_rows($result) > 0) {
        $student = pg_fetch_assoc($result);
        echo "<pre>";
        print_r($student);
        echo "</pre>";
    } else {
        echo "<p>Student not found</p>";
    }
}
?>