<?php
// Direct test of the get_student endpoint
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Direct Student Data Test</h1>";

// Include the database connection
$host = 'localhost';
$dbname = 'student_management_system';
$username = 'postgres';
$password = 'root';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Database connected successfully<br><br>";
    
    // Test the specific student we know exists
    $studentId = 18; // mariwan khalid
    
    echo "<h2>Testing Student ID: $studentId</h2>";
    
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo "<h3>Raw Database Data:</h3>";
        echo "<pre>";
        print_r($student);
        echo "</pre>";
        
        echo "<h3>JSON Response (what AJAX should get):</h3>";
        
        // Get enrolled subjects
        $stmt = $pdo->prepare("SELECT subject_name FROM enrollments WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
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
        
        echo "<pre>";
        echo json_encode($response, JSON_PRETTY_PRINT);
        echo "</pre>";
        
        echo "<h3>Test AJAX Call:</h3>";
        echo "<button onclick=\"testAjaxCall($studentId)\">Test AJAX Get Student</button>";
        echo "<div id='ajax-result'></div>";
        
    } else {
        echo "❌ Student not found with ID: $studentId";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage();
}
?>

<script>
function testAjaxCall(studentId) {
    console.log('Testing AJAX call for student:', studentId);
    
    fetch(`index.php?ajax=get_student&id=${studentId}`)
        .then(response => {
            console.log('Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Raw AJAX response:', text);
            document.getElementById('ajax-result').innerHTML = 
                '<h4>AJAX Response:</h4><pre>' + text + '</pre>';
            
            try {
                const data = JSON.parse(text);
                console.log('Parsed AJAX data:', data);
            } catch (e) {
                console.error('JSON parse error:', e);
                document.getElementById('ajax-result').innerHTML += 
                    '<p style="color: red;">JSON Parse Error: ' + e.message + '</p>';
            }
        })
        .catch(error => {
            console.error('AJAX error:', error);
            document.getElementById('ajax-result').innerHTML = 
                '<p style="color: red;">AJAX Error: ' + error + '</p>';
        });
}
</script>