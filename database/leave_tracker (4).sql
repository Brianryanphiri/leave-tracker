-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 31, 2026 at 05:25 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `leave_tracker`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 10, 'email_sent', '{\"type\":\"leave_approval\",\"recipient\":\"ryanjuniorphiri@gmail.com\",\"leave_id\":8,\"success\":false}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 11:59:43'),
(2, 10, 'email_sent', '{\"type\":\"leave_approval\",\"recipient\":\"ryanjuniorphiri@gmail.com\",\"leave_id\":8,\"success\":false}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 12:21:39');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'user_logout', 'users', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-29 04:56:02'),
(2, 10, 'user_logout', 'users', 10, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-29 08:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `type` enum('leave_approval','leave_rejection','leave_reminder','system_notification') DEFAULT 'system_notification',
  `status` enum('sent','failed','pending') DEFAULT 'sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`id`, `user_id`, `recipient_email`, `subject`, `type`, `status`, `sent_at`, `error_message`) VALUES
(1, NULL, 'ryanjuniorphiri@gmail.com', 'Leave Request Approved - Your Company', 'leave_approval', 'failed', '2026-01-30 02:59:41', 'PHP mail() function returned false'),
(2, NULL, 'hr@company.com', 'Leave Approved: Chisomo Imfa - Your Company', 'leave_approval', 'failed', '2026-01-30 02:59:43', 'PHP mail() function returned false'),
(3, NULL, 'ryanjuniorphiri@gmail.com', 'Leave Request Approved - Your Company', 'leave_approval', 'failed', '2026-01-30 03:21:37', 'PHP mail() function returned false'),
(4, NULL, 'hr@company.com', 'Leave Approved: Chisomo Imfa - Your Company', 'leave_approval', 'failed', '2026-01-30 03:21:39', 'PHP mail() function returned false');

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` int(11) NOT NULL,
  `template_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `variables` text DEFAULT NULL COMMENT 'JSON array of available variables',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_templates`
--

INSERT INTO `email_templates` (`id`, `template_key`, `name`, `subject`, `body`, `variables`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'leave_received', 'Leave Request Received', 'Your Leave Request Has Been Received', 'Dear {employee_name},\n\nWe have received your {leave_type} request from {start_date} to {end_date} ({total_days} days).\n\nCurrent balance before approval: {balance_remaining} days\n\nThis request is under review by management. You\'ll be notified once a decision is made.\n\nBest regards,\nHR Department', '[\"employee_name\", \"leave_type\", \"start_date\", \"end_date\", \"total_days\", \"balance_remaining\"]', 1, '2026-01-29 06:47:58', '2026-01-29 06:47:58'),
(2, 'leave_approved', 'Leave Approval Notification', 'Leave Request Approved - {company_name}', '<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <style>\r\n        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }\r\n        .container { max-width: 600px; margin: 0 auto; padding: 20px; }\r\n        .header { background: #10B981; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }\r\n        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }\r\n        .badge { background: #10B981; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; display: inline-block; margin: 5px 0; }\r\n        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #10B981; }\r\n        .details-row { display: flex; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }\r\n        .details-label { font-weight: bold; width: 150px; color: #4b5563; }\r\n        .details-value { flex: 1; color: #1f2937; }\r\n        .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }\r\n        .notes-box { background: #fff7ed; border-radius: 6px; padding: 15px; margin: 15px 0; border-left: 4px solid #f59e0b; }\r\n        .company-logo { font-size: 24px; font-weight: bold; color: white; }\r\n        @media (max-width: 600px) {\r\n            .container { padding: 10px; }\r\n            .details-row { flex-direction: column; }\r\n            .details-label { width: 100%; margin-bottom: 5px; }\r\n        }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"container\">\r\n        <div class=\"header\">\r\n            <div class=\"company-logo\">{company_name}</div>\r\n            <h1 style=\"margin: 10px 0 5px 0;\">✓ Leave Request Approved</h1>\r\n            <p style=\"margin: 0; opacity: 0.9;\">Your leave request has been approved</p>\r\n        </div>\r\n        \r\n        <div class=\"content\">\r\n            <p>Dear <strong>{employee_name}</strong>,</p>\r\n            \r\n            <p>Your leave request has been <span class=\"badge\">APPROVED</span> by {approver_name}.</p>\r\n            \r\n            <div class=\"details\">\r\n                <h3 style=\"margin-top: 0; color: #10B981;\">Approval Details</h3>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Leave Type:</div>\r\n                    <div class=\"details-value\"><strong>{leave_type}</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Dates:</div>\r\n                    <div class=\"details-value\">\r\n                        <strong>{start_date} to {end_date}</strong>\r\n                        {half_day}\r\n                    </div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Duration:</div>\r\n                    <div class=\"details-value\"><strong>{total_days} day(s)</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Approved By:</div>\r\n                    <div class=\"details-value\">\r\n                        <strong>{approver_name}</strong><br>\r\n                        <small>{approver_email}</small>\r\n                    </div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Approved On:</div>\r\n                    <div class=\"details-value\"><strong>{approved_date}</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Status:</div>\r\n                    <div class=\"details-value\">\r\n                        <span class=\"badge\" style=\"background: #10B981;\">✓ Approved</span>\r\n                    </div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Reason:</div>\r\n                    <div class=\"details-value\">{reason}</div>\r\n                </div>\r\n            </div>\r\n            \r\n            {approver_notes_html}\r\n            \r\n            <div class=\"notes-box\">\r\n                <h4 style=\"margin-top: 0; color: #d97706;\">📋 Important Notes:</h4>\r\n                <ul style=\"margin: 10px 0; padding-left: 20px;\">\r\n                    <li>Please ensure you complete a proper handover before proceeding on leave</li>\r\n                    <li>Your leave has been recorded in the system</li>\r\n                    <li>For any changes or cancellations, contact your manager immediately</li>\r\n                    <li>Make sure your out-of-office email is set up</li>\r\n                </ul>\r\n            </div>\r\n            \r\n            <p>You can view your leave balance and history by logging into the Leave Management System.</p>\r\n            \r\n            <p style=\"margin-top: 30px;\">\r\n                Best regards,<br>\r\n                <strong>HR Department</strong><br>\r\n                <strong>{company_name}</strong>\r\n            </p>\r\n        </div>\r\n        \r\n        <div class=\"footer\">\r\n            <p>This is an automated email from the Leave Management System.</p>\r\n            <p>{company_name} | HR Department</p>\r\n            <p style=\"margin-top: 10px; font-size: 11px; color: #9ca3af;\">\r\n                Please do not reply to this email. For inquiries, contact HR directly.\r\n            </p>\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>', 'employee_name,leave_type,start_date,end_date,total_days,approver_name,approver_email,approved_date,reason,approver_notes,half_day,company_name', 1, '2026-01-29 06:47:58', '2026-01-30 00:59:45'),
(3, 'leave_rejected', 'Leave Request Rejected', 'Update on Your Leave Request', 'Dear {employee_name},\n\nUnfortunately, your {leave_type} request from {start_date} to {end_date} was not approved.\n\nReason: {rejection_reason}\n\nYour leave balance remains: {balance_remaining} days\n\nYou may submit a new request with different dates.\n\nBest regards,\nHR Department', '[\"employee_name\", \"leave_type\", \"start_date\", \"end_date\", \"rejection_reason\", \"balance_remaining\"]', 1, '2026-01-29 06:47:58', '2026-01-29 06:47:58'),
(6, 'leave_approved_hr', 'HR Leave Approval Notification', 'Leave Approved: {employee_name} - {company_name}', '<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <style>\r\n        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }\r\n        .container { max-width: 600px; margin: 0 auto; padding: 20px; }\r\n        .header { background: #3B82F6; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }\r\n        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }\r\n        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3B82F6; }\r\n        .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }\r\n        .action-box { background: #eff6ff; border-radius: 6px; padding: 15px; margin: 15px 0; }\r\n        .details-row { display: flex; margin-bottom: 8px; }\r\n        .details-label { font-weight: bold; width: 140px; color: #4b5563; }\r\n        .details-value { flex: 1; color: #1f2937; }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"container\">\r\n        <div class=\"header\">\r\n            <h1 style=\"margin: 0;\">📋 Leave Approved - Lota Notification</h1>\r\n            <p style=\"margin: 5px 0 0 0; opacity: 0.9;\">A leave request has been approved</p>\r\n        </div>\r\n        \r\n        <div class=\"content\">\r\n            <p>Hello HR Team,</p>\r\n            \r\n            <p>A leave request has been approved and requires your attention.</p>\r\n            \r\n            <div class=\"details\">\r\n                <h3 style=\"margin-top: 0; color: #3B82F6;\">Employee Information</h3>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Employee Name:</div>\r\n                    <div class=\"details-value\"><strong>{employee_name}</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Department:</div>\r\n                    <div class=\"details-value\">{department}</div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Position:</div>\r\n                    <div class=\"details-value\">{position}</div>\r\n                </div>\r\n                \r\n                <h3 style=\"color: #3B82F6; margin-top: 20px;\">Leave Details</h3>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Leave Type:</div>\r\n                    <div class=\"details-value\"><strong>{leave_type}</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Dates:</div>\r\n                    <div class=\"details-value\">\r\n                        <strong>{start_date} to {end_date}</strong>\r\n                    </div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Duration:</div>\r\n                    <div class=\"details-value\"><strong>{total_days} day(s)</strong></div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Approved By:</div>\r\n                    <div class=\"details-value\">{approver_name}</div>\r\n                </div>\r\n                \r\n                <div class=\"details-row\">\r\n                    <div class=\"details-label\">Reason:</div>\r\n                    <div class=\"details-value\">{reason}</div>\r\n                </div>\r\n            </div>\r\n            \r\n            <div class=\"action-box\">\r\n                <h4 style=\"margin-top: 0; color: #1e40af;\">✅ Action Required:</h4>\r\n                <ul style=\"margin: 10px 0; padding-left: 20px;\">\r\n                    <li>Update employee\'s leave balance records</li>\r\n                    <li>Process payroll adjustments if needed</li>\r\n                    <li>Update department calendar</li>\r\n                    <li>Notify department head if required</li>\r\n                </ul>\r\n            </div>\r\n            \r\n            <p>This leave has been recorded in the system. Please verify the details and take necessary actions.</p>\r\n            \r\n            <p style=\"margin-top: 30px;\">\r\n                Best regards,<br>\r\n                <strong>Leave Management System</strong><br>\r\n                <em>Automated Notification</em>\r\n            </p>\r\n        </div>\r\n        \r\n        <div class=\"footer\">\r\n            <p>This is an automated notification from {company_name} Leave Management System.</p>\r\n            <p>Generated on {notification_date}</p>\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>', 'employee_name,department,position,leave_type,start_date,end_date,total_days,approver_name,reason,company_name,notification_date', 1, '2026-01-30 01:00:33', '2026-01-30 01:00:33');

-- --------------------------------------------------------

--
-- Table structure for table `google_forms`
--

CREATE TABLE `google_forms` (
  `id` int(11) NOT NULL,
  `form_name` varchar(100) NOT NULL,
  `form_url` text NOT NULL,
  `form_id` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `google_forms`
--

INSERT INTO `google_forms` (`id`, `form_name`, `form_url`, `form_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Leave Request Form', 'https://forms.google.com/your-form-url', 'leave_request_form_001', 1, '2026-01-29 00:24:38', '2026-01-29 00:24:38');

-- --------------------------------------------------------

--
-- Table structure for table `google_form_submissions`
--

CREATE TABLE `google_form_submissions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `submission_id` varchar(100) NOT NULL COMMENT 'Google Forms submission ID',
  `employee_email` varchar(100) NOT NULL,
  `form_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Raw form submission data' CHECK (json_valid(`form_data`)),
  `processed` tinyint(1) DEFAULT 0,
  `processing_result` enum('success','failed','pending') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leaves`
--

CREATE TABLE `leaves` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `total_days` int(11) DEFAULT NULL,
  `half_day` enum('morning','afternoon','none') DEFAULT 'none',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source` enum('dashboard','google_forms') DEFAULT 'dashboard',
  `form_submission_id` varchar(100) DEFAULT NULL,
  `google_form_id` int(11) DEFAULT NULL,
  `applied_date` date DEFAULT NULL,
  `notification_sent` tinyint(1) DEFAULT 0,
  `approver_notes` text DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leaves`
--

INSERT INTO `leaves` (`id`, `user_id`, `leave_type_id`, `start_date`, `end_date`, `reason`, `status`, `approved_by`, `rejected_by`, `rejection_reason`, `total_days`, `half_day`, `created_at`, `updated_at`, `source`, `form_submission_id`, `google_form_id`, `applied_date`, `notification_sent`, `approver_notes`, `approved_at`, `rejected_at`) VALUES
(7, 11, 1, '2026-01-30', '2026-02-01', '', 'approved', 10, NULL, NULL, 3, 'none', '2026-01-29 23:52:24', '2026-01-30 20:47:16', 'dashboard', NULL, NULL, '2026-01-29', 0, '', '2026-01-30 20:47:16', NULL),
(8, 11, 1, '2026-02-08', '2026-02-13', '', 'approved', 10, NULL, NULL, 6, 'none', '2026-01-30 01:27:55', '2026-01-30 20:46:56', 'dashboard', NULL, NULL, '2026-01-29', 0, '', '2026-01-30 20:46:56', NULL),
(9, 10, 8, '2026-01-31', '2026-02-01', '', 'approved', 10, NULL, NULL, 2, 'none', '2026-01-30 20:49:02', '2026-01-30 20:49:38', 'dashboard', NULL, NULL, '2026-01-30', 0, '', '2026-01-30 20:49:38', NULL);

--
-- Triggers `leaves`
--
DELIMITER $$
CREATE TRIGGER `calculate_leave_days_before_insert` BEFORE INSERT ON `leaves` FOR EACH ROW BEGIN
    SET NEW.total_days = DATEDIFF(NEW.end_date, NEW.start_date) + 1;
    SET NEW.applied_date = COALESCE(NEW.applied_date, CURDATE());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `calculate_leave_days_before_update` BEFORE UPDATE ON `leaves` FOR EACH ROW BEGIN
    SET NEW.total_days = DATEDIFF(NEW.end_date, NEW.start_date) + 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `leave_balances`
--

CREATE TABLE `leave_balances` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` year(4) NOT NULL,
  `total_days` int(11) DEFAULT 0,
  `used_days` int(11) DEFAULT 0,
  `remaining_days` int(11) DEFAULT 0,
  `carried_over` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_policies`
--

CREATE TABLE `leave_policies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `total_days_per_year` int(11) DEFAULT 21,
  `carry_over_days` int(11) DEFAULT 5,
  `min_notice_days` int(11) DEFAULT 7,
  `max_consecutive_days` int(11) DEFAULT 30,
  `allow_half_day` tinyint(1) DEFAULT 0,
  `requires_documentation` tinyint(1) DEFAULT 0,
  `approval_level` enum('auto','manager','hr','ceo') DEFAULT 'manager',
  `color` varchar(7) DEFAULT '#10B981',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_policies`
--

INSERT INTO `leave_policies` (`id`, `name`, `description`, `total_days_per_year`, `carry_over_days`, `min_notice_days`, `max_consecutive_days`, `allow_half_day`, `requires_documentation`, `approval_level`, `color`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Standard Employee', 'Standard annual leave policy for regular employees', 21, 5, 7, 30, 0, 0, 'manager', '#10B981', 1, '2026-01-29 06:46:47', '2026-01-29 06:46:47'),
(2, 'Management', 'Leave policy for management staff', 25, 7, 7, 30, 0, 0, 'manager', '#3B82F6', 1, '2026-01-29 06:46:47', '2026-01-29 06:46:47'),
(3, 'Executive', 'Leave policy for executive level', 30, 10, 3, 30, 0, 0, 'manager', '#8B5CF6', 1, '2026-01-29 06:46:47', '2026-01-29 06:46:47'),
(4, 'Probation', 'Leave policy for probationary staff', 10, 0, 14, 30, 0, 0, 'manager', '#F59E0B', 1, '2026-01-29 06:46:47', '2026-01-29 06:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `max_days` int(11) DEFAULT 0,
  `requires_approval` tinyint(1) DEFAULT 1,
  `color` varchar(7) DEFAULT '#10B981',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `name`, `description`, `max_days`, `requires_approval`, `color`, `is_active`, `created_at`) VALUES
(1, 'Annual Leave', 'Paid annual vacation leave', 21, 1, '#10B981', 1, '2026-01-29 00:24:37'),
(2, 'Sick Leave', 'Leave for health reasons', 14, 0, '#3B82F6', 1, '2026-01-29 00:24:37'),
(3, 'Emergency Leave', 'Leave for urgent personal matters', 5, 1, '#EF4444', 1, '2026-01-29 00:24:37'),
(4, 'Maternity Leave', 'Leave for childbirth', 90, 1, '#8B5CF6', 1, '2026-01-29 00:24:37'),
(5, 'Paternity Leave', 'Leave for new fathers', 14, 1, '#3B82F6', 1, '2026-01-29 00:24:37'),
(6, 'Personal Leave', 'Leave for personal reasons', 7, 1, '#F59E0B', 1, '2026-01-29 00:24:37'),
(7, 'Study Leave', 'Leave for educational purposes', 30, 1, '#8B5CF6', 1, '2026-01-29 00:24:37'),
(8, 'Bereavement Leave', 'Leave for family bereavement', 7, 0, '#6B7280', 1, '2026-01-29 00:24:37');

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed','pending') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
  `category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `category`, `description`, `is_public`, `created_at`, `updated_at`) VALUES
(1, 'company_name', 'Your Company', 'string', 'general', 'Company name', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(2, 'leave_year_start', '01-01', 'string', 'leave', 'Leave year start date (MM-DD)', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(3, 'leave_year_end', '12-31', 'string', 'leave', 'Leave year end date (MM-DD)', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(4, 'max_carry_over', '5', 'number', 'leave', 'Maximum days that can be carried over', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(5, 'approval_workflow', 'direct_manager', 'string', 'workflow', 'Leave approval workflow type', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(6, 'auto_approve_sick_leave', 'true', 'boolean', 'workflow', 'Auto-approve sick leave requests', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(7, 'notification_email', 'true', 'boolean', 'notifications', 'Send email notifications', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(8, 'google_forms_enabled', 'true', 'boolean', 'integration', 'Enable Google Forms integration', 0, '2026-01-29 00:24:38', '2026-01-29 00:24:38'),
(9, 'webhook_secret_key', '12b6a42218791342fc6b6045269db2f1618447d5db76b4b94f8b60ae4ccfb04d', 'string', 'google_forms', 'Google Forms Webhook Secret Key', 0, '2026-01-29 06:48:18', '2026-01-29 09:58:25'),
(10, 'webhook_url', 'http://yourdomain.com/leave_tracker/api/webhook.php', 'string', 'google_forms', 'Webhook URL for Google Forms', 0, '2026-01-29 06:48:18', '2026-01-29 06:54:54'),
(11, 'auto_create_users', 'false', 'boolean', 'google_forms', 'Automatically create user accounts from form submissions', 0, '2026-01-29 06:48:18', '2026-01-29 06:48:18'),
(12, 'default_leave_policy', '1', 'number', 'google_forms', 'Default leave policy for new users', 0, '2026-01-29 06:48:18', '2026-01-29 06:48:18'),
(13, 'notification_on_submission', 'true', 'boolean', 'notifications', 'Send email when form is submitted', 0, '2026-01-29 06:48:18', '2026-01-29 06:48:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','ceo','employee') DEFAULT 'employee',
  `department` varchar(50) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `password_change_required` tinyint(1) DEFAULT 0,
  `annual_leave_days` int(11) DEFAULT 21,
  `sick_leave_days` int(11) DEFAULT 14,
  `emergency_leave_days` int(11) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `google_forms_email` varchar(100) DEFAULT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `leave_policy_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `last_login`, `last_activity`, `full_name`, `role`, `department`, `position`, `phone`, `status`, `password_change_required`, `annual_leave_days`, `sick_leave_days`, `emergency_leave_days`, `created_at`, `updated_at`, `google_forms_email`, `email_notifications`, `leave_policy_id`) VALUES
(10, 'chisomoimfa@gmail.com', '$2y$10$XTOtBg0xR38pCjQ916NycuQvdC8ENeQ1MCftyaknwT6zXZ2Iqaapm', '2026-01-30 19:21:05', '2026-01-29 21:30:05', 'Brian Phiri', 'admin', NULL, NULL, NULL, 'active', 0, 21, 14, 5, '2026-01-29 07:29:09', '2026-01-30 19:21:05', NULL, 1, NULL),
(11, 'ryanjuniorphiri@gmail.com', '$2y$10$/PrgIXmIcqAIU4B8Eu1qVeWjT8kNS1hXDK58rXlec8SPyQYGKu8.W', NULL, NULL, 'Chisomo Imfa', 'employee', '', 'Accountant', '+265997265470', 'active', 0, 21, 14, 5, '2026-01-29 23:49:43', '2026-01-29 23:49:43', NULL, 1, NULL),
(12, 'letifasungama@gmail.com', '$2y$10$SxScoDootaXBwTboXPnps.nhpQ3STyzPcKRD.A.pNHNl3t.GtULD2', NULL, NULL, 'Letifa sungam', 'employee', '', 'hh', '+265997265470', 'active', 0, 18, 14, 5, '2026-01-30 01:33:15', '2026-01-30 01:33:15', NULL, 1, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_leave_summary`
-- (See below for the actual view)
--
CREATE TABLE `user_leave_summary` (
`user_id` int(11)
,`full_name` varchar(100)
,`email` varchar(100)
,`department` varchar(50)
,`role` enum('admin','ceo','employee')
,`annual_balance` decimal(33,0)
,`sick_balance` decimal(33,0)
,`emergency_balance` decimal(33,0)
,`pending_leaves` bigint(21)
,`approved_leaves` bigint(21)
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `user_leave_summary`
--
DROP TABLE IF EXISTS `user_leave_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_leave_summary`  AS SELECT `u`.`id` AS `user_id`, `u`.`full_name` AS `full_name`, `u`.`email` AS `email`, `u`.`department` AS `department`, `u`.`role` AS `role`, coalesce(`lb`.`annual_balance`,`u`.`annual_leave_days`) AS `annual_balance`, coalesce(`lb`.`sick_balance`,`u`.`sick_leave_days`) AS `sick_balance`, coalesce(`lb`.`emergency_balance`,`u`.`emergency_leave_days`) AS `emergency_balance`, coalesce(`pl`.`pending_leaves`,0) AS `pending_leaves`, coalesce(`al`.`approved_leaves`,0) AS `approved_leaves`, `u`.`created_at` AS `created_at` FROM ((((`users` `u` left join (select `leaves`.`user_id` AS `user_id`,sum(case when `leaves`.`leave_type_id` = 1 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_annual`,sum(case when `leaves`.`leave_type_id` = 2 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_sick`,sum(case when `leaves`.`leave_type_id` = 3 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_emergency` from `leaves` where year(`leaves`.`created_at`) = year(curdate()) group by `leaves`.`user_id`) `l` on(`u`.`id` = `l`.`user_id`)) left join (select `leaves`.`user_id` AS `user_id`,count(0) AS `pending_leaves` from `leaves` where `leaves`.`status` = 'pending' group by `leaves`.`user_id`) `pl` on(`u`.`id` = `pl`.`user_id`)) left join (select `leaves`.`user_id` AS `user_id`,count(0) AS `approved_leaves` from `leaves` where `leaves`.`status` = 'approved' and year(`leaves`.`created_at`) = year(curdate()) group by `leaves`.`user_id`) `al` on(`u`.`id` = `al`.`user_id`)) left join (select `u`.`id` AS `id`,`u`.`annual_leave_days` - coalesce(`l`.`used_annual`,0) AS `annual_balance`,`u`.`sick_leave_days` - coalesce(`l`.`used_sick`,0) AS `sick_balance`,`u`.`emergency_leave_days` - coalesce(`l`.`used_emergency`,0) AS `emergency_balance` from (`users` `u` left join (select `leaves`.`user_id` AS `user_id`,sum(case when `leaves`.`leave_type_id` = 1 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_annual`,sum(case when `leaves`.`leave_type_id` = 2 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_sick`,sum(case when `leaves`.`leave_type_id` = 3 and `leaves`.`status` = 'approved' then `leaves`.`total_days` else 0 end) AS `used_emergency` from `leaves` where year(`leaves`.`created_at`) = year(curdate()) group by `leaves`.`user_id`) `l` on(`u`.`id` = `l`.`user_id`))) `lb` on(`u`.`id` = `lb`.`id`)) WHERE `u`.`status` = 'active' ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `recipient_email` (`recipient_email`),
  ADD KEY `type` (`type`),
  ADD KEY `sent_at` (`sent_at`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_key` (`template_key`);

--
-- Indexes for table `google_forms`
--
ALTER TABLE `google_forms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `google_form_submissions`
--
ALTER TABLE `google_form_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `submission_id` (`submission_id`),
  ADD KEY `form_id` (`form_id`),
  ADD KEY `idx_employee_email` (`employee_email`),
  ADD KEY `idx_processed` (`processed`),
  ADD KEY `idx_submission_id` (`submission_id`);

--
-- Indexes for table `leaves`
--
ALTER TABLE `leaves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leave_type_id` (`leave_type_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_start_date` (`start_date`),
  ADD KEY `idx_end_date` (`end_date`),
  ADD KEY `google_form_id` (`google_form_id`),
  ADD KEY `fk_leaves_approved_by` (`approved_by`),
  ADD KEY `rejected_by` (`rejected_by`);

--
-- Indexes for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_leave_year` (`user_id`,`leave_type_id`,`year`),
  ADD KEY `leave_type_id` (`leave_type_id`);

--
-- Indexes for table `leave_policies`
--
ALTER TABLE `leave_policies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_google_forms_email` (`google_forms_email`),
  ADD KEY `leave_policy_id` (`leave_policy_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `google_forms`
--
ALTER TABLE `google_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `google_form_submissions`
--
ALTER TABLE `google_form_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leaves`
--
ALTER TABLE `leaves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `leave_balances`
--
ALTER TABLE `leave_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `leave_policies`
--
ALTER TABLE `leave_policies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_activity_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `fk_email_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `google_form_submissions`
--
ALTER TABLE `google_form_submissions`
  ADD CONSTRAINT `google_form_submissions_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `google_forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leaves`
--
ALTER TABLE `leaves`
  ADD CONSTRAINT `fk_leaves_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leaves_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leaves_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `leaves_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leaves_ibfk_4` FOREIGN KEY (`google_form_id`) REFERENCES `google_forms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leaves_ibfk_5` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_balances_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`);

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `notification_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`leave_policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
