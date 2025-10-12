<?php
// Test if the main index.php generates any output at all
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Testing Main Index.php Output</h1>";

// Capture any output from index.php
ob_start();

// Set error handler to catch any errors
set_error_handler(function($severity, $message, $file, $line) {
    echo "<p style='color: red;'>❌ PHP Error: {$message} in {$file} line {$line}</p>";
});

try {
    // Test if we can include index.php
    $start_time = microtime(true);
    
    // We'll use curl to test the actual HTTP response
    $url = 'http://localhost/student_system/index.php';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    
    echo "<h2>📊 HTTP Response Test</h2>";
    echo "<p>HTTP Status Code: {$http_code}</p>";
    echo "<p>Response Time: " . number_format($total_time * 1000, 2) . " ms</p>";
    
    if ($http_code == 200) {
        echo "<p>✅ HTTP request successful</p>";
        
        // Check response length
        $response_length = strlen($response);
        echo "<p>Response Length: {$response_length} bytes</p>";
        
        if ($response_length > 0) {
            echo "<p>✅ Response contains data</p>";
            
            // Check for common HTML elements
            if (strpos($response, '<!DOCTYPE html') !== false) {
                echo "<p>✅ Contains DOCTYPE declaration</p>";
            } else {
                echo "<p>❌ Missing DOCTYPE declaration</p>";
            }
            
            if (strpos($response, '<html') !== false) {
                echo "<p>✅ Contains HTML tag</p>";
            } else {
                echo "<p>❌ Missing HTML tag</p>";
            }
            
            if (strpos($response, '<body') !== false) {
                echo "<p>✅ Contains BODY tag</p>";
            } else {
                echo "<p>❌ Missing BODY tag</p>";
            }
            
            // Check for dashboard content
            if (strpos($response, 'Analytics Dashboard') !== false) {
                echo "<p>✅ Contains dashboard content</p>";
            } else {
                echo "<p>❌ Missing dashboard content</p>";
            }
            
            // Check for PHP errors in response
            if (strpos($response, 'Fatal error') !== false || strpos($response, 'Parse error') !== false) {
                echo "<p>❌ Response contains PHP errors</p>";
                // Show the error part
                $lines = explode("\n", $response);
                foreach ($lines as $line) {
                    if (strpos($line, 'Fatal error') !== false || strpos($line, 'Parse error') !== false) {
                        echo "<p style='color: red; background: #ffe6e6; padding: 10px;'>{$line}</p>";
                    }
                }
            } else {
                echo "<p>✅ No PHP errors detected in response</p>";
            }
            
            // Show first 500 characters of response for debugging
            echo "<h3>📄 Response Preview (first 500 chars):</h3>";
            echo "<textarea style='width: 100%; height: 200px;'>" . htmlspecialchars(substr($response, 0, 500)) . "</textarea>";
            
        } else {
            echo "<p>❌ Empty response</p>";
        }
    } else {
        echo "<p>❌ HTTP request failed with code: {$http_code}</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Exception: " . $e->getMessage() . "</p>";
}

echo "<h2>🔧 Debugging Steps</h2>";
echo "<ol>";
echo "<li>Check if the response contains actual HTML content</li>";
echo "<li>Look for PHP error messages in the response</li>";
echo "<li>Check browser developer tools for JavaScript errors</li>";
echo "<li>Try accessing the page directly: <a href='http://localhost/student_system/index.php' target='_blank'>http://localhost/student_system/index.php</a></li>";
echo "</ol>";
?>