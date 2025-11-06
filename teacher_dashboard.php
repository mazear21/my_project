<?php
// Teacher Dashboard - Teachers can only view and edit their assigned subjects/students
include 'auth_check.php';

// Ensure only teachers can access this page
if (!isTeacher()) {
    header('Location: index.php');
    exit;
}

include 'db.php';

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['full_name'];

// Get teacher details
$teacher_query = "SELECT * FROM teachers WHERE id = $1";
$teacher_result = pg_query_params($conn, $teacher_query, array($teacher_id));
$teacher = pg_fetch_assoc($teacher_result);

// Get assigned subjects
$subjects_query = "
    SELECT 
        s.id,
        s.subject_name,
        s.year,
        s.credits,
        ts.class_level,
        COUNT(DISTINCT m.student_id) as student_count
    FROM teacher_subjects ts
    JOIN subjects s ON ts.subject_id = s.id
    LEFT JOIN marks m ON s.id = m.subject_id
    WHERE ts.teacher_id = $1
    GROUP BY s.id, s.subject_name, s.year, s.credits, ts.class_level
    ORDER BY s.year, s.subject_name
";
$subjects_result = pg_query_params($conn, $subjects_query, array($teacher_id));
$assigned_subjects = pg_fetch_all($subjects_result);

$page = $_GET['page'] ?? 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - <?= htmlspecialchars($teacher_name) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .header-info h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header-info p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .btn-primary:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .btn-logout {
            background: rgba(239,68,68,0.2);
            color: white;
            border: 2px solid rgba(239,68,68,0.3);
        }
        
        .btn-logout:hover {
            background: rgba(239,68,68,0.4);
        }
        
        .content {
            padding: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #3b82f6;
        }
        
        .stat-card.success {
            border-left-color: #10b981;
        }
        
        .stat-card.warning {
            border-left-color: #f59e0b;
        }
        
        .stat-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e3a8a;
        }
        
        .section-title {
            font-size: 24px;
            color: #1e3a8a;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .subjects-table th {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }
        
        .subjects-table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .subjects-table tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-year {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-class {
            background: #f3e8ff;
            color: #6b21a8;
        }
        
        .btn-manage {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-manage:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <div class="avatar">👨‍🏫</div>
                <div class="header-info">
                    <h1>Welcome, <?= htmlspecialchars($teacher_name) ?>!</h1>
                    <p>Teacher Dashboard • <?= htmlspecialchars($teacher['specialization'] ?? 'N/A') ?></p>
                </div>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-primary">📊 Full System</a>
                <a href="logout.php" class="btn btn-logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📚</div>
                    <div class="stat-label">Assigned Subjects</div>
                    <div class="stat-value"><?= count($assigned_subjects ?? []) ?></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">👥</div>
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value">
                        <?php
                        $total_students = 0;
                        if ($assigned_subjects) {
                            foreach ($assigned_subjects as $subject) {
                                $total_students += $subject['student_count'];
                            }
                        }
                        echo $total_students;
                        ?>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-label">Specialization</div>
                    <div class="stat-value" style="font-size: 20px; padding-top: 10px;">
                        <?= htmlspecialchars($teacher['specialization'] ?? 'N/A') ?>
                    </div>
                </div>
            </div>
            
            <!-- Assigned Subjects -->
            <h2 class="section-title">📖 Your Assigned Subjects</h2>
            
            <?php if ($assigned_subjects && count($assigned_subjects) > 0): ?>
                <table class="subjects-table">
                    <thead>
                        <tr>
                            <th>Subject Name</th>
                            <th>Year</th>
                            <th>Class</th>
                            <th>Credits</th>
                            <th>Students</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($subject['subject_name']) ?></strong></td>
                                <td><span class="badge badge-year">Year <?= $subject['year'] ?></span></td>
                                <td><span class="badge badge-class">Class <?= htmlspecialchars($subject['class_level']) ?></span></td>
                                <td><?= $subject['credits'] ?></td>
                                <td><?= $subject['student_count'] ?> students</td>
                                <td>
                                    <a href="index.php?page=marks" class="btn-manage">
                                        Manage Marks
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>No Subjects Assigned Yet</h3>
                    <p>Please contact the administrator to assign subjects to you.</p>
                </div>
            <?php endif; ?>
            
            <!-- Quick Info -->
            <div style="margin-top: 3rem; padding: 1.5rem; background: #fef3c7; border-radius: 12px; border-left: 4px solid #f59e0b;">
                <h3 style="color: #92400e; margin-bottom: 1rem;">ℹ️ Quick Guide</h3>
                <ul style="color: #78350f; line-height: 1.8;">
                    <li>You can only view and edit marks for subjects assigned to you</li>
                    <li>Click "Manage Marks" to edit student marks for each subject</li>
                    <li>You can view student performance, but cannot manage students or subjects</li>
                    <li>For any system changes, please contact the administrator</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
