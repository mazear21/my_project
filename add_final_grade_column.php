<?php
// Script to add final_grade column to marks table
include 'db.php';

try {
    // Add final_grade column if it doesn't exist
    $add_column_query = "
        ALTER TABLE marks 
        ADD COLUMN IF NOT EXISTS final_grade DECIMAL(10,2) DEFAULT 0.00
    ";
    
    $result = pg_query($conn, $add_column_query);
    
    if ($result) {
        echo "✅ Successfully added final_grade column to marks table\n";
        
        // Update existing records with calculated final grades
        $update_query = "
            UPDATE marks 
            SET final_grade = marks.mark * (subjects.credits / 100.0)
            FROM subjects 
            WHERE marks.subject_id = subjects.id
        ";
        
        $update_result = pg_query($conn, $update_query);
        
        if ($update_result) {
            $affected_rows = pg_affected_rows($update_result);
            echo "✅ Successfully updated $affected_rows existing records with final grades\n";
        } else {
            echo "❌ Error updating existing records: " . pg_last_error($conn) . "\n";
        }
        
    } else {
        echo "❌ Error adding final_grade column: " . pg_last_error($conn) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

pg_close($conn);
?>