<?php
// Database Cleanup Script
include 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Cleanup</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        h1 { color: #1e3a8a; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .warning { background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .danger { background: #fee2e2; border: 2px solid #ef4444; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { background: #d1fae5; border: 2px solid #10b981; padding: 15px; border-radius: 5px; margin: 10px 0; }
        button { padding: 10px 20px; margin: 5px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-success { background: #10b981; color: white; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🧹 Database Cleanup Tool</h1>
    
    <div class='section'>
        <h2>⚠️ IMPORTANT - Read Before Proceeding</h2>
        <div class='warning'>
            <p><strong>This tool will help you clean up unused database elements.</strong></p>
            <p>✅ <strong>Recommended actions are safe</strong> and will improve database performance.</p>
            <p>⚠️ <strong>Always backup your database first!</strong></p>
        </div>
    </div>

    <?php
    if (isset($_POST['action'])) {
        echo "<div class='section'>";
        echo "<h2>Results:</h2>";
        
        switch ($_POST['action']) {
            case 'drop_student_subjects':
                $result = pg_query($conn, "DROP TABLE IF EXISTS student_subjects CASCADE");
                if ($result) {
                    echo "<div class='success'>✅ Successfully dropped <code>student_subjects</code> table!</div>";
                } else {
                    echo "<div class='danger'>❌ Error: " . pg_last_error($conn) . "</div>";
                }
                break;
                
            case 'drop_departments':
                $queries = [
                    "ALTER TABLE students DROP CONSTRAINT IF EXISTS students_department_id_fkey CASCADE",
                    "ALTER TABLE students DROP COLUMN IF EXISTS department_id CASCADE",
                    "ALTER TABLE students DROP COLUMN IF EXISTS department CASCADE",
                    "DROP TABLE IF EXISTS departments CASCADE"
                ];
                
                $all_success = true;
                foreach ($queries as $query) {
                    if (!pg_query($conn, $query)) {
                        echo "<div class='danger'>❌ Error on query: $query<br>" . pg_last_error($conn) . "</div>";
                        $all_success = false;
                        break;
                    }
                }
                
                if ($all_success) {
                    echo "<div class='success'>✅ Successfully dropped <code>departments</code> table and related columns!</div>";
                }
                break;
                
            case 'drop_unused_columns':
                $queries = [
                    "ALTER TABLE students DROP COLUMN IF EXISTS leave_date CASCADE",
                    "ALTER TABLE students DROP COLUMN IF EXISTS address CASCADE",
                    "ALTER TABLE subjects DROP COLUMN IF EXISTS teacher_id CASCADE",
                    "ALTER TABLE teachers DROP COLUMN IF EXISTS created_by CASCADE",
                    "ALTER TABLE promotion_history DROP COLUMN IF EXISTS academic_performance CASCADE"
                ];
                
                $success_count = 0;
                foreach ($queries as $query) {
                    if (pg_query($conn, $query)) {
                        $success_count++;
                    }
                }
                
                echo "<div class='success'>✅ Successfully dropped $success_count unused columns!</div>";
                break;
                
            case 'verify':
                echo "<div class='success'><h3>Current Database Status:</h3>";
                
                // Check if student_subjects exists
                $check = pg_query($conn, "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'student_subjects')");
                $exists = pg_fetch_result($check, 0, 0) === 't';
                echo "<p><code>student_subjects</code> table: " . ($exists ? "❌ Still exists" : "✅ Deleted") . "</p>";
                
                // Check teachers count
                $teachers = pg_query($conn, "SELECT COUNT(*) FROM teachers");
                $count = pg_fetch_result($teachers, 0, 0);
                echo "<p>Teachers in database: <strong>$count</strong></p>";
                
                // Check marks count
                $marks = pg_query($conn, "SELECT COUNT(*) FROM marks");
                $marks_count = pg_fetch_result($marks, 0, 0);
                echo "<p>Student enrollments (marks): <strong>$marks_count</strong></p>";
                
                echo "</div>";
                break;
        }
        
        echo "</div>";
    }
    ?>

    <div class='section'>
        <h2>🗑️ Step 1: Drop Unused Tables</h2>
        <div class='danger'>
            <h3>Drop <code>student_subjects</code> Table</h3>
            <p><strong>Status:</strong> Empty table (0 rows)</p>
            <p><strong>Reason:</strong> Student enrollment is handled by the <code>marks</code> table.</p>
            <p><strong>Impact:</strong> None - table is already unused</p>
            
            <form method='POST' onsubmit="return confirm('Are you sure you want to drop the student_subjects table?');">
                <input type='hidden' name='action' value='drop_student_subjects'>
                <button type='submit' class='btn-danger'>🗑️ Drop student_subjects Table</button>
            </form>
        </div>
        
        <div class='warning' style='margin-top: 20px;'>
            <h3>Drop <code>departments</code> Table</h3>
            <p><strong>Status:</strong> Has 4 rows but not used anywhere</p>
            <p><strong>Reason:</strong> Students table has <code>department_id</code> column but it's NULL everywhere and never used in queries.</p>
            <p><strong>Impact:</strong> First, we'll drop the foreign key constraint and the unused department columns, then delete the table.</p>
            
            <form method='POST' onsubmit="return confirm('Are you sure you want to drop the departments table and related foreign keys?');">
                <input type='hidden' name='action' value='drop_departments'>
                <button type='submit' class='btn-warning'>🗑️ Drop departments Table & Foreign Keys</button>
            </form>
        </div>
    </div>

    <div class='section'>
        <h2>🧹 Step 2: Drop Unused Columns</h2>
        <div class='warning'>
            <h3>Remove Unused Columns</h3>
            <p>The following columns are always NULL or empty and can be safely removed:</p>
            <ul>
                <li><code>students.leave_date</code> - NULL everywhere</li>
                <li><code>students.address</code> - Empty strings everywhere</li>
                <li><code>subjects.teacher_id</code> - NULL everywhere (using teacher_subjects instead)</li>
                <li><code>teachers.created_by</code> - NULL everywhere</li>
                <li><code>promotion_history.academic_performance</code> - NULL everywhere</li>
            </ul>
            <p><strong>Note:</strong> The <code>department</code> and <code>department_id</code> columns will be dropped when you drop the departments table.</p>
            <p><strong>Impact:</strong> Reduces database size and improves query performance</p>
            
            <form method='POST' onsubmit="return confirm('Are you sure you want to drop these unused columns?');">
                <input type='hidden' name='action' value='drop_unused_columns'>
                <button type='submit' class='btn-warning'>🧹 Drop Unused Columns</button>
            </form>
        </div>
    </div>

    <div class='section'>
        <h2>✅ Step 3: Verify Cleanup</h2>
        <form method='POST'>
            <input type='hidden' name='action' value='verify'>
            <button type='submit' class='btn-success'>✅ Verify Current Status</button>
        </form>
    </div>

    <div class='section'>
        <h2>📝 SQL Commands (Manual Execution)</h2>
        <p>If you prefer to run these manually in pgAdmin:</p>
        <pre style='background: #f3f4f6; padding: 15px; border-radius: 5px; overflow-x: auto;'>
-- Drop unused table
DROP TABLE IF EXISTS student_subjects CASCADE;

-- Drop unused columns
ALTER TABLE students DROP COLUMN IF EXISTS department CASCADE;
ALTER TABLE students DROP COLUMN IF EXISTS leave_date CASCADE;
ALTER TABLE students DROP COLUMN IF EXISTS department_id CASCADE;
ALTER TABLE students DROP COLUMN IF EXISTS address CASCADE;
ALTER TABLE subjects DROP COLUMN IF EXISTS teacher_id CASCADE;
ALTER TABLE teachers DROP COLUMN IF EXISTS created_by CASCADE;
ALTER TABLE promotion_history DROP COLUMN IF EXISTS academic_performance CASCADE;
        </pre>
    </div>
</body>
</html>
