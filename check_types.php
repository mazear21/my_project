<?php
include 'db.php';

$r = pg_query($conn, "SELECT data_type FROM information_schema.columns WHERE table_name = 'students' AND column_name = 'year'");
echo 'students.year type: ' . pg_fetch_result($r, 0, 0) . "\n";

$r2 = pg_query($conn, "SELECT data_type FROM information_schema.columns WHERE table_name = 'teacher_subjects' AND column_name = 'year'");
echo 'teacher_subjects.year type: ' . pg_fetch_result($r2, 0, 0) . "\n";

$r3 = pg_query($conn, "SELECT data_type FROM information_schema.columns WHERE table_name = 'students' AND column_name = 'class_level'");
echo 'students.class_level type: ' . pg_fetch_result($r3, 0, 0) . "\n";
?>
