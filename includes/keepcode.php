<?php
// dashboard.php - Main Dashboard Page (UPDATED)
$page_title = "Dashboard";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php'; // New functions file

// Get dashboard statistics
$pdo = getPDOConnection();
$stats = [];
$recent_pending = [];
$recent_approved = [];
$on_leave_today = [];
$recent_google_forms = [];
$leave_stats = [];

if ($pdo) {
    try {
        // Get dashboard statistics
        $stats = getDashboardStatistics();

        // Get recent pending leaves (last 7) - UPDATED with source
        $stmt = $pdo->prepare("
            SELECT l.*, u.full_name, u.email, lt.name as leave_type_name, lt.color,
                   DATEDIFF(l.end_date, l.start_date) + 1 as total_days,
                   l.source, l.applied_date, l.half_day,
                   CASE 
                     WHEN l.source = 'google_forms' THEN 'Google Forms'
                     ELSE 'Dashboard'
                   END as request_source
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.status = 'pending'
            ORDER BY l.created_at DESC
            LIMIT 7
        ");
        $stmt->execute();
        $recent_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent approved leaves (last 5) - UPDATED
        $stmt = $pdo->prepare("
            SELECT l.*, u.full_name, lt.name as leave_type_name, lt.color,
                   DATEDIFF(l.end_date, l.start_date) + 1 as total_days,
                   u2.full_name as approved_by_name, l.source, l.applied_date
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users u2 ON l.approved_by = u2.id
            WHERE l.status = 'approved'
            ORDER BY l.updated_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $recent_approved = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get leaves on leave today - UPDATED
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT u.full_name, u.email, l.start_date, l.end_date, 
                   lt.name as leave_type_name, lt.color, l.half_day,
                   DATEDIFF(l.end_date, l.start_date) + 1 as total_days
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.status = 'approved'
            AND ? BETWEEN l.start_date AND l.end_date
            AND u.status = 'active'
            ORDER BY u.full_name
            LIMIT 10
        ");
        $stmt->execute([$today]);
        $on_leave_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent Google Forms submissions (last 10) - NEW
        $stmt = $pdo->prepare("
            SELECT gfs.*, gf.form_name,
                   CASE 
                     WHEN gfs.processed = 1 AND gfs.processing_result = 'success' THEN 'Processed'
                     WHEN gfs.processed = 1 AND gfs.processing_result = 'failed' THEN 'Failed'
                     ELSE 'Pending'
                   END as status_label
            FROM google_form_submissions gfs
            LEFT JOIN google_forms gf ON gfs.form_id = gf.id
            ORDER BY gfs.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recent_google_forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get leave statistics by type - UPDATED
        $stmt = $pdo->query("
            SELECT lt.name, lt.color, COUNT(*) as count, 
                   SUM(l.total_days) as total_days,
                   ROUND(AVG(DATEDIFF(l.end_date, l.start_date) + 1), 1) as avg_days
            FROM leaves l
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.status = 'approved'
            AND YEAR(l.created_at) = YEAR(CURRENT_DATE())
            GROUP BY lt.id, lt.name, lt.color
            ORDER BY count DESC
            LIMIT 8
        ");
        $leave_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get upcoming leaves (next 7 days) - NEW
        $next_week = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("
            SELECT u.full_name, l.start_date, l.end_date, 
                   lt.name as leave_type_name, lt.color,
                   DATEDIFF(l.start_date, CURDATE()) as days_until
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.status = 'approved'
            AND l.start_date BETWEEN CURDATE() AND ?
            AND u.status = 'active'
            ORDER BY l.start_date ASC
            LIMIT 8
        ");
        $stmt->execute([$next_week]);
        $upcoming_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching dashboard data: " . $e->getMessage());
    }
}

// Function to get dashboard statistics
function getDashboardStatistics()
{
    $pdo = getPDOConnection();
    $stats = [];

    // Total active users
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    $stats['total_users'] = $stmt->fetch()['count'];

    // Pending leaves
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
    $stats['pending_leaves'] = $stmt->fetch()['count'];

    // Approved this month
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'approved' AND MONTH(created_at) = MONTH(CURRENT_DATE())");
    $stats['approved_this_month'] = $stmt->fetch()['count'];

    // On leave today
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT l.user_id) as count FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.status = 'approved' AND ? BETWEEN l.start_date AND l.end_date AND u.status = 'active'");
    $stmt->execute([$today]);
    $stats['on_leave_today'] = $stmt->fetch()['count'];

    // Google Forms submissions (today)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM google_form_submissions WHERE DATE(created_at) = CURDATE()");
    $stats['google_forms_today'] = $stmt->fetch()['count'];

    // Total leave days this month
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_days), 0) as total FROM leaves WHERE status = 'approved' AND MONTH(created_at) = MONTH(CURRENT_DATE())");
    $stats['total_leave_days_month'] = $stmt->fetch()['total'];

    // Employees with low leave balance (< 5 days)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM leave_balances WHERE remaining_days < 5 AND year = YEAR(CURRENT_DATE())");
    $stats['low_balance_users'] = $stmt->fetch()['count'];

    return $stats;
}
?>

<style>
    /* Dashboard specific styles - UPDATED */
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 40px;
        padding: 28px;
        background: var(--color-white);
        border-radius: 24px;
        box-shadow: 0 8px 32px rgba(16, 185, 129, 0.12);
        border: 1px solid var(--color-border);
        animation: slideDown 0.5s ease-out;
        position: relative;
        overflow: hidden;
    }

    .dashboard-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, transparent 100%);
        z-index: 0;
    }

    .header-left {
        position: relative;
        z-index: 1;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .header-left h1 {
        font-family: 'Playfair Display', serif;
        font-size: 2.4em;
        color: var(--color-secondary);
        font-weight: 700;
        margin-bottom: 10px;
        background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .header-left p {
        color: var(--color-dark-gray);
        font-size: 1.1em;
        font-weight: 500;
    }

    .date-display {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 22px;
        background: linear-gradient(135deg, var(--color-light-gray), var(--color-white));
        border-radius: 16px;
        font-size: 1em;
        color: var(--color-text);
        font-weight: 500;
        border: 1px solid var(--color-border);
        position: relative;
        z-index: 1;
        backdrop-filter: blur(10px);
    }

    .date-display i {
        color: var(--color-primary);
        font-size: 1.3em;
    }

    /* Stats Grid - UPDATED with more cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }

    .stat-card {
        background: var(--color-white);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.1);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        animation: fadeIn 0.6s ease-out;
        cursor: pointer;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(16, 185, 129, 0.15);
        border-color: var(--color-primary-light);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--color-primary), var(--color-primary-light));
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }

    .stat-icon {
        width: 64px;
        height: 64px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8em;
        color: white;
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.2);
        transition: all 0.3s ease;
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.1);
    }

    .stat-icon.active-users {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-icon.pending-leaves {
        background: linear-gradient(135deg, var(--color-info), #2563EB);
    }

    .stat-icon.approved-month {
        background: linear-gradient(135deg, var(--color-success), var(--color-primary-dark));
    }

    .stat-icon.on-leave {
        background: linear-gradient(135deg, var(--color-warning), #F59E0B);
    }

    .stat-icon.google-forms {
        background: linear-gradient(135deg, var(--color-accent), var(--color-secondary));
    }

    .stat-icon.total-leave {
        background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    }

    .stat-icon.low-balance {
        background: linear-gradient(135deg, #EF4444, #DC2626);
    }

    .stat-icon.half-day {
        background: linear-gradient(135deg, #F59E0B, #D97706);
    }

    .stat-info h3 {
        font-size: 0.95em;
        color: var(--color-dark-gray);
        margin-bottom: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-value {
        font-size: 2.6em;
        font-weight: 700;
        color: var(--color-secondary);
        line-height: 1;
        margin-bottom: 12px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .stat-change {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9em;
        font-weight: 500;
    }

    .stat-change.positive {
        color: var(--color-success);
    }

    .stat-change.negative {
        color: var(--color-danger);
    }

    .stat-change i {
        font-size: 1.1em;
    }

    .stat-trend {
        font-size: 0.85em;
        padding: 4px 10px;
        border-radius: 20px;
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 10px;
    }

    /* Content Grid - UPDATED with 3 columns */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
        margin-bottom: 40px;
    }

    @media (max-width: 1200px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    .card {
        background: var(--color-white);
        border-radius: 24px;
        padding: 32px;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.1);
        border: 1px solid var(--color-border);
        animation: fadeIn 0.7s ease-out;
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 28px;
        padding-bottom: 22px;
        border-bottom: 2px solid var(--color-border);
    }

    .card-header h2 {
        font-family: 'Playfair Display', serif;
        font-size: 1.7em;
        color: var(--color-secondary);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .card-header h2 i {
        color: var(--color-primary);
        font-size: 1.2em;
    }

    .btn-view-all {
        background: var(--color-primary);
        color: var(--color-white);
        border: none;
        padding: 11px 26px;
        border-radius: 14px;
        font-size: 0.9em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-view-all:hover {
        background: var(--color-primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        color: var(--color-white);
        text-decoration: none;
    }

    .card-body {
        flex: 1;
        overflow-y: auto;
        max-height: 500px;
    }

    /* Leave Requests Table - UPDATED */
    .leave-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .leave-table th {
        text-align: left;
        padding: 18px 16px;
        background: var(--color-light-gray);
        color: var(--color-secondary);
        font-weight: 600;
        border-bottom: 2px solid var(--color-primary-light);
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .leave-table th:first-child {
        border-top-left-radius: 14px;
    }

    .leave-table th:last-child {
        border-top-right-radius: 14px;
    }

    .leave-table td {
        padding: 22px 16px;
        border-bottom: 1px solid var(--color-border);
        vertical-align: middle;
    }

    .leave-table tr {
        transition: all 0.2s ease;
    }

    .leave-table tr:hover {
        background: var(--color-light-gray);
    }

    .employee-info {
        display: flex;
        align-items: center;
        gap: 14px;
        font-weight: 500;
    }

    .employee-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 0.9em;
        flex-shrink: 0;
    }

    .source-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: 600;
        margin-top: 4px;
    }

    .source-badge.google-forms {
        background: rgba(66, 133, 244, 0.1);
        color: #4285F4;
        border: 1px solid rgba(66, 133, 244, 0.2);
    }

    .source-badge.dashboard {
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .leave-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        background: var(--color-light-gray);
    }

    .leave-type-badge i {
        font-size: 1.1em;
    }

    .days-badge {
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 0.85em;
        font-weight: 600;
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        min-width: 70px;
        text-align: center;
    }

    .half-day-indicator {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 8px;
        color: #F59E0B;
        font-size: 0.8em;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
    }

    .btn-action {
        padding: 9px 18px;
        border-radius: 10px;
        border: none;
        font-size: 0.85em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 100px;
        justify-content: center;
    }

    .btn-approve {
        background: var(--color-success);
        color: white;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    }

    .btn-reject {
        background: var(--color-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
    }

    .btn-approve:hover {
        background: var(--color-primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(16, 185, 129, 0.3);
    }

    .btn-reject:hover {
        background: #DC2626;
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(239, 68, 68, 0.3);
    }

    /* On Leave Today - UPDATED */
    .on-leave-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .on-leave-item {
        display: flex;
        align-items: center;
        padding: 22px;
        border-radius: 18px;
        background: linear-gradient(135deg, var(--color-light-gray), white);
        margin-bottom: 16px;
        transition: all 0.3s ease;
        border: 1px solid transparent;
    }

    .on-leave-item:hover {
        transform: translateX(10px);
        border-color: var(--color-primary-light);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.1);
    }

    .leave-date {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 16px;
        background: white;
        border-radius: 14px;
        margin-right: 22px;
        min-width: 86px;
        border: 2px solid var(--color-primary-light);
        flex-shrink: 0;
    }

    .date-month {
        font-size: 0.85em;
        color: var(--color-primary);
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .date-day {
        font-size: 2em;
        font-weight: 700;
        color: var(--color-secondary);
        line-height: 1;
    }

    .leave-details h4 {
        font-size: 1.1em;
        color: var(--color-text);
        margin-bottom: 8px;
        font-weight: 600;
    }

    .leave-details p {
        color: var(--color-dark-gray);
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 6px;
    }

    .leave-details i {
        color: var(--color-primary);
        width: 16px;
    }

    /* Google Forms Submissions - NEW */
    .submission-item {
        padding: 18px;
        border-radius: 14px;
        background: linear-gradient(135deg, #f8f9fa, white);
        margin-bottom: 12px;
        border: 1px solid rgba(66, 133, 244, 0.1);
        transition: all 0.3s ease;
    }

    .submission-item:hover {
        border-color: #4285F4;
        background: linear-gradient(135deg, rgba(66, 133, 244, 0.05), white);
        transform: translateX(5px);
    }

    .submission-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .submission-email {
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.95em;
    }

    .submission-time {
        font-size: 0.8em;
        color: var(--color-dark-gray);
    }

    .submission-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
    }

    .status-processed {
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-pending {
        background: rgba(245, 158, 11, 0.1);
        color: #F59E0B;
        border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-failed {
        background: rgba(239, 68, 68, 0.1);
        color: var(--color-danger);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .submission-form {
        font-size: 0.85em;
        color: #4285F4;
        margin-bottom: 8px;
    }

    /* Leave Stats Chart - UPDATED */
    .stats-chart {
        margin-top: 30px;
    }

    .stat-bar {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
    }

    .stat-label {
        min-width: 120px;
        font-size: 0.9em;
        color: var(--color-text);
        font-weight: 500;
    }

    .stat-bar-container {
        flex: 1;
        height: 30px;
        background: var(--color-light-gray);
        border-radius: 15px;
        overflow: hidden;
        position: relative;
    }

    .stat-bar-fill {
        height: 100%;
        border-radius: 15px;
        position: relative;
        transition: width 1s ease-out;
    }

    .stat-bar-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        animation: shimmer 2s infinite;
    }

    .stat-count {
        min-width: 60px;
        text-align: right;
        font-weight: 600;
        color: var(--color-secondary);
        font-size: 0.9em;
    }

    @keyframes shimmer {
        0% {
            transform: translateX(-100%);
        }

        100% {
            transform: translateX(100%);
        }
    }

    /* Quick Actions - UPDATED */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 28px;
        margin-top: 40px;
    }

    .action-card {
        background: var(--color-white);
        border-radius: 24px;
        padding: 38px 32px;
        text-align: center;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.1);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .action-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(16, 185, 129, 0.15);
        border-color: var(--color-primary);
    }

    .action-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--color-primary), var(--color-primary-light));
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .action-card:hover::after {
        transform: scaleX(1);
    }

    .action-icon {
        width: 84px;
        height: 84px;
        border-radius: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 28px;
        font-size: 2.4em;
        color: white;
        box-shadow: 0 8px 24px rgba(16, 185, 129, 0.2);
        transition: all 0.3s ease;
    }

    .action-card:hover .action-icon {
        transform: scale(1.1) rotate(5deg);
    }

    .action-card:nth-child(1) .action-icon {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .action-card:nth-child(2) .action-icon {
        background: linear-gradient(135deg, var(--color-accent), var(--color-secondary));
    }

    .action-card:nth-child(3) .action-icon {
        background: linear-gradient(135deg, var(--color-info), #2563EB);
    }

    .action-card:nth-child(4) .action-icon {
        background: linear-gradient(135deg, var(--color-purple), #8B5CF6);
    }

    .action-card:nth-child(5) .action-icon {
        background: linear-gradient(135deg, #10B981, #059669);
    }

    .action-card:nth-child(6) .action-icon {
        background: linear-gradient(135deg, #F59E0B, #D97706);
    }

    .action-card h3 {
        font-size: 1.4em;
        color: var(--color-secondary);
        margin-bottom: 14px;
        font-weight: 600;
    }

    .action-card p {
        color: var(--color-dark-gray);
        font-size: 0.98em;
        line-height: 1.6;
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--color-dark-gray);
    }

    .empty-state i {
        font-size: 4em;
        color: var(--color-primary-light);
        margin-bottom: 24px;
        opacity: 0.7;
    }

    .empty-state h3 {
        font-size: 1.4em;
        color: var(--color-secondary);
        margin-bottom: 14px;
        font-weight: 600;
    }

    .empty-state p {
        max-width: 320px;
        margin: 0 auto;
        font-size: 1em;
    }

    /* Footer */
    .footer {
        margin-top: 60px;
        padding: 28px;
        background: var(--color-white);
        border-radius: 24px;
        text-align: center;
        color: var(--color-dark-gray);
        font-size: 0.9em;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.08);
        border: 1px solid var(--color-border);
    }

    .footer-content p {
        margin-bottom: 10px;
    }

    .footer-content p:last-child {
        margin-bottom: 0;
        color: var(--color-primary);
        font-weight: 500;
    }

    /* Animation */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.5s ease-out;
    }

    /* Chart container */
    .chart-container {
        margin-top: 30px;
        height: 300px;
        position: relative;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .dashboard-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 20px;
            padding: 24px;
        }

        .date-display {
            align-self: flex-start;
        }

        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .quick-actions {
            grid-template-columns: 1fr;
        }

        .card-header {
            flex-direction: column;
            gap: 16px;
            align-items: flex-start;
        }

        .btn-view-all {
            align-self: flex-start;
        }

        .action-buttons {
            flex-direction: column;
            gap: 8px;
        }

        .btn-action {
            width: 100%;
        }

        .on-leave-item {
            flex-direction: column;
            align-items: flex-start;
        }

        .leave-date {
            margin-right: 0;
            margin-bottom: 15px;
            align-self: flex-start;
        }
    }

    /* Tooltip */
    .tooltip {
        position: relative;
        display: inline-block;
    }

    .tooltip .tooltiptext {
        visibility: hidden;
        width: 200px;
        background-color: var(--color-secondary);
        color: var(--color-white);
        text-align: center;
        border-radius: 6px;
        padding: 8px;
        position: absolute;
        z-index: 100;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 0.85em;
        font-weight: normal;
    }

    .tooltip:hover .tooltiptext {
        visibility: visible;
        opacity: 1;
    }

    /* Loading animation */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(16, 185, 129, 0.3);
        border-radius: 50%;
        border-top-color: var(--color-primary);
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <div class="header-left">
        <h1>
            <?php echo $page_title; ?>
        </h1>
        <p>Welcome back,
            <?php echo htmlspecialchars($current_user['full_name']); ?>! Here's your leave management
            overview
        </p>
        <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
            <span class="tooltip">
                <span
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(16, 185, 129, 0.1); border-radius: 20px; font-size: 0.9em;">
                    <i class="fas fa-user-shield"></i>
                    <?php echo ucfirst($current_user['role']); ?>
                </span>
                <span class="tooltiptext">Your access level:
                    <?php echo strtoupper($current_user['role']); ?>
                </span>
            </span>
            <?php if ($current_user['department']): ?>
                <span
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(59, 130, 246, 0.1); border-radius: 20px; font-size: 0.9em;">
                    <i class="fas fa-building"></i>
                    <?php echo $current_user['department']; ?>
                </span>
            <?php endif; ?>
            <span
                style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(139, 92, 246, 0.1); border-radius: 20px; font-size: 0.9em;">
                <i class="fas fa-clock"></i> Last login:
                <?php echo date('M j, g:i A', strtotime($current_user['last_login'] ?? 'now')); ?>
            </span>
        </div>
    </div>
    <div class="date-display">
        <i class="fas fa-calendar-day"></i>
        <span>
            <?php echo date('l, F j, Y'); ?>
        </span>
    </div>
</div>

<!-- Stats Grid - UPDATED with more stats -->
<div class="stats-grid">
    <div class="stat-card fade-in" onclick="window.location.href='manage-users.php'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>Active Users</h3>
                <div class="stat-value">
                    <?php echo $stats['total_users'] ?? 0; ?>
                </div>
                <div class="stat-change positive">
                    <i class="fas fa-users"></i>
                    <span>Total active employees</span>
                </div>
            </div>
            <div class="stat-icon active-users">
                <i class="fas fa-user-check"></i>
            </div>
        </div>
    </div>

    <div class="stat-card fade-in" onclick="window.location.href='leave-approvals.php'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>Pending Leaves</h3>
                <div class="stat-value">
                    <?php echo $stats['pending_leaves'] ?? 0; ?>
                </div>
                <div class="stat-change">
                    <i class="fas fa-clock"></i>
                    <span>Awaiting your approval</span>
                    <?php if ($stats['pending_leaves'] > 0): ?>
                        <span class="stat-trend">
                            <i class="fas fa-exclamation-circle"></i> Needs Attention
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-icon pending-leaves">
                <i class="fas fa-hourglass-half"></i>
            </div>
        </div>
    </div>

    <div class="stat-card fade-in" onclick="window.location.href='reports.php'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>Approved This Month</h3>
                <div class="stat-value">
                    <?php echo $stats['approved_this_month'] ?? 0; ?>
                </div>
                <div class="stat-change positive">
                    <i class="fas fa-chart-line"></i>
                    <span>Leaves approved this month</span>
                </div>
            </div>
            <div class="stat-icon approved-month">
                <i class="fas fa-check-circle"></i>
            </div>
        </div>
    </div>

    <div class="stat-card fade-in" onclick="window.location.href='calendar.php'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>On Leave Today</h3>
                <div class="stat-value">
                    <?php echo $stats['on_leave_today'] ?? 0; ?>
                </div>
                <div class="stat-change">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Employees on leave</span>
                </div>
            </div>
            <div class="stat-icon on-leave">
                <i class="fas fa-calendar-times"></i>
            </div>
        </div>
    </div>

    <div class="stat-card fade-in" onclick="window.location.href='google-forms.php'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>Google Forms Today</h3>
                <div class="stat-value">
                    <?php echo $stats['google_forms_today'] ?? 0; ?>
                </div>
                <div class="stat-change">
                    <i class="fab fa-google"></i>
                    <span>Form submissions today</span>
                </div>
            </div>
            <div class="stat-icon google-forms">
                <i class="fab fa-google"></i>
            </div>
        </div>
    </div>

    <div class="stat-card fade-in" onclick="window.location.href='reports.php?filter=low_balance'">
        <div class="stat-header">
            <div class="stat-info">
                <h3>Low Balance Alert</h3>
                <div class="stat-value">
                    <?php echo $stats['low_balance_users'] ?? 0; ?>
                </div>
                <div
                    class="stat-change <?php echo ($stats['low_balance_users'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Employees with < 5 days</span>
                            <?php if (($stats['low_balance_users'] ?? 0) > 0): ?>
                                <span class="stat-trend" style="background: rgba(239, 68, 68, 0.1); color: #EF4444;">
                                    <i class="fas fa-exclamation-triangle"></i> Alert
                                </span>
                            <?php endif; ?>
                </div>
            </div>
            <div class="stat-icon low-balance">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
        </div>
    </div>
</div>

<!-- Content Grid - UPDATED -->
<div class="content-grid">
    <!-- Pending Leave Approvals -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-clock"></i> Pending Approvals</h2>
            <a href="leave-approvals.php" class="btn-view-all">
                <i class="fas fa-external-link-alt"></i> View All
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($recent_pending)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>No Pending Requests</h3>
                    <p>All leave requests have been processed</p>
                </div>
            <?php else: ?>
                <table class="leave-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>Dates</th>
                            <th>Days</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_pending as $leave):
                            $start_date_obj = new DateTime($leave['start_date']);
                            $end_date_obj = new DateTime($leave['end_date']);
                            $total_days = $leave['total_days'];
                            $half_day = $leave['half_day'] ?? 'none';
                            ?>
                            <tr>
                                <td>
                                    <div class="employee-info">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($leave['full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div>
                                                <?php echo htmlspecialchars($leave['full_name']); ?>
                                            </div>
                                            <div
                                                class="source-badge <?php echo $leave['source'] === 'google_forms' ? 'google-forms' : 'dashboard'; ?>">
                                                <i
                                                    class="fas <?php echo $leave['source'] === 'google_forms' ? 'fa-google' : 'fa-desktop'; ?>"></i>
                                                <?php echo $leave['request_source']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="leave-type-badge"
                                        style="border-left: 4px solid <?php echo $leave['color']; ?>">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $start_date = $start_date_obj->format('M j');
                                    $end_date = $end_date_obj->format('M j, Y');
                                    echo "$start_date - $end_date";
                                    ?>
                                    <br>
                                    <small style="color: var(--color-dark-gray); font-size: 0.85em;">
                                        Applied:
                                        <?php echo date('M j', strtotime($leave['applied_date'] ?? $leave['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="days-badge">
                                        <?php echo $total_days; ?> days
                                        <?php if ($half_day !== 'none'): ?>
                                            <span class="half-day-indicator" title="Half Day: <?php echo ucfirst($half_day); ?>">
                                                <i class="fas fa-clock"></i>½
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-action btn-approve"
                                            onclick="approveLeave(<?php echo $leave['id']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn-action btn-reject"
                                            onclick="rejectLeave(<?php echo $leave['id']; ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Two Smaller Cards -->
    <div style="display: flex; flex-direction: column; gap: 30px;">
        <!-- On Leave Today -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-day"></i> On Leave Today</h2>
                <a href="calendar.php" class="btn-view-all">
                    <i class="fas fa-calendar-alt"></i> View Calendar
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($on_leave_today)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No One on Leave</h3>
                        <p>All employees are present today</p>
                    </div>
                <?php else: ?>
                    <ul class="on-leave-list">
                        <?php foreach ($on_leave_today as $leave):
                            $start_date = new DateTime($leave['start_date']);
                            $end_date = new DateTime($leave['end_date']);
                            $total_days = $leave['total_days'];
                            $half_day = $leave['half_day'] ?? 'none';
                            ?>
                            <li class="on-leave-item">
                                <div class="leave-date">
                                    <span class="date-month">
                                        <?php echo $start_date->format('M'); ?>
                                    </span>
                                    <span class="date-day">
                                        <?php echo $start_date->format('d'); ?>
                                    </span>
                                </div>
                                <div class="leave-details">
                                    <h4>
                                        <?php echo htmlspecialchars($leave['full_name']); ?>
                                    </h4>
                                    <p>
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php echo $start_date->format('M j') . ' - ' . $end_date->format('M j, Y'); ?>
                                        <?php if ($half_day !== 'none'): ?>
                                            <span style="margin-left: 10px; color: #F59E0B; font-size: 0.9em;">
                                                <i class="fas fa-clock"></i>
                                                <?php echo ucfirst($half_day); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <p>
                                        <i class="fas fa-tag"></i>
                                        <span class="leave-type-badge"
                                            style="border-left: 4px solid <?php echo $leave['color']; ?>">
                                            <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                        </span>
                                    </p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Google Forms Submissions -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fab fa-google"></i> Recent Forms</h2>
                <a href="google-forms.php" class="btn-view-all">
                    <i class="fas fa-external-link-alt"></i> View All
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_google_forms)): ?>
                    <div class="empty-state">
                        <i class="fab fa-google"></i>
                        <h3>No Form Submissions</h3>
                        <p>No Google Forms submissions yet</p>
                    </div>
                <?php else: ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($recent_google_forms as $submission):
                            $form_data = json_decode($submission['form_data'] ?? '{}', true);
                            $email = $form_data['email_address'] ?? $submission['employee_email'];
                            $status_class = 'status-' . strtolower($submission['status_label']);
                            ?>
                            <div class="submission-item">
                                <div class="submission-header">
                                    <div class="submission-email">
                                        <?php echo htmlspecialchars($email); ?>
                                    </div>
                                    <div class="submission-status <?php echo $status_class; ?>">
                                        <i
                                            class="fas 
                                            <?php echo $submission['status_label'] === 'Processed' ? 'fa-check-circle' :
                                                ($submission['status_label'] === 'Failed' ? 'fa-times-circle' : 'fa-clock'); ?>">
                                        </i>
                                        <?php echo $submission['status_label']; ?>
                                    </div>
                                </div>
                                <div class="submission-form">
                                    <i class="fas fa-file-alt"></i>
                                    <?php echo htmlspecialchars($submission['form_name'] ?? 'Unknown Form'); ?>
                                </div>
                                <div class="submission-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M j, g:i A', strtotime($submission['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Leave Statistics Chart -->
<div class="card" style="margin-top: 30px;">
    <div class="card-header">
        <h2><i class="fas fa-chart-bar"></i> Leave Statistics</h2>
        <a href="reports.php" class="btn-view-all">
            <i class="fas fa-chart-pie"></i> Detailed Reports
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($leave_stats)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h3>No Statistics Available</h3>
                <p>No approved leaves for this year yet</p>
            </div>
        <?php else: ?>
            <div class="stats-chart">
                <?php
                $max_count = max(array_column($leave_stats, 'count'));
                foreach ($leave_stats as $stat):
                    $percentage = $max_count > 0 ? ($stat['count'] / $max_count) * 100 : 0;
                    ?>
                    <div class="stat-bar">
                        <div class="stat-label">
                            <span class="leave-type-badge"
                                style="border-left: 4px solid <?php echo $stat['color']; ?>; padding: 4px 10px; font-size: 0.85em;">
                                <?php echo htmlspecialchars($stat['name']); ?>
                            </span>
                        </div>
                        <div class="stat-bar-container">
                            <div class="stat-bar-fill" style="
                                width: <?php echo $percentage; ?>%; 
                                background: linear-gradient(90deg, <?php echo $stat['color']; ?>, <?php echo adjustBrightness($stat['color'], 20); ?>);
                            "></div>
                        </div>
                        <div class="stat-count">
                            <?php echo $stat['count']; ?> leaves
                            <br>
                            <small style="color: var(--color-dark-gray); font-size: 0.85em;">
                                <?php echo $stat['total_days']; ?> days
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions - UPDATED with more actions -->
<div class="quick-actions">
    <div class="action-card" onclick="window.location.href='leave-approvals.php'">
        <div class="action-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3>Review Leave Requests</h3>
        <p>Approve or reject pending leave requests from employees and Google Forms</p>
    </div>

    <div class="action-card" onclick="window.location.href='manage-users.php'">
        <div class="action-icon">
            <i class="fas fa-users-cog"></i>
        </div>
        <h3>Manage Users</h3>
        <p>Add, edit, or remove users and manage employee permissions and leave policies</p>
    </div>

    <div class="action-card" onclick="window.location.href='reports.php'">
        <div class="action-icon">
            <i class="fas fa-chart-pie"></i>
        </div>
        <h3>View Reports</h3>
        <p>Generate detailed leave reports and analytics for insights and planning</p>
    </div>

    <div class="action-card" onclick="window.location.href='google-forms.php'">
        <div class="action-icon">
            <i class="fab fa-google"></i>
        </div>
        <h3>Google Forms</h3>
        <p>Manage Google Forms integration and view form submissions and statistics</p>
    </div>

    <div class="action-card" onclick="window.location.href='calendar.php'">
        <div class="action-icon">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <h3>View Calendar</h3>
        <p>Check company leave calendar and see who's off on specific dates</p>
    </div>

    <div class="action-card" onclick="window.location.href='email-templates.php'">
        <div class="action-icon">
            <i class="fas fa-envelope"></i>
        </div>
        <h3>Email Templates</h3>
        <p>Manage email templates for leave approvals, rejections, and notifications</p>
    </div>
</div>

<!-- Footer -->
<div class="footer">
    <div class="footer-content">
        <p>Leave Management System v2.0 | Google Forms Integration Active</p>
        <p><i class="fas fa-sync-alt"></i> Last updated:
            <?php echo date('F j, Y \a\t g:i A'); ?>
        </p>
        <p><i class="fas fa-database"></i> Database:
            <?php echo DB_NAME; ?> |
            <i class="fas fa-users"></i> Active Users:
            <?php echo $stats['total_users'] ?? 0; ?> |
            <i class="fab fa-google"></i> Forms Today:
            <?php echo $stats['google_forms_today'] ?? 0; ?>
        </p>
    </div>
</div>

<script>
    // Add animation delays to stat cards
    document.querySelectorAll('.stat-card').forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });

    // Approve/Reject functions - UPDATED for new database
    function approveLeave(leaveId) {
        if (confirm('Are you sure you want to approve this leave request?')) {
            showLoading(leaveId, 'approve');
            fetch(`api/approve-leave.php?id=${leaveId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approve',
                    approved_by: <?php echo $current_user['id']; ?>
                })
        })
                .then(response => response.json())
            .then(data => {
                hideLoading(leaveId);
                if (data.success) {
                    showNotification('Leave request approved successfully', 'success');
                    // Update stats
                    updateDashboardStats();
                    // Reload after delay
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message || 'Error approving leave', 'error');
                }
            })
            .catch(error => {
                hideLoading(leaveId);
                console.error('Error:', error);
                showNotification('An error occurred while approving the leave', 'error');
            });
    }
    }

    function rejectLeave(leaveId) {
        const reason = prompt('Please enter the reason for rejection:');
        if (reason !== null && reason.trim() !== '') {
            showLoading(leaveId, 'reject');
            fetch(`api/reject-leave.php?id=${leaveId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reject',
                    reason: reason.trim(),
                    rejected_by: <?php echo $current_user['id']; ?>
                })
        })
                .then(response => response.json())
            .then(data => {
                hideLoading(leaveId);
                if (data.success) {
                    showNotification('Leave request rejected successfully', 'success');
                    // Update stats
                    updateDashboardStats();
                    // Reload after delay
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message || 'Error rejecting leave', 'error');
                }
            })
            .catch(error => {
                hideLoading(leaveId);
                console.error('Error:', error);
                showNotification('An error occurred while rejecting the leave', 'error');
            });
    }
    }

    function showLoading(leaveId, action) {
        const buttons = document.querySelectorAll(`button[onclick*="${leaveId}"]`);
        buttons.forEach(button => {
            button.innerHTML = `<span class="loading"></span> ${action === 'approve' ? 'Approving...' : 'Rejecting...'}`;
            button.disabled = true;
        });
    }

    function hideLoading(leaveId) {
        const buttons = document.querySelectorAll(`button[onclick*="${leaveId}"]`);
        buttons.forEach(button => {
            if (button.classList.contains('btn-approve')) {
                button.innerHTML = '<i class="fas fa-check"></i> Approve';
            } else {
                button.innerHTML = '<i class="fas fa-times"></i> Reject';
            }
            button.disabled = false;
        });
    }

    // Update dashboard stats without reloading
    function updateDashboardStats() {
        fetch('api/get-dashboard-stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stat cards
                    document.querySelectorAll('.stat-value')[0].textContent = data.stats.total_users;
                    document.querySelectorAll('.stat-value')[1].textContent = data.stats.pending_leaves;
                    document.querySelectorAll('.stat-value')[2].textContent = data.stats.approved_this_month;
                    document.querySelectorAll('.stat-value')[3].textContent = data.stats.on_leave_today;
                    document.querySelectorAll('.stat-value')[4].textContent = data.stats.google_forms_today;
                    document.querySelectorAll('.stat-value')[5].textContent = data.stats.low_balance_users;
                }
            })
            .catch(error => console.error('Error updating stats:', error));
    }

    // Initialize any charts if needed
    document.addEventListener('DOMContentLoaded', function () {
        // Animate stat bars
        const statBars = document.querySelectorAll('.stat-bar-fill');
        statBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = width;
            }, 500);
        });

        // Auto-refresh dashboard every 60 seconds
        setInterval(updateDashboardStats, 60000);
    });

    // Notification function
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div style="
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? 'var(--color-success)' :
                type === 'error' ? 'var(--color-danger)' :
                    'var(--color-info)'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1000;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideIn 0.3s ease-out;
            ">
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-exclamation-circle' :
                    'fa-info-circle'}"></i>
                <span>${message}</span>
            </div>
        `;

        document.body.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }

    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        .notification-success { background: var(--color-success) !important; }
        .notification-error { background: var(--color-danger) !important; }
        .notification-info { background: var(--color-info) !important; }
    `;
    document.head.appendChild(style);

    // Utility function to adjust color brightness
    function adjustBrightness(color, percent) {
        // This is a placeholder - you'd need to implement actual color manipulation
        return color;
    }
</script>

<?php
// Helper function for color adjustment
function adjustBrightness($hex, $percent)
{
    // Simple color adjustment - you might want to use a proper color library
    return $hex;
}

require_once __DIR__ . '/includes/footer.php';
?>