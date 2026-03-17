<?php
// leaves.php - All Leaves Overview
$page_title = "All Leaves";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getPDOConnection();
$leaves = [];
$filters = [
    'status' => $_GET['status'] ?? 'all',
    'department' => $_GET['department'] ?? 'all',
    'leave_type' => $_GET['leave_type'] ?? 'all',
    'date_range' => $_GET['date_range'] ?? 'this_month',
    'search' => $_GET['search'] ?? '',
    'sort' => $_GET['sort'] ?? 'newest',
    'page' => isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1
];

$departments = [];
$leave_types = [];
$status_counts = [];
$total_leaves = 0;
$per_page = 20;
$total_pages = 1;

// Date ranges
$date_ranges = [
    'today' => ['name' => 'Today', 'start' => date('Y-m-d'), 'end' => date('Y-m-d')],
    'this_week' => ['name' => 'This Week', 'start' => date('Y-m-d', strtotime('monday this week')), 'end' => date('Y-m-d', strtotime('sunday this week'))],
    'this_month' => ['name' => 'This Month', 'start' => date('Y-m-01'), 'end' => date('Y-m-t')],
    'last_month' => ['name' => 'Last Month', 'start' => date('Y-m-01', strtotime('last month')), 'end' => date('Y-m-t', strtotime('last month'))],
    'this_year' => ['name' => 'This Year', 'start' => date('Y-01-01'), 'end' => date('Y-12-31')],
    'custom' => ['name' => 'Custom Range', 'start' => $_GET['start_date'] ?? '', 'end' => $_GET['end_date'] ?? '']
];

// Fetch filter options and data
if ($pdo) {
    try {
        // Get departments
        $stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get leave types
        $stmt = $pdo->query("SELECT id, name, color FROM leave_types WHERE is_active = 1 ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get status counts
        $status_counts = getStatusCounts();

        // Get leaves with filters
        list($leaves, $total_leaves) = getFilteredLeaves($filters, $per_page);
        $total_pages = ceil($total_leaves / $per_page);

    } catch (PDOException $e) {
        error_log("Error fetching leaves data: " . $e->getMessage());
    }
}

function getStatusCounts() {
    global $pdo;
    
    $sql = "
        SELECT 
            status,
            COUNT(*) as count,
            SUM(total_days) as total_days
        FROM leaves
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY status
        ORDER BY FIELD(status, 'pending', 'approved', 'rejected', 'cancelled')
    ";
    
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $counts = [
        'all' => ['count' => 0, 'total_days' => 0, 'label' => 'All Leaves', 'color' => '#6B7280'],
        'pending' => ['count' => 0, 'total_days' => 0, 'label' => 'Pending', 'color' => '#F59E0B'],
        'approved' => ['count' => 0, 'total_days' => 0, 'label' => 'Approved', 'color' => '#10B981'],
        'rejected' => ['count' => 0, 'total_days' => 0, 'label' => 'Rejected', 'color' => '#EF4444'],
        'cancelled' => ['count' => 0, 'total_days' => 0, 'label' => 'Cancelled', 'color' => '#6B7280']
    ];
    
    foreach ($results as $row) {
        $status = $row['status'];
        if (isset($counts[$status])) {
            $counts[$status] = [
                'count' => $row['count'],
                'total_days' => $row['total_days'],
                'label' => ucfirst($status),
                'color' => $counts[$status]['color']
            ];
            $counts['all']['count'] += $row['count'];
            $counts['all']['total_days'] += $row['total_days'];
        }
    }
    
    return $counts;
}

function getFilteredLeaves($filters, $per_page) {
    global $pdo;
    
    $where_conditions = [];
    $params = [];
    
    // Status filter
    if ($filters['status'] !== 'all') {
        $where_conditions[] = "l.status = ?";
        $params[] = $filters['status'];
    }
    
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
    if ($filters['date_range'] !== 'custom' && isset($filters['date_range'])) {
        $range = $GLOBALS['date_ranges'][$filters['date_range']];
        if ($range['start'] && $range['end']) {
            $where_conditions[] = "l.start_date BETWEEN ? AND ?";
            $params[] = $range['start'];
            $params[] = $range['end'];
        }
    } elseif ($filters['date_range'] === 'custom' && !empty($_GET['start_date']) && !empty($_GET['end_date'])) {
        $where_conditions[] = "l.start_date BETWEEN ? AND ?";
        $params[] = $_GET['start_date'];
        $params[] = $_GET['end_date'];
    }
    
    // Search filter
    if (!empty($filters['search'])) {
        $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR l.reason LIKE ?)";
        $search_term = "%{$filters['search']}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Build WHERE clause
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        $where_clause
    ";
    
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_count = $stmt->fetchColumn();
    
    // Calculate offset for pagination
    $offset = ($filters['page'] - 1) * $per_page;
    
    // Order by
    $order_by = match($filters['sort']) {
        'oldest' => 'l.created_at ASC',
        'name' => 'u.full_name ASC',
        'department' => 'u.department ASC, u.full_name ASC',
        'start_date' => 'l.start_date ASC',
        'end_date' => 'l.end_date DESC',
        default => 'l.created_at DESC'
    };
    
    // Fetch leaves with details
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
            END as request_source,
            COALESCE(a.full_name, 'N/A') as approved_by_name
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN users a ON l.approved_by = a.id
        $where_clause
        ORDER BY $order_by
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [$leaves, $total_count];
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

    /* Status Tiles */
    .status-tiles {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .status-tile {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        text-align: center;
    }

    .status-tile:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 30px rgba(212, 160, 23, 0.15);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .status-tile.active {
        border-color: var(--color-primary);
        box-shadow: 0 4px 25px rgba(212, 160, 23, 0.2);
    }

    .status-tile::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: var(--tile-color, var(--color-border));
    }

    .status-tile .status-count {
        font-size: 2.5em;
        font-weight: 700;
        color: var(--color-text);
        line-height: 1;
        margin-bottom: 10px;
    }

    .status-tile .status-label {
        font-size: 0.95em;
        color: var(--color-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }

    .status-tile .status-days {
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

    /* Leaves Grid */
    .leaves-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }

    @media (min-width: 1400px) {
        .leaves-grid {
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        }
    }

    .leave-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
    }

    .leave-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 35px rgba(212, 160, 23, 0.15);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .leave-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: var(--card-color, var(--color-border));
    }

    .card-header {
        padding: 25px 25px 15px;
        border-bottom: 1px solid var(--color-border);
        position: relative;
    }

    .card-header .status-badge {
        position: absolute;
        top: 20px;
        right: 20px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: var(--status-bg, rgba(212, 160, 23, 0.1));
        color: var(--status-color, var(--color-primary-dark));
        border: 1px solid var(--status-border, rgba(212, 160, 23, 0.2));
    }

    .employee-info {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
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

    .employee-details h4 {
        font-size: 1.1em;
        color: var(--color-text);
        margin-bottom: 5px;
        font-weight: 600;
    }

    .employee-details p {
        color: var(--color-dark-gray);
        font-size: 0.9em;
        margin-bottom: 3px;
    }

    .leave-type {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 600;
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-primary-dark);
        border: 1px solid rgba(212, 160, 23, 0.2);
        margin-top: 10px;
    }

    .leave-type i {
        color: var(--leave-type-color, var(--color-primary));
    }

    .card-body {
        padding: 20px 25px;
    }

    .leave-dates {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding: 15px;
        background: rgba(212, 160, 23, 0.05);
        border-radius: 12px;
        border: 1px solid var(--color-border);
    }

    .date-item {
        text-align: center;
        flex: 1;
    }

    .date-label {
        font-size: 0.8em;
        color: var(--color-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .date-value {
        font-size: 1.2em;
        font-weight: 700;
        color: var(--color-text);
    }

    .date-range {
        font-size: 0.9em;
        color: var(--color-dark-gray);
        margin-top: 5px;
    }

    .leave-details {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
    }

    .detail-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9em;
        color: var(--color-dark-gray);
    }

    .detail-item i {
        color: var(--color-primary);
        width: 16px;
        text-align: center;
    }

    .days-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 20px;
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

    .card-footer {
        padding: 20px 25px;
        border-top: 1px solid var(--color-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .source-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-primary-dark);
        border: 1px solid rgba(212, 160, 23, 0.2);
    }

    .action-buttons {
        display: flex;
        gap: 10px;
    }

    .btn-action {
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 0.9em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 80px;
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

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 40px;
        flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        height: 44px;
        padding: 0 16px;
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

    /* Responsive */
    @media (max-width: 1200px) {
        .leaves-grid {
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }

        .page-title {
            font-size: 1.8em;
        }

        .status-tiles {
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

        .leaves-grid {
            grid-template-columns: 1fr;
        }

        .card-footer {
            flex-direction: column;
            align-items: stretch;
        }

        .action-buttons {
            width: 100%;
        }

        .btn-action {
            flex: 1;
        }
    }

    @media (max-width: 480px) {
        .status-tiles {
            grid-template-columns: 1fr;
        }

        .page-title {
            font-size: 1.6em;
        }

        .employee-info {
            flex-direction: column;
            text-align: center;
        }

        .leave-dates {
            flex-direction: column;
            gap: 15px;
        }

        .date-item {
            width: 100%;
        }
    }
</style>

<div class="content-area">
    

    <!-- Status Tiles -->
    <div class="status-tiles">
        <?php foreach ($status_counts as $status => $data): ?>
            <div class="status-tile <?php echo $filters['status'] === $status ? 'active' : ''; ?>"
                 onclick="window.location.href='?status=<?php echo $status; ?>'"
                 style="--tile-color: <?php echo $data['color']; ?>">
                <div class="status-count"><?php echo $data['count']; ?></div>
                <div class="status-label"><?php echo $data['label']; ?></div>
                <div class="status-days"><?php echo $data['total_days']; ?> total days</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-header">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Leaves
            </h3>
            <form method="GET" action="" class="search-box">
                <input type="text" name="search" placeholder="Search by name, email, or reason..."
                    value="<?php echo htmlspecialchars($filters['search']); ?>">
                <i class="fas fa-search"></i>
            </form>
        </div>

        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>">
            
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Date Range</label>
                    <select name="date_range" class="filter-select" onchange="toggleCustomDate(this.value)">
                        <?php foreach ($date_ranges as $key => $range): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filters['date_range'] == $key ? 'selected' : ''; ?>>
                                <?php echo $range['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
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
                    <label class="filter-label">Sort By</label>
                    <select name="sort" class="filter-select">
                        <option value="newest" <?php echo $filters['sort'] == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $filters['sort'] == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="name" <?php echo $filters['sort'] == 'name' ? 'selected' : ''; ?>>Employee Name</option>
                        <option value="department" <?php echo $filters['sort'] == 'department' ? 'selected' : ''; ?>>Department</option>
                        <option value="start_date" <?php echo $filters['sort'] == 'start_date' ? 'selected' : ''; ?>>Start Date</option>
                        <option value="end_date" <?php echo $filters['sort'] == 'end_date' ? 'selected' : ''; ?>>End Date</option>
                    </select>
                </div>
            </div>
            
            <div id="customDateFields" style="display: <?php echo $filters['date_range'] == 'custom' ? 'grid' : 'none'; ?>; margin-top: 20px;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Start Date</label>
                        <input type="date" name="start_date" class="filter-select" 
                            value="<?php echo !empty($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : date('Y-m-01'); ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">End Date</label>
                        <input type="date" name="end_date" class="filter-select" 
                            value="<?php echo !empty($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : date('Y-m-t'); ?>">
                    </div>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                    <i class="fas fa-redo"></i>
                    Reset Filters
                </button>
                <button type="button" class="btn btn-export" onclick="exportLeaves()">
                    <i class="fas fa-file-export"></i>
                    Export
                </button>
            </div>
        </form>
    </div>

    <!-- Leaves Grid -->
    <div class="leaves-grid">
        <?php if (empty($leaves)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No Leaves Found</h3>
                <p>No leaves match your current filters. Try adjusting your search criteria.</p>
                <button class="btn btn-primary" onclick="resetFilters()">
                    <i class="fas fa-redo"></i>
                    Reset Filters
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($leaves as $leave): 
                // Determine status colors
                $status_colors = [
                    'pending' => ['bg' => 'rgba(245, 158, 11, 0.1)', 'color' => '#F59E0B', 'border' => 'rgba(245, 158, 11, 0.2)'],
                    'approved' => ['bg' => 'rgba(16, 185, 129, 0.1)', 'color' => '#10B981', 'border' => 'rgba(16, 185, 129, 0.2)'],
                    'rejected' => ['bg' => 'rgba(239, 68, 68, 0.1)', 'color' => '#EF4444', 'border' => 'rgba(239, 68, 68, 0.2)'],
                    'cancelled' => ['bg' => 'rgba(107, 114, 128, 0.1)', 'color' => '#6B7280', 'border' => 'rgba(107, 114, 128, 0.2)']
                ];
                
                $status_config = $status_colors[$leave['status']] ?? $status_colors['pending'];
                $start_date = new DateTime($leave['start_date']);
                $end_date = new DateTime($leave['end_date']);
                $applied_date = new DateTime($leave['applied_date'] ?? $leave['created_at']);
            ?>
                <div class="leave-card" 
                     style="--card-color: <?php echo $status_config['color']; ?>; 
                            --status-bg: <?php echo $status_config['bg']; ?>; 
                            --status-color: <?php echo $status_config['color']; ?>; 
                            --status-border: <?php echo $status_config['border']; ?>;
                            --leave-type-color: <?php echo $leave['leave_type_color']; ?>;">
                    
                    <div class="card-header">
                        <span class="status-badge">
                            <?php echo ucfirst($leave['status']); ?>
                        </span>
                        
                        <div class="employee-info">
                            <div class="employee-avatar">
                                <?php echo strtoupper(substr($leave['full_name'], 0, 1)); ?>
                            </div>
                            <div class="employee-details">
                                <h4><?php echo htmlspecialchars($leave['full_name']); ?></h4>
                                <p><?php echo htmlspecialchars($leave['email']); ?></p>
                                <p><i class="fas fa-building"></i> <?php echo htmlspecialchars($leave['department'] ?? 'No Department'); ?></p>
                            </div>
                        </div>
                        
                        <div class="leave-type">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="leave-dates">
                            <div class="date-item">
                                <div class="date-label">From</div>
                                <div class="date-value"><?php echo $start_date->format('d'); ?></div>
                                <div class="date-range"><?php echo $start_date->format('M, Y'); ?></div>
                            </div>
                            <div class="date-item">
                                <i class="fas fa-arrow-right" style="color: var(--color-primary);"></i>
                            </div>
                            <div class="date-item">
                                <div class="date-label">To</div>
                                <div class="date-value"><?php echo $end_date->format('d'); ?></div>
                                <div class="date-range"><?php echo $end_date->format('M, Y'); ?></div>
                            </div>
                        </div>
                        
                        <div class="leave-details">
                            <div class="detail-item">
                                <i class="fas fa-calendar-day"></i>
                                <span>
                                    <?php echo $leave['total_days']; ?> days
                                    <?php if ($leave['half_day'] !== 'none'): ?>
                                        <span class="half-day-badge" title="Half Day: <?php echo ucfirst($leave['half_day']); ?>">
                                            <i class="fas fa-clock"></i>½
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-clock"></i>
                                <span>Applied: <?php echo $applied_date->format('M j, Y'); ?></span>
                            </div>
                            <?php if ($leave['status'] === 'approved' && !empty($leave['approved_by_name'])): ?>
                                <div class="detail-item">
                                    <i class="fas fa-user-check"></i>
                                    <span>Approved by: <?php echo htmlspecialchars($leave['approved_by_name']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($leave['reason'])): ?>
                            <div style="margin-top: 15px; padding: 12px; background: rgba(212, 160, 23, 0.05); border-radius: 8px; border-left: 3px solid var(--color-primary);">
                                <div style="font-size: 0.85em; color: var(--color-secondary); margin-bottom: 5px; font-weight: 600;">Reason:</div>
                                <div style="font-size: 0.9em; color: var(--color-text); line-height: 1.5;"><?php echo htmlspecialchars($leave['reason']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-footer">
                        <span class="source-badge">
                            <i class="fas <?php echo $leave['source'] === 'Google Forms' ? 'fa-google' : 'fa-desktop'; ?>"></i>
                            <?php echo $leave['request_source']; ?>
                        </span>
                        
                        <div class="action-buttons">
                            <?php if ($leave['status'] === 'pending' && ($current_user['role'] === 'admin' || $current_user['role'] === 'ceo')): ?>
                                <button class="btn-action btn-approve" onclick="approveLeave(<?php echo $leave['id']; ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-action btn-reject" onclick="rejectLeave(<?php echo $leave['id']; ?>)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            <?php else: ?>
                                <button class="btn-action btn-view" onclick="viewLeave(<?php echo $leave['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($filters['page'] > 1): ?>
                <a href="?<?php echo buildPaginationQuery(1); ?>">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?<?php echo buildPaginationQuery($filters['page'] - 1); ?>">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                <span class="disabled"><i class="fas fa-angle-left"></i></span>
            <?php endif; ?>

            <?php
            $start_page = max(1, $filters['page'] - 2);
            $end_page = min($total_pages, $filters['page'] + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo buildPaginationQuery($i); ?>" class="<?php echo $i == $filters['page'] ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($filters['page'] < $total_pages): ?>
                <a href="?<?php echo buildPaginationQuery($filters['page'] + 1); ?>">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?<?php echo buildPaginationQuery($total_pages); ?>">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-right"></i></span>
                <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Toggle custom date fields
        window.toggleCustomDate = function(value) {
            const customDateFields = document.getElementById('customDateFields');
            customDateFields.style.display = value === 'custom' ? 'grid' : 'none';
        };

        // Reset filters
        window.resetFilters = function() {
            window.location.href = 'leaves.php';
        };

        // Export leaves
        window.exportLeaves = function() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            showNotification('Preparing export...', 'info');
            
            // Trigger download
            setTimeout(() => {
                window.location.href = `api/export-leaves.php?${params.toString()}`;
            }, 1000);
        };

        // Approve leave
        window.approveLeave = function(leaveId) {
            if (confirm('Are you sure you want to approve this leave request?')) {
                showNotification('Approving leave...', 'info');
                
                fetch('api/approve-leave.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: leaveId,
                        notes: ''
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Leave approved successfully!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message || 'Error approving leave', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
        };

        // Reject leave
        window.rejectLeave = function(leaveId) {
            const reason = prompt('Please enter reason for rejection:', '');
            
            if (reason !== null && reason.trim() !== '') {
                showNotification('Rejecting leave...', 'info');
                
                fetch('api/reject-leave.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: leaveId,
                        reason: reason.trim()
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Leave rejected', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message || 'Error rejecting leave', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            } else if (reason !== null) {
                showNotification('Reason is required for rejection', 'warning');
            }
        };

        // View leave
        window.viewLeave = function(leaveId) {
            window.open(`view-leave.php?id=${leaveId}`, '_blank');
        };

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

        // Auto-submit search on enter
        document.querySelector('.search-box input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const searchValue = this.value;
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchValue);
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            }
        });
    });
</script>

<?php
function buildPaginationQuery($page) {
    global $filters;
    $params = [];
    
    if ($filters['status'] !== 'all') $params[] = 'status=' . urlencode($filters['status']);
    if ($filters['department'] !== 'all') $params[] = 'department=' . urlencode($filters['department']);
    if ($filters['leave_type'] !== 'all') $params[] = 'leave_type=' . urlencode($filters['leave_type']);
    if ($filters['date_range'] !== 'this_month') $params[] = 'date_range=' . urlencode($filters['date_range']);
    if ($filters['search']) $params[] = 'search=' . urlencode($filters['search']);
    if ($filters['sort'] !== 'newest') $params[] = 'sort=' . urlencode($filters['sort']);
    
    if ($filters['date_range'] === 'custom') {
        if (!empty($_GET['start_date'])) $params[] = 'start_date=' . urlencode($_GET['start_date']);
        if (!empty($_GET['end_date'])) $params[] = 'end_date=' . urlencode($_GET['end_date']);
    }
    
    $params[] = 'page=' . $page;
    
    return implode('&', $params);
}

require_once __DIR__ . '/includes/footer.php';
?>