<?php
include 'db.php';

echo "=== CHECKING BASIC C++ STUDENTS ===\n\n";

// Get basic C++ subject ID
$subject = pg_query($conn, "SELECT id FROM subjects WHERE subject_name = 'basic C++'");
$subject_id = pg_fetch_result($subject, 0, 0);
echo "Subject ID for 'basic C++': $subject_id\n\n";

// Count students with marks for basic C++
$count = pg_query_params($conn, "
    SELECT COUNT(DISTINCT m.student_id) as count
    FROM marks m
    JOIN students st ON m.student_id = st.id
    WHERE m.subject_id = $1 AND st.status = 'active'
", [$subject_id]);
$total = pg_fetch_result($count, 0, 0);
echo "Total ACTIVE students with basic C++ marks: $total\n\n";

// List all students
echo "Students:\n";
$students = pg_query_params($conn, "
    SELECT st.id, st.name, st.class_level, st.year, st.status
    FROM marks m
    JOIN students st ON m.student_id = st.id
    WHERE m.subject_id = $1
    ORDER BY st.status, st.name
", [$subject_id]);

$active = 0;
$inactive = 0;
while($row = pg_fetch_assoc($students)) {
    $status_mark = $row['status'] == 'active' ? '✓' : '✗';
    echo "$status_mark {$row['name']} - Class {$row['class_level']}, Year {$row['year']} ({$row['status']})\n";
    if($row['status'] == 'active') $active++;
    else $inactive++;
}

echo "\nSummary:\n";
echo "Active: $active\n";
echo "Inactive: $inactive\n";
echo "Total: " . ($active + $inactive) . "\n";
?>
