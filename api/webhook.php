<?php
// api/webhook.php - Google Forms Webhook Endpoint
header('Content-Type: application/json');

// Include database and config
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php'; // We'll create this

// Set timezone
date_default_timezone_set('Africa/Blantyre'); // Change to your timezone

// Get webhook secret from settings or environment
function getWebhookSecret()
{
    $result = fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'webhook_secret_key'");
    return $result['setting_value'] ?? 'YOUR_SECRET_KEY_HERE'; // ← Match Google Script
}

// Validate webhook request
function validateWebhook()
{
    // Get secret from POST data (Google Script sends as 'secret')
    $providedSecret = $_POST['secret'] ?? '';
    $expectedSecret = getWebhookSecret();

    if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing webhook secret'
        ]);
        exit;
    }

    // Validate required data - UPDATED FIELD NAMES
    $requiredFields = ['email_address', 'leave_type', 'start_date', 'end_date'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Missing required field: $field"
            ]);
            exit;
        }
    }

    return true;
}

// Get employee by email
function getEmployeeByEmail($email)
{
    $sql = "SELECT u.*, lb.remaining_days, lt.name as leave_type_name, lt.id as leave_type_id
            FROM users u
            LEFT JOIN leave_balances lb ON u.id = lb.user_id
            LEFT JOIN leave_types lt ON lb.leave_type_id = lt.id
            WHERE u.email = ? AND u.status = 'active'
            LIMIT 1";

    return fetchOne($sql, [$email]);
}

// Validate leave request
function validateLeaveRequest($employee, $leaveType, $startDate, $endDate, $totalDays)
{
    $errors = [];

    // Check if employee exists
    if (!$employee) {
        $errors[] = "Employee not found or inactive";
        return $errors;
    }

    // Check if leave type exists
    $leaveTypeInfo = fetchOne("SELECT * FROM leave_types WHERE name = ?", [$leaveType]);
    if (!$leaveTypeInfo) {
        $errors[] = "Invalid leave type";
        return $errors;
    }

    // Validate dates
    if (strtotime($startDate) > strtotime($endDate)) {
        $errors[] = "Start date cannot be after end date";
    }

    if (strtotime($startDate) < strtotime(date('Y-m-d'))) {
        $errors[] = "Start date cannot be in the past";
    }

    // Check minimum notice period
    $daysNotice = (strtotime($startDate) - time()) / (60 * 60 * 24);
    if ($daysNotice < $leaveTypeInfo['min_notice_days']) {
        $errors[] = "Minimum notice period for {$leaveType} is {$leaveTypeInfo['min_notice_days']} days";
    }

    // Check leave balance
    $balance = fetchOne("
        SELECT remaining_days 
        FROM leave_balances 
        WHERE user_id = ? AND leave_type_id = ? AND year = YEAR(CURDATE())
    ", [$employee['id'], $leaveTypeInfo['id']]);

    if ($balance && $balance['remaining_days'] < $totalDays) {
        $errors[] = "Insufficient leave balance. Available: {$balance['remaining_days']} days, Requested: {$totalDays} days";
    }

    // Check for overlapping leaves
    $overlap = fetchOne("
        SELECT COUNT(*) as count 
        FROM leaves 
        WHERE user_id = ? 
        AND status IN ('pending', 'approved')
        AND (
            (start_date BETWEEN ? AND ?)
            OR (end_date BETWEEN ? AND ?)
            OR (? BETWEEN start_date AND end_date)
            OR (? BETWEEN start_date AND end_date)
        )
    ", [
        $employee['id'],
        $startDate,
        $endDate,
        $startDate,
        $endDate,
        $startDate,
        $endDate
    ]);

    if ($overlap && $overlap['count'] > 0) {
        $errors[] = "You already have a leave request for these dates";
    }

    return $errors;
}

// Save Google Form submission
function saveFormSubmission($formId, $submissionId, $email, $formData)
{
    $data = [
        'form_id' => $formId,
        'submission_id' => $submissionId,
        'employee_email' => $email,
        'form_data' => json_encode($formData),
        'processed' => false,
        'processing_result' => 'pending'
    ];

    return insertData('google_form_submissions', $data);
}

// Create leave request
function createLeaveRequest($employee, $formData, $submissionId = null)
{
    $pdo = getPDOConnection();

    // Get leave type ID
    $leaveType = fetchOne("SELECT id FROM leave_types WHERE name = ?", [$formData['leave_type']]);

    if (!$leaveType) {
        return ['success' => false, 'message' => 'Invalid leave type'];
    }

    // Calculate total days
    $start = new DateTime($formData['start_date']);
    $end = new DateTime($formData['end_date']);
    $interval = $start->diff($end);
    $totalDays = $interval->days + 1;

    // Adjust for half day
    if (isset($formData['half_day']) && $formData['half_day'] != 'none' && $formData['half_day'] != 'full_day') {
        $totalDays -= 0.5;
    }

    // Prepare leave data
    $leaveData = [
        'user_id' => $employee['id'],
        'leave_type_id' => $leaveType['id'],
        'start_date' => $formData['start_date'],
        'end_date' => $formData['end_date'],
        'reason' => $formData['reason_for_leave'] ?? ($formData['reason'] ?? null), // Handle both field names
        'status' => 'pending',
        'total_days' => $totalDays,
        'source' => 'google_forms',
        'form_submission_id' => $submissionId,
        'applied_date' => date('Y-m-d'),
        'half_day' => $formData['half_day'] ?? 'full_day'
    ];

    try {
        $leaveId = insertData('leaves', $leaveData);

        if ($leaveId) {
            // Update form submission
            updateData(
                'google_form_submissions',
                ['processed' => true, 'processing_result' => 'success', 'processed_at' => date('Y-m-d H:i:s')],
                'submission_id = ?',
                [$submissionId]
            );

            return ['success' => true, 'leave_id' => $leaveId, 'data' => $leaveData];
        }

        return ['success' => false, 'message' => 'Failed to create leave request'];

    } catch (Exception $e) {
        error_log("Error creating leave: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Send email notification
function sendLeaveConfirmationEmail($employee, $leaveData)
{
    // Get email template
    $template = fetchOne("SELECT * FROM email_templates WHERE template_key = 'leave_received' AND is_active = 1");

    if (!$template) {
        error_log("Email template 'leave_received' not found");
        return false;
    }

    // Get leave balance
    $balance = fetchOne("
        SELECT remaining_days 
        FROM leave_balances 
        WHERE user_id = ? AND leave_type_id = ? AND year = YEAR(CURDATE())
    ", [$employee['id'], $leaveData['leave_type_id']]);

    // Prepare variables
    $variables = [
        '{employee_name}' => $employee['full_name'],
        '{leave_type}' => fetchOne("SELECT name FROM leave_types WHERE id = ?", [$leaveData['leave_type_id']])['name'],
        '{start_date}' => date('F j, Y', strtotime($leaveData['start_date'])),
        '{end_date}' => date('F j, Y', strtotime($leaveData['end_date'])),
        '{total_days}' => $leaveData['total_days'],
        '{balance_remaining}' => $balance['remaining_days'] ?? 'N/A'
    ];

    // Replace variables in template
    $subject = str_replace(array_keys($variables), array_values($variables), $template['subject']);
    $body = str_replace(array_keys($variables), array_values($variables), $template['body']);

    // Send email
    return sendEmail($employee['email'], $subject, $body);
}

// Simple email function
function sendEmail($to, $subject, $body)
{
    // Basic email headers for testing
    $headers = "From: leave-system@yourcompany.com\r\n";
    $headers .= "Reply-To: no-reply@yourcompany.com\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    // For testing, log and send basic email
    error_log("Sending email to: $to");
    error_log("Subject: $subject");

    // In production, use proper SMTP
    return mail($to, $subject, $body, $headers);
}

// Log webhook activity
function logWebhookActivity($action, $details)
{
    $data = [
        'user_id' => null,
        'action' => $action,
        'table_name' => 'google_form_submissions',
        'record_id' => null,
        'old_value' => null,
        'new_value' => json_encode($details),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Webhook'
    ];

    return insertData('audit_logs', $data);
}

// Create user from form data if auto-create is enabled
function createUserFromForm($formData)
{
    $autoCreate = fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'auto_create_users'");

    if (!$autoCreate || $autoCreate['setting_value'] != 'true') {
        return false;
    }

    // Extract name parts
    $fullName = $formData['full_name'] ?? '';
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';

    // Generate username from email
    $email = $formData['email_address'];
    $username = strtok($email, '@');

    $userData = [
        'username' => $username,
        'email' => $email,
        'full_name' => $fullName,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'role' => 'employee' // Default role
    ];

    return insertData('users', $userData);
}

// MAIN WEBHOOK HANDLER
try {
    // Validate request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Log raw POST data for debugging
    error_log("Webhook received: " . json_encode($_POST));

    validateWebhook();

    // Get form data
    $formData = $_POST;
    $submissionId = $formData['submission_id'] ?? uniqid('gform_', true);
    $formId = $formData['form_id'] ?? 1;

    logWebhookActivity('webhook_received', [
        'data' => $formData,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    // Save raw submission
    $saveResult = saveFormSubmission($formId, $submissionId, $formData['email_address'], $formData);

    // Get employee - use email_address field
    $employee = getEmployeeByEmail($formData['email_address']);

    if (!$employee) {
        // Auto-create user if enabled
        $userId = createUserFromForm($formData);
        if ($userId) {
            $employee = getEmployeeByEmail($formData['email_address']);
        }

        if (!$employee) {
            updateData(
                'google_form_submissions',
                ['processed' => true, 'processing_result' => 'failed', 'error_message' => 'Employee not found'],
                'submission_id = ?',
                [$submissionId]
            );

            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Employee not found in system. Please register first.'
            ]);
            exit;
        }
    }

    // Validate leave request
    $startDate = $formData['start_date'];
    $endDate = $formData['end_date'];
    $totalDays = (strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24) + 1;

    $validationErrors = validateLeaveRequest($employee, $formData['leave_type'], $startDate, $endDate, $totalDays);

    if (!empty($validationErrors)) {
        updateData(
            'google_form_submissions',
            ['processed' => true, 'processing_result' => 'failed', 'error_message' => implode(', ', $validationErrors)],
            'submission_id = ?',
            [$submissionId]
        );

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validationErrors
        ]);
        exit;
    }

    // Create leave request
    $result = createLeaveRequest($employee, $formData, $submissionId);

    if ($result['success']) {
        // Send confirmation email
        $emailSent = sendLeaveConfirmationEmail($employee, $result['data']);

        // Update leave with email status
        updateData(
            'leaves',
            ['notification_sent' => $emailSent ? 1 : 0],
            'id = ?',
            [$result['leave_id']]
        );

        logWebhookActivity('leave_created', [
            'leave_id' => $result['leave_id'],
            'employee_id' => $employee['id'],
            'email_sent' => $emailSent
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Leave request created successfully',
            'leave_id' => $result['leave_id'],
            'email_sent' => $emailSent
        ]);

    } else {
        updateData(
            'google_form_submissions',
            ['processed' => true, 'processing_result' => 'failed', 'error_message' => $result['message']],
            'submission_id = ?',
            [$submissionId]
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create leave request',
            'error' => $result['message']
        ]);
    }

} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}