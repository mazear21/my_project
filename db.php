<?php
// Database configuration - works for both local and cloud (Railway/Render)
// Cloud platforms automatically set these environment variables

$host = getenv('PGHOST') ?: 'localhost';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'student_db';
$user = getenv('PGUSER') ?: 'postgres';
$password = getenv('PGPASSWORD') ?: '0998';

// Build connection string
$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";

// For Railway.app SSL requirement
if (getenv('PGHOST')) {
    $conn_string .= " sslmode=require";
}

$conn = pg_connect($conn_string);

if (!$conn) {
    die("Connection failed: " . pg_last_error() . "\nConnection string: host=$host dbname=$dbname user=$user");
}
?>