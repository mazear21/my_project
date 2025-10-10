<?php
include("db.php");

echo "Looking for student mariwan:\n\n";

$result = pg_query($conn, "SELECT * FROM students WHERE name LIKE '%mariwan%' OR email LIKE '%mariwan%'");
if($result && pg_num_rows($result) > 0) {
    while($row = pg_fetch_assoc($result)) {
        echo "Found student:\n";
        print_r($row);
    }
} else {
    echo "No student found with 'mariwan' in name or email\n";
    
    // Show all students
    echo "\nAll students:\n";
    $result = pg_query($conn, "SELECT id, name, email FROM students ORDER BY id DESC LIMIT 5");
    while($row = pg_fetch_assoc($result)) {
        echo "ID: " . $row['id'] . " - Name: " . $row['name'] . " - Email: " . $row['email'] . "\n";
    }
}
?>