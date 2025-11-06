<?php
// Database connection
$host = 'localhost';
$port = '5432';
$dbname = 'school_management';
$user = 'postgres';
$password = 'admin';

$conn = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$password");

if (!$conn) {
    die("Connection failed: " . pg_last_error());
}

echo "Connected successfully to database!\n\n";

// Step 1: Add authentication columns to teachers table
echo "Step 1: Adding authentication columns to teachers table...\n";

$alter_teachers = "
    ALTER TABLE teachers 
    ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE,
    ADD COLUMN IF NOT EXISTS password VARCHAR(255),
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) DEFAULT 'teacher',
    ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT true,
    ADD COLUMN IF NOT EXISTS last_login TIMESTAMP
";

$result = pg_query($conn, $alter_teachers);
if ($result) {
    echo "✅ Successfully added authentication columns to teachers table\n\n";
} else {
    echo "❌ Error: " . pg_last_error($conn) . "\n\n";
}

// Step 2: Create admin user in teachers table
echo "Step 2: Creating admin user...\n";

// Check if admin already exists
$check_admin = "SELECT id FROM teachers WHERE username = 'admin'";
$admin_exists = pg_query($conn, $check_admin);

if (pg_num_rows($admin_exists) == 0) {
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    $create_admin = "
        INSERT INTO teachers (name, email, phone, specialization, degree, salary, join_date, username, password, role, is_active)
        VALUES ('System Administrator', 'admin@school.com', '0000000000', 'Administration', 'Master', 0, CURRENT_DATE, 'admin', '$admin_password', 'admin', true)
    ";
    
    $result = pg_query($conn, $create_admin);
    if ($result) {
        echo "✅ Admin user created successfully!\n";
        echo "   Username: admin\n";
        echo "   Password: admin123\n\n";
    } else {
        echo "❌ Error creating admin: " . pg_last_error($conn) . "\n\n";
    }
} else {
    echo "ℹ️  Admin user already exists\n\n";
}

// Step 3: Update existing teachers with default usernames and passwords
echo "Step 3: Creating default login credentials for existing teachers...\n";

$get_teachers = "SELECT id, name, email FROM teachers WHERE username IS NULL AND role != 'admin'";
$teachers_result = pg_query($conn, $get_teachers);

$updated_count = 0;

if ($teachers_result && pg_num_rows($teachers_result) > 0) {
    while ($teacher = pg_fetch_assoc($teachers_result)) {
        // Create username from email (before @) or from name
        $username = strtolower(str_replace(' ', '_', $teacher['name']));
        
        // Default password is "teacher123"
        $password = password_hash('teacher123', PASSWORD_DEFAULT);
        
        $update_teacher = "
            UPDATE teachers 
            SET username = $1, password = $2, role = 'teacher', is_active = true
            WHERE id = $3
        ";
        
        $result = pg_query_params($conn, $update_teacher, array($username, $password, $teacher['id']));
        
        if ($result) {
            echo "✅ Created login for: {$teacher['name']} (username: $username, password: teacher123)\n";
            $updated_count++;
        } else {
            echo "❌ Failed for: {$teacher['name']} - " . pg_last_error($conn) . "\n";
        }
    }
    
    echo "\n✅ Updated $updated_count teacher accounts\n\n";
} else {
    echo "ℹ️  No teachers need username updates\n\n";
}

// Step 4: Create sessions table for better session management
echo "Step 4: Creating sessions table...\n";

$create_sessions = "
    CREATE TABLE IF NOT EXISTS user_sessions (
        id SERIAL PRIMARY KEY,
        teacher_id INTEGER REFERENCES teachers(id) ON DELETE CASCADE,
        session_token VARCHAR(255) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT
    )
";

$result = pg_query($conn, $create_sessions);
if ($result) {
    echo "✅ Sessions table created successfully\n\n";
} else {
    echo "❌ Error creating sessions table: " . pg_last_error($conn) . "\n\n";
}

echo "========================================\n";
echo "🎉 Authentication setup complete!\n";
echo "========================================\n\n";

echo "📝 Default Login Credentials:\n";
echo "   Admin:\n";
echo "   - Username: admin\n";
echo "   - Password: admin123\n\n";
echo "   Teachers:\n";
echo "   - Username: [teacher_name_with_underscores]\n";
echo "   - Password: teacher123\n\n";

echo "⚠️  IMPORTANT: Change these default passwords after first login!\n";

pg_close($conn);
?>
