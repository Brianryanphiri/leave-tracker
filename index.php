<?php
// index.php
// Start session only if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lota Leave Tracker</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f5dc 0%, #e6e0d6 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Updated background pattern with requested colors */
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='120' height='120' viewBox='0 0 120 120' xmlns='http://www.w3.org/2000/svg' opacity='0.08'%3E%3Cpath d='M60 15 L105 60 L60 105 L15 60 Z' fill='none' stroke='%23556B2F' stroke-width='1.5'/%3E%3Ccircle cx='60' cy='60' r='20' fill='none' stroke='%23BC8F8F' stroke-width='1.5'/%3E%3C/svg%3E");
            background-size: 120px;
            z-index: -1;
        }

        /* Floating icons with new color scheme */
        .floating-icons {
            position: fixed;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .floating-icon {
            position: absolute;
            opacity: 0.12;
            font-size: 2.5rem;
            animation: float 25s infinite linear;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
        }

        .floating-icon:nth-child(1) { top: 5%; left: 8%; animation-delay: 0s; color: #556B2F; font-size: 3rem; }
        .floating-icon:nth-child(2) { top: 15%; right: 12%; animation-delay: -3s; color: #BC8F8F; font-size: 2.8rem; }
        .floating-icon:nth-child(3) { bottom: 25%; left: 10%; animation-delay: -5s; color: #B0C4DE; font-size: 3.2rem; }
        .floating-icon:nth-child(4) { top: 35%; right: 15%; animation-delay: -7s; color: #D2B48C; font-size: 2.6rem; }
        .floating-icon:nth-child(5) { bottom: 15%; right: 8%; animation-delay: -9s; color: #556B2F; font-size: 3rem; }
        .floating-icon:nth-child(6) { top: 55%; left: 15%; animation-delay: -11s; color: #BC8F8F; font-size: 2.7rem; }
        .floating-icon:nth-child(7) { bottom: 8%; right: 20%; animation-delay: -13s; color: #B0C4DE; font-size: 3.1rem; }
        .floating-icon:nth-child(8) { top: 10%; right: 25%; animation-delay: -15s; color: #D2B48C; font-size: 2.9rem; }
        .floating-icon:nth-child(9) { top: 70%; left: 5%; animation-delay: -17s; color: #556B2F; font-size: 3rem; }
        .floating-icon:nth-child(10) { bottom: 40%; right: 5%; animation-delay: -19s; color: #BC8F8F; font-size: 2.8rem; }

        /* Geometric shapes with new colors */
        .geometric-shape {
            position: fixed;
            border-radius: 25px;
            opacity: 0.08;
            z-index: -1;
            filter: blur(1px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .shape-1 {
            width: 250px;
            height: 250px;
            background: linear-gradient(135deg, #556B2F, #6B8E23);
            top: 5%;
            right: 10%;
            transform: rotate(45deg);
            animation: pulse 8s infinite alternate;
        }

        .shape-2 {
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #BC8F8F, #D2B48C);
            bottom: 15%;
            left: 8%;
            transform: rotate(30deg);
            border-radius: 40% 60% 60% 40% / 60% 30% 70% 40%;
            animation: pulse 10s infinite alternate-reverse;
        }

        .shape-3 {
            width: 180px;
            height: 180px;
            background: linear-gradient(135deg, #B0C4DE, #87CEEB);
            top: 65%;
            right: 20%;
            transform: rotate(60deg);
            border-radius: 50%;
            animation: pulse 12s infinite alternate;
            opacity: 0.06;
        }

        /* Grid lines with new color */
        .grid-lines {
            position: fixed;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(to right, rgba(85, 107, 47, 0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(85, 107, 47, 0.05) 1px, transparent 1px);
            background-size: 60px 60px;
            z-index: -1;
        }

        /* Dots pattern */
        .dots-pattern {
            position: fixed;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(rgba(188, 143, 143, 0.1) 2px, transparent 2px),
                radial-gradient(rgba(85, 107, 47, 0.08) 1.5px, transparent 1.5px);
            background-size: 40px 40px, 25px 25px;
            background-position: 0 0, 20px 20px;
            z-index: -1;
        }

        /* Decorative lines */
        .decorative-line {
            position: fixed;
            height: 2px;
            z-index: -1;
            opacity: 0.1;
        }

        .line-1 {
            top: 30%;
            left: 10%;
            right: 10%;
            width: 80%;
            background: linear-gradient(90deg, transparent, #556B2F, transparent);
        }

        .line-2 {
            bottom: 40%;
            left: 5%;
            right: 5%;
            width: 90%;
            background: linear-gradient(90deg, transparent, #BC8F8F, transparent);
        }

        @keyframes float {
            0% {
                transform: translateY(0px) rotate(0deg) scale(1);
            }
            25% {
                transform: translateY(-25px) rotate(90deg) scale(1.05);
            }
            50% {
                transform: translateY(0px) rotate(180deg) scale(1);
            }
            75% {
                transform: translateY(25px) rotate(270deg) scale(1.05);
            }
            100% {
                transform: translateY(0px) rotate(360deg) scale(1);
            }
        }

        @keyframes pulse {
            0% {
                opacity: 0.06;
                transform: scale(1) rotate(var(--rotation, 45deg));
            }
            50% {
                opacity: 0.1;
                transform: scale(1.05) rotate(var(--rotation, 45deg));
            }
            100% {
                opacity: 0.06;
                transform: scale(1) rotate(var(--rotation, 45deg));
            }
        }

        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo-image {
            width: 120px;
            height: 120px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border: 4px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            background: linear-gradient(135deg, #D2B48C, #F5DEB3);
        }

        .main-title {
            font-family: 'Playfair Display', serif;
            font-size: 4em;
            font-weight: 700;
            color: #556B2F;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        .subtitle {
            font-size: 1.2em;
            color: #8B7355;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 30px;
        }

        .content-section {
            text-align: center;
            max-width: 800px;
            margin: 0 auto 50px;
            background: rgba(255, 255, 255, 0.85);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(5px);
        }

        .tagline {
            font-size: 2.5em;
            color: #2F4F2F;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .description {
            font-size: 1.3em;
            color: #555;
            line-height: 1.6;
            margin-bottom: 40px;
        }

        .action-section {
            display: flex;
            gap: 20px;
            margin-bottom: 40px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            padding: 16px 32px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 1.1em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #556B2F 0%, #6B8E23 100%);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(85, 107, 47, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #D2B48C 0%, #F5DEB3 100%);
            color: #556B2F;
            border: 2px solid #556B2F;
        }

        .btn-secondary:hover {
            background: #556B2F;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(85, 107, 47, 0.3);
        }

        .security-notice {
            background: rgba(255, 255, 255, 0.9);
            padding: 15px 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #666;
            font-size: 0.9em;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #556B2F;
        }

        .security-notice i {
            color: #556B2F;
        }

        .footer-section {
            text-align: center;
            color: #777;
            font-size: 0.9em;
            margin-top: 30px;
            background: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 15px;
            width: 100%;
            max-width: 800px;
        }

        .footer-links {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #556B2F;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            background: rgba(85, 107, 47, 0.1);
            text-decoration: none;
            transform: translateY(-2px);
        }

        .copyright {
            margin-top: 10px;
            color: #8B7355;
        }

        @media (max-width: 768px) {
            .main-title {
                font-size: 3em;
            }

            .tagline {
                font-size: 2em;
            }

            .description {
                font-size: 1.1em;
            }

            .btn {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }

            .action-section {
                flex-direction: column;
                align-items: center;
            }

            .content-section {
                padding: 30px 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Background Elements -->
    <div class="floating-icons">
        <i class="floating-icon fas fa-calendar-alt" title="Calendar"></i>
        <i class="floating-icon fas fa-users" title="Team"></i>
        <i class="floating-icon fas fa-clock" title="Time Off"></i>
        <i class="floating-icon fas fa-chart-bar" title="Analytics"></i>
        <i class="floating-icon fas fa-file-signature" title="Approval"></i>
        <i class="floating-icon fas fa-laptop-house" title="Remote Work"></i>
        <i class="floating-icon fas fa-balance-scale" title="Balance"></i>
        <i class="floating-icon fas fa-business-time" title="Business"></i>
        <i class="floating-icon fas fa-user-check" title="Approval"></i>
        <i class="floating-icon fas fa-tasks" title="Tasks"></i>
    </div>
    
    <div class="geometric-shape shape-1" style="--rotation: 45deg;"></div>
    <div class="geometric-shape shape-2" style="--rotation: 30deg;"></div>
    <div class="geometric-shape shape-3" style="--rotation: 60deg;"></div>
    
    <div class="grid-lines"></div>
    <div class="dots-pattern"></div>
    
    <div class="decorative-line line-1"></div>
    <div class="decorative-line line-2"></div>
    
    <div class="container">
        <div class="logo-section">
            <img src="assets/images/lotalogo.jpg" alt="Lota Logo" class="logo-image">
            <h1 class="main-title">Lota</h1>
            <p class="subtitle">LEAVE MANAGEMENT SYSTEM</p>
        </div>

        <div class="content-section">
            <h2 class="tagline">Professional Leave Tracking Solution</h2>
            <p class="description">
                
            </p>
        </div>

        <div class="action-section">
            <a href="login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                Administrator Login
            </a>
            <a href="register.php" class="btn btn-secondary">
                <i class="fas fa-user-plus"></i>
                Create Account
            </a>
        </div>

        <div class="security-notice">
            <i class="fas fa-shield-alt"></i>
            <span>Secure access for authorized personnel only • All data is encrypted and protected</span>
        </div>

        <div class="footer-section">
            <div class="footer-links">
                <a href="#"><i class="fas fa-question-circle"></i> Support</a>
                <a href="#"><i class="fas fa-file-alt"></i> Policy</a>
                <a href="#"><i class="fas fa-lock"></i> Security</a>
                <a href="#"><i class="fas fa-book"></i> Documentation</a>
            </div>
            <p class="copyright">&copy; <?php echo date('Y'); ?> Lota Leave Tracker. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Simple animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.btn, .logo-image, .content-section');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 200);
            });
        });
    </script>
</body>

</html>