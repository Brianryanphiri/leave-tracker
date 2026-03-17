<?php
// view-leave.php - View Leave Request Details - UPDATED FOR YOUR DATABASE
$page_title = "View Leave Request";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if leave ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: leave-approvals.php');
    exit();
}

$leave_id = intval($_GET['id']);
$pdo = getPDOConnection();
$leave = null;
$error_message = null;
$leave_balance = null;
$history = [];
$attachments = [];

if ($pdo) {
    try {
        // UPDATED SQL QUERY - Matches your exact database structure
        $stmt = $pdo->prepare("
            SELECT 
                l.*,
                u.id as user_id,
                u.full_name as employee_name,
                u.email as employee_email,
                u.department as employee_department,
                u.position as employee_position,
                u.phone as employee_phone,
                u.role as employee_role,
                lt.name as leave_type_name,
                lt.description as leave_type_description,
                lt.color as leave_type_color,
                a.full_name as approver_name,
                a.email as approver_email,
                a.department as approver_department,
                a.position as approver_position,
                CASE 
                    WHEN l.source = 'google_forms' THEN 'Google Forms'
                    ELSE 'Dashboard'
                END as request_source
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE l.id = ?
        ");

        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$leave) {
            $error_message = "Leave request not found.";
        } else {
            // Check if user has permission to view this leave
            $can_view = $current_user['role'] === 'admin' ||
                $current_user['role'] === 'ceo' ||
                $current_user['id'] == $leave['user_id'];

            if (!$can_view) {
                header('Location: dashboard.php');
                exit();
            }

            // Get leave balance for this user and leave type
            $current_year = date('Y');
            $balance_stmt = $pdo->prepare("
                SELECT * FROM leave_balances 
                WHERE user_id = ? AND leave_type_id = ? AND year = ?
            ");
            $balance_stmt->execute([$leave['user_id'], $leave['leave_type_id'], $current_year]);
            $leave_balance = $balance_stmt->fetch(PDO::FETCH_ASSOC);

            // Get approval history - check if table exists first
            $table_check = $pdo->query("SHOW TABLES LIKE 'leave_history'")->fetch();
            if ($table_check) {
                $history_stmt = $pdo->prepare("
                    SELECT 
                        lh.*,
                        u.full_name as action_by_name
                    FROM leave_history lh
                    LEFT JOIN users u ON lh.action_by = u.id
                    WHERE lh.leave_id = ?
                    ORDER BY lh.created_at DESC
                ");
                $history_stmt->execute([$leave_id]);
                $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Get attachments - check if table exists first
            $table_check = $pdo->query("SHOW TABLES LIKE 'leave_attachments'")->fetch();
            if ($table_check) {
                $attachments_stmt = $pdo->prepare("
                    SELECT * FROM leave_attachments 
                    WHERE leave_id = ? 
                    ORDER BY created_at DESC
                ");
                $attachments_stmt->execute([$leave_id]);
                $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate durations
            $start_date = new DateTime($leave['start_date']);
            $end_date = new DateTime($leave['end_date']);
            $applied_date = new DateTime($leave['applied_date'] ?? $leave['created_at']);
            $approved_date = $leave['approved_at'] ? new DateTime($leave['approved_at']) : null;
            $rejected_date = $leave['rejected_at'] ? new DateTime($leave['rejected_at']) : null;
            $total_calendar_days = $end_date->diff($start_date)->days + 1;

            // Format dates
            $formatted_start = $start_date->format('l, F j, Y');
            $formatted_end = $end_date->format('l, F j, Y');
            $formatted_applied = $applied_date->format('F j, Y \a\t g:i A');
            $formatted_approved = $approved_date ? $approved_date->format('F j, Y \a\t g:i A') : null;
            $formatted_rejected = $rejected_date ? $rejected_date->format('F j, Y \a\t g:i A') : null;

            // Calculate duration text
            $duration_text = ($leave['total_days'] ?? $total_calendar_days) . ' day' . (($leave['total_days'] ?? $total_calendar_days) > 1 ? 's' : '');
            if (isset($leave['half_day']) && $leave['half_day'] !== 'none') {
                $duration_text .= ' (' . ucfirst($leave['half_day']) . ' half day)';
            }

            // Status badge color
            $status_colors = [
                'pending' => ['bg' => '#FFF3CD', 'text' => '#856404', 'border' => '#FFE69C', 'icon' => 'clock'],
                'approved' => ['bg' => '#D1E7DD', 'text' => '#0F5132', 'border' => '#BADBCC', 'icon' => 'check-circle'],
                'rejected' => ['bg' => '#F8D7DA', 'text' => '#842029', 'border' => '#F5C2C7', 'icon' => 'times-circle'],
                'cancelled' => ['bg' => '#E2E3E5', 'text' => '#41464B', 'border' => '#D3D6D8', 'icon' => 'ban']
            ];
            $leave_status = $leave['status'] ?? 'pending';
            $status_config = $status_colors[$leave_status] ?? $status_colors['pending'];
        }

    } catch (PDOException $e) {
        error_log("Error fetching leave details: " . $e->getMessage());
        $error_message = "Error loading leave details: " . $e->getMessage();
    }
} else {
    $error_message = "Database connection failed. Please try again.";
}

// Only declare getFileIcon if it doesn't exist in functions.php
if (!function_exists('getFileIcon')) {
    // Helper function to get file icon based on extension
    function getFileIcon($ext)
    {
        $icons = [
            'pdf' => 'file-pdf',
            'doc' => 'file-word',
            'docx' => 'file-word',
            'xls' => 'file-excel',
            'xlsx' => 'file-excel',
            'ppt' => 'file-powerpoint',
            'pptx' => 'file-powerpoint',
            'jpg' => 'file-image',
            'jpeg' => 'file-image',
            'png' => 'file-image',
            'gif' => 'file-image',
            'txt' => 'file-alt',
            'zip' => 'file-archive',
            'rar' => 'file-archive',
            '7z' => 'file-archive'
        ];
        return $icons[strtolower($ext)] ?? 'file';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Leave Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
            --color-pending: #856404;
            --color-approved: #0F5132;
            --color-rejected: #842029;
            --color-cancelled: #41464B;
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
            max-width: 1400px;
            margin: 0 auto;
            box-sizing: border-box;
            padding-top: 20px;
        }

        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #F8D7DA, #F5C6CB);
            border: 1px solid #F5C6CB;
            border-radius: 16px;
            padding: 40px;
            margin: 50px auto;
            max-width: 600px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }

        .error-message i {
            font-size: 4em;
            color: #721C24;
            margin-bottom: 20px;
        }

        .error-message h2 {
            color: #721C24;
            font-size: 1.8em;
            margin-bottom: 15px;
            font-weight: 700;
        }

        .error-message p {
            color: #721C24;
            font-size: 1.1em;
            margin-bottom: 25px;
            line-height: 1.6;
            opacity: 0.9;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 40px;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 30px rgba(139, 115, 85, 0.1);
            border: 1px solid var(--color-border);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 8px;
            height: 100%;
            background: linear-gradient(180deg, var(--color-primary), var(--color-primary-dark));
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.4em;
            color: var(--color-text);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 20px;
            background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-title i {
            font-size: 1.8em;
            color: var(--color-primary);
        }

        .page-subtitle {
            color: var(--color-dark-gray);
            font-size: 1.2em;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .page-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(212, 160, 23, 0.25);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, #D2691E 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(212, 160, 23, 0.35);
        }

        .btn-secondary {
            background: white;
            color: var(--color-primary-dark);
            border: 2px solid var(--color-border);
            font-weight: 600;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white;
            border-color: var(--color-primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(212, 160, 23, 0.2);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--color-danger), #8B4513);
            color: white;
            box-shadow: 0 6px 20px rgba(139, 69, 19, 0.25);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #8B4513, #A0522D);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(139, 69, 19, 0.35);
        }

        /* Leave Details Grid */
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 35px;
            margin-bottom: 40px;
        }

        @media (max-width: 1200px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Main Details Card */
        .main-details-card {
            background: white;
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 8px 30px rgba(139, 115, 85, 0.1);
            border: 1px solid var(--color-border);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 2px solid var(--color-border);
        }

        .detail-title {
            font-size: 1.6em;
            color: var(--color-text);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .detail-title i {
            color: var(--color-primary);
            font-size: 1.3em;
        }

        .status-badge {
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Detail Sections */
        .detail-section {
            margin-bottom: 30px;
        }

        .detail-section:last-child {
            margin-bottom: 0;
        }

        .section-title {
            font-size: 1.2em;
            color: var(--color-primary-dark);
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            font-size: 1.2em;
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .detail-label {
            font-size: 0.95em;
            color: var(--color-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 1.1em;
            color: var(--color-text);
            font-weight: 600;
        }

        .detail-value strong {
            font-weight: 700;
            color: var(--color-primary-dark);
        }

        /* Employee Info */
        .employee-card {
            background: white;
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 8px 30px rgba(139, 115, 85, 0.1);
            border: 1px solid var(--color-border);
            height: fit-content;
        }

        .employee-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 2px solid var(--color-border);
        }

        .employee-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 2.5em;
            flex-shrink: 0;
            box-shadow: 0 8px 25px rgba(212, 160, 23, 0.3);
        }

        .employee-info h3 {
            font-size: 1.5em;
            color: var(--color-text);
            margin-bottom: 8px;
            font-weight: 700;
        }

        .employee-info p {
            color: var(--color-dark-gray);
            font-size: 1em;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .employee-info p i {
            width: 20px;
            color: var(--color-primary);
            font-size: 1.1em;
        }

        /* Leave Type Badge */
        .leave-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 1.05em;
            font-weight: 700;
            background: rgba(212, 160, 23, 0.1);
            color: var(--color-primary-dark);
            border: 2px solid rgba(212, 160, 23, 0.2);
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(212, 160, 23, 0.15);
        }

        /* Reason Box */
        .reason-box {
            background: linear-gradient(135deg, rgba(212, 160, 23, 0.05), rgba(212, 160, 23, 0.02));
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 25px;
            margin-top: 15px;
        }

        .reason-box p {
            color: var(--color-text);
            font-size: 1.1em;
            line-height: 1.7;
            margin: 0;
            white-space: pre-wrap;
        }

        /* Approver Info */
        .approver-info {
            background: linear-gradient(135deg, rgba(212, 160, 23, 0.08), rgba(212, 160, 23, 0.03));
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 25px;
            margin-top: 25px;
        }

        .approver-info h4 {
            font-size: 1.1em;
            color: var(--color-primary-dark);
            margin-bottom: 15px;
            font-weight: 700;
        }

        .approver-details {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .approver-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 1.5em;
            flex-shrink: 0;
            box-shadow: 0 5px 20px rgba(212, 160, 23, 0.25);
        }

        .approver-text h5 {
            font-size: 1.1em;
            color: var(--color-text);
            margin-bottom: 5px;
            font-weight: 700;
        }

        .approver-text p {
            color: var(--color-dark-gray);
            font-size: 0.9em;
            margin-bottom: 0;
        }

        .approver-notes {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
        }

        .approver-notes p {
            color: var(--color-text);
            font-size: 1em;
            line-height: 1.6;
            margin: 0;
            font-style: italic;
        }

        /* History Section */
        .history-section {
            margin-bottom: 40px;
        }

        .history-card {
            background: white;
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 8px 30px rgba(139, 115, 85, 0.1);
            border: 1px solid var(--color-border);
        }

        .history-timeline {
            margin-top: 25px;
        }

        .timeline-item {
            display: flex;
            gap: 25px;
            padding: 25px 0;
            border-bottom: 1px solid var(--color-border);
            position: relative;
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 24px;
            top: 60px;
            bottom: -25px;
            width: 3px;
            background: var(--color-border);
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3em;
            flex-shrink: 0;
            z-index: 1;
            box-shadow: 0 5px 20px rgba(212, 160, 23, 0.25);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-content h5 {
            font-size: 1.1em;
            color: var(--color-text);
            margin-bottom: 8px;
            font-weight: 700;
        }

        .timeline-content p {
            color: var(--color-dark-gray);
            font-size: 0.95em;
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .timeline-time {
            font-size: 0.9em;
            color: var(--color-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        /* Attachments Section */
        .attachments-section {
            margin-bottom: 40px;
        }

        .attachments-card {
            background: white;
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 8px 30px rgba(139, 115, 85, 0.1);
            border: 1px solid var(--color-border);
        }

        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 25px;
        }

        .attachment-item {
            background: linear-gradient(135deg, rgba(212, 160, 23, 0.08), rgba(212, 160, 23, 0.03));
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .attachment-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(212, 160, 23, 0.2);
            border-color: rgba(212, 160, 23, 0.4);
        }

        .attachment-icon {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8em;
            flex-shrink: 0;
            box-shadow: 0 5px 20px rgba(212, 160, 23, 0.25);
        }

        .attachment-info {
            flex: 1;
            min-width: 0;
        }

        .attachment-name {
            font-size: 1em;
            color: var(--color-text);
            font-weight: 700;
            margin-bottom: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .attachment-size {
            font-size: 0.9em;
            color: var(--color-dark-gray);
            font-weight: 500;
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 50px 30px;
            color: var(--color-dark-gray);
        }

        .no-data i {
            font-size: 3.5em;
            color: rgba(212, 160, 23, 0.3);
            margin-bottom: 25px;
        }

        .no-data h4 {
            font-size: 1.4em;
            color: var(--color-text);
            margin-bottom: 15px;
            font-weight: 700;
        }

        .no-data p {
            max-width: 450px;
            margin: 0 auto;
            font-size: 1.05em;
            line-height: 1.7;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 35px;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.4);
            border: 1px solid var(--color-border);
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 2px solid var(--color-border);
        }

        .modal-header h3 {
            color: var(--color-text);
            font-size: 1.6em;
            margin: 0;
            font-weight: 700;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .close-modal {
            background: white;
            border: 2px solid var(--color-border);
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            color: var(--color-primary-dark);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .close-modal:hover {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white;
            transform: rotate(90deg);
            border-color: var(--color-primary);
        }

        .modal-form-group {
            margin-bottom: 25px;
        }

        .modal-form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 700;
            color: var(--color-text);
            font-size: 1em;
        }

        .modal-form-control {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid var(--color-border);
            border-radius: 14px;
            font-family: 'Inter', sans-serif;
            font-size: 1.05em;
            color: var(--color-text);
            background: white;
            transition: all 0.3s ease;
            resize: vertical;
            min-height: 120px;
        }

        .modal-form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px rgba(212, 160, 23, 0.2);
        }

        .modal-actions {
            display: flex;
            gap: 20px;
            justify-content: flex-end;
            margin-top: 35px;
            padding-top: 25px;
            border-top: 2px solid var(--color-border);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .loading-overlay.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(212, 160, 23, 0.2);
            border-top: 5px solid var(--color-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 25px;
        }

        .loading-message {
            font-size: 1.2em;
            color: var(--color-text);
            font-weight: 700;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .content-area {
                padding: 25px;
            }

            .page-title {
                font-size: 2em;
            }

            .details-grid {
                gap: 25px;
            }

            .main-details-card,
            .employee-card,
            .history-card,
            .attachments-card {
                padding: 25px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 20px;
            }

            .page-title {
                font-size: 1.8em;
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .page-header {
                padding: 25px;
            }

            .detail-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .employee-header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .page-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .timeline-item {
                flex-direction: column;
                gap: 20px;
            }

            .timeline-item::before {
                left: 24px;
                top: 50px;
            }

            .approver-details {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .modal-content {
                width: 95%;
                padding: 25px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .attachments-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 1.6em;
            }

            .detail-title {
                font-size: 1.4em;
            }

            .section-title {
                font-size: 1.1em;
            }

            .btn {
                padding: 12px 20px;
                font-size: 0.95em;
            }

            .modal-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .close-modal {
                align-self: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="content-area">
        <?php if (isset($error_message)): ?>
                <!-- Error Message -->
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h2>Leave Request Not Found</h2>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                    <a href="leave-approvals.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Leave Approvals
                    </a>
                </div>
        <?php elseif (!$leave): ?>
                <!-- Loading or Error State -->
                <div class="error-message">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h2>Loading Leave Request...</h2>
                    <p>Please wait while we fetch the leave details.</p>
                    <a href="leave-approvals.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Leave Approvals
                    </a>
                </div>
        <?php else: ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-file-alt"></i>
                        Leave Request Details
                    </h1>
                    <p class="page-subtitle">Request ID: #<?php echo $leave_id; ?></p>
                
                    <div class="page-actions">
                        <?php if (($leave['status'] ?? '') === 'pending' && ($current_user['role'] === 'admin' || $current_user['role'] === 'ceo')): ?>
                                <button class="btn btn-primary" onclick="approveLeave(<?php echo $leave_id; ?>)">
                                    <i class="fas fa-check"></i> Approve Request
                                </button>
                                <button class="btn btn-danger" onclick="showRejectModal(<?php echo $leave_id; ?>)">
                                    <i class="fas fa-times"></i> Reject Request
                                </button>
                        <?php endif; ?>
                    
                        <?php if ($current_user['id'] == ($leave['user_id'] ?? 0) && ($leave['status'] ?? '') === 'pending'): ?>
                                <button class="btn btn-secondary" onclick="showCancelModal(<?php echo $leave_id; ?>)">
                                    <i class="fas fa-ban"></i> Cancel Request
                                </button>
                        <?php endif; ?>
                    
                        <a href="leave-approvals.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Approvals
                        </a>
                    
                        <?php if ($current_user['role'] === 'admin' || $current_user['role'] === 'ceo'): ?>
                                <button class="btn btn-secondary" onclick="printLeaveDetails()">
                                    <i class="fas fa-print"></i> Print Details
                                </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Leave Details Grid -->
                <div class="details-grid">
                    <!-- Main Details -->
                    <div class="main-details-card">
                        <div class="detail-header">
                            <h2 class="detail-title">
                                <i class="fas fa-info-circle"></i>
                                Leave Information
                            </h2>
                            <span class="status-badge" style="background: <?php echo $status_config['bg']; ?>; 
                                                         color: <?php echo $status_config['text']; ?>; 
                                                         border: 1px solid <?php echo $status_config['border']; ?>;">
                                <i class="fas fa-<?php echo $status_config['icon']; ?>"></i>
                                <?php echo ucfirst($leave_status); ?>
                            </span>
                        </div>

                        <!-- Dates & Duration -->
                        <div class="detail-section">
                            <h3 class="section-title">
                                <i class="fas fa-calendar-alt"></i>
                                Dates & Duration
                            </h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Start Date</span>
                                    <span class="detail-value"><?php echo $formatted_start; ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">End Date</span>
                                    <span class="detail-value"><?php echo $formatted_end; ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Duration</span>
                                    <span class="detail-value">
                                        <strong><?php echo $duration_text; ?></strong>
                                        <br>
                                        <small>(<?php echo $total_calendar_days; ?> calendar days)</small>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Applied On</span>
                                    <span class="detail-value"><?php echo $formatted_applied; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Leave Type -->
                        <div class="detail-section">
                            <h3 class="section-title">
                                <i class="fas fa-tag"></i>
                                Leave Type
                            </h3>
                            <div class="leave-type-badge" style="border-left-color: <?php echo $leave['leave_type_color'] ?? '#D4A017'; ?>;">
                                <i class="fas fa-calendar-alt" style="color: <?php echo $leave['leave_type_color'] ?? '#D4A017'; ?>;"></i>
                                <?php echo htmlspecialchars($leave['leave_type_name'] ?? 'Unknown Leave Type'); ?>
                            </div>
                            <?php if (!empty($leave['leave_type_description'])): ?>
                                    <p style="margin-top: 15px; color: var(--color-dark-gray); font-size: 1.05em; line-height: 1.6;">
                                        <?php echo htmlspecialchars($leave['leave_type_description']); ?>
                                    </p>
                            <?php endif; ?>
                        </div>

                        <!-- Reason -->
                        <div class="detail-section">
                            <h3 class="section-title">
                                <i class="fas fa-comment-alt"></i>
                                Reason for Leave
                            </h3>
                            <div class="reason-box">
                                <p><?php echo !empty($leave['reason']) ? nl2br(htmlspecialchars($leave['reason'])) : 'No reason provided'; ?></p>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="detail-section">
                            <h3 class="section-title">
                                <i class="fas fa-plus-circle"></i>
                                Additional Information
                            </h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Request Source</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($leave['request_source'] ?? 'Dashboard'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Half Day</span>
                                    <span class="detail-value">
                                        <?php echo isset($leave['half_day']) && $leave['half_day'] !== 'none' ? ucfirst($leave['half_day']) : 'No'; ?>
                                    </span>
                                </div>
                                <?php if ($leave_status === 'approved' && !empty($formatted_approved)): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Approved On</span>
                                        <span class="detail-value"><?php echo $formatted_approved; ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($leave_status === 'rejected' && !empty($formatted_rejected)): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Rejected On</span>
                                        <span class="detail-value"><?php echo $formatted_rejected; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Approver Information (if approved/rejected) -->
                        <?php if (in_array($leave_status, ['approved', 'rejected']) && !empty($leave['approver_name'])): ?>
                            <div class="detail-section">
                                <h3 class="section-title">
                                    <i class="fas fa-user-check"></i>
                                    <?php echo $leave_status === 'approved' ? 'Approved By' : 'Rejected By'; ?>
                                </h3>
                                <div class="approver-info">
                                    <div class="approver-details">
                                        <div class="approver-avatar">
                                            <?php echo strtoupper(substr($leave['approver_name'], 0, 1)); ?>
                                        </div>
                                        <div class="approver-text">
                                            <h5><?php echo htmlspecialchars($leave['approver_name']); ?></h5>
                                            <p><?php echo htmlspecialchars($leave['approver_position'] ?? 'Position not specified'); ?></p>
                                            <p><?php echo htmlspecialchars($leave['approver_department'] ?? 'Department not specified'); ?></p>
                                        </div>
                                    </div>
                                    <?php if (!empty($leave['approver_notes'])): ?>
                                        <div class="approver-notes">
                                            <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($leave['approver_notes'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($leave['rejection_reason'])): ?>
                                        <div class="approver-notes">
                                            <p><strong>Rejection Reason:</strong> <?php echo nl2br(htmlspecialchars($leave['rejection_reason'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div style="margin-top: 20px; font-size: 0.95em; color: var(--color-secondary); font-weight: 600;">
                                        <i class="fas fa-calendar-check"></i>
                                        <?php echo $leave_status === 'approved' ? 'Approved' : 'Rejected'; ?> 
                                        <?php if ($leave_status === 'approved' && !empty($formatted_approved)): ?>
                                                on: <?php echo $formatted_approved; ?>
                                        <?php elseif ($leave_status === 'rejected' && !empty($formatted_rejected)): ?>
                                                on: <?php echo $formatted_rejected; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Employee Information -->
                    <div class="employee-card">
                        <div class="employee-header">
                            <div class="employee-avatar">
                                <?php echo strtoupper(substr($leave['employee_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="employee-info">
                                <h3><?php echo htmlspecialchars($leave['employee_name'] ?? 'Unknown Employee'); ?></h3>
                                <p>
                                    <i class="fas fa-envelope"></i>
                                    <?php echo htmlspecialchars($leave['employee_email'] ?? 'No email provided'); ?>
                                </p>
                                <p>
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($leave['employee_department'] ?? 'No department specified'); ?>
                                </p>
                                <p>
                                    <i class="fas fa-briefcase"></i>
                                    <?php echo htmlspecialchars($leave['employee_position'] ?? 'Position not specified'); ?>
                                </p>
                                <?php if (!empty($leave['employee_phone'])): ?>
                                    <p>
                                        <i class="fas fa-phone"></i>
                                        <?php echo htmlspecialchars($leave['employee_phone']); ?>
                                    </p>
                                <?php endif; ?>
                                <p>
                                    <i class="fas fa-user-tag"></i>
                                    <?php echo ucfirst($leave['employee_role'] ?? 'employee'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Leave Balance -->
                        <div style="margin-top: 30px;">
                            <h3 class="section-title">
                                <i class="fas fa-chart-pie"></i>
                                Leave Balance
                            </h3>
                            <?php if ($leave_balance): ?>
                                    <div style="background: linear-gradient(135deg, rgba(212, 160, 23, 0.08), rgba(212, 160, 23, 0.03)); 
                                      border: 1px solid var(--color-border); 
                                      border-radius: 16px; 
                                      padding: 25px; 
                                      margin-top: 20px;">
                                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; text-align: center;">
                                            <div>
                                                <div style="font-size: 0.95em; color: var(--color-secondary); margin-bottom: 8px; font-weight: 600;">Total</div>
                                                <div style="font-size: 1.8em; font-weight: 700; color: var(--color-primary-dark);">
                                                    <?php echo $leave_balance['total_days']; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div style="font-size: 0.95em; color: var(--color-secondary); margin-bottom: 8px; font-weight: 600;">Used</div>
                                                <div style="font-size: 1.8em; font-weight: 700; color: var(--color-danger);">
                                                    <?php echo $leave_balance['used_days']; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div style="font-size: 0.95em; color: var(--color-secondary); margin-bottom: 8px; font-weight: 600;">Remaining</div>
                                                <div style="font-size: 1.8em; font-weight: 700; color: var(--color-success);">
                                                    <?php echo $leave_balance['remaining_days']; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="margin-top: 20px; font-size: 0.9em; color: var(--color-dark-gray); text-align: center; font-weight: 600;">
                                            Year: <?php echo $leave_balance['year']; ?>
                                        </div>
                                    </div>
                            <?php else: ?>
                                    <div class="no-data" style="padding: 25px;">
                                        <i class="fas fa-chart-line"></i>
                                        <p>No leave balance information available for current year.</p>
                                    </div>
                            <?php endif; ?>
                        </div>

                        <!-- Contact Information -->
                        <div style="margin-top: 30px;">
                            <h3 class="section-title">
                                <i class="fas fa-address-card"></i>
                                Quick Actions
                            </h3>
                            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 20px;">
                                <a href="mailto:<?php echo htmlspecialchars($leave['employee_email'] ?? ''); ?>" 
                                   class="btn btn-secondary" style="justify-content: center;">
                                    <i class="fas fa-envelope"></i> Email Employee
                                </a>
                                <?php if (!empty($leave['employee_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($leave['employee_phone']); ?>" 
                                       class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-phone"></i> Call Employee
                                    </a>
                                <?php endif; ?>
                                <?php if ($current_user['role'] === 'admin' || $current_user['role'] === 'ceo'): ?>
                                    <a href="edit-leave.php?id=<?php echo $leave_id; ?>" 
                                       class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-edit"></i> Edit Leave
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- History Timeline -->
                <div class="history-section">
                    <div class="history-card">
                        <h2 class="detail-title">
                            <i class="fas fa-history"></i>
                            Request History
                        </h2>
                    
                        <div class="history-timeline">
                            <?php if (!empty($history)): ?>
                                    <?php foreach ($history as $event):
                                        $event_date = new DateTime($event['created_at']);
                                        $formatted_event_date = $event_date->format('F j, Y \a\t g:i A');
                                        ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon">
                                                    <i class="fas fa-<?php echo $event['action'] === 'created' ? 'plus' :
                                                        ($event['action'] === 'updated' ? 'edit' :
                                                            ($event['action'] === 'approved' ? 'check' :
                                                                ($event['action'] === 'rejected' ? 'times' :
                                                                    ($event['action'] === 'cancelled' ? 'ban' : 'info-circle')))); ?>"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <h5>
                                                        <?php echo ucfirst($event['action']); ?> 
                                                        <?php if (!empty($event['action_by_name'])): ?>
                                                                by <?php echo htmlspecialchars($event['action_by_name']); ?>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <?php if (!empty($event['notes'])): ?>
                                                            <p><?php echo nl2br(htmlspecialchars($event['notes'])); ?></p>
                                                    <?php endif; ?>
                                                    <div class="timeline-time">
                                                        <i class="far fa-clock"></i>
                                                        <?php echo $formatted_event_date; ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                            <?php else: ?>
                                    <!-- Default timeline items based on current status -->
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <i class="fas fa-plus"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <h5>Leave request created</h5>
                                            <p>Request was submitted for approval</p>
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo $formatted_applied; ?>
                                            </div>
                                        </div>
                                    </div>
                            
                                    <?php if ($leave_status === 'approved'): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <h5>Leave request approved</h5>
                                                    <?php if (!empty($leave['approver_name'])): ?>
                                                            <p>Approved by <?php echo htmlspecialchars($leave['approver_name']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($leave['approver_notes'])): ?>
                                                            <p><?php echo nl2br(htmlspecialchars($leave['approver_notes'])); ?></p>
                                                    <?php endif; ?>
                                                    <div class="timeline-time">
                                                        <i class="far fa-clock"></i>
                                                        <?php echo $formatted_approved; ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php elseif ($leave_status === 'rejected'): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon">
                                                    <i class="fas fa-times"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <h5>Leave request rejected</h5>
                                                    <?php if (!empty($leave['approver_name'])): ?>
                                                            <p>Rejected by <?php echo htmlspecialchars($leave['approver_name']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($leave['rejection_reason'])): ?>
                                                            <p>Reason: <?php echo nl2br(htmlspecialchars($leave['rejection_reason'])); ?></p>
                                                    <?php endif; ?>
                                                    <div class="timeline-time">
                                                        <i class="far fa-clock"></i>
                                                        <?php echo $formatted_rejected; ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php else: ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon">
                                                    <i class="fas fa-clock"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <h5>Pending approval</h5>
                                                    <p>Waiting for review by approver</p>
                                                    <div class="timeline-time">
                                                        <i class="far fa-clock"></i>
                                                        Current status
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Attachments Section -->
                <?php if (!empty($attachments)): ?>
                    <div class="attachments-section">
                        <div class="attachments-card">
                            <h2 class="detail-title">
                                <i class="fas fa-paperclip"></i>
                                Attachments
                            </h2>
                    
                            <div class="attachments-grid">
                                <?php foreach ($attachments as $attachment):
                                    $file_ext = pathinfo($attachment['file_name'], PATHINFO_EXTENSION);
                                    $file_icon = getFileIcon($file_ext);
                                    $file_size = formatFileSize($attachment['file_size']);
                                    $upload_date = new DateTime($attachment['created_at']);
                                    ?>
                                        <a href="download-attachment.php?id=<?php echo $attachment['id']; ?>" 
                                           class="attachment-item" 
                                           title="<?php echo htmlspecialchars($attachment['original_name']); ?>">
                                            <div class="attachment-icon">
                                                <i class="fas fa-<?php echo $file_icon; ?>"></i>
                                            </div>
                                            <div class="attachment-info">
                                                <div class="attachment-name">
                                                    <?php echo htmlspecialchars($attachment['original_name']); ?>
                                                </div>
                                                <div class="attachment-size">
                                                    <?php echo $file_size; ?> • 
                                                    <?php echo $upload_date->format('M j, Y'); ?>
                                                </div>
                                            </div>
                                        </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-message" id="loadingMessage">Processing request...</div>
    </div>

    <!-- Reject Modal -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Leave Request</h3>
                <button class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--color-text); font-size: 1.05em; line-height: 1.6;">
                    Please provide a reason for rejecting this leave request. The employee will be notified with this reason.
                </p>
                
                <div class="modal-form-group">
                    <label for="rejectionReason">Rejection Reason *</label>
                    <textarea id="rejectionReason" class="modal-form-control" placeholder="Enter reason for rejection..." required></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">
                        <i class="fas fa-check"></i> Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Modal -->
    <div class="modal-overlay" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-ban"></i> Cancel Leave Request</h3>
                <button class="close-modal" onclick="closeCancelModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--color-text); font-size: 1.05em; line-height: 1.6;">
                    Are you sure you want to cancel this leave request? This action cannot be undone.
                </p>
                
                <div class="modal-form-group">
                    <label for="cancelReason">Cancellation Reason (Optional)</label>
                    <textarea id="cancelReason" class="modal-form-control" placeholder="Enter reason for cancellation..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">
                        <i class="fas fa-times"></i> No, Keep Request
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmCancelBtn">
                        <i class="fas fa-check"></i> Yes, Cancel Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentLeaveId = null;
        let isProcessing = false;

        // Show/hide loading
        function showLoading(message = 'Processing...') {
            isProcessing = true;
            const overlay = document.getElementById('loadingOverlay');
            const messageEl = document.getElementById('loadingMessage');
            messageEl.textContent = message;
            overlay.classList.add('active');
        }

        function hideLoading() {
            isProcessing = false;
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('active');
        }

        // Approve leave function
        function approveLeave(leaveId) {
            if (isProcessing) return;
            
            if (!confirm('Are you sure you want to approve this leave request?')) {
                return;
            }

            showLoading('Approving leave request...');

            const requestData = {
                id: leaveId,
                notes: ''
            };

            fetch('api/approve-leave.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    const emailStatus = data.email_sent ? 'Email notification sent.' : 'Email notification failed.';
                    alert(`Leave approved successfully! ${emailStatus}`);
                    location.reload();
                } else {
                    alert(data.message || 'Error approving leave request');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('An error occurred while approving leave. Please try again.');
            });
        }

        // Reject leave modal functions
        function showRejectModal(leaveId) {
            if (isProcessing) return;
            
            currentLeaveId = leaveId;
            const modal = document.getElementById('rejectModal');
            modal.style.display = 'flex';
            document.getElementById('rejectionReason').value = '';
            
            const confirmBtn = document.getElementById('confirmRejectBtn');
            confirmBtn.onclick = () => rejectLeave(leaveId);
        }

        function closeRejectModal() {
            const modal = document.getElementById('rejectModal');
            modal.style.display = 'none';
            document.getElementById('rejectionReason').value = '';
            currentLeaveId = null;
        }

        function rejectLeave(leaveId) {
            if (isProcessing) return;
            
            const reason = document.getElementById('rejectionReason').value.trim();
            
            if (!reason) {
                alert('Please provide a reason for rejection');
                return;
            }

            showLoading('Rejecting leave request...');

            const requestData = {
                id: leaveId,
                reason: reason
            };

            fetch('api/reject-leave.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    closeRejectModal();
                    const emailStatus = data.email_sent ? 'Email notification sent.' : 'Email notification failed.';
                    alert(`Leave rejected successfully. ${emailStatus}`);
                    location.reload();
                } else {
                    alert(data.message || 'Error rejecting leave request');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('An error occurred while rejecting leave. Please try again.');
            });
        }

        // Cancel leave modal functions
        function showCancelModal(leaveId) {
            if (isProcessing) return;
            
            currentLeaveId = leaveId;
            const modal = document.getElementById('cancelModal');
            modal.style.display = 'flex';
            document.getElementById('cancelReason').value = '';
            
            const confirmBtn = document.getElementById('confirmCancelBtn');
            confirmBtn.onclick = () => cancelLeave(leaveId);
        }

        function closeCancelModal() {
            const modal = document.getElementById('cancelModal');
            modal.style.display = 'none';
            document.getElementById('cancelReason').value = '';
            currentLeaveId = null;
        }

        function cancelLeave(leaveId) {
            if (isProcessing) return;
            
            const reason = document.getElementById('cancelReason').value.trim();
            
            if (!confirm('Are you sure you want to cancel this leave request?')) {
                return;
            }

            showLoading('Cancelling leave request...');

            const requestData = {
                id: leaveId,
                reason: reason
            };

            // Check if cancel API exists
            fetch('api/cancel-leave.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Cancel API not available');
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    closeCancelModal();
                    alert('Leave request cancelled successfully.');
                    location.reload();
                } else {
                    alert(data.message || 'Error cancelling leave request');
                }
            })
            .catch(error => {
                // Fallback to reject API with cancellation reason
                console.warn('Cancel API not available, using reject as fallback:', error);
                
                const fallbackRequest = {
                    id: leaveId,
                    reason: reason || 'Cancelled by employee'
                };
                
                fetch('api/reject-leave.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(fallbackRequest)
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        closeCancelModal();
                        alert('Leave request cancelled successfully (marked as rejected).');
                        location.reload();
                    } else {
                        alert(data.message || 'Error cancelling leave request');
                    }
                })
                .catch(fallbackError => {
                    hideLoading();
                    console.error('Fallback error:', fallbackError);
                    alert('An error occurred while cancelling leave. Please try again.');
                });
            });
        }

        // Print function
        function printLeaveDetails() {
            window.print();
        }

        // Close modals when clicking outside
        document.getElementById('rejectModal').addEventListener('click', function (e) {
            if (e.target === this && !isProcessing) {
                closeRejectModal();
            }
        });

        document.getElementById('cancelModal').addEventListener('click', function (e) {
            if (e.target === this && !isProcessing) {
                closeCancelModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !isProcessing) {
                closeRejectModal();
                closeCancelModal();
            }
        });
    </script>
</body>
</html>