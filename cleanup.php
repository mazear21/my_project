<?php
require_once 'db.php';

// Delete all student-related data
pg_query($conn, "DELETE FROM marks");
pg_query($conn, "DELETE FROM graduated_students");
pg_query($conn, "DELETE FROM students");

// Reset the ID sequences to start from 1
pg_query($conn, "ALTER SEQUENCE students_id_seq RESTART WITH 1");

echo "✓ All student data cleared from database\n";
echo "✓ Student ID sequence reset to 1\n";
echo "Ready to add new students starting from ID 1";
?>
