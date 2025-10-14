<?php
/**
 * Quick Database Backup Script
 * Run this file in your browser: http://localhost/my_project/quick_backup.php
 */

// Include database connection
include 'db.php';

// Create backups directory if it doesn't exist
$backup_dir = __DIR__ . '/backups';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

// Generate backup filename with timestamp
$backup_file = $backup_dir . '/db_backup_' . date('Y-m-d_H-i-s') . '.sql';

// Database connection details (update if needed)
$db_host = 'localhost';
$db_name = 'student_db';
$db_user = 'postgres';
$db_pass = '0998';

// Try to find pg_dump
$pg_dump_paths = [
    'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
    'C:\\Program Files\\PostgreSQL\\15\\bin\\pg_dump.exe',
    'C:\\Program Files\\PostgreSQL\\14\\bin\\pg_dump.exe',
    'C:\\Program Files\\PostgreSQL\\13\\bin\\pg_dump.exe',
    'pg_dump' // Try system PATH
];

$pg_dump = null;
foreach ($pg_dump_paths as $path) {
    if (file_exists($path) || $path === 'pg_dump') {
        $pg_dump = $path;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Database Backup</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            color: #667eea;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #155724;
        }
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #721c24;
        }
        .backup-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 5px;
            transition: transform 0.2s;
        }
        .backup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .secondary-btn {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .file-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin: 20px 0;
        }
        .file-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-item:last-child {
            border-bottom: none;
        }
        .file-name {
            font-family: 'Courier New', monospace;
            color: #333;
        }
        .file-size {
            color: #666;
            font-size: 0.9em;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>💾 Quick Database Backup</h1>
        <p class="subtitle">Backup your Student Management System database</p>

        <?php
        // Handle backup action
        if (isset($_POST['backup'])) {
            if ($pg_dump) {
                // Set PGPASSWORD environment variable
                putenv("PGPASSWORD=$db_pass");
                
                // Execute pg_dump
                $command = "\"$pg_dump\" -U $db_user -h $db_host $db_name > \"$backup_file\" 2>&1";
                exec($command, $output, $return_var);
                
                if ($return_var === 0 && file_exists($backup_file)) {
                    $file_size = filesize($backup_file);
                    $file_size_mb = number_format($file_size / 1024 / 1024, 2);
                    
                    echo "<div class='success-box'>";
                    echo "<h3>✅ Backup Successful!</h3>";
                    echo "<p><strong>File:</strong> " . basename($backup_file) . "</p>";
                    echo "<p><strong>Size:</strong> $file_size_mb MB</p>";
                    echo "<p><strong>Location:</strong> <code>$backup_file</code></p>";
                    echo "</div>";
                } else {
                    echo "<div class='error-box'>";
                    echo "<h3>❌ Backup Failed!</h3>";
                    echo "<p>Error executing pg_dump. Output:</p>";
                    echo "<pre>" . implode("\n", $output) . "</pre>";
                    echo "</div>";
                }
            } else {
                echo "<div class='error-box'>";
                echo "<h3>❌ pg_dump Not Found!</h3>";
                echo "<p>Please install PostgreSQL or specify the correct path.</p>";
                echo "</div>";
            }
        }

        // Handle SQL backup (using PHP)
        if (isset($_POST['sql_backup'])) {
            $tables = ['students', 'subjects', 'marks', 'graduated_students', 'promotion_history'];
            $sql_content = "-- Student Management System Database Backup\n";
            $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($tables as $table) {
                $sql_content .= "\n-- Table: $table\n";
                
                // Get table structure
                $create_query = "SELECT 'CREATE TABLE ' || quote_ident(tablename) || ' (' || 
                                array_to_string(array_agg(quote_ident(attname) || ' ' || format_type(atttypid, atttypmod)), ', ') || ');'
                                FROM pg_catalog.pg_attribute a
                                JOIN pg_catalog.pg_class c ON a.attrelid = c.oid
                                JOIN pg_catalog.pg_namespace n ON c.relnamespace = n.oid
                                WHERE c.relname = '$table' AND n.nspname = 'public' AND a.attnum > 0 AND NOT a.attisdropped
                                GROUP BY tablename";
                
                // Get table data
                $data_query = "SELECT * FROM $table";
                $result = pg_query($conn, $data_query);
                
                if ($result) {
                    $sql_content .= "\nDELETE FROM $table;\n";
                    
                    while ($row = pg_fetch_assoc($result)) {
                        $columns = array_keys($row);
                        $values = array_map(function($v) use ($conn) {
                            return $v === null ? 'NULL' : "'" . pg_escape_string($conn, $v) . "'";
                        }, array_values($row));
                        
                        $sql_content .= "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
                    }
                }
            }
            
            $sql_backup_file = $backup_dir . '/sql_backup_' . date('Y-m-d_H-i-s') . '.sql';
            file_put_contents($sql_backup_file, $sql_content);
            
            $file_size = filesize($sql_backup_file);
            $file_size_kb = number_format($file_size / 1024, 2);
            
            echo "<div class='success-box'>";
            echo "<h3>✅ SQL Backup Successful!</h3>";
            echo "<p><strong>File:</strong> " . basename($sql_backup_file) . "</p>";
            echo "<p><strong>Size:</strong> $file_size_kb KB</p>";
            echo "<p><strong>Location:</strong> <code>$sql_backup_file</code></p>";
            echo "</div>";
        }
        ?>

        <div class="info-box">
            <h3>📋 Backup Information</h3>
            <p><strong>Database:</strong> <?php echo $db_name; ?></p>
            <p><strong>Backup Directory:</strong> <code><?php echo $backup_dir; ?></code></p>
            <p><strong>pg_dump Status:</strong> <?php echo $pg_dump ? "✅ Found at: <code>$pg_dump</code>" : "❌ Not found"; ?></p>
        </div>

        <form method="post">
            <button type="submit" name="backup" class="backup-btn">
                🔧 Backup with pg_dump (Recommended)
            </button>
            <button type="submit" name="sql_backup" class="backup-btn secondary-btn">
                📝 Backup with PHP SQL Export
            </button>
        </form>

        <h3 style="margin-top: 40px;">📂 Existing Backups</h3>
        <div class="file-list">
            <?php
            $backups = glob($backup_dir . '/*.sql');
            if (empty($backups)) {
                echo "<p style='text-align: center; color: #999;'>No backups found</p>";
            } else {
                // Sort by modification time (newest first)
                usort($backups, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                
                foreach ($backups as $backup) {
                    $filename = basename($backup);
                    $filesize = filesize($backup);
                    $filesize_mb = number_format($filesize / 1024 / 1024, 2);
                    $filetime = date('Y-m-d H:i:s', filemtime($backup));
                    
                    echo "<div class='file-item'>";
                    echo "<div>";
                    echo "<div class='file-name'>$filename</div>";
                    echo "<div class='file-size'>$filesize_mb MB • $filetime</div>";
                    echo "</div>";
                    echo "<a href='backups/$filename' download class='backup-btn' style='padding: 8px 16px; font-size: 14px;'>⬇️ Download</a>";
                    echo "</div>";
                }
            }
            ?>
        </div>

        <div class="info-box">
            <h4>💡 Backup Tips:</h4>
            <ul>
                <li>Run backups daily, especially before making major changes</li>
                <li>Keep at least 7 days of backups</li>
                <li>Store backups in multiple locations (local + cloud)</li>
                <li>Test your backups by restoring them occasionally</li>
                <li>Use version control (Git) for code, separate backups for database</li>
            </ul>
        </div>

        <p style="text-align: center; margin-top: 30px; color: #666;">
            <a href="index.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
        </p>
    </div>
</body>
</html>
