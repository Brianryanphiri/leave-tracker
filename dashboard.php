<?php
// dashboard.php - Main Dashboard Page (CLEAN VERSION)
$page_title = "Dashboard";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check for success/error messages from URL
if (isset($_GET['success'])) {
    echo '<div class="success-message" style="background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%); color: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #8B7355;">
            <i class="fas fa-check-circle"></i> ' . htmlspecialchars(urldecode($_GET['success'])) . '
          </div>';
}
if (isset($_GET['error'])) {
    echo '<div class="error-message" style="background: linear-gradient(135deg, #8B0000 0%, #B22222 100%); color: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #BC8F8F;">
            <i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars(urldecode($_GET['error'])) . '
          </div>';
}

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

    } catch (PDOException $e) {
        error_log("Error fetching dashboard data: " . $e->getMessage());
    }
}
?>

<style>
    /* ===== DASHBOARD STYLES - CLEAN WHITE BACKGROUND ===== */
    body {
        font-family: 'Inter', sans-serif;
        background: #FFFFFF;
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
        margin: 0;
        padding: 0;
    }

    /* Subtle background pattern with ocher colors */
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

    /* Dashboard Container */
    .dashboard-container {
        position: relative;
        z-index: 1;
        max-width: 100%;
        width: 100%;
        padding: 20px;
        margin: 0;
        box-sizing: border-box;
        padding-top: 10px;
        /* Reduced top padding */
    }

    /* Welcome Header - SIMPLIFIED */
    .welcome-header {
        margin-bottom: 30px;
        padding: 20px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        animation: slideDown 0.5s ease-out;
        width: 100%;
        box-sizing: border-box;
        position: relative;
    }

    .welcome-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, #D4A017, #B8860B);
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-15px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .welcome-header h1 {
        font-family: 'Playfair Display', serif;
        font-size: 1.8em;
        color: #2F2F2F;
        font-weight: 700;
        margin-bottom: 5px;
        background: linear-gradient(135deg, #B8860B, #D4A017);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .welcome-header p {
        color: #666;
        font-size: 1em;
        font-weight: 400;
        margin: 0;
        line-height: 1.6;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
        width: 100%;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        animation: fadeIn 0.6s ease-out;
        cursor: pointer;
        width: 100%;
        box-sizing: border-box;
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
        background: linear-gradient(90deg, #D4A017, #B8860B);
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
        background: linear-gradient(135deg, #D4A017, #B8860B);
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.35);
    }

    .stat-icon.active-users {
        background: linear-gradient(135deg, #D4A017, #B8860B);
    }

    .stat-icon.pending-leaves {
        background: linear-gradient(135deg, #8B7355, #A0522D);
    }

    .stat-icon.approved-month {
        background: linear-gradient(135deg, #B8860B, #D2691E);
    }

    .stat-icon.on-leave {
        background: linear-gradient(135deg, #CD853F, #D4A017);
    }

    .stat-icon.google-forms {
        background: linear-gradient(135deg, #4285F4, #34A853);
    }

    .stat-icon.low-balance {
        background: linear-gradient(135deg, #8B0000, #B22222);
    }

    .stat-info h3 {
        font-size: 0.95em;
        color: #8B7355;
        margin-bottom: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-value {
        font-size: 2.5em;
        font-weight: 700;
        color: #2F2F2F;
        line-height: 1;
        margin-bottom: 12px;
    }

    .stat-change {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9em;
        font-weight: 500;
    }

    .stat-change.positive {
        color: #B8860B;
    }

    .stat-change.negative {
        color: #8B0000;
    }

    .stat-change i {
        font-size: 1.1em;
    }

    .stat-trend {
        font-size: 0.85em;
        padding: 4px 10px;
        border-radius: 20px;
        background: rgba(212, 160, 23, 0.1);
        color: #B8860B;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-left: 10px;
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    /* Content Grid */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
        margin-bottom: 40px;
        width: 100%;
    }

    @media (max-width: 1200px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    .card {
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        animation: fadeIn 0.7s ease-out;
        height: 100%;
        display: flex;
        flex-direction: column;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.3s ease;
    }

    .card:hover {
        box-shadow: 0 6px 25px rgba(212, 160, 23, 0.12);
        border-color: rgba(212, 160, 23, 0.2);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid rgba(212, 160, 23, 0.1);
    }

    .card-header h2 {
        font-family: 'Playfair Display', serif;
        font-size: 1.4em;
        color: #2F2F2F;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .card-header h2 i {
        color: #D4A017;
        font-size: 1.2em;
    }

    .btn-view-all {
        background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%);
        color: white;
        border: none;
        padding: 10px 22px;
        border-radius: 12px;
        font-size: 0.9em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.2);
    }

    .btn-view-all:hover {
        background: linear-gradient(135deg, #B8860B 0%, #D2691E 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(212, 160, 23, 0.3);
    }

    .card-body {
        flex: 1;
        overflow-y: auto;
        max-height: 400px;
        width: 100%;
    }

    /* Leave Table */
    .leave-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .leave-table th {
        text-align: left;
        padding: 16px 12px;
        background: rgba(212, 160, 23, 0.05);
        color: #2F2F2F;
        font-weight: 600;
        border-bottom: 2px solid rgba(212, 160, 23, 0.2);
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .leave-table th:first-child {
        border-top-left-radius: 12px;
    }

    .leave-table th:last-child {
        border-top-right-radius: 12px;
    }

    .leave-table td {
        padding: 20px 12px;
        border-bottom: 1px solid rgba(212, 160, 23, 0.1);
        vertical-align: middle;
        color: #555;
    }

    .leave-table tr:hover {
        background: rgba(212, 160, 23, 0.03);
    }

    .employee-info {
        display: flex;
        align-items: center;
        gap: 15px;
        font-weight: 500;
    }

    .employee-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #D4A017, #B8860B);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 0.9em;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(212, 160, 23, 0.2);
    }

    .source-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 10px;
        font-size: 0.75em;
        font-weight: 600;
        margin-top: 5px;
        background: white;
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .source-badge.google-forms {
        background: rgba(66, 133, 244, 0.1);
        color: #4285F4;
        border-color: rgba(66, 133, 244, 0.2);
    }

    .source-badge.dashboard {
        background: rgba(212, 160, 23, 0.1);
        color: #B8860B;
        border-color: rgba(212, 160, 23, 0.2);
    }

    .leave-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        background: white;
        color: #2F2F2F;
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .leave-type-badge i {
        font-size: 1em;
        color: #D4A017;
    }

    .days-badge {
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 0.85em;
        font-weight: 600;
        background: rgba(212, 160, 23, 0.1);
        color: #B8860B;
        min-width: 70px;
        text-align: center;
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .half-day-indicator {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 8px;
        color: #D4A017;
        font-size: 0.8em;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
    }

    .btn-action {
        padding: 8px 16px;
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
        background: linear-gradient(135deg, #D4A017, #B8860B);
        color: white;
        box-shadow: 0 3px 10px rgba(212, 160, 23, 0.2);
    }

    .btn-reject {
        background: linear-gradient(135deg, #8B0000, #B22222);
        color: white;
        box-shadow: 0 3px 10px rgba(139, 0, 0, 0.2);
    }

    .btn-approve:hover {
        background: linear-gradient(135deg, #B8860B, #D2691E);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(212, 160, 23, 0.3);
    }

    .btn-reject:hover {
        background: linear-gradient(135deg, #660000, #8B0000);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(139, 0, 0, 0.3);
    }

    /* On Leave Today */
    .on-leave-list {
        list-style: none;
        padding: 0;
        margin: 0;
        width: 100%;
    }

    .on-leave-item {
        display: flex;
        align-items: center;
        padding: 20px;
        border-radius: 14px;
        background: white;
        margin-bottom: 12px;
        transition: all 0.3s ease;
        border: 1px solid rgba(212, 160, 23, 0.1);
        width: 100%;
        box-sizing: border-box;
    }

    .on-leave-item:hover {
        transform: translateX(5px);
        border-color: rgba(212, 160, 23, 0.3);
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.1);
    }

    .leave-date {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 15px;
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.1), rgba(255, 255, 255, 0.9));
        border-radius: 12px;
        margin-right: 20px;
        min-width: 80px;
        border: 2px solid rgba(212, 160, 23, 0.3);
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(212, 160, 23, 0.1);
    }

    .date-month {
        font-size: 0.85em;
        color: #B8860B;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .date-day {
        font-size: 1.8em;
        font-weight: 700;
        color: #2F2F2F;
        line-height: 1;
    }

    .leave-details h4 {
        font-size: 1.1em;
        color: #2F2F2F;
        margin-bottom: 8px;
        font-weight: 600;
    }

    .leave-details p {
        color: #666;
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 5px;
    }

    /* Google Forms Submissions */
    .submission-item {
        padding: 18px;
        border-radius: 12px;
        background: white;
        margin-bottom: 12px;
        border: 1px solid rgba(212, 160, 23, 0.1);
        transition: all 0.3s ease;
        width: 100%;
        box-sizing: border-box;
    }

    .submission-item:hover {
        border-color: rgba(212, 160, 23, 0.3);
        transform: translateX(3px);
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.1);
    }

    .submission-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .submission-email {
        font-weight: 600;
        color: #2F2F2F;
        font-size: 0.95em;
    }

    .submission-time {
        font-size: 0.8em;
        color: #8B7355;
    }

    .submission-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 16px;
        font-size: 0.8em;
        font-weight: 600;
        background: white;
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .status-processed {
        background: rgba(212, 160, 23, 0.1);
        color: #B8860B;
        border-color: rgba(212, 160, 23, 0.3);
    }

    .status-pending {
        background: rgba(139, 115, 85, 0.1);
        color: #8B7355;
        border-color: rgba(139, 115, 85, 0.2);
    }

    .status-failed {
        background: rgba(139, 0, 0, 0.1);
        color: #8B0000;
        border-color: rgba(139, 0, 0, 0.2);
    }

    .submission-form {
        font-size: 0.85em;
        color: #4285F4;
        margin-bottom: 8px;
        font-weight: 500;
    }

    /* Leave Statistics */
    .stats-chart {
        margin-top: 25px;
        width: 100%;
    }

    .stat-bar {
        display: flex;
        align-items: center;
        margin-bottom: 16px;
        width: 100%;
    }

    .stat-label {
        min-width: 120px;
        font-size: 0.9em;
        color: #2F2F2F;
        font-weight: 500;
    }

    .stat-bar-container {
        flex: 1;
        height: 28px;
        background: rgba(212, 160, 23, 0.05);
        border-radius: 14px;
        overflow: hidden;
        position: relative;
        border: 1px solid rgba(212, 160, 23, 0.1);
    }

    .stat-bar-fill {
        height: 100%;
        border-radius: 14px;
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
        color: #2F2F2F;
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

    /* Quick Actions */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-top: 40px;
        width: 100%;
    }

    .action-card {
        background: white;
        border-radius: 16px;
        padding: 30px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        width: 100%;
        box-sizing: border-box;
    }

    .action-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(212, 160, 23, 0.15);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .action-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #D4A017, #B8860B);
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .action-card:hover::after {
        transform: scaleX(1);
    }

    .action-icon {
        width: 80px;
        height: 80px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 25px;
        font-size: 2.2em;
        color: white;
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.2);
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #D4A017, #B8860B);
    }

    .action-card:hover .action-icon {
        transform: scale(1.1) rotate(5deg);
        box-shadow: 0 8px 25px rgba(212, 160, 23, 0.3);
    }

    .action-card:nth-child(1) .action-icon {
        background: linear-gradient(135deg, #D4A017, #B8860B);
    }

    .action-card:nth-child(2) .action-icon {
        background: linear-gradient(135deg, #8B7355, #A0522D);
    }

    .action-card:nth-child(3) .action-icon {
        background: linear-gradient(135deg, #B8860B, #D2691E);
    }

    .action-card:nth-child(4) .action-icon {
        background: linear-gradient(135deg, #4285F4, #34A853);
    }

    .action-card:nth-child(5) .action-icon {
        background: linear-gradient(135deg, #CD853F, #D4A017);
    }

    .action-card:nth-child(6) .action-icon {
        background: linear-gradient(135deg, #A0522D, #8B7355);
    }

    .action-card h3 {
        font-size: 1.3em;
        color: #2F2F2F;
        margin-bottom: 12px;
        font-weight: 600;
    }

    .action-card p {
        color: #666;
        font-size: 0.95em;
        line-height: 1.6;
        margin: 0;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #8B7355;
        width: 100%;
    }

    .empty-state i {
        font-size: 3.5em;
        color: rgba(212, 160, 23, 0.3);
        margin-bottom: 25px;
    }

    .empty-state h3 {
        font-size: 1.4em;
        color: #2F2F2F;
        margin-bottom: 15px;
        font-weight: 600;
    }

    .empty-state p {
        max-width: 300px;
        margin: 0 auto;
        font-size: 1em;
        line-height: 1.6;
    }

    /* Footer */
    .dashboard-footer {
        margin-top: 50px;
        padding: 25px;
        background: white;
        border-radius: 16px;
        text-align: center;
        color: #666;
        font-size: 0.9em;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        width: 100%;
        box-sizing: border-box;
    }

    .footer-content p {
        margin-bottom: 10px;
        line-height: 1.6;
    }

    .footer-content p:last-child {
        margin-bottom: 0;
        color: #B8860B;
        font-weight: 500;
    }

    .footer-content i {
        color: #D4A017;
        margin: 0 5px;
    }

    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(15px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.5s ease-out;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .dashboard-container {
            padding: 15px !important;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .content-grid {
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .quick-actions {
            grid-template-columns: 1fr;
        }

        .card-header {
            flex-direction: column;
            gap: 15px;
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

        .welcome-header h1 {
            font-size: 1.6em;
        }
    }

    @media (max-width: 480px) {
        .stat-value {
            font-size: 2em;
        }

        .card {
            padding: 20px;
        }

        .welcome-header {
            padding: 15px;
        }
    }
</style>

<div class="dashboard-container">
    <!-- Simple Welcome Header -->
    <div class="welcome-header">
        <h1>Welcome back, <?php echo htmlspecialchars($current_user['full_name']); ?>!</h1>
        <p>Here's your leave management overview for today</p>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card fade-in" onclick="window.location.href='manage-employees.php'">
            <div class="stat-header">
                <div class="stat-info">
                    <h3>Active Employees</h3>
                    <div class="stat-value"><?php echo $stats['total_users'] ?? 0; ?></div>
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
                    <div class="stat-value"><?php echo $stats['pending_leaves'] ?? 0; ?></div>
                    <div class="stat-change">
                        <i class="fas fa-clock"></i>
                        <span>Awaiting approval</span>
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
                    <div class="stat-value"><?php echo $stats['approved_this_month'] ?? 0; ?></div>
                    <div class="stat-change positive">
                        <i class="fas fa-chart-line"></i>
                        <span>Leaves approved</span>
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
                    <div class="stat-value"><?php echo $stats['on_leave_today'] ?? 0; ?></div>
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
                    <div class="stat-value"><?php echo $stats['google_forms_today'] ?? 0; ?></div>
                    <div class="stat-change">
                        <i class="fab fa-google"></i>
                        <span>Form submissions</span>
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
                    <div class="stat-value"><?php echo $stats['low_balance_users'] ?? 0; ?></div>
                    <div
                        class="stat-change <?php echo ($stats['low_balance_users'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Employees with < 5 days</span>
                                <?php if (($stats['low_balance_users'] ?? 0) > 0): ?>
                                    <span class="stat-trend"
                                        style="background: rgba(139, 0, 0, 0.1); color: #8B0000; border-color: rgba(139, 0, 0, 0.2);">
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

    <!-- Content Grid -->
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
                                                <div><?php echo htmlspecialchars($leave['full_name']); ?></div>
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
                                        <small style="color: #8B7355; font-size: 0.85em;">
                                            Applied:
                                            <?php echo date('M j', strtotime($leave['applied_date'] ?? $leave['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="days-badge">
                                            <?php echo $total_days; ?> days
                                            <?php if ($half_day !== 'none'): ?>
                                                <span class="half-day-indicator"
                                                    title="Half Day: <?php echo ucfirst($half_day); ?>">
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

        <!-- Right Column -->
        <div style="display: flex; flex-direction: column; gap: 30px; width: 100%;">
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
                                        <span class="date-month"><?php echo $start_date->format('M'); ?></span>
                                        <span class="date-day"><?php echo $start_date->format('d'); ?></span>
                                    </div>
                                    <div class="leave-details">
                                        <h4><?php echo htmlspecialchars($leave['full_name']); ?></h4>
                                        <p>
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo $start_date->format('M j') . ' - ' . $end_date->format('M j, Y'); ?>
                                            <?php if ($half_day !== 'none'): ?>
                                                <span style="margin-left: 10px; color: #D4A017; font-size: 0.9em;">
                                                    <i class="fas fa-clock"></i><?php echo ucfirst($half_day); ?>
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
                        <div style="max-height: 300px; overflow-y: auto; width: 100%;">
                            <?php foreach ($recent_google_forms as $submission):
                                $form_data = json_decode($submission['form_data'] ?? '{}', true);
                                $email = $form_data['email_address'] ?? $submission['employee_email'];
                                $status_class = 'status-' . strtolower($submission['status_label']);
                                ?>
                                <div class="submission-item">
                                    <div class="submission-header">
                                        <div class="submission-email"><?php echo htmlspecialchars($email); ?></div>
                                        <div class="submission-status <?php echo $status_class; ?>">
                                            <i
                                                class="fas <?php echo $submission['status_label'] === 'Processed' ? 'fa-check-circle' : ($submission['status_label'] === 'Failed' ? 'fa-times-circle' : 'fa-clock'); ?>"></i>
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
                                    style="border-left: 4px solid <?php echo $stat['color']; ?>; padding: 6px 12px; font-size: 0.9em;">
                                    <?php echo htmlspecialchars($stat['name']); ?>
                                </span>
                            </div>
                            <div class="stat-bar-container">
                                <div class="stat-bar-fill"
                                    style="width: <?php echo $percentage; ?>%; background: linear-gradient(90deg, <?php echo $stat['color']; ?>, <?php echo adjustBrightness($stat['color'], 20); ?>);">
                                </div>
                            </div>
                            <div class="stat-count">
                                <?php echo $stat['count']; ?> leaves
                                <br>
                                <small style="color: #8B7355; font-size: 0.85em;">
                                    <?php echo $stat['total_days']; ?> days
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <div class="action-card" onclick="window.location.href='leave-approvals.php'">
            <div class="action-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Review Leave Requests</h3>
            <p>Approve or reject pending leave requests from employees and Google Forms</p>
        </div>

        <div class="action-card" onclick="window.location.href='manage-employees.php'">
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

    <!-- Dashboard Footer -->
    <div class="dashboard-footer">
        <div class="footer-content">
            <p>Leave Management System v2.0 | Google Forms Integration Active</p>
            <p><i class="fas fa-sync-alt"></i> Last updated: <?php echo date('F j, Y \a\t g:i A'); ?></p>
            <p>
                <i class="fas fa-database"></i> Database: <?php echo DB_NAME; ?> |
                <i class="fas fa-users"></i> Active Users: <?php echo $stats['total_users'] ?? 0; ?> |
                <i class="fab fa-google"></i> Forms Today: <?php echo $stats['google_forms_today'] ?? 0; ?>
            </p>
        </div>
    </div>
</div>

<script>
    // ========== CONFIGURATION ==========
    const BASE_URL = '/leave-tracker/';
    const API_URL = BASE_URL + 'api/';

    console.log('✅ Leave Tracker Dashboard loaded');
    console.log('📁 Base URL:', BASE_URL);
    console.log('🔗 API URL:', API_URL);

    // ========== ANIMATIONS ==========
    document.querySelectorAll('.stat-card').forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });

    // ========== APPROVE FUNCTION ==========
    function approveLeave(leaveId) {
        const notes = prompt('Add optional notes for the employee (press Cancel to skip):', '');

        if (notes !== null) {
            if (confirm('Approve leave request #' + leaveId + '?')) {
                // Show loading
                const button = document.querySelector(`button[onclick="approveLeave(${leaveId})"]`);
                const originalHTML = button.innerHTML;
                button.innerHTML = '<span class="loading"></span> Approving...';
                button.disabled = true;

                // API CALL
                fetch(API_URL + 'approve-leave.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        id: leaveId,
                        notes: notes || ''
                    })
                })
                    .then(response => {
                        console.log('Response Status:', response.status);

                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }

                        return response.text();
                    })
                    .then(text => {
                        console.log('Response:', text);

                        try {
                            const data = JSON.parse(text);

                            if (data.success) {
                                showNotification('✅ Leave approved successfully!', 'success');
                                // Remove the row
                                removeRow(leaveId);
                                // Update stats
                                updateStats();
                            } else {
                                showNotification('❌ Error: ' + data.message, 'error');
                            }
                        } catch (e) {
                            console.error('Invalid JSON:', text);
                            showNotification('❌ Invalid server response', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        showNotification('❌ Network error: ' + error.message, 'error');
                    })
                    .finally(() => {
                        // Restore button
                        button.innerHTML = originalHTML;
                        button.disabled = false;
                    });
            }
        }
    }

    // ========== REJECT FUNCTION ==========
    function rejectLeave(leaveId) {
        const reason = prompt('Reason for rejection (required):', '');

        if (reason !== null && reason.trim() !== '') {
            if (confirm('Reject leave request #' + leaveId + '?')) {
                // Show loading
                const button = document.querySelector(`button[onclick="rejectLeave(${leaveId})"]`);
                const originalHTML = button.innerHTML;
                button.innerHTML = '<span class="loading"></span> Rejecting...';
                button.disabled = true;

                fetch(API_URL + 'reject-leave.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        id: leaveId,
                        reason: reason.trim()
                    })
                })
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);

                            if (data.success) {
                                showNotification('✅ Leave rejected', 'success');
                                removeRow(leaveId);
                                updateStats();
                            } else {
                                showNotification('❌ Error: ' + data.message, 'error');
                            }
                        } catch (e) {
                            showNotification('❌ Invalid response', 'error');
                        }
                    })
                    .catch(err => {
                        showNotification('❌ Network error: ' + err.message, 'error');
                    })
                    .finally(() => {
                        button.innerHTML = originalHTML;
                        button.disabled = false;
                    });
            }
        } else if (reason !== null) {
            showNotification('⚠️ Reason is required', 'warning');
        }
    }

    // ========== HELPER FUNCTIONS ==========
    function removeRow(leaveId) {
        // Find the row
        const row = document.querySelector(`button[onclick="approveLeave(${leaveId})"]`)?.closest('tr') ||
            document.querySelector(`button[onclick="rejectLeave(${leaveId})"]`)?.closest('tr');

        if (row) {
            // Mark as processed
            row.style.transition = 'all 0.5s ease';
            row.style.backgroundColor = 'rgba(212, 160, 23, 0.05)';

            // Remove after delay
            setTimeout(() => {
                row.style.opacity = '0';
                row.style.height = '0';
                row.style.padding = '0';
                row.style.margin = '0';
                row.style.border = 'none';
                row.style.overflow = 'hidden';

                setTimeout(() => {
                    if (row.parentElement) {
                        row.remove();
                        checkEmptyTable();
                    }
                }, 300);
            }, 1000);
        } else {
            // If row not found, reload page
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }
    }

    function checkEmptyTable() {
        const tableBody = document.querySelector('.leave-table tbody');
        if (tableBody && tableBody.children.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px;">
                        <div style="color: #8B7355; font-size: 0.9em;">
                            <i class="fas fa-check-circle" style="font-size: 3em; color: #D4A017; margin-bottom: 15px;"></i>
                            <h3 style="color: #2F2F2F; margin: 10px 0;">All Caught Up!</h3>
                            <p>No pending leave requests</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    }

    function updateStats() {
        console.log('Updating stats...');

        fetch(API_URL + 'get-dashboard-stats.php')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.stats) {
                    const stats = data.stats;

                    // Update pending count immediately
                    const pendingEl = document.querySelector('.stat-card:nth-child(2) .stat-value');
                    if (pendingEl) {
                        const current = parseInt(pendingEl.textContent) || 0;
                        pendingEl.textContent = Math.max(0, current - 1);
                    }

                    // You can update other stats too if needed
                }
            })
            .catch(err => console.log('Stats update skipped:', err.message));
    }

    function showNotification(message, type = 'info') {
        // Remove existing
        document.querySelectorAll('.custom-notif').forEach(n => n.remove());

        const notif = document.createElement('div');
        notif.className = 'custom-notif';
        notif.innerHTML = `
            <div class="notif-content ${type}">
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-exclamation-circle' :
                    type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()">&times;</button>
            </div>
        `;

        document.body.appendChild(notif);

        setTimeout(() => notif.remove(), 4000);
    }

    // ========== INITIALIZATION ==========
    document.addEventListener('DOMContentLoaded', function () {
        console.log('📊 Dashboard ready');

        // Animate stat bars
        const bars = document.querySelectorAll('.stat-bar-fill');
        bars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => bar.style.width = width, 300);
        });

        // Add notification styles if not present
        if (!document.querySelector('#notif-styles')) {
            const style = document.createElement('style');
            style.id = 'notif-styles';
            style.textContent = `
                .custom-notif {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    animation: slideIn 0.3s ease;
                }
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                .notif-content {
                    padding: 14px 18px;
                    border-radius: 10px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    font-size: 14px;
                    color: white;
                    min-width: 320px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                    font-weight: 500;
                }
                .notif-content.success { 
                    background: linear-gradient(135deg, #D4A017, #B8860B);
                    border-left: 4px solid #B8860B;
                }
                .notif-content.error { 
                    background: linear-gradient(135deg, #8B0000, #B22222);
                    border-left: 4px solid #660000;
                }
                .notif-content.warning { 
                    background: linear-gradient(135deg, #D2B48C, #8B7355);
                    border-left: 4px solid #8B7355;
                }
                .notif-content.info { 
                    background: linear-gradient(135deg, #B0C4DE, #87CEEB);
                    border-left: 4px solid #5D9CEC;
                }
                .notif-content button {
                    background: none;
                    border: none;
                    color: white;
                    cursor: pointer;
                    font-size: 20px;
                    margin-left: auto;
                    opacity: 0.7;
                    padding: 0;
                    width: 24px;
                    height: 24px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 50%;
                }
                .notif-content button:hover { 
                    opacity: 1;
                    background: rgba(255,255,255,0.1);
                }
                .loading {
                    display: inline-block;
                    width: 16px;
                    height: 16px;
                    border: 2px solid rgba(255,255,255,0.3);
                    border-radius: 50%;
                    border-top-color: white;
                    animation: spin 1s linear infinite;
                    margin-right: 8px;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
    });
</script>

<?php
// Helper function for color adjustment
function adjustBrightness($hex, $percent)
{
    // Simple color adjustment
    $color = ltrim($hex, '#');
    if (strlen($color) == 3) {
        $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
    }

    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));

    $r = min(255, max(0, $r + $r * $percent / 100));
    $g = min(255, max(0, $g + $g * $percent / 100));
    $b = min(255, max(0, $b + $b * $percent / 100));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

require_once __DIR__ . '/includes/footer.php';