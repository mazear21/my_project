<?php
// Run database migration using PHP
include 'db.php';

echo "Running database migration...\n\n";

// Check if degree column exists
$check_degree = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='teachers' AND column_name='degree'");
$degree_exists = pg_num_rows($check_degree) > 0;

// Check if salary column exists
$check_salary = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='teachers' AND column_name='salary'");
$salary_exists = pg_num_rows($check_salary) > 0;

// Check if status column exists
$check_status = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='teachers' AND column_name='status'");
$status_exists = pg_num_rows($check_status) > 0;

echo "Current state:\n";
echo "- degree column exists: " . ($degree_exists ? "YES" : "NO") . "\n";
echo "- salary column exists: " . ($salary_exists ? "YES" : "NO") . "\n";
echo "- status column exists: " . ($status_exists ? "YES" : "NO") . "\n\n";

// Add degree column if it doesn't exist
if (!$degree_exists) {
    echo "Adding degree column...\n";
    $result = pg_query($conn, "ALTER TABLE teachers ADD COLUMN degree VARCHAR(100)");
    if ($result) {
        echo "✓ degree column added successfully\n";
    } else {
        echo "✗ Error adding degree column: " . pg_last_error($conn) . "\n";
    }
} else {
    echo "✓ degree column already exists\n";
}

// Add salary column if it doesn't exist
if (!$salary_exists) {
    echo "Adding salary column...\n";
    $result = pg_query($conn, "ALTER TABLE teachers ADD COLUMN salary INTEGER");
    if ($result) {
        echo "✓ salary column added successfully\n";
    } else {
        echo "✗ Error adding salary column: " . pg_last_error($conn) . "\n";
    }
} else {
    echo "✓ salary column already exists\n";
}

// Remove status column if it exists
if ($status_exists) {
    echo "Removing status column...\n";
    $result = pg_query($conn, "ALTER TABLE teachers DROP COLUMN status");
    if ($result) {
        echo "✓ status column removed successfully\n";
    } else {
        echo "✗ Error removing status column: " . pg_last_error($conn) . "\n";
    }
} else {
    echo "✓ status column already removed\n";
}

// Update existing teachers to have default degree if NULL
echo "Updating existing teachers with default degree...\n";
$result = pg_query($conn, "UPDATE teachers SET degree = 'Bachelor''s Degree' WHERE degree IS NULL");
if ($result) {
    $affected = pg_affected_rows($result);
    echo "✓ Updated $affected teachers with default degree\n";
} else {
    echo "✗ Error updating teachers: " . pg_last_error($conn) . "\n";
}

echo "\n===================\n";
echo "Migration complete!\n";
echo "===================\n";

pg_close($conn);
?>
