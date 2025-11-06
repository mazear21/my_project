<?php
// Authentication Check - Include this at the top of protected pages

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Location: login.php');
    exit;
}

// Verify session is still valid
if (isset($_SESSION['session_token'])) {
    include_once 'db.php';
    
    $session_check = pg_query_params($conn,
        "SELECT is_active FROM login_sessions WHERE session_token = $1 AND user_id = $2 AND user_type = $3",
        array($_SESSION['session_token'], $_SESSION['user_id'], $_SESSION['user_type'])
    );
    
    if (!$session_check || pg_num_rows($session_check) === 0) {
        // Invalid session, force logout
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    $session_data = pg_fetch_assoc($session_check);
    if (!$session_data['is_active']) {
        // Session deactivated, force logout
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    // Update last activity
    pg_query_params($conn,
        "UPDATE login_sessions SET last_activity = NOW() WHERE session_token = $1",
        array($_SESSION['session_token'])
    );
}

// Helper function to check if user is admin
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Helper function to check if user is teacher
function isTeacher() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'teacher';
}

// Helper function to check if teacher can edit specific subject
function canEditSubject($teacher_id, $subject_id) {
    global $conn;
    
    if (isAdmin()) {
        return true; // Admin can edit everything
    }
    
    if (!isTeacher()) {
        return false;
    }
    
    // Check if teacher is assigned to this subject
    $check_query = "SELECT COUNT(*) as count FROM teacher_subjects WHERE teacher_id = $1 AND subject_id = $2";
    $result = pg_query_params($conn, $check_query, array($teacher_id, $subject_id));
    
    if ($result) {
        $row = pg_fetch_assoc($result);
        return $row['count'] > 0;
    }
    
    return false;
}

// Helper function to check if teacher can edit marks for specific student
function canEditStudentMarks($teacher_id, $student_id, $subject_id) {
    global $conn;
    
    if (isAdmin()) {
        return true; // Admin can edit everything
    }
    
    if (!isTeacher()) {
        return false;
    }
    
    // Check if teacher teaches this subject
    if (!canEditSubject($teacher_id, $subject_id)) {
        return false;
    }
    
    // Check if student is enrolled in this subject
    $check_query = "SELECT COUNT(*) as count FROM marks WHERE student_id = $1 AND subject_id = $2";
    $result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
    
    if ($result) {
        $row = pg_fetch_assoc($result);
        return $row['count'] > 0;
    }
    
    return false;
}

// Helper function to log admin actions
function logAction($action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    global $conn;
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        return;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    $old_values_json = $old_values ? json_encode($old_values) : null;
    $new_values_json = $new_values ? json_encode($new_values) : null;
    
    pg_query_params($conn,
        "INSERT INTO audit_log (user_id, user_type, action, table_name, record_id, old_values, new_values, ip_address) 
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8)",
        array(
            $_SESSION['user_id'],
            $_SESSION['user_type'],
            $action,
            $table_name,
            $record_id,
            $old_values_json,
            $new_values_json,
            $ip_address
        )
    );
}
?>
