<?php
// Prevent caching to ensure fresh data
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Authentication Check
include 'auth_check.php';

// Start output buffering to prevent HTML output before JSON responses
ob_start();

// Include database connection
include 'db.php';

// Add password_plain column if it doesn't exist (for admin to view passwords)
$check_column = "SELECT column_name FROM information_schema.columns WHERE table_name='teachers' AND column_name='password_plain'";
$column_exists = pg_query($conn, $check_column);
if (pg_num_rows($column_exists) == 0) {
    pg_query($conn, "ALTER TABLE teachers ADD COLUMN password_plain TEXT");
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    // Only set JSON header for specific actions that return JSON
    $json_actions = ['update_mark', 'promote_student', 'graduate_student', 'update_teacher', 'delete_teacher', 
                     'generate_teacher_credentials', 'remove_teacher_assignment', 'assign_subject_to_teacher'];
    
    if (in_array($_POST['action'], $json_actions)) {
        header('Content-Type: application/json');
    }
    
    if ($_POST['action'] === 'update_mark') {
        // PERMISSION CHECK: Only teachers can edit marks, not admins
        if (!isTeacher()) {
            echo json_encode(['success' => false, 'message' => 'Access Denied: Only teachers can edit student marks. Administrators have view-only access.']);
            exit;
        }
        
        $student_id = $_POST['student_id'];
        $subject_id = $_POST['subject_id'];
        $final_mark = (int)$_POST['final_mark'];
        $midterm_mark = (int)$_POST['midterm_mark'];
        $quizzes_mark = (int)$_POST['quizzes_mark'];
        $daily_mark = (int)$_POST['daily_mark'];
        
        // Check if teacher is assigned to this subject
        $teacher_id = $_SESSION['teacher_id'];
        if (!canEditSubject($teacher_id, $subject_id)) {
            echo json_encode(['success' => false, 'message' => 'Access Denied: You are not assigned to teach this subject.']);
            exit;
        }
        
        // Check if student is enrolled in this subject
        if (!canEditStudentMarks($teacher_id, $student_id, $subject_id)) {
            echo json_encode(['success' => false, 'message' => 'Access Denied: This student is not enrolled in your subject.']);
            exit;
        }
        
        // Calculate total
        $total_mark = $final_mark + $midterm_mark + $quizzes_mark + $daily_mark;
        
        // Validate marks
        if ($final_mark < 0 || $final_mark > 60 ||
            $midterm_mark < 0 || $midterm_mark > 20 ||
            $quizzes_mark < 0 || $quizzes_mark > 10 ||
            $daily_mark < 0 || $daily_mark > 10) {
            echo json_encode(['success' => false, 'message' => 'Invalid mark ranges']);
            exit;
        }
        
        // Get subject credits to calculate final grade
        $credits_query = "SELECT credits FROM subjects WHERE id = $1";
        $credits_result = pg_query_params($conn, $credits_query, array($subject_id));
        $credits = pg_fetch_assoc($credits_result)['credits'] ?? 0;
        
        // Calculate final grade: total_mark × (credits / 100)
        $final_grade = $total_mark * ($credits / 100.0);
        
        // Determine status
        $status = $total_mark >= 50 ? 'Pass' : 'Fail';
        
        // Check if mark exists
        $check_query = "SELECT id FROM marks WHERE student_id = $1 AND subject_id = $2";
        $check_result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
        
        if (pg_num_rows($check_result) > 0) {
            // Update existing mark
            $update_query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6, final_grade = $7 WHERE student_id = $8 AND subject_id = $9";
            $result = pg_query_params($conn, $update_query, array($final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark, $status, $final_grade, $student_id, $subject_id));
        } else {
            // Insert new mark
            $insert_query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status, final_grade) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)";
            $result = pg_query_params($conn, $insert_query, array($student_id, $subject_id, $final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark, $status, $final_grade));
        }
        
        if ($result) {
            // Log the action
            logAction('update_mark', 'marks', null, null, ['student_id' => $student_id, 'subject_id' => $subject_id, 'total_mark' => $total_mark]);
            
            echo json_encode(['success' => true, 'total' => $total_mark, 'final_grade' => $final_grade]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . pg_last_error($conn)]);
        }
        exit;
    }
    
    // TEACHER TASK MANAGEMENT
    if ($_POST['action'] === 'add_task') {
        if (!isTeacher()) {
            echo json_encode(['success' => false, 'message' => 'Access Denied']);
            exit;
        }
        
        $teacher_id = $_SESSION['teacher_id'];
        $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $task_type = $_POST['task_type'];
        $title = $_POST['title'];
        $description = $_POST['description'] ?? '';
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $priority = $_POST['priority'] ?? 'medium';
        
        $query = "INSERT INTO teacher_tasks (teacher_id, subject_id, task_type, title, description, due_date, priority) 
                  VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id";
        $result = pg_query_params($conn, $query, [$teacher_id, $subject_id, $task_type, $title, $description, $due_date, $priority]);
        
        if ($result) {
            $task_id = pg_fetch_result($result, 0, 0);
            echo json_encode(['success' => true, 'task_id' => $task_id]);
        } else {
            echo json_encode(['success' => false, 'message' => pg_last_error($conn)]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_task_status') {
        if (!isTeacher()) {
            echo json_encode(['success' => false, 'message' => 'Access Denied']);
            exit;
        }
        
        $task_id = (int)$_POST['task_id'];
        $status = $_POST['status'];
        $teacher_id = $_SESSION['teacher_id'];
        
        $query = "UPDATE teacher_tasks SET status = $1, updated_at = CURRENT_TIMESTAMP 
                  WHERE id = $2 AND teacher_id = $3";
        $result = pg_query_params($conn, $query, [$status, $task_id, $teacher_id]);
        
        echo json_encode(['success' => (bool)$result]);
        exit;
    }
    
    if ($_POST['action'] === 'delete_task') {
        if (!isTeacher()) {
            echo json_encode(['success' => false, 'message' => 'Access Denied']);
            exit;
        }
        
        $task_id = (int)$_POST['task_id'];
        $teacher_id = $_SESSION['teacher_id'];
        
        $query = "DELETE FROM teacher_tasks WHERE id = $1 AND teacher_id = $2";
        $result = pg_query_params($conn, $query, [$task_id, $teacher_id]);
        
        echo json_encode(['success' => (bool)$result]);
        exit;
    }
    
    if ($_POST['action'] === 'filter_reports') {
        $class_filter = $_POST['class_filter'] ?? '';
        $subject_filter = $_POST['subject_filter'] ?? '';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $where_conditions = [];
        $params = [];
        $param_count = 0;
        
        if (!empty($class_filter)) {
            $param_count++;
            $where_conditions[] = "s.class_level = $$param_count";
            $params[] = $class_filter;
        }
        
        if (!empty($subject_filter)) {
            $param_count++;
            $where_conditions[] = "sub.id = $$param_count";
            $params[] = $subject_filter;
        }
        
        // Note: Date filtering removed as marks table has no timestamp columns
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $query = "
            SELECT 
                s.id as student_id,
                s.name as student_name,
                s.class_level,
                sub.subject_name,
                sub.credits,
                m.final_exam as final_mark,
                m.midterm_exam as midterm_mark,
                m.quizzes as quizzes_mark,
                m.daily_activities as daily_mark,
                m.mark as total_mark,
                m.final_grade,
                CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END as grade
            FROM students s
            LEFT JOIN marks m ON s.id = m.student_id
            LEFT JOIN subjects sub ON m.subject_id = sub.id
            $where_clause
            ORDER BY s.name, sub.subject_name
        ";
        
        // Fix parameter placeholders
        for ($i = 1; $i <= $param_count; $i++) {
            $query = str_replace("$$i", "$" . $i, $query);
        }
        
        $result = pg_query_params($conn, $query, $params);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Query error: ' . pg_last_error($conn)]);
            exit;
        }
        
        $data = [];
        while ($row = pg_fetch_assoc($result)) {
            $data[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

// Handle GET requests for AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_student') {
        $student_id = (int)$_GET['id'];
        
        $query = "SELECT * FROM students WHERE id = $1";
        $result = pg_query_params($conn, $query, array($student_id));
        
        if ($result && pg_num_rows($result) > 0) {
            $student = pg_fetch_assoc($result);
            echo json_encode(['success' => true, 'student' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
        }
        exit;
    }
    
    if ($_GET['action'] === 'get_student_subjects') {
        $student_id = (int)$_GET['id'];
        
        // Get student data
        $student_query = "SELECT * FROM students WHERE id = $1";
        $student_result = pg_query_params($conn, $student_query, array($student_id));
        
        if (!$student_result || pg_num_rows($student_result) == 0) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit;
        }
        
        $student = pg_fetch_assoc($student_result);
        
        // Get all subjects
        $all_subjects_query = "SELECT id, subject_name, description, credits, year FROM subjects ORDER BY year, subject_name";
        $all_subjects_result = pg_query($conn, $all_subjects_query);
        $all_subjects = [];
        
        if ($all_subjects_result) {
            while ($subject = pg_fetch_assoc($all_subjects_result)) {
                $all_subjects[] = $subject;
            }
        }
        
        // Get enrolled subjects (subjects where student has marks)
        $enrolled_query = "SELECT DISTINCT subject_id FROM marks WHERE student_id = $1";
        $enrolled_result = pg_query_params($conn, $enrolled_query, array($student_id));
        $enrolled_subjects = [];
        
        if ($enrolled_result) {
            while ($row = pg_fetch_assoc($enrolled_result)) {
                $enrolled_subjects[] = $row['subject_id'];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'student' => $student,
            'all_subjects' => $all_subjects,
            'enrolled_subjects' => $enrolled_subjects
        ]);
        exit;
    }
    
    if ($_GET['action'] === 'get_subject') {
        $subject_id = (int)$_GET['id'];
        
        $query = "SELECT * FROM subjects WHERE id = $1";
        $result = pg_query_params($conn, $query, array($subject_id));
        
        if ($result && pg_num_rows($result) > 0) {
            $subject = pg_fetch_assoc($result);
            echo json_encode(['success' => true, 'subject' => $subject]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Subject not found']);
        }
        exit;
    }
    
    if ($_GET['action'] === 'get_mark') {
        $mark_id = (int)$_GET['id'];
        
        $query = "
            SELECT 
                m.*,
                s.name as student_name,
                sub.subject_name
            FROM marks m
            JOIN students s ON m.student_id = s.id
            JOIN subjects sub ON m.subject_id = sub.id
            WHERE m.id = $1
        ";
        $result = pg_query_params($conn, $query, array($mark_id));
        
        if ($result && pg_num_rows($result) > 0) {
            $mark = pg_fetch_assoc($result);
            echo json_encode(['success' => true, 'mark' => $mark]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Mark not found']);
        }
        exit;
    }
}

// Handle regular form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // This handles non-AJAX form submissions
}

if (isset($_POST['action']) && $_POST['action'] === 'add_student') {
    // ADMIN ONLY: Teachers cannot add students
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can add students.']);
        exit;
    }
    
    $name = trim($_POST['student_name']);
    $age = (int)$_POST['age'];
    $gender = trim($_POST['gender']);
    $class_level = trim($_POST['class_level']);
    $year = (int)$_POST['year'];
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $selected_subjects = $_POST['subjects'] ?? [];
    
    // Handle backward compatibility: if class_level doesn't include year prefix, add it
    if ($class_level && !preg_match('/^[12][ABC]$/', $class_level)) {
        if (in_array($class_level, ['A', 'B', 'C'])) {
            $class_level = $year . $class_level;
        }
    }
    
    if (!empty($name) && !empty($class_level) && !empty($gender) && $age > 0 && !empty($year)) {
        // Insert student record
        $query = "INSERT INTO students (name, age, gender, class_level, year, phone) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id";
        $result = pg_query_params($conn, $query, array($name, $age, $gender, $class_level, $year, $phone));
        
        if (!$result) {
            $error = pg_last_error($conn);
            error_log("Database error in add_student: " . $error);
            ob_end_clean();
            echo "Error adding student: " . $error;
            exit;
        }
        
        if ($result) {
            $row = pg_fetch_assoc($result);
            $student_id = $row['id'];
            
            // Enroll student in selected subjects (initialize with 0 marks)
            if (!empty($selected_subjects)) {
                foreach ($selected_subjects as $subject_id) {
                    $subject_id = (int)$subject_id;
                    $marks_query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
                    pg_query_params($conn, $marks_query, array($student_id, $subject_id, 0, 0, 0, 0, 0, 'Pending'));
                }
            }
            
            $success_count = count($selected_subjects);
            $success_msg = "Student added successfully!";
            if ($success_count > 0) {
                $success_msg .= " Enrolled in $success_count subject(s).";
            }
            
            ob_end_clean();
            echo $success_msg;
            exit;
        } else {
            ob_end_clean();
            echo "Error adding student: " . pg_last_error($conn);
            exit;
        }
    } else {
        ob_end_clean();
        echo "Error: Name, age, gender, and class level are required.";
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'add_subject') {
    // ADMIN ONLY: Teachers cannot add subjects
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can add subjects.']);
        exit;
    }
    
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $year = (int)$_POST['year'];
    
    if (!empty($subject_name) && $credits > 0 && !empty($year)) {
        $query = "INSERT INTO subjects (subject_name, description, credits, year) VALUES ($1, $2, $3, $4)";
        $result = pg_query_params($conn, $query, array($subject_name, $description, $credits, $year));
        
        if ($result) {
            ob_end_clean();
            echo "Subject added successfully!";
            exit;
        } else {
            ob_end_clean();
            echo "Error adding subject: " . pg_last_error($conn);
            exit;
        }
    } else {
        ob_end_clean();
        echo "Error: Subject name and credits are required.";
        exit;
    }
}

// Teacher Management Actions
if (isset($_POST['action']) && $_POST['action'] === 'add_teacher') {
    // ADMIN ONLY: Teachers cannot add other teachers
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can add teachers.']);
        exit;
    }
    
    $name = trim($_POST['teacher_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $specialization = trim($_POST['specialization']);
    $degree = trim($_POST['degree']);
    $salary = !empty($_POST['salary']) ? (int)$_POST['salary'] : null;
    $join_date = trim($_POST['join_date']);
    $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $year = !empty($_POST['year']) ? (int)$_POST['year'] : null;
    $class_level = !empty($_POST['class_level']) ? trim($_POST['class_level']) : null;
    
    // Generate username from name (e.g., "Ahmed Ali" -> "ahmed.ali@school.edu")
    $username = strtolower(str_replace(' ', '.', $name)) . '@school.edu';
    
    // Generate temporary password (teacher should change it on first login)
    $temp_password = 'teacher' . rand(1000, 9999);
    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
    
    if (!empty($name) && !empty($email) && !empty($specialization) && !empty($degree)) {
        // Check if email already exists
        $check_query = "SELECT id FROM teachers WHERE email = $1";
        $check_result = pg_query_params($conn, $check_query, array($email));
        
        if (pg_num_rows($check_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists!']);
            exit;
        }
        
        // Check if username already exists
        $check_username = "SELECT id FROM teachers WHERE username = $1";
        $check_username_result = pg_query_params($conn, $check_username, array($username));
        
        if (pg_num_rows($check_username_result) > 0) {
            // Add number suffix to make it unique
            $username = strtolower(str_replace(' ', '.', $name)) . rand(100, 999) . '@school.edu';
        }
        
        // Start transaction
        pg_query($conn, "BEGIN");
        
        $query = "INSERT INTO teachers (name, email, phone, specialization, degree, salary, join_date, username, password, role, is_active, created_by) 
                  VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 'teacher', true, $10) RETURNING id";
        $result = pg_query_params($conn, $query, array(
            $name, $email, $phone, $specialization, $degree, $salary, $join_date, 
            $username, $hashed_password, $_SESSION['user_id']
        ));
        
        if ($result) {
            $teacher_id = pg_fetch_assoc($result)['id'];
            
            // If subject is assigned, add it to teacher_subjects
            if ($subject_id && $year && $class_level) {
                $assign_query = "INSERT INTO teacher_subjects (teacher_id, subject_id, year, class_level) VALUES ($1, $2, $3, $4)";
                $assign_result = pg_query_params($conn, $assign_query, array($teacher_id, $subject_id, $year, $class_level));
                
                if (!$assign_result) {
                    pg_query($conn, "ROLLBACK");
                    echo json_encode(['success' => false, 'message' => 'Error assigning subject: ' . pg_last_error($conn)]);
                    exit;
                }
            }
            
            // Log the action
            logAction('create_teacher', 'teachers', $teacher_id, null, ['name' => $name, 'username' => $username]);
            
            pg_query($conn, "COMMIT");
            echo json_encode([
                'success' => true, 
                'message' => 'Teacher added successfully!',
                'credentials' => [
                    'username' => $username,
                    'password' => $temp_password
                ]
            ]);
            exit;
        } else {
            pg_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'message' => 'Error adding teacher: ' . pg_last_error($conn)]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Name, email, specialization, and degree are required.']);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update_teacher') {
    // ADMIN ONLY: Teachers cannot update other teachers
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can update teachers.']);
        exit;
    }
    
    $teacher_id = (int)$_POST['teacher_id'];
    $name = trim($_POST['teacher_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $specialization = trim($_POST['specialization']);
    $degree = trim($_POST['degree']);
    $salary = !empty($_POST['salary']) ? (int)$_POST['salary'] : null;
    $join_date = trim($_POST['join_date']);
    
    if ($teacher_id > 0 && !empty($name) && !empty($email) && !empty($specialization) && !empty($degree)) {
        // Check if email already exists for other teachers
        $check_query = "SELECT id FROM teachers WHERE email = $1 AND id != $2";
        $check_result = pg_query_params($conn, $check_query, array($email, $teacher_id));
        
        if (pg_num_rows($check_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists!']);
            exit;
        }
        
        $query = "UPDATE teachers SET name = $1, email = $2, phone = $3, specialization = $4, degree = $5, salary = $6, join_date = $7 WHERE id = $8";
        $result = pg_query_params($conn, $query, array($name, $email, $phone, $specialization, $degree, $salary, $join_date, $teacher_id));
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Teacher updated successfully!']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating teacher: ' . pg_last_error($conn)]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_teacher') {
    // ADMIN ONLY: Teachers cannot delete other teachers
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can delete teachers.']);
        exit;
    }
    
    $teacher_id = (int)$_POST['teacher_id'];
    
    if ($teacher_id > 0) {
        // Start transaction
        pg_query($conn, "BEGIN");
        
        // Step 1: Delete teacher (assignments will be cascade deleted due to ON DELETE CASCADE)
        $query = "DELETE FROM teachers WHERE id = $1";
        $result = pg_query_params($conn, $query, array($teacher_id));
        
        if (!$result) {
            pg_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'message' => 'Error deleting teacher: ' . pg_last_error($conn)]);
            exit;
        }
        
        // Step 2: Temporarily drop foreign key constraint
        pg_query($conn, "ALTER TABLE teacher_subjects DROP CONSTRAINT IF EXISTS teacher_subjects_teacher_id_fkey");
        
        // Step 3: Renumber teachers with ID > deleted ID
        $update_teachers_query = "UPDATE teachers SET id = id - 1 WHERE id > $1";
        $update_result = pg_query_params($conn, $update_teachers_query, array($teacher_id));
        
        if (!$update_result) {
            pg_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'message' => 'Error renumbering teachers: ' . pg_last_error($conn)]);
            exit;
        }
        
        // Step 4: Update teacher_subjects references
        $update_subjects_query = "UPDATE teacher_subjects SET teacher_id = teacher_id - 1 WHERE teacher_id > $1";
        $update_subjects_result = pg_query_params($conn, $update_subjects_query, array($teacher_id));
        
        if (!$update_subjects_result) {
            pg_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'message' => 'Error renumbering teacher subjects: ' . pg_last_error($conn)]);
            exit;
        }
        
        // Step 5: Restore foreign key constraint
        pg_query($conn, "ALTER TABLE teacher_subjects ADD CONSTRAINT teacher_subjects_teacher_id_fkey FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE");
        
        // Step 6: Reset sequence to MAX(id) + 1
        $max_id_result = pg_query($conn, "SELECT COALESCE(MAX(id), 0) as max_id FROM teachers");
        $max_row = pg_fetch_assoc($max_id_result);
        $next_id = $max_row['max_id'] + 1;
        pg_query($conn, "ALTER SEQUENCE teachers_id_seq RESTART WITH $next_id");
        
        // Commit transaction
        pg_query($conn, "COMMIT");
        
        echo json_encode(['success' => true, 'message' => 'Teacher deleted successfully! IDs renumbered.']);
        exit;
    }
}

// Generate/Update login credentials for existing teacher (Manual Entry)
if (isset($_POST['action']) && $_POST['action'] === 'generate_teacher_credentials') {
    // ADMIN ONLY: Teachers cannot generate credentials
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can manage credentials.']);
        exit;
    }
    
    $teacher_id = (int)$_POST['teacher_id'];
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if ($teacher_id > 0 && !empty($username)) {
        // Get teacher details
        $check_query = "SELECT id, name, username, password FROM teachers WHERE id = $1";
        $check_result = pg_query_params($conn, $check_query, array($teacher_id));
        
        if (pg_num_rows($check_result) === 0) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found!']);
            exit;
        }
        
        $teacher_data = pg_fetch_assoc($check_result);
        $has_existing_password = !empty($teacher_data['password']);
        
        // Check if username already exists for other teachers
        $check_username = "SELECT id FROM teachers WHERE username = $1 AND id != $2";
        $username_result = pg_query_params($conn, $check_username, array($username, $teacher_id));
        
        if (pg_num_rows($username_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists for another teacher!']);
            exit;
        }
        
        // Validate password if provided or if creating new credentials
        if (!empty($password)) {
            // New password provided - validate and hash it
            if (strlen($password) < 6) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
                exit;
            }
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Store encrypted password for admin viewing (base64 encoded)
            $encrypted_password = base64_encode($password);
            
            // Update with new password and encrypted backup
            $update_query = "UPDATE teachers SET username = $1, password = $2, password_plain = $3, role = 'teacher', is_active = true WHERE id = $4";
            $update_result = pg_query_params($conn, $update_query, array($username, $hashed_password, $encrypted_password, $teacher_id));
        } elseif ($has_existing_password) {
            // No new password provided, but teacher has existing password - keep it
            $update_query = "UPDATE teachers SET username = $1, role = 'teacher', is_active = true WHERE id = $2";
            $update_result = pg_query_params($conn, $update_query, array($username, $teacher_id));
        } else {
            // No password provided and no existing password - error
            echo json_encode(['success' => false, 'message' => 'Password is required for new credentials.']);
            exit;
        }
        
        if ($update_result) {
            // Log the action
            logAction('update_credentials', 'teachers', $teacher_id, 
                      ['username' => $teacher_data['username']], 
                      ['username' => $username]);
            
            $message = !empty($password) ? 'Credentials updated successfully!' : 'Username updated successfully!';
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Error saving credentials: ' . pg_last_error($conn)]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Please provide all required fields.']);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'remove_teacher_assignment') {
    $assignment_id = (int)$_POST['assignment_id'];
    
    if ($assignment_id > 0) {
        $query = "DELETE FROM teacher_subjects WHERE id = $1";
        $result = pg_query_params($conn, $query, array($assignment_id));
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Assignment removed successfully!']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Error removing assignment: ' . pg_last_error($conn)]);
            exit;
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'assign_subject_to_teacher') {
    $teacher_id = (int)$_POST['teacher_id'];
    $subject_id = (int)$_POST['subject_id'];
    $year = (int)$_POST['year'];
    $class_level = trim($_POST['class_level']);
    
    if ($teacher_id > 0 && $subject_id > 0 && $year > 0 && !empty($class_level)) {
        // Check if assignment already exists
        $check_query = "SELECT id FROM teacher_subjects WHERE teacher_id = $1 AND subject_id = $2 AND year = $3 AND class_level = $4";
        $check_result = pg_query_params($conn, $check_query, array($teacher_id, $subject_id, $year, $class_level));
        
        if (pg_num_rows($check_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Assignment already exists!']);
            exit;
        }
        
        $query = "INSERT INTO teacher_subjects (teacher_id, subject_id, year, class_level) VALUES ($1, $2, $3, $4)";
        $result = pg_query_params($conn, $query, array($teacher_id, $subject_id, $year, $class_level));
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Subject assigned successfully!']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Error assigning subject: ' . pg_last_error($conn)]);
            exit;
        }
    }
}

// AJAX handler to get teacher data
if (isset($_GET['action']) && $_GET['action'] === 'get_teacher' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $teacher_id = (int)$_GET['id'];
    
    if ($teacher_id > 0) {
        $query = "SELECT * FROM teachers WHERE id = $1";
        $result = pg_query_params($conn, $query, array($teacher_id));
        
        if ($result && pg_num_rows($result) > 0) {
            $teacher = pg_fetch_assoc($result);
            echo json_encode(['success' => true, 'teacher' => $teacher]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
    }
    exit;
}

// AJAX handler to get teacher subjects
if (isset($_GET['action']) && $_GET['action'] === 'get_teacher_subjects' && isset($_GET['teacher_id'])) {
    header('Content-Type: application/json');
    $teacher_id = (int)$_GET['teacher_id'];
    
    if ($teacher_id > 0) {
        // Get teacher info
        $teacher_query = "SELECT id, name, email FROM teachers WHERE id = $1";
        $teacher_result = pg_query_params($conn, $teacher_query, array($teacher_id));
        
        if ($teacher_result && pg_num_rows($teacher_result) > 0) {
            $teacher = pg_fetch_assoc($teacher_result);
            
            // Get teacher assignments with class-wise student breakdown
            $assignments_query = "SELECT ts.id, ts.subject_id, s.subject_name, ts.year, ts.class_level,
                                        TO_CHAR(ts.assigned_date, 'Mon DD, YYYY') as assigned_date,
                                        (SELECT json_agg(json_build_object('class', class_breakdown.class_level, 'count', class_breakdown.student_count))
                                         FROM (
                                             SELECT st.class_level, COUNT(DISTINCT st.id) as student_count
                                             FROM students st
                                             INNER JOIN marks m ON st.id = m.student_id
                                             WHERE m.subject_id = ts.subject_id 
                                               AND st.year = ts.year
                                             GROUP BY st.class_level
                                             ORDER BY st.class_level
                                         ) as class_breakdown
                                        ) as class_breakdown,
                                        (SELECT COUNT(DISTINCT st.id)
                                         FROM students st
                                         INNER JOIN marks m ON st.id = m.student_id
                                         WHERE m.subject_id = ts.subject_id 
                                           AND st.year = ts.year
                                        ) as student_count
                                 FROM teacher_subjects ts
                                 JOIN subjects s ON ts.subject_id = s.id
                                 WHERE ts.teacher_id = $1
                                 ORDER BY ts.year, ts.class_level, s.subject_name";
            $assignments_result = pg_query_params($conn, $assignments_query, array($teacher_id));
            
            $assignments = [];
            if ($assignments_result) {
                $assignments = pg_fetch_all($assignments_result) ?: [];
            }
            
            echo json_encode(['success' => true, 'teacher' => $teacher, 'assignments' => $assignments]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
    }
    exit;
}

// AJAX handler to get available subjects for teacher assignment
if (isset($_GET['action']) && $_GET['action'] === 'get_available_subjects') {
    header('Content-Type: application/json');
    
    $query = "SELECT id, subject_name, year FROM subjects ORDER BY year, subject_name";
    $result = pg_query($conn, $query);
    
    if ($result) {
        $subjects = pg_fetch_all($result);
        echo json_encode(['success' => true, 'subjects' => $subjects ?: []]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to load subjects']);
    }
    exit;
}


if (isset($_POST['action']) && $_POST['action'] === 'add_mark') {
    $student_id = (int)$_POST['student_id'];
    $subject_id = (int)$_POST['subject_id'];
    $final_exam = (int)$_POST['final_exam'];
    $midterm_exam = (int)$_POST['midterm_exam'];
    $quizzes = (int)$_POST['quizzes'];
    $daily_activities = (int)$_POST['daily_activities'];
    
    // Calculate total mark
    $total_mark = $final_exam + $midterm_exam + $quizzes + $daily_activities;
    
    // Get subject credits to calculate final grade
    $credits_query = "SELECT credits FROM subjects WHERE id = $1";
    $credits_result = pg_query_params($conn, $credits_query, array($subject_id));
    $credits = pg_fetch_assoc($credits_result)['credits'] ?? 0;
    
    // Calculate final grade: total_mark × (credits / 100)
    $final_grade = $total_mark * ($credits / 100.0);
    
    // Determine status
    $status = $total_mark >= 50 ? 'Pass' : 'Fail';
    
    if ($student_id > 0 && $subject_id > 0) {
        // Check if mark already exists
        $check_query = "SELECT id FROM marks WHERE student_id = $1 AND subject_id = $2";
        $check_result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
        
        if (pg_num_rows($check_result) > 0) {
            // Update existing mark
            $query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6, final_grade = $7 WHERE student_id = $8 AND subject_id = $9";
            $result = pg_query_params($conn, $query, array($final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status, $final_grade, $student_id, $subject_id));
            $message = "Mark updated successfully!";
        } else {
            // Insert new mark
            $query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status, final_grade) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)";
            $result = pg_query_params($conn, $query, array($student_id, $subject_id, $final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status, $final_grade));
            $message = "Mark added successfully!";
        }
        
        if ($result) {
            header("Location: ?page=marks&success=" . urlencode($message));
            exit;
        } else {
            $error_message = "Error adding mark: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Student and subject are required.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update_student') {
    // ADMIN ONLY: Teachers cannot update students
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can update students.']);
        exit;
    }
    
    $student_id = (int)$_POST['student_id'];
    $name = trim($_POST['student_name']);
    $age = (int)$_POST['age'];
    $gender = trim($_POST['gender']);
    $class_level = trim($_POST['class_level']);
    $year = (int)$_POST['year'];
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $selected_subjects = $_POST['subjects'] ?? [];
    
    // Handle backward compatibility: if class_level doesn't include year prefix, add it
    if ($class_level && !preg_match('/^[12][ABC]$/', $class_level)) {
        if (in_array($class_level, ['A', 'B', 'C'])) {
            $class_level = $year . $class_level;
        }
    }
    
    if (!empty($name) && !empty($class_level) && !empty($gender) && $age > 0 && !empty($year)) {
        // Update student record
        $query = "UPDATE students SET name = $1, age = $2, gender = $3, class_level = $4, year = $5, phone = $6 WHERE id = $7";
        $result = pg_query_params($conn, $query, array($name, $age, $gender, $class_level, $year, $phone, $student_id));
        
        if (!$result) {
            $error = pg_last_error($conn);
            error_log("Database error in update_student: " . $error);
            ob_end_clean();
            echo "Error updating student: " . $error;
            exit;
        }
        
        if ($result) {
            // Handle subject enrollment changes
            
            // Get currently enrolled subjects
            $current_subjects_query = "SELECT DISTINCT subject_id FROM marks WHERE student_id = $1";
            $current_subjects_result = pg_query_params($conn, $current_subjects_query, array($student_id));
            $current_subjects = [];
            
            if ($current_subjects_result) {
                while ($row = pg_fetch_assoc($current_subjects_result)) {
                    $current_subjects[] = $row['subject_id'];
                }
            }
            
            // Convert selected subjects to integers
            $selected_subjects = array_map('intval', $selected_subjects);
            
            // Find subjects to remove (were enrolled but now unchecked)
            $subjects_to_remove = array_diff($current_subjects, $selected_subjects);
            foreach ($subjects_to_remove as $subject_id) {
                $delete_marks_query = "DELETE FROM marks WHERE student_id = $1 AND subject_id = $2";
                pg_query_params($conn, $delete_marks_query, array($student_id, $subject_id));
            }
            
            // Find subjects to add (newly checked)
            $subjects_to_add = array_diff($selected_subjects, $current_subjects);
            foreach ($subjects_to_add as $subject_id) {
                $insert_marks_query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
                pg_query_params($conn, $insert_marks_query, array($student_id, $subject_id, 0, 0, 0, 0, 0, 'Pending'));
            }
            
            $changes_count = count($subjects_to_remove) + count($subjects_to_add);
            $success_msg = "Student updated successfully!";
            if ($changes_count > 0) {
                $success_msg .= " Subject enrollments updated: " . count($subjects_to_add) . " added, " . count($subjects_to_remove) . " removed.";
            }
            
            ob_end_clean();
            echo $success_msg;
            exit;
        } else {
            ob_end_clean();
            echo "Error updating student: " . pg_last_error($conn);
            exit;
        }
    } else {
        ob_end_clean();
        echo "Error: Name, age, gender, and class level are required.";
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    // ADMIN ONLY: Teachers cannot delete students
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can delete students.']);
        exit;
    }
    
    $student_id = (int)$_POST['student_id'];
    
    if ($student_id > 0) {
        // Start transaction
        pg_query($conn, "BEGIN");
        
        // Step 1: Delete all marks for this student
        $delete_marks_query = "DELETE FROM marks WHERE student_id = $1";
        $marks_result = pg_query_params($conn, $delete_marks_query, array($student_id));
        
        if (!$marks_result) {
            pg_query($conn, "ROLLBACK");
            ob_end_clean();
            echo "Error deleting marks: " . pg_last_error($conn);
            exit;
        }
        
        // Step 2: Delete the student
        $delete_student_query = "DELETE FROM students WHERE id = $1";
        $result = pg_query_params($conn, $delete_student_query, array($student_id));
        
        if (!$result) {
            pg_query($conn, "ROLLBACK");
            ob_end_clean();
            echo "Error deleting student: " . pg_last_error($conn);
            exit;
        }
        
        // Step 3: Renumber only students with ID > deleted ID
        // First, disable foreign key constraint temporarily
        pg_query($conn, "ALTER TABLE marks DROP CONSTRAINT IF EXISTS marks_student_id_fkey");
        
        // Update students table (decrement IDs)
        $update_students_query = "UPDATE students SET id = id - 1 WHERE id > $1";
        $update_result = pg_query_params($conn, $update_students_query, array($student_id));
        
        if (!$update_result) {
            pg_query($conn, "ROLLBACK");
            ob_end_clean();
            echo "Error renumbering students: " . pg_last_error($conn);
            exit;
        }
        
        // Update marks table (decrement student_id references)
        $update_marks_query = "UPDATE marks SET student_id = student_id - 1 WHERE student_id > $1";
        $update_marks_result = pg_query_params($conn, $update_marks_query, array($student_id));
        
        if (!$update_marks_result) {
            pg_query($conn, "ROLLBACK");
            ob_end_clean();
            echo "Error renumbering marks: " . pg_last_error($conn);
            exit;
        }
        
        // Re-enable foreign key constraint
        pg_query($conn, "ALTER TABLE marks ADD CONSTRAINT marks_student_id_fkey FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
        
        // Step 4: Reset sequence to MAX(id) + 1
        $max_id_result = pg_query($conn, "SELECT COALESCE(MAX(id), 0) as max_id FROM students");
        $max_row = pg_fetch_assoc($max_id_result);
        $next_id = $max_row['max_id'] + 1;
        pg_query($conn, "ALTER SEQUENCE students_id_seq RESTART WITH $next_id");
        
        // Commit transaction
        pg_query($conn, "COMMIT");
        
        ob_end_clean();
        echo "Student deleted successfully! IDs renumbered.";
        exit;
    } else {
        ob_end_clean();
        echo "Error: Invalid student ID.";
        exit;
    }
}

// Handle manual resequencing (for testing)
if (isset($_POST['action']) && $_POST['action'] === 'manual_resequence') {
    $table_name = $_POST['table_name'] ?? '';
    
    if (in_array($table_name, ['students', 'subjects', 'marks', 'graduated_students'])) {
        if (resequenceTable($conn, $table_name)) {
            $success_message = ucfirst($table_name) . " IDs resequenced successfully!";
        } else {
            $error_message = "Error resequencing " . $table_name . " IDs.";
        }
    } else {
        $error_message = "Invalid table name for resequencing.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update_subject') {
    // ADMIN ONLY: Teachers cannot update subjects
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can update subjects.']);
        exit;
    }
    
    $subject_id = (int)$_POST['subject_id'];
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $year = (int)$_POST['year'];
    
    if (!empty($subject_name) && $credits > 0 && $year > 0) {
        $query = "UPDATE subjects SET subject_name = $1, description = $2, credits = $3, year = $4 WHERE id = $5";
        $result = pg_query_params($conn, $query, array($subject_name, $description, $credits, $year, $subject_id));
        
        if ($result) {
            ob_end_clean();
            echo "Subject updated successfully!";
            exit;
        } else {
            ob_end_clean();
            echo "Error updating subject: " . pg_last_error($conn);
            exit;
        }
    } else {
        ob_end_clean();
        echo "Error: Subject name, credits, and academic year are required.";
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_subject') {
    // ADMIN ONLY: Teachers cannot delete subjects
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only administrators can delete subjects.']);
        exit;
    }
    
    $subject_id = (int)$_POST['subject_id'];
    
    if ($subject_id > 0) {
        // First delete all marks for this subject
        $delete_marks_query = "DELETE FROM marks WHERE subject_id = $1";
        pg_query_params($conn, $delete_marks_query, array($subject_id));
        
        // Then delete the subject
        $delete_subject_query = "DELETE FROM subjects WHERE id = $1";
        $result = pg_query_params($conn, $delete_subject_query, array($subject_id));
        
        if ($result) {
            // Resequence IDs after deletion
            resequenceTable($conn, 'subjects');
            ob_end_clean();
            echo "Subject deleted successfully!";
            exit;
        } else {
            ob_end_clean();
            echo "Error deleting subject: " . pg_last_error($conn);
            exit;
        }
    } else {
        ob_end_clean();
        echo "Error: Invalid subject ID.";
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update_mark_record') {
    $mark_id = (int)$_POST['mark_id'];
    $final_exam = (int)$_POST['final_exam'];
    $midterm_exam = (int)$_POST['midterm_exam'];
    $quizzes = (int)$_POST['quizzes'];
    $daily_activities = (int)$_POST['daily_activities'];
    
    // Calculate total
    $total_mark = $final_exam + $midterm_exam + $quizzes + $daily_activities;
    
    // Validate marks
    if ($final_exam < 0 || $final_exam > 60 ||
        $midterm_exam < 0 || $midterm_exam > 20 ||
        $quizzes < 0 || $quizzes > 10 ||
        $daily_activities < 0 || $daily_activities > 10) {
        $error_message = "Invalid mark ranges. Please check the limits for each field.";
    } else {
        // Get subject credits to calculate final grade
        $credits_query = "SELECT s.credits FROM subjects s INNER JOIN marks m ON m.subject_id = s.id WHERE m.id = $1";
        $credits_result = pg_query_params($conn, $credits_query, array($mark_id));
        $credits = pg_fetch_assoc($credits_result)['credits'] ?? 0;
        
        // Calculate final grade: total_mark × (credits / 100)
        $final_grade = $total_mark * ($credits / 100.0);
        
        // Determine status
        $status = $total_mark >= 50 ? 'Pass' : 'Fail';
        
        $query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6, final_grade = $7 WHERE id = $8";
        $result = pg_query_params($conn, $query, array($final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status, $final_grade, $mark_id));
        
        if ($result) {
            header("Location: ?page=marks&success=" . urlencode("Mark updated successfully!"));
            exit;
        } else {
            $error_message = "Error updating mark: " . pg_last_error($conn);
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_mark') {
    // PERMISSION CHECK: Only teachers can delete/reset marks, not admins
    if (!isTeacher()) {
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only teachers can reset marks. Administrators have view-only access.']);
        exit;
    }
    
    $mark_id = (int)$_POST['mark_id'];
    
    if ($mark_id > 0) {
        // Get subject_id and student_id from mark to check permissions
        $mark_check = pg_query_params($conn, "SELECT student_id, subject_id FROM marks WHERE id = $1", array($mark_id));
        
        if (!$mark_check || pg_num_rows($mark_check) === 0) {
            echo "Invalid mark ID.";
            exit;
        }
        
        $mark_data = pg_fetch_assoc($mark_check);
        $student_id = $mark_data['student_id'];
        $subject_id = $mark_data['subject_id'];
        $teacher_id = $_SESSION['teacher_id'];
        
        // Check if teacher can edit this mark
        if (!canEditStudentMarks($teacher_id, $student_id, $subject_id)) {
            echo "Access Denied: You cannot reset marks for subjects you don't teach.";
            exit;
        }
        
        // Instead of deleting, reset all marks to 0
        $reset_query = "UPDATE marks 
                        SET final_exam = 0, 
                            midterm_exam = 0, 
                            quizzes = 0, 
                            daily_activities = 0, 
                            mark = 0, 
                            final_grade = 0, 
                            status = 'Fail'
                        WHERE id = $1";
        $result = pg_query_params($conn, $reset_query, array($mark_id));
        
        if ($result) {
            // Log the action
            logAction('reset_mark', 'marks', $mark_id, null, ['student_id' => $student_id, 'subject_id' => $subject_id]);
            
            echo "Mark reset successfully!";
            exit;
        } else {
            echo "Error resetting mark: " . pg_last_error($conn);
            exit;
        }
    } else {
        echo "Invalid mark ID.";
        exit;
    }
}

// Handle getting Year 2 subjects for promotion
// AJAX endpoint to check promotion eligibility
if (isset($_POST['action']) && $_POST['action'] === 'check_promotion_eligibility') {
    header('Content-Type: application/json');
    
    $student_id = (int)$_POST['student_id'];
    
    if ($student_id > 0) {
        $eligibility = checkPromotionEligibility($conn, $student_id);
        echo json_encode($eligibility);
    } else {
        echo json_encode([
            'eligible' => false,
            'message' => 'Invalid student ID',
            'details' => []
        ]);
    }
    
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'get_year2_subjects') {
    header('Content-Type: application/json');
    
    $subjects_query = "SELECT id, subject_name, credits FROM subjects WHERE year = 2 ORDER BY subject_name";
    $subjects_result = pg_query($conn, $subjects_query);
    
    if ($subjects_result) {
        $subjects = [];
        while ($subject = pg_fetch_assoc($subjects_result)) {
            $subjects[] = $subject;
        }
        echo json_encode($subjects);
    } else {
        echo json_encode(['error' => 'Error loading subjects: ' . pg_last_error($conn)]);
    }
    
    exit;
}

// Handle student promotion with subject selection
if (isset($_POST['action']) && $_POST['action'] === 'promote_student_with_subjects') {
    $student_id = (int)$_POST['student_id'];
    $selected_subjects = json_decode($_POST['selected_subjects'], true);
    
    if ($student_id > 0 && is_array($selected_subjects)) {
        // Get student details
        $student_query = "SELECT * FROM students WHERE id = $1 AND year = 1 AND status = 'active'";
        $student_result = pg_query_params($conn, $student_query, array($student_id));
        
        if ($student_result && pg_num_rows($student_result) > 0) {
            $student = pg_fetch_assoc($student_result);
            
            // Check promotion eligibility FIRST
            $eligibility = checkPromotionEligibility($conn, $student_id);
            
            if (!$eligibility['eligible']) {
                // Return JSON error with details
                echo json_encode([
                    'success' => false,
                    'message' => $eligibility['message'],
                    'details' => $eligibility['details']
                ]);
                exit;
            }
            
            // Student is eligible, proceed with promotion
            // Begin transaction
            pg_query($conn, "BEGIN");
            
            try {
                // Update student to Year 2
                $update_query = "UPDATE students SET year = 2 WHERE id = $1";
                $update_result = pg_query_params($conn, $update_query, array($student_id));
                
                if (!$update_result) {
                    throw new Exception("Error updating student year");
                }
                
                // Record promotion history
                $history_query = "INSERT INTO promotion_history (student_id, from_year, to_year, promotion_date) VALUES ($1, 1, 2, CURRENT_DATE)";
                pg_query_params($conn, $history_query, array($student_id));
                
                // Create marks entries for selected Year 2 subjects
                foreach ($selected_subjects as $subject_id) {
                    $mark_query = "INSERT INTO marks (student_id, subject_id) VALUES ($1, $2)";
                    $mark_result = pg_query_params($conn, $mark_query, array($student_id, $subject_id));
                    
                    if (!$mark_result) {
                        throw new Exception("Error creating mark entry for subject ID: $subject_id");
                    }
                }
                
                // Commit transaction
                pg_query($conn, "COMMIT");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Student promoted to Year 2!',
                    'details' => $eligibility['details']
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback on error
                pg_query($conn, "ROLLBACK");
                echo json_encode([
                    'success' => false,
                    'message' => 'Error promoting student: ' . $e->getMessage()
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Student not found or not eligible for promotion (must be active Year 1 student).'
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid student ID or subject selection.'
        ]);
        exit;
    }
}

// Handle student promotion (Year 1 to Year 2)
if (isset($_POST['action']) && $_POST['action'] === 'promote_student') {
    $student_id = (int)$_POST['student_id'];
    
    if ($student_id > 0) {
        // Get student details
        $student_query = "SELECT * FROM students WHERE id = $1 AND year = 1 AND status = 'active'";
        $student_result = pg_query_params($conn, $student_query, array($student_id));
        
        if ($student_result && pg_num_rows($student_result) > 0) {
            $student = pg_fetch_assoc($student_result);
            
            // Check promotion eligibility
            $eligibility = checkPromotionEligibility($conn, $student_id);
            
            if (!$eligibility['eligible']) {
                // Return JSON error with details
                echo json_encode([
                    'success' => false,
                    'message' => $eligibility['message'],
                    'details' => $eligibility['details']
                ]);
                exit;
            }
            
            // Student is eligible, proceed with promotion
            $update_query = "UPDATE students SET year = 2 WHERE id = $1";
            $update_result = pg_query_params($conn, $update_query, array($student_id));
            
            if ($update_result) {
                // Record promotion history
                $history_query = "INSERT INTO promotion_history (student_id, from_year, to_year, promotion_date) VALUES ($1, 1, 2, CURRENT_DATE)";
                pg_query_params($conn, $history_query, array($student_id));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Student promoted to Year 2 successfully!',
                    'details' => $eligibility['details']
                ]);
                exit;
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error promoting student: ' . pg_last_error($conn)
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Student not found or not eligible for promotion (must be active Year 1 student).'
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid student ID.'
        ]);
        exit;
    }
}

// Handle student graduation (Year 2 students)
if (isset($_POST['action']) && $_POST['action'] === 'graduate_student') {
    $student_id = (int)$_POST['student_id'];
    
    if ($student_id > 0) {
        // Get student details
        $student_query = "SELECT * FROM students WHERE id = $1 AND year = 2 AND status = 'active'";
        $student_result = pg_query_params($conn, $student_query, array($student_id));
        
        if ($student_result && pg_num_rows($student_result) > 0) {
            $student = pg_fetch_assoc($student_result);
            
            // Check graduation eligibility (Year 2 only)
            $eligibility = checkGraduationEligibility($conn, $student_id);
            
            if (!$eligibility['eligible']) {
                // Return JSON error with details
                echo json_encode([
                    'success' => false,
                    'message' => $eligibility['message'],
                    'details' => $eligibility['details']
                ]);
                exit;
            }
            
            // Student is eligible, calculate full graduation grade for records
            $graduation_data = calculateGraduationGrade($conn, $student_id);
            $graduation_grade = $graduation_data['graduation_grade'];
            
            // Insert into graduated_students table with graduation grade
            $graduate_query = "INSERT INTO graduated_students (student_id, student_name, age, gender, class_level, phone, graduation_date, final_year, graduation_grade) 
                              VALUES ($1, $2, $3, $4, $5, $6, CURRENT_DATE, 2, $7)";
            $graduate_result = pg_query_params($conn, $graduate_query, array(
                $student['id'], $student['name'], $student['age'], $student['gender'], 
                $student['class_level'], $student['phone'], $graduation_grade
            ));
            
            if ($graduate_result) {
                // Update student status to graduated
                $update_query = "UPDATE students SET status = 'graduated' WHERE id = $1";
                pg_query_params($conn, $update_query, array($student_id));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Student graduated successfully! Final Grade: ' . round($graduation_grade, 2) . '/100',
                    'details' => [
                        'graduation_grade' => round($graduation_grade, 2),
                        'year1_grade' => $graduation_data['year1_grade'],
                        'year2_grade' => $graduation_data['year2_grade']
                    ]
                ]);
                exit;
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error graduating student: ' . pg_last_error($conn)
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Student not found or not eligible for graduation (must be active Year 2 student).'
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid student ID.'
        ]);
        exit;
    }
}

// Handle count eligible students
if (isset($_GET['action']) && $_GET['action'] === 'count_eligible_students') {
    header('Content-Type: application/json');
    $year = (int)$_GET['year'];
    
    // Get all students of the specified year
    $students_query = "SELECT id FROM students WHERE year = $1 AND status = 'active'";
    $students_result = pg_query_params($conn, $students_query, array($year));
    
    $total = 0;
    $eligible = 0;
    
    while ($student = pg_fetch_assoc($students_result)) {
        $student_id = $student['id'];
        $total++;
        
        // Check eligibility based on year
        if ($year == 1) {
            $eligibility = checkPromotionEligibility($conn, $student_id);
        } else {
            $eligibility = checkGraduationEligibility($conn, $student_id);
        }
        
        if ($eligibility['eligible']) {
            $eligible++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'year' => $year,
        'total' => $total,
        'eligible' => $eligible
    ]);
    exit;
}

// Handle bulk promotion
if (isset($_POST['action']) && $_POST['action'] === 'bulk_promotion') {
    header('Content-Type: application/json');
    $year = (int)$_POST['year'];
    
    if ($year !== 1) {
        echo json_encode(['success' => false, 'message' => 'Bulk promotion is only for Year 1 students.']);
        exit;
    }
    
    // Get all Year 1 students
    $students_query = "SELECT id FROM students WHERE year = 1 AND status = 'active'";
    $students_result = pg_query($conn, $students_query);
    
    $promoted = 0;
    $skipped = 0;
    $processed = 0;
    
    while ($student = pg_fetch_assoc($students_result)) {
        $student_id = $student['id'];
        $processed++;
        
        // Check promotion eligibility
        $eligibility = checkPromotionEligibility($conn, $student_id);
        
        if ($eligibility['eligible']) {
            // Promote student
            $update_query = "UPDATE students SET year = 2 WHERE id = $1";
            $result = pg_query_params($conn, $update_query, array($student_id));
            
            if ($result) {
                $promoted++;
            }
        } else {
            $skipped++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'promoted' => $promoted,
        'skipped' => $skipped
    ]);
    exit;
}

// Handle bulk graduation
if (isset($_POST['action']) && $_POST['action'] === 'bulk_graduation') {
    header('Content-Type: application/json');
    $year = (int)$_POST['year'];
    
    if ($year !== 2) {
        echo json_encode(['success' => false, 'message' => 'Bulk graduation is only for Year 2 students.']);
        exit;
    }
    
    // Get all Year 2 students
    $students_query = "SELECT * FROM students WHERE year = 2 AND status = 'active'";
    $students_result = pg_query($conn, $students_query);
    
    $graduated = 0;
    $skipped = 0;
    $processed = 0;
    
    while ($student = pg_fetch_assoc($students_result)) {
        $student_id = $student['id'];
        $processed++;
        
        // Check graduation eligibility
        $eligibility = checkGraduationEligibility($conn, $student_id);
        
        if ($eligibility['eligible']) {
            // Calculate graduation grade
            $graduation_data = calculateGraduationGrade($conn, $student_id);
            $graduation_grade = $graduation_data['graduation_grade'];
            
            // Insert into graduated_students
            $graduate_query = "INSERT INTO graduated_students (student_id, student_name, age, gender, class_level, phone, graduation_date, final_year, graduation_grade) 
                              VALUES ($1, $2, $3, $4, $5, $6, CURRENT_DATE, 2, $7)";
            $graduate_result = pg_query_params($conn, $graduate_query, array(
                $student['id'], $student['name'], $student['age'], $student['gender'], 
                $student['class_level'], $student['phone'], $graduation_grade
            ));
            
            if ($graduate_result) {
                // Update student status
                pg_query_params($conn, "UPDATE students SET status = 'graduated' WHERE id = $1", array($student_id));
                $graduated++;
            }
        } else {
            $skipped++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'graduated' => $graduated,
        'skipped' => $skipped
    ]);
    exit;
}

// Handle graduated student deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_graduated_student') {
    $graduated_id = (int)$_POST['graduated_id'];
    
    if ($graduated_id > 0) {
        // Start transaction
        pg_query($conn, "BEGIN");
        
        // Delete the graduated student
        $delete_query = "DELETE FROM graduated_students WHERE id = $1";
        $result = pg_query_params($conn, $delete_query, array($graduated_id));
        
        if (!$result) {
            pg_query($conn, "ROLLBACK");
            $error_message = "Error deleting graduated student: " . pg_last_error($conn);
        } else {
            // Renumber only graduated students with ID > deleted ID
            $update_query = "UPDATE graduated_students SET id = id - 1 WHERE id > $1";
            $update_result = pg_query_params($conn, $update_query, array($graduated_id));
            
            if (!$update_result) {
                pg_query($conn, "ROLLBACK");
                $error_message = "Error renumbering graduated students: " . pg_last_error($conn);
            } else {
                // Reset sequence to MAX(id) + 1
                $max_id_result = pg_query($conn, "SELECT COALESCE(MAX(id), 0) as max_id FROM graduated_students");
                $max_row = pg_fetch_assoc($max_id_result);
                $next_id = $max_row['max_id'] + 1;
                pg_query($conn, "ALTER SEQUENCE graduated_students_id_seq RESTART WITH $next_id");
                
                pg_query($conn, "COMMIT");
                $success_message = "Graduated student deleted successfully! IDs renumbered.";
            }
        }
    }
}

// Handle bulk deletion of graduated students
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_graduated') {
    if (isset($_POST['selected_graduates']) && is_array($_POST['selected_graduates'])) {
        $selected_ids = array_map('intval', $_POST['selected_graduates']);
        sort($selected_ids); // Sort to delete from lowest to highest
        
        pg_query($conn, "BEGIN");
        
        $deleted_count = 0;
        // Delete one by one and renumber after each deletion
        foreach ($selected_ids as $index => $graduated_id) {
            // Adjust ID based on how many we've already deleted
            $adjusted_id = $graduated_id - $deleted_count;
            
            $delete_query = "DELETE FROM graduated_students WHERE id = $1";
            $result = pg_query_params($conn, $delete_query, array($adjusted_id));
            
            if (!$result) {
                pg_query($conn, "ROLLBACK");
                $error_message = "Error deleting graduated students: " . pg_last_error($conn);
                break;
            }
            
            // Renumber IDs greater than the deleted one
            $update_query = "UPDATE graduated_students SET id = id - 1 WHERE id > $1";
            pg_query_params($conn, $update_query, array($adjusted_id));
            
            $deleted_count++;
        }
        
        if (!isset($error_message)) {
            // Reset sequence
            $max_id_result = pg_query($conn, "SELECT COALESCE(MAX(id), 0) as max_id FROM graduated_students");
            $max_row = pg_fetch_assoc($max_id_result);
            $next_id = $max_row['max_id'] + 1;
            pg_query($conn, "ALTER SEQUENCE graduated_students_id_seq RESTART WITH $next_id");
            
            pg_query($conn, "COMMIT");
            $success_message = "$deleted_count graduated students deleted successfully! IDs renumbered.";
        }
    } else {
        $error_message = "No students selected for deletion.";
    }
}

// Handle data reset (clear all data and reset sequences)
if (isset($_POST['action']) && $_POST['action'] === 'reset_all_data') {
    if (isset($_POST['confirm_reset']) && $_POST['confirm_reset'] === 'yes') {
        $reset_result = resetAllData($conn);
        if ($reset_result['success']) {
            $success_message = "All data cleared and sequences reset successfully!";
        } else {
            $error_message = "Error resetting data: " . $reset_result['message'];
        }
    } else {
        $error_message = "Reset confirmation not provided.";
    }
}

// Handle AJAX requests for KPI filtering
if (isset($_POST['ajax']) && $_POST['ajax'] === 'get_kpis') {
    // Clear any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $filter_year = isset($_POST['filter_year']) && $_POST['filter_year'] !== '' ? (int)$_POST['filter_year'] : null;
    
    try {
        // Suppress any PHP errors from being output
        $old_error_reporting = error_reporting(0);
        
        $kpis = getKPIs($conn, $filter_year);
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        
        $response = ['success' => true, 'kpis' => $kpis];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
        echo json_encode($response);
    } catch (Throwable $e) {
        $response = ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    ob_end_flush();
    exit;
}

// Handle AJAX requests for grade distribution chart
if (isset($_POST['ajax']) && $_POST['ajax'] === 'get_grade_distribution') {
    // Clear any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $filter_year = isset($_POST['filter_year']) && $_POST['filter_year'] !== '' ? (int)$_POST['filter_year'] : null;
    
    try {
        // Suppress any PHP errors from being output
        $old_error_reporting = error_reporting(0);
        
        $gradeData = getGradeDistributionData($conn, $filter_year);
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        
        $response = ['success' => true, 'data' => $gradeData];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
        echo json_encode($response);
    } catch (Throwable $e) {
        $response = ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    ob_end_flush();
    exit;
}

// Handle AJAX requests for student distribution chart
if (isset($_POST['ajax']) && $_POST['ajax'] === 'get_student_distribution') {
    // Clear any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $filter_type = isset($_POST['filter_type']) ? $_POST['filter_type'] : 'overview';
    
    try {
        // Suppress any PHP errors from being output
        $old_error_reporting = error_reporting(0);
        
        $distributionData = getStudentDistributionData($conn, $filter_type);
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        
        $response = ['success' => true, 'data' => $distributionData];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
        echo json_encode($response);
    } catch (Throwable $e) {
        $response = ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    ob_end_flush();
    exit;
}

// Handle class format migration request
if (isset($_POST['action']) && $_POST['action'] === 'migrate_class_format') {
    header('Content-Type: application/json');
    
    try {
        // Find students with old class format and update them
        $old_format_query = "SELECT id, class_level, year FROM students WHERE class_level ~ '^[ABC]$' AND status = 'active'";
        $old_format_result = pg_query($conn, $old_format_query);
        
        $updated_count = 0;
        
        if ($old_format_result && pg_num_rows($old_format_result) > 0) {
            while ($student = pg_fetch_assoc($old_format_result)) {
                $new_class_level = $student['year'] . $student['class_level'];
                
                $update_query = "UPDATE students SET class_level = $1 WHERE id = $2";
                $update_result = pg_query_params($conn, $update_query, array($new_class_level, $student['id']));
                
                if ($update_result) {
                    $updated_count++;
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Migrated $updated_count students from old class format to new format"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Migration failed: ' . $e->getMessage()
        ]);
    }
    
    exit;
}

// Handle credit-weighted grade calculation
if (isset($_POST['action']) && $_POST['action'] === 'calculate_weighted_grade') {
    header('Content-Type: application/json');
    
    $student_id = $_POST['student_id'] ?? null;
    $year = $_POST['year'] ?? null;
    
    if ($student_id && $year) {
        $result = calculateCreditWeightedGrade($conn, $student_id, $year);
        echo json_encode($result);
    } else {
        echo json_encode(['error' => 'Student ID and year are required']);
    }
    
    exit;
}

// Handle get students request
if (isset($_POST['action']) && $_POST['action'] === 'get_students') {
    header('Content-Type: application/json');
    
    try {
        $query = "SELECT id, name FROM students ORDER BY name";
        $result = pg_query($conn, $query);
        
        $students = [];
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $students[] = $row;
            }
        }
        
        echo json_encode($students);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to load students: ' . $e->getMessage()]);
    }
    
    exit;
}



// Function to resequence table IDs
function resequenceTable($conn, $table_name) {
    try {
        // Begin transaction
        pg_query($conn, "BEGIN");
        
        // Get all records ordered by current ID
        $select_query = "SELECT id FROM $table_name ORDER BY id";
        $result = pg_query($conn, $select_query);
        
        if (!$result) {
            pg_query($conn, "ROLLBACK");
            return false;
        }
        
        $records = pg_fetch_all($result);
        if (!$records) {
            pg_query($conn, "COMMIT");
            return true; // No records to resequence
        }
        
        // Use a temporary high number to avoid conflicts
        $temp_offset = 100000;
        
        // First pass: move all IDs to temporary high numbers
        $id_mapping = [];
        $temp_id = $temp_offset;
        foreach ($records as $record) {
            $old_id = $record['id'];
            $id_mapping[$old_id] = $temp_id;
            
            $update_query = "UPDATE $table_name SET id = $1 WHERE id = $2";
            $update_result = pg_query_params($conn, $update_query, array($temp_id, $old_id));
            
            if (!$update_result) {
                pg_query($conn, "ROLLBACK");
                return false;
            }
            
            $temp_id++;
        }
        
        // Second pass: update to final sequential IDs
        $new_id = 1;
        foreach ($records as $record) {
            $old_id = $record['id'];
            $temp_id = $id_mapping[$old_id];
            
            $update_query = "UPDATE $table_name SET id = $1 WHERE id = $2";
            $update_result = pg_query_params($conn, $update_query, array($new_id, $temp_id));
            
            if (!$update_result) {
                pg_query($conn, "ROLLBACK");
                return false;
            }
            
            $new_id++;
        }
        
        // Reset the sequence to start from the next number
        $sequence_name = $table_name . '_id_seq';
        $sequence_result = pg_query($conn, "ALTER SEQUENCE $sequence_name RESTART WITH $new_id");
        
        if (!$sequence_result) {
            pg_query($conn, "ROLLBACK");
            return false;
        }
        
        // Commit transaction
        pg_query($conn, "COMMIT");
        
        return true;
    } catch (Exception $e) {
        // Rollback on error
        pg_query($conn, "ROLLBACK");
        return false;
    }
}

// Function to resequence all tables that reference each other
function resequenceAllTables($conn) {
    try {
        // Begin transaction
        pg_query($conn, "BEGIN");
        
        // Temporarily disable foreign key constraints
        pg_query($conn, "SET session_replication_role = replica");
        
        // Resequence in order: students, subjects, marks, graduated_students
        $tables = ['students', 'subjects', 'marks', 'graduated_students', 'promotion_history'];
        
        foreach ($tables as $table) {
            if (!resequenceTable($conn, $table)) {
                // Re-enable foreign key constraints
                pg_query($conn, "SET session_replication_role = DEFAULT");
                pg_query($conn, "ROLLBACK");
                return false;
            }
        }
        
        // Re-enable foreign key constraints
        pg_query($conn, "SET session_replication_role = DEFAULT");
        
        // Commit transaction
        pg_query($conn, "COMMIT");
        
        return true;
    } catch (Exception $e) {
        // Re-enable foreign key constraints and rollback
        pg_query($conn, "SET session_replication_role = DEFAULT");
        pg_query($conn, "ROLLBACK");
        return false;
    }
}

// Function to reset all data
function resetAllData($conn) {
    try {
        // Begin transaction
        pg_query($conn, "BEGIN");
        
        // Delete all data
        pg_query($conn, "DELETE FROM marks");
        pg_query($conn, "DELETE FROM subjects");
        pg_query($conn, "DELETE FROM students");
        pg_query($conn, "DELETE FROM graduated_students");
        pg_query($conn, "DELETE FROM promotion_history");
        
        // Reset all sequences to start from 1
        pg_query($conn, "ALTER SEQUENCE students_id_seq RESTART WITH 1");
        pg_query($conn, "ALTER SEQUENCE subjects_id_seq RESTART WITH 1");
        pg_query($conn, "ALTER SEQUENCE marks_id_seq RESTART WITH 1");
        pg_query($conn, "ALTER SEQUENCE graduated_students_id_seq RESTART WITH 1");
        pg_query($conn, "ALTER SEQUENCE promotion_history_id_seq RESTART WITH 1");
        
        // Commit transaction
        pg_query($conn, "COMMIT");
        
        return ['success' => true];
    } catch (Exception $e) {
        // Rollback on error
        pg_query($conn, "ROLLBACK");
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle success messages from URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Handle error messages from URL  
if (isset($_GET['error'])) {
    $error_message = $_GET['error'];
}

// Get page parameter
$page = $_GET['page'] ?? 'reports';

// TEACHER PERMISSIONS: Allow teachers to access dashboard and marks
if (isTeacher()) {
    $allowed_pages = ['dashboard', 'marks']; // Teachers can access dashboard and marks
    if (!in_array($page, $allowed_pages)) {
        $page = 'dashboard'; // Default to dashboard
    }
}

// Get updated KPIs
function getKPIs($conn, $filter_year = null) {
    // Build year filter condition
    $year_filter = $filter_year ? "AND s.year = $filter_year" : "";
    $year_filter_marks = $filter_year ? "AND s.year = $filter_year" : "";
    
    // Get student counts by year and status
    $student_stats = pg_query($conn, "
        SELECT 
            year,
            status,
            COUNT(*) as count
        FROM students 
        WHERE status = 'active' " . ($filter_year ? "AND year = $filter_year" : "") . "
        GROUP BY year, status
        ORDER BY year, status
    ");
    
    $year1_active = 0;
    $year2_active = 0;
    $graduated_count = 0;
    $total_active = 0;
    
    if ($student_stats && pg_num_rows($student_stats) > 0) {
        while($stat = pg_fetch_assoc($student_stats)) {
            if ($stat['status'] == 'active') {
                $total_active += (int)$stat['count'];
                if ($stat['year'] == 1) {
                    $year1_active = (int)$stat['count'];
                } elseif ($stat['year'] == 2) {
                    $year2_active = (int)$stat['count'];
                }
            } elseif ($stat['status'] == 'graduated') {
                $graduated_count += (int)$stat['count'];
            }
        }
    }
    
    // For filtered year, adjust the counts
    if ($filter_year) {
        $total_active = $filter_year == 1 ? $year1_active : $year2_active;
        if ($filter_year == 1) $year2_active = 0;
        if ($filter_year == 2) $year1_active = 0;
    }
    
    $total_subjects = pg_query($conn, "SELECT COUNT(*) as count FROM subjects" . ($filter_year ? " WHERE year = $filter_year" : ""));
    $total_subjects_count = pg_fetch_assoc($total_subjects)['count'];
    
    // Initialize top_year variable
    $top_year = null;
    
    // Calculate average score - if "All Years" selected, show highest year average
    if ($filter_year) {
        // Specific year selected - show that year's average
        $avg_score = pg_query($conn, "
            SELECT ROUND(AVG(m.mark), 1) as avg 
            FROM marks m 
            JOIN students s ON m.student_id = s.id 
            WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
        ");
        $avg_score_value = pg_fetch_assoc($avg_score)['avg'] ?? 0;
    } else {
        // All years - show highest average between Year 1 and Year 2
        $year_averages = pg_query($conn, "
            SELECT 
                s.year,
                ROUND(AVG(m.mark), 1) as avg_mark
            FROM marks m 
            JOIN students s ON m.student_id = s.id 
            WHERE m.mark > 0 AND s.status = 'active'
            GROUP BY s.year
            ORDER BY avg_mark DESC
            LIMIT 1
        ");
        $year_avg_data = pg_fetch_assoc($year_averages);
        $avg_score_value = $year_avg_data['avg_mark'] ?? 0;
        $top_year = $year_avg_data['year'] ?? null;
    }
    
    // Calculate top performing class based on pass rate and high grades
    $top_class = pg_query($conn, "
        SELECT 
            s.class_level,
            COUNT(*) as total_students,
            COUNT(CASE WHEN m.mark >= 50 THEN 1 END) as passed_students,
            COUNT(CASE WHEN m.mark >= 80 THEN 1 END) as high_grade_students,
            CASE 
                WHEN COUNT(*) = 0 THEN 0
                ELSE ROUND(
                    (COUNT(CASE WHEN m.mark >= 50 THEN 1 END) * 100.0 / COUNT(*)) + 
                    (COUNT(CASE WHEN m.mark >= 80 THEN 1 END) * 50.0 / COUNT(*))
                , 1)
            END as performance_score,
            ROUND(AVG(m.mark), 1) as avg_mark
        FROM students s 
        JOIN marks m ON s.id = m.student_id 
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
        GROUP BY s.class_level 
        HAVING COUNT(*) >= 2
        ORDER BY performance_score DESC, avg_mark DESC
        LIMIT 1
    ");
    $top_class_data = pg_fetch_assoc($top_class);
    
    // Calculate pass rate percentage
    $pass_rate = pg_query($conn, "
        SELECT 
            CASE 
                WHEN COUNT(*) = 0 THEN 0
                ELSE ROUND(
                    COUNT(CASE WHEN m.mark >= 50 THEN 1 END) * 100.0 / COUNT(*)
                , 1)
            END as pass_rate
        FROM marks m
        JOIN students s ON m.student_id = s.id 
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
    ");
    $pass_rate_value = pg_fetch_assoc($pass_rate)['pass_rate'] ?? 0;
    
    // Calculate subjects with most failing students (risk indicator)
    $risk_subject = pg_query($conn, "
        SELECT 
            sub.subject_name,
            COUNT(CASE WHEN m.mark < 50 THEN 1 END) as failing_count,
            COUNT(*) as total_enrolled,
            CASE 
                WHEN COUNT(*) = 0 THEN 0
                ELSE ROUND(
                    COUNT(CASE WHEN m.mark < 50 THEN 1 END) * 100.0 / COUNT(*)
                , 1)
            END as failure_rate
        FROM subjects sub
        JOIN marks m ON sub.id = m.subject_id
        JOIN students s ON m.student_id = s.id
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks " . ($filter_year ? "AND sub.year = $filter_year" : "") . "
        GROUP BY sub.id, sub.subject_name
        HAVING COUNT(*) >= 2
        ORDER BY failure_rate DESC, failing_count DESC
        LIMIT 1
    ");
    $risk_subject_data = pg_fetch_assoc($risk_subject);
    
    // Calculate total enrolled students (students with marks)
    $enrolled_students = pg_query($conn, "
        SELECT COUNT(DISTINCT m.student_id) as enrolled_count
        FROM marks m
        JOIN students s ON m.student_id = s.id
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
    ");
    $enrolled_students_count = pg_fetch_assoc($enrolled_students)['enrolled_count'] ?? 0;
    
    // Calculate excellence rate (students with marks >= 90)
    $excellence_rate = pg_query($conn, "
        SELECT 
            CASE 
                WHEN COUNT(*) = 0 THEN 0
                ELSE ROUND(
                    COUNT(CASE WHEN m.mark >= 90 THEN 1 END) * 100.0 / COUNT(*)
                , 1)
            END as excellence_rate
        FROM marks m
        JOIN students s ON m.student_id = s.id 
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
    ");
    $excellence_rate_value = pg_fetch_assoc($excellence_rate)['excellence_rate'] ?? 0;
    
    return [
        'total_students' => $total_active,
        'year1_students' => $year1_active,
        'year2_students' => $year2_active,
        'graduated_students' => $graduated_count,
        'total_subjects' => $total_subjects_count,
        'avg_score' => $avg_score_value,
        'top_year' => $top_year,
        'top_class' => $top_class_data['class_level'] ?? 'N/A',
        'top_class_display' => formatClassDisplay($top_class_data['class_level'] ?? 'N/A'),
        'top_class_score' => $top_class_data['performance_score'] ?? 0,
        'pass_rate' => $pass_rate_value,
        'risk_subject' => $risk_subject_data['subject_name'] ?? 'N/A',
        'risk_failure_rate' => $risk_subject_data['failure_rate'] ?? 0,
        'enrolled_students' => $enrolled_students_count,
        'excellence_rate' => $excellence_rate_value
    ];
}

// Helper function to format class display
function formatClassDisplay($class_level) {
    if (preg_match('/^([12])([ABC])$/', $class_level, $matches)) {
        return "Year {$matches[1]} - Class {$matches[2]}";
    }
    return $class_level;
}

function generateChartData($conn) {
    // Get grade distribution data - no default filter, show all years
    $gradeDistData = getGradeDistributionData($conn, null);
    $grades = $gradeDistData['labels'];
    $grade_counts = $gradeDistData['data'];
    
    return [
        'kpis' => getKPIs($conn, null), // Load all years KPIs by default
        'grades' => $grades,
        'grade_counts' => $grade_counts,
        'student_distribution' => getStudentDistributionData($conn, 'overview')
    ];
}

// Function to get grade distribution data by year
function getGradeDistributionData($conn, $filter_year = null) {
    // Build year filter condition
    $year_filter = $filter_year ? "AND s.year = $filter_year" : "";
    
    $grade_dist = pg_query($conn, "
        SELECT 
            CASE 
                WHEN m.mark >= 90 THEN 'A+'
                WHEN m.mark >= 80 THEN 'A'
                WHEN m.mark >= 70 THEN 'B'
                WHEN m.mark >= 50 THEN 'C'
                ELSE 'F'
            END as grade,
            COUNT(*) as count
        FROM marks m
        JOIN students s ON m.student_id = s.id
        WHERE m.mark > 0 AND s.status = 'active' $year_filter
        GROUP BY 
            CASE 
                WHEN m.mark >= 90 THEN 'A+'
                WHEN m.mark >= 80 THEN 'A'
                WHEN m.mark >= 70 THEN 'B'
                WHEN m.mark >= 50 THEN 'C'
                ELSE 'F'
            END
        ORDER BY 
            CASE 
                WHEN CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A+' THEN 1
                WHEN CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A' THEN 2
                WHEN CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'B' THEN 3
                WHEN CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'C' THEN 4
                ELSE 5
            END
    ");

    $grades = ['A+' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'F' => 0];
    
    if ($grade_dist && pg_num_rows($grade_dist) > 0) {
        while($row = pg_fetch_assoc($grade_dist)) {
            $grades[$row['grade']] = (int)$row['count'];
        }
    }
    
    // Filter out grades with zero values for better UI
    $filteredLabels = [];
    $filteredData = [];
    
    foreach ($grades as $label => $value) {
        if ($value > 0) {
            $filteredLabels[] = $label;
            $filteredData[] = $value;
        }
    }
    
    // If no data, show empty chart
    if (empty($filteredLabels)) {
        $filteredLabels = ['No Data'];
        $filteredData = [1];
    }
    
    return [
        'labels' => $filteredLabels,
        'data' => $filteredData
    ];
}

// Function to get student distribution data by filter type
function getStudentDistributionData($conn, $filter_type = 'overview') {
    if ($filter_type === '1') {
        // Year 1 students breakdown by class
        $query = "
            SELECT 
                class_level,
                COUNT(*) as count
            FROM students 
            WHERE year = 1 AND status = 'active' AND class_level LIKE '1%'
            GROUP BY class_level
            ORDER BY class_level
        ";
        $result = pg_query($conn, $query);
        
        $labels = [];
        $data = [];
        $colors = ['#1e40af', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899'];
        
        if ($result && pg_num_rows($result) > 0) {
            $i = 0;
            while($row = pg_fetch_assoc($result)) {
                $labels[] = 'Class ' . substr($row['class_level'], 1); // Remove '1' prefix for display
                $data[] = (int)$row['count'];
                $i++;
            }
        } else {
            $labels = ['No Year 1 Students'];
            $data = [0];
            $colors = ['#9CA3AF'];
        }
        
        return [
            'labels' => $labels,
            'data' => $data,
            'colors' => array_slice($colors, 0, count($labels))
        ];
        
    } elseif ($filter_type === '2') {
        // Year 2 students breakdown by class
        $query = "
            SELECT 
                class_level,
                COUNT(*) as count
            FROM students 
            WHERE year = 2 AND status = 'active' AND class_level LIKE '2%'
            GROUP BY class_level
            ORDER BY class_level
        ";
        $result = pg_query($conn, $query);
        
        $labels = [];
        $data = [];
        $colors = ['#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#1e40af', '#EC4899'];
        
        if ($result && pg_num_rows($result) > 0) {
            $i = 0;
            while($row = pg_fetch_assoc($result)) {
                $labels[] = 'Class ' . substr($row['class_level'], 1); // Remove '2' prefix for display
                $data[] = (int)$row['count'];
                $i++;
            }
        } else {
            $labels = ['No Year 2 Students'];
            $data = [0];
            $colors = ['#9CA3AF'];
        }
        
        return [
            'labels' => $labels,
            'data' => $data,
            'colors' => array_slice($colors, 0, count($labels))
        ];
        
    } else {
        // Overview - all categories
        $year1_query = "SELECT COUNT(*) as count FROM students WHERE year = 1 AND status = 'active'";
        $year2_query = "SELECT COUNT(*) as count FROM students WHERE year = 2 AND status = 'active'";
        $graduated_query = "SELECT COUNT(*) as count FROM graduated_students";
        
        $year1_result = pg_query($conn, $year1_query);
        $year2_result = pg_query($conn, $year2_query);
        $graduated_result = pg_query($conn, $graduated_query);
        
        $year1_count = $year1_result ? (int)pg_fetch_assoc($year1_result)['count'] : 0;
        $year2_count = $year2_result ? (int)pg_fetch_assoc($year2_result)['count'] : 0;
        $graduated_count = $graduated_result ? (int)pg_fetch_assoc($graduated_result)['count'] : 0;
        
        return [
            'labels' => ['Year 1 Active', 'Year 2 Active', 'Graduated'],
            'data' => [$year1_count, $year2_count, $graduated_count],
            'colors' => ['#1e40af', '#10B981', '#F59E0B']
        ];
    }
}

// Function to calculate credit-weighted final grade for a student
function calculateCreditWeightedGrade($conn, $student_id, $year) {
    // Get all subjects for the year with their credits
    $subjects_query = "
        SELECT id, subject_name, credits 
        FROM subjects 
        WHERE year = $1 
        ORDER BY subject_name
    ";
    $subjects_result = pg_query_params($conn, $subjects_query, array($year));
    
    if (!$subjects_result || pg_num_rows($subjects_result) == 0) {
        return ['error' => 'No subjects found for this year'];
    }
    
    $total_credits = 0;
    $total_weighted_score = 0;
    $subjects_data = [];
    $missing_subjects = [];
    
    while ($subject = pg_fetch_assoc($subjects_result)) {
        $total_credits += $subject['credits'];
        
        // Get student's mark for this subject
        $marks_query = "
            SELECT mark, status 
            FROM marks 
            WHERE student_id = $1 AND subject_id = $2
        ";
        $marks_result = pg_query_params($conn, $marks_query, array($student_id, $subject['id']));
        
        if ($marks_result && pg_num_rows($marks_result) > 0) {
            $mark_data = pg_fetch_assoc($marks_result);
            $subject_grade = (float)$mark_data['mark'];
            $credit_weight = (float)$subject['credits'] / 100; // Convert to decimal
            $weighted_score = $subject_grade * $credit_weight;
            $total_weighted_score += $weighted_score;
            
            $subjects_data[] = [
                'subject_name' => $subject['subject_name'],
                'credits' => $subject['credits'],
                'grade' => $subject_grade,
                'credit_weight' => $credit_weight,
                'weighted_score' => $weighted_score,
                'status' => $mark_data['status']
            ];
        } else {
            $missing_subjects[] = $subject['subject_name'];
        }
    }
    
    // Check if student is enrolled in all subjects
    if (!empty($missing_subjects)) {
        return [
            'error' => 'Student must be enrolled in all subjects to calculate final grade',
            'missing_subjects' => $missing_subjects,
            'total_credits' => $total_credits
        ];
    }
    
    // Calculate final weighted grade
    $final_grade = $total_weighted_score;
    $grade_percentage = ($final_grade / $total_credits) * 100;
    
    // Determine overall status
    $overall_status = $grade_percentage >= 50 ? 'Pass' : 'Fail';
    
    return [
        'success' => true,
        'final_grade' => round($final_grade, 2),
        'grade_percentage' => round($grade_percentage, 2),
        'total_credits' => $total_credits,
        'subjects_breakdown' => $subjects_data,
        'overall_status' => $overall_status
    ];
}

// Function to calculate graduation grade (Year 1 + Year 2 final grades)
// Check if Year 1 student is eligible for promotion to Year 2
function checkPromotionEligibility($conn, $student_id) {
    // Get all Year 1 marks for the student with subject details
    $query = "
        SELECT 
            m.id,
            m.mark,
            m.final_grade,
            s.subject_name,
            s.credits
        FROM marks m
        INNER JOIN subjects s ON m.subject_id = s.id
        WHERE m.student_id = $1 AND s.year = 1
        ORDER BY s.subject_name
    ";
    $result = pg_query_params($conn, $query, array($student_id));
    
    if (!$result) {
        return [
            'eligible' => false,
            'message' => 'Error checking eligibility: ' . pg_last_error($conn),
            'details' => []
        ];
    }
    
    $marks = pg_fetch_all($result);
    
    if (!$marks || count($marks) === 0) {
        return [
            'eligible' => false,
            'message' => 'Student has no Year 1 marks recorded. Please add marks for all Year 1 subjects first.',
            'details' => []
        ];
    }
    
    // Check how many Year 1 subjects exist
    $subjects_query = "SELECT COUNT(*) as total FROM subjects WHERE year = 1";
    $subjects_result = pg_query($conn, $subjects_query);
    $subjects_data = pg_fetch_assoc($subjects_result);
    $total_year1_subjects = (int)$subjects_data['total'];
    
    if (count($marks) < $total_year1_subjects) {
        return [
            'eligible' => false,
            'message' => 'Student has incomplete Year 1 subjects. Completed: ' . count($marks) . '/' . $total_year1_subjects,
            'details' => [
                'completed_subjects' => count($marks),
                'total_subjects' => $total_year1_subjects
            ]
        ];
    }
    
    $total_final_grade = 0;
    $failed_subjects = [];
    
    foreach ($marks as $mark) {
        $total_final_grade += floatval($mark['final_grade']);
        
        // Check if subject is failed (mark < 50)
        if (floatval($mark['mark']) < 50) {
            $failed_subjects[] = [
                'subject' => $mark['subject_name'],
                'mark' => floatval($mark['mark']),
                'credits' => floatval($mark['credits'])
            ];
        }
    }
    
    // Check both conditions
    $has_minimum_grade = $total_final_grade >= 25;
    $has_no_failed_subjects = count($failed_subjects) === 0;
    
    if (!$has_minimum_grade || !$has_no_failed_subjects) {
        $reasons = [];
        
        if (!$has_minimum_grade) {
            $reasons[] = [
                'type' => 'total_grade',
                'grade' => round($total_final_grade, 2),
                'max' => 50,
                'required' => 25
            ];
        }
        
        if (!$has_no_failed_subjects) {
            $reasons[] = [
                'type' => 'failed_subjects',
                'count' => count($failed_subjects)
            ];
        }
        
        return [
            'eligible' => false,
            'message' => 'Student does not meet promotion requirements',
            'details' => [
                'total_final_grade' => round($total_final_grade, 2),
                'required_grade' => 25,
                'has_minimum_grade' => $has_minimum_grade,
                'failed_subjects' => $failed_subjects,
                'has_failed_subjects' => !$has_no_failed_subjects,
                'reasons' => $reasons
            ]
        ];
    }
    
    return [
        'eligible' => true,
        'message' => 'Student is eligible for promotion to Year 2',
        'details' => [
            'total_final_grade' => round($total_final_grade, 2),
            'required_grade' => 25,
            'completed_subjects' => count($marks),
            'total_subjects' => $total_year1_subjects
        ]
    ];
}

// Check if Year 2 student is eligible for graduation
function checkGraduationEligibility($conn, $student_id) {
    // Get all Year 2 marks for the student with subject details
    $query = "
        SELECT 
            m.id,
            m.mark,
            m.final_grade,
            s.subject_name,
            s.credits
        FROM marks m
        INNER JOIN subjects s ON m.subject_id = s.id
        WHERE m.student_id = $1 AND s.year = 2
        ORDER BY s.subject_name
    ";
    $result = pg_query_params($conn, $query, array($student_id));
    
    if (!$result) {
        return [
            'eligible' => false,
            'message' => 'Error checking eligibility: ' . pg_last_error($conn),
            'details' => []
        ];
    }
    
    $marks = pg_fetch_all($result);
    
    if (!$marks || count($marks) === 0) {
        return [
            'eligible' => false,
            'message' => 'Student has no Year 2 marks recorded. Please add marks for all Year 2 subjects first.',
            'details' => []
        ];
    }
    
    // Check how many Year 2 subjects exist
    $subjects_query = "SELECT COUNT(*) as total FROM subjects WHERE year = 2";
    $subjects_result = pg_query($conn, $subjects_query);
    $subjects_data = pg_fetch_assoc($subjects_result);
    $total_year2_subjects = (int)$subjects_data['total'];
    
    if (count($marks) < $total_year2_subjects) {
        return [
            'eligible' => false,
            'message' => 'Student has incomplete Year 2 subjects. Completed: ' . count($marks) . '/' . $total_year2_subjects,
            'details' => [
                'completed_subjects' => count($marks),
                'total_subjects' => $total_year2_subjects
            ]
        ];
    }
    
    $total_final_grade = 0;
    $failed_subjects = [];
    
    foreach ($marks as $mark) {
        $total_final_grade += floatval($mark['final_grade']);
        
        // Check if subject is failed (mark < 50)
        if (floatval($mark['mark']) < 50) {
            $failed_subjects[] = [
                'subject' => $mark['subject_name'],
                'mark' => floatval($mark['mark']),
                'credits' => floatval($mark['credits'])
            ];
        }
    }
    
    // Check both conditions
    $has_minimum_grade = $total_final_grade >= 25;
    $has_no_failed_subjects = count($failed_subjects) === 0;
    
    if (!$has_minimum_grade || !$has_no_failed_subjects) {
        $reasons = [];
        
        if (!$has_minimum_grade) {
            $reasons[] = [
                'type' => 'total_grade',
                'grade' => round($total_final_grade, 2),
                'max' => 50,
                'required' => 25
            ];
        }
        
        if (!$has_no_failed_subjects) {
            $reasons[] = [
                'type' => 'failed_subjects',
                'count' => count($failed_subjects)
            ];
        }
        
        return [
            'eligible' => false,
            'message' => 'Student does not meet graduation requirements',
            'details' => [
                'total_final_grade' => round($total_final_grade, 2),
                'required_grade' => 25,
                'has_minimum_grade' => $has_minimum_grade,
                'failed_subjects' => $failed_subjects,
                'has_failed_subjects' => !$has_no_failed_subjects,
                'reasons' => $reasons
            ]
        ];
    }
    
    return [
        'eligible' => true,
        'message' => 'Student is eligible for graduation',
        'details' => [
            'total_final_grade' => round($total_final_grade, 2),
            'required_grade' => 25,
            'completed_subjects' => count($marks),
            'total_subjects' => $total_year2_subjects
        ]
    ];
}

function calculateGraduationGrade($conn, $student_id) {
    // Calculate Year 1 final grade
    $year1_query = "
        SELECT 
            SUM(m.final_grade) as year_final_grade,
            COUNT(m.id) as completed_subjects,
            COUNT(s.id) as total_subjects
        FROM subjects s
        LEFT JOIN marks m ON s.id = m.subject_id AND m.student_id = $1
        WHERE s.year = 1
    ";
    $year1_result = pg_query_params($conn, $year1_query, array($student_id));
    $year1_data = pg_fetch_assoc($year1_result);
    
    // Calculate Year 2 final grade
    $year2_query = "
        SELECT 
            SUM(m.final_grade) as year_final_grade,
            COUNT(m.id) as completed_subjects,
            COUNT(s.id) as total_subjects
        FROM subjects s
        LEFT JOIN marks m ON s.id = m.subject_id AND m.student_id = $1
        WHERE s.year = 2
    ";
    $year2_result = pg_query_params($conn, $year2_query, array($student_id));
    $year2_data = pg_fetch_assoc($year2_result);
    
    // Check if student completed all subjects for each year
    $year1_complete = ($year1_data['completed_subjects'] == $year1_data['total_subjects'] && $year1_data['total_subjects'] > 0);
    $year2_complete = ($year2_data['completed_subjects'] == $year2_data['total_subjects'] && $year2_data['total_subjects'] > 0);
    
    $year1_grade = $year1_complete ? floatval($year1_data['year_final_grade']) : 0;
    $year2_grade = $year2_complete ? floatval($year2_data['year_final_grade']) : 0;
    
    // Calculate graduation grade
    $graduation_grade = $year1_grade + $year2_grade;
    $graduation_percentage = ($graduation_grade / 100) * 100; // Out of 100 total points
    
    // Determine graduation status
    $graduation_status = 'incomplete';
    if ($year1_complete && $year2_complete) {
        if ($graduation_percentage >= 50) {
            $graduation_status = 'graduated';
        } else {
            $graduation_status = 'failed';
        }
    } else if ($year1_complete) {
        $graduation_status = 'year1_complete';
    } else if ($year2_complete) {
        $graduation_status = 'year2_complete';
    }
    
    return [
        'success' => true,
        'year1_grade' => round($year1_grade, 2),
        'year1_max' => 50,
        'year1_complete' => $year1_complete,
        'year1_subjects_completed' => $year1_data['completed_subjects'],
        'year1_subjects_total' => $year1_data['total_subjects'],
        'year2_grade' => round($year2_grade, 2),
        'year2_max' => 50,
        'year2_complete' => $year2_complete,
        'year2_subjects_completed' => $year2_data['completed_subjects'],
        'year2_subjects_total' => $year2_data['total_subjects'],
        'graduation_grade' => round($graduation_grade, 2),
        'graduation_max' => 100,
        'graduation_percentage' => round($graduation_percentage, 2),
        'graduation_status' => $graduation_status
    ];
}

// Only generate chart data if we're on the reports page (dashboard)
$chartData = null;
if ($page == 'reports') {
    $chartData = generateChartData($conn);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Premium Student Management System</title>
    <!-- Professional Fonts for English, Arabic, and Kurdish -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&family=Noto+Sans:wght@300;400;500;600;700&family=Noto+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    
    <style>
        /* ===== CSS VARIABLES - MATCHING IMAGE STYLE ===== */
        :root {
            /* Primary Colors from Image */
            --primary-color: #1e3a8a;        /* Deep blue from header */
            --primary-dark: #1e40af;         /* Slightly darker blue */
            --secondary-color: #6b7280;      /* Gray for secondary text */
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --text-color: #1f2937;          /* Dark text like image */
            --text-light: #6b7280;          /* Light gray text */
            --bg-color: #f8fafc;            /* Light background */
            --card-bg: #ffffff;             /* White cards like image */
            --border-color: #e5e7eb;        /* Light border */
            --hover-color: #f3f4f6;         /* Hover state */
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;           /* Subtle radius like image */
            --transition: all 0.2s ease-in-out;
            
            /* KPI Card Colors from Image */
            --kpi-blue: #1e3a8a;           /* Dark blue */
            --kpi-light-blue: #3b82f6;     /* Medium blue */
            --kpi-yellow: #fbbf24;         /* Yellow accent */
            --kpi-cyan: #06b6d4;           /* Cyan accent */
        }

        /* ===== GLOBAL STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', 'Segoe UI', 'Noto Sans Arabic', 'Noto Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-color);
            min-height: 100vh;
            color: var(--text-color);
            line-height: 1.6;
            overflow-x: hidden; /* Prevent horizontal page scroll */
            max-width: 100vw; /* Constrain to viewport width */
        }

        /* ===== NAVIGATION - MATCHING IMAGE STYLE ===== */
        nav {
            background: var(--primary-color);
            padding: 0;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 100%;
            margin: 0;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            padding: 0 2rem;
            min-height: 70px;
            gap: 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            justify-self: center;
            flex-wrap: nowrap;
        }
        
        .user-info-section {
            justify-self: end;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-container a {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            font-size: 13px;
            letter-spacing: 0.3px;
            border: 2px solid transparent;
            white-space: nowrap;
        }

        .nav-container a::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            border-radius: 8px;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .nav-container a:hover::before {
            opacity: 1;
        }

        .nav-container a:hover {
            color: #fbbf24;
            transform: translateY(-2px);
            border-color: rgba(251, 191, 36, 0.5);
            box-shadow: 0 8px 25px rgba(251, 191, 36, 0.3);
        }

        .nav-container a.active {
            background: linear-gradient(45deg, #fbbf24, #f59e0b);
            color: #1e3a8a;
            font-weight: 700;
            border-color: #fbbf24;
            box-shadow: 0 6px 20px rgba(251, 191, 36, 0.4);
        }

        .nav-container a.active::before {
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            opacity: 1;
        }

        /* Language Switcher Enhanced */
        .language-switcher {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .language-switcher select {
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.1));
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 10px 16px;
            border-radius: 12px;
            font-family: 'Roboto', 'Noto Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            min-width: 60px;
        }

        .language-switcher select:hover {
            background: linear-gradient(45deg, #fbbf24, #f59e0b);
            color: #1e3a8a;
            border-color: #fbbf24;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(251, 191, 36, 0.3);
        }

        .language-switcher select:focus {
            outline: none;
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.3);
        }

        .language-switcher select option {
            background: #1e3a8a;
            color: white;
            padding: 8px;
            font-weight: 500;
        }

        /* Academic Brand Styling */
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            margin-right: auto;
        }

        .brand-icon {
            font-size: 36px;
            background: linear-gradient(45deg, #fbbf24, #f59e0b);
            width: 55px;
            height: 55px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(251, 191, 36, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .brand-title {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 13px;
            color: #fbbf24;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
            line-height: 1;
        }

        /* Responsive navbar adjustments */
        @media (max-width: 768px) {
            .nav-container {
                flex-wrap: wrap;
                gap: 15px;
                padding: 15px;
            }
            
            .nav-brand {
                order: 1;
                flex: 1 1 100%;
                justify-content: center;
            }
            
            .nav-links {
                order: 2;
                flex: 1 1 auto;
                justify-content: center;
            }
            
            .language-switcher {
                order: 3;
                flex: 1 1 auto;
                justify-content: center;
            }
            
            .nav-container a {
                padding: 10px 16px;
                font-size: 12px;
            }
        }

        /* ===== CONTAINERS ===== */
        .container {
            max-width: 100%;
            width: 100%;
            margin: 0 auto;
            padding: 2rem;
        }
        
        @media (min-width: 1440px) {
            .container {
                max-width: 1400px;
            }
        }

        .content-wrapper {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-top: 2rem;
        }

        /* ===== PREMIUM REPORTS DASHBOARD ===== */
        .reports-dashboard {
            padding: 0;
        }

        .dashboard-header {
            padding: 3rem 2rem 2rem;
            margin: 0;
            border-bottom: none;
            background: var(--primary-color);
            text-align: center;
        }

        .dashboard-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .dashboard-subtitle {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 400;
            text-rendering: optimizeLegibility;
        }
        
        /* RTL support for Arabic/Kurdish */
        [dir="rtl"] .blur-text-segment,
        .blur-text-segment[dir="rtl"] {
            unicode-bidi: embed;
        }

        /* ===== REPORTS MAIN CONTENT ===== */
        .reports-main-content {
            padding: 2rem;
            padding-top: 2rem;
            width: 100%;
            max-width: 100%;
            overflow-y: auto;
            min-height: calc(100vh - 70px);
        }

        /* ===== YEAR FILTER TOGGLE ===== */
        .year-filter-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .filter-title {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1rem;
        }

        .year-toggle-switch {
            display: flex;
            background: var(--hover-color);
            border-radius: 25px;
            padding: 4px;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .year-toggle-switch input[type="radio"] {
            display: none;
        }

        .year-toggle-switch label {
            padding: 8px 16px;
            cursor: pointer;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            color: var(--text-light);
            white-space: nowrap;
            position: relative;
            z-index: 2;
        }

        .year-toggle-switch input[type="radio"]:checked + label {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .year-toggle-switch label:hover {
            color: var(--text-color);
        }

        @media (max-width: 768px) {
            .year-filter-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .year-toggle-switch {
                width: 100%;
                justify-content: center;
            }
        }

        /* ===== KPI CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 3rem;
            width: 100%;
            scroll-margin-top: 90px;
        }
        
        @media (max-width: 1400px) {
            .kpi-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 1024px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 640px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }

        .kpi-card {
            position: relative;
            border-radius: 14px;
            padding: 1.5rem;
            z-index: 1;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 20px 20px 60px #bebebe, -20px -20px 60px #ffffff;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .kpi-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 25px 25px 70px #bebebe, -25px -25px 70px #ffffff;
        }

        /* Animated Blob Background */
        .bg {
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            z-index: 2;
            background: rgba(255, 255, 255, .95);
            backdrop-filter: blur(24px);
            border-radius: 10px;
            overflow: hidden;
            outline: 2px solid white;
        }

        .blob {
            position: absolute;
            z-index: 1;
            top: 50%;
            left: 50%;
            width: 150px;
            height: 150px;
            border-radius: 50%;
            opacity: 1;
            filter: blur(12px);
        }

        /* Different animations for each blob - clockwise from top-left */
        @keyframes blob-bounce-1 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(0, 0, 0); }
            25% { transform: translate(-100%, -100%) translate3d(100%, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(100%, 100%, 0); }
            75% { transform: translate(-100%, -100%) translate3d(0, 100%, 0); }
        }

        /* Counter-clockwise from top-right */
        @keyframes blob-bounce-2 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(100%, 0, 0); }
            25% { transform: translate(-100%, -100%) translate3d(100%, 100%, 0); }
            50% { transform: translate(-100%, -100%) translate3d(0, 100%, 0); }
            75% { transform: translate(-100%, -100%) translate3d(0, 0, 0); }
        }

        /* Diagonal bounce - top-left to bottom-right */
        @keyframes blob-bounce-3 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(0, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(100%, 100%, 0); }
        }

        /* Diagonal bounce - top-right to bottom-left */
        @keyframes blob-bounce-4 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(100%, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(0, 100%, 0); }
        }

        /* Horizontal slide */
        @keyframes blob-bounce-5 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(0, 50%, 0); }
            50% { transform: translate(-100%, -100%) translate3d(100%, 50%, 0); }
        }

        /* Vertical slide */
        @keyframes blob-bounce-6 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(50%, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(50%, 100%, 0); }
        }

        /* Figure-8 pattern */
        @keyframes blob-bounce-7 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(0, 50%, 0); }
            25% { transform: translate(-100%, -100%) translate3d(50%, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(100%, 50%, 0); }
            75% { transform: translate(-100%, -100%) translate3d(50%, 100%, 0); }
        }

        /* Reverse clockwise */
        @keyframes blob-bounce-8 {
            0%, 100% { transform: translate(-100%, -100%) translate3d(0, 100%, 0); }
            25% { transform: translate(-100%, -100%) translate3d(0, 0, 0); }
            50% { transform: translate(-100%, -100%) translate3d(100%, 0, 0); }
            75% { transform: translate(-100%, -100%) translate3d(100%, 100%, 0); }
        }

        /* Blob colors and animations for different KPI cards */
        .kpi-card:nth-child(1) .blob {
            background-color: #1e40af;
            animation: blob-bounce-1 6s infinite ease-in-out;
        }

        .kpi-card:nth-child(2) .blob {
            background-color: #10b981;
            animation: blob-bounce-2 7s infinite ease-in-out;
        }

        .kpi-card:nth-child(3) .blob {
            background-color: #f59e0b;
            animation: blob-bounce-3 5s infinite ease-in-out;
        }

        .kpi-card:nth-child(4) .blob {
            background-color: #8b5cf6;
            animation: blob-bounce-4 5.5s infinite ease-in-out;
        }

        .kpi-card:nth-child(5) .blob {
            background-color: #1e40af;
            animation: blob-bounce-5 6.5s infinite ease-in-out;
        }

        .kpi-card:nth-child(6) .blob {
            background-color: #ef4444;
            animation: blob-bounce-6 5s infinite ease-in-out;
        }

        .kpi-card:nth-child(7) .blob {
            background-color: #10b981;
            animation: blob-bounce-7 8s infinite ease-in-out;
        }

        .kpi-card:nth-child(8) .blob {
            background-color: #f59e0b;
            animation: blob-bounce-8 7s infinite ease-in-out;
        }

        .kpi-icon {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            font-weight: 600;
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }

        .kpi-card:hover .kpi-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 28px rgba(0,0,0,0.25);
        }

        .kpi-card:nth-child(1) .kpi-icon { 
            background: linear-gradient(135deg, #3b82f6, #1e40af); 
        }
        .kpi-card:nth-child(2) .kpi-icon { 
            background: linear-gradient(135deg, #10b981, #059669); 
        }
        .kpi-card:nth-child(3) .kpi-icon { 
            background: linear-gradient(135deg, #f59e0b, #d97706); 
        }
        .kpi-card:nth-child(4) .kpi-icon { 
            background: linear-gradient(135deg, #8b5cf6, #7c3aed); 
        }

        .kpi-content {
            text-align: center;
            width: 100%;
            position: relative;
            z-index: 10;
        }
        
        .kpi-card > .kpi-icon,
        .kpi-card > .kpi-content {
            position: relative;
            z-index: 10;
        }

        .kpi-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1;
            margin-bottom: 0.4rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .kpi-label {
            font-size: 0.95rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-trend {
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }

        .kpi-trend.positive { color: var(--success-color); }
        .kpi-trend.negative { color: var(--danger-color); }
        .kpi-trend.negative { color: var(--danger-color); }

        /* ===== FILTER PANEL ===== */
        .filter-panel {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .filter-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-section {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .premium-select {
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Roboto', 'Noto Sans', sans-serif;
            font-size: 0.9rem;
            background: var(--card-bg);
            color: var(--text-color);
            transition: var(--transition);
        }

        .premium-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .apply-filters-btn, .clear-filters-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-family: 'Roboto', 'Noto Sans', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .apply-filters-btn {
            background: var(--primary-color);
            color: white;
        }

        .apply-filters-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .clear-filters-btn {
            background: var(--hover-color);
            color: var(--text-color);
            margin-left: 0.5rem;
        }

        .clear-filters-btn:hover {
            background: var(--danger-color);
            color: white;
        }

        /* ===== CHARTS GRID ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: 100%;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
            max-width: 100%;
            min-width: 0;
        }

        .chart-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .chart-wide {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }

        .chart-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-period-select {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background: var(--card-bg);
            color: var(--text-color);
        }

        .chart-expand-btn {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .chart-expand-btn:hover {
            background: var(--hover-color);
            color: var(--text-color);
        }

        /* Chart Year Filter Styles */
        .chart-year-filter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .year-filter-select {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            min-width: 100px;
        }

        .year-filter-select:hover {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .year-filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        .schedule-year-filter {
            margin-right: 15px;
        }

        .chart-container {
            height: 300px;
            position: relative;
            max-width: 100%;
            width: 100%;
        }

        .chart-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: var(--border-radius);
        }

        /* ===== SCHEDULE STYLES ===== */
        .schedule-container {
            overflow-x: auto;
        }

        .schedule-header {
            padding: 1rem 0;
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: 1rem;
        }

        .schedule-header h4 {
            margin: 0;
            color: var(--text-color);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .class-tabs {
            display: flex;
            gap: 0.5rem;
        }

        .class-tab {
            padding: 0.6rem 1.2rem;
            border: 2px solid var(--border-color);
            border-radius: 4px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .class-tab:hover {
            background: var(--hover-color);
            border-color: var(--primary-color);
        }

        .class-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .schedule-table {
            display: none;
        }

        .schedule-table.active {
            display: block;
        }

        .formal-schedule {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            border: 2px solid var(--border-color);
            background: white;
            table-layout: fixed;
        }

        .formal-schedule th {
            padding: 8px 6px;
            text-align: center;
            border: 1px solid var(--border-color);
            background: #f8f9fa;
            color: var(--text-color);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .formal-schedule th:first-child {
            width: 100px;
        }

        .formal-schedule td {
            padding: 8px 6px;
            text-align: center;
            border: 1px solid var(--border-color);
            vertical-align: middle;
            font-weight: 500;
        }

        .formal-schedule td:first-child {
            width: 100px;
            font-weight: 600;
        }

        .time-period {
            background: #e8f4fd !important;
            font-weight: 700;
            color: #1565c0;
            font-size: 0.8rem;
        }

        .subject-cell {
            font-weight: 500;
            font-size: 0.82rem;
            color: #333;
        }

        /* Subject-specific light colors */
        .subject-math {
            background: #e3f2fd !important;
            color: #1976d2;
        }

        .subject-english {
            background: #f3e5f5 !important;
            color: #7b1fa2;
        }

        .subject-science {
            background: #e0f2f1 !important;
            color: #00695c;
        }

        .subject-history {
            background: #fff3e0 !important;
            color: #ef6c00;
        }

        .subject-geography {
            background: #e8f5e8 !important;
            color: #2e7d32;
        }

        .subject-computer {
            background: #f1f8e9 !important;
            color: #558b2f;
        }

        .subject-art {
            background: #fce4ec !important;
            color: #c2185b;
        }

        .subject-pe {
            background: #fff8e1 !important;
            color: #f57c00;
        }

        .break-cell {
            background: #f5f5f5 !important;
            color: #666;
            font-weight: 600;
            font-style: italic;
        }

        .no-class {
            background: #fafafa !important;
            color: #999;
            font-style: italic;
        }

        .formal-schedule tr:hover .subject-cell {
            opacity: 0.8;
        }

        /* Schedule slot layout */
        .day-schedule {
            text-align: left !important;
            padding: 8px !important;
            direction: ltr !important;
        }

        .schedule-slot {
            display: inline-block;
            margin: 3px 5px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            border: 1px solid #ddd;
            text-align: left;
            direction: ltr;
        }

        .schedule-slot .time {
            font-weight: 700;
            display: block;
            font-size: 0.8rem;
            margin-bottom: 2px;
        }

        .schedule-slot .subject {
            font-weight: 500;
        }

        /* ===== DATA TABLE ===== */
        .data-table-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }

        .export-actions {
            display: flex;
            gap: 0.5rem;
        }

        .export-btn {
            padding: 0.6rem 1.2rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Roboto', 'Noto Sans', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .export-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* ===== ACTION BUTTONS FOR TEACHERS ===== */
        .action-btn {
            padding: 0.4rem 0.8rem;
            margin: 0 0.2rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
            font-weight: 500;
            background: var(--hover-color);
            color: var(--text-color);
        }

        .action-btn.edit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .action-btn.edit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }

        .action-btn.delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .action-btn.delete:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }

        .action-btn.view {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .action-btn.view:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }

        /* Total subjects badge */
        .total-subjects-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 0.8rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .total-subjects-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        /* Badge styles */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            border: 1px solid transparent;
        }
        
        .badge-primary {
            background: #eff6ff;
            color: #1e40af;
            border-color: #bfdbfe;
        }
        
        .badge-secondary {
            background: #f1f5f9;
            color: #334155;
            border-color: #e2e8f0;
        }
        
        .badge-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }
        
        .badge-danger {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }
        
        .badge-info {
            background: #f0f9ff;
            color: #075985;
            border-color: #bae6fd;
        }
        
        /* Modal styles */
        .modal {
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .close:hover,
        .close:focus {
            transform: scale(1.2);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-container {
            overflow-x: auto;
            overflow-y: visible;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin: 0;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
            position: relative;
            background: white;
        }
        
        /* Prevent page horizontal scroll */
        .data-table-section {
            overflow-x: hidden;
            max-width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            table-layout: fixed;
        }

        th, td {
            padding: 0.875rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        th {
            background: var(--hover-color);
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Students Table Column Widths for Perfect Alignment */
        #studentsTable th:nth-child(1), #studentsTable td:nth-child(1) { width: 50px; text-align: center; }
        #studentsTable th:nth-child(2), #studentsTable td:nth-child(2) { width: 150px; }
        #studentsTable th:nth-child(3), #studentsTable td:nth-child(3) { width: 60px; text-align: center; }
        #studentsTable th:nth-child(4), #studentsTable td:nth-child(4) { width: 100px; text-align: center; }
        #studentsTable th:nth-child(5), #studentsTable td:nth-child(5) { width: 100px; text-align: center; }
        #studentsTable th:nth-child(6), #studentsTable td:nth-child(6) { width: 120px; text-align: center; }
        #studentsTable th:nth-child(7), #studentsTable td:nth-child(7) { width: 110px; }
        #studentsTable th:nth-child(8), #studentsTable td:nth-child(8) { width: auto; }
        #studentsTable th:nth-child(9), #studentsTable td:nth-child(9) { width: 150px; }
        
        /* Action buttons alignment */
        #studentsTable td:nth-child(9) {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }
        
        #studentsTable td:nth-child(9) button {
            width: 100%;
            margin: 0 !important;
        }
        
        #teachersTable td:last-child {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
        }
        
        #teachersTable td:last-child button {
            width: 100%;
            margin: 0 !important;
        }

        /* Teachers Table Specific Styling */
        #teachersTable {
            table-layout: fixed;
        }
        
        #teachersTable th {
            text-align: center;
            vertical-align: middle;
        }
        
        /* Email column - fixed width with ellipsis */
        #teachersTable th:nth-child(3),
        #teachersTable td:nth-child(3) {
            width: 180px;
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Phone column - fixed width */
        #teachersTable th:nth-child(4),
        #teachersTable td:nth-child(4) {
            width: 130px;
            max-width: 130px;
        }

        #teachersTable th:nth-child(2),
        #teachersTable th:nth-child(3) {
            text-align: left;
        }

        #teachersTable td {
            vertical-align: middle;
        }

        #teachersTable td:nth-child(2),
        #teachersTable td:nth-child(3) {
            text-align: left;
        }
        
        /* Teacher Access/Credential Table Specific Styling */
        #teacherAccessTable {
            table-layout: fixed;
        }
        
        /* Email column in Teacher Access table - fixed width with ellipsis */
        #teacherAccessTable th:nth-child(3),
        #teacherAccessTable td:nth-child(3) {
            width: 200px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: left;
        }
        
        tr:hover {
            background: var(--hover-color);
        }

        .grade-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            min-width: 40px;
        }

        .grade-a-plus { background: #dcfce7; color: #166534; }
        .grade-a { background: #dbeafe; color: #1e40af; }
        .grade-b { background: #fef3c7; color: #d97706; }
        .grade-c { background: #fed7aa; color: #ea580c; }
        .grade-f { background: #fecaca; color: #dc2626; }

        /* ===== EXPANDABLE STUDENT ROWS ===== */
        .student-main-row {
            cursor: pointer;
            background: white;
            transition: all 0.3s ease;
            border-left: 4px solid transparent !important;
        }

        .student-main-row:hover {
            background: #f8f9fa !important;
            border-left-color: var(--primary-color) !important;
        }

        .student-main-row.expanded {
            background: #f0f7ff !important;
            border-left-color: var(--primary-color) !important;
        }
        


        .student-name-cell {
            font-weight: 600;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .expand-icon {
            display: inline-block;
            transition: transform 0.3s ease;
            font-size: 0.9rem;
            color: #64748b;
        }

        .student-main-row.expanded .expand-icon {
            transform: rotate(90deg);
        }

        .subject-details-row {
            display: none;
            background: #f8fafc;
            border-left: 4px solid #3b82f6 !important;
        }

        .subject-details-row.show {
            display: table-row;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .subject-details-container {
            padding: 1rem;
            background: white;
            border-top: 3px solid #667eea;
            max-width: 100%;
            overflow-x: hidden;
        }

        .subject-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .subject-marks-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            table-layout: auto;
            word-wrap: break-word;
        }

        .subject-marks-table th {
            background: #f1f5f9;
            color: #1e293b;
            padding: 0.4rem 0.6rem;
            font-size: 0.75rem;
            text-align: left;
            font-weight: 600;
            white-space: normal;
            border-bottom: 2px solid #e2e8f0;
        }

        .subject-marks-table td {
            padding: 0.4rem 0.6rem;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.8rem;
            white-space: normal;
            word-wrap: break-word;
        }

        .subject-marks-table td:nth-child(4),
        .subject-marks-table td:nth-child(6) {
            text-align: center;
        }

        .subject-marks-table tr:last-child td {
            border-bottom: none;
        }

        .subject-marks-table tr:hover {
            background: #f0f9ff;
        }

        .mark-input-inline {
            width: 60px;
            padding: 0.4rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-align: center;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .mark-input-inline:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .inline-edit-btn {
            padding: 0.3rem 0.7rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .inline-edit-btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .inline-delete-btn {
            padding: 0.3rem 0.7rem;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .inline-delete-btn:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }

        .manage-marks-btn {
            padding: 0.4rem 1rem;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
        }

        .manage-marks-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
        }

        .total-subjects-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .final-grade-display {
            font-size: 1.1rem;
            font-weight: 700;
            color: #3b82f6;
        }

        /* ===== LOADING OVERLAY ===== */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.15s ease;
        }

        .loading-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .loading-content {
            background: white;
            padding: 1.5rem 2.5rem;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.2s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Advanced Loader Styles */
        .loader {
            width: 6em;
            height: 6em;
            margin: 0 auto 1rem;
        }

        .loader-ring {
            animation: ringA 2s linear infinite;
        }

        .loader-ring-a {
            stroke: #1e3a8a;
        }

        .loader-ring-b {
            animation-name: ringB;
            stroke: #1e40af;
        }

        .loader-ring-c {
            animation-name: ringC;
            stroke: #2563eb;
        }

        .loader-ring-d {
            animation-name: ringD;
            stroke: #1e40af;
        }

        /* Ring Animations */
        @keyframes ringA {
            from, 4% {
                stroke-dasharray: 0 660;
                stroke-width: 8;
                stroke-dashoffset: -330;
            }
            12% {
                stroke-dasharray: 60 600;
                stroke-width: 12;
                stroke-dashoffset: -335;
            }
            32% {
                stroke-dasharray: 60 600;
                stroke-width: 12;
                stroke-dashoffset: -595;
            }
            40%, 54% {
                stroke-dasharray: 0 660;
                stroke-width: 8;
                stroke-dashoffset: -660;
            }
            62% {
                stroke-dasharray: 60 600;
                stroke-width: 12;
                stroke-dashoffset: -665;
            }
            82% {
                stroke-dasharray: 60 600;
                stroke-width: 12;
                stroke-dashoffset: -925;
            }
            90%, to {
                stroke-dasharray: 0 660;
                stroke-width: 8;
                stroke-dashoffset: -990;
            }
        }

        @keyframes ringB {
            from, 12% {
                stroke-dasharray: 0 220;
                stroke-width: 8;
                stroke-dashoffset: -110;
            }
            20% {
                stroke-dasharray: 20 200;
                stroke-width: 12;
                stroke-dashoffset: -115;
            }
            40% {
                stroke-dasharray: 20 200;
                stroke-width: 12;
                stroke-dashoffset: -195;
            }
            48%, 62% {
                stroke-dasharray: 0 220;
                stroke-width: 8;
                stroke-dashoffset: -220;
            }
            70% {
                stroke-dasharray: 20 200;
                stroke-width: 12;
                stroke-dashoffset: -225;
            }
            90% {
                stroke-dasharray: 20 200;
                stroke-width: 12;
                stroke-dashoffset: -305;
            }
            98%, to {
                stroke-dasharray: 0 220;
                stroke-width: 8;
                stroke-dashoffset: -330;
            }
        }

        @keyframes ringC {
            from {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: 0;
            }
            8% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -5;
            }
            28% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -175;
            }
            36%, 58% {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: -220;
            }
            66% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -225;
            }
            86% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -395;
            }
            94%, to {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: -440;
            }
        }

        @keyframes ringD {
            from, 8% {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: 0;
            }
            16% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -5;
            }
            36% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -175;
            }
            44%, 50% {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: -220;
            }
            58% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -225;
            }
            78% {
                stroke-dasharray: 40 400;
                stroke-width: 12;
                stroke-dashoffset: -395;
            }
            86%, to {
                stroke-dasharray: 0 440;
                stroke-width: 8;
                stroke-dashoffset: -440;
            }
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #e0e7ff;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 0.5rem;
            font-family: 'Roboto', 'Noto Sans', sans-serif;
        }

        .loading-subtext {
            font-size: 0.9rem;
            color: #64748b;
            font-family: 'Roboto', 'Noto Sans', sans-serif;
        }

        .loading-dots {
            display: inline-block;
            width: 1ch;
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }

        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }

        /* Success message animation */
        .success-message {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 1rem 2rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            animation: slideInDown 0.5s ease;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-message {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 1rem 2rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
            animation: slideInDown 0.5s ease;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 1200px) {
            .charts-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .nav-container { padding: 0 1rem; flex-wrap: wrap; }
            .user-info-section { flex-wrap: wrap; width: 100%; justify-content: center !important; margin-top: 10px; }
            .current-user { font-size: 12px !important; }
            .reports-main-content { padding: 1rem; }
            .dashboard-header { padding: 2rem 1rem 1.5rem; text-align: center; }
            .dashboard-header h1 { font-size: 1.5rem; }
            .charts-grid { grid-template-columns: 1fr; gap: 1rem; }
            .kpi-grid { grid-template-columns: 1fr; gap: 1rem; }
            .chart-card { padding: 1rem; margin: 0; }
            .chart-container { height: 250px; }
            .filter-sections { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-content">
            <svg class="loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                <circle class="loader-ring loader-ring-a" cx="50" cy="50" r="45" fill="none" stroke-width="4" stroke-linecap="round"></circle>
                <circle class="loader-ring loader-ring-b" cx="50" cy="50" r="35" fill="none" stroke-width="4" stroke-linecap="round"></circle>
                <circle class="loader-ring loader-ring-c" cx="50" cy="50" r="25" fill="none" stroke-width="4" stroke-linecap="round"></circle>
                <circle class="loader-ring loader-ring-d" cx="50" cy="50" r="15" fill="none" stroke-width="4" stroke-linecap="round"></circle>
            </svg>
            <div class="loading-text">Processing<span class="loading-dots"></span></div>
            <div class="loading-subtext">Please wait while we update your data</div>
        </div>
    </div>

    <nav>
        <div class="nav-container">
            <div class="nav-brand">
                <div class="brand-icon" style="overflow: hidden;">
                    <img src="photo_2025-11-12_21-42-15.jpg" alt="Logo" style="width: 65px; height: 65px; object-fit: cover; border-radius: 8px;">
                </div>
                <div class="brand-text">
                    <span class="brand-title" data-translate="system_title">Student Management</span>
                    <span class="brand-subtitle" data-translate="system_subtitle">Academic Portal</span>
                </div>
            </div>
            <div class="nav-links">
                <?php if (isAdmin()): ?>
                    <a href="?page=reports" <?= $page == 'reports' ? 'class="active"' : '' ?> data-translate="nav_reports">Dashboard</a>
                    <a href="?page=students" <?= $page == 'students' ? 'class="active"' : '' ?> data-translate="nav_students">Students</a>
                    <a href="?page=marks" <?= $page == 'marks' ? 'class="active"' : '' ?> data-translate="nav_marks">Marks</a>
                    <a href="?page=subjects" <?= $page == 'subjects' ? 'class="active"' : '' ?> data-translate="nav_subjects">Subjects</a>
                    <a href="?page=teachers" <?= $page == 'teachers' ? 'class="active"' : '' ?> data-translate="nav_teachers">Teachers</a>
                    <a href="?page=teacher_dashboard" <?= $page == 'teacher_dashboard' ? 'class="active"' : '' ?> data-translate="nav_access">Access</a>
                    <a href="?page=graduated" <?= $page == 'graduated' ? 'class="active"' : '' ?> data-translate="nav_graduated">Graduates</a>
                <?php else: ?>
                    <a href="?page=dashboard" <?= $page == 'dashboard' ? 'class="active"' : '' ?> data-translate="nav_dashboard">Dashboard</a>
                    <a href="?page=marks" <?= $page == 'marks' ? 'class="active"' : '' ?> data-translate="nav_marks">Marks</a>
                <?php endif; ?>
            </div>
            <div class="user-info-section">
                <div class="current-user" style="display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                    <div style="display: flex; flex-direction: column; align-items: flex-start;">
                        <span style="font-size: 11px; opacity: 0.8; color: white;" data-translate="<?= isAdmin() ? 'administrator' : 'teacher' ?>"><?= isAdmin() ? 'Administrator' : 'Teacher' ?></span>
                        <span style="font-weight: 600; font-size: 13px; color: white;"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
                    </div>
                </div>
                <a href="logout.php" style="padding: 8px 14px; background: rgba(255,255,255,0.2); border-radius: 8px; text-decoration: none; color: white; font-weight: 500; font-size: 13px; transition: all 0.3s; border: 1px solid rgba(255,255,255,0.3);" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'" data-translate="logout">
                    Logout
                </a>
                <div class="language-switcher">
                    <select id="languageSelector" onchange="changeLanguage(this.value)">
                        <option value="en">EN</option>
                        <option value="ar">AR</option>
                        <option value="ku">KU</option>
                    </select>
                </div>
            </div>
        </div>
    </nav>

    <!-- Notification Container (for AJAX messages) -->
    <div id="notificationContainer" style="position: fixed; top: 80px; right: 20px; z-index: 10000; max-width: 400px;"></div>

    <!-- Notification Messages -->
    <?php if (isset($success_message)): ?>
    <div style="background: var(--success-color); color: white; padding: 1rem; text-align: center; margin: 1rem 2rem; border-radius: 8px;">
        <?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
    <div style="background: var(--danger-color); color: white; padding: 1rem; text-align: center; margin: 1rem 2rem; border-radius: 8px;">
        <?= htmlspecialchars($error_message) ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <?php if ($page == 'dashboard' && isTeacher()): ?>
            <!-- PROFESSIONAL TEACHER DASHBOARD -->
            <?php
            $teacher_id = $_SESSION['teacher_id'];
            
            // Get teacher info and subjects
            $teacher_info = pg_query_params($conn, "SELECT * FROM teachers WHERE id = $1", [$teacher_id]);
            $teacher = pg_fetch_assoc($teacher_info);
            
            $teacher_subjects = pg_query_params($conn, "
                SELECT s.id, s.subject_name, s.year 
                FROM subjects s
                INNER JOIN teacher_subjects ts ON s.id = ts.subject_id
                WHERE ts.teacher_id = $1
                ORDER BY s.year, s.subject_name
            ", [$teacher_id]);
            
            // Get teacher tasks
            $tasks_query = "
                SELECT t.*, s.subject_name, s.year as subject_year
                FROM teacher_tasks t
                LEFT JOIN subjects s ON t.subject_id = s.id
                WHERE t.teacher_id = $1 AND t.status != 'cancelled'
                ORDER BY 
                    CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
                    t.due_date ASC,
                    CASE t.priority 
                        WHEN 'high' THEN 1 
                        WHEN 'medium' THEN 2 
                        WHEN 'low' THEN 3 
                    END
            ";
            $tasks_result = pg_query_params($conn, $tasks_query, [$teacher_id]);
            
            // Get UNIQUE student count for this teacher (students enrolled in teacher's assigned subjects for their specific years)
            $student_count = pg_query_params($conn, "
                SELECT COUNT(DISTINCT m.student_id) as count
                FROM marks m
                INNER JOIN subjects s ON m.subject_id = s.id
                INNER JOIN teacher_subjects ts ON s.id = ts.subject_id AND s.year = ts.year
                INNER JOIN students st ON m.student_id = st.id AND st.year = ts.year
                WHERE ts.teacher_id = $1 AND st.status = 'active'
            ", [$teacher_id]);
            $total_students = pg_fetch_result($student_count, 0, 0);
            
            // Get UNIQUE students with incomplete marks (any mark component is 0)
            $incomplete_marks = pg_query_params($conn, "
                SELECT COUNT(DISTINCT m.student_id) as count
                FROM marks m
                INNER JOIN subjects s ON m.subject_id = s.id
                INNER JOIN teacher_subjects ts ON s.id = ts.subject_id AND s.year = ts.year
                INNER JOIN students st ON m.student_id = st.id AND st.year = ts.year
                WHERE ts.teacher_id = $1 
                AND st.status = 'active'
                AND (m.final_exam = 0 OR m.midterm_exam = 0 OR m.quizzes = 0 OR m.daily_activities = 0)
            ", [$teacher_id]);
            $incomplete_count = pg_fetch_result($incomplete_marks, 0, 0);
            
            // Get overdue tasks count
            $overdue_tasks = pg_query_params($conn, "
                SELECT COUNT(*) as count
                FROM teacher_tasks
                WHERE teacher_id = $1 
                AND status = 'pending' 
                AND due_date < CURRENT_DATE
            ", [$teacher_id]);
            $overdue_count = pg_fetch_result($overdue_tasks, 0, 0);
            ?>
            
            <div class="content-wrapper">
                <div class="dashboard-header">
                    <h1 data-translate="academic_task_manager">Academic Task Manager</h1>
                    <p class="dashboard-subtitle"><span data-translate="welcome">Welcome</span>, <?= htmlspecialchars($teacher['name']) ?> - <span data-translate="manage_teaching_schedule">Manage your teaching schedule and academic tasks</span></p>
                </div>

                <div class="reports-main-content">
                    <!-- Quick Stats -->
                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= pg_num_rows($teacher_subjects) ?></div>
                                <div class="kpi-label" data-translate="subjects_teaching">Subjects Teaching</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $total_students ?></div>
                                <div class="kpi-label" data-translate="kpi_total_students">Total Students</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $incomplete_count ?></div>
                                <div class="kpi-label" data-translate="students_pending_marks">Students Pending Marks</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $overdue_count ?></div>
                                <div class="kpi-label" data-translate="overdue_tasks">Overdue Tasks</div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Content Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
                        <!-- Add New Task Section -->
                        <div class="chart-card">
                            <h3 class="chart-title" style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-plus-circle" style="color: var(--primary-color);"></i>
                                <span data-translate="create_new_task">Create New Task</span>
                            </h3>
                            <form id="taskForm" style="padding: 20px;">
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="task_type">Task Type</label>
                                    <select name="task_type" required style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                        <option value="exam" data-translate="task_examination">Examination</option>
                                        <option value="homework" data-translate="task_homework">Homework Assignment</option>
                                        <option value="reminder" data-translate="task_reminder">Reminder</option>
                                        <option value="note" data-translate="task_note">General Note</option>
                                    </select>
                                </div>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="subject">Subject</label>
                                    <select name="subject_id" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                        <option value="" data-translate="all_subjects">All Subjects</option>
                                        <?php while ($subject = pg_fetch_assoc($teacher_subjects)): ?>
                                            <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['subject_name']) ?> (<span data-translate="year">Year</span> <?= $subject['year'] ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="task_title">Title <span style="color: #ef4444;">*</span></label>
                                    <input type="text" name="title" required data-translate-placeholder="task_title_placeholder" placeholder="e.g., Midterm Exam - Chapter 1-3" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                </div>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="task_description">Description</label>
                                    <textarea name="description" rows="3" data-translate-placeholder="task_description_placeholder" placeholder="Additional details..." style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="task_due_date">Due Date</label>
                                        <input type="date" name="due_date" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #334155;" data-translate="task_priority">Priority</label>
                                        <select name="priority" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                            <option value="low" data-translate="priority_low">Low</option>
                                            <option value="medium" selected data-translate="priority_medium">Medium</option>
                                            <option value="high" data-translate="priority_high">High</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" style="width: 100%; padding: 12px; background: var(--primary-color); color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer;">
                                    <i class="fas fa-save"></i> <span data-translate="create_task_btn">Create Task</span>
                                </button>
                            </form>
                        </div>

                        <!-- My Subjects Section -->
                        <div class="chart-card">
                            <h3 class="chart-title" style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-book" style="color: var(--primary-color);"></i>
                                <span data-translate="my_subjects">My Subjects</span>
                            </h3>
                            <div style="padding: 20px;">
                                <?php 
                                pg_result_seek($teacher_subjects, 0); // Reset pointer
                                if (pg_num_rows($teacher_subjects) > 0):
                                    while ($subj = pg_fetch_assoc($teacher_subjects)):
                                        // Get the teacher's assignment details to match year
                                        $assignment = pg_query_params($conn, "
                                            SELECT year FROM teacher_subjects 
                                            WHERE teacher_id = $1 AND subject_id = $2
                                        ", [$teacher_id, $subj['id']]);
                                        $assigned_year = pg_fetch_result($assignment, 0, 0);
                                        
                                        // Get class breakdown for this subject
                                        $class_breakdown_query = pg_query_params($conn, "
                                            SELECT st.class_level, COUNT(DISTINCT st.id) as count
                                            FROM students st
                                            INNER JOIN marks m ON st.id = m.student_id
                                            WHERE m.subject_id = $1 
                                            AND st.status = 'active'
                                            AND st.year = $2
                                            GROUP BY st.class_level
                                            ORDER BY st.class_level
                                        ", [$subj['id'], $assigned_year]);
                                        
                                        $class_breakdown = [];
                                        $total_students = 0;
                                        while ($class_row = pg_fetch_assoc($class_breakdown_query)) {
                                            $class_breakdown[] = $class_row;
                                            $total_students += (int)$class_row['count'];
                                        }
                                ?>
                                    <div style="background: #f8fafc; border-left: 4px solid var(--primary-color); padding: 15px; margin-bottom: 12px; border-radius: 6px;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                                            <div style="flex: 1;">
                                                <h4 style="margin: 0 0 8px 0; color: #1e293b; font-size: 15px; font-weight: 700;"><?= htmlspecialchars($subj['subject_name']) ?></h4>
                                                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                                    <span style="padding: 4px 10px; background: #1e3a8a; color: white; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                        <i class="fas fa-calendar-alt"></i> <span data-translate="year">Year</span> <?= $subj['year'] ?>
                                                    </span>
                                                    <?php if (count($class_breakdown) > 0): ?>
                                                        <?php foreach ($class_breakdown as $class_info): ?>
                                                            <span style="padding: 4px 10px; background: #2563eb; color: white; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                                <i class="fas fa-door-open"></i> <span data-translate="class">Class</span> <?= htmlspecialchars($class_info['class_level']) ?>: <?= $class_info['count'] ?> <span data-translate="students">Students</span>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($class_breakdown) > 1): ?>
                                                            <span style="padding: 4px 10px; background: #3b82f6; color: white; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                                <i class="fas fa-users"></i> <span data-translate="total">Total</span>: <?= $total_students ?> <span data-translate="students">Students</span>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="padding: 4px 10px; background: #94a3b8; color: white; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                            <i class="fas fa-users"></i> 0 <span data-translate="students">Students</span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <a href="?page=marks&subject=<?= $subj['id'] ?>" style="padding: 8px 16px; background: var(--primary-color); color: white; text-decoration: none; border-radius: 5px; font-size: 13px; font-weight: 600; white-space: nowrap;">
                                                <span data-translate="view_marks">View Marks</span>
                                            </a>
                                        </div>
                                    </div>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                    <p style="text-align: center; color: #94a3b8; padding: 40px;" data-translate="no_subjects_assigned">No subjects assigned yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks List -->
                    <div class="chart-card" style="margin-top: 30px;">
                        <h3 class="chart-title" style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-tasks" style="color: var(--primary-color);"></i>
                            <span data-translate="my_tasks_schedule">My Tasks & Schedule</span>
                        </h3>
                        <div id="tasksList" style="padding: 20px;">
                            <?php if (pg_num_rows($tasks_result) > 0): ?>
                                <div style="display: grid; gap: 12px;">
                                    <?php while ($task = pg_fetch_assoc($tasks_result)): 
                                        $is_overdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && $task['status'] == 'pending';
                                        $priority_colors = [
                                            'high' => '#ef4444',
                                            'medium' => '#f59e0b',
                                            'low' => '#3b82f6'
                                        ];
                                        $type_icons = [
                                            'exam' => 'fa-file-alt',
                                            'homework' => 'fa-book-open',
                                            'reminder' => 'fa-bell',
                                            'note' => 'fa-sticky-note'
                                        ];
                                    ?>
                                        <div class="task-item" data-task-id="<?= $task['id'] ?>" style="background: <?= $task['status'] == 'completed' ? '#f0fdf4' : ($is_overdue ? '#fef2f2' : 'white') ?>; border: 1px solid <?= $task['status'] == 'completed' ? '#86efac' : ($is_overdue ? '#fecaca' : '#e2e8f0') ?>; border-left: 4px solid <?= $priority_colors[$task['priority']] ?>; padding: 16px; border-radius: 8px;">
                                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                                <div style="flex: 1;">
                                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                                        <i class="fas <?= $type_icons[$task['task_type']] ?>" style="color: <?= $priority_colors[$task['priority']] ?>; font-size: 18px;"></i>
                                                        <h4 style="margin: 0; color: #1e293b; font-size: 16px; <?= $task['status'] == 'completed' ? 'text-decoration: line-through; opacity: 0.7;' : '' ?>">
                                                            <?= htmlspecialchars($task['title']) ?>
                                                        </h4>
                                                        <span style="background: <?= $priority_colors[$task['priority']] ?>; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                                            <?= $task['priority'] ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if ($task['description']): ?>
                                                        <p style="margin: 8px 0; color: #64748b; font-size: 14px;"><?= htmlspecialchars($task['description']) ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <div style="display: flex; gap: 15px; margin-top: 8px; font-size: 13px; color: #64748b;">
                                                        <span><i class="fas fa-tag"></i> <?= ucfirst($task['task_type']) ?></span>
                                                        <?php if ($task['subject_name']): ?>
                                                            <span><i class="fas fa-book"></i> <?= htmlspecialchars($task['subject_name']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($task['due_date']): ?>
                                                            <span style="<?= $is_overdue ? 'color: #ef4444; font-weight: 600;' : '' ?>">
                                                                <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                                <?= $is_overdue ? '(OVERDUE)' : '' ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <div style="display: flex; gap: 8px;">
                                                    <?php if ($task['status'] == 'pending'): ?>
                                                        <button onclick="updateTaskStatus(<?= $task['id'] ?>, 'completed')" style="padding: 8px 12px; background: #10b981; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 13px;">
                                                            <i class="fas fa-check"></i> Complete
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="updateTaskStatus(<?= $task['id'] ?>, 'pending')" style="padding: 8px 12px; background: #64748b; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 13px;">
                                                            <i class="fas fa-undo"></i> Reopen
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="deleteTask(<?= $task['id'] ?>)" style="padding: 8px 12px; background: #ef4444; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 13px;">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p style="text-align: center; color: #94a3b8; padding: 40px;">
                                    <i class="fas fa-inbox" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                                    <span data-translate="no_tasks_yet">No tasks yet. Create your first task above to get started.</span>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            // Task Management Functions
            document.getElementById('taskForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'add_task');
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Task created successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            });
            
            function updateTaskStatus(taskId, status) {
                const formData = new FormData();
                formData.append('action', 'update_task_status');
                formData.append('task_id', taskId);
                formData.append('status', status);
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error updating task');
                    }
                });
            }
            
            function deleteTask(taskId) {
                if (!confirm('Are you sure you want to delete this task?')) return;
                
                const formData = new FormData();
                formData.append('action', 'delete_task');
                formData.append('task_id', taskId);
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error deleting task');
                    }
                });
            }
            </script>

        <?php elseif ($page == 'reports'): ?>
            <!-- PREMIUM REPORTS DASHBOARD -->
            <div class="content-wrapper page-dashboard">
                <div class="reports-dashboard">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header">
                        <h1 data-translate="analytics_dashboard">Analytics Dashboard</h1>
                        <p class="dashboard-subtitle" data-translate="comprehensive_overview">Comprehensive Student Performance Overview</p>
                    </div>

                    <!-- Main Content -->
                    <div class="reports-main-content">
                        <!-- Year Filter Toggle -->
                        <div class="year-filter-container" data-aos="fade-down" data-aos-delay="50">
                            <div class="filter-title">
                                <span data-translate="view_data_for">View Data For:</span>
                            </div>
                            <div class="year-toggle-switch">
                                <input type="radio" id="year-all" name="year-filter" value="" checked>
                                <label for="year-all" data-translate="all_years">All Years</label>
                                
                                <input type="radio" id="year-1" name="year-filter" value="1">
                                <label for="year-1" data-translate="year_1_only">Year 1 Only</label>
                                
                                <input type="radio" id="year-2" name="year-filter" value="2">
                                <label for="year-2" data-translate="year_2_only">Year 2 Only</label>
                            </div>
                        </div>

                        <!-- Premium KPI Cards -->
                        <div class="kpi-grid">
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['year1_students'] ?? 0 ?>"><?= $chartData['kpis']['year1_students'] ?? 0 ?></div>
                                    <div class="kpi-label" data-translate="year_1_students">Year 1 Students</div>
                                    <div class="kpi-trend positive" data-translate="active_students">Active students</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="150">
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['year2_students'] ?? 0 ?>"><?= $chartData['kpis']['year2_students'] ?? 0 ?></div>
                                    <div class="kpi-label" data-translate="year_2_students">Year 2 Students</div>
                                    <div class="kpi-trend positive" data-translate="active_students">Active students</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="200">
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['avg_score'] ?? 0 ?>%</div>
                                    <div class="kpi-label" data-translate="average_score">Average Score</div>
                                    <div class="kpi-trend positive">
                                        <?php if (isset($chartData['kpis']['top_year']) && $chartData['kpis']['top_year']): ?>
                                            <span data-translate="top_performing_year">Top Performing Year</span>: 
                                            <span data-translate="year">Year</span> <?= $chartData['kpis']['top_year'] ?>
                                        <?php else: ?>
                                            <span data-translate="overall_performance">Overall performance</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="250">
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['top_class_display'] ?? 'N/A' ?></div>
                                    <div class="kpi-label" data-translate="top_class_performance">Top Class Performance</div>
                                    <div class="kpi-trend positive"><?= $chartData['kpis']['top_class_score'] ?? 0 ?>% score</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="300">
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['pass_rate'] ?? 0 ?>%</div>
                                    <div class="kpi-label" data-translate="pass_rate">Pass Rate</div>
                                    <div class="kpi-trend positive" data-translate="passing_students">Passing students</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="350">
                                <div class="kpi-content">
                                    <div class="kpi-value" style="font-size: 1.4rem; line-height: 1.2; overflow-wrap: break-word; word-wrap: break-word; hyphens: auto;"><?= htmlspecialchars($chartData['kpis']['risk_subject'] ?? 'N/A') ?></div>
                                    <div class="kpi-label" data-translate="risk_subject">Risk Subject</div>
                                    <div class="kpi-trend negative"><?= $chartData['kpis']['risk_failure_rate'] ?? 0 ?>% fail rate</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="400">
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['enrolled_students'] ?? 0 ?></div>
                                    <div class="kpi-label" data-translate="enrolled_students">Enrolled Students</div>
                                    <div class="kpi-trend positive" data-translate="with_marks">With marks recorded</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="450">
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['excellence_rate'] ?? 0 ?>%</div>
                                    <div class="kpi-label" data-translate="excellence_rate">Excellence Rate</div>
                                    <div class="kpi-trend <?= ($chartData['kpis']['excellence_rate'] ?? 0) >= 15 ? 'positive' : 'negative' ?>" data-translate="a_plus_students">A+ students (90+)</div>
                                </div>
                            </div>
                        </div>

                        <script>
                        // Add animated waves to all KPI cards on Reports page
                        (function() {
                            // Wait for page to fully load
                            if (document.readyState === 'loading') {
                                document.addEventListener('DOMContentLoaded', addWaves);
                            } else {
                                addWaves();
                            }
                            
                            function addWaves() {
                                const kpiCards = document.querySelectorAll('.reports-main-content .kpi-grid .kpi-card');
                                console.log('Found KPI cards:', kpiCards.length);
                                
                                kpiCards.forEach((card, index) => {
                                    // Check if blob already exists
                                    if (card.querySelector('.blob')) {
                                        console.log('Blob already exists in card', index);
                                        return;
                                    }
                                    
                                    // Add blob and bg elements
                                    const blob = document.createElement('div');
                                    blob.className = 'blob';
                                    card.insertBefore(blob, card.firstChild);
                                    
                                    const bg = document.createElement('div');
                                    bg.className = 'bg';
                                    card.insertBefore(bg, card.firstChild.nextSibling);
                                    
                                    console.log('Added blob animation to card', index);
                                });
                            }
                        })();
                        </script>

                        <!-- Charts Section -->
                        <div class="charts-grid">
                            <!-- Performance Distribution Chart -->
                            <div class="chart-card" data-aos="fade-up">
                                <div class="chart-header">
                                    <h3 class="chart-title"><i class="fas fa-chart-bar"></i> Grade Distribution</h3>
                                    <div class="chart-actions">
                                        <div class="chart-year-filter">
                                            <select id="gradeDistributionYearFilter" class="year-filter-select" onchange="updateGradeDistributionChart(this.value)">
                                                <option value="" data-translate="all_years">All Years</option>
                                                <option value="1" data-translate="year_1">Year 1</option>
                                                <option value="2" data-translate="year_2">Year 2</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="performanceDistributionChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Subject Performance Chart - Year 1 -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <span data-translate="year">Year</span> 1 - <span data-translate="subject_performance">Subject Performance</span>
                                    </h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('subjectChart1')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="subjectPerformanceChart1" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Top Performers Chart - Year 1 -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="120">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <span data-translate="year">Year</span> 1 - <i class="fas fa-trophy"></i> <span data-translate="top_performers">Top 3 Performers</span>
                                    </h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('topPerformersChart1')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="topPerformersChart1" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Subject Performance Chart - Year 2 -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="140">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <span data-translate="year">Year</span> 2 - <span data-translate="subject_performance">Subject Performance</span>
                                    </h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('subjectChart2')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="subjectPerformanceChart2" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Top Performers Chart - Year 2 -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="160">
                                <div class="chart-header">
                                    <h3 class="chart-title">
                                        <span data-translate="year">Year</span> 2 - <i class="fas fa-trophy"></i> <span data-translate="top_performers">Top 3 Performers</span>
                                    </h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('topPerformersChart2')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="topPerformersChart2" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Student Distribution by Year Chart -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="200">
                                <div class="chart-header">
                                    <h3 class="chart-title" data-translate="total_overview">Total Overview</h3>
                                    <div class="chart-actions">
                                        <div class="chart-year-filter">
                                            <select id="studentDistributionFilter" class="year-filter-select" onchange="updateStudentDistributionChart(this.value)">
                                                <option value="overview" data-translate="overview">Overview</option>
                                                <option value="1" data-translate="year_1">Year 1 Only</option>
                                                <option value="2" data-translate="year_2">Year 2 Only</option>
                                            </select>
                                        </div>
                                        <button class="chart-expand-btn" onclick="expandChart('studentDistributionChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="studentDistributionChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Weekly Schedule -->
                            <div class="chart-card chart-wide" data-aos="fade-up" data-aos-delay="200">
                                <div class="chart-header">
                                    <h3 class="chart-title" data-translate="weekly_schedule">Weekly Schedule</h3>
                                    <div class="chart-actions">
                                        <div class="schedule-year-filter">
                                            <select id="scheduleYearFilter" class="year-filter-select" onchange="updateScheduleYear(this.value)">
                                                <option value="1" selected data-translate="year_1">Year 1</option>
                                                <option value="2" data-translate="year_2">Year 2</option>
                                            </select>
                                        </div>
                                        <div class="class-tabs">
                                            <button class="class-tab active" onclick="showClassSchedule('A')" data-translate="class_a">Class A</button>
                                            <button class="class-tab" onclick="showClassSchedule('B')" data-translate="class_b">Class B</button>
                                            <button class="class-tab" onclick="showClassSchedule('C')" data-translate="class_c">Class C</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="schedule-container">
                    <?php
                    // Get actual subjects from database
                    $subjects_query = pg_query($conn, "SELECT DISTINCT subject_name FROM subjects ORDER BY subject_name");
                    $subjects = [];
                    
                    if ($subjects_query && pg_num_rows($subjects_query) > 0) {
                        while($subj = pg_fetch_assoc($subjects_query)) {
                            $subjects[] = $subj['subject_name'];
                        }
                    }
                    
                    // If no subjects in DB, use your actual curriculum subjects
                    if (empty($subjects)) {
                        $subjects = ['Advanced C++', 'Database', 'English', 'Human Resource Management', 'Web Development'];
                    }
                    
                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
                    
                    // Define realistic, varied schedules for each year and class
                    $class_schedules = [
                        '1' => [ // Year 1 - Foundational courses
                            'A' => [
                                'Sunday' => [
                                    ['08:30 - 10:00', 'Basic C++'],
                                    ['10:00 - 10:15', 'Break'],
                                    ['10:15 - 11:45', 'Basics of Principle Statistics'],
                                    ['11:45 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'English']
                                ],
                                'Monday' => [
                                    ['08:30 - 10:00', 'Computer Essentials'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'MIS'],
                                    ['11:50 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basic C++']
                                ],
                                'Tuesday' => [
                                    ['08:30 - 10:00', 'Basics of Principle Statistics'],
                                    ['10:00 - 10:10', 'Break'],
                                    ['10:10 - 11:40', 'English'],
                                    ['11:40 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Computer Essentials']
                                ],
                                'Wednesday' => [
                                    ['08:30 - 10:00', 'MIS'],
                                    ['10:00 - 10:15', 'Break'],
                                    ['10:15 - 11:45', 'Basic C++'],
                                    ['11:45 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basics of Principle Statistics']
                                ],
                                'Thursday' => [
                                    ['08:30 - 10:00', 'English'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'Computer Essentials']
                                ]
                            ],
                            'B' => [
                                'Sunday' => [
                                    ['08:30 - 10:00', 'Basics of Principle Statistics'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'Basic C++'],
                                    ['11:50 - 12:10', 'Break'],
                                    ['12:10 - 01:40', 'MIS']
                                ],
                                'Monday' => [
                                    ['08:30 - 10:00', 'English'],
                                    ['10:00 - 10:10', 'Break'],
                                    ['10:10 - 11:40', 'Computer Essentials'],
                                    ['11:40 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basics of Principle Statistics']
                                ],
                                'Tuesday' => [
                                    ['08:30 - 10:00', 'MIS'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'Basic C++'],
                                    ['11:50 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'English']
                                ],
                                'Wednesday' => [
                                    ['08:30 - 10:00', 'Computer Essentials'],
                                    ['10:00 - 10:15', 'Break'],
                                    ['10:15 - 11:45', 'Basics of Principle Statistics'],
                                    ['11:45 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'MIS']
                                ],
                                'Thursday' => [
                                    ['08:30 - 10:00', 'Basic C++'],
                                    ['10:00 - 10:10', 'Break'],
                                    ['10:10 - 11:40', 'English']
                                ]
                            ],
                            'C' => [
                                'Sunday' => [
                                    ['08:30 - 10:00', 'Computer Essentials'],
                                    ['10:00 - 10:15', 'Break'],
                                    ['10:15 - 11:45', 'MIS'],
                                    ['11:45 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basics of Principle Statistics']
                                ],
                                'Monday' => [
                                    ['08:30 - 10:00', 'Basic C++'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'English'],
                                    ['11:50 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Computer Essentials']
                                ],
                                'Tuesday' => [
                                    ['08:30 - 10:00', 'Basics of Principle Statistics'],
                                    ['10:00 - 10:10', 'Break'],
                                    ['10:10 - 11:40', 'MIS'],
                                    ['11:40 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basic C++']
                                ],
                                'Wednesday' => [
                                    ['08:30 - 10:00', 'English'],
                                    ['10:00 - 10:15', 'Break'],
                                    ['10:15 - 11:45', 'Computer Essentials'],
                                    ['11:45 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basics of Principle Statistics']
                                ],
                                'Thursday' => [
                                    ['08:30 - 10:00', 'MIS'],
                                    ['10:00 - 10:20', 'Break'],
                                    ['10:20 - 11:50', 'Basic C++']
                                ]
                            ]
                        ],
                        '2' => [ // Year 2 - Advanced courses
                        'A' => [
                            'Sunday' => [
                                ['08:30 - 10:00', 'Advanced C++'],
                                ['10:00 - 10:15', 'Break'],
                                ['10:15 - 11:45', 'Database'],
                                ['11:45 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Web Development']
                            ],
                            'Monday' => [
                                ['08:30 - 10:00', 'English'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'Human Resource Management'],
                                ['11:50 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Advanced C++']
                            ],
                            'Tuesday' => [
                                ['08:30 - 10:00', 'Database'],
                                ['10:00 - 10:10', 'Break'],
                                ['10:10 - 11:40', 'Web Development'],
                                ['11:40 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'English']
                            ],
                            'Wednesday' => [
                                ['08:30 - 10:00', 'Web Development'],
                                ['10:00 - 10:15', 'Break'],
                                ['10:15 - 11:45', 'Advanced C++'],
                                ['11:45 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Human Resource Management']
                            ],
                            'Thursday' => [
                                ['08:30 - 10:00', 'English'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'Database']
                            ]
                        ],
                        'B' => [
                            'Sunday' => [
                                ['08:30 - 10:00', 'Database'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'English'],
                                ['11:50 - 12:10', 'Break'],
                                ['12:10 - 01:40', 'Advanced C++']
                            ],
                            'Monday' => [
                                ['08:30 - 10:00', 'Web Development'],
                                ['10:00 - 10:15', 'Break'],
                                ['10:15 - 11:45', 'Human Resource Management'],
                                ['11:45 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Database']
                            ],
                            'Tuesday' => [
                                ['08:30 - 10:00', 'Advanced C++'],
                                ['10:00 - 10:10', 'Break'],
                                ['10:10 - 11:40', 'English'],
                                ['11:40 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Web Development']
                            ],
                            'Wednesday' => [
                                ['08:30 - 10:00', 'Human Resource Management'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'Database'],
                                ['11:50 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'English']
                            ],
                            'Thursday' => [
                                ['08:30 - 10:00', 'Web Development'],
                                ['10:00 - 10:15', 'Break'],
                                ['10:15 - 11:45', 'Advanced C++']
                            ]
                        ],
                        'C' => [
                            'Sunday' => [
                                ['08:30 - 10:00', 'English'],
                                ['10:00 - 10:10', 'Break'],
                                ['10:10 - 11:40', 'Web Development'],
                                ['11:40 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Human Resource Management']
                            ],
                            'Monday' => [
                                ['08:30 - 10:00', 'Advanced C++'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'Database'],
                                ['11:50 - 12:10', 'Break'],
                                ['12:10 - 01:40', 'English']
                            ],
                            'Tuesday' => [
                                ['08:30 - 10:00', 'Human Resource Management'],
                                ['10:00 - 10:15', 'Break'],
                                ['10:15 - 11:45', 'Web Development'],
                                ['11:45 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Advanced C++']
                            ],
                            'Wednesday' => [
                                ['08:30 - 10:00', 'Database'],
                                ['10:00 - 10:10', 'Break'],
                                ['10:10 - 11:40', 'English'],
                                ['11:40 - 12:00', 'Break'],
                                ['12:00 - 01:30', 'Web Development']
                            ],
                            'Thursday' => [
                                ['08:30 - 10:00', 'Human Resource Management'],
                                ['10:00 - 10:20', 'Break'],
                                ['10:20 - 11:50', 'Advanced C++']
                            ]
                            ]
                        ]
                    ];
                    
                    // Function to get subject CSS class
                    function getSubjectClass($subject) {
                        $subject_lower = strtolower($subject);
                        if (strpos($subject_lower, 'c++') !== false || strpos($subject_lower, 'basic c++') !== false || strpos($subject_lower, 'programming') !== false) return 'subject-computer';
                        if (strpos($subject_lower, 'database') !== false) return 'subject-science';
                        if (strpos($subject_lower, 'english') !== false) return 'subject-english';
                        if (strpos($subject_lower, 'human') !== false || strpos($subject_lower, 'management') !== false) return 'subject-history';
                        if (strpos($subject_lower, 'web') !== false || strpos($subject_lower, 'development') !== false) return 'subject-math';
                        if (strpos($subject_lower, 'statistics') !== false || strpos($subject_lower, 'principle') !== false || strpos($subject_lower, 'mathematics') !== false) return 'subject-math';
                        if (strpos($subject_lower, 'computer') !== false || strpos($subject_lower, 'essentials') !== false || strpos($subject_lower, 'intro') !== false) return 'subject-computer';
                        if (strpos($subject_lower, 'mis') !== false) return 'subject-history';
                        return 'subject-cell';
                    }
                    
                    // Get current year (default to 1 for initial load)
                    $current_year = '1';
                    
                    // Make schedule data available to JavaScript
                    echo "<script>
                        window.scheduleData = " . json_encode($class_schedules) . ";
                        window.currentScheduleYear = '$current_year';
                        window.days = " . json_encode($days) . ";
                    </script>";
                    ?>
                    
                    <!-- Class A Schedule -->
                    <div id="schedule-A" class="schedule-table active">
                        <div class="schedule-header">
                            <h4 data-translate="class_a_schedule">Class A - Weekly Academic Schedule</h4>
                        </div>
                        <table class="formal-schedule">
                            <thead>
                                <tr>
                                    <th data-translate="day">Day</th>
                                    <th data-translate="schedule">Schedule</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): ?>
                                <tr>
                                    <td class="time-period" data-translate="<?= strtolower($day) ?>"><?= $day ?></td>
                                    <td class="day-schedule">
                                        <?php foreach ($class_schedules[$current_year]['A'][$day] as $slot): ?>
                                            <?php if ($slot[1] === 'Break'): ?>
                                                <div class="schedule-slot break-cell">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject" data-translate="break">Break</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="schedule-slot <?= getSubjectClass($slot[1]) ?>">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject"><?= htmlspecialchars($slot[1]) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Class B Schedule -->
                    <div id="schedule-B" class="schedule-table">
                        <div class="schedule-header">
                            <h4 data-translate="class_b_schedule">Class B - Weekly Academic Schedule</h4>
                        </div>
                        <table class="formal-schedule">
                            <thead>
                                <tr>
                                    <th data-translate="day">Day</th>
                                    <th data-translate="schedule">Schedule</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): ?>
                                <tr>
                                    <td class="time-period" data-translate="<?= strtolower($day) ?>"><?= $day ?></td>
                                    <td class="day-schedule">
                                        <?php foreach ($class_schedules[$current_year]['B'][$day] as $slot): ?>
                                            <?php if ($slot[1] === 'Break'): ?>
                                                <div class="schedule-slot break-cell">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject" data-translate="break">Break</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="schedule-slot <?= getSubjectClass($slot[1]) ?>">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject"><?= htmlspecialchars($slot[1]) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Class C Schedule -->
                    <div id="schedule-C" class="schedule-table">
                        <div class="schedule-header">
                            <h4 data-translate="class_c_schedule">Class C - Weekly Academic Schedule</h4>
                        </div>
                        <table class="formal-schedule">
                            <thead>
                                <tr>
                                    <th data-translate="day">Day</th>
                                    <th data-translate="schedule">Schedule</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): ?>
                                <tr>
                                    <td class="time-period" data-translate="<?= strtolower($day) ?>"><?= $day ?></td>
                                    <td class="day-schedule">
                                        <?php foreach ($class_schedules[$current_year]['C'][$day] as $slot): ?>
                                            <?php if ($slot[1] === 'Break'): ?>
                                                <div class="schedule-slot break-cell">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject" data-translate="break">Break</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="schedule-slot <?= getSubjectClass($slot[1]) ?>">
                                                    <span class="time"><?= $slot[0] ?></span>
                                                    <span class="subject"><?= htmlspecialchars($slot[1]) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                        <!-- Search and Filters for Reports -->
                        <div class="filter-panel" data-aos="fade-up">
                            <h3 class="filter-title" data-translate="search_filter_reports"> Search & Filter Reports</h3>
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="search_student">Search Student</label>
                                    <input type="text" id="reportsSearchStudent" class="premium-select" placeholder="Search by student name..." data-translate-placeholder="search_by_student_name" onkeyup="filterReportsTable()">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year</label>
                                    <select id="reportsFilterYear" class="premium-select" onchange="updateReportsSubjectFilter(); filterReportsTable()">
                                        <option value="" data-translate="all_years">All Years</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_class">Filter by Class</label>
                                    <select id="reportsFilterClass" class="premium-select" onchange="filterReportsTable()">
                                        <option value="" data-translate="all_classes">All Classes</option>
                                        <?php
                                        $classes = pg_query($conn, "SELECT DISTINCT class_level FROM students ORDER BY class_level");
                                        if ($classes && pg_num_rows($classes) > 0) {
                                            while($class = pg_fetch_assoc($classes)):
                                        ?>
                                        <option value="<?= htmlspecialchars($class['class_level']) ?>"><?= htmlspecialchars($class['class_level']) ?></option>
                                        <?php 
                                            endwhile; 
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_subject">Filter by Subject</label>
                                    <select id="reportsFilterSubject" class="premium-select" onchange="filterReportsTable()">
                                        <option value="" data-translate="all_subjects">All Subjects</option>
                                        <!-- Options will be populated dynamically -->
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="sort_by_final_grade">Sort by Final Grade</label>
                                    <select id="sortReportsByGrade" class="premium-select" onchange="filterReportsTable()">
                                        <option value="" data-translate="sort_default">Default</option>
                                        <option value="asc" data-translate="sort_grade_low_high">Grade: Low to High</option>
                                        <option value="desc" data-translate="sort_grade_high_low">Grade: High to Low</option>
                                    </select>
                                </div>
                                <button class="clear-filters-btn" onclick="clearReportsFilters()" data-translate="clear_filters">
                                    🔄 Clear Filters
                                </button>
                            </div>
                        </div>

                        <!-- Data Table Section -->
                        <div class="data-table-section" data-aos="fade-up">
                            <div class="section-header">
                                <h3 class="section-title"><i class="fas fa-file-alt"></i> Detailed Reports</h3>
                                <div class="export-actions">
                                    <button class="export-btn" onclick="collapseAllReports()" data-translate="collapse_all"><i class="fas fa-folder-minus"></i> Collapse All</button>
                                    <button class="export-btn" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> Export CSV</button>
                                    <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                                </div>
                            </div>
                            <div class="table-container">
                                <table id="reportsTable">
                                    <thead>
                                        <tr>
                                            <th data-translate="student_name">Student Name</th>
                                            <th style="text-align: center;" data-translate="year">Year</th>
                                            <th style="text-align: center;" data-translate="class">Class</th>
                                            <th style="text-align: center;" data-translate="total_subjects">Subjects</th>
                                            <th style="text-align: center;" data-translate="final_grade">Final Grade</th>
                                            <th style="text-align: center;" data-translate="actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportsTableBody">
                                        <?php
                                        // Get all students with marks, grouped by student (only active students)
                                        $students_query = "
                                            SELECT DISTINCT
                                                s.id as student_id,
                                                s.name as student_name,
                                                s.year as student_year,
                                                s.class_level
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
                                            WHERE s.status = 'active'
                                            ORDER BY s.name
                                        ";
                                        $students_result = pg_query($conn, $students_query);
                                        
                                        if ($students_result && pg_num_rows($students_result) > 0):
                                            while($student = pg_fetch_assoc($students_result)):
                                                $student_id = $student['student_id'];
                                                
                                                // Get marks for this student
                                                $marks_query = pg_prepare($conn, "marks_for_student_" . $student_id, "
                                                    SELECT 
                                                        m.id as mark_id,
                                                        sub.subject_name,
                                                        sub.year as subject_year,
                                                        sub.credits,
                                                        m.final_exam,
                                                        m.midterm_exam,
                                                        m.quizzes,
                                                        m.daily_activities,
                                                        m.mark as total_mark,
                                                        m.final_grade,
                                                        CASE 
                                                            WHEN m.mark >= 90 THEN 'A+'
                                                            WHEN m.mark >= 80 THEN 'A'
                                                            WHEN m.mark >= 70 THEN 'B'
                                                            WHEN m.mark >= 50 THEN 'C'
                                                            ELSE 'F'
                                                        END as grade
                                                    FROM marks m
                                                    JOIN subjects sub ON m.subject_id = sub.id
                                                    WHERE m.student_id = $1
                                                    ORDER BY sub.year, sub.subject_name
                                                ");
                                                $marks_result = pg_execute($conn, "marks_for_student_" . $student_id, array($student_id));
                                                $student_marks = pg_fetch_all($marks_result);
                                                $total_subjects = count($student_marks);
                                                
                                                // Group marks by year and calculate totals
                                                $marks_by_year = [];
                                                $year_totals = [];
                                                if ($student_marks) {
                                                    foreach ($student_marks as $mark) {
                                                        $year = $mark['subject_year'];
                                                        if (!isset($marks_by_year[$year])) {
                                                            $marks_by_year[$year] = [];
                                                            $year_totals[$year] = 0;
                                                        }
                                                        $marks_by_year[$year][] = $mark;
                                                        $year_totals[$year] += floatval($mark['final_grade']);
                                                    }
                                                }
                                                
                                                // Calculate student's year-specific final grade
                                                $graduation_result = calculateGraduationGrade($conn, $student_id);
                                                $year1_grade = 0;
                                                $year2_grade = 0;
                                                $total_grade = 0;
                                                if ($graduation_result['success']) {
                                                    $year1_grade = $graduation_result['year1_grade'];
                                                    $year2_grade = $graduation_result['year2_grade'];
                                                    $total_grade = $year1_grade + $year2_grade;
                                                }
                                        ?>
                                        <!-- Student Main Row (Clickable) -->
                                        <tr class="student-main-row" onclick="toggleStudentDetails(<?= $student_id ?>)" data-student-id="<?= $student_id ?>" data-year="<?= $student['student_year'] ?>">
                                            <td>
                                                <div class="student-name-cell">
                                                    <span class="expand-icon">▶</span>
                                                    <strong><?= htmlspecialchars($student['student_name']) ?></strong>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                             background: <?= $student['student_year'] == 1 ? '#dbeafe' : '#dcfce7' ?>; 
                                                             color: <?= $student['student_year'] == 1 ? '#1e40af' : '#166534' ?>;">
                                                    <span data-translate="year">Year</span> <?= $student['student_year'] ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                             background: #f3f4f6; color: #374151;">
                                                    <?= htmlspecialchars($student['class_level']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="total-subjects-badge">
                                                    <span><?= $total_subjects ?></span>
                                                    <span data-translate="subjects">subjects</span>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="final-grade-display"><?= number_format($total_grade, 2) ?></span>
                                            </td>
                                            <td style="text-align: center;" onclick="event.stopPropagation();">
                                                <button class="manage-marks-btn" onclick="toggleStudentDetails(<?= $student_id ?>)" data-translate="view_details">
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Subject Details Row (Expandable) -->
                                        <tr class="subject-details-row" id="details-<?= $student_id ?>">
                                            <td colspan="6">
                                                <div class="subject-details-container">
                                                    <div class="subject-title"><span data-translate="subject_marks">Subject Marks</span></div>
                                                    <table class="subject-marks-table">
                                                        <thead>
                                                            <tr>
                                                                <th data-translate="subject">Subject</th>
                                                                <th data-translate="final_exam">Final (60)</th>
                                                                <th data-translate="midterm_exam">Midterm (20)</th>
                                                                <th data-translate="quiz">Quiz (10)</th>
                                                                <th data-translate="daily_activities">Daily (10)</th>
                                                                <th data-translate="total">Total</th>
                                                                <th data-translate="grade">Grade</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                            if ($student_marks && count($student_marks) > 0):
                                                                ksort($marks_by_year); // Sort by year
                                                                foreach ($marks_by_year as $year => $year_marks):
                                                            ?>
                                                                <?php foreach($year_marks as $mark): 
                                                                    $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $mark['grade']));
                                                                ?>
                                                                <tr>
                                                                    <td style="text-align: left; font-weight: 500;">
                                                                        <?= htmlspecialchars($mark['subject_name']) ?>
                                                                    </td>
                                                                    <td><?= intval($mark['final_exam']) ?></td>
                                                                    <td><?= intval($mark['midterm_exam']) ?></td>
                                                                    <td><?= intval($mark['quizzes']) ?></td>
                                                                    <td><?= intval($mark['daily_activities']) ?></td>
                                                                    <td><strong><?= intval($mark['total_mark']) ?></strong></td>
                                                                    <td>
                                                                        <span class="grade-badge <?= $grade_class ?>"><?= $mark['grade'] ?></span>
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                                <!-- Year Total Row -->
                                                                <tr style="background: linear-gradient(135deg, <?= $year == 1 ? '#dbeafe' : '#f3e8ff' ?> 0%, <?= $year == 1 ? '#bfdbfe' : '#e9d5ff' ?> 100%); font-weight: 600; border-top: 2px solid <?= $year == 1 ? '#3b82f6' : '#a855f7' ?>;">
                                                                    <td colspan="6" style="text-align: left; padding: 12px; color: <?= $year == 1 ? '#1e40af' : '#6b21a8' ?>; font-size: 1rem;">
                                                                        <span data-translate="year">Year</span> <?= $year ?> <span data-translate="total_credits">Total Credits</span>
                                                                    </td>
                                                                    <td style="text-align: center; color: <?= $year == 1 ? '#1e40af' : '#6b21a8' ?>; font-size: 1.1rem;">
                                                                        <strong><?= number_format($year_totals[$year], 2) ?></strong>
                                                                    </td>
                                                                </tr>
                                                            <?php 
                                                                endforeach;
                                                            else:
                                                            ?>
                                                                <tr>
                                                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-light);">
                                                                        No marks recorded yet
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php 
                                            endwhile;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                                No reports available. Add some student marks to see data here.
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'students'): ?>
            <!-- STUDENTS PAGE -->
            <div class="content-wrapper page-students">
                <div class="dashboard-header">
                    <h1 data-translate="students_title">Students Management</h1>
                    <p class="dashboard-subtitle" data-translate="manage_student_info">Manage student information and enrollment</p>
                </div>
                <div class="reports-main-content">
                    <!-- Add Student Form -->
                    <div class="filter-panel" data-aos="fade-up">
                        <h3 class="filter-title" data-translate="add_new_student">➕ Add New Student</h3>
                        <form method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'students');">
                            <input type="hidden" name="action" value="add_student">
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="full_name">Full Name *</label>
                                    <input type="text" name="student_name" class="premium-select" required placeholder="Enter student's full name" data-translate-placeholder="enter_full_name">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="age">Age *</label>
                                    <input type="number" name="age" class="premium-select" min="15" max="30" required placeholder="Student age" data-translate-placeholder="student_age">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="gender">Gender *</label>
                                    <select name="gender" class="premium-select" required>
                                        <option value="" data-translate="select_gender">Select Gender</option>
                                        <option value="Male" data-translate="male">Male</option>
                                        <option value="Female" data-translate="female">Female</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year *</label>
                                    <select name="year" id="studentYearSelect" class="premium-select" required onchange="filterSubjectsByStudentYear(); updateClassOptions();">
                                        <option value="" data-translate="select_year">Select Year</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="class">Class *</label>
                                    <select name="class_level" id="studentClassSelect" class="premium-select" required>
                                        <option value="" data-translate="select_class">Select Class</option>
                                        <optgroup label="Year 1" data-translate="year_1">
                                            <option value="1A">Year 1 - Class A</option>
                                            <option value="1B">Year 1 - Class B</option>
                                            <option value="1C">Year 1 - Class C</option>
                                        </optgroup>
                                        <optgroup label="Year 2" data-translate="year_2">
                                            <option value="2A">Year 2 - Class A</option>
                                            <option value="2B">Year 2 - Class B</option>
                                            <option value="2C">Year 2 - Class C</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="email">Email</label>
                                    <input type="email" name="email" class="premium-select" placeholder="Enter email address..." data-translate-placeholder="email_placeholder_student">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="phone_number">Phone Number</label>
                                    <input type="tel" name="phone" class="premium-select" placeholder="Enter phone number" data-translate-placeholder="enter_phone_number">
                                </div>
                                <div class="filter-section" style="grid-column: span 2;">
                                    <label class="filter-label" data-translate="enroll_in_subjects">Enroll in Subjects</label>
                                    <div id="subjectEnrollmentContainer" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; max-height: 150px; overflow-y: auto;">
                                        <!-- Select All Checkbox -->
                                        <div id="selectAllSubjectsContainer" style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 2px solid #e5e7eb; display: none;">
                                            <div style="display: flex; align-items: center; background: #f0f9ff; padding: 8px 12px; border-radius: 6px;">
                                                <input type="checkbox" id="selectAllSubjects" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;" onchange="toggleAllSubjects(this)">
                                                <label for="selectAllSubjects" style="margin: 0; font-weight: 600; color: #0284c7; cursor: pointer; user-select: none;">
                                                    <span data-translate="select_all_subjects">Select All Subjects</span>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="no-subjects-message" style="color: #666; text-align: center; padding: 20px; font-style: italic;" data-translate="please_select_year_first">Please select an academic year first to see available subjects.</div>
                                        <?php
                                        $subjects_query = "SELECT id, subject_name, description, year FROM subjects ORDER BY year, subject_name";
                                        $subjects_result = pg_query($conn, $subjects_query);
                                        
                                        if ($subjects_result && pg_num_rows($subjects_result) > 0):
                                            while($subject = pg_fetch_assoc($subjects_result)):
                                        ?>
                                        <div class="subject-item" data-subject-year="<?= $subject['year'] ?? '' ?>" style="margin-bottom: 10px; display: none; align-items: center;">
                                            <input type="checkbox" name="subjects[]" value="<?= $subject['id'] ?>" 
                                                   id="subject_<?= $subject['id'] ?>" style="margin-right: 10px;">
                                            <label for="subject_<?= $subject['id'] ?>" style="margin: 0; font-weight: 500; color: #333;">
                                                <?= htmlspecialchars($subject['subject_name']) ?>
                                                <?php if (!empty($subject['description'])): ?>
                                                    <small style="color: #666; display: block; font-weight: normal;">
                                                        <?= htmlspecialchars($subject['description']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </label>
                                            <div style="margin-left: auto; font-size: 0.8rem; color: #666; padding: 2px 8px; background: #f0f9ff; border-radius: 4px;">
                                                Year <?= $subject['year'] ?? 'N/A' ?>
                                            </div>
                                        </div>
                                        <?php 
                                            endwhile;
                                        else:
                                        ?>
                                        <p style="color: #666; margin: 0;" data-translate="no_subjects_available">No subjects available. Please add subjects first.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="grid-column: span 2; display: flex; gap: 10px; margin-top: 10px;">
                                    <button type="submit" class="apply-filters-btn" style="flex: 1;" data-translate="add_student">
                                        ➕ Add Student
                                    </button>
                                    <button type="reset" class="clear-filters-btn" style="flex: 1;" data-translate="reset_form">
                                        <i class="fas fa-undo"></i> Reset Form
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Students List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="students_list"><i class="fas fa-list"></i> Students List</h3>
                            <div class="export-actions">
                                <button class="export-btn" onclick="exportData('csv')" data-translate="export_csv">📊 Export CSV</button>
                                <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                            </div>
                        </div>
                        
                        <!-- Search and Filter Section -->
                        <div class="filter-panel" style="margin-bottom: 1rem;" data-aos="fade-up">
                            <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1rem;" data-translate="search_filter_students"> Search & Filter Students</h4>
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="search">Search</label>
                                    <input type="text" id="searchStudents" class="premium-select" placeholder="Search by name, email, or phone..." data-translate-placeholder="search_by_name" onkeyup="filterStudents()">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_class">Filter by Class</label>
                                    <select id="filterStudentClass" class="premium-select" onchange="filterStudents()">
                                        <option value="" data-translate="all_classes">All Classes</option>
                                        <?php
                                        $filter_classes = pg_query($conn, "SELECT DISTINCT class_level FROM students WHERE class_level IS NOT NULL ORDER BY class_level");
                                        while($class = pg_fetch_assoc($filter_classes)):
                                        ?>
                                        <option value="Class <?= htmlspecialchars($class['class_level']) ?>">Class <?= htmlspecialchars($class['class_level']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_gender">Filter by Gender</label>
                                    <select id="filterStudentGender" class="premium-select" onchange="filterStudents()">
                                        <option value="" data-translate="all_genders">All Genders</option>
                                        <option value="Male" data-translate="male">Male</option>
                                        <option value="Female" data-translate="female">Female</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_age_range">Filter by Age Range</label>
                                    <select id="filterStudentAge" class="premium-select" onchange="filterStudents()">
                                        <option value="" data-translate="all_ages">All Ages</option>
                                        <option value="15-17">15-17 years</option>
                                        <option value="18-20">18-20 years</option>
                                        <option value="21-25">21-25 years</option>
                                        <option value="26+">26+ years</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_enrollment">Filter by Enrollment</label>
                                    <select id="filterStudentEnrollment" class="premium-select" onchange="filterStudents()">
                                        <option value="" data-translate="all_students">All Students</option>
                                        <option value="enrolled">Has Subjects</option>
                                        <option value="not-enrolled">No Subjects</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_year">Filter by Year</label>
                                    <select id="filterStudentYear" class="premium-select" onchange="filterStudents()">
                                        <option value="" data-translate="all_years">All Years</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <button class="clear-filters-btn" onclick="clearStudentsFilters()" style="margin-top: 1.5rem;" data-translate="clear_filters">
                                        🔄 Clear Filters
                                    </button>
                                </div>
                                <div class="filter-section">
                                    <button class="apply-filters-btn" onclick="showBulkActionModal()" style="margin-top: 1.5rem; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);" data-translate="bulk_promote_graduate">
                                        <i class="fas fa-users-cog"></i> Bulk Promote/Graduate
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-container">
                            <table id="studentsTable">
                                <thead>
                                    <tr>
                                        <th data-translate="id">ID</th>
                                        <th data-translate="name">Name</th>
                                        <th data-translate="age">Age</th>
                                        <th data-translate="gender">Gender</th>
                                        <th data-translate="class">Class</th>
                                        <th data-translate="academic_year">Academic Year</th>
                                        <th data-translate="phone">Phone</th>
                                        <th data-translate="subjects">Subjects</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $students_query = "
                                        SELECT 
                                            s.*,
                                            STRING_AGG(sub.subject_name, ', ') as enrolled_subjects
                                        FROM students s
                                        LEFT JOIN marks m ON s.id = m.student_id
                                        LEFT JOIN subjects sub ON m.subject_id = sub.id AND sub.year = s.year
                                        WHERE s.status = 'active'
                                        GROUP BY s.id, s.name, s.age, s.gender, s.class_level, s.phone, s.year, s.status
                                        ORDER BY s.id
                                    ";
                                    $students_result = pg_query($conn, $students_query);
                                    
                                    if ($students_result && pg_num_rows($students_result) > 0):
                                        while($student = pg_fetch_assoc($students_result)):
                                            // Check eligibility for promotion or graduation
                                            $eligibility = null;
                                            if ($student['year'] == 1) {
                                                $eligibility = checkPromotionEligibility($conn, $student['id']);
                                            } elseif ($student['year'] == 2) {
                                                $eligibility = checkGraduationEligibility($conn, $student['id']);
                                            }
                                            $is_eligible = $eligibility && $eligibility['eligible'];
                                    ?>
                                    <tr>
                                        <td><?= $student['id'] ?></td>
                                        <td><?= htmlspecialchars($student['name']) ?></td>
                                        <td><?= $student['age'] ?? 'N/A' ?></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: <?= $student['gender'] == 'Male' ? '#e3f2fd' : '#fce4ec' ?>; 
                                                         color: <?= $student['gender'] == 'Male' ? '#1565c0' : '#c2185b' ?>;">
                                                <span data-translate="<?= strtolower($student['gender'] ?? 'n_a') ?>"><?= $student['gender'] ?? 'N/A' ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: var(--primary-light); color: var(--primary-color);">
                                                Class <?= htmlspecialchars($student['class_level']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: <?= $student['year'] == 1 ? '#E0F2FE' : '#FEF3C7' ?>; 
                                                         color: <?= $student['year'] == 1 ? '#0284C7' : '#D97706' ?>;">
                                                <span data-translate="<?= $student['year'] == 1 ? 'year_1' : 'year_2' ?>">Year <?= $student['year'] ?? 'N/A' ?></span>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($student['phone'] ?? '') ?></td>
                                        <td>
                                            <?php if (!empty($student['enrolled_subjects'])): ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                    <?php 
                                                    $subjects = explode(', ', $student['enrolled_subjects']);
                                                    foreach ($subjects as $subject): 
                                                    ?>
                                                        <span style="padding: 3px 8px; background: #f0f9ff; color: #0369a1; 
                                                                     border-radius: 4px; font-size: 0.75rem; white-space: nowrap;
                                                                     border: 1px solid #bae6fd;">
                                                            <?= htmlspecialchars(trim($subject)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #999; font-style: italic;">No subjects</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="editStudent(<?= $student['id'] ?>)" data-translate="edit">Edit</button>
                                            <button class="export-btn" onclick="handleStudentAction('delete_student', <?= $student['id'] ?>)" 
                                                    style="background: var(--danger-color); color: white;" data-translate="delete">Delete</button>
                                            
                                            <?php if ($student['year'] == 1): ?>
                                                <button class="export-btn" onclick="handleStudentAction('promote_student', <?= $student['id'] ?>)" 
                                                        style="background: <?= $is_eligible ? '#10B981' : '#F59E0B' ?>; color: white;" 
                                                        title="<?= $is_eligible ? 'Eligible for promotion' : 'Not eligible for promotion' ?>" 
                                                        data-translate="promote">Promote</button>
                                            <?php elseif ($student['year'] == 2): ?>
                                                <button class="export-btn" onclick="graduateStudent(<?= $student['id'] ?>)" 
                                                        style="background: <?= $is_eligible ? '#10B981' : '#F59E0B' ?>; color: white;" 
                                                        title="<?= $is_eligible ? 'Eligible for graduation' : 'Not eligible for graduation' ?>" 
                                                        data-translate="graduate">Graduate</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                            No students found. Add some students to get started.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'teacher_dashboard'): ?>
            <!-- TEACHER ACCESS MANAGEMENT PAGE -->
            <div class="content-wrapper page-access">
                <div class="dashboard-header">
                    <h1 data-translate="teacher_access_title">Teacher Access Management</h1>
                    <p class="dashboard-subtitle" data-translate="teacher_access_subtitle">Manage teacher login credentials and system access</p>
                </div>

                <div class="reports-main-content">
                    <!-- Statistics Cards -->
                    <div class="kpi-grid">
                        <?php
                        // FORCE FRESH QUERY - Clear any potential cache
                        // Verify database connection
                        if (!$conn) {
                            include 'db.php';
                        }
                        
                        // Get real teacher statistics with error checking
                        $total_teachers = 0;
                        $total_teachers_result = pg_query($conn, "SELECT COUNT(*)::integer as count FROM teachers");
                        if ($total_teachers_result) {
                            $row = pg_fetch_assoc($total_teachers_result);
                            $total_teachers = (int)$row['count'];
                        } else {
                            error_log("Failed to query teachers count: " . pg_last_error($conn));
                        }
                        
                        // FORCE DEBUG: Check if we're getting the right value
                        error_log("DEBUG: Total teachers count = " . $total_teachers);
                        
                        // Double check with a fresh query
                        $verify_result = pg_query($conn, "SELECT COUNT(*) FROM teachers");
                        $verify_count = pg_fetch_result($verify_result, 0, 0);
                        error_log("DEBUG: Verify count = " . $verify_count);
                        
                        // Use the verified count
                        $total_teachers = (int)$verify_count;
                        
                        // Count teachers with login credentials
                        $active_logins = 0;
                        $active_login_result = pg_query($conn, "SELECT COUNT(*)::integer as count FROM teachers WHERE username IS NOT NULL AND password IS NOT NULL AND username != ''");
                        if ($active_login_result) {
                            $row = pg_fetch_assoc($active_login_result);
                            $active_logins = (int)$row['count'];
                        }
                        
                        // Count teachers without login credentials
                        $no_logins = 0;
                        $no_login_result = pg_query($conn, "SELECT COUNT(*)::integer as count FROM teachers WHERE username IS NULL OR password IS NULL OR username = ''");
                        if ($no_login_result) {
                            $row = pg_fetch_assoc($no_login_result);
                            $no_logins = (int)$row['count'];
                        }
                        
                        // Count subjects without teachers assigned
                        $subjects_no_teacher = 0;
                        $subjects_no_teacher_result = pg_query($conn, "
                            SELECT COUNT(*) as count 
                            FROM subjects s 
                            WHERE NOT EXISTS (
                                SELECT 1 FROM teacher_subjects ts WHERE ts.subject_id = s.id
                            )
                        ");
                        if ($subjects_no_teacher_result) {
                            $row = pg_fetch_assoc($subjects_no_teacher_result);
                            $subjects_no_teacher = (int)$row['count'];
                        }
                        ?>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $total_teachers ?></div>
                                <div class="kpi-label" data-translate="total_teachers">Total Teachers</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $active_logins ?></div>
                                <div class="kpi-label" data-translate="active_logins">Active Logins</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $no_logins ?></div>
                                <div class="kpi-label" data-translate="no_access">No Access</div>
                            </div>
                        </div>
                        
                        <div class="kpi-card">
                            <div class="blob"></div>
                            <div class="bg"></div>
                            <div class="kpi-content">
                                <div class="kpi-value"><?= $subjects_no_teacher ?></div>
                                <div class="kpi-label" data-translate="subjects_no_teacher">Subjects Without Teacher</div>
                            </div>
                        </div>
                    </div>

                    <!-- Force KPI Update Script -->
                    <script>
                    (function() {
                        // Force update KPI values from PHP (bypass any cache)
                        const kpiValues = {
                            teachers: <?= $total_teachers ?>,
                            activeLogins: <?= $active_logins ?>,
                            noLogins: <?= $no_logins ?>,
                            subjectsNoTeacher: <?= $subjects_no_teacher ?>
                        };
                        
                        console.log('Teacher Dashboard KPI Values from DB:', kpiValues);
                        
                        // Wait for DOM to be fully loaded
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', updateKPIs);
                        } else {
                            updateKPIs();
                        }
                        
                        function updateKPIs() {
                            const kpiCards = document.querySelectorAll('.kpi-grid .kpi-card');
                            if (kpiCards.length >= 4) {
                                // Update each KPI card
                                kpiCards[0].querySelector('.kpi-value').textContent = kpiValues.teachers;
                                kpiCards[1].querySelector('.kpi-value').textContent = kpiValues.activeLogins;
                                kpiCards[2].querySelector('.kpi-value').textContent = kpiValues.noLogins;
                                kpiCards[3].querySelector('.kpi-value').textContent = kpiValues.subjectsNoTeacher;
                                
                                console.log('[SUCCESS] KPI values force updated!');
                                console.log('Teachers KPI now shows:', kpiCards[0].querySelector('.kpi-value').textContent);
                            }
                        }
                        
                        // Also update after a slight delay to override any other scripts
                        setTimeout(updateKPIs, 100);
                        setTimeout(updateKPIs, 500);
                    })();
                    </script>

                    <!-- Charts Section -->
                    <div class="charts-grid">
                        <div class="chart-card">
                            <h3 class="chart-title" data-translate="chart_teachers_by_spec">Teachers by Specialization</h3>
                            <div class="chart-container">
                                <canvas id="teacherSpecializationChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <h3 class="chart-title" data-translate="chart_teachers_by_degree">Teachers by Degree</h3>
                            <div class="chart-container">
                                <canvas id="teacherDegreeChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <h3 class="chart-title" data-translate="chart_teachers_by_subject">Teachers by Subject</h3>
                            <div class="chart-container">
                                <canvas id="teachersBySubjectChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Teacher Credential Management Table -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="teacher_credential_mgmt">Teacher Credential Management</h3>
                            <div class="export-actions">
                                <button class="export-btn" onclick="printTable()" data-translate="print_btn">Print</button>
                            </div>
                        </div>

                        <!-- Filter Bar -->
                        <div class="filter-sections" style="margin-bottom: 1rem;">
                            <div class="filter-section">
                                <label class="filter-label" data-translate="search_teacher_label">Search Teacher</label>
                                <input type="text" id="searchTeacherAccess" class="premium-select" data-translate-placeholder="search_by_name" placeholder="Search by name..." onkeyup="filterTeacherAccess()">
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_spec">Filter by Specialization</label>
                                <select id="filterSpecialization" class="premium-select" onchange="filterTeacherAccess()">
                                    <option value="" data-translate="all_specializations">All Specializations</option>
                                    <?php
                                    $spec_query = "SELECT DISTINCT specialization FROM teachers WHERE specialization IS NOT NULL ORDER BY specialization";
                                    $spec_result = pg_query($conn, $spec_query);
                                    while ($spec = pg_fetch_assoc($spec_result)) {
                                        echo "<option value='" . htmlspecialchars($spec['specialization']) . "'>" . htmlspecialchars($spec['specialization']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_status">Filter by Status</label>
                                <select id="filterLoginStatus" class="premium-select" onchange="filterTeacherAccess()">
                                    <option value="" data-translate="all_statuses">All</option>
                                    <option value="active" data-translate="has_login">Has Login</option>
                                    <option value="no_login" data-translate="no_login_option">No Login</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_degree">Filter by Degree</label>
                                <select id="filterDegree" class="premium-select" onchange="filterTeacherAccess()">
                                    <option value="" data-translate="all_degrees">All Degrees</option>
                                    <?php
                                    $degree_query = "SELECT DISTINCT degree FROM teachers WHERE degree IS NOT NULL ORDER BY degree";
                                    $degree_result = pg_query($conn, $degree_query);
                                    while ($deg = pg_fetch_assoc($degree_result)) {
                                        echo "<option value='" . htmlspecialchars($deg['degree']) . "'>" . htmlspecialchars($deg['degree']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="table-container">
                            <table id="teacherAccessTable">
                                <thead>
                                    <tr>
                                        <th data-translate="th_id">ID</th>
                                        <th data-translate="th_teacher_name">Teacher Name</th>
                                        <th data-translate="th_contact_email">Contact Email</th>
                                        <th data-translate="th_specialization">Specialization</th>
                                        <th data-translate="th_degree">Degree</th>
                                        <th data-translate="th_login_username">Login Username</th>
                                        <th data-translate="th_status">Status</th>
                                        <th data-translate="th_subjects">Subjects</th>
                                        <th data-translate="th_actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $teachers_query = "SELECT t.*, 
                                                             COUNT(DISTINCT ts.subject_id) as subject_count
                                                      FROM teachers t
                                                      LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id
                                                      GROUP BY t.id
                                                      ORDER BY t.name";
                                    $teachers_result = pg_query($conn, $teachers_query);
                                    
                                    if ($teachers_result && pg_num_rows($teachers_result) > 0):
                                        while($teacher = pg_fetch_assoc($teachers_result)):
                                            $has_login = !empty($teacher['username']) && !empty($teacher['password']);
                                    ?>
                                    <tr class="teacher-access-row" 
                                        data-name="<?= strtolower(htmlspecialchars($teacher['name'])) ?>"
                                        data-specialization="<?= htmlspecialchars($teacher['specialization'] ?? '') ?>"
                                        data-degree="<?= htmlspecialchars($teacher['degree'] ?? '') ?>"
                                        data-status="<?= $has_login ? 'active' : 'no_login' ?>">
                                        <td style="text-align: center;"><?= $teacher['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($teacher['name']) ?></strong></td>
                                        <td><?= htmlspecialchars($teacher['email']) ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-secondary">
                                                <?= htmlspecialchars($teacher['specialization'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-info">
                                                <?= htmlspecialchars($teacher['degree'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($has_login): ?>
                                                <span class="badge badge-primary">
                                                    <?= htmlspecialchars($teacher['username']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;" data-translate="not_set">Not Set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($has_login): ?>
                                                <span class="badge badge-success" data-translate="status_active">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" data-translate="no_access">No Access</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-warning">
                                                <?= $teacher['subject_count'] ?> 
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <button class="action-btn edit" 
                                                    onclick="openCredentialModal(<?= $teacher['id'] ?>, '<?= htmlspecialchars($teacher['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($teacher['username'] ?? '', ENT_QUOTES) ?>', <?= $has_login ? 'true' : 'false' ?>, '<?= htmlspecialchars(!empty($teacher['password_plain']) ? base64_decode($teacher['password_plain']) : '', ENT_QUOTES) ?>')">
                                                <?= $has_login ? '<span data-translate="update_access">Update Access</span>' : '<span data-translate="create_access">Create Access</span>' ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 2rem; color: #64748b;" data-translate="no_teachers_found">
                                            No teachers found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Credential Modal -->
            <div id="credentialModal" onclick="if(event.target.id === 'credentialModal') closeCredentialModal()" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; padding: 1rem;">
                <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; max-width: 550px; width: 100%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                    <!-- Header -->
                    <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; display: flex; align-items: center; justify-content: space-between; border-radius: 12px 12px 0 0;">
                        <h3 id="modalTitle" style="margin: 0; color: white; font-size: 1.3rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-user-shield"></i>
                            <span data-translate="create_teacher_access">Create Teacher Access</span>
                        </h3>
                        <button onclick="closeCredentialModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Body -->
                    <div style="padding: 2rem; overflow-y: auto; flex: 1;">
                        <form id="credentialForm" onsubmit="return saveTeacherCredentials(event);">
                            <input type="hidden" id="credential_teacher_id" name="teacher_id">
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b;" data-translate="modal_teacher_name">Teacher Name</label>
                                <input type="text" id="credential_teacher_name" readonly style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem; background: #f8fafc; color: #64748b;">
                            </div>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b;"><span data-translate="modal_login_username">Login Email/Username</span> <span style="color: #ef4444;">*</span></label>
                                <input type="text" id="credential_username" name="username" required
                                       data-translate-placeholder="modal_username_placeholder" placeholder="teacher.email@school.edu"
                                       style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s;" 
                                       onfocus="this.style.borderColor='#1e40af'" 
                                       onblur="this.style.borderColor='#e2e8f0'">
                                <small style="display: block; margin-top: 0.5rem; color: #64748b; font-size: 0.875rem;" data-translate="modal_username_hint">This will be used for login</small>
                            </div>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b;">
                                    <span data-translate="modal_password">Password</span> 
                                    <span id="passwordNote" style="font-weight: 400; font-size: 0.875rem;"></span>
                                </label>
                                <input type="text" id="credential_password" name="password"
                                       data-translate-placeholder="modal_password_placeholder" placeholder="Enter new password"
                                       style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s;" 
                                       onfocus="this.style.borderColor='#1e40af'" 
                                       onblur="this.style.borderColor='#e2e8f0'">
                                <small style="display: block; margin-top: 0.5rem; color: #64748b; font-size: 0.875rem;" data-translate="modal_password_hint">Minimum 6 characters. Leave blank to keep current password.</small>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e5e7eb;">
                                <button type="button" onclick="closeCredentialModal()" 
                                        style="padding: 0.75rem 1.5rem; border: 2px solid #e5e7eb; background: white; color: #64748b; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s;"
                                        onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1'" 
                                        onmouseout="this.style.background='white'; this.style.borderColor='#e5e7eb'">
                                    <span data-translate="modal_cancel">Cancel</span>
                                </button>
                                <button type="submit" 
                                        style="padding: 0.75rem 2rem; border: none; background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%); color: white; border-radius: 8px; cursor: pointer; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);"
                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(30, 64, 175, 0.4)'" 
                                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(30, 64, 175, 0.3)'">
                                    <i class="fas fa-save" style="margin-right: 0.5rem;"></i>
                                    <span data-translate="modal_save">Save Credentials</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'graduated'): ?>
            <!-- GRADUATED STUDENTS PAGE -->
            <div class="content-wrapper page-graduated">
                <div class="dashboard-header">
                    <h1 data-translate="graduated_title">Graduated Students</h1>
                    <p class="dashboard-subtitle" data-translate="manage_graduated_students">View all graduated students</p>
                </div>
                <div class="reports-main-content">
                    <!-- Graduated Students List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="graduated_list"><i class="fas fa-list"></i> Graduated Students List</h3>
                            <div class="export-actions">
                                <button id="bulkDeleteGraduates" class="export-btn" onclick="bulkDeleteGraduates()" style="background: var(--danger-color); color: white; display: none;">
                                    <span data-translate="delete_selected">Delete Selected</span>
                                </button>
                                <button class="export-btn" onclick="exportData('csv')" data-translate="export_csv">📊 Export CSV</button>
                                <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table id="graduatedTable">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="masterCheckboxGraduates" onchange="toggleSelectAllGraduates()">
                                        </th>
                                        <th data-translate="id">ID</th>
                                        <th data-translate="name">Name</th>
                                        <th data-translate="age">Age</th>
                                        <th data-translate="gender">Gender</th>
                                        <th data-translate="class">Class</th>
                                        <th data-translate="graduation_grade">Graduation Grade</th>
                                        <th data-translate="email">Email</th>
                                        <th data-translate="phone">Phone</th>
                                        <th data-translate="graduation_date">Graduation Date</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $graduated_query = "SELECT * FROM graduated_students ORDER BY graduation_date DESC, student_name";
                                    $graduated_result = pg_query($conn, $graduated_query);
                                    
                                    if ($graduated_result && pg_num_rows($graduated_result) > 0):
                                        while($graduate = pg_fetch_assoc($graduated_result)):
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="graduate-checkbox" value="<?= $graduate['id'] ?>" onchange="updateBulkDeleteButton()">
                                        </td>
                                        <td><?= $graduate['id'] ?></td>
                                        <td><?= htmlspecialchars($graduate['student_name']) ?></td>
                                        <td><?= $graduate['age'] ?? 'N/A' ?></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; 
                                                         background: <?= $graduate['gender'] == 'Male' ? '#e3f2fd' : '#fce4ec' ?>; 
                                                         color: <?= $graduate['gender'] == 'Male' ? '#1565c0' : '#c2185b' ?>;">
                                                <?= $graduate['gender'] ?? 'N/A' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: var(--primary-light); color: var(--primary-color);">
                                                Class <?= htmlspecialchars($graduate['class_level']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 700;
                                                         background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                         color: white; text-align: center; min-width: 60px; display: inline-block;">
                                                <?= $graduate['graduation_grade'] ? number_format($graduate['graduation_grade'], 1) . '/100' : 'N/A' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($graduate['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($graduate['phone'] ?? '') ?></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: #f0f9ff; color: #0369a1;">
                                                <?= date('M d, Y', strtotime($graduate['graduation_date'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="handleGraduatedAction('delete_graduated_student', <?= $graduate['id'] ?>)" 
                                                    style="background: var(--danger-color); color: white; margin: 2px;" data-translate="delete">🗑️ Delete</button>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                            No graduated students yet. Students will appear here after graduation.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'teachers'): ?>
            <!-- TEACHERS PAGE -->
            <div class="content-wrapper page-teachers">
                <div class="dashboard-header">
                    <h1 data-translate="teachers_title">Teachers Management</h1>
                    <p class="dashboard-subtitle" data-translate="manage_teacher_info">Manage teachers and subject assignments</p>
                </div>
                <div class="reports-main-content">
                    <!-- Add Teacher Form -->
                    <div class="filter-panel" data-aos="fade-up">
                        <h3 class="filter-title" data-translate="add_new_teacher">➕ Add New Teacher</h3>
                        <form method="POST" action="" id="addTeacherForm" onsubmit="return handleTeacherFormSubmit(event, this);">
                            <input type="hidden" name="action" value="add_teacher">
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="full_name">Full Name *</label>
                                    <input type="text" name="teacher_name" class="premium-select" required placeholder="Enter teacher's full name" data-translate-placeholder="enter_full_name">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="email">Email *</label>
                                    <input type="email" name="email" class="premium-select" required placeholder="teacher@school.edu" data-translate-placeholder="teacher_email">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="phone">Phone</label>
                                    <input type="tel" name="phone" class="premium-select" placeholder="07XX XXX XXXX" data-translate-placeholder="phone_number">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="specialization">Specialization *</label>
                                    <select name="specialization" class="premium-select" required>
                                        <option value="" data-translate="select_specialization">Select Specialization</option>
                                        <option value="Mathematics">Mathematics</option>
                                        <option value="Physics">Physics</option>
                                        <option value="Chemistry">Chemistry</option>
                                        <option value="Biology">Biology</option>
                                        <option value="English">English</option>
                                        <option value="Arabic">Arabic</option>
                                        <option value="Kurdish">Kurdish</option>
                                        <option value="Computer Science">Computer Science</option>
                                        <option value="History">History</option>
                                        <option value="Geography">Geography</option>
                                        <option value="Islamic Studies">Islamic Studies</option>
                                        <option value="Physical Education">Physical Education</option>
                                        <option value="Arts">Arts</option>
                                        <option value="Management">Management</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="degree">Degree *</label>
                                    <select name="degree" class="premium-select" required>
                                        <option value="" data-translate="select_degree">Select Degree</option>
                                        <option value="High School Diploma" data-translate="degree_high_school">High School Diploma</option>
                                        <option value="Associate Degree" data-translate="degree_associate">Associate Degree</option>
                                        <option value="Bachelor's Degree" data-translate="degree_bachelor">Bachelor's Degree</option>
                                        <option value="Master's Degree" data-translate="degree_master">Master's Degree</option>
                                        <option value="Doctorate (PhD)" data-translate="degree_doctorate">Doctorate (PhD)</option>
                                        <option value="Professional Certificate" data-translate="degree_certificate">Professional Certificate</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="salary">Monthly Salary (IQD)</label>
                                    <input type="number" name="salary" class="premium-select" placeholder="500000" min="0" step="1000">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="assigned_subjects">Assigned Subject (Optional)</label>
                                    <select name="subject_id" id="addTeacherSubject" class="premium-select" onchange="updateAddTeacherYear()">
                                        <option value="" data-translate="no_subject_assignment">No subject assignment</option>
                                        <?php
                                        $subjects_query = "SELECT id, subject_name, year FROM subjects ORDER BY year, subject_name";
                                        $subjects_result = pg_query($conn, $subjects_query);
                                        if ($subjects_result) {
                                            while($subject = pg_fetch_assoc($subjects_result)) {
                                                echo "<option value='{$subject['id']}' data-year='{$subject['year']}'>{$subject['subject_name']} (Year {$subject['year']})</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="year">Year</label>
                                    <select name="year" id="addTeacherYear" class="premium-select" disabled>
                                        <option value="" data-translate="select_subject_first">Select subject first</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="class">Class</label>
                                    <select name="class_level" class="premium-select">
                                        <option value="" data-translate="no_class_assignment">No class assignment</option>
                                        <option value="A" data-translate="class_a">Class A</option>
                                        <option value="B" data-translate="class_b">Class B</option>
                                        <option value="C" data-translate="class_c">Class C</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="join_date">Join Date</label>
                                    <input type="date" name="join_date" class="premium-select" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="apply-filters-btn" data-translate="add_teacher">Add Teacher</button>
                            </div>
                        </form>
                    </div>

                    <!-- Teachers Table -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="teachers_list">Teachers List</h3>
                            <div class="export-actions">
                                <button class="export-btn" onclick="printTeachers()" data-translate="print">Print</button>
                            </div>
                        </div>

                        <!-- Filter Bar -->
                        <div class="filter-sections" style="margin-bottom: 1rem; padding: 1rem; background: var(--card-bg); border-radius: 8px;">
                            <div class="filter-section">
                                <label class="filter-label" data-translate="search">Search</label>
                                <input type="text" id="searchTeacher" class="premium-select" placeholder="Search by name, email..." onkeyup="filterTeachers()" data-translate-placeholder="search_teachers">
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_degree">Filter by Degree</label>
                                <select id="filterTeacherDegree" class="premium-select" onchange="filterTeachers()">
                                    <option value="" data-translate="all">All</option>
                                    <option value="High School Diploma">High School Diploma</option>
                                    <option value="Associate Degree">Associate Degree</option>
                                    <option value="Bachelor's Degree">Bachelor's Degree</option>
                                    <option value="Master's Degree">Master's Degree</option>
                                    <option value="Doctorate (PhD)">Doctorate (PhD)</option>
                                    <option value="Professional Certificate">Professional Certificate</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_specialization">Filter by Specialization</label>
                                <select id="filterTeacherSpec" class="premium-select" onchange="filterTeachers()">
                                    <option value="" data-translate="all">All</option>
                                    <option value="Mathematics">Mathematics</option>
                                    <option value="Physics">Physics</option>
                                    <option value="Chemistry">Chemistry</option>
                                    <option value="Biology">Biology</option>
                                    <option value="English">English</option>
                                    <option value="Arabic">Arabic</option>
                                    <option value="Kurdish">Kurdish</option>
                                    <option value="Computer Science">Computer Science</option>
                                    <option value="History">History</option>
                                    <option value="Geography">Geography</option>
                                    <option value="Islamic Studies">Islamic Studies</option>
                                    <option value="Physical Education">Physical Education</option>
                                    <option value="Arts">Arts</option>
                                    <option value="Management">Management</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_year">Filter by Year</label>
                                <select id="filterTeacherYear" class="premium-select" onchange="filterTeachers()">
                                    <option value="" data-translate="all">All</option>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_class">Filter by Class</label>
                                <select id="filterTeacherClass" class="premium-select" onchange="filterTeachers()">
                                    <option value="" data-translate="all">All</option>
                                    <option value="A">Class A</option>
                                    <option value="B">Class B</option>
                                    <option value="C">Class C</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table id="teachersTable">
                                <thead>
                                    <tr>
                                        <th data-translate="id">ID</th>
                                        <th data-translate="name">Name</th>
                                        <th data-translate="email">Email</th>
                                        <th data-translate="phone">Phone</th>
                                        <th data-translate="specialization">Specialization</th>
                                        <th data-translate="degree">Degree</th>
                                        <th data-translate="salary">Salary</th>
                                        <th data-translate="assigned_subjects">Assigned Subjects</th>
                                        <th data-translate="join_date">Join Date</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $teachers_query = "SELECT t.*, 
                                                             COUNT(DISTINCT ts.subject_id) as subject_count
                                                      FROM teachers t
                                                      LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id
                                                      GROUP BY t.id
                                                      ORDER BY t.name";
                                    $teachers_result = pg_query($conn, $teachers_query);
                                    
                                    if ($teachers_result && pg_num_rows($teachers_result) > 0):
                                        while($teacher = pg_fetch_assoc($teachers_result)):
                                    ?>
                                    <tr class="teacher-row" data-teacher-id="<?= $teacher['id'] ?>" 
                                        data-degree="<?= htmlspecialchars($teacher['degree'] ?? '') ?>"
                                        data-specialization="<?= htmlspecialchars($teacher['specialization'] ?? '') ?>">
                                        <td style="text-align: center;"><?= $teacher['id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($teacher['name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($teacher['email']) ?></td>
                                        <td style="text-align: center;"><?= htmlspecialchars($teacher['phone'] ?? 'N/A') ?></td>
                                        <td style="text-align: center;">
                                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 500;
                                                         background: #f1f5f9; color: #334155; display: inline-block; border: 1px solid #e2e8f0;">
                                                <?= htmlspecialchars($teacher['specialization'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 500;
                                                         background: #f8fafc; color: #475569; display: inline-block; border: 1px solid #cbd5e1;">
                                                <?= htmlspecialchars($teacher['degree'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;
                                                         background: #f0fdf4; color: #166534; display: inline-block; border: 1px solid #bbf7d0;">
                                                <?= isset($teacher['salary']) && $teacher['salary'] ? number_format($teacher['salary']) . ' IQD' : 'N/A' ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($teacher['subject_count'] > 0): ?>
                                                <span class="total-subjects-badge" style="cursor: pointer;" onclick="toggleTeacherDetails(<?= $teacher['id'] ?>)" title="Click to view assigned subjects">
                                                    <span><?= $teacher['subject_count'] ?></span>
                                                    <span data-translate="subjects">subjects</span>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.85rem;" data-translate="no_assignments">No Assignments</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 500;
                                                         background: #f0f9ff; color: #0c4a6e; display: inline-block; border: 1px solid #bae6fd; white-space: nowrap;">
                                                <?= date('M d, Y', strtotime($teacher['join_date'])) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <button class="action-btn edit" onclick="editTeacher(<?= $teacher['id'] ?>)" data-translate="edit" title="Edit">Edit</button>
                                            <button class="action-btn delete" onclick="deleteTeacher(<?= $teacher['id'] ?>)" data-translate="delete" title="Delete">Delete</button>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; color: var(--text-light); padding: 2rem;" data-translate="no_teachers">
                                            No teachers added yet. Click "Add New Teacher" to get started.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'subjects'): ?>
            <!-- SUBJECTS PAGE -->
            <div class="content-wrapper page-subjects">
                <div class="dashboard-header">
                    <h1 data-translate="subjects_title">Subjects Management</h1>
                    <p class="dashboard-subtitle" data-translate="manage_subjects_curriculum">Manage subjects and curriculum</p>
                </div>
                <div class="reports-main-content">
                    <!-- Add Subject Form -->
                    <div class="filter-panel" data-aos="fade-up">
                        <h3 class="filter-title" data-translate="add_new_subject">➕ Add New Subject</h3>
                        <form method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'subjects');">
                            <input type="hidden" name="action" value="add_subject">
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="subject_name">Subject Name</label>
                                    <input type="text" name="subject_name" class="premium-select" required>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="description">Description</label>
                                    <textarea name="description" class="premium-select" rows="3"></textarea>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year *</label>
                                    <select name="year" class="premium-select" required>
                                        <option value="" data-translate="select_year">Select Year</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="credits">Credits</label>
                                    <input type="number" name="credits" class="premium-select" min="1" max="50" required>
                                </div>
                                <button type="submit" class="apply-filters-btn" data-translate="add_subject">Add Subject</button>
                            </div>
                        </form>
                    </div>

                    <!-- Year Filter -->
                    <div class="filter-panel" data-aos="fade-up">
                        <h3 class="filter-title" data-translate="filter_subjects">Filter Subjects</h3>
                        <div class="filter-sections">
                            <div class="filter-section">
                                <label class="filter-label" data-translate="academic_year">Academic Year</label>
                                <select id="yearFilter" class="premium-select" onchange="filterSubjects()">
                                    <option value="" data-translate="all_years">All Years</option>
                                    <option value="1" data-translate="year_1">Year 1</option>
                                    <option value="2" data-translate="year_2">Year 2</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_subject">Filter by Subject</label>
                                <select id="subjectFilter" class="premium-select" onchange="filterSubjects()">
                                    <option value="" data-translate="all_subjects">All Subjects</option>
                                    <?php
                                    // Get all unique subjects for filter
                                    $subjects_filter_query = "SELECT DISTINCT subject_name FROM subjects ORDER BY subject_name";
                                    $subjects_filter_result = pg_query($conn, $subjects_filter_query);
                                    if ($subjects_filter_result) {
                                        while($subj = pg_fetch_assoc($subjects_filter_result)) {
                                            echo "<option value=\"" . htmlspecialchars($subj['subject_name']) . "\">" . htmlspecialchars($subj['subject_name']) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_class">Filter by Class</label>
                                <select id="classFilter" class="premium-select" onchange="filterSubjects()">
                                    <option value="" data-translate="all_classes">All Classes</option>
                                    <option value="A">Class A</option>
                                    <option value="B">Class B</option>
                                    <option value="C">Class C</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-label" data-translate="filter_by_teacher">Filter by Teacher</label>
                                <select id="teacherFilter" class="premium-select" onchange="filterSubjects()">
                                    <option value="" data-translate="all_teachers">All Teachers</option>
                                    <?php
                                    // Get all teachers for filter
                                    $teachers_filter_query = "SELECT id, name FROM teachers ORDER BY name";
                                    $teachers_filter_result = pg_query($conn, $teachers_filter_query);
                                    if ($teachers_filter_result) {
                                        while($teacher = pg_fetch_assoc($teachers_filter_result)) {
                                            echo "<option value=\"" . htmlspecialchars($teacher['name']) . "\">" . htmlspecialchars($teacher['name']) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Subjects List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="subjects_list"><i class="fas fa-list"></i> Subjects List</h3>
                            <div class="export-actions">
                                <button class="export-btn" onclick="exportData('csv')" data-translate="export_csv">📊 Export CSV</button>
                                <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                            </div>
                        </div>
                        <div class="table-container">
                            <table id="subjectsTable">
                                <thead>
                                    <tr>
                                        <th data-translate="id">ID</th>
                                        <th data-translate="subject_name">Subject Name</th>
                                        <th data-translate="description">Description</th>
                                        <th data-translate="credits">Credits</th>
                                        <th data-translate="academic_year">Year</th>
                                        <th data-translate="teacher">Teacher</th>
                                        <th data-translate="total_enrolls">Total Enrolls</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $subjects_query = "
                                        SELECT 
                                            s.*,
                                            COUNT(DISTINCT m.student_id) as enrollment_count,
                                            t.name as teacher_name,
                                            t.id as teacher_id,
                                            ts.class_level as assigned_class
                                        FROM subjects s
                                        LEFT JOIN marks m ON s.id = m.subject_id
                                        LEFT JOIN teacher_subjects ts ON s.id = ts.subject_id AND s.year = ts.year
                                        LEFT JOIN teachers t ON ts.teacher_id = t.id
                                        GROUP BY s.id, s.subject_name, s.description, s.credits, s.year, t.name, t.id, ts.class_level
                                        ORDER BY s.id
                                    ";
                                    $subjects_result = pg_query($conn, $subjects_query);
                                    
                                    if ($subjects_result && pg_num_rows($subjects_result) > 0):
                                        while($subject = pg_fetch_assoc($subjects_result)):
                                    ?>
                                    <tr data-year="<?= $subject['year'] ?? '' ?>" 
                                        data-teacher="<?= htmlspecialchars($subject['teacher_name'] ?? '') ?>"
                                        data-subject="<?= htmlspecialchars($subject['subject_name'] ?? '') ?>"
                                        data-class="<?= htmlspecialchars($subject['assigned_class'] ?? '') ?>">
                                        <td><?= $subject['id'] ?></td>
                                        <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($subject['description'] ?? '') ?></td>
                                        <td><?= $subject['credits'] ?></td>
                                        <td><?= $subject['year'] ? 'Year ' . $subject['year'] : 'N/A' ?></td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($subject['teacher_name'])): ?>
                                                <span style="font-weight: 500;">
                                                    <?= htmlspecialchars($subject['teacher_name']) ?>
                                                    <?php if (!empty($subject['assigned_class'])): ?>
                                                        <span style="font-size: 0.85rem; color: #64748b;"> (Class <?= htmlspecialchars($subject['assigned_class']) ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.85rem;">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 6px 12px; background: var(--kpi-light-blue); color: white; border-radius: 4px; font-weight: 500; min-width: 90px;">
                                                <span><?= $subject['enrollment_count'] ?? 0 ?></span>
                                                <span data-translate="students_count">students</span>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="editSubject(<?= $subject['id'] ?>)" data-translate="edit">Edit</button>
                                            <button class="export-btn" onclick="handleSubjectAction('delete_subject', <?= $subject['id'] ?>)" style="background: var(--danger-color); color: white;" data-translate="delete">Delete</button>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                            No subjects found. Add some subjects to get started.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'marks'): ?>
            <!-- MARKS PAGE -->
            <div class="content-wrapper page-marks">
                <div class="dashboard-header">
                    <h1 data-translate="marks_title">Marks Management</h1>
                    <p class="dashboard-subtitle" data-translate="input_manage_marks">Input and manage student marks</p>
                </div>
                <div class="reports-main-content">
                    <!-- Marks List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="marks_list"> Marks List</h3>
                            <div class="export-actions">
                                <button class="export-btn" onclick="collapseAllMarks()" data-translate="collapse_all"><i class="fas fa-folder-minus"></i> Collapse All</button>
                                <button class="export-btn" onclick="exportData('csv')" data-translate="export_csv">📊 Export CSV</button>
                                <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                            </div>
                        </div>
                        
                        <!-- Search and Filter Section -->
                        <div class="filter-panel" style="margin-bottom: 1rem;" data-aos="fade-up">
                            <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1rem;" data-translate="search_filter_marks"> Search & Filter Marks</h4>
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year</label>
                                    <select id="filterMarksYear" class="premium-select" onchange="updateMarksClassFilter(); updateMarksSubjectFilter(); filterMarks()">
                                        <option value="" data-translate="all_years">All Years</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_class">Filter by Class</label>
                                    <select id="filterMarksClass" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_classes">All Classes</option>
                                        <!-- Options will be populated dynamically -->
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_subject">Filter by Subject</label>
                                    <select id="filterMarksSubject" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_subjects">All Subjects</option>
                                        <!-- Options will be populated dynamically -->
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_teacher">Filter by Teacher</label>
                                    <select id="filterMarksTeacher" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_teachers">All Teachers</option>
                                        <?php
                                        // Get all teachers for filter
                                        $teachers_filter_query = "SELECT DISTINCT id, name FROM teachers ORDER BY name";
                                        $teachers_filter_result = pg_query($conn, $teachers_filter_query);
                                        if ($teachers_filter_result) {
                                            while($teacher = pg_fetch_assoc($teachers_filter_result)) {
                                                echo "<option value=\"" . htmlspecialchars($teacher['name']) . "\">" . htmlspecialchars($teacher['name']) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="sort_by_final_grade">Sort by Final Grade</label>
                                    <select id="sortMarksByGrade" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="sort_default">Default</option>
                                        <option value="asc" data-translate="sort_grade_low_high">Grade: Low to High</option>
                                        <option value="desc" data-translate="sort_grade_high_low">Grade: High to Low</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="search">Search</label>
                                    <input type="text" id="searchMarks" class="premium-select" placeholder="Search by student name..." data-translate-placeholder="search_by_student_name" onkeyup="filterMarks()">
                                </div>
                                <div class="filter-section">
                                    <button class="clear-filters-btn" onclick="clearMarksFilters()" style="margin-top: 1.5rem;" data-translate="clear_filters">
                                        🔄 Clear Filters
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-container">
                            <table id="marksTable">
                                <thead>
                                    <tr>
                                        <th data-translate="student">Student</th>
                                        <th style="text-align: center;" data-translate="year">Year</th>
                                        <th style="text-align: center;" data-translate="class_level">Class</th>
                                        <th style="text-align: center;" data-translate="total_subjects">Subjects</th>
                                        <th style="text-align: center;" data-translate="final_grade">Final Grade</th>
                                        <th style="text-align: center;" data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Get all students with marks, grouped by student (only active students)
                                    // For teachers: only show students enrolled in their assigned subjects
                                    if (isTeacher()) {
                                        $teacher_id = $_SESSION['teacher_id'];
                                        $students_marks_query = "
                                            SELECT DISTINCT
                                                s.id as student_id,
                                                s.name as student_name,
                                                s.year as student_year,
                                                s.class_level,
                                                s.status as student_status
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
                                            JOIN subjects sub ON m.subject_id = sub.id
                                            JOIN teacher_subjects ts ON sub.id = ts.subject_id AND sub.year = ts.year
                                            WHERE s.status = 'active' 
                                            AND ts.teacher_id = $teacher_id
                                            ORDER BY s.name
                                        ";
                                        $students_marks_result = pg_query($conn, $students_marks_query);
                                    } else {
                                        // Admin sees all students
                                        $students_marks_query = "
                                            SELECT DISTINCT
                                                s.id as student_id,
                                                s.name as student_name,
                                                s.year as student_year,
                                                s.class_level,
                                                s.status as student_status
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
                                            WHERE s.status = 'active'
                                            ORDER BY s.name
                                        ";
                                        $students_marks_result = pg_query($conn, $students_marks_query);
                                    }
                                    
                                    if ($students_marks_result && pg_num_rows($students_marks_result) > 0):
                                        while($student = pg_fetch_assoc($students_marks_result)):
                                            $student_id = $student['student_id'];
                                            
                                            // Get marks for this student with teacher information
                                            // For teachers: only show subjects they teach
                                            if (isTeacher()) {
                                                $teacher_id = $_SESSION['teacher_id'];
                                                $marks_for_student_query = "
                                                    SELECT 
                                                        m.id as mark_id,
                                                        sub.subject_name,
                                                        sub.year as subject_year,
                                                        sub.credits,
                                                        m.final_exam,
                                                        m.midterm_exam,
                                                        m.quizzes,
                                                        m.daily_activities,
                                                        m.mark as total_mark,
                                                        m.final_grade,
                                                        m.status,
                                                        t.name as teacher_name,
                                                        t.id as teacher_id,
                                                        ts.class_level as assigned_class,
                                                        CASE 
                                                            WHEN m.mark >= 90 THEN 'A+'
                                                            WHEN m.mark >= 80 THEN 'A'
                                                            WHEN m.mark >= 70 THEN 'B'
                                                            WHEN m.mark >= 50 THEN 'C'
                                                            ELSE 'F'
                                                        END as grade
                                                    FROM marks m
                                                    JOIN subjects sub ON m.subject_id = sub.id
                                                    LEFT JOIN teacher_subjects ts ON sub.id = ts.subject_id AND sub.year = ts.year
                                                    LEFT JOIN teachers t ON ts.teacher_id = t.id
                                                    WHERE m.student_id = $1
                                                    AND ts.teacher_id = $teacher_id
                                                    ORDER BY sub.year, sub.subject_name
                                                ";
                                            } else {
                                                // Admin sees all subjects
                                                $marks_for_student_query = "
                                                    SELECT 
                                                        m.id as mark_id,
                                                        sub.subject_name,
                                                        sub.year as subject_year,
                                                        sub.credits,
                                                        m.final_exam,
                                                        m.midterm_exam,
                                                        m.quizzes,
                                                        m.daily_activities,
                                                        m.mark as total_mark,
                                                        m.final_grade,
                                                        m.status,
                                                        t.name as teacher_name,
                                                        t.id as teacher_id,
                                                        ts.class_level as assigned_class,
                                                        CASE 
                                                            WHEN m.mark >= 90 THEN 'A+'
                                                            WHEN m.mark >= 80 THEN 'A'
                                                            WHEN m.mark >= 70 THEN 'B'
                                                            WHEN m.mark >= 50 THEN 'C'
                                                            ELSE 'F'
                                                        END as grade
                                                    FROM marks m
                                                    JOIN subjects sub ON m.subject_id = sub.id
                                                    LEFT JOIN teacher_subjects ts ON sub.id = ts.subject_id AND sub.year = ts.year
                                                    LEFT JOIN teachers t ON ts.teacher_id = t.id
                                                    WHERE m.student_id = $1
                                                    ORDER BY sub.year, sub.subject_name
                                                ";
                                            }
                                            $marks_params = array($student_id);
                                            $marks_for_student_result = pg_query_params($conn, $marks_for_student_query, $marks_params);
                                            $student_marks = pg_fetch_all($marks_for_student_result);
                                            $total_subjects = count($student_marks);
                                            
                                            // Group marks by year and calculate totals
                                            $marks_by_year = [];
                                            $year_totals = [];
                                            $teacher_names = []; // Collect all teacher names for this student
                                            if ($student_marks) {
                                                foreach ($student_marks as $mark) {
                                                    $year = $mark['subject_year'];
                                                    if (!isset($marks_by_year[$year])) {
                                                        $marks_by_year[$year] = [];
                                                        $year_totals[$year] = 0;
                                                    }
                                                    $marks_by_year[$year][] = $mark;
                                                    $year_totals[$year] += floatval($mark['final_grade']);
                                                    
                                                    // Collect unique teacher names
                                                    if (!empty($mark['teacher_name']) && !in_array($mark['teacher_name'], $teacher_names)) {
                                                        $teacher_names[] = $mark['teacher_name'];
                                                    }
                                                }
                                            }
                                            $teachers_list = implode(', ', $teacher_names); // Join all teachers
                                            
                                            // Calculate student's year-specific final grade
                                            $graduation_result = calculateGraduationGrade($conn, $student_id);
                                            $year1_grade = 0;
                                            $year2_grade = 0;
                                            $total_grade = 0;
                                            if ($graduation_result['success']) {
                                                $year1_grade = $graduation_result['year1_grade'];
                                                $year2_grade = $graduation_result['year2_grade'];
                                                $total_grade = $year1_grade + $year2_grade;
                                            }
                                    ?>
                                    <!-- Student Main Row (Clickable) -->
                                    <tr class="student-main-row" onclick="toggleStudentDetails(<?= $student_id ?>)" 
                                        data-student-id="<?= $student_id ?>"
                                        data-year="<?= $student['student_year'] ?>" 
                                        data-student-status="<?= $student['student_status'] ?>" 
                                        data-class="<?= $student['class_level'] ?>"
                                        data-teachers="<?= htmlspecialchars($teachers_list) ?>">
                                        <td>
                                            <div class="student-name-cell">
                                                <span class="expand-icon">▶</span>
                                                <strong><?= htmlspecialchars($student['student_name']) ?></strong>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: #f5f5f5; color: #000000; border: 1px solid #e0e0e0;">
                                                <span data-translate="year">Year</span> <?= $student['student_year'] ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: #f3f4f6; color: #374151;">
                                                <?= htmlspecialchars($student['class_level']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="total-subjects-badge">
                                                <span><?= $total_subjects ?></span>
                                                <span data-translate="subjects">subjects</span>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="final-grade-display"><?= number_format($total_grade, 2) ?></span>
                                        </td>
                                        <td style="text-align: center;" onclick="event.stopPropagation();">
                                            <button class="manage-marks-btn" onclick="toggleStudentDetails(<?= $student_id ?>)" data-translate="manage_marks">
                                                Manage Marks
                                            </button>
                                        </td>
                                    </tr>
                                    
                                    <!-- Subject Details Row (Expandable) with Inline Editing -->
                                    <tr class="subject-details-row" id="details-<?= $student_id ?>">
                                        <td colspan="6">
                                            <div class="subject-details-container">
                                                <div class="subject-title"><span data-translate="subject_marks_title">Subject Marks - Click Edit to modify</span></div>
                                                <table class="subject-marks-table">
                                                    <thead>
                                                        <tr>
                                                            <th data-translate="subject">Subject</th>
                                                            <th data-translate="teacher">Teacher</th>
                                                            <th data-translate="final_exam">Final (60)</th>
                                                            <th data-translate="midterm_exam">Midterm (20)</th>
                                                            <th data-translate="quiz">Quiz (10)</th>
                                                            <th data-translate="daily_activities">Daily (10)</th>
                                                            <th data-translate="total">Total</th>
                                                            <th data-translate="grade">Grade</th>
                                                            <th data-translate="status">Status</th>
                                                            <th data-translate="actions">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        if ($student_marks && count($student_marks) > 0):
                                                            ksort($marks_by_year); // Sort by year
                                                            foreach ($marks_by_year as $year => $year_marks):
                                                        ?>
                                                            <?php foreach($year_marks as $mark): 
                                                                $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $mark['grade']));
                                                            ?>
                                                            <tr>
                                                                <td style="text-align: left; font-weight: 500;">
                                                                    <?= htmlspecialchars($mark['subject_name']) ?>
                                                                </td>
                                                                <td style="text-align: center;">
                                                                    <?php if (!empty($mark['teacher_name'])): ?>
                                                                        <span style="font-weight: 500; color: #334155;">
                                                                            <?= htmlspecialchars($mark['teacher_name']) ?>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="color: #94a3b8; font-size: 0.85rem;">Not Assigned</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?= intval($mark['final_exam']) ?></td>
                                                                <td><?= intval($mark['midterm_exam']) ?></td>
                                                                <td><?= intval($mark['quizzes']) ?></td>
                                                                <td><?= intval($mark['daily_activities']) ?></td>
                                                                <td><strong><?= intval($mark['total_mark']) ?></strong></td>
                                                                <td>
                                                                    <span class="grade-badge <?= $grade_class ?>"><?= $mark['grade'] ?></span>
                                                                </td>
                                                                <td>
                                                                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 500;
                                                                                 background: <?= $mark['status'] == 'Pass' ? '#dcfce7' : ($mark['status'] == 'Fail' ? '#fecaca' : '#fef3c7') ?>; 
                                                                                 color: <?= $mark['status'] == 'Pass' ? '#166534' : ($mark['status'] == 'Fail' ? '#dc2626' : '#d97706') ?>;">
                                                                        <?= htmlspecialchars($mark['status']) ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php if (isTeacher()): ?>
                                                                        <button class="inline-edit-btn" onclick="editMark(<?= $mark['mark_id'] ?>)" data-translate="edit">Edit</button>
                                                                        <button class="inline-delete-btn" onclick="handleMarkAction('delete_mark', <?= $mark['mark_id'] ?>)" title="Reset all marks to 0">Reset</button>
                                                                    <?php else: ?>
                                                                        <span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">View Only</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                            <!-- Year Total Row -->
                                                            <tr style="background: linear-gradient(135deg, <?= $year == 1 ? '#dbeafe' : '#f3e8ff' ?> 0%, <?= $year == 1 ? '#bfdbfe' : '#e9d5ff' ?> 100%); font-weight: 600; border-top: 2px solid <?= $year == 1 ? '#3b82f6' : '#a855f7' ?>;">
                                                                <td colspan="9" style="text-align: left; padding: 12px; color: <?= $year == 1 ? '#1e40af' : '#6b21a8' ?>; font-size: 1rem;">
                                                                    <i class="fas fa-chart-pie"></i> Year <?= $year ?> Total Credits
                                                                </td>
                                                                <td style="text-align: center; color: <?= $year == 1 ? '#1e40af' : '#6b21a8' ?>; font-size: 1.1rem;">
                                                                    <strong><?= number_format($year_totals[$year], 2) ?></strong>
                                                                </td>
                                                            </tr>
                                                        <?php 
                                                            endforeach;
                                                        else:
                                                        ?>
                                                            <tr>
                                                                <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-light);">
                                                                    No marks recorded yet
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                            No marks found. Add some marks to get started.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Initialize AOS (Animate On Scroll)
        AOS.init({
            duration: 600,
            easing: 'ease-in-out',
            once: true,
            offset: 20,
            delay: 0
        });

        // Global subjects data for filters
        <?php
        if (isTeacher()) {
            // For teachers: only show their assigned subjects
            $teacher_id = $_SESSION['teacher_id'];
            $all_subjects_for_filter = pg_query_params($conn, "
                SELECT DISTINCT s.id, s.subject_name, ts.year
                FROM teacher_subjects ts
                JOIN subjects s ON ts.subject_id = s.id
                WHERE ts.teacher_id = $1
                ORDER BY ts.year, s.subject_name
            ", [$teacher_id]);
        } else {
            // For admin: show all subjects
            $all_subjects_for_filter = pg_query($conn, "SELECT id, subject_name, year FROM subjects ORDER BY year, subject_name");
        }
        echo "const allSubjects = " . json_encode(pg_fetch_all($all_subjects_for_filter)) . ";\n";
        ?>

        // ===== COUNT UP ANIMATION UTILITY =====
        class CountUp {
            constructor(element, options = {}) {
                this.element = element;
                this.target = parseFloat(options.target) || 0;
                this.startValue = parseFloat(options.start) || 0;
                this.duration = options.duration || 2000; // milliseconds
                this.decimals = options.decimals !== undefined ? options.decimals : this.getDecimals(this.target);
                this.separator = options.separator || ',';
                this.suffix = options.suffix || '';
                this.prefix = options.prefix || '';
                this.easing = options.easing || this.easeOutExpo;
                this.onComplete = options.onComplete || null;
                
                this.startTime = null;
                this.animationFrame = null;
            }
            
            getDecimals(num) {
                const str = num.toString();
                if (str.includes('.')) {
                    const decimals = str.split('.')[1];
                    if (parseInt(decimals) !== 0) {
                        return decimals.length;
                    }
                }
                return 0;
            }
            
            // Easing function - exponential ease out for smooth spring-like effect
            easeOutExpo(t) {
                return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
            }
            
            // Alternative easing - cubic ease out
            easeOutCubic(t) {
                return 1 - Math.pow(1 - t, 3);
            }
            
            formatNumber(num) {
                const fixedNum = num.toFixed(this.decimals);
                const parts = fixedNum.split('.');
                
                // Add thousand separators
                if (this.separator) {
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, this.separator);
                }
                
                return this.prefix + parts.join('.') + this.suffix;
            }
            
            animate(timestamp) {
                if (!this.startTime) this.startTime = timestamp;
                
                const elapsed = timestamp - this.startTime;
                const progress = Math.min(elapsed / this.duration, 1);
                const easedProgress = this.easing(progress);
                
                const currentValue = this.startValue + (this.target - this.startValue) * easedProgress;
                this.element.textContent = this.formatNumber(currentValue);
                
                if (progress < 1) {
                    this.animationFrame = requestAnimationFrame((t) => this.animate(t));
                } else {
                    this.element.textContent = this.formatNumber(this.target);
                    if (this.onComplete) this.onComplete();
                }
            }
            
            startAnimation() {
                if (this.animationFrame) {
                    cancelAnimationFrame(this.animationFrame);
                }
                this.startTime = null;
                this.animationFrame = requestAnimationFrame((t) => this.animate(t));
            }
            
            reset() {
                if (this.animationFrame) {
                    cancelAnimationFrame(this.animationFrame);
                }
                this.element.textContent = this.formatNumber(this.startValue);
            }
            
            update(newTarget, newOptions = {}) {
                this.startValue = parseFloat(this.element.textContent.replace(/[^0-9.-]/g, '')) || this.startValue;
                this.target = newTarget;
                if (newOptions.duration) this.duration = newOptions.duration;
                if (newOptions.suffix !== undefined) this.suffix = newOptions.suffix;
                if (newOptions.prefix !== undefined) this.prefix = newOptions.prefix;
                this.startAnimation();
            }
        }
        
        // Intersection Observer for viewport detection
        function observeKPICards() {
            const kpiCards = document.querySelectorAll('.kpi-card');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !entry.target.dataset.animated) {
                        entry.target.dataset.animated = 'true';
                        
                        const valueElement = entry.target.querySelector('.kpi-value');
                        if (valueElement && valueElement.dataset.target) {
                            const target = parseFloat(valueElement.dataset.target);
                            const text = valueElement.textContent.trim();
                            const suffix = text.includes('%') ? '%' : '';
                            const prefix = text.includes('Class') ? 'Class ' : '';
                            
                            // Only animate numbers
                            if (!isNaN(target)) {
                                const counter = new CountUp(valueElement, {
                                    target: target,
                                    start: 0,
                                    duration: 2000,
                                    decimals: 0,
                                    separator: '',
                                    suffix: suffix,
                                    prefix: prefix
                                });
                                
                                // Store counter instance for later updates
                                valueElement.countUpInstance = counter;
                                counter.startAnimation();
                            }
                        }
                    }
                });
            }, {
                threshold: 0.2,
                rootMargin: '0px 0px -50px 0px'
            });
            
            kpiCards.forEach(card => observer.observe(card));
        }
        
        // Initialize KPI animations on page load
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                observeKPICards();
            }, 100);
        });

        // ============================================
        // BLUR TEXT ANIMATION CLASS
        // ============================================
        class BlurText {
            constructor(element, options = {}) {
                this.element = element;
                this.text = element.textContent.trim();
                this.animateBy = options.animateBy || 'words'; // 'words' or 'characters'
                this.delay = options.delay || 50; // ms between each word/character
                this.direction = options.direction || 'top'; // 'top' or 'bottom'
                this.threshold = options.threshold || 0.1;
                this.rootMargin = options.rootMargin || '0px';
                this.onComplete = options.onComplete || null;
                this.stepDuration = options.stepDuration || 350; // ms for each animation step
                
                this.animated = false;
                this.observer = null;
                
                // Detect if text is RTL (Arabic/Kurdish)
                this.isRTL = this.detectRTL(this.text);
                
                this.init();
            }
            
            detectRTL(text) {
                // Check for Arabic, Hebrew, Kurdish (Sorani) characters
                const rtlChars = /[\u0600-\u06FF\u0750-\u077F\u0590-\u05FF\uFB50-\uFDFF\uFE70-\uFEFF]/;
                return rtlChars.test(text);
            }
            
            init() {
                // Clear original text
                this.element.textContent = '';
                this.element.style.display = 'flex';
                this.element.style.flexWrap = 'wrap';
                this.element.style.gap = '0';
                this.element.style.justifyContent = 'center';
                
                // For RTL languages, always animate by words (not characters)
                // This preserves Arabic letter connections and text shaping
                const shouldAnimateByWords = this.isRTL || this.animateBy === 'words';
                
                // Split text into segments
                const segments = shouldAnimateByWords
                    ? this.text.split(' ') 
                    : Array.from(this.text);
                
                // Set text direction
                if (this.isRTL) {
                    this.element.style.direction = 'rtl';
                }
                
                // Create span elements for each segment
                segments.forEach((segment, index) => {
                    const span = document.createElement('span');
                    span.textContent = segment;
                    span.style.display = 'inline-block';
                    span.style.willChange = 'transform, filter, opacity';
                    span.className = 'blur-text-segment';
                    
                    // Preserve text direction for RTL
                    if (this.isRTL) {
                        span.style.direction = 'rtl';
                        span.style.unicodeBidi = 'embed';
                    }
                    
                    // Set initial state
                    const yOffset = this.direction === 'top' ? -50 : 50;
                    span.style.transform = `translateY(${yOffset}px)`;
                    span.style.filter = 'blur(10px)';
                    span.style.opacity = '0';
                    
                    this.element.appendChild(span);
                    
                    // Add space after words (but not after last word)
                    if (shouldAnimateByWords && index < segments.length - 1) {
                        const space = document.createElement('span');
                        space.innerHTML = '&nbsp;';
                        space.style.display = 'inline-block';
                        this.element.appendChild(space);
                    }
                });
                
                // Setup intersection observer
                this.setupObserver();
            }
            
            setupObserver() {
                this.observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && !this.animated) {
                            this.animated = true;
                            this.animate();
                            this.observer.unobserve(this.element);
                        }
                    });
                }, {
                    threshold: this.threshold,
                    rootMargin: this.rootMargin
                });
                
                this.observer.observe(this.element);
            }
            
            animate() {
                const segments = this.element.querySelectorAll('.blur-text-segment');
                const yMid = this.direction === 'top' ? 5 : -5;
                
                segments.forEach((span, index) => {
                    const baseDelay = index * this.delay;
                    
                    // Create multi-step animation using CSS transitions
                    setTimeout(() => {
                        // Step 1: Move to mid-point with partial blur
                        span.style.transition = `all ${this.stepDuration}ms cubic-bezier(0.34, 1.56, 0.64, 1)`;
                        span.style.transform = `translateY(${yMid}px)`;
                        span.style.filter = 'blur(5px)';
                        span.style.opacity = '0.5';
                        
                        // Step 2: Move to final position, remove blur
                        setTimeout(() => {
                            span.style.transition = `all ${this.stepDuration}ms cubic-bezier(0.34, 1.56, 0.64, 1)`;
                            span.style.transform = 'translateY(0)';
                            span.style.filter = 'blur(0px)';
                            span.style.opacity = '1';
                            
                            // Call onComplete for last segment
                            if (index === segments.length - 1 && this.onComplete) {
                                setTimeout(() => this.onComplete(), this.stepDuration);
                            }
                        }, this.stepDuration);
                    }, baseDelay);
                });
            }
            
            reset() {
                this.animated = false;
                const segments = this.element.querySelectorAll('.blur-text-segment');
                const yOffset = this.direction === 'top' ? -50 : 50;
                
                segments.forEach(span => {
                    span.style.transition = 'none';
                    span.style.transform = `translateY(${yOffset}px)`;
                    span.style.filter = 'blur(10px)';
                    span.style.opacity = '0';
                });
                
                if (this.observer) {
                    this.observer.observe(this.element);
                }
            }
            
            destroy() {
                if (this.observer) {
                    this.observer.disconnect();
                }
                this.element.textContent = this.text;
                this.element.style.display = '';
                this.element.style.flexWrap = '';
                this.element.style.gap = '';
            }
        }
        
        // Blur text animation removed - headers use simple CSS now
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Header animations removed for better performance
        });

        // Define updateGradeDistributionChart function globally so it's available immediately
        window.updateGradeDistributionChart = function(year = '') {
            // Show loading state
            const chartContainer = document.querySelector('#performanceDistributionChart')?.parentElement;
            if (!chartContainer) return;
            
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'chart-loading';
            loadingDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-light);">📊 Loading chart data...</div>';
            chartContainer.style.position = 'relative';
            chartContainer.appendChild(loadingDiv);

            // Make AJAX request to get filtered grade distribution data
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax=get_grade_distribution&filter_year=' + encodeURIComponent(year)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && window.gradeDistributionChart) {
                    // Generate dynamic colors for the new data
                    const gradeColors = {
                        'A+': '#10B981', // Green
                        'A': '#1e40af',  // Navy Blue  
                        'B': '#F59E0B',  // Yellow
                        'C': '#F97316',  // Orange
                        'F': '#DC2626',  // Red
                        'No Data': '#9CA3AF' // Gray for no data
                    };
                    const newBackgroundColors = data.data.labels.map(label => gradeColors[label] || '#9CA3AF');
                    
                    // Update chart data
                    window.gradeDistributionChart.data.labels = data.data.labels;
                    window.gradeDistributionChart.data.datasets[0].data = data.data.data;
                    window.gradeDistributionChart.data.datasets[0].backgroundColor = newBackgroundColors;
                    window.gradeDistributionChart.update('active');
                    
                    // Remove loading state
                    if (loadingDiv && loadingDiv.parentElement) {
                        loadingDiv.parentElement.removeChild(loadingDiv);
                    }
                    
                    console.log('Grade distribution chart updated for year:', year || 'all years');
                } else {
                    console.error('Error updating grade distribution chart:', data.error || 'Unknown error');
                    // Remove loading state even on error
                    if (loadingDiv && loadingDiv.parentElement) {
                        loadingDiv.parentElement.removeChild(loadingDiv);
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching grade distribution data:', error);
                // Remove loading state on error
                if (loadingDiv && loadingDiv.parentElement) {
                    loadingDiv.parentElement.removeChild(loadingDiv);
                }
            });
        };

        // Define updateStudentDistributionChart function globally
        window.updateStudentDistributionChart = function(filterType = 'overview') {
            if (!window.studentDistributionChart) return;
            
            // Show loading state
            const chartContainer = document.querySelector('#studentDistributionChart')?.parentElement;
            if (!chartContainer) return;
            
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'chart-loading';
            loadingDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-light);">📊 Loading chart data...</div>';
            chartContainer.style.position = 'relative';
            chartContainer.appendChild(loadingDiv);

            // Make AJAX request to get filtered student distribution data
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax=get_student_distribution&filter_type=' + encodeURIComponent(filterType)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && window.studentDistributionChart) {
                    // Update chart data
                    window.studentDistributionChart.data.labels = data.data.labels;
                    window.studentDistributionChart.data.datasets[0].data = data.data.data;
                    window.studentDistributionChart.data.datasets[0].backgroundColor = data.data.colors;
                    window.studentDistributionChart.data.datasets[0].borderColor = data.data.colors.map(color => color + 'CC');
                    window.studentDistributionChart.update('active');
                    
                    // Remove loading state
                    if (loadingDiv && loadingDiv.parentElement) {
                        loadingDiv.parentElement.removeChild(loadingDiv);
                    }
                    
                    console.log('Student distribution chart updated for:', filterType);
                } else {
                    console.error('Error updating student distribution chart:', data.error || 'Unknown error');
                    // Remove loading state even on error
                    if (loadingDiv && loadingDiv.parentElement) {
                        loadingDiv.parentElement.removeChild(loadingDiv);
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching student distribution data:', error);
                // Remove loading state on error
                if (loadingDiv && loadingDiv.parentElement) {
                    loadingDiv.parentElement.removeChild(loadingDiv);
                }
            });
        };

        // Chart creation functions
        function createGradeDistributionChart() {
            const gradeCtx = document.getElementById('performanceDistributionChart');
            if (!gradeCtx) return;
            
            // Get real grade distribution data from the database for Year 1 (default)
            <?php
            $initialGradeData = getGradeDistributionData($conn, 1);
            ?>
            const gradeLabels = <?= json_encode($initialGradeData['labels']) ?>;
            const gradeCounts = <?= json_encode($initialGradeData['data']) ?>;
            
            // Generate dynamic colors based on labels
            const gradeColors = {
                'A+': '#10B981', // Green
                'A': '#1e40af',  // Navy Blue  
                'B': '#F59E0B',  // Yellow
                'C': '#F97316',  // Orange
                'F': '#DC2626',  // Red
                'No Data': '#9CA3AF' // Gray for no data
            };
            const backgroundColors = gradeLabels.map(label => gradeColors[label] || '#9CA3AF');
            
            window.gradeDistributionChart = new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: gradeLabels,
                    datasets: [{
                        data: gradeCounts,
                        backgroundColor: backgroundColors,
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverBorderWidth: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                color: '#64748b',
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        datalabels: {
                            display: true,
                            color: '#ffffff',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 12,
                                weight: 'bold'
                            },
                            formatter: function(value, context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return percentage + '%';
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(37, 46, 69, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                            cornerRadius: 12,
                            padding: 16,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' students (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
        }

        function createSubjectPerformanceChart1() {
            const subjectCtx = document.getElementById('subjectPerformanceChart1');
            if (!subjectCtx) return;
            
            <?php
            // Get subject performance data for Year 1
            $subject_query_year1 = pg_query($conn, "
                SELECT 
                    sub.subject_name,
                    ROUND(AVG(m.mark), 1) as avg_mark
                FROM subjects sub
                LEFT JOIN marks m ON sub.id = m.subject_id
                LEFT JOIN students s ON m.student_id = s.id
                WHERE sub.year = 1 AND (s.status = 'active' OR s.id IS NULL)
                GROUP BY sub.id, sub.subject_name
                ORDER BY avg_mark DESC NULLS LAST
            ");

            $subject_labels_year1 = [];
            $subject_averages_year1 = [];

            if ($subject_query_year1 && pg_num_rows($subject_query_year1) > 0) {
                while($subject = pg_fetch_assoc($subject_query_year1)) {
                    $subject_labels_year1[] = $subject['subject_name'];
                    $subject_averages_year1[] = $subject['avg_mark'] ? (float)$subject['avg_mark'] : 0;
                }
            } else {
                // Fallback data
                $subject_labels_year1 = ['No Data'];
                $subject_averages_year1 = [0];
            }
            ?>
            
            const subjects = <?= json_encode($subject_labels_year1) ?>;
            const scores = <?= json_encode($subject_averages_year1) ?>;
            
            window.subjectPerformanceChart1 = new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: subjects,
                    datasets: [{
                        label: 'Average Score (Year 1)',
                        data: scores,
                        backgroundColor: 'rgba(30, 64, 175, 0.8)',
                        borderColor: '#1e40af',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            color: '#1e40af',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 11,
                                weight: 'bold'
                            },
                            formatter: function(value) {
                                return value + '%';
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(37, 46, 69, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                            cornerRadius: 12,
                            padding: 16
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: 'rgba(156, 163, 175, 0.2)',
                                lineWidth: 1
                            },
                            ticks: {
                                color: '#6b7280',
                                font: { family: "'Poppins', sans-serif", size: 11 }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: '#6b7280',
                                font: { family: "'Poppins', sans-serif", size: 11 },
                                maxRotation: 45
                            }
                        }
                    }
                }
            });
        }

        function createSubjectPerformanceChart2() {
            const subjectCtx = document.getElementById('subjectPerformanceChart2');
            if (!subjectCtx) return;
            
            <?php
            // Get subject performance data for Year 2
            $subject_query_year2 = pg_query($conn, "
                SELECT 
                    sub.subject_name,
                    ROUND(AVG(m.mark), 1) as avg_mark
                FROM subjects sub
                LEFT JOIN marks m ON sub.id = m.subject_id
                LEFT JOIN students s ON m.student_id = s.id
                WHERE sub.year = 2 AND (s.status = 'active' OR s.id IS NULL)
                GROUP BY sub.id, sub.subject_name
                ORDER BY avg_mark DESC NULLS LAST
            ");

            $subject_labels_year2 = [];
            $subject_averages_year2 = [];

            if ($subject_query_year2 && pg_num_rows($subject_query_year2) > 0) {
                while($subject = pg_fetch_assoc($subject_query_year2)) {
                    $subject_labels_year2[] = $subject['subject_name'];
                    $subject_averages_year2[] = $subject['avg_mark'] ? (float)$subject['avg_mark'] : 0;
                }
            } else {
                // Fallback data
                $subject_labels_year2 = ['No Data'];
                $subject_averages_year2 = [0];
            }
            ?>
            
            const subjects = <?= json_encode($subject_labels_year2) ?>;
            const scores = <?= json_encode($subject_averages_year2) ?>;
            
            window.subjectPerformanceChart2 = new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: subjects,
                    datasets: [{
                        label: 'Average Score (Year 2)',
                        data: scores,
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderColor: '#22c55e',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            color: '#22c55e',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 11,
                                weight: 'bold'
                            },
                            formatter: function(value) {
                                return value + '%';
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(37, 46, 69, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                            cornerRadius: 12,
                            padding: 16
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: 'rgba(156, 163, 175, 0.2)',
                                lineWidth: 1
                            },
                            ticks: {
                                color: '#6b7280',
                                font: { family: "'Poppins', sans-serif", size: 11 }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: '#6b7280',
                                font: { family: "'Poppins', sans-serif", size: 11 },
                                maxRotation: 45
                            }
                        }
                    }
                }
            });
        }

        function createTopPerformersChart1() {
            const topPerformersCtx = document.getElementById('topPerformersChart1');
            if (!topPerformersCtx) return;
            
            <?php
            // Get top 3 performers data for Year 1
            $top_performers_query_year1 = pg_query($conn, "
                SELECT 
                    s.name as student_name,
                    ROUND(AVG(m.mark), 1) as avg_mark,
                    COUNT(m.mark) as subjects_count
                FROM students s
                JOIN marks m ON s.id = m.student_id
                WHERE m.mark > 0 AND s.year = 1 AND s.status = 'active'
                GROUP BY s.id, s.name
                HAVING COUNT(m.mark) >= 2
                ORDER BY AVG(m.mark) DESC
                LIMIT 3
            ");
            
            $top_performers_names_year1 = [];
            $top_performers_scores_year1 = [];
            
            if ($top_performers_query_year1 && pg_num_rows($top_performers_query_year1) > 0) {
                while($performer = pg_fetch_assoc($top_performers_query_year1)) {
                    $top_performers_names_year1[] = $performer['student_name'];
                    $top_performers_scores_year1[] = (float)$performer['avg_mark'];
                }
            } else {
                // Fallback data
                $top_performers_names_year1 = ['No Data'];
                $top_performers_scores_year1 = [0];
            }
            ?>
            
            const topPerformersLabels = <?= json_encode($top_performers_names_year1) ?>;
            const topPerformersData = <?= json_encode($top_performers_scores_year1) ?>;
            
            // Generate colors for top 3 (Gold, Silver, Bronze)
            const rankColors = topPerformersData.map((score, index) => {
                if (index === 0) return '#FFD700'; // Gold for 1st
                if (index === 1) return '#C0C0C0'; // Silver for 2nd
                if (index === 2) return '#CD7F32'; // Bronze for 3rd
                return '#64FFDA'; // Fallback
            });
            
            window.topPerformersChart1 = new Chart(topPerformersCtx, {
                type: 'bar',
                data: {
                    labels: topPerformersLabels,
                    datasets: [{
                        label: 'Average Score (Year 1)',
                        data: topPerformersData,
                        backgroundColor: rankColors,
                        borderColor: rankColors.map(color => color + '80'),
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y', // This makes it horizontal
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: true,
                            color: '#ffffff',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 12,
                                weight: 'bold'
                            },
                            formatter: function(value, context) {
                                const rank = context.dataIndex + 1;
                                const rankEmoji = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '🏆';
                                return rankEmoji + ' ' + value + '%';
                            },
                            anchor: 'center',
                            align: 'center'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#e2e8f0',
                            bodyColor: '#cbd5e1',
                            borderColor: '#334155',
                            borderWidth: 1,
                            cornerRadius: 8,
                            titleFont: { family: "'Poppins', sans-serif", size: 13, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 12 },
                            callbacks: {
                                label: function(context) {
                                    const rank = context.dataIndex + 1;
                                    const rankEmoji = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '🏆';
                                    return `${rankEmoji} Rank ${rank}: ${context.parsed.x}% (Year 2)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(148, 163, 184, 0.1)' },
                            ticks: {
                                font: { family: "'Poppins', sans-serif", size: 10, weight: '500' },
                                color: '#8892b0',
                                callback: function(value) { return value + '%'; }
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { family: "'Poppins', sans-serif", size: 10, weight: '500' },
                                color: '#8892b0'
                            }
                        }
                    }
                }
            });
        }

        function createTopPerformersChart2() {
            const topPerformersCtx = document.getElementById('topPerformersChart2');
            if (!topPerformersCtx) return;
            
            <?php
            // Get top 3 performers data for Year 2
            $top_performers_query_year2 = pg_query($conn, "
                SELECT 
                    s.name as student_name,
                    ROUND(AVG(m.mark), 1) as avg_mark,
                    COUNT(m.mark) as subjects_count
                FROM students s
                JOIN marks m ON s.id = m.student_id
                WHERE m.mark > 0 AND s.year = 2 AND s.status = 'active'
                GROUP BY s.id, s.name
                HAVING COUNT(m.mark) >= 2
                ORDER BY AVG(m.mark) DESC
                LIMIT 3
            ");
            
            $top_performers_names_year2 = [];
            $top_performers_scores_year2 = [];
            
            if ($top_performers_query_year2 && pg_num_rows($top_performers_query_year2) > 0) {
                while($performer = pg_fetch_assoc($top_performers_query_year2)) {
                    $top_performers_names_year2[] = $performer['student_name'];
                    $top_performers_scores_year2[] = (float)$performer['avg_mark'];
                }
            } else {
                // Fallback data
                $top_performers_names_year2 = ['No Data'];
                $top_performers_scores_year2 = [0];
            }
            ?>
            
            const topPerformersLabels = <?= json_encode($top_performers_names_year2) ?>;
            const topPerformersData = <?= json_encode($top_performers_scores_year2) ?>;
            
            // Generate colors for top 3 (Gold, Silver, Bronze)
            const rankColors = topPerformersData.map((score, index) => {
                if (index === 0) return '#FFD700'; // Gold for 1st
                if (index === 1) return '#C0C0C0'; // Silver for 2nd
                if (index === 2) return '#CD7F32'; // Bronze for 3rd
                return '#64FFDA'; // Fallback
            });
            
            window.topPerformersChart2 = new Chart(topPerformersCtx, {
                type: 'bar',
                data: {
                    labels: topPerformersLabels,
                    datasets: [{
                        label: 'Average Score (Year 2)',
                        data: topPerformersData,
                        backgroundColor: rankColors,
                        borderColor: rankColors.map(color => color + '80'),
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y', // This makes it horizontal
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: true,
                            color: '#ffffff',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 12,
                                weight: 'bold'
                            },
                            formatter: function(value, context) {
                                const rank = context.dataIndex + 1;
                                const rankEmoji = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '🏆';
                                return rankEmoji + ' ' + value + '%';
                            },
                            anchor: 'center',
                            align: 'center'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#e2e8f0',
                            bodyColor: '#cbd5e1',
                            borderColor: '#334155',
                            borderWidth: 1,
                            cornerRadius: 8,
                            titleFont: { family: "'Poppins', sans-serif", size: 13, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 12 },
                            callbacks: {
                                label: function(context) {
                                    const rank = context.dataIndex + 1;
                                    const rankEmoji = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '🏆';
                                    return `${rankEmoji} Rank ${rank}: ${context.parsed.x}% (Year 2)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(148, 163, 184, 0.1)' },
                            ticks: {
                                font: { family: "'Poppins', sans-serif", size: 10, weight: '500' },
                                color: '#8892b0',
                                callback: function(value) { return value + '%'; }
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { family: "'Poppins', sans-serif", size: 10, weight: '500' },
                                color: '#8892b0'
                            }
                        }
                    }
                }
            });
        }

        function createStudentDistributionChart() {
            const distributionCtx = document.getElementById('studentDistributionChart');
            if (!distributionCtx) return;
            
            // Get student distribution data
            const distributionLabels = <?= json_encode($chartData['student_distribution']['labels'] ?? []) ?>;
            const distributionData = <?= json_encode($chartData['student_distribution']['data'] ?? []) ?>;
            const distributionColors = <?= json_encode($chartData['student_distribution']['colors'] ?? []) ?>;
            
            window.studentDistributionChart = new Chart(distributionCtx, {
                type: 'pie',
                data: {
                    labels: distributionLabels,
                    datasets: [{
                        data: distributionData,
                        backgroundColor: distributionColors,
                        borderColor: distributionColors.map(color => color),
                        borderWidth: 2,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    family: "'Poppins', sans-serif",
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#64748B'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(17, 24, 39, 0.9)',
                            titleColor: '#F9FAFB',
                            bodyColor: '#F9FAFB',
                            borderColor: '#374151',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                            titleFont: {
                                family: "'Poppins', sans-serif",
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                family: "'Poppins', sans-serif",
                                size: 12,
                                weight: '400'
                            },
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return `${context.label}: ${context.parsed} (${percentage}%)`;
                                }
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            font: {
                                family: "'Poppins', sans-serif",
                                size: 12,
                                weight: '600'
                            },
                            formatter: function(value, context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                if (value > 0) {
                                    const label = context.chart.data.labels[context.dataIndex];
                                    return `${label}\n${percentage}%`;
                                }
                                return '';
                            }
                        }
                    }
                }
            });
        }

        // Register Chart.js datalabels plugin
        Chart.register(ChartDataLabels);

        // Chart initialization with real data
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing premium charts...');

            // Get current page
            const currentPage = new URLSearchParams(window.location.search).get('page') || 'reports';
            console.log('Current page:', currentPage);

            // Year Filter Functionality - ONLY for Reports page
            const yearFilterInputs = document.querySelectorAll('input[name="year-filter"]');
            
            function updateKPICards(year = '') {
                console.log('updateKPICards called with year:', year);
                
                // Show loading state
                const kpiCards = document.querySelectorAll('.kpi-card');
                kpiCards.forEach(card => {
                    card.style.opacity = '0.6';
                    card.style.pointerEvents = 'none';
                });

                // Make AJAX request to get filtered KPI data
                fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'ajax=get_kpis&filter_year=' + encodeURIComponent(year)
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers.get('content-type'));
                    return response.text(); // Get as text first to debug
                })
                .then(text => {
                    console.log('Raw response:', text);
                    try {
                        const data = JSON.parse(text);
                        console.log('Parsed JSON:', data);
                        
                        if (data.success) {
                            const kpis = data.kpis;
                            console.log('KPI data received:', kpis);
                            
                            // Update KPI values with animation
                            const kpiUpdates = [
                                { selector: '.kpi-card:nth-child(1) .kpi-value', value: kpis.year1_students, suffix: '', prefix: '' },
                                { selector: '.kpi-card:nth-child(2) .kpi-value', value: kpis.year2_students, suffix: '', prefix: '' },
                                { selector: '.kpi-card:nth-child(3) .kpi-value', value: kpis.avg_score, suffix: '%', prefix: '' },
                                { selector: '.kpi-card:nth-child(4) .kpi-value', value: kpis.top_class, suffix: '', prefix: 'Class ', isText: true },
                                { selector: '.kpi-card:nth-child(4) .kpi-trend', value: kpis.top_class_score, suffix: '% score', prefix: '', isSmall: true },
                                { selector: '.kpi-card:nth-child(5) .kpi-value', value: kpis.pass_rate, suffix: '%', prefix: '' },
                                { selector: '.kpi-card:nth-child(6) .kpi-value', value: kpis.risk_subject, suffix: '', prefix: '', isText: true },
                                { selector: '.kpi-card:nth-child(6) .kpi-trend', value: kpis.risk_failure_rate, suffix: '% fail rate', prefix: '', isSmall: true },
                                { selector: '.kpi-card:nth-child(7) .kpi-value', value: kpis.enrolled_students, suffix: '', prefix: '' },
                                { selector: '.kpi-card:nth-child(8) .kpi-value', value: kpis.excellence_rate, suffix: '%', prefix: '' }
                            ];

                            kpiUpdates.forEach(item => {
                                const element = document.querySelector(item.selector);
                                if (element) {
                                    // For text values (like subject names or class names), just update directly
                                    if (item.isText) {
                                        element.textContent = item.prefix + item.value + item.suffix;
                                    } else {
                                        // For numeric values, use CountUp animation
                                        const numericValue = parseFloat(item.value);
                                        if (!isNaN(numericValue)) {
                                            if (element.countUpInstance) {
                                                // Update existing counter
                                                element.countUpInstance.update(numericValue, {
                                                    duration: item.isSmall ? 1200 : 1800,
                                                    suffix: item.suffix,
                                                    prefix: item.prefix
                                                });
                                            } else {
                                                // Create new counter
                                                const counter = new CountUp(element, {
                                                    target: numericValue,
                                                    start: 0,
                                                    duration: item.isSmall ? 1200 : 1800,
                                                    decimals: item.value.toString().includes('.') ? 1 : 0,
                                                    separator: '',
                                                    suffix: item.suffix,
                                                    prefix: item.prefix
                                                });
                                                element.countUpInstance = counter;
                                                counter.startAnimation();
                                            }
                                        } else {
                                            element.textContent = item.prefix + item.value + item.suffix;
                                        }
                                    }
                                }
                            });

                            // Restore card state
                            kpiCards.forEach(card => {
                                card.style.opacity = '1';
                                card.style.pointerEvents = 'auto';
                            });
                        } else {
                            console.error('Error updating KPIs:', data.error || 'Unknown error');
                            // Restore card state even on error
                            kpiCards.forEach(card => {
                                card.style.opacity = '1';
                                card.style.pointerEvents = 'auto';
                            });
                        }
                    } catch (parseError) {
                        console.error('JSON parse error:', parseError);
                        console.error('Response was not valid JSON:', text);
                        // Restore card state even on error
                        kpiCards.forEach(card => {
                            card.style.opacity = '1';
                            card.style.pointerEvents = 'auto';
                        });
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    // Restore card state even on error
                    kpiCards.forEach(card => {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    });
                });
            }

            // Add event listeners to year filter inputs - ONLY for Reports page
            if (currentPage === 'reports' && yearFilterInputs.length > 0) {
                yearFilterInputs.forEach(input => {
                    input.addEventListener('change', function() {
                        if (this.checked) {
                            updateKPICards(this.value);
                            updateGradeDistributionChart(this.value);
                        }
                    });
                });

                // Load All Years data by default since it's the default selected filter
                updateKPICards('');
                updateGradeDistributionChart('');
            }
            
            // Grade Distribution Chart (Doughnut)
            createGradeDistributionChart();
            console.log('Grade distribution chart created');

            // Subject Performance Charts (Bar) - Real Data by Year
            createSubjectPerformanceChart1();
            console.log('Subject performance chart Year 1 created');
            createSubjectPerformanceChart2();
            console.log('Subject performance chart Year 2 created');

            // Top 3 Performers Charts (Horizontal Bar) - Real Data by Year
            createTopPerformersChart1();
            console.log('Top 3 performers chart Year 1 created');
            createTopPerformersChart2();
            console.log('Top 3 performers chart Year 2 created');

            // Student Distribution Chart (Pie) - Real Data
            createStudentDistributionChart();
            console.log('Student distribution chart created');

            // Performance Trend Chart (Line) - Real Data
            const trendCtx = document.getElementById('performanceTrendChart');
            if (trendCtx) {
                <?php
                // Get real monthly trend data from marks grouped by class
                $trend_query = pg_query($conn, "
                    SELECT 
                        s.class_level,
                        ROUND(AVG(m.mark), 1) as avg_mark,
                        COUNT(m.mark) as count_marks
                    FROM marks m
                    JOIN students s ON m.student_id = s.id
                    GROUP BY s.class_level
                    ORDER BY s.class_level
                ");
                
                $trend_labels = [];
                $trend_values = [];
                
                if ($trend_query && pg_num_rows($trend_query) > 0) {
                    while($trend = pg_fetch_assoc($trend_query)) {
                        $trend_labels[] = $trend['class_level'];
                        $trend_values[] = (float)$trend['avg_mark'];
                    }
                } else {
                    // Fallback: Generate monthly labels with zero data
                    for($i = 11; $i >= 0; $i--) {
                        $date = new DateTime();
                        $date->sub(new DateInterval('P' . $i . 'M'));
                        $trend_labels[] = $date->format('M Y');
                        $trend_values[] = 0;
                    }
                }
                ?>
                
                const months = <?= json_encode($trend_labels) ?>;
                const trendData = <?= json_encode($trend_values) ?>;
                
                function createPerformanceTrendsChart() {
                    const trendCtx = document.getElementById('performanceTrendChart');
                    if (!trendCtx) return;
                    
                    window.performanceTrendsChart = new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Average Performance',
                            data: trendData,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#667eea',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 3,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            borderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                cornerRadius: 12,
                                padding: 16
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: 50,
                                max: 100,
                                grid: {
                                    color: 'rgba(151, 166, 195, 0.15)',
                                    drawBorder: false
                                },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#8892b0',
                                    callback: function(value) { return value + '%'; }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#8892b0'
                                }
                            }
                        }
                    }
                });
                }
                
                // Create initial chart
                createPerformanceTrendsChart();
                console.log('Performance trend chart created');
            }

            console.log('All premium charts initialized successfully!');
            
            // Initialize schedule with Year 1 data
            if (typeof window.scheduleData !== 'undefined' && window.scheduleData) {
                updateScheduleYear('1');
                console.log('Schedule initialized with Year 1 data');
            }
            
            // Initialize Teacher Dashboard Charts
            const teacherDashboardPage = document.querySelector('.content-wrapper');
            if (teacherDashboardPage && window.location.search.includes('page=teacher_dashboard')) {
                initializeTeacherDashboardCharts();
            }
        });
        
        // Teacher Dashboard Chart Initialization
        function initializeTeacherDashboardCharts() {
            <?php
            // Get data for teacher dashboard charts
            $spec_query = "SELECT specialization, COUNT(*) as count FROM teachers WHERE specialization IS NOT NULL GROUP BY specialization ORDER BY count DESC";
            $spec_result = pg_query($conn, $spec_query);
            $specializations = [];
            $spec_counts = [];
            while ($row = pg_fetch_assoc($spec_result)) {
                $specializations[] = $row['specialization'];
                $spec_counts[] = (int)$row['count'];
            }
            
            $degree_query = "SELECT degree, COUNT(*) as count FROM teachers WHERE degree IS NOT NULL GROUP BY degree ORDER BY count DESC";
            $degree_result = pg_query($conn, $degree_query);
            $degrees = [];
            $degree_counts = [];
            while ($row = pg_fetch_assoc($degree_result)) {
                $degrees[] = $row['degree'];
                $degree_counts[] = (int)$row['count'];
            }
            
            $login_active = pg_fetch_assoc(pg_query($conn, "SELECT COUNT(*) as count FROM teachers WHERE username IS NOT NULL AND password IS NOT NULL"))['count'];
            $login_inactive = pg_fetch_assoc(pg_query($conn, "SELECT COUNT(*) as count FROM teachers WHERE username IS NULL OR password IS NULL"))['count'];
            
            // Teachers by Subject Query
            $teachers_by_subject_query = "SELECT s.subject_name, COUNT(DISTINCT ts.teacher_id) as teacher_count 
                                         FROM subjects s 
                                         LEFT JOIN teacher_subjects ts ON s.id = ts.subject_id 
                                         GROUP BY s.id, s.subject_name 
                                         ORDER BY teacher_count DESC, s.subject_name ASC";
            $teachers_by_subject_result = pg_query($conn, $teachers_by_subject_query);
            $subject_names = [];
            $teachers_per_subject = [];
            while ($row = pg_fetch_assoc($teachers_by_subject_result)) {
                $subject_names[] = $row['subject_name'];
                $teachers_per_subject[] = (int)$row['teacher_count'];
            }
            ?>
            
            // Teacher Specialization Chart
            const specCtx = document.getElementById('teacherSpecializationChart');
            if (specCtx) {
                new Chart(specCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($specializations) ?>,
                        datasets: [{
                            data: <?= json_encode($spec_counts) ?>,
                            backgroundColor: [
                                '#1e40af', '#10b981', '#f59e0b', '#8b5cf6',
                                '#ef4444', '#ec4899', '#14b8a6', '#f97316',
                                '#6366f1', '#84cc16', '#a855f7', '#06b6d4'
                            ],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    font: { family: "'Poppins', sans-serif", size: 12 },
                                    padding: 15,
                                    color: '#334155'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                padding: 12,
                                cornerRadius: 8
                            }
                        }
                    }
                });
            }
            
            // Teacher Degree Chart
            const degreeCtx = document.getElementById('teacherDegreeChart');
            if (degreeCtx) {
                new Chart(degreeCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($degrees) ?>,
                        datasets: [{
                            label: 'Teachers',
                            data: <?= json_encode($degree_counts) ?>,
                            backgroundColor: 'rgba(30, 64, 175, 0.8)',
                            borderColor: 'rgba(30, 64, 175, 1)',
                            borderWidth: 2,
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                padding: 12,
                                cornerRadius: 8
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 11 },
                                    color: '#64748b',
                                    stepSize: 1
                                },
                                grid: { color: 'rgba(0,0,0,0.05)' }
                            },
                            x: {
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 10 },
                                    color: '#64748b'
                                },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }
            
            // Teachers by Subject Chart (Radar Chart)
            const teachersBySubjectCtx = document.getElementById('teachersBySubjectChart');
            if (teachersBySubjectCtx) {
                new Chart(teachersBySubjectCtx, {
                    type: 'radar',
                    data: {
                        labels: <?= json_encode($subject_names) ?>,
                        datasets: [{
                            label: 'Teachers Assigned',
                            data: <?= json_encode($teachers_per_subject) ?>,
                            backgroundColor: 'rgba(30, 64, 175, 0.2)',
                            borderColor: '#1e40af',
                            borderWidth: 3,
                            pointBackgroundColor: '#1e40af',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: '#1e40af',
                            pointHoverBorderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { 
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        const value = context.parsed.r;
                                        return value + ' teacher' + (value !== 1 ? 's' : '');
                                    }
                                }
                            }
                        },
                        scales: {
                            r: {
                                beginAtZero: true,
                                min: 0,
                                max: Math.max(...<?= json_encode($teachers_per_subject) ?>) + 1,
                                ticks: {
                                    stepSize: 1,
                                    font: { family: "'Poppins', sans-serif", size: 11 },
                                    color: '#64748b',
                                    backdropColor: 'transparent'
                                },
                                pointLabels: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '600' },
                                    color: '#334155'
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                angleLines: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            }
                        }
                    }
                });
            }
            
            console.log('Teacher dashboard charts initialized successfully!');
        }

        // Schedule functions
        function showClassSchedule(classLetter) {
            // Hide all schedule tables
            const schedules = document.querySelectorAll('.schedule-table');
            schedules.forEach(schedule => schedule.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.class-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected schedule
            const selectedSchedule = document.getElementById(`schedule-${classLetter}`);
            if (selectedSchedule) {
                selectedSchedule.classList.add('active');
            }
            
            // Add active class to clicked tab
            const clickedTab = event.target;
            if (clickedTab) {
                clickedTab.classList.add('active');
            }
        }

        // Function to update schedule year
        function updateScheduleYear(year) {
            // Store current year
            window.currentScheduleYear = year;
            
            // Update all schedule tables with new year data
            const classes = ['A', 'B', 'C'];
            
            classes.forEach(className => {
                const scheduleDiv = document.getElementById(`schedule-${className}`);
                if (scheduleDiv && window.scheduleData && window.scheduleData[year] && window.scheduleData[year][className]) {
                    // Find the tbody in this schedule
                    const tbody = scheduleDiv.querySelector('tbody');
                    if (tbody) {
                        // Clear existing content
                        tbody.innerHTML = '';
                        
                        // Rebuild the schedule for this class
                        window.days.forEach(day => {
                            const row = document.createElement('tr');
                            
                            // Day column
                            const dayCell = document.createElement('td');
                            dayCell.className = 'time-period';
                            dayCell.textContent = day;
                            dayCell.setAttribute('data-translate', day.toLowerCase());
                            row.appendChild(dayCell);
                            
                            // Schedule column
                            const scheduleCell = document.createElement('td');
                            scheduleCell.className = 'day-schedule';
                            
                            const daySchedule = window.scheduleData[year][className][day];
                            if (daySchedule) {
                                daySchedule.forEach(slot => {
                                    const slotDiv = document.createElement('div');
                                    slotDiv.className = 'schedule-slot';
                                    
                                    if (slot[1] === 'Break') {
                                        slotDiv.classList.add('break-cell');
                                    } else {
                                        // Add subject-specific class
                                        const subjectClass = getSubjectClassJS(slot[1]);
                                        if (subjectClass) {
                                            slotDiv.classList.add(subjectClass);
                                        }
                                    }
                                    
                                    const timeSpan = document.createElement('span');
                                    timeSpan.className = 'time';
                                    timeSpan.textContent = slot[0];
                                    
                                    const subjectSpan = document.createElement('span');
                                    subjectSpan.className = 'subject';
                                    subjectSpan.textContent = slot[1];
                                    if (slot[1] === 'Break') {
                                        subjectSpan.setAttribute('data-translate', 'break');
                                    }
                                    
                                    slotDiv.appendChild(timeSpan);
                                    slotDiv.appendChild(subjectSpan);
                                    scheduleCell.appendChild(slotDiv);
                                });
                            }
                            
                            row.appendChild(scheduleCell);
                            tbody.appendChild(row);
                        });
                    }
                }
            });
            
            // Update the select dropdown value
            const selectElement = document.getElementById('scheduleYearFilter');
            if (selectElement) {
                selectElement.value = year;
            }
            
            console.log(`Schedule switched to Year ${year}`);
        }
        
        // Helper function to get subject CSS class
        function getSubjectClassJS(subject) {
            const subjectMap = {
                'Basic C++': 'subject-computer',
                'Basics of Principle Statistics': 'subject-math',
                'Computer Essentials': 'subject-computer',
                'English': 'subject-english',
                'MIS': 'subject-history',
                'Advanced C++': 'subject-computer',
                'Database': 'subject-computer',
                'Web Development': 'subject-computer',
                'Human Resource Management': 'subject-history'
            };
            return subjectMap[subject] || 'subject-cell';
        }

        // Toggle student details (expandable rows)
        function toggleStudentDetails(studentId) {
            const mainRow = document.querySelector(`.student-main-row[data-student-id="${studentId}"]`);
            const detailsRow = document.getElementById(`details-${studentId}`);
            
            if (!mainRow || !detailsRow) {
                console.error('Student row not found:', studentId);
                return;
            }
            
            // Toggle expanded class on main row
            mainRow.classList.toggle('expanded');
            
            // Toggle show class on details row
            detailsRow.classList.toggle('show');
            
            // Optional: Close other expanded rows (uncomment if you want accordion behavior)
            // document.querySelectorAll('.student-main-row.expanded').forEach(row => {
            //     if (row.dataset.studentId != studentId) {
            //         row.classList.remove('expanded');
            //         document.getElementById(`details-${row.dataset.studentId}`).classList.remove('show');
            //     }
            // });
        }
        
        // Collapse all expanded marks panels
        function collapseAllMarks() {
            // Remove expanded class from all main rows
            document.querySelectorAll('.student-main-row.expanded').forEach(row => {
                row.classList.remove('expanded');
            });
            
            // Remove show class from all details rows
            document.querySelectorAll('.subject-details-row.show').forEach(row => {
                row.classList.remove('show');
            });
        }
        
        // Collapse all expanded reports panels (for Analytics/Dashboard page)
        function collapseAllReports() {
            // Remove expanded class from all main rows
            document.querySelectorAll('.student-main-row.expanded').forEach(row => {
                row.classList.remove('expanded');
            });
            
            // Remove show class from all details rows
            document.querySelectorAll('.subject-details-row.show').forEach(row => {
                row.classList.remove('show');
            });
        }

        // Loading overlay functions
        function showLoading(message = 'Processing', subtext = 'Please wait while we update your data') {
            const overlay = document.getElementById('loadingOverlay');
            const textElement = overlay.querySelector('.loading-text');
            const subtextElement = overlay.querySelector('.loading-subtext');
            
            if (textElement) {
                textElement.innerHTML = message + '<span class="loading-dots"></span>';
            }
            if (subtextElement) {
                subtextElement.textContent = subtext;
            }
            
            overlay.classList.add('active');
        }

        function hideLoading() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('active');
        }

        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = type === 'success' ? 'success-message' : 'error-message';
            notification.style.marginBottom = '1rem';
            notification.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span>${type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>'} ${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: transparent; border: none; color: white; font-size: 1.2rem; cursor: pointer; padding: 0 0.5rem;">×</button>
                </div>
            `;
            
            container.appendChild(notification);
            
            // Auto-remove after 3 seconds (faster)
            setTimeout(() => {
                notification.style.animation = 'slideInDown 0.3s ease reverse';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // AJAX form submission helper
        function submitFormAjax(form, onSuccess) {
            showLoading('Saving Changes', 'Please wait...');
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                hideLoading();
                
                // Check if response indicates success
                if (data.includes('success') || data.includes('successfully')) {
                    showNotification('Operation completed successfully!', 'success');
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                } else if (data.includes('error') || data.includes('Error')) {
                    showNotification('An error occurred. Please try again.', 'error');
                } else {
                    // Default: assume success and reload table
                    showNotification('Changes saved!', 'success');
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Network error. Please check your connection.', 'error');
            });
            
            return false; // Prevent default form submission
        }

        // Form submit handler with page-specific reload
        function handleFormSubmit(event, form, page = null, onSuccess = null) {
            event.preventDefault();
            
            console.log('Form submitted:', form);
            console.log('Action:', new FormData(form).get('action'));
            
            showLoading('Saving Changes', 'Please wait...');
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(data => {
                console.log('Server response:', data);
                hideLoading();
                
                // Check for success/error in response
                const success = data.includes('successfully') || data.includes('Success');
                const error = data.includes('Error:') || data.includes('error');
                
                console.log('Success:', success, 'Error:', error);
                
                if (success) {
                    showNotification('✓ Saved successfully!', 'success');
                    
                    // Close modal after success if callback provided
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                    
                    // Reset form if it's an add form
                    if (formData.get('action').includes('add')) {
                        form.reset();
                        
                        // Clear subject checkboxes
                        form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                    }
                    
                    // Instead of reload, fetch and update the specific section
                    if (page) {
                        fetchAndUpdateSection(page);
                    }
                } else if (error) {
                    // Extract error message if possible
                    const errorMatch = data.match(/Error: ([^<]+)/);
                    const message = errorMatch ? errorMatch[1] : 'An error occurred';
                    showNotification(message, 'error');
                } else {
                    // Assume success
                    showNotification('✓ Updated!', 'success');
                    
                    // Close modal after success if callback provided
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                    
                    // Reload page for updates to show
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Network error. Please try again.', 'error');
            });
            
            return false;
        }

        // Fetch and update section without full page refresh
        function fetchAndUpdateSection(page = null) {
            const currentPage = page || new URLSearchParams(window.location.search).get('page') || 'reports';
            
            showLoading('Updating Data', 'Please wait...');
            
            fetch(`?page=${currentPage}`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update table based on page
                    let tableId = '';
                    if (currentPage === 'marks') tableId = 'marksTable';
                    else if (currentPage === 'students') tableId = 'studentsTable';
                    else if (currentPage === 'subjects') tableId = 'subjectsTable';
                    else if (currentPage === 'teachers') tableId = 'teachersTable';
                    else if (currentPage === 'graduated') tableId = 'graduatedTable';
                    
                    if (tableId) {
                        const newTable = doc.getElementById(tableId);
                        const oldTable = document.getElementById(tableId);
                        
                        if (newTable && oldTable) {
                            // Only update tbody to preserve table structure
                            const newTbody = newTable.querySelector('tbody');
                            const oldTbody = oldTable.querySelector('tbody');
                            
                            if (newTbody && oldTbody) {
                                oldTbody.innerHTML = newTbody.innerHTML;
                            }
                        }
                    }
                    
                    // Update KPI cards on reports page
                    if (currentPage === 'reports') {
                        const kpiCards = doc.querySelectorAll('.kpi-card');
                        kpiCards.forEach((newCard, index) => {
                            const oldCard = document.querySelectorAll('.kpi-card')[index];
                            if (oldCard) {
                                const newValue = newCard.querySelector('.kpi-value');
                                const oldValue = oldCard.querySelector('.kpi-value');
                                if (newValue && oldValue) {
                                    oldValue.textContent = newValue.textContent;
                                }
                            }
                        });
                    }
                    
                    hideLoading();
                })
                .catch(error => {
                    hideLoading();
                    console.error('Update error:', error);
                });
        }

        // Reload table data without full page refresh
        function reloadTableData(page = null) {
            const currentPage = page || new URLSearchParams(window.location.search).get('page') || 'reports';
            
            showLoading('Refreshing Data', 'Loading latest information...');
            
            fetch(`?page=${currentPage}`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Find the table in the response
                    let tableId = '';
                    if (currentPage === 'marks') tableId = 'marksTable';
                    else if (currentPage === 'students') tableId = 'studentsTable';
                    else if (currentPage === 'subjects') tableId = 'subjectsTable';
                    else if (currentPage === 'reports') tableId = 'reportsTable';
                    else if (currentPage === 'graduated') tableId = 'graduatedTable';
                    
                    if (tableId) {
                        const newTable = doc.getElementById(tableId);
                        const oldTable = document.getElementById(tableId);
                        
                        if (newTable && oldTable) {
                            oldTable.innerHTML = newTable.innerHTML;
                        }
                    }
                    
                    hideLoading();
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error reloading data:', error);
                    showNotification('Failed to reload data. Please refresh the page.', 'error');
                });
        }

        // Filter functions
        function applyFilters() {
            const classFilter = document.getElementById('class-filter').value;
            const subjectFilter = document.getElementById('subject-filter').value;
            const dateFrom = document.getElementById('date-from').value;
            const dateTo = document.getElementById('date-to').value;

            const formData = new FormData();
            formData.append('action', 'filter_reports');
            formData.append('class_filter', classFilter);
            formData.append('subject_filter', subjectFilter);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateTable(data.data);
                } else {
                    console.error('Filter error:', data.message);
                }
            })
            .catch(error => {
                console.error('Error applying filters:', error);
            });
        }

        function clearAllFilters() {
            document.getElementById('class-filter').value = '';
            document.getElementById('subject-filter').value = '';
            document.getElementById('date-from').value = '';
            document.getElementById('date-to').value = '';
            location.reload(); // Simple way to reset the table
        }

        function updateTable(data) {
            const tbody = document.getElementById('reportsTableBody');
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: var(--text-light); padding: 2rem;">No data found for the selected filters.</td></tr>';
                return;
            }

            data.forEach(row => {
                const gradeClass = 'grade-' + (row.grade ? row.grade.toLowerCase().replace('+', '-plus') : 'f');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row.student_name || ''}</td>
                    <td>${row.class_level || ''}</td>
                    <td>${row.subject_name || ''}</td>
                    <td>${row.final_mark || '0'}</td>
                    <td>${row.midterm_mark || '0'}</td>
                    <td>${row.quizzes_mark || '0'}</td>
                    <td>${row.daily_mark || '0'}</td>
                    <td><strong>${row.total_mark || '0'}</strong></td>
                    <td><strong style="color: #3498db;">${row.final_grade ? parseFloat(row.final_grade).toFixed(2) : '0.00'}</strong></td>
                    <td><span class="grade-badge ${gradeClass}">${row.grade || 'F'}</span></td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Export functions
        function exportData(format) {
            if (format === 'csv') {
                // Determine which table to export based on current page
                let table = null;
                let filename = 'export.csv';
                
                // Check which page we're on and get the appropriate table
                if (document.getElementById('marksTable')) {
                    table = document.getElementById('marksTable');
                    filename = 'marks_export.csv';
                } else if (document.getElementById('studentsTable')) {
                    table = document.getElementById('studentsTable');
                    filename = 'students_export.csv';
                } else if (document.getElementById('subjectsTable')) {
                    table = document.getElementById('subjectsTable');
                    filename = 'subjects_export.csv';
                } else if (document.getElementById('reportsTable')) {
                    table = document.getElementById('reportsTable');
                    filename = 'reports_export.csv';
                } else if (document.querySelector('.graduated-section table')) {
                    table = document.querySelector('.graduated-section table');
                    filename = 'graduated_students_export.csv';
                }
                
                if (!table) {
                    alert('No table found to export');
                    return;
                }
                
                const rows = table.querySelectorAll('tr');
                let csvContent = '';
                
                rows.forEach(row => {
                    // Skip hidden rows (filtered out)
                    if (row.style.display === 'none') return;
                    
                    const cols = row.querySelectorAll('td, th');
                    const rowData = [];
                    cols.forEach(col => {
                        let cellText = col.textContent.trim();
                        // Clean up text and remove extra whitespace
                        cellText = cellText.replace(/\s+/g, ' ');
                        // Remove emojis and special characters for cleaner CSV
                        cellText = cellText.replace(/[^\w\s.,@-]/g, '');
                        rowData.push(`"${cellText}"`);
                    });
                    if (rowData.length > 0) {
                        csvContent += rowData.join(',') + '\n';
                    }
                });
                
                if (csvContent.trim() === '') {
                    alert('No data to export');
                    return;
                }
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('hidden', '');
                a.setAttribute('href', url);
                a.setAttribute('download', filename);
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                // Show success message
                const timestamp = new Date().toLocaleString();
                alert(`CSV exported successfully!\nFile: ${filename}\nTime: ${timestamp}`);
            }
        }

        // =====================================================
        // UNIFIED PRINT SYSTEM - Professional Report Format
        // =====================================================
        // All print functions use the same professional template
        // with consistent header, styling, and branding
        // =====================================================

        // Unified print template generator for all pages
        function generatePrintTemplate(title, content, additionalStyles = '') {
            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>${title}</title>
                    <style>
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        
                        body {
                            font-family: Arial, sans-serif;
                            line-height: 1.5;
                            color: #000;
                            background: #fff;
                            padding: 20mm;
                        }
                        
                        .report-header {
                            text-align: center;
                            margin-bottom: 30px;
                            padding-bottom: 15px;
                            border-bottom: 2px solid #000;
                        }
                        
                        .report-header h1 {
                            font-size: 24px;
                            font-weight: bold;
                            margin-bottom: 8px;
                            text-transform: uppercase;
                            letter-spacing: 1px;
                        }
                        
                        .report-meta {
                            font-size: 11px;
                            color: #333;
                            line-height: 1.6;
                        }
                        
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-bottom: 20px;
                        }
                        
                        th {
                            background: #f0f0f0;
                            border: 1px solid #000;
                            padding: 10px 8px;
                            text-align: left;
                            font-size: 11px;
                            font-weight: bold;
                            text-transform: uppercase;
                        }
                        
                        td {
                            border: 1px solid #666;
                            padding: 8px;
                            text-align: left;
                            font-size: 11px;
                        }
                        
                        tbody tr:nth-child(even) {
                            background: #fafafa;
                        }
                        
                        tbody tr:hover {
                            background: #f5f5f5;
                        }
                        
                        .failed-grade {
                            color: #dc2626;
                            font-weight: bold;
                        }
                        
                        .failed-total {
                            color: #dc2626;
                            text-decoration: underline;
                            font-weight: bold;
                        }
                        
                        .status-pass {
                            color: #16a34a;
                            font-weight: bold;
                        }
                        
                        .status-fail {
                            color: #dc2626;
                            font-weight: bold;
                        }
                        
                        .page-break {
                            page-break-after: always;
                        }
                        
                        .no-print {
                            display: none;
                        }
                        
                        @media print {
                            body {
                                padding: 10mm;
                            }
                            
                            .page-break {
                                page-break-after: always;
                            }
                            
                            @page {
                                margin: 15mm;
                                size: A4;
                            }
                        }
                        
                        ${additionalStyles}
                    </style>
                </head>
                <body>
                    <div class="report-header">
                        <h1>${title}</h1>
                        <div class="report-meta">
                            Generated on: ${new Date().toLocaleString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric', 
                                hour: '2-digit', 
                                minute: '2-digit'
                            })}<br>
                            Student Management System
                        </div>
                    </div>
                    
                    ${content}
                    
                    <script>
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                            }, 500);
                        };
                        
                        window.onafterprint = function() {
                            window.close();
                        };
                    <\/script>
                </body>
                </html>
            `;
        }

        function printTable() {
            // Check if this is the Analytics Report (reports page)
            if (document.getElementById('reportsTable')) {
                printAnalyticsReport();
                return;
            }
            
            // Check if this is the Marks page
            if (document.getElementById('marksTable')) {
                printMarksReport();
                return;
            }
            
            // Get the current page's table for printing (other pages)
            let table = null;
            let pageTitle = 'Data Export';
            let isStudentsPage = false;
            
            if (document.getElementById('studentsTable')) {
                table = document.getElementById('studentsTable');
                pageTitle = 'Students List';
                isStudentsPage = true;
            } else if (document.getElementById('subjectsTable')) {
                table = document.getElementById('subjectsTable');
                pageTitle = 'Subjects List';
            } else if (document.querySelector('.graduated-section table')) {
                table = document.querySelector('.graduated-section table');
                pageTitle = 'Graduated Students Report';
            }
            
            if (!table) {
                alert('No table found to print');
                return;
            }
            
            // For Students page, create custom formatted content
            if (isStudentsPage) {
                printStudentsReport();
                return;
            }
            
            // Clone the table for other pages
            const tableClone = table.cloneNode(true);
            
            // Remove hidden rows and action columns
            const rows = tableClone.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.remove();
                    return;
                }
                
                // Remove "Actions" column header and cells
                const cells = row.querySelectorAll('th, td');
                cells.forEach((cell, index) => {
                    if (cell.textContent.includes('Actions') || cell.querySelector('button')) {
                        cell.remove();
                    }
                });
            });
            
            // Generate content HTML
            const content = tableClone.outerHTML;
            
            // Additional styles specific to table printing
            const additionalStyles = `
                table {
                    font-size: 10px;
                }
                
                th {
                    background: #000 !important;
                    color: #fff !important;
                    border: 1px solid #000 !important;
                }
                
                td {
                    vertical-align: top;
                }
                
                .status-badge {
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 9px;
                    font-weight: bold;
                }
            `;
            
            // Create print window with unified template
            const printWindow = window.open('', '_blank');
            const printHTML = generatePrintTemplate(pageTitle, content, additionalStyles);
            
            printWindow.document.write(printHTML);
            printWindow.document.close();
        }
        
        // Custom print function for Students page
        function printStudentsReport() {
            const rows = document.querySelectorAll('#studentsTable tbody tr');
            const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
            
            if (visibleRows.length === 0) {
                alert('No students to print');
                return;
            }
            
            // Get current filter values to generate report description
            const yearFilter = document.getElementById('filterStudentYear').value;
            const classFilter = document.getElementById('filterStudentClass').value;
            const genderFilter = document.getElementById('filterStudentGender').value;
            const ageFilter = document.getElementById('filterStudentAge').value;
            const enrollmentFilter = document.getElementById('filterStudentEnrollment').value;
            
            // Generate human-readable report description
            let reportDescription = 'This report presents a comprehensive list of ';
            let filters = [];
            
            if (yearFilter) {
                filters.push(`Year ${yearFilter}`);
            }
            
            if (classFilter) {
                filters.push(`${classFilter}`);
            }
            
            if (genderFilter) {
                filters.push(`${genderFilter}`);
            }
            
            if (ageFilter) {
                filters.push(`age range ${ageFilter}`);
            }
            
            if (enrollmentFilter) {
                filters.push(enrollmentFilter === 'enrolled' ? 'enrolled in subjects' : 'not enrolled in any subjects');
            }
            
            if (filters.length > 0) {
                reportDescription += filters.join(', ') + ' students';
            } else {
                reportDescription += 'all active students';
            }
            
            reportDescription += ` currently registered in the Student Management System. The report contains detailed information including student names, age, gender, class assignment, academic year, current enrollment status, and contact information. This document serves as an official record and can be used for administrative purposes, academic planning, and student tracking. Total number of students matching the criteria: ${visibleRows.length}.`;
            
            let tableRows = '';
            
            visibleRows.forEach((row, index) => {
                const cells = row.querySelectorAll('td');
                
                // Extract data from cells (excluding ID and subjects columns)
                const name = cells[1]?.textContent.trim() || '';
                const age = cells[2]?.textContent.trim() || '';
                const gender = cells[3]?.textContent.trim() || '';
                const classLevel = cells[4]?.textContent.trim().replace('Class ', '') || '';
                const year = cells[5]?.textContent.trim().replace('Year ', '') || '';
                const phone = cells[6]?.textContent.trim() || 'N/A';
                
                tableRows += `
                    <tr>
                        <td>${name}</td>
                        <td>${age}</td>
                        <td>${gender}</td>
                        <td>${classLevel}</td>
                        <td>Year ${year}</td>
                        <td>${phone}</td>
                    </tr>
                `;
            });
            
            const studentsHTML = `
                <div style="background: #f9f9f9; padding: 20px; margin-bottom: 25px; border-left: 4px solid #000; border-right: 4px solid #000;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Report Summary</h3>
                    <p style="margin: 0; font-size: 11px; line-height: 1.8; text-align: justify; color: #333;">
                        ${reportDescription}
                    </p>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Class</th>
                            <th>Year</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border: 2px solid #000; text-align: center;">
                    <strong>Total Students: ${visibleRows.length}</strong>
                </div>
            `;
            
            const additionalStyles = `
                table {
                    font-size: 11px;
                    width: 100%;
                }
                
                th {
                    background: #000 !important;
                    color: #fff !important;
                    text-align: center;
                    vertical-align: middle;
                    padding: 12px 8px;
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                td {
                    padding: 10px 8px;
                    vertical-align: middle;
                    text-align: center;
                    border: 1px solid #666;
                    color: #000;
                }
                
                tbody tr:nth-child(even) {
                    background: #fafafa;
                }
                
                tbody tr:hover {
                    background: #f0f0f0;
                }
                
                .status-pass,
                .status-fail {
                    color: #000;
                    font-weight: normal;
                }
            `;
            
            const printWindow = window.open('', '_blank');
            const printHTML = generatePrintTemplate('Students List', studentsHTML, additionalStyles);
            
            printWindow.document.write(printHTML);
            printWindow.document.close();
        }

        // Print Marks Report with Summary
        function printMarksReport() {
            const table = document.getElementById('marksTable');
            const rows = Array.from(table.querySelectorAll('tbody tr.student-main-row'));
            const visibleRows = rows.filter(row => row.style.display !== 'none');
            
            if (visibleRows.length === 0) {
                alert('No data to print');
                return;
            }
            
            // Calculate statistics
            const grades = visibleRows.map(row => {
                const cells = row.querySelectorAll('td');
                return parseFloat(cells[4].textContent) || 0;
            });
            
            const total = grades.length;
            const avgGrade = grades.reduce((sum, g) => sum + g, 0) / total;
            const maxGrade = Math.max(...grades);
            const minGrade = Math.min(...grades);
            
            // Generate filter description
            const yearFilter = document.getElementById('filterMarksYear').value;
            const classFilter = document.getElementById('filterMarksClass').value;
            const subjectFilter = document.getElementById('filterMarksSubject').value;
            
            let reportDescription = 'This report presents a comprehensive overview of student marks ';
            let filters = [];
            
            if (yearFilter) filters.push(`Year ${yearFilter}`);
            if (classFilter) filters.push(`${classFilter}`);
            if (subjectFilter) filters.push(`enrolled in ${subjectFilter}`);
            
            if (filters.length > 0) {
                reportDescription += 'for students in ' + filters.join(', ') + '. ';
            } else {
                reportDescription += 'for all students currently enrolled in the system. ';
            }
            
            reportDescription += `The report includes detailed information about each student's academic performance, year level, class assignment, total enrolled subjects, and cumulative final grade. `;
            reportDescription += `This document serves as an official academic record and can be used for performance evaluation, academic planning, and progress tracking. `;
            reportDescription += `Summary statistics: Average grade is ${avgGrade.toFixed(2)}, with the highest grade being ${maxGrade.toFixed(2)} and the lowest grade being ${minGrade.toFixed(2)}. `;
            reportDescription += `Total number of students matching the criteria: ${total}.`;
            
            // Clone and clean table
            const tableClone = table.cloneNode(true);
            const cloneRows = tableClone.querySelectorAll('tbody tr');
            cloneRows.forEach(row => {
                if (row.style.display === 'none' || row.classList.contains('subject-details-row')) {
                    row.remove();
                    return;
                }
                // Remove Actions column
                const actionCell = row.querySelector('td:last-child');
                if (actionCell && actionCell.querySelector('button')) {
                    actionCell.remove();
                }
            });
            
            // Remove Actions header
            const headerCells = tableClone.querySelectorAll('thead th');
            if (headerCells.length > 0) {
                headerCells[headerCells.length - 1].remove();
            }
            
            const marksHTML = `
                <div class="report-summary">
                    <h3>REPORT SUMMARY</h3>
                    <p>${reportDescription}</p>
                </div>
                
                ${tableClone.outerHTML}
            `;
            
            const additionalStyles = `
                .report-summary {
                    background: #f5f5f5;
                    border: 2px solid #000;
                    padding: 15px;
                    margin-bottom: 20px;
                    page-break-inside: avoid;
                }
                
                .report-summary h3 {
                    margin: 0 0 10px 0;
                    font-size: 14px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .report-summary p {
                    margin: 0;
                    font-size: 11px;
                    line-height: 1.6;
                    text-align: justify;
                }
            `;
            
            const printWindow = window.open('', '_blank');
            const printHTML = generatePrintTemplate('Student Marks Report', marksHTML, additionalStyles);
            
            printWindow.document.write(printHTML);
            printWindow.document.close();
        }

        // Dedicated function for printing Analytics Report with professional formatting
        function printAnalyticsReport() {
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            
            // Get all visible student rows
            const studentRows = document.querySelectorAll('#reportsTable tbody tr.student-main-row');
            const visibleStudents = Array.from(studentRows).filter(row => row.style.display !== 'none');
            
            if (visibleStudents.length === 0) {
                alert(getTranslation('no_data_to_print') || 'No data to print');
                return;
            }
            
            // Show loading message
            showLoading(getTranslation('print') || 'Preparing Report', 'Loading student data for printing...');
            
            // Expand all visible students to ensure data is loaded
            visibleStudents.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const detailsRow = document.getElementById(`details-${studentId}`);
                if (detailsRow && !detailsRow.classList.contains('show')) {
                    toggleStudentDetails(studentId);
                }
            });
            
            // Wait a bit for all details to load, then collect data
            setTimeout(() => {
                collectAndPrintData();
                hideLoading();
            }, 800);
        }
        
        // Function to collect data and generate print document
        function collectAndPrintData() {
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const studentRows = document.querySelectorAll('#reportsTable tbody tr.student-main-row');
            const visibleStudents = Array.from(studentRows).filter(row => row.style.display !== 'none');
            
            console.log('Total visible students:', visibleStudents.length);
            
            // Collect student data
            const studentsData = [];
            visibleStudents.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const cells = row.querySelectorAll('td');
                const studentName = cells[0].querySelector('strong')?.textContent.trim() || cells[0].textContent.trim();
                const yearBadge = cells[1].textContent.trim();
                const classBadge = cells[2].textContent.trim();
                
                console.log('Processing student:', studentName, studentId);
                
                // Get the expanded details row (using correct class name)
                const detailsRow = document.getElementById(`details-${studentId}`);
                if (!detailsRow) {
                    console.warn(`Details row not found for student ${studentId}`);
                    return;
                }
                
                const marksContainer = detailsRow.querySelector('.subject-details-container');
                if (!marksContainer) {
                    console.warn(`Marks container not found for student ${studentId}`);
                    return;
                }
                
                console.log('Found marks container for:', studentName);
                
                // Extract marks data from the single table
                const year1Marks = [];
                const year2Marks = [];
                let year1Total = 0;
                let year2Total = 0;
                
                const marksTable = marksContainer.querySelector('.subject-marks-table');
                if (marksTable) {
                    const rows = marksTable.querySelectorAll('tbody tr');
                    let currentYear = null;
                    let pendingMarks = [];
                    
                    console.log('Total rows in marks table:', rows.length);
                    
                    rows.forEach((tr, index) => {
                        const cells = tr.querySelectorAll('td');
                        
                        // Check if this is a Year Total row
                        if (cells.length > 0 && cells[0].textContent.includes('Year') && cells[0].textContent.includes('Total')) {
                            const yearText = cells[0].textContent;
                            const totalCell = cells[cells.length - 1];
                            const totalValue = parseFloat(totalCell.textContent.trim()) || 0;
                            
                            console.log('Found year total row:', yearText, 'Total:', totalValue);
                            
                            if (yearText.includes('Year 1')) {
                                // Assign pending marks to Year 1
                                year1Marks.push(...pendingMarks);
                                pendingMarks = [];
                                year1Total = totalValue;
                                currentYear = 2; // Switch to Year 2 for next subjects
                                console.log('Switched to Year 2 after Year 1 total');
                            } else if (yearText.includes('Year 2')) {
                                // Assign pending marks to Year 2
                                year2Marks.push(...pendingMarks);
                                pendingMarks = [];
                                year2Total = totalValue;
                                currentYear = null; // No more years expected
                                console.log('Year 2 total found, marking end');
                            }
                        }
                        // Regular mark row - check for enough cells and not empty
                        else if (cells.length >= 7 && cells[0].textContent.trim() && !cells[0].textContent.includes('No marks')) {
                            const markData = {
                                subject: cells[0].textContent.trim(),
                                final: cells[1].textContent.trim(),
                                midterm: cells[2].textContent.trim(),
                                quizzes: cells[3].textContent.trim(),
                                daily: cells[4].textContent.trim(),
                                total: cells[5].textContent.trim(),
                                grade: cells[6].querySelector('.grade-badge')?.textContent.trim() || cells[6].textContent.trim()
                            };
                            
                            // Add to pending marks - they'll be assigned when we hit the Year Total row
                            pendingMarks.push(markData);
                            console.log('Added mark to pending:', markData.subject, 'Current year context:', currentYear);
                        }
                    });
                    
                    // Assign any remaining pending marks (shouldn't happen if data is well-formed)
                    if (pendingMarks.length > 0) {
                        console.warn('Remaining pending marks:', pendingMarks.length);
                        if (currentYear === 2 || year1Total > 0) {
                            year2Marks.push(...pendingMarks);
                        } else {
                            year1Marks.push(...pendingMarks);
                        }
                    }
                }
                
                console.log('Year 1 marks:', year1Marks.length, 'Year 2 marks:', year2Marks.length);
                console.log('Year 1 subjects:', year1Marks.map(m => m.subject));
                console.log('Year 2 subjects:', year2Marks.map(m => m.subject));
                
                // Calculate pass/fail status for each year
                const year1Credits = parseFloat(year1Total) || 0;
                const year1HasF = year1Marks.some(m => m.grade.trim().toUpperCase() === 'F');
                const year1Status = (year1Credits >= 25 && !year1HasF) ? 'PASS' : 'FAIL';
                
                const year2Credits = parseFloat(year2Total) || 0;
                const year2HasF = year2Marks.some(m => m.grade.trim().toUpperCase() === 'F');
                const year2Status = (year2Credits >= 25 && !year2HasF) ? 'PASS' : 'FAIL';
                
                const combinedTotal = (year1Credits + year2Credits).toFixed(2);
                
                console.log('Student:', studentName, 'Y1 Credits:', year1Credits, 'Y1 Status:', year1Status, 'Y2 Credits:', year2Credits, 'Y2 Status:', year2Status);
                
                studentsData.push({
                    name: studentName,
                    year: yearBadge,
                    class: classBadge,
                    year1Marks,
                    year2Marks,
                    year1Total: year1Total.toFixed(2),
                    year2Total: year2Total.toFixed(2),
                    year1Status,
                    year2Status,
                    combinedTotal
                });
            });
            
            console.log('Total students data collected:', studentsData.length);
            console.log('Students data:', studentsData);
            
            if (studentsData.length === 0) {
                alert(getTranslation('no_data_to_print') || 'No data available to print');
                return;
            }
            
            // Check if data has the required properties
            studentsData.forEach((student, idx) => {
                console.log(`Student ${idx}:`, {
                    name: student.name,
                    year1Marks: student.year1Marks?.length || 0,
                    year2Marks: student.year2Marks?.length || 0,
                    year1Total: student.year1Total,
                    year2Total: student.year2Total,
                    year1Status: student.year1Status,
                    year2Status: student.year2Status,
                    combinedTotal: student.combinedTotal
                });
            });
            
            console.log('About to generate HTML...');
            
            // Generate HTML for print
            const printWindow = window.open('', '_blank');
            
            if (!printWindow) {
                alert('Pop-up blocked! Please allow pop-ups for this site.');
                return;
            }
            
            console.log('Print window opened successfully');
            
            // Build student sections separately to avoid complex template literals
            let studentSections = '';
            
            studentsData.forEach((student, index) => {
                console.log(`Building HTML for student ${index}: ${student.name}`);
                
                // Build Year 1 marks table
                let year1Section = '';
                if (student.year1Marks && student.year1Marks.length > 0) {
                    let year1Rows = '';
                    student.year1Marks.forEach(mark => {
                        const total = parseInt(mark.total) || 0;
                        const isFailed = total < 50;
                        const isGradeF = mark.grade.trim().toUpperCase() === 'F';
                        year1Rows += `
                            <tr>
                                <td>${mark.subject}</td>
                                <td>${mark.final}</td>
                                <td>${mark.midterm}</td>
                                <td>${mark.quizzes}</td>
                                <td>${mark.daily}</td>
                                <td><strong class="${isFailed ? 'failed-total' : ''}">${mark.total}</strong></td>
                                <td><strong class="${isGradeF ? 'failed-grade' : ''}">${mark.grade}</strong></td>
                            </tr>
                        `;
                    });
                    
                    year1Section = `
                        <div class="year-section">
                            <div class="year-title">Year 1 - Subject Marks</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Final<br>(60)</th>
                                        <th>Midterm<br>(20)</th>
                                        <th>Quiz<br>(10)</th>
                                        <th>Daily<br>(10)</th>
                                        <th>Total</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${year1Rows}
                                </tbody>
                            </table>
                            <div class="year-summary">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="text-align: left;">
                                        <strong>Academic Status:</strong> 
                                        <span style="color: ${student.year1Status === 'PASS' ? '#16a34a' : '#dc2626'}; font-weight: bold;">
                                            ${student.year1Status}
                                        </span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>Year 1 Total Credits:</strong> 
                                        <span style="font-size: 14px; color: ${parseFloat(student.year1Total) >= 25 ? '#16a34a' : '#dc2626'};">
                                            ${student.year1Total} / 50.00
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // Build Year 2 marks table
                let year2Section = '';
                if (student.year2Marks && student.year2Marks.length > 0) {
                    let year2Rows = '';
                    student.year2Marks.forEach(mark => {
                        const total = parseInt(mark.total) || 0;
                        const isFailed = total < 50;
                        const isGradeF = mark.grade.trim().toUpperCase() === 'F';
                        year2Rows += `
                            <tr>
                                <td>${mark.subject}</td>
                                <td>${mark.final}</td>
                                <td>${mark.midterm}</td>
                                <td>${mark.quizzes}</td>
                                <td>${mark.daily}</td>
                                <td><strong class="${isFailed ? 'failed-total' : ''}">${mark.total}</strong></td>
                                <td><strong class="${isGradeF ? 'failed-grade' : ''}">${mark.grade}</strong></td>
                            </tr>
                        `;
                    });
                    
                    year2Section = `
                        <div class="year-section">
                            <div class="year-title">Year 2 - Subject Marks</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Final<br>(60)</th>
                                        <th>Midterm<br>(20)</th>
                                        <th>Quiz<br>(10)</th>
                                        <th>Daily<br>(10)</th>
                                        <th>Total</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${year2Rows}
                                </tbody>
                            </table>
                            <div class="year-summary">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="text-align: left;">
                                        <strong>Academic Status:</strong> 
                                        <span style="color: ${student.year2Status === 'PASS' ? '#16a34a' : '#dc2626'}; font-weight: bold;">
                                            ${student.year2Status}
                                        </span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>Year 2 Total Credits:</strong> 
                                        <span style="font-size: 14px; color: ${parseFloat(student.year2Total) >= 25 ? '#16a34a' : '#dc2626'};">
                                            ${student.year2Total} / 50.00
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // Build combined total section
                let combinedSection = '';
                if ((student.year1Marks && student.year1Marks.length > 0) || (student.year2Marks && student.year2Marks.length > 0)) {
                    const totalCredits = parseFloat(student.combinedTotal);
                    const overallStatus = totalCredits >= 50 ? 'PASS' : 'FAIL';
                    const statusColor = overallStatus === 'PASS' ? '#16a34a' : '#dc2626';
                    
                    combinedSection = `
                        <div style="margin-top: 20px; border: 2px solid #000; overflow: hidden;">
                            <div style="background: #000; color: #fff; padding: 8px 15px; font-weight: bold; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Overall Performance Summary
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #f9f9f9;">
                                <div style="text-align: left;">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;">FINAL STATUS</div>
                                    <div style="font-size: 16px; font-weight: bold; color: ${statusColor};">
                                        ${overallStatus}
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;">CUMULATIVE CREDITS</div>
                                    <div style="font-size: 18px; font-weight: bold; color: ${statusColor};">
                                        ${student.combinedTotal} / 100.00
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // Build no data section
                let noDataSection = '';
                if ((!student.year1Marks || student.year1Marks.length === 0) && (!student.year2Marks || student.year2Marks.length === 0)) {
                    noDataSection = '<div class="no-data">No marks available</div>';
                }
                
                // Combine all sections for this student
                studentSections += `
                    <div class="student-section">
                        <div class="student-header">
                            <h2>${student.name}</h2>
                            <div class="student-info">
                                <span>Academic Year: ${student.year}</span>
                                <span>Class: ${student.class}</span>
                            </div>
                        </div>
                        ${year1Section}
                        ${year2Section}
                        ${combinedSection}
                        ${noDataSection}
                    </div>
                `;
            });
            
            console.log('Student sections HTML length:', studentSections.length);
            
            // Calculate summary statistics
            const totalStudents = studentsData.length;
            const allGrades = studentsData.map(s => parseFloat(s.combinedTotal) || 0);
            const avgGrade = allGrades.reduce((sum, g) => sum + g, 0) / totalStudents;
            const maxGrade = Math.max(...allGrades);
            const minGrade = Math.min(...allGrades);
            
            // Get filter description
            const yearFilter = document.getElementById('reportsFilterYear')?.value || '';
            const classFilter = document.getElementById('reportsFilterClass')?.value || '';
            const subjectFilter = document.getElementById('reportsFilterSubject')?.value || '';
            
            // Generate comprehensive report description
            let reportDescription = 'This report presents a comprehensive analytics overview of student academic performance ';
            let filters = [];
            
            if (yearFilter) filters.push(`Year ${yearFilter}`);
            if (classFilter) filters.push(`${classFilter}`);
            if (subjectFilter) filters.push(`enrolled in ${subjectFilter}`);
            
            if (filters.length > 0) {
                reportDescription += 'for students in ' + filters.join(', ') + '. ';
            } else {
                reportDescription += 'for all students currently enrolled in the academic system. ';
            }
            
            reportDescription += 'The report includes detailed breakdowns of individual student performance across Year 1 and Year 2 subjects, showing comprehensive marks for final exams, midterm exams, quizzes, and daily activities. ';
            reportDescription += 'Each student section displays their academic status (PASS/FAIL), year-wise total credits, and combined performance metrics. ';
            reportDescription += 'This document serves as an official academic performance record and can be used for academic evaluation, progress tracking, performance review, and administrative planning. ';
            reportDescription += `Summary statistics: Average combined grade is ${avgGrade.toFixed(2)} out of 100, with the highest combined grade being ${maxGrade.toFixed(2)} and the lowest combined grade being ${minGrade.toFixed(2)}. `;
            reportDescription += `Total number of students matching the criteria: ${totalStudents}.`;
            
            // Summary box HTML with text-based report summary
            const summaryBox = `
                <div class="report-summary">
                    <h3>REPORT SUMMARY</h3>
                    <p>${reportDescription}</p>
                </div>
            `;
            
            // Combine summary with student sections
            const fullContent = summaryBox + studentSections;
            
            // Additional styles specific to Analytics Report
            const analyticsStyles = `
                .report-summary {
                    background: #f5f5f5;
                    border: 2px solid #000;
                    padding: 15px;
                    margin-bottom: 20px;
                    page-break-inside: avoid;
                }
                
                .report-summary h3 {
                    margin: 0 0 10px 0;
                    font-size: 14px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .report-summary p {
                    margin: 0;
                    font-size: 11px;
                    line-height: 1.6;
                    text-align: justify;
                }
                
                .student-section {
                    page-break-inside: avoid;
                    page-break-after: always;
                    margin-bottom: 30px;
                }
                
                .student-section:last-child {
                    page-break-after: auto;
                }
                
                .student-header {
                    background: #f5f5f5;
                    border: 2px solid #000;
                    padding: 15px;
                    margin-bottom: 20px;
                }
                
                .student-header h2 {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 8px;
                }
                
                .student-info {
                    font-size: 12px;
                    display: flex;
                    gap: 20px;
                }
                
                .student-info span {
                    font-weight: bold;
                }
                
                .year-section {
                    margin-bottom: 25px;
                }
                
                .year-title {
                    background: #000;
                    color: #fff;
                    padding: 10px 15px;
                    font-size: 14px;
                    font-weight: bold;
                    margin-bottom: 2px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                table {
                    margin-bottom: 10px;
                }
                
                th {
                    text-align: center;
                    font-size: 10px;
                }
                
                td {
                    text-align: center;
                    font-size: 11px;
                }
                
                td:first-child {
                    text-align: left;
                    font-weight: bold;
                }
                
                .year-summary {
                    background: #f0f0f0;
                    border: 1px solid #000;
                    padding: 12px 15px;
                    font-size: 12px;
                }
                
                .no-data {
                    text-align: center;
                    padding: 30px;
                    font-style: italic;
                    color: #666;
                    background: #f9f9f9;
                    border: 1px dashed #999;
                }
            `;
            
            // Use unified print template
            const printHTML = generatePrintTemplate('Analytics Report', fullContent, analyticsStyles);
            
            // IMPORTANT: Write a simple test first to ensure window is writable
            printWindow.document.write('<h1>Loading...</h1>');
            printWindow.document.close();
            
            // Clear and write actual content after brief delay
            setTimeout(() => {
                console.log('HTML generated, length:', printHTML.length);
            
                try {
                printWindow.document.open();
                printWindow.document.write(printHTML);
                printWindow.document.close();
                console.log('Print window content written successfully');
            } catch (error) {
                console.error('Error writing to print window:', error);
                alert('Error generating print document: ' + error.message);
                printWindow.close();
            }
            }, 100); // Small delay to ensure window is ready
        }

        function expandChart(chartId) {
            console.log('Expanding chart:', chartId);
        }

        // Student management functions
        function editStudent(studentId) {
            // Fetch student data via AJAX
            fetch('?action=get_student&id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Get student's enrolled subjects
                        return fetch('?action=get_student_subjects&id=' + studentId);
                    } else {
                        throw new Error(data.message);
                    }
                })
                .then(response => response.json())
                .then(subjectData => {
                    if (subjectData.success) {
                        showEditModal(subjectData.student, subjectData.enrolled_subjects, subjectData.all_subjects);
                    } else {
                        throw new Error(subjectData.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching student data: ' + error.message);
                });
        }

        function deleteStudent(studentId) {
            if (confirm('Are you sure you want to delete this student? This will also remove all their marks.')) {
                showLoading('Deleting Student', 'Please wait...');
                
                const formData = new FormData();
                formData.append('action', 'delete_student');
                formData.append('student_id', studentId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    hideLoading();
                    showNotification('Student deleted successfully!', 'success');
                    setTimeout(() => {
                        reloadTableData('students');
                    }, 500);
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification('Error deleting student', 'error');
                });
            }
        }

        function promoteStudent(studentId) {
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const confirmMsg = getTranslation('confirm_promote_student');
            const loadingTitle = getTranslation('checking_eligibility');
            const loadingMsg = getTranslation('validating_records');
            
            if (confirm(confirmMsg)) {
                showLoading(loadingTitle, loadingMsg);
                
                const formData = new FormData();
                formData.append('action', 'promote_student');
                formData.append('student_id', studentId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        showNotification(getTranslation('student_promoted_success'), 'success');
                        setTimeout(() => {
                            reloadTableData('students');
                        }, 500);
                    } else {
                        // Show detailed error modal
                        showEligibilityErrorModal('Promotion', data);
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification(getTranslation('network_error'), 'error');
                });
            }
        }

        function graduateStudent(studentId) {
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const confirmMsg = getTranslation('confirm_graduate_student');
            const loadingTitle = getTranslation('checking_eligibility');
            const loadingMsg = getTranslation('validating_records');
            
            if (confirm(confirmMsg)) {
                showLoading(loadingTitle, loadingMsg);
                
                const formData = new FormData();
                formData.append('action', 'graduate_student');
                formData.append('student_id', studentId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        showNotification(getTranslation('student_graduated_success'), 'success');
                        setTimeout(() => {
                            reloadTableData('students');
                        }, 500);
                    } else {
                        // Show detailed error modal
                        showEligibilityErrorModal('Graduation', data);
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification(getTranslation('network_error'), 'error');
                });
            }
        }

        // Show detailed eligibility error modal
        function showEligibilityErrorModal(actionType, data) {
            const details = data.details || {};
            const failedSubjects = details.failed_subjects || [];
            
            let failedSubjectsHTML = '';
            if (failedSubjects.length > 0) {
                failedSubjectsHTML = `
                    <div style="margin-top: 1rem; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 6px; overflow: hidden;">
                        <div style="padding: 0.75rem 1rem; background: #fee2e2; font-weight: 600; color: #991b1b; border-bottom: 1px solid #fca5a5;">
                            <i class="fas fa-times-circle" style="margin-right: 0.5rem;"></i>
                            <span data-translate="failed_subjects_label">Failed Subjects (Mark < 50)</span>
                        </div>
                        <div style="padding: 0.5rem; max-height: 200px; overflow-y: auto;">
                            ${failedSubjects.map(s => `
                                <div style="padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #fecaca;">
                                    <span style="font-weight: 500; color: #7f1d1d;">${s.subject}</span>
                                    <span style="color: #dc2626; font-weight: 700; font-size: 1rem;">${s.mark}/100</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            let requirementsHTML = '';
            if (details.reasons && details.reasons.length > 0) {
                const reasonsList = details.reasons.map(r => {
                    if (r.type === 'total_grade') {
                        return `<li style="margin: 0.4rem 0;">${getTranslation('total_final_grade')}: ${r.grade}/${r.max} (${getTranslation('required')}: ≥${r.required})</li>`;
                    } else if (r.type === 'failed_subjects') {
                        return `<li style="margin: 0.4rem 0;">${getTranslation('failed_subjects')}: ${r.count}</li>`;
                    }
                    return '';
                }).join('');
                
                requirementsHTML = `
                    <div style="margin-top: 1rem; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 1rem;">
                        <div style="font-weight: 600; color: #92400e; margin-bottom: 0.75rem;">
                            <i class="fas fa-clipboard-list" style="margin-right: 0.5rem;"></i>
                            <span data-translate="requirements">Requirements</span>:
                        </div>
                        <ul style="margin: 0; padding-left: 1.25rem; color: #78350f;">
                            ${reasonsList}
                        </ul>
                    </div>
                `;
            }
            
            const modalHTML = `
                <div id="eligibilityErrorModal" onclick="if(event.target.id === 'eligibilityErrorModal') closeEligibilityErrorModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 1rem; animation: fadeIn 0.2s ease;">
                    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; width: 100%; max-width: 550px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s ease;">
                        
                        <!-- Header -->
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.25rem 1.5rem; border-radius: 12px 12px 0 0; display: flex; align-items: center; justify-content: space-between;">
                            <h3 style="margin: 0; color: white; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span data-translate="${actionType === 'Promotion' ? 'cannot_promote_student' : 'cannot_graduate_student'}">
                                    Cannot ${actionType === 'Promotion' ? 'Promote' : 'Graduate'} Student
                                </span>
                            </h3>
                            <button onclick="closeEligibilityErrorModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 1.5rem; overflow-y: auto; flex: 1;">
                            <div style="background: #fee2e2; padding: 1rem; border-radius: 8px; border-left: 4px solid #dc2626; margin-bottom: 1rem;">
                                <p style="margin: 0; color: #7f1d1d; font-weight: 600; font-size: 0.95rem;" data-translate="student_not_meet_requirements">
                                    Student does not meet ${actionType.toLowerCase()} requirements
                                </p>
                            </div>
                            
                            ${requirementsHTML}
                            ${failedSubjectsHTML}
                            
                            <div style="margin-top: 1rem; background: #dbeafe; border-left: 4px solid #1e40af; border-radius: 8px; padding: 1rem;">
                                <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.75rem;">
                                    <i class="fas fa-lightbulb" style="margin-right: 0.5rem;"></i>
                                    <span data-translate="action_required">Action Required</span>:
                                </div>
                                <ul style="margin: 0; padding-left: 1.25rem; color: #1e3a8a;">
                                    ${failedSubjects.length > 0 ? '<li><span data-translate="update_failed_subjects">Update failed subjects to have marks ≥ 50</span></li>' : ''}
                                    ${details.total_final_grade < details.required_grade ? '<li><span data-translate="increase_marks">Increase marks to reach minimum final grade of 25</span></li>' : ''}
                                    <li><span data-translate="review_marks">Review all subject marks before trying again</span></li>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div style="padding: 1rem 1.5rem; border-top: 2px solid #e5e7eb; background: #f9fafb; border-radius: 0 0 12px 12px; display: flex; justify-content: flex-end;">
                            <button onclick="closeEligibilityErrorModal()" 
                                    style="padding: 0.75rem 1.75rem; border: none; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: white; border-radius: 8px; cursor: pointer; font-size: 0.95rem; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" 
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(30, 58, 138, 0.4)'" 
                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                <span data-translate="ok_understand">OK, I Understand</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Apply current language
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            applyTranslations(currentLang);
            
            // Add ESC key to close
            const keyHandler = function(e) {
                if (e.key === 'Escape') {
                    closeEligibilityErrorModal();
                    document.removeEventListener('keydown', keyHandler);
                }
            };
            document.addEventListener('keydown', keyHandler);
        }
        
        function closeEligibilityErrorModal() {
            const modal = document.getElementById('eligibilityErrorModal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.2s ease';
                setTimeout(() => modal.remove(), 200);
            }
        }

        // Show bulk action modal
        function showBulkActionModal() {
            // Count eligible students for each year
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const loadingTitle = translations[currentLang]?.checking_eligibility || 'Checking Eligibility';
            const loadingMessage = translations[currentLang]?.counting_eligible_students || 'Counting eligible students...';
            showLoading(loadingTitle, loadingMessage);
            
            Promise.all([
                fetch('?action=count_eligible_students&year=1').then(r => r.json()),
                fetch('?action=count_eligible_students&year=2').then(r => r.json())
            ]).then(([year1Data, year2Data]) => {
                hideLoading();
                
                const year1Eligible = year1Data.eligible || 0;
                const year1Total = year1Data.total || 0;
                const year2Eligible = year2Data.eligible || 0;
                const year2Total = year2Data.total || 0;
                
                const modalHTML = `
                    <div id="bulkActionModal" onclick="if(event.target.id === 'bulkActionModal') closeBulkActionModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 1rem; animation: fadeIn 0.2s ease;">
                        <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; width: 100%; max-width: 550px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s ease;">
                            
                            <!-- Header -->
                            <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.25rem 1.5rem; border-radius: 12px 12px 0 0; display: flex; align-items: center; justify-content: space-between;">
                                <h3 style="margin: 0; color: white; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-users-cog"></i>
                                    <span data-translate="bulk_action">Bulk Promote/Graduate</span>
                                </h3>
                                <button onclick="closeBulkActionModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <!-- Content -->
                            <div style="padding: 1.5rem;">
                                <p style="margin: 0 0 1.5rem 0; color: #64748b; font-size: 0.95rem;">
                                    <span data-translate="bulk_action_desc">Select which year students you want to promote or graduate. Only eligible students will be processed.</span>
                                </p>
                                
                                <div style="display: flex; flex-direction: column; gap: 1rem;">
                                    <button onclick="executeBulkAction(1)" class="apply-filters-btn" ${year1Eligible === 0 ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : 'style="cursor: pointer;"'}>
                                        <div style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); display: flex; align-items: center; justify-content: space-between; border-radius: 8px;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem; flex: 1;">
                                                <i class="fas fa-arrow-up" style="font-size: 1.25rem;"></i>
                                                <div style="text-align: left; flex: 1;">
                                                    <div style="font-weight: 700; margin-bottom: 0.25rem;"><span data-translate="promote_year_1">Promote Year 1 Students</span></div>
                                                    <div style="font-size: 0.85rem; opacity: 0.9;"><span data-translate="year_1_to_year_2">Move eligible Year 1 students to Year 2</span></div>
                                                    <div style="margin-top: 0.5rem; padding: 0.4rem 0.75rem; background: rgba(255,255,255,0.2); border-radius: 4px; display: inline-block;">
                                                        <span data-translate="eligible">Eligible</span>: <strong>${year1Eligible}</strong> / ${year1Total}
                                                    </div>
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right" style="margin-left: 0.5rem;"></i>
                                        </div>
                                    </button>
                                    
                                    <button onclick="executeBulkAction(2)" class="apply-filters-btn" ${year2Eligible === 0 ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : 'style="cursor: pointer;"'}>
                                        <div style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); display: flex; align-items: center; justify-content: space-between; border-radius: 8px;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem; flex: 1;">
                                                <i class="fas fa-graduation-cap" style="font-size: 1.25rem;"></i>
                                                <div style="text-align: left; flex: 1;">
                                                    <div style="font-weight: 700; margin-bottom: 0.25rem;"><span data-translate="graduate_year_2">Graduate Year 2 Students</span></div>
                                                    <div style="font-size: 0.85rem; opacity: 0.9;"><span data-translate="year_2_to_graduated">Move eligible Year 2 students to graduated</span></div>
                                                    <div style="margin-top: 0.5rem; padding: 0.4rem 0.75rem; background: rgba(255,255,255,0.2); border-radius: 4px; display: inline-block;">
                                                        <span data-translate="eligible">Eligible</span>: <strong>${year2Eligible}</strong> / ${year2Total}
                                                    </div>
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right" style="margin-left: 0.5rem;"></i>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Footer -->
                            <div style="padding: 1rem 1.5rem; border-top: 2px solid #e5e7eb; background: #f9fafb; border-radius: 0 0 12px 12px; display: flex; justify-content: flex-end;">
                                <button onclick="closeBulkActionModal()" class="clear-filters-btn" style="padding: 0.65rem 1.5rem;">
                                    <span data-translate="cancel">Cancel</span>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.insertAdjacentHTML('beforeend', modalHTML);
                
                // Apply translations after DOM is fully rendered
                setTimeout(() => {
                    const currentLang = localStorage.getItem('selectedLanguage') || 'en';
                    
                    // Manually translate all elements in the modal
                    const modal = document.getElementById('bulkActionModal');
                    if (modal && translations[currentLang]) {
                        modal.querySelectorAll('[data-translate]').forEach(element => {
                            const key = element.getAttribute('data-translate');
                            if (translations[currentLang][key]) {
                                element.textContent = translations[currentLang][key];
                            }
                        });
                    }
                }, 50);
                
                const keyHandler = function(e) {
                    if (e.key === 'Escape') {
                        closeBulkActionModal();
                        document.removeEventListener('keydown', keyHandler);
                    }
                };
                document.addEventListener('keydown', keyHandler);
            }).catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Failed to load eligibility data', 'error');
            });
        }
        
        function closeBulkActionModal() {
            const modal = document.getElementById('bulkActionModal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.2s ease';
                setTimeout(() => modal.remove(), 200);
            }
        }
        
        function executeBulkAction(year) {
            const action = year === 1 ? 'promotion' : 'graduation';
            const actionText = year === 1 ? 'promote' : 'graduate';
            
            if (confirm(`Are you sure you want to ${actionText} all eligible Year ${year} students? This action cannot be undone.`)) {
                showLoading('Processing', `Checking and ${actionText}ing eligible students...`);
                closeBulkActionModal();
                
                const formData = new FormData();
                formData.append('action', 'bulk_' + action);
                formData.append('year', year);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        const msg = `Successfully processed ${data.processed} student(s). ${data.promoted || data.graduated || 0} ${actionText}d, ${data.skipped || 0} skipped (not eligible).`;
                        showNotification(msg, 'success');
                        setTimeout(() => {
                            reloadTableData('students');
                        }, 1000);
                    } else {
                        showNotification(data.message || `Failed to ${actionText} students`, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }

        function showEditModal(student, enrolledSubjects, allSubjects) {
            // Create modal HTML
            const modalHTML = `
                <div id="editModal" onclick="if(event.target.id === 'editModal') closeEditModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 1rem;">
                    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; width: 95%; max-width: 900px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        
                        <!-- Header -->
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: white; font-size: 1.25rem; font-weight: 600;">
                                <i class="fas fa-user-graduate" style="margin-right: 0.5rem;"></i>
                                <span data-translate="edit_student">Edit Student</span>
                            </h3>
                            <button onclick="closeEditModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 2rem; overflow-y: auto; flex: 1;">
                            <form id="editStudentForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'students', function(){ closeEditModal(); });">
                                <input type="hidden" name="action" value="update_student">
                                <input type="hidden" name="student_id" value="${student.id}">
                                
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem; margin-bottom: 1.5rem;">
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;"><span data-translate="full_name">Full Name</span> *</label>
                                        <input type="text" name="student_name" value="${student.name || ''}" required 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;"><span data-translate="age">Age</span> *</label>
                                        <input type="number" name="age" value="${student.age || ''}" min="15" max="30" required 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;"><span data-translate="gender">Gender</span> *</label>
                                        <select name="gender" required style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            <option value="" data-translate="select_gender">Select Gender</option>
                                            <option value="Male" ${student.gender === 'Male' ? 'selected' : ''} data-translate="male">Male</option>
                                            <option value="Female" ${student.gender === 'Female' ? 'selected' : ''} data-translate="female">Female</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;"><span data-translate="class">Class</span> *</label>
                                        <select name="class_level" id="editStudentClassSelect" required style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            <option value="" data-translate="select_class">Select Class</option>
                                            <!-- Options will be populated by JavaScript based on year selection -->
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;"><span data-translate="academic_year">Academic Year</span> *</label>
                                        <select name="year" id="editStudentYearSelect" required style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;" onchange="filterEditSubjectsByStudentYear(); updateEditClassOptions();">
                                            <option value="" data-translate="select_year">Select Year</option>
                                            <option value="1" ${student.year === '1' || student.year === 1 ? 'selected' : ''} data-translate="year_1">Year 1</option>
                                            <option value="2" ${student.year === '2' || student.year === 2 ? 'selected' : ''} data-translate="year_2">Year 2</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="phone">Phone</label>
                                        <input type="tel" name="phone" value="${student.phone || ''}"
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                            </div>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;" data-translate="subject_enrollment">Subject Enrollment</label>
                                <div id="editSubjectEnrollmentContainer" style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 15px; max-height: 200px; overflow-y: auto;">
                                    ${allSubjects.map(subject => `
                                        <div class="edit-subject-item" data-edit-subject-year="${subject.year || ''}" style="margin-bottom: 10px; display: flex; align-items: center; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef;">
                                            <input type="checkbox" name="subjects[]" value="${subject.id}" 
                                                   id="edit_subject_${subject.id}" 
                                                   ${enrolledSubjects.includes(subject.id.toString()) ? 'checked' : ''}
                                                   style="margin-right: 10px; transform: scale(1.2);">
                                            <label for="edit_subject_${subject.id}" style="margin: 0; font-weight: 500; color: #333; flex: 1; cursor: pointer;">
                                                ${subject.subject_name}
                                                ${subject.description ? `<small style="color: #666; display: block; font-weight: normal;">${subject.description}</small>` : ''}
                                            </label>
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <div style="font-size: 0.8rem; color: #666; padding: 2px 8px; background: #f0f9ff; border-radius: 4px;">
                                                    <span data-translate="year">Year</span> ${subject.year || 'N/A'}
                                                </div>
                                                <div style="font-size: 0.8rem; color: #666; padding: 2px 8px; background: #e3f2fd; border-radius: 4px;">
                                                    ${subject.credits} <span data-translate="credits">credits</span>
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                <small style="color: #666; margin-top: 5px; display: block;" data-translate="tip_enroll">
                                    Tip: Check/uncheck subjects to enroll or unenroll the student. Unchecking will remove all marks for that subject.
                                </small>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                                <button type="button" onclick="closeEditModal()" 
                                        style="padding: 0.65rem 1.5rem; border: 1.5px solid #cbd5e1; background: white; color: #475569; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; font-size: 0.95rem;" 
                                        onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#94a3b8'" onmouseout="this.style.background='white'; this.style.borderColor='#cbd5e1'">
                                    <i class="fas fa-times" style="margin-right: 0.5rem;"></i>
                                    <span data-translate="cancel">Cancel</span>
                                </button>
                                <button type="submit" 
                                        style="padding: 0.65rem 1.5rem; border: none; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: white; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; font-size: 0.95rem; box-shadow: 0 2px 8px rgba(30,58,138,0.3);" 
                                        onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(30,58,138,0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(30,58,138,0.3)'">
                                    <i class="fas fa-save" style="margin-right: 0.5rem;"></i>
                                    <span data-translate="update_student">Update Student</span>
                                </button>
                            </div>
                        </form>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Apply translations to the modal
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            changeLanguage(currentLang);
            
            // Initialize class options based on student's year and set current value
            setTimeout(() => {
                const yearSelect = document.getElementById('editStudentYearSelect');
                const classSelect = document.getElementById('editStudentClassSelect');
                
                if (yearSelect && classSelect) {
                    // Update class options based on current year
                    updateEditClassOptions();
                    
                    // Set the current class value
                    classSelect.value = student.class_level || '';
                }
                
                // Filter subjects based on student's current year after modal is created
                filterEditSubjectsByStudentYear();
            }, 100);
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            if (modal) {
                modal.remove();
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'editModal') {
                closeEditModal();
            }
        });

        // Subject management functions
        function editSubject(subjectId) {
            // Fetch subject data via AJAX
            fetch('?action=get_subject&id=' + subjectId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showEditSubjectModal(data.subject);
                    } else {
                        alert('Error fetching subject data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching subject data');
                });
        }

        function deleteSubject(subjectId) {
            if (confirm('Are you sure you want to delete this subject? This will also remove all marks for this subject.')) {
                showLoading('Deleting Subject', 'Please wait...');
                
                const formData = new FormData();
                formData.append('action', 'delete_subject');
                formData.append('subject_id', subjectId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    hideLoading();
                    showNotification('Subject deleted successfully!', 'success');
                    setTimeout(() => {
                        reloadTableData('subjects');
                    }, 500);
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification('Error deleting subject', 'error');
                });
            }
        }

        function showEditSubjectModal(subject) {
            // Create modal HTML
            const modalHTML = `
                <div id="editSubjectModal" onclick="if(event.target.id === 'editSubjectModal') closeEditSubjectModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 1rem;">
                    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; width: 95%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        
                        <!-- Header -->
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: white; font-size: 1.25rem; font-weight: 600;">
                                <i class="fas fa-book" style="margin-right: 0.5rem;"></i>
                                <span data-translate="edit_subject">Edit Subject</span>
                            </h3>
                            <button onclick="closeEditSubjectModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 2rem; overflow-y: auto; flex: 1;">
                            <form id="editSubjectForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'subjects', function(){ closeEditSubjectModal(); });">
                                <input type="hidden" name="action" value="update_subject">
                                <input type="hidden" name="subject_id" value="${subject.id}">
                                
                                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="subject_name">Subject Name *</label>
                                        <input type="text" name="subject_name" value="${subject.subject_name || ''}" required 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="description">Description</label>
                                        <textarea name="description" rows="3" 
                                                  style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; resize: vertical;">${subject.description || ''}</textarea>
                                    </div>
                                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
                                        <div>
                                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="academic_year">Academic Year *</label>
                                            <select name="year" required style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                                <option value="" data-translate="select_year">Select Year</option>
                                                <option value="1" ${subject.year === '1' || subject.year === 1 ? 'selected' : ''} data-translate="year_1">Year 1</option>
                                                <option value="2" ${subject.year === '2' || subject.year === 2 ? 'selected' : ''} data-translate="year_2">Year 2</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="credits">Credits *</label>
                                            <input type="number" name="credits" value="${subject.credits || ''}" min="1" max="50" required 
                                                   style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Footer -->
                        <div style="padding: 1.5rem 2rem; border-top: 1px solid #e5e7eb; background: #f8fafc; border-radius: 0 0 12px 12px; display: flex; gap: 1rem; justify-content: flex-end;">
                            <button type="button" onclick="closeEditSubjectModal()" 
                                    style="padding: 0.65rem 1.5rem; border: 1.5px solid #cbd5e1; background: white; color: #475569; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; font-size: 0.95rem;" 
                                    onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#94a3b8'" onmouseout="this.style.background='white'; this.style.borderColor='#cbd5e1'">
                                <i class="fas fa-times" style="margin-right: 0.5rem;"></i>
                                <span data-translate="cancel">Cancel</span>
                            </button>
                            <button type="submit" form="editSubjectForm" 
                                    style="padding: 0.65rem 1.5rem; border: none; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: white; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; font-size: 0.95rem; box-shadow: 0 2px 8px rgba(30,58,138,0.3);" 
                                    onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(30,58,138,0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(30,58,138,0.3)'">
                                <i class="fas fa-save" style="margin-right: 0.5rem;"></i>
                                <span data-translate="update_subject">Update Subject</span>
                            </button>
                        </div>
                        
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Apply translations to the newly added modal
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            changeLanguage(currentLang);
        }

        function closeEditSubjectModal() {
            const modal = document.getElementById('editSubjectModal');
            if (modal) {
                modal.remove();
            }
        }

        // Close subject modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'editSubjectModal') {
                closeEditSubjectModal();
            }
        });

        // Mark management functions
        function editMark(markId) {
            // Fetch mark data via AJAX
            fetch('?action=get_mark&id=' + markId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showEditMarkModal(data.mark);
                    } else {
                        alert('Error fetching mark data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching mark data');
                });
        }

        function deleteMark(markId) {
            if (confirm('Are you sure you want to delete this mark?')) {
                showLoading('Deleting Mark', 'Please wait...');
                
                const formData = new FormData();
                formData.append('action', 'delete_mark');
                formData.append('mark_id', markId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    hideLoading();
                    showNotification('Mark deleted successfully!', 'success');
                    setTimeout(() => {
                        reloadTableData('marks');
                    }, 500);
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showNotification('Error deleting mark', 'error');
                });
            }
        }

        function showEditMarkModal(mark) {
            // Helper function to format number for display (remove unnecessary decimals)
            function formatNumber(value) {
                if (!value || value <= 0) return '';
                const num = parseFloat(value);
                return num % 1 === 0 ? parseInt(num) : num;
            }
            
            // Create modal HTML
            const modalHTML = `
                <div id="editMarkModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Roboto', 'Noto Sans', sans-serif;">✏️ <span data-translate="edit_mark">Edit Mark</span></h3>
                        <form id="editMarkForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'marks', function(){ closeEditMarkModal(); });">
                            <input type="hidden" name="action" value="update_mark_record">
                            <input type="hidden" name="mark_id" value="${mark.id}">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                                <div style="grid-column: span 2;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;" data-translate="student">Student</label>
                                    <div style="padding: 0.75rem; background: #f8f9fa; border-radius: 6px; color: #666;">
                                        ${mark.student_name}
                                    </div>
                                </div>
                                <div style="grid-column: span 2;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;" data-translate="subject">Subject</label>
                                    <div style="padding: 0.75rem; background: #f8f9fa; border-radius: 6px; color: #666;">
                                        ${mark.subject_name}
                                    </div>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;"><span data-translate="final_exam_range">Final Exam (0-60)</span> *</label>
                                    <input type="number" name="final_exam" value="${formatNumber(mark.final_exam)}" min="0" max="60" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;"><span data-translate="midterm_range">Midterm (0-20)</span> *</label>
                                    <input type="number" name="midterm_exam" value="${formatNumber(mark.midterm_exam)}" min="0" max="20" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;"><span data-translate="quizzes_range">Quizzes (0-10)</span> *</label>
                                    <input type="number" name="quizzes" value="${formatNumber(mark.quizzes)}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;"><span data-translate="daily_range">Daily Activities (0-10)</span> *</label>
                                    <input type="number" name="daily_activities" value="${formatNumber(mark.daily_activities)}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div style="grid-column: span 2;">
                                    <div style="background: #f0f9ff; padding: 1rem; border-radius: 6px; border: 1px solid #0ea5e9;">
                                        <div style="font-weight: 500; color: #0c4a6e; margin-bottom: 0.5rem;"><i class="fas fa-calculator"></i> <span data-translate="mark_calculation">Mark Calculation</span></div>
                                        <div style="font-size: 0.9rem; color: #0369a1;">
                                            Total = Final Exam + Midterm + Quizzes + Daily Activities<br>
                                            <strong><span data-translate="current_total">Current Total</span>: <span id="totalPreview">${(parseInt(mark.final_exam || 0) + parseInt(mark.midterm_exam || 0) + parseInt(mark.quizzes || 0) + parseInt(mark.daily_activities || 0))}</span>/100</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="closeEditMarkModal()" data-translate="cancel"
                                        style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Cancel
                                </button>
                                <button type="submit" data-translate="update_mark"
                                        style="padding: 0.75rem 1.5rem; border: none; background: #3B82F6; color: white; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Update Mark
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Apply translations to the modal
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            changeLanguage(currentLang);
            
            // Add real-time total calculation and input validation
            const inputs = document.querySelectorAll('#editMarkModal input[type="number"]');
            inputs.forEach(input => {
                // Auto-correct on input change with slight delay
                input.addEventListener('input', function() {
                    updateTotalPreview();
                    
                    // Use timeout to allow user to finish typing
                    clearTimeout(this.validationTimeout);
                    this.validationTimeout = setTimeout(() => {
                        limitInputValue(this);
                        updateTotalPreview(); // Recalculate after correction
                    }, 500);
                });
                
                // Immediate validation when user leaves the field
                input.addEventListener('blur', function() {
                    clearTimeout(this.validationTimeout);
                    limitInputValue(this);
                    updateTotalPreview();
                });
                
                // Real-time validation while typing to prevent extreme values
                input.addEventListener('keyup', function() {
                    const max = parseInt(this.getAttribute('max'));
                    const currentValue = parseInt(this.value);
                    
                    // If value is way over limit, correct immediately
                    if (currentValue > max * 2) {
                        this.value = max;
                        limitInputValue(this);
                        updateTotalPreview();
                    }
                });
                
                // Prevent pasting invalid values
                input.addEventListener('paste', function(e) {
                    setTimeout(() => {
                        limitInputValue(this);
                        updateTotalPreview();
                    }, 10);
                });
            });
        }

        function updateTotalPreview() {
            const final = parseInt(document.querySelector('input[name="final_exam"]').value) || 0;
            const midterm = parseInt(document.querySelector('input[name="midterm_exam"]').value) || 0;
            const quizzes = parseInt(document.querySelector('input[name="quizzes"]').value) || 0;
            const daily = parseInt(document.querySelector('input[name="daily_activities"]').value) || 0;
            const total = final + midterm + quizzes + daily;
            
            const preview = document.getElementById('totalPreview');
            if (preview) {
                preview.textContent = total;
                preview.style.color = total > 100 ? '#dc2626' : '#0369a1';
            }
        }

        function closeEditMarkModal() {
            const modal = document.getElementById('editMarkModal');
            if (modal) {
                modal.remove();
            }
        }

        // Close mark modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'editMarkModal') {
                closeEditMarkModal();
            }
        });

        // Update Marks Subject Filter based on Year
        function updateMarksSubjectFilter() {
            const yearFilter = document.getElementById('filterMarksYear').value;
            const subjectSelect = document.getElementById('filterMarksSubject');
            const currentValue = subjectSelect.value;
            
            // Clear current options except "All Subjects"
            const allSubjectsOption = document.createElement('option');
            allSubjectsOption.value = '';
            allSubjectsOption.setAttribute('data-translate', 'all_subjects');
            allSubjectsOption.textContent = getTranslation('all_subjects');
            subjectSelect.innerHTML = '';
            subjectSelect.appendChild(allSubjectsOption);
            
            // Get all subjects from the data
            if (typeof allSubjects !== 'undefined' && allSubjects) {
                allSubjects.forEach(subject => {
                    // If year filter is set, only show subjects for that year
                    if (yearFilter === '' || subject.year == yearFilter) {
                        const option = document.createElement('option');
                        option.value = subject.subject_name;
                        option.textContent = subject.subject_name;
                        subjectSelect.appendChild(option);
                    }
                });
            }
            
            // Restore selection if it's still valid
            if (currentValue) {
                const options = Array.from(subjectSelect.options);
                const matchingOption = options.find(opt => opt.value === currentValue);
                if (matchingOption) {
                    subjectSelect.value = currentValue;
                } else {
                    subjectSelect.value = '';
                }
            }
        }

        // Update Reports Subject Filter based on Year
        function updateReportsSubjectFilter() {
            const yearFilter = document.getElementById('reportsFilterYear').value;
            const subjectSelect = document.getElementById('reportsFilterSubject');
            const currentValue = subjectSelect.value;
            
            // Clear current options except "All Subjects"
            const allSubjectsOption = document.createElement('option');
            allSubjectsOption.value = '';
            allSubjectsOption.setAttribute('data-translate', 'all_subjects');
            allSubjectsOption.textContent = getTranslation('all_subjects');
            subjectSelect.innerHTML = '';
            subjectSelect.appendChild(allSubjectsOption);
            
            // Get all subjects from the data
            if (typeof allSubjects !== 'undefined' && allSubjects) {
                allSubjects.forEach(subject => {
                    // If year filter is set, only show subjects for that year
                    if (yearFilter === '' || subject.year == yearFilter) {
                        const option = document.createElement('option');
                        option.value = subject.subject_name;
                        option.textContent = subject.subject_name;
                        subjectSelect.appendChild(option);
                    }
                });
            }
            
            // Restore selection if it's still valid
            if (currentValue) {
                const options = Array.from(subjectSelect.options);
                const matchingOption = options.find(opt => opt.value === currentValue);
                if (matchingOption) {
                    subjectSelect.value = currentValue;
                } else {
                    subjectSelect.value = '';
                }
            }
        }

        // Initialize subject filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize marks subject filter if on marks page
            if (document.getElementById('filterMarksSubject')) {
                updateMarksSubjectFilter();
                // Run initial filter to show summary
                setTimeout(() => filterMarks(), 100);
            }
            
            // Initialize reports subject filter if on reports page
            if (document.getElementById('reportsFilterSubject')) {
                updateReportsSubjectFilter();
                // Run initial filter to show summary
                setTimeout(() => filterReportsTable(), 100);
            }
        });

        // Marks filtering and search functions
        function filterMarks() {
            const searchValue = document.getElementById('searchMarks').value.toLowerCase();
            const yearFilter = document.getElementById('filterMarksYear').value;
            const classFilter = document.getElementById('filterMarksClass').value;
            const subjectFilter = document.getElementById('filterMarksSubject').value;
            const teacherFilter = document.getElementById('filterMarksTeacher') ? document.getElementById('filterMarksTeacher').value : '';
            const sortByGrade = document.getElementById('sortMarksByGrade').value;
            
            const table = document.getElementById('marksTable');
            const rows = Array.from(table.querySelectorAll('tbody tr.student-main-row'));
            
            let visibleCount = 0;
            let visibleRows = [];
            
            rows.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const detailsRow = document.getElementById('details-' + studentId);
                
                const cells = row.querySelectorAll('td');
                
                if (cells.length === 0) return; // Skip empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                
                // Get year, class, and teachers from data attributes
                const rowYear = row.getAttribute('data-year');
                const rowClass = row.getAttribute('data-class');
                const rowTeachers = row.getAttribute('data-teachers') || '';
                
                // Get final grade for sorting
                const finalGradeCell = cells[4]; // Final Grade column
                const finalGrade = parseFloat(finalGradeCell.textContent) || 0;
                
                // Check if student has the filtered subject
                let hasSubject = true;
                if (subjectFilter && detailsRow) {
                    const subjectRows = detailsRow.querySelectorAll('.subject-marks-table tbody tr');
                    hasSubject = false;
                    subjectRows.forEach(subjRow => {
                        const subjCells = subjRow.querySelectorAll('td');
                        if (subjCells.length > 0) {
                            const subjectName = subjCells[0].textContent.trim();
                            if (subjectName === subjectFilter) {
                                hasSubject = true;
                            }
                        }
                    });
                }
                
                // Check search criteria
                const matchesSearch = searchValue === '' || studentName.includes(searchValue);
                const matchesYear = yearFilter === '' || rowYear === yearFilter;
                const matchesClass = classFilter === '' || rowClass === classFilter;
                const matchesSubject = subjectFilter === '' || hasSubject;
                const matchesTeacher = teacherFilter === '' || rowTeachers.includes(teacherFilter);
                
                // Show/hide row based on all criteria
                if (matchesSearch && matchesYear && matchesClass && matchesSubject && matchesTeacher) {
                    row.style.display = '';
                    if (detailsRow) detailsRow.style.display = '';
                    visibleCount++;
                    visibleRows.push({ row, detailsRow, finalGrade });
                } else {
                    row.style.display = 'none';
                    if (detailsRow) detailsRow.style.display = 'none';
                }
            });
            
            // Apply sorting if selected
            if (sortByGrade && visibleRows.length > 0) {
                visibleRows.sort((a, b) => {
                    if (sortByGrade === 'asc') {
                        return a.finalGrade - b.finalGrade;
                    } else {
                        return b.finalGrade - a.finalGrade;
                    }
                });
                
                // Reorder rows in the table
                const tbody = table.querySelector('tbody');
                visibleRows.forEach(item => {
                    tbody.appendChild(item.row);
                    if (item.detailsRow) {
                        tbody.appendChild(item.detailsRow);
                    }
                });
            }
            
            // Update results counter
            updateMarksCounter(visibleCount);
            
            // Update summary statistics
            updateMarksSummary(visibleRows);
        }

        function updateMarksSummary(visibleRows) {
            const summaryBox = document.getElementById('marksSummaryBox');
            if (!summaryBox) return;
            
            if (visibleRows.length === 0) {
                summaryBox.style.display = 'none';
                return;
            }
            
            summaryBox.style.display = 'block';
            
            const grades = visibleRows.map(item => item.finalGrade);
            const total = visibleRows.length;
            const avgGrade = grades.reduce((sum, grade) => sum + grade, 0) / total;
            const maxGrade = Math.max(...grades);
            const minGrade = Math.min(...grades);
            
            document.getElementById('marksTotalStudents').textContent = total;
            document.getElementById('marksAvgGrade').textContent = avgGrade.toFixed(2);
            document.getElementById('marksMaxGrade').textContent = maxGrade.toFixed(2);
            document.getElementById('marksMinGrade').textContent = minGrade.toFixed(2);
        }

        function clearMarksFilters() {
            document.getElementById('searchMarks').value = '';
            document.getElementById('filterMarksYear').value = '';
            document.getElementById('filterMarksClass').value = '';
            document.getElementById('filterMarksSubject').value = '';
            if (document.getElementById('filterMarksTeacher')) {
                document.getElementById('filterMarksTeacher').value = '';
            }
            document.getElementById('sortMarksByGrade').value = '';
            
            updateMarksSubjectFilter();
            filterMarks();
        }

        // Function to limit input values to min/max range
        function limitInputValue(input) {
            const min = parseInt(input.getAttribute('min'));
            const max = parseInt(input.getAttribute('max'));
            let value = parseInt(input.value);
            
            // If empty or not a number, leave it empty
            if (input.value === '' || isNaN(value)) {
                return;
            }
            
            // Auto-correct values outside the range
            if (value < min) {
                input.value = min;
            } else if (value > max) {
                input.value = max;
            }
            
            // Trigger any change events (like total calculation)
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Add event listeners for mark input validation
        document.addEventListener('DOMContentLoaded', function() {
            // Find all mark input fields and add validation
            const markInputs = document.querySelectorAll('input[name="final_exam"], input[name="midterm_exam"], input[name="quizzes"], input[name="daily_activities"]');
            
            markInputs.forEach(input => {
                // Auto-correct on input change with slight delay
                input.addEventListener('input', function() {
                    // Use timeout to allow user to finish typing
                    clearTimeout(this.validationTimeout);
                    this.validationTimeout = setTimeout(() => {
                        limitInputValue(this);
                    }, 500); // Wait 500ms after user stops typing
                });
                
                // Immediate validation when user leaves the field
                input.addEventListener('blur', function() {
                    clearTimeout(this.validationTimeout);
                    limitInputValue(this);
                });
                
                // Real-time validation while typing to prevent extreme values
                input.addEventListener('keyup', function() {
                    const max = parseInt(this.getAttribute('max'));
                    const currentValue = parseInt(this.value);
                    
                    // If value is way over limit, correct immediately
                    if (currentValue > max * 2) {
                        this.value = max;
                        limitInputValue(this);
                    }
                });
                
                // Prevent pasting invalid values
                input.addEventListener('paste', function(e) {
                    setTimeout(() => {
                        limitInputValue(this);
                    }, 10);
                });
            });
        });

        // Function to filter students and subjects based on academic year selection
        function filterMarkStudentsAndSubjects() {
            const selectedYear = document.getElementById('marksAcademicYear').value;
            const selectedClass = document.getElementById('marksClassLevel').value;
            const studentSelect = document.getElementById('marksStudentSelect');
            const subjectSelect = document.getElementById('marksSubjectSelect');
            const classSelect = document.getElementById('marksClassLevel');
            
            // Clear current options except the first one
            studentSelect.innerHTML = '<option value="" data-translate="select_student">Select Student</option>';
            subjectSelect.innerHTML = '<option value="" data-translate="select_subject">Select Subject</option>';
            
            // Update class options based on selected year
            if (selectedYear !== '') {
                classSelect.innerHTML = '<option value="" data-translate="select_class">Select Class</option>';
                
                if (selectedYear === '1') {
                    classSelect.innerHTML += `
                        <option value="1A">Year 1 - Class A</option>
                        <option value="1B">Year 1 - Class B</option>
                        <option value="1C">Year 1 - Class C</option>
                    `;
                } else if (selectedYear === '2') {
                    classSelect.innerHTML += `
                        <option value="2A">Year 2 - Class A</option>
                        <option value="2B">Year 2 - Class B</option>
                        <option value="2C">Year 2 - Class C</option>
                    `;
                }
                
                // Restore selected class if it matches the new year
                if (selectedClass && selectedClass.startsWith(selectedYear)) {
                    classSelect.value = selectedClass;
                }
            }
            
            if (selectedYear === '' || selectedClass === '') {
                return; // Don't populate students/subjects if no year or class selected
            }
            
            // Populate students for selected year and class (only active students)
            <?php
            $students_by_year_class = pg_query($conn, "SELECT id, name, year, class_level FROM students WHERE status = 'active' ORDER BY year, class_level, name");
            echo "const allStudents = " . json_encode(pg_fetch_all($students_by_year_class)) . ";\n";
            
            $subjects_by_year = pg_query($conn, "SELECT id, subject_name, year FROM subjects ORDER BY year, subject_name");
            echo "const allSubjects = " . json_encode(pg_fetch_all($subjects_by_year)) . ";\n";
            ?>
            
            // Filter and add students by year and class
            if (allStudents) {
                allStudents.forEach(student => {
                    if (student.year == selectedYear && student.class_level == selectedClass) {
                        const option = document.createElement('option');
                        option.value = student.id;
                        option.textContent = student.name;
                        studentSelect.appendChild(option);
                    }
                });
            }
            
            // Filter and add subjects by year
            if (allSubjects) {
                allSubjects.forEach(subject => {
                    if (subject.year == selectedYear) {
                        const option = document.createElement('option');
                        option.value = subject.id;
                        option.textContent = subject.subject_name;
                        subjectSelect.appendChild(option);
                    }
                });
            }
            
            // Update translations
            updateTranslations();
        }

        // Reports filtering and search functions
        function filterReportsTable() {
            const searchValue = document.getElementById('reportsSearchStudent').value.toLowerCase();
            const yearFilter = document.getElementById('reportsFilterYear')?.value || '';
            const classFilter = document.getElementById('reportsFilterClass')?.value || '';
            const subjectFilter = document.getElementById('reportsFilterSubject')?.value || '';
            const sortByGrade = document.getElementById('sortReportsByGrade')?.value || '';
            
            const table = document.getElementById('reportsTable');
            const rows = Array.from(table.querySelectorAll('tbody tr.student-main-row'));
            let visibleCount = 0;
            let visibleRows = [];

            rows.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const detailsRow = document.getElementById('details-' + studentId);
                
                const cells = row.querySelectorAll('td');
                if (cells.length === 0) return; // Skip empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                const studentYear = row.getAttribute('data-year'); // Get year from data attribute
                const classLevel = cells[2].textContent.trim();
                
                // Get final grade for sorting
                const finalGradeCell = cells[4]; // Final Grade column
                const finalGrade = parseFloat(finalGradeCell.textContent) || 0;
                
                let matches = true;
                
                // Search filter
                if (searchValue && !studentName.includes(searchValue)) {
                    matches = false;
                }
                
                // Year filter
                if (yearFilter && studentYear !== yearFilter) {
                    matches = false;
                }
                
                // Class filter
                if (classFilter && classLevel !== classFilter) {
                    matches = false;
                }
                
                // Subject filter - check if student has the subject in their marks
                if (subjectFilter && detailsRow) {
                    let hasSubject = false;
                    const subjectRows = detailsRow.querySelectorAll('.subject-marks-table tbody tr');
                    subjectRows.forEach(subjRow => {
                        const subjCells = subjRow.querySelectorAll('td');
                        if (subjCells.length > 0) {
                            const subjectName = subjCells[0].textContent.trim();
                            if (subjectName === subjectFilter) {
                                hasSubject = true;
                            }
                        }
                    });
                    if (!hasSubject) {
                        matches = false;
                    }
                }
                
                if (matches) {
                    row.style.display = '';
                    if (detailsRow) detailsRow.style.display = '';
                    visibleCount++;
                    visibleRows.push({ row, detailsRow, finalGrade });
                } else {
                    row.style.display = 'none';
                    if (detailsRow) detailsRow.style.display = 'none';
                }
            });
            
            // Apply sorting if selected
            if (sortByGrade && visibleRows.length > 0) {
                visibleRows.sort((a, b) => {
                    if (sortByGrade === 'asc') {
                        return a.finalGrade - b.finalGrade;
                    } else {
                        return b.finalGrade - a.finalGrade;
                    }
                });
                
                // Reorder rows in the table
                const tbody = table.querySelector('tbody');
                visibleRows.forEach(item => {
                    tbody.appendChild(item.row);
                    if (item.detailsRow) {
                        tbody.appendChild(item.detailsRow);
                    }
                });
            }
            
            // Update results counter
            updateReportsCounter(visibleCount);
            
            // Update summary statistics
            updateReportsSummary(visibleRows);
        }

        function updateReportsSummary(visibleRows) {
            const summaryBox = document.getElementById('reportsSummaryBox');
            if (!summaryBox) return;
            
            if (visibleRows.length === 0) {
                summaryBox.style.display = 'none';
                return;
            }
            
            summaryBox.style.display = 'block';
            
            const grades = visibleRows.map(item => item.finalGrade);
            const total = visibleRows.length;
            const avgGrade = grades.reduce((sum, grade) => sum + grade, 0) / total;
            const maxGrade = Math.max(...grades);
            const minGrade = Math.min(...grades);
            
            document.getElementById('reportsTotalStudents').textContent = total;
            document.getElementById('reportsAvgGrade').textContent = avgGrade.toFixed(2);
            document.getElementById('reportsMaxGrade').textContent = maxGrade.toFixed(2);
            document.getElementById('reportsMinGrade').textContent = minGrade.toFixed(2);
        }

        function clearReportsFilters() {
            document.getElementById('reportsSearchStudent').value = '';
            if (document.getElementById('reportsFilterYear')) {
                document.getElementById('reportsFilterYear').value = '';
            }
            if (document.getElementById('reportsFilterClass')) {
                document.getElementById('reportsFilterClass').value = '';
            }
            if (document.getElementById('reportsFilterSubject')) {
                document.getElementById('reportsFilterSubject').value = '';
            }
            if (document.getElementById('sortReportsByGrade')) {
                document.getElementById('sortReportsByGrade').value = '';
            }
            updateReportsSubjectFilter();
            filterReportsTable();
        }

        function updateReportsCounter(count) {
            let counter = document.getElementById('reportsCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#reportsTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'reportsCounter';
                counter.style.cssText = 'text-align: right; margin-bottom: 1rem; font-size: 0.95rem; color: var(--text-color); font-weight: 500; padding: 0.5rem 1rem; background: var(--card-bg); border-radius: 6px; display: inline-block; float: right;';
                // Insert at the top (before the table container)
                tableContainer.insertBefore(counter, tableContainer.firstChild);
            }
            
            const total = document.querySelectorAll('#reportsTable tbody tr.student-main-row').length;
            const showingText = getTranslation('showing') || 'Showing';
            const ofText = getTranslation('of') || 'of';
            const studentsText = getTranslation('students') || 'students';
            counter.textContent = `${showingText} ${count} ${ofText} ${total} ${studentsText}`;
        }

        function updateMarksCounter(count) {
            let counter = document.getElementById('marksCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#marksTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'marksCounter';
                counter.style.cssText = 'text-align: right; margin-bottom: 1rem; font-size: 0.95rem; color: var(--text-color); font-weight: 500; padding: 0.5rem 1rem; background: var(--card-bg); border-radius: 6px; display: inline-block; float: right;';
                // Insert at the top (before the table container)
                tableContainer.insertBefore(counter, tableContainer.firstChild);
            }
            
            const total = document.querySelectorAll('#marksTable tbody tr.student-main-row').length;
            const showingText = getTranslation('showing') || 'Showing';
            const ofText = getTranslation('of') || 'of';
            const studentsText = getTranslation('students') || 'students';
            counter.textContent = `${showingText} ${count} ${ofText} ${total} ${studentsText}`;
        }

        // Initialize marks counter on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('marksTable')) {
                setTimeout(() => {
                    const totalRows = document.querySelectorAll('#marksTable tbody tr.student-main-row').length;
                    updateMarksCounter(totalRows);
                }, 100);
            }
            
            if (document.getElementById('studentsTable')) {
                setTimeout(() => {
                    const totalRows = document.querySelectorAll('#studentsTable tbody tr').length;
                    updateStudentsCounter(totalRows);
                }, 100);
            }
            
            if (document.getElementById('reportsTable')) {
                setTimeout(() => {
                    const totalRows = document.querySelectorAll('#reportsTable tbody tr.student-main-row').length;
                    updateReportsCounter(totalRows);
                }, 100);
            }
        });

        // Students filtering and search functions
        function filterStudents() {
            const searchValue = document.getElementById('searchStudents').value.toLowerCase();
            const classFilter = document.getElementById('filterStudentClass').value;
            const genderFilter = document.getElementById('filterStudentGender').value;
            const ageFilter = document.getElementById('filterStudentAge').value;
            const enrollmentFilter = document.getElementById('filterStudentEnrollment').value;
            const yearFilter = document.getElementById('filterStudentYear').value;
            
            // Update class dropdown based on year filter
            updateClassFilterOptions(yearFilter);
            
            const table = document.getElementById('studentsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            let visibleCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                
                if (cells.length === 0) continue; // Skip header or empty rows
                
                const studentName = cells[1].textContent.toLowerCase();
                const studentAge = parseInt(cells[2].textContent) || 0;
                const studentGender = cells[3].textContent.trim();
                const studentClass = cells[4].textContent.trim();
                const studentYear = cells[5].textContent.trim();
                const studentPhone = cells[6].textContent.toLowerCase();
                const studentSubjects = cells[7].textContent.trim();
                
                // Check search criteria
                const matchesSearch = searchValue === '' || 
                    studentName.includes(searchValue) || 
                    studentPhone.includes(searchValue);
                
                const matchesClass = classFilter === '' || studentClass === classFilter;
                const matchesGender = genderFilter === '' || studentGender === genderFilter;
                
                // Year matching - extract numeric year from badge to work with all languages
                const yearMatch = studentYear.match(/\d+/);
                const studentYearNumber = yearMatch ? yearMatch[0] : '';
                const matchesYear = yearFilter === '' || studentYearNumber === yearFilter;
                
                // Age range matching
                let matchesAge = true;
                if (ageFilter !== '') {
                    switch(ageFilter) {
                        case '15-17':
                            matchesAge = studentAge >= 15 && studentAge <= 17;
                            break;
                        case '18-20':
                            matchesAge = studentAge >= 18 && studentAge <= 20;
                            break;
                        case '21-25':
                            matchesAge = studentAge >= 21 && studentAge <= 25;
                            break;
                        case '26+':
                            matchesAge = studentAge >= 26;
                            break;
                    }
                }
                
                // Enrollment matching
                let matchesEnrollment = true;
                if (enrollmentFilter !== '') {
                    if (enrollmentFilter === 'enrolled') {
                        matchesEnrollment = studentSubjects !== 'No subjects' && studentSubjects.trim() !== '';
                    } else if (enrollmentFilter === 'not-enrolled') {
                        matchesEnrollment = studentSubjects === 'No subjects' || studentSubjects.trim() === '';
                    }
                }
                
                // Show/hide row based on all criteria
                if (matchesSearch && matchesClass && matchesGender && matchesAge && matchesEnrollment && matchesYear) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Update results counter
            updateStudentsCounter(visibleCount);
        }
        
        // Update class filter options based on selected year
        function updateClassFilterOptions(yearFilter) {
            const classSelect = document.getElementById('filterStudentClass');
            const currentValue = classSelect.value;
            
            // Clear existing options except "All Classes"
            classSelect.innerHTML = '<option value="" data-translate="all_classes">All Classes</option>';
            
            if (yearFilter === '') {
                // Show all classes if no year selected
                const allClasses = ['1A', '1B', '1C', '2A', '2B', '2C'];
                allClasses.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = 'Class ' + cls;
                    option.textContent = 'Class ' + cls;
                    classSelect.appendChild(option);
                });
            } else {
                // Show only classes for the selected year
                const classes = ['A', 'B', 'C'];
                classes.forEach(cls => {
                    const option = document.createElement('option');
                    const classValue = 'Class ' + yearFilter + cls;
                    option.value = classValue;
                    option.textContent = classValue;
                    classSelect.appendChild(option);
                });
            }
            
            // Restore previous value if it's still valid
            if (currentValue && Array.from(classSelect.options).some(opt => opt.value === currentValue)) {
                classSelect.value = currentValue;
            } else {
                classSelect.value = ''; // Reset to "All Classes"
            }
        }

        function clearStudentsFilters() {
            document.getElementById('searchStudents').value = '';
            document.getElementById('filterStudentClass').value = '';
            document.getElementById('filterStudentGender').value = '';
            document.getElementById('filterStudentAge').value = '';
            document.getElementById('filterStudentEnrollment').value = '';
            document.getElementById('filterStudentYear').value = '';
            filterStudents();
        }

        function filterSubjects() {
            const yearFilter = document.getElementById('yearFilter').value;
            const subjectFilter = document.getElementById('subjectFilter').value;
            const classFilter = document.getElementById('classFilter').value;
            const teacherFilter = document.getElementById('teacherFilter').value;
            const table = document.getElementById('subjectsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            // Update subject and class filter options based on selected year
            updateYearSpecificFilters(yearFilter);
            
            let visibleCount = 0;
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const yearData = row.getAttribute('data-year');
                const subjectData = row.getAttribute('data-subject') || '';
                const classData = row.getAttribute('data-class') || '';
                const teacherData = row.getAttribute('data-teacher') || '';
                
                const matchesYear = yearFilter === '' || yearData === yearFilter;
                const matchesSubject = subjectFilter === '' || subjectData === subjectFilter;
                const matchesClass = classFilter === '' || classData === classFilter;
                const matchesTeacher = teacherFilter === '' || teacherData === teacherFilter;
                
                if (matchesYear && matchesSubject && matchesClass && matchesTeacher) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Update counter if it exists
            updateSubjectsCounter(visibleCount);
        }
        
        function updateYearSpecificFilters(selectedYear) {
            const table = document.getElementById('subjectsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            const subjectFilterSelect = document.getElementById('subjectFilter');
            const classFilterSelect = document.getElementById('classFilter');
            const currentSubject = subjectFilterSelect.value;
            const currentClass = classFilterSelect.value;
            
            if (selectedYear === '') {
                // Show all subjects
                const availableSubjects = new Set();
                for (let i = 0; i < rows.length; i++) {
                    const subjectData = rows[i].getAttribute('data-subject');
                    if (subjectData) {
                        availableSubjects.add(subjectData);
                    }
                }
                
                let subjectsHTML = `<option value="" data-translate="all_subjects">${getTranslation('all_subjects')}</option>`;
                Array.from(availableSubjects).sort().forEach(subject => {
                    subjectsHTML += `<option value="${subject}">${subject}</option>`;
                });
                subjectFilterSelect.innerHTML = subjectsHTML;
                
                // Show all classes
                classFilterSelect.innerHTML = `
                    <option value="" data-translate="all_classes">${getTranslation('all_classes')}</option>
                    <option value="A" data-translate="class_a">Class A</option>
                    <option value="B" data-translate="class_b">Class B</option>
                    <option value="C" data-translate="class_c">Class C</option>
                `;
            } else {
                // Get available subjects and classes for the selected year
                const availableSubjects = new Set();
                const availableClasses = new Set();
                
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const yearData = row.getAttribute('data-year');
                    
                    if (yearData === selectedYear) {
                        const subjectData = row.getAttribute('data-subject');
                        const classData = row.getAttribute('data-class');
                        
                        if (subjectData) {
                            availableSubjects.add(subjectData);
                        }
                        if (classData) {
                            availableClasses.add(classData);
                        }
                    }
                }
                
                // Build subject options
                let subjectsHTML = `<option value="" data-translate="all_subjects">${getTranslation('all_subjects')}</option>`;
                Array.from(availableSubjects).sort().forEach(subject => {
                    subjectsHTML += `<option value="${subject}">${subject}</option>`;
                });
                subjectFilterSelect.innerHTML = subjectsHTML;
                
                // Build class options
                let classesHTML = `<option value="" data-translate="all_classes">${getTranslation('all_classes')}</option>`;
                ['A', 'B', 'C'].forEach(cls => {
                    if (availableClasses.has(cls)) {
                        classesHTML += `<option value="${cls}" data-translate="class_${cls.toLowerCase()}">Class ${cls}</option>`;
                    }
                });
                classFilterSelect.innerHTML = classesHTML;
            }
            
            // Restore previous selections if still available
            const subjectOptions = subjectFilterSelect.options;
            for (let i = 0; i < subjectOptions.length; i++) {
                if (subjectOptions[i].value === currentSubject) {
                    subjectFilterSelect.value = currentSubject;
                    break;
                }
            }
            
            const classOptions = classFilterSelect.options;
            for (let i = 0; i < classOptions.length; i++) {
                if (classOptions[i].value === currentClass) {
                    classFilterSelect.value = currentClass;
                    break;
                }
            }
        }
        
        // Keep old function name for compatibility
        function filterSubjectsByYear() {
            filterSubjects();
        }

        function updateSubjectsCounter(count) {
            let counter = document.getElementById('subjectsCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#subjectsTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'subjectsCounter';
                counter.style.cssText = 'text-align: right; margin-bottom: 1rem; font-size: 0.95rem; color: var(--text-color); font-weight: 500; padding: 0.5rem 1rem; background: var(--card-bg); border-radius: 6px; display: inline-block; float: right;';
                // Insert at the top (before the table container)
                tableContainer.insertBefore(counter, tableContainer.firstChild);
            }
            const showingText = getTranslation('showing') || 'Showing';
            const subjectsText = getTranslation('subjects') || 'subjects';
            counter.textContent = `${showingText} ${count} ${subjectsText}`;
        }

        // Filter subjects in enrollment section based on student's academic year
        function filterSubjectsByStudentYear() {
            const selectedYear = document.getElementById('studentYearSelect').value;
            const subjectItems = document.querySelectorAll('.subject-item');
            const container = document.getElementById('subjectEnrollmentContainer');
            const selectAllContainer = document.getElementById('selectAllSubjectsContainer');
            const selectAllCheckbox = document.getElementById('selectAllSubjects');
            
            // Remove existing message
            const existingMessage = container.querySelector('.no-subjects-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            if (selectedYear === '') {
                // Hide all subjects and select all checkbox when no year is selected
                subjectItems.forEach(item => {
                    item.style.display = 'none';
                    // Uncheck all subjects
                    const checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
                
                // Hide select all checkbox
                if (selectAllContainer) {
                    selectAllContainer.style.display = 'none';
                }
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
                
                // Show message to select year first
                const message = document.createElement('div');
                message.className = 'no-subjects-message';
                message.style.cssText = 'color: #666; text-align: center; padding: 20px; font-style: italic;';
                message.setAttribute('data-translate', 'please_select_year_first');
                message.textContent = getTranslation('please_select_year_first');
                container.appendChild(message);
                return;
            }
            
            let visibleCount = 0;
            subjectItems.forEach(item => {
                const subjectYear = item.getAttribute('data-subject-year');
                
                if (subjectYear === selectedYear) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                    // Uncheck hidden subjects
                    const checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                }
            });
            
            // Show/hide select all checkbox based on visible subjects
            if (visibleCount > 0 && selectAllContainer) {
                selectAllContainer.style.display = 'block';
                // Reset select all checkbox
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
            } else {
                if (selectAllContainer) {
                    selectAllContainer.style.display = 'none';
                }
            }
            
            // Show message if no subjects match the selected year
            if (visibleCount === 0) {
                const message = document.createElement('div');
                message.className = 'no-subjects-message';
                message.style.cssText = 'color: #666; text-align: center; padding: 20px; font-style: italic;';
                message.textContent = `No subjects available for Year ${selectedYear}`;
                container.appendChild(message);
            }
        }

        // Toggle all subject checkboxes
        function toggleAllSubjects(checkbox) {
            const subjectItems = document.querySelectorAll('.subject-item');
            const isChecked = checkbox.checked;
            
            subjectItems.forEach(item => {
                // Only toggle visible subjects (those matching the selected year)
                if (item.style.display !== 'none') {
                    const subjectCheckbox = item.querySelector('input[type="checkbox"]');
                    if (subjectCheckbox) {
                        subjectCheckbox.checked = isChecked;
                    }
                }
            });
        }

        // Filter subjects in edit modal based on student's academic year
        function filterEditSubjectsByStudentYear() {
            const selectedYear = document.getElementById('editStudentYearSelect').value;
            const subjectItems = document.querySelectorAll('.edit-subject-item');
            const container = document.getElementById('editSubjectEnrollmentContainer');
            
            // Remove existing message
            const existingMessage = container.querySelector('.no-edit-subjects-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            if (selectedYear === '') {
                // Hide all subjects when no year is selected
                subjectItems.forEach(item => {
                    item.style.display = 'none';
                });
                
                // Show message to select year first
                const message = document.createElement('div');
                message.className = 'no-edit-subjects-message';
                message.style.cssText = 'color: #666; text-align: center; padding: 20px; font-style: italic;';
                message.setAttribute('data-translate', 'please_select_year_first');
                message.textContent = getTranslation('please_select_year_first');
                container.appendChild(message);
                return;
            }
            
            let visibleCount = 0;
            subjectItems.forEach(item => {
                const subjectYear = item.getAttribute('data-edit-subject-year');
                
                if (subjectYear === selectedYear) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                    // Don't uncheck hidden subjects in edit mode to preserve existing enrollments
                }
            });
            
            // Show message if no subjects match the selected year
            if (visibleCount === 0) {
                const message = document.createElement('div');
                message.className = 'no-edit-subjects-message';
                message.style.cssText = 'color: #666; text-align: center; padding: 20px; font-style: italic;';
                message.textContent = `No subjects available for Year ${selectedYear}`;
                container.appendChild(message);
            }
        }

        // Function to update class options based on selected year
        function updateClassOptions() {
            const yearSelect = document.getElementById('studentYearSelect');
            const classSelect = document.getElementById('studentClassSelect');
            
            if (!yearSelect || !classSelect) return;
            
            const selectedYear = yearSelect.value;
            
            // Clear current options except the default
            classSelect.innerHTML = '<option value="" data-translate="select_class">Select Class</option>';
            
            if (selectedYear === '1') {
                classSelect.innerHTML += `
                    <option value="1A">Year 1 - Class A</option>
                    <option value="1B">Year 1 - Class B</option>
                    <option value="1C">Year 1 - Class C</option>
                `;
            } else if (selectedYear === '2') {
                classSelect.innerHTML += `
                    <option value="2A">Year 2 - Class A</option>
                    <option value="2B">Year 2 - Class B</option>
                    <option value="2C">Year 2 - Class C</option>
                `;
            }
            
            // Reset class selection
            classSelect.value = '';
        }

        // Function to update edit modal class options based on selected year
        function updateEditClassOptions() {
            const yearSelect = document.getElementById('editStudentYearSelect');
            const classSelect = document.getElementById('editStudentClassSelect');
            
            if (!yearSelect || !classSelect) return;
            
            const selectedYear = yearSelect.value;
            const currentValue = classSelect.value;
            
            // Clear current options except the default
            classSelect.innerHTML = '<option value="" data-translate="select_class">Select Class</option>';
            
            if (selectedYear === '1') {
                classSelect.innerHTML += `
                    <optgroup label="${getTranslation('year_1')}">
                        <option value="1A" data-translate="year_1_class_a">Year 1 - Class A</option>
                        <option value="1B" data-translate="year_1_class_b">Year 1 - Class B</option>
                        <option value="1C" data-translate="year_1_class_c">Year 1 - Class C</option>
                    </optgroup>
                `;
            } else if (selectedYear === '2') {
                classSelect.innerHTML += `
                    <optgroup label="${getTranslation('year_2')}">
                        <option value="2A" data-translate="year_2_class_a">Year 2 - Class A</option>
                        <option value="2B" data-translate="year_2_class_b">Year 2 - Class B</option>
                        <option value="2C" data-translate="year_2_class_c">Year 2 - Class C</option>
                    </optgroup>
                `;
            }
            
            // Apply translations to the newly added options
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            classSelect.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.getAttribute('data-translate');
                if (translations[currentLang] && translations[currentLang][key]) {
                    element.textContent = translations[currentLang][key];
                }
            });
            
            // Try to maintain selection if it matches the new year
            if (currentValue && currentValue.startsWith(selectedYear)) {
                classSelect.value = currentValue;
            } else {
                classSelect.value = '';
            }
        }

        // Function to update marks class filter based on selected year
        function updateMarksClassFilter() {
            const selectedYear = document.getElementById('filterMarksYear').value;
            const classFilter = document.getElementById('filterMarksClass');
            
            if (!classFilter) return;
            
            // Clear current options except the default
            const allClassesText = getTranslation('all_classes');
            classFilter.innerHTML = `<option value="">${allClassesText}</option>`;
            
            if (selectedYear === '') {
                // If no year selected, show all classes
                <?php
                $all_classes = pg_query($conn, "
                    SELECT DISTINCT class_level 
                    FROM students 
                    WHERE status = 'active' AND class_level ~ '^[12][ABC]$' 
                    ORDER BY class_level
                ");
                
                if ($all_classes && pg_num_rows($all_classes) > 0) {
                    echo "const allClasses = [];\n";
                    while($class = pg_fetch_assoc($all_classes)) {
                        echo "allClasses.push('" . htmlspecialchars($class['class_level']) . "');\n";
                    }
                    echo "
                    allClasses.forEach(className => {
                        const option = document.createElement('option');
                        option.value = className;
                        const year = className.charAt(0);
                        const letter = className.charAt(1);
                        const yearText = getTranslation('year');
                        const classText = getTranslation('class');
                        option.textContent = `\${yearText} \${year} - \${classText} \${letter}`;
                        classFilter.appendChild(option);
                    });
                    ";
                }
                ?>
            } else {
                // Show only classes for selected year
                const classes = ['A', 'B', 'C'];
                const yearText = getTranslation('year');
                const classText = getTranslation('class');
                classes.forEach(letter => {
                    const className = selectedYear + letter;
                    const option = document.createElement('option');
                    option.value = className;
                    option.textContent = `${yearText} ${selectedYear} - ${classText} ${letter}`;
                    classFilter.appendChild(option);
                });
            }
        }

        function updateStudentsCounter(count) {
            let counter = document.getElementById('studentsCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#studentsTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'studentsCounter';
                counter.style.cssText = 'text-align: right; margin-bottom: 1rem; font-size: 0.95rem; color: var(--text-color); font-weight: 500; padding: 0.5rem 1rem; background: var(--card-bg); border-radius: 6px; display: inline-block; float: right;';
                // Insert at the top (before the table container)
                tableContainer.insertBefore(counter, tableContainer.firstChild);
            }
            
            const total = document.querySelectorAll('#studentsTable tbody tr').length;
            const showingText = getTranslation('showing') || 'Showing';
            const ofText = getTranslation('of') || 'of';
            const studentsText = getTranslation('students') || 'students';
            counter.textContent = `${showingText} ${count} ${ofText} ${total} ${studentsText}`;
        }

        // Language Translation System
        const translations = {
            en: {
                // Login Page
                page_title: "Login - Academic Management System",
                login_subtitle: "Sign in to access your dashboard",
                username: "Username",
                password: "Password",
                sign_in: "Sign In",
                signing_in: "Signing In...",
                access_levels: "Access Levels",
                administrator: "Administrator",
                full_system_control: "Full System Control",
                subject_management: "Subject Management",
                secure_portal: "Secure Academic Portal",
                error_invalid_credentials: "Invalid username or password",
                error_empty_fields: "Please enter both username and password",
                // Navigation
                nav_reports: "Dashboard",
                nav_dashboard: "Dashboard",
                nav_access: "Access",
                nav_students: "Students", 
                nav_graduated: "Graduated",
                nav_subjects: "Subjects",
                nav_marks: "Marks",
                logout: "Logout",
                system_title: "Student Management",
                system_subtitle: "Academic Portal",
                weekly_schedule: "Weekly Schedule",
                class_a_schedule: "Class A - Weekly Academic Schedule",
                class_b_schedule: "Class B - Weekly Academic Schedule", 
                class_c_schedule: "Class C - Weekly Academic Schedule",
                day: "Day",
                schedule: "Schedule",
                sunday: "Sunday",
                monday: "Monday",
                tuesday: "Tuesday",
                wednesday: "Wednesday",
                thursday: "Thursday",
                break: "Break",
                academic_year: "Academic Year",
                select_year: "Select Year",
                year_1: "Year 1",
                year_2: "Year 2",
                filter_by_year: "Filter by Year",
                all_years: "All Years",
                filter_by_class: "Filter by Class",
                all_classes: "All Classes",
                select_class: "Select Class",
                delete_selected: "Delete Selected",
                actions: "Actions",
                enroll_in_subjects: "Enroll in Subjects",
                select_all_subjects: "Select All Subjects",
                please_select_year_first: "Please select an Academic Year first to see available subjects",
                no_subjects_available: "No subjects available. Please add subjects first.",
                reports_title: "Student Management System - Reports Dashboard",
                students_title: "Students Management",
                graduated_title: "Graduated Students",
                manage_graduated_students: "View all graduated students",
                graduated_list: "Graduated Students List",
                graduation_date: "Graduation Date",
                subjects_title: "Subjects Management", 
                marks_title: "Marks Management",
                year_1_students: "Year 1 Students",
                year_2_students: "Year 2 Students", 
                graduated_students: "Graduated Students",
                active_students: "Active Students",
                successfully_graduated: "Successfully Graduated",
                top_performing_year: "Top Performing Year",
                top_class_performance: "Top Class Performance",
                pass_rate: "Pass Rate",
                passing_students: "Passing Students",
                risk_subject: "Risk Subject",
                attendance_rate: "Attendance Rate",
                student_engagement: "Student Engagement",
                difficulty_index: "Difficulty Index",
                curriculum_balance: "Curriculum Balance",
                enrolled_students: "Enrolled Students",
                with_marks: "With Marks",
                excellence_rate: "Excellence Rate",
                a_plus_students: "A+ Students (90+)",
                view_data_for: "View Data For",
                year_1_only: "Year 1 Only",
                year_2_only: "Year 2 Only",
                total_enrolls: "Total Enrolls",
                student_distribution: "Student Distribution",
                total_overview: "Total Overview",
                all_years: "All Years",
                student_status: "Student Status",
                academic_year: "Academic Year",
                select_academic_year: "Select Academic Year",
                add_new_student: "Add New Student",
                add_new_subject: "Add New Subject",
                add_new_mark: "Add New Mark",
                add_subject: "Add Subject",
                search_filter: "Search & Filter",
                search_filter_reports: "Search & Filter Reports",
                search_filter_students: "Search & Filter Students",
                subjects_list: "Subjects List",
                student_name: "Student Name",
                subject_name: "Subject Name",
                class_level: "Class Level",
                actions: "Actions",
                action: "Action",
                edit: "Edit",
                delete: "Delete",
                promote: "Promote",
                graduate: "Graduate",
                save: "Save",
                update: "Update",
                cancel: "Cancel",
                grade_distribution: "Grade Distribution",
                subject_performance: "Subject Performance", 
                top_performers: "Top 3 Performers",
                performance_trends: "Performance Trends",
                detailed_reports: "Detailed Reports",
                export_csv: "Export CSV",
                print: "Print",
                full_name: "Full Name",
                name: "Name",
                age: "Age",
                gender: "Gender",
                class: "Class",
                email: "Email",
                phone: "Phone",
                subjects: "Subjects",
                id: "ID",
                credits: "Credits",
                select_gender: "Select Gender",
                select_class: "Select Class",
                male: "Male",
                female: "Female",
                description: "Description",
                final_exam: "Final Exam",
                midterm_exam: "Midterm Exam",
                quizzes: "Quizzes",
                daily_activities: "Daily Activities",
                total_mark: "Total Mark",
                grade: "Grade",
                filter_by_class: "Filter by Class",
                filter_by_subject: "Filter by Subject",
                filter_by_teacher: "Filter by Teacher",
                filter_by_grade: "Filter by Grade",
                filter_by_gender: "Filter by Gender",
                filter_by_age_range: "Filter by Age Range",
                filter_by_enrollment: "Filter by Enrollment",
                all_classes: "All Classes",
                all_subjects: "All Subjects",
                all_teachers: "All Teachers",
                all_grades: "All Grades",
                all_genders: "All Genders",
                all_ages: "All Ages",
                all_students: "All Students",
                sort_by_final_grade: "Sort by Final Grade",
                sort_default: "Default",
                sort_grade_low_high: "Grade: Low to High",
                sort_grade_high_low: "Grade: High to Low",
                clear_filters: "Clear Filters",
                collapse_all: "Collapse All",
                search: "Search",
                search_student: "Search Student",
                search_subject: "Search Subject",
                search_by_name: "Search by name, email, or phone...",
                analytics_dashboard: "Analytics Dashboard",
                comprehensive_overview: "Comprehensive Student Performance Overview",
                total_students: "Total Students",
                all_enrolled: "All enrolled",
                total_subjects: "Total Subjects",
                available_courses: "Available courses",
                average_score: "Average Score",
                overall_performance: "Overall performance",
                top_performing_class: "Top Performing Class",
                manage_student_info: "Manage student information and enrollment",
                input_manage_marks: "Input and manage student marks",
                // Teacher Management Translations
                nav_teachers: "Teachers",
                teachers_title: "Teachers Management",
                manage_teacher_info: "Manage teachers and subject assignments",
                add_new_teacher: "Add New Teacher",
                teachers_list: "Teachers List",
                teacher: "Teacher",
                teachers: "Teachers",
                specialization: "Specialization",
                select_specialization: "Select Specialization",
                select_degree: "Select Degree",
                degree_high_school: "High School Diploma",
                degree_associate: "Associate Degree",
                degree_bachelor: "Bachelor's Degree",
                degree_master: "Master's Degree",
                degree_doctorate: "Doctorate (PhD)",
                degree_certificate: "Professional Certificate",
                no_subject_assignment: "No subject assignment",
                select_subject_first: "Select subject first",
                no_class_assignment: "No class assignment",
                assigned_subjects: "Assigned Subjects",
                no_subject_assignments: "No subject assignments yet",
                assign_new_subject: "Assign New Subject",
                join_date: "Join Date",
                add_teacher: "Add Teacher",
                edit_teacher: "Edit Teacher",
                delete_teacher: "Delete Teacher",
                view_teacher: "View Teacher",
                assign_subjects: "Assign Subjects",
                remove_assignment: "Remove Assignment",
                assigned: "Assigned",
                remove: "Remove",
                teacher_email: "teacher@school.edu",
                filter_specialization: "Filter by Specialization",
                filter_degree: "Filter by Degree",
                filter_year: "Filter by Year",
                filter_class: "Filter by Class",
                view: "View",
                degree: "Degree",
                salary: "Salary",
                search_teachers: "Search by name, email...",
                no_teachers: "No teachers added yet. Click 'Add New Teacher' to get started.",
                no_assignments: "No Assignments",
                assigned_date: "Assigned Date",
                filter_status: "Filter by Status",
                active: "Active",
                inactive: "Inactive",
                all: "All",
                // Teacher Access Management
                teacher_access_title: "Teacher Access Management",
                teacher_access_subtitle: "Manage teacher login credentials and system access",
                total_teachers: "Total Teachers",
                active_logins: "Active Logins",
                no_access: "No Access",
                subjects_no_teacher: "Subjects Without Teacher",
                teacher_credential_mgmt: "Teacher Credential Management",
                print_btn: "Print",
                search_teacher_label: "Search Teacher",
                search_by_name: "Search by name...",
                filter_by_spec: "Filter by Specialization",
                all_specializations: "All Specializations",
                all_statuses: "All",
                has_login: "Has Login",
                no_login_option: "No Login",
                filter_by_degree: "Filter by Degree",
                all_degrees: "All Degrees",
                th_id: "ID",
                th_teacher_name: "Teacher Name",
                th_contact_email: "Contact Email",
                th_specialization: "Specialization",
                th_degree: "Degree",
                th_login_username: "Login Username",
                th_status: "Status",
                th_subjects: "Subjects",
                th_actions: "Actions",
                not_set: "Not Set",
                status_active: "Active",
                update_access: "Update Access",
                create_access: "Create Access",
                no_teachers_found: "No teachers found",
                create_teacher_access: "Create Teacher Access",
                modal_teacher_name: "Teacher Name",
                modal_login_username: "Login Email/Username",
                modal_username_placeholder: "teacher.email@school.edu",
                modal_username_hint: "This will be used for login",
                modal_password: "Password",
                modal_password_placeholder: "Enter new password",
                modal_password_hint: "Minimum 6 characters. Leave blank to keep current password.",
                modal_cancel: "Cancel",
                modal_save: "Save Credentials",
                chart_teachers_by_spec: "Teachers by Specialization",
                chart_teachers_by_degree: "Teachers by Degree",
                chart_teachers_by_subject: "Teachers by Subject",
                // Teacher Dashboard (Tasks)
                academic_task_manager: "Academic Task Manager",
                welcome: "Welcome",
                manage_teaching_schedule: "Manage your teaching schedule and academic tasks",
                subjects_teaching: "Subjects Teaching",
                kpi_total_students: "Total Students",
                students_pending_marks: "Students Pending Marks",
                overdue_tasks: "Overdue Tasks",
                create_new_task: "Create New Task",
                task_type: "Task Type",
                task_examination: "Examination",
                task_homework: "Homework Assignment",
                task_reminder: "Reminder",
                task_note: "General Note",
                all_subjects: "All Subjects",
                task_title: "Title",
                task_title_placeholder: "e.g., Midterm Exam - Chapter 1-3",
                task_description: "Description",
                task_description_placeholder: "Additional details...",
                task_due_date: "Due Date",
                task_priority: "Priority",
                priority_low: "Low",
                priority_medium: "Medium",
                priority_high: "High",
                create_task_btn: "Create Task",
                my_subjects: "My Subjects",
                students_label: "Students",
                view_marks: "View Marks",
                no_subjects_assigned: "No subjects assigned yet",
                // End Teacher Translations
                marks_list: "Marks List",
                search_filter_marks: "Search & Filter Marks",
                add_mark: "Add Mark",
                student: "Student",
                subject: "Subject",
                status: "Status",
                midterm: "Midterm",
                final: "Final",
                daily: "Daily",
                total: "Total",
                final_grade: "Final Grade",
                graduation_grade: "Graduation Grade",
                students_count: "students",
                showing: "Showing",
                of: "of",
                students: "students",
                subjects: "subjects",
                manage_marks: "Manage Marks",
                subject_marks_title: "Subject Marks - Click Edit to modify",
                final_exam: "Final (60)",
                midterm_exam: "Midterm (20)",
                quiz: "Quiz (10)",
                daily_activities: "Daily (10)",
                select_student: "Select Student",
                edit: "Edit",
                delete: "Delete",
                view_details: "View Details",
                subject_marks: "Subject Marks",
                total_credits: "Total Credits",
                year_1_student: "Year 1 Student",
                year_2_student: "Year 2 Student",
                unknown: "Unknown",
                male: "Male",
                female: "Female",
                n_a: "N/A",
                year: "Year",
                edit_student: "Edit Student",
                full_name: "Full Name",
                select_gender: "Select Gender",
                select_class: "Select Class",
                cancel: "Cancel",
                update_student: "Update Student",
                subject_enrollment: "Subject Enrollment",
                credits: "credits",
                tip_enroll: "Tip: Check/uncheck subjects to enroll or unenroll the student. Unchecking will remove all marks for that subject.",
                class_a: "Class A",
                class_b: "Class B", 
                class_c: "Class C",
                year_1_class_a: "Year 1 - Class A",
                year_1_class_b: "Year 1 - Class B",
                year_1_class_c: "Year 1 - Class C",
                year_2_class_a: "Year 2 - Class A",
                year_2_class_b: "Year 2 - Class B",
                year_2_class_c: "Year 2 - Class C",
                edit_mark: "Edit Mark",
                mark_calculation: "Mark Calculation",
                current_total: "Current Total",
                update_mark: "Update Mark",
                update_subject: "Update Subject",
                edit_subject: "Edit Subject",
                subject_name: "Subject Name",
                cannot_promote_student: "Cannot Promote Student",
                cannot_graduate_student: "Cannot Graduate Student",
                student_not_meet_requirements: "Student does not meet requirements",
                failed_subjects_label: "Failed Subjects (Mark < 50)",
                requirements: "Requirements",
                action_required: "Action Required",
                update_failed_subjects: "Update failed subjects to have marks ≥ 50",
                increase_marks: "Increase marks to reach minimum final grade of 25",
                review_marks: "Review all subject marks before trying again",
                ok_understand: "OK, I Understand",
                bulk_promote_graduate: "Bulk Promote/Graduate",
                bulk_action: "Bulk Promote/Graduate",
                bulk_action_desc: "Select which year students you want to promote or graduate. Only eligible students will be processed.",
                promote_year_1: "Promote Year 1 Students",
                year_1_to_year_2: "Move eligible Year 1 students to Year 2",
                graduate_year_2: "Graduate Year 2 Students",
                year_2_to_graduated: "Move eligible Year 2 students to graduated",
                eligible: "Eligible",
                checking_eligibility: "Checking Eligibility",
                counting_eligible_students: "Counting eligible students...",
                validating_records: "Validating student records...",
                confirm_promote_student: "Are you sure you want to promote this student from Year 1 to Year 2?",
                confirm_graduate_student: "Are you sure you want to graduate this student? This will move them to the graduated students list.",
                student_promoted_success: "Student promoted to Year 2!",
                student_graduated_success: "Student graduated successfully!",
                network_error: "Network error. Please try again.",
                promote_to_year_2: "Promote to Year 2",
                select_year_2_subjects: "Select Year 2 subjects for this student",
                student_eligible_promotion: "Student Eligible for Promotion",
                all_requirements_met: "All requirements met! Select Year 2 subjects to enroll.",
                available_year_2_subjects: "Available Year 2 Subjects",
                promote_student: "Promote Student",
                error_loading_subjects: "Error loading subjects. Please try again.",
                select_at_least_one_subject: "Please select at least one Year 2 subject.",
                promoting_student: "Promoting Student",
                moving_to_year_2: "Moving to Year 2...",
                error_promoting_student: "Error promoting student",
                loading: "Loading",
                saving: "Saving",
                switching_language: "Switching Language",
                please_wait: "Please wait...",
                total_final_grade: "Total Final Grade",
                required: "Required",
                failed_subjects: "Failed Subjects",
                my_tasks_schedule: "My Tasks & Schedule",
                no_tasks_yet: "No tasks yet. Create your first task above to get started.",
                analytics_report: "Analytics Report",
                generated_on: "Generated on",
                no_data_to_print: "No data to print",
                no_marks_available: "No marks available",
                average: "Average",
                time: "Time",
                sunday: "Sunday",
                monday: "Monday",
                tuesday: "Tuesday",
                wednesday: "Wednesday",
                thursday: "Thursday",
                select_subject: "Select Subject",
                final_exam_range: "Final Exam (0-60)",
                midterm_range: "Midterm (0-20)",
                quizzes_range: "Quizzes (0-10)",
                daily_range: "Daily Activities (0-10)",
                enter_full_name: "Enter student's full name",
                student_age: "Student age",
                email_placeholder: "student@example.com",
                search_by_student_name: "Search by student name...",
                search_by_student_subject: "Search by student name or subject...",
                filter_by_status: "Filter by Status",
                all_status: "All Status",
                manage_subjects_curriculum: "Manage subjects and curriculum",
                phone_number: "Phone Number",
                enter_phone_number: "Enter phone number",
                add_student: "Add Student",
                reset_form: "Reset Form",
                students_list: "Students List",
                email_placeholder_student: "Enter email address..."
            },
            ar: {
                // Login Page
                page_title: "تسجيل الدخول - نظام الإدارة الأكاديمية",
                login_subtitle: "قم بتسجيل الدخول للوصول إلى لوحة التحكم",
                username: "اسم المستخدم",
                password: "كلمة المرور",
                sign_in: "تسجيل الدخول",
                signing_in: "جاري تسجيل الدخول...",
                access_levels: "مستويات الوصول",
                administrator: "المسؤول",
                full_system_control: "التحكم الكامل بالنظام",
                subject_management: "إدارة المواد",
                secure_portal: "بوابة أكاديمية آمنة",
                error_invalid_credentials: "اسم المستخدم أو كلمة المرور غير صحيحة",
                error_empty_fields: "الرجاء إدخال اسم المستخدم وكلمة المرور",
                // Navigation
                nav_reports: "لوحة التحكم",
                nav_dashboard: "لوحة التحكم",
                nav_access: "الوصول",
                nav_students: "الطلاب",
                nav_graduated: "المتخرجون",
                nav_subjects: "المواد", 
                nav_marks: "الدرجات",
                logout: "تسجيل الخروج",
                system_title: "إدارة الطلاب",
                system_subtitle: "البوابة الأكاديمية",
                weekly_schedule: "الجدول الأسبوعي",
                class_a_schedule: "کلاس A - الجدول الأكاديمي الأسبوعي",
                class_b_schedule: "کلاس B - الجدول الأكاديمي الأسبوعي",
                class_c_schedule: "کلاس C - الجدول الأكاديمي الأسبوعي",
                day: "اليوم",
                schedule: "الجدول",
                sunday: "الأحد",
                monday: "الاثنين",
                tuesday: "الثلاثاء",
                wednesday: "الأربعاء",
                thursday: "الخميس",
                break: "استراحة",
                academic_year: "السنة الأكاديمية",
                select_year: "اختر السنة",
                year_1: "السنة الأولى",
                year_2: "السنة الثانية",
                filter_by_year: "تصفية حسب السنة",
                all_years: "جميع السنوات",
                filter_by_class: "تصفية حسب الصف",
                all_classes: "جميع الصفوف",
                select_class: "اختر الصف",
                delete_selected: "حذف المحدد",
                actions: "الإجراءات",
                enroll_in_subjects: "التسجيل في المواد",
                select_all_subjects: "اختر جميع المواد",
                please_select_year_first: "يرجى اختيار السنة الدراسية أولاً لرؤية المواد المتاحة",
                no_subjects_available: "لا توجد مواد متاحة. يرجى إضافة المواد أولاً.",
                reports_title: "نظام إدارة الطلاب - لوحة التقارير",
                students_title: " إدارة الطلاب",
                subjects_title: " إدارة المواد",
                marks_title: " إدارة الدرجات", 
                year_1_students: "طلاب السنة الأولى",
                year_2_students: "طلاب السنة الثانية", 
                graduated_students: "الطلاب المتخرجون",
                active_students: "الطلاب النشطون",
                successfully_graduated: "تخرج بنجاح",
                top_performing_year: "أفضل سنة أداءً",
                top_class_performance: "أفضل صف أداءً",
                pass_rate: "معدل النجاح",
                passing_students: "الطلاب الناجحون",
                risk_subject: "مادة خطر",
                attendance_rate: "معدل الحضور",
                student_engagement: "مشاركة الطلاب",
                difficulty_index: "مؤشر الصعوبة",
                curriculum_balance: "توازن المنهج",
                enrolled_students: "الطلاب المسجلون",
                with_marks: "بدرجات مسجلة",
                excellence_rate: "معدل التفوق",
                a_plus_students: "طلاب امتياز (90+)",
                view_data_for: "عرض البيانات لـ",
                year_1_only: "السنة الأولى فقط",
                year_2_only: "السنة الثانية فقط",
                total_enrolls: "إجمالي المسجلين",
                student_distribution: "توزيع الطلاب",
                total_overview: "النظرة العامة الكاملة",
                all_years: "جميع السنوات",
                student_status: "حالة الطالب",
                academic_year: "السنة الأكاديمية",
                select_academic_year: "اختر السنة الأكاديمية", 
                add_new_student: "إضافة طالب جديد",
                add_new_subject: "إضافة مادة جديدة",
                add_new_mark: "إضافة درجة جديدة",
                add_subject: "إضافة مادة",
                search_filter: "البحث والتصفية",
                search_filter_reports: "البحث وتصفية التقارير",
                search_filter_students: "البحث وتصفية الطلاب",
                subjects_list: "قائمة المواد",
                student_name: "اسم الطالب",
                subject_name: "اسم المادة", 
                class_level: "مستوى الصف",
                actions: "الإجراءات",
                action: "الإجراء",
                edit: "تعديل",
                delete: "حذف",
                promote: "ترقية",
                graduate: "تخرج",
                save: "حفظ",
                update: "تحديث",
                cancel: "إلغاء",
                grade_distribution: "توزيع الدرجات",
                subject_performance: "أداء المواد",
                top_performers: "أفضل 3 طلاب", 
                performance_trends: "اتجاهات الأداء",
                detailed_reports: "التقارير التفصيلية",
                export_csv: "تصدير CSV",
                print:  "طباعة",
                full_name: "الاسم الكامل",
                name: "الاسم",
                age: "العمر",
                gender: "الجنس",
                class: "الصف",
                email: "البريد الإلكتروني",
                phone: "الهاتف",
                subjects: "المواد",
                id: "المعرف",
                credits: "الساعات المعتمدة",
                select_gender: "اختر الجنس",
                select_class: "اختر الصف",
                male: "ذكر",
                female: "أنثى",
                description: "الوصف",
                final_exam: "الامتحان النهائي",
                midterm_exam: "امتحان منتصف الفصل",
                quizzes: "الاختبارات القصيرة",
                daily_activities: "الأنشطة اليومية",
                total_mark: "الدرجة الإجمالية",
                grade: "التقدير",
                filter_by_class: "تصفية حسب الصف",
                filter_by_subject: "تصفية حسب المادة",
                filter_by_teacher: "تصفية حسب المعلم",
                filter_by_grade: "تصفية حسب الدرجة",
                filter_by_gender: "تصفية حسب الجنس",
                filter_by_age_range: "تصفية حسب الفئة العمرية",
                filter_by_enrollment: "تصفية حسب التسجيل",
                all_classes: "جميع الصفوف",
                all_subjects: "جميع المواد",
                all_teachers: "جميع المعلمين",
                all_grades: "جميع الدرجات",
                all_genders: "جميع الأجناس",
                all_ages: "جميع الأعمار",
                all_students: "جميع الطلاب",
                sort_by_final_grade: "ترتيب حسب الدرجة النهائية",
                sort_default: "افتراضي",
                sort_grade_low_high: "الدرجة: من الأدنى إلى الأعلى",
                sort_grade_high_low: "الدرجة: من الأعلى إلى الأدنى",
                clear_filters: "مسح التصفيات",
                collapse_all: "طي الكل",
                search: "البحث",
                search_student: "البحث عن طالب",
                search_subject: "البحث عن مادة",
                search_by_name: "البحث بالاسم أو البريد أو الهاتف...",
                analytics_dashboard: "لوحة التحليلات",
                comprehensive_overview: "نظرة شاملة على أداء الطلاب",
                total_students: "إجمالي الطلاب",
                all_enrolled: "جميع المسجلين",
                total_subjects: "إجمالي المواد",
                available_courses: "المقررات المتاحة",
                average_score: "متوسط النتائج",
                overall_performance: "الأداء العام",
                top_performing_class: "أفضل صف أداءً",
                manage_student_info: "إدارة معلومات الطلاب والتسجيل",
                input_manage_marks: "إدخال وإدارة درجات الطلاب",
                // Teacher Management Translations
                nav_teachers: "المعلمون",
                teachers_title: "إدارة المعلمين",
                manage_teacher_info: "إدارة المعلمين وتعيين المواد",
                add_new_teacher: "إضافة معلم جديد",
                teachers_list: "قائمة المعلمين",
                teacher: "المعلم",
                teachers: "المعلمون",
                specialization: "التخصص",
                select_specialization: "اختر التخصص",
                select_degree: "اختر الدرجة",
                degree_high_school: "شهادة الثانوية العامة",
                degree_associate: "درجة مشارك",
                degree_bachelor: "درجة البكالوريوس",
                degree_master: "درجة الماجستير",
                degree_doctorate: "الدكتوراه (PhD)",
                degree_certificate: "شهادة مهنية",
                no_subject_assignment: "بدون تعيين مادة",
                select_subject_first: "اختر المادة أولاً",
                no_class_assignment: "بدون تعيين صف",
                assigned_subjects: "المواد المكلف بها",
                no_subject_assignments: "لا توجد مواد مكلف بها بعد",
                assign_new_subject: "تعيين مادة جديدة",
                join_date: "تاريخ الانضمام",
                add_teacher: "إضافة معلم",
                edit_teacher: "تعديل المعلم",
                delete_teacher: "حذف المعلم",
                view_teacher: "عرض المعلم",
                assign_subjects: "تعيين المواد",
                remove_assignment: "إزالة التكليف",
                assigned: "مُكلَّف",
                remove: "إزالة",
                teacher_email: "teacher@school.edu",
                filter_specialization: "تصفية حسب التخصص",
                filter_degree: "تصفية حسب الدرجة",
                filter_year: "تصفية حسب السنة",
                filter_class: "تصفية حسب الصف",
                view: "عرض",
                degree: "الدرجة",
                salary: "الراتب",
                search_teachers: "البحث بالاسم، البريد...",
                no_teachers: "لم تتم إضافة معلمين بعد. انقر على 'إضافة معلم جديد' للبدء.",
                no_assignments: "لا توجد تكليفات",
                assigned_date: "تاريخ التكليف",
                filter_status: "تصفية حسب الحالة",
                active: "نشط",
                inactive: "غير نشط",
                all: "الكل",
                // Teacher Access Management
                teacher_access_title: "إدارة وصول المعلمين",
                teacher_access_subtitle: "إدارة بيانات اعتماد تسجيل دخول المعلمين والوصول إلى النظام",
                total_teachers: "إجمالي المعلمين",
                active_logins: "تسجيلات الدخول النشطة",
                no_access: "بدون وصول",
                subjects_no_teacher: "مواد بدون معلم",
                teacher_credential_mgmt: "إدارة بيانات اعتماد المعلمين",
                print_btn: "طباعة",
                search_teacher_label: "البحث عن معلم",
                search_by_name: "البحث بالاسم...",
                filter_by_spec: "تصفية حسب التخصص",
                all_specializations: "جميع التخصصات",
                all_statuses: "الكل",
                has_login: "لديه تسجيل دخول",
                no_login_option: "بدون تسجيل دخول",
                filter_by_degree: "تصفية حسب الدرجة",
                all_degrees: "جميع الدرجات",
                th_id: "الرقم",
                th_teacher_name: "اسم المعلم",
                th_contact_email: "البريد الإلكتروني",
                th_specialization: "التخصص",
                th_degree: "الدرجة",
                th_login_username: "اسم المستخدم",
                th_status: "الحالة",
                th_subjects: "المواد",
                th_actions: "الإجراءات",
                not_set: "غير محدد",
                status_active: "نشط",
                update_access: "تحديث الوصول",
                create_access: "إنشاء وصول",
                no_teachers_found: "لم يتم العثور على معلمين",
                create_teacher_access: "إنشاء وصول المعلم",
                modal_teacher_name: "اسم المعلم",
                modal_login_username: "البريد الإلكتروني/اسم المستخدم *",
                modal_username_placeholder: "teacher.email@school.edu",
                modal_username_hint: "سيتم استخدامه لتسجيل الدخول",
                modal_password: "كلمة المرور",
                modal_password_placeholder: "أدخل كلمة المرور الجديدة",
                modal_password_hint: "6 أحرف على الأقل. اتركه فارغاً للاحتفاظ بكلمة المرور الحالية.",
                modal_cancel: "إلغاء",
                modal_save: "حفظ البيانات",
                chart_teachers_by_spec: "المعلمون حسب التخصص",
                chart_teachers_by_degree: "المعلمون حسب الدرجة",
                chart_teachers_by_subject: "المعلمون حسب المادة",
                // Teacher Dashboard (Tasks)
                academic_task_manager: "مدير المهام الأكاديمية",
                welcome: "مرحباً",
                manage_teaching_schedule: "إدارة جدول التدريس والمهام الأكاديمية",
                subjects_teaching: "المواد التي تدرسها",
                kpi_total_students: "إجمالي الطلاب",
                students_pending_marks: "الطلاب بانتظار الدرجات",
                overdue_tasks: "المهام المتأخرة",
                create_new_task: "إنشاء مهمة جديدة",
                task_type: "نوع المهمة",
                task_examination: "امتحان",
                task_homework: "واجب منزلي",
                task_reminder: "تذكير",
                task_note: "ملاحظة عامة",
                all_subjects: "جميع المواد",
                task_title: "العنوان",
                task_title_placeholder: "مثال: امتحان منتصف الفصل - الفصول 1-3",
                task_description: "الوصف",
                task_description_placeholder: "تفاصيل إضافية...",
                task_due_date: "تاريخ الاستحقاق",
                task_priority: "الأولوية",
                priority_low: "منخفضة",
                priority_medium: "متوسطة",
                priority_high: "عالية",
                create_task_btn: "إنشاء مهمة",
                my_subjects: "موادي",
                students_label: "طلاب",
                view_marks: "عرض الدرجات",
                no_subjects_assigned: "لم يتم تعيين مواد بعد",
                // End Teacher Translations
                marks_list: "قائمة الدرجات",
                search_filter_marks: "البحث وتصفية الدرجات",
                add_mark: "إضافة درجة",
                student: "الطالب",
                subject: "المادة",
                status: "الحالة",
                midterm: "منتصف الفصل",
                final: "النهائي",
                daily: "اليومي",
                total: "الإجمالي",
                final_grade: "الدرجة النهائية",
                graduation_grade: "درجة التخرج",
                students_count: "طلاب",
                showing: "عرض",
                of: "من",
                students: "طلاب",
                subjects: "المواد",
                manage_marks: "إدارة الدرجات",
                subject_marks_title: "درجات المواد - انقر على تعديل للتغيير",
                final_exam: "النهائي (60)",
                midterm_exam: "منتصف الفصل (20)",
                quiz: "الاختبار (10)",
                daily_activities: "اليومي (10)",
                select_student: "اختر الطالب",
                edit: "تعديل",
                delete: "حذف",
                view_details: "عرض التفاصيل",
                subject_marks: "درجات المواد",
                total_credits: "مجموع النقاط",
                year_1_student: "طالب السنة الأولى",
                year_2_student: "طالب السنة الثانية",
                unknown: "غير معروف",
                male: "ذكر",
                female: "أنثى",
                n_a: "غير متوفر",
                year: "السنة",
                edit_student: "تعديل الطالب",
                full_name: "الاسم الكامل",
                select_gender: "اختر الجنس",
                select_class: "اختر الصف",
                cancel: "إلغاء",
                update_student: "تحديث الطالب",
                subject_enrollment: "تسجيل المواد",
                credits: "نقاط",
                tip_enroll: "نصيحة: حدد/ألغِ تحديد المواد لتسجيل الطالب أو إلغاء تسجيله. إلغاء التحديد سيزيل جميع الدرجات لتلك المادة.",
                select_subject: "اختر المادة",
                final_exam_range: "الامتحان النهائي (0-60)",
                midterm_range: "منتصف الفصل (0-20)",
                quizzes_range: "الاختبارات القصيرة (0-10)",
                daily_range: "الأنشطة اليومية (0-10)",
                enter_full_name: "أدخل الاسم الكامل للطالب",
                student_age: "عمر الطالب",
                email_placeholder: "student@example.com",
                search_by_student_name: "البحث باسم الطالب...",
                search_by_student_subject: "البحث باسم الطالب أو المادة...",
                filter_by_status: "تصفية حسب الحالة",
                all_status: "جميع الحالات",
                manage_subjects_curriculum: "إدارة المواد والمناهج",
                phone_number: "رقم الهاتف",
                enter_phone_number: "أدخل رقم الهاتف",
                add_student: "إضافة طالب",
                reset_form: "إعادة تعيين النموذج",
                students_list: "قائمة الطلاب",
                graduated_title: "الطلاب المتخرجون",
                manage_graduated_students: "إدارة الطلاب المتخرجون",
                graduated_list: "قائمة المتخرجين",
                graduation_date: "تاريخ التخرج",
                select_subjects_enroll: "اختر المواد لتسجيل هذا الطالب فيها (اختياري - يمكن القيام به لاحقاً)",
                email_placeholder_student: "أدخل عنوان البريد الإلكتروني...",
                class_a: "A کلاس",
                class_b: "B کلاس",
                class_c: "C کلاس",
                year_1_class_a: "السنة الأولى - کلاس A",
                year_1_class_b: "السنة الأولى - کلاس B",
                year_1_class_c: "السنة الأولى - کلاس C",
                year_2_class_a: "السنة الثانية - کلاس A",
                year_2_class_b: "السنة الثانية - کلاس B",
                year_2_class_c: "السنة الثانية - کلاس C",
                edit_mark: "تعديل الدرجة",
                mark_calculation: "حساب الدرجة",
                current_total: "المجموع الحالي",
                update_mark: "تحديث الدرجة",
                update_subject: "تحديث المادة",
                edit_subject: "تعديل المادة",
                subject_name: "اسم المادة",
                cannot_promote_student: "لا يمكن ترقية الطالب",
                cannot_graduate_student: "لا يمكن تخريج الطالب",
                student_not_meet_requirements: "الطالب لا يستوفي المتطلبات",
                failed_subjects_label: "المواد الراسبة (الدرجة < 50)",
                requirements: "المتطلبات",
                action_required: "الإجراء المطلوب",
                update_failed_subjects: "تحديث المواد الراسبة للحصول على درجات ≥ 50",
                increase_marks: "زيادة الدرجات للوصول إلى الحد الأدنى من الدرجة النهائية 25",
                review_marks: "راجع جميع درجات المواد قبل المحاولة مرة أخرى",
                ok_understand: "حسناً، فهمت",
                bulk_promote_graduate: "ترقية/تخريج جماعي",
                bulk_action: "ترقية/تخريج جماعي",
                bulk_action_desc: "اختر السنة التي تريد ترقية أو تخريج طلابها. سيتم معالجة الطلاب المؤهلين فقط.",
                promote_year_1: "ترقية طلاب السنة الأولى",
                year_1_to_year_2: "نقل طلاب السنة الأولى المؤهلين إلى السنة الثانية",
                graduate_year_2: "تخريج طلاب السنة الثانية",
                year_2_to_graduated: "نقل طلاب السنة الثانية المؤهلين إلى الخريجين",
                eligible: "مؤهل",
                checking_eligibility: "فحص الأهلية",
                counting_eligible_students: "حساب الطلاب المؤهلين...",
                validating_records: "التحقق من سجلات الطلاب...",
                confirm_promote_student: "هل أنت متأكد من ترقية هذا الطالب من السنة الأولى إلى السنة الثانية؟",
                confirm_graduate_student: "هل أنت متأكد من تخريج هذا الطالب؟ سيتم نقله إلى قائمة الخريجين.",
                student_promoted_success: "تمت ترقية الطالب إلى السنة الثانية!",
                student_graduated_success: "تم تخريج الطالب بنجاح!",
                network_error: "خطأ في الشبكة. يرجى المحاولة مرة أخرى.",
                promote_to_year_2: "الترقية إلى السنة الثانية",
                select_year_2_subjects: "اختر مواد السنة الثانية لهذا الطالب",
                student_eligible_promotion: "الطالب مؤهل للترقية",
                all_requirements_met: "تم استيفاء جميع المتطلبات! اختر مواد السنة الثانية للتسجيل.",
                available_year_2_subjects: "مواد السنة الثانية المتاحة",
                promote_student: "ترقية الطالب",
                error_loading_subjects: "خطأ في تحميل المواد. يرجى المحاولة مرة أخرى.",
                select_at_least_one_subject: "يرجى اختيار مادة واحدة على الأقل من السنة الثانية.",
                promoting_student: "ترقية الطالب",
                moving_to_year_2: "الانتقال إلى السنة الثانية...",
                error_promoting_student: "خطأ في ترقية الطالب",
                loading: "جاري التحميل",
                saving: "جاري الحفظ",
                switching_language: "تغيير اللغة",
                total_final_grade: "إجمالي الدرجة النهائية",
                required: "المطلوب",
                failed_subjects: "المواد الراسبة",
                my_tasks_schedule: "مهامي وجدولي",
                no_tasks_yet: "لا توجد مهام بعد. قم بإنشاء مهمتك الأولى أعلاه للبدء.",
                please_wait: "يرجى الانتظار...",
                analytics_report: "تقرير التحليلات",
                generated_on: "تم الإنشاء في",
                no_data_to_print: "لا توجد بيانات للطباعة",
                no_marks_available: "لا توجد درجات متاحة",
                average: "المعدل",
                time: "الوقت",
                sunday: "الأحد",
                monday: "الاثنين",
                tuesday: "الثلاثاء",
                wednesday: "الأربعاء",
                thursday: "الخميس"
            },
            ku: {
                // Login Page
                page_title: "چوونەژوورەوە - سیستەمی بەڕێوەبردنی ئەکادیمی",
                login_subtitle: "چوونەژوورەوە بۆ دەستگەیشتن بە داشبۆرد",
                username: "ناوی بەکارهێنەر",
                password: "وشەی نهێنی",
                sign_in: "چوونەژوورەوە",
                signing_in: "چوونەژوورەوە...",
                access_levels: "ئاستەکانی دەستگەیشتن",
                administrator: "بەڕێوەبەر",
                full_system_control: "کۆنترۆڵی تەواوی سیستەم",
                subject_management: "بەڕێوەبردنی وانەکان",
                secure_portal: "دەروازەی ئەکادیمی پارێزراو",
                error_invalid_credentials: "ناوی بەکارهێنەر یان وشەی نهێنی هەڵەیە",
                error_empty_fields: "تکایە ناوی بەکارهێنەر و وشەی نهێنی بنووسە",
                // Navigation
                nav_reports: "داشبۆرد",
                nav_dashboard: "داشبۆرد",
                nav_access: "دەستگەیشتن",
                nav_students: "قوتابییەکان",
                nav_graduated: "دەرچووەکان",
                nav_subjects: "وانەکان",
                nav_marks: "نمرەکان",
                logout: "دەرچوون",
                system_title: "بەڕێوەبردنی قوتابییان",
                system_subtitle: "دەرگای ئەکادیمی",
                weekly_schedule: "خشتەی هەفتانە",
                class_a_schedule: "کلاس A - خشتەی ئەکادیمیی هەفتانە",
                class_b_schedule: "کلاس B - خشتەی ئەکادیمیی هەفتانە",
                class_c_schedule: "کلاس C - خشتەی ئەکادیمیی هەفتانە",
                day: "ڕۆژ",
                schedule: "خشتە",
                sunday: "یەکشەممە",
                monday: "دووشەممە",
                tuesday: "سێشەممە",
                wednesday: "چوارشەممە",
                thursday: "پێنجشەممە",
                break: "پشوو",
                academic_year: "ساڵی ئەکادیمی",
                select_year: "ساڵ هەڵبژێرە",
                year_1: "ساڵی یەکەم",
                year_2: "ساڵی دووەم",
                filter_by_year: "پاڵاوتن بەپێی ساڵ",
                all_years: "هەموو ساڵەکان",
                filter_by_class: "پاڵاوتن بەپێی پۆل",
                all_classes: "هەموو پۆلەکان",
                select_class: "پۆل هەڵبژێرە",
                delete_selected: "هەڵبژاردەکان بسڕەوە",
                actions: "کردارەکان",
                enroll_in_subjects: "لە وانەکاندا تۆمارکردن",
                select_all_subjects: "هەموو وانەکان هەڵبژێرە",
                please_select_year_first: "تکایە یەکەم جار ساڵی خوێندن هەڵبژێرە بۆ بینینی وانە بەردەستەکان",
                no_subjects_available: "هیچ وانەیەک بەردەست نییە. تکایە یەکەم جار وانەکان زیاد بکە.",
                reports_title: "سیستەمی بەڕێوەبردنی قوتابییان - داشبۆردی ڕاپۆرتەکان",
                students_title: "بەڕێوەبردنی قوتابییەکان", 
                subjects_title: "بەڕێوەبردنی وانەکان",
                marks_title: "بەڕێوەبردنی نمرەکان",
                year_1_students: "قوتابییانی ساڵی یەکەم",
                year_2_students: "قوتابییانی ساڵی دووەم", 
                graduated_students: "قوتابییە دەرچووەکان",
                active_students: "قوتابییە چالاکەکان",
                successfully_graduated: "بە سەرکەوتوویی دەرچوو",
                top_performing_year: "ساڵی باشترین کارایی",
                top_class_performance: "پۆلی باشترین کارایی",
                pass_rate: "ڕێژەی سەرکەوتن",
                passing_students: "قوتابیانی سەرکەوتوو",
                risk_subject: "بابەتی مەترسیدار",
                attendance_rate: "ڕێژەی ئامادەبوون",
                student_engagement: "بەشداریکردنی قوتابیان",
                difficulty_index: "نیشاندەری سەختی",
                curriculum_balance: "هاوسەنگی کۆرس",
                enrolled_students: "قوتابیانی تۆمارکراو",
                with_marks: "بە نمرەی تۆمارکراو",
                excellence_rate: "ڕێژەی تایبەتمەندی",
                a_plus_students: "قوتابیانی A+ (90+)",
                view_data_for: "زانیارییەکان نیشان بدە بۆ",
                year_1_only: "تەنها ساڵی یەکەم",
                year_2_only: "تەنها ساڵی دووەم",
                total_enrolls: "کۆی تۆمارکراوان",
                student_distribution: "دابەشبوونی قوتابییەکان",
                total_overview: "بینینی گشتی تەواو",
                all_years: "هەموو ساڵەکان",
                student_status: "بارودۆخی قوتابی",
                academic_year: "ساڵی زانستی",
                select_academic_year: "ساڵی زانستی هەڵبژێرە",
                add_new_student: "زیادکردنی قوتابیی نوێ",
                add_new_subject: "زیادکردنی وانەی نوێ",
                add_new_mark: "زیادکردنی نمرەی نوێ",
                add_subject: "وانە زیادبکە",
                search_filter: "گەڕان و پاڵاوتن",
                search_filter_reports: "گەڕان و پاڵاوتنی ڕاپۆرتەکان",
                search_filter_students: "گەڕان و پاڵاوتنی قوتابییەکان",
                subjects_list: "لیستی وانەکان",
                student_name: "ناوی قوتابی",
                subject_name: "ناوی وانە",
                class_level: "ئاستی پۆل", 
                actions: "کردارەکان",
                action: "کردار",
                edit: "دەستکاری",
                delete: "سڕینەوە",
                promote: "بەرزکردنەوە",
                graduate: "دەرچوون",
                save: "پاشەکەوت",
                update: "نوێکردنەوە",
                cancel: "هەڵوەشاندنەوە",
                grade_distribution: "دابەشکردنی نمرەکان",
                subject_performance: "کارایی وانەکان",
                top_performers: "باشترین 3 قوتابی",
                performance_trends: "ئاراستەی کارایی", 
                detailed_reports: "ڕاپۆرتە ورددەکان",
                export_csv: "هەناردنی CSV",
                print:  "چاپکردن",
                full_name: "ناوی تەواو",
                name: "ناو",
                age: "تەمەن",
                gender: "ڕەگەز",
                class: "پۆل",
                email: "ئیمەیڵ",
                phone: "تەلەفۆن",
                subjects: "وانەکان",
                id: "ناسنامە",
                credits: "یەکەی خوێندن",
                select_gender: "ڕەگەز هەڵبژێرە",
                select_class: "پۆل هەڵبژێرە",
                male: "نێر",
                female: "مێ",
                description: "وەسف",
                final_exam: "تاقیکردنەوەی کۆتایی",
                midterm_exam: "تاقیکردنەوەی ناوەڕاست",
                quizzes: "تاقیکردنەوە بچووکەکان",
                daily_activities: "چالاکییە ڕۆژانەکان",
                total_mark: "نمرەی گشتی",
                grade: "پلە",
                filter_by_class: "پاڵاوتن بەپێی پۆل",
                filter_by_subject: "پاڵاوتن بەپێی وانە",
                filter_by_teacher: "پاڵاوتن بەپێی مامۆستا",
                filter_by_grade: "پاڵاوتن بەپێی نمرە",
                filter_by_gender: "پاڵاوتن بەپێی ڕەگەز",
                filter_by_age_range: "پاڵاوتن بەپێی تەمەن",
                filter_by_enrollment: "پاڵاوتن بەپێی تۆمارکردن",
                all_classes: "هەموو پۆلەکان",
                all_subjects: "هەموو وانەکان",
                all_teachers: "هەموو مامۆستایان",
                all_grades: "هەموو نمرەکان",
                all_genders: "هەموو ڕەگەزەکان",
                all_ages: "هەموو تەمەنەکان",
                all_students: "هەموو قوتابییەکان",
                sort_by_final_grade: "ڕیزکردن بەپێی نمرەی کۆتایی",
                sort_default: "بنەڕەتی",
                sort_grade_low_high: "نمرە: لە کەم بۆ زۆر",
                sort_grade_high_low: "نمرە: لە زۆر بۆ کەم",
                clear_filters: "پاڵاوتنەکان بسڕەوە",
                collapse_all: "هەموو داخستن",
                search: "گەڕان",
                search_student: "گەڕان بەدوای قوتابی",
                search_subject: "گەڕان بەدوای وانە",
                search_by_name: "گەڕان بەناو، ئیمەیڵ، یان تەلەفۆن...",
                analytics_dashboard: "داشبۆردی شیکاری",
                comprehensive_overview: "تێڕوانینی گشتگیر بۆ کارایی قوتابییان",
                total_students: "کۆی قوتابییان",
                all_enrolled: "هەموو تۆمارکراوەکان",
                total_subjects: "کۆی وانەکان",
                available_courses: "کۆرسە بەردەستەکان",
                average_score: "ناوەندی نمرەکان",
                overall_performance: "کارایی گشتی",
                top_performing_class: "باشترین پۆل",
                manage_student_info: "بەڕێوەبردنی زانیاری قوتابییان و تۆمارکردن",
                manage_teacher_info: "بەڕێوەبردنی مامۆستایان و دیاریکردنی وانەکان",
                input_manage_marks: "تۆمارکردن و بەڕێوەبردنی نمرەی قوتابییان",
                marks_list: "لیستی نمرەکان",
                search_filter_marks: "گەڕان و پاڵاوتنی نمرەکان",
                nav_teachers: "مامۆستایان",
                teachers_title: "بەڕێوەبردنی مامۆستایان",
                add_new_teacher: "مامۆستای نوێ زیادبکە",
                teachers_list: "لیستی مامۆستایان",
                teacher: "مامۆستا",
                teachers: "مامۆستایان",
                specialization: "پسپۆڕی",
                select_specialization: "پسپۆڕی هەڵبژێرە",
                select_degree: "بڕوانامە هەڵبژێرە",
                degree_high_school: "بڕوانامەی ئامادەیی",
                degree_associate: "بڕوانامەی هاوبەش",
                degree_bachelor: "بڕوانامەی بەکالۆریۆس",
                degree_master: "بڕوانامەی ماستەر",
                degree_doctorate: "دکتۆرا (PhD)",
                degree_certificate: "بڕوانامەی پیشەیی",
                no_subject_assignment: "دیاریکردنی وانە نییە",
                select_subject_first: "یەکەم وانە هەڵبژێرە",
                no_class_assignment: "دیاریکردنی پۆل نییە",
                assigned_subjects: "وانە دیاریکراوەکان",
                no_subject_assignments: "هێشتا هیچ وانەیەک دیاری نەکراوە",
                assign_new_subject: "دیاریکردنی وانەی نوێ",
                join_date: "بەرواری بەشداریکردن",
                add_teacher: "مامۆستا زیادبکە",
                edit_teacher: "دەستکاری مامۆستا",
                delete_teacher: "سڕینەوەی مامۆستا",
                view_teacher: "پیشاندانی مامۆستا",
                assign_subjects: "دیاریکردنی وانەکان",
                remove_assignment: "لابردنی دیاریکراو",
                assigned: "دیاریکراو",
                remove: "لابردن",
                teacher_email: "ئیمەیڵی مامۆستا",
                filter_specialization: "پاڵاوتن بە پسپۆڕی",
                filter_degree: "پاڵاوتن بە بڕوانامە",
                filter_year: "پاڵاوتن بە ساڵ",
                filter_class: "پاڵاوتن بە پۆل",
                view: "پیشاندان",
                degree: "بڕوانامە",
                salary: "مووچە",
                search_teachers: "گەڕان بە ناو، ئیمەیڵ...",
                no_teachers: "هێشتا هیچ مامۆستایەک زیادنەکراوە. کرتە لە 'مامۆستای نوێ زیادبکە' بکە.",
                no_assignments: "دیاریکراو نییە",
                assigned_date: "بەرواری دیاریکردن",
                filter_status: "پاڵاوتن بە بارودۆخ",
                active: "چالاک",
                inactive: "ناچالاک",
                all: "هەموو",
                // Teacher Access Management
                teacher_access_title: "بەڕێوەبردنی دەستگەیشتنی مامۆستایان",
                teacher_access_subtitle: "بەڕێوەبردنی زانیاری چوونەژوورەوە و دەستگەیشتنی سیستەم بۆ مامۆستایان",
                total_teachers: "کۆی گشتی مامۆستایان",
                active_logins: "چوونەژوورەوە چالاکەکان",
                no_access: "دەستگەیشتن نییە",
                subjects_no_teacher: "وانەکان بەبێ مامۆستا",
                teacher_credential_mgmt: "بەڕێوەبردنی زانیاری مامۆستایان",
                print_btn: "چاپکردن",
                search_teacher_label: "گەڕان بەدوای مامۆستا",
                search_by_name: "گەڕان بە ناو...",
                filter_by_spec: "پاڵاوتن بە پسپۆڕی",
                all_specializations: "هەموو پسپۆڕیەکان",
                all_statuses: "هەموو",
                has_login: "چوونەژوورەوەی هەیە",
                no_login_option: "چوونەژوورەوە نییە",
                filter_by_degree: "پاڵاوتن بە بڕوانامە",
                all_degrees: "هەموو بڕوانامەکان",
                th_id: "ژمارە",
                th_teacher_name: "ناوی مامۆستا",
                th_contact_email: "ئیمەیڵ",
                th_specialization: "پسپۆڕی",
                th_degree: "بڕوانامە",
                th_login_username: "ناوی بەکارهێنەر",
                th_status: "بارودۆخ",
                th_subjects: "وانەکان",
                th_actions: "کردارەکان",
                not_set: "دانەنراوە",
                status_active: "چالاک",
                update_access: "نوێکردنەوەی دەستگەیشتن",
                create_access: "دروستکردنی دەستگەیشتن",
                no_teachers_found: "هیچ مامۆستایەک نەدۆزرایەوە",
                create_teacher_access: "دروستکردنی دەستگەیشتنی مامۆستا",
                modal_teacher_name: "ناوی مامۆستا",
                modal_login_username: "ئیمەیڵ/ناوی بەکارهێنەر *",
                modal_username_placeholder: "teacher.email@school.edu",
                modal_username_hint: "ئەمە بۆ چوونەژوورەوە بەکاردێت",
                modal_password: "وشەی نهێنی",
                modal_password_placeholder: "وشەی نهێنی نوێ بنووسە",
                modal_password_hint: "لایەنی کەم 6 پیت. بەتاڵی بهێڵە بۆ پاراستنی وشەی نهێنی ئێستا.",
                modal_cancel: "پاشگەزبوونەوە",
                modal_save: "پاشەکەوتکردنی زانیاری",
                chart_teachers_by_spec: "مامۆستایان بە پسپۆڕی",
                chart_teachers_by_degree: "مامۆستایان بە بڕوانامە",
                chart_teachers_by_subject: "مامۆستایان بە وانە",
                // Teacher Dashboard (Tasks)
                academic_task_manager: "بەڕێوەبەری ئەرکە ئەکادیمیەکان",
                welcome: "بەخێربێیت",
                manage_teaching_schedule: "بەڕێوەبردنی خشتەی وانەوتنەوە و ئەرکە ئەکادیمیەکان",
                subjects_teaching: "وانە وتنەوەکان",
                kpi_total_students: "کۆی گشتی قوتابیان",
                students_pending_marks: "قوتابیانی چاوەڕوانی نمرە",
                overdue_tasks: "ئەرکە دواکەوتووەکان",
                create_new_task: "دروستکردنی ئەرکی نوێ",
                task_type: "جۆری ئەرک",
                task_examination: "تاقیکردنەوە",
                task_homework: "ئەرکی ماڵەوە",
                task_reminder: "بیرخەرەوە",
                task_note: "تێبینی گشتی",
                all_subjects: "هەموو وانەکان",
                task_title: "ناونیشان",
                task_title_placeholder: "نموونە: تاقیکردنەوەی ناوەڕاست - بەشی 1-3",
                task_description: "وەسف",
                task_description_placeholder: "وردەکاری زیادە...",
                task_due_date: "بەرواری کۆتایی",
                task_priority: "پێشینە",
                priority_low: "کەم",
                priority_medium: "مامناوەند",
                priority_high: "بەرز",
                create_task_btn: "دروستکردنی ئەرک",
                my_subjects: "وانەکانم",
                students_label: "قوتابیان",
                view_marks: "پیشاندانی نمرەکان",
                no_subjects_assigned: "هێشتا هیچ وانەیەک دیاری نەکراوە",
                add_mark: "نمرە زیادبکە",
                student: "قوتابی",
                subject: "وانە",
                status: "بارودۆخ",
                midterm: "ناوەڕاست",
                final: "کۆتایی",
                daily: "ڕۆژانە",
                total: "گشتی",
                final_grade: "نمرەی کۆتایی",
                graduation_grade: "نمرەی دەرچوون",
                students_count: "قوتابیان",
                showing: "پیشاندان",
                of: "لە",
                students: "قوتابیان",
                subjects: "وانەکان",
                manage_marks: "بەڕێوەبردنی نمرەکان",
                subject_marks_title: "نمرەکانی وانەکان - کرتە لە دەستکاری بکە",
                final_exam: "کۆتایی (60)",
                midterm_exam: "ناوەڕاست (20)",
                quiz: "تاقیکردنەوە (10)",
                daily_activities: "ڕۆژانە (10)",
                select_student: "قوتابی هەڵبژێرە",
                edit: "دەستکاری",
                delete: "سڕینەوە",
                view_details: "پیشاندانی وردەکاری",
                subject_marks: "نمرەکانی وانەکان",
                total_credits: "کۆی خاڵەکان",
                year_1_student: "قوتابی ساڵی یەکەم",
                year_2_student: "قوتابی ساڵی دووەم",
                unknown: "نەزانراو",
                male: "نێر",
                female: "مێ",
                n_a: "بەردەست نییە",
                year: "ساڵ",
                edit_student: "دەستکاری قوتابی",
                full_name: "ناوی تەواو",
                select_gender: "ڕەگەز هەڵبژێرە",
                select_class: "پۆل هەڵبژێرە",
                cancel: "پاشگەزبوونەوە",
                update_student: "نوێکردنەوەی قوتابی",
                subject_enrollment: "تۆماری وانەکان",
                credits: "خاڵ",
                tip_enroll: "تێبینی: نیشانە بکە/لابە بۆ تۆمارکردن یان لابردنی تۆماری قوتابی. لابردنی نیشانە هەموو نمرەکانی ئەو وانەیە دەسڕێتەوە.",
                select_subject: "وانە هەڵبژێرە",
                final_exam_range: "تاقیکردنەوەی کۆتایی (0-60)",
                midterm_range: "ناوەڕاست (0-20)",
                quizzes_range: "تاقیکردنەوە بچووکەکان (0-10)",
                daily_range: "چالاکییە ڕۆژانەکان (0-10)",
                enter_full_name: "ناوی تەواوی قوتابی بنووسە",
                student_age: "تەمەنی قوتابی",
                email_placeholder: "student@example.com",
                search_by_student_name: "گەڕان بەناوی قوتابی...",
                search_by_student_subject: "گەڕان بەناوی قوتابی یان وانە...",
                filter_by_status: "پاڵاوتن بەپێی بارودۆخ",
                all_status: "هەموو بارودۆخەکان",
                manage_subjects_curriculum: "بەڕێوەبردنی وانەکان و مەنهەج",
                phone_number: "ژمارەی تەلەفۆن",
                enter_phone_number: "ژمارەی تەلەفۆن بنووسە",
                add_student: "قوتابی زیادبکە",
                reset_form: "فۆرم ڕێکخەرەوە",
                students_list: "لیستی قوتابییەکان",
                graduated_title: "قوتابییە دەرچووەکان",
                manage_graduated_students: "بەڕێوەبردنی قوتابییە دەرچووەکان",
                graduated_list: "لیستی دەرچووان",
                graduation_date: "ڕێکەوتی دەرچوون",
                select_subjects_enroll: "وانەکان هەڵبژێرە بۆ تۆمارکردنی ئەم قوتابییە (ئیختیاری - دواتر دەکرێت)",
                email_placeholder_student: "ئیمەیڵ ئەدرەس بنووسە...",
                class_a: "A کلاس",
                class_b: "B کلاس",
                class_c: "C کلاس",
                year_1_class_a: "ساڵی یەکەم - کلاسی A",
                year_1_class_b: "ساڵی یەکەم - کلاسی B",
                year_1_class_c: "ساڵی یەکەم - کلاسی C",
                year_2_class_a: "ساڵی دووەم - کلاسی A",
                year_2_class_b: "ساڵی دووەم - کلاسی B",
                year_2_class_c: "ساڵی دووەم - کلاسی C",
                edit_mark: "دەستکاری نمرە",
                mark_calculation: "ژمێریاری نمرە",
                current_total: "کۆی ئێستا",
                update_mark: "نوێکردنەوەی نمرە",
                update_subject: "وانە نوێبکەرەوە",
                edit_subject: "دەستکاری وانە",
                subject_name: "ناوی وانە",
                cannot_promote_student: "ناتوانرێت قوتابی بەرزبکرێتەوە",
                cannot_graduate_student: "ناتوانرێت قوتابی دەرچووبکرێت",
                student_not_meet_requirements: "قوتابی پێداویستیەکان بەدی ناهێنێت",
                failed_subjects_label: "وانە شکستخواردووەکان (نمرە < 50)",
                requirements: "پێداویستیەکان",
                action_required: "کردار پێویستە",
                update_failed_subjects: "نوێکردنەوەی وانە شکستخواردووەکان بۆ وەرگرتنی نمرەی ≥ 50",
                increase_marks: "زیادکردنی نمرەکان بۆ گەیشتن بە کەمترین نمرەی کۆتایی 25",
                review_marks: "پێداچوونەوەی هەموو نمرەکانی وانەکان پێش هەوڵدانەوە",
                ok_understand: "باشە، تێگەیشتم",
                bulk_promote_graduate: "بەرزکردنەوە/دەرچوون بە کۆمەڵ",
                bulk_action: "بەرزکردنەوە/دەرچوون بە کۆمەڵ",
                bulk_action_desc: "ساڵی قوتابیانی دیاریبکە کە دەتەوێت بەرز یان دەرچووبکەیتەوە. تەنها قوتابیە شایستەکان پرۆسێس دەکرێن.",
                promote_year_1: "بەرزکردنەوەی قوتابیانی ساڵی یەکەم",
                year_1_to_year_2: "گواستنەوەی قوتابیە شایستەکانی ساڵی یەکەم بۆ ساڵی دووەم",
                graduate_year_2: "دەرچوونی قوتابیانی ساڵی دووەم",
                year_2_to_graduated: "گواستنەوەی قوتابیە شایستەکانی ساڵی دووەم بۆ دەرچووان",
                eligible: "شایستە",
                checking_eligibility: "پشکنینی شایستەیی",
                counting_eligible_students: "ژمێرینی قوتابیە شایستەکان...",
                validating_records: "دڵنیابوون لە تۆمارەکانی قوتابیان...",
                confirm_promote_student: "دڵنیایت لە بەرزکردنەوەی ئەم قوتابیە لە ساڵی یەکەم بۆ ساڵی دووەم؟",
                confirm_graduate_student: "دڵنیایت لە دەرچوونی ئەم قوتابیە؟ ئەمە دەیگوێزێتەوە بۆ لیستی دەرچووان.",
                student_promoted_success: "قوتابی بەرزکرایەوە بۆ ساڵی دووەم!",
                student_graduated_success: "قوتابی بە سەرکەوتوویی دەرچوو!",
                network_error: "هەڵەی تۆڕ. تکایە دووبارە هەوڵ بدەوە.",
                promote_to_year_2: "بەرزکردنەوە بۆ ساڵی دووەم",
                select_year_2_subjects: "بابەتەکانی ساڵی دووەم هەڵبژێرە بۆ ئەم قوتابیە",
                student_eligible_promotion: "قوتابی شایستەی بەرزکردنەوەیە",
                all_requirements_met: "هەموو پێداویستیەکان تەواو بوون! بابەتەکانی ساڵی دووەم هەڵبژێرە بۆ تۆمارکردن.",
                available_year_2_subjects: "بابەتە بەردەستەکانی ساڵی دووەم",
                promote_student: "بەرزکردنەوەی قوتابی",
                error_loading_subjects: "هەڵە لە بارکردنی بابەتەکان. تکایە دووبارە هەوڵ بدەوە.",
                select_at_least_one_subject: "تکایە لانیکەم یەک بابەتی ساڵی دووەم هەڵبژێرە.",
                promoting_student: "بەرزکردنەوەی قوتابی",
                moving_to_year_2: "گواستنەوە بۆ ساڵی دووەم...",
                error_promoting_student: "هەڵە لە بەرزکردنەوەی قوتابی",
                loading: "بارکردن",
                saving: "پاشەکەوتکردن",
                switching_language: "گۆڕینی زمان",
                total_final_grade: "کۆی نمرەی کۆتایی",
                required: "پێویست",
                failed_subjects: "بابەتە شکستخواردووەکان",
                my_tasks_schedule: "ئەرکەکانم و خشتەکەم",
                no_tasks_yet: "هێشتا هیچ ئەرکێک نییە. یەکەمین ئەرکەکەت لە سەرەوە دروست بکە بۆ دەستپێکردن.",
                please_wait: "تکایە چاوەڕێ بکە...",
                analytics_report: "ڕاپۆرتی شیکاری",
                generated_on: "دروستکراوە لە",
                no_data_to_print: "هیچ زانیاریەک نییە بۆ چاپکردن",
                no_marks_available: "هیچ نمرەیەک بەردەست نییە",
                average: "تێکڕا",
                time: "کات",
                sunday: "یەکشەممە",
                monday: "دووشەممە",
                tuesday: "سێشەممە",
                wednesday: "چوارشەممە",
                thursday: "پێنجشەممە"
            }
        };

        // Initialize language on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check if translations object is properly loaded
            if (typeof translations === 'undefined' || !translations) {
                console.error('Translations object not loaded properly');
                return;
            }
            
            const savedLang = localStorage.getItem('selectedLanguage') || 'en';
            changeLanguage(savedLang);
            
            // Update language selector
            const langSelector = document.getElementById('languageSelector');
            if (langSelector) {
                langSelector.value = savedLang;
            }
            
            // Initialize subject filtering in add student form
            const studentYearSelect = document.getElementById('studentYearSelect');
            if (studentYearSelect) {
                // Hide all subjects initially until a year is selected
                filterSubjectsByStudentYear();
            }
        });

        // Get translation for current language
        function getTranslation(key) {
            try {
                const currentLang = localStorage.getItem('selectedLanguage') || 'en';
                if (typeof translations !== 'undefined' && translations[currentLang] && translations[currentLang][key]) {
                    return translations[currentLang][key];
                }
                // Fallback to English if translation not found
                if (typeof translations !== 'undefined' && translations.en && translations.en[key]) {
                    return translations.en[key];
                }
                return key;
            } catch (error) {
                console.error('Translation error for key:', key, error);
                return key;
            }
        }

        // Language switching function
        function changeLanguage(lang, skipReload = false) {
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            
            // If language hasn't changed and we're not forcing, just update elements
            if (lang === currentLang && !skipReload) {
                applyTranslations(lang);
                return;
            }
            
            // Only show loader and reload if language actually changed
            if (lang !== currentLang) {
                // Show loader while switching language
                showLoading(
                    translations[lang]?.switching_language || 'Switching Language',
                    translations[lang]?.please_wait || 'Please wait...'
                );
                
                // Save new language and reload
                localStorage.setItem('selectedLanguage', lang);
                
                setTimeout(() => {
                    window.location.reload();
                }, 250);
                return;
            }
            
            // Apply translations without reload
            applyTranslations(lang);
        }
        
        // Apply translations to all elements
        function applyTranslations(lang) {
            localStorage.setItem('selectedLanguage', lang);
            
            // Keep all languages as LTR (left-to-right) - no special RTL behavior for Arabic
            const htmlRoot = document.getElementById('htmlRoot');
            htmlRoot.setAttribute('dir', 'ltr');
            htmlRoot.setAttribute('lang', lang);
            
            // Also update document element for compatibility  
            document.documentElement.dir = 'ltr';
            document.documentElement.lang = lang;
            
            // Update all elements with data-translate attribute
            document.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.getAttribute('data-translate');
                if (translations[lang] && translations[lang][key]) {
                    // Check if element is an option or has textContent
                    if (element.tagName === 'OPTION') {
                        element.textContent = translations[lang][key];
                    } else {
                        element.textContent = translations[lang][key];
                    }
                }
            });
            
            // Update all elements with data-translate-placeholder attribute
            document.querySelectorAll('[data-translate-placeholder]').forEach(element => {
                const key = element.getAttribute('data-translate-placeholder');
                if (translations[lang] && translations[lang][key]) {
                    element.placeholder = translations[lang][key];
                }
            });
            
            // Update dynamically created messages
            const noSubjectsMessage = document.querySelector('.no-subjects-message');
            if (noSubjectsMessage) {
                noSubjectsMessage.textContent = getTranslation('please_select_year_first');
            }
            
            const noEditSubjectsMessage = document.querySelector('.no-edit-subjects-message');
            if (noEditSubjectsMessage) {
                noEditSubjectsMessage.textContent = getTranslation('please_select_year_first');
            }
            
            // Update chart titles if on reports page
            if (window.location.search.includes('page=reports') || !window.location.search.includes('page=')) {
                updateChartTitles(lang);
                updateChartLabels(lang);
            }
            
            // Update language selector dropdown
            const langSelector = document.getElementById('languageSelector');
            if (langSelector) {
                langSelector.value = lang;
            }
        }

        // Update chart titles
        function updateChartTitles(lang) {
            const chartTitles = {
                'Grade Distribution': 'grade_distribution',
                'Subject Performance': 'subject_performance', 
                'Top 3 Performers': 'top_performers',
                'Performance Trends': 'performance_trends',
                'Detailed Reports': 'detailed_reports'
            };
            
            document.querySelectorAll('.chart-title, .section-title').forEach(title => {
                // Skip if the title has child elements with data-translate (our new format)
                if (title.querySelector('[data-translate]')) {
                    return; // Let the normal translation handle it
                }
                
                const currentText = title.textContent.trim();
                for (const [english, key] of Object.entries(chartTitles)) {
                    if (currentText.includes(english.split(' ').slice(-1)[0])) {
                        if (translations[lang] && translations[lang][key]) {
                            title.textContent = translations[lang][key];
                        }
                        break;
                    }
                }
            });
        }

        // Update chart labels for language changes
        function updateChartLabels(lang) {
            // Get translated grade labels
            const gradeLabels = {
                'A+': 'A+', 'A': 'A', 'B': 'B', 'C': 'C', 'F': 'F'
            };
            
            // Update Grade Distribution Chart
            if (window.gradeDistributionChart) {
                try {
                    window.gradeDistributionChart.destroy();
                } catch(e) {}
                createGradeDistributionChart();
            }
            
            // Update Subject Performance Charts  
            if (window.subjectPerformanceChart1) {
                try {
                    window.subjectPerformanceChart1.destroy();
                } catch(e) {}
                createSubjectPerformanceChart1();
            }
            if (window.subjectPerformanceChart2) {
                try {
                    window.subjectPerformanceChart2.destroy();
                } catch(e) {}
                createSubjectPerformanceChart2();
            }
            
            // Update Top Performers Charts
            if (window.topPerformersChart1) {
                try {
                    window.topPerformersChart1.destroy();
                } catch(e) {}
                createTopPerformersChart1();
            }
            if (window.topPerformersChart2) {
                try {
                    window.topPerformersChart2.destroy();
                } catch(e) {}
                createTopPerformersChart2();
            }
            
            // Update Performance Trends Chart
            if (window.performanceTrendsChart) {
                try {
                    window.performanceTrendsChart.destroy();
                } catch(e) {}
                createPerformanceTrendsChart();
            }
        }

        // Initialize language on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedLang = localStorage.getItem('selectedLanguage') || 'en';
            document.getElementById('languageSelector').value = savedLang;
            changeLanguage(savedLang);
        });

        // AJAX Functions for smooth operations without page refresh
        function showSuccessMessage(message) {
            const messageDiv = document.createElement('div');
            messageDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #28a745;
                color: white;
                padding: 15px 20px;
                border-radius: 5px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideInRight 0.3s ease;
            `;
            messageDiv.textContent = message;
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => messageDiv.remove(), 300);
            }, 3000);
        }

        function showErrorMessage(message) {
            const messageDiv = document.createElement('div');
            messageDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #dc3545;
                color: white;
                padding: 15px 20px;
                border-radius: 5px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideInRight 0.3s ease;
            `;
            messageDiv.textContent = message;
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => messageDiv.remove(), 300);
            }, 3000);
        }

        function submitFormAjax(formElement, successCallback) {
            const formData = new FormData(formElement);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Check if the response contains success indicators
                if (data.includes('Student added successfully') || 
                    data.includes('Student updated successfully') ||
                    data.includes('Student deleted successfully') ||
                    data.includes('Subject added successfully') ||
                    data.includes('Subject updated successfully') ||
                    data.includes('Subject deleted successfully') ||
                    data.includes('Mark added successfully') ||
                    data.includes('Mark updated successfully') ||
                    data.includes('Mark deleted successfully') ||
                    data.includes('Student promoted successfully') ||
                    data.includes('Student graduated successfully')) {
                    
                    // Extract success message
                    let message = 'Operation completed successfully';
                    if (data.includes('added successfully')) message = 'Added successfully!';
                    else if (data.includes('updated successfully')) message = 'Updated successfully!';
                    else if (data.includes('deleted successfully')) message = 'Deleted successfully!';
                    else if (data.includes('promoted successfully')) message = 'Student promoted successfully!';
                    else if (data.includes('graduated successfully')) message = 'Student graduated successfully!';
                    
                    showSuccessMessage(message);
                    
                    // Specific actions based on operation type
                    if (data.includes('Subject added successfully')) {
                        refreshSubjectsList();
                        refreshSubjectEnrollment();
                    } else if (data.includes('Student added successfully')) {
                        refreshStudentsList();
                    } else if (data.includes('Mark added successfully')) {
                        refreshMarksList();
                    }
                    
                    // Call success callback to refresh the content
                    if (successCallback) successCallback();
                    
                    // Clear form if it's an add form
                    if (formElement.querySelector('input[value="add_student"]') ||
                        formElement.querySelector('input[value="add_subject"]') ||
                        formElement.querySelector('input[value="add_mark"]')) {
                        formElement.reset();
                    }
                } else {
                    showErrorMessage('An error occurred. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }

        // Functions to refresh different sections without page reload
        function refreshSubjectsList() {
            // Only refresh if we're on the subjects page
            if (window.location.search.includes('page=subjects')) {
                setTimeout(() => {
                    location.reload();
                }, 500);
            }
        }

        function refreshSubjectEnrollment() {
            // Refresh the subject enrollment section in student form
            const container = document.getElementById('subjectEnrollmentContainer');
            if (container) {
                // Get current page URL to maintain context
                const currentPage = window.location.search || '?page=students';
                
                fetch(currentPage)
                .then(response => response.text())
                .then(data => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data, 'text/html');
                    const newContainer = doc.getElementById('subjectEnrollmentContainer');
                    
                    if (newContainer) {
                        container.innerHTML = newContainer.innerHTML;
                        // Re-apply year filtering if a year is selected
                        const yearSelect = document.getElementById('studentYearSelect');
                        if (yearSelect && yearSelect.value) {
                            filterSubjectsByStudentYear();
                        }
                    }
                })
                .catch(error => console.error('Error refreshing subject enrollment:', error));
            }
        }

        function refreshStudentsList() {
            // Only refresh if we're on the students page
            if (window.location.search.includes('page=students') || 
                window.location.search.includes('page=') === false) {
                setTimeout(() => {
                    location.reload();
                }, 1000);
            }
        }

        function refreshMarksList() {
            // Only refresh if we're on the marks page
            if (window.location.search.includes('page=marks')) {
                setTimeout(() => {
                    location.reload();
                }, 1000);
            }
        }

        function handleStudentAction(action, studentId) {
            if (action === 'promote_student') {
                showPromotionDialog(studentId);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('student_id', studentId);
            
            let confirmMessage = '';
            let loadingMessage = '';
            if (action === 'delete_student') {
                confirmMessage = 'Are you sure you want to delete this student?';
                loadingMessage = 'Deleting Student';
            } else if (action === 'graduate_student') {
                confirmMessage = 'Are you sure you want to graduate this student?';
                loadingMessage = 'Graduating Student';
            }
            
            if (confirmMessage && !confirm(confirmMessage)) {
                return;
            }
            
            showLoading(loadingMessage, 'Please wait...');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                hideLoading();
                if (data.includes('successfully')) {
                    let message = 'Operation completed successfully';
                    if (data.includes('deleted successfully')) message = 'Student deleted successfully!';
                    else if (data.includes('promoted successfully')) message = 'Student promoted successfully!';
                    else if (data.includes('graduated successfully')) message = 'Student graduated successfully!';
                    
                    showNotification(message, 'success');
                    setTimeout(() => {
                        reloadTableData('students');
                    }, 500);
                } else {
                    showNotification('An error occurred. Please try again.', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Network error. Please try again.', 'error');
            });
        }

        function showPromotionDialog(studentId) {
            try {
                // First, check if student is eligible for promotion
                showLoading(getTranslation('checking_eligibility'), getTranslation('validating_records'));
                
                const checkFormData = new FormData();
                checkFormData.append('action', 'check_promotion_eligibility');
                checkFormData.append('student_id', studentId);
            
            fetch('', {
                method: 'POST',
                body: checkFormData
            })
            .then(response => response.json())
            .then(eligibility => {
                hideLoading();
                
                if (!eligibility.eligible) {
                    // Show error modal if not eligible
                    showEligibilityErrorModal('Promotion', eligibility);
                    return;
                }
                
                // Student is eligible, now get Year 2 subjects
                return fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_year2_subjects`
                });
            })
            .then(response => {
                if (!response) return; // Stopped due to ineligibility
                return response.json();
            })
            .then(subjects => {
                if (!subjects) return; // Stopped due to ineligibility
                
                if (subjects.error) {
                    showNotification(subjects.error, 'error');
                    return;
                }
                
                // Get all translations first to avoid issues in template literals
                const creditsText = getTranslation('credits');
                const promoteToYear2Text = getTranslation('promote_to_year_2');
                const selectYear2SubjectsText = getTranslation('select_year_2_subjects');
                const studentEligibleText = getTranslation('student_eligible_promotion');
                const allRequirementsMetText = getTranslation('all_requirements_met');
                const availableYear2SubjectsText = getTranslation('available_year_2_subjects');
                const cancelText = getTranslation('cancel');
                const promoteStudentText = getTranslation('promote_student');
                
                const modal = document.createElement('div');
                modal.style.cssText = `
                    position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                    background: rgba(0,0,0,0.7); z-index: 10000; display: flex; 
                    align-items: center; justify-content: center; padding: 20px;
                `;
                
                let subjectsHtml = subjects.map(subject => `
                    <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; background: #F9FAFB; border-radius: 4px; margin-bottom: 0.5rem;">
                        <input type="checkbox" id="subject_${subject.id}" value="${subject.id}" checked style="margin: 0;">
                        <label for="subject_${subject.id}" style="margin: 0; flex: 1; cursor: pointer;">
                            <strong>${subject.subject_name}</strong> (${subject.credits} ${creditsText})
                        </label>
                    </div>
                `).join('');
                
                modal.innerHTML = `
                    <div style="background: white; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative;">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #E5E7EB; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0;">
                            <h3 style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-arrow-up"></i> ${promoteToYear2Text}
                            </h3>
                            <p style="margin: 0.5rem 0 0 0; opacity: 0.9; font-size: 0.9rem;">${selectYear2SubjectsText}</p>
                            <button onclick="this.closest('.promotion-modal').remove()" 
                                    style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: white; opacity: 0.8;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div style="padding: 1.5rem;">
                            <div style="background: #F0FDF4; padding: 1rem; border-radius: 6px; border-left: 4px solid #059669; margin-bottom: 1.5rem;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #059669;"><i class="fas fa-check-circle"></i> ${studentEligibleText}</h4>
                                <p style="margin: 0; font-size: 0.9rem; color: #065F46;">
                                    ${allRequirementsMetText}
                                </p>
                            </div>
                            
                            <h4 style="margin: 0 0 1rem 0; color: #374151;">${availableYear2SubjectsText}:</h4>
                            <div id="subjectsList" style="max-height: 300px; overflow-y: auto;">
                                ${subjectsHtml}
                            </div>
                            
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #E5E7EB;">
                                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                    <button onclick="this.closest('.promotion-modal').remove()" 
                                            style="background: #6B7280; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer;">
                                        ${cancelText}
                                    </button>
                                    <button onclick="executePromotion(${studentId})" 
                                            style="background: #10B981; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        <i class="fas fa-arrow-up"></i> ${promoteStudentText}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                modal.className = 'promotion-modal';
                document.body.appendChild(modal);
                
                // Close on backdrop click
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) modal.remove();
                });
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showErrorMessage(getTranslation('error_loading_subjects'));
            });
            } catch (error) {
                hideLoading();
                console.error('Promotion dialog error:', error);
                showErrorMessage(getTranslation('error_loading_subjects'));
            }
        }

        function executePromotion(studentId) {
            const modal = document.querySelector('.promotion-modal');
            const selectedSubjects = [];
            
            // Get selected subjects
            const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
            checkboxes.forEach(checkbox => {
                selectedSubjects.push(checkbox.value);
            });
            
            if (selectedSubjects.length === 0) {
                showErrorMessage(getTranslation('select_at_least_one_subject'));
                return;
            }
            
            // Execute promotion with selected subjects
            showLoading(getTranslation('promoting_student'), getTranslation('moving_to_year_2'));
            const formData = new FormData();
            formData.append('action', 'promote_student_with_subjects');
            formData.append('student_id', studentId);
            formData.append('selected_subjects', JSON.stringify(selectedSubjects));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                modal.remove();
                hideLoading();
                
                if (data.success) {
                    showNotification(getTranslation('student_promoted_success'), 'success');
                    setTimeout(() => {
                        reloadTableData('students');
                    }, 500);
                } else {
                    // Show detailed error modal
                    if (data.details) {
                        showEligibilityErrorModal('Promotion', data);
                    } else {
                        showNotification(data.message || getTranslation('error_promoting_student'), 'error');
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                modal.remove();
                showNotification('Network error. Please try again.', 'error');
            });
        }

        function handleSubjectAction(action, subjectId) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('subject_id', subjectId);
            
            if (action === 'delete_subject' && !confirm('Are you sure you want to delete this subject?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('successfully')) {
                    let message = action === 'delete_subject' ? 'Subject deleted successfully!' : 'Subject updated successfully!';
                    showSuccessMessage(message);
                    
                    if (action === 'delete_subject') {
                        // Remove the subject row from the table
                        let subjectButton = document.querySelector(`button[onclick*="handleSubjectAction('delete_subject', ${subjectId})"]`);
                        if (!subjectButton) {
                            // Fallback: try to find by data attribute or alternative selector
                            subjectButton = document.querySelector(`button[onclick*="deleteSubject(${subjectId})"]`);
                        }
                        if (subjectButton) {
                            const row = subjectButton.closest('tr');
                            if (row) {
                                row.style.animation = 'fadeOut 0.3s ease';
                                setTimeout(() => row.remove(), 300);
                            }
                        }
                        
                        // Refresh subject enrollment in student form
                        refreshSubjectEnrollment();
                    }
                } else {
                    showErrorMessage('An error occurred. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }

        function handleMarkAction(action, markId) {
            if (action === 'delete_mark' && !confirm('Are you sure you want to reset this mark to 0?')) {
                return;
            }
            
            showLoading('Processing', 'Please wait...');
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('mark_id', markId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                console.log('Response:', data);
                hideLoading();
                if (data.includes('successfully') || data.includes('Success')) {
                    showNotification('Mark reset successfully!', 'success');
                    setTimeout(() => {
                        reloadTableData('marks');
                    }, 500);
                } else {
                    console.error('Server response:', data);
                    showNotification(data || 'An error occurred. Please try again.', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Network error. Please try again.', 'error');
            });
        }

        // Graduation page functions
        function handleGraduatedAction(action, graduatedId) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('graduated_id', graduatedId);
            
            if (action === 'delete_graduated_student' && !confirm('Are you sure you want to delete this graduated student record?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('successfully')) {
                    showSuccessMessage('Graduated student deleted successfully!');
                    
                    // Remove the row from the table
                    const graduatedButton = document.querySelector(`button[onclick*="handleGraduatedAction('delete_graduated_student', ${graduatedId})"]`);
                    if (graduatedButton) {
                        const graduatedRow = graduatedButton.closest('tr');
                        if (graduatedRow) {
                            graduatedRow.style.animation = 'fadeOut 0.3s ease';
                            setTimeout(() => graduatedRow.remove(), 300);
                        }
                    }
                } else {
                    showErrorMessage('An error occurred. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }

        function toggleSelectAllGraduates() {
            const masterCheckbox = document.getElementById('masterCheckboxGraduates');
            const checkboxes = document.querySelectorAll('.graduate-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = masterCheckbox.checked;
            });
            
            updateBulkDeleteButton();
        }

        function updateBulkDeleteButton() {
            const checkboxes = document.querySelectorAll('.graduate-checkbox:checked');
            const bulkDeleteBtn = document.getElementById('bulkDeleteGraduates');
            
            if (checkboxes.length > 0) {
                bulkDeleteBtn.style.display = 'inline-block';
            } else {
                bulkDeleteBtn.style.display = 'none';
            }
        }

        function bulkDeleteGraduates() {
            const checkboxes = document.querySelectorAll('.graduate-checkbox:checked');
            
            if (checkboxes.length === 0) {
                showErrorMessage('No graduates selected for deletion.');
                return;
            }
            
            if (!confirm(`Are you sure you want to delete ${checkboxes.length} graduated student record(s)?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bulk_delete_graduated');
            
            checkboxes.forEach(checkbox => {
                formData.append('selected_graduates[]', checkbox.value);
            });
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('successfully')) {
                    showSuccessMessage(`${checkboxes.length} graduated students deleted successfully!`);
                    
                    // Remove selected rows from the table
                    checkboxes.forEach(checkbox => {
                        const row = checkbox.closest('tr');
                        if (row) {
                            row.style.animation = 'fadeOut 0.3s ease';
                            setTimeout(() => row.remove(), 300);
                        }
                    });
                    
                    // Reset UI
                    setTimeout(() => {
                        updateBulkDeleteButton();
                        document.getElementById('masterCheckboxGraduates').checked = false;
                    }, 400);
                } else {
                    showErrorMessage('An error occurred during bulk deletion.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }

        function showResetDataModal() {
            const modalHTML = `
                <div id="resetDataModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; text-align: center;">
                        <h3 style="margin: 0 0 1rem 0; color: #dc3545;">🚨 Reset All Data</h3>
                        <p style="margin-bottom: 1.5rem; color: #666;">
                            This will permanently delete ALL data including students, subjects, marks, and graduated students. 
                            All ID sequences will be reset to start from 1.
                        </p>
                        <p style="margin-bottom: 1.5rem; color: #dc3545; font-weight: bold;">
                            This action cannot be undone!
                        </p>
                        <div style="display: flex; gap: 1rem; justify-content: center;">
                            <button onclick="closeResetDataModal()" style="padding: 0.75rem 1.5rem; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer;">
                                Cancel
                            </button>
                            <button onclick="confirmResetData()" style="padding: 0.75rem 1.5rem; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer;">
                                Yes, Reset All Data
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }

        function closeResetDataModal() {
            const modal = document.getElementById('resetDataModal');
            if (modal) {
                modal.remove();
            }
        }

        function confirmResetData() {
            const formData = new FormData();
            formData.append('action', 'reset_all_data');
            formData.append('confirm_reset', 'yes');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('successfully')) {
                    showSuccessMessage('All data has been reset successfully!');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showErrorMessage('An error occurred during data reset.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
            
            closeResetDataModal();
        }

        function manualResequence(tableName) {
            if (!confirm(`Resequence ${tableName} IDs to be sequential (1,2,3,4...)? This will fix ID ordering issues.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'manual_resequence');
            formData.append('table_name', tableName);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (data.includes('resequenced successfully')) {
                    showSuccessMessage(`${tableName} IDs resequenced successfully!`);
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showErrorMessage('Error resequencing IDs. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorMessage('Network error. Please try again.');
            });
        }

        // Initialize marks page filters
        function initializeMarksFilters() {
            const filterMarksClass = document.getElementById('filterMarksClass');
            
            if (filterMarksClass) {
                // Clear existing options except the default "All Classes"
                const allClassesText = getTranslation('all_classes');
                filterMarksClass.innerHTML = `<option value="">${allClassesText}</option>`;
                
                // Get all classes in new format only (after migration)
                <?php
                $filter_classes = pg_query($conn, "
                    SELECT DISTINCT class_level 
                    FROM students 
                    WHERE status = 'active' AND class_level ~ '^[12][ABC]$' 
                    ORDER BY class_level
                ");
                
                if ($filter_classes && pg_num_rows($filter_classes) > 0) {
                    echo "const availableClasses = [];\n";
                    while($class = pg_fetch_assoc($filter_classes)) {
                        echo "availableClasses.push('" . htmlspecialchars($class['class_level']) . "');\n";
                    }
                    echo "
                    availableClasses.forEach(className => {
                        const option = document.createElement('option');
                        option.value = className;
                        // Format display name with translation
                        const year = className.charAt(0);
                        const letter = className.charAt(1);
                        const yearText = getTranslation('year');
                        const classText = getTranslation('class');
                        option.textContent = `\${yearText} \${year} - \${classText} \${letter}`;
                        filterMarksClass.appendChild(option);
                    });
                    ";
                } else {
                    echo "console.log('No classes found after migration');";
                }
                ?>
            }
        }

        // Initialize page-specific functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-migrate old class format to new format
            autoMigrateClassFormat();
            
            // Initialize marks filters if on marks page
            if (window.location.search.includes('page=marks')) {
                setTimeout(() => {
                    initializeMarksFilters();
                }, 500); // Give migration time to complete
            }
        });

        // Function to automatically migrate old class format to new format
        function autoMigrateClassFormat() {
            // Send AJAX request to migrate old format classes
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=migrate_class_format'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Class format migration completed:', data.message);
                } else {
                    console.log('Class format migration failed:', data.message);
                }
            })
            .catch(error => {
                console.error('Migration error:', error);
            });
        }

        // ===== TEACHER MANAGEMENT FUNCTIONS =====
        
        function updateAddTeacherYear() {
            const subjectSelect = document.getElementById('addTeacherSubject');
            const yearSelect = document.getElementById('addTeacherYear');
            
            if (subjectSelect.value) {
                const selectedOption = subjectSelect.options[subjectSelect.selectedIndex];
                const year = selectedOption.getAttribute('data-year');
                
                yearSelect.innerHTML = `<option value="${year}" selected>Year ${year}</option>`;
                yearSelect.disabled = false;
            } else {
                yearSelect.innerHTML = '<option value="">Select subject first</option>';
                yearSelect.disabled = true;
            }
        }
        
        function handleTeacherFormSubmit(event, form) {
            event.preventDefault();
            
            // Show loader
            showLoader('Adding teacher...');
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoader();
                if (data.success) {
                    // Check if credentials were returned
                    if (data.credentials) {
                        // Show credentials popup
                        showCredentialsPopup(data.credentials.username, data.credentials.password, form.querySelector('[name="teacher_name"]').value);
                    } else {
                        showSuccessMessage(data.message);
                        setTimeout(() => {
                            fetchAndUpdateSection('teachers');
                        }, 1000);
                    }
                } else {
                    showErrorMessage(data.message);
                }
            })
            .catch(error => {
                hideLoader();
                console.error('Error:', error);
                showErrorMessage('Failed to add teacher');
            });
            
            return false;
        }
        
        function showCredentialsPopup(username, password, teacherName) {
            const popup = document.createElement('div');
            popup.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.7); display: flex; align-items: center;
                justify-content: center; z-index: 100000; backdrop-filter: blur(4px);
            `;
            
            popup.innerHTML = `
                <div style="background: white; padding: 2.5rem; border-radius: 16px; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <div style="font-size: 60px; margin-bottom: 1rem;"><i class="fas fa-check-circle" style="color: #10B981;"></i></div>
                        <h2 style="margin: 0 0 0.5rem 0; color: #10b981; font-size: 24px;">Teacher Added Successfully!</h2>
                        <p style="margin: 0; color: #666; font-size: 14px;">Login credentials generated for ${teacherName}</p>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; border-left: 4px solid #10b981;">
                        <h3 style="margin: 0 0 1rem 0; color: #333; font-size: 16px; font-weight: 600;"><i class="fas fa-key"></i> Login Credentials</h3>
                        
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; color: #666; font-size: 12px; margin-bottom: 0.5rem; font-weight: 500;">Username</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="text" id="credUsername" value="${username}" readonly 
                                    style="flex: 1; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: monospace; font-size: 14px; background: white;">
                                <button onclick="copyToClipboard('credUsername', this)" 
                                    style="padding: 10px 16px; background: #1e3a8a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; white-space: nowrap;">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <label style="display: block; color: #666; font-size: 12px; margin-bottom: 0.5rem; font-weight: 500;">Password</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="text" id="credPassword" value="${password}" readonly 
                                    style="flex: 1; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: monospace; font-size: 14px; background: white;">
                                <button onclick="copyToClipboard('credPassword', this)" 
                                    style="padding: 10px 16px; background: #1e3a8a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; white-space: nowrap;">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #ffc107;">
                        <p style="margin: 0; color: #856404; font-size: 13px; line-height: 1.5;">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> Please share these credentials securely with the teacher. 
                            They should change the password on first login.
                        </p>
                    </div>
                    
                    <button onclick="closeCredentialsPopup()" 
                        style="width: 100%; padding: 14px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
                        color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer;">
                        Done
                    </button>
                </div>
            `;
            
            document.body.appendChild(popup);
        }
        
        function copyToClipboard(inputId, button) {
            const input = document.getElementById(inputId);
            input.select();
            document.execCommand('copy');
            
            const originalText = button.innerHTML;
            button.innerHTML = '✓ Copied!';
            button.style.background = '#10b981';
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.style.background = '#1e3a8a';
            }, 2000);
        }
        
        function closeCredentialsPopup() {
            const popups = document.querySelectorAll('[style*="backdrop-filter: blur(4px)"]');
            popups.forEach(popup => popup.remove());
            fetchAndUpdateSection('teachers');
        }
        
        function showLoader(message = 'Processing...') {
            const loader = document.createElement('div');
            loader.id = 'globalLoader';
            loader.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                     background: rgba(0,0,0,0.7); display: flex; align-items: center; 
                     justify-content: center; z-index: 99999; backdrop-filter: blur(4px);">
                    <div style="background: white; padding: 2rem 3rem; border-radius: 12px; 
                         text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                        <div class="spinner" style="width: 50px; height: 50px; margin: 0 auto 1rem; 
                             border: 4px solid #f3f3f3; border-top: 4px solid #1e3a8a; 
                             border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        <p style="margin: 0; font-weight: 600; color: #1e3a8a; font-size: 1.1rem;">${message}</p>
                    </div>
                </div>
            `;
            document.body.appendChild(loader);
        }
        
        function hideLoader() {
            const loader = document.getElementById('globalLoader');
            if (loader) loader.remove();
        }
        
        function showSuccessMessage(message) {
            const alert = document.createElement('div');
            alert.style.cssText = `
                position: fixed; top: 20px; right: 20px; z-index: 100000;
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: white; padding: 1rem 1.5rem; border-radius: 8px;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                font-weight: 500; animation: slideInRight 0.3s ease;
            `;
            alert.textContent = '✓ ' + message;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 3000);
        }
        
        function showErrorMessage(message) {
            const alert = document.createElement('div');
            alert.style.cssText = `
                position: fixed; top: 20px; right: 20px; z-index: 100000;
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: white; padding: 1rem 1.5rem; border-radius: 8px;
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
                font-weight: 500; animation: slideInRight 0.3s ease;
            `;
            alert.textContent = '✗ ' + message;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 4000);
        }
        
        function filterTeachers() {
            const searchInput = document.getElementById('searchTeacher');
            const degreeFilter = document.getElementById('filterTeacherDegree');
            const specFilter = document.getElementById('filterTeacherSpec');
            const yearFilter = document.getElementById('filterTeacherYear');
            const classFilter = document.getElementById('filterTeacherClass');
            
            if (!searchInput || !degreeFilter || !specFilter) return;
            
            const searchValue = searchInput.value.toLowerCase();
            const degreeValue = degreeFilter.value;
            const specValue = specFilter.value;
            const yearValue = yearFilter ? yearFilter.value : '';
            const classValue = classFilter ? classFilter.value : '';
            
            const table = document.getElementById('teachersTable');
            const rows = table.getElementsByClassName('teacher-row');
            
            for (let row of rows) {
                const name = row.cells[1].textContent.toLowerCase();
                const email = row.cells[2].textContent.toLowerCase();
                const phone = row.cells[3].textContent.toLowerCase();
                const degree = row.getAttribute('data-degree') || '';
                const spec = row.getAttribute('data-specialization') || '';
                
                const matchesSearch = name.includes(searchValue) || email.includes(searchValue) || phone.includes(searchValue);
                const matchesDegree = !degreeValue || degree === degreeValue;
                const matchesSpec = !specValue || spec.includes(specValue);
                
                // For year and class filtering, we need to check assignments
                let matchesYear = !yearValue;
                let matchesClass = !classValue;
                
                if (yearValue || classValue) {
                    const teacherId = row.getAttribute('data-teacher-id');
                    const detailsRow = document.getElementById('teacher-details-' + teacherId);
                    if (detailsRow) {
                        const assignmentRows = detailsRow.querySelectorAll('tbody tr');
                        for (let assignRow of assignmentRows) {
                            const assignYear = assignRow.getAttribute('data-year'); // Get year from data attribute
                            const classText = assignRow.cells[2] ? assignRow.cells[2].textContent : '';
                            
                            if (!yearValue || assignYear === yearValue) {
                                matchesYear = true;
                            }
                            if (!classValue || classText.includes(classValue)) {
                                matchesClass = true;
                            }
                        }
                    } else {
                        // If no assignments, don't match year/class filters
                        matchesYear = !yearValue;
                        matchesClass = !classValue;
                    }
                }
                
                if (matchesSearch && matchesDegree && matchesSpec && matchesYear && matchesClass) {
                    row.style.display = '';
                    const detailsRow = document.getElementById('teacher-details-' + row.dataset.teacherId);
                    if (detailsRow && detailsRow.style.display === 'table-row') {
                        detailsRow.style.display = 'table-row';
                    }
                } else {
                    row.style.display = 'none';
                    const detailsRow = document.getElementById('teacher-details-' + row.dataset.teacherId);
                    if (detailsRow) {
                        detailsRow.style.display = 'none';
                    }
                }
            }
        }
        
        function toggleTeacherDetails(teacherId) {
            showLoading(getTranslation('loading'), getTranslation('please_wait'));
            
            fetch(`?action=get_teacher_subjects&teacher_id=${teacherId}`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showTeacherSubjectsModal(data.teacher, data.assignments);
                    } else {
                        showErrorMessage(data.message || 'Failed to load teacher subjects');
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showErrorMessage('Failed to load teacher subjects');
                });
        }
        
        function showTeacherSubjectsModal(teacher, assignments) {
            try {
                const escape = str => String(str).replace(/[&<>"']/g, m => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m]));
                
                // Get all translations first
                const studentsText = getTranslation('students');
                const totalText = getTranslation('total');
                const yearText = getTranslation('year');
                const assignedText = getTranslation('assigned');
                const removeText = getTranslation('remove');
                const noSubjectAssignmentsText = getTranslation('no_subject_assignments');
                const assignedSubjectsText = getTranslation('assigned_subjects');
                const assignNewSubjectText = getTranslation('assign_new_subject');
                
                const assignmentsHTML = assignments && assignments.length > 0 ? assignments.map(a => {
                // Parse class breakdown if available
                let classBreakdownHTML = '';
                let totalStudents = 0;
                
                if (a.class_breakdown && a.class_breakdown !== 'null') {
                    try {
                        const breakdown = JSON.parse(a.class_breakdown);
                        if (Array.isArray(breakdown) && breakdown.length > 0) {
                            // Sort by class name
                            breakdown.sort((x, y) => x.class.localeCompare(y.class));
                            
                            // Create badges for each class
                            classBreakdownHTML = breakdown.map(item => {
                                totalStudents += parseInt(item.count) || 0;
                                return `<span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #2563eb; color: white;">
                                    <i class="fas fa-door-open"></i> Class ${escape(item.class)}: ${item.count} ${studentsText}
                                </span>`;
                            }).join('');
                            
                            // Add total badge if multiple classes
                            if (breakdown.length > 1) {
                                classBreakdownHTML += `<span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #3b82f6; color: white;">
                                    <i class="fas fa-users"></i> ${totalText}: ${totalStudents} ${studentsText}
                                </span>`;
                            }
                        } else {
                            classBreakdownHTML = `<span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #94a3b8; color: white;">
                                <i class="fas fa-users"></i> 0 ${studentsText}
                            </span>`;
                        }
                    } catch (e) {
                        classBreakdownHTML = `<span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #3b82f6; color: white;">
                            <i class="fas fa-users"></i> ${a.student_count || 0} ${studentsText}
                        </span>`;
                    }
                } else {
                    classBreakdownHTML = `<span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #94a3b8; color: white;">
                        <i class="fas fa-users"></i> 0 ${studentsText}
                    </span>`;
                }
                
                return `
                <div onclick="event.stopPropagation()" style="padding: 1rem; margin-bottom: 0.75rem; background: #f8fafc; border-left: 4px solid #1e3a8a; border-radius: 6px; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; cursor: default; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 1.1rem; margin-bottom: 0.5rem;">
                            ${escape(a.subject_name)}
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; background: #1e3a8a; color: white;">
                                <i class="fas fa-calendar-alt"></i> ${yearText} ${a.year}
                            </span>
                            ${classBreakdownHTML}
                            <span style="padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; background: #e5e7eb; color: #64748b;">
                                <i class="fas fa-clock"></i> ${assignedText} ${a.assigned_date}
                            </span>
                        </div>
                    </div>
                    <button class="action-btn delete" onclick="removeTeacherAssignmentFromModal(${a.id}, ${teacher.id})" title="Remove Assignment" style="padding: 8px 14px; font-size: 0.85rem;">
                        <i class="fas fa-trash-alt" style="margin-right: 0.4rem;"></i>${removeText}
                    </button>
                </div>
                `;
            }).join('') : `
                <div style="text-align: center; padding: 3rem;">
                    <i class="fas fa-book-open" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                    <p style="color: #94a3b8; font-size: 1rem; margin: 0;">${noSubjectAssignmentsText}</p>
                </div>
            `;
            
            const modalHTML = `
                <div id="teacherSubjectsModal" onclick="if(event.target.id === 'teacherSubjectsModal') closeTeacherSubjectsModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                     background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000;">
                    <div onclick="event.stopPropagation()" style="background: white; padding: 0; border-radius: 12px; max-width: 800px; width: 90%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; color: white; display: flex; align-items: center; justify-content: space-between;">
                            <h3 style="margin: 0; font-size: 1.3rem; display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <span>${assignedSubjectsText} - ${escape(teacher.name)}</span>
                            </h3>
                            <button onclick="closeTeacherSubjectsModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div style="padding: 1.5rem 2rem; overflow-y: auto; flex: 1;">
                            ${assignmentsHTML}
                        </div>
                        <div style="padding: 1.5rem 2rem; border-top: 2px solid #e5e7eb; background: #f9fafb; display: flex; justify-content: center; gap: 1rem;">
                            <button class="apply-filters-btn" onclick="openAssignSubjectsModalFromTeacher(${teacher.id}, '${escape(teacher.name)}')" style="padding: 0.75rem 2rem; font-size: 0.95rem;">
                                <i class="fas fa-plus-circle" style="margin-right: 0.5rem;"></i>
                                ${assignNewSubjectText}
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            // Apply current language to the newly added modal
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            applyTranslations(currentLang);
            } catch (error) {
                console.error('Teacher subjects modal error:', error);
                showErrorMessage('Failed to load teacher subjects');
            }
        }
        
        function closeTeacherSubjectsModal() {
            const modal = document.getElementById('teacherSubjectsModal');
            if (modal) modal.remove();
        }
        
        function removeTeacherAssignmentFromModal(assignmentId, teacherId) {
            if (confirm('Are you sure you want to remove this assignment?')) {
                showLoader('Removing assignment...');
                
                const formData = new FormData();
                formData.append('action', 'remove_teacher_assignment');
                formData.append('assignment_id', assignmentId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        hideLoader();
                        if (data.success) {
                            showSuccessMessage('Assignment removed successfully');
                            // Refresh the modal content
                            closeTeacherSubjectsModal();
                            setTimeout(() => toggleTeacherDetails(teacherId), 300);
                        } else {
                            showErrorMessage(data.message || 'Failed to remove assignment');
                        }
                    })
                    .catch(error => {
                        hideLoader();
                        console.error('Error:', error);
                        showErrorMessage('Failed to remove assignment');
                    });
            }
        }
        
        function openAssignSubjectsModalFromTeacher(teacherId, teacherName) {
            // Store teacher info to refresh modal after assignment
            window.currentTeacherId = teacherId;
            openAssignSubjectsModal(teacherId, teacherName);
        }
        
        function editTeacher(teacherId) {
            showLoading(getTranslation('loading'), getTranslation('please_wait'));
            
            // Fetch teacher data
            fetch('?action=get_teacher&id=' + teacherId)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showEditTeacherModal(data.teacher);
                    } else {
                        showErrorMessage(data.message || 'Failed to load teacher data');
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showErrorMessage('Failed to load teacher data');
                });
        }
        
        function showEditTeacherModal(teacher) {
            // Escape function to prevent XSS and errors
            const escape = (str) => {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            };
            
            const modalHTML = `
                <div id="editTeacherModal" onclick="if(event.target.id === 'editTeacherModal') closeEditTeacherModal()" 
                     style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); 
                     display: flex; align-items: center; justify-content: center; z-index: 10000; padding: 1rem;">
                    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; max-width: 900px; width: 95%; 
                         max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        
                        <!-- Header -->
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; 
                             border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: white; font-size: 1.25rem; font-weight: 600;">
                                <i class="fas fa-user-edit" style="margin-right: 0.5rem;"></i>
                                <span data-translate="edit_teacher">Edit Teacher</span>
                            </h3>
                            <button onclick="closeEditTeacherModal()" style="background: rgba(255,255,255,0.2); border: none; 
                                 color: white; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; 
                                 display: flex; align-items: center; justify-content: center; font-size: 1.25rem; 
                                 transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                                 onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 2rem; overflow-y: auto; flex: 1;">
                            <form id="editTeacherForm" onsubmit="submitEditTeacher(event, ${teacher.id})">
                                <!-- 2 Column Grid Layout -->
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="full_name">Full Name</label>
                                        <input type="text" name="teacher_name" value="${escape(teacher.name)}" class="premium-select" required 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="email">Email</label>
                                        <input type="email" name="email" value="${escape(teacher.email)}" class="premium-select" required 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="phone">Phone</label>
                                        <input type="tel" name="phone" value="${escape(teacher.phone || '')}" class="premium-select" 
                                               style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="specialization">Specialization</label>
                                        <select name="specialization" class="premium-select" required 
                                                style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            <option value="Mathematics" ${teacher.specialization === 'Mathematics' ? 'selected' : ''} data-translate="spec_mathematics">Mathematics</option>
                                            <option value="Physics" ${teacher.specialization === 'Physics' ? 'selected' : ''} data-translate="spec_physics">Physics</option>
                                            <option value="Chemistry" ${teacher.specialization === 'Chemistry' ? 'selected' : ''} data-translate="spec_chemistry">Chemistry</option>
                                            <option value="Biology" ${teacher.specialization === 'Biology' ? 'selected' : ''} data-translate="spec_biology">Biology</option>
                                            <option value="English" ${teacher.specialization === 'English' ? 'selected' : ''} data-translate="spec_english">English</option>
                                            <option value="Arabic" ${teacher.specialization === 'Arabic' ? 'selected' : ''} data-translate="spec_arabic">Arabic</option>
                                            <option value="Kurdish" ${teacher.specialization === 'Kurdish' ? 'selected' : ''} data-translate="spec_kurdish">Kurdish</option>
                                            <option value="Computer Science" ${teacher.specialization === 'Computer Science' ? 'selected' : ''} data-translate="spec_computer_science">Computer Science</option>
                                            <option value="History" ${teacher.specialization === 'History' ? 'selected' : ''} data-translate="spec_history">History</option>
                                            <option value="Geography" ${teacher.specialization === 'Geography' ? 'selected' : ''} data-translate="spec_geography">Geography</option>
                                            <option value="Islamic Studies" ${teacher.specialization === 'Islamic Studies' ? 'selected' : ''} data-translate="spec_islamic_studies">Islamic Studies</option>
                                            <option value="Physical Education" ${teacher.specialization === 'Physical Education' ? 'selected' : ''} data-translate="spec_physical_education">Physical Education</option>
                                            <option value="Arts" ${teacher.specialization === 'Arts' ? 'selected' : ''} data-translate="spec_arts">Arts</option>
                                            <option value="Management" ${teacher.specialization === 'Management' ? 'selected' : ''} data-translate="spec_management">Management</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="degree">Degree</label>
                                        <select name="degree" class="premium-select" required 
                                                style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            <option value="High School Diploma" ${teacher.degree === 'High School Diploma' ? 'selected' : ''} data-translate="degree_high_school">High School Diploma</option>
                                            <option value="Associate Degree" ${teacher.degree === 'Associate Degree' ? 'selected' : ''} data-translate="degree_associate">Associate Degree</option>
                                            <option value="Bachelor's Degree" ${teacher.degree === "Bachelor's Degree" ? 'selected' : ''} data-translate="degree_bachelor">Bachelor's Degree</option>
                                            <option value="Master's Degree" ${teacher.degree === "Master's Degree" ? 'selected' : ''} data-translate="degree_master">Master's Degree</option>
                                            <option value="Doctorate (PhD)" ${teacher.degree === 'Doctorate (PhD)' ? 'selected' : ''} data-translate="degree_doctorate">Doctorate (PhD)</option>
                                            <option value="Professional Certificate" ${teacher.degree === 'Professional Certificate' ? 'selected' : ''} data-translate="degree_certificate">Professional Certificate</option>
                                        </select>
                                    </div>
                                    
                                    <div style="grid-column: span 2;">
                                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
                                            <div>
                                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="salary">Monthly Salary (IQD)</label>
                                                <input type="number" name="salary" value="${teacher.salary || ''}" class="premium-select" min="0" step="1000"
                                                       style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            </div>
                                            
                                            <div>
                                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e3a8a;" data-translate="join_date">Join Date</label>
                                                <input type="date" name="join_date" value="${teacher.join_date}" class="premium-select" 
                                                       style="width: 100%; padding: 0.65rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                                            </div>
                                        </div>
                                    </div>
                                    
                                </div>
                            </form>
                        </div>
                        
                        <!-- Footer -->
                        <div style="padding: 1.5rem 2rem; border-top: 1px solid #e5e7eb; background: #f8fafc; 
                             border-radius: 0 0 12px 12px; display: flex; gap: 1rem; justify-content: flex-end;">
                            <button type="button" onclick="closeEditTeacherModal()" 
                                    style="padding: 0.65rem 1.5rem; border: 1.5px solid #cbd5e1; background: white; 
                                    color: #475569; border-radius: 6px; cursor: pointer; font-weight: 600; 
                                    transition: all 0.2s; font-size: 0.95rem;" 
                                    onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#94a3b8'" 
                                    onmouseout="this.style.background='white'; this.style.borderColor='#cbd5e1'">
                                <i class="fas fa-times" style="margin-right: 0.5rem;"></i>
                                <span data-translate="cancel">Cancel</span>
                            </button>
                            <button type="submit" form="editTeacherForm" 
                                    style="padding: 0.65rem 1.5rem; border: none; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); 
                                    color: white; border-radius: 6px; cursor: pointer; font-weight: 600; 
                                    transition: all 0.2s; font-size: 0.95rem; box-shadow: 0 2px 8px rgba(30,58,138,0.3);" 
                                    onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(30,58,138,0.4)'" 
                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(30,58,138,0.3)'">
                                <i class="fas fa-save" style="margin-right: 0.5rem;"></i>
                                <span data-translate="update">Update</span>
                            </button>
                        </div>
                        
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Apply translations to the newly added modal
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            changeLanguage(currentLang);
        }
        
        function closeEditTeacherModal() {
            const modal = document.getElementById('editTeacherModal');
            if (modal) modal.remove();
        }
        
        function submitEditTeacher(event, teacherId) {
            event.preventDefault();
            showLoader('Updating teacher...');
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'update_teacher');
            formData.append('teacher_id', teacherId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoader();
                closeEditTeacherModal();
                if (data.success) {
                    showSuccessMessage(data.message);
                    setTimeout(() => {
                        fetchAndUpdateSection('teachers');
                    }, 1000);
                } else {
                    showErrorMessage(data.message);
                }
            })
            .catch(error => {
                hideLoader();
                console.error('Error:', error);
                showErrorMessage('Failed to update teacher');
            });
        }
        
        function deleteTeacher(teacherId) {
            const translations = {
                en: 'Are you sure you want to delete this teacher? This action cannot be undone.',
                ar: 'هل أنت متأكد من حذف هذا المعلم؟ لا يمكن التراجع عن هذا الإجراء.',
                ku: 'دڵنیای لە سڕینەوەی ئەم مامۆستایە؟ ناتوانی ئەم کردارە بگەڕێنیتەوە.'
            };
            
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const confirmMessage = translations[currentLang] || translations.en;
            
            if (confirm(confirmMessage)) {
                showLoader('Deleting teacher...');
                
                const formData = new FormData();
                formData.append('action', 'delete_teacher');
                formData.append('teacher_id', teacherId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoader();
                    if (data.success) {
                        showSuccessMessage(data.message);
                        setTimeout(() => {
                            fetchAndUpdateSection('teachers');
                        }, 1000);
                    } else {
                        showErrorMessage(data.message);
                    }
                })
                .catch(error => {
                    hideLoader();
                    console.error('Error:', error);
                    showErrorMessage('Failed to delete teacher');
                });
            }
        }
        
        // Generate login credentials for teacher
        // Teacher Access Management Functions
        function openCredentialModal(teacherId, teacherName, currentUsername, hasPassword, currentPassword) {
            document.getElementById('credential_teacher_id').value = teacherId;
            document.getElementById('credential_teacher_name').value = teacherName;
            document.getElementById('credential_username').value = currentUsername || '';
            
            // Show current password if it exists
            if (currentPassword && currentPassword.trim() !== '') {
                document.getElementById('credential_password').value = currentPassword;
                document.getElementById('credential_password').placeholder = 'Current: ' + currentPassword;
            } else {
                document.getElementById('credential_password').value = '';
                document.getElementById('credential_password').placeholder = 'Enter new password';
            }
            
            const modalTitle = currentUsername ? 'Update Teacher Access' : 'Create Teacher Access';
            document.getElementById('modalTitle').textContent = modalTitle;
            
            // Update password field based on whether password exists
            const passwordField = document.getElementById('credential_password');
            const passwordNote = document.getElementById('passwordNote');
            const usernameField = document.getElementById('credential_username');
            
            if (hasPassword && currentUsername) {
                passwordField.required = false;
                passwordNote.textContent = '(Current password shown)';
                passwordNote.style.color = '#10b981';
                usernameField.required = true;
            } else {
                passwordField.required = true;
                passwordNote.textContent = '(Required for new access)';
                passwordNote.style.color = '#ef4444';
                usernameField.required = true;
            }
            
            document.getElementById('credentialModal').style.display = 'flex';
        }
        
        function closeCredentialModal() {
            document.getElementById('credentialModal').style.display = 'none';
            document.getElementById('credentialForm').reset();
        }
        
        function saveTeacherCredentials(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'generate_teacher_credentials');
            
            showLoading(getTranslation('saving'), getTranslation('please_wait'));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showSuccessMessage(data.message);
                    closeCredentialModal();
                    setTimeout(() => {
                        fetchAndUpdateSection('teachers');
                    }, 1000);
                } else {
                    showErrorMessage(data.message);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showErrorMessage('Failed to save credentials');
            });
            
            return false;
        }
        
        function filterTeacherAccess() {
            const searchValue = document.getElementById('searchTeacherAccess').value.toLowerCase();
            const specValue = document.getElementById('filterSpecialization').value;
            const statusValue = document.getElementById('filterLoginStatus').value;
            const degreeValue = document.getElementById('filterDegree').value;
            
            const rows = document.querySelectorAll('.teacher-access-row');
            
            rows.forEach(row => {
                const name = row.dataset.name || '';
                const specialization = row.dataset.specialization || '';
                const degree = row.dataset.degree || '';
                const status = row.dataset.status || '';
                
                const matchesSearch = name.includes(searchValue);
                const matchesSpec = !specValue || specialization === specValue;
                const matchesStatus = !statusValue || status === statusValue;
                const matchesDegree = !degreeValue || degree === degreeValue;
                
                if (matchesSearch && matchesSpec && matchesStatus && matchesDegree) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('credentialModal');
            if (event.target == modal) {
                closeCredentialModal();
            }
        }
        
        function removeTeacherAssignment(assignmentId) {
            const translations = {
                en: 'Remove this subject assignment?',
                ar: 'إزالة تعيين هذه المادة؟',
                ku: 'لابردنی دیاریکردنی ئەم وانەیە؟'
            };
            
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            const confirmMessage = translations[currentLang] || translations.en;
            
            if (confirm(confirmMessage)) {
                showLoader('Removing assignment...');
                
                const formData = new FormData();
                formData.append('action', 'remove_teacher_assignment');
                formData.append('assignment_id', assignmentId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoader();
                    if (data.success) {
                        showSuccessMessage(data.message);
                        setTimeout(() => {
                            fetchAndUpdateSection('teachers');
                        }, 1000);
                    } else {
                        showErrorMessage(data.message);
                    }
                })
                .catch(error => {
                    hideLoader();
                    console.error('Error:', error);
                    showErrorMessage('Failed to remove assignment');
                });
            }
        }
        
        function printTeachers() {
            const table = document.getElementById('teachersTable');
            const rows = Array.from(table.querySelectorAll('tbody tr.teacher-row'));
            const visibleRows = rows.filter(row => row.style.display !== 'none');
            
            if (visibleRows.length === 0) {
                alert('No data to print');
                return;
            }
            
            // Calculate statistics
            const total = visibleRows.length;
            const degrees = {};
            const specializations = {};
            let totalSalary = 0;
            let salaryCount = 0;
            
            visibleRows.forEach(row => {
                const degree = row.getAttribute('data-degree') || 'N/A';
                const spec = row.getAttribute('data-specialization') || 'N/A';
                degrees[degree] = (degrees[degree] || 0) + 1;
                specializations[spec] = (specializations[spec] || 0) + 1;
                
                // Extract salary
                const salaryCell = row.querySelectorAll('td')[6];
                if (salaryCell) {
                    const salaryText = salaryCell.textContent.replace(/[^0-9]/g, '');
                    const salary = parseInt(salaryText);
                    if (salary > 0) {
                        totalSalary += salary;
                        salaryCount++;
                    }
                }
            });
            
            const avgSalary = salaryCount > 0 ? (totalSalary / salaryCount) : 0;
            
            // Generate filter description
            const degreeFilter = document.getElementById('filterTeacherDegree').value;
            const specFilter = document.getElementById('filterTeacherSpec').value;
            const yearFilter = document.getElementById('filterTeacherYear').value;
            const classFilter = document.getElementById('filterTeacherClass').value;
            
            let reportDescription = 'This report presents a comprehensive overview of teachers ';
            let filters = [];
            
            if (degreeFilter) filters.push(`with ${degreeFilter}`);
            if (specFilter) filters.push(`specializing in ${specFilter}`);
            if (yearFilter) filters.push(`teaching Year ${yearFilter}`);
            if (classFilter) filters.push(`assigned to Class ${classFilter}`);
            
            if (filters.length > 0) {
                reportDescription += filters.join(', ') + '. ';
            } else {
                reportDescription += 'currently registered in the system. ';
            }
            
            reportDescription += `The report includes detailed information about each teacher's contact details, academic qualifications, specialization area, salary information, and teaching assignments. `;
            reportDescription += `This document serves as an official record for administrative purposes and can be used for HR management, budgeting, and resource planning. `;
            
            if (salaryCount > 0) {
                reportDescription += `Financial Summary: Average monthly salary is ${avgSalary.toLocaleString()} IQD, with total monthly salary expenditure of ${totalSalary.toLocaleString()} IQD across ${salaryCount} teachers. `;
            }
            
            reportDescription += `Total number of teachers: ${total}. `;
            
            // Most common degree and specialization
            const topDegree = Object.keys(degrees).reduce((a, b) => degrees[a] > degrees[b] ? a : b, '');
            const topSpec = Object.keys(specializations).reduce((a, b) => specializations[a] > specializations[b] ? a : b, '');
            reportDescription += `Most common qualification: ${topDegree} (${degrees[topDegree]} teachers). `;
            reportDescription += `Most common specialization: ${topSpec} (${specializations[topSpec]} teachers).`;
            
            // Clone and clean table
            const tableClone = table.cloneNode(true);
            const cloneRows = tableClone.querySelectorAll('tbody tr');
            cloneRows.forEach(row => {
                if (row.style.display === 'none' || row.classList.contains('teacher-details-row')) {
                    row.remove();
                    return;
                }
                // Remove Actions column
                const actionCell = row.querySelector('td:last-child');
                if (actionCell && actionCell.querySelector('button')) {
                    actionCell.remove();
                }
            });
            
            // Remove Actions header
            const headerCells = tableClone.querySelectorAll('thead th');
            if (headerCells.length > 0) {
                headerCells[headerCells.length - 1].remove();
            }
            
            const teachersHTML = `
                <div class="report-summary">
                    <h3>REPORT SUMMARY</h3>
                    <p>${reportDescription}</p>
                </div>
                
                ${tableClone.outerHTML}
            `;
            
            const additionalStyles = `
                .report-summary {
                    background: #f5f5f5;
                    border: 2px solid #000;
                    padding: 15px;
                    margin-bottom: 20px;
                    page-break-inside: avoid;
                }
                
                .report-summary h3 {
                    margin: 0 0 10px 0;
                    font-size: 14px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .report-summary p {
                    margin: 0;
                    font-size: 11px;
                    line-height: 1.6;
                    text-align: justify;
                }
                
                td {
                    text-align: center;
                }
                
                td:nth-child(2),
                td:nth-child(3) {
                    text-align: left;
                }
            `;
            
            const printWindow = window.open('', '_blank');
            const printHTML = generatePrintTemplate('Teachers Management Report', teachersHTML, additionalStyles);
            
            printWindow.document.write(printHTML);
            printWindow.document.close();
        }
        
        function openAssignSubjectsModal(teacherId, teacherName) {
            showLoading(getTranslation('loading'), getTranslation('please_wait'));
            
            // Fetch available subjects from database
            fetch('?action=get_available_subjects&teacher_id=' + teacherId)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showAssignSubjectsModal(teacherId, teacherName, data.subjects);
                    } else {
                        showErrorMessage(data.message || 'Failed to load subjects');
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showErrorMessage('Failed to load subjects');
                });
        }
        
        function showAssignSubjectsModal(teacherId, teacherName, subjects) {
            // Escape function
            const escape = (str) => {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            };
            
            const modalHTML = `
                <div id="assignSubjectsModal" onclick="if(event.target.id === 'assignSubjectsModal') closeAssignSubjectsModal()" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                     background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10001;">
                    <div onclick="event.stopPropagation()" style="background: white; padding: 0; border-radius: 12px; max-width: 700px; width: 90%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 1.5rem 2rem; color: white; display: flex; align-items: center; justify-content: space-between;">
                            <h3 style="margin: 0; color: white; font-size: 1.2rem;"><i class="fas fa-plus-circle" style="margin-right: 0.5rem;"></i><span data-translate="assign_subjects">Assign Subjects</span> - ${escape(teacherName)}</h3>
                            <button onclick="closeAssignSubjectsModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div style="padding: 2rem; overflow-y: auto; flex: 1;">
                        <form id="assignSubjectsForm" onsubmit="submitAssignSubject(event, ${teacherId})">
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;" data-translate="subject">Subject</label>
                                <select name="subject_id" id="assignSubjectSelect" class="premium-select" required style="width: 100%;" onchange="updateYearClassOptions()">
                                    <option value="">Select Subject</option>
                                    ${subjects.map(s => `<option value="${s.id}" data-year="${s.year}">${escape(s.subject_name)} (Year ${s.year})</option>`).join('')}
                                </select>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;" data-translate="year">Year</label>
                                <select name="year" id="assignYearSelect" class="premium-select" required style="width: 100%;" disabled>
                                    <option value="">Select subject first</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;" data-translate="class">Class</label>
                                <select name="class_level" class="premium-select" required style="width: 100%;">
                                    <option value="A">Class A</option>
                                    <option value="B">Class B</option>
                                    <option value="C">Class C</option>
                                </select>
                            </div>
                        </form>
                        </div>
                        <div style="padding: 1.5rem 2rem; border-top: 2px solid #e5e7eb; background: #f9fafb; display: flex; gap: 1rem; justify-content: flex-end;">
                            <button type="button" onclick="closeAssignSubjectsModal()" class="export-btn" style="background: #6b7280; padding: 0.75rem 1.5rem;" data-translate="cancel">Cancel</button>
                            <button type="submit" form="assignSubjectsForm" class="apply-filters-btn" style="padding: 0.75rem 1.5rem;" data-translate="assign">Assign Subject</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        
        function updateYearClassOptions() {
            const subjectSelect = document.getElementById('assignSubjectSelect');
            const yearSelect = document.getElementById('assignYearSelect');
            
            if (subjectSelect.value) {
                const selectedOption = subjectSelect.options[subjectSelect.selectedIndex];
                const year = selectedOption.getAttribute('data-year');
                
                yearSelect.innerHTML = `<option value="${year}" selected>Year ${year}</option>`;
                yearSelect.disabled = false;
            } else {
                yearSelect.innerHTML = '<option value="">Select subject first</option>';
                yearSelect.disabled = true;
            }
        }
        
        function closeAssignSubjectsModal() {
            const modal = document.getElementById('assignSubjectsModal');
            if (modal) modal.remove();
        }
        
        function submitAssignSubject(event, teacherId) {
            event.preventDefault();
            showLoader('Assigning subject...');
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'assign_subject_to_teacher');
            formData.append('teacher_id', teacherId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoader();
                closeAssignSubjectsModal();
                if (data.success) {
                    showSuccessMessage(data.message);
                    // If opened from teacher modal, refresh it instead of page reload
                    if (window.currentTeacherId) {
                        setTimeout(() => {
                            toggleTeacherDetails(window.currentTeacherId);
                            window.currentTeacherId = null;
                        }, 500);
                    } else {
                        setTimeout(() => {
                            fetchAndUpdateSection('teachers');
                        }, 1000);
                    }
                } else {
                    showErrorMessage(data.message);
                }
            })
            .catch(error => {
                hideLoader();
                console.error('Error:', error);
                showErrorMessage('Failed to assign subject');
            });
        }


    </script>
    
    <style>
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Print Styles */
    @media print {
        /* Hide everything except the table */
        nav, .filter-panel, .export-actions, .action-btn, 
        .language-selector, .dashboard-subtitle, .filter-sections,
        .section-header .export-actions, button, .add-form-container {
            display: none !important;
        }
        
        /* Hide filter bar and add teacher form */
        .filter-sections {
            display: none !important;
        }
        
        /* Show only section title */
        .section-header {
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: #000;
        }
        
        .content-wrapper {
            padding: 0 !important;
        }
        
        .data-table-section {
            box-shadow: none !important;
            border: none !important;
        }
        
        table {
            page-break-inside: auto;
        }
        
        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        
        thead {
            display: table-header-group;
        }
        
        /* Hide expandable details in print */
        /* Hide expandable rows when printing */
        .teacher-details-row,
        .subject-details-row {
            display: none !important;
        }
        
        /* Clean table for printing */
        th, td {
            border: 1px solid #000 !important;
            padding: 8px !important;
        }
        
        /* Remove unnecessary styling for print */
        span {
            background: none !important;
            color: #000 !important;
            border: none !important;
            padding: 0 !important;
        }
    }
    </style>
    </script>
</body>
</html>
