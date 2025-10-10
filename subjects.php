<?php include("db.php"); ?>
<!DOCTYPE html>
<html>
<head><title>Subjects</title></head>
<body>
<h2>Subjects</h2>
<form method="POST">
  Subject Name: <input type="text" name="subject_name" required>
  <input type="submit" name="add" value="Add Subject">
</form><br>

<?php
if (isset($_POST['add'])) {
    $subject_name = $_POST['subject_name'];
    pg_query($conn, "INSERT INTO subjects (subject_name) VALUES ('$subject_name')");
}

$result = pg_query($conn, "SELECT * FROM subjects ORDER BY id ASC");
echo "<table border='1' cellpadding='10'>
<tr><th>ID</th><th>Subject</th></tr>";
while ($r = pg_fetch_assoc($result)) {
    echo "<tr><td>{$r['id']}</td><td>{$r['subject_name']}</td></tr>";
}
echo "</table>";
?>
</body>
</html>
