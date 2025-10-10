<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db.php");

echo "<h2>Database Connection Test</h2>";

if ($conn) {
    echo "✅ Database connected successfully<br>";
    
    // Test basic queries
    echo "<h3>Testing Tables:</h3>";
    
    // Test students table
    $students = pg_query($conn, "SELECT COUNT(*) as count FROM students");
    if ($students) {
        $student_count = pg_fetch_assoc($students)['count'];
        echo "✅ Students table: $student_count records<br>";
    } else {
        echo "❌ Students table error: " . pg_last_error($conn) . "<br>";
    }
    
    // Test subjects table
    $subjects = pg_query($conn, "SELECT COUNT(*) as count FROM subjects");
    if ($subjects) {
        $subject_count = pg_fetch_assoc($subjects)['count'];
        echo "✅ Subjects table: $subject_count records<br>";
    } else {
        echo "❌ Subjects table error: " . pg_last_error($conn) . "<br>";
    }
    
    // Test marks table
    $marks = pg_query($conn, "SELECT COUNT(*) as count FROM marks");
    if ($marks) {
        $mark_count = pg_fetch_assoc($marks)['count'];
        echo "✅ Marks table: $mark_count records<br>";
    } else {
        echo "❌ Marks table error: " . pg_last_error($conn) . "<br>";
    }
    
    // Test class_level query
    $classes = pg_query($conn, "SELECT DISTINCT class_level FROM students ORDER BY class_level");
    if ($classes) {
        echo "✅ Class levels query successful<br>";
        while($class = pg_fetch_assoc($classes)) {
            echo "  - " . $class['class_level'] . "<br>";
        }
    } else {
        echo "❌ Class levels query error: " . pg_last_error($conn) . "<br>";
    }
    
} else {
    echo "❌ Database connection failed<br>";
}

?>