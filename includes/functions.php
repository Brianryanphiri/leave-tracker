<?php
// includes/functions.php - FULLY UPDATED VERSION

// ============================================
// LOAD DATABASE CONFIGURATION
// ============================================

// Check if database.php exists and load it
//if (file_exists(__DIR__ . '/../config/database.php')) {
//    require_once __DIR__ . '/../config/database.php';
//} else {
//    die("Database configuration file not found!");
//}

// ============================================
// ALIAS FUNCTIONS (using existing ones without redeclaring)
// ============================================

// Create aliases for functions that might have naming conflicts
if (!function_exists('getDBConnection')) {
    function getDBConnection()
    {
        return getPDOConnection(); // Use your existing function from database.php
    }
}

// ============================================
// NEW EMAIL FUNCTIONS - UPDATED VERSION
// ============================================

/**
 * Send email using template with enhanced features
 */
function sendLeaveEmail($templateKey, $toEmail, $variables = [])
{
    // Get email template
    $template = fetchOne("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1", [$templateKey]);

    if (!$template) {
        error_log("Email template '$templateKey' not found");
        return false;
    }

    // Parse variables from JSON string or comma-separated
    $availableVariables = [];
    if (!empty($template['variables'])) {
        // Check if variables is JSON array or comma-separated
        if (strpos($template['variables'], '[') === 0) {
            $availableVariables = json_decode($template['variables'], true) ?: [];
        } else {
            $availableVariables = array_map('trim', explode(',', $template['variables']));
        }
    }

    // Replace variables in template
    $subject = $template['subject'];
    $body = $template['body'];

    foreach ($variables as $key => $value) {
        $placeholder = '{' . $key . '}';
        $subject = str_replace($placeholder, $value, $subject);
        $body = str_replace($placeholder, $value, $body);
    }

    // Handle special placeholders
    $body = handleSpecialPlaceholders($body, $variables);

    // Replace any remaining placeholders with empty string
    foreach ($availableVariables as $var) {
        $placeholder = '{' . $var . '}';
        if (strpos($subject, $placeholder) !== false) {
            $subject = str_replace($placeholder, '', $subject);
        }
        if (strpos($body, $placeholder) !== false) {
            $body = str_replace($placeholder, '', $body);
        }
    }

    // Send email using enhanced SMTP function
    return sendEmailViaSMTP($toEmail, $subject, $body);
}

/**
 * Handle special placeholders in email body
 */
function handleSpecialPlaceholders($body, $variables)
{
    // Handle approver notes
    $body = str_replace(
        '{approver_notes_html}',
        !empty($variables['approver_notes']) && trim($variables['approver_notes']) !== ''
        ? '<div class="notes-box" style="background: #fff7ed; border-radius: 6px; padding: 15px; margin: 15px 0; border-left: 4px solid #f59e0b;">
                <h4 style="margin-top: 0; color: #d97706;">📝 Approver Notes:</h4>
                <p><em>' . htmlspecialchars($variables['approver_notes']) . '</em></p>
               </div>'
        : '',
        $body
    );

    // Handle half day indicator
    $body = str_replace(
        '{half_day}',
        !empty($variables['half_day']) && $variables['half_day'] !== 'none'
        ? '<br><small style="color: #f59e0b;">(Half Day: ' . ucfirst(str_replace('_', ' ', $variables['half_day'])) . ')</small>'
        : '',
        $body
    );

    // Handle balance warning
    $body = str_replace(
        '{balance_warning}',
        !empty($variables['balance_warning']) && $variables['balance_warning']
        ? '<div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 10px; margin: 10px 0; color: #92400e;">
                <strong>⚠ Note:</strong> Employee has low leave balance.
               </div>'
        : '',
        $body
    );

    return $body;
}

/**
 * Enhanced email sending function with actual email delivery
 */
function sendEmailViaSMTP($to, $subject, $body)
{
    // Get email settings
    $fromEmail = getSetting('notification_from_email', 'noreply@yourcompany.com');
    $fromName = getSetting('company_name', 'Your Company');
    $replyTo = getSetting('hr_email', 'hr@yourcompany.com');

    // Headers
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 1 (Highest)',
        'X-MSMail-Priority: High',
        'Importance: High'
    ];

    // Add CC if specified in settings
    $ccEmail = getSetting('hr_cc_email');
    if ($ccEmail) {
        $headers[] = 'Cc: ' . $ccEmail;
    }

    // Add BCC for logging if specified
    $bccEmail = getSetting('email_log_bcc');
    if ($bccEmail) {
        $headers[] = 'Bcc: ' . $bccEmail;
    }

    try {
        // Send email using PHP mail()
        $result = mail($to, $subject, $body, implode("\r\n", $headers));

        // Log the email attempt
        if ($result) {
            error_log("✅ Email sent successfully to: $to | Subject: $subject");

            // Log to database if email_logs table exists
            logEmailToDatabase($to, $subject, 'leave_approval', 'sent');

            return true;
        } else {
            error_log("❌ Failed to send email to: $to | Subject: $subject");

            // Log failure to database
            logEmailToDatabase($to, $subject, 'leave_approval', 'failed', 'PHP mail() function returned false');

            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Email sending error: " . $e->getMessage());
        logEmailToDatabase($to, $subject, 'leave_approval', 'failed', $e->getMessage());
        return false;
    }
}

/**
 * Log email to database
 */
function logEmailToDatabase($to, $subject, $type, $status, $error = null)
{
    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            // Check if email_logs table exists
            $tableExists = $pdo->query("SHOW TABLES LIKE 'email_logs'")->rowCount() > 0;

            if ($tableExists) {
                $stmt = $pdo->prepare("
                    INSERT INTO email_logs 
                    (recipient_email, subject, type, status, error_message, sent_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$to, $subject, $type, $status, $error]);
                return true;
            }
        }
    } catch (Exception $e) {
        error_log("Failed to log email to database: " . $e->getMessage());
    }
    return false;
}

/**
 * Send leave approval email with all details
 */
function sendLeaveApprovalEmail($leave, $approverNotes, $approver)
{
    try {
        // Get company settings
        $companyName = getSetting('company_name', 'Your Company');
        $hrEmail = getSetting('hr_email', 'hr@company.com');

        // Calculate total days if not set
        if (empty($leave['total_days'])) {
            $startDate = new DateTime($leave['start_date']);
            $endDate = new DateTime($leave['end_date']);
            $interval = $startDate->diff($endDate);
            $totalDays = $interval->days + 1;

            // Adjust for half day
            if (!empty($leave['half_day']) && $leave['half_day'] !== 'none') {
                $totalDays -= 0.5;
            }
            $leave['total_days'] = $totalDays;
        }

        // Check leave balance
        $balanceWarning = false;
        if (!empty($leave['remaining_balance_days'])) {
            $balanceWarning = $leave['remaining_balance_days'] < $leave['total_days'];
        }

        // Prepare email variables
        $variables = [
            'employee_name' => $leave['employee_name'] ?? 'Employee',
            'leave_type' => $leave['leave_type_name'] ?? 'Leave',
            'start_date' => date('F j, Y', strtotime($leave['start_date'])),
            'end_date' => date('F j, Y', strtotime($leave['end_date'])),
            'total_days' => $leave['total_days'] ?? 0,
            'approved_date' => date('F j, Y'),
            'approver_name' => $approver['full_name'] ?? 'Approver',
            'approver_email' => $approver['email'] ?? 'approver@company.com',
            'company_name' => $companyName,
            'approver_notes' => !empty($approverNotes) ? trim($approverNotes) : '',
            'reason' => !empty($leave['reason']) ? htmlspecialchars($leave['reason']) : 'Not specified',
            'department' => $leave['department'] ?? 'Not specified',
            'position' => $leave['position'] ?? 'Not specified',
            'half_day' => $leave['half_day'] ?? 'none',
            'balance_warning' => $balanceWarning,
            'notification_date' => date('F j, Y, g:i A'),
            'leave_id' => $leave['id'] ?? 'N/A',
            'approval_time' => date('g:i A')
        ];

        // Send email to employee
        $emailResult = sendLeaveEmail('leave_approved', $leave['employee_email'], $variables);

        // Also send to HR if configured
        if (getSetting('hr_notification_enabled', 'true') === 'true') {
            // Add HR-specific variables
            $hrVariables = $variables;
            $hrVariables['action_items'] = '1. Update employee\'s leave balance records\n2. Process payroll adjustments if needed\n3. Update department calendar';

            sendLeaveEmail('leave_approved_hr', $hrEmail, $hrVariables);
        }

        // Log activity
        logActivity('email_sent', [
            'type' => 'leave_approval',
            'recipient' => $leave['employee_email'],
            'leave_id' => $leave['id'],
            'success' => $emailResult
        ], $approver['id'] ?? null);

        return $emailResult;

    } catch (Exception $e) {
        error_log("❌ Error in sendLeaveApprovalEmail: " . $e->getMessage());
        return false;
    }
}

// ============================================
// LEAVE MANAGEMENT FUNCTIONS (Existing - Keep as is)
// ============================================

// Create user from Google Form data
function createUserFromForm($formData)
{
    $defaultPolicy = fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'default_leave_policy'");

    $userData = [
        'email' => $formData['email'],
        'full_name' => $formData['full_name'] ?? 'Unknown User',
        'password' => password_hash(uniqid(), PASSWORD_DEFAULT), // Random password
        'role' => 'employee',
        'status' => 'active',
        'leave_policy_id' => $defaultPolicy['setting_value'] ?? 1,
        'google_forms_email' => $formData['email'],
        'email_notifications' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $userId = insertData('users', $userData);

    if ($userId) {
        // Create leave balances for this user
        createDefaultLeaveBalances($userId, $userData['leave_policy_id']);

        // Log the creation using logUserActivity from auth.php
        if (function_exists('logUserActivity')) {
            logUserActivity('user_created', 'users', $userId, null, json_encode($userData));
        }
    }

    return $userId;
}

// Create default leave balances for new user
function createDefaultLeaveBalances($userId, $policyId)
{
    $policy = fetchOne("SELECT * FROM leave_policies WHERE id = ?", [$policyId]);

    if (!$policy)
        return false;

    // Get all active leave types
    $leaveTypes = fetchAll("SELECT * FROM leave_types WHERE is_active = 1");

    foreach ($leaveTypes as $type) {
        // Determine days based on policy and leave type
        $totalDays = getLeaveDaysForPolicy($type['name'], $policy);

        $balanceData = [
            'user_id' => $userId,
            'leave_type_id' => $type['id'],
            'year' => date('Y'),
            'total_days' => $totalDays,
            'used_days' => 0,
            'remaining_days' => $totalDays,
            'carried_over' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];

        insertData('leave_balances', $balanceData);
    }

    return true;
}

// Determine leave days based on policy
function getLeaveDaysForPolicy($leaveTypeName, $policy)
{
    $defaults = [
        'Annual Leave' => $policy['total_days_per_year'] ?? 21,
        'Sick Leave' => 14,
        'Emergency Leave' => 5,
        'Personal Leave' => 7,
        'Maternity Leave' => 90,
        'Paternity Leave' => 14,
        'Study Leave' => 30,
        'Bereavement Leave' => 7
    ];

    return $defaults[$leaveTypeName] ?? 0;
}

// Get setting value
function getSetting($key, $default = null)
{
    $result = fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $result['setting_value'] ?? $default;
}

// Calculate total leave days including half days
function calculateTotalLeaveDays($startDate, $endDate, $halfDay = 'none')
{
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = $start->diff($end);
    $totalDays = $interval->days + 1;

    // Adjust for half day
    if ($halfDay !== 'none') {
        $totalDays -= 0.5;
    }

    return $totalDays;
}

// Get employee leave balance
function getEmployeeLeaveBalance($userId, $leaveTypeId, $year = null)
{
    if ($year === null) {
        $year = date('Y');
    }

    $sql = "SELECT * FROM leave_balances 
            WHERE user_id = ? AND leave_type_id = ? AND year = ?";

    return fetchOne($sql, [$userId, $leaveTypeId, $year]);
}

// Check if employee is on leave on specific date
function isEmployeeOnLeave($userId, $date = null)
{
    if ($date === null) {
        $date = date('Y-m-d');
    }

    $sql = "SELECT COUNT(*) as count FROM leaves 
            WHERE user_id = ? AND status = 'approved' 
            AND ? BETWEEN start_date AND end_date";

    $result = fetchOne($sql, [$userId, $date]);
    return $result['count'] > 0;
}

// Get upcoming leaves for employee
function getUpcomingLeaves($userId, $limit = 5)
{
    $sql = "SELECT l.*, lt.name as leave_type_name, lt.color 
            FROM leaves l
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.user_id = ? AND l.status = 'approved' 
            AND l.start_date >= CURDATE()
            ORDER BY l.start_date ASC
            LIMIT ?";

    return fetchAll($sql, [$userId, $limit]);
}

// Format date for display
function formatDateForDisplay($date, $format = 'M j, Y')
{
    if (empty($date))
        return '';
    return date($format, strtotime($date));
}

// Get dashboard statistics - COMPATIBLE VERSION
function getDashboardStatistics()
{
    $stats = [];
    $pdo = getPDOConnection(); // Use your existing function

    if (!$pdo)
        return $stats;

    try {
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

        // Half day leaves today
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM leaves WHERE status = 'approved' AND ? BETWEEN start_date AND end_date AND half_day != 'none'");
        $stmt->execute([$today]);
        $stats['half_day_today'] = $stmt->fetch()['count'];

        // Pending Google Forms submissions
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM google_form_submissions WHERE processed = 0");
        $stats['pending_form_submissions'] = $stmt->fetch()['count'];

    } catch (PDOException $e) {
        error_log("Error getting dashboard stats: " . $e->getMessage());
    }

    return $stats;
}

// Validate leave request dates
function validateLeaveDates($startDate, $endDate, $leaveTypeId = null)
{
    $errors = [];

    // Basic validation
    if (empty($startDate) || empty($endDate)) {
        $errors[] = "Start date and end date are required";
        return $errors;
    }

    $start = strtotime($startDate);
    $end = strtotime($endDate);
    $today = strtotime(date('Y-m-d'));

    if ($start < $today) {
        $errors[] = "Start date cannot be in the past";
    }

    if ($end < $start) {
        $errors[] = "End date cannot be before start date";
    }

    // Check against leave type rules if provided
    if ($leaveTypeId) {
        $leaveType = fetchOne("SELECT * FROM leave_types WHERE id = ?", [$leaveTypeId]);
        if ($leaveType) {
            $daysNotice = floor(($start - $today) / (60 * 60 * 24));
            if ($daysNotice < $leaveType['min_notice_days']) {
                $errors[] = "Minimum notice period for this leave type is {$leaveType['min_notice_days']} days";
            }

            $totalDays = (($end - $start) / (60 * 60 * 24)) + 1;
            if ($leaveType['max_consecutive_days'] > 0 && $totalDays > $leaveType['max_consecutive_days']) {
                $errors[] = "Maximum consecutive days for this leave type is {$leaveType['max_consecutive_days']}";
            }
        }
    }

    return $errors;
}

// Get color for status
function getStatusColor($status)
{
    $colors = [
        'pending' => '#F59E0B', // Orange
        'approved' => '#10B981', // Green
        'rejected' => '#EF4444', // Red
        'cancelled' => '#6B7280' // Gray
    ];

    if (isset($colors[$status])) {
        return $colors[$status];
    }
    return '#6B7280';
}

// Get badge HTML for status - FIXED VERSION (PHP 7.4+ compatible)
function getStatusBadge($status)
{
    $colors = [
        'pending' => ['bg' => 'rgba(245, 158, 11, 0.1)', 'text' => '#F59E0B', 'icon' => 'fa-clock'],
        'approved' => ['bg' => 'rgba(16, 185, 129, 0.1)', 'text' => '#10B981', 'icon' => 'fa-check-circle'],
        'rejected' => ['bg' => 'rgba(239, 68, 68, 0.1)', 'text' => '#EF4444', 'icon' => 'fa-times-circle'],
        'cancelled' => ['bg' => 'rgba(107, 114, 128, 0.1)', 'text' => '#6B7280', 'icon' => 'fa-ban']
    ];

    if (isset($colors[$status])) {
        $config = $colors[$status];
    } else {
        $config = $colors['pending'];
    }

    return "<span style='background: {$config['bg']}; color: {$config['text']}; padding: 4px 12px; border-radius: 20px; font-size: 0.85em; display: inline-flex; align-items: center; gap: 6px;'>
            <i class='fas {$config['icon']}'></i>" . ucfirst($status) . "
            </span>";
}

// Generate random password
function generateRandomPassword($length = 12)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Check if email exists in system
function emailExists($email)
{
    $sql = "SELECT COUNT(*) as count FROM users WHERE email = ? OR google_forms_email = ?";
    $result = fetchOne($sql, [$email, $email]);
    return $result['count'] > 0;
}

// Get user by email
function getUserByEmail($email)
{
    $sql = "SELECT * FROM users WHERE email = ? OR google_forms_email = ?";
    return fetchOne($sql, [$email, $email]);
}

// Get all leave types as options for dropdown
function getLeaveTypeOptions($selectedId = null)
{
    $leaveTypes = fetchAll("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
    $options = '';

    foreach ($leaveTypes as $type) {
        $selected = ($selectedId == $type['id']) ? 'selected' : '';
        $options .= "<option value='{$type['id']}' {$selected} style='border-left: 4px solid {$type['color']}; padding-left: 10px;'>
                    {$type['name']}
                    </option>";
    }

    return $options;
}

// Get all active users as options for dropdown
function getUserOptions($selectedId = null)
{
    $users = fetchAll("SELECT id, full_name, email FROM users WHERE status = 'active' ORDER BY full_name");
    $options = '';

    foreach ($users as $user) {
        $selected = ($selectedId == $user['id']) ? 'selected' : '';
        $options .= "<option value='{$user['id']}' {$selected}>
                    {$user['full_name']} ({$user['email']})
                    </option>";
    }

    return $options;
}

// Sanitize input data
function sanitizeInput($data)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
        return $data;
    }

    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Redirect with message
function redirectWithMessage($url, $type, $message)
{
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
    header("Location: $url");
    exit();
}

// Display flash message if exists - FIXED VERSION
function displayFlashMessage()
{
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $message['type'];
        $text = $message['message'];

        $colors = [
            'success' => '#10B981',
            'error' => '#EF4444',
            'warning' => '#F59E0B',
            'info' => '#3B82F6'
        ];

        if (isset($colors[$type])) {
            $color = $colors[$type];
        } else {
            $color = $colors['info'];
        }

        // Determine icon based on type
        if ($type === 'success') {
            $icon = 'fa-check-circle';
        } elseif ($type === 'error') {
            $icon = 'fa-exclamation-circle';
        } elseif ($type === 'warning') {
            $icon = 'fa-exclamation-triangle';
        } else {
            $icon = 'fa-info-circle';
        }

        echo "<div class='flash-message' style='
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: $color;
            color: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
        '>
            <i class='fas $icon'></i>
            <span>$text</span>
        </div>";

        // Add CSS animation
        echo "<style>
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .flash-message {
                animation: slideIn 0.3s ease-out;
            }
            
            .flash-message.fade-out {
                animation: slideOut 0.3s ease-out;
            }
        </style>";

        echo "<script>
            setTimeout(function() {
                const msg = document.querySelector('.flash-message');
                if (msg) {
                    msg.classList.add('fade-out');
                    setTimeout(() => msg.remove(), 300);
                }
            }, 5000);
        </script>";

        unset($_SESSION['flash_message']);
    }
}

// Get current URL with query parameters
function currentUrl($withQuery = true)
{
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
        "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    if (!$withQuery) {
        $url = strtok($url, '?');
    }

    return $url;
}

// Check if user is logged in and has required role
function requireRole($requiredRole)
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    $userRole = $_SESSION['user_role'] ?? 'employee';

    // Define role hierarchy
    $roleHierarchy = ['employee' => 1, 'admin' => 2, 'ceo' => 3];

    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;

    if ($userLevel < $requiredLevel) {
        header('Location: unauthorized.php');
        exit();
    }
}

// Format file size
function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// Get current year
function getCurrentYear()
{
    return date('Y');
}

// Get fiscal year
function getFiscalYear($date = null)
{
    if ($date === null) {
        $date = date('Y-m-d');
    }

    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));

    // Example: Fiscal year starts April 1st
    if ($month < 4) {
        $year--;
    }

    return $year;
}

// Get employee initial for avatar
function getEmployeeInitial($name)
{
    if (empty($name))
        return '?';

    $words = explode(' ', trim($name));
    $initial = strtoupper(substr($words[0], 0, 1));

    // Add last name initial if exists
    if (count($words) > 1) {
        $initial .= strtoupper(substr($words[count($words) - 1], 0, 1));
    }

    return $initial;
}

// Get leave request source badge
function getRequestSourceBadge($source)
{
    if ($source === 'google_forms') {
        return "<span style='background: rgba(66, 133, 244, 0.1); color: #4285F4; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; display: inline-flex; align-items: center; gap: 6px;'>
                <i class='fab fa-google'></i> Google Forms
                </span>";
    } else {
        return "<span style='background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; display: inline-flex; align-items: center; gap: 6px;'>
                <i class='fas fa-desktop'></i> Dashboard
                </span>";
    }
}

// Get half day indicator - FIXED VERSION
function getHalfDayIndicator($halfDay)
{
    if ($halfDay === 'none')
        return '';

    if ($halfDay === 'morning') {
        $text = 'Morning';
        $color = '#F59E0B';
    } else {
        $text = 'Afternoon';
        $color = '#8B5CF6';
    }

    return "<span style='color: $color; font-size: 0.85em; display: inline-flex; align-items: center; gap: 4px; margin-left: 8px;'>
            <i class='fas fa-clock'></i>½ ($text)
            </span>";
}

// Calculate remaining leave days after approval
function calculateRemainingLeaveDays($userId, $leaveTypeId, $daysTaken, $year = null)
{
    if ($year === null) {
        $year = date('Y');
    }

    // Get current balance
    $balance = getEmployeeLeaveBalance($userId, $leaveTypeId, $year);

    if (!$balance) {
        return 0;
    }

    $newRemaining = $balance['remaining_days'] - $daysTaken;

    // Update the balance
    $pdo = getPDOConnection();
    if ($pdo) {
        $stmt = $pdo->prepare("
            UPDATE leave_balances 
            SET used_days = used_days + ?, 
                remaining_days = remaining_days - ?
            WHERE user_id = ? AND leave_type_id = ? AND year = ?
        ");

        $stmt->execute([$daysTaken, $daysTaken, $userId, $leaveTypeId, $year]);
    }

    return max(0, $newRemaining);
}

// Validate Google Forms submission
function validateGoogleFormSubmission($formData)
{
    $errors = [];

    // Required fields
    $requiredFields = ['email', 'leave_type', 'start_date', 'end_date'];
    foreach ($requiredFields as $field) {
        if (empty($formData[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }

    // Email validation
    if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }

    // Date validation
    if (!empty($formData['start_date']) && !empty($formData['end_date'])) {
        $start = strtotime($formData['start_date']);
        $end = strtotime($formData['end_date']);

        if ($start === false || $end === false) {
            $errors[] = "Invalid date format";
        } elseif ($end < $start) {
            $errors[] = "End date cannot be before start date";
        }
    }

    // Leave type validation
    if (!empty($formData['leave_type'])) {
        $leaveType = fetchOne("SELECT id FROM leave_types WHERE name = ?", [$formData['leave_type']]);
        if (!$leaveType) {
            $errors[] = "Invalid leave type";
        }
    }

    return $errors;
}

// Get form submission status badge - FIXED VERSION
function getFormSubmissionStatusBadge($processed, $result)
{
    if (!$processed) {
        return "<span style='background: rgba(245, 158, 11, 0.1); color: #F59E0B; padding: 4px 10px; border-radius: 20px; font-size: 0.8em;'>
                <i class='fas fa-clock'></i> Pending
                </span>";
    }

    if ($result === 'success') {
        return "<span style='background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 4px 10px; border-radius: 20px; font-size: 0.8em;'>
                <i class='fas fa-check-circle'></i> Processed
                </span>";
    } else {
        return "<span style='background: rgba(239, 68, 68, 0.1); color: #EF4444; padding: 4px 10px; border-radius: 20px; font-size: 0.8em;'>
                <i class='fas fa-times-circle'></i> Failed
                </span>";
    }
}

// Get time ago format
function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// Alternative to nested ternary using if-else
function safeTernary($condition, $trueValue, $falseValue, $defaultValue = null)
{
    if ($condition) {
        return $trueValue;
    } elseif ($falseValue !== null) {
        return $falseValue;
    } else {
        return $defaultValue;
    }
}

// Safe array access with fallback
function safeArrayAccess($array, $key, $default = null)
{
    if (is_array($array) && isset($array[$key])) {
        return $array[$key];
    }
    return $default;
}

// Enhanced status check with fallback
function getEnhancedStatus($status, $customStatuses = [])
{
    $defaultStatuses = [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled'
    ];

    if (isset($customStatuses[$status])) {
        return $customStatuses[$status];
    } elseif (isset($defaultStatuses[$status])) {
        return $defaultStatuses[$status];
    } else {
        return 'Unknown';
    }
}

// Safe date comparison
function compareDates($date1, $date2, $operator = '>')
{
    $timestamp1 = strtotime($date1);
    $timestamp2 = strtotime($date2);

    if ($timestamp1 === false || $timestamp2 === false) {
        return false;
    }

    switch ($operator) {
        case '>':
            return $timestamp1 > $timestamp2;
        case '>=':
            return $timestamp1 >= $timestamp2;
        case '<':
            return $timestamp1 < $timestamp2;
        case '<=':
            return $timestamp1 <= $timestamp2;
        case '==':
            return $timestamp1 == $timestamp2;
        default:
            return false;
    }
}

// Get notification icon based on type
function getNotificationIcon($type)
{
    $icons = [
        'leave_approved' => 'fa-check-circle',
        'leave_rejected' => 'fa-times-circle',
        'leave_pending' => 'fa-clock',
        'leave_cancelled' => 'fa-ban',
        'new_leave_request' => 'fa-calendar-plus',
        'balance_low' => 'fa-exclamation-triangle',
        'new_user' => 'fa-user-plus',
        'system_alert' => 'fa-bell'
    ];

    if (isset($icons[$type])) {
        return $icons[$type];
    }
    return 'fa-bell';
}

// Format currency
function formatCurrency($amount, $currency = 'USD')
{
    $formats = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£'
    ];

    $symbol = isset($formats[$currency]) ? $formats[$currency] : $currency;
    return $symbol . number_format($amount, 2);
}

// Get month name
function getMonthName($monthNumber)
{
    $months = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December'
    ];

    if (isset($months[$monthNumber])) {
        return $months[$monthNumber];
    }
    return 'Unknown';
}

// ============================================
// NEW ADDITIONAL FUNCTIONS - UPDATED
// ============================================

/**
 * Get user by ID
 */
function getUserById($userId)
{
    return fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
}

/**
 * Check if user has permission for action
 */
function hasPermission($userId, $permission)
{
    $user = getUserById($userId);
    if (!$user)
        return false;

    // Permission logic based on role
    $permissions = [
        'employee' => ['view_own_leaves', 'apply_leave'],
        'admin' => ['view_all_leaves', 'approve_leaves', 'manage_users', 'view_reports'],
        'ceo' => ['view_all_leaves', 'approve_leaves', 'manage_users', 'view_reports', 'system_settings']
    ];

    return isset($permissions[$user['role']]) && in_array($permission, $permissions[$user['role']]);
}

/**
 * Get user's full name by ID
 */
function getUserFullName($userId)
{
    $user = getUserById($userId);
    return $user ? $user['full_name'] : 'Unknown User';
}

/**
 * Log activity to database
 */
function logActivity($action, $details = null, $userId = null)
{
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    }

    $data = [
        'user_id' => $userId,
        'action' => $action,
        'details' => is_array($details) ? json_encode($details) : $details,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Check if activity_logs table exists before inserting
    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0;
            if ($tableExists) {
                return insertData('activity_logs', $data);
            }
        }
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }

    return false;
}

/**
 * Get recent activities
 */
function getRecentActivities($limit = 10)
{
    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0;
            if ($tableExists) {
                $sql = "SELECT al.*, u.full_name 
                        FROM activity_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        ORDER BY al.created_at DESC
                        LIMIT ?";
                return fetchAll($sql, [$limit]);
            }
        }
    } catch (Exception $e) {
        error_log("Failed to get recent activities: " . $e->getMessage());
    }
    return [];
}

/**
 * Check if database is connected
 */
function isDBConnected()
{
    $pdo = getPDOConnection();
    return $pdo !== false;
}

/**
 * Get database tables
 */
function getDatabaseTables()
{
    $result = fetchAll("SHOW TABLES");
    $tables = [];
    foreach ($result as $row) {
        $tables[] = array_values($row)[0];
    }
    return $tables;
}

/**
 * Get leave by ID with all details
 */
function getLeaveById($leaveId)
{
    $sql = "SELECT l.*, 
                   u.full_name as employee_name,
                   u.email as employee_email,
                   u.department,
                   u.position,
                   lt.name as leave_type_name,
                   lt.color,
                   a.full_name as approver_name,
                   a.email as approver_email
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE l.id = ?";

    return fetchOne($sql, [$leaveId]);
}

/**
 * Get all pending leaves
 */
function getPendingLeaves($limit = null)
{
    $sql = "SELECT l.*, 
                   u.full_name as employee_name,
                   u.email as employee_email,
                   u.department,
                   lt.name as leave_type_name,
                   lt.color,
                   DATEDIFF(l.end_date, l.start_date) + 1 as total_days
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.status = 'pending'
            ORDER BY l.created_at ASC";

    if ($limit) {
        $sql .= " LIMIT " . intval($limit);
    }

    return fetchAll($sql);
}

/**
 * Get approved leaves for date range
 */
function getApprovedLeaves($startDate, $endDate)
{
    $sql = "SELECT l.*, 
                   u.full_name as employee_name,
                   u.email as employee_email,
                   u.department,
                   lt.name as leave_type_name,
                   lt.color,
                   DATEDIFF(l.end_date, l.start_date) + 1 as total_days,
                   a.full_name as approver_name
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE l.status = 'approved'
            AND l.start_date <= ? 
            AND l.end_date >= ?
            ORDER BY l.start_date ASC";

    return fetchAll($sql, [$endDate, $startDate]);
}

/**
 * Create activity_logs table if not exists
 */
function createActivityLogsTable()
{
    $sql = "CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) DEFAULT NULL,
        `action` VARCHAR(100) NOT NULL,
        `details` TEXT,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `user_agent` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `action` (`action`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            $pdo->exec($sql);
            return true;
        }
    } catch (Exception $e) {
        error_log("Failed to create activity_logs table: " . $e->getMessage());
    }
    return false;
}

/**
 * Create email_logs table if not exists
 */
function createEmailLogsTable()
{
    $sql = "CREATE TABLE IF NOT EXISTS `email_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `recipient_email` VARCHAR(100) NOT NULL,
        `subject` VARCHAR(255) NOT NULL,
        `type` VARCHAR(50) NOT NULL,
        `status` VARCHAR(20) NOT NULL,
        `error_message` TEXT,
        `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `recipient_email` (`recipient_email`),
        KEY `type` (`type`),
        KEY `sent_at` (`sent_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            $pdo->exec($sql);
            return true;
        }
    } catch (Exception $e) {
        error_log("Failed to create email_logs table: " . $e->getMessage());
    }
    return false;
}

// ============================================
// INITIALIZATION FUNCTIONS
// ============================================

/**
 * Initialize database tables if needed
 */
function initializeSystemTables()
{
    createActivityLogsTable();
    createEmailLogsTable();
    return true;
}

// Initialize tables on include (optional)
// initializeSystemTables();

?>