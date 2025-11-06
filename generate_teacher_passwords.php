<?php
// Generate passwords for existing teachers who don't have login credentials

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
echo "🔍 Finding teachers without login credentials...\n\n";

// Get all teachers without username/password
$query = "SELECT id, name, email FROM teachers WHERE username IS NULL OR password IS NULL";
$result = pg_query($conn, $query);

if (!$result) {
    die("❌ Query failed: " . pg_last_error($conn));
}

$count = pg_num_rows($result);

if ($count === 0) {
    echo "✅ All teachers already have login credentials!\n";
    exit;
}

echo "📝 Found $count teachers without credentials. Generating now...\n\n";
echo "========================================\n";

pg_query($conn, "BEGIN");

$credentials = [];

while ($teacher = pg_fetch_assoc($result)) {
    $teacher_id = $teacher['id'];
    $name = $teacher['name'];
    $email = $teacher['email'];
    
    // Generate username from name
    $username = strtolower(str_replace(' ', '.', $name)) . '@school.edu';
    
    // Check if username exists
    $check = pg_query_params($conn, "SELECT id FROM teachers WHERE username = $1", array($username));
    if (pg_num_rows($check) > 0) {
        // Add random number if exists
        $username = strtolower(str_replace(' ', '.', $name)) . rand(100, 999) . '@school.edu';
    }
    
    // Generate password
    $temp_password = 'teacher' . rand(1000, 9999);
    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
    
    // Update teacher
    $update_query = "UPDATE teachers SET username = $1, password = $2, role = 'teacher', is_active = true WHERE id = $3";
    $update_result = pg_query_params($conn, $update_query, array($username, $hashed_password, $teacher_id));
    
    if ($update_result) {
        echo "✅ Teacher: $name\n";
        echo "   📧 Username: $username\n";
        echo "   🔑 Password: $temp_password\n";
        echo "   Email: $email\n";
        echo "   ─────────────────────────────\n";
        
        $credentials[] = [
            'name' => $name,
            'username' => $username,
            'password' => $temp_password,
            'email' => $email
        ];
    } else {
        echo "❌ Failed to update teacher: $name\n";
    }
}

pg_query($conn, "COMMIT");

echo "========================================\n";
echo "✅ Generated credentials for $count teachers!\n\n";

echo "📋 CREDENTIALS SUMMARY:\n";
echo "Copy and share these securely with your teachers:\n\n";

foreach ($credentials as $cred) {
    echo "Teacher: {$cred['name']}\n";
    echo "Username: {$cred['username']}\n";
    echo "Password: {$cred['password']}\n";
    echo "Email: {$cred['email']}\n";
    echo "\n";
}

echo "⚠️  IMPORTANT: Teachers should change their passwords on first login!\n";

pg_close($conn);
?>
