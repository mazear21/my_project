<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Direct KPI Test</h1>";

// Include database connection
include 'db.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>✅ Database Connected</h2>";

// Test the getKPIs function directly
echo "<h2>Testing getKPIs Function:</h2>";

// First, let's make sure the function is available
if (function_exists('getKPIs')) {
    echo "<p>✅ getKPIs function exists</p>";
} else {
    echo "<p style='color: red;'>❌ getKPIs function not found - need to extract it</p>";
    
    // Define the function here for testing
    function getKPIs($conn, $filter_year = null) {
        // Build year filter condition
        $year_filter = $filter_year ? "AND s.year = $filter_year" : "";
        $year_filter_marks = $filter_year ? "AND s.year = $filter_year" : "";
        
        // Get student counts by year and status
        $student_stats = pg_query($conn, "
            SELECT 
                year,
                status,
                COUNT(*) as count
            FROM students 
            WHERE status = 'active' " . ($filter_year ? "AND year = $filter_year" : "") . "
            GROUP BY year, status
            ORDER BY year, status
        ");
        
        $year1_active = 0;
        $year2_active = 0;
        $graduated_count = 0;
        $total_active = 0;
        
        if ($student_stats && pg_num_rows($student_stats) > 0) {
            while($stat = pg_fetch_assoc($student_stats)) {
                if ($stat['status'] == 'active') {
                    $total_active += (int)$stat['count'];
                    if ($stat['year'] == 1) {
                        $year1_active = (int)$stat['count'];
                    } elseif ($stat['year'] == 2) {
                        $year2_active = (int)$stat['count'];
                    }
                } elseif ($stat['status'] == 'graduated') {
                    $graduated_count += (int)$stat['count'];
                }
            }
        }
        
        // For filtered year, adjust the counts
        if ($filter_year) {
            $total_active = $filter_year == 1 ? $year1_active : $year2_active;
            if ($filter_year == 1) $year2_active = 0;
            if ($filter_year == 2) $year1_active = 0;
        }
        
        $total_subjects = pg_query($conn, "SELECT COUNT(*) as count FROM subjects" . ($filter_year ? " WHERE year = $filter_year" : ""));
        $total_subjects_count = pg_fetch_assoc($total_subjects)['count'];
        
        $avg_score = pg_query($conn, "
            SELECT ROUND(AVG(m.mark), 1) as avg 
            FROM marks m 
            JOIN students s ON m.student_id = s.id 
            WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
        ");
        $avg_score_value = pg_fetch_assoc($avg_score)['avg'] ?? 0;
        
        return [
            'total_students' => $total_active,
            'year1_students' => $year1_active,
            'year2_students' => $year2_active,
            'graduated_students' => $graduated_count,
            'total_subjects' => $total_subjects_count,
            'avg_score' => $avg_score_value,
            'top_class' => 'N/A',
            'top_class_score' => 0,
            'pass_rate' => 0,
            'risk_subject' => 'N/A',
            'risk_failure_rate' => 0,
            'enrolled_students' => 0,
            'excellence_rate' => 0
        ];
    }
}

// Test All Years
echo "<h3>Testing All Years:</h3>";
try {
    $kpis_all = getKPIs($conn, null);
    echo "<pre>";
    print_r($kpis_all);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Test Year 1
echo "<h3>Testing Year 1:</h3>";
try {
    $kpis_year1 = getKPIs($conn, 1);
    echo "<pre>";
    print_r($kpis_year1);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Test Year 2
echo "<h3>Testing Year 2:</h3>";
try {
    $kpis_year2 = getKPIs($conn, 2);
    echo "<pre>";
    print_r($kpis_year2);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Test the actual AJAX endpoint
echo "<h2>Testing AJAX Endpoint Simulation:</h2>";

// Simulate POST request
$_POST['ajax'] = 'get_kpis';
$_POST['filter_year'] = '1';

echo "<h3>Simulating Year 1 AJAX Request:</h3>";
ob_start();
try {
    $kpis = getKPIs($conn, 1);
    $response = ['success' => true, 'kpis' => $kpis];
    echo json_encode($response);
} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
    echo json_encode($response);
}
$ajax_output = ob_get_clean();

echo "<p><strong>AJAX Response:</strong></p>";
echo "<pre style='background: #f0f0f0; padding: 10px;'>" . htmlspecialchars($ajax_output) . "</pre>";

pg_close($conn);
?>