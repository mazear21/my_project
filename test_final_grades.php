<?php
// Test script to verify final grade calculations
include 'db.php';

echo "<h2>🧪 Final Grade Calculation Test</h2>";

// Test query to show all marks with final grades
$test_query = "
    SELECT 
        s.name as student_name,
        sub.subject_name,
        sub.credits,
        m.mark as total_mark,
        m.final_grade,
        (m.mark * (sub.credits / 100.0)) as calculated_final_grade
    FROM marks m
    JOIN students s ON m.student_id = s.id
    JOIN subjects sub ON m.subject_id = sub.id
    ORDER BY s.name, sub.subject_name
";

$result = pg_query($conn, $test_query);

if ($result && pg_num_rows($result) > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>
            <th>Student</th>
            <th>Subject</th>
            <th>Credits</th>
            <th>Total Mark</th>
            <th>Final Grade (DB)</th>
            <th>Final Grade (Calculated)</th>
            <th>Match?</th>
          </tr>";
    
    while ($row = pg_fetch_assoc($result)) {
        $matches = abs($row['final_grade'] - $row['calculated_final_grade']) < 0.01;
        $match_text = $matches ? "✅ Yes" : "❌ No";
        $row_color = $matches ? "" : "background: #ffe6e6;";
        
        echo "<tr style='$row_color'>
                <td>{$row['student_name']}</td>
                <td>{$row['subject_name']}</td>
                <td>{$row['credits']}</td>
                <td>{$row['total_mark']}</td>
                <td>" . number_format($row['final_grade'], 2) . "</td>
                <td>" . number_format($row['calculated_final_grade'], 2) . "</td>
                <td>$match_text</td>
              </tr>";
    }
    echo "</table>";
    
    // Calculate total final grade for each student
    echo "<h3>📊 Student Final Grade Summary</h3>";
    
    $summary_query = "
        SELECT 
            s.name as student_name,
            s.year,
            COUNT(m.id) as subjects_count,
            SUM(m.final_grade) as total_final_grade,
            AVG(m.mark) as average_mark
        FROM students s
        JOIN marks m ON s.id = m.student_id
        GROUP BY s.id, s.name, s.year
        ORDER BY s.name
    ";
    
    $summary_result = pg_query($conn, $summary_query);
    
    if ($summary_result && pg_num_rows($summary_result) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>
                <th>Student</th>
                <th>Year</th>
                <th>Subjects Count</th>
                <th>Total Final Grade</th>
                <th>Average Mark</th>
              </tr>";
        
        while ($row = pg_fetch_assoc($summary_result)) {
            echo "<tr>
                    <td>{$row['student_name']}</td>
                    <td>Year {$row['year']}</td>
                    <td>{$row['subjects_count']}</td>
                    <td style='color: #3498db; font-weight: bold;'>" . number_format($row['total_final_grade'], 2) . "</td>
                    <td>" . number_format($row['average_mark'], 2) . "</td>
                  </tr>";
        }
        echo "</table>";
    }
    
} else {
    echo "<p>❌ No marks found in database</p>";
}

echo "<h3>📋 Formula Explanation:</h3>";
echo "<p><strong>Individual Subject Final Grade = Total Mark × (Credits ÷ 100)</strong></p>";
echo "<p>Examples:</p>";
echo "<ul>";
echo "<li>Total Mark: 79, Credits: 7 → Final Grade: 79 × 0.07 = 5.53</li>";
echo "<li>Total Mark: 53, Credits: 8 → Final Grade: 53 × 0.08 = 4.24</li>";
echo "<li>Total Mark: 100, Credits: 5 → Final Grade: 100 × 0.05 = 5.00</li>";
echo "</ul>";
echo "<p><strong>Overall Final Grade = Sum of all Individual Final Grades</strong></p>";

pg_close($conn);
?>