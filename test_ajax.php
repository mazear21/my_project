<?php
include("db.php");

// Test the get_student AJAX endpoint directly
if(isset($_GET['test_student_id'])) {
    $student_id = (int)$_GET['test_student_id'];
    
    echo "<h2>Testing get_student endpoint for ID: $student_id</h2>";
    
    $result = pg_query($conn, "SELECT * FROM students WHERE id = $student_id");
    if($result && pg_num_rows($result) > 0) {
        $student = pg_fetch_assoc($result);
        
        echo "<h3>Raw Database Data:</h3>";
        echo "<pre>";
        print_r($student);
        echo "</pre>";
        
        // Get enrolled subjects
        $subjects_result = pg_query($conn, "SELECT subject_id FROM student_subjects WHERE student_id = $student_id");
        $enrolled_subjects = [];
        if($subjects_result) {
            while($row = pg_fetch_assoc($subjects_result)) {
                $enrolled_subjects[] = (int)$row['subject_id'];
            }
        }
        $student['enrolled_subjects'] = $enrolled_subjects;
        
        echo "<h3>Final JSON Data (what AJAX returns):</h3>";
        echo "<pre>";
        echo json_encode($student, JSON_PRETTY_PRINT);
        echo "</pre>";
        
        echo "<h3>Test AJAX Endpoint:</h3>";
        echo '<button onclick="testAjax(' . $student_id . ')">Test AJAX Call</button>';
        echo '<div id="ajax-result"></div>';
        
        echo '<script>
        function testAjax(id) {
            fetch("index.php?ajax=get_student&id=" + id)
                .then(response => response.text())
                .then(data => {
                    document.getElementById("ajax-result").innerHTML = "<h4>AJAX Response:</h4><pre>" + data + "</pre>";
                    console.log("AJAX Response:", data);
                })
                .catch(error => {
                    document.getElementById("ajax-result").innerHTML = "<h4>AJAX Error:</h4>" + error;
                });
        }
        </script>';
        
    } else {
        echo "<p>Student not found</p>";
    }
} else {
    echo "<h2>Enter Student ID to Test:</h2>";
    echo '<form method="GET">';
    echo '<input type="number" name="test_student_id" placeholder="Student ID" required>';
    echo '<button type="submit">Test</button>';
    echo '</form>';
    
    // Show available students
    echo "<h3>Available Students:</h3>";
    $result = pg_query($conn, "SELECT id, name, email FROM students ORDER BY id");
    if($result && pg_num_rows($result) > 0) {
        echo "<ul>";
        while($row = pg_fetch_assoc($result)) {
            echo "<li><a href='?test_student_id=" . $row['id'] . "'>ID: " . $row['id'] . " - " . htmlspecialchars($row['name']) . " (" . htmlspecialchars($row['email']) . ")</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No students found</p>";
    }
}
?>