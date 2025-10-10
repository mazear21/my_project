<?php include("db.php"); ?>
<!DOCTYPE html>
<html>
<head><title>Students</title></head>
<body>
<h2>Students</h2>
<form method="POST">
  Name: <input type="text" name="name" required>
  Email: <input type="email" name="email" required>
  Dept: <input type="text" name="department" required>
  <input type="submit" name="add" value="Add Student">
</form><br>

<?php
if (isset($_POST['add'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $dept = $_POST['department'];
    pg_query($conn, "INSERT INTO students (name, email, department) VALUES ('$name', '$email', '$dept')");
}

$result = pg_query($conn, "SELECT * FROM students ORDER BY id ASC");
echo "<table border='1' cellpadding='10'>
<tr><th>ID</th><th>Name</th><th>Email</th><th>Dept</th><th>Join Date</th></tr>";
while ($r = pg_fetch_assoc($result)) {
    echo "<tr>
        <td>{$r['id']}</td>
        <td>{$r['name']}</td>
        <td>{$r['email']}</td>
        <td>{$r['department']}</td>
        <td>{$r['join_date']}</td>
    </tr>";
}
echo "</table>";
?>
</body>
</html>
