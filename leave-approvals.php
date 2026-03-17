<?php
// leave-approvals.php - Leave Approval Management
$page_title = "Leave Approvals";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user has approval permissions
$can_approve = $current_user['role'] === 'admin' || $current_user['role'] === 'ceo';
if (!$can_approve) {
    header('Location: dashboard.php');
    exit();
}

$pdo = getPDOConnection();
$pending_leaves = [];
$recent_approvals = [];
$stats = [];
$departments = [];
$leave_types = [];

// Date ranges
$date_ranges = [
    'today' => ['name' => 'Today', 'start' => date('Y-m-d'), 'end' => date('Y-m-d')],
    'this_week' => ['name' => 'This Week', 'start' => date('Y-m-d', strtotime('monday this week')), 'end' => date('Y-m-d', strtotime('sunday this week'))],
    'this_month' => ['name' => 'This Month', 'start' => date('Y-m-01'), 'end' => date('Y-m-t')],
    'last_month' => ['name' => 'Last Month', 'start' => date('Y-m-01', strtotime('last month')), 'end' => date('Y-m-t', strtotime('last month'))],
    'this_year' => ['name' => 'This Year', 'start' => date('Y-01-01'), 'end' => date('Y-12-31')]
];

// Filters
$filters = [
    'department' => $_GET['department'] ?? 'all',
    'leave_type' => $_GET['leave_type'] ?? 'all',
    'date_range' => $_GET['date_range'] ?? 'this_month',
    'search' => $_GET['search'] ?? ''
];

if ($pdo) {
    try {
        // Get departments
        $stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get leave types
        $stmt = $pdo->query("SELECT id, name, color FROM leave_types WHERE is_active = 1 ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get pending leaves
        list($pending_leaves, $pending_count) = getFilteredLeaves('pending', $filters);

        // Get recent approvals (last 7 days)
        $stmt = $pdo->prepare("
            SELECT 
                l.*,
                u.full_name,
                u.email,
                u.department,
                u.position,
                lt.name as leave_type_name,
                lt.color as leave_type_color,
                a.full_name as approved_by_name,
                DATE(l.approved_at) as approval_date
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE l.status = 'approved'
            AND l.approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY l.approved_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recent_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get approval statistics
        $stats = getApprovalStats();

    } catch (PDOException $e) {
        error_log("Error fetching approvals data: " . $e->getMessage());
    }
}

function getFilteredLeaves($status, $filters)
{
    global $pdo, $date_ranges;

    $where_conditions = ["l.status = ?"];
    $params = [$status];

    // Department filter
    if ($filters['department'] !== 'all') {
        $where_conditions[] = "u.department = ?";
        $params[] = $filters['department'];
    }

    // Leave type filter
    if ($filters['leave_type'] !== 'all') {
        $where_conditions[] = "l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    // Date range filter
    if ($filters['date_range'] !== 'all') {
        $range = $date_ranges[$filters['date_range']];
        $where_conditions[] = "l.start_date BETWEEN ? AND ?";
        $params[] = $range['start'];
        $params[] = $range['end'];
    }

    // Search filter
    if (!empty($filters['search'])) {
        $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR l.reason LIKE ?)";
        $search_term = "%{$filters['search']}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get count
    $count_sql = "
        SELECT COUNT(*) as total
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        $where_clause
    ";

    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_count = $stmt->fetchColumn();

    // Get leaves
    $sql = "
        SELECT 
            l.*,
            u.full_name,
            u.email,
            u.department,
            u.position,
            lt.name as leave_type_name,
            lt.color as leave_type_color,
            DATEDIFF(l.end_date, l.start_date) + 1 as total_calendar_days,
            CASE 
                WHEN l.source = 'google_forms' THEN 'Google Forms'
                ELSE 'Dashboard'
            END as request_source
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        $where_clause
        ORDER BY l.created_at ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [$leaves, $total_count];
}

function getApprovalStats()
{
    global $pdo;

    $stats = [
        'pending' => 0,
        'approved_today' => 0,
        'approved_week' => 0,
        'rejected_today' => 0,
        'avg_approval_time' => 0
    ];

    try {
        // Pending count
        $stmt = $pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'pending'");
        $stats['pending'] = $stmt->fetchColumn();

        // Approved today
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM leaves 
            WHERE status = 'approved' 
            AND DATE(approved_at) = CURDATE()
        ");
        $stmt->execute();
        $stats['approved_today'] = $stmt->fetchColumn();

        // Approved this week
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM leaves 
            WHERE status = 'approved' 
            AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $stats['approved_week'] = $stmt->fetchColumn();

        // Rejected today
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM leaves 
            WHERE status = 'rejected' 
            AND DATE(rejected_at) = CURDATE()
        ");
        $stmt->execute();
        $stats['rejected_today'] = $stmt->fetchColumn();

        // Average approval time (hours)
        $stmt = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, approved_at)) 
            FROM leaves 
            WHERE status = 'approved' 
            AND approved_at IS NOT NULL
        ");
        $avg_hours = $stmt->fetchColumn();
        $stats['avg_approval_time'] = $avg_hours ? round($avg_hours, 1) : 0;

    } catch (PDOException $e) {
        error_log("Error fetching approval stats: " . $e->getMessage());
    }

    return $stats;
}
?>

<style>
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

    /* Loading Overlay */
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        flex-direction: column;
    }

    .loading-overlay.active {
        display: flex;
        animation: fadeIn 0.3s ease;
    }

    .loading-spinner {
        width: 50px;
        height: 50px;
        border: 4px solid rgba(212, 160, 23, 0.2);
        border-top: 4px solid var(--color-primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 20px;
    }

    .loading-message {
        font-size: 1.1em;
        color: var(--color-text);
        font-weight: 600;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
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

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
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
        background: var(--card-color, var(--color-primary));
    }

    .stat-card.pending::before {
        background: var(--color-warning);
    }

    .stat-card.approved::before {
        background: var(--color-success);
    }

    .stat-card.rejected::before {
        background: var(--color-danger);
    }

    .stat-card.average::before {
        background: var(--color-info);
    }

    .stat-card .stat-value {
        font-size: 2.5em;
        font-weight: 700;
        color: var(--color-text);
        line-height: 1;
        margin-bottom: 10px;
    }

    .stat-card .stat-label {
        font-size: 0.95em;
        color: var(--color-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }

    .stat-card .stat-detail {
        font-size: 0.9em;
        color: var(--color-dark-gray);
        font-style: italic;
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

    .filter-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .filter-title {
        font-size: 1.2em;
        color: var(--color-text);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .filter-title i {
        color: var(--color-primary);
    }

    .search-box {
        position: relative;
        min-width: 300px;
        flex: 1;
        max-width: 400px;
    }

    .search-box input {
        width: 100%;
        padding: 14px 45px 14px 20px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-size: 0.95em;
        transition: all 0.3s ease;
        background: white;
        color: var(--color-text);
    }

    .search-box input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .search-box i {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--color-secondary);
        font-size: 1.1em;
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

    .filter-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--color-border);
        flex-wrap: wrap;
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

    /* Pending Leaves Section */
    .pending-section {
        margin-bottom: 40px;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .section-title {
        font-size: 1.5em;
        color: var(--color-text);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .section-title i {
        color: var(--color-primary);
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        background: rgba(245, 158, 11, 0.1);
        color: #F59E0B;
        border: 1px solid rgba(245, 158, 11, 0.2);
    }

    /* Leaves Table */
    .leaves-table-container {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        margin-bottom: 30px;
    }

    .leaves-table {
        width: 100%;
        border-collapse: collapse;
    }

    .leaves-table thead {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.9), rgba(184, 134, 11, 0.95));
    }

    .leaves-table th {
        padding: 18px 20px;
        text-align: left;
        color: white;
        font-weight: 600;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .leaves-table th:first-child {
        border-top-left-radius: 16px;
    }

    .leaves-table th:last-child {
        border-top-right-radius: 16px;
    }

    .leaves-table tbody tr {
        border-bottom: 1px solid var(--color-border);
        transition: all 0.3s ease;
    }

    .leaves-table tbody tr:hover {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.03), rgba(212, 160, 23, 0.01));
    }

    .leaves-table tbody tr:last-child {
        border-bottom: none;
    }

    .leaves-table td {
        padding: 20px;
        color: var(--color-text);
        font-size: 0.95em;
        vertical-align: middle;
    }

    .employee-cell {
        display: flex;
        align-items: center;
        gap: 15px;
        min-width: 250px;
    }

    .employee-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 1.2em;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.25);
    }

    .employee-info h4 {
        font-size: 1.05em;
        color: var(--color-text);
        margin-bottom: 5px;
        font-weight: 600;
    }

    .employee-info p {
        color: var(--color-dark-gray);
        font-size: 0.85em;
        margin-bottom: 2px;
    }

    .leave-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-primary-dark);
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .dates-cell {
        min-width: 180px;
    }

    .date-range {
        font-weight: 600;
        color: var(--color-text);
        margin-bottom: 5px;
    }

    .days-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        box-shadow: 0 3px 10px rgba(212, 160, 23, 0.2);
    }

    .half-day-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 8px;
        padding: 3px 8px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.2);
        font-size: 0.8em;
    }

    .reason-cell {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .reason-cell:hover {
        overflow: visible;
        white-space: normal;
        position: relative;
        z-index: 2;
        background: white;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        padding: 15px;
    }

    .action-cell {
        min-width: 200px;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
    }

    .btn-action {
        padding: 10px 20px;
        border-radius: 10px;
        font-size: 0.9em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 100px;
        justify-content: center;
    }

    .btn-approve {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        box-shadow: 0 3px 10px rgba(212, 160, 23, 0.2);
    }

    .btn-approve:hover {
        background: linear-gradient(135deg, var(--color-primary-dark), #D2691E);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(212, 160, 23, 0.3);
    }

    .btn-reject {
        background: linear-gradient(135deg, var(--color-danger), #8B4513);
        color: white;
        box-shadow: 0 3px 10px rgba(139, 69, 19, 0.2);
    }

    .btn-reject:hover {
        background: linear-gradient(135deg, #8B4513, #A0522D);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(139, 69, 19, 0.3);
    }

    .btn-view {
        background: white;
        color: var(--color-primary-dark);
        border: 2px solid var(--color-border);
    }

    .btn-view:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(212, 160, 23, 0.2);
    }

    /* Recent Approvals */
    .approvals-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 25px;
    }

    .approval-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
    }

    .approval-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 35px rgba(212, 160, 23, 0.15);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .approval-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .approval-card .card-header {
        padding: 25px 25px 15px;
        border-bottom: 1px solid var(--color-border);
        position: relative;
    }

    .approval-card .status-badge {
        position: absolute;
        top: 20px;
        right: 20px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(16, 185, 129, 0.1);
        color: #10B981;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .approval-card .approval-info {
        font-size: 0.85em;
        color: var(--color-dark-gray);
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--color-border);
    }

    .approval-card .card-body {
        padding: 20px 25px;
    }

    .approval-card .card-footer {
        padding: 20px 25px;
        border-top: 1px solid var(--color-border);
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.03), rgba(212, 160, 23, 0.01));
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        color: var(--color-dark-gray);
        grid-column: 1 / -1;
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

    /* Modal Styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        width: 90%;
        max-width: 500px;
        max-height: 80vh;
        overflow-y: auto;
        padding: 30px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        border: 1px solid var(--color-border);
        animation: slideUp 0.3s ease;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--color-border);
    }

    .modal-header h3 {
        color: var(--color-text);
        font-size: 1.5em;
        margin: 0;
        font-weight: 700;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .close-modal {
        background: white;
        border: 2px solid var(--color-border);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4em;
        color: var(--color-primary-dark);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .close-modal:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        transform: rotate(90deg);
        border-color: var(--color-primary);
    }

    .modal-form-group {
        margin-bottom: 20px;
    }

    .modal-form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.95em;
    }

    .modal-form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 1em;
        color: var(--color-text);
        background: white;
        transition: all 0.3s ease;
        resize: vertical;
        min-height: 100px;
    }

    .modal-form-control:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .modal-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid var(--color-border);
    }

    /* Animations */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(40px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .approvals-grid {
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }
    }

    @media (max-width: 992px) {
        .leaves-table {
            display: block;
            overflow-x: auto;
        }

        .employee-cell {
            min-width: 200px;
        }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }

        .page-title {
            font-size: 1.8em;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .filter-header {
            flex-direction: column;
            align-items: stretch;
        }

        .search-box {
            min-width: 100%;
            max-width: 100%;
        }

        .filter-grid {
            grid-template-columns: 1fr;
        }

        .leaves-table th,
        .leaves-table td {
            padding: 15px;
        }

        .action-buttons {
            flex-direction: column;
            gap: 8px;
        }

        .btn-action {
            min-width: auto;
            width: 100%;
        }

        .approvals-grid {
            grid-template-columns: 1fr;
        }

        .modal-content {
            width: 95%;
            padding: 20px;
        }
    }

    @media (max-width: 576px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .page-title {
            font-size: 1.6em;
        }

        .employee-cell {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .leaves-table th,
        .leaves-table td {
            padding: 12px 10px;
            font-size: 0.9em;
        }

        .modal-actions {
            flex-direction: column;
        }
    }
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-message" id="loadingMessage">Processing request...</div>
</div>

<div class="content-area">
    <!-- Page Header -->
    

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card pending">
            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="stat-label">Pending Requests</div>
            <div class="stat-detail">Awaiting your review</div>
        </div>
        <div class="stat-card approved">
            <div class="stat-value"><?php echo $stats['approved_today'] ?? 0; ?></div>
            <div class="stat-label">Approved Today</div>
            <div class="stat-detail"><?php echo date('M j, Y'); ?></div>
        </div>
        <div class="stat-card approved">
            <div class="stat-value"><?php echo $stats['approved_week'] ?? 0; ?></div>
            <div class="stat-label">Approved This Week</div>
            <div class="stat-detail">Last 7 days</div>
        </div>
        <div class="stat-card average">
            <div class="stat-value"><?php echo $stats['avg_approval_time'] ?? 0; ?>h</div>
            <div class="stat-label">Avg. Approval Time</div>
            <div class="stat-detail">From submission to approval</div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-header">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Pending Requests
            </h3>
            <form method="GET" action="" class="search-box">
                <input type="text" name="search" placeholder="Search by employee name, email, or reason..."
                    value="<?php echo htmlspecialchars($filters['search']); ?>">
                <i class="fas fa-search"></i>
            </form>
        </div>

        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>">
            
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Department</label>
                    <select name="department" class="filter-select">
                        <option value="all" <?php echo $filters['department'] == 'all' ? 'selected' : ''; ?>>All Departments</option>
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
                        <option value="all" <?php echo $filters['leave_type'] == 'all' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($leave_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo $filters['leave_type'] == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Date Range</label>
                    <select name="date_range" class="filter-select">
                        <?php foreach ($date_ranges as $key => $range): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filters['date_range'] == $key ? 'selected' : ''; ?>>
                                    <?php echo $range['name']; ?>
                                </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <a href="leave-approvals.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i>
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Pending Leaves Section -->
    <div class="pending-section">
        <div class="section-header">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Pending Leave Requests
                <?php if (!empty($pending_leaves)): ?>
                        <span class="badge">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo count($pending_leaves); ?> requests
                        </span>
                <?php endif; ?>
            </h3>
        </div>

        <?php if (empty($pending_leaves)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>No Pending Requests</h3>
                    <p>All leave requests have been processed. Check back later for new submissions.</p>
                </div>
        <?php else: ?>
                <div class="leaves-table-container">
                    <table class="leaves-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Dates</th>
                                <th>Duration</th>
                                <th>Reason</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_leaves as $leave):
                                $start_date = new DateTime($leave['start_date']);
                                $end_date = new DateTime($leave['end_date']);
                                $applied_date = new DateTime($leave['created_at']);
                                ?>
                                    <tr id="leave-row-<?php echo $leave['id']; ?>">
                                        <td>
                                            <div class="employee-cell">
                                                <div class="employee-avatar">
                                                    <?php echo strtoupper(substr($leave['full_name'], 0, 1)); ?>
                                                </div>
                                                <div class="employee-info">
                                                    <h4><?php echo htmlspecialchars($leave['full_name']); ?></h4>
                                                    <p><?php echo htmlspecialchars($leave['email']); ?></p>
                                                    <p><i class="fas fa-building"></i> <?php echo htmlspecialchars($leave['department'] ?? 'No Department'); ?></p>
                                                    <p><i class="fas fa-calendar"></i> Applied: <?php echo $applied_date->format('M j, Y'); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="leave-type-badge" style="border-left-color: <?php echo $leave['leave_type_color']; ?>;">
                                                <i class="fas fa-calendar-alt" style="color: <?php echo $leave['leave_type_color']; ?>;"></i>
                                                <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                            </span>
                                        </td>
                                        <td class="dates-cell">
                                            <div class="date-range">
                                                <?php echo $start_date->format('M j'); ?> - <?php echo $end_date->format('M j, Y'); ?>
                                            </div>
                                            <div style="font-size: 0.85em; color: var(--color-dark-gray);">
                                                <?php echo $start_date->format('D') . ' to ' . $end_date->format('D'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="days-badge">
                                                <?php echo $leave['total_days']; ?> days
                                                <?php if ($leave['half_day'] !== 'none'): ?>
                                                        <span class="half-day-badge" title="Half Day: <?php echo ucfirst($leave['half_day']); ?>">
                                                            <i class="fas fa-clock"></i>½
                                                        </span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="reason-cell" title="<?php echo htmlspecialchars($leave['reason'] ?? 'No reason provided'); ?>">
                                            <?php echo !empty($leave['reason']) ? htmlspecialchars(substr($leave['reason'], 0, 50)) . (strlen($leave['reason']) > 50 ? '...' : '') : 'No reason provided'; ?>
                                        </td>
                                        <td class="action-cell">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-approve" onclick="approveLeave(<?php echo $leave['id']; ?>)">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn-action btn-reject" onclick="showRejectModal(<?php echo $leave['id']; ?>)">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                                <button class="btn-action btn-view" onclick="viewLeave(<?php echo $leave['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
        <?php endif; ?>
    </div>

    <!-- Recent Approvals Section -->
    <?php if (!empty($recent_approvals)): ?>
            <div class="pending-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-history"></i>
                        Recent Approvals
                    </h3>
                </div>

                <div class="approvals-grid">
                    <?php foreach ($recent_approvals as $approval):
                        $start_date = new DateTime($approval['start_date']);
                        $end_date = new DateTime($approval['end_date']);
                        $approved_date = new DateTime($approval['approval_date']);
                        ?>
                            <div class="approval-card">
                                <div class="card-header">
                                    <span class="status-badge">
                                        <i class="fas fa-check-circle"></i> Approved
                                    </span>
                            
                                    <div class="employee-cell">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($approval['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="employee-info">
                                            <h4><?php echo htmlspecialchars($approval['full_name']); ?></h4>
                                            <p><?php echo htmlspecialchars($approval['department'] ?? 'No Department'); ?></p>
                                        </div>
                                    </div>
                            
                                    <div class="approval-info">
                                        <div><i class="fas fa-user-check"></i> Approved by: <?php echo htmlspecialchars($approval['approved_by_name'] ?? 'System'); ?></div>
                                        <div><i class="fas fa-calendar-check"></i> On: <?php echo $approved_date->format('M j, Y g:i A'); ?></div>
                                    </div>
                                </div>
                        
                                <div class="card-body">
                                    <div class="leave-type-badge" style="border-left-color: <?php echo $approval['leave_type_color']; ?>; margin-bottom: 15px;">
                                        <i class="fas fa-calendar-alt" style="color: <?php echo $approval['leave_type_color']; ?>;"></i>
                                        <?php echo htmlspecialchars($approval['leave_type_name']); ?>
                                    </div>
                            
                                    <div style="margin-bottom: 15px;">
                                        <strong>Dates:</strong> <?php echo $start_date->format('M j'); ?> - <?php echo $end_date->format('M j, Y'); ?>
                                        <span class="days-badge" style="margin-left: 10px;">
                                            <?php echo $approval['total_days']; ?> days
                                        </span>
                                    </div>
                            
                                    <?php if (!empty($approval['reason'])): ?>
                                            <div style="font-size: 0.9em; color: var(--color-text);">
                                                <strong>Reason:</strong> <?php echo htmlspecialchars($approval['reason']); ?>
                                            </div>
                                    <?php endif; ?>
                                </div>
                        
                                <div class="card-footer">
                                    <span style="font-size: 0.85em; color: var(--color-dark-gray);">
                                        <i class="fas fa-clock"></i> 
                                        <?php
                                        $created = new DateTime($approval['created_at']);
                                        $approved = new DateTime($approval['approved_at']);
                                        $diff = $approved->diff($created);
                                        echo $diff->days . ' days, ' . $diff->h . ' hours for approval';
                                        ?>
                                    </span>
                                    <button class="btn-action btn-view" onclick="viewLeave(<?php echo $approval['id']; ?>)">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </div>
                            </div>
                    <?php endforeach; ?>
                </div>
            </div>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Leave Request</h3>
            <button class="close-modal" onclick="closeRejectModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <p style="margin-bottom: 20px; color: var(--color-text);">
                Please provide a reason for rejecting this leave request. The employee will be notified with this reason.
            </p>
            
            <div class="modal-form-group">
                <label for="rejectionReason">Rejection Reason *</label>
                <textarea id="rejectionReason" class="modal-form-control" placeholder="Enter reason for rejection..." required></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmRejectBtn">
                    <i class="fas fa-check"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Global variables
    let currentLeaveId = null;
    let isProcessing = false;

    document.addEventListener('DOMContentLoaded', function () {
        // Initialize date pickers
        initDatePickers();
        
        // Auto-submit search on enter
        document.querySelector('.search-box input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const searchValue = this.value;
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchValue);
                window.location.href = url.toString();
            }
        });
    });

    function initDatePickers() {
        // Initialize any date pickers if needed
    }

    // Show/hide loading overlay
    function showLoading(message = 'Processing request...') {
        isProcessing = true;
        const overlay = document.getElementById('loadingOverlay');
        const messageEl = document.getElementById('loadingMessage');
        messageEl.textContent = message;
        overlay.classList.add('active');
    }

    function hideLoading() {
        isProcessing = false;
        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.remove('active');
    }

    // Approve leave function - UPDATED to match API
    function approveLeave(leaveId) {
        if (isProcessing) return;
        
        if (!confirm('Are you sure you want to approve this leave request?')) {
            return;
        }

        showLoading('Approving leave request...');
        disableActionButtons(leaveId);

        // Create data object matching API requirements
        const requestData = {
            id: leaveId,
            notes: '' // Optional notes field
        };

        fetch('api/approve-leave.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            hideLoading();
            enableActionButtons(leaveId);
            
            if (data.success) {
                const emailStatus = data.email_sent ? 'Email notification sent.' : 'Email notification failed.';
                showNotification(`Leave approved successfully! ${emailStatus}`, 'success');
                
                // Remove the row from the table
                removeLeaveRow(leaveId, 'left');
                
                // Update stats after delay
                setTimeout(updateStats, 2000);
            } else {
                showNotification(data.message || 'Error approving leave request', 'error');
            }
        })
        .catch(error => {
            hideLoading();
            enableActionButtons(leaveId);
            console.error('Error:', error);
            showNotification('An error occurred while approving leave. Please try again.', 'error');
        });
    }

    // Reject leave modal functions - UPDATED to match API
    function showRejectModal(leaveId) {
        if (isProcessing) return;
        
        currentLeaveId = leaveId;
        const modal = document.getElementById('rejectModal');
        modal.style.display = 'flex';
        document.getElementById('rejectionReason').value = '';
        
        // Set up confirm button
        const confirmBtn = document.getElementById('confirmRejectBtn');
        confirmBtn.onclick = () => rejectLeave(leaveId);
    }

    function closeRejectModal() {
        const modal = document.getElementById('rejectModal');
        modal.style.display = 'none';
        document.getElementById('rejectionReason').value = '';
        currentLeaveId = null;
    }

    function rejectLeave(leaveId) {
        if (isProcessing) return;
        
        const reason = document.getElementById('rejectionReason').value.trim();
        
        if (!reason) {
            showNotification('Please provide a reason for rejection', 'warning');
            return;
        }

        showLoading('Rejecting leave request...');
        disableActionButtons(leaveId);

        // Create data object matching API requirements
        const requestData = {
            id: leaveId,
            reason: reason
        };

        fetch('api/reject-leave.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            hideLoading();
            enableActionButtons(leaveId);
            
            if (data.success) {
                closeRejectModal();
                const emailStatus = data.email_sent ? 'Email notification sent.' : 'Email notification failed.';
                showNotification(`Leave rejected successfully. ${emailStatus}`, 'success');
                
                // Remove the row from the table
                removeLeaveRow(leaveId, 'right');
                
                // Update stats after delay
                setTimeout(updateStats, 2000);
            } else {
                showNotification(data.message || 'Error rejecting leave request', 'error');
            }
        })
        .catch(error => {
            hideLoading();
            enableActionButtons(leaveId);
            console.error('Error:', error);
            showNotification('An error occurred while rejecting leave. Please try again.', 'error');
        });
    }

    // View leave details
   function viewLeave(leaveId) {
    window.location.href = `view-leave.php?id=${leaveId}`;
}


    // Remove leave row with animation
    function removeLeaveRow(leaveId, direction = 'left') {
        const row = document.getElementById(`leave-row-${leaveId}`);
        if (row) {
            row.style.transition = 'all 0.5s ease';
            row.style.opacity = '0';
            row.style.transform = direction === 'left' ? 'translateX(-100px)' : 'translateX(100px)';
            
            setTimeout(() => {
                row.remove();
                updatePendingCount();
                
                // If no more pending leaves, show empty state
                if (document.querySelectorAll('#pending-leaves-table tbody tr').length === 0) {
                    setTimeout(() => location.reload(), 1000);
                }
            }, 500);
        }
    }

    // Disable action buttons during processing
    function disableActionButtons(leaveId) {
        const row = document.getElementById(`leave-row-${leaveId}`);
        if (row) {
            const buttons = row.querySelectorAll('.btn-action');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            });
        }
    }

    // Enable action buttons
    function enableActionButtons(leaveId) {
        const row = document.getElementById(`leave-row-${leaveId}`);
        if (row) {
            const buttons = row.querySelectorAll('.btn-action');
            buttons.forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            });
        }
    }

    // Update pending count badge
    function updatePendingCount() {
        const badge = document.querySelector('.badge');
        if (badge) {
            const currentCount = parseInt(badge.textContent.match(/\d+/)?.[0]) || 0;
            const newCount = Math.max(0, currentCount - 1);
            
            if (newCount <= 0) {
                badge.innerHTML = '<i class="fas fa-check-circle"></i> All processed';
                badge.style.background = 'rgba(16, 185, 129, 0.1)';
                badge.style.color = '#10B981';
            } else {
                badge.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${newCount} requests`;
            }
        }
    }

    // Update stats by reloading page
    function updateStats() {
        location.reload();
    }

    // Show notification
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.custom-notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `custom-notification`;
        
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
                z-index: 10000;
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
                    transition: opacity 0.3s;
                ">
                    &times;
                </button>
            </div>
        `;

        document.body.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                const notificationDiv = notification.querySelector('div');
                notificationDiv.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => notification.remove(), 300);
            }
        }, 5000);
    }

    // Close modal when clicking outside
    document.getElementById('rejectModal').addEventListener('click', function (e) {
        if (e.target === this && !isProcessing) {
            closeRejectModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !isProcessing) {
            closeRejectModal();
        }
    });

    // Add CSS for animations if not already present
    if (!document.querySelector('#notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
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
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>