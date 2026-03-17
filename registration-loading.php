<?php
// registration-loading.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if coming from successful registration
if (!isset($_SESSION['registration_success']) || !$_SESSION['registration_success']) {
    header('Location: register.php');
    exit();
}

// Get the email from session
$user_email = $_SESSION['new_user_email'] ?? '';

// Clear the session variables
unset($_SESSION['registration_success']);
unset($_SESSION['new_user_email']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created - Lota Leave Tracker</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }

        /* Background Image with Overlay */
        .background-image {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('assets/images/indexbg.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            z-index: -2;
        }

        .background-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(85, 107, 47, 0.85) 0%, rgba(139, 195, 74, 0.8) 100%);
            z-index: -1;
        }

        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px;
            position: relative;
            z-index: 1;
        }

        .loading-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 30px;
            padding: 60px 50px;
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.25),
                0 0 0 1px rgba(255, 255, 255, 0.1),
                inset 0 0 0 1px rgba(255, 255, 255, 0.3);
            text-align: center;
            max-width: 600px;
            width: 100%;
            animation: cardAppear 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255, 255, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .loading-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #556B2F, #8BC34A, #CDDC39, #8BC34A, #556B2F);
            background-size: 200% 100%;
            animation: shimmer 3s infinite linear;
        }

        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        .logo-section {
            margin-bottom: 40px;
            position: relative;
        }

        .logo-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 25px;
        }

        .logo-image {
            width: 140px;
            height: 140px;
            border-radius: 28px;
            object-fit: cover;
            box-shadow:
                0 20px 40px rgba(85, 107, 47, 0.3),
                0 0 0 4px rgba(255, 255, 255, 0.8),
                inset 0 0 20px rgba(255, 255, 255, 0.5);
            animation: logoFloat 3s ease-in-out infinite;
            border: 3px solid rgba(255, 255, 255, 0.9);
        }

        @keyframes logoFloat {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-15px) rotate(2deg);
            }
        }

        .logo-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 180px;
            height: 180px;
            border-radius: 35px;
            background: linear-gradient(45deg, #556B2F, #8BC34A);
            opacity: 0.4;
            filter: blur(20px);
            z-index: -1;
            animation: glowPulse 2s ease-in-out infinite;
        }

        @keyframes glowPulse {

            0%,
            100% {
                opacity: 0.3;
                transform: translate(-50%, -50%) scale(1);
            }

            50% {
                opacity: 0.5;
                transform: translate(-50%, -50%) scale(1.1);
            }
        }

        .brand-name {
            font-family: 'Playfair Display', serif;
            font-size: 3.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #556B2F 0%, #8BC34A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .system-name {
            font-size: 1.1em;
            color: #666;
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 400;
            margin-bottom: 5px;
        }

        .tagline {
            font-size: 1.2em;
            color: #888;
            font-weight: 300;
            margin-bottom: 50px;
            position: relative;
            display: inline-block;
            padding: 0 20px;
        }

        .tagline::before,
        .tagline::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40px;
            height: 1px;
            background: linear-gradient(90deg, transparent, #8BC34A);
        }

        .tagline::before {
            left: -50px;
        }

        .tagline::after {
            right: -50px;
            background: linear-gradient(90deg, #8BC34A, transparent);
        }

        /* Loading Animation */
        .loading-animation {
            margin: 50px 0 60px;
            position: relative;
        }

        .spinner-container {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
        }

        .spinner-outer {
            position: absolute;
            width: 120px;
            height: 120px;
            border: 8px solid rgba(139, 195, 74, 0.15);
            border-radius: 50%;
            top: 0;
            left: 0;
        }

        .spinner-inner {
            position: absolute;
            width: 120px;
            height: 120px;
            border: 8px solid transparent;
            border-top: 8px solid #556B2F;
            border-radius: 50%;
            top: 0;
            left: 0;
            animation: spin 1.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) infinite;
        }

        .spinner-center {
            position: absolute;
            width: 60px;
            height: 60px;
            background: #556B2F;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 30px rgba(85, 107, 47, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            font-size: 1.4em;
            color: #444;
            margin-bottom: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .loading-dots {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
        }

        .loading-dot {
            width: 16px;
            height: 16px;
            background: linear-gradient(135deg, #556B2F, #8BC34A);
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
            box-shadow: 0 4px 15px rgba(85, 107, 47, 0.3);
        }

        .loading-dot:nth-child(1) {
            animation-delay: -0.32s;
        }

        .loading-dot:nth-child(2) {
            animation-delay: -0.16s;
        }

        .loading-dot:nth-child(3) {
            animation-delay: 0s;
        }

        @keyframes bounce {

            0%,
            80%,
            100% {
                transform: scale(0.8);
                opacity: 0.6;
            }

            40% {
                transform: scale(1.2);
                opacity: 1;
            }
        }

        /* Success Animation */
        .success-container {
            display: none;
            animation: successAppear 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        @keyframes successAppear {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .success-animation {
            margin: 40px 0 50px;
            position: relative;
        }

        .checkmark-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #28a745, #20c997);
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: circleExpand 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
            box-shadow: 0 20px 40px rgba(40, 167, 69, 0.3);
            position: relative;
            overflow: hidden;
        }

        .checkmark-circle::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            animation: shine 2s infinite linear;
        }

        @keyframes circleExpand {
            from {
                transform: scale(0);
            }

            to {
                transform: scale(1);
            }
        }

        @keyframes shine {
            0% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .checkmark {
            width: 60px;
            height: 60px;
            position: relative;
            transform: rotate(45deg);
            animation: checkmarkDraw 0.5s 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
            opacity: 0;
        }

        .checkmark-stem {
            position: absolute;
            width: 8px;
            height: 0;
            background-color: white;
            left: 26px;
            top: 0;
            border-radius: 4px;
            animation: stemDraw 0.5s 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
        }

        .checkmark-kick {
            position: absolute;
            width: 0;
            height: 8px;
            background-color: white;
            left: 0;
            bottom: 26px;
            border-radius: 4px;
            animation: kickDraw 0.5s 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
        }

        @keyframes checkmarkDraw {
            to {
                opacity: 1;
                transform: rotate(45deg) scale(1);
            }
        }

        @keyframes stemDraw {
            to {
                height: 60px;
            }
        }

        @keyframes kickDraw {
            to {
                width: 30px;
            }
        }

        .success-title {
            font-size: 2.8em;
            color: #28a745;
            margin-bottom: 25px;
            font-weight: 700;
            background: linear-gradient(135deg, #28a745, #20c997);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .success-message {
            font-size: 1.3em;
            color: #555;
            line-height: 1.7;
            margin-bottom: 30px;
            padding: 0 10px;
            font-weight: 400;
        }

        .user-email-container {
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.9), rgba(233, 236, 239, 0.9));
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin: 30px 0;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow:
                0 10px 30px rgba(0, 0, 0, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }

        .user-email {
            font-family: 'Inter', monospace;
            font-size: 1.2em;
            color: #333;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            word-break: break-all;
        }

        .user-email i {
            color: #556B2F;
            font-size: 1.4em;
        }

        .status-note {
            background: linear-gradient(135deg, rgba(255, 243, 205, 0.9), rgba(255, 235, 159, 0.9));
            border-radius: 15px;
            padding: 20px;
            margin: 25px 0;
            border-left: 5px solid #ffc107;
            box-shadow: 0 8px 25px rgba(255, 193, 7, 0.15);
        }

        .status-note i {
            color: #ffc107;
            font-size: 1.2em;
            margin-right: 10px;
        }

        .status-note span {
            color: #856404;
            font-weight: 500;
            line-height: 1.6;
        }

        .redirect-info {
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.1), rgba(0, 86, 179, 0.1));
            border-radius: 15px;
            padding: 20px;
            margin: 30px 0;
            border: 1px solid rgba(13, 110, 253, 0.2);
            font-size: 1.1em;
            color: #0d6efd;
        }

        .countdown {
            font-weight: 700;
            font-size: 1.3em;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 18px 40px;
            border-radius: 15px;
            text-decoration: none;
            font-size: 1.1em;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            min-width: 200px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            border: none;
            cursor: pointer;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #556B2F 0%, #8BC34A 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 20px 40px rgba(85, 107, 47, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 20px 40px rgba(108, 117, 125, 0.4);
        }

        /* Floating particles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            animation: float linear infinite;
        }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .loading-container {
                padding: 20px;
            }

            .loading-card {
                padding: 40px 25px;
                border-radius: 25px;
            }

            .logo-image {
                width: 120px;
                height: 120px;
            }

            .brand-name {
                font-size: 2.8em;
            }

            .system-name {
                font-size: 1em;
                letter-spacing: 2px;
            }

            .success-title {
                font-size: 2.2em;
            }

            .btn {
                padding: 16px 30px;
                min-width: 180px;
                font-size: 1em;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
        }

        @media (max-width: 480px) {
            .loading-card {
                padding: 30px 20px;
            }

            .logo-image {
                width: 100px;
                height: 100px;
            }

            .brand-name {
                font-size: 2.2em;
            }

            .success-title {
                font-size: 1.8em;
            }

            .btn {
                width: 100%;
                max-width: 280px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Background Image -->
    <div class="background-image"></div>
    <div class="background-overlay"></div>

    <!-- Floating Particles -->
    <div class="particles" id="particles"></div>

    <!-- Main Content -->
    <div class="loading-container">
        <div class="loading-card">
            <!-- Logo Section -->
            <div class="logo-section">
                <div class="logo-wrapper">
                    <div class="logo-glow"></div>
                    <img src="assets/images/lotalogo.jpg" alt="Lota Logo" class="logo-image">
                </div>
                <h1 class="brand-name">Lota</h1>
                <div class="system-name">Leave Management System</div>
                <div class="tagline">Professional Leave Tracking</div>
            </div>

            <!-- Loading Animation -->
            <div class="loading-animation" id="loadingStage">
                <div class="spinner-container">
                    <div class="spinner-outer"></div>
                    <div class="spinner-inner"></div>
                    <div class="spinner-center">
                        <i class="fas fa-leaf"></i>
                    </div>
                </div>
                <div class="loading-text">
                    <i class="fas fa-cog fa-spin"></i>
                    Creating Your Account
                </div>
                <div class="loading-dots">
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                </div>
            </div>

            <!-- Success Animation -->
            <div class="success-container" id="successStage">
                <div class="success-animation">
                    <div class="checkmark-circle">
                        <div class="checkmark">
                            <div class="checkmark-stem"></div>
                            <div class="checkmark-kick"></div>
                        </div>
                    </div>
                </div>

                <h1 class="success-title">Welcome Aboard!</h1>

                <div class="success-message">
                    Your Lota account has been successfully created and is ready for use.
                </div>

                <div class="user-email-container">
                    <div class="user-email">
                        <i class="fas fa-envelope-circle-check"></i>
                        <?php echo htmlspecialchars($user_email); ?>
                    </div>
                </div>

                <div class="status-note">
                    <i class="fas fa-info-circle"></i>
                    <span>
                        Your account status is currently <strong>Pending Approval</strong>.
                        wait for an administrator to activate your account.
                    </span>
                </div>

                <div class="redirect-info">
                    <i class="fas fa-hourglass-half"></i>
                    Redirecting to login page in <span class="countdown" id="countdown">10</span> seconds
                </div>

                <div class="action-buttons">
                    <a href="login.php" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i>
                        Go to Login
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i>
                        Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Create floating particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 30;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');

                // Random size
                const size = Math.random() * 10 + 5;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;

                // Random position
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;

                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 5;
                particle.style.animationDuration = `${duration}s`;
                particle.style.animationDelay = `${delay}s`;

                // Random color
                const colors = [
                    'rgba(255, 255, 255, 0.3)',
                    'rgba(139, 195, 74, 0.3)',
                    'rgba(85, 107, 47, 0.3)',
                    'rgba(255, 255, 255, 0.2)'
                ];
                particle.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];

                particlesContainer.appendChild(particle);
            }
        }

        // Start loading sequence
        setTimeout(() => {
            document.getElementById('loadingStage').style.display = 'none';
            document.getElementById('successStage').style.display = 'block';

            // Start countdown for redirect
            let seconds = 10;
            const countdownElement = document.getElementById('countdown');

            const countdownInterval = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds;

                // Add animation effect when counting down
                countdownElement.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    countdownElement.style.transform = 'scale(1)';
                }, 200);

                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    // Add fade out effect before redirect
                    document.querySelector('.loading-card').style.animation = 'cardAppear 0.5s reverse forwards';
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 500);
                }
            }, 1000);
        }, 3500); // Show loading for 3.5 seconds

        // Initialize particles
        document.addEventListener('DOMContentLoaded', createParticles);

        // Add keypress event to skip countdown
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                window.location.href = 'login.php';
            }
        });
    </script>
</body>

</html>