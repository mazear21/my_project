<?php
// Script to update subject credits for the graduation system
include 'db.php';

echo "🎓 Updating Subject Credits for Graduation System\n\n";

// Recommended credit assignments for perfect 50+50=100 system
$credit_updates = [
    // Year 1 subjects (total = 50 max)
    'basic C++' => 12,
    'Basics of Principle Statistics' => 10,
    'computer Essentials' => 8,
    'English' => 10,
    'musice' => 10,  // Note: keeping the typo as it exists in database
    
    // Year 2 subjects (total = 50 max)
    'advanced C++' => 12,
    'Advanced Database' => 14,
    'Advanced English' => 8,
    'Humane Resource Management' => 8,
    'web development' => 8
];

echo "📊 New Credit Distribution:\n";
echo "Subject | Year | New Credits | Formula\n";
echo "----------------------------------------\n";

$year1_total = 0;
$year2_total = 0;

foreach ($credit_updates as $subject_name => $credits) {
    // Determine year based on subject name
    $year = (strpos($subject_name, 'advanced') !== false || 
             strpos($subject_name, 'Advanced') !== false || 
             strpos($subject_name, 'Humane') !== false || 
             strpos($subject_name, 'web') !== false) ? 2 : 1;
    
    if ($year == 1) $year1_total += $credits;
    else $year2_total += $credits;
    
    echo sprintf("%-30s | %d | %2d | Total × 0.%02d\n", $subject_name, $year, $credits, $credits);
}

echo "----------------------------------------\n";
echo sprintf("%-30s | 1 | %2d | Max: 100 × %d = %d points\n", "Year 1 Total", $year1_total, $year1_total, $year1_total);
echo sprintf("%-30s | 2 | %2d | Max: 100 × %d = %d points\n", "Year 2 Total", $year2_total, $year2_total, $year2_total);
echo "----------------------------------------\n";
echo "Graduation Total: $year1_total + $year2_total = " . ($year1_total + $year2_total) . " points maximum\n\n";

// Actually update the database
echo "🔄 Updating Database...\n";

foreach ($credit_updates as $subject_name => $credits) {
    $update_query = "UPDATE subjects SET credits = $1 WHERE subject_name = $2";
    $result = pg_query_params($conn, $update_query, array($credits, $subject_name));
    
    if ($result) {
        $affected_rows = pg_affected_rows($result);
        if ($affected_rows > 0) {
            echo "✅ Updated '$subject_name' to $credits credits\n";
        } else {
            echo "⚠️ No rows updated for '$subject_name' (might not exist)\n";
        }
    } else {
        echo "❌ Error updating '$subject_name': " . pg_last_error($conn) . "\n";
    }
}

// Update existing marks with new final grades
echo "\n🔄 Recalculating Final Grades...\n";

$recalc_query = "
    UPDATE marks 
    SET final_grade = marks.mark * (subjects.credits / 100.0)
    FROM subjects 
    WHERE marks.subject_id = subjects.id
";

$recalc_result = pg_query($conn, $recalc_query);

if ($recalc_result) {
    $affected_rows = pg_affected_rows($recalc_result);
    echo "✅ Recalculated final grades for $affected_rows marks\n";
} else {
    echo "❌ Error recalculating final grades: " . pg_last_error($conn) . "\n";
}

echo "\n✅ Credits Update Complete!\n";
echo "🎓 Graduation System Ready:\n";
echo "   - Year 1: Maximum $year1_total points (if 100% in all subjects)\n";
echo "   - Year 2: Maximum $year2_total points (if 100% in all subjects)\n";
echo "   - Total Graduation: Year 1 + Year 2 = Maximum " . ($year1_total + $year2_total) . " points\n";

pg_close($conn);
?>