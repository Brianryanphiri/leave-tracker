<?php
// login.php - WITH REDIRECT DEBUGGING
// Start session FIRST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database configuration AFTER session start
require_once __DIR__ . '/config/database.php';

// Debug: Check if already logged in
if (isset($_SESSION['user_id'])) {
    error_log("DEBUG: User already logged in. User ID: " . $_SESSION['user_id']);
    error_log("DEBUG: Session data: " . print_r($_SESSION, true));

    $user_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
    if ($user_role) {
        $allowed_roles = ['admin', 'ceo', 'employee', 'manager'];
        if (in_array($user_role, $allowed_roles)) {
            error_log("DEBUG: Redirecting to dashboard from session check");
            header('Location: dashboard.php');
            exit();
        }
    }
}

// Initialize variables
$error = '';
$success = '';
$email = '';
$remember_email = false;

// Check for error in URL parameter
if (isset($_GET['error']) && !empty(trim($_GET['error']))) {
    $error = trim(urldecode($_GET['error']));
}

// Check for success message in URL parameter
if (isset($_GET['success']) && !empty(trim($_GET['success']))) {
    $success = trim(urldecode($_GET['success']));
}

// Check if remember cookie exists
if (isset($_COOKIE['remember_email'])) {
    $email = $_COOKIE['remember_email'];
    $remember_email = true;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']) ? true : false;

    error_log("=== LOGIN ATTEMPT ===");
    error_log("Email: $email");

    // Basic validation
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        try {
            // Get PDO connection
            $pdo = getPDOConnection();

            if (!$pdo) {
                throw new Exception('Database connection failed');
            }

            // Prepare statement
            $stmt = $pdo->prepare("SELECT id, full_name, email, password, role, status, department, position FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                error_log("User found: " . $user['email'] . ", Role: " . $user['role']);

                // Check if account is active
                if ($user['status'] !== 'active') {
                    $error = 'Your account is not active. Please contact administrator.';
                } else {
                    // Check password
                    $password_valid = false;

                    // Method 1: Check if password is hashed
                    if (password_verify($password, $user['password'])) {
                        $password_valid = true;
                        error_log("Password verified via password_verify");
                    }
                    // Method 2: Check plain text match
                    elseif ($password === $user['password']) {
                        $password_valid = true;
                        error_log("Password matched as plain text");

                        // Auto-hash plain text passwords
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                        $update_stmt->execute([$hashed_password, $email]);
                    }

                    if ($password_valid) {
                        error_log("✅ PASSWORD VALID - Setting session");

                        // Regenerate session ID for security
                        session_regenerate_id(true);

                        // Set ALL session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['full_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['department'] = $user['department'] ?? '';
                        $_SESSION['position'] = $user['position'] ?? '';
                        $_SESSION['login_time'] = time();
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                        // For backward compatibility
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];

                        error_log("Session set. Session data: " . print_r($_SESSION, true));

                        // Set remember me cookie if checked
                        if ($remember) {
                            setcookie('remember_email', $email, time() + (30 * 24 * 60 * 60), "/", "", false, true);
                        } else {
                            // Clear remember cookie if not checked
                            setcookie('remember_email', '', time() - 3600, "/");
                        }

                        // Update last login
                        $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $update_stmt->execute([$user['id']]);

                        error_log("✅ LOGIN SUCCESSFUL - Redirecting...");

                        // TEST: First check if we can output something
                        error_log("Testing redirect for role: " . $user['role']);

                        // Determine redirect URL
                        $redirect_url = 'dashboard.php';
                        if ($user['role'] === 'employee') {
                            $redirect_url = 'employee-dashboard.php';
                        } elseif ($user['role'] === 'manager') {
                            $redirect_url = 'manager-dashboard.php';
                        }

                        error_log("Redirect URL determined: $redirect_url");

                        // Check if file exists
                        if (!file_exists($redirect_url)) {
                            error_log("❌ ERROR: Redirect file does not exist: $redirect_url");
                            $error = 'System configuration error. Please contact administrator.';
                        } else {
                            error_log("Redirect file exists: $redirect_url");

                            // Try to redirect
                            header('Location: ' . $redirect_url);
                            error_log("Header sent for redirect to: $redirect_url");

                            // Force exit and log
                            exit();
                        }
                    } else {
                        $error = 'Invalid email or password';
                        error_log("❌ Password invalid for: $email");
                    }
                }
            } else {
                $error = 'Invalid email or password';
                error_log("❌ User not found: $email");
            }
        } catch (PDOException $e) {
            error_log("❌ PDO Exception: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        } catch (Exception $e) {
            error_log("❌ General Exception: " . $e->getMessage());
            $error = 'Login failed. Please try again.';
        }
    }

    error_log("=== LOGIN ATTEMPT ENDED ===");
}

// Debug: Check if we have output before headers
if (headers_sent($filename, $linenum)) {
    error_log("❌ HEADERS ALREADY SENT! File: $filename, Line: $linenum");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Lota Leave Tracker</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body,
        html {
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            height: 100%;
            overflow: hidden;
        }

        .container {
            display: flex;
            height: 100%;
        }

        /* Left Panel - Hero Section */
        .left-panel {
            flex: 1;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .left-panel-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('assets/images/auth-bg.jpg');
            background-size: cover;
            background-position: center;
            z-index: 1;
        }

        .left-panel-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }

        .left-panel-content {
            position: relative;
            z-index: 3;
            text-align: center;
            color: white;
            padding: 40px;
            max-width: 600px;
        }

        .logo-container {
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .logo-image {
            width: 120px;
            height: 120px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
            border: 4px solid rgba(255, 255, 255, 0.3);
        }

        .logo-text {
            font-size: 36px;
            font-weight: 700;
            color: white;
            letter-spacing: 1px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .tagline {
            font-size: 1.1em;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .left-panel h1 {
            font-size: 3.5em;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .system-name {
            font-size: 3.2em;
            color: #D2B48C;
            font-weight: 700;
            margin-bottom: 30px;
            letter-spacing: 1px;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
        }

        .left-panel p {
            font-size: 1.3em;
            font-weight: 400;
            color: #F5F5DC;
            line-height: 1.6;
            opacity: 0.95;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
        }

        .features-list {
            text-align: left;
            margin-top: 40px;
            list-style: none;
        }

        .features-list li {
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .features-list i {
            color: #D2B48C;
            font-size: 1.2em;
        }

        /* Right Panel - Form Section */
        .right-panel {
            flex: 1;
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            box-sizing: border-box;
            background: linear-gradient(135deg, #f9f7f2 0%, #F5F5DC 100%);
        }

        .login-form-box {
            width: 100%;
            max-width: 480px;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(85, 107, 47, 0.1);
            border: 1px solid rgba(85, 107, 47, 0.1);
            transition: transform 0.3s ease;
        }

        .login-form-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 50px rgba(85, 107, 47, 0.15);
        }

        .login-form-box h2 {
            font-size: 2.5em;
            font-weight: 600;
            color: #556B2F;
            margin-bottom: 10px;
            text-align: center;
        }

        .form-subtitle {
            text-align: center;
            color: #666666;
            margin-bottom: 30px;
            font-size: 1.1em;
        }

        /* Message Styles */
        .message-container {
            padding: 16px;
            margin-bottom: 25px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            animation: slideDown 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            border: 1px solid transparent;
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

        .message-error {
            background: linear-gradient(135deg, #ffeaea 0%, #ffd6d6 100%);
            color: #c92a2a;
            border-color: #f5c6cb;
        }

        .message-success {
            background: linear-gradient(135deg, #d4f8d4 0%, #b8e6b8 100%);
            color: #2b8a3e;
            border-color: #c3e6cb;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1A1A1A;
            font-size: 0.95em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group label i {
            color: #556B2F;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #E0E0E0;
            border-radius: 10px;
            box-sizing: border-box;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f9f9f9;
            font-family: 'Inter', sans-serif;
        }

        .form-group input:focus {
            outline: none;
            border-color: #556B2F;
            background: white;
            box-shadow: 0 0 0 3px rgba(85, 107, 47, 0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999999;
            transition: color 0.2s ease;
            background: none;
            border: none;
            padding: 4px;
            font-size: 1.2em;
            border-radius: 4px;
        }

        .password-toggle:hover {
            color: #556B2F;
            background: rgba(85, 107, 47, 0.1);
        }

        .options-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0 25px;
        }

        .remember-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .remember-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #556B2F;
            border-radius: 4px;
        }

        .remember-group label {
            color: #666666;
            font-size: 0.95em;
            cursor: pointer;
            font-weight: 500;
        }

        .forgot-password {
            color: #556B2F;
            text-decoration: none;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .forgot-password:hover {
            color: #3D4A21;
            background: rgba(85, 107, 47, 0.1);
            text-decoration: none;
        }

        .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #556B2F 0%, #6B8E23 100%);
            border: none;
            color: white;
            font-size: 18px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #3D4A21 0%, #556B2F 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(85, 107, 47, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .register-link {
            margin-top: 25px;
            font-size: 15px;
            text-align: center;
            color: #666666;
        }

        .register-link a {
            color: #556B2F;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 6px;
        }

        .register-link a:hover {
            color: #3D4A21;
            background: rgba(85, 107, 47, 0.1);
            text-decoration: none;
        }

        .security-note {
            font-size: 12px;
            color: #999999;
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #E0E0E0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            line-height: 1.5;
        }

        .system-status {
            margin-top: 25px;
            padding: 14px;
            background: linear-gradient(135deg, #f9f7f2 0%, #f5f0e6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85em;
            color: #666666;
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2b8a3e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                overflow-y: auto;
            }

            .left-panel,
            .right-panel {
                min-height: 50vh;
                padding: 30px 20px;
            }

            .logo-image {
                width: 80px;
                height: 80px;
            }

            .logo-text {
                font-size: 24px;
            }

            .left-panel h1 {
                font-size: 2.5em;
            }

            .system-name {
                font-size: 2.2em;
            }

            .left-panel p {
                font-size: 1.1em;
            }

            .login-form-box {
                padding: 25px;
                margin: 0 15px;
            }

            .login-form-box h2 {
                font-size: 2em;
            }

            .options-group {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="container">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="left-panel-bg"></div>
            <div class="left-panel-overlay"></div>

            <div class="left-panel-content">
                <div class="logo-container">
                    <img src="assets/images/lotalogo.jpg" alt="Lota Logo" class="logo-image">
                    <div>
                        <span class="logo-text">Lota</span>
                        <div class="tagline">Professional Leave Management</div>
                    </div>
                </div>

                <h1>Welcome Back</h1>
                <div class="system-name">Leave Tracker System</div>
                <p>Access your professional leave management dashboard. Track, request, and manage leaves efficiently.
                </p>

                <ul class="features-list">
                    <li><i class="fas fa-check-circle"></i> Secure role-based access control</li>
                    <li><i class="fas fa-check-circle"></i> Real-time leave tracking</li>
                    <li><i class="fas fa-check-circle"></i> Automated approval workflows</li>
                    <li><i class="fas fa-check-circle"></i> Comprehensive reporting</li>
                    <li><i class="fas fa-check-circle"></i> Google Forms integration</li>
                </ul>
            </div>
        </div>

        <!-- Right Panel - Form Section -->
        <div class="right-panel">
            <div class="login-form-box">
                <h2>Sign In</h2>
                <p class="form-subtitle">Enter your credentials to access the dashboard</p>

                <?php if (!empty($success)): ?>
                    <div class="message-container message-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="message-container message-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i>
                            Email Address
                        </label>
                        <input type="email" id="email" name="email" required placeholder="you@example.com"
                            value="<?= htmlspecialchars($email) ?>" autocomplete="username">
                    </div>

                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required
                                placeholder="Enter your password" autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="options-group">
                        <div class="remember-group">
                            <input type="checkbox" id="remember" name="remember" <?= $remember_email ? 'checked' : '' ?>>
                            <label for="remember">Remember me</label>
                        </div>
                        <a href="forgot-password.php" class="forgot-password">
                            <i class="fas fa-key"></i> Forgot Password?
                        </a>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn-primary" id="submitBtn">
                            <i class="fas fa-sign-in-alt"></i> Sign In to Dashboard
                        </button>
                    </div>
                </form>

                <div class="register-link">
                    Don't have an account? <a href="register.php">
                        <i class="fas fa-user-plus"></i> Request Access
                    </a>
                </div>

                <div class="security-note">
                    <i class="fas fa-lock"></i> Your data is encrypted and securely stored. We never share your personal
                    information.
                </div>

                <div class="system-status">
                    <div class="status-indicator"></div>
                    <span>System Status: <strong>Online</strong> • Database: Connected</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.parentElement.querySelector('i');

            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Form submission
        document.getElementById('loginForm').addEventListener('submit', function (e) {
            const submitBtn = document.getElementById('submitBtn');
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div> Authenticating...';

            // Basic validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showError('Please enter a valid email address.');
                resetButton();
                return;
            }

            if (password.length < 3) {
                e.preventDefault();
                showError('Password must be at least 3 characters.');
                resetButton();
                return;
            }
        });

        function showError(message) {
            // Remove existing error messages
            const existingErrors = document.querySelectorAll('.message-error, .message-success');
            existingErrors.forEach(msg => {
                if (msg.parentNode) {
                    msg.parentNode.removeChild(msg);
                }
            });

            // Create new error message
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message-container message-error';
            messageDiv.innerHTML = `
                <i class="fas fa-exclamation-circle"></i>
                <span>${message}</span>
            `;

            // Insert after form subtitle
            const formBox = document.querySelector('.login-form-box');
            const subtitle = document.querySelector('.form-subtitle');
            formBox.insertBefore(messageDiv, subtitle.nextSibling);
        }

        function resetButton() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In to Dashboard';
        }

        // Auto-focus on email field
        document.addEventListener('DOMContentLoaded', function () {
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
    </script>
</body>

</html>