<?php
// Simple test to check if dashboard content is rendering
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Dashboard Test</title></head><body>";
echo "<h1>🔍 Dashboard Content Test</h1>";

try {
    require_once 'db.php';
    echo "<p>✅ Database connected</p>";
    
    // Simulate the page parameter logic
    $page = $_GET['page'] ?? 'reports';
    echo "<p>📄 Current page: {$page}</p>";
    
    // Test if we're on reports page
    if ($page == 'reports') {
        echo "<p>✅ On reports/dashboard page</p>";
        
        // Try to include the generateChartData function and test it
        // First, let's test without the full function
        echo "<h2>Basic Dashboard Content Test</h2>";
        echo "<div style='background: #f0f8ff; padding: 20px; border: 1px solid #ccc; margin: 10px 0;'>";
        echo "<h3>📊 Analytics Dashboard</h3>";
        echo "<p>Comprehensive Student Performance Overview</p>";
        
        // Basic KPI cards (hardcoded for testing)
        echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 20px 0;'>";
        
        echo "<div style='background: white; padding: 1rem; border-radius: 6px; border-left: 4px solid #3498db;'>";
        echo "<div style='font-size: 2rem; font-weight: bold; color: #3498db;'>1</div>";
        echo "<div>Year 1 Students</div>";
        echo "</div>";
        
        echo "<div style='background: white; padding: 1rem; border-radius: 6px; border-left: 4px solid #e74c3c;'>";
        echo "<div style='font-size: 2rem; font-weight: bold; color: #e74c3c;'>1</div>";
        echo "<div>Year 2 Students</div>";
        echo "</div>";
        
        echo "<div style='background: white; padding: 1rem; border-radius: 6px; border-left: 4px solid #27ae60;'>";
        echo "<div style='font-size: 2rem; font-weight: bold; color: #27ae60;'>32.34%</div>";
        echo "<div>Graduation Grade</div>";
        echo "</div>";
        
        echo "</div>";
        echo "</div>";
        
        echo "<p>✅ Basic dashboard content rendered</p>";
        
        // Test basic database query
        $student_count = 0;
        $marks_count = 0;
        
        $result = pg_query($conn, "SELECT COUNT(*) as count FROM students");
        if ($result) {
            $student_count = pg_fetch_assoc($result)['count'];
        }
        
        $result = pg_query($conn, "SELECT COUNT(*) as count FROM marks");
        if ($result) {
            $marks_count = pg_fetch_assoc($result)['count'];
        }
        
        echo "<h2>📊 Real Data</h2>";
        echo "<p>Students: {$student_count}</p>";
        echo "<p>Marks: {$marks_count}</p>";
        
        if ($student_count > 0 && $marks_count > 0) {
            echo "<p>✅ Data is available for dashboard</p>";
        } else {
            echo "<p>❌ No data available for dashboard</p>";
        }
        
    } else {
        echo "<p>ℹ️ Not on dashboard page</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<h2>🔗 Navigation Links</h2>";
echo "<ul>";
echo "<li><a href='test_dashboard_simple.php'>This Test Page</a></li>";
echo "<li><a href='index.php'>Main Dashboard</a></li>";
echo "<li><a href='index.php?page=reports'>Dashboard (explicit)</a></li>";
echo "<li><a href='index.php?page=marks'>Marks Page</a></li>";
echo "<li><a href='index.php?page=students'>Students Page</a></li>";
echo "</ul>";

echo "<h2>💡 Debugging Tips</h2>";
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>If the main dashboard is still blank, try:</strong></p>";
echo "<ul>";
echo "<li>1. Check browser developer console for JavaScript errors</li>";
echo "<li>2. Try Ctrl+F5 to hard refresh the page</li>";
echo "<li>3. Check if any CSS/JavaScript files are blocking the content</li>";
echo "<li>4. View page source to see if HTML is being generated</li>";
echo "</ul>";
echo "</div>";

echo "</body></html>";
?>