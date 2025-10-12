<?php
// Test the specific sections that might be causing issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';

echo "<h1>Testing Graduation Grade Function Issues</h1>";

// Let's test just the marks table query without the graduation grade calculation first
echo "<h2>1. Testing Basic Marks Query (without graduation calculation)</h2>";

$basic_marks_query = "
    SELECT 
        m.*,
        s.name as student_name,
        s.year as student_year,
        s.status as student_status,
        s.class_level as student_class,
        sub.subject_name,
        sub.year as subject_year,
        CASE 
            WHEN m.mark >= 90 THEN 'A+'
            WHEN m.mark >= 80 THEN 'A'
            WHEN m.mark >= 70 THEN 'B'
            WHEN m.mark >= 50 THEN 'C'
            ELSE 'F'
        END as grade
    FROM marks m
    JOIN students s ON m.student_id = s.id
    JOIN subjects sub ON m.subject_id = sub.id
    ORDER BY m.id
    LIMIT 3
";

$basic_result = pg_query($conn, $basic_marks_query);

if ($basic_result && pg_num_rows($basic_result) > 0) {
    echo "<p>✅ Basic marks query successful</p>";
    echo "<table border='1'><tr><th>Student</th><th>Subject</th><th>Final Grade (DB)</th></tr>";
    while($row = pg_fetch_assoc($basic_result)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['student_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['subject_name']) . "</td>";
        echo "<td>" . number_format($row['final_grade'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>❌ Basic marks query failed: " . pg_last_error($conn) . "</p>";
}

// Now let's test the calculateGraduationGrade function by copying it here
echo "<h2>2. Testing Graduation Grade Function</h2>";

function calculateGraduationGrade($conn, $student_id) {
    try {
        // Calculate Year 1 final grade
        $year1_query = "
            SELECT 
                SUM(m.final_grade) as year_final_grade,
                COUNT(m.id) as completed_subjects,
                COUNT(s.id) as total_subjects
            FROM subjects s
            LEFT JOIN marks m ON s.id = m.subject_id AND m.student_id = $1
            WHERE s.year = 1
        ";
        $year1_result = pg_query_params($conn, $year1_query, array($student_id));
        $year1_data = pg_fetch_assoc($year1_result);
        
        // Calculate Year 2 final grade
        $year2_query = "
            SELECT 
                SUM(m.final_grade) as year_final_grade,
                COUNT(m.id) as completed_subjects,
                COUNT(s.id) as total_subjects
            FROM subjects s
            LEFT JOIN marks m ON s.id = m.subject_id AND m.student_id = $1
            WHERE s.year = 2
        ";
        $year2_result = pg_query_params($conn, $year2_query, array($student_id));
        $year2_data = pg_fetch_assoc($year2_result);
        
        $year1_grade = floatval($year1_data['year_final_grade']);
        $year2_grade = floatval($year2_data['year_final_grade']);
        
        return [
            'success' => true,
            'year1_grade' => $year1_grade,
            'year2_grade' => $year2_grade,
            'year1_completed' => intval($year1_data['completed_subjects']),
            'year2_completed' => intval($year2_data['completed_subjects']),
            'year1_total' => intval($year1_data['total_subjects']),
            'year2_total' => intval($year2_data['total_subjects'])
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Test the function
$test_student_id = 1; // Ali Salahadin
$graduation_result = calculateGraduationGrade($conn, $test_student_id);

if ($graduation_result['success']) {
    echo "<p>✅ Graduation grade calculation successful</p>";
    echo "<p>Year 1 Grade: " . $graduation_result['year1_grade'] . "</p>";
    echo "<p>Year 2 Grade: " . $graduation_result['year2_grade'] . "</p>";
    echo "<p>Total: " . ($graduation_result['year1_grade'] + $graduation_result['year2_grade']) . "</p>";
} else {
    echo "<p>❌ Graduation grade calculation failed: " . $graduation_result['error'] . "</p>";
}

// Now test the combined query
echo "<h2>3. Testing Combined Query (as in updated index.php)</h2>";

$combined_query = "
    SELECT 
        m.*,
        s.name as student_name,
        s.year as student_year,
        s.status as student_status,
        s.class_level as student_class,
        sub.subject_name,
        sub.year as subject_year,
        CASE 
            WHEN m.mark >= 90 THEN 'A+'
            WHEN m.mark >= 80 THEN 'A'
            WHEN m.mark >= 70 THEN 'B'
            WHEN m.mark >= 50 THEN 'C'
            ELSE 'F'
        END as grade
    FROM marks m
    JOIN students s ON m.student_id = s.id
    JOIN subjects sub ON m.subject_id = sub.id
    ORDER BY m.id
    LIMIT 2
";

$combined_result = pg_query($conn, $combined_query);

if ($combined_result && pg_num_rows($combined_result) > 0) {
    echo "<p>✅ Combined query successful</p>";
    echo "<table border='1'><tr><th>Student</th><th>Subject</th><th>Individual Final Grade</th><th>Graduation Grade</th></tr>";
    
    while($mark = pg_fetch_assoc($combined_result)) {
        // Calculate graduation grade for this student (as in updated code)
        $graduation_result = calculateGraduationGrade($conn, $mark['student_id']);
        $graduation_grade = 0;
        if ($graduation_result['success']) {
            $graduation_grade = $graduation_result['year1_grade'] + $graduation_result['year2_grade'];
        }
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($mark['student_name']) . "</td>";
        echo "<td>" . htmlspecialchars($mark['subject_name']) . "</td>";
        echo "<td>" . number_format($mark['final_grade'], 2) . "</td>";
        echo "<td><strong style='color: #3498db;'>" . number_format($graduation_grade, 2) . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p>✅ Combined query with graduation grade calculation works!</p>";
} else {
    echo "<p>❌ Combined query failed: " . pg_last_error($conn) . "</p>";
}

echo "<h2>4. Diagnosis</h2>";
echo "<p>If all tests above passed, then the issue might be:</p>";
echo "<ul>";
echo "<li>Browser caching - try Ctrl+F5 to hard refresh</li>";
echo "<li>Apache not running - check if XAMPP Apache is started</li>";
echo "<li>PHP errors being hidden - check Apache error logs</li>";
echo "<li>JavaScript errors preventing page display</li>";
echo "</ul>";

pg_close($conn);
?>