<?php
// Simple test page that should definitely work
echo "<!DOCTYPE html>";
echo "<html>";
echo "<head><title>Simple Test Page</title></head>";
echo "<body>";
echo "<h1>✅ PHP is Working!</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test database connection
try {
    require_once 'db.php';
    echo "<p>✅ Database connection working</p>";
    
    $result = pg_query($conn, "SELECT COUNT(*) as count FROM students");
    if ($result) {
        $count = pg_fetch_assoc($result)['count'];
        echo "<p>✅ Database query working - {$count} students found</p>";
    }
    
    pg_close($conn);
} catch (Exception $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>Links to Test:</h2>";
echo "<ul>";
echo "<li><a href='index.php'>Main Index Page</a></li>";
echo "<li><a href='index.php?page=marks'>Marks Page</a></li>";
echo "<li><a href='index.php?page=reports'>Reports Page</a></li>";
echo "</ul>";

echo "</body>";
echo "</html>";
?>