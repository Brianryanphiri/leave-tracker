<?php
// api/approve-leave.php - UPDATED VERSION (Fixed for new email functions)
ob_start();
header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle test request
if (isset($_GET['test'])) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'API is working',
        'session' => isset($_SESSION['user_id']) ? 'Logged in as user ' . $_SESSION['user_id'] : 'Not logged in',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Please login first'
    ]);
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// If no JSON data, try POST
if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    $data = $_POST;
}

// Validate input
if (empty($data)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'No input data received'
    ]);
    exit;
}

$leaveId = isset($data['id']) ? intval($data['id']) : 0;
$notes = isset($data['notes']) ? trim($data['notes']) : '';

if ($leaveId <= 0) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Invalid leave ID provided'
    ]);
    exit;
}

// Get database connection
define('ROOT_PATH', dirname(__DIR__));

// Include files with error checking
$config_file = ROOT_PATH . '/config/database.php';
$functions_file = ROOT_PATH . '/includes/functions.php';

if (!file_exists($config_file)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Database config file not found'
    ]);
    exit;
}

if (!file_exists($functions_file)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Functions file not found'
    ]);
    exit;
}

require_once $config_file;
require_once $functions_file;
require_once ROOT_PATH . '/includes/email-functions.php';

// Get database connection
try {
    $pdo = getPDOConnection();
    if (!$pdo) {
        throw new Exception('Could not connect to database');
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}

try {
    // Begin transaction
    $pdo->beginTransaction();

    // Get leave details with approver info
    $stmt = $pdo->prepare("
        SELECT l.*, 
               u.full_name as employee_name, 
               u.email as employee_email,
               u.department as employee_department,
               u.position as employee_position,
               lt.name as leave_type_name, 
               lt.color,
               a.full_name as approver_name, 
               a.email as approver_email,
               a.department as approver_department,
               a.position as approver_position
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN users a ON a.id = ?
        WHERE l.id = ? AND l.status = 'pending'
        FOR UPDATE
    ");

    if (!$stmt->execute([$current_user_id, $leaveId])) {
        throw new Exception('Failed to fetch leave details');
    }

    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Leave request not found or already processed'
        ]);
        exit;
    }

    // Calculate total days
    $start = new DateTime($leave['start_date']);
    $end = new DateTime($leave['end_date']);
    $totalDays = $end->diff($start)->days + 1;

    // Adjust for half day if exists
    if (isset($leave['half_day']) && $leave['half_day'] !== 'none') {
        $totalDays -= 0.5;
        $halfDayText = " (Half day: " . ucfirst($leave['half_day']) . ")";
    } else {
        $halfDayText = "";
    }

    // Update leave status
    $updateStmt = $pdo->prepare("
        UPDATE leaves 
        SET status = 'approved',
            approved_by = ?,
            approved_at = NOW(),
            updated_at = NOW(),
            approver_notes = ?,
            total_days = ?
        WHERE id = ? AND status = 'pending'
    ");

    $result = $updateStmt->execute([$current_user_id, $notes, $totalDays, $leaveId]);

    if (!$result || $updateStmt->rowCount() === 0) {
        throw new Exception('Failed to update leave status');
    }

    // Update leave balance (if table exists)
    $year = date('Y');

    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'leave_balances'")->fetch();
        if ($tableCheck) {
            $balanceStmt = $pdo->prepare("
                SELECT id FROM leave_balances 
                WHERE user_id = ? AND leave_type_id = ? AND year = ?
            ");

            if ($balanceStmt->execute([$leave['user_id'], $leave['leave_type_id'], $year])) {
                $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                if ($balance) {
                    $updateBalanceStmt = $pdo->prepare("
                        UPDATE leave_balances 
                        SET used_days = used_days + ?,
                            remaining_days = remaining_days - ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateBalanceStmt->execute([$totalDays, $totalDays, $balance['id']]);
                } else {
                    // Create new balance record
                    $leaveTypeStmt = $pdo->prepare("SELECT default_days FROM leave_types WHERE id = ?");
                    $leaveTypeStmt->execute([$leave['leave_type_id']]);
                    $leaveType = $leaveTypeStmt->fetch(PDO::FETCH_ASSOC);

                    $defaultDays = $leaveType['default_days'] ?? 0;
                    $remainingDays = $defaultDays - $totalDays;

                    $createBalanceStmt = $pdo->prepare("
                        INSERT INTO leave_balances 
                        (user_id, leave_type_id, year, total_days, used_days, remaining_days, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $createBalanceStmt->execute([
                        $leave['user_id'],
                        $leave['leave_type_id'],
                        $year,
                        $defaultDays,
                        $totalDays,
                        $remainingDays
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        // Silent fail for balance update - not critical
        error_log("Leave balance update failed: " . $e->getMessage());
    }

    // Commit transaction
    $pdo->commit();

    // SEND EMAIL NOTIFICATION - Using new PHPMailer function
    $emailSent = false;

    // Prepare approver info
    $approverInfo = [
        'full_name' => $leave['approver_name'] ?? 'Approver',
        'email' => $leave['approver_email'] ?? '',
        'department' => $leave['approver_department'] ?? '',
        'position' => $leave['approver_position'] ?? ''
    ];

    // Prepare leave data for email function
    $emailLeaveData = [
        'employee_name' => $leave['employee_name'],
        'employee_email' => $leave['employee_email'],
        'employee_department' => $leave['employee_department'],
        'employee_position' => $leave['employee_position'],
        'leave_type' => $leave['leave_type_name'],
        'start_date' => $leave['start_date'],
        'end_date' => $leave['end_date'],
        'total_days' => $totalDays,
        'reason' => $leave['reason'] ?? '',
        'id' => $leave['id']
    ];

    // Try to send email using the new function
    if (function_exists('sendLeaveApprovalEmailPHPMailer')) {
        // Use the new PHPMailer function
        $emailSent = sendLeaveApprovalEmailPHPMailer($emailLeaveData, $leave['approver_name'], $notes);
        error_log("Email sent using sendLeaveApprovalEmailPHPMailer: " . ($emailSent ? 'YES' : 'NO'));
    } elseif (function_exists('sendLeaveApprovalEmail')) {
        // Fallback to original function (if compatibility wrapper is active)
        $emailSent = sendLeaveApprovalEmail($emailLeaveData, $leave['approver_name'], $notes);
        error_log("Email sent using sendLeaveApprovalEmail (compatibility): " . ($emailSent ? 'YES' : 'NO'));
    } else {
        error_log("Warning: No email function found - tried sendLeaveApprovalEmailPHPMailer and sendLeaveApprovalEmail");
    }

    // Clean output and send success response
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Leave request approved successfully',
        'leave_id' => $leaveId,
        'email_sent' => $emailSent,
        'email_function_used' => function_exists('sendLeaveApprovalEmailPHPMailer') ? 'PHPMailer' : (function_exists('sendLeaveApprovalEmail') ? 'Compatibility' : 'None'),
        'data' => [
            'employee_name' => $leave['employee_name'],
            'employee_email' => $leave['employee_email'],
            'leave_type' => $leave['leave_type_name'],
            'start_date' => $leave['start_date'],
            'end_date' => $leave['end_date'],
            'dates_formatted' => date('M j, Y', strtotime($leave['start_date'])) . ' to ' . date('M j, Y', strtotime($leave['end_date'])),
            'total_days' => $totalDays,
            'approver_name' => $leave['approver_name'],
            'approver_notes' => $notes,
            'approved_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error in approve-leave.php: " . $e->getMessage());

    // Clean output and send error
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>