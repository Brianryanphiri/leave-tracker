<?php
// register.php

// Start PHP Session (only if not started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once __DIR__ . '/config/database.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$error = '';
$success = '';
$full_name = '';
$email = '';
$registration_complete = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms_accepted = isset($_POST['terms']) ? 1 : 0;

    // Validation
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (!$terms_accepted) {
        $error = 'You must agree to the Terms of Service and Privacy Policy';
    } else {
        try {
            // Get database connection
            $conn = getConnection();

            if (!$conn) {
                $error = 'Database connection failed. Please try again.';
                error_log("Database connection failed in register.php");
            } else {
                // Check if email already exists
                $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                if (!$check_stmt) {
                    $error = 'Database error. Please try again.';
                    error_log("Prepare statement failed: " . $conn->error);
                } else {
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    $check_stmt->store_result();

                    if ($check_stmt->num_rows > 0) {
                        $error = 'This email is already registered';
                        $check_stmt->close();
                    } else {
                        $check_stmt->close();

                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        // First, let's check what columns exist in the users table
                        $result = $conn->query("DESCRIBE users");
                        $columns = [];
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $columns[] = $row['Field'];
                            }
                            $result->free();
                        }

                        // Log available columns for debugging
                        error_log("Available columns in users table: " . implode(', ', $columns));

                        // Check if terms_accepted column exists
                        $has_terms_column = in_array('terms_accepted', $columns);

                        if ($has_terms_column) {
                            // Insert with terms_accepted column
                            $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role, status, terms_accepted, created_at) VALUES (?, ?, ?, 'employee', 'pending', ?, NOW())");
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("sssi", $full_name, $email, $hashed_password, $terms_accepted);
                            } else {
                                $error = 'Database error. Please try again.';
                                error_log("Insert prepare failed: " . $conn->error);
                                $conn->close();
                                throw new Exception("Insert prepare failed");
                            }
                        } else {
                            // Insert without terms_accepted column
                            $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role, status, created_at) VALUES (?, ?, ?, 'employee', 'pending', NOW())");
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("sss", $full_name, $email, $hashed_password);
                            } else {
                                $error = 'Database error. Please try again.';
                                error_log("Insert prepare failed: " . $conn->error);
                                $conn->close();
                                throw new Exception("Insert prepare failed");
                            }
                        }

                        if ($insert_stmt->execute()) {
                            $user_id = $insert_stmt->insert_id;
                            $insert_stmt->close();

                            $registration_complete = true;
                            $success = 'Account created successfully! Your account is pending approval.';

                            // Store success message in session for the loading page
                            $_SESSION['registration_success'] = true;
                            $_SESSION['new_user_email'] = $email;
                            $_SESSION['new_user_name'] = $full_name;

                            // Send email notification to admin (optional)
                            sendRegistrationNotification($full_name, $email);

                            // Redirect to loading page
                            header('Location: registration-loading.php');
                            exit();
                        } else {
                            $error = 'Registration failed. Please try again. Error: ' . $insert_stmt->error;
                            error_log("Registration execute failed: " . $insert_stmt->error);
                            $insert_stmt->close();
                        }
                    }
                }

                $conn->close();
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = 'Registration error. Please try again later.';
        }
    }
}

// Function to send registration notification (optional)
function sendRegistrationNotification($name, $email)
{
    // You can implement email sending here
    // For now, just log it
    error_log("New registration: $name ($email) - Pending approval");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Lota Leave Tracker</title>
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

        /* Left Panel - Hero Section with Background Image */
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

        /* Dark overlay for better text readability */
        .left-panel-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }

        /* Content ON TOP of the background image */
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
            margin-top: 10px;
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

        .register-form-box {
            width: 100%;
            max-width: 480px;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(85, 107, 47, 0.1);
            border: 1px solid rgba(85, 107, 47, 0.1);
            transition: transform 0.3s ease;
        }

        .register-form-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 50px rgba(85, 107, 47, 0.15);
        }

        .register-form-box h2 {
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

        .form-group input.error {
            border-color: #dc3545;
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
        }

        .password-toggle:hover {
            color: #556B2F;
        }

        .password-strength {
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 2px;
        }

        .terms-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0;
        }

        .terms-group input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .terms-label {
            font-size: 14px;
            color: #666666;
            line-height: 1.5;
            cursor: pointer;
        }

        .terms-label a {
            color: #556B2F;
            text-decoration: none;
            font-weight: 600;
        }

        .terms-label a:hover {
            text-decoration: underline;
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
            margin-top: 10px;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #3D4A21 0%, #556B2F 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(85, 107, 47, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .message-container {
            padding: 16px;
            margin-bottom: 25px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            animation: slideDown 0.3s ease-out;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
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

        .message-success {
            background: linear-gradient(135deg, #d4f8d4 0%, #b8e6b8 100%);
            color: #2b8a3e;
            border: 1px solid #c3e6cb;
        }

        .message-error {
            background: linear-gradient(135deg, #ffeaea 0%, #ffd6d6 100%);
            color: #c92a2a;
            border: 1px solid #f5c6cb;
        }

        .login-link {
            margin-top: 25px;
            font-size: 15px;
            text-align: center;
            color: #666666;
        }

        .login-link a {
            color: #556B2F;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .login-link a:hover {
            color: #3D4A21;
            text-decoration: underline;
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

            .left-panel .system-name {
                font-size: 2.2em;
            }

            .left-panel p {
                font-size: 1.1em;
            }

            .register-form-box {
                padding: 25px;
                margin: 0 15px;
            }

            .register-form-box h2 {
                font-size: 2em;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="container">
        <!-- Left Panel - Background image with logo and text ON TOP -->
        <div class="left-panel">
            <div class="left-panel-bg"></div>
            <div class="left-panel-overlay"></div>

            <div class="left-panel-content">
                <div class="logo-container">
                    <img src="assets/images/lotalogo.jpg" alt="Lota Logo" class="logo-image">
                    <span class="logo-text">Lota</span>
                </div>

                <h1>Welcome to</h1>
                <div class="system-name">Lota Leave Tracker</div>
                <p>Streamline your organization's leave management with our professional platform. Efficient, secure,
                    and designed for modern workplaces.</p>
            </div>
        </div>

        <!-- Right Panel - Form Section -->
        <div class="right-panel">
            <div class="register-form-box">
                <h2>Create Account</h2>
                <p class="form-subtitle">Join our community and streamline your leave management</p>

                <?php if ($error): ?>
                            <div class="message-container message-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?= htmlspecialchars($error) ?></span>
                            </div>
                <?php endif; ?>

                <form method="POST" action="" id="registrationForm">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name"
                            value="<?= htmlspecialchars($full_name) ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required placeholder="you@example.com"
                            value="<?= htmlspecialchars($email) ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required
                                placeholder="Create a strong password (min. 8 characters)">
                            <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="passwordStrength"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" required
                                placeholder="Re-enter your password">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="terms-group">
                        <input type="checkbox" id="terms" name="terms" <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                        <label for="terms" class="terms-label">
                            I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                        </label>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                    </div>
                </form>

                <div class="login-link">
                    Already have an account? <a href="login.php">Sign in to Lota</a>
                </div>

                <div class="security-note">
                    <i class="fas fa-lock"></i> Your data is encrypted and securely stored. We never share your personal
                    information.
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function (e) {
            const password = e.target.value;
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;

            // Criteria checks
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            // Cap at 5 for percentage calculation
            strength = Math.min(strength, 5);

            let width = (strength / 5) * 100;
            let color = '#dc3545'; // Red for weak

            if (strength >= 2) color = '#ffc107'; // Orange/Yellow for medium
            if (strength >= 4) color = '#28a745'; // Green for strong

            strengthBar.style.width = width + '%';
            strengthBar.style.backgroundColor = color;
        });

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

        // Form validation and submission
        document.getElementById('registrationForm').addEventListener('submit', function (e) {
            const submitBtn = document.getElementById('submitBtn');
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const email = document.getElementById('email').value;
            const terms = document.getElementById('terms').checked;

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div> Creating Account...';

            // Password validation
            if (password.length < 8) {
                e.preventDefault();
                showError('Password must be at least 8 characters long.');
                resetButton();
                return;
            }

            if (password !== confirmPassword) {
                e.preventDefault();
                showError('Passwords do not match. Please check and try again.');
                resetButton();
                return;
            }

            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showError('Please enter a valid email address.');
                resetButton();
                return;
            }

            // Terms validation
            if (!terms) {
                e.preventDefault();
                showError('You must agree to the Terms of Service and Privacy Policy.');
                resetButton();
                return;
            }

            // If all validations pass, form will submit normally
        });

        function showError(message) {
            // Remove existing error messages
            const existingErrors = document.querySelectorAll('.message-error');
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
            const formBox = document.querySelector('.register-form-box');
            const subtitle = document.querySelector('.form-subtitle');
            formBox.insertBefore(messageDiv, subtitle.nextSibling);

            // Scroll to error message
            messageDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function resetButton() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
        }

        // Auto-capitalize first letter of name
        document.getElementById('full_name').addEventListener('input', function (e) {
            if (this.value.length === 1) {
                this.value = this.value.toUpperCase();
            }
        });

        // Real-time password validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const password = document.getElementById('password').value;
            const confirm = this.value;

            if (confirm && password !== confirm) {
                this.style.borderColor = '#dc3545';
            } else if (confirm && password === confirm) {
                this.style.borderColor = '#28a745';
            } else {
                this.style.borderColor = '#E0E0E0';
            }
        });
    </script>
</body>

</html>