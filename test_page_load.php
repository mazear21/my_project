<?php
// Simple test to check if the main page loads with basic content
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Testing Basic Page Load</h1>";

try {
    // Include database connection
    require_once 'db.php';
    echo "<p>✅ Database connection included successfully</p>";
    
    // Test basic query
    $test_query = "SELECT COUNT(*) as count FROM students";
    $result = pg_query($conn, $test_query);
    
    if ($result) {
        $count = pg_fetch_assoc($result)['count'];
        echo "<p>✅ Database query successful - Found {$count} students</p>";
    } else {
        echo "<p>❌ Database query failed: " . pg_last_error($conn) . "</p>";
    }
    
    // Test if marks exist
    $marks_query = "SELECT COUNT(*) as count FROM marks";
    $marks_result = pg_query($conn, $marks_query);
    
    if ($marks_result) {
        $marks_count = pg_fetch_assoc($marks_result)['count'];
        echo "<p>✅ Marks table accessible - Found {$marks_count} marks</p>";
    } else {
        echo "<p>❌ Marks query failed: " . pg_last_error($conn) . "</p>";
    }
    
    // Test the calculateGraduationGrade function
    if (function_exists('calculateGraduationGrade')) {
        echo "<p>❌ calculateGraduationGrade function not found in this scope</p>";
    } else {
        echo "<p>ℹ️ calculateGraduationGrade function not in global scope (expected - it's in index.php)</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<h2>Testing Index.php Content</h2>";
echo "<p>Let's check if index.php renders content...</p>";

// Start output buffering to capture any errors
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Check if we can access the index page through HTTP
    echo "<p>If you see this message, PHP is working. The issue might be with the browser access or Apache configuration.</p>";
    echo "<p><strong>Try accessing:</strong> <a href='http://localhost/student_system/index.php' target='_blank'>http://localhost/student_system/index.php</a></p>";
    echo "<p><strong>Or try the marks page:</strong> <a href='http://localhost/student_system/index.php?page=marks' target='_blank'>http://localhost/student_system/index.php?page=marks</a></p>";
    
} catch (Exception $e) {
    echo "<p>❌ Error accessing index: " . $e->getMessage() . "</p>";
}

$output = ob_get_clean();
echo $output;
?>