<?php
// Start output buffering to prevent HTML output before JSON responses
ob_start();

// Include database connection
include 'db.php';

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_mark') {
        $student_id = $_POST['student_id'];
        $subject_id = $_POST['subject_id'];
        $final_mark = (int)$_POST['final_mark'];
        $midterm_mark = (int)$_POST['midterm_mark'];
        $quizzes_mark = (int)$_POST['quizzes_mark'];
        $daily_mark = (int)$_POST['daily_mark'];
        
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
            echo json_encode(['success' => true, 'total' => $total_mark, 'final_grade' => $final_grade]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . pg_last_error($conn)]);
        }
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
        // Insert student record - assuming we'll add a year column to the database
        $query = "INSERT INTO students (name, age, gender, class_level, year, email, phone, academic_year, graduation_status, join_date) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10) RETURNING id";
        $current_date = date('Y-m-d');
        $result = pg_query_params($conn, $query, array($name, $age, $gender, $class_level, $year, $email, $phone, 1, 'Active', $current_date));
        
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
            
            header("Location: ?page=students&success=" . urlencode($success_msg));
            exit;
        } else {
            $error_message = "Error adding student: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Name, age, gender, and class level are required.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'add_subject') {
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $year = (int)$_POST['year'];
    
    if (!empty($subject_name) && $credits > 0 && !empty($year)) {
        $query = "INSERT INTO subjects (subject_name, description, credits, year) VALUES ($1, $2, $3, $4)";
        $result = pg_query_params($conn, $query, array($subject_name, $description, $credits, $year));
        
        if ($result) {
            header("Location: ?page=subjects&success=" . urlencode("Subject added successfully!"));
            exit;
        } else {
            $error_message = "Error adding subject: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Subject name and credits are required.";
    }
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
        $query = "UPDATE students SET name = $1, age = $2, gender = $3, class_level = $4, year = $5, email = $6, phone = $7 WHERE id = $8";
        $result = pg_query_params($conn, $query, array($name, $age, $gender, $class_level, $year, $email, $phone, $student_id));
        
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
            
            header("Location: ?page=students&success=" . urlencode($success_msg));
            exit;
        } else {
            $error_message = "Error updating student: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Name, age, gender, and class level are required.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $student_id = (int)$_POST['student_id'];
    
    if ($student_id > 0) {
        // First delete all marks for this student
        $delete_marks_query = "DELETE FROM marks WHERE student_id = $1";
        pg_query_params($conn, $delete_marks_query, array($student_id));
        
        // Then delete the student
        $delete_student_query = "DELETE FROM students WHERE id = $1";
        $result = pg_query_params($conn, $delete_student_query, array($student_id));
        
        if ($result) {
            // Resequence IDs after deletion
            resequenceTable($conn, 'students');
            header("Location: ?page=students&success=" . urlencode("Student deleted successfully!"));
            exit;
        } else {
            $error_message = "Error deleting student: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Invalid student ID.";
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
    $subject_id = (int)$_POST['subject_id'];
    $subject_name = trim($_POST['subject_name']);
    $description = trim($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $year = (int)$_POST['year'];
    
    if (!empty($subject_name) && $credits > 0 && $year > 0) {
        $query = "UPDATE subjects SET subject_name = $1, description = $2, credits = $3, year = $4 WHERE id = $5";
        $result = pg_query_params($conn, $query, array($subject_name, $description, $credits, $year, $subject_id));
        
        if ($result) {
            header("Location: ?page=subjects&success=" . urlencode("Subject updated successfully!"));
            exit;
        } else {
            $error_message = "Error updating subject: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Subject name, credits, and academic year are required.";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_subject') {
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
            header("Location: ?page=subjects&success=" . urlencode("Subject deleted successfully!"));
            exit;
        } else {
            $error_message = "Error deleting subject: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Invalid subject ID.";
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
    $mark_id = (int)$_POST['mark_id'];
    
    if ($mark_id > 0) {
        $delete_query = "DELETE FROM marks WHERE id = $1";
        $result = pg_query_params($conn, $delete_query, array($mark_id));
        
        if ($result) {
            // Resequence IDs after deletion
            resequenceTable($conn, 'marks');
            header("Location: ?page=marks&success=" . urlencode("Mark deleted successfully!"));
            exit;
        } else {
            $error_message = "Error deleting mark: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Invalid mark ID.";
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
            $graduate_query = "INSERT INTO graduated_students (student_id, student_name, age, gender, class_level, email, phone, graduation_date, final_year, graduation_grade) 
                              VALUES ($1, $2, $3, $4, $5, $6, $7, CURRENT_DATE, 2, $8)";
            $graduate_result = pg_query_params($conn, $graduate_query, array(
                $student['id'], $student['name'], $student['age'], $student['gender'], 
                $student['class_level'], $student['email'], $student['phone'], $graduation_grade
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

// Handle graduated student deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_graduated_student') {
    $graduated_id = $_POST['graduated_id'];
    
    if ($graduated_id) {
        $delete_query = "DELETE FROM graduated_students WHERE id = $1";
        $result = pg_query_params($conn, $delete_query, array($graduated_id));
        
        if ($result) {
            // Resequence IDs after deletion
            resequenceTable($conn, 'graduated_students');
            $success_message = "Graduated student deleted successfully!";
        } else {
            $error_message = "Error deleting graduated student: " . pg_last_error($conn);
        }
    }
}

// Handle bulk deletion of graduated students
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_graduated') {
    if (isset($_POST['selected_graduates']) && is_array($_POST['selected_graduates'])) {
        $selected_ids = $_POST['selected_graduates'];
        $placeholders = implode(',', array_map(function($i) { return '$' . ($i + 1); }, array_keys($selected_ids)));
        
        $delete_query = "DELETE FROM graduated_students WHERE id IN ($placeholders)";
        $result = pg_query_params($conn, $delete_query, $selected_ids);
        
        if ($result) {
            // Resequence IDs after deletion
            resequenceTable($conn, 'graduated_students');
            $success_message = count($selected_ids) . " graduated students deleted successfully!";
        } else {
            $error_message = "Error deleting graduated students: " . pg_last_error($conn);
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
    
    $avg_score = pg_query($conn, "
        SELECT ROUND(AVG(m.mark), 1) as avg 
        FROM marks m 
        JOIN students s ON m.student_id = s.id 
        WHERE m.mark > 0 AND s.status = 'active' $year_filter_marks
    ");
    $avg_score_value = pg_fetch_assoc($avg_score)['avg'] ?? 0;
    
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
    // Get grade distribution data using the new function for Year 1 (default)
    $gradeDistData = getGradeDistributionData($conn, 1);
    $grades = $gradeDistData['labels'];
    $grade_counts = $gradeDistData['data'];
    
    return [
        'kpis' => getKPIs($conn, 1), // Load Year 1 KPIs by default
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
        $colors = ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899'];
        
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
        $colors = ['#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#3B82F6', '#EC4899'];
        
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
            'colors' => ['#3B82F6', '#10B981', '#F59E0B']
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
            $reasons[] = "Total Final Grade: " . round($total_final_grade, 2) . "/50 (Required: ≥25)";
        }
        
        if (!$has_no_failed_subjects) {
            $reasons[] = "Failed Subjects: " . count($failed_subjects);
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
            $reasons[] = "Total Final Grade: " . round($total_final_grade, 2) . "/50 (Required: ≥25)";
        }
        
        if (!$has_no_failed_subjects) {
            $reasons[] = "Failed Subjects: " . count($failed_subjects);
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
    <title>Premium Student Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-color);
            min-height: 100vh;
            color: var(--text-color);
            line-height: 1.6;
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
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            min-height: 70px;
        }

        .nav-links {
            display: flex;
            gap: 0;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav-container a {
            text-decoration: none;
            color: white;
            font-weight: 600;
            padding: 12px 24px;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: 2px solid transparent;
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
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            text-transform: uppercase;
            letter-spacing: 1px;
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
            gap: 12px;
            color: white;
        }

        .brand-icon {
            font-size: 32px;
            background: linear-gradient(45deg, #fbbf24, #f59e0b);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(251, 191, 36, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .brand-title {
            font-size: 18px;
            font-weight: 700;
            color: white;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .brand-subtitle {
            font-size: 12px;
            color: #fbbf24;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .dashboard-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
        }

        .dashboard-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
            z-index: 2;
        }

        /* ===== REPORTS MAIN CONTENT ===== */
        .reports-main-content {
            padding: 2rem;
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(45deg, var(--primary-color), var(--success-color));
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
        }

        .students-icon { background: var(--kpi-blue); }
        .subjects-icon { background: var(--kpi-light-blue); }
        .score-icon { background: var(--kpi-yellow); }
        .class-icon { background: var(--kpi-cyan); }

        .kpi-content {
            text-align: center;
            width: 100%;
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 500;
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
            margin-bottom: 2rem;
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
            font-family: 'Poppins', sans-serif;
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
            font-family: 'Poppins', sans-serif;
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
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
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
            font-size: 0.9rem;
            border: 2px solid var(--border-color);
            background: white;
        }

        .formal-schedule th {
            padding: 12px 8px;
            text-align: center;
            border: 1px solid var(--border-color);
            background: #f8f9fa;
            color: var(--text-color);
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .formal-schedule td {
            padding: 12px 8px;
            text-align: center;
            border: 1px solid var(--border-color);
            vertical-align: middle;
            font-weight: 500;
        }

        .time-period {
            background: #e8f4fd !important;
            font-weight: 700;
            color: #1565c0;
            min-width: 120px;
            font-size: 0.85rem;
        }

        .subject-cell {
            font-weight: 500;
            font-size: 0.88rem;
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
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .export-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: var(--hover-color);
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            padding: 1rem 1.5rem;
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
            border-radius: 6px;
            overflow: hidden;
        }

        .subject-marks-table th {
            background: #e0f2fe;
            color: #0c4a6e;
            padding: 0.6rem;
            font-size: 0.8rem;
            text-align: center;
            font-weight: 600;
        }

        .subject-marks-table td {
            padding: 0.7rem;
            text-align: center;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
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
            font-family: 'Poppins', sans-serif;
        }

        .loading-subtext {
            font-size: 0.9rem;
            color: #64748b;
            font-family: 'Poppins', sans-serif;
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
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .nav-container { padding: 0 1rem; }
            .reports-main-content { padding: 1rem; }
            .dashboard-header { padding: 2rem 1rem; }
            .dashboard-header h1 { font-size: 2rem; }
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
            <div class="loading-spinner"></div>
            <div class="loading-text">Processing<span class="loading-dots"></span></div>
            <div class="loading-subtext">Please wait while we update your data</div>
        </div>
    </div>

    <nav>
        <div class="nav-container">
            <div class="nav-brand">
                <div class="brand-icon">🎓</div>
                <div class="brand-text">
                    <span class="brand-title" data-translate="system_title">Student Management</span>
                    <span class="brand-subtitle" data-translate="system_subtitle">Academic Portal</span>
                </div>
            </div>
            <div class="nav-links">
                <a href="?page=reports" <?= $page == 'reports' ? 'class="active"' : '' ?> data-translate="nav_reports">Dashboard</a>
                <a href="?page=students" <?= $page == 'students' ? 'class="active"' : '' ?> data-translate="nav_students">Students</a>
                <a href="?page=marks" <?= $page == 'marks' ? 'class="active"' : '' ?> data-translate="nav_marks">Marks</a>
                <a href="?page=subjects" <?= $page == 'subjects' ? 'class="active"' : '' ?> data-translate="nav_subjects">Subjects</a>
                <a href="?page=graduated" <?= $page == 'graduated' ? 'class="active"' : '' ?> data-translate="nav_graduated">Graduates</a>
            </div>
            <div class="language-switcher">
                <select id="languageSelector" onchange="changeLanguage(this.value)">
                    <option value="en">En</option>
                    <option value="ar">Ar</option>
                    <option value="ku">Kr</option>
                </select>
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
        <?php if ($page == 'reports'): ?>
            <!-- PREMIUM REPORTS DASHBOARD -->
            <div class="content-wrapper">
                <div class="reports-dashboard">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header" data-aos="fade-down">
                        <h1 data-translate="analytics_dashboard">📊 Analytics Dashboard</h1>
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
                                    <div class="kpi-trend positive" data-translate="overall_performance">Overall performance</div>
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

                        <!-- Charts Section -->
                        <div class="charts-grid">
                            <!-- Performance Distribution Chart -->
                            <div class="chart-card" data-aos="fade-up">
                                <div class="chart-header">
                                    <h3 class="chart-title">📊 Grade Distribution</h3>
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
                                    <h3 class="chart-title">🎯 Subject Performance <span style="background: #3B82F6; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;">YEAR 1</span></h3>
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
                                    <h3 class="chart-title"> Top 3 Performers <span style="background: #3B82F6; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;">YEAR 1</span></h3>
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
                                    <h3 class="chart-title"> Subject Performance <span style="background: #10B981; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;">YEAR 2</span></h3>
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
                                    <h3 class="chart-title"> Top 3 Performers <span style="background: #10B981; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;">YEAR 2</span></h3>
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
                                    ['10:20 - 11:50', 'Music'],
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
                                    ['08:30 - 10:00', 'Music'],
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
                                    ['12:10 - 01:40', 'Music']
                                ],
                                'Monday' => [
                                    ['08:30 - 10:00', 'English'],
                                    ['10:00 - 10:10', 'Break'],
                                    ['10:10 - 11:40', 'Computer Essentials'],
                                    ['11:40 - 12:00', 'Break'],
                                    ['12:00 - 01:30', 'Basics of Principle Statistics']
                                ],
                                'Tuesday' => [
                                    ['08:30 - 10:00', 'Music'],
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
                                    ['12:00 - 01:30', 'Music']
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
                                    ['10:15 - 11:45', 'Music'],
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
                                    ['10:10 - 11:40', 'Music'],
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
                                    ['08:30 - 10:00', 'Music'],
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
                        if (strpos($subject_lower, 'music') !== false) return 'subject-history';
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
                                    <select id="reportsFilterYear" class="premium-select" onchange="filterReportsTable()">
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
                                <button class="clear-filters-btn" onclick="clearReportsFilters()" data-translate="clear_filters">
                                    🔄 Clear Filters
                                </button>
                            </div>
                        </div>

                        <!-- Data Table Section -->
                        <div class="data-table-section" data-aos="fade-up">
                            <div class="section-header">
                                <h3 class="section-title">📋 Detailed Reports</h3>
                                <div class="export-actions">
                                    <button class="export-btn" onclick="exportData('csv')">📊 Export CSV</button>
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
                                        // Get all students with marks, grouped by student
                                        $students_query = "
                                            SELECT DISTINCT
                                                s.id as student_id,
                                                s.name as student_name,
                                                s.year as student_year,
                                                s.class_level
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
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
                                        <tr class="student-main-row" onclick="toggleStudentDetails(<?= $student_id ?>)" data-student-id="<?= $student_id ?>">
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
                                                    Year <?= $student['student_year'] ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                             background: #f3f4f6; color: #374151;">
                                                    <?= htmlspecialchars($student['class_level']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="total-subjects-badge"><?= $total_subjects ?> subjects</span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="final-grade-display"><?= number_format($total_grade, 2) ?></span>
                                            </td>
                                            <td style="text-align: center;" onclick="event.stopPropagation();">
                                                <button class="manage-marks-btn" onclick="toggleStudentDetails(<?= $student_id ?>)">
                                                    📋 View Details
                                                </button>
                                            </td>
                                        </tr>
                                        
                                        <!-- Subject Details Row (Expandable) -->
                                        <tr class="subject-details-row" id="details-<?= $student_id ?>">
                                            <td colspan="6">
                                                <div class="subject-details-container">
                                                    <div class="subject-title">📚 Subject Marks</div>
                                                    <table class="subject-marks-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Subject</th>
                                                                <th>Final (60)</th>
                                                                <th>Midterm (20)</th>
                                                                <th>Quiz (10)</th>
                                                                <th>Daily (10)</th>
                                                                <th>Total</th>
                                                                <th>Grade</th>
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
                                                                        📊 Year <?= $year ?> Total Credits
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
            <div class="content-wrapper">
                <div class="dashboard-header">
                    <h1 data-translate="students_title"> Students Management</h1>
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
                                        🔄 Reset Form
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Students List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="students_list">📋 Students List</h3>
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
                                        <th data-translate="academic_year">Year</th>
                                        <th>Status</th>
                                        <th data-translate="email">Email</th>
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
                                        LEFT JOIN subjects sub ON m.subject_id = sub.id
                                        WHERE s.status = 'active'
                                        GROUP BY s.id, s.name, s.age, s.gender, s.class_level, s.email, s.phone, s.year, s.status
                                        ORDER BY s.id
                                    ";
                                    $students_result = pg_query($conn, $students_query);
                                    
                                    if ($students_result && pg_num_rows($students_result) > 0):
                                        while($student = pg_fetch_assoc($students_result)):
                                    ?>
                                    <tr>
                                        <td><?= $student['id'] ?></td>
                                        <td><?= htmlspecialchars($student['name']) ?></td>
                                        <td><?= $student['age'] ?? 'N/A' ?></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; 
                                                         background: <?= $student['gender'] == 'Male' ? '#e3f2fd' : '#fce4ec' ?>; 
                                                         color: <?= $student['gender'] == 'Male' ? '#1565c0' : '#c2185b' ?>;">
                                                <?= $student['gender'] ?? 'N/A' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: var(--primary-light); color: var(--primary-color);">
                                                Class <?= htmlspecialchars($student['class_level']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: #f0f9ff; color: #0369a1;">
                                                Year <?= $student['year'] ?? 'N/A' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($student['year'] == 1): ?>
                                                <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
                                                             background: #E0F2FE; color: #0284C7;">
                                                    📚 Year 1 Student
                                                </span>
                                            <?php elseif ($student['year'] == 2): ?>
                                                <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
                                                             background: #FEF3C7; color: #D97706;">
                                                    🎓 Year 2 Student
                                                </span>
                                            <?php else: ?>
                                                <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
                                                             background: #F3F4F6; color: #6B7280;">
                                                    ❓ Unknown
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($student['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['phone'] ?? '') ?></td>
                                        <td>
                                            <?php if (!empty($student['enrolled_subjects'])): ?>
                                                <div style="font-size: 0.85rem; color: #666;">
                                                    <?= htmlspecialchars($student['enrolled_subjects']) ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #999; font-style: italic;">No subjects</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="editStudent(<?= $student['id'] ?>)" style="margin: 2px;" data-translate="edit">✏️ Edit</button>
                                            <button class="export-btn" onclick="handleStudentAction('delete_student', <?= $student['id'] ?>)" 
                                                    style="background: var(--danger-color); color: white; margin: 2px;" data-translate="delete">🗑️ Delete</button>
                                            
                                            <?php if ($student['year'] == 1): ?>
                                                <button class="export-btn" onclick="handleStudentAction('promote_student', <?= $student['id'] ?>)" 
                                                        style="background: #10B981; color: white; margin: 2px;" data-translate="promote">📈 Promote</button>
                                            <?php elseif ($student['year'] == 2): ?>
                                                <button class="export-btn" onclick="graduateStudent(<?= $student['id'] ?>)" 
                                                        style="background: #F59E0B; color: white; margin: 2px;" data-translate="graduate">🎓 Graduate</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center; color: var(--text-light); padding: 2rem;">
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

        <?php elseif ($page == 'graduated'): ?>
            <!-- GRADUATED STUDENTS PAGE -->
            <div class="content-wrapper">
                <div class="dashboard-header">
                    <h1 data-translate="graduated_title">🎓 Graduated Students</h1>
                    <p class="dashboard-subtitle" data-translate="manage_graduated_students">View all graduated students</p>
                </div>
                <div class="reports-main-content">
                    <!-- Graduated Students List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="graduated_list">📋 Graduated Students List</h3>
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
                                        <th>Graduation Grade</th>
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

        <?php elseif ($page == 'subjects'): ?>
            <!-- SUBJECTS PAGE -->
            <div class="content-wrapper">
                <div class="dashboard-header">
                    <h1 data-translate="subjects_title">📚 Subjects Management</h1>
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
                        <h3 class="filter-title" data-translate="filter_by_year">Filter by Year</h3>
                        <div class="filter-sections">
                            <div class="filter-section">
                                <label class="filter-label" data-translate="academic_year">Academic Year</label>
                                <select id="yearFilter" class="premium-select" onchange="filterSubjectsByYear()">
                                    <option value="" data-translate="all_years">All Years</option>
                                    <option value="1" data-translate="year_1">Year 1</option>
                                    <option value="2" data-translate="year_2">Year 2</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Subjects List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="subjects_list">📋 Subjects List</h3>
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
                                        <th data-translate="total_enrolls">Total Enrolls</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $subjects_query = "
                                        SELECT 
                                            s.*,
                                            COUNT(DISTINCT m.student_id) as enrollment_count
                                        FROM subjects s
                                        LEFT JOIN marks m ON s.id = m.subject_id
                                        GROUP BY s.id, s.subject_name, s.description, s.credits, s.year
                                        ORDER BY s.id
                                    ";
                                    $subjects_result = pg_query($conn, $subjects_query);
                                    
                                    if ($subjects_result && pg_num_rows($subjects_result) > 0):
                                        while($subject = pg_fetch_assoc($subjects_result)):
                                    ?>
                                    <tr data-year="<?= $subject['year'] ?? '' ?>">
                                        <td><?= $subject['id'] ?></td>
                                        <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($subject['description'] ?? '') ?></td>
                                        <td><?= $subject['credits'] ?></td>
                                        <td><?= $subject['year'] ? 'Year ' . $subject['year'] : 'N/A' ?></td>
                                        <td>
                                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; background: var(--kpi-light-blue); color: white; border-radius: 4px; font-weight: 500;">
                                                👥 <?= $subject['enrollment_count'] ?? 0 ?> students
                                            </span>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="editSubject(<?= $subject['id'] ?>)" data-translate="edit">✏️ Edit</button>
                                            <button class="export-btn" onclick="handleSubjectAction('delete_subject', <?= $subject['id'] ?>)" style="background: var(--danger-color); color: white;" data-translate="delete">🗑️ Delete</button>
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
            <div class="content-wrapper">
                <div class="dashboard-header">
                    <h1 data-translate="marks_title">📝 Marks Management</h1>
                    <p class="dashboard-subtitle" data-translate="input_manage_marks">Input and manage student marks</p>
                </div>
                <div class="reports-main-content">
                    <!-- Marks List -->
                    <div class="data-table-section" data-aos="fade-up">
                        <div class="section-header">
                            <h3 class="section-title" data-translate="marks_list"> Marks List</h3>
                            <div class="export-actions">
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
                                    <select id="filterMarksYear" class="premium-select" onchange="updateMarksClassFilter(); filterMarks()">
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
                                    // Get all students with marks, grouped by student
                                    $students_marks_query = "
                                        SELECT DISTINCT
                                            s.id as student_id,
                                            s.name as student_name,
                                            s.year as student_year,
                                            s.class_level,
                                            s.status as student_status
                                        FROM students s
                                        JOIN marks m ON s.id = m.student_id
                                        ORDER BY s.name
                                    ";
                                    $students_marks_result = pg_query($conn, $students_marks_query);
                                    
                                    if ($students_marks_result && pg_num_rows($students_marks_result) > 0):
                                        while($student = pg_fetch_assoc($students_marks_result)):
                                            $student_id = $student['student_id'];
                                            
                                            // Get marks for this student
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
                                            ";
                                            $marks_params = array($student_id);
                                            $marks_for_student_result = pg_query_params($conn, $marks_for_student_query, $marks_params);
                                            $student_marks = pg_fetch_all($marks_for_student_result);
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
                                    <tr class="student-main-row" onclick="toggleStudentDetails(<?= $student_id ?>)" 
                                        data-student-id="<?= $student_id ?>"
                                        data-year="<?= $student['student_year'] ?>" 
                                        data-student-status="<?= $student['student_status'] ?>" 
                                        data-class="<?= $student['class_level'] ?>">
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
                                                Year <?= $student['student_year'] ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: #f3f4f6; color: #374151;">
                                                <?= htmlspecialchars($student['class_level']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="total-subjects-badge"><?= $total_subjects ?> subjects</span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="final-grade-display"><?= number_format($total_grade, 2) ?></span>
                                        </td>
                                        <td style="text-align: center;" onclick="event.stopPropagation();">
                                            <button class="manage-marks-btn" onclick="toggleStudentDetails(<?= $student_id ?>)">
                                                📝 Manage Marks
                                            </button>
                                        </td>
                                    </tr>
                                    
                                    <!-- Subject Details Row (Expandable) with Inline Editing -->
                                    <tr class="subject-details-row" id="details-<?= $student_id ?>">
                                        <td colspan="6">
                                            <div class="subject-details-container">
                                                <div class="subject-title">📚 Subject Marks - Click Edit to modify</div>
                                                <table class="subject-marks-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Subject</th>
                                                            <th>Final (60)</th>
                                                            <th>Midterm (20)</th>
                                                            <th>Quiz (10)</th>
                                                            <th>Daily (10)</th>
                                                            <th>Total</th>
                                                            <th>Grade</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
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
                                                                <td>
                                                                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 500;
                                                                                 background: <?= $mark['status'] == 'Pass' ? '#dcfce7' : ($mark['status'] == 'Fail' ? '#fecaca' : '#fef3c7') ?>; 
                                                                                 color: <?= $mark['status'] == 'Pass' ? '#166534' : ($mark['status'] == 'Fail' ? '#dc2626' : '#d97706') ?>;">
                                                                        <?= htmlspecialchars($mark['status']) ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <button class="inline-edit-btn" onclick="editMark(<?= $mark['mark_id'] ?>)">✏️ Edit</button>
                                                                    <button class="inline-delete-btn" onclick="handleMarkAction('delete_mark', <?= $mark['mark_id'] ?>)">🗑️</button>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                            <!-- Year Total Row -->
                                                            <tr style="background: linear-gradient(135deg, <?= $year == 1 ? '#dbeafe' : '#f3e8ff' ?> 0%, <?= $year == 1 ? '#bfdbfe' : '#e9d5ff' ?> 100%); font-weight: 600; border-top: 2px solid <?= $year == 1 ? '#3b82f6' : '#a855f7' ?>;">
                                                                <td colspan="8" style="text-align: left; padding: 12px; color: <?= $year == 1 ? '#1e40af' : '#6b21a8' ?>; font-size: 1rem;">
                                                                    📊 Year <?= $year ?> Total Credits
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
            duration: 800,
            easing: 'ease-in-out',
            once: true,
            offset: 100
        });

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
                        'A': '#3B82F6',  // Blue  
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
                'A': '#3B82F6',  // Blue  
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
                WHERE sub.year = 1
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
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderColor: '#3b82f6',
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
                            color: '#3b82f6',
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
                WHERE sub.year = 2
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
                WHERE m.mark > 0 AND s.year = 1
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
                WHERE m.mark > 0 AND s.year = 2
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

            // Year Filter Functionality
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

            // Add event listeners to year filter inputs
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
        });

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
                'Music': 'subject-history',
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
                    <span>${type === 'success' ? '✅' : '❌'} ${message}</span>
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
            
            // Close modal immediately if callback provided
            if (typeof onSuccess === 'function') {
                onSuccess();
            }
            
            showLoading('Saving Changes', 'Please wait...');
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                hideLoading();
                
                // Check for success/error in response
                const success = data.includes('successfully') || data.includes('Success');
                const error = data.includes('Error:') || data.includes('error');
                
                if (success) {
                    showNotification('✓ Saved successfully!', 'success');
                    
                    // Reset form if it's an add form
                    if (formData.get('action').includes('add')) {
                        form.reset();
                        
                        // Clear subject checkboxes
                        form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                    }
                    
                    // Reload table data immediately
                    reloadTableData(page);
                } else if (error) {
                    // Extract error message if possible
                    const errorMatch = data.match(/Error: ([^<]+)/);
                    const message = errorMatch ? errorMatch[1] : 'An error occurred';
                    showNotification(message, 'error');
                } else {
                    // Assume success
                    showNotification('✓ Updated!', 'success');
                    reloadTableData(page);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('Network error. Please try again.', 'error');
            });
            
            return false;
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

        function printTable() {
            // Get the current page's table for printing
            let table = null;
            let pageTitle = 'Data Export';
            
            if (document.getElementById('marksTable')) {
                table = document.getElementById('marksTable');
                pageTitle = 'Student Marks Report';
            } else if (document.getElementById('studentsTable')) {
                table = document.getElementById('studentsTable');
                pageTitle = 'Students Report';
            } else if (document.getElementById('subjectsTable')) {
                table = document.getElementById('subjectsTable');
                pageTitle = 'Subjects Report';
            } else if (document.getElementById('reportsTable')) {
                table = document.getElementById('reportsTable');
                pageTitle = 'Analytics Report';
            }
            
            if (!table) {
                alert('No table found to print');
                return;
            }
            
            // Create a new window for printing
            const printWindow = window.open('', '_blank');
            const tableClone = table.cloneNode(true);
            
            // Remove hidden rows from the clone
            const rows = tableClone.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.remove();
                }
            });
            
            // Create print document
            const printDocument = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${pageTitle}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #333; text-align: center; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        tr:nth-child(even) { background-color: #f9f9f9; }
                        .print-info { text-align: center; margin-bottom: 20px; font-size: 0.9em; color: #666; }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>${pageTitle}</h1>
                    <div class="print-info">
                        Generated on: ${new Date().toLocaleString()}<br>
                        Student Management System
                    </div>
                    ${tableClone.outerHTML}
                </body>
                </html>
            `;
            
            printWindow.document.write(printDocument);
            printWindow.document.close();
            
            // Wait for the content to load, then print
            printWindow.onload = function() {
                printWindow.print();
                printWindow.close();
            };
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
            if (confirm('Are you sure you want to promote this student from Year 1 to Year 2?')) {
                showLoading('Checking Eligibility', 'Validating student records...');
                
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
                        showNotification('Student promoted to Year 2!', 'success');
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
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }

        function graduateStudent(studentId) {
            if (confirm('Are you sure you want to graduate this student? This will move them to the graduated students list.')) {
                showLoading('Checking Eligibility', 'Validating student records...');
                
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
                        showNotification('Student graduated successfully!', 'success');
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
                    showNotification('Network error. Please try again.', 'error');
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
                    <div style="margin-top: 1rem; padding: 1rem; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px;">
                        <div style="font-weight: 600; color: #991b1b; margin-bottom: 0.5rem;">❌ Failed Subjects (Mark < 50):</div>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            ${failedSubjects.map(s => `
                                <li style="padding: 0.5rem 0; border-bottom: 1px solid #fca5a5;">
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="font-weight: 500;">${s.subject}</span>
                                        <span style="color: #dc2626; font-weight: 600;">${s.mark}/100</span>
                                    </div>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                `;
            }
            
            let requirementsHTML = '';
            if (details.reasons && details.reasons.length > 0) {
                requirementsHTML = `
                    <div style="margin-top: 1rem; padding: 1rem; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px;">
                        <div style="font-weight: 600; color: #92400e; margin-bottom: 0.5rem;">📋 Requirements:</div>
                        <ul style="margin: 0; padding-left: 1.5rem;">
                            ${details.reasons.map(r => `<li style="color: #78350f; margin: 0.3rem 0;">${r}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
            
            const modalHTML = `
                <div id="eligibilityErrorModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center; animation: fadeIn 0.2s ease;">
                    <div style="background: white; padding: 2rem; border-radius: 16px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s ease;">
                        <div style="text-align: center; margin-bottom: 1.5rem;">
                            <div style="font-size: 4rem; margin-bottom: 0.5rem;">⚠️</div>
                            <h3 style="margin: 0; color: #dc2626; font-size: 1.5rem; font-weight: 700;">Cannot ${actionType === 'Promotion' ? 'Promote' : 'Graduate'} Student</h3>
                        </div>
                        
                        <div style="background: #fee2e2; padding: 1rem; border-radius: 8px; border-left: 4px solid #dc2626; margin-bottom: 1rem;">
                            <p style="margin: 0; color: #7f1d1d; font-weight: 500;">${data.message}</p>
                        </div>
                        
                        ${requirementsHTML}
                        ${failedSubjectsHTML}
                        
                        <div style="margin-top: 1.5rem; padding: 1rem; background: #dbeafe; border-radius: 8px;">
                            <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.5rem;">💡 What to do:</div>
                            <ul style="margin: 0; padding-left: 1.5rem; color: #1e3a8a;">
                                ${failedSubjects.length > 0 ? '<li>Update failed subjects to have marks ≥ 50</li>' : ''}
                                ${details.total_final_grade < details.required_grade ? '<li>Increase marks to reach minimum final grade of 25</li>' : ''}
                                <li>Review all subject marks before trying again</li>
                            </ul>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                            <button onclick="closeEligibilityErrorModal()" 
                                    style="padding: 0.75rem 2rem; border: none; background: #3b82f6; color: white; border-radius: 8px; cursor: pointer; font-size: 1rem; font-weight: 600;">
                                OK, I Understand
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Add click outside to close
            const modal = document.getElementById('eligibilityErrorModal');
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeEligibilityErrorModal();
                }
            });
            
            // Add ESC and Enter key to close
            const keyHandler = function(e) {
                if (e.key === 'Escape' || e.key === 'Enter') {
                    closeEligibilityErrorModal();
                    document.removeEventListener('keydown', keyHandler);
                }
            };
            document.addEventListener('keydown', keyHandler);
            
            // Store key handler reference for cleanup
            modal.dataset.keyHandler = 'attached';
        }
        
        function closeEligibilityErrorModal() {
            const modal = document.getElementById('eligibilityErrorModal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.2s ease';
                setTimeout(() => modal.remove(), 200);
            }
        }

        function showEditModal(student, enrolledSubjects, allSubjects) {
            // Create modal HTML
            const modalHTML = `
                <div id="editModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Student</h3>
                        <form id="editStudentForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'students', function(){ closeEditModal(); });">
                            <input type="hidden" name="action" value="update_student">
                            <input type="hidden" name="student_id" value="${student.id}">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Full Name *</label>
                                    <input type="text" name="student_name" value="${student.name || ''}" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Age *</label>
                                    <input type="number" name="age" value="${student.age || ''}" min="15" max="30" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Gender *</label>
                                    <select name="gender" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                        <option value="">Select Gender</option>
                                        <option value="Male" ${student.gender === 'Male' ? 'selected' : ''}>Male</option>
                                        <option value="Female" ${student.gender === 'Female' ? 'selected' : ''}>Female</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Class *</label>
                                    <select name="class_level" id="editStudentClassSelect" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                        <option value="">Select Class</option>
                                        <!-- Options will be populated by JavaScript based on year selection -->
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Academic Year *</label>
                                    <select name="year" id="editStudentYearSelect" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;" onchange="filterEditSubjectsByStudentYear(); updateEditClassOptions();">
                                        <option value="">Select Year</option>
                                        <option value="1" ${student.year === '1' || student.year === 1 ? 'selected' : ''}>Year 1</option>
                                        <option value="2" ${student.year === '2' || student.year === 2 ? 'selected' : ''}>Year 2</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;" data-translate="email">Email</label>
                                    <input type="email" name="email" value="${student.email || ''}" placeholder="Enter email address..." data-translate-placeholder="email_placeholder_student"
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Phone</label>
                                    <input type="tel" name="phone" value="${student.phone || ''}"
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                            </div>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">📚 Subject Enrollment</label>
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
                                                    Year ${subject.year || 'N/A'}
                                                </div>
                                                <div style="font-size: 0.8rem; color: #666; padding: 2px 8px; background: #e3f2fd; border-radius: 4px;">
                                                    ${subject.credits} credits
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                <small style="color: #666; margin-top: 5px; display: block;">
                                    📝 Tip: Check/uncheck subjects to enroll or unenroll the student. Unchecking will remove all marks for that subject.
                                </small>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="closeEditModal()" 
                                        style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        style="padding: 0.75rem 1.5rem; border: none; background: #3B82F6; color: white; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Update Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
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
                <div id="editSubjectModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Subject</h3>
                        <form id="editSubjectForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'subjects', function(){ closeEditSubjectModal(); });">
                            <input type="hidden" name="action" value="update_subject">
                            <input type="hidden" name="subject_id" value="${subject.id}">
                            
                            <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Subject Name *</label>
                                    <input type="text" name="subject_name" value="${subject.subject_name || ''}" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Description</label>
                                    <textarea name="description" rows="3" 
                                              style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem; resize: vertical;">${subject.description || ''}</textarea>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Academic Year *</label>
                                    <select name="year" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                        <option value="">Select Year</option>
                                        <option value="1" ${subject.year === '1' || subject.year === 1 ? 'selected' : ''}>Year 1</option>
                                        <option value="2" ${subject.year === '2' || subject.year === 2 ? 'selected' : ''}>Year 2</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Credits *</label>
                                    <input type="number" name="credits" value="${subject.credits || ''}" min="1" max="50" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="closeEditSubjectModal()" 
                                        style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        style="padding: 0.75rem 1.5rem; border: none; background: #3B82F6; color: white; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Update Subject
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
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
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Mark</h3>
                        <form id="editMarkForm" method="POST" action="" onsubmit="return handleFormSubmit(event, this, 'marks', function(){ closeEditMarkModal(); });">
                            <input type="hidden" name="action" value="update_mark_record">
                            <input type="hidden" name="mark_id" value="${mark.id}">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                                <div style="grid-column: span 2;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Student</label>
                                    <div style="padding: 0.75rem; background: #f8f9fa; border-radius: 6px; color: #666;">
                                        ${mark.student_name}
                                    </div>
                                </div>
                                <div style="grid-column: span 2;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Subject</label>
                                    <div style="padding: 0.75rem; background: #f8f9fa; border-radius: 6px; color: #666;">
                                        ${mark.subject_name}
                                    </div>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Final Exam (0-60) *</label>
                                    <input type="number" name="final_exam" value="${formatNumber(mark.final_exam)}" min="0" max="60" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Midterm (0-20) *</label>
                                    <input type="number" name="midterm_exam" value="${formatNumber(mark.midterm_exam)}" min="0" max="20" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Quizzes (0-10) *</label>
                                    <input type="number" name="quizzes" value="${formatNumber(mark.quizzes)}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Daily Activities (0-10) *</label>
                                    <input type="number" name="daily_activities" value="${formatNumber(mark.daily_activities)}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div style="grid-column: span 2;">
                                    <div style="background: #f0f9ff; padding: 1rem; border-radius: 6px; border: 1px solid #0ea5e9;">
                                        <div style="font-weight: 500; color: #0c4a6e; margin-bottom: 0.5rem;">📊 Mark Calculation</div>
                                        <div style="font-size: 0.9rem; color: #0369a1;">
                                            Total = Final Exam + Midterm + Quizzes + Daily Activities<br>
                                            <strong>Current Total: <span id="totalPreview">${(parseInt(mark.final_exam || 0) + parseInt(mark.midterm_exam || 0) + parseInt(mark.quizzes || 0) + parseInt(mark.daily_activities || 0))}</span>/100</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="closeEditMarkModal()" 
                                        style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        style="padding: 0.75rem 1.5rem; border: none; background: #3B82F6; color: white; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    Update Mark
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
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

        // Marks filtering and search functions
        function filterMarks() {
            const searchValue = document.getElementById('searchMarks').value.toLowerCase();
            const yearFilter = document.getElementById('filterMarksYear').value;
            const classFilter = document.getElementById('filterMarksClass').value;
            
            const table = document.getElementById('marksTable');
            const rows = table.querySelectorAll('tbody tr.student-main-row');
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const detailsRow = document.getElementById('details-' + studentId);
                
                const cells = row.querySelectorAll('td');
                
                if (cells.length === 0) return; // Skip empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                
                // Get year and class from data attributes
                const rowYear = row.getAttribute('data-year');
                const rowClass = row.getAttribute('data-class');
                
                // Check search criteria
                const matchesSearch = searchValue === '' || studentName.includes(searchValue);
                const matchesYear = yearFilter === '' || rowYear === yearFilter;
                const matchesClass = classFilter === '' || rowClass === classFilter;
                
                // Show/hide row based on all criteria
                if (matchesSearch && matchesYear && matchesClass) {
                    row.style.display = '';
                    if (detailsRow) detailsRow.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                    if (detailsRow) detailsRow.style.display = 'none';
                }
            });
            
            // Update results counter
            updateMarksCounter(visibleCount);
        }

        function clearMarksFilters() {
            document.getElementById('searchMarks').value = '';
            document.getElementById('filterMarksYear').value = '';
            document.getElementById('filterMarksClass').value = '';
            
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
            
            const table = document.getElementById('reportsTable');
            const rows = table.querySelectorAll('tbody tr.student-main-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const studentId = row.getAttribute('data-student-id');
                const detailsRow = document.getElementById('details-' + studentId);
                
                const cells = row.querySelectorAll('td');
                if (cells.length === 0) return; // Skip empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                const yearText = cells[1].textContent;
                const classLevel = cells[2].textContent.trim();
                
                let matches = true;
                
                // Search filter
                if (searchValue && !studentName.includes(searchValue)) {
                    matches = false;
                }
                
                // Year filter
                if (yearFilter) {
                    const studentYear = yearText.includes('Year 1') ? '1' : '2';
                    if (studentYear !== yearFilter) {
                        matches = false;
                    }
                }
                
                // Class filter
                if (classFilter && classLevel !== classFilter) {
                    matches = false;
                }
                
                if (matches) {
                    row.style.display = '';
                    if (detailsRow) detailsRow.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                    if (detailsRow) detailsRow.style.display = 'none';
                }
            });
            
            // Update results counter
            updateReportsCounter(visibleCount);
        }

        function clearReportsFilters() {
            document.getElementById('reportsSearchStudent').value = '';
            if (document.getElementById('reportsFilterYear')) {
                document.getElementById('reportsFilterYear').value = '';
            }
            if (document.getElementById('reportsFilterClass')) {
                document.getElementById('reportsFilterClass').value = '';
            }
            filterReportsTable();
        }

        function updateReportsCounter(count) {
            let counter = document.getElementById('reportsCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#reportsTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'reportsCounter';
                counter.style.cssText = 'text-align: right; margin-top: 0.5rem; font-size: 0.9rem; color: #666;';
                tableContainer.appendChild(counter);
            }
            
            const total = document.querySelectorAll('#reportsTable tbody tr.student-main-row').length;
            counter.textContent = `Showing ${count} of ${total} students`;
        }

        function updateMarksCounter(count) {
            let counter = document.getElementById('marksCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#marksTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'marksCounter';
                counter.style.cssText = 'text-align: right; margin-top: 0.5rem; font-size: 0.9rem; color: #666;';
                tableContainer.appendChild(counter);
            }
            
            const total = document.querySelectorAll('#marksTable tbody tr.student-main-row').length;
            counter.textContent = `Showing ${count} of ${total} students`;
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
                const studentEmail = cells[6].textContent.toLowerCase();
                const studentPhone = cells[7].textContent.toLowerCase();
                const studentSubjects = cells[8].textContent.trim();
                
                // Check search criteria
                const matchesSearch = searchValue === '' || 
                    studentName.includes(searchValue) || 
                    studentEmail.includes(searchValue) ||
                    studentPhone.includes(searchValue);
                
                const matchesClass = classFilter === '' || studentClass === classFilter;
                const matchesGender = genderFilter === '' || studentGender === genderFilter;
                
                // Year matching
                const matchesYear = yearFilter === '' || studentYear.includes('Year ' + yearFilter);
                
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

        function clearStudentsFilters() {
            document.getElementById('searchStudents').value = '';
            document.getElementById('filterStudentClass').value = '';
            document.getElementById('filterStudentGender').value = '';
            document.getElementById('filterStudentAge').value = '';
            document.getElementById('filterStudentEnrollment').value = '';
            document.getElementById('filterStudentYear').value = '';
            filterStudents();
        }

        function filterSubjectsByYear() {
            const yearFilter = document.getElementById('yearFilter').value;
            const table = document.getElementById('subjectsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            let visibleCount = 0;
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const yearData = row.getAttribute('data-year');
                
                if (yearFilter === '' || yearData === yearFilter) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Update counter if it exists
            updateSubjectsCounter(visibleCount);
        }

        function updateSubjectsCounter(count) {
            let counter = document.getElementById('subjectsCounter');
            if (!counter) {
                // Create counter if it doesn't exist
                const tableContainer = document.querySelector('#subjectsTable').parentNode;
                counter = document.createElement('div');
                counter.id = 'subjectsCounter';
                counter.style.cssText = 'text-align: right; margin-top: 0.5rem; font-size: 0.9rem; color: #666;';
                tableContainer.appendChild(counter);
            }
            counter.textContent = `Showing ${count} subjects`;
        }

        // Filter subjects in enrollment section based on student's academic year
        function filterSubjectsByStudentYear() {
            const selectedYear = document.getElementById('studentYearSelect').value;
            const subjectItems = document.querySelectorAll('.subject-item');
            const container = document.getElementById('subjectEnrollmentContainer');
            
            // Remove existing message
            const existingMessage = container.querySelector('.no-subjects-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            if (selectedYear === '') {
                // Hide all subjects when no year is selected
                subjectItems.forEach(item => {
                    item.style.display = 'none';
                    // Uncheck all subjects
                    const checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
                
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
            
            // Show message if no subjects match the selected year
            if (visibleCount === 0) {
                const message = document.createElement('div');
                message.className = 'no-subjects-message';
                message.style.cssText = 'color: #666; text-align: center; padding: 20px; font-style: italic;';
                message.textContent = `No subjects available for Year ${selectedYear}`;
                container.appendChild(message);
            }
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
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (selectedYear === '1') {
                classSelect.innerHTML += `
                    <optgroup label="Year 1">
                        <option value="1A">Year 1 - Class A</option>
                        <option value="1B">Year 1 - Class B</option>
                        <option value="1C">Year 1 - Class C</option>
                    </optgroup>
                `;
            } else if (selectedYear === '2') {
                classSelect.innerHTML += `
                    <optgroup label="Year 2">
                        <option value="2A">Year 2 - Class A</option>
                        <option value="2B">Year 2 - Class B</option>
                        <option value="2C">Year 2 - Class C</option>
                    </optgroup>
                `;
            }
            
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
            classFilter.innerHTML = '<option value="" data-translate="all_classes">All Classes</option>';
            
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
                        option.textContent = `Year \${year} - Class \${letter}`;
                        classFilter.appendChild(option);
                    });
                    ";
                }
                ?>
            } else {
                // Show only classes for selected year
                const classes = ['A', 'B', 'C'];
                classes.forEach(letter => {
                    const className = selectedYear + letter;
                    const option = document.createElement('option');
                    option.value = className;
                    option.textContent = `Year ${selectedYear} - Class ${letter}`;
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
                counter.style.cssText = 'text-align: right; margin-top: 0.5rem; font-size: 0.9rem; color: #666;';
                tableContainer.appendChild(counter);
            }
            
            const total = document.querySelectorAll('#studentsTable tbody tr').length;
            counter.textContent = `Showing ${count} of ${total} students`;
        }

        // Language Translation System
        const translations = {
            en: {
                nav_reports: "Dashboard",
                nav_students: "Students", 
                nav_graduated: "Graduated",
                nav_subjects: "Subjects",
                nav_marks: "Marks",
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
                cancel: "Cancel",
                grade_distribution: "Grade Distribution",
                subject_performance: "Subject Performance", 
                top_performers: "Top 3 Performers",
                performance_trends: "Performance Trends",
                detailed_reports: "Detailed Reports",
                export_csv: "Export CSV",
                print: "🖨️ Print",
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
                filter_by_grade: "Filter by Grade",
                filter_by_gender: "Filter by Gender",
                filter_by_age_range: "Filter by Age Range",
                filter_by_enrollment: "Filter by Enrollment",
                all_classes: "All Classes",
                all_subjects: "All Subjects",
                all_grades: "All Grades",
                all_genders: "All Genders",
                all_ages: "All Ages",
                all_students: "All Students",
                clear_filters: "🔄 Clear Filters",
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
                marks_list: "📝 Marks List",
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
                select_student: "Select Student",
                class_a: "Class A",
                class_b: "Class B", 
                class_c: "Class C",
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
                nav_reports: "لوحة التحكم",
                nav_students: "الطلاب",
                nav_graduated: "المتخرجون",
                nav_subjects: "المواد", 
                nav_marks: "الدرجات",
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
                subjects_list: "📋 قائمة المواد",
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
                cancel: "إلغاء",
                grade_distribution: "توزيع الدرجات",
                subject_performance: "أداء المواد",
                top_performers: "أفضل 3 طلاب", 
                performance_trends: "اتجاهات الأداء",
                detailed_reports: "التقارير التفصيلية",
                export_csv: "📊 تصدير CSV",
                print: "🖨️ طباعة",
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
                filter_by_grade: "تصفية حسب الدرجة",
                filter_by_gender: "تصفية حسب الجنس",
                filter_by_age_range: "تصفية حسب الفئة العمرية",
                filter_by_enrollment: "تصفية حسب التسجيل",
                all_classes: "جميع الصفوف",
                all_subjects: "جميع المواد",
                all_grades: "جميع الدرجات",
                all_genders: "جميع الأجناس",
                all_ages: "جميع الأعمار",
                all_students: "جميع الطلاب",
                clear_filters: "🔄 مسح التصفيات",
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
                marks_list: "📝 قائمة الدرجات",
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
                select_student: "اختر الطالب",
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
                time: "الوقت",
                sunday: "الأحد",
                monday: "الاثنين",
                tuesday: "الثلاثاء",
                wednesday: "الأربعاء",
                thursday: "الخميس"
            },
            ku: {
                nav_reports: "داشبۆرد",
                nav_students: "قوتابییەکان",
                nav_graduated: "دەرچووەکان",
                nav_subjects: "وانەکان",
                nav_marks: "نمرەکان",
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
                subjects_list: "📋 لیستی وانەکان",
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
                cancel: "هەڵوەشاندنەوە",
                grade_distribution: "دابەشکردنی نمرەکان",
                subject_performance: "کارایی وانەکان",
                top_performers: "باشترین 3 قوتابی",
                performance_trends: "ئاراستەی کارایی", 
                detailed_reports: "📋 ڕاپۆرتە ورددەکان",
                export_csv: "📊 هەناردنی CSV",
                print: "🖨️ چاپکردن",
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
                filter_by_grade: "پاڵاوتن بەپێی نمرە",
                filter_by_gender: "پاڵاوتن بەپێی ڕەگەز",
                filter_by_age_range: "پاڵاوتن بەپێی تەمەن",
                filter_by_enrollment: "پاڵاوتن بەپێی تۆمارکردن",
                all_classes: "هەموو پۆلەکان",
                all_subjects: "هەموو وانەکان",
                all_grades: "هەموو نمرەکان",
                all_genders: "هەموو ڕەگەزەکان",
                all_ages: "هەموو تەمەنەکان",
                all_students: "هەموو قوتابییەکان",
                clear_filters: "🔄 پاڵاوتنەکان بسڕەوە",
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
                input_manage_marks: "تۆمارکردن و بەڕێوەبردنی نمرەی قوتابییان",
                marks_list: "📝 لیستی نمرەکان",
                search_filter_marks: "گەڕان و پاڵاوتنی نمرەکان",
                add_mark: "نمرە زیادبکە",
                student: "قوتابی",
                subject: "وانە",
                status: "بارودۆخ",
                midterm: "ناوەڕاست",
                final: "کۆتایی",
                daily: "ڕۆژانە",
                total: "گشتی",
                final_grade: "نمرەی کۆتایی",
                select_student: "قوتابی هەڵبژێرە",
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
            try {
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
            } catch (error) {
                console.error('Language initialization error:', error);
                // Force English as fallback
                localStorage.setItem('selectedLanguage', 'en');
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
            const currentLang = localStorage.getItem('selectedLanguage') || 'en';
            if (translations[currentLang] && translations[currentLang][key]) {
                return translations[currentLang][key];
            }
            // Fallback to English if translation not found
            return translations.en[key] || key;
        }

        // Language switching function
        function changeLanguage(lang) {
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
            // First, check if student is eligible for promotion
            showLoading('Checking Eligibility', 'Validating student records...');
            
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
                            <strong>${subject.subject_name}</strong> (${subject.credits} credits)
                        </label>
                    </div>
                `).join('');
                
                modal.innerHTML = `
                    <div style="background: white; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative;">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #E5E7EB; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0;">
                            <h3 style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                                📈 Promote to Year 2
                            </h3>
                            <p style="margin: 0.5rem 0 0 0; opacity: 0.9; font-size: 0.9rem;">Select Year 2 subjects for this student</p>
                            <button onclick="this.closest('.promotion-modal').remove()" 
                                    style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: white; opacity: 0.8;">
                                ×
                            </button>
                        </div>
                        <div style="padding: 1.5rem;">
                            <div style="background: #F0FDF4; padding: 1rem; border-radius: 6px; border-left: 4px solid #059669; margin-bottom: 1.5rem;">
                                <h4 style="margin: 0 0 0.5rem 0; color: #059669;">✅ Student Eligible for Promotion</h4>
                                <p style="margin: 0; font-size: 0.9rem; color: #065F46;">
                                    All requirements met! Select Year 2 subjects to enroll.
                                </p>
                            </div>
                            
                            <h4 style="margin: 0 0 1rem 0; color: #374151;">Available Year 2 Subjects:</h4>
                            <div id="subjectsList" style="max-height: 300px; overflow-y: auto;">
                                ${subjectsHtml}
                            </div>
                            
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #E5E7EB;">
                                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                    <button onclick="this.closest('.promotion-modal').remove()" 
                                            style="background: #6B7280; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer;">
                                        Cancel
                                    </button>
                                    <button onclick="executePromotion(${studentId})" 
                                            style="background: #10B981; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        📈 Promote Student
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
                console.error('Error:', error);
                showErrorMessage('Error loading subjects. Please try again.');
            });
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
                showErrorMessage('Please select at least one Year 2 subject.');
                return;
            }
            
            // Execute promotion with selected subjects
            showLoading('Promoting Student', 'Moving to Year 2...');
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
                    showNotification('Student promoted to Year 2!', 'success');
                    setTimeout(() => {
                        reloadTableData('students');
                    }, 500);
                } else {
                    // Show detailed error modal
                    if (data.details) {
                        showEligibilityErrorModal('Promotion', data);
                    } else {
                        showNotification(data.message || 'Error promoting student', 'error');
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
            if (action === 'delete_mark' && !confirm('Are you sure you want to delete this mark?')) {
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
                hideLoading();
                if (data.includes('successfully') || data.includes('Success')) {
                    showNotification('Mark deleted successfully!', 'success');
                    setTimeout(() => {
                        reloadTableData('marks');
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
                filterMarksClass.innerHTML = '<option value="" data-translate="all_classes">All Classes</option>';
                
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
                        // Format display name
                        const year = className.charAt(0);
                        const letter = className.charAt(1);
                        option.textContent = `Year \${year} - Class \${letter}`;
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
    </style>
    </script>
</body>
</html>
