<?php
// includes/auth.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once __DIR__ . '/../config/database.php';

/**
 * Check if user is logged in
 * Supports both old and new session variable names for compatibility
 */
function isLoggedIn()
{
    return isset($_SESSION['user_id']) &&
        (isset($_SESSION['user_role']) || isset($_SESSION['role']));
}

/**
 * Get current user role with fallback support
 */
function getCurrentUserRole()
{
    if (isset($_SESSION['user_role'])) {
        return $_SESSION['user_role'];
    } elseif (isset($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    return null;
}

/**
 * Check if user has admin/CEO access
 */
function requireAdminAccess()
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ../login.php?error=Please log in first');
        exit();
    }

    $user_role = getCurrentUserRole();
    $allowed_roles = ['admin', 'ceo'];

    if (!$user_role || !in_array($user_role, $allowed_roles)) {
        header('Location: ../login.php?error=Access denied. Admin/CEO access required.');
        exit();
    }
}

/**
 * Check if user has specific role
 */
function hasRole($role)
{
    $user_role = getCurrentUserRole();
    return $user_role === $role;
}

/**
 * Check if user has any of the specified roles
 */
function hasAnyRole($roles)
{
    $user_role = getCurrentUserRole();
    return in_array($user_role, $roles);
}

/**
 * Get current user info
 */
function getCurrentUser()
{
    if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
        return null;
    }

    $pdo = getPDOConnection();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting current user: " . $e->getMessage());
        return null;
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId()
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user email
 */
function getCurrentUserEmail()
{
    if (isset($_SESSION['user_email'])) {
        return $_SESSION['user_email'];
    } elseif (isset($_SESSION['email'])) {
        return $_SESSION['email'];
    }
    return null;
}

/**
 * Get current user name
 */
function getCurrentUserName()
{
    if (isset($_SESSION['user_name'])) {
        return $_SESSION['user_name'];
    } elseif (isset($_SESSION['full_name'])) {
        return $_SESSION['full_name'];
    }
    return null;
}

/**
 * Login function with password verification
 */
function loginUser($email, $password)
{
    $pdo = getPDOConnection();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    try {
        // Find user by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }

        // Verify password
        $password_valid = false;

        // Method 1: Check if password is hashed
        if (password_verify($password, $user['password'])) {
            $password_valid = true;
        }
        // Method 2: Check demo passwords (for testing only)
        elseif (in_array($email, ['admin@company.com', 'ceo@company.com', 'employee@company.com'])) {
            $demo_passwords = [
                'admin@company.com' => 'admin123',
                'ceo@company.com' => 'ceo123',
                'employee@company.com' => 'employee123'
            ];

            if (isset($demo_passwords[$email]) && $password === $demo_passwords[$email]) {
                $password_valid = true;

                // Optionally hash the demo password for future logins
                // $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                // $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                // $update_stmt->execute([$hashed_password, $email]);
            }
        }

        if (!$password_valid) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }

        // Set session variables - MUST MATCH login.php
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['department'] = $user['department'] ?? '';
        $_SESSION['position'] = $user['position'] ?? '';
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Also set the old variables for compatibility if needed
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        // Log login activity
        logActivity($user['id'], 'user_login', 'users', $user['id'], null, json_encode([
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]));

        return ['success' => true, 'message' => 'Login successful', 'role' => $user['role']];

    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return ['success' => false, 'message' => 'System error. Please try again.'];
    }
}

/**
 * Logout function
 */
function logoutUser()
{
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'], 'user_logout', 'users', $_SESSION['user_id']);
    }

    // Clear session
    $_SESSION = [];

    // Destroy session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    // Redirect to login
    header('Location: ../login.php?success=Logged out successfully');
    exit();
}

/**
 * Check if user needs to change password
 */
function requiresPasswordChange()
{
    if (!isLoggedIn()) {
        return false;
    }

    $pdo = getPDOConnection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT password_change_required FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['password_change_required'] == 1;
    } catch (PDOException $e) {
        error_log("Error checking password change requirement: " . $e->getMessage());
        return false;
    }
}

/**
 * Log activity - MAIN FUNCTION
 */
function logActivity($user_id, $action, $table_name = null, $record_id = null, $old_value = null, $new_value = null)
{
    $pdo = getPDOConnection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $user_id,
            $action,
            $table_name,
            $record_id,
            $old_value,
            $new_value,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Log user activity with current user ID (alias function)
 */
function logUserActivity($action, $table_name = null, $record_id = null, $old_value = null, $new_value = null)
{
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        return logActivity($user_id, $action, $table_name, $record_id, $old_value, $new_value);
    }
    return false;
}

/**
 * Get dashboard statistics
 */
function getDashboardStats()
{
    $pdo = getPDOConnection();
    if (!$pdo) {
        return [];
    }

    try {
        $stats = [];
        $current_year = date('Y');

        // Total active users
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
        $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Pending leaves
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
        $stats['pending_leaves'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Approved leaves this month
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM leaves 
            WHERE status = 'approved' 
            AND MONTH(created_at) = MONTH(CURRENT_DATE())
            AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute();
        $stats['approved_this_month'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Leaves on leave today
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT l.user_id) as count 
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            WHERE l.status = 'approved'
            AND ? BETWEEN l.start_date AND l.end_date
            AND u.status = 'active'
        ");
        $stmt->execute([$today]);
        $stats['on_leave_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Total leave days this year
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0) as total 
            FROM leaves 
            WHERE status = 'approved'
            AND YEAR(created_at) = ?
        ");
        $stmt->execute([$current_year]);
        $stats['total_leave_days'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Return statistics with default values
        return array_merge([
            'total_users' => 0,
            'pending_leaves' => 0,
            'approved_this_month' => 0,
            'on_leave_today' => 0,
            'total_leave_days' => 0
        ], $stats);

    } catch (PDOException $e) {
        error_log("Error getting dashboard stats: " . $e->getMessage());
        return [
            'total_users' => 0,
            'pending_leaves' => 0,
            'approved_this_month' => 0,
            'on_leave_today' => 0,
            'total_leave_days' => 0
        ];
    }
}

/**
 * Check if user can access a specific page based on role
 */
function checkPageAccess($allowed_roles)
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ../login.php?error=Please log in to access this page');
        exit();
    }

    $user_role = getCurrentUserRole();

    if (!$user_role || !in_array($user_role, $allowed_roles)) {
        header('Location: ../unauthorized.php');
        exit();
    }
}

/**
 * Redirect if not logged in
 */
function requireLogin()
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ../login.php?error=Please log in to access this page');
        exit();
    }
}

/**
 * Redirect if already logged in
 */
function redirectIfLoggedIn($redirect_to = 'dashboard.php')
{
    if (isLoggedIn()) {
        header('Location: ' . $redirect_to);
        exit();
    }
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Check if user's session is expired
 */
function isSessionExpired($timeout_minutes = 60)
{
    if (!isset($_SESSION['login_time'])) {
        return true;
    }

    $session_lifetime = time() - $_SESSION['login_time'];
    return $session_lifetime > ($timeout_minutes * 60);
}

/**
 * Update session activity time
 */
function updateSessionActivity()
{
    $_SESSION['last_activity'] = time();
}

/**
 * Check if user is idle
 */
function isUserIdle($idle_minutes = 15)
{
    if (!isset($_SESSION['last_activity'])) {
        return true;
    }

    $idle_time = time() - $_SESSION['last_activity'];
    return $idle_time > ($idle_minutes * 60);
}

/**
 * Get user's department
 */
function getUserDepartment()
{
    if (isset($_SESSION['department'])) {
        return $_SESSION['department'];
    }

    $user = getCurrentUser();
    return $user['department'] ?? null;
}

/**
 * Get user's position
 */
function getUserPosition()
{
    if (isset($_SESSION['position'])) {
        return $_SESSION['position'];
    }

    $user = getCurrentUser();
    return $user['position'] ?? null;
}

/**
 * Check if user can approve leaves
 */
function canApproveLeaves()
{
    $user_role = getCurrentUserRole();
    return in_array($user_role, ['admin', 'ceo', 'manager']);
}

/**
 * Check if user can manage users
 */
function canManageUsers()
{
    $user_role = getCurrentUserRole();
    return in_array($user_role, ['admin']);
}

/**
 * Check if user can view reports
 */
function canViewReports()
{
    $user_role = getCurrentUserRole();
    return in_array($user_role, ['admin', 'ceo', 'manager']);
}

/**
 * Get user permissions array
 */
function getUserPermissions()
{
    $role = getCurrentUserRole();

    $permissions = [
        'view_dashboard' => true,
        'apply_leave' => true,
        'view_own_leaves' => true,
        'edit_own_profile' => true,
    ];

    switch ($role) {
        case 'admin':
            $permissions['manage_users'] = true;
            $permissions['approve_leaves'] = true;
            $permissions['view_all_leaves'] = true;
            $permissions['manage_leave_types'] = true;
            $permissions['view_reports'] = true;
            $permissions['manage_settings'] = true;
            break;

        case 'ceo':
            $permissions['approve_leaves'] = true;
            $permissions['view_all_leaves'] = true;
            $permissions['view_reports'] = true;
            break;

        case 'manager':
            $permissions['approve_leaves'] = true;
            $permissions['view_team_leaves'] = true;
            $permissions['view_reports'] = true;
            break;

        case 'employee':
        default:
            // Default permissions already set
            break;
    }

    return $permissions;
}

/**
 * Check if user has specific permission
 */
function hasPermission($permission)
{
    $permissions = getUserPermissions();
    return isset($permissions[$permission]) && $permissions[$permission];
}

/**
 * Get login history for current user
 */
function getLoginHistory($limit = 10)
{
    $pdo = getPDOConnection();
    if (!$pdo || !isLoggedIn()) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs 
            WHERE user_id = ? 
            AND action = 'user_login'
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$_SESSION['user_id'], $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting login history: " . $e->getMessage());
        return [];
    }
}

/**
 * Update user's last activity
 */
function updateLastActivity()
{
    if (!isLoggedIn()) {
        return;
    }

    $pdo = getPDOConnection();
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        error_log("Error updating last activity: " . $e->getMessage());
    }
}

/**
 * Clean up expired sessions
 */
function cleanupExpiredSessions($expiry_hours = 24)
{
    $pdo = getPDOConnection();
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM sessions 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$expiry_hours]);
    } catch (PDOException $e) {
        error_log("Error cleaning up sessions: " . $e->getMessage());
    }
}

// Initialize last activity time
if (isLoggedIn() && !isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Check for session timeout
if (isLoggedIn() && isSessionExpired()) {
    logoutUser();
}

// Check for idle timeout
if (isLoggedIn() && isUserIdle()) {
    updateSessionActivity();
}
?>