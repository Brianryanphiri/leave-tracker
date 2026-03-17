<?php
// add-employee.php - Add/Edit Employee Form (UPDATED WITH OCHER SCHEME)
$page_title = "Add New Employee";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is admin or CEO
if ($current_user['role'] !== 'admin' && $current_user['role'] !== 'ceo') {
    header('Location: dashboard.php');
    exit();
}

$pdo = getPDOConnection();
$employee = null;
$is_edit = false;
$employee_id = null;
$departments = [];
$managers = [];
$leave_types = [];
$message = '';
$message_type = '';

// Check if we're editing an existing employee
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $employee_id = intval($_GET['edit']);
    $is_edit = true;
    $page_title = "Edit Employee";

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE id = ? AND (role = 'employee' OR role = 'ceo')
            ");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                header('Location: manage-employees.php?error=' . urlencode('Employee not found'));
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error fetching employee: " . $e->getMessage());
        }
    }
}

// Fetch departments
if ($pdo) {
    try {
        // Get unique departments from existing employees
        $stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get managers (admins and CEOs)
        $stmt = $pdo->query("SELECT id, full_name, department FROM users WHERE role IN ('admin', 'ceo') AND status = 'active' ORDER BY full_name");
        $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get leave types for leave allocations
        $stmt = $pdo->query("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching data: " . $e->getMessage());
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $data = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'role' => $_POST['role'] ?? 'employee',
        'department' => trim($_POST['department'] ?? ''),
        'position' => trim($_POST['position'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'hire_date' => trim($_POST['hire_date'] ?? date('Y-m-d')),
        'status' => $_POST['status'] ?? 'active',
        'annual_leave_days' => intval($_POST['annual_leave_days'] ?? 21),
        'sick_leave_days' => intval($_POST['sick_leave_days'] ?? 14),
        'emergency_leave_days' => intval($_POST['emergency_leave_days'] ?? 5),
        'email_notifications' => isset($_POST['email_notifications']) ? 1 : 0,
        'leave_eligible' => isset($_POST['leave_eligible']) ? 1 : 0
    ];

    // For new employees, generate password
    $password = '';
    if (!$is_edit) {
        $password = bin2hex(random_bytes(8)); // Generate random password
        $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        // Don't add created_at here - it will be handled by the database
    }

    // Validate required fields
    $required = ['full_name', 'email', 'hire_date'];
    $errors = [];

    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }

    // Validate email format
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Validate hire date
    if (!empty($data['hire_date'])) {
        $hire_date = DateTime::createFromFormat('Y-m-d', $data['hire_date']);
        if (!$hire_date) {
            $errors[] = "Invalid hire date format. Use YYYY-MM-DD";
        } elseif ($hire_date > new DateTime()) {
            $errors[] = "Hire date cannot be in the future";
        }
    }

    // Check if email already exists (for new employees)
    if (!$is_edit && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                $errors[] = "Email already exists";
            }
        } catch (PDOException $e) {
            error_log("Error checking email: " . $e->getMessage());
        }
    }

    if (empty($errors)) {
        try {
            if ($is_edit && $employee_id) {
                // Update existing employee
                $update_fields = [];
                $update_params = [];

                foreach ($data as $field => $value) {
                    if ($field !== 'password') {
                        $update_fields[] = "$field = ?";
                        $update_params[] = $value;
                    }
                }

                $update_params[] = $employee_id;

                $sql = "UPDATE users SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($update_params);

                // Log the update
                logActivity('employee_updated', [
                    'table_name' => 'users',
                    'record_id' => $employee_id,
                    'old_value' => $employee,
                    'new_value' => $data
                ]);

                $message = "Employee updated successfully!";
                $message_type = 'success';

            } else {
                // Insert new employee
                $columns = array_keys($data);
                $placeholders = array_fill(0, count($columns), '?');
                $values = array_values($data);

                // Don't include created_at in columns - database handles it
                $sql = "INSERT INTO users (" . implode(', ', $columns) . ", created_at, updated_at) 
                        VALUES (" . implode(', ', $placeholders) . ", NOW(), NOW())";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);

                $employee_id = $pdo->lastInsertId();

                // Send welcome email with credentials
                if (!empty($password)) {
                    sendWelcomeEmail($data['email'], $data['full_name'], $password, $data['email']);
                }

                // Log the creation
                logActivity('employee_created', [
                    'table_name' => 'users',
                    'record_id' => $employee_id,
                    'new_value' => $data
                ]);

                $message = "Employee added successfully!";
                $message_type = 'success';

                // Reset form for new entry
                if (!$is_edit) {
                    $data = array_fill_keys(array_keys($data), '');
                    $data['hire_date'] = date('Y-m-d');
                    $data['annual_leave_days'] = 21;
                    $data['sick_leave_days'] = 14;
                    $data['emergency_leave_days'] = 5;
                    $data['leave_eligible'] = 0;
                    $password = '';
                }
            }

        } catch (PDOException $e) {
            error_log("Error saving employee: " . $e->getMessage());
            $message = "Error saving employee: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// If we're editing, use employee data
if ($is_edit && $employee) {
    $data = $employee;
}

// Calculate leave eligibility
$leave_eligible = 0;
$leave_eligibility_date = '';
if (!empty($data['hire_date'])) {
    $hire_date = new DateTime($data['hire_date']);
    $current_date = new DateTime();
    $one_year_ago = (clone $current_date)->modify('-1 year');

    // Employee is eligible if hired more than 1 year ago
    if ($hire_date <= $one_year_ago) {
        $leave_eligible = 1;
    }

    // Calculate eligibility date (hire date + 1 year)
    $eligibility_date = (clone $hire_date)->modify('+1 year');
    $leave_eligibility_date = $eligibility_date->format('M j, Y');
}
?>

<style>
    /* Enhanced Background Styles */
    body {
        font-family: 'Inter', sans-serif;
        background: #FFFFFF;
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
        margin: 0;
        padding: 0;
    }

    /* Subtle background pattern with ocher colors */
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

    /* Main Layout */
    .content-area {
        padding: 30px;
        position: relative;
        z-index: 1;
        min-height: calc(100vh - 60px);
    }

    /* Enhanced Form Container */
    .form-container {
        max-width: 1200px;
        margin: 0 auto;
        background: white;
        border-radius: 20px;
        box-shadow: 0 8px 40px rgba(139, 115, 85, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.1);
        overflow: hidden;
    }

    .form-container::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 200px;
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.05), transparent);
        border-radius: 0 0 0 100%;
    }

    /* Enhanced Form Header */
    .form-header {
        padding: 40px;
        background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%);
        color: white;
        position: relative;
        overflow: hidden;
    }

    .form-header::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 300px;
        height: 300px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transform: translate(30%, -30%);
    }

    .form-header h1 {
        font-family: 'Playfair Display', serif;
        font-size: 2.2em;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        z-index: 1;
    }

    .form-header h1 i {
        font-size: 1.6em;
        background: rgba(255, 255, 255, 0.2);
        width: 70px;
        height: 70px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(5px);
    }

    .form-header p {
        opacity: 0.9;
        font-size: 1.1em;
        position: relative;
        z-index: 1;
        max-width: 600px;
    }

    .form-content {
        padding: 40px;
    }

    /* Form Sections */
    .form-section {
        margin-bottom: 40px;
        padding-bottom: 40px;
        border-bottom: 2px solid rgba(212, 160, 23, 0.1);
        position: relative;
        background: white;
        padding: 30px;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
        transition: all 0.3s ease;
        display: block !important;
    }

    .form-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .section-header {
        display: flex;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid rgba(212, 160, 23, 0.2);
    }

    .section-icon {
        width: 60px;
        height: 60px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.1), rgba(210, 180, 140, 0.1));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5em;
        color: #D4A017;
        margin-right: 20px;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.15);
        border: 2px solid rgba(212, 160, 23, 0.2);
    }

    .section-title {
        font-size: 1.5em;
        font-weight: 700;
        color: #2F2F2F;
        margin-bottom: 8px;
        font-family: 'Playfair Display', serif;
    }

    .section-subtitle {
        color: #8B7355;
        font-size: 1em;
        max-width: 600px;
    }

    /* Form Grid */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        color: #2F2F2F;
        font-size: 0.95em;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-group label .required {
        color: #DC2626;
        margin-left: 3px;
        font-weight: bold;
    }

    .form-control {
        width: 100%;
        padding: 14px 18px;
        border: 2px solid rgba(212, 160, 23, 0.3);
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 1em;
        color: #333;
        background: white;
        transition: all 0.3s ease;
        box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.04);
    }

    .form-control:focus {
        outline: none;
        border-color: #D4A017;
        box-shadow: 0 0 0 4px rgba(212, 160, 23, 0.15);
        background: white;
    }

    .form-control::placeholder {
        color: #A8A8A8;
    }

    select.form-control {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='%23D4A017' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 18px center;
        background-size: 18px;
        padding-right: 50px;
    }

    /* Checkbox and Radio */
    .checkbox-group,
    .radio-group {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-top: 12px;
    }

    .checkbox-item,
    .radio-item {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        padding: 12px 18px;
        background: rgba(255, 255, 255, 0.8);
        border-radius: 10px;
        border: 2px solid rgba(212, 160, 23, 0.2);
        transition: all 0.3s ease;
        flex: 1;
        min-width: 200px;
    }

    .checkbox-item:hover,
    .radio-item:hover {
        background: rgba(212, 160, 23, 0.05);
        border-color: #D4A017;
        transform: translateY(-2px);
    }

    .checkbox-item input[type="checkbox"],
    .radio-item input[type="radio"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #D4A017;
    }

    /* Leave Eligibility Banner */
    .eligibility-banner {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.1), rgba(210, 180, 140, 0.1));
        border: 2px solid rgba(212, 160, 23, 0.3);
        border-radius: 14px;
        padding: 20px;
        margin: 20px 0;
        display: flex;
        align-items: center;
        gap: 15px;
        backdrop-filter: blur(5px);
    }

    .eligibility-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background: linear-gradient(135deg, #D4A017, #B8860B);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.3em;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.25);
        flex-shrink: 0;
    }

    .eligibility-content {
        flex: 1;
    }

    .eligibility-content h4 {
        color: #2F2F2F;
        margin-bottom: 8px;
        font-size: 1.1em;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .eligibility-content p {
        color: #8B7355;
        margin: 0;
        font-size: 0.95em;
        line-height: 1.5;
    }

    /* Leave Allocation Cards */
    .leave-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-top: 30px;
    }

    .leave-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        border: 2px solid transparent;
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(212, 160, 23, 0.1);
    }

    .leave-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 6px;
    }

    .leave-card:nth-child(1)::before { background: linear-gradient(90deg, #D4A017, #B8860B); }
    .leave-card:nth-child(2)::before { background: linear-gradient(90deg, #8B7355, #A0522D); }
    .leave-card:nth-child(3)::before { background: linear-gradient(90deg, #CD853F, #D4A017); }

    .leave-card:hover {
        border-color: #D4A017;
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(212, 160, 23, 0.15);
    }

    .leave-card-header {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
    }

    .leave-card-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3em;
        margin-right: 15px;
        color: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .leave-card-title {
        font-weight: 700;
        color: #2F2F2F;
        font-size: 1.1em;
        font-family: 'Playfair Display', serif;
    }

    .leave-card-desc {
        font-size: 0.9em;
        color: #8B7355;
        margin-top: 5px;
    }

    /* Generated Password */
    .password-display {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.08), rgba(210, 180, 140, 0.08));
        border: 2px dashed rgba(212, 160, 23, 0.5);
        border-radius: 16px;
        padding: 25px;
        margin-bottom: 25px;
        display: none;
        backdrop-filter: blur(5px);
        box-shadow: inset 0 0 15px rgba(212, 160, 23, 0.1);
    }

    .password-display.show {
        display: block;
        animation: fadeIn 0.5s ease-out;
    }

    .password-display h4 {
        color: #2F2F2F;
        margin-bottom: 15px;
        font-size: 1.2em;
        display: flex;
        align-items: center;
        gap: 12px;
        font-family: 'Playfair Display', serif;
    }

    .password-field {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
    }

    .password-field input {
        flex: 1;
        padding: 15px;
        border: 2px solid rgba(212, 160, 23, 0.3);
        border-radius: 10px;
        font-family: 'Courier New', monospace;
        font-size: 1.1em;
        letter-spacing: 1.5px;
        background: white;
        font-weight: 600;
        color: #2F2F2F;
        box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .copy-btn {
        padding: 15px 25px;
        background: linear-gradient(135deg, #D4A017, #B8860B);
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 700;
        font-size: 1em;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(212, 160, 23, 0.25);
    }

    .copy-btn:hover {
        background: linear-gradient(135deg, #B8860B, #D2691E);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(212, 160, 23, 0.35);
    }

    /* Form Actions */
    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 40px;
        padding-top: 30px;
        border-top: 2px solid rgba(212, 160, 23, 0.1);
        background: white;
        padding: 25px;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
    }

    .btn {
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1em;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(212, 160, 23, 0.3);
    }

    .btn-secondary {
        background: white;
        color: #2F2F2F;
        border: 2px solid rgba(212, 160, 23, 0.3);
    }

    .btn-secondary:hover {
        background: rgba(212, 160, 23, 0.05);
        border-color: #D4A017;
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(212, 160, 23, 0.15);
    }

    .btn-danger {
        background: linear-gradient(135deg, #DC2626, #B91C1C);
        color: white;
    }

    .btn-danger:hover {
        background: linear-gradient(135deg, #B91C1C, #DC2626);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
    }

    /* Alert Messages */
    .alert {
        padding: 20px 25px;
        border-radius: 16px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
        animation: slideIn 0.5s ease-out;
        border: 2px solid transparent;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }

    .alert-success {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.15), rgba(184, 134, 11, 0.05));
        color: #B8860B;
        border-color: rgba(212, 160, 23, 0.3);
    }

    .alert-error {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(185, 28, 28, 0.05));
        color: #7F1D1D;
        border-color: rgba(239, 68, 68, 0.3);
    }

    .alert i {
        font-size: 1.5em;
    }

    /* Summary Card */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .summary-card {
        background: white;
        padding: 20px;
        border-radius: 14px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        border: 1px solid rgba(212, 160, 23, 0.1);
    }

    .summary-card h4 {
        color: #D4A017;
        margin-bottom: 15px;
        font-size: 1em;
        border-bottom: 2px solid rgba(212, 160, 23, 0.2);
        padding-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .summary-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(212, 160, 23, 0.1);
    }

    .summary-item:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: none;
    }

    .summary-label {
        color: #8B7355;
        font-size: 0.9em;
    }

    .summary-value {
        color: #2F2F2F;
        font-weight: 600;
        text-align: right;
        max-width: 60%;
        word-break: break-word;
    }

    /* Department Dropdown with Typing */
    .department-wrapper {
        position: relative;
    }

    .department-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid rgba(212, 160, 23, 0.2);
        border-radius: 10px;
        margin-top: 5px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 100;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        display: none;
    }

    .department-suggestion {
        padding: 12px 18px;
        cursor: pointer;
        transition: all 0.2s ease;
        border-bottom: 1px solid rgba(212, 160, 23, 0.1);
    }

    .department-suggestion:hover {
        background: rgba(212, 160, 23, 0.05);
    }

    .department-suggestion:last-child {
        border-bottom: none;
    }

    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .form-grid {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        
        .leave-cards {
            grid-template-columns: 1fr;
        }
        
        .summary-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 20px;
        }
        
        .form-header {
            padding: 30px 20px;
        }
        
        .form-header h1 {
            font-size: 1.8em;
        }
        
        .form-content {
            padding: 25px;
        }
        
        .form-section {
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            font-size: 1.3em;
            margin-right: 0;
            margin-bottom: 10px;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .form-actions {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
        
        .checkbox-item,
        .radio-item {
            min-width: 100%;
        }
    }

    @media (max-width: 480px) {
        .form-container {
            border-radius: 16px;
        }
        
        .form-header h1 {
            font-size: 1.6em;
        }
        
        .section-title {
            font-size: 1.3em;
        }
        
        .form-control {
            padding: 14px 16px;
        }
        
        .btn {
            padding: 12px 18px;
        }
        
        .leave-card {
            padding: 20px;
        }
    }
</style>

<div class="content-area">
    <div class="form-container">
        <div class="form-header">
            <h1>
                <i class="fas <?php echo $is_edit ? 'fa-user-edit' : 'fa-user-plus'; ?>"></i>
                <?php echo $page_title; ?>
            </h1>
            <p>
                <?php echo $is_edit
                    ? 'Update employee information and settings in the leave management system'
                    : 'Add a new employee to the leave management system with comprehensive details'; ?>
            </p>
        </div>
        
        <div class="form-content">
            <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <div>
                            <h4 style="margin: 0 0 5px 0; font-size: 1.1em;">
                                <?php echo $message_type === 'success' ? 'Success!' : 'Attention Required'; ?>
                            </h4>
                            <?php echo $message; ?>
                        </div>
                    </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="employeeForm">
                <!-- Section 1: Basic Information -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Basic Information</h2>
                            <p class="section-subtitle">Employee's personal and contact details for identification</p>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-user"></i>
                                Full Name <span class="required">*</span>
                            </label>
                            <input type="text" name="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($data['full_name'] ?? ''); ?>" 
                                   placeholder="Enter employee's full name" required>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-envelope"></i>
                                Email Address <span class="required">*</span>
                            </label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($data['email'] ?? ''); ?>" 
                                   placeholder="employee@company.com" required 
                                   <?php echo $is_edit ? 'readonly' : ''; ?>>
                            <small style="color: #8B7355; margin-top: 5px; display: block;">
                                <?php echo $is_edit ? 'Email cannot be changed for existing employees' : 'This will be used for login and notifications'; ?>
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-phone"></i>
                                Phone Number
                            </label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>" 
                                   placeholder="+1234567890">
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-calendar-day"></i>
                                Hire Date <span class="required">*</span>
                            </label>
                            <input type="date" name="hire_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($data['hire_date'] ?? date('Y-m-d')); ?>" 
                                   required>
                            <small style="color: #8B7355; margin-top: 5px; display: block;">
                                Date when employee joined the company
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Employment Details -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Employment Details</h2>
                            <p class="section-subtitle">Job information, department, and employment status</p>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-user-tag"></i>
                                Role
                            </label>
                            <select name="role" class="form-control">
                                <option value="employee" <?php echo ($data['role'] ?? 'employee') === 'employee' ? 'selected' : ''; ?>>
                                    Employee
                                </option>
                                <option value="ceo" <?php echo ($data['role'] ?? '') === 'ceo' ? 'selected' : ''; ?>>
                                    CEO
                                </option>
                            </select>
                            <small style="color: #8B7355; margin-top: 5px; display: block;">
                                Role determines system access and permissions
                            </small>
                        </div>
                        
                        <div class="form-group department-wrapper">
                            <label>
                                <i class="fas fa-building"></i>
                                Department
                            </label>
                            <input type="text" name="department" class="form-control" id="departmentInput"
                                   value="<?php echo htmlspecialchars($data['department'] ?? ''); ?>" 
                                   placeholder="Type department name or select from list"
                                   list="departmentList">
                            <datalist id="departmentList">
                                <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small style="color: #8B7355; margin-top: 5px; display: block;">
                                Type to search existing departments or enter a new one
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-id-badge"></i>
                                Position/Title
                            </label>
                            <input type="text" name="position" class="form-control" 
                                   value="<?php echo htmlspecialchars($data['position'] ?? ''); ?>" 
                                   placeholder="e.g., Software Developer, Marketing Manager">
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-chart-line"></i>
                                Status
                            </label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo ($data['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>
                                    Active
                                </option>
                                <option value="inactive" <?php echo ($data['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>
                                    Inactive
                                </option>
                            </select>
                            <small style="color: #8B7355; margin-top: 5px; display: block;">
                                Active employees can access the system
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Section 3: Leave Eligibility -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Leave Eligibility</h2>
                            <p class="section-subtitle">Configure employee's leave eligibility status</p>
                        </div>
                    </div>
                    
                    <!-- Leave Eligibility Banner -->
                    <div class="eligibility-banner" id="eligibilityBanner">
                        <div class="eligibility-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="eligibility-content">
                            <h4>
                                <i class="fas fa-info-circle"></i>
                                Leave Eligibility Status
                            </h4>
                            <p id="eligibilityText">
                                <?php if (!empty($data['hire_date'])): ?>
                                        <?php
                                        $hire_date = new DateTime($data['hire_date']);
                                        $current_date = new DateTime();
                                        $one_year_ago = (clone $current_date)->modify('-1 year');
                                        $eligibility_date = (clone $hire_date)->modify('+1 year');

                                        if ($hire_date <= $one_year_ago): ?>
                                                <span style="color: #B8860B; font-weight: 600;">
                                                    <i class="fas fa-check-circle"></i> ELIGIBLE FOR LEAVE
                                                </span> - Employee has completed one year of service (hired on <?php echo $hire_date->format('M j, Y'); ?>).
                                        <?php else: ?>
                                                <span style="color: #CD853F; font-weight: 600;">
                                                    <i class="fas fa-clock"></i> NOT YET ELIGIBLE
                                                </span> - Employee will become eligible on <?php echo $eligibility_date->format('M j, Y'); ?>.
                                        <?php endif; ?>
                                <?php else: ?>
                                        <span style="color: #8B7355; font-weight: 600;">
                                            <i class="fas fa-question-circle"></i> PENDING ELIGIBILITY
                                        </span> - Enter hire date to calculate leave eligibility.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Leave Eligibility Checkbox -->
                    <div class="checkbox-group">
                        <label class="checkbox-item">
                            <input type="checkbox" name="leave_eligible" value="1" 
                                <?php echo isset($data['leave_eligible']) && $data['leave_eligible'] ? 'checked' : 'checked'; ?>
                                id="leaveEligibleCheckbox">
                            <span style="font-weight: 600; color: #2F2F2F;">
                                <i class="fas fa-check-circle"></i> Employee is eligible for leave
                            </span>
                        </label>
                    </div>
                    
                    <div style="margin-top: 15px; padding: 15px; background: rgba(212, 160, 23, 0.05); border-radius: 12px; border-left: 4px solid #D4A017;">
                        <h4 style="color: #2F2F2F; margin-bottom: 8px; font-size: 0.95em; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-lightbulb"></i> Leave Policy Information
                        </h4>
                        <p style="color: #8B7355; margin: 0; font-size: 0.9em;">
                            New employees are eligible for leave after completing <strong>1 year of service</strong>. 
                            The eligibility date is automatically calculated based on the hire date. 
                            You can manually override this if needed for special cases.
                        </p>
                    </div>
                </div>
                
                <!-- Section 4: Leave Allocation -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Leave Allocation</h2>
                            <p class="section-subtitle">Set annual leave balances for this employee</p>
                        </div>
                    </div>
                    
                    <div class="leave-cards">
                        <div class="leave-card">
                            <div class="leave-card-header">
                                <div class="leave-card-icon" style="background: linear-gradient(135deg, #D4A017, #B8860B);">
                                    <i class="fas fa-umbrella-beach"></i>
                                </div>
                                <div>
                                    <div class="leave-card-title">Annual Leave</div>
                                    <div class="leave-card-desc">Paid vacation days per year for relaxation</div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label style="color: #B8860B; font-weight: 600; margin-bottom: 8px;">Days per Year</label>
                                <input type="number" name="annual_leave_days" class="form-control" 
                                       value="<?php echo $data['annual_leave_days'] ?? 21; ?>" 
                                       min="0" max="365" step="1" required>
                                <small style="color: #8B7355; margin-top: 5px; display: block;">
                                    Standard: 21 days | Maximum: 365 days
                                </small>
                            </div>
                        </div>
                        
                        <div class="leave-card">
                            <div class="leave-card-header">
                                <div class="leave-card-icon" style="background: linear-gradient(135deg, #8B7355, #A0522D);">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div>
                                    <div class="leave-card-title">Sick Leave</div>
                                    <div class="leave-card-desc">Medical and health-related leave days</div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label style="color: #A0522D; font-weight: 600; margin-bottom: 8px;">Days per Year</label>
                                <input type="number" name="sick_leave_days" class="form-control" 
                                       value="<?php echo $data['sick_leave_days'] ?? 14; ?>" 
                                       min="0" max="365" step="1" required>
                                <small style="color: #8B7355; margin-top: 5px; display: block;">
                                    Standard: 14 days | Maximum: 365 days
                                </small>
                            </div>
                        </div>
                        
                        <div class="leave-card">
                            <div class="leave-card-header">
                                <div class="leave-card-icon" style="background: linear-gradient(135deg, #CD853F, #D4A017);">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div>
                                    <div class="leave-card-title">Emergency Leave</div>
                                    <div class="leave-card-desc">Urgent personal matters and emergencies</div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label style="color: #CD853F; font-weight: 600; margin-bottom: 8px;">Days per Year</label>
                                <input type="number" name="emergency_leave_days" class="form-control" 
                                       value="<?php echo $data['emergency_leave_days'] ?? 5; ?>" 
                                       min="0" max="365" step="1" required>
                                <small style="color: #8B7355; margin-top: 5px; display: block;">
                                    Standard: 5 days | Maximum: 365 days
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 25px; padding: 18px; background: rgba(212, 160, 23, 0.05); border-radius: 12px;">
                        <h4 style="color: #2F2F2F; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; font-size: 1em;">
                            <i class="fas fa-info-circle"></i> Leave Policy Summary
                        </h4>
                        <p style="color: #8B7355; margin: 0; font-size: 0.95em;">
                            Total allocated leave days: <strong id="totalLeaveDays" style="color: #D4A017;"><?php echo ($data['annual_leave_days'] ?? 21) + ($data['sick_leave_days'] ?? 14) + ($data['emergency_leave_days'] ?? 5); ?></strong> days per year.
                            These allocations will be renewed annually on the employee's work anniversary.
                        </p>
                    </div>
                </div>
                
                <!-- Section 5: Notification Settings -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Notification Settings</h2>
                            <p class="section-subtitle">Configure how the employee receives system updates</p>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label style="font-size: 1.1em;">
                                <i class="fas fa-envelope-open-text"></i>
                                Email Notifications
                            </label>
                            <div class="checkbox-group">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="email_notifications" value="1" 
                                        <?php echo isset($data['email_notifications']) && $data['email_notifications'] ? 'checked' : 'checked'; ?>>
                                    <span style="font-weight: 600; color: #2F2F2F;">
                                        Send email notifications for leave status updates
                                    </span>
                                </label>
                            </div>
                            <small style="color: #8B7355; margin-top: 5px; display: block; padding: 10px; background: rgba(212, 160, 23, 0.05); border-radius: 8px; font-size: 0.9em;">
                                <i class="fas fa-info-circle"></i> Employee will receive emails for leave request approvals, rejections, and important account updates.
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Section 6: Review & Summary -->
                <div class="form-section" style="border-bottom: none;">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Review & Summary</h2>
                            <p class="section-subtitle">Review all employee information before submission</p>
                        </div>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-card">
                            <h4><i class="fas fa-user-circle"></i> Basic Information</h4>
                            <div class="summary-item">
                                <span class="summary-label">Full Name:</span>
                                <span class="summary-value" id="summaryName"><?php echo htmlspecialchars($data['full_name'] ?? 'Not provided'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Email:</span>
                                <span class="summary-value" id="summaryEmail"><?php echo htmlspecialchars($data['email'] ?? 'Not provided'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Phone:</span>
                                <span class="summary-value" id="summaryPhone"><?php echo htmlspecialchars($data['phone'] ?? 'Not provided'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Hire Date:</span>
                                <span class="summary-value" id="summaryHireDate"><?php echo !empty($data['hire_date']) ? date('M j, Y', strtotime($data['hire_date'])) : 'Not provided'; ?></span>
                            </div>
                        </div>
                        
                        <div class="summary-card">
                            <h4><i class="fas fa-briefcase"></i> Employment Details</h4>
                            <div class="summary-item">
                                <span class="summary-label">Department:</span>
                                <span class="summary-value" id="summaryDept"><?php echo htmlspecialchars($data['department'] ?? 'Not assigned'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Position:</span>
                                <span class="summary-value" id="summaryPosition"><?php echo htmlspecialchars($data['position'] ?? 'Not specified'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Status:</span>
                                <span class="summary-value" id="summaryStatus"><?php echo htmlspecialchars(($data['status'] ?? 'active') === 'active' ? 'Active' : 'Inactive'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Leave Eligible:</span>
                                <span class="summary-value" id="summaryEligible"><?php echo isset($data['leave_eligible']) && $data['leave_eligible'] ? 'Yes' : 'No'; ?></span>
                            </div>
                        </div>
                        
                        <div class="summary-card">
                            <h4><i class="fas fa-calendar-alt"></i> Leave Allocation</h4>
                            <div class="summary-item">
                                <span class="summary-label">Annual Leave:</span>
                                <span class="summary-value" id="summaryAnnual"><?php echo $data['annual_leave_days'] ?? 21; ?> days</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Sick Leave:</span>
                                <span class="summary-value" id="summarySick"><?php echo $data['sick_leave_days'] ?? 14; ?> days</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Emergency Leave:</span>
                                <span class="summary-value" id="summaryEmergency"><?php echo $data['emergency_leave_days'] ?? 5; ?> days</span>
                            </div>
                            <div class="summary-item" style="border-top: 2px solid rgba(212, 160, 23, 0.3); padding-top: 10px; margin-top: 10px;">
                                <span class="summary-label" style="font-weight: 700; color: #2F2F2F;">Total:</span>
                                <span class="summary-value" style="font-weight: 700; color: #D4A017; font-size: 1.1em;" id="summaryTotal">
                                    <?php echo ($data['annual_leave_days'] ?? 21) + ($data['sick_leave_days'] ?? 14) + ($data['emergency_leave_days'] ?? 5); ?> days
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Generated Password Section (for new employees only) -->
                <?php if (!$is_edit): ?>
                    <div id="generatedPassword" class="password-display">
                        <h4><i class="fas fa-key"></i> Generated Credentials</h4>
                        <div class="password-field">
                            <input type="text" id="passwordField" readonly value="<?php echo htmlspecialchars($password ?? ''); ?>">
                            <button type="button" class="copy-btn" onclick="copyPassword()">
                                <i class="fas fa-copy"></i> Copy Password
                            </button>
                        </div>
                        <div style="color: #8B7355; font-size: 0.9em; padding: 12px; background: rgba(255, 255, 255, 0.8); border-radius: 8px; margin-top: 12px;">
                            <p style="margin: 0 0 8px 0;">
                                <i class="fas fa-info-circle"></i> This password has been automatically generated and will be emailed to the employee.
                            </p>
                            <p style="margin: 0;">
                                <i class="fas fa-shield-alt"></i> For security reasons, please ask the employee to change their password after first login.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Form Actions -->
        <div class="form-actions">
            <div>
                <a href="manage-employees.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Employees
                </a>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-redo"></i>
                    Reset Form
                </button>
                
                <?php if ($is_edit && $employee_id): ?>
                        <a href="view-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-eye"></i>
                            View Profile
                        </a>
                <?php endif; ?>
                
                <button type="submit" form="employeeForm" class="btn btn-primary">
                    <i class="fas fa-<?php echo $is_edit ? 'save' : 'user-plus'; ?>"></i>
                    <?php echo $is_edit ? 'Update Employee' : 'Add Employee'; ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Update summary in real-time
        updateSummary();
        
        // Update leave eligibility based on hire date
        updateLeaveEligibility();
        
        // Add real-time validation
        const form = document.getElementById('employeeForm');
        const inputs = form.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                updateSummary();
                
                // Update eligibility when hire date changes
                if (this.name === 'hire_date') {
                    updateLeaveEligibility();
                }
                
                // Remove error styling on input
                if (this.style.borderColor === 'rgb(220, 38, 38)') {
                    this.style.borderColor = '';
                    const errorMsg = this.parentNode.querySelector('.error-message');
                    if (errorMsg) errorMsg.remove();
                }
            });
            
            input.addEventListener('blur', function() {
                validateField(this);
            });
        });
        
        // Leave calculation updates
        const leaveInputs = ['annual_leave_days', 'sick_leave_days', 'emergency_leave_days'];
        leaveInputs.forEach(name => {
            const input = form.querySelector(`[name="${name}"]`);
            if (input) {
                input.addEventListener('input', updateTotalLeaveDays);
            }
        });
        
        // Show generated password if exists
        const passwordField = document.getElementById('passwordField');
        if (passwordField && passwordField.value) {
            document.getElementById('generatedPassword')?.classList.add('show');
        }
        
        // Form submission validation
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                showNotification('Please fix all errors before submitting.', 'error');
            }
        });
        
        // Department suggestions
        const departmentInput = document.getElementById('departmentInput');
        if (departmentInput) {
            departmentInput.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                const datalist = document.getElementById('departmentList');
                const options = Array.from(datalist.options);
                
                // You could add AJAX here to fetch departments from server
                // For now, we'll just use the existing datalist
            });
        }
    });
    
    // Update leave eligibility
    function updateLeaveEligibility() {
        const hireDateInput = document.querySelector('[name="hire_date"]');
        const eligibilityBanner = document.getElementById('eligibilityBanner');
        const eligibilityCheckbox = document.getElementById('leaveEligibleCheckbox');
        
        if (!hireDateInput || !hireDateInput.value) {
            return;
        }
        
        const hireDate = new Date(hireDateInput.value);
        const currentDate = new Date();
        const oneYearAgo = new Date(currentDate);
        oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);
        
        const eligibilityDate = new Date(hireDate);
        eligibilityDate.setFullYear(eligibilityDate.getFullYear() + 1);
        
        const formattedHireDate = hireDate.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
        
        const formattedEligibilityDate = eligibilityDate.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
        
        let eligibilityText = '';
        let isEligible = hireDate <= oneYearAgo;
        
        if (hireDate <= oneYearAgo) {
            eligibilityText = `<span style="color: #B8860B; font-weight: 600;">
                <i class="fas fa-check-circle"></i> ELIGIBLE FOR LEAVE
            </span> - Employee has completed one year of service (hired on ${formattedHireDate}).`;
            
            // Auto-check the eligibility checkbox
            if (eligibilityCheckbox) {
                eligibilityCheckbox.checked = true;
            }
        } else {
            eligibilityText = `<span style="color: #CD853F; font-weight: 600;">
                <i class="fas fa-clock"></i> NOT YET ELIGIBLE
            </span> - Employee will become eligible on ${formattedEligibilityDate}.`;
            
            // Uncheck the eligibility checkbox for new hires
            if (eligibilityCheckbox && !eligibilityCheckbox.hasAttribute('data-manually-checked')) {
                eligibilityCheckbox.checked = false;
            }
        }
        
        if (eligibilityBanner) {
            eligibilityBanner.querySelector('#eligibilityText').innerHTML = eligibilityText;
        }
        
        // Update summary
        const summaryEligible = document.getElementById('summaryEligible');
        if (summaryEligible) {
            summaryEligible.textContent = isEligible ? 'Yes' : 'No';
        }
    }
    
    // Allow manual override of eligibility
    document.addEventListener('DOMContentLoaded', function() {
        const eligibilityCheckbox = document.getElementById('leaveEligibleCheckbox');
        if (eligibilityCheckbox) {
            eligibilityCheckbox.addEventListener('change', function() {
                this.setAttribute('data-manually-checked', this.checked);
                
                // Update summary
                const summaryEligible = document.getElementById('summaryEligible');
                if (summaryEligible) {
                    summaryEligible.textContent = this.checked ? 'Yes' : 'No';
                }
            });
        }
    });
    
    // Summary Update
    function updateSummary() {
        const form = document.getElementById('employeeForm');
        
        // Basic Info
        document.getElementById('summaryName').textContent = 
            form.querySelector('[name="full_name"]')?.value || 'Not provided';
        document.getElementById('summaryEmail').textContent = 
            form.querySelector('[name="email"]')?.value || 'Not provided';
        document.getElementById('summaryPhone').textContent = 
            form.querySelector('[name="phone"]')?.value || 'Not provided';
        
        // Hire Date
        const hireDateInput = form.querySelector('[name="hire_date"]');
        if (hireDateInput && hireDateInput.value) {
            const hireDate = new Date(hireDateInput.value);
            document.getElementById('summaryHireDate').textContent = 
                hireDate.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
        } else {
            document.getElementById('summaryHireDate').textContent = 'Not provided';
        }
        
        // Employment
        document.getElementById('summaryDept').textContent = 
            form.querySelector('[name="department"]')?.value || 'Not assigned';
        document.getElementById('summaryPosition').textContent = 
            form.querySelector('[name="position"]')?.value || 'Not specified';
        
        const statusValue = form.querySelector('[name="status"]')?.value;
        document.getElementById('summaryStatus').textContent = 
            statusValue === 'inactive' ? 'Inactive' : 'Active';
        
        // Leave Allocation
        const annual = parseInt(form.querySelector('[name="annual_leave_days"]')?.value) || 0;
        const sick = parseInt(form.querySelector('[name="sick_leave_days"]')?.value) || 0;
        const emergency = parseInt(form.querySelector('[name="emergency_leave_days"]')?.value) || 0;
        
        document.getElementById('summaryAnnual').textContent = `${annual} days`;
        document.getElementById('summarySick').textContent = `${sick} days`;
        document.getElementById('summaryEmergency').textContent = `${emergency} days`;
        document.getElementById('summaryTotal').textContent = `${annual + sick + emergency} days`;
        
        // Update total leave days in section 4
        document.getElementById('totalLeaveDays').textContent = annual + sick + emergency;
    }
    
    function updateTotalLeaveDays() {
        updateSummary();
    }
    
    // Validation Functions
    function validateField(field) {
        let isValid = true;
        let message = '';
        
        // Clear previous error
        field.style.borderColor = '';
        const errorMsg = field.parentNode.querySelector('.error-message');
        if (errorMsg) errorMsg.remove();
        
        // Validate based on field type
        if (field.hasAttribute('required') && !field.value.trim()) {
            isValid = false;
            message = 'This field is required';
        } else if (field.type === 'email' && field.value.trim()) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(field.value)) {
                isValid = false;
                message = 'Please enter a valid email address';
            }
        } else if (field.name === 'phone' && field.value.trim()) {
            const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
            if (!phoneRegex.test(field.value.replace(/[\s\-\(\)]/g, ''))) {
                isValid = false;
                message = 'Please enter a valid phone number';
            }
        } else if (field.type === 'number' && field.value) {
            const min = parseInt(field.getAttribute('min')) || 0;
            const max = parseInt(field.getAttribute('max')) || Infinity;
            const value = parseInt(field.value);
            
            if (value < min || value > max) {
                isValid = false;
                message = `Value must be between ${min} and ${max}`;
            }
        } else if (field.name === 'hire_date' && field.value) {
            const hireDate = new Date(field.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (hireDate > today) {
                isValid = false;
                message = 'Hire date cannot be in the future';
            }
        }
        
        if (!isValid) {
            field.style.borderColor = '#DC2626';
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.style.color = '#DC2626';
            errorDiv.style.fontSize = '0.85em';
            errorDiv.style.marginTop = '8px';
            errorDiv.style.display = 'flex';
            errorDiv.style.alignItems = 'center';
            errorDiv.style.gap = '5px';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            field.parentNode.appendChild(errorDiv);
            
            // Scroll to error
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        return isValid;
    }
    
    function validateForm() {
        const form = document.getElementById('employeeForm');
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!validateField(field)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    // Reset Form
    function resetForm() {
        Swal.fire({
            title: 'Reset Form?',
            text: 'All entered data will be lost. This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#8B7355',
            confirmButtonText: 'Yes, reset',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('employeeForm').reset();
                updateSummary();
                updateLeaveEligibility();
                showNotification('Form has been reset successfully.', 'success');
            }
        });
    }
    
    // Copy Password
    function copyPassword() {
        const passwordField = document.getElementById('passwordField');
        if (passwordField && passwordField.value) {
            navigator.clipboard.writeText(passwordField.value)
                .then(() => {
                    showNotification('Password copied to clipboard!', 'success');
                    
                    // Visual feedback
                    const copyBtn = document.querySelector('.copy-btn');
                    const originalText = copyBtn.innerHTML;
                    copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    copyBtn.style.background = 'linear-gradient(135deg, #10B981, #059669)';
                    
                    setTimeout(() => {
                        copyBtn.innerHTML = originalText;
                        copyBtn.style.background = 'linear-gradient(135deg, #D4A017, #B8860B)';
                    }, 2000);
                })
                .catch(err => {
                    console.error('Failed to copy: ', err);
                    showNotification('Failed to copy password. Please try again.', 'error');
                });
        }
    }
    
    // Notification System
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        document.querySelectorAll('.custom-notification').forEach(n => n.remove());
        
        // Create notification
        const notification = document.createElement('div');
        notification.className = `custom-notification notification-${type}`;
        notification.innerHTML = `
            <div style="
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? 'linear-gradient(135deg, #D4A017, #B8860B)' : 
                           type === 'error' ? 'linear-gradient(135deg, #DC2626, #B91C1C)' : 
                           'linear-gradient(135deg, #3B82F6, #2563EB)'};
                color: white;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.15);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 12px;
                max-width: 350px;
                animation: slideInRight 0.3s ease-out;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255,255,255,0.2);
            ">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                             type === 'error' ? 'fa-exclamation-circle' : 
                             'fa-info-circle'}" 
                   style="font-size: 1.3em;"></i>
                <div style="font-size: 0.95em;">
                    <strong style="display: block; margin-bottom: 3px;">
                        ${type === 'success' ? 'Success!' : 
                          type === 'error' ? 'Error!' : 
                          'Notice'}
                    </strong>
                    <span>${message}</span>
                </div>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
        
        // Add animation styles
        if (!document.getElementById('notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }
</script>

<?php
// Function to send welcome email
function sendWelcomeEmail($to_email, $employee_name, $password, $login_email)
{
    $subject = "Welcome to Leave Management System";

    $body = "Dear {$employee_name},\n\n";
    $body .= "Your account has been created in the Leave Management System.\n\n";
    $body .= "Here are your login credentials:\n";
    $body .= "Email: {$login_email}\n";
    $body .= "Password: {$password}\n\n";
    $body .= "Please log in at: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/login.php\n\n";
    $body .= "For security reasons, we recommend changing your password after your first login.\n\n";
    $body .= "Best regards,\n";
    $body .= "HR Department\n";

    $headers = "From: noreply@yourcompany.com\r\n";
    $headers .= "Reply-To: hr@yourcompany.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return mail($to_email, $subject, $body, $headers);
}

require_once __DIR__ . '/includes/footer.php';
?>