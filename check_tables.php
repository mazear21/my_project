<?php
// Check what tables actually exist and their structure
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Structure Check</h1>";

$host = 'localhost';
$dbname = 'student_management_system';
$username = 'postgres';
$password = 'root';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p>✅ Database connected</p>";
    
    // List all tables
    echo "<h2>All Tables:</h2>";
    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // Check if student_subjects table exists
    echo "<h2>Checking student_subjects table:</h2>";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM student_subjects");
        $count = $stmt->fetchColumn();
        echo "<p>✅ student_subjects table exists with $count records</p>";
        
        // Show structure
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'student_subjects'");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>student_subjects structure:</h3>";
        echo "<ul>";
        foreach ($columns as $col) {
            echo "<li>{$col['column_name']} ({$col['data_type']})</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ student_subjects table does not exist: " . $e->getMessage() . "</p>";
    }
    
    // Check if enrollments table exists
    echo "<h2>Checking enrollments table:</h2>";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM enrollments");
        $count = $stmt->fetchColumn();
        echo "<p>✅ enrollments table exists with $count records</p>";
        
        // Show structure
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'enrollments'");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>enrollments structure:</h3>";
        echo "<ul>";
        foreach ($columns as $col) {
            echo "<li>{$col['column_name']} ({$col['data_type']})</li>";
        }
        echo "</ul>";
        
        // Show sample data
        echo "<h3>Sample enrollments data:</h3>";
        $stmt = $pdo->query("SELECT * FROM enrollments LIMIT 5");
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>";
        print_r($samples);
        echo "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ enrollments table does not exist: " . $e->getMessage() . "</p>";
    }
    
    // Check student ID 18 specifically
    echo "<h2>Student ID 18 enrollments:</h2>";
    try {
        $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE student_id = ?");
        $stmt->execute([18]);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($enrollments)) {
            echo "<p>No enrollments found for student ID 18</p>";
        } else {
            echo "<pre>";
            print_r($enrollments);
            echo "</pre>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error checking enrollments: " . $e->getMessage() . "</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}
?>