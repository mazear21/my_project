<?php
include 'db.php';

echo "<h1>Database Reset Tool</h1>";

if (isset($_POST['confirm_reset'])) {
    echo "<h2>Resetting Database...</h2>";
    
    try {
        // Clear all data
        $queries = [
            "DELETE FROM marks",
            "DELETE FROM student_subjects", 
            "DELETE FROM students",
            "DELETE FROM subjects",
            
            // Reset sequences to start from 1
            "ALTER SEQUENCE students_id_seq RESTART WITH 1",
            "ALTER SEQUENCE subjects_id_seq RESTART WITH 1"
        ];
        
        foreach ($queries as $query) {
            $result = pg_query($conn, $query);
            if ($result) {
                echo "<p style='color: green;'>✅ " . htmlspecialchars($query) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ " . htmlspecialchars($query) . " - " . pg_last_error($conn) . "</p>";
            }
        }
        
        echo "<h2 style='color: green;'>✅ Database Reset Complete!</h2>";
        echo "<p>All IDs will now start from 1 when you add new records.</p>";
        echo "<p><a href='index.php'>Go to Student System</a></p>";
        
    } catch (Exception $e) {
        echo "<h2 style='color: red;'>❌ Error: " . $e->getMessage() . "</h2>";
    }
} else {
    echo "<h2>⚠️ Warning: This will delete ALL data!</h2>";
    echo "<p>This action will:</p>";
    echo "<ul>";
    echo "<li>Delete all students, subjects, enrollments, and marks</li>";
    echo "<li>Reset all ID sequences to start from 1</li>";
    echo "<li>Cannot be undone!</li>";
    echo "</ul>";
    
    echo "<form method='POST'>";
    echo "<p><input type='checkbox' id='confirm' required> I understand this will delete all data</p>";
    echo "<button type='submit' name='confirm_reset' style='background: #e74c3c; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>🗑️ Reset Database</button>";
    echo "</form>";
}
?>