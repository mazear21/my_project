<?php
$conn = pg_connect("host=localhost dbname=student_db user=postgres password=0998");
if (!$conn) {
    die("Connection failed: " . pg_last_error());
}
?>