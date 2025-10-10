<?php
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
        
        // Determine status
        $status = $total_mark >= 50 ? 'Pass' : 'Fail';
        
        // Check if mark exists
        $check_query = "SELECT id FROM marks WHERE student_id = $1 AND subject_id = $2";
        $check_result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
        
        if (pg_num_rows($check_result) > 0) {
            // Update existing mark
            $update_query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6 WHERE student_id = $7 AND subject_id = $8";
            $result = pg_query_params($conn, $update_query, array($final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark, $status, $student_id, $subject_id));
        } else {
            // Insert new mark
            $insert_query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
            $result = pg_query_params($conn, $insert_query, array($student_id, $subject_id, $final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark, $status));
        }
        
        if ($result) {
            echo json_encode(['success' => true, 'total' => $total_mark]);
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
                m.final_exam as final_mark,
                m.midterm_exam as midterm_mark,
                m.quizzes as quizzes_mark,
                m.daily_activities as daily_mark,
                m.mark as total_mark,
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
    
    // Determine status
    $status = $total_mark >= 50 ? 'Pass' : 'Fail';
    
    if ($student_id > 0 && $subject_id > 0) {
        // Check if mark already exists
        $check_query = "SELECT id FROM marks WHERE student_id = $1 AND subject_id = $2";
        $check_result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
        
        if (pg_num_rows($check_result) > 0) {
            // Update existing mark
            $query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6 WHERE student_id = $7 AND subject_id = $8";
            $result = pg_query_params($conn, $query, array($final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status, $student_id, $subject_id));
            $message = "Mark updated successfully!";
        } else {
            // Insert new mark
            $query = "INSERT INTO marks (student_id, subject_id, final_exam, midterm_exam, quizzes, daily_activities, mark, status) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
            $result = pg_query_params($conn, $query, array($student_id, $subject_id, $final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status));
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
            header("Location: ?page=students&success=" . urlencode("Student deleted successfully!"));
            exit;
        } else {
            $error_message = "Error deleting student: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Invalid student ID.";
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
        // Determine status
        $status = $total_mark >= 50 ? 'Pass' : 'Fail';
        
        $query = "UPDATE marks SET final_exam = $1, midterm_exam = $2, quizzes = $3, daily_activities = $4, mark = $5, status = $6 WHERE id = $7";
        $result = pg_query_params($conn, $query, array($final_exam, $midterm_exam, $quizzes, $daily_activities, $total_mark, $status, $mark_id));
        
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
            header("Location: ?page=marks&success=" . urlencode("Mark deleted successfully!"));
            exit;
        } else {
            $error_message = "Error deleting mark: " . pg_last_error($conn);
        }
    } else {
        $error_message = "Invalid mark ID.";
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
            
            // Update student to Year 2
            $update_query = "UPDATE students SET year = 2 WHERE id = $1";
            $update_result = pg_query_params($conn, $update_query, array($student_id));
            
            if ($update_result) {
                // Record promotion history
                $history_query = "INSERT INTO promotion_history (student_id, from_year, to_year, promotion_date) VALUES ($1, 1, 2, CURRENT_DATE)";
                pg_query_params($conn, $history_query, array($student_id));
                
                header("Location: ?page=students&success=" . urlencode("Student promoted to Year 2 successfully!"));
                exit;
            } else {
                $error_message = "Error promoting student: " . pg_last_error($conn);
            }
        } else {
            $error_message = "Student not found or not eligible for promotion (must be active Year 1 student).";
        }
    } else {
        $error_message = "Invalid student ID.";
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
            
            // Insert into graduated_students table
            $graduate_query = "INSERT INTO graduated_students (student_id, student_name, age, gender, class_level, email, phone, graduation_date, final_year) 
                              VALUES ($1, $2, $3, $4, $5, $6, $7, CURRENT_DATE, 2)";
            $graduate_result = pg_query_params($conn, $graduate_query, array(
                $student['id'], $student['name'], $student['age'], $student['gender'], 
                $student['class_level'], $student['email'], $student['phone']
            ));
            
            if ($graduate_result) {
                // Update student status to graduated
                $update_query = "UPDATE students SET status = 'graduated' WHERE id = $1";
                pg_query_params($conn, $update_query, array($student_id));
                
                header("Location: ?page=students&success=" . urlencode("Student graduated successfully!"));
                exit;
            } else {
                $error_message = "Error graduating student: " . pg_last_error($conn);
            }
        } else {
            $error_message = "Student not found or not eligible for graduation (must be active Year 2 student).";
        }
    } else {
        $error_message = "Invalid student ID.";
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
function getKPIs($conn) {
    // Get student counts by year and status
    $student_stats = pg_query($conn, "
        SELECT 
            year,
            status,
            COUNT(*) as count
        FROM students 
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
    
    $total_subjects = pg_query($conn, "SELECT COUNT(*) as count FROM subjects");
    $total_subjects_count = pg_fetch_assoc($total_subjects)['count'];
    
    $avg_score = pg_query($conn, "SELECT ROUND(AVG(mark), 1) as avg FROM marks WHERE mark > 0");
    $avg_score_value = pg_fetch_assoc($avg_score)['avg'] ?? 0;
    
    $top_year = pg_query($conn, "
        SELECT s.year, ROUND(AVG(m.mark), 1) as avg_mark 
        FROM students s 
        JOIN marks m ON s.id = m.student_id 
        WHERE m.mark > 0 AND s.status = 'active'
        GROUP BY s.year 
        ORDER BY avg_mark DESC 
        LIMIT 1
    ");
    $top_year_data = pg_fetch_assoc($top_year);
    
    return [
        'total_students' => $total_active,
        'year1_students' => $year1_active,
        'year2_students' => $year2_active,
        'graduated_students' => $graduated_count,
        'total_subjects' => $total_subjects_count,
        'avg_score' => $avg_score_value,
        'top_year' => $top_year_data['year'] ?? 'N/A',
        'top_year_avg' => $top_year_data['avg_mark'] ?? 0
    ];
}

function generateChartData($conn) {
    // Grade distribution
    $grade_dist = pg_query($conn, "
        SELECT 
            CASE 
                WHEN mark >= 90 THEN 'A+'
                WHEN mark >= 80 THEN 'A'
                WHEN mark >= 70 THEN 'B'
                WHEN mark >= 50 THEN 'C'
                ELSE 'F'
            END as grade,
            COUNT(*) as count
        FROM marks 
        WHERE mark > 0
        GROUP BY 
            CASE 
                WHEN mark >= 90 THEN 'A+'
                WHEN mark >= 80 THEN 'A'
                WHEN mark >= 70 THEN 'B'
                WHEN mark >= 50 THEN 'C'
                ELSE 'F'
            END
        ORDER BY 
            CASE 
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A+' THEN 1
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A' THEN 2
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'B' THEN 3
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'C' THEN 4
                ELSE 5
            END
    ");
    
    $grades = [];
    $grade_counts = [];
    if ($grade_dist && pg_num_rows($grade_dist) > 0) {
        while($grade = pg_fetch_assoc($grade_dist)) {
            $grades[] = $grade['grade'];
            $grade_counts[] = (int)$grade['count'];
        }
    }
    
    return [
        'kpis' => getKPIs($conn),
        'grades' => $grades,
        'grade_counts' => $grade_counts,
        'student_distribution' => [
            'labels' => ['Year 1 Active', 'Year 2 Active', 'Graduated'],
            'data' => [
                getKPIs($conn)['year1_students'],
                getKPIs($conn)['year2_students'], 
                getKPIs($conn)['graduated_students']
            ]
        ]
    ];
}

$chartData = generateChartData($conn);
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
        /* ===== CSS VARIABLES ===== */
        :root {
            --primary-color: #2563EB;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748B;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --text-color: #1F2937;
            --text-light: #6B7280;
            --bg-color: #F8FAFC;
            --card-bg: #FFFFFF;
            --border-color: #E5E7EB;
            --hover-color: #F3F4F6;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ===== GLOBAL STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--text-color);
            line-height: 1.6;
        }

        /* ===== NAVIGATION ===== */
        nav {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #1e40af 100%);
            padding: 0;
            box-shadow: 0 8px 32px rgba(30, 58, 138, 0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(20px);
            border-bottom: 3px solid #fbbf24;
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
            gap: 1rem;
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

        .students-icon { background: linear-gradient(45deg, #667eea, #764ba2); }
        .subjects-icon { background: linear-gradient(45deg, #f093fb, #f5576c); }
        .score-icon { background: linear-gradient(45deg, #4facfe, #00f2fe); }
        .class-icon { background: linear-gradient(45deg, #43e97b, #38f9d7); }

        .kpi-content {
            flex: 1;
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

        .chart-container {
            height: 300px;
            position: relative;
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
        }

        .schedule-slot {
            display: inline-block;
            margin: 3px 5px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            border: 1px solid #ddd;
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
                <a href="?page=graduated" <?= $page == 'graduated' ? 'class="active"' : '' ?> data-translate="nav_graduated">Graduated</a>
                <a href="?page=subjects" <?= $page == 'subjects' ? 'class="active"' : '' ?> data-translate="nav_subjects">Subjects</a>
                <a href="?page=marks" <?= $page == 'marks' ? 'class="active"' : '' ?> data-translate="nav_marks">Marks</a>
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
                        <!-- Premium KPI Cards -->
                        <div class="kpi-grid">
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="kpi-icon students-icon">👥</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['year1_students'] ?>"><?= $chartData['kpis']['year1_students'] ?></div>
                                    <div class="kpi-label" data-translate="year_1_students">Year 1 Students</div>
                                    <div class="kpi-trend positive" data-translate="active_students">Active students</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="150">
                                <div class="kpi-icon students-icon">🎓</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['year2_students'] ?>"><?= $chartData['kpis']['year2_students'] ?></div>
                                    <div class="kpi-label" data-translate="year_2_students">Year 2 Students</div>
                                    <div class="kpi-trend positive" data-translate="active_students">Active students</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="200">
                                <div class="kpi-icon subjects-icon">🏆</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['graduated_students'] ?>"><?= $chartData['kpis']['graduated_students'] ?></div>
                                    <div class="kpi-label" data-translate="graduated_students">Graduated Students</div>
                                    <div class="kpi-trend positive" data-translate="successfully_graduated">Successfully graduated</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="250">
                                <div class="kpi-icon subjects-icon">📚</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['total_subjects'] ?>"><?= $chartData['kpis']['total_subjects'] ?></div>
                                    <div class="kpi-label" data-translate="total_subjects">Total Subjects</div>
                                    <div class="kpi-trend positive" data-translate="available_courses">Available courses</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="300">
                                <div class="kpi-icon score-icon">⭐</div>
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['avg_score'] ?>%</div>
                                    <div class="kpi-label" data-translate="average_score">Average Score</div>
                                    <div class="kpi-trend positive" data-translate="overall_performance">Overall performance</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="350">
                                <div class="kpi-icon class-icon">🏆</div>
                                <div class="kpi-content">
                                    <div class="kpi-value">Year <?= $chartData['kpis']['top_year'] ?></div>
                                    <div class="kpi-label" data-translate="top_performing_year">Top Performing Year</div>
                                    <div class="kpi-trend positive"><?= $chartData['kpis']['top_year_avg'] ?>% average</div>
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
                                        <button class="chart-expand-btn" onclick="expandChart('performanceChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="performanceDistributionChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Subject Performance Chart -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="chart-header">
                                    <h3 class="chart-title">🎯 Subject Performance</h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('subjectChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="subjectPerformanceChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Top Performers Chart -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="150">
                                <div class="chart-header">
                                    <h3 class="chart-title">🏆 Top 3 Performers</h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('topPerformersChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="topPerformersChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Student Distribution by Year Chart -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="200">
                                <div class="chart-header">
                                    <h3 class="chart-title" data-translate="student_distribution">📊 Student Distribution</h3>
                                    <div class="chart-actions">
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
                    
                    // Define realistic, varied schedules for each class using your actual subjects
                    $class_schedules = [
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
                    ];
                    
                    // Function to get subject CSS class
                    function getSubjectClass($subject) {
                        $subject_lower = strtolower($subject);
                        if (strpos($subject_lower, 'c++') !== false || strpos($subject_lower, 'advanced') !== false) return 'subject-computer';
                        if (strpos($subject_lower, 'database') !== false) return 'subject-science';
                        if (strpos($subject_lower, 'english') !== false) return 'subject-english';
                        if (strpos($subject_lower, 'human') !== false || strpos($subject_lower, 'management') !== false) return 'subject-history';
                        if (strpos($subject_lower, 'web') !== false || strpos($subject_lower, 'development') !== false) return 'subject-math';
                        return 'subject-cell';
                    }
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
                                        <?php foreach ($class_schedules['A'][$day] as $slot): ?>
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
                                        <?php foreach ($class_schedules['B'][$day] as $slot): ?>
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
                                        <?php foreach ($class_schedules['C'][$day] as $slot): ?>
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
                                        <?php
                                        $subjects = pg_query($conn, "SELECT id, subject_name FROM subjects ORDER BY subject_name");
                                        if ($subjects && pg_num_rows($subjects) > 0) {
                                            while($subject = pg_fetch_assoc($subjects)):
                                        ?>
                                        <option value="<?= htmlspecialchars($subject['subject_name']) ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                                        <?php 
                                            endwhile;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_grade">Filter by Grade</label>
                                    <select id="reportsFilterGrade" class="premium-select" onchange="filterReportsTable()">
                                        <option value="" data-translate="all_grades">All Grades</option>
                                        <option value="A+">A+</option>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="F">F</option>
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
                                    <button class="export-btn" onclick="exportData('pdf')" data-translate="export_pdf">📄 Export PDF</button>
                                    <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                                </div>
                            </div>
                            <div class="table-container">
                                <table id="reportsTable">
                                    <thead>
                                        <tr>
                                            <th data-translate="student_name">Student Name</th>
                                            <th data-translate="class">Class</th>
                                            <th data-translate="subject">Subject</th>
                                            <th data-translate="final">Final</th>
                                            <th data-translate="midterm">Midterm</th>
                                            <th data-translate="quizzes">Quizzes</th>
                                            <th data-translate="daily">Daily</th>
                                            <th data-translate="total">Total</th>
                                            <th data-translate="grade">Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportsTableBody">
                                        <?php
                                        $reports_query = "
                                            SELECT 
                                                m.id as mark_id,
                                                s.name as student_name,
                                                s.class_level,
                                                sub.subject_name,
                                                m.final_exam as final_mark,
                                                m.midterm_exam as midterm_mark,
                                                m.quizzes as quizzes_mark,
                                                m.daily_activities as daily_mark,
                                                m.mark as total_mark,
                                                CASE 
                                                    WHEN m.mark >= 90 THEN 'A+'
                                                    WHEN m.mark >= 80 THEN 'A'
                                                    WHEN m.mark >= 70 THEN 'B'
                                                    WHEN m.mark >= 50 THEN 'C'
                                                    ELSE 'F'
                                                END as grade
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
                                            JOIN subjects sub ON m.subject_id = sub.id
                                            WHERE m.mark > 0
                                            ORDER BY s.name, sub.subject_name
                                            LIMIT 50
                                        ";
                                        $reports_result = pg_query($conn, $reports_query);
                                        
                                        if ($reports_result && pg_num_rows($reports_result) > 0):
                                            while($report = pg_fetch_assoc($reports_result)):
                                                $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $report['grade']));
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($report['student_name']) ?></td>
                                            <td><?= htmlspecialchars($report['class_level']) ?></td>
                                            <td><?= htmlspecialchars($report['subject_name']) ?></td>
                                            <td><?= intval($report['final_mark']) ?></td>
                                            <td><?= intval($report['midterm_mark']) ?></td>
                                            <td><?= intval($report['quizzes_mark']) ?></td>
                                            <td><?= intval($report['daily_mark']) ?></td>
                                            <td><strong><?= intval($report['total_mark']) ?></strong></td>
                                            <td><span class="grade-badge <?= $grade_class ?>"><?= $report['grade'] ?></span></td>
                                        </tr>
                                        <?php 
                                            endwhile;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center; color: var(--text-light); padding: 2rem;">
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
                        <form method="POST" action="">
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
                                    <label class="filter-label" data-translate="class">Class *</label>
                                    <select name="class_level" class="premium-select" required>
                                        <option value="" data-translate="select_class">Select Class</option>
                                        <option value="A">Class A</option>
                                        <option value="B">Class B</option>
                                        <option value="C">Class C</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year *</label>
                                    <select name="year" id="studentYearSelect" class="premium-select" required onchange="filterSubjectsByStudentYear()">
                                        <option value="" data-translate="select_year">Select Year</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
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
                                    <label class="filter-label">Enroll in Subjects</label>
                                    <div id="subjectEnrollmentContainer" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; max-height: 150px; overflow-y: auto;">
                                        <?php
                                        $subjects_query = "SELECT id, subject_name, description, year FROM subjects ORDER BY year, subject_name";
                                        $subjects_result = pg_query($conn, $subjects_query);
                                        
                                        if ($subjects_result && pg_num_rows($subjects_result) > 0):
                                            while($subject = pg_fetch_assoc($subjects_result)):
                                        ?>
                                        <div class="subject-item" data-subject-year="<?= $subject['year'] ?? '' ?>" style="margin-bottom: 10px; display: flex; align-items: center;">
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
                                        <p style="color: #666; margin: 0;">No subjects available. Please add subjects first.</p>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color: #666; margin-top: 5px; display: block;" data-translate="select_subjects_enroll">
                                        Select subjects to enroll this student in (optional - can be done later)
                                    </small>
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
                                        ORDER BY s.year, s.name
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
                                            <button class="export-btn" onclick="deleteStudent(<?= $student['id'] ?>)" 
                                                    style="background: var(--danger-color); color: white; margin: 2px;" data-translate="delete">🗑️ Delete</button>
                                            
                                            <?php if ($student['year'] == 1): ?>
                                                <button class="export-btn" onclick="promoteStudent(<?= $student['id'] ?>)" 
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
                                <button class="export-btn" onclick="exportData('csv')" data-translate="export_csv">📊 Export CSV</button>
                                <button class="export-btn" onclick="printTable()" data-translate="print">🖨️ Print</button>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table id="graduatedTable">
                                <thead>
                                    <tr>
                                        <th data-translate="id">ID</th>
                                        <th data-translate="name">Name</th>
                                        <th data-translate="age">Age</th>
                                        <th data-translate="gender">Gender</th>
                                        <th data-translate="class">Class</th>
                                        <th data-translate="email">Email</th>
                                        <th data-translate="phone">Phone</th>
                                        <th data-translate="graduation_date">Graduation Date</th>
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
                                        <td><?= $graduate['student_id'] ?></td>
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
                                        <td><?= htmlspecialchars($graduate['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($graduate['phone'] ?? '') ?></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;
                                                         background: #f0f9ff; color: #0369a1;">
                                                <?= date('M d, Y', strtotime($graduate['graduation_date'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-light); padding: 2rem;">
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
                        <form method="POST" action="">
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
                                    <input type="number" name="credits" class="premium-select" min="1" max="10" required>
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
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $subjects_query = "SELECT * FROM subjects ORDER BY subject_name";
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
                                            <button class="export-btn" onclick="editSubject(<?= $subject['id'] ?>)" data-translate="edit">✏️ Edit</button>
                                            <button class="export-btn" onclick="deleteSubject(<?= $subject['id'] ?>)" style="background: var(--danger-color); color: white;" data-translate="delete">🗑️ Delete</button>
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
                    <!-- Add Mark Form -->
                    <div class="filter-panel" data-aos="fade-up">
                        <h3 class="filter-title" data-translate="add_new_mark">➕ Add New Mark</h3>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_mark">
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="academic_year">Academic Year</label>
                                    <select id="marksAcademicYear" name="academic_year" class="premium-select" onchange="filterMarkStudentsAndSubjects()" required>
                                        <option value="" data-translate="select_academic_year">Select Academic Year</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="class_level">Class</label>
                                    <select id="marksClassLevel" name="class_level" class="premium-select" onchange="filterMarkStudentsAndSubjects()" required>
                                        <option value="" data-translate="select_class">Select Class</option>
                                        <option value="A" data-translate="class_a">Class A</option>
                                        <option value="B" data-translate="class_b">Class B</option>
                                        <option value="C" data-translate="class_c">Class C</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="student">Student</label>
                                    <select name="student_id" id="marksStudentSelect" class="premium-select" required>
                                        <option value="" data-translate="select_student">Select Student</option>
                                        <!-- Students will be populated by JavaScript based on year selection -->
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="subject">Subject</label>
                                    <select name="subject_id" id="marksSubjectSelect" class="premium-select" required>
                                        <option value="" data-translate="select_subject">Select Subject</option>
                                        <!-- Subjects will be populated by JavaScript based on year selection -->
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="final_exam_range">Final Exam (0-60)</label>
                                    <input type="number" name="final_exam" class="premium-select" min="0" max="60" required>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="midterm_range">Midterm (0-20)</label>
                                    <input type="number" name="midterm_exam" class="premium-select" min="0" max="20" required>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="quizzes_range">Quizzes (0-10)</label>
                                    <input type="number" name="quizzes" class="premium-select" min="0" max="10" required>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="daily_range">Daily Activities (0-10)</label>
                                    <input type="number" name="daily_activities" class="premium-select" min="0" max="10" required>
                                </div>
                                <button type="submit" class="apply-filters-btn" data-translate="add_mark">Add Mark</button>
                            </div>
                        </form>
                    </div>

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
                                    <select id="filterMarksYear" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_years">All Years</option>
                                        <option value="1" data-translate="year_1">Year 1</option>
                                        <option value="2" data-translate="year_2">Year 2</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_status">Student Status</label>
                                    <select id="filterStudentStatus" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_status">All Status</option>
                                        <option value="active" data-translate="active_students">Active Students</option>
                                        <option value="graduated" data-translate="graduated_students">Graduated Students</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_class">Filter by Class</label>
                                    <select id="filterMarksClass" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_classes">All Classes</option>
                                        <option value="A" data-translate="class_a">Class A</option>
                                        <option value="B" data-translate="class_b">Class B</option>
                                        <option value="C" data-translate="class_c">Class C</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="search">Search</label>
                                    <input type="text" id="searchMarks" class="premium-select" placeholder="Search by student name or subject..." data-translate-placeholder="search_by_student_subject" onkeyup="filterMarks()">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_student">Filter by Student</label>
                                    <select id="filterStudent" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_students">All Students</option>
                                        <?php
                                        $filter_students = pg_query($conn, "SELECT DISTINCT s.id, s.name FROM students s JOIN marks m ON s.id = m.student_id ORDER BY s.name");
                                        while($student = pg_fetch_assoc($filter_students)):
                                        ?>
                                        <option value="<?= htmlspecialchars($student['name']) ?>"><?= htmlspecialchars($student['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_subject">Filter by Subject</label>
                                    <select id="filterSubject" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_subjects">All Subjects</option>
                                        <?php
                                        $filter_subjects = pg_query($conn, "SELECT DISTINCT sub.id, sub.subject_name FROM subjects sub JOIN marks m ON sub.id = m.subject_id ORDER BY sub.subject_name");
                                        while($subject = pg_fetch_assoc($filter_subjects)):
                                        ?>
                                        <option value="<?= htmlspecialchars($subject['subject_name']) ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_grade">Filter by Grade</label>
                                    <select id="filterGrade" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_grades">All Grades</option>
                                        <option value="A+">A+</option>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="F">F</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label" data-translate="filter_by_status">Filter by Status</label>
                                    <select id="filterStatus" class="premium-select" onchange="filterMarks()">
                                        <option value="" data-translate="all_status">All Status</option>
                                        <option value="Pass">Pass</option>
                                        <option value="Fail">Fail</option>
                                        <option value="Pending">Pending</option>
                                    </select>
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
                                        <th data-translate="year">Year</th>
                                        <th data-translate="class_level">Class</th>
                                        <th data-translate="student_status">Status</th>
                                        <th data-translate="subject">Subject</th>
                                        <th data-translate="final">Final</th>
                                        <th data-translate="midterm">Midterm</th>
                                        <th data-translate="quizzes">Quizzes</th>
                                        <th data-translate="daily">Daily</th>
                                        <th data-translate="total">Total</th>
                                        <th data-translate="grade">Grade</th>
                                        <th data-translate="status">Status</th>
                                        <th data-translate="actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $marks_query = "
                                        SELECT 
                                            m.*,
                                            s.name as student_name,
                                            s.year as student_year,
                                            s.status as student_status,
                                            s.class_level as student_class,
                                            sub.subject_name,
                                            sub.year as subject_year,
                                            CASE 
                                                WHEN m.mark >= 90 THEN 'A+'
                                                WHEN m.mark >= 80 THEN 'A'
                                                WHEN m.mark >= 70 THEN 'B'
                                                WHEN m.mark >= 50 THEN 'C'
                                                ELSE 'F'
                                            END as grade
                                        FROM marks m
                                        JOIN students s ON m.student_id = s.id
                                        JOIN subjects sub ON m.subject_id = sub.id
                                        ORDER BY s.year, s.name, sub.subject_name
                                    ";
                                    $marks_result = pg_query($conn, $marks_query);
                                    
                                    if ($marks_result && pg_num_rows($marks_result) > 0):
                                        while($mark = pg_fetch_assoc($marks_result)):
                                            $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $mark['grade']));
                                    ?>
                                    <tr data-year="<?= $mark['student_year'] ?>" data-student-status="<?= $mark['student_status'] ?>" data-class="<?= $mark['student_class'] ?>">
                                        <td><?= htmlspecialchars($mark['student_name']) ?></td>
                                        <td>
                                            <span style="padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: <?= $mark['student_year'] == 1 ? '#dbeafe' : '#dcfce7' ?>; 
                                                         color: <?= $mark['student_year'] == 1 ? '#1e40af' : '#166534' ?>;">
                                                Year <?= $mark['student_year'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: #f3f4f6; color: #374151;">
                                                Class <?= htmlspecialchars($mark['student_class']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: <?= $mark['student_status'] == 'active' ? '#dcfce7' : '#f3f4f6' ?>; 
                                                         color: <?= $mark['student_status'] == 'active' ? '#166534' : '#6b7280' ?>;">
                                                <?= $mark['student_status'] == 'active' ? 'Active' : 'Graduated' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($mark['subject_name']) ?></td>
                                        <td><?= (int)$mark['final_exam'] ?></td>
                                        <td><?= (int)$mark['midterm_exam'] ?></td>
                                        <td><?= (int)$mark['quizzes'] ?></td>
                                        <td><?= (int)$mark['daily_activities'] ?></td>
                                        <td><strong><?= (int)$mark['mark'] ?></strong></td>
                                        <td><span class="grade-badge <?= $grade_class ?>"><?= $mark['grade'] ?></span></td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;
                                                         background: <?= $mark['status'] == 'Pass' ? '#dcfce7' : ($mark['status'] == 'Fail' ? '#fecaca' : '#fef3c7') ?>; 
                                                         color: <?= $mark['status'] == 'Pass' ? '#166534' : ($mark['status'] == 'Fail' ? '#dc2626' : '#d97706') ?>;">
                                                <?= htmlspecialchars($mark['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="export-btn" onclick="editMark(<?= $mark['id'] ?>)" style="margin: 2px;" data-translate="edit">✏️ Edit</button>
                                            <button class="export-btn" onclick="deleteMark(<?= $mark['id'] ?>)" 
                                                    style="background: var(--danger-color); color: white; margin: 2px;" data-translate="delete">🗑️ Delete</button>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="13" style="text-align: center; color: var(--text-light); padding: 2rem;">
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

        // Chart creation functions
        function createGradeDistributionChart() {
            const gradeCtx = document.getElementById('performanceDistributionChart');
            if (!gradeCtx) return;
            
            // Get grade distribution data directly in function
            const gradeLabels = <?= json_encode($chartData['grades']) ?>;
            const gradeCounts = <?= json_encode($chartData['grade_counts']) ?>;
            
            window.gradeDistributionChart = new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: gradeLabels.length > 0 ? gradeLabels : ['A+', 'A', 'B', 'C', 'F'],
                    datasets: [{
                        data: gradeCounts.length > 0 ? gradeCounts : [8, 12, 15, 10, 5],
                        backgroundColor: [
                            '#10B981', // A+ - Green
                            '#3B82F6', // A - Blue  
                            '#F59E0B', // B - Yellow
                            '#EF4444', // C - Orange
                            '#DC2626'  // F - Red
                        ],
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

        function createSubjectPerformanceChart() {
            const subjectCtx = document.getElementById('subjectPerformanceChart');
            if (!subjectCtx) return;
            
            <?php
            // Get subject performance data
            $subject_query = pg_query($conn, "
                SELECT 
                    sub.subject_name,
                    ROUND(AVG(m.mark), 1) as avg_mark
                FROM subjects sub
                LEFT JOIN marks m ON sub.id = m.subject_id
                GROUP BY sub.id, sub.subject_name
                ORDER BY avg_mark DESC NULLS LAST
            ");

            $subject_labels = [];
            $subject_averages = [];

            if ($subject_query && pg_num_rows($subject_query) > 0) {
                while($subject = pg_fetch_assoc($subject_query)) {
                    $subject_labels[] = $subject['subject_name'];
                    $subject_averages[] = $subject['avg_mark'] ? (float)$subject['avg_mark'] : 0;
                }
            } else {
                // Fallback data
                $subject_labels = ['No Data'];
                $subject_averages = [0];
            }
            ?>
            
            const subjects = <?= json_encode($subject_labels) ?>;
            const scores = <?= json_encode($subject_averages) ?>;
            
            window.subjectPerformanceChart = new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: subjects,
                    datasets: [{
                        label: 'Average Score',
                        data: scores,
                        backgroundColor: 'rgba(102, 126, 234, 0.8)',
                        borderColor: '#667eea',
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
                            color: '#667eea',
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
                                font: { family: "'Poppins', sans-serif", size: 11, weight: '500' },
                                color: '#8892b0',
                                maxRotation: 45
                            }
                        }
                    }
                }
            });
        }

        function createTopPerformersChart() {
            const topPerformersCtx = document.getElementById('topPerformersChart');
            if (!topPerformersCtx) return;
            
            <?php
            // Get top 3 performers data
            $top_performers_query = pg_query($conn, "
                SELECT 
                    s.name as student_name,
                    ROUND(AVG(m.mark), 1) as avg_mark,
                    COUNT(m.mark) as subjects_count
                FROM students s
                JOIN marks m ON s.id = m.student_id
                WHERE m.mark > 0
                GROUP BY s.id, s.name
                HAVING COUNT(m.mark) >= 2
                ORDER BY AVG(m.mark) DESC
                LIMIT 3
            ");
            
            $top_performers_names = [];
            $top_performers_scores = [];
            
            if ($top_performers_query && pg_num_rows($top_performers_query) > 0) {
                while($performer = pg_fetch_assoc($top_performers_query)) {
                    $top_performers_names[] = $performer['student_name'];
                    $top_performers_scores[] = (float)$performer['avg_mark'];
                }
            } else {
                // Fallback data
                $top_performers_names = ['No Data'];
                $top_performers_scores = [0];
            }
            ?>
            
            const topPerformersLabels = <?= json_encode($top_performers_names) ?>;
            const topPerformersData = <?= json_encode($top_performers_scores) ?>;
            
            // Generate colors for top 3 (Gold, Silver, Bronze)
            const rankColors = topPerformersData.map((score, index) => {
                if (index === 0) return '#FFD700'; // Gold for 1st
                if (index === 1) return '#C0C0C0'; // Silver for 2nd
                if (index === 2) return '#CD7F32'; // Bronze for 3rd
                return '#64FFDA'; // Fallback
            });
            
            window.topPerformersChart = new Chart(topPerformersCtx, {
                type: 'bar',
                data: {
                    labels: topPerformersLabels,
                    datasets: [{
                        label: 'Average Score',
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
                                    return `${rankEmoji} Rank ${rank}: ${context.parsed.x}%`;
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
            const distributionLabels = <?= json_encode($chartData['student_distribution']['labels']) ?>;
            const distributionData = <?= json_encode($chartData['student_distribution']['data']) ?>;
            
            window.studentDistributionChart = new Chart(distributionCtx, {
                type: 'pie',
                data: {
                    labels: distributionLabels,
                    datasets: [{
                        data: distributionData,
                        backgroundColor: [
                            '#3B82F6', // Year 1 - Blue
                            '#10B981', // Year 2 - Green  
                            '#F59E0B'  // Graduated - Yellow
                        ],
                        borderColor: [
                            '#2563EB',
                            '#059669',
                            '#D97706'
                        ],
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
                                return value > 0 ? `${value}\n${percentage}%` : '';
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

            // Grade Distribution Chart (Doughnut)
            createGradeDistributionChart();
            console.log('Grade distribution chart created');

            // Subject Performance Chart (Bar) - Real Data
            createSubjectPerformanceChart();
            console.log('Subject performance chart created');

            // Top 3 Performers Chart (Horizontal Bar) - Real Data
            createTopPerformersChart();
            console.log('Top 3 performers chart created');

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
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_student';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'student_id';
                idInput.value = studentId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function promoteStudent(studentId) {
            if (confirm('Are you sure you want to promote this student from Year 1 to Year 2?')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'promote_student';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'student_id';
                idInput.value = studentId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function graduateStudent(studentId) {
            if (confirm('Are you sure you want to graduate this student? This will move them to the graduated students list.')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'graduate_student';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'student_id';
                idInput.value = studentId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showEditModal(student, enrolledSubjects, allSubjects) {
            // Create modal HTML
            const modalHTML = `
                <div id="editModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Student</h3>
                        <form id="editStudentForm" method="POST" action="">
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
                                    <select name="class_level" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                        <option value="">Select Class</option>
                                        <option value="A" ${student.class_level === 'A' ? 'selected' : ''}>Class A</option>
                                        <option value="B" ${student.class_level === 'B' ? 'selected' : ''}>Class B</option>
                                        <option value="C" ${student.class_level === 'C' ? 'selected' : ''}>Class C</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Academic Year *</label>
                                    <select name="year" id="editStudentYearSelect" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;" onchange="filterEditSubjectsByStudentYear()">
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
            
            // Filter subjects based on student's current year after modal is created
            setTimeout(() => {
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
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_subject';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'subject_id';
                idInput.value = subjectId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showEditSubjectModal(subject) {
            // Create modal HTML
            const modalHTML = `
                <div id="editSubjectModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Subject</h3>
                        <form id="editSubjectForm" method="POST" action="">
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
                                    <input type="number" name="credits" value="${subject.credits || ''}" min="1" max="10" required 
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
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_mark';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'mark_id';
                idInput.value = markId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showEditMarkModal(mark) {
            // Create modal HTML
            const modalHTML = `
                <div id="editMarkModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 1.5rem 0; color: #333; font-family: 'Poppins', sans-serif;">✏️ Edit Mark</h3>
                        <form id="editMarkForm" method="POST" action="">
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
                                    <input type="number" name="final_exam" value="${mark.final_exam || 0}" min="0" max="60" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Midterm (0-20) *</label>
                                    <input type="number" name="midterm_exam" value="${mark.midterm_exam || 0}" min="0" max="20" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Quizzes (0-10) *</label>
                                    <input type="number" name="quizzes" value="${mark.quizzes || 0}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Daily Activities (0-10) *</label>
                                    <input type="number" name="daily_activities" value="${mark.daily_activities || 0}" min="0" max="10" required 
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                </div>
                                <div style="grid-column: span 2;">
                                    <div style="background: #f0f9ff; padding: 1rem; border-radius: 6px; border: 1px solid #0ea5e9;">
                                        <div style="font-weight: 500; color: #0c4a6e; margin-bottom: 0.5rem;">📊 Mark Calculation</div>
                                        <div style="font-size: 0.9rem; color: #0369a1;">
                                            Total = Final Exam + Midterm + Quizzes + Daily Activities<br>
                                            <strong>Current Total: <span id="totalPreview">${(parseInt(mark.final_exam) + parseInt(mark.midterm_exam) + parseInt(mark.quizzes) + parseInt(mark.daily_activities)) || 0}</span>/100</strong>
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
            
            // Add real-time total calculation
            const inputs = document.querySelectorAll('#editMarkModal input[type="number"]');
            inputs.forEach(input => {
                input.addEventListener('input', updateTotalPreview);
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
            const studentStatusFilter = document.getElementById('filterStudentStatus').value;
            const classFilter = document.getElementById('filterMarksClass').value;
            const studentFilter = document.getElementById('filterStudent').value.toLowerCase();
            const subjectFilter = document.getElementById('filterSubject').value.toLowerCase();
            const gradeFilter = document.getElementById('filterGrade').value;
            const statusFilter = document.getElementById('filterStatus').value;
            
            const table = document.getElementById('marksTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            let visibleCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                
                if (cells.length === 0) continue; // Skip header or empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                const yearText = cells[1].textContent.toLowerCase();
                const classText = cells[2].textContent.toLowerCase();
                const studentStatusText = cells[3].textContent.toLowerCase();
                const subjectName = cells[4].textContent.toLowerCase();
                const grade = cells[10].textContent.trim();
                const status = cells[11].textContent.trim();
                
                // Get year, class, and student status from data attributes
                const rowYear = row.getAttribute('data-year');
                const rowStudentStatus = row.getAttribute('data-student-status');
                const rowClass = row.getAttribute('data-class');
                
                // Check search criteria
                const matchesSearch = searchValue === '' || 
                    studentName.includes(searchValue) || 
                    subjectName.includes(searchValue);
                
                const matchesYear = yearFilter === '' || rowYear === yearFilter;
                const matchesStudentStatus = studentStatusFilter === '' || rowStudentStatus === studentStatusFilter;
                const matchesClass = classFilter === '' || rowClass === classFilter;
                const matchesStudent = studentFilter === '' || studentName.includes(studentFilter);
                const matchesSubject = subjectFilter === '' || subjectName.includes(subjectFilter);
                const matchesGrade = gradeFilter === '' || grade === gradeFilter;
                const matchesStatus = statusFilter === '' || status === statusFilter;
                
                // Show/hide row based on all criteria
                if (matchesSearch && matchesYear && matchesStudentStatus && matchesClass && matchesStudent && matchesSubject && matchesGrade && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Update results counter
            updateMarksCounter(visibleCount);
        }

        function clearMarksFilters() {
            document.getElementById('searchMarks').value = '';
            document.getElementById('filterMarksYear').value = '';
            document.getElementById('filterStudentStatus').value = '';
            document.getElementById('filterMarksClass').value = '';
            document.getElementById('filterStudent').value = '';
            document.getElementById('filterSubject').value = '';
            document.getElementById('filterGrade').value = '';
            document.getElementById('filterStatus').value = '';
            filterMarks();
        }

        // Function to filter students and subjects based on academic year selection
        function filterMarkStudentsAndSubjects() {
            const selectedYear = document.getElementById('marksAcademicYear').value;
            const selectedClass = document.getElementById('marksClassLevel').value;
            const studentSelect = document.getElementById('marksStudentSelect');
            const subjectSelect = document.getElementById('marksSubjectSelect');
            
            // Clear current options except the first one
            studentSelect.innerHTML = '<option value="" data-translate="select_student">Select Student</option>';
            subjectSelect.innerHTML = '<option value="" data-translate="select_subject">Select Subject</option>';
            
            if (selectedYear === '' || selectedClass === '') {
                return; // Don't populate if no year or class selected
            }
            
            // Populate students for selected year and class (only active students)
            <?php
            $students_by_year_class = pg_query($conn, "SELECT id, name, year, class_level FROM students WHERE status = 'active' ORDER BY year, class_level, name");
            echo "const allStudents = " . json_encode(pg_fetch_all($students_by_year_class)) . ";\n";
            
            $subjects_by_year = pg_query($conn, "SELECT id, subject_name, year FROM subjects ORDER BY year, subject_name");
            echo "const allSubjects = " . json_encode(pg_fetch_all($subjects_by_year)) . ";\n";
            ?>
            
            // Filter and add students by year and class
            allStudents.forEach(student => {
                if (student.year == selectedYear && student.class_level == selectedClass) {
                    const option = document.createElement('option');
                    option.value = student.id;
                    option.textContent = student.name;
                    studentSelect.appendChild(option);
                }
            });
            
            // Filter and add subjects by year
            allSubjects.forEach(subject => {
                if (subject.year == selectedYear) {
                    const option = document.createElement('option');
                    option.value = subject.id;
                    option.textContent = subject.subject_name;
                    subjectSelect.appendChild(option);
                }
            });
            
            // Update translations
            updateTranslations();
        }

        // Reports filtering and search functions
        function filterReportsTable() {
            const searchValue = document.getElementById('reportsSearchStudent').value.toLowerCase();
            const classFilter = document.getElementById('reportsFilterClass').value;
            const subjectFilter = document.getElementById('reportsFilterSubject').value;
            const gradeFilter = document.getElementById('reportsFilterGrade').value;
            
            const table = document.getElementById('reportsTable');
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length === 0) return; // Skip empty rows
                
                const studentName = cells[0].textContent.toLowerCase();
                const classLevel = cells[1].textContent;
                const subject = cells[2].textContent;
                const grade = cells[8].textContent.trim();
                
                let matches = true;
                
                // Search filter
                if (searchValue && !studentName.includes(searchValue)) {
                    matches = false;
                }
                
                // Class filter
                if (classFilter && classLevel !== classFilter) {
                    matches = false;
                }
                
                // Subject filter
                if (subjectFilter && subject !== subjectFilter) {
                    matches = false;
                }
                
                // Grade filter
                if (gradeFilter && grade !== gradeFilter) {
                    matches = false;
                }
                
                if (matches) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update results counter
            updateReportsCounter(visibleCount);
        }

        function clearReportsFilters() {
            document.getElementById('reportsSearchStudent').value = '';
            document.getElementById('reportsFilterClass').value = '';
            document.getElementById('reportsFilterSubject').value = '';
            document.getElementById('reportsFilterGrade').value = '';
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
            
            const total = document.querySelectorAll('#reportsTable tbody tr').length;
            counter.textContent = `Showing ${count} of ${total} reports`;
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
            
            const total = document.querySelectorAll('#marksTable tbody tr').length;
            counter.textContent = `Showing ${count} of ${total} marks`;
        }

        // Initialize marks counter on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('marksTable')) {
                setTimeout(() => {
                    const totalRows = document.querySelectorAll('#marksTable tbody tr').length;
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
                    const totalRows = document.querySelectorAll('#reportsTable tbody tr').length;
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
                message.textContent = 'Please select an Academic Year first to see available subjects';
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
                message.textContent = 'Please select an Academic Year first to see available subjects';
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
                student_distribution: "Student Distribution",
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
                grade_distribution: "📈 Grade Distribution",
                subject_performance: "🎯 Subject Performance", 
                top_performers: "🏆 Top 3 Performers",
                performance_trends: "📈 Performance Trends",
                detailed_reports: "📋 Detailed Reports",
                export_csv: "📊 Export CSV",
                export_pdf: "📄 Export PDF",
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
                search_filter_marks: "🔍 Search & Filter Marks",
                add_mark: "Add Mark",
                student: "Student",
                subject: "Subject",
                status: "Status",
                midterm: "Midterm",
                final: "Final",
                daily: "Daily",
                total: "Total",
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
                filter_by_student: "Filter by Student",
                filter_by_status: "Filter by Status",
                all_status: "All Status",
                manage_subjects_curriculum: "Manage subjects and curriculum",
                phone_number: "Phone Number",
                enter_phone_number: "Enter phone number",
                add_student: "Add Student",
                reset_form: "Reset Form",
                students_list: "Students List",
                select_subjects_enroll: "Select subjects to enroll this student in (optional - can be done later)",
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
                reports_title: "نظام إدارة الطلاب - لوحة التقارير",
                students_title: "👥 إدارة الطلاب",
                subjects_title: "📚 إدارة المواد",
                marks_title: "📝 إدارة الدرجات", 
                year_1_students: "طلاب السنة الأولى",
                year_2_students: "طلاب السنة الثانية", 
                graduated_students: "الطلاب المتخرجون",
                active_students: "الطلاب النشطون",
                successfully_graduated: "تخرج بنجاح",
                top_performing_year: "أفضل سنة أداءً",
                student_distribution: "توزيع الطلاب",
                all_years: "جميع السنوات",
                student_status: "حالة الطالب",
                academic_year: "السنة الأكاديمية",
                select_academic_year: "اختر السنة الأكاديمية", 
                add_new_student: "➕ إضافة طالب جديد",
                add_new_subject: "➕ إضافة مادة جديدة",
                add_new_mark: "➕ إضافة درجة جديدة",
                add_subject: "إضافة مادة",
                search_filter: "🔍 البحث والتصفية",
                search_filter_reports: "🔍 البحث وتصفية التقارير",
                search_filter_students: "🔍 البحث وتصفية الطلاب",
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
                grade_distribution: "📈 توزيع الدرجات",
                subject_performance: "🎯 أداء المواد",
                top_performers: "🏆 أفضل 3 طلاب", 
                performance_trends: "📈 اتجاهات الأداء",
                detailed_reports: "📋 التقارير التفصيلية",
                export_csv: "📊 تصدير CSV",
                export_pdf: "📄 تصدير PDF", 
                print: "🖨️ طباعة",
                full_name: "الاسم الكامل *",
                name: "الاسم",
                age: "العمر *",
                gender: "الجنس *",
                class: "الصف *",
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
                search_filter_marks: "🔍 البحث وتصفية الدرجات",
                add_mark: "إضافة درجة",
                student: "الطالب",
                subject: "المادة",
                status: "الحالة",
                midterm: "منتصف الفصل",
                final: "النهائي",
                daily: "اليومي",
                total: "الإجمالي",
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
                filter_by_student: "تصفية حسب الطالب",
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
                reports_title: "سیستەمی بەڕێوەبردنی قوتابییان - داشبۆردی ڕاپۆرتەکان",
                students_title: "👥 بەڕێوەبردنی قوتابییەکان", 
                subjects_title: "📚 بەڕێوەبردنی وانەکان",
                marks_title: "📝 بەڕێوەبردنی نمرەکان",
                year_1_students: "قوتابییانی ساڵی یەکەم",
                year_2_students: "قوتابییانی ساڵی دووەم", 
                graduated_students: "قوتابییە دەرچووەکان",
                active_students: "قوتابییە چالاکەکان",
                successfully_graduated: "بە سەرکەوتوویی دەرچوو",
                top_performing_year: "ساڵی باشترین کارایی",
                student_distribution: "دابەشبوونی قوتابییەکان",
                all_years: "هەموو ساڵەکان",
                student_status: "بارودۆخی قوتابی",
                academic_year: "ساڵی زانستی",
                select_academic_year: "ساڵی زانستی هەڵبژێرە",
                add_new_student: "➕ زیادکردنی قوتابیی نوێ",
                add_new_subject: "➕ زیادکردنی وانەی نوێ",
                add_new_mark: "➕ زیادکردنی نمرەی نوێ",
                add_subject: "وانە زیادبکە",
                search_filter: "🔍 گەڕان و پاڵاوتن",
                search_filter_reports: "🔍 گەڕان و پاڵاوتنی ڕاپۆرتەکان",
                search_filter_students: "🔍 گەڕان و پاڵاوتنی قوتابییەکان",
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
                grade_distribution: "📈 دابەشکردنی نمرەکان",
                subject_performance: "🎯 کارایی وانەکان",
                top_performers: "🏆 باشترین 3 قوتابی",
                performance_trends: "📈 ئاراستەی کارایی", 
                detailed_reports: "📋 ڕاپۆرتە ورددەکان",
                export_csv: "📊 هەناردنی CSV",
                export_pdf: "📄 هەناردنی PDF",
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
                search_filter_marks: "🔍 گەڕان و پاڵاوتنی نمرەکان",
                add_mark: "نمرە زیادبکە",
                student: "قوتابی",
                subject: "وانە",
                status: "بارودۆخ",
                midterm: "ناوەڕاست",
                final: "کۆتایی",
                daily: "ڕۆژانە",
                total: "گشتی",
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
                filter_by_student: "پاڵاوتن بەپێی قوتابی",
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
            
            // Update Subject Performance Chart  
            if (window.subjectPerformanceChart) {
                try {
                    window.subjectPerformanceChart.destroy();
                } catch(e) {}
                createSubjectPerformanceChart();
            }
            
            // Update Top Performers Chart
            if (window.topPerformersChart) {
                try {
                    window.topPerformersChart.destroy();
                } catch(e) {}
                createTopPerformersChart();
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
    </script>
</body>
</html>