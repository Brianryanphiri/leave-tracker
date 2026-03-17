<?php
// apply-leave-for-employee.php - Manual leave application by admin
$page_title = "Apply Leave for Employee";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is admin or CEO
if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('Location: dashboard.php');
    exit();
}

$pdo = getPDOConnection();
$leave_types = [];
$employees = [];
$message = '';
$message_type = '';

// Fetch leave types
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch active employees
        $stmt = $pdo->query("SELECT id, full_name, email, department FROM users WHERE status = 'active' ORDER BY full_name");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Pre-fetch leave balances for all employees
        $employee_balances = [];
        foreach ($employees as $employee) {
            $employee_id = $employee['id'];
            
            // Get user leave allocations
            $stmt = $pdo->prepare("SELECT 
                annual_leave_days, 
                sick_leave_days, 
                emergency_leave_days 
                FROM users WHERE id = ?");
            $stmt->execute([$employee_id]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_data) {
                // Get used leaves for current year
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN leave_type_id = 1 AND status = 'approved' THEN total_days ELSE 0 END) as used_annual,
                        SUM(CASE WHEN leave_type_id = 2 AND status = 'approved' THEN total_days ELSE 0 END) as used_sick,
                        SUM(CASE WHEN leave_type_id = 3 AND status = 'approved' THEN total_days ELSE 0 END) as used_emergency
                    FROM leaves 
                    WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE())
                ");
                $stmt->execute([$employee_id]);
                $used_leaves = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $employee_balances[$employee_id] = [
                    'balance' => [
                        'annual' => max(0, $user_data['annual_leave_days'] - ($used_leaves['used_annual'] ?? 0)),
                        'sick' => max(0, $user_data['sick_leave_days'] - ($used_leaves['used_sick'] ?? 0)),
                        'emergency' => max(0, $user_data['emergency_leave_days'] - ($used_leaves['used_emergency'] ?? 0))
                    ],
                    'allocated' => [
                        'annual' => $user_data['annual_leave_days'],
                        'sick' => $user_data['sick_leave_days'],
                        'emergency' => $user_data['emergency_leave_days']
                    ],
                    'used' => [
                        'annual' => $used_leaves['used_annual'] ?? 0,
                        'sick' => $used_leaves['used_sick'] ?? 0,
                        'emergency' => $used_leaves['used_emergency'] ?? 0
                    ]
                ];
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching data: " . $e->getMessage());
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee_id'] ?? '';
    $leave_type_id = $_POST['leave_type_id'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = $_POST['reason'] ?? '';
    $half_day = $_POST['half_day'] ?? 'none';
    $send_notification = isset($_POST['send_notification']);

    // Validate inputs
    if (empty($employee_id) || empty($leave_type_id) || empty($start_date) || empty($end_date)) {
        $message = "Please fill all required fields";
        $message_type = 'error';
    } else {
        try {
            // Calculate total days
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $total_days = $end->diff($start)->days + 1;

            // Check if half day
            if ($half_day !== 'none' && $total_days === 1) {
                $total_days = 0.5;
            }

            // Insert leave record
            $stmt = $pdo->prepare("
                INSERT INTO leaves (
                    user_id, leave_type_id, start_date, end_date, reason, 
                    status, total_days, half_day, source, applied_date, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, 'dashboard', CURDATE(), NOW(), NOW())
            ");

            $stmt->execute([
                $employee_id,
                $leave_type_id,
                $start_date,
                $end_date,
                $reason,
                $total_days,
                $half_day
            ]);

            $leave_id = $pdo->lastInsertId();

            // Get employee details
            $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            // Send notification email if requested
            if ($send_notification && $employee) {
                // Get leave type name
                $stmt = $pdo->prepare("SELECT name FROM leave_types WHERE id = ?");
                $stmt->execute([$leave_type_id]);
                $leave_type = $stmt->fetch(PDO::FETCH_ASSOC);

                // Send email
                $to = $employee['email'];
                $subject = "Leave Request Submitted on Your Behalf";
                $body = "Dear {$employee['full_name']},\n\n";
                $body .= "A leave request has been submitted on your behalf:\n\n";
                $body .= "Leave Type: {$leave_type['name']}\n";
                $body .= "Dates: $start_date to $end_date\n";
                $body .= "Total Days: $total_days\n";
                if ($half_day !== 'none') {
                    $body .= "Half Day: " . ucfirst($half_day) . "\n";
                }
                if (!empty($reason)) {
                    $body .= "Reason: $reason\n";
                }
                $body .= "\nThis request is now pending approval.\n\n";
                $body .= "Best regards,\nAdministration Team";

                $headers = "From: noreply@yourcompany.com\r\n";
                $headers .= "Reply-To: hr@yourcompany.com\r\n";

                if (mail($to, $subject, $body, $headers)) {
                    // Log notification
                    $stmt = $pdo->prepare("
                        INSERT INTO notification_logs (user_id, type, title, message, status, sent_at)
                        VALUES (?, 'email', ?, ?, 'sent', NOW())
                    ");
                    $stmt->execute([
                        $employee_id,
                        $subject,
                        $body
                    ]);
                }
            }

            // Log admin action
            logActivity('manual_leave_added', [
                'table_name' => 'leaves',
                'record_id' => $leave_id,
                'admin_id' => $current_user['id'],
                'employee_id' => $employee_id,
                'dates' => $start_date . ' to ' . $end_date
            ]);

            $message = "Leave request submitted successfully!";
            $message_type = 'success';

            // Clear form
            $_POST = [];

        } catch (PDOException $e) {
            error_log("Error submitting leave: " . $e->getMessage());
            $message = "Error submitting leave request: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>

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
        max-width: 100%;
        width: 100%;
        box-sizing: border-box;
        padding-top: 20px;
    }

    .application-container {
        max-width: 900px;
        margin: 0 auto;
        background: white;
        border-radius: 20px;
        padding: 40px;
        box-shadow: 0 8px 30px rgba(139, 115, 85, 0.08);
        border: 1px solid var(--color-border);
        position: relative;
        overflow: hidden;
    }

    .application-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, var(--color-primary), var(--color-primary-dark));
    }

    .page-title {
        font-family: 'Playfair Display', serif;
        font-size: 2em;
        color: var(--color-text);
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--color-border);
        display: flex;
        align-items: center;
        gap: 15px;
        background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .page-title i {
        color: var(--color-primary);
        font-size: 1.5em;
    }

    .form-section {
        margin-bottom: 40px;
        background: white;
        padding: 25px;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.05);
        border: 1px solid var(--color-border);
    }

    .section-title {
        font-size: 1.3em;
        color: var(--color-text);
        margin-bottom: 25px;
        padding-bottom: 12px;
        border-bottom: 2px solid rgba(212, 160, 23, 0.2);
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
    }

    .section-title i {
        color: var(--color-primary);
        font-size: 1.2em;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 25px;
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.95em;
    }

    .form-group label .required {
        color: var(--color-danger);
        margin-left: 4px;
    }

    .form-control {
        width: 100%;
        padding: 16px 18px;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 1em;
        color: var(--color-text);
        background: white;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
    }

    .form-control::placeholder {
        color: var(--color-dark-gray);
        opacity: 0.7;
    }

    textarea.form-control {
        min-height: 140px;
        resize: vertical;
        line-height: 1.6;
    }

    select.form-control option {
        padding: 12px;
        font-size: 0.95em;
    }

    .half-day-options {
        display: flex;
        gap: 25px;
        margin-top: 12px;
        flex-wrap: wrap;
    }

    .half-day-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 20px;
        background: white;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 500;
        color: var(--color-text);
    }

    .half-day-option:hover {
        border-color: var(--color-primary);
        background: rgba(212, 160, 23, 0.05);
    }

    .half-day-option input[type="radio"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--color-primary);
    }

    .half-day-option input[type="radio"]:checked + span {
        color: var(--color-primary-dark);
        font-weight: 600;
    }

    .notification-checkbox {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 30px 0;
        padding: 20px;
        background: rgba(212, 160, 23, 0.05);
        border-radius: 12px;
        border: 1px solid var(--color-border);
    }

    .notification-checkbox input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--color-primary);
    }

    .notification-checkbox label {
        font-weight: 500;
        color: var(--color-text);
        cursor: pointer;
    }

    .form-actions {
        display: flex;
        gap: 20px;
        margin-top: 40px;
        padding-top: 30px;
        border-top: 2px solid rgba(212, 160, 23, 0.2);
        flex-wrap: wrap;
    }

    .btn-submit {
        background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
        color: white;
        border: none;
        padding: 16px 32px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1.05em;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 200px;
        justify-content: center;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.2);
    }

    .btn-submit:hover {
        background: linear-gradient(135deg, var(--color-primary-dark) 0%, #D2691E 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.3);
    }

    .btn-cancel {
        background: white;
        color: var(--color-primary-dark);
        border: 2px solid var(--color-border);
        padding: 16px 32px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1.05em;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        text-align: center;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 120px;
        justify-content: center;
    }

    .btn-cancel:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(212, 160, 23, 0.15);
    }

    .alert {
        padding: 18px 22px;
        border-radius: 12px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
        animation: slideIn 0.3s ease-out;
        border-left: 4px solid;
    }

    .alert-success {
        background: rgba(212, 160, 23, 0.1);
        color: var(--color-success);
        border-left-color: var(--color-success);
    }

    .alert-error {
        background: rgba(139, 69, 19, 0.1);
        color: var(--color-danger);
        border-left-color: var(--color-danger);
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .employee-info-box {
        background: white;
        border: 1px solid var(--color-border);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        display: none;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.08);
    }

    .employee-info-box.active {
        display: block;
        animation: slideDown 0.3s ease-out;
    }

    .employee-info-row {
        display: flex;
        gap: 40px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }

    .info-item {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 0.95em;
    }

    .info-label {
        font-weight: 600;
        color: var(--color-secondary);
        min-width: 120px;
    }

    .info-value {
        color: var(--color-text);
        font-weight: 500;
    }

    .info-balance {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--color-border);
    }

    .balance-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-right: 25px;
        padding: 8px 15px;
        background: rgba(212, 160, 23, 0.1);
        border-radius: 10px;
        font-size: 0.9em;
        font-weight: 500;
    }

    .balance-item strong {
        color: var(--color-primary-dark);
        font-size: 1.1em;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .loading-spinner {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--color-secondary);
    }

    .loading-spinner i {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Leave type option styling */
    select option {
        padding: 12px 15px;
        margin: 5px 0;
        font-size: 0.95em;
    }

    select option[value=""] {
        color: var(--color-dark-gray);
        font-style: italic;
    }

    /* Date input styling */
    input[type="date"] {
        position: relative;
    }

    input[type="date"]::-webkit-calendar-picker-indicator {
        background-color: transparent;
        color: transparent;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238B7355' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
        background-position: center;
        background-repeat: no-repeat;
        background-size: 18px;
        cursor: pointer;
        padding: 0;
        margin-left: 10px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }

        .application-container {
            padding: 25px;
        }

        .page-title {
            font-size: 1.7em;
        }

        .form-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .form-section {
            padding: 20px;
        }

        .form-actions {
            flex-direction: column;
            gap: 15px;
        }

        .btn-submit,
        .btn-cancel {
            width: 100%;
            min-width: auto;
        }

        .employee-info-row {
            flex-direction: column;
            gap: 15px;
        }

        .half-day-options {
            flex-direction: column;
            gap: 15px;
        }

        .half-day-option {
            width: 100%;
            justify-content: space-between;
        }

        .balance-item {
            margin-right: 15px;
            margin-bottom: 10px;
        }
    }

    @media (max-width: 480px) {
        .page-title {
            font-size: 1.5em;
        }

        .application-container {
            padding: 20px;
        }

        .form-section {
            padding: 15px;
        }

        .section-title {
            font-size: 1.1em;
        }

        .form-control {
            padding: 14px 16px;
        }
    }
</style>

<div class="content-area">
    <div class="application-container">
        <h1 class="page-title">
            <i class="fas fa-user-plus"></i>
            Apply Leave for Employee
        </h1>

        <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
        <?php endif; ?>

        <form method="POST" action="" id="leaveForm">
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-user-tie"></i>
                    Employee Information
                </h3>

                <div class="form-group">
                    <label>Select Employee <span class="required">*</span></label>
                    <select name="employee_id" id="employeeSelect" class="form-control" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo $employee['id']; ?>"
                                    data-email="<?php echo htmlspecialchars($employee['email']); ?>"
                                    data-department="<?php echo htmlspecialchars($employee['department'] ?? 'Not set'); ?>"
                                    <?php echo ($_POST['employee_id'] ?? '') == $employee['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                    (<?php echo htmlspecialchars($employee['email']); ?>)
                                </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="employeeInfoBox" class="employee-info-box">
                    <div class="employee-info-row">
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value" id="infoEmail"></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Department:</span>
                            <span class="info-value" id="infoDepartment"></span>
                        </div>
                    </div>
                    <div class="info-balance">
                        <div class="info-item">
                            <span class="info-label">Leave Balance:</span>
                            <span class="info-value" id="infoBalance">
                                <span class="loading-spinner">
                                    <i class="fas fa-spinner"></i> Select an employee to view balance
                                </span>
                            </span>
                        </div>
                        <div style="margin-top: 15px;">
                            <span class="balance-item">
                                Annual: <strong id="balanceAnnual">0</strong> days
                            </span>
                            <span class="balance-item">
                                Sick: <strong id="balanceSick">0</strong> days
                            </span>
                            <span class="balance-item">
                                Emergency: <strong id="balanceEmergency">0</strong> days
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Leave Details
                </h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Leave Type <span class="required">*</span></label>
                        <select name="leave_type_id" class="form-control" required>
                            <option value="">-- Select Leave Type --</option>
                            <?php foreach ($leave_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"
                                        style="border-left: 4px solid <?php echo $type['color']; ?>; padding-left: 12px;"
                                        <?php echo ($_POST['leave_type_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['name']); ?>
                                        <?php if ($type['max_days'] > 0): ?>
                                                (Max: <?php echo $type['max_days']; ?> days)
                                        <?php endif; ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Start Date <span class="required">*</span></label>
                        <input type="date" name="start_date" class="form-control" min="<?php echo date('Y-m-d'); ?>"
                            required value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>"
                            onchange="updateEndDateMin(this)">
                    </div>

                    <div class="form-group">
                        <label>End Date <span class="required">*</span></label>
                        <input type="date" name="end_date" id="endDate" class="form-control" min="<?php echo date('Y-m-d'); ?>"
                            required value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Half Day</label>
                    <div class="half-day-options">
                        <label class="half-day-option">
                            <input type="radio" name="half_day" value="none" 
                                <?php echo ($_POST['half_day'] ?? 'none') == 'none' ? 'checked' : ''; ?>>
                            <span>Full Day</span>
                        </label>
                        <label class="half-day-option">
                            <input type="radio" name="half_day" value="morning"
                                <?php echo ($_POST['half_day'] ?? '') == 'morning' ? 'checked' : ''; ?>>
                            <span>Morning Only</span>
                        </label>
                        <label class="half-day-option">
                            <input type="radio" name="half_day" value="afternoon"
                                <?php echo ($_POST['half_day'] ?? '') == 'afternoon' ? 'checked' : ''; ?>>
                            <span>Afternoon Only</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Reason (Optional)</label>
                    <textarea name="reason" class="form-control" placeholder="Enter reason for leave..."
                        rows="4"><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="notification-checkbox">
                <input type="checkbox" name="send_notification" id="sendNotification" 
                    <?php echo isset($_POST['send_notification']) ? 'checked' : 'checked'; ?>>
                <label for="sendNotification">Send email notification to employee</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i>
                    Submit Leave Request
                </button>
                <a href="dashboard.php" class="btn-cancel">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const employeeSelect = document.getElementById('employeeSelect');
        const employeeInfoBox = document.getElementById('employeeInfoBox');
        const infoEmail = document.getElementById('infoEmail');
        const infoDepartment = document.getElementById('infoDepartment');
        const infoBalance = document.getElementById('infoBalance');
        const balanceAnnual = document.getElementById('balanceAnnual');
        const balanceSick = document.getElementById('balanceSick');
        const balanceEmergency = document.getElementById('balanceEmergency');
        
        // Get balance data from PHP (embedded in the page)
        const employeeBalances = <?php echo json_encode($employee_balances ?? []); ?>;
        const defaultBalances = {
            annual: 21,
            sick: 14,
            emergency: 5
        };

        // Initialize if employee is pre-selected (form submission with error)
        if (employeeSelect.value) {
            updateEmployeeInfo();
        }

        // Update employee info when selection changes
        employeeSelect.addEventListener('change', updateEmployeeInfo);

        function updateEmployeeInfo() {
            const selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
            const userId = employeeSelect.value;

            if (userId) {
                infoEmail.textContent = selectedOption.getAttribute('data-email');
                infoDepartment.textContent = selectedOption.getAttribute('data-department');
                employeeInfoBox.classList.add('active');

                // Show loading
                infoBalance.innerHTML = '<span class="loading-spinner"><i class="fas fa-spinner"></i> Loading balance...</span>';
                
                // Use pre-loaded balance data from PHP
                if (employeeBalances[userId]) {
                    const balanceData = employeeBalances[userId];
                    updateBalanceDisplay({
                        success: true,
                        balance: balanceData.balance,
                        used: balanceData.used,
                        allocated: balanceData.allocated
                    });
                } else {
                    // If no balance data found, use defaults
                    useDefaultBalance();
                }
            } else {
                employeeInfoBox.classList.remove('active');
                resetBalanceDisplay();
            }
        }

        function updateBalanceDisplay(data) {
            if (data && data.success && data.balance) {
                infoBalance.innerHTML = '';
                
                // Display remaining balance
                balanceAnnual.textContent = data.balance.annual || '0';
                balanceSick.textContent = data.balance.sick || '0';
                balanceEmergency.textContent = data.balance.emergency || '0';
                
                // Show used vs allocated if available
                if (data.used && data.allocated) {
                    infoBalance.innerHTML = `
                        <div style="font-size: 0.85em; color: var(--color-secondary); margin-top: 5px; line-height: 1.4;">
                            <div>Annual: ${data.used.annual}/${data.allocated.annual} days</div>
                            <div>Sick: ${data.used.sick}/${data.allocated.sick} days</div>
                            <div>Emergency: ${data.used.emergency}/${data.allocated.emergency} days</div>
                        </div>
                    `;
                }
            } else {
                useDefaultBalance();
            }
        }

        function useDefaultBalance() {
            infoBalance.innerHTML = '<span style="color: var(--color-warning); font-style: italic;">Using estimated balance</span>';
            balanceAnnual.textContent = defaultBalances.annual;
            balanceSick.textContent = defaultBalances.sick;
            balanceEmergency.textContent = defaultBalances.emergency;
        }

        function resetBalanceDisplay() {
            infoBalance.innerHTML = '<span class="loading-spinner"><i class="fas fa-spinner"></i> Select an employee to view balance</span>';
            balanceAnnual.textContent = '0';
            balanceSick.textContent = '0';
            balanceEmergency.textContent = '0';
        }

        // Form validation
        const form = document.getElementById('leaveForm');
        form.addEventListener('submit', function (e) {
            const startDate = document.querySelector('input[name="start_date"]');
            const endDate = document.querySelector('input[name="end_date"]');

            // Check if dates are valid
            if (!startDate.value || !endDate.value) {
                return true; // Let browser handle required field validation
            }

            if (new Date(startDate.value) > new Date(endDate.value)) {
                e.preventDefault();
                showNotification('End date must be after start date', 'error');
                endDate.focus();
                endDate.style.borderColor = 'var(--color-danger)';
                return false;
            }

            // Validate that the employee is selected
            if (!employeeSelect.value) {
                e.preventDefault();
                showNotification('Please select an employee', 'error');
                employeeSelect.focus();
                return false;
            }

            // Reset border color
            endDate.style.borderColor = 'var(--color-border)';
            return true;
        });

        // Update minimum end date based on start date
        window.updateEndDateMin = function(input) {
            const endDate = document.getElementById('endDate');
            endDate.min = input.value;

            // If end date is before start date, reset it
            if (endDate.value && new Date(endDate.value) < new Date(input.value)) {
                endDate.value = input.value;
            }
        };

        // Show notification
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.custom-notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `custom-notification`;
            
            const bgColor = type === 'success' ? '#D4A017' : 
                           type === 'error' ? '#8B4513' : 
                           type === 'warning' ? '#CD853F' : '#4285F4';
            
            notification.innerHTML = `
                <div style="
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 16px 22px;
                    background: ${bgColor};
                    color: white;
                    border-radius: 12px;
                    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                    z-index: 1000;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    animation: slideIn 0.3s ease-out;
                    font-weight: 500;
                    min-width: 300px;
                    max-width: 400px;
                ">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                                 type === 'error' ? 'fa-exclamation-circle' : 
                                 type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    <span style="flex: 1;">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="
                        background: none;
                        border: none;
                        color: white;
                        cursor: pointer;
                        font-size: 20px;
                        padding: 0;
                        width: 24px;
                        height: 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-radius: 50%;
                        opacity: 0.7;
                    ">
                        &times;
                    </button>
                </div>
            `;

            document.body.appendChild(notification);

            // Remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease-out';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Add CSS for animations if not already present
        if (!document.querySelector('#notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }

        // Add CSS for custom notification
        if (!document.querySelector('#custom-notification-styles')) {
            const style = document.createElement('style');
            style.id = 'custom-notification-styles';
            style.textContent = `
                .custom-notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10000;
                    animation: slideInRight 0.3s ease-out;
                }
                
                @keyframes slideInRight {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
                
                @keyframes slideOutRight {
                    from {
                        transform: translateX(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    });
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>