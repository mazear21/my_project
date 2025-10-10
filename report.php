<?php include("db.php"); ?>
<!DOCTYPE html>
<html>
<head><title>Reports</title></head>
<body>
<h2>Monthly Report</h2>

<?php
// Total passes and fails this month
$result = pg_query($conn, "
SELECT status, COUNT(*) AS count
FROM marks
WHERE date_trunc('month', CURRENT_DATE) = date_trunc('month', CURRENT_DATE)
GROUP BY status;
");

echo "<h3>Pass/Fail Count</h3><table border='1'><tr><th>Status</th><th>Count</th></tr>";
while ($r = pg_fetch_assoc($result)) {
    echo "<tr><td>{$r['status']}</td><td>{$r['count']}</td></tr>";
}
echo "</table>";

// New students this month
$students = pg_query($conn, "
SELECT COUNT(*) FROM students WHERE date_trunc('month', join_date) = date_trunc('month', CURRENT_DATE);
");
$count = pg_fetch_result($students, 0, 0);
echo "<h3>New Students This Month: $count</h3>";

// Subjects with most fails
$fails = pg_query($conn, "
SELECT sub.subject_name, COUNT(*) AS fail_count
FROM marks m
JOIN subjects sub ON sub.id = m.subject_id
WHERE status='Fail'
GROUP BY sub.subject_name
ORDER BY fail_count DESC
LIMIT 3;
");

echo "<h3>Subjects with Most Fails</h3><table border='1'><tr><th>Subject</th><th>Fails</th></tr>";
while ($f = pg_fetch_assoc($fails)) {
    echo "<tr><td>{$f['subject_name']}</td><td>{$f['fail_count']}</td></tr>";
}
echo "</table>";
?>
</body>
</html>
