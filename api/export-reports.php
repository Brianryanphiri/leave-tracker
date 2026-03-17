<?php
// api/export-reports.php - Simplified export functionality
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is admin or CEO
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

$pdo = getPDOConnection();

// Get current user
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

// Check if export parameter is set
if (!isset($_GET['export'])) {
    header('HTTP/1.0 400 Bad Request');
    exit('Export type not specified');
}

$export_type = $_GET['export'];
$filters = [
    'period' => $_GET['period'] ?? 'monthly',
    'department' => $_GET['department'] ?? 'all',
    'leave_type' => $_GET['leave_type'] ?? 'all',
    'status' => $_GET['status'] ?? 'approved', // Default to approved for exports
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t')
];

// Simple CSV export
if ($export_type === 'csv') {
    $filename = "leave_report_" . date('Y-m-d_H-i-s') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Basic header
    fputcsv($output, ['Leave Report - Generated on ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty line

    // Get leave data
    $query = "
        SELECT 
            u.full_name,
            u.department,
            u.email,
            l.start_date,
            l.end_date,
            l.total_days,
            l.status,
            lt.name as leave_type,
            l.reason
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        WHERE l.start_date BETWEEN ? AND ?
    ";

    $params = [$filters['start_date'], $filters['end_date']];

    if ($filters['department'] !== 'all') {
        $query .= " AND u.department = ?";
        $params[] = $filters['department'];
    }

    if ($filters['leave_type'] !== 'all') {
        $query .= " AND l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    if ($filters['status'] !== 'all') {
        $query .= " AND l.status = ?";
        $params[] = $filters['status'];
    }

    $query .= " ORDER BY l.start_date DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Write headers
    fputcsv($output, ['Employee Name', 'Department', 'Email', 'Start Date', 'End Date', 'Total Days', 'Leave Type', 'Status', 'Reason']);

    // Write data
    foreach ($leaves as $leave) {
        fputcsv($output, [
            $leave['full_name'],
            $leave['department'],
            $leave['email'],
            $leave['start_date'],
            $leave['end_date'],
            $leave['total_days'],
            $leave['leave_type'],
            $leave['status'],
            $leave['reason']
        ]);
    }

    fclose($output);
    exit;
}

// Excel export
elseif ($export_type === 'excel') {
    $filename = "leave_report_" . date('Y-m-d_H-i-s') . ".xls";

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Get leave data (same query as CSV)
    $query = "
        SELECT 
            u.full_name,
            u.department,
            u.email,
            l.start_date,
            l.end_date,
            l.total_days,
            l.status,
            lt.name as leave_type,
            l.reason
        FROM leaves l
        JOIN users u ON l.user_id = u.id
        JOIN leave_types lt ON l.leave_type_id = lt.id
        WHERE l.start_date BETWEEN ? AND ?
    ";

    $params = [$filters['start_date'], $filters['end_date']];

    if ($filters['department'] !== 'all') {
        $query .= " AND u.department = ?";
        $params[] = $filters['department'];
    }

    if ($filters['leave_type'] !== 'all') {
        $query .= " AND l.leave_type_id = ?";
        $params[] = $filters['leave_type'];
    }

    if ($filters['status'] !== 'all') {
        $query .= " AND l.status = ?";
        $params[] = $filters['status'];
    }

    $query .= " ORDER BY l.start_date DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output HTML table (Excel can open this)
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #D4A017; color: white; font-weight: bold; }
        </style>
    </head>
    <body>
        <h2>Leave Report</h2>
        <p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Period:</strong> " . $filters['start_date'] . " to " . $filters['end_date'] . "</p>
        
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Department</th>
                <th>Email</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Total Days</th>
                <th>Leave Type</th>
                <th>Status</th>
                <th>Reason</th>
            </tr>";

    foreach ($leaves as $leave) {
        echo "<tr>
            <td>" . htmlspecialchars($leave['full_name']) . "</td>
            <td>" . htmlspecialchars($leave['department']) . "</td>
            <td>" . htmlspecialchars($leave['email']) . "</td>
            <td>" . $leave['start_date'] . "</td>
            <td>" . $leave['end_date'] . "</td>
            <td>" . $leave['total_days'] . "</td>
            <td>" . htmlspecialchars($leave['leave_type']) . "</td>
            <td>" . $leave['status'] . "</td>
            <td>" . htmlspecialchars($leave['reason']) . "</td>
        </tr>";
    }

    echo "</table>
    </body>
    </html>";

    exit;
}

// PDF export - placeholder (requires PDF library)
elseif ($export_type === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="leave_report_' . date('Y-m-d_H-i-s') . '.pdf"');

    echo "<html>
    <head>
        <title>Leave Report PDF</title>
    </head>
    <body>
        <h1>Leave Report</h1>
        <p>PDF export requires a PDF library like TCPDF or Dompdf.</p>
        <p>To enable PDF export, please install a PDF library and update this file.</p>
        <p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Filters applied:</strong></p>
        <ul>
            <li>Period: " . $filters['start_date'] . " to " . $filters['end_date'] . "</li>
            <li>Department: " . $filters['department'] . "</li>
            <li>Leave Type: " . $filters['leave_type'] . "</li>
            <li>Status: " . $filters['status'] . "</li>
        </ul>
    </body>
    </html>";

    exit;
} else {
    header('HTTP/1.0 400 Bad Request');
    exit('Invalid export type');
}
?>