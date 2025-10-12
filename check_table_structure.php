<?php
// Check subjects table structure
include 'db.php';

echo "🔍 Checking subjects table structure:\n\n";

// Get table structure
$structure_query = "
    SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale
    FROM information_schema.columns 
    WHERE table_name = 'subjects'
    ORDER BY ordinal_position
";

$result = pg_query($conn, $structure_query);

if ($result) {
    echo "Column Name | Data Type | Max Length | Precision | Scale\n";
    echo "------------------------------------------------------------\n";
    
    while ($row = pg_fetch_assoc($result)) {
        echo sprintf("%-12s | %-10s | %-10s | %-9s | %s\n", 
            $row['column_name'], 
            $row['data_type'], 
            $row['character_maximum_length'] ?: 'N/A',
            $row['numeric_precision'] ?: 'N/A',
            $row['numeric_scale'] ?: 'N/A'
        );
    }
}

// Check current credits values
echo "\n📊 Current credits in subjects table:\n\n";
$credits_query = "SELECT id, subject_name, credits FROM subjects ORDER BY year, subject_name";
$credits_result = pg_query($conn, $credits_query);

if ($credits_result) {
    while ($row = pg_fetch_assoc($credits_result)) {
        echo "ID: {$row['id']} | {$row['subject_name']} | Credits: {$row['credits']}\n";
    }
}

pg_close($conn);
?>