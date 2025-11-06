<?php
session_start();
include 'db.php';

// Log the logout
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    pg_query_params($conn,
        "INSERT INTO audit_log (user_id, user_type, action, ip_address) 
         VALUES ($1, $2, 'logout', $3)",
        array($_SESSION['user_id'], $_SESSION['user_type'], $ip_address)
    );
    
    // Deactivate session
    if (isset($_SESSION['session_token'])) {
        pg_query_params($conn,
            "UPDATE login_sessions SET is_active = false WHERE session_token = $1",
            array($_SESSION['session_token'])
        );
    }
}

// Clear all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>
