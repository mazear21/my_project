<?php include("db.php"); ?>
<!DOCTYPE html>
<html>
<head><title>Marks</title></head>
<body>
<h2>Enter Marks</h2>

<form method="POST">
  Student ID: <input type="number" name="student_id" required>
  Subject ID: <input type="number" name="subject_id" required>
  Mark: <input type="number" name="mark" required>
  <input type="submit" name="add" value="Save">
</form><br>

<?php
if (isset($_POST['add'])) {
    $student = $_POST['student_id'];
    $subject = $_POST['subject_id'];
    $mark = $_POST['mark'];
    $status = ($mark >= 50) ? 'Pass' : 'Fail';
    pg_query($conn, "INSERT INTO marks (student_id, subject_id, mark, status)
                     VALUES ($student, $subject, $mark, '$status')");
}

$result = pg_query($conn, "SELECT m.id, s.name, sub.subject_name, m.mark, m.status
                           FROM marks m
                           JOIN students s ON s.id = m.student_id
                           JOIN subjects sub ON sub.id = m.subject_id
                           ORDER BY m.id ASC");

echo "<table border='1' cellpadding='10'>
<tr><th>ID</th><th>Student</th><th>Subject</th><th>Mark</th><th>Status</th></tr>";
while ($r = pg_fetch_assoc($result)) {
    echo "<tr>
        <td>{$r['id']}</td>
        <td>{$r['name']}</td>
        <td>{$r['subject_name']}</td>
        <td>{$r['mark']}</td>
        <td>{$r['status']}</td>
    </tr>";
}
echo "</table>";
?>
</body>
</html>
