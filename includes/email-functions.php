<?php
// includes/email-functions.php

/**
 * LOTA Leave Management System - Email Functions
 * Updated with PHPMailer integration for Gmail SMTP
 * FIXED: Removed duplicate function declarations
 */

// Include PHPMailer config
require_once __DIR__ . '/mailer-config.php';

/**
 * Send leave approval email with HTML formatting using PHPMailer
 * Note: This is a separate implementation from functions.php
 */
function sendLeaveApprovalEmailPHPMailer($leave, $approverName, $additionalNotes = '')
{
    try {
        if (empty($leave['employee_email'])) {
            error_log("[Leave Approval] No email address for: " . $leave['employee_name']);
            return false;
        }

        $companyName = "LOTA";
        $startDate = date('F j, Y', strtotime($leave['start_date']));
        $endDate = date('F j, Y', strtotime($leave['end_date']));
        $approvedDate = date('F j, Y');

        $subject = "Leave Request Approved - " . $companyName;

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request Approved</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #10B981; color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .status-badge { display: inline-block; background: #10B981; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; margin-bottom: 20px; }
        .details-box { background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; width: 150px; color: #065f46; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Leave Request Approved</h1>
            <p>Your request has been approved</p>
        </div>
        <div class="content">
            <div class="status-badge">✓ APPROVED</div>
            
            <p>Dear <strong>{$leave['employee_name']}</strong>,</p>
            <p>We are pleased to inform you that your leave request has been approved.</p>
            
            <div class="details-box">
                <div class="detail-row">
                    <span class="detail-label">Leave Type:</span>
                    <span>{$leave['leave_type']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Date:</span>
                    <span>{$startDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">End Date:</span>
                    <span>{$endDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Days:</span>
                    <span>{$leave['total_days']} day(s)</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Approved By:</span>
                    <span>{$approverName}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Approval Date:</span>
                    <span>{$approvedDate}</span>
                </div>
            </div>

HTML;

        if (!empty($additionalNotes)) {
            $htmlContent .= <<<HTML
            <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0;">
                <h4 style="margin-top: 0; color: #92400e;">Additional Notes:</h4>
                <p>{$additionalNotes}</p>
            </div>
HTML;
        }

        $htmlContent .= <<<HTML
            <p>Please ensure all pending work is completed before your leave begins.</p>
            <p>Best regards,<br><strong>{$companyName} HR Department</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated message from the {$companyName} Leave Management System.</p>
            <p>© {$companyName} • {$approvedDate}</p>
        </div>
    </div>
</body>
</html>
HTML;

        // Send using PHPMailer
        $success = sendEmailPHPMailer(
            $leave['employee_email'],
            $subject,
            $htmlContent,
            $leave['employee_name'],
            'LOTA HR Department'
        );

        if ($success) {
            error_log("[SUCCESS] Approval email sent to: " . $leave['employee_email']);
        } else {
            error_log("[ERROR] Failed to send approval email to: " . $leave['employee_email']);
        }

        return $success;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Leave approval email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send leave rejection email with HTML formatting using PHPMailer
 * Note: This is a separate implementation from functions.php
 */
function sendLeaveRejectionEmailPHPMailer($leave, $reason)
{
    try {
        if (empty($leave['employee_email'])) {
            error_log("[Leave Rejection] No email address for: " . $leave['employee_name']);
            return false;
        }

        $companyName = "LOTA";
        $startDate = date('F j, Y', strtotime($leave['start_date']));
        $endDate = date('F j, Y', strtotime($leave['end_date']));
        $rejectedDate = date('F j, Y');

        $subject = "Leave Request Update - " . $companyName;

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request Status</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #EF4444; color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .status-badge { display: inline-block; background: #EF4444; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; margin-bottom: 20px; }
        .details-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; width: 150px; color: #991b1b; }
        .reason-box { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Leave Request Update</h1>
            <p>Your request has been reviewed</p>
        </div>
        <div class="content">
            <div class="status-badge">✗ NOT APPROVED</div>
            
            <p>Dear <strong>{$leave['employee_name']}</strong>,</p>
            <p>Your leave request has been reviewed and could not be approved at this time.</p>
            
            <div class="details-box">
                <div class="detail-row">
                    <span class="detail-label">Leave Type:</span>
                    <span>{$leave['leave_type']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Date:</span>
                    <span>{$startDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">End Date:</span>
                    <span>{$endDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Days:</span>
                    <span>{$leave['total_days']} day(s)</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Review Date:</span>
                    <span>{$rejectedDate}</span>
                </div>
            </div>

            <div class="reason-box">
                <h4 style="margin-top: 0; color: #92400e;">Reason for Rejection:</h4>
                <p>{$reason}</p>
            </div>
            
            <p>If you have any questions or wish to discuss this further, please contact the HR department.</p>
            <p>You may submit a new request with different dates or additional information.</p>
            
            <p>Best regards,<br><strong>{$companyName} HR Department</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated message from the {$companyName} Leave Management System.</p>
            <p>© {$companyName} • {$rejectedDate}</p>
        </div>
    </div>
</body>
</html>
HTML;

        // Send using PHPMailer
        $success = sendEmailPHPMailer(
            $leave['employee_email'],
            $subject,
            $htmlContent,
            $leave['employee_name'],
            'LOTA HR Department'
        );

        if ($success) {
            error_log("[SUCCESS] Rejection email sent to: " . $leave['employee_email']);
        } else {
            error_log("[ERROR] Failed to send rejection email to: " . $leave['employee_email']);
        }

        return $success;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Leave rejection email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send leave request notification to manager using PHPMailer
 */
function sendLeaveRequestNotificationPHPMailer($leave, $managerEmail, $managerName)
{
    try {
        if (empty($managerEmail)) {
            error_log("[Manager Notification] No manager email for leave ID: " . $leave['id']);
            return false;
        }

        $companyName = "LOTA";
        $startDate = date('F j, Y', strtotime($leave['start_date']));
        $endDate = date('F j, Y', strtotime($leave['end_date']));
        $requestDate = date('F j, Y', strtotime($leave['created_at']));

        $subject = "New Leave Request Requires Approval - " . $companyName;

        // Create action link (adjust URL as needed)
        $approveLink = "http://localhost/leave-tracker/approve-leave.php?id=" . $leave['id'];
        $rejectLink = "http://localhost/leave-tracker/reject-leave.php?id=" . $leave['id'];

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Leave Request</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #3B82F6; color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .details-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; width: 150px; color: #1e40af; }
        .action-buttons { margin: 30px 0; text-align: center; }
        .btn { display: inline-block; padding: 12px 24px; margin: 0 10px; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .btn-approve { background: #10B981; color: white; }
        .btn-reject { background: #EF4444; color: white; }
        .btn-view { background: #6B7280; color: white; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Leave Request</h1>
            <p>Requires your attention and approval</p>
        </div>
        <div class="content">
            <p>Dear <strong>{$managerName}</strong>,</p>
            <p>You have a new leave request that requires your review and approval.</p>
            
            <div class="details-box">
                <div class="detail-row">
                    <span class="detail-label">Employee:</span>
                    <span>{$leave['employee_name']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Employee ID:</span>
                    <span>{$leave['employee_id']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Department:</span>
                    <span>{$leave['department']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Leave Type:</span>
                    <span>{$leave['leave_type']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Date:</span>
                    <span>{$startDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">End Date:</span>
                    <span>{$endDate}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Days:</span>
                    <span>{$leave['total_days']} day(s)</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Reason:</span>
                    <span>{$leave['reason']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Request Date:</span>
                    <span>{$requestDate}</span>
                </div>
            </div>

            <div class="action-buttons">
                <a href="{$approveLink}" class="btn btn-approve">✓ Approve Request</a>
                <a href="{$rejectLink}" class="btn btn-reject">✗ Reject Request</a>
            </div>
            
            <p>Please review this request and take appropriate action within 48 hours.</p>
            <p>Best regards,<br><strong>{$companyName} Leave Management System</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated notification from {$companyName} Leave Management System.</p>
            <p>© {$companyName} • {$requestDate}</p>
        </div>
    </div>
</body>
</html>
HTML;

        // Send using PHPMailer
        $success = sendEmailPHPMailer(
            $managerEmail,
            $subject,
            $htmlContent,
            $managerName,
            'LOTA Leave Management System'
        );

        if ($success) {
            error_log("[SUCCESS] Manager notification sent to: " . $managerEmail);
        } else {
            error_log("[ERROR] Failed to send manager notification to: " . $managerEmail);
        }

        return $success;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Manager notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send generic notification email using PHPMailer
 */
function sendNotificationEmailPHPMailer($to, $subject, $message, $type = 'info', $recipientName = '')
{
    try {
        if (empty($to)) {
            error_log("[Notification] No recipient email provided");
            return false;
        }

        $companyName = "LOTA";

        $colors = [
            'info' => '#3B82F6',
            'success' => '#10B981',
            'warning' => '#F59E0B',
            'error' => '#EF4444',
            'default' => '#6B7280'
        ];

        $color = $colors[$type] ?? $colors['default'];

        $greeting = !empty($recipientName) ? "Dear <strong>{$recipientName}</strong>," : "Hello,";

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: {$color}; color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .message-box { background: #f9fafb; border-left: 4px solid {$color}; padding: 20px; margin: 20px 0; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$subject}</h1>
        </div>
        <div class="content">
            <p>{$greeting}</p>
            
            <div class="message-box">
                {$message}
            </div>
            
            <p>If you have any questions, please contact the HR department.</p>
            <p>Best regards,<br><strong>{$companyName} Team</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated email from {$companyName} Management System.</p>
            <p>© {$companyName} • All rights reserved</p>
        </div>
    </div>
</body>
</html>
HTML;

        $success = sendEmailPHPMailer($to, $subject, $htmlContent, $recipientName);

        if ($success) {
            error_log("[SUCCESS] Notification email sent to: " . $to);
        } else {
            error_log("[ERROR] Failed to send notification email to: " . $to);
        }

        return $success;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Notification email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset email using PHPMailer
 */
function sendPasswordResetEmailPHPMailer($email, $name, $resetToken)
{
    try {
        $companyName = "LOTA";
        $resetLink = "http://localhost/leave-tracker/reset-password.php?token=" . urlencode($resetToken);
        $expiryTime = date('F j, Y, g:i a', strtotime('+1 hour'));

        $subject = "Password Reset Request - " . $companyName;

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #8B5CF6; color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .reset-box { background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 25px; margin: 20px 0; text-align: center; }
        .reset-btn { display: inline-block; background: #8B5CF6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 15px 0; }
        .token-box { background: #f9fafb; padding: 15px; border-radius: 5px; font-family: monospace; margin: 15px 0; }
        .warning-box { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; }
        .footer { background: #f9fafb; padding: 20px; text-align: center; color: #6b7280; font-size: 14px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Password Reset Request</h1>
        </div>
        <div class="content">
            <p>Dear <strong>{$name}</strong>,</p>
            <p>We received a request to reset your password for the {$companyName} Leave Management System.</p>
            
            <div class="reset-box">
                <p>Click the button below to reset your password:</p>
                <a href="{$resetLink}" class="reset-btn">Reset Your Password</a>
                <p style="font-size: 12px; color: #6b7280; margin-top: 10px;">
                    Or copy this link:<br>
                    <span style="word-break: break-all;">{$resetLink}</span>
                </p>
            </div>
            
            <div class="warning-box">
                <h4 style="margin-top: 0; color: #92400e;">Important Security Information:</h4>
                <p>• This password reset link will expire on: <strong>{$expiryTime}</strong></p>
                <p>• If you didn't request this password reset, please ignore this email</p>
                <p>• For security reasons, do not share this link with anyone</p>
            </div>
            
            <p>If you're having trouble clicking the button, copy and paste the URL above into your web browser.</p>
            <p>Best regards,<br><strong>{$companyName} IT Department</strong></p>
        </div>
        <div class="footer">
            <p>This is an automated message from {$companyName} Security System.</p>
            <p>© {$companyName} • Security Notification</p>
        </div>
    </div>
</body>
</html>
HTML;

        $success = sendEmailPHPMailer(
            $email,
            $subject,
            $htmlContent,
            $name,
            'LOTA IT Security'
        );

        if ($success) {
            error_log("[SUCCESS] Password reset email sent to: " . $email);
        } else {
            error_log("[ERROR] Failed to send password reset email to: " . $email);
        }

        return $success;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Password reset email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Test email functionality using PHPMailer
 */
function testEmailSystemPHPMailer($recipientEmail, $recipientName = 'Test User')
{
    try {
        $testData = [
            'employee_name' => $recipientName,
            'employee_email' => $recipientEmail,
            'employee_id' => 'TEST001',
            'department' => 'Testing',
            'leave_type' => 'Annual Leave',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+3 days')),
            'total_days' => 4,
            'reason' => 'System testing and verification',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $results = [
            'approval' => sendLeaveApprovalEmailPHPMailer($testData, 'Test Approver', 'This is a test approval email.'),
            'rejection' => sendLeaveRejectionEmailPHPMailer($testData, 'Test rejection reason for system verification.'),
            'notification' => sendNotificationEmailPHPMailer(
                $recipientEmail,
                'System Test Notification',
                '<p>This is a test notification email to verify the email system is working correctly.</p><p>All email functions should now be operational.</p>',
                'success',
                $recipientName
            )
        ];

        return $results;

    } catch (Exception $e) {
        error_log("[EXCEPTION] Email system test error: " . $e->getMessage());
        return false;
    }
}

// Compatibility wrapper functions (optional - use if you want to maintain backward compatibility)
if (!function_exists('sendLeaveApprovalEmail')) {
    /**
     * Compatibility wrapper for the original function name
     * This allows existing code to work without changes
     */
    function sendLeaveApprovalEmail($leave, $approverName, $additionalNotes = '')
    {
        return sendLeaveApprovalEmailPHPMailer($leave, $approverName, $additionalNotes);
    }
}

if (!function_exists('sendLeaveRejectionEmail')) {
    /**
     * Compatibility wrapper for the original function name
     */
    function sendLeaveRejectionEmail($leave, $reason)
    {
        return sendLeaveRejectionEmailPHPMailer($leave, $reason);
    }
}
?>