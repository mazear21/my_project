<?php
// Complete debugging script for the edit issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Complete Student Edit Debug</h1>";

// Database connection
$host = 'localhost';
$dbname = 'student_management_system';
$username = 'postgres';
$password = 'root';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Database connected<br>";
    
    // Test student 18 specifically
    $studentId = 18;
    echo "<h2>Testing Student ID: $studentId (mariwan khalid)</h2>";
    
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo "<h3>Database Record:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        foreach ($student as $key => $value) {
            echo "<tr><td><strong>$key</strong></td><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        echo "</table>";
        
        // Get enrolled subjects
        $stmt = $pdo->prepare("SELECT subject_name FROM enrollments WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "<h3>Enrolled Subjects:</h3>";
        echo "<pre>" . print_r($subjects, true) . "</pre>";
        
        // Test the exact AJAX endpoint
        echo "<h3>AJAX Endpoint Test:</h3>";
        echo "<button onclick=\"testRealAjax()\">Test Real AJAX Call</button>";
        echo "<div id='ajax-test-result'></div>";
        
        // Show what the AJAX should return
        $response = [
            'id' => $student['id'],
            'name' => $student['name'],
            'email' => $student['email'],
            'age' => $student['age'],
            'gender' => $student['gender'],
            'class_level' => $student['class_level'],
            'academic_year' => $student['academic_year'],
            'phone' => $student['phone'],
            'address' => $student['address'],
            'graduation_status' => $student['graduation_status'],
            'enrolled_subjects' => $subjects
        ];
        
        echo "<h3>Expected AJAX Response:</h3>";
        echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
        
    } else {
        echo "❌ Student not found!";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage();
}
?>

<script>
function testRealAjax() {
    console.log('Testing real AJAX endpoint...');
    
    fetch('index.php?ajax=get_student&id=18')
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', [...response.headers.entries()]);
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            console.log('Response length:', text.length);
            console.log('First 100 chars:', text.substring(0, 100));
            
            document.getElementById('ajax-test-result').innerHTML = 
                '<h4>Raw AJAX Response:</h4><pre>' + text + '</pre>';
            
            // Try to parse JSON
            try {
                const data = JSON.parse(text);
                console.log('Parsed successfully:', data);
                
                document.getElementById('ajax-test-result').innerHTML += 
                    '<h4>Parsed JSON:</h4><pre>' + JSON.stringify(data, null, 2) + '</pre>';
                    
                // Check for undefined values
                const undefinedFields = [];
                for (const [key, value] of Object.entries(data)) {
                    if (value === undefined || value === null || value === 'undefined') {
                        undefinedFields.push(key);
                    }
                }
                
                if (undefinedFields.length > 0) {
                    document.getElementById('ajax-test-result').innerHTML += 
                        '<p style="color: red;">⚠️ Fields with undefined/null values: ' + undefinedFields.join(', ') + '</p>';
                } else {
                    document.getElementById('ajax-test-result').innerHTML += 
                        '<p style="color: green;">✓ All fields have valid values</p>';
                }
                
            } catch (e) {
                console.error('JSON parse failed:', e);
                document.getElementById('ajax-test-result').innerHTML += 
                    '<p style="color: red;">❌ JSON Parse Error: ' + e.message + '</p>';
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            document.getElementById('ajax-test-result').innerHTML = 
                '<p style="color: red;">❌ Fetch Error: ' + error + '</p>';
        });
}
</script>