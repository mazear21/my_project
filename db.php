<?php
// Database configuration - works for both local and cloud (Railway)
// Railway provides DATABASE_URL, we parse it for PostgreSQL connection

// Check if DATABASE_URL exists (Railway cloud environment)
$database_url = getenv('DATABASE_URL');

if ($database_url) {
    // Parse Railway's DATABASE_URL (format: postgresql://user:password@host:port/database)
    $db_parts = parse_url($database_url);
    $host = $db_parts['host'];
    $port = $db_parts['port'];
    $dbname = ltrim($db_parts['path'], '/');
    $user = $db_parts['user'];
    $password = $db_parts['pass'];
    
    // Build connection string with SSL for Railway
    $conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password sslmode=require";
} else {
    // Local development fallback
    $host = 'localhost';
    $port = '5432';
    $dbname = 'student_db';
    $user = 'postgres';
    $password = '0998';
    
    $conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
}

$conn = pg_connect($conn_string);

if (!$conn) {
    die("Connection failed: " . pg_last_error() . "\nConnection string: host=$host dbname=$dbname user=$user");
}
?>