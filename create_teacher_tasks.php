<?php
// Create teacher_tasks table for academic tracking
include 'db.php';

// Create teacher_tasks table
$create_table = "
CREATE TABLE IF NOT EXISTS teacher_tasks (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
    task_type VARCHAR(50) NOT NULL CHECK (task_type IN ('exam', 'homework', 'reminder', 'note')),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    due_date DATE,
    priority VARCHAR(20) DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high')),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'cancelled')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_teacher_tasks_teacher ON teacher_tasks(teacher_id);
CREATE INDEX IF NOT EXISTS idx_teacher_tasks_subject ON teacher_tasks(subject_id);
CREATE INDEX IF NOT EXISTS idx_teacher_tasks_date ON teacher_tasks(due_date);
CREATE INDEX IF NOT EXISTS idx_teacher_tasks_status ON teacher_tasks(status);
";

$result = pg_query($conn, $create_table);

if ($result) {
    echo "<h2>✅ Success!</h2>";
    echo "<p>teacher_tasks table created successfully</p>";
    echo "<p><a href='index.php'>Go to Dashboard</a></p>";
} else {
    echo "<h2>❌ Error</h2>";
    echo "<p>" . pg_last_error($conn) . "</p>";
}
?>
