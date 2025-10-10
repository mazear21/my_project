<?php
include("db.php");

echo "Updating students table structure for enhanced profile system...\n";

// Check current columns in students table
$result = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position");
$existing_columns = [];
while($row = pg_fetch_assoc($result)) {
    $existing_columns[] = $row['column_name'];
    echo "- " . $row['column_name'] . "\n";
}

// Add new columns if they don't exist
$new_columns = [
    'age' => 'INTEGER DEFAULT 18',
    'gender' => 'VARCHAR(10) DEFAULT \'Male\'',
    'class_level' => 'VARCHAR(10) DEFAULT \'A\'',
    'academic_year' => 'INTEGER DEFAULT 1',
    'phone' => 'VARCHAR(20) DEFAULT \'\'',
    'address' => 'TEXT DEFAULT \'\'',
    'graduation_status' => 'VARCHAR(20) DEFAULT \'Active\''
];

foreach($new_columns as $column => $definition) {
    if(!in_array($column, $existing_columns)) {
        echo "\nAdding column: $column\n";
        $sql = "ALTER TABLE students ADD COLUMN $column $definition";
        $result = pg_query($conn, $sql);
        if($result) {
            echo "✅ Successfully added $column\n";
        } else {
            echo "❌ Failed to add $column: " . pg_last_error($conn) . "\n";
        }
    } else {
        echo "✅ Column $column already exists\n";
    }
}

echo "\nStudents table enhancement completed!\n";
?>