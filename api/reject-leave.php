<?php
// api/reject-leave.php - SIMPLIFIED VERSION
ob_start();
header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ONLY respond to test for GET requests explicitly
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['test'])) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Reject API is working (GET test)',
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

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data received'
    ]);
    exit;
}

$leaveId = isset($data['id']) ? intval($data['id']) : 0;
$reason = isset($data['reason']) ? trim($data['reason']) : '';

if ($leaveId <= 0) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Invalid leave ID provided'
    ]);
    exit;
}

if (empty($reason)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Rejection reason is required'
    ]);
    exit;
}

// Get database connection
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/functions.php';

// Try to include email functions if exists
$email_functions_file = ROOT_PATH . '/includes/email-functions.php';
if (file_exists($email_functions_file)) {
    require_once $email_functions_file;
}

try {
    $pdo = getPDOConnection();
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Get leave details
    $stmt = $pdo->prepare("
        SELECT l.*, u.full_name, u.email, lt.name as leave_type
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        WHERE l.id = ? AND l.status = 'pending'
        FOR UPDATE
    ");

    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        throw new Exception('Leave request not found or already processed');
    }

    // Update leave status
    $updateStmt = $pdo->prepare("
        UPDATE leaves 
        SET status = 'rejected',
            approved_by = ?,
            approved_at = NOW(),
            approver_notes = ?
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$current_user_id, $reason, $leaveId]);

    if (!$result || $updateStmt->rowCount() === 0) {
        throw new Exception('Failed to update leave status');
    }

    // Commit transaction
    $pdo->commit();

    // Send email if function exists
    $emailSent = false;
    if (function_exists('sendLeaveRejectionEmailPHPMailer') && !empty($leave['email'])) {
        $emailData = [
            'employee_name' => $leave['full_name'],
            'employee_email' => $leave['email'],
            'leave_type' => $leave['leave_type'],
            'start_date' => $leave['start_date'],
            'end_date' => $leave['end_date']
        ];
        $emailSent = sendLeaveRejectionEmailPHPMailer($emailData, $reason);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Leave request rejected successfully',
        'leave_id' => $leaveId,
        'email_sent' => $emailSent
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>