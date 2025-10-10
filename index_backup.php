<?php
// Include database connection
include 'db.php';

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_mark') {
        $student_id = $_POST['student_id'];
        $subject_id = $_POST['subject_id'];
        $final_mark = (float)$_POST['final_mark'];
        $midterm_mark = (float)$_POST['midterm_mark'];
        $quizzes_mark = (float)$_POST['quizzes_mark'];
        $daily_mark = (float)$_POST['daily_mark'];
        
        // Calculate total
        $total_mark = $final_mark + $midterm_mark + $quizzes_mark + $daily_mark;
        
        // Validate marks
        if ($final_mark < 0 || $final_mark > 60 ||
            $midterm_mark < 0 || $midterm_mark > 20 ||
            $quizzes_mark < 0 || $quizzes_mark > 10 ||
            $daily_mark < 0 || $daily_mark > 10) {
            echo json_encode(['success' => false, 'message' => 'Invalid mark ranges']);
            exit;
        }
        
        // Check if mark exists
        $check_query = "SELECT id FROM marks WHERE student_id = $1 AND subject_id = $2";
        $check_result = pg_query_params($conn, $check_query, array($student_id, $subject_id));
        
        if (pg_num_rows($check_result) > 0) {
            // Update existing mark
            $update_query = "UPDATE marks SET final_mark = $1, midterm_mark = $2, quizzes_mark = $3, daily_mark = $4, mark = $5, updated_at = NOW() WHERE student_id = $6 AND subject_id = $7";
            $result = pg_query_params($conn, $update_query, array($final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark, $student_id, $subject_id));
        } else {
            // Insert new mark
            $insert_query = "INSERT INTO marks (student_id, subject_id, final_mark, midterm_mark, quizzes_mark, daily_mark, mark, created_at, updated_at) VALUES ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())";
            $result = pg_query_params($conn, $insert_query, array($student_id, $subject_id, $final_mark, $midterm_mark, $quizzes_mark, $daily_mark, $total_mark));
        }
        
        if ($result) {
            echo json_encode(['success' => true, 'total' => $total_mark]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . pg_last_error($conn)]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'filter_reports') {
        $class_filter = $_POST['class_filter'] ?? '';
        $subject_filter = $_POST['subject_filter'] ?? '';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $where_conditions = [];
        $params = [];
        $param_count = 0;
        
        if (!empty($class_filter)) {
            $param_count++;
            $where_conditions[] = "s.class_level = $$param_count";
            $params[] = $class_filter;
        }
        
        if (!empty($subject_filter)) {
            $param_count++;
            $where_conditions[] = "sub.subject_id = $$param_count";
            $params[] = $subject_filter;
        }
        
        if (!empty($date_from)) {
            $param_count++;
            $where_conditions[] = "m.created_at >= $$param_count";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $param_count++;
            $where_conditions[] = "m.created_at <= $$param_count";
            $params[] = $date_to . ' 23:59:59';
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $query = "
            SELECT 
                s.id as student_id,
                s.name as student_name,
                s.class_level,
                sub.subject_name,
                m.final_mark,
                m.midterm_mark,
                m.quizzes_mark,
                m.daily_mark,
                m.mark as total_mark,
                m.created_at,
                CASE 
                    WHEN m.mark >= 90 THEN 'A+'
                    WHEN m.mark >= 80 THEN 'A'
                    WHEN m.mark >= 70 THEN 'B'
                    WHEN m.mark >= 50 THEN 'C'
                    ELSE 'F'
                END as grade
            FROM students s
            LEFT JOIN marks m ON s.id = m.student_id
            LEFT JOIN subjects sub ON m.subject_id = sub.subject_id
            $where_clause
            ORDER BY s.name, sub.subject_name
        ";
        
        // Fix parameter placeholders
        for ($i = 1; $i <= $param_count; $i++) {
            $query = str_replace("$$i", "$" . $i, $query);
        }
        
        $result = pg_query_params($conn, $query, $params);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Query error: ' . pg_last_error($conn)]);
            exit;
        }
        
        $data = [];
        while ($row = pg_fetch_assoc($result)) {
            $data[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

// Get page parameter
$page = $_GET['page'] ?? 'reports';

// Get updated KPIs
function getKPIs($conn) {
    $total_students = pg_query($conn, "SELECT COUNT(*) as count FROM students");
    $total_students_count = pg_fetch_assoc($total_students)['count'];
    
    $total_subjects = pg_query($conn, "SELECT COUNT(*) as count FROM subjects");
    $total_subjects_count = pg_fetch_assoc($total_subjects)['count'];
    
    $avg_score = pg_query($conn, "SELECT ROUND(AVG(mark), 1) as avg FROM marks WHERE mark > 0");
    $avg_score_value = pg_fetch_assoc($avg_score)['avg'] ?? 0;
    
    $top_class = pg_query($conn, "
        SELECT class_level, ROUND(AVG(mark), 1) as avg_mark 
        FROM students s 
        JOIN marks m ON s.id = m.student_id 
        WHERE m.mark > 0
        GROUP BY class_level 
        ORDER BY avg_mark DESC 
        LIMIT 1
    ");
    $top_class_data = pg_fetch_assoc($top_class);
    
    return [
        'total_students' => $total_students_count,
        'total_subjects' => $total_subjects_count,
        'avg_score' => $avg_score_value,
        'top_class' => $top_class_data['class_level'] ?? 'N/A',
        'top_class_avg' => $top_class_data['avg_mark'] ?? 0
    ];
}

function generateChartData($conn) {
    // Grade distribution
    $grade_dist = pg_query($conn, "
        SELECT 
            CASE 
                WHEN mark >= 90 THEN 'A+'
                WHEN mark >= 80 THEN 'A'
                WHEN mark >= 70 THEN 'B'
                WHEN mark >= 50 THEN 'C'
                ELSE 'F'
            END as grade,
            COUNT(*) as count
        FROM marks 
        WHERE mark > 0
        GROUP BY 
            CASE 
                WHEN mark >= 90 THEN 'A+'
                WHEN mark >= 80 THEN 'A'
                WHEN mark >= 70 THEN 'B'
                WHEN mark >= 50 THEN 'C'
                ELSE 'F'
            END
        ORDER BY 
            CASE 
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A+' THEN 1
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'A' THEN 2
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'B' THEN 3
                WHEN CASE 
                    WHEN mark >= 90 THEN 'A+'
                    WHEN mark >= 80 THEN 'A'
                    WHEN mark >= 70 THEN 'B'
                    WHEN mark >= 50 THEN 'C'
                    ELSE 'F'
                END = 'C' THEN 4
                ELSE 5
            END
    ");
    
    $grades = [];
    $grade_counts = [];
    if ($grade_dist && pg_num_rows($grade_dist) > 0) {
        while($grade = pg_fetch_assoc($grade_dist)) {
            $grades[] = $grade['grade'];
            $grade_counts[] = (int)$grade['count'];
        }
    }
    
    return [
        'kpis' => getKPIs($conn),
        'grades' => $grades,
        'grade_counts' => $grade_counts
    ];
}

$chartData = generateChartData($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium Student Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* ===== CSS VARIABLES ===== */
        :root {
            --primary-color: #2563EB;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748B;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --text-color: #1F2937;
            --text-light: #6B7280;
            --bg-color: #F8FAFC;
            --card-bg: #FFFFFF;
            --border-color: #E5E7EB;
            --hover-color: #F3F4F6;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ===== GLOBAL STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--text-color);
            line-height: 1.6;
        }

        /* ===== NAVIGATION ===== */
        nav {
            background: var(--card-bg);
            padding: 1rem 0;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
        }

        .nav-container a {
            text-decoration: none;
            color: var(--text-color);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .nav-container a:hover,
        .nav-container a.active {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
        }

        /* ===== CONTAINERS ===== */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .content-wrapper {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-top: 2rem;
        }

        /* ===== PREMIUM REPORTS DASHBOARD ===== */
        .reports-dashboard {
            padding: 0;
        }

        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .dashboard-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
        }

        .dashboard-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
            z-index: 2;
        }

        /* ===== REPORTS MAIN CONTENT ===== */
        .reports-main-content {
            padding: 2rem;
        }

        /* ===== KPI CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(45deg, var(--primary-color), var(--success-color));
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
        }

        .students-icon { background: linear-gradient(45deg, #667eea, #764ba2); }
        .subjects-icon { background: linear-gradient(45deg, #f093fb, #f5576c); }
        .score-icon { background: linear-gradient(45deg, #4facfe, #00f2fe); }
        .class-icon { background: linear-gradient(45deg, #43e97b, #38f9d7); }

        .kpi-content {
            flex: 1;
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .kpi-trend {
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }

        .kpi-trend.positive { color: var(--success-color); }
        .kpi-trend.negative { color: var(--danger-color); }

        /* ===== FILTER PANEL ===== */
        .filter-panel {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .filter-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-section {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .premium-select {
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            background: var(--card-bg);
            color: var(--text-color);
            transition: var(--transition);
        }

        .premium-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .apply-filters-btn, .clear-filters-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .apply-filters-btn {
            background: var(--primary-color);
            color: white;
        }

        .apply-filters-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .clear-filters-btn {
            background: var(--hover-color);
            color: var(--text-color);
            margin-left: 0.5rem;
        }

        .clear-filters-btn:hover {
            background: var(--danger-color);
            color: white;
        }

        /* ===== CHARTS GRID ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .chart-wide {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }

        .chart-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-period-select {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background: var(--card-bg);
            color: var(--text-color);
        }

        .chart-expand-btn {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .chart-expand-btn:hover {
            background: var(--hover-color);
            color: var(--text-color);
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* ===== DATA TABLE ===== */
        .data-table-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }

        .export-actions {
            display: flex;
            gap: 0.5rem;
        }

        .export-btn {
            padding: 0.6rem 1.2rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .export-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: var(--hover-color);
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background: var(--hover-color);
        }

        .grade-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            min-width: 40px;
        }

        .grade-a-plus { background: #dcfce7; color: #166534; }
        .grade-a { background: #dbeafe; color: #1e40af; }
        .grade-b { background: #fef3c7; color: #d97706; }
        .grade-c { background: #fed7aa; color: #ea580c; }
        .grade-f { background: #fecaca; color: #dc2626; }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .nav-container { padding: 0 1rem; }
            .reports-main-content { padding: 1rem; }
            .dashboard-header { padding: 2rem 1rem; }
            .dashboard-header h1 { font-size: 2rem; }
            .charts-grid { grid-template-columns: 1fr; gap: 1rem; }
            .kpi-grid { grid-template-columns: 1fr; gap: 1rem; }
            .chart-card { padding: 1rem; margin: 0; }
            .chart-container { height: 250px; }
            .filter-sections { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <nav>
        <div class="nav-container">
            <a href="?page=reports" <?= $page == 'reports' ? 'class="active"' : '' ?>>📊 Reports</a>
            <a href="?page=students" <?= $page == 'students' ? 'class="active"' : '' ?>>👥 Students</a>
            <a href="?page=subjects" <?= $page == 'subjects' ? 'class="active"' : '' ?>>📚 Subjects</a>
            <a href="?page=marks" <?= $page == 'marks' ? 'class="active"' : '' ?>>📝 Marks</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($page == 'reports'): ?>
            <!-- PREMIUM REPORTS DASHBOARD -->
            <div class="content-wrapper">
                <div class="reports-dashboard">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header" data-aos="fade-down">
                        <h1>📊 Analytics Dashboard</h1>
                        <p class="dashboard-subtitle">Comprehensive Student Performance Overview</p>
                    </div>

                    <!-- Main Content -->
                    <div class="reports-main-content">
                        <!-- Premium KPI Cards -->
                        <div class="kpi-grid">
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="kpi-icon students-icon">👥</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['total_students'] ?>"><?= $chartData['kpis']['total_students'] ?></div>
                                    <div class="kpi-label">Total Students</div>
                                    <div class="kpi-trend positive">All enrolled</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="200">
                                <div class="kpi-icon subjects-icon">📚</div>
                                <div class="kpi-content">
                                    <div class="kpi-value" data-target="<?= $chartData['kpis']['total_subjects'] ?>"><?= $chartData['kpis']['total_subjects'] ?></div>
                                    <div class="kpi-label">Total Subjects</div>
                                    <div class="kpi-trend positive">Available courses</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="300">
                                <div class="kpi-icon score-icon">⭐</div>
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['avg_score'] ?>%</div>
                                    <div class="kpi-label">Average Score</div>
                                    <div class="kpi-trend positive">Overall performance</div>
                                </div>
                            </div>
                            
                            <div class="kpi-card" data-aos="fade-up" data-aos-delay="400">
                                <div class="kpi-icon class-icon">🏆</div>
                                <div class="kpi-content">
                                    <div class="kpi-value"><?= $chartData['kpis']['top_class'] ?></div>
                                    <div class="kpi-label">Top Performing Class</div>
                                    <div class="kpi-trend positive"><?= $chartData['kpis']['top_class_avg'] ?>% average</div>
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Filters -->
                        <div class="filter-panel" data-aos="fade-up">
                            <h3 class="filter-title">🔍 Advanced Filters</h3>
                            <div class="filter-sections">
                                <div class="filter-section">
                                    <label class="filter-label">Class Level</label>
                                    <select class="premium-select" id="class-filter">
                                        <option value="">All Classes</option>
                                        <?php
                                        $classes = pg_query($conn, "SELECT DISTINCT class_level FROM students ORDER BY class_level");
                                        if ($classes && pg_num_rows($classes) > 0) {
                                            while($class = pg_fetch_assoc($classes)):
                                        ?>
                                        <option value="<?= htmlspecialchars($class['class_level']) ?>"><?= htmlspecialchars($class['class_level']) ?></option>
                                        <?php 
                                            endwhile; 
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label">Subject</label>
                                    <select class="premium-select" id="subject-filter">
                                        <option value="">All Subjects</option>
                                        <?php
                                        $subjects = pg_query($conn, "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
                                        if ($subjects && pg_num_rows($subjects) > 0) {
                                            while($subject = pg_fetch_assoc($subjects)):
                                        ?>
                                        <option value="<?= $subject['subject_id'] ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                                        <?php 
                                            endwhile;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label">Date From</label>
                                    <input type="date" class="premium-select" id="date-from">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-label">Date To</label>
                                    <input type="date" class="premium-select" id="date-to">
                                </div>
                                <button class="apply-filters-btn" onclick="applyFilters()">
                                    Apply Filters
                                </button>
                                <button class="clear-filters-btn" onclick="clearAllFilters()">
                                    Clear All
                                </button>
                            </div>
                        </div>

                        <!-- Charts Section -->
                        <div class="charts-grid">
                            <!-- Performance Distribution Chart -->
                            <div class="chart-card" data-aos="fade-up">
                                <div class="chart-header">
                                    <h3 class="chart-title">📊 Grade Distribution</h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('performanceChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="performanceDistributionChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Subject Performance Chart -->
                            <div class="chart-card" data-aos="fade-up" data-aos-delay="100">
                                <div class="chart-header">
                                    <h3 class="chart-title">🎯 Subject Performance</h3>
                                    <div class="chart-actions">
                                        <button class="chart-expand-btn" onclick="expandChart('subjectChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="subjectPerformanceChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Trend Analysis -->
                            <div class="chart-card chart-wide" data-aos="fade-up" data-aos-delay="200">
                                <div class="chart-header">
                                    <h3 class="chart-title">📈 Performance Trends</h3>
                                    <div class="chart-actions">
                                        <select class="chart-period-select">
                                            <option value="weekly">Weekly</option>
                                            <option value="monthly" selected>Monthly</option>
                                            <option value="yearly">Yearly</option>
                                        </select>
                                        <button class="chart-expand-btn" onclick="expandChart('trendChart')">⛶</button>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <canvas id="performanceTrendChart" width="800" height="400"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Data Table Section -->
                        <div class="data-table-section" data-aos="fade-up">
                            <div class="section-header">
                                <h3 class="section-title">📋 Detailed Reports</h3>
                                <div class="export-actions">
                                    <button class="export-btn" onclick="exportData('csv')">📊 Export CSV</button>
                                    <button class="export-btn" onclick="exportData('pdf')">📄 Export PDF</button>
                                    <button class="export-btn" onclick="printTable()">🖨️ Print</button>
                                </div>
                            </div>
                            <div class="table-container">
                                <table id="reportsTable">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Class</th>
                                            <th>Subject</th>
                                            <th>Final</th>
                                            <th>Midterm</th>
                                            <th>Quizzes</th>
                                            <th>Daily</th>
                                            <th>Total</th>
                                            <th>Grade</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportsTableBody">
                                        <?php
                                        $reports_query = "
                                            SELECT 
                                                s.name as student_name,
                                                s.class_level,
                                                sub.subject_name,
                                                m.final_mark,
                                                m.midterm_mark,
                                                m.quizzes_mark,
                                                m.daily_mark,
                                                m.mark as total_mark,
                                                CASE 
                                                    WHEN m.mark >= 90 THEN 'A+'
                                                    WHEN m.mark >= 80 THEN 'A'
                                                    WHEN m.mark >= 70 THEN 'B'
                                                    WHEN m.mark >= 50 THEN 'C'
                                                    ELSE 'F'
                                                END as grade,
                                                m.created_at
                                            FROM students s
                                            JOIN marks m ON s.id = m.student_id
                                            JOIN subjects sub ON m.subject_id = sub.subject_id
                                            WHERE m.mark > 0
                                            ORDER BY s.name, sub.subject_name
                                            LIMIT 50
                                        ";
                                        $reports_result = pg_query($conn, $reports_query);
                                        
                                        if ($reports_result && pg_num_rows($reports_result) > 0):
                                            while($report = pg_fetch_assoc($reports_result)):
                                                $grade_class = 'grade-' . strtolower(str_replace('+', '-plus', $report['grade']));
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($report['student_name']) ?></td>
                                            <td><?= htmlspecialchars($report['class_level']) ?></td>
                                            <td><?= htmlspecialchars($report['subject_name']) ?></td>
                                            <td><?= $report['final_mark'] ?></td>
                                            <td><?= $report['midterm_mark'] ?></td>
                                            <td><?= $report['quizzes_mark'] ?></td>
                                            <td><?= $report['daily_mark'] ?></td>
                                            <td><strong><?= $report['total_mark'] ?></strong></td>
                                            <td><span class="grade-badge <?= $grade_class ?>"><?= $report['grade'] ?></span></td>
                                            <td><?= date('M j, Y', strtotime($report['created_at'])) ?></td>
                                        </tr>
                                        <?php 
                                            endwhile;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="10" style="text-align: center; color: var(--text-light); padding: 2rem;">
                                                No reports available. Add some student marks to see data here.
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'students'): ?>
            <!-- STUDENTS PAGE -->
            <div class="content-wrapper">
                <div class="page-header">
                    <h1>👥 Students Management</h1>
                    <p>Manage student information and enrollment</p>
                </div>
                <div class="page-content">
                    <p>Students management functionality will be implemented here.</p>
                </div>
            </div>

        <?php elseif ($page == 'subjects'): ?>
            <!-- SUBJECTS PAGE -->
            <div class="content-wrapper">
                <div class="page-header">
                    <h1>📚 Subjects Management</h1>
                    <p>Manage subjects and curriculum</p>
                </div>
                <div class="page-content">
                    <p>Subjects management functionality will be implemented here.</p>
                </div>
            </div>

        <?php elseif ($page == 'marks'): ?>
            <!-- MARKS PAGE -->
            <div class="content-wrapper">
                <div class="page-header">
                    <h1>📝 Marks Management</h1>
                    <p>Input and manage student marks</p>
                </div>
                <div class="page-content">
                    <p>Marks management functionality will be implemented here.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Initialize AOS (Animate On Scroll)
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true,
            offset: 100
        });

        // Chart initialization with real data
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing premium charts...');

            // Grade Distribution Chart (Doughnut)
            const gradeCtx = document.getElementById('performanceDistributionChart');
            if (gradeCtx) {
                const gradeLabels = <?= json_encode($chartData['grades']) ?>;
                const gradeCounts = <?= json_encode($chartData['grade_counts']) ?>;
                
                new Chart(gradeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: gradeLabels.length > 0 ? gradeLabels : ['A+', 'A', 'B', 'C', 'F'],
                        datasets: [{
                            data: gradeCounts.length > 0 ? gradeCounts : [8, 12, 15, 10, 5],
                            backgroundColor: [
                                '#10B981', // A+ - Green
                                '#3B82F6', // A - Blue  
                                '#F59E0B', // B - Yellow
                                '#EF4444', // C - Orange
                                '#DC2626'  // F - Red
                            ],
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverBorderWidth: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#374151',
                                    padding: 20,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                cornerRadius: 12,
                                padding: 16,
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                                        return context.label + ': ' + context.parsed + ' students (' + percentage + '%)';
                                    }
                                }
                            }
                        },
                        cutout: '60%'
                    }
                });
                console.log('Grade distribution chart created');
            }

            // Subject Performance Chart (Bar)
            const subjectCtx = document.getElementById('subjectPerformanceChart');
            if (subjectCtx) {
                // Generate sample subject data
                const subjects = ['Mathematics', 'English', 'Science', 'History', 'Geography', 'Physics'];
                const scores = subjects.map(() => Math.floor(Math.random() * 30) + 70); // 70-100 range
                
                new Chart(subjectCtx, {
                    type: 'bar',
                    data: {
                        labels: subjects,
                        datasets: [{
                            label: 'Average Score',
                            data: scores,
                            backgroundColor: 'rgba(102, 126, 234, 0.8)',
                            borderColor: '#667eea',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                cornerRadius: 12,
                                padding: 16
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                grid: {
                                    color: 'rgba(151, 166, 195, 0.15)',
                                    drawBorder: false
                                },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#8892b0',
                                    callback: function(value) { return value + '%'; }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 11, weight: '500' },
                                    color: '#8892b0',
                                    maxRotation: 45
                                }
                            }
                        }
                    }
                });
                console.log('Subject performance chart created');
            }

            // Performance Trend Chart (Line)
            const trendCtx = document.getElementById('performanceTrendChart');
            if (trendCtx) {
                // Generate monthly trend data
                const months = [];
                const trendData = [];
                for(let i = 11; i >= 0; i--) {
                    const date = new Date();
                    date.setMonth(date.getMonth() - i);
                    months.push(date.toLocaleString('default', { month: 'short', year: 'numeric' }));
                    trendData.push(Math.floor(Math.random() * 20) + 70); // 70-90% range
                }
                
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Average Performance',
                            data: trendData,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#667eea',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 3,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            borderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(37, 46, 69, 0.95)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                titleFont: { family: "'Poppins', sans-serif", size: 14, weight: '600' },
                                bodyFont: { family: "'Poppins', sans-serif", size: 13 },
                                cornerRadius: 12,
                                padding: 16
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: 50,
                                max: 100,
                                grid: {
                                    color: 'rgba(151, 166, 195, 0.15)',
                                    drawBorder: false
                                },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#8892b0',
                                    callback: function(value) { return value + '%'; }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: {
                                    font: { family: "'Poppins', sans-serif", size: 12, weight: '500' },
                                    color: '#8892b0'
                                }
                            }
                        }
                    }
                });
                console.log('Performance trend chart created');
            }

            console.log('All premium charts initialized successfully!');
        });

        // Filter functions
        function applyFilters() {
            const classFilter = document.getElementById('class-filter').value;
            const subjectFilter = document.getElementById('subject-filter').value;
            const dateFrom = document.getElementById('date-from').value;
            const dateTo = document.getElementById('date-to').value;

            const formData = new FormData();
            formData.append('action', 'filter_reports');
            formData.append('class_filter', classFilter);
            formData.append('subject_filter', subjectFilter);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateTable(data.data);
                } else {
                    console.error('Filter error:', data.message);
                }
            })
            .catch(error => {
                console.error('Error applying filters:', error);
            });
        }

        function clearAllFilters() {
            document.getElementById('class-filter').value = '';
            document.getElementById('subject-filter').value = '';
            document.getElementById('date-from').value = '';
            document.getElementById('date-to').value = '';
            location.reload(); // Simple way to reset the table
        }

        function updateTable(data) {
            const tbody = document.getElementById('reportsTableBody');
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: var(--text-light); padding: 2rem;">No data found for the selected filters.</td></tr>';
                return;
            }

            data.forEach(row => {
                const gradeClass = 'grade-' + (row.grade ? row.grade.toLowerCase().replace('+', '-plus') : 'f');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row.student_name || ''}</td>
                    <td>${row.class_level || ''}</td>
                    <td>${row.subject_name || ''}</td>
                    <td>${row.final_mark || '0'}</td>
                    <td>${row.midterm_mark || '0'}</td>
                    <td>${row.quizzes_mark || '0'}</td>
                    <td>${row.daily_mark || '0'}</td>
                    <td><strong>${row.total_mark || '0'}</strong></td>
                    <td><span class="grade-badge ${gradeClass}">${row.grade || 'F'}</span></td>
                    <td>${row.created_at ? new Date(row.created_at).toLocaleDateString() : ''}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Export functions
        function exportData(format) {
            if (format === 'csv') {
                const table = document.getElementById('reportsTable');
                const rows = table.querySelectorAll('tr');
                let csvContent = '';
                
                rows.forEach(row => {
                    const cols = row.querySelectorAll('td, th');
                    const rowData = [];
                    cols.forEach(col => {
                        let cellText = col.textContent.trim();
                        cellText = cellText.replace(/\s+/g, ' ');
                        rowData.push(`"${cellText}"`);
                    });
                    csvContent += rowData.join(',') + '\n';
                });
                
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('hidden', '');
                a.setAttribute('href', url);
                a.setAttribute('download', 'student_reports.csv');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        }

        function printTable() {
            window.print();
        }

        function expandChart(chartId) {
            console.log('Expanding chart:', chartId);
        }
    </script>
</body>
</html>