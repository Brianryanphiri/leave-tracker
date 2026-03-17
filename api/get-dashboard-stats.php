<?php
// api/get-dashboard-stats.php
header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle test request
if (isset($_GET['test'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Stats API is working',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please login first'
    ]);
    exit;
}

// Get database connection
define('ROOT_PATH', dirname(__DIR__));

// Include files with error checking
$config_file = ROOT_PATH . '/config/database.php';
$functions_file = ROOT_PATH . '/includes/functions.php';

if (!file_exists($config_file)) {
    echo json_encode([
        'success' => false,
        'message' => 'Database config file not found',
        'path' => $config_file
    ]);
    exit;
}

if (!file_exists($functions_file)) {
    echo json_encode([
        'success' => false,
        'message' => 'Functions file not found',
        'path' => $functions_file
    ]);
    exit;
}

require_once $config_file;
require_once $functions_file;

try {
    $pdo = getPDOConnection();
    if (!$pdo) {
        throw new Exception('Could not connect to database');
    }

    $stats = [];
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');

    // Get total active users
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // Get pending leaves
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
    $stats['pending_leaves'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // Get approved leaves this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM leaves 
        WHERE status = 'approved' 
        AND DATE_FORMAT(approved_at, '%Y-%m') = ?
    ");
    $stmt->execute([$currentMonth]);
    $stats['approved_this_month'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // Get on leave today
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT l.user_id) as count 
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        WHERE l.status = 'approved'
        AND ? BETWEEN l.start_date AND l.end_date
        AND u.status = 'active'
    ");
    $stmt->execute([$today]);
    $stats['on_leave_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // Get Google Forms submissions today
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM google_form_submissions 
            WHERE DATE(created_at) = ?
        ");
        $stmt->execute([$today]);
        $stats['google_forms_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (Exception $e) {
        $stats['google_forms_today'] = 0;
    }

    // Get users with low leave balance (< 5 days)
    try {
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT user_id) as count 
            FROM leave_balances 
            WHERE remaining_days < 5 
            AND year = YEAR(CURRENT_DATE())
        ");
        $stats['low_balance_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (Exception $e) {
        $stats['low_balance_users'] = 0;
    }

    // Get database name from config
    $db_name = defined('DB_NAME') ? DB_NAME : 'N/A';

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'db_name' => $db_name,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Error in get-dashboard-stats.php: " . $e->getMessage());

    // Return default stats on error
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_users' => 0,
            'pending_leaves' => 0,
            'approved_this_month' => 0,
            'on_leave_today' => 0,
            'google_forms_today' => 0,
            'low_balance_users' => 0
        ],
        'db_name' => 'Error',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>