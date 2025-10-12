<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Minimal Marks Test</title></head><body>";
echo "<h1>Testing Minimal Marks Page</h1>";

try {
    require_once 'db.php';
    echo "<p>✅ Database connected</p>";
    
    // Copy the calculateGraduationGrade function
    function calculateGraduationGrade($conn, $student_id) {
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
            'year2_grade' => $year2_grade
        ];
    }
    
    // Test the marks query exactly as in the updated index.php
    $marks_query = "
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
    ";
    $marks_result = pg_query($conn, $marks_query);
    
    echo "<p>✅ Marks query executed</p>";
    
    if ($marks_result && pg_num_rows($marks_result) > 0) {
        echo "<h2>Marks Table</h2>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Student</th><th>Subject</th><th>Total</th><th>Final Grade (New)</th><th>Grade</th></tr>";
        
        while($mark = pg_fetch_assoc($marks_result)) {
            $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $mark['grade']));
            
            // Calculate graduation grade for this student
            $graduation_result = calculateGraduationGrade($conn, $mark['student_id']);
            $graduation_grade = 0;
            if ($graduation_result['success']) {
                $graduation_grade = $graduation_result['year1_grade'] + $graduation_result['year2_grade'];
            }
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($mark['student_name']) . "</td>";
            echo "<td>" . htmlspecialchars($mark['subject_name']) . "</td>";
            echo "<td>" . (int)$mark['mark'] . "</td>";
            echo "<td><strong style='color: #3498db;'>" . number_format($graduation_grade, 2) . "</strong></td>";
            echo "<td>" . $mark['grade'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>✅ Marks table rendered successfully!</p>";
    } else {
        echo "<p>❌ No marks found</p>";
    }
    
    pg_close($conn);
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
    echo "<p>Stack trace: " . $e->getTraceAsString() . "</p>";
}

echo "</body></html>";
?>