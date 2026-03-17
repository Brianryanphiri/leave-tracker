<?php
// includes/footer.php
?>

<!-- Footer -->
<footer class="footer">
    <div class="footer-content">
        <div class="footer-simple">
            <p>&copy; <?php echo date('Y'); ?> Lota Leave Tracker System v2.0</p>
            <p class="user-info">
                <i class="fas fa-user"></i> Logged in as:
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?>
                • <i class="fas fa-clock"></i> Last login:
                <?php echo isset($_SESSION['login_time']) ? date('M j, Y g:i A', $_SESSION['login_time']) : 'Today'; ?>
            </p>
        </div>
    </div>
</footer>

<style>
    /* Footer Styles */
    .footer {
        background: white;
        border-top: 1px solid rgba(212, 160, 23, 0.1);
        padding: 25px 0;
        margin-top: 40px;
        box-shadow: 0 -4px 15px rgba(139, 115, 85, 0.03);
        position: relative;
    }

    .footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 3px;
        background: linear-gradient(90deg, #D4A017, #B8860B);
        border-radius: 2px;
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 30px;
    }

    .footer-simple {
        text-align: center;
    }

    .footer-simple p {
        margin: 0 0 8px 0;
        color: #666;
        font-size: 0.9em;
        line-height: 1.5;
    }

    .user-info {
        color: #8B7355 !important;
        font-size: 0.85em !important;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .user-info i {
        color: #D4A017;
        margin-right: 5px;
        font-size: 0.9em;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .footer {
            padding: 20px 0;
            margin-top: 30px;
        }

        .footer-content {
            padding: 0 20px;
        }

        .user-info {
            flex-direction: column;
            gap: 5px;
        }
    }
</style>

</main>
</div>

<script>
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    // Show mobile menu button on small screens
    function checkScreenSize() {
        if (window.innerWidth <= 1200) {
            mobileMenuBtn.style.display = 'block';
        } else {
            mobileMenuBtn.style.display = 'none';
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    }

    // Initial check
    checkScreenSize();

    // Toggle mobile menu
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }

    // Close menu on window resize
    window.addEventListener('resize', checkScreenSize);

    // Auto-refresh dashboard every 5 minutes
    if (window.location.pathname.includes('dashboard.php')) {
        setTimeout(() => {
            location.reload();
        }, 300000); // 5 minutes
    }

    // Add click animations
    document.querySelectorAll('.btn-action, .nav-link, .action-card').forEach(element => {
        element.addEventListener('click', function (e) {
            // Add ripple effect
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            const ripple = document.createElement('span');
            ripple.style.cssText = `
                position: absolute;
                border-radius: 50%;
                background: rgba(212, 160, 23, 0.2);
                transform: scale(0);
                animation: ripple 0.6s linear;
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                pointer-events: none;
            `;

            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);

            setTimeout(() => ripple.remove(), 600);
        });
    });

    // Add CSS for ripple animation
    if (!document.querySelector('#ripple-style')) {
        const style = document.createElement('style');
        style.id = 'ripple-style';
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    }

    // Notification system
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
            <button class="notification-close"><i class="fas fa-times"></i></button>
        `;

        document.body.appendChild(notification);

        // Show notification
        setTimeout(() => notification.classList.add('show'), 10);

        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 5000);

        // Close button
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        });
    }

    // Check for URL messages
    const urlParams = new URLSearchParams(window.location.search);
    const message = urlParams.get('message');
    const error = urlParams.get('error');

    if (message) {
        showNotification(decodeURIComponent(message), 'success');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    if (error) {
        showNotification(decodeURIComponent(error), 'error');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // Add notification styles
    if (!document.querySelector('#notification-style')) {
        const style = document.createElement('style');
        style.id = 'notification-style';
        style.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                padding: 16px 22px;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                gap: 12px;
                z-index: 9999;
                transform: translateX(150%);
                transition: transform 0.3s ease;
                max-width: 380px;
                border-left: 4px solid #D4A017;
            }
            
            .notification.show {
                transform: translateX(0);
            }
            
            .notification-success {
                border-left-color: #B8860B;
                background: rgba(212, 160, 23, 0.1);
            }
            
            .notification-error {
                border-left-color: #8B4513;
                background: rgba(139, 69, 19, 0.1);
            }
            
            .notification-info {
                border-left-color: #4285F4;
                background: rgba(66, 133, 244, 0.1);
            }
            
            .notification i:first-child {
                font-size: 1.1em;
                flex-shrink: 0;
            }
            
            .notification-success i:first-child {
                color: #B8860B;
            }
            
            .notification-error i:first-child {
                color: #8B4513;
            }
            
            .notification-info i:first-child {
                color: #4285F4;
            }
            
            .notification span {
                flex: 1;
                font-weight: 500;
                color: #2F2F2F;
                font-size: 0.95em;
            }
            
            .notification-close {
                background: none;
                border: none;
                color: #8B7355;
                cursor: pointer;
                padding: 4px;
                border-radius: 6px;
                transition: all 0.3s ease;
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
            }
            
            .notification-close:hover {
                background: rgba(212, 160, 23, 0.1);
            }
            
            .notification-close i {
                font-size: 0.9em;
            }
        `;
        document.head.appendChild(style);
    }
</script>
</body>

</html>