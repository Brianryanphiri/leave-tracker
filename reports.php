<?php
// reports.php - Leave Reports & Analytics Dashboard
$page_title = "Reports & Analytics";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is admin or CEO
if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('Location: dashboard.php');
    exit();
}

$pdo = getPDOConnection();
$reports = [];
$filters = [
    'period' => $_GET['period'] ?? 'monthly',
    'department' => $_GET['department'] ?? 'all',
    'leave_type' => $_GET['leave_type'] ?? 'all',
    'status' => $_GET['status'] ?? 'all',
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t'),
    'year' => $_GET['year'] ?? date('Y')
];

$departments = [];
$leave_types = [];
$years = range(date('Y') - 5, date('Y'));
$summary_stats = [];
$department_stats = [];
$leave_type_stats = [];
$monthly_trends = [];
$employee_usage = [];

// Fetch filter options
if ($pdo) {
    try {
        // Get departments
        $stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get leave types
        $stmt = $pdo->query("SELECT id, name, color FROM leave_types WHERE is_active = 1 ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get summary statistics
        $summary_stats = getSummaryStatistics($filters);

        // Get department statistics
        $department_stats = getDepartmentStatistics($filters);

        // Get leave type statistics
        $leave_type_stats = getLeaveTypeStatistics($filters);

        // Get monthly trends
        $monthly_trends = getMonthlyTrends($filters['year']);

        // Get employee usage
        $employee_usage = getEmployeeUsage($filters);

    } catch (PDOException $e) {
        error_log("Error fetching report data: " . $e->getMessage());
    }
}

// Helper functions for statistics
function getSummaryStatistics($filters)
{
    global $pdo;

    $where_conditions = ["l.status = 'approved'"];
    $params = [];

    // Build where conditions based on filters
    if ($filters['department'] !== 'all') {
        $where_conditions[] = "u.department = ?";
        $params[] = $filters['department'];
    }

    if ($filters['leave_type'] !== 'all') {
        $where_conditions[] = "l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    if ($filters['status'] !== 'all') {
        $where_conditions[] = "l.status = ?";
        $params[] = $filters['status'];
    }

    $where_conditions[] = "l.start_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $where_clause = implode(' AND ', $where_conditions);

    $sql = "
        SELECT 
            COUNT(DISTINCT l.id) as total_leaves,
            COUNT(DISTINCT l.user_id) as unique_employees,
            SUM(l.total_days) as total_days,
            ROUND(AVG(l.total_days), 1) as avg_days_per_leave,
            COUNT(CASE WHEN l.half_day != 'none' THEN 1 END) as half_day_leaves,
            MIN(l.start_date) as first_leave_date,
            MAX(l.end_date) as last_leave_date,
            COUNT(CASE WHEN DATEDIFF(l.end_date, l.start_date) > 7 THEN 1 END) as long_leaves
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        WHERE $where_clause
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getDepartmentStatistics($filters)
{
    global $pdo;

    $where_conditions = ["l.status = 'approved'"];
    $params = [];

    if ($filters['leave_type'] !== 'all') {
        $where_conditions[] = "l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    if ($filters['status'] !== 'all') {
        $where_conditions[] = "l.status = ?";
        $params[] = $filters['status'];
    }

    $where_conditions[] = "l.start_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $where_clause = implode(' AND ', $where_conditions);

    $sql = "
        SELECT 
            COALESCE(u.department, 'Unassigned') as department,
            COUNT(DISTINCT l.id) as leave_count,
            SUM(l.total_days) as total_days,
            COUNT(DISTINCT l.user_id) as employee_count,
            ROUND(SUM(l.total_days) / COUNT(DISTINCT l.user_id), 1) as avg_days_per_employee,
            ROUND(COUNT(DISTINCT l.id) * 100.0 / (SELECT COUNT(*) FROM leaves WHERE status = 'approved' AND start_date BETWEEN ? AND ?), 1) as percentage
        FROM leaves l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE $where_clause
        GROUP BY u.department
        ORDER BY total_days DESC
        LIMIT 10
    ";

    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLeaveTypeStatistics($filters)
{
    global $pdo;

    $where_conditions = ["l.status = 'approved'"];
    $params = [];

    if ($filters['department'] !== 'all') {
        $where_conditions[] = "u.department = ?";
        $params[] = $filters['department'];
    }

    if ($filters['status'] !== 'all') {
        $where_conditions[] = "l.status = ?";
        $params[] = $filters['status'];
    }

    $where_conditions[] = "l.start_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $where_clause = implode(' AND ', $where_conditions);

    $sql = "
        SELECT 
            lt.name,
            lt.color,
            COUNT(DISTINCT l.id) as leave_count,
            SUM(l.total_days) as total_days,
            COUNT(DISTINCT l.user_id) as employee_count,
            ROUND(AVG(l.total_days), 1) as avg_days_per_leave,
            ROUND(SUM(l.total_days) * 100.0 / (SELECT SUM(total_days) FROM leaves WHERE status = 'approved' AND start_date BETWEEN ? AND ?), 1) as percentage
        FROM leaves l
        JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN users u ON l.user_id = u.id
        WHERE $where_clause
        GROUP BY lt.id, lt.name, lt.color
        ORDER BY total_days DESC
    ";

    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyTrends($year)
{
    global $pdo;

    $sql = "
        SELECT 
            MONTHNAME(STR_TO_DATE(m.month, '%m')) as month_name,
            m.month as month_num,
            COALESCE(COUNT(l.id), 0) as leave_count,
            COALESCE(SUM(l.total_days), 0) as total_days,
            COALESCE(COUNT(DISTINCT l.user_id), 0) as employee_count
        FROM (
            SELECT '01' as month UNION SELECT '02' UNION SELECT '03' UNION SELECT '04' 
            UNION SELECT '05' UNION SELECT '06' UNION SELECT '07' UNION SELECT '08' 
            UNION SELECT '09' UNION SELECT '10' UNION SELECT '11' UNION SELECT '12'
        ) m
        LEFT JOIN leaves l ON m.month = MONTH(l.start_date) AND YEAR(l.start_date) = ? AND l.status = 'approved'
        GROUP BY m.month
        ORDER BY m.month
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEmployeeUsage($filters)
{
    global $pdo;

    $where_conditions = ["l.status = 'approved'"];
    $params = [];

    if ($filters['department'] !== 'all') {
        $where_conditions[] = "u.department = ?";
        $params[] = $filters['department'];
    }

    if ($filters['leave_type'] !== 'all') {
        $where_conditions[] = "l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    if ($filters['status'] !== 'all') {
        $where_conditions[] = "l.status = ?";
        $params[] = $filters['status'];
    }

    $where_conditions[] = "l.start_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $where_clause = implode(' AND ', $where_conditions);

    $sql = "
        SELECT 
            u.full_name,
            u.department,
            u.email,
            COUNT(DISTINCT l.id) as leave_count,
            SUM(l.total_days) as total_days,
            MAX(l.end_date) as last_leave_date,
            GROUP_CONCAT(DISTINCT lt.name ORDER BY lt.name SEPARATOR ', ') as leave_types_used,
            ROUND(SUM(l.total_days) * 100.0 / (SELECT SUM(total_days) FROM leaves WHERE status = 'approved' AND start_date BETWEEN ? AND ?), 2) as percentage_of_total
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        WHERE $where_clause
        GROUP BY u.id, u.full_name, u.department, u.email
        ORDER BY total_days DESC
        LIMIT 15
    ";

    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap');

    /* Color Scheme - Ocher/Dark Mustard */
    :root {
        --color-primary: #D4A017;
        --color-primary-dark: #B8860B;
        --color-primary-light: #FFD700;
        --color-secondary: #8B7355;
        --color-success: #B8860B;
        --color-danger: #8B4513;
        --color-warning: #CD853F;
        --color-info: #4285F4;
        --color-text: #2F2F2F;
        --color-light-gray: #F5F5F5;
        --color-dark-gray: #666666;
        --color-white: #FFFFFF;
        --color-border: rgba(212, 160, 23, 0.2);
        --color-background: #FFFFFF;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--color-background);
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
        margin: 0;
        padding: 0;
    }

    /* Subtle background pattern */
    body::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image:
            linear-gradient(rgba(212, 160, 23, 0.02) 1px, transparent 1px),
            linear-gradient(90deg, rgba(212, 160, 23, 0.02) 1px, transparent 1px);
        background-size: 50px 50px;
        z-index: -1;
    }

    .content-area {
        position: relative;
        z-index: 1;
        padding: 30px;
        max-width: 100%;
        width: 100%;
        box-sizing: border-box;
        padding-top: 20px;
    }

    /* Page Header */
    .page-header {
        margin-bottom: 40px;
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        position: relative;
        overflow: hidden;
    }

    .page-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, var(--color-primary), var(--color-primary-dark));
    }

    .page-title {
        font-family: 'Playfair Display', serif;
        font-size: 2.2em;
        color: var(--color-text);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 15px;
        background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .page-title i {
        font-size: 1.6em;
        color: var(--color-primary);
    }

    .page-subtitle {
        color: var(--color-dark-gray);
        font-size: 1.1em;
        margin-bottom: 20px;
    }

    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 16px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
    }

    .filter-title {
        font-size: 1.2em;
        color: var(--color-text);
        margin-bottom: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .filter-title i {
        color: var(--color-primary);
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .filter-group {
        margin-bottom: 15px;
    }

    .filter-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.9em;
    }

    .filter-select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 0.95em;
        color: var(--color-text);
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .date-range {
        display: flex;
        gap: 15px;
        align-items: flex-end;
    }

    .date-input {
        flex: 1;
    }

    .date-input input {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 0.95em;
        color: var(--color-text);
        background: white;
        transition: all 0.3s ease;
    }

    .date-input input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .filter-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--color-border);
    }

    .btn {
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.95em;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.2);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, var(--color-primary-dark) 0%, #D2691E 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(212, 160, 23, 0.3);
    }

    .btn-secondary {
        background: white;
        color: var(--color-primary-dark);
        border: 2px solid var(--color-border);
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(212, 160, 23, 0.15);
    }

    .btn-export {
        background: linear-gradient(135deg, var(--color-info) 0%, #34A853 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(66, 133, 244, 0.2);
    }

    .btn-export:hover {
        background: linear-gradient(135deg, #34A853 0%, #0F9D58 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(66, 133, 244, 0.3);
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 30px rgba(212, 160, 23, 0.15);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8em;
        color: white;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.25);
        transition: all 0.3s ease;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.35);
    }

    .stat-icon.total-leaves {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-icon.total-days {
        background: linear-gradient(135deg, var(--color-success), var(--color-primary-dark));
    }

    .stat-icon.avg-days {
        background: linear-gradient(135deg, var(--color-warning), var(--color-primary));
    }

    .stat-icon.unique-employees {
        background: linear-gradient(135deg, var(--color-info), #34A853);
    }

    .stat-icon.long-leaves {
        background: linear-gradient(135deg, var(--color-danger), #8B4513);
    }

    .stat-icon.half-days {
        background: linear-gradient(135deg, #8B7355, var(--color-secondary));
    }

    .stat-value {
        font-size: 2.5em;
        font-weight: 700;
        color: var(--color-text);
        line-height: 1;
        margin-bottom: 10px;
    }

    .stat-label {
        font-size: 0.95em;
        color: var(--color-secondary);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-subtext {
        font-size: 0.9em;
        color: var(--color-dark-gray);
        margin-top: 10px;
        font-style: italic;
    }

    /* Charts and Tables Section */
    .charts-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
        gap: 30px;
        margin-bottom: 40px;
    }

    .chart-card {
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
    }

    .chart-card h3 {
        font-size: 1.3em;
        color: var(--color-text);
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--color-border);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .chart-card h3 i {
        color: var(--color-primary);
    }

    .chart-container {
        height: 300px;
        position: relative;
    }

    /* Data Tables */
    .data-table-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
        gap: 30px;
        margin-bottom: 40px;
    }

    .table-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
    }

    .table-card h3 {
        font-size: 1.3em;
        color: var(--color-text);
        padding: 25px 25px 20px;
        margin: 0;
        border-bottom: 1px solid var(--color-border);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .table-card h3 i {
        color: var(--color-primary);
    }

    .table-responsive {
        overflow-x: auto;
        max-height: 400px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }

    .data-table th {
        text-align: left;
        padding: 16px 20px;
        background: rgba(212, 160, 23, 0.05);
        color: var(--color-text);
        font-weight: 600;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--color-border);
        position: sticky;
        top: 0;
        z-index: 10;
        white-space: nowrap;
    }

    .data-table td {
        padding: 15px 20px;
        border-bottom: 1px solid var(--color-border);
        vertical-align: middle;
        color: var(--color-dark-gray);
    }

    .data-table tr:hover {
        background: rgba(212, 160, 23, 0.03);
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    /* Progress bars for percentages */
    .progress-bar {
        width: 100%;
        height: 8px;
        background: rgba(212, 160, 23, 0.1);
        border-radius: 4px;
        overflow: hidden;
        margin-top: 6px;
    }

    .progress-fill {
        height: 100%;
        border-radius: 4px;
        background: linear-gradient(90deg, var(--color-primary), var(--color-primary-dark));
        transition: width 1s ease-out;
    }

    /* Department badges */
    .dept-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 500;
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-primary-dark);
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    /* Leave type color indicators */
    .color-indicator {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 8px;
        vertical-align: middle;
    }

    /* Export Section */
    .export-section {
        background: white;
        border-radius: 16px;
        padding: 30px;
        margin-top: 40px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        text-align: center;
    }

    .export-section h3 {
        font-size: 1.4em;
        color: var(--color-text);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .export-options {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-wrap: wrap;
        margin-top: 25px;
    }

    .export-btn {
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1em;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 180px;
        justify-content: center;
    }

    .export-btn.csv {
        background: linear-gradient(135deg, #34A853, #0F9D58);
        color: white;
    }

    .export-btn.pdf {
        background: linear-gradient(135deg, #EA4335, #D14836);
        color: white;
    }

    .export-btn.excel {
        background: linear-gradient(135deg, #0F9D58, #34A853);
        color: white;
    }

    .export-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--color-dark-gray);
    }

    .empty-state i {
        font-size: 4em;
        color: rgba(212, 160, 23, 0.3);
        margin-bottom: 25px;
    }

    .empty-state h3 {
        font-size: 1.5em;
        color: var(--color-text);
        margin-bottom: 15px;
        font-weight: 600;
    }

    .empty-state p {
        max-width: 400px;
        margin: 0 auto 25px;
        font-size: 1em;
        line-height: 1.6;
    }

    /* Responsive */
    @media (max-width: 1200px) {

        .charts-section,
        .data-table-section {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }

        .page-title {
            font-size: 1.8em;
        }

        .filter-grid {
            grid-template-columns: 1fr;
        }

        .date-range {
            flex-direction: column;
            gap: 15px;
        }

        .date-input {
            width: 100%;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .chart-card,
        .table-card {
            padding: 20px;
        }

        .export-options {
            flex-direction: column;
            align-items: center;
        }

        .export-btn {
            width: 100%;
            max-width: 300px;
        }
    }

    @media (max-width: 480px) {
        .page-title {
            font-size: 1.6em;
        }

        .stat-value {
            font-size: 2em;
        }

        .data-table {
            font-size: 0.9em;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
        }
    }
</style>

<div class="content-area">
   

    <!-- Filter Section -->
    <div class="filter-section">
        <h3 class="filter-title">
            <i class="fas fa-filter"></i>
            Filter Reports
        </h3>

        <form method="GET" action="" id="reportFilters">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Period</label>
                    <select name="period" class="filter-select" onchange="updatePeriod(this.value)">
                        <option value="monthly" <?php echo $filters['period'] == 'monthly' ? 'selected' : ''; ?>>Monthly
                        </option>
                        <option value="quarterly" <?php echo $filters['period'] == 'quarterly' ? 'selected' : ''; ?>
                            >Quarterly</option>
                        <option value="yearly" <?php echo $filters['period'] == 'yearly' ? 'selected' : ''; ?>>Yearly
                        </option>
                        <option value="custom" <?php echo $filters['period'] == 'custom' ? 'selected' : ''; ?>>Custom
                            Range</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Department</label>
                    <select name="department" class="filter-select">
                        <option value="all" <?php echo $filters['department'] == 'all' ? 'selected' : ''; ?>>All
                            Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filters['department'] == $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Leave Type</label>
                    <select name="leave_type" class="filter-select">
                        <option value="all" <?php echo $filters['leave_type'] == 'all' ? 'selected' : ''; ?>>All Types
                        </option>
                        <?php foreach ($leave_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $filters['leave_type'] == $type['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?php echo $filters['status'] == 'all' ? 'selected' : ''; ?>>All Status
                        </option>
                        <option value="approved" <?php echo $filters['status'] == 'approved' ? 'selected' : ''; ?>
                            >Approved</option>
                        <option value="pending" <?php echo $filters['status'] == 'pending' ? 'selected' : ''; ?>>Pending
                        </option>
                        <option value="rejected" <?php echo $filters['status'] == 'rejected' ? 'selected' : ''; ?>
                            >Rejected</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Year</label>
                    <select name="year" class="filter-select">
                        <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $filters['year'] == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="date-range" id="customDateRange"
                style="display: <?php echo $filters['period'] == 'custom' ? 'flex' : 'none'; ?>;">
                <div class="date-input">
                    <label class="filter-label">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $filters['start_date']; ?>"
                        class="form-control">
                </div>
                <div class="date-input">
                    <label class="filter-label">End Date</label>
                    <input type="date" name="end_date" value="<?php echo $filters['end_date']; ?>" class="form-control">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-chart-line"></i>
                    Generate Report
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                    <i class="fas fa-redo"></i>
                    Reset Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['total_leaves'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Total Leaves</div>
                </div>
                <div class="stat-icon total-leaves">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </div>
            <div class="stat-subtext">Approved leave requests</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['total_days'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Total Days</div>
                </div>
                <div class="stat-icon total-days">
                    <i class="fas fa-calendar-day"></i>
                </div>
            </div>
            <div class="stat-subtext">Total leave days taken</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['avg_days_per_leave'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Avg Days/Leave</div>
                </div>
                <div class="stat-icon avg-days">
                    <i class="fas fa-calculator"></i>
                </div>
            </div>
            <div class="stat-subtext">Average duration per leave</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['unique_employees'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Employees</div>
                </div>
                <div class="stat-icon unique-employees">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="stat-subtext">Unique employees on leave</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['long_leaves'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Long Leaves</div>
                </div>
                <div class="stat-icon long-leaves">
                    <i class="fas fa-calendar-week"></i>
                </div>
            </div>
            <div class="stat-subtext">Leaves > 7 days</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo $summary_stats['half_day_leaves'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Half Days</div>
                </div>
                <div class="stat-icon half-days">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
            <div class="stat-subtext">Half day leave requests</div>
        </div>
    </div>

    <!-- Department Statistics -->
    <div class="table-card">
        <h3><i class="fas fa-building"></i> Department Analysis</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Leaves</th>
                        <th>Total Days</th>
                        <th>Employees</th>
                        <th>Avg Days/Employee</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($department_stats)): ?>
                        <tr>
                            <td colspan="6" class="empty-state" style="text-align: center; padding: 40px;">
                                <i class="fas fa-chart-pie"
                                    style="font-size: 2em; color: rgba(212, 160, 23, 0.3); margin-bottom: 15px;"></i>
                                <div style="color: var(--color-dark-gray);">No department data available</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($department_stats as $dept): ?>
                            <tr>
                                <td>
                                    <span class="dept-badge">
                                        <i class="fas fa-building"></i>
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $dept['leave_count']; ?>
                                </td>
                                <td>
                                    <?php echo $dept['total_days']; ?>
                                </td>
                                <td>
                                    <?php echo $dept['employee_count']; ?>
                                </td>
                                <td>
                                    <?php echo $dept['avg_days_per_employee']; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span>
                                            <?php echo $dept['percentage']; ?>%
                                        </span>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $dept['percentage']; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Leave Type Statistics -->
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-tags"></i> Leave Type Distribution</h3>
            <div class="chart-container">
                <canvas id="leaveTypeChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-chart-line"></i> Monthly Trends (
                <?php echo $filters['year']; ?>)
            </h3>
            <div class="chart-container">
                <canvas id="monthlyTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Employee Usage -->
    <div class="data-table-section">
        <div class="table-card">
            <h3><i class="fas fa-user-clock"></i> Top Employees by Leave Usage</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Leaves</th>
                            <th>Total Days</th>
                            <th>Last Leave</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employee_usage)): ?>
                            <tr>
                                <td colspan="6" class="empty-state" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-users"
                                        style="font-size: 2em; color: rgba(212, 160, 23, 0.3); margin-bottom: 15px;"></i>
                                    <div style="color: var(--color-dark-gray);">No employee data available</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employee_usage as $employee): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500;">
                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                        </div>
                                        <div style="font-size: 0.85em; color: var(--color-dark-gray);">
                                            <?php echo htmlspecialchars($employee['email']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="dept-badge">
                                            <i class="fas fa-building"></i>
                                            <?php echo htmlspecialchars($employee['department']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $employee['leave_count']; ?>
                                    </td>
                                    <td>
                                        <?php echo $employee['total_days']; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($employee['last_leave_date'])); ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span>
                                                <?php echo $employee['percentage_of_total']; ?>%
                                            </span>
                                            <div class="progress-bar">
                                                <div class="progress-fill"
                                                    style="width: <?php echo $employee['percentage_of_total']; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <h3><i class="fas fa-list-alt"></i> Leave Type Breakdown</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Leaves</th>
                            <th>Total Days</th>
                            <th>Employees</th>
                            <th>Avg Days</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leave_type_stats)): ?>
                            <tr>
                                <td colspan="6" class="empty-state" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-tag"
                                        style="font-size: 2em; color: rgba(212, 160, 23, 0.3); margin-bottom: 15px;"></i>
                                    <div style="color: var(--color-dark-gray);">No leave type data available</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leave_type_stats as $type): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="color-indicator"
                                                style="background-color: <?php echo $type['color']; ?>"></span>
                                            <?php echo htmlspecialchars($type['name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $type['leave_count']; ?>
                                    </td>
                                    <td>
                                        <?php echo $type['total_days']; ?>
                                    </td>
                                    <td>
                                        <?php echo $type['employee_count']; ?>
                                    </td>
                                    <td>
                                        <?php echo $type['avg_days_per_leave']; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span>
                                                <?php echo $type['percentage']; ?>%
                                            </span>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $type['percentage']; ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Export Section -->
    <div class="export-section">
        <h3><i class="fas fa-file-export"></i> Export Reports</h3>
        <p>Download comprehensive reports in various formats for further analysis</p>

        <div class="export-options">
            <button type="button" class="export-btn csv" onclick="exportReport('csv')">
                <i class="fas fa-file-csv"></i>
                Export as CSV
            </button>
            <button type="button" class="export-btn pdf" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf"></i>
                Export as PDF
            </button>
            <button type="button" class="export-btn excel" onclick="exportReport('excel')">
                <i class="fas fa-file-excel"></i>
                Export as Excel
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Update period filter visibility
        window.updatePeriod = function (period) {
            const dateRange = document.getElementById('customDateRange');
            dateRange.style.display = period === 'custom' ? 'flex' : 'none';
        };

        // Reset filters
        window.resetFilters = function () {
            document.getElementById('reportFilters').reset();
            window.updatePeriod('monthly');
            document.querySelector('select[name="period"]').value = 'monthly';
            document.querySelector('select[name="department"]').value = 'all';
            document.querySelector('select[name="leave_type"]').value = 'all';
            document.querySelector('select[name="status"]').value = 'all';
            document.querySelector('select[name="year"]').value = '<?php echo date("Y"); ?>';

            // Set default dates
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);

            document.querySelector('input[name="start_date"]').value = formatDate(firstDay);
            document.querySelector('input[name="end_date"]').value = formatDate(lastDay);
        };

        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Export report
        window.exportReport = function (format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);

            showNotification(`Preparing ${format.toUpperCase()} export...`, 'info');

            // Add loading state to buttons
            const buttons = document.querySelectorAll('.export-btn');
            buttons.forEach(btn => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                btn.disabled = true;

                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }, 2000);
            });

            // Trigger download
            setTimeout(() => {
                window.location.href = `api/export-reports.php?${params.toString()}`;
            }, 1500);
        };

        // Initialize charts
        initializeCharts();

        function initializeCharts() {
            // Leave Type Chart
            const leaveTypeCtx = document.getElementById('leaveTypeChart').getContext('2d');
            const leaveTypeData = {
                labels: [
                    <?php foreach ($leave_type_stats as $type): ?>
                        '<?php echo addslashes($type['name']); ?>',
                    <?php endforeach; ?>
                ],
            datasets: [{
                data: [
                        <?php foreach ($leave_type_stats as $type): ?>
                                <?php echo $type['total_days']; ?>,
                        <?php endforeach; ?>
                    ],
            backgroundColor: [
                        <?php foreach ($leave_type_stats as $type): ?>
                    '<?php echo $type['color']; ?>',
                        <?php endforeach; ?>
                    ],
            borderWidth: 1,
                borderColor: 'rgba(255, 255, 255, 0.8)'
        }]
    };

    new Chart(leaveTypeCtx, {
        type: 'doughnut',
        data: leaveTypeData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        font: {
                            size: 11
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} days (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

    // Monthly Trends Chart
    const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
    const monthlyData = {
        labels: [
                    <?php foreach ($monthly_trends as $trend): ?>
                '<?php echo substr($trend['month_name'], 0, 3); ?>',
                    <?php endforeach; ?>
                ],
    datasets: [{
        label: 'Total Days',
        data: [
                        <?php foreach ($monthly_trends as $trend): ?>
                                <?php echo $trend['total_days']; ?>,
                        <?php endforeach; ?>
                    ],
        backgroundColor: 'rgba(212, 160, 23, 0.1)',
        borderColor: '#D4A017',
        borderWidth: 2,
        fill: true,
        tension: 0.4
    }, {
        label: 'Leave Count',
        data: [
                        <?php foreach ($monthly_trends as $trend): ?>
                                <?php echo $trend['leave_count']; ?>,
                        <?php endforeach; ?>
                    ],
        backgroundColor: 'rgba(184, 134, 11, 0.1)',
        borderColor: '#B8860B',
        borderWidth: 2,
        fill: true,
        tension: 0.4
    }]
            };

    new Chart(monthlyCtx, {
        type: 'line',
        data: monthlyData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            size: 12
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(212, 160, 23, 0.1)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(212, 160, 23, 0.1)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                }
            }
        }
    });
        }

    // Show notification
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `custom-notification notification-${type}`;

        const bgColor = type === 'success' ? '#D4A017' :
            type === 'error' ? '#8B4513' :
                type === 'warning' ? '#CD853F' : '#4285F4';

        notification.innerHTML = `
                <div style="
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 16px 22px;
                    background: ${bgColor};
                    color: white;
                    border-radius: 12px;
                    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                    z-index: 1000;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    animation: slideIn 0.3s ease-out;
                    font-weight: 500;
                    min-width: 300px;
                    max-width: 400px;
                ">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-exclamation-circle' :
                    type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    <span style="flex: 1;">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="
                        background: none;
                        border: none;
                        color: white;
                        cursor: pointer;
                        font-size: 20px;
                        padding: 0;
                        width: 24px;
                        height: 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-radius: 50%;
                        opacity: 0.7;
                    ">
                        &times;
                    </button>
                </div>
            `;

        document.body.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => notification.remove(), 300);
            }
        }, 5000);
    }

    // Add CSS for animations if not already present
    if (!document.querySelector('#notification-animations')) {
        const style = document.createElement('style');
        style.id = 'notification-animations';
        style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
        document.head.appendChild(style);
    }
    });
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>