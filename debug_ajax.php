<?php
// Let's test the exact AJAX call and see what happens
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Direct AJAX Endpoint Debug</h1>";

// Database connection
$host = 'localhost';
$dbname = 'student_management_system';
$username = 'postgres';
$password = 'root';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p>✅ Database connected</p>";
    
    // Test the exact query that the AJAX endpoint should use
    $studentId = 18;
    echo "<h2>Testing Student ID: $studentId</h2>";
    
    // Get student data
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo "<p style='color: red;'>❌ Student not found in database</p>";
        exit;
    }
    
    echo "<h3>Raw Database Result:</h3>";
    echo "<pre>";
    print_r($student);
    echo "</pre>";
    
    // Get enrolled subjects
    $stmt = $pdo->prepare("SELECT subject_name FROM enrollments WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h3>Enrolled Subjects:</h3>";
    echo "<pre>";
    print_r($subjects);
    echo "</pre>";
    
    // Create the exact response that should be returned
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
    
    echo "<h3>JSON Response (what AJAX should get):</h3>";
    $json = json_encode($response);
    echo "<pre>$json</pre>";
    
    echo "<h3>Field by Field Analysis:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Database Value</th><th>JSON Value</th><th>Type</th><th>Empty?</th></tr>";
    
    foreach ($response as $key => $value) {
        if ($key !== 'enrolled_subjects') {
            $dbValue = $student[$key] ?? 'N/A';
            $jsonValue = $value;
            $type = gettype($value);
            $isEmpty = empty($value) ? 'YES' : 'NO';
            
            echo "<tr>";
            echo "<td>$key</td>";
            echo "<td>" . htmlspecialchars($dbValue) . "</td>";
            echo "<td>" . htmlspecialchars($jsonValue) . "</td>";
            echo "<td>$type</td>";
            echo "<td>$isEmpty</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    
    // Test if any values are actually null/undefined
    echo "<h3>Null/Empty Check:</h3>";
    foreach ($response as $key => $value) {
        if ($key !== 'enrolled_subjects') {
            if (is_null($value)) {
                echo "<p style='color: red;'>❌ $key is NULL</p>";
            } elseif ($value === '') {
                echo "<p style='color: orange;'>⚠️ $key is empty string</p>";
            } elseif (empty($value)) {
                echo "<p style='color: orange;'>⚠️ $key is empty (but not null)</p>";
            } else {
                echo "<p style='color: green;'>✅ $key has value: '$value'</p>";
            }
        }
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}
?>

<h3>Test AJAX Call</h3>
<button onclick="testRealAjax()">Test Real AJAX Call</button>
<div id="ajax-test-result"></div>

<script>
function testRealAjax() {
    const resultDiv = document.getElementById('ajax-test-result');
    resultDiv.innerHTML = '<p>Testing real AJAX call...</p>';
    
    fetch('index.php?ajax=get_student&id=18')
        .then(response => response.text())
        .then(text => {
            console.log('Real AJAX response:', text);
            resultDiv.innerHTML = '<h4>Real AJAX Response:</h4><pre>' + text + '</pre>';
            
            // Try to parse it
            try {
                const data = JSON.parse(text);
                resultDiv.innerHTML += '<h4>Parsed Successfully:</h4><pre>' + JSON.stringify(data, null, 2) + '</pre>';
                
                // Check each field
                const fields = ['id', 'name', 'email', 'age', 'gender', 'class_level', 'academic_year', 'phone', 'address', 'graduation_status'];
                resultDiv.innerHTML += '<h4>Field Analysis:</h4>';
                fields.forEach(field => {
                    const value = data[field];
                    const status = (value === null || value === undefined) ? '❌ NULL/UNDEFINED' : `✅ "${value}"`;
                    resultDiv.innerHTML += `<p>${field}: ${status}</p>`;
                });
                
            } catch (e) {
                resultDiv.innerHTML += '<p style="color: red;">JSON Parse Error: ' + e.message + '</p>';
            }
        })
        .catch(error => {
            resultDiv.innerHTML += '<p style="color: red;">AJAX Error: ' + error + '</p>';
        });
}
</script>