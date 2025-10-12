<?php
// Test the graduation grade display for marks table
require_once 'db.php';

// Include the calculateGraduationGrade function
function calculateGraduationGrade($student_id, $conn) {
    // Calculate Year 1 final grade
    $year1_query = "
        SELECT SUM(m.final_grade) as year1_grade, COUNT(*) as completed_subjects
        FROM marks m 
        JOIN subjects s ON m.subject_id = s.id 
        WHERE m.student_id = $1 AND s.year = 1
    ";
    $year1_result = pg_query_params($conn, $year1_query, array($student_id));
    $year1_data = pg_fetch_assoc($year1_result);
    
    $year1_grade = floatval($year1_data['year1_grade']);
    $year1_completed = intval($year1_data['completed_subjects']);
    
    // Calculate Year 2 final grade
    $year2_query = "
        SELECT SUM(m.final_grade) as year2_grade, COUNT(*) as completed_subjects
        FROM marks m 
        JOIN subjects s ON m.subject_id = s.id 
        WHERE m.student_id = $1 AND s.year = 2
    ";
    $year2_result = pg_query_params($conn, $year2_query, array($student_id));
    $year2_data = pg_fetch_assoc($year2_result);
    
    $year2_grade = floatval($year2_data['year2_grade']);
    $year2_completed = intval($year2_data['completed_subjects']);
    
    // Get total subjects for each year
    $year1_total_query = "SELECT COUNT(*) as total FROM subjects WHERE year = 1";
    $year1_total_result = pg_query($conn, $year1_total_query);
    $year1_total = intval(pg_fetch_assoc($year1_total_result)['total']);
    
    $year2_total_query = "SELECT COUNT(*) as total FROM subjects WHERE year = 2";
    $year2_total_result = pg_query($conn, $year2_total_query);
    $year2_total = intval(pg_fetch_assoc($year2_total_result)['total']);
    
    return [
        'success' => true,
        'year1_grade' => $year1_grade,
        'year2_grade' => $year2_grade,
        'year1_completed' => $year1_completed,
        'year2_completed' => $year2_completed,
        'year1_total' => $year1_total,
        'year2_total' => $year2_total,
        'graduation_grade' => $year1_grade + $year2_grade
    ];
}

// Test the graduation grade calculation
echo "<h2>Testing Graduation Grade Display in Marks Table</h2>";

// Get Ali Salahadin's data
$student_query = "SELECT id, name FROM students WHERE name ILIKE '%ali%salahadin%'";
$student_result = pg_query($conn, $student_query);

if ($student_result && pg_num_rows($student_result) > 0) {
    $student = pg_fetch_assoc($student_result);
    $student_id = $student['id'];
    $student_name = $student['name'];
    
    echo "<h3>Student: {$student_name} (ID: {$student_id})</h3>";
    
    // Calculate graduation grade
    $graduation_result = calculateGraduationGrade($student_id, $conn);
    
    if ($graduation_result['success']) {
        echo "<div style='background: #f0f8ff; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<strong>Graduation Grade Calculation:</strong><br>";
        echo "Year 1 Grade: " . number_format($graduation_result['year1_grade'], 2) . "<br>";
        echo "Year 2 Grade: " . number_format($graduation_result['year2_grade'], 2) . "<br>";
        echo "<strong>Total Graduation Grade: " . number_format($graduation_result['graduation_grade'], 2) . "</strong><br>";
        echo "</div>";
        
        // Show what will be displayed in each marks table row
        echo "<h4>What will be shown in the Final Grade column for each subject:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Subject</th><th>Individual Final Grade (Old)</th><th>Graduation Grade (New)</th></tr>";
        
        // Get Ali's marks
        $marks_query = "
            SELECT m.final_grade, s.subject_name
            FROM marks m 
            JOIN subjects s ON m.subject_id = s.id 
            WHERE m.student_id = $1
            ORDER BY s.year, s.subject_name
        ";
        $marks_result = pg_query_params($conn, $marks_query, array($student_id));
        
        if ($marks_result && pg_num_rows($marks_result) > 0) {
            while ($mark = pg_fetch_assoc($marks_result)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($mark['subject_name']) . "</td>";
                echo "<td>" . number_format($mark['final_grade'], 2) . "</td>";
                echo "<td><strong style='color: #3498db;'>" . number_format($graduation_result['graduation_grade'], 2) . "</strong></td>";
                echo "</tr>";
            }
        }
        echo "</table>";
        
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<strong>Note:</strong> Instead of showing individual subject final grades (9.48, 7.42, etc.), ";
        echo "each row will now show the same total graduation grade (" . number_format($graduation_result['graduation_grade'], 2) . ") ";
        echo "which is the sum of all 5 subjects combined.";
        echo "</div>";
    }
} else {
    echo "<p>No student found matching 'Ali Salahadin'</p>";
}

pg_close($conn);
?>