<?php
// Direct test of the AJAX endpoint to see what's happening
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>AJAX Endpoint Test</h1>";

// Simulate the exact AJAX call
$_GET['ajax'] = 'get_student';
$_GET['id'] = '18';

echo "<h2>Testing with Student ID: 18</h2>";
echo "<h3>Raw Output:</h3>";
echo "<pre>";

// Start output buffering to capture what the AJAX endpoint produces
ob_start();

// Include the main file (which will process the AJAX request)
include 'index.php';

// Get the captured output
$output = ob_get_clean();

echo htmlspecialchars($output);
echo "</pre>";

echo "<h3>Analysis:</h3>";
if (empty($output)) {
    echo "<p style='color: red;'>❌ No output from AJAX endpoint</p>";
} else if (json_decode($output) !== null) {
    echo "<p style='color: green;'>✅ Valid JSON response</p>";
    $data = json_decode($output, true);
    echo "<h4>Parsed Data:</h4>";
    echo "<pre>" . print_r($data, true) . "</pre>";
} else {
    echo "<p style='color: red;'>❌ Invalid JSON response</p>";
    echo "<p>First 200 characters: " . substr($output, 0, 200) . "</p>";
    
    // Check for common issues
    if (strpos($output, '<') !== false) {
        echo "<p style='color: orange;'>⚠️ Response contains HTML tags</p>";
    }
    if (strpos($output, 'error') !== false) {
        echo "<p style='color: orange;'>⚠️ Response contains error text</p>";
    }
}
?>