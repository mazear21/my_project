<?php
// Script to update subject credits for graduation system
include 'db.php';

echo "<h2>🎓 Updating Subject Credits for Graduation System</h2>";

// New credit assignments for 50-point max per year
$credit_updates = [
    // Year 1 subjects (total = 30 max)
    'Basic C++' => 8,
    'Basics of Principle Statistics' => 7,
    'Computer Essentials' => 6,
    'English' => 5,
    'Music' => 4,
    
    // Year 2 subjects (total = 50 max)
    'Advanced C++' => 10,
    'Advanced Database' => 12,
    'Advanced English' => 8,
    'Human Resource Management' => 10,
    'Web Development' => 10
];

echo "<h3>📊 New Credit Distribution:</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'><th>Subject</th><th>Year</th><th>New Credits</th><th>Formula</th></tr>";

$year1_total = 0;
$year2_total = 0;

foreach ($credit_updates as $subject_name => $credits) {
    // Determine year based on subject name
    $year = (strpos($subject_name, 'Advanced') !== false || 
             strpos($subject_name, 'Human') !== false || 
             strpos($subject_name, 'Web') !== false) ? 2 : 1;
    
    if ($year == 1) $year1_total += $credits;
    else $year2_total += $credits;
    
    echo "<tr>";
    echo "<td>$subject_name</td>";
    echo "<td>Year $year</td>";
    echo "<td>$credits</td>";
    echo "<td>Total × 0.0$credits</td>";
    echo "</tr>";
}

echo "<tr style='background: #e6f3ff; font-weight: bold;'>";
echo "<td colspan='2'>Year 1 Total</td>";
echo "<td>$year1_total</td>";
echo "<td>Max: 100 × $year1_total = $year1_total points</td>";
echo "</tr>";

echo "<tr style='background: #e6f3ff; font-weight: bold;'>";
echo "<td colspan='2'>Year 2 Total</td>";
echo "<td>$year2_total</td>";
echo "<td>Max: 100 × $year2_total = $year2_total points</td>";
echo "</tr>";

echo "</table>";

// Actually update the database
echo "<h3>🔄 Updating Database...</h3>";

foreach ($credit_updates as $subject_name => $credits) {
    $update_query = "UPDATE subjects SET credits = $1 WHERE subject_name = $2";
    $result = pg_query_params($conn, $update_query, array($credits, $subject_name));
    
    if ($result) {
        $affected_rows = pg_affected_rows($result);
        if ($affected_rows > 0) {
            echo "✅ Updated $subject_name to $credits credits<br>";
        } else {
            echo "⚠️ No rows updated for $subject_name (might not exist)<br>";
        }
    } else {
        echo "❌ Error updating $subject_name: " . pg_last_error($conn) . "<br>";
    }
}

// Update existing marks with new final grades
echo "<h3>🔄 Recalculating Final Grades...</h3>";

$recalc_query = "
    UPDATE marks 
    SET final_grade = marks.mark * (subjects.credits / 100.0)
    FROM subjects 
    WHERE marks.subject_id = subjects.id
";

$recalc_result = pg_query($conn, $recalc_query);

if ($recalc_result) {
    $affected_rows = pg_affected_rows($recalc_result);
    echo "✅ Recalculated final grades for $affected_rows marks<br>";
} else {
    echo "❌ Error recalculating final grades: " . pg_last_error($conn) . "<br>";
}

echo "<h3>✅ Credits Update Complete!</h3>";
echo "<p><strong>Graduation System Ready:</strong></p>";
echo "<ul>";
echo "<li>Year 1: Maximum $year1_total points (if 100% in all subjects)</li>";
echo "<li>Year 2: Maximum $year2_total points (if 100% in all subjects)</li>";
echo "<li>Total Graduation: Year 1 + Year 2 = Maximum " . ($year1_total + $year2_total) . " points</li>";
echo "</ul>";

pg_close($conn);
?>