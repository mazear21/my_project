<?php
session_start();

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
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
            
            $password_valid = false;
            $needs_hashing = false;
            
            // First try bcrypt verification (normal case)
            if (password_verify($password, $admin['password'])) {
                $password_valid = true;
            } 
            // If bcrypt fails, check if it's a plain text password (for pgAdmin additions)
            else if ($password === $admin['password']) {
                $password_valid = true;
                $needs_hashing = true;
            }
            
            if ($password_valid) {
                // If password needs hashing, update it now
                if ($needs_hashing) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    pg_query_params($conn, 
                        "UPDATE admin_users SET password = $1 WHERE id = $2", 
                        array($hashed_password, $admin['id'])
                    );
                }
                
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
                
                $password_valid = false;
                $needs_hashing = false;
                
                // First try bcrypt verification (normal case)
                if (password_verify($password, $teacher['password'])) {
                    $password_valid = true;
                } 
                // If bcrypt fails, check if it's a plain text password (for pgAdmin additions)
                else if ($password === $teacher['password']) {
                    $password_valid = true;
                    $needs_hashing = true;
                }
                
                if ($password_valid) {
                    // If password needs hashing, update it now
                    if ($needs_hashing) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        pg_query_params($conn, 
                            "UPDATE teachers SET password = $1 WHERE id = $2", 
                            array($hashed_password, $teacher['id'])
                        );
                    }
                    
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
                    
                    header('Location: index.php');
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
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-translate="page_title">Login - Academic Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0a0e1a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Animated laser beams background */
        body::before,
        body::after {
            content: '';
            position: absolute;
            width: 2px;
            height: 100%;
            background: linear-gradient(
                to bottom,
                transparent 0%,
                rgba(59, 130, 246, 0.8) 10%,
                rgba(59, 130, 246, 0.4) 50%,
                transparent 100%
            );
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.6),
                        0 0 40px rgba(59, 130, 246, 0.3);
            animation: beam-scan 8s ease-in-out infinite;
            z-index: 0;
        }
        
        body::before {
            left: 20%;
            animation-delay: 0s;
        }
        
        body::after {
            right: 20%;
            animation-delay: 4s;
        }
        
        @keyframes beam-scan {
            0%, 100% {
                opacity: 0.3;
                transform: translateY(-10%) scaleY(0.95);
            }
            50% {
                opacity: 1;
                transform: translateY(10%) scaleY(1.05);
            }
        }
        
        /* Subtle grid overlay */
        .grid-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(59, 130, 246, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: grid-move 20s linear infinite;
            z-index: 0;
        }
        
        @keyframes grid-move {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        
        .login-container {
            position: relative;
            width: 420px;
            padding: 50px 45px;
            background: rgba(15, 23, 42, 0.95);
            box-sizing: border-box;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
            border-radius: 15px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            animation: slideUp 0.6s ease-out;
            z-index: 1;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }
        
        .login-header h1 {
            color: #ffffff;
            font-size: 26px;
            margin-bottom: 8px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .login-header p {
            color: #94a3b8;
            font-size: 14px;
            font-weight: 400;
        }
        
        .logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        .form-group {
            position: relative;
            margin-bottom: 35px;
        }
        
        .form-group label {
            position: absolute;
            top: -20px;
            left: 0;
            padding: 0;
            font-size: 13px;
            color: #94a3b8;
            pointer-events: none;
            font-weight: 600;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 0;
            font-size: 16px;
            color: #ffffff;
            border: none;
            border-bottom: 2px solid #475569;
            outline: none;
            background: transparent;
            transition: border-bottom-color 0.3s ease;
            font-family: inherit;
        }
        
        .form-group input:focus {
            border-bottom-color: #3b82f6;
        }
        
        .form-group input:focus ~ label {
            color: #3b82f6;
        }
        
        .error-message {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            border-left: 4px solid #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            animation: shake 0.4s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .login-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 16px 30px;
            color: #ffffff;
            font-size: 17px;
            font-weight: 600;
            text-decoration: none;
            text-transform: uppercase;
            transition: all 0.3s ease;
            margin-top: 30px;
            letter-spacing: 1px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 8px;
            min-height: 56px;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }
        
        .login-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(59, 130, 246, 0.3);
        }
        
        .login-btn span {
            position: relative;
            z-index: 1;
        }
        
        .login-btn span:not([data-translate]) {
            display: none;
        }
        
        .info-section {
            margin-top: 35px;
            padding-top: 25px;
            border-top: 1px solid rgba(148, 163, 184, 0.2);
        }
        
        .info-section h3 {
            color: #cbd5e1;
            font-size: 13px;
            margin-bottom: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .user-types {
            display: flex;
            gap: 12px;
        }
        
        .user-type-badge {
            flex: 1;
            padding: 14px;
            background: rgba(30, 41, 59, 0.6);
            border-radius: 8px;
            text-align: center;
            font-size: 13px;
            border: 1px solid rgba(59, 130, 246, 0.2);
            transition: all 0.3s ease;
        }
        
        .user-type-badge:hover {
            background: rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }
        
        .user-type-badge strong {
            display: block;
            color: #3b82f6;
            margin-bottom: 6px;
            font-size: 14px;
        }
        
        .user-type-badge span {
            color: #94a3b8;
            font-size: 11px;
        }
        
        .toggle-password {
            position: absolute;
            right: 0;
            top: 8px;
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            font-size: 18px;
            padding: 5px;
            transition: color 0.3s ease;
        }
        
        .toggle-password:hover {
            color: #3b82f6;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid rgba(148, 163, 184, 0.15);
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <!-- Language Selector -->
    <div style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
        <select id="languageSelect" onchange="changeLanguage(this.value)" style="padding: 8px 15px; background: rgba(15, 23, 42, 0.95); color: #ffffff; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; cursor: pointer; font-size: 14px; backdrop-filter: blur(10px);">
            <option value="en">English</option>
            <option value="ar">العربية</option>
            <option value="ku">کوردی</option>
        </select>
    </div>

    <div class="login-container">
        <div class="login-header">
            <div class="logo" style="overflow: hidden;">
                <img src="photo_2025-11-12_21-42-15.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            </div>
            <h1 data-translate="system_title">Academic Management System</h1>
            <p data-translate="login_subtitle">Sign in to access your dashboard</p>
        </div>
        
        <?php if ($error_message): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <span data-translate="error_invalid_credentials"><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                    required
                    autofocus
                >
                <label for="username" data-translate="username">Username</label>
            </div>
            
            <div class="form-group">
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required
                >
                <label for="password" data-translate="password">Password</label>
                <button type="button" class="toggle-password" onclick="togglePassword()" tabindex="-1">
                    <i class="fas fa-eye" id="eye-icon"></i>
                </button>
            </div>
            
            <button type="submit" class="login-btn">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
                <span data-translate="sign_in">Sign In</span>
            </button>
        </form>
        
        <div class="info-section">
            <h3 data-translate="access_levels">Access Levels</h3>
            <div class="user-types">
                <div class="user-type-badge">
                    <strong><i class="fas fa-user-shield"></i> <span data-translate="administrator">Administrator</span></strong>
                    <span data-translate="full_system_control">Full System Control</span>
                </div>
                <div class="user-type-badge">
                    <strong><i class="fas fa-chalkboard-teacher"></i> <span data-translate="teacher">Teacher</span></strong>
                    <span data-translate="subject_management">Subject Management</span>
                </div>
            </div>
        </div>
        
        <div class="footer-text">
            <i class="fas fa-lock"></i> <span data-translate="secure_portal">Secure Academic Portal</span> &copy; <?= date('Y') ?>
        </div>
    </div>
    
    <script>
        // Translation system
        const translations = {
            en: {
                page_title: "Login - Academic Management System",
                system_title: "Academic Management System",
                login_subtitle: "Sign in to access your dashboard",
                username: "Username",
                password: "Password",
                sign_in: "Sign In",
                signing_in: "Signing In...",
                access_levels: "Access Levels",
                administrator: "Administrator",
                full_system_control: "Full System Control",
                teacher: "Teacher",
                subject_management: "Subject Management",
                secure_portal: "Secure Academic Portal",
                error_invalid_credentials: "Invalid username or password",
                error_empty_fields: "Please enter both username and password"
            },
            ar: {
                page_title: "تسجيل الدخول - نظام الإدارة الأكاديمية",
                system_title: "نظام الإدارة الأكاديمية",
                login_subtitle: "قم بتسجيل الدخول للوصول إلى لوحة التحكم",
                username: "اسم المستخدم",
                password: "كلمة المرور",
                sign_in: "تسجيل الدخول",
                signing_in: "جاري تسجيل الدخول...",
                access_levels: "مستويات الوصول",
                administrator: "المسؤول",
                full_system_control: "التحكم الكامل بالنظام",
                teacher: "المعلم",
                subject_management: "إدارة المواد",
                secure_portal: "بوابة أكاديمية آمنة",
                error_invalid_credentials: "اسم المستخدم أو كلمة المرور غير صحيحة",
                error_empty_fields: "الرجاء إدخال اسم المستخدم وكلمة المرور"
            },
            ku: {
                page_title: "چوونەژوورەوە - سیستەمی بەڕێوەبردنی ئەکادیمی",
                system_title: "سیستەمی بەڕێوەبردنی ئەکادیمی",
                login_subtitle: "چوونەژوورەوە بۆ دەستگەیشتن بە داشبۆرد",
                username: "ناوی بەکارهێنەر",
                password: "وشەی نهێنی",
                sign_in: "چوونەژوورەوە",
                signing_in: "چوونەژوورەوە...",
                access_levels: "ئاستەکانی دەستگەیشتن",
                administrator: "بەڕێوەبەر",
                full_system_control: "کۆنترۆڵی تەواوی سیستەم",
                teacher: "مامۆستا",
                subject_management: "بەڕێوەبردنی وانەکان",
                secure_portal: "دەروازەی ئەکادیمی پارێزراو",
                error_invalid_credentials: "ناوی بەکارهێنەر یان وشەی نهێنی هەڵەیە",
                error_empty_fields: "تکایە ناوی بەکارهێنەر و وشەی نهێنی بنووسە"
            }
        };

        let currentLang = localStorage.getItem('language') || 'en';

        function changeLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('language', lang);
            
            // Update HTML attributes
            document.documentElement.lang = lang;
            document.documentElement.dir = 'ltr';
            
            // Update all translatable elements
            document.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.getAttribute('data-translate');
                if (translations[lang] && translations[lang][key]) {
                    if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                        element.placeholder = translations[lang][key];
                    } else if (element.tagName === 'TITLE') {
                        element.textContent = translations[lang][key];
                    } else {
                        element.textContent = translations[lang][key];
                    }
                }
            });
            
            // Update language selector
            document.getElementById('languageSelect').value = lang;
        }

        // Initialize language on page load
        document.addEventListener('DOMContentLoaded', function() {
            changeLanguage(currentLang);
        });

        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                eyeIcon.className = 'fas fa-eye';
            }
        }
        
        // Auto-hide error message after 5 seconds
        const errorMsg = document.querySelector('.error-message');
        if (errorMsg) {
            setTimeout(() => {
                errorMsg.style.transition = 'opacity 0.5s, transform 0.5s';
                errorMsg.style.opacity = '0';
                errorMsg.style.transform = 'translateY(-10px)';
                setTimeout(() => errorMsg.remove(), 500);
            }, 5000);
        }
        
        // Add loading state to button on submit
        document.querySelector('form').addEventListener('submit', function() {
            const btn = document.querySelector('.login-btn');
            const signInText = btn.querySelector('[data-translate="sign_in"]');
            if (signInText) {
                signInText.textContent = translations[currentLang].signing_in;
            }
            btn.disabled = true;
        });
    </script>
</body>
</html>
