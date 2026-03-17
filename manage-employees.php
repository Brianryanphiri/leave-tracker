<?php
// manage-employees.php - Employee Management Dashboard (CLEAN WHITE VERSION)
$page_title = "Employee Management";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is admin or CEO
if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('Location: dashboard.php');
    exit();
}

$pdo = getPDOConnection();
$employees = [];
$departments = [];
$stats = [];
$search_query = '';
$department_filter = '';
$status_filter = '';
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$total_employees = 0;
$total_pages = 1;

// Handle search and filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search_query = $_GET['search'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    $status_filter = $_GET['status'] ?? '';
}

// Fetch data
if ($pdo) {
    try {
        // Build query with filters
        $where_conditions = [];
        $params = [];

        if (!empty($search_query)) {
            $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.position LIKE ?)";
            $search_term = "%{$search_query}%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if (!empty($department_filter)) {
            $where_conditions[] = "u.department = ?";
            $params[] = $department_filter;
        }

        if (!empty($status_filter)) {
            $where_conditions[] = "u.status = ?";
            $params[] = $status_filter;
        }

        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get total count for pagination
        $count_sql = "SELECT COUNT(*) as total FROM users u WHERE u.role = 'employee' OR u.role = 'ceo'";
        if ($where_conditions) {
            $count_sql .= " AND " . implode(' AND ', $where_conditions);
        }

        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $count_result = $stmt->fetch();
        $total_employees = $count_result['total'];
        $total_pages = ceil($total_employees / $per_page);

        // Calculate offset
        $offset = ($current_page - 1) * $per_page;

        // Fetch employees with leave statistics
        $sql = "
            SELECT 
                u.id,
                u.email,
                u.full_name,
                u.role,
                u.department,
                u.position,
                u.phone,
                u.status,
                u.annual_leave_days,
                u.sick_leave_days,
                u.emergency_leave_days,
                u.created_at,
                u.updated_at,
                COALESCE(COUNT(DISTINCT l.id), 0) as total_leaves,
                COALESCE(SUM(CASE WHEN l.status = 'approved' THEN l.total_days ELSE 0 END), 0) as approved_days,
                COALESCE(SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END), 0) as pending_leaves,
                COALESCE(SUM(CASE WHEN l.status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected_leaves
            FROM users u
            LEFT JOIN leaves l ON u.id = l.user_id AND YEAR(l.created_at) = YEAR(CURDATE())
            WHERE (u.role = 'employee' OR u.role = 'ceo')
        ";

        if ($where_conditions) {
            $sql .= " AND " . implode(' AND ', $where_conditions);
        }

        $sql .= " GROUP BY u.id ORDER BY u.full_name ASC LIMIT ? OFFSET ?";

        $params[] = $per_page;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get unique departments for filter
        $stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get statistics
        $stats = getEmployeeStatistics();

    } catch (PDOException $e) {
        error_log("Error fetching employees: " . $e->getMessage());
    }
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_employees'])) {
        $action = $_POST['bulk_action'];
        $selected_ids = array_map('intval', $_POST['selected_employees']);

        if (!empty($selected_ids)) {
            try {
                $ids_placeholder = implode(',', array_fill(0, count($selected_ids), '?'));

                switch ($action) {
                    case 'activate':
                        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($ids_placeholder)");
                        $stmt->execute($selected_ids);
                        break;
                    case 'deactivate':
                        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($ids_placeholder)");
                        $stmt->execute($selected_ids);
                        break;
                    case 'delete':
                        // Soft delete - mark as inactive
                        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($ids_placeholder)");
                        $stmt->execute($selected_ids);
                        break;
                }

                // Refresh page
                header("Location: manage-employees.php?success=" . urlencode("Bulk action completed successfully"));
                exit();

            } catch (PDOException $e) {
                error_log("Error performing bulk action: " . $e->getMessage());
                $error_message = "Error performing bulk action. Please try again.";
            }
        }
    }
}

// Helper function for statistics
function getEmployeeStatistics()
{
    global $pdo;

    $stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'on_leave_today' => 0,
        'new_this_month' => 0
    ];

    if ($pdo) {
        try {
            $today = date('Y-m-d');

            // Total employees
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role IN ('employee', 'ceo')");
            $result = $stmt->fetch();
            $stats['total'] = $result['count'];

            // Active employees
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'active' AND role IN ('employee', 'ceo')");
            $result = $stmt->fetch();
            $stats['active'] = $result['count'];

            // Inactive employees
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'inactive' AND role IN ('employee', 'ceo')");
            $result = $stmt->fetch();
            $stats['inactive'] = $result['count'];

            // Employees on leave today
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT u.id) as count 
                FROM users u
                JOIN leaves l ON u.id = l.user_id
                WHERE l.status = 'approved' 
                AND ? BETWEEN l.start_date AND l.end_date
                AND u.status = 'active'
            ");
            $stmt->execute([$today]);
            $result = $stmt->fetch();
            $stats['on_leave_today'] = $result['count'];

            // New employees this month
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM users 
                WHERE MONTH(created_at) = MONTH(CURDATE()) 
                AND YEAR(created_at) = YEAR(CURDATE())
                AND role IN ('employee', 'ceo')
            ");
            $result = $stmt->fetch();
            $stats['new_this_month'] = $result['count'];

        } catch (PDOException $e) {
            error_log("Error fetching employee statistics: " . $e->getMessage());
        }
    }

    return $stats;
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

    /* Employee Management Styles */
    .content-area {
        position: relative;
        z-index: 1;
        padding: 30px;
        max-width: 100%;
        width: 100%;
        box-sizing: border-box;
        padding-top: 20px;
        /* Reduced top padding since header exists */
    }

    /* Simple Page Header */
    .page-header {
        margin-bottom: 30px;
        padding: 20px;
        background: white;
        border-radius: 16px;
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
        font-size: 1.8em;
        color: var(--color-text);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .page-title i {
        font-size: 1.4em;
        color: var(--color-primary);
    }

    /* Stats Cards */
    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
        cursor: pointer;
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

    .stat-card .stat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .stat-card .stat-icon {
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
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.35);
    }

    .stat-card .stat-icon.total {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-card .stat-icon.active {
        background: linear-gradient(135deg, var(--color-success), var(--color-primary-dark));
    }

    .stat-card .stat-icon.inactive {
        background: linear-gradient(135deg, var(--color-danger), #8B4513);
    }

    .stat-card .stat-icon.on-leave {
        background: linear-gradient(135deg, var(--color-warning), var(--color-primary));
    }

    .stat-card .stat-icon.new {
        background: linear-gradient(135deg, var(--color-info), #34A853);
    }

    .stat-card .stat-value {
        font-size: 2.2em;
        font-weight: 700;
        color: var(--color-text);
        margin-bottom: 8px;
        line-height: 1;
    }

    .stat-card .stat-label {
        font-size: 0.95em;
        color: var(--color-secondary);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Action Bar */
    .action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
        background: white;
        padding: 25px;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
    }

    .search-box {
        position: relative;
        min-width: 350px;
        flex: 1;
    }

    .search-box input {
        width: 100%;
        padding: 14px 50px 14px 20px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-size: 0.95em;
        transition: all 0.3s ease;
        background: var(--color-white);
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

    .filter-group {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .filter-select {
        padding: 14px 18px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        background: var(--color-white);
        font-size: 0.95em;
        color: var(--color-text);
        min-width: 160px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .action-buttons {
        display: flex;
        gap: 15px;
    }

    .btn {
        padding: 14px 26px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.95em;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        min-width: 140px;
        justify-content: center;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
        color: var(--color-white);
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
        color: var(--color-white);
        box-shadow: 0 4px 12px rgba(66, 133, 244, 0.2);
    }

    .btn-export:hover {
        background: linear-gradient(135deg, #34A853 0%, #0F9D58 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(66, 133, 244, 0.3);
    }

    /* Employees Table */
    .employees-table-container {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        margin-bottom: 40px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .employees-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    .employees-table th {
        text-align: left;
        padding: 20px 16px;
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

    .employees-table th:first-child {
        width: 60px;
        text-align: center;
    }

    .employees-table td {
        padding: 18px 16px;
        border-bottom: 1px solid var(--color-border);
        vertical-align: middle;
        color: var(--color-dark-gray);
    }

    .employees-table tr:hover {
        background: rgba(212, 160, 23, 0.03);
    }

    .employees-table tr:last-child td {
        border-bottom: none;
    }

    /* Employee Row Styles */
    .employee-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .employee-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 1em;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(212, 160, 23, 0.2);
    }

    .employee-details {
        flex: 1;
        min-width: 0;
    }

    .employee-name {
        font-weight: 600;
        color: var(--color-text);
        margin-bottom: 5px;
        font-size: 1em;
    }

    .employee-email {
        font-size: 0.85em;
        color: var(--color-dark-gray);
        word-break: break-all;
    }

    .department-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 500;
        background: white;
        color: var(--color-primary-dark);
        border: 1px solid var(--color-border);
        white-space: nowrap;
    }

    .position-text {
        font-weight: 500;
        color: var(--color-text);
        font-size: 0.95em;
        white-space: nowrap;
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        min-width: 90px;
        justify-content: center;
        white-space: nowrap;
    }

    .status-active {
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-success);
        border: 1px solid rgba(212, 160, 23, 0.3);
    }

    .status-inactive {
        background: rgba(139, 69, 19, 0.1);
        color: var(--color-danger);
        border: 1px solid rgba(139, 69, 19, 0.2);
    }

    /* Leave Statistics */
    .leave-stats {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 150px;
    }

    .stat-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85em;
    }

    .stat-item i {
        width: 18px;
        text-align: center;
        color: var(--color-secondary);
        flex-shrink: 0;
    }

    .stat-approved {
        color: var(--color-success);
    }

    .stat-pending {
        color: var(--color-warning);
    }

    .stat-rejected {
        color: var(--color-danger);
    }

    /* Action Buttons */
    .action-menu {
        display: flex;
        gap: 10px;
    }

    .btn-action {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95em;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        color: white;
        text-decoration: none;
    }

    .btn-view {
        background: linear-gradient(135deg, var(--color-info), #4285F4);
    }

    .btn-view:hover {
        background: linear-gradient(135deg, #4285F4, #3367D6);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(66, 133, 244, 0.3);
    }

    .btn-edit {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    }

    .btn-edit:hover {
        background: linear-gradient(135deg, var(--color-primary-dark), #D2691E);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.3);
    }

    .btn-delete {
        background: linear-gradient(135deg, var(--color-danger), #8B4513);
    }

    .btn-delete:hover {
        background: linear-gradient(135deg, #8B4513, #A0522D);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(139, 69, 19, 0.3);
    }

    /* Bulk Actions */
    .bulk-actions {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 20px;
        background: rgba(212, 160, 23, 0.05);
        border-top: 1px solid var(--color-border);
    }

    .bulk-select {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--color-text);
        font-weight: 500;
    }

    .bulk-select input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--color-primary);
    }

    .bulk-actions select {
        padding: 12px 16px;
        border: 2px solid var(--color-border);
        border-radius: 10px;
        background: white;
        font-size: 0.95em;
        color: var(--color-text);
        min-width: 180px;
        cursor: pointer;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 30px;
        flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        height: 44px;
        padding: 0 14px;
        border-radius: 12px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
        font-size: 0.95em;
    }

    .pagination a {
        background: white;
        color: var(--color-text);
        border: 2px solid var(--color-border);
    }

    .pagination a:hover {
        background: var(--color-primary);
        color: white;
        border-color: var(--color-primary);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.2);
    }

    .pagination .active {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
    }

    .pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
        background: rgba(212, 160, 23, 0.05);
    }

    /* Checkbox */
    .checkbox-cell {
        text-align: center;
    }

    .employee-checkbox {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--color-primary);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
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

    /* Success/Error Messages */
    .alert {
        padding: 18px 22px;
        border-radius: 12px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 15px;
        animation: slideIn 0.3s ease-out;
        border-left: 4px solid;
    }

    .alert-success {
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-success);
        border-left-color: var(--color-success);
    }

    .alert-error {
        background: rgba(139, 69, 19, 0.1);
        color: var(--color-danger);
        border-left-color: var(--color-danger);
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* CEO Badge */
    .ceo-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-left: 8px;
        color: var(--color-primary);
        font-size: 0.8em;
        background: rgba(212, 160, 23, 0.1);
        padding: 2px 8px;
        border-radius: 10px;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .action-bar {
            flex-direction: column;
            align-items: stretch;
            gap: 20px;
        }

        .search-box {
            min-width: 100%;
        }

        .filter-group {
            width: 100%;
            justify-content: space-between;
        }

        .filter-select {
            flex: 1;
            min-width: auto;
        }

        .action-buttons {
            width: 100%;
            justify-content: flex-start;
        }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }

        .stats-container {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
        }

        .page-title {
            font-size: 1.6em;
        }

        .page-header {
            padding: 20px;
        }

        .action-bar {
            padding: 20px;
        }

        .btn {
            padding: 12px 20px;
            font-size: 0.9em;
            min-width: auto;
            flex: 1;
        }

        .action-menu {
            flex-direction: column;
            gap: 8px;
        }

        .btn-action {
            width: 100%;
            height: 36px;
        }

        .bulk-actions {
            flex-direction: column;
            gap: 15px;
            align-items: stretch;
        }

        .bulk-select {
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .stats-container {
            grid-template-columns: 1fr;
        }

        .page-title {
            font-size: 1.4em;
        }

        .filter-group {
            flex-direction: column;
        }

        .filter-select {
            width: 100%;
        }

        .action-buttons {
            flex-direction: column;
        }
    }
</style>



    <!-- Statistics Cards -->
    <div class="stats-container">
        <div class="stat-card" onclick="window.location.href='manage-employees.php?status=active'">
            <div class="stat-header">
                <div>
                    <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Employees</div>
                </div>
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="stat-card" onclick="window.location.href='manage-employees.php?status=active'">
            <div class="stat-header">
                <div>
                    <div class="stat-value"><?php echo $stats['active'] ?? 0; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>

        <div class="stat-card" onclick="window.location.href='manage-employees.php?status=inactive'">
            <div class="stat-header">
                <div>
                    <div class="stat-value"><?php echo $stats['inactive'] ?? 0; ?></div>
                    <div class="stat-label">Inactive</div>
                </div>
                <div class="stat-icon inactive">
                    <i class="fas fa-user-slash"></i>
                </div>
            </div>
        </div>

        <div class="stat-card" onclick="window.location.href='calendar.php'">
            <div class="stat-header">
                <div>
                    <div class="stat-value"><?php echo $stats['on_leave_today'] ?? 0; ?></div>
                    <div class="stat-label">On Leave Today</div>
                </div>
                <div class="stat-icon on-leave">
                    <i class="fas fa-umbrella-beach"></i>
                </div>
            </div>
        </div>

        <div class="stat-card" onclick="window.location.href='reports.php?filter=recent'">
            <div class="stat-header">
                <div>
                    <div class="stat-value"><?php echo $stats['new_this_month'] ?? 0; ?></div>
                    <div class="stat-label">New This Month</div>
                </div>
                <div class="stat-icon new">
                    <i class="fas fa-user-plus"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
        <form method="GET" action="" class="search-box">
            <input type="text" name="search" placeholder="Search employees by name, email, or position..."
                value="<?php echo htmlspecialchars($search_query); ?>">
            <i class="fas fa-search"></i>
        </form>

        <form method="GET" action="" class="filter-group">
            <select name="department" class="filter-select" onchange="this.form.submit()">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter == $dept ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </form>

        <div class="action-buttons">
            <a href="add-employee.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i>
                Add New Employee
            </a>
            <button type="button" class="btn btn-secondary" onclick="exportEmployees()">
                <i class="fas fa-file-export"></i>
                Export
            </button>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="employees-table-container">
        <?php if (empty($employees)): ?>
            <div class="empty-state">
                <i class="fas fa-user-friends"></i>
                <h3>No Employees Found</h3>
                <p>No employees match your search criteria. Try adjusting your filters or add a new employee.</p>
                <a href="add-employee.php" class="btn btn-primary" style="margin-top: 20px; display: inline-flex;">
                    <i class="fas fa-user-plus"></i> Add New Employee
                </a>
            </div>
        <?php else: ?>
            <form method="POST" action="" id="bulkForm">
                <div class="table-responsive">
                    <table class="employees-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="employee-checkbox">
                                </th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Status</th>
                                <th>Leave Balance</th>
                                <th>Leave Statistics</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee):
                                $leave_balance = [
                                    'annual' => $employee['annual_leave_days'] - $employee['approved_days'],
                                    'sick' => $employee['sick_leave_days'],
                                    'emergency' => $employee['emergency_leave_days']
                                ];
                                $status_class = 'status-' . $employee['status'];
                                ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="selected_employees[]"
                                            value="<?php echo $employee['id']; ?>" class="employee-checkbox">
                                    </td>
                                    <td>
                                        <div class="employee-info">
                                            <div class="employee-avatar">
                                                <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="employee-details">
                                                <div class="employee-name">
                                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                                    <?php if ($employee['role'] == 'ceo'): ?>
                                                        <span class="ceo-badge">
                                                            <i class="fas fa-crown"></i> CEO
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="employee-email">
                                                    <?php echo htmlspecialchars($employee['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($employee['department']): ?>
                                            <span class="department-badge">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($employee['department']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--color-dark-gray); font-size: 0.9em;">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="position-text">
                                            <?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fas fa-circle" style="font-size: 0.6em;"></i>
                                            <?php echo ucfirst($employee['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="leave-stats">
                                            <div class="stat-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><strong><?php echo $leave_balance['annual']; ?></strong> days</span>
                                            </div>
                                            <div class="stat-item">
                                                <i class="fas fa-heartbeat"></i>
                                                <span><strong><?php echo $leave_balance['sick']; ?></strong> days</span>
                                            </div>
                                            <div class="stat-item">
                                                <i class="fas fa-exclamation-circle"></i>
                                                <span><strong><?php echo $leave_balance['emergency']; ?></strong> days</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="leave-stats">
                                            <div class="stat-item">
                                                <i class="fas fa-check-circle stat-approved"></i>
                                                <span><?php echo $employee['approved_days']; ?> days</span>
                                            </div>
                                            <div class="stat-item">
                                                <i class="fas fa-clock stat-pending"></i>
                                                <span><?php echo $employee['pending_leaves']; ?> pending</span>
                                            </div>
                                            <div class="stat-item">
                                                <i class="fas fa-times-circle stat-rejected"></i>
                                                <span><?php echo $employee['rejected_leaves']; ?> rejected</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-menu">
                                            <a href="view-employee.php?id=<?php echo $employee['id']; ?>"
                                                class="btn-action btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="add-employee.php?edit=<?php echo $employee['id']; ?>"
                                                class="btn-action btn-edit" title="Edit Employee">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn-action btn-delete" title="Deactivate Employee"
                                                onclick="confirmDelete(<?php echo $employee['id']; ?>, '<?php echo htmlspecialchars($employee['full_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk Actions -->
                <div class="bulk-actions">
                    <div class="bulk-select">
                        <input type="checkbox" id="bulkSelectAll" onchange="toggleBulkSelectAll(this)">
                        <label for="bulkSelectAll">Select All</label>
                    </div>
                    <div style="flex: 1;"></div>
                    <select name="bulk_action" class="filter-select" style="min-width: 180px;">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate Selected</option>
                        <option value="deactivate">Deactivate Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                        <i class="fas fa-play"></i> Apply
                    </button>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a
                            href="?page=1<?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a
                            href="?page=<?php echo $current_page - 1; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);

                    for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>"
                            class="<?php echo $i == $current_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a
                            href="?page=<?php echo $current_page + 1; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a
                            href="?page=<?php echo $total_pages; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    // Select All functionality
    document.getElementById('selectAll').addEventListener('change', function () {
        const checkboxes = document.querySelectorAll('.employee-checkbox:not(#selectAll)');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });

    function toggleBulkSelectAll(checkbox) {
        const checkboxes = document.querySelectorAll('.employee-checkbox:not(#selectAll)');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
        });
        document.getElementById('selectAll').checked = checkbox.checked;
    }

    // Confirm delete
    function confirmDelete(employeeId, employeeName) {
        if (confirm(`Are you sure you want to deactivate ${employeeName}? This will prevent them from accessing the system.`)) {
            showNotification(`Deactivating ${employeeName}...`, 'info');

            // Send AJAX request to delete
            fetch('api/delete-employee.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: employeeId,
                    action: 'deactivate'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`Employee ${employeeName} has been deactivated`, 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message || 'Error deactivating employee', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
        }
    }

    // Confirm bulk action
    function confirmBulkAction() {
        const selected = document.querySelectorAll('.employee-checkbox:checked:not(#selectAll)');
        if (selected.length === 0) {
            showNotification('Please select at least one employee to perform bulk action.', 'warning');
            return false;
        }

        const action = document.querySelector('select[name="bulk_action"]').value;
        if (!action) {
            showNotification('Please select a bulk action.', 'warning');
            return false;
        }

        const actionText = {
            'activate': 'activate',
            'deactivate': 'deactivate',
            'delete': 'delete'
        }[action];

        return confirm(`Are you sure you want to ${actionText} ${selected.length} selected employee(s)?`);
    }

    // Export functionality
    function exportEmployees() {
        const search = '<?php echo $search_query; ?>';
        const department = '<?php echo $department_filter; ?>';
        const status = '<?php echo $status_filter; ?>';

        let url = 'api/export-employees.php?format=csv';
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (department) url += `&department=${encodeURIComponent(department)}`;
        if (status) url += `&status=${encodeURIComponent(status)}`;

        showNotification('Preparing export...', 'info');
        window.location.href = url;
    }

    // Show notification
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.custom-notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
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

    // Add CSS for animations
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

    // Auto-submit search on enter
    document.querySelector('.search-box input').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });

    // Make stat cards clickable
    document.querySelectorAll('.stat-card').forEach(card => {
        card.style.cursor = 'pointer';
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>