<?php
include 'db.php';

$teacher_id = 7; // mr idris

echo "=== TEACHER ASSIGNMENTS ===\n";
$assignments = pg_query_params($conn, "
    SELECT ts.id, s.subject_name, ts.year, ts.class_level,
           CONCAT(ts.year::text, ts.class_level) as expected_class
    FROM teacher_subjects ts
    JOIN subjects s ON ts.subject_id = s.id
    WHERE ts.teacher_id = $1
    ORDER BY ts.year, ts.class_level, s.subject_name
", [$teacher_id]);

while($row = pg_fetch_assoc($assignments)) {
    echo "{$row['subject_name']} - Year {$row['year']}, Class {$row['class_level']} (Looking for students in class: {$row['expected_class']})\n";
}

echo "\n=== STUDENTS BY CLASS ===\n";
$classes = pg_query($conn, "
    SELECT class_level, year, COUNT(*) as count
    FROM students 
    WHERE status = 'active'
    GROUP BY class_level, year
    ORDER BY year, class_level
");
while($row = pg_fetch_assoc($classes)) {
    echo "Class {$row['class_level']}, Year {$row['year']}: {$row['count']} students\n";
}

echo "\n=== STUDENTS IN TEACHER'S CLASSES ===\n";
$students = pg_query_params($conn, "
    SELECT DISTINCT st.id, st.name, st.class_level, st.year
    FROM students st
    INNER JOIN teacher_subjects ts ON st.year = ts.year
        AND st.class_level = CONCAT(ts.year::text, ts.class_level)
    WHERE ts.teacher_id = $1 
    AND st.status = 'active'
    ORDER BY st.year, st.class_level, st.name
", [$teacher_id]);

$count = 0;
while($row = pg_fetch_assoc($students)) {
    $count++;
    echo "{$count}. {$row['name']} - Class {$row['class_level']}, Year {$row['year']}\n";
}

echo "\nTOTAL: $count students\n";
?>
