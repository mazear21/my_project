<?php
session_start();

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] === 'admin') {
        header('Location: index.php');
    } else {
        header('Location: teacher_dashboard.php');
    }
    exit;
}

include 'db.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password';
    } else {
        // Try admin login first
        $admin_query = "SELECT * FROM admin_users WHERE username = $1 AND is_active = true";
        $admin_result = pg_query_params($conn, $admin_query, array($username));
        
        if ($admin_result && pg_num_rows($admin_result) > 0) {
            $admin = pg_fetch_assoc($admin_result);
            
            if (password_verify($password, $admin['password'])) {
                // Admin login successful
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['user_type'] = 'admin';
                $_SESSION['username'] = $admin['username'];
                $_SESSION['full_name'] = $admin['full_name'];
                
                // Update last login
                pg_query_params($conn, "UPDATE admin_users SET last_login = NOW() WHERE id = $1", array($admin['id']));
                
                // Create session token
                $session_token = bin2hex(random_bytes(32));
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                
                pg_query_params($conn, 
                    "INSERT INTO login_sessions (user_id, user_type, session_token, ip_address, user_agent) 
                     VALUES ($1, 'admin', $2, $3, $4)",
                    array($admin['id'], $session_token, $ip_address, $user_agent)
                );
                
                $_SESSION['session_token'] = $session_token;
                
                // Log the login
                pg_query_params($conn,
                    "INSERT INTO audit_log (user_id, user_type, action, ip_address) 
                     VALUES ($1, 'admin', 'login', $2)",
                    array($admin['id'], $ip_address)
                );
                
                header('Location: index.php');
                exit;
            } else {
                $error_message = 'Invalid username or password';
            }
        } else {
            // Try teacher login
            $teacher_query = "SELECT * FROM teachers WHERE username = $1 AND is_active = true";
            $teacher_result = pg_query_params($conn, $teacher_query, array($username));
            
            if ($teacher_result && pg_num_rows($teacher_result) > 0) {
                $teacher = pg_fetch_assoc($teacher_result);
                
                if (password_verify($password, $teacher['password'])) {
                    // Teacher login successful
                    $_SESSION['user_id'] = $teacher['id'];
                    $_SESSION['user_type'] = 'teacher';
                    $_SESSION['username'] = $teacher['username'];
                    $_SESSION['full_name'] = $teacher['name'];
                    $_SESSION['teacher_id'] = $teacher['id'];
                    
                    // Update last login
                    pg_query_params($conn, "UPDATE teachers SET last_login = NOW() WHERE id = $1", array($teacher['id']));
                    
                    // Create session token
                    $session_token = bin2hex(random_bytes(32));
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];
                    
                    pg_query_params($conn, 
                        "INSERT INTO login_sessions (user_id, user_type, session_token, ip_address, user_agent) 
                         VALUES ($1, 'teacher', $2, $3, $4)",
                        array($teacher['id'], $session_token, $ip_address, $user_agent)
                    );
                    
                    $_SESSION['session_token'] = $session_token;
                    
                    // Log the login
                    pg_query_params($conn,
                        "INSERT INTO audit_log (user_id, user_type, action, ip_address) 
                         VALUES ($1, 'teacher', 'login', $2)",
                        array($teacher['id'], $ip_address)
                    );
                    
                    header('Location: teacher_dashboard.php');
                    exit;
                } else {
                    $error_message = 'Invalid username or password';
                }
            } else {
                $error_message = 'Invalid username or password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - School Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px;
            width: 100%;
            max-width: 450px;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .info-section {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e0e0e0;
        }
        
        .info-section h3 {
            color: #333;
            font-size: 14px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .user-types {
            display: flex;
            gap: 10px;
        }
        
        .user-type-badge {
            flex: 1;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
            font-size: 13px;
        }
        
        .user-type-badge strong {
            display: block;
            color: #667eea;
            margin-bottom: 4px;
        }
        
        .user-type-badge span {
            color: #666;
            font-size: 11px;
        }
        
        .password-toggle {
            position: relative;
        }
        
        .password-toggle input {
            padding-right: 45px;
        }
        
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 18px;
            padding: 5px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🎓</div>
            <h1>School Management System</h1>
            <p>Sign in to continue</p>
        </div>
        
        <?php if ($error_message): ?>
            <div class="error-message">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    placeholder="Enter your username"
                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                    required
                    autofocus
                >
            </div>
            
            <div class="form-group password-toggle">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Enter your password"
                    required
                >
                <button type="button" class="toggle-password" onclick="togglePassword()">👁️</button>
            </div>
            
            <button type="submit" class="login-btn">Sign In</button>
        </form>
        
        <div class="info-section">
            <h3>User Types:</h3>
            <div class="user-types">
                <div class="user-type-badge">
                    <strong>Admin</strong>
                    <span>Full System Access</span>
                </div>
                <div class="user-type-badge">
                    <strong>Teacher</strong>
                    <span>Assigned Subjects Only</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-password');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.textContent = '👁️‍🗨️';
            } else {
                passwordInput.type = 'password';
                toggleBtn.textContent = '👁️';
            }
        }
        
        // Auto-hide error message after 5 seconds
        const errorMsg = document.querySelector('.error-message');
        if (errorMsg) {
            setTimeout(() => {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.remove(), 500);
            }, 5000);
        }
    </script>
</body>
</html>
