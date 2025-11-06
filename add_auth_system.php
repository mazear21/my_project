<?php
// Database Migration: Add Authentication System
// This adds login credentials for teachers and creates admin user

// Database connection
$host = "localhost";
$port = "5432";
$dbname = "student_db";
$user = "postgres";
$password = "0998";

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
$conn = pg_connect($conn_string);

if (!$conn) {
    die("❌ Database connection failed: " . pg_last_error());
}

echo "✅ Connected to database successfully!\n\n";

// Start transaction
pg_query($conn, "BEGIN");

try {
    // Step 1: Add auth columns to teachers table
    echo "📝 Step 1: Adding authentication columns to teachers table...\n";
    
    $add_columns_query = "
        ALTER TABLE teachers 
        ADD COLUMN IF NOT EXISTS username VARCHAR(100) UNIQUE,
        ADD COLUMN IF NOT EXISTS password VARCHAR(255),
        ADD COLUMN IF NOT EXISTS role VARCHAR(20) DEFAULT 'teacher',
        ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT true,
        ADD COLUMN IF NOT EXISTS last_login TIMESTAMP,
        ADD COLUMN IF NOT EXISTS created_by INTEGER,
        ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ";
    
    if (pg_query($conn, $add_columns_query)) {
        echo "   ✅ Authentication columns added successfully!\n\n";
    } else {
        throw new Exception("Failed to add columns: " . pg_last_error($conn));
    }
    
    // Step 2: Create admin user table
    echo "📝 Step 2: Creating admin users table...\n";
    
    $create_admin_table = "
        CREATE TABLE IF NOT EXISTS admin_users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(200) NOT NULL,
            email VARCHAR(200) UNIQUE NOT NULL,
            role VARCHAR(20) DEFAULT 'admin',
            is_active BOOLEAN DEFAULT true,
            last_login TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";
    
    if (pg_query($conn, $create_admin_table)) {
        echo "   ✅ Admin users table created successfully!\n\n";
    } else {
        throw new Exception("Failed to create admin table: " . pg_last_error($conn));
    }
    
    // Step 3: Create default admin account
    echo "📝 Step 3: Creating default admin account...\n";
    
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    $create_admin = "
        INSERT INTO admin_users (username, password, full_name, email, role, is_active)
        VALUES ('admin', '$admin_password', 'System Administrator', 'admin@school.edu', 'admin', true)
        ON CONFLICT (username) DO NOTHING
    ";
    
    if (pg_query($conn, $create_admin)) {
        echo "   ✅ Default admin account created!\n";
        echo "   📧 Username: admin\n";
        echo "   🔑 Password: admin123\n";
        echo "   ⚠️  IMPORTANT: Change this password after first login!\n\n";
    } else {
        throw new Exception("Failed to create admin: " . pg_last_error($conn));
    }
    
    // Step 4: Create login sessions table
    echo "📝 Step 4: Creating login sessions table...\n";
    
    $create_sessions_table = "
        CREATE TABLE IF NOT EXISTS login_sessions (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            user_type VARCHAR(20) NOT NULL, -- 'admin' or 'teacher'
            session_token VARCHAR(255) UNIQUE NOT NULL,
            ip_address VARCHAR(50),
            user_agent TEXT,
            login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT true
        )
    ";
    
    if (pg_query($conn, $create_sessions_table)) {
        echo "   ✅ Login sessions table created successfully!\n\n";
    } else {
        throw new Exception("Failed to create sessions table: " . pg_last_error($conn));
    }
    
    // Step 5: Create audit log table
    echo "📝 Step 5: Creating audit log table...\n";
    
    $create_audit_table = "
        CREATE TABLE IF NOT EXISTS audit_log (
            id SERIAL PRIMARY KEY,
            user_id INTEGER,
            user_type VARCHAR(20), -- 'admin' or 'teacher'
            action VARCHAR(100) NOT NULL,
            table_name VARCHAR(100),
            record_id INTEGER,
            old_values TEXT,
            new_values TEXT,
            ip_address VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";
    
    if (pg_query($conn, $create_audit_table)) {
        echo "   ✅ Audit log table created successfully!\n\n";
    } else {
        throw new Exception("Failed to create audit table: " . pg_last_error($conn));
    }
    
    // Commit transaction
    pg_query($conn, "COMMIT");
    
    echo "\n========================================\n";
    echo "✅ AUTHENTICATION SYSTEM INSTALLED SUCCESSFULLY!\n";
    echo "========================================\n\n";
    
    echo "📋 Summary:\n";
    echo "   ✅ Teachers table updated with auth columns\n";
    echo "   ✅ Admin users table created\n";
    echo "   ✅ Default admin account created\n";
    echo "   ✅ Login sessions table created\n";
    echo "   ✅ Audit log table created\n\n";
    
    echo "🔐 Default Admin Credentials:\n";
    echo "   Username: admin\n";
    echo "   Password: admin123\n\n";
    
    echo "📝 Next Steps:\n";
    echo "   1. Admin can now create teacher accounts from the admin panel\n";
    echo "   2. Teachers will receive unique usernames (e.g., ahmed.ali@school.edu)\n";
    echo "   3. Admin can activate/deactivate teacher accounts anytime\n";
    echo "   4. Teachers can only edit marks for their assigned subjects\n\n";
    
} catch (Exception $e) {
    // Rollback on error
    pg_query($conn, "ROLLBACK");
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "⚠️  All changes have been rolled back.\n";
}

pg_close($conn);
?>
