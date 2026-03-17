<?php
// includes/header.php
session_start();

// Include database configuration only once
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config/database.php';
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Timezone
date_default_timezone_set('Asia/Karachi');

// Initialize user data
$current_user = [
    'id' => $_SESSION['user_id'],
    'full_name' => $_SESSION['full_name'] ?? 'User',
    'email' => $_SESSION['email'] ?? '',
    'role' => $_SESSION['user_role'] ?? 'employee',
    'department' => $_SESSION['department'] ?? '',
    'last_login' => $_SESSION['login_time'] ?? date('Y-m-d H:i:s')
];

// Page title - set default if not defined
if (!isset($page_title)) {
    $page_title = "Leave Management System";
}

// Get database connection for use in the header
$pdo = getPDOConnection();

// Get counts for badges
$pending_count = 0;
$pending_forms = 0;
$new_employees_count = 0;
$unread_notifications = 0;

if ($pdo) {
    try {
        // Get pending leave count
        $stmt = executeQuery("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
        if ($stmt) {
            $result = $stmt->fetch();
            $pending_count = $result['count'];
        }

        // Get pending form count
        $stmt = executeQuery("SELECT COUNT(*) as count FROM google_form_submissions WHERE processed = 0");
        if ($stmt) {
            $result = $stmt->fetch();
            $pending_forms = $result['count'];
        }

        // Get new employees (created in last 7 days)
        $stmt = executeQuery("SELECT COUNT(*) as count FROM users WHERE status = 'active' AND DATEDIFF(CURDATE(), created_at) <= 7");
        if ($stmt) {
            $result = $stmt->fetch();
            $new_employees_count = $result['count'];
        }

        // Get unread notifications count
        $stmt = executeQuery("SELECT COUNT(*) as count FROM notification_logs WHERE user_id = ? AND status = 'pending'");
        if ($stmt) {
            $stmt->execute([$current_user['id']]);
            $result = $stmt->fetch();
            $unread_notifications = $result['count'];
        }

    } catch (PDOException $e) {
        error_log("Error fetching counts: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Leave Management System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap"
        rel="stylesheet">

    <style>
        /* ===== BASE STYLES ===== */
        :root {
            /* Updated Ocher Color Scheme */
            --color-primary: #D4A017;
            --color-primary-dark: #B8860B;
            --color-primary-light: #FFD700;
            --color-secondary: #8B7355;
            --color-success: #556B2F;
            --color-danger: #8B4513;
            --color-warning: #CD853F;
            --color-info: #B0C4DE;
            --color-text: #2F2F2F;
            --color-light-gray: #F8F8F8;
            --color-dark-gray: #696969;
            --color-white: #FFFFFF;
            --color-border: #D4A017;
            --color-bg: #FFFFFF;
            --color-sidebar: #FFFFFF;
            --color-sidebar-hover: rgba(212, 160, 23, 0.08);
            --color-sidebar-active: rgba(212, 160, 23, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #FFFFFF;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
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

        a {
            text-decoration: none;
            color: inherit;
        }

        button {
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            border: none;
            background: none;
        }

        ul {
            list-style: none;
        }

        /* ===== MAIN LAYOUT ===== */
        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===== SIDEBAR - IMPROVED DESIGN ===== */
        .sidebar {
            width: 280px;
            background: var(--color-sidebar);
            border-right: 1px solid rgba(212, 160, 23, 0.1);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(139, 115, 85, 0.08);
        }

        .sidebar-header {
            padding: 25px;
            border-bottom: 1px solid rgba(212, 160, 23, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--color-sidebar);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3em;
            box-shadow: 0 4px 12px rgba(212, 160, 23, 0.25);
            transition: all 0.3s ease;
        }

        .logo:hover .logo-icon {
            transform: scale(1.05);
            box-shadow: 0 6px 18px rgba(212, 160, 23, 0.35);
        }

        .logo-text {
            font-family: 'Playfair Display', serif;
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 0.5px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .nav-section {
            margin-bottom: 25px;
        }

        .nav-title {
            font-size: 0.75em;
            color: #8B7355;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            padding: 0 25px 12px;
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(212, 160, 23, 0.1);
        }

        .nav-menu {
            padding: 0 15px;
        }

        .nav-item {
            margin-bottom: 6px;
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            color: var(--color-text);
            font-weight: 500;
            font-size: 0.9em;
            transition: all 0.3s ease;
            position: relative;
            border: 1px solid transparent;
            background: transparent;
        }

        .nav-link:hover {
            background: var(--color-sidebar-hover);
            color: var(--color-primary-dark);
            border-color: rgba(212, 160, 23, 0.2);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: var(--color-sidebar-active);
            color: var(--color-primary-dark);
            border: 1px solid rgba(212, 160, 23, 0.2);
            font-weight: 600;
            position: relative;
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: -15px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: linear-gradient(180deg, var(--color-primary), var(--color-primary-dark));
            border-radius: 0 2px 2px 0;
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em;
            color: var(--color-secondary);
            transition: all 0.3s ease;
        }

        .nav-link.active i {
            color: var(--color-primary);
        }

        .nav-link:hover i {
            color: var(--color-primary);
            transform: scale(1.1);
        }

        .nav-badge {
            margin-left: auto;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white;
            font-size: 0.65em;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(212, 160, 23, 0.2);
            transition: all 0.3s ease;
        }

        .nav-link:hover .nav-badge {
            transform: scale(1.1);
        }

        .nav-badge.warning {
            background: linear-gradient(135deg, #CD853F, #8B7355);
        }

        .nav-badge.danger {
            background: linear-gradient(135deg, #8B4513, #A0522D);
        }

        .nav-badge.info {
            background: linear-gradient(135deg, #4285F4, #34A853);
        }

        /* ===== IMPROVED DROPDOWN MENUS ===== */
        .nav-item.has-submenu {
            position: relative;
        }

        .nav-item.has-submenu .nav-link {
            cursor: pointer;
        }

        .nav-item.has-submenu .nav-link::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: all 0.3s ease;
            color: var(--color-secondary);
            font-size: 0.9em;
        }

        .nav-item.has-submenu.active .nav-link::after {
            transform: rotate(180deg);
            color: var(--color-primary);
        }

        .nav-submenu {
            margin-top: 5px;
            display: none;
            padding: 0;
            border-left: 2px solid rgba(212, 160, 23, 0.1);
            margin-left: 30px;
            animation: slideDown 0.3s ease-out;
        }

        .nav-submenu.active {
            display: block;
        }

        .nav-subitem {
            margin-bottom: 4px;
        }

        .nav-sublink {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px 12px 30px;
            border-radius: 10px;
            color: var(--color-text);
            font-weight: 500;
            font-size: 0.85em;
            transition: all 0.3s ease;
            background: transparent;
            border: 1px solid transparent;
        }

        .nav-sublink:hover {
            background: var(--color-sidebar-hover);
            color: var(--color-primary-dark);
            border-color: rgba(212, 160, 23, 0.2);
            transform: translateX(5px);
        }

        .nav-sublink i {
            width: 16px;
            text-align: center;
            font-size: 0.9em;
            color: var(--color-secondary);
        }

        .nav-sublink.active {
            background: var(--color-sidebar-active);
            color: var(--color-primary-dark);
            font-weight: 600;
            border-color: rgba(212, 160, 23, 0.2);
        }

        .nav-sublink.active i {
            color: var(--color-primary);
        }

        .nav-sublink.active::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 6px;
            background: var(--color-primary);
            border-radius: 50%;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== SIDEBAR FOOTER ===== */
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(212, 160, 23, 0.1);
            background: var(--color-sidebar);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(212, 160, 23, 0.05);
            border: 1px solid rgba(212, 160, 23, 0.1);
            transition: all 0.3s ease;
        }

        .user-profile:hover {
            background: rgba(212, 160, 23, 0.08);
            border-color: rgba(212, 160, 23, 0.2);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1em;
            flex-shrink: 0;
            box-shadow: 0 4px 8px rgba(212, 160, 23, 0.2);
            transition: all 0.3s ease;
        }

        .user-profile:hover .user-avatar {
            transform: scale(1.05);
            box-shadow: 0 6px 12px rgba(212, 160, 23, 0.3);
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9em;
            color: var(--color-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.8em;
            color: var(--color-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .logout-btn {
            color: var(--color-danger);
            font-size: 1.1em;
            padding: 8px;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: rgba(139, 69, 19, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-btn:hover {
            background: rgba(139, 69, 19, 0.2);
            transform: translateY(-2px);
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: transparent;
            position: relative;
        }

        /* ===== TOP HEADER - UPDATED STYLE ===== */
        .top-header {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: var(--color-white);
            border-bottom: 1px solid rgba(212, 160, 23, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            z-index: 900;
            box-shadow: 0 2px 10px rgba(139, 115, 85, 0.05);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.5em;
            font-weight: 700;
            color: var(--color-text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--color-primary);
            font-size: 1.2em;
        }

        .mobile-menu-btn {
            display: none;
            font-size: 1.3em;
            color: var(--color-primary-dark);
            padding: 10px;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: rgba(212, 160, 23, 0.1);
            border: 1px solid rgba(212, 160, 23, 0.2);
        }

        .mobile-menu-btn:hover {
            background: rgba(212, 160, 23, 0.2);
            transform: translateY(-2px);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Header Actions */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .action-btn {
            position: relative;
            padding: 10px;
            border-radius: 10px;
            color: var(--color-secondary);
            transition: all 0.3s ease;
            background: rgba(212, 160, 23, 0.08);
            border: 1px solid rgba(212, 160, 23, 0.1);
        }

        .action-btn:hover {
            background: rgba(212, 160, 23, 0.15);
            color: var(--color-primary);
            border-color: rgba(212, 160, 23, 0.3);
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 1.1em;
        }

        .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #8B4513, #A0522D);
            color: white;
            font-size: 0.7em;
            padding: 3px 7px;
            border-radius: 10px;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .time-display,
        .date-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(212, 160, 23, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(212, 160, 23, 0.1);
            font-size: 0.9em;
            color: var(--color-text);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .time-display:hover,
        .date-display:hover {
            background: rgba(212, 160, 23, 0.08);
            border-color: rgba(212, 160, 23, 0.2);
        }

        .time-display i,
        .date-display i {
            color: var(--color-primary);
        }

        /* ===== CONTENT AREA ===== */
        .content-area {
            padding: 30px;
            padding-top: calc(70px + 20px);
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .sidebar {
                transform: translateX(-100%);
                box-shadow: 0 0 30px rgba(0, 0, 0, 0.15);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 0 20px;
                height: 60px;
            }

            .content-area {
                padding: 20px;
                padding-top: calc(60px + 20px);
            }

            .page-title {
                font-size: 1.3em;
            }

            .sidebar {
                width: 260px;
            }

            .header-actions {
                gap: 10px;
            }

            .action-btn {
                padding: 8px;
            }

            .time-display,
            .date-display {
                padding: 6px 12px;
                font-size: 0.85em;
            }
        }

        @media (max-width: 576px) {
            .header-right {
                gap: 10px;
            }

            .header-actions {
                gap: 8px;
            }

            .nav-link {
                padding: 12px 16px;
                font-size: 0.85em;
            }

            .nav-title {
                padding: 0 20px 10px;
            }

            .time-display span,
            .date-display span {
                display: none;
            }

            .time-display,
            .date-display {
                padding: 8px;
            }

            .time-display i,
            .date-display i {
                margin: 0;
            }
        }

        /* Overlay for mobile menu */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
            backdrop-filter: blur(3px);
        }

        .overlay.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* ===== UTILITY CLASSES ===== */
        .hidden {
            display: none !important;
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .gap-2 {
            gap: 8px;
        }

        .gap-4 {
            gap: 16px;
        }

        /* Smooth scroll for sidebar */
        .sidebar-nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(212, 160, 23, 0.3) transparent;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background-color: rgba(212, 160, 23, 0.3);
            border-radius: 3px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background-color: rgba(212, 160, 23, 0.5);
        }

        /* Active state glow effect */
        .nav-link.active {
            box-shadow: 0 2px 8px rgba(212, 160, 23, 0.15);
        }

        /* Hover effect for submenu items */
        .nav-sublink:hover i {
            transform: translateX(3px);
            transition: transform 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="logo-text">Lota</div>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-title">Main Navigation</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php"
                                class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                                <i class="fas fa-tachometer-alt"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="apply-leave-for-employee.php"
                                class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'apply-leave-for-employee.php' ? 'active' : ''; ?>">
                                <i class="fas fa-plus-circle"></i>
                                <span>Apply Leave</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="leaves.php"
                                class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leaves.php' ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-check"></i>
                                <span>Leaves</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="calendar.php"
                                class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'calendar.php' ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Calendar</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <?php if ($current_user['role'] == 'admin' || $current_user['role'] == 'ceo'): ?>
                    <div class="nav-section">
                        <div class="nav-title">Administration</div>
                        <ul class="nav-menu">
                            <li class="nav-item">
                                <a href="leave-approvals.php"
                                    class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leave-approvals.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Leave Approvals</span>
                                    <?php if ($pending_count > 0): ?>
                                        <span class="nav-badge danger"><?php echo $pending_count; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>

                            <li
                                class="nav-item has-submenu <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-employees.php', 'add-employee.php', 'view-employee.php', 'employee-leaves.php', 'apply-leave-for-employee.php']) ? 'active' : ''; ?>">
                                <a href="#" class="nav-link">
                                    <i class="fas fa-users"></i>
                                    <span>Employee Management</span>
                                    <?php if ($new_employees_count > 0): ?>
                                        <span class="nav-badge info"><?php echo $new_employees_count; ?> new</span>
                                    <?php endif; ?>
                                </a>
                                <ul
                                    class="nav-submenu <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-employees.php', 'add-employee.php', 'view-employee.php', 'employee-leaves.php', 'apply-leave-for-employee.php']) ? 'active' : ''; ?>">
                                    <li class="nav-subitem">
                                        <a href="manage-employees.php"
                                            class="nav-sublink <?php echo basename($_SERVER['PHP_SELF']) == 'manage-employees.php' ? 'active' : ''; ?>">
                                            <i class="fas fa-list"></i>
                                            <span>All Employees</span>
                                        </a>
                                    </li>
                                    <li class="nav-subitem">
                                        <a href="add-employee.php"
                                            class="nav-sublink <?php echo basename($_SERVER['PHP_SELF']) == 'add-employee.php' ? 'active' : ''; ?>">
                                            <i class="fas fa-user-plus"></i>
                                            <span>Add New Employee</span>
                                        </a>
                                    </li>
                                    <li class="nav-subitem">
                                        <a href="apply-leave-for-employee.php"
                                            class="nav-sublink <?php echo basename($_SERVER['PHP_SELF']) == 'apply-leave-for-employee.php' ? 'active' : ''; ?>">
                                            <i class="fas fa-calendar-plus"></i>
                                            <span>Apply Leave for Employee</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <li class="nav-item">
                                <a href="reports.php"
                                    class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-pie"></i>
                                    <span>Reports & Analytics</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="google-forms.php"
                                    class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'google-forms.php' ? 'active' : ''; ?>">
                                    <i class="fab fa-google"></i>
                                    <span>Google Forms</span>
                                    <?php if ($pending_forms > 0): ?>
                                        <span class="nav-badge warning"><?php echo $pending_forms; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-title">Settings</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="profile.php"
                                class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                                <i class="fas fa-user-circle"></i>
                                <span>My Profile</span>
                            </a>
                        </li>
                        <!-- In header.php sidebar section -->
<li class="nav-item">
    <a href="leave-forms.php"
        class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leave-forms.php' ? 'active' : ''; ?>">
                                <i class="fas fa-file-alt"></i>
                                <span>Leave Forms</span>
                                <?php if ($unread_notifications > 0): ?>
                                    <span class="nav-badge danger"><?php echo $unread_notifications; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php if ($current_user['role'] == 'admin' || $current_user['role'] == 'ceo'): ?>
                            <li class="nav-item">
                                <a href="system-settings.php"
                                    class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'system-settings.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-cog"></i>
                                    <span>System Settings</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                        <div class="user-role">
                            <?php
                            $role_display = [
                                'admin' => 'Administrator',
                                'ceo' => 'CEO',
                                'employee' => 'Employee'
                            ];
                            echo $role_display[$current_user['role']] ?? ucfirst($current_user['role']);
                            ?>
                            <?php if ($current_user['department']): ?>
                                • <?php echo htmlspecialchars($current_user['department']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Overlay for mobile menu -->
        <div class="overlay" id="overlay"></div>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Header -->
            <header class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">
                        <?php
                        // Set appropriate icon based on page
                        $page_icons = [
                            'dashboard.php' => 'fas fa-tachometer-alt',
                            'apply-leave.php' => 'fas fa-plus-circle',
                            'leaves.php' => 'fas fa-calendar-check',
                            'calendar.php' => 'fas fa-calendar-alt',
                            'leave-approvals.php' => 'fas fa-check-circle',
                            'manage-employees.php' => 'fas fa-users',
                            'add-employee.php' => 'fas fa-user-plus',
                            'view-employee.php' => 'fas fa-user',
                            'apply-leave-for-employee.php' => 'fas fa-calendar-plus',
                            'reports.php' => 'fas fa-chart-pie',
                            'google-forms.php' => 'fab fa-google',
                            'profile.php' => 'fas fa-user-circle',
                            'notifications.php' => 'fas fa-bell',
                            'system-settings.php' => 'fas fa-cog'
                        ];
                        $current_page = basename($_SERVER['PHP_SELF']);
                        $page_icon = $page_icons[$current_page] ?? 'fas fa-tachometer-alt';
                        ?>
                        <i class="<?php echo $page_icon; ?>"></i>
                        <?php echo htmlspecialchars($page_title); ?>
                    </h1>
                </div>

                <div class="header-right">
                    <div class="header-actions">
                        <a href="notifications.php" class="action-btn" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span class="badge"><?php echo $unread_notifications; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="time-display" title="Current Time">
                            <i class="far fa-clock"></i>
                            <span><?php echo date('h:i A'); ?></span>
                        </div>
                        <div class="date-display" title="Today's Date">
                            <i class="far fa-calendar"></i>
                            <span><?php echo date('M j, Y'); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <div class="content-area">
                <!-- Content will be inserted here by individual pages -->

                <script>
                    // Mobile menu toggle
                    document.addEventListener('DOMContentLoaded', function () {
                        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
                        const sidebar = document.getElementById('sidebar');
                        const overlay = document.getElementById('overlay');

                        // Toggle sidebar on mobile
                        mobileMenuBtn.addEventListener('click', function () {
                            sidebar.classList.toggle('active');
                            overlay.classList.toggle('active');
                            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                        });

                        // Close sidebar when clicking overlay
                        overlay.addEventListener('click', function () {
                            sidebar.classList.remove('active');
                            overlay.classList.remove('active');
                            document.body.style.overflow = '';
                        });

                        // Close sidebar when clicking on a link (on mobile)
                        const navLinks = document.querySelectorAll('.nav-link, .nav-sublink');
                        navLinks.forEach(link => {
                            link.addEventListener('click', function () {
                                if (window.innerWidth <= 1200) {
                                    sidebar.classList.remove('active');
                                    overlay.classList.remove('active');
                                    document.body.style.overflow = '';
                                }
                            });
                        });

                        // Improved submenu toggle functionality
                        const submenuItems = document.querySelectorAll('.nav-item.has-submenu');
                        submenuItems.forEach(item => {
                            const link = item.querySelector('.nav-link');

                            link.addEventListener('click', function (e) {
                                if (window.innerWidth > 1200) {
                                    e.preventDefault();
                                    e.stopPropagation();

                                    // Close other submenus
                                    submenuItems.forEach(otherItem => {
                                        if (otherItem !== item && otherItem.classList.contains('active')) {
                                            otherItem.classList.remove('active');
                                            const otherSubmenu = otherItem.querySelector('.nav-submenu');
                                            if (otherSubmenu) {
                                                otherSubmenu.classList.remove('active');
                                            }
                                        }
                                    });

                                    // Toggle current submenu
                                    item.classList.toggle('active');
                                    const submenu = item.querySelector('.nav-submenu');
                                    if (submenu) {
                                        submenu.classList.toggle('active');
                                    }
                                }
                            });
                        });

                        // Close submenus when clicking outside
                        document.addEventListener('click', function (e) {
                            if (!e.target.closest('.nav-item.has-submenu')) {
                                submenuItems.forEach(item => {
                                    item.classList.remove('active');
                                    const submenu = item.querySelector('.nav-submenu');
                                    if (submenu) {
                                        submenu.classList.remove('active');
                                    }
                                });
                            }
                        });

                        // Auto-close submenus on mobile
                        window.addEventListener('resize', function () {
                            if (window.innerWidth <= 1200) {
                                submenuItems.forEach(item => {
                                    item.classList.remove('active');
                                    const submenu = item.querySelector('.nav-submenu');
                                    if (submenu) {
                                        submenu.classList.remove('active');
                                    }
                                });
                            }
                        });

                        // Set current page highlight
                        const currentPage = window.location.pathname.split('/').pop();
                        const navLinksAll = document.querySelectorAll('.nav-link, .nav-sublink');

                        navLinksAll.forEach(link => {
                            const href = link.getAttribute('href');
                            if (href === currentPage || (currentPage === '' && href === 'dashboard.php')) {
                                link.classList.add('active');

                                // Also activate parent submenu if this is a sublink
                                if (link.classList.contains('nav-sublink')) {
                                    const parentItem = link.closest('.nav-item.has-submenu');
                                    if (parentItem) {
                                        parentItem.classList.add('active');
                                        const submenu = parentItem.querySelector('.nav-submenu');
                                        if (submenu) {
                                            submenu.classList.add('active');
                                        }
                                    }
                                }
                            }
                        });

                        // Auto-refresh page every 5 minutes for dashboard
                        if (currentPage === 'dashboard.php') {
                            setTimeout(() => {
                                location.reload();
                            }, 300000); // 5 minutes
                        }
                    });
                </script>