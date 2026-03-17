<?php
// view-employee.php - View Employee Details
$page_title = "Employee Profile";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is admin or CEO
if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('Location: dashboard.php');
    exit();
}

// Get employee ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage-employees.php?error=' . urlencode('Invalid employee ID'));
    exit();
}

$employee_id = intval($_GET['id']);
$pdo = getPDOConnection();
$employee = null;
$leave_stats = [];
$recent_leaves = [];
$leave_balance = [];
$annual_usage = [];

// Fetch employee details
if ($pdo) {
    try {
        // Get employee basic info
        $stmt = $pdo->prepare("
            SELECT 
                u.*,
                DATE_FORMAT(u.created_at, '%M %d, %Y') as formatted_created,
                DATE_FORMAT(u.updated_at, '%M %d, %Y') as formatted_updated,
                DATE_FORMAT(u.last_login, '%M %d, %Y %h:%i %p') as formatted_last_login,
                (SELECT COUNT(*) FROM leaves WHERE user_id = u.id AND status = 'approved' AND YEAR(created_at) = YEAR(CURDATE())) as total_approved_leaves,
                (SELECT COUNT(*) FROM leaves WHERE user_id = u.id AND status = 'pending') as total_pending_leaves,
                (SELECT COUNT(*) FROM leaves WHERE user_id = u.id) as total_leaves
            FROM users u
            WHERE u.id = ? AND (u.role = 'employee' OR u.role = 'ceo')
        ");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            header('Location: manage-employees.php?error=' . urlencode('Employee not found'));
            exit();
        }
        
        // Get leave statistics for current year
        $stmt = $pdo->prepare("
            SELECT 
                lt.name as leave_type,
                lt.color,
                COUNT(l.id) as total_count,
                SUM(l.total_days) as total_days,
                AVG(l.total_days) as avg_days,
                SUM(CASE WHEN l.status = 'approved' THEN l.total_days ELSE 0 END) as approved_days,
                SUM(CASE WHEN l.status = 'pending' THEN l.total_days ELSE 0 END) as pending_days,
                SUM(CASE WHEN l.status = 'rejected' THEN l.total_days ELSE 0 END) as rejected_days
            FROM leaves l
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.user_id = ? AND YEAR(l.created_at) = YEAR(CURDATE())
            GROUP BY lt.id, lt.name, lt.color
            ORDER BY total_days DESC
        ");
        $stmt->execute([$employee_id]);
        $leave_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent leaves (last 5)
        $stmt = $pdo->prepare("
            SELECT 
                l.*,
                lt.name as leave_type_name,
                lt.color,
                DATEDIFF(l.end_date, l.start_date) + 1 as duration_days,
                DATE_FORMAT(l.start_date, '%b %d, %Y') as formatted_start,
                DATE_FORMAT(l.end_date, '%b %d, %Y') as formatted_end,
                DATE_FORMAT(l.created_at, '%b %d, %Y') as formatted_created,
                CASE 
                    WHEN l.status = 'approved' THEN 'Approved'
                    WHEN l.status = 'pending' THEN 'Pending'
                    WHEN l.status = 'rejected' THEN 'Rejected'
                    ELSE 'Cancelled'
                END as status_label
            FROM leaves l
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.user_id = ?
            ORDER BY l.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$employee_id]);
        $recent_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate leave balance
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN leave_type_id = 1 AND status = 'approved' THEN total_days ELSE 0 END) as used_annual,
                SUM(CASE WHEN leave_type_id = 2 AND status = 'approved' THEN total_days ELSE 0 END) as used_sick,
                SUM(CASE WHEN leave_type_id = 3 AND status = 'approved' THEN total_days ELSE 0 END) as used_emergency
            FROM leaves 
            WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE())
        ");
        $stmt->execute([$employee_id]);
        $used_leaves = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $leave_balance = [
            'annual' => [
                'allocated' => $employee['annual_leave_days'],
                'used' => $used_leaves['used_annual'] ?? 0,
                'remaining' => $employee['annual_leave_days'] - ($used_leaves['used_annual'] ?? 0)
            ],
            'sick' => [
                'allocated' => $employee['sick_leave_days'],
                'used' => $used_leaves['used_sick'] ?? 0,
                'remaining' => $employee['sick_leave_days'] - ($used_leaves['used_sick'] ?? 0)
            ],
            'emergency' => [
                'allocated' => $employee['emergency_leave_days'],
                'used' => $used_leaves['used_emergency'] ?? 0,
                'remaining' => $employee['emergency_leave_days'] - ($used_leaves['used_emergency'] ?? 0)
            ]
        ];
        
        // Get annual usage for chart
        $stmt = $pdo->prepare("
            SELECT 
                MONTH(created_at) as month,
                COUNT(*) as total_leaves,
                SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as approved_days
            FROM leaves 
            WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE())
            GROUP BY MONTH(created_at)
            ORDER BY month
        ");
        $stmt->execute([$employee_id]);
        $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array with all months
        $annual_usage = array_fill(1, 12, ['leaves' => 0, 'days' => 0]);
        foreach ($monthly_data as $data) {
            $annual_usage[$data['month']] = [
                'leaves' => $data['total_leaves'],
                'days' => $data['approved_days'] ?? 0
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching employee details: " . $e->getMessage());
    }
}
?>

<style>
    /* View Employee Styles */
    .content-area {
        padding: 30px;
    }
    
    .profile-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .page-title {
        font-family: 'Playfair Display', serif;
        font-size: 2em;
        color: var(--color-secondary);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .page-title i {
        color: var(--color-primary);
        font-size: 1.5em;
    }
    
    .employee-status {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-top: 10px;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 600;
        min-width: 100px;
        justify-content: center;
    }
    
    .status-active {
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
    
    .status-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: var(--color-danger);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }
    
    .role-badge {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 600;
    }
    
    .header-actions {
        display: flex;
        gap: 12px;
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
        background: var(--color-primary);
        color: var(--color-white);
    }
    
    .btn-primary:hover {
        background: var(--color-primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
    }
    
    .btn-secondary {
        background: var(--color-light-gray);
        color: var(--color-secondary);
        border: 1px solid var(--color-border);
    }
    
    .btn-secondary:hover {
        background: var(--color-border);
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: var(--color-danger);
        color: var(--color-white);
    }
    
    .btn-danger:hover {
        background: #DC2626;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
    }
    
    /* Main Content Grid */
    .profile-grid {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 30px;
    }
    
    @media (max-width: 992px) {
        .profile-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Left Column - Profile Card */
    .profile-card {
        background: var(--color-white);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.08);
        border: 1px solid var(--color-border);
        height: fit-content;
    }
    
    .profile-header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--color-border);
    }
    
    .profile-avatar {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 2.5em;
        font-weight: 700;
        color: white;
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
    }
    
    .profile-name {
        font-size: 1.6em;
        font-weight: 700;
        color: var(--color-secondary);
        margin-bottom: 5px;
    }
    
    .profile-position {
        color: var(--color-dark-gray);
        font-size: 1em;
        margin-bottom: 10px;
    }
    
    .profile-department {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: rgba(59, 130, 246, 0.1);
        color: #3B82F6;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 500;
        border: 1px solid rgba(59, 130, 246, 0.2);
    }
    
    .profile-info {
        margin-top: 25px;
    }
    
    .info-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid var(--color-border-light);
    }
    
    .info-item:last-child {
        border-bottom: none;
    }
    
    .info-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: var(--color-light-gray);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--color-primary);
        font-size: 1.1em;
        flex-shrink: 0;
    }
    
    .info-content {
        flex: 1;
        min-width: 0;
    }
    
    .info-label {
        font-size: 0.85em;
        color: var(--color-dark-gray);
        margin-bottom: 3px;
    }
    
    .info-value {
        font-weight: 600;
        color: var(--color-secondary);
        font-size: 0.95em;
        word-break: break-all;
    }
    
    /* Right Column - Tabs */
    .profile-tabs {
        background: var(--color-white);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.08);
        border: 1px solid var(--color-border);
    }
    
    .tabs-header {
        display: flex;
        background: var(--color-light-gray);
        border-bottom: 1px solid var(--color-border);
        overflow-x: auto;
    }
    
    .tab-button {
        padding: 18px 24px;
        background: none;
        border: none;
        font-weight: 600;
        color: var(--color-dark-gray);
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
        position: relative;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.95em;
    }
    
    .tab-button:hover {
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-primary);
    }
    
    .tab-button.active {
        background: var(--color-white);
        color: var(--color-primary);
    }
    
    .tab-button.active::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--color-primary);
    }
    
    .tab-content {
        padding: 30px;
        display: none;
    }
    
    .tab-content.active {
        display: block;
        animation: fadeIn 0.3s ease-out;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Leave Balance Cards */
    .balance-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .balance-card {
        background: var(--color-white);
        border-radius: 16px;
        padding: 25px;
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .balance-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.12);
        border-color: var(--color-primary-light);
    }
    
    .balance-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
    }
    
    .balance-card.annual::before {
        background: linear-gradient(90deg, var(--color-primary), var(--color-primary-dark));
    }
    
    .balance-card.sick::before {
        background: linear-gradient(90deg, var(--color-info), #2563EB);
    }
    
    .balance-card.emergency::before {
        background: linear-gradient(90deg, var(--color-danger), #DC2626);
    }
    
    .balance-header {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .balance-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5em;
        color: white;
        margin-right: 15px;
    }
    
    .balance-icon.annual {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }
    
    .balance-icon.sick {
        background: linear-gradient(135deg, var(--color-info), #2563EB);
    }
    
    .balance-icon.emergency {
        background: linear-gradient(135deg, var(--color-danger), #DC2626);
    }
    
    .balance-title {
        font-weight: 600;
        color: var(--color-secondary);
        font-size: 1.1em;
        margin-bottom: 3px;
    }
    
    .balance-subtitle {
        font-size: 0.85em;
        color: var(--color-dark-gray);
    }
    
    .balance-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        text-align: center;
    }
    
    .stat-item {
        padding: 12px;
        border-radius: 10px;
        background: var(--color-light-gray);
    }
    
    .stat-value {
        font-size: 1.4em;
        font-weight: 700;
        color: var(--color-secondary);
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.8em;
        color: var(--color-dark-gray);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Leave Statistics */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: var(--color-white);
        border-radius: 16px;
        padding: 25px;
        border: 1px solid var(--color-border);
    }
    
    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--color-border);
    }
    
    .stat-card-title {
        font-weight: 600;
        color: var(--color-secondary);
        font-size: 1.1em;
    }
    
    .stat-card-value {
        font-size: 1.8em;
        font-weight: 700;
        color: var(--color-primary);
    }
    
    .stat-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .stat-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid var(--color-border-light);
    }
    
    .stat-list-item:last-child {
        border-bottom: none;
    }
    
    .stat-label {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .stat-color {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }
    
    /* Recent Leaves */
    .recent-leaves {
        background: var(--color-white);
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--color-border);
    }
    
    .leaves-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .leaves-table th {
        text-align: left;
        padding: 18px 16px;
        background: var(--color-light-gray);
        color: var(--color-secondary);
        font-weight: 600;
        font-size: 0.9em;
        border-bottom: 2px solid var(--color-border);
    }
    
    .leaves-table td {
        padding: 16px;
        border-bottom: 1px solid var(--color-border);
        vertical-align: middle;
    }
    
    .leaves-table tr:hover {
        background: var(--color-light-gray);
    }
    
    .leaves-table tr:last-child td {
        border-bottom: none;
    }
    
    .leave-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 18px;
        font-size: 0.85em;
        font-weight: 600;
        background: var(--color-light-gray);
    }
    
    .leave-status {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        min-width: 90px;
        justify-content: center;
    }
    
    .status-approved {
        background: rgba(16, 185, 129, 0.1);
        color: var(--color-success);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
    
    .status-pending {
        background: rgba(245, 158, 11, 0.1);
        color: var(--color-warning);
        border: 1px solid rgba(245, 158, 11, 0.2);
    }
    
    .status-rejected {
        background: rgba(239, 68, 68, 0.1);
        color: var(--color-danger);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }
    
    /* Chart Container */
    .chart-container {
        background: var(--color-white);
        border-radius: 16px;
        padding: 25px;
        border: 1px solid var(--color-border);
        margin-bottom: 30px;
    }
    
    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .chart-title {
        font-weight: 600;
        color: var(--color-secondary);
        font-size: 1.2em;
    }
    
    .chart-year {
        color: var(--color-dark-gray);
        font-size: 0.9em;
    }
    
    .chart-wrapper {
        height: 250px;
        position: relative;
    }
    
    .chart-bar {
        display: flex;
        align-items: flex-end;
        height: 200px;
        padding: 20px 0;
        gap: 20px;
    }
    
    .month-bar {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .bar-value {
        flex: 1;
        width: 30px;
        background: linear-gradient(to top, var(--color-primary), var(--color-primary-light));
        border-radius: 4px 4px 0 0;
        min-height: 1px;
        position: relative;
        transition: height 0.5s ease;
    }
    
    .bar-label {
        margin-top: 10px;
        font-size: 0.8em;
        color: var(--color-dark-gray);
        text-transform: uppercase;
    }
    
    .bar-tooltip {
        position: absolute;
        top: -40px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--color-secondary);
        color: white;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 0.8em;
        white-space: nowrap;
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }
    
    .month-bar:hover .bar-tooltip {
        opacity: 1;
    }
    
    .month-bar:hover .bar-value {
        background: linear-gradient(to top, var(--color-primary-dark), var(--color-primary));
    }
    
    .chart-legend {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-top: 20px;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85em;
        color: var(--color-dark-gray);
    }
    
    .legend-color {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }
    
    .legend-leaves {
        background: var(--color-primary);
    }
    
    .legend-days {
        background: var(--color-primary-light);
    }
    
    /* Empty States */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--color-dark-gray);
    }
    
    .empty-state i {
        font-size: 3em;
        color: var(--color-primary-light);
        margin-bottom: 20px;
        opacity: 0.7;
    }
    
    .empty-state h3 {
        font-size: 1.2em;
        color: var(--color-secondary);
        margin-bottom: 10px;
        font-weight: 600;
    }
    
    .empty-state p {
        max-width: 400px;
        margin: 0 auto 20px;
        font-size: 0.95em;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }
        
        .page-header {
            flex-direction: column;
        }
        
        .header-actions {
            width: 100%;
            justify-content: flex-start;
        }
        
        .balance-cards,
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .tabs-header {
            flex-wrap: wrap;
        }
        
        .tab-button {
            flex: 1;
            min-width: 120px;
            justify-content: center;
        }
        
        .profile-card {
            padding: 20px;
        }
        
        .tab-content {
            padding: 20px;
        }
        
        .balance-stats {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    
    @media (max-width: 480px) {
        .balance-stats {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .chart-bar {
            gap: 10px;
        }
        
        .bar-value {
            width: 20px;
        }
    }
</style>

<div class="content-area">
    <div class="profile-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-user"></i>
                    <?php echo $page_title; ?>
                </h1>
                <div class="employee-status">
                    <span class="status-badge <?php echo $employee['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas fa-circle" style="font-size: 0.6em;"></i>
                        <?php echo ucfirst($employee['status']); ?>
                    </span>
                    <span class="role-badge">
                        <i class="fas fa-<?php echo $employee['role'] === 'ceo' ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo strtoupper($employee['role']); ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <a href="manage-employees.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Employees
                </a>
                <a href="add-employee.php?edit=<?php echo $employee_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i>
                    Edit Profile
                </a>
                <a href="apply-leave-for-employee.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-primary">
                    <i class="fas fa-calendar-plus"></i>
                    Apply Leave
                </a>
            </div>
        </div>
        
        <!-- Main Content Grid -->
        <div class="profile-grid">
            <!-- Left Column - Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-name"><?php echo htmlspecialchars($employee['full_name']); ?></div>
                    <div class="profile-position"><?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?></div>
                    <?php if ($employee['department']): ?>
                        <div class="profile-department">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($employee['department']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="profile-info">
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['email']); ?></div>
                        </div>
                    </div>
                    
                    <?php if ($employee['phone']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['phone']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?php echo $employee['formatted_created']; ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Last Updated</div>
                            <div class="info-value"><?php echo $employee['formatted_updated']; ?></div>
                        </div>
                    </div>
                    
                    <?php if ($employee['last_login']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Last Login</div>
                            <div class="info-value"><?php echo $employee['formatted_last_login']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Email Notifications</div>
                            <div class="info-value">
                                <?php echo $employee['email_notifications'] ? 'Enabled' : 'Disabled'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Tabs -->
            <div class="profile-tabs">
                <div class="tabs-header">
                    <button class="tab-button active" onclick="showTab('overview')">
                        <i class="fas fa-chart-pie"></i>
                        Overview
                    </button>
                    <button class="tab-button" onclick="showTab('leaves')">
                        <i class="fas fa-calendar-alt"></i>
                        Leave History
                    </button>
                    <button class="tab-button" onclick="showTab('statistics')">
                        <i class="fas fa-chart-bar"></i>
                        Statistics
                    </button>
                </div>
                
                <!-- Overview Tab -->
                <div id="overviewTab" class="tab-content active">
                    <!-- Leave Balance Cards -->
                    <div class="balance-cards">
                        <div class="balance-card annual">
                            <div class="balance-header">
                                <div class="balance-icon annual">
                                    <i class="fas fa-umbrella-beach"></i>
                                </div>
                                <div>
                                    <div class="balance-title">Annual Leave</div>
                                    <div class="balance-subtitle">Paid vacation days</div>
                                </div>
                            </div>
                            <div class="balance-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['annual']['allocated']; ?></div>
                                    <div class="stat-label">Allocated</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['annual']['used']; ?></div>
                                    <div class="stat-label">Used</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['annual']['remaining']; ?></div>
                                    <div class="stat-label">Remaining</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="balance-card sick">
                            <div class="balance-header">
                                <div class="balance-icon sick">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div>
                                    <div class="balance-title">Sick Leave</div>
                                    <div class="balance-subtitle">Medical and health</div>
                                </div>
                            </div>
                            <div class="balance-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['sick']['allocated']; ?></div>
                                    <div class="stat-label">Allocated</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['sick']['used']; ?></div>
                                    <div class="stat-label">Used</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['sick']['remaining']; ?></div>
                                    <div class="stat-label">Remaining</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="balance-card emergency">
                            <div class="balance-header">
                                <div class="balance-icon emergency">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <div>
                                    <div class="balance-title">Emergency Leave</div>
                                    <div class="balance-subtitle">Urgent matters</div>
                                </div>
                            </div>
                            <div class="balance-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['emergency']['allocated']; ?></div>
                                    <div class="stat-label">Allocated</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['emergency']['used']; ?></div>
                                    <div class="stat-label">Used</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $leave_balance['emergency']['remaining']; ?></div>
                                    <div class="stat-label">Remaining</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <div class="stat-card-title">Total Leaves</div>
                                <div class="stat-card-value"><?php echo $employee['total_leaves'] ?? 0; ?></div>
                            </div>
                            <ul class="stat-list">
                                <li class="stat-list-item">
                                    <span>Approved</span>
                                    <span style="color: var(--color-success); font-weight: 600;">
                                        <?php echo $employee['total_approved_leaves'] ?? 0; ?>
                                    </span>
                                </li>
                                <li class="stat-list-item">
                                    <span>Pending</span>
                                    <span style="color: var(--color-warning); font-weight: 600;">
                                        <?php echo $employee['total_pending_leaves'] ?? 0; ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-card-header">
                                <div class="stat-card-title">Activity</div>
                                <div class="stat-card-value">
                                    <?php 
                                        $days_since_created = $employee['created_at'] ? 
                                            round((time() - strtotime($employee['created_at'])) / (60 * 60 * 24)) : 0;
                                        echo $days_since_created;
                                    ?> days
                                </div>
                            </div>
                            <ul class="stat-list">
                                <li class="stat-list-item">
                                    <span>Account Created</span>
                                    <span><?php echo $employee['formatted_created']; ?></span>
                                </li>
                                <li class="stat-list-item">
                                    <span>Last Updated</span>
                                    <span><?php echo $employee['formatted_updated']; ?></span>
                                </li>
                                <?php if ($employee['last_login']): ?>
                                <li class="stat-list-item">
                                    <span>Last Login</span>
                                    <span><?php echo $employee['formatted_last_login']; ?></span>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Annual Usage Chart -->
                    <div class="chart-container">
                        <div class="chart-header">
                            <div class="chart-title">Annual Leave Usage</div>
                            <div class="chart-year"><?php echo date('Y'); ?></div>
                        </div>
                        <div class="chart-wrapper">
                            <div class="chart-bar">
                                <?php 
                                $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                $max_days = max(array_column($annual_usage, 'days')) ?: 1;
                                foreach ($annual_usage as $month => $data):
                                    $height = ($data['days'] / $max_days) * 150; // Scale to 150px max height
                                    $leaves_tooltip = $data['leaves'] . ' leave' . ($data['leaves'] != 1 ? 's' : '');
                                    $days_tooltip = $data['days'] . ' day' . ($data['days'] != 1 ? 's' : '');
                                ?>
                                <div class="month-bar">
                                    <div class="bar-value" style="height: <?php echo $height; ?>px;">
                                        <div class="bar-tooltip">
                                            <?php echo $leaves_tooltip; ?><br>
                                            <?php echo $days_tooltip; ?>
                                        </div>
                                    </div>
                                    <div class="bar-label"><?php echo $month_names[$month - 1]; ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="chart-legend">
                                <div class="legend-item">
                                    <div class="legend-color legend-leaves"></div>
                                    <span>Leaves Count</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color legend-days"></div>
                                    <span>Days Taken</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Leave History Tab -->
                <div id="leavesTab" class="tab-content">
                    <?php if (empty($recent_leaves)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Leave History</h3>
                            <p>This employee has not applied for any leaves yet.</p>
                            <a href="apply-leave-for-employee.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="fas fa-calendar-plus"></i> Apply First Leave
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="recent-leaves">
                            <table class="leaves-table">
                                <thead>
                                    <tr>
                                        <th>Leave Type</th>
                                        <th>Dates</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Applied On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_leaves as $leave): ?>
                                    <tr>
                                        <td>
                                            <span class="leave-type-badge" style="border-left: 4px solid <?php echo $leave['color']; ?>">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $leave['formatted_start']; ?> -<br>
                                            <?php echo $leave['formatted_end']; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--color-secondary);">
                                                <?php echo $leave['duration_days']; ?> days
                                            </span>
                                        </td>
                                        <td>
                                            <span class="leave-status status-<?php echo $leave['status']; ?>">
                                                <i class="fas fa-circle" style="font-size: 0.6em;"></i>
                                                <?php echo $leave['status_label']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $leave['formatted_created']; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="employee-leaves.php?id=<?php echo $employee_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-list"></i>
                                View All Leaves
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Statistics Tab -->
                <div id="statisticsTab" class="tab-content">
                    <?php if (empty($leave_stats)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h3>No Statistics Available</h3>
                            <p>No leave data available for statistics.</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-container" style="margin-bottom: 30px;">
                            <div class="chart-header">
                                <div class="chart-title">Leave Type Distribution</div>
                                <div class="chart-year"><?php echo date('Y'); ?></div>
                            </div>
                            <div class="chart-wrapper">
                                <div class="chart-bar">
                                    <?php 
                                    $total_days = array_sum(array_column($leave_stats, 'total_days'));
                                    foreach ($leave_stats as $stat):
                                        $percentage = $total_days > 0 ? ($stat['total_days'] / $total_days) * 100 : 0;
                                        $height = ($percentage / 100) * 150; // Scale to 150px max height
                                    ?>
                                    <div class="month-bar">
                                        <div class="bar-value" style="height: <?php echo $height; ?>px; background: linear-gradient(to top, <?php echo $stat['color']; ?>, <?php echo adjustBrightness($stat['color'], 20); ?>);">
                                            <div class="bar-tooltip">
                                                <?php echo $stat['leave_type']; ?><br>
                                                <?php echo $stat['total_days']; ?> days (<?php echo round($percentage, 1); ?>%)
                                            </div>
                                        </div>
                                        <div class="bar-label"><?php echo substr($stat['leave_type'], 0, 3); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="recent-leaves">
                            <table class="leaves-table">
                                <thead>
                                    <tr>
                                        <th>Leave Type</th>
                                        <th>Total Leaves</th>
                                        <th>Total Days</th>
                                        <th>Avg. Duration</th>
                                        <th>Status Distribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leave_stats as $stat): ?>
                                    <tr>
                                        <td>
                                            <div class="stat-label">
                                                <div class="stat-color" style="background: <?php echo $stat['color']; ?>;"></div>
                                                <?php echo htmlspecialchars($stat['leave_type']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo $stat['total_count']; ?></td>
                                        <td><?php echo $stat['total_days']; ?> days</td>
                                        <td><?php echo round($stat['avg_days'] ?? 0, 1); ?> days</td>
                                        <td>
                                            <div style="display: flex; gap: 10px; font-size: 0.85em;">
                                                <span style="color: var(--color-success);">
                                                    <i class="fas fa-check"></i> <?php echo $stat['approved_days']; ?>
                                                </span>
                                                <span style="color: var(--color-warning);">
                                                    <i class="fas fa-clock"></i> <?php echo $stat['pending_days']; ?>
                                                </span>
                                                <span style="color: var(--color-danger);">
                                                    <i class="fas fa-times"></i> <?php echo $stat['rejected_days']; ?>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Tab switching functionality
    function showTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active class from all tab buttons
        document.querySelectorAll('.tab-button').forEach(button => {
            button.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + 'Tab').classList.add('active');
        
        // Add active class to clicked button
        event.currentTarget.classList.add('active');
    }
    
    // Initialize chart bars animation
    document.addEventListener('DOMContentLoaded', function() {
        // Animate the bars in the annual usage chart
        const bars = document.querySelectorAll('.bar-value');
        bars.forEach(bar => {
            const originalHeight = bar.style.height;
            bar.style.height = '0px';
            
            setTimeout(() => {
                bar.style.height = originalHeight;
            }, 300);
        });
        
        // Set up tooltips
        setupTooltips();
    });
    
    function setupTooltips() {
        const monthBars = document.querySelectorAll('.month-bar');
        monthBars.forEach(bar => {
            bar.addEventListener('mouseenter', function() {
                const tooltip = this.querySelector('.bar-tooltip');
                if (tooltip) {
                    // Position tooltip
                    const rect = this.getBoundingClientRect();
                    tooltip.style.left = '50%';
                    tooltip.style.transform = 'translateX(-50%)';
                }
            });
        });
    }
    
    // Helper function to adjust color brightness
    <?php
    function adjustBrightness($hex, $percent) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = min(255, max(0, $r + $r * $percent / 100));
        $g = min(255, max(0, $g + $g * $percent / 100));
        $b = min(255, max(0, $b + $b * $percent / 100));
        
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    ?>
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>