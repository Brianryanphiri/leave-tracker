<?php
// includes/mailer-config.php

/**
 * PHPMailer Configuration for LOTA Leave Management System
 * Using Gmail SMTP with App Password authentication
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if PHPMailer files exist
$phpmailer_path = __DIR__ . '/../PHPMailer/src/PHPMailer.php';
if (!file_exists($phpmailer_path)) {
    error_log("PHPMailer not found at: $phpmailer_path");
    die("Email system configuration error. Please contact administrator.");
}

// Include PHPMailer classes
require_once $phpmailer_path;
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email using PHPMailer with Gmail SMTP
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $htmlContent HTML email body
 * @param string $toName Recipient name (optional)
 * @param string $fromName Sender name (optional)
 * @return bool Success status
 */
function sendEmailPHPMailer($to, $subject, $htmlContent, $toName = '', $fromName = 'LOTA HR')
{
    $mail = new PHPMailer(true);

    try {
        // SMTP Configuration for Gmail
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'chisomoimfa@gmail.com';
        $mail->Password = 'pkeoyuewtjlfmnks'; // Your 16-character App Password (NO SPACES!)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Optional: Enable for debugging (0 = off, 1 = client messages, 2 = client and server messages)
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Uncomment for debugging

        // Timeout settings
        $mail->Timeout = 30;

        // Character set
        $mail->CharSet = 'UTF-8';

        // Sender information
        $mail->setFrom('chisomoimfa@gmail.com', $fromName);
        $mail->addReplyTo('chisomoimfa@gmail.com', $fromName);

        // Recipient
        if (!empty($toName)) {
            $mail->addAddress($to, $toName);
        } else {
            $mail->addAddress($to);
        }

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;

        // Create plain text version for non-HTML email clients
        $plainText = strip_tags($htmlContent);
        $plainText = preg_replace('/\s+/', ' ', $plainText); // Remove extra whitespace
        $mail->AltBody = $plainText;

        // Send email
        $result = $mail->send();

        if ($result) {
            error_log("[EMAIL SUCCESS] Sent to: $to | Subject: $subject");
            // Log to database if needed
            logEmailToDatabase($to, $subject, 'leave_email', 'sent');
        }

        return $result;

    } catch (Exception $e) {
        // Log error with details but don't expose to users
        $errorDetails = "PHPMailer Error for $to: " . $mail->ErrorInfo;
        error_log("[EMAIL ERROR] " . $errorDetails);

        // Log to database
        logEmailToDatabase($to, $subject, 'leave_email', 'failed', $mail->ErrorInfo);

        return false;
    }
}

/**
 * Log email to database
 */


/**
 * Quick test function to verify email configuration
 */
function testEmailConnection()
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'chisomoimfa@gmail.com';
        $mail->Password = 'pkeoyuewtjlfmnks';

        // Test connection
        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return ['status' => 'success', 'message' => 'Connected to Gmail SMTP successfully'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }

    return ['status' => 'error', 'message' => 'Failed to connect'];
}
?>