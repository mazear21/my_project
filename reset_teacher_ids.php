<?php
require_once 'db.php';

// Start transaction
pg_query($conn, "BEGIN");

try {
    // Drop foreign key constraints temporarily
    pg_query($conn, "ALTER TABLE teacher_subjects DROP CONSTRAINT IF EXISTS teacher_subjects_teacher_id_fkey");
    
    // Get all teachers ordered by current ID
    $result = pg_query($conn, "SELECT id FROM teachers ORDER BY id");
    $teachers = pg_fetch_all($result);
    
    if ($teachers) {
        // Temporarily use high numbers to avoid conflicts
        $temp_offset = 100000;
        $temp_id = $temp_offset;
        
        // First pass: move to temporary IDs
        foreach ($teachers as $teacher) {
            $old_id = $teacher['id'];
            pg_query_params($conn, "UPDATE teachers SET id = $1 WHERE id = $2", array($temp_id, $old_id));
            // Update teacher_subjects references
            pg_query_params($conn, "UPDATE teacher_subjects SET teacher_id = $1 WHERE teacher_id = $2", array($temp_id, $old_id));
            $temp_id++;
        }
        
        // Second pass: assign sequential IDs starting from 1
        $new_id = 1;
        $temp_id = $temp_offset;
        foreach ($teachers as $teacher) {
            pg_query_params($conn, "UPDATE teachers SET id = $1 WHERE id = $2", array($new_id, $temp_id));
            // Update teacher_subjects references
            pg_query_params($conn, "UPDATE teacher_subjects SET teacher_id = $1 WHERE teacher_id = $2", array($new_id, $temp_id));
            $new_id++;
            $temp_id++;
        }
        
        // Reset sequence
        pg_query($conn, "ALTER SEQUENCE teachers_id_seq RESTART WITH $new_id");
        
        echo "✓ Teacher IDs renumbered from 1 to " . ($new_id - 1) . "\n";
        echo "✓ Next teacher will get ID $new_id\n";
    } else {
        echo "✓ No teachers to renumber\n";
        pg_query($conn, "ALTER SEQUENCE teachers_id_seq RESTART WITH 1");
    }
    
    // Restore foreign key constraint
    pg_query($conn, "ALTER TABLE teacher_subjects ADD CONSTRAINT teacher_subjects_teacher_id_fkey FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE");
    
    pg_query($conn, "COMMIT");
    echo "✓ Success!";
} catch (Exception $e) {
    pg_query($conn, "ROLLBACK");
    echo "✗ Error: " . $e->getMessage();
}
?>
