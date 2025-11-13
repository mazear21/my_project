<?php
include 'db.php';

$teacher_id = 7; // Change this to test different teachers

echo "=== TESTING FOR TEACHER ID: $teacher_id ===\n\n";

echo "1. Teacher's Assigned Subjects:\n";
$subjects = pg_query_params($conn, "
    SELECT ts.id, s.subject_name, ts.year, ts.class_level, 
           CONCAT(ts.year::text, ts.class_level) as full_class
    FROM teacher_subjects ts 
    JOIN subjects s ON ts.subject_id = s.id 
    WHERE ts.teacher_id = $1
", [$teacher_id]);
while($row = pg_fetch_assoc($subjects)) {
    echo "  - {$row['subject_name']} (Year {$row['year']}, Class {$row['class_level']}, Full: {$row['full_class']})\n";
}

echo "\n2. Students Per Subject:\n";
$per_subject = pg_query_params($conn, "
    SELECT s.subject_name, ts.year, ts.class_level,
           COUNT(DISTINCT m.student_id) as student_count
    FROM teacher_subjects ts
    JOIN subjects s ON ts.subject_id = s.id
    JOIN marks m ON m.subject_id = ts.subject_id
    JOIN students st ON m.student_id = st.id 
        AND st.class_level = CONCAT(ts.year::text, ts.class_level)
        AND st.status = 'active'
    WHERE ts.teacher_id = $1
    GROUP BY s.subject_name, ts.year, ts.class_level
", [$teacher_id]);
while($row = pg_fetch_assoc($per_subject)) {
    echo "  - {$row['subject_name']} (Year {$row['year']}, Class {$row['class_level']}): {$row['student_count']} students\n";
}

echo "\n3. UNIQUE Total Students:\n";
$unique = pg_query_params($conn, "
    SELECT COUNT(DISTINCT st.id) as count
    FROM students st
    INNER JOIN marks m ON st.id = m.student_id
    INNER JOIN teacher_subjects ts ON m.subject_id = ts.subject_id
    WHERE ts.teacher_id = $1 
    AND st.class_level = CONCAT(ts.year::text, ts.class_level)
    AND st.status = 'active'
", [$teacher_id]);
$total = pg_fetch_assoc($unique);
echo "  Total UNIQUE students: {$total['count']}\n";

echo "\n4. Students with Incomplete Marks:\n";
$incomplete = pg_query_params($conn, "
    SELECT COUNT(DISTINCT st.id) as count
    FROM students st
    INNER JOIN marks m ON st.id = m.student_id
    INNER JOIN teacher_subjects ts ON m.subject_id = ts.subject_id
    WHERE ts.teacher_id = $1 
    AND st.class_level = CONCAT(ts.year::text, ts.class_level)
    AND st.status = 'active'
    AND (m.final_exam = 0 OR m.midterm_exam = 0 OR m.quizzes = 0 OR m.daily_activities = 0)
", [$teacher_id]);
$incomplete_count = pg_fetch_assoc($incomplete);
echo "  Students with incomplete marks: {$incomplete_count['count']}\n";

echo "\n5. Sample Students:\n";
$students = pg_query_params($conn, "
    SELECT DISTINCT st.id, st.name, st.class_level
    FROM students st
    INNER JOIN marks m ON st.id = m.student_id
    INNER JOIN teacher_subjects ts ON m.subject_id = ts.subject_id
    WHERE ts.teacher_id = $1 
    AND st.class_level = CONCAT(ts.year::text, ts.class_level)
    AND st.status = 'active'
    LIMIT 10
", [$teacher_id]);
while($row = pg_fetch_assoc($students)) {
    echo "  - {$row['name']} (Class: {$row['class_level']})\n";
}
?>
