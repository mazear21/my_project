<?php
/**
 * Password Hash Generator for Admin Users
 * 
 * Use this script to generate bcrypt hashed passwords for manual admin user creation in pgAdmin
 * 
 * IMPORTANT: After using this script, delete it or move it outside the web directory for security
 */

include 'db.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Password Hash Generator</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .section {
            margin: 25px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        h2 {
            color: #3b82f6;
            margin-top: 0;
            font-size: 18px;
        }
        input[type='text'], input[type='password'] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 10px 0;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            background: #3b82f6;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            margin-top: 10px;
        }
        button:hover {
            background: #2563eb;
        }
        .result {
            background: #e0f2fe;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border: 1px solid #0ea5e9;
            word-break: break-all;
            font-family: monospace;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .success {
            background: #d4edda;
            border: 1px solid #28a745;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        code {
            background: #f1f1f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        table th, table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        table th {
            background: #3b82f6;
            color: white;
        }
        table tr:nth-child(even) {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Password Hash Generator & Admin User Management</h1>";

// Check current admin users
echo "<div class='section'>
    <h2>Current Admin Users</h2>";
    
$admin_check = pg_query($conn, "SELECT id, username, full_name, email, is_active, last_login FROM admin_users ORDER BY id");

if ($admin_check && pg_num_rows($admin_check) > 0) {
    echo "<table>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Active</th>
            <th>Last Login</th>
        </tr>";
    
    while ($admin = pg_fetch_assoc($admin_check)) {
        $active = $admin['is_active'] == 't' ? 'Yes' : 'No';
        $last_login = $admin['last_login'] ?? 'Never';
        echo "<tr>
            <td>{$admin['id']}</td>
            <td><strong>{$admin['username']}</strong></td>
            <td>{$admin['full_name']}</td>
            <td>{$admin['email']}</td>
            <td>{$active}</td>
            <td>{$last_login}</td>
        </tr>";
    }
    echo "</table>";
} else {
    echo "<p>No admin users found in database.</p>";
}

echo "</div>";

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $admin_id = (int)$_POST['admin_id'];
    $new_password = $_POST['new_password'];
    
    if (!empty($admin_id) && !empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $update_query = "UPDATE admin_users SET password = $1 WHERE id = $2";
        $result = pg_query_params($conn, $update_query, array($hashed_password, $admin_id));
        
        if ($result) {
            echo "<div class='success'>
                <strong>Success!</strong> Password updated for Admin ID {$admin_id}<br>
                <strong>Username:</strong> " . htmlspecialchars($_POST['username']) . "<br>
                <strong>New Password:</strong> " . htmlspecialchars($new_password) . "<br>
                <strong>Hashed:</strong> {$hashed_password}
            </div>";
        } else {
            echo "<div class='warning'><strong>Error:</strong> Failed to update password - " . pg_last_error($conn) . "</div>";
        }
    }
}

// Handle password generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_hash'])) {
    $password = $_POST['password'];
    if (!empty($password)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        echo "<div class='section'>
            <h2>Generated Hash</h2>
            <p><strong>Plain Password:</strong> " . htmlspecialchars($password) . "</p>
            <p><strong>Bcrypt Hash:</strong></p>
            <div class='result'>{$hashed}</div>
            <p>Use this hash in your SQL UPDATE query:</p>
            <div class='result'>UPDATE admin_users SET password = '{$hashed}' WHERE id = YOUR_ADMIN_ID;</div>
        </div>";
    }
}

echo "<div class='section'>
    <h2>Option 1: Fix Existing Admin Password (Recommended)</h2>
    <p>Update the password for Admin ID 4 (or any admin) to a properly hashed password:</p>
    <form method='POST'>
        <label><strong>Admin ID:</strong></label>
        <input type='text' name='admin_id' value='4' required>
        
        <label><strong>Username (for reference):</strong></label>
        <input type='text' name='username' placeholder='Enter username for confirmation'>
        
        <label><strong>New Password:</strong></label>
        <input type='password' name='new_password' required placeholder='Enter new password'>
        
        <button type='submit' name='update_password'>Update Admin Password</button>
    </form>
</div>

<div class='section'>
    <h2>Option 2: Generate Hash Manually</h2>
    <p>Generate a bcrypt hash for a password, then manually update the database:</p>
    <form method='POST'>
        <label><strong>Password to Hash:</strong></label>
        <input type='password' name='password' required placeholder='Enter password to hash'>
        <button type='submit' name='generate_hash'>Generate Hash</button>
    </form>
</div>

<div class='warning'>
    <strong>Security Warning:</strong> Delete this file after use! This script should not be accessible in production.<br>
    Run it locally and then remove it: <code>c:\\xampp\\htdocs\\my_project\\hash_password.php</code>
</div>

<div class='section'>
    <h2>How to Create Admin User Properly</h2>
    <p>When creating admin users in pgAdmin, always hash the password first:</p>
    <ol>
        <li>Use this tool to generate a hashed password</li>
        <li>Insert into database with the hashed password:
            <pre style='background:#f1f1f1; padding:10px; margin:10px 0;'>
INSERT INTO admin_users (username, password, full_name, email, role, is_active)
VALUES ('admin', '[HASHED_PASSWORD]', 'Administrator', 'admin@school.edu', 'super_admin', true);</pre>
        </li>
    </ol>
</div>

</div>
</body>
</html>";

pg_close($conn);
?>
