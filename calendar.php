<?php
// calendar.php - Professional Interactive Leave Calendar (UPDATED TO MATCH LEAVES.PHP COLOR SCHEME)
$page_title = "Leave Calendar";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Get database connection
$pdo = getPDOConnection();

// Get all users for the dropdown
$users = [];
$user_stats = [];
$leave_types = [];
$all_leaves = [];

if ($pdo) {
    try {
        // Get active users
        $stmt = $pdo->query("
            SELECT id, full_name, email, department, position, status 
            FROM users 
            WHERE status = 'active' 
            ORDER BY full_name
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get leave types with colors
        $stmt = $pdo->query("SELECT id, name, color FROM leave_types WHERE is_active = 1");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get current year's leaves for all users
        $currentYear = date('Y');
        $stmt = $pdo->prepare("
            SELECT 
                l.*,
                u.full_name as employee_name,
                u.department as employee_department,
                u.position as employee_position,
                lt.name as leave_type_name,
                lt.color as leave_color,
                a.full_name as approved_by_name,
                CASE 
                    WHEN l.status = 'approved' THEN 'approved'
                    WHEN l.status = 'pending' THEN 'pending'
                    WHEN l.status = 'rejected' THEN 'rejected'
                    ELSE 'cancelled'
                END as status_class
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            JOIN leave_types lt ON l.leave_type_id = lt.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE YEAR(l.start_date) = ? OR YEAR(l.end_date) = ?
            ORDER BY l.start_date ASC
        ");
        $stmt->execute([$currentYear, $currentYear]);
        $all_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group leaves by user for stats
        foreach ($all_leaves as $leave) {
            $userId = $leave['user_id'];
            if (!isset($user_stats[$userId])) {
                $user_stats[$userId] = [
                    'total_leaves' => 0,
                    'approved_leaves' => 0,
                    'pending_leaves' => 0,
                    'rejected_leaves' => 0,
                    'total_days' => 0
                ];
            }

            $user_stats[$userId]['total_leaves']++;
            $user_stats[$userId]['total_days'] += $leave['total_days'] ?? 0;

            if ($leave['status'] == 'approved') {
                $user_stats[$userId]['approved_leaves']++;
            } elseif ($leave['status'] == 'pending') {
                $user_stats[$userId]['pending_leaves']++;
            } elseif ($leave['status'] == 'rejected') {
                $user_stats[$userId]['rejected_leaves']++;
            }
        }

    } catch (PDOException $e) {
        error_log("Error fetching calendar data: " . $e->getMessage());
    }
}

// Convert leaves to JSON for JavaScript
$leaves_json = json_encode($all_leaves ?? []);
$users_json = json_encode($users);
$leave_types_json = json_encode($leave_types ?? []);
?>

<style>
    /* ===== CALENDAR STYLES WITH OCHER/DARK MUSTARD COLOR SCHEME ===== */
    /* Color Scheme - Matching leaves.php */
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

    /* Body styling with animated background */
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #f5f5dc 0%, #FAF3E0 100%);
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
        margin: 0;
        padding: 0;
    }

    /* Updated background pattern with ocher colors */
    body::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image: url("data:image/svg+xml,%3Csvg width='120' height='120' viewBox='0 0 120 120' xmlns='http://www.w3.org/2000/svg' opacity='0.05'%3E%3Cpath d='M60 15 L105 60 L60 105 L15 60 Z' fill='none' stroke='%23D4A017' stroke-width='1.5'/%3E%3Ccircle cx='60' cy='60' r='20' fill='none' stroke='%23B8860B' stroke-width='1.5'/%3E%3C/svg%3E");
        background-size: 120px;
        z-index: -3;
    }

    /* Floating icons with ocher color scheme */
    .floating-icons {
        position: fixed;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: -2;
    }

    .floating-icon {
        position: absolute;
        opacity: 0.12;
        font-size: 2.5rem;
        animation: float 25s infinite linear;
        filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
    }

    .floating-icon:nth-child(1) {
        top: 5%;
        left: 8%;
        animation-delay: 0s;
        color: #D4A017;
        font-size: 3rem;
    }

    .floating-icon:nth-child(2) {
        top: 15%;
        right: 12%;
        animation-delay: -3s;
        color: #B8860B;
        font-size: 2.8rem;
    }

    .floating-icon:nth-child(3) {
        bottom: 25%;
        left: 10%;
        animation-delay: -5s;
        color: #FFD700;
        font-size: 3.2rem;
    }

    .floating-icon:nth-child(4) {
        top: 35%;
        right: 15%;
        animation-delay: -7s;
        color: #8B7355;
        font-size: 2.6rem;
    }

    .floating-icon:nth-child(5) {
        bottom: 15%;
        right: 8%;
        animation-delay: -9s;
        color: #CD853F;
        font-size: 3rem;
    }

    /* Geometric shapes with ocher colors */
    .geometric-shape {
        position: fixed;
        border-radius: 25px;
        opacity: 0.08;
        z-index: -2;
        filter: blur(0.5px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    }

    .shape-1 {
        width: 250px;
        height: 250px;
        background: linear-gradient(135deg, #D4A017, #B8860B);
        top: 5%;
        right: 10%;
        transform: rotate(45deg);
        animation: pulse 8s infinite alternate;
    }

    .shape-2 {
        width: 200px;
        height: 200px;
        background: linear-gradient(135deg, #8B7355, #CD853F);
        bottom: 15%;
        left: 8%;
        transform: rotate(30deg);
        border-radius: 40% 60% 60% 40% / 60% 30% 70% 40%;
        animation: pulse 10s infinite alternate-reverse;
    }

    .shape-3 {
        width: 180px;
        height: 180px;
        background: linear-gradient(135deg, #FFD700, #F5DEB3);
        top: 65%;
        right: 20%;
        transform: rotate(60deg);
        border-radius: 50%;
        animation: pulse 12s infinite alternate;
        opacity: 0.06;
    }

    /* Grid lines */
    .grid-lines {
        position: fixed;
        width: 100%;
        height: 100%;
        background-image:
            linear-gradient(to right, rgba(212, 160, 23, 0.05) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(212, 160, 23, 0.05) 1px, transparent 1px);
        background-size: 60px 60px;
        z-index: -3;
    }

    /* Dots pattern */
    .dots-pattern {
        position: fixed;
        width: 100%;
        height: 100%;
        background-image:
            radial-gradient(rgba(184, 134, 11, 0.08) 2px, transparent 2px),
            radial-gradient(rgba(212, 160, 23, 0.06) 1.5px, transparent 1.5px);
        background-size: 40px 40px, 25px 25px;
        background-position: 0 0, 20px 20px;
        z-index: -3;
    }

    /* Decorative lines */
    .decorative-line {
        position: fixed;
        height: 2px;
        z-index: -2;
        opacity: 0.1;
    }

    .line-1 {
        top: 30%;
        left: 10%;
        right: 10%;
        width: 80%;
        background: linear-gradient(90deg, transparent, #D4A017, transparent);
    }

    .line-2 {
        bottom: 40%;
        left: 5%;
        right: 5%;
        width: 90%;
        background: linear-gradient(90deg, transparent, #B8860B, transparent);
    }

    /* Animations */
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
            opacity: 0.12;
            transform: scale(1.05) rotate(var(--rotation, 45deg));
        }

        100% {
            opacity: 0.06;
            transform: scale(1) rotate(var(--rotation, 45deg));
        }
    }

    /* Calendar Page Container */
    .calendar-page {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 100%;
        padding: 20px;
        margin: 0;
        box-sizing: border-box;
    }

    /* Calendar Header - Updated with ocher color scheme */
    .calendar-header {
        background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%);
        border-radius: 16px 16px 0 0;
        padding: 25px 30px;
        margin-bottom: 0;
        box-shadow: 0 10px 40px rgba(212, 160, 23, 0.15);
        border: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        color: white;
        position: relative;
        overflow: hidden;
        width: 100%;
        backdrop-filter: blur(8px);
    }

    .calendar-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,50 Q25,40 50,50 T100,50" stroke="rgba(255,255,255,0.1)" stroke-width="2" fill="none"/></svg>');
        opacity: 0.3;
    }

    .calendar-title h1 {
        font-family: 'Playfair Display', serif;
        font-size: 2.2em;
        color: white;
        margin-bottom: 5px;
        font-weight: 700;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .calendar-title p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 0.95em;
        margin: 0;
    }

    /* Calendar Controls - Updated */
    .calendar-controls {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
        background: rgba(255, 255, 255, 0.15);
        padding: 12px;
        border-radius: 12px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-nav {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .nav-btn {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 1.1em;
        font-weight: 600;
    }

    .nav-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 255, 255, 0.2);
    }

    .month-year {
        min-width: 220px;
        text-align: center;
        font-size: 1.3em;
        font-weight: 600;
        color: white;
        padding: 12px 25px;
        background: rgba(255, 255, 255, 0.15);
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        text-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .today-btn {
        padding: 12px 24px;
        background: rgba(255, 255, 255, 0.25);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.95em;
    }

    .today-btn:hover {
        background: rgba(255, 255, 255, 0.35);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 255, 255, 0.2);
    }

    /* View Type Selector - Updated */
    .view-selector {
        display: flex;
        background: rgba(255, 255, 255, 0.15);
        border-radius: 12px;
        padding: 6px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        backdrop-filter: blur(10px);
    }

    .view-btn {
        padding: 10px 24px;
        border: none;
        background: transparent;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 500;
        cursor: pointer;
        border-radius: 10px;
        transition: all 0.3s ease;
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .view-btn.active {
        background: rgba(255, 255, 255, 0.25);
        color: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .view-btn:hover:not(.active) {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    /* Filters Section - Updated */
    .calendar-filters {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 25px;
        padding: 25px;
        background: rgba(255, 255, 255, 0.85);
        border-radius: 0 0 16px 16px;
        box-shadow: 0 8px 30px rgba(212, 160, 23, 0.08);
        border: 1px solid rgba(212, 160, 23, 0.2);
        border-top: none;
        width: 100%;
        box-sizing: border-box;
        backdrop-filter: blur(8px);
    }

    .filter-group {
        flex: 1;
        min-width: 280px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .employee-select {
        width: 100%;
        padding: 14px;
        border-radius: 12px;
        border: 2px solid var(--color-border);
        background: var(--color-white);
        font-size: 0.95em;
        color: var(--color-text);
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .employee-select:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15), 0 4px 12px rgba(212, 160, 23, 0.08);
    }

    .filter-buttons {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .filter-btn {
        padding: 12px 24px;
        border-radius: 12px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.95em;
    }

    .filter-btn.apply {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.2);
    }

    .filter-btn.clear {
        background: white;
        color: var(--color-primary-dark);
        border: 2px solid var(--color-border);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .filter-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .filter-btn.apply:hover {
        background: linear-gradient(135deg, var(--color-primary-dark), #D2691E);
        box-shadow: 0 8px 25px rgba(212, 160, 23, 0.3);
    }

    .filter-btn.clear:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
        box-shadow: 0 8px 25px rgba(212, 160, 23, 0.2);
    }

    /* Professional Calendar Grid - Updated */
    .calendar-grid {
        background: var(--color-white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 15px 50px rgba(212, 160, 23, 0.08);
        border: 1px solid var(--color-border);
        margin-bottom: 30px;
        position: relative;
        width: 100%;
        box-sizing: border-box;
    }

    .calendar-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.9), rgba(184, 134, 11, 0.95));
        color: white;
        padding: 18px 0;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9em;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.1);
        width: 100%;
    }

    .weekday {
        text-align: center;
        padding: 8px;
        position: relative;
    }

    .weekday::after {
        content: '';
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 1px;
        height: 60%;
        background: rgba(255, 255, 255, 0.2);
    }

    .weekday:last-child::after {
        display: none;
    }

    /* Professional Calendar Days - Updated */
    .calendar-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        grid-auto-rows: 150px;
        background: var(--color-white);
        position: relative;
        width: 100%;
    }

    .calendar-day {
        padding: 12px;
        border: 1px solid var(--color-border);
        background: var(--color-white);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        animation: fadeInDay 0.5s ease-out;
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
    }

    .calendar-day:hover {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.03), rgba(212, 160, 23, 0.01));
        z-index: 2;
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(212, 160, 23, 0.08);
        border-color: rgba(212, 160, 23, 0.3);
    }

    .calendar-day.today {
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.1), rgba(212, 160, 23, 0.05));
        border: 2px solid var(--color-primary);
        z-index: 3;
    }

    .calendar-day.other-month {
        background: linear-gradient(135deg, rgba(245, 245, 220, 0.5), rgba(212, 160, 23, 0.1));
        color: #8B7355;
        opacity: 0.7;
    }

    .calendar-day .date-number {
        font-weight: 700;
        font-size: 1.3em;
        color: var(--color-text);
        margin-bottom: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        text-align: center;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(212, 160, 23, 0.05), rgba(212, 160, 23, 0.02));
        transition: all 0.3s ease;
    }

    .calendar-day:hover .date-number {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        transform: scale(1.05);
    }

    .calendar-day.today .date-number {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        box-shadow: 0 4px 15px rgba(212, 160, 23, 0.3);
    }

    .calendar-day.weekend {
        background: linear-gradient(135deg, rgba(139, 115, 85, 0.05), rgba(139, 115, 85, 0.02));
    }

    /* Professional Leave Events - Updated colors */
    .leave-events {
        flex: 1;
        overflow-y: auto;
        margin-top: 5px;
        padding-right: 5px;
        width: 100%;
    }

    .leave-events::-webkit-scrollbar {
        width: 4px;
    }

    .leave-events::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.03);
        border-radius: 2px;
    }

    .leave-events::-webkit-scrollbar-thumb {
        background: rgba(212, 160, 23, 0.2);
        border-radius: 2px;
    }

    .leave-events::-webkit-scrollbar-thumb:hover {
        background: rgba(212, 160, 23, 0.4);
    }

    .leave-event {
        font-size: 0.8em;
        padding: 6px 10px;
        margin-bottom: 6px;
        border-radius: 8px;
        color: white;
        cursor: pointer;
        transition: all 0.3s ease;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        position: relative;
        border-left: 3px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        font-weight: 500;
        width: 100%;
        box-sizing: border-box;
    }

    .leave-event:hover {
        transform: translateX(3px) translateY(-1px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        z-index: 1;
    }

    .leave-event .employee-name {
        font-weight: 600;
        font-size: 1em;
        margin-bottom: 2px;
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .leave-event .leave-type {
        font-size: 0.85em;
        opacity: 0.9;
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .leave-event.status-pending {
        background: linear-gradient(135deg, #F59E0B, #CD853F);
    }

    .leave-event.status-approved {
        background: linear-gradient(135deg, #10B981, #B8860B);
    }

    .leave-event.status-rejected {
        background: linear-gradient(135deg, #EF4444, #8B4513);
    }

    .leave-event.multi-day-start {
        border-top-left-radius: 8px;
        border-bottom-left-radius: 8px;
    }

    .leave-event.multi-day-end {
        border-top-right-radius: 8px;
        border-bottom-right-radius: 8px;
    }

    .leave-event.multi-day-middle {
        border-radius: 0;
        border-left: none;
    }

    /* Event Count Badge */
    .event-count {
        position: absolute;
        top: 15px;
        right: 15px;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        font-size: 0.75em;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 3px 10px rgba(212, 160, 23, 0.3);
        z-index: 2;
    }

    /* Professional Legend - Updated */
    .calendar-legend {
        display: flex;
        gap: 25px;
        flex-wrap: wrap;
        padding: 25px;
        background: var(--color-white);
        border-radius: 16px;
        box-shadow: 0 8px 30px rgba(212, 160, 23, 0.08);
        border: 1px solid var(--color-border);
        margin-bottom: 30px;
        width: 100%;
        box-sizing: border-box;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 20px;
        background: linear-gradient(135deg, rgba(245, 245, 220, 0.5), rgba(212, 160, 23, 0.1));
        border-radius: 12px;
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
    }

    .legend-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        border-color: var(--color-primary);
    }

    .legend-color {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .legend-label {
        font-size: 0.9em;
        color: var(--color-text);
        font-weight: 500;
    }

    /* Professional Stats Cards - Updated */
    .calendar-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
        width: 100%;
    }

    .stat-card-calendar {
        background: var(--color-white);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(212, 160, 23, 0.08);
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        width: 100%;
        box-sizing: border-box;
    }

    .stat-card-calendar::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, var(--color-primary), var(--color-primary-dark));
    }

    .stat-card-calendar:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 60px rgba(212, 160, 23, 0.12);
        border-color: var(--color-primary);
    }

    .stat-card-calendar .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 2;
    }

    .stat-card-calendar .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6em;
        color: white;
        box-shadow: 0 6px 20px rgba(212, 160, 23, 0.2);
        transition: all 0.3s ease;
    }

    .stat-card-calendar:hover .stat-icon {
        transform: scale(1.05) rotate(5deg);
    }

    .stat-card-calendar .stat-value {
        font-size: 2.2em;
        font-weight: 700;
        color: var(--color-text);
        margin-bottom: 8px;
        position: relative;
        z-index: 2;
    }

    .stat-card-calendar .stat-label {
        font-size: 0.95em;
        color: var(--color-secondary);
        position: relative;
        z-index: 2;
    }

    /* Professional Modal - Updated */
    .leave-details-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(10px);
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .leave-details-content {
        background: var(--color-white);
        border-radius: 16px;
        width: 90%;
        max-width: 500px;
        max-height: 85vh;
        overflow-y: auto;
        padding: 35px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.25);
        border: 1px solid var(--color-border);
        animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        box-sizing: border-box;
        backdrop-filter: blur(10px);
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-40px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--color-border);
    }

    .modal-header h3 {
        color: var(--color-text);
        font-size: 1.5em;
        margin: 0;
        font-weight: 700;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .close-modal {
        background: white;
        border: 2px solid var(--color-border);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4em;
        color: var(--color-primary-dark);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .close-modal:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        transform: rotate(90deg);
        border-color: var(--color-primary);
    }

    .modal-details {
        margin-bottom: 30px;
        width: 100%;
    }

    .detail-row {
        display: flex;
        margin-bottom: 18px;
        padding-bottom: 18px;
        border-bottom: 1px solid var(--color-border);
        transition: all 0.3s ease;
        width: 100%;
    }

    .detail-row:hover {
        border-bottom-color: var(--color-primary);
        padding-left: 5px;
    }

    .detail-label {
        width: 150px;
        font-weight: 600;
        color: var(--color-text);
        font-size: 0.95em;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    .detail-value {
        flex: 1;
        color: var(--color-text);
        font-size: 0.95em;
        min-width: 0;
    }

    .modal-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        padding-top: 25px;
        border-top: 2px solid var(--color-border);
        width: 100%;
    }

    .modal-btn {
        padding: 12px 28px;
        border-radius: 12px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.95em;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .modal-btn.view {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
    }

    .modal-btn.close {
        background: white;
        color: var(--color-primary-dark);
        border: 2px solid var(--color-border);
    }

    .modal-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .modal-btn.view:hover {
        background: linear-gradient(135deg, var(--color-primary-dark), #D2691E);
    }

    .modal-btn.close:hover {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        border-color: var(--color-primary);
    }

    /* Year View Styles - Updated */
    .year-view-container {
        display: none;
        background: var(--color-white);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 15px 50px rgba(212, 160, 23, 0.08);
        border: 1px solid var(--color-border);
        margin-bottom: 30px;
        width: 100%;
        box-sizing: border-box;
    }

    .year-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 30px;
        width: 100%;
    }

    .year-month {
        background: linear-gradient(135deg, rgba(245, 245, 220, 0.5), rgba(212, 160, 23, 0.1));
        border-radius: 16px;
        padding: 20px;
        border: 1px solid var(--color-border);
        transition: all 0.3s ease;
        width: 100%;
        box-sizing: border-box;
    }

    .year-month:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(212, 160, 23, 0.08);
        border-color: var(--color-primary);
    }

    .year-month-header {
        text-align: center;
        font-size: 1.1em;
        font-weight: 600;
        color: var(--color-text);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--color-border);
    }

    .year-month-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 5px;
        width: 100%;
    }

    .year-day {
        width: 100%;
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8em;
        color: var(--color-text);
        border-radius: 6px;
        transition: all 0.3s ease;
        position: relative;
        box-sizing: border-box;
        background: var(--color-white);
    }

    .year-day.has-leave {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 3px 10px rgba(212, 160, 23, 0.2);
    }

    .year-day.has-leave:hover {
        transform: scale(1.1);
        box-shadow: 0 5px 15px rgba(212, 160, 23, 0.3);
    }

    .year-day.weekend {
        color: var(--color-secondary);
        opacity: 0.6;
        background: rgba(245, 245, 220, 0.5);
    }

    /* Loading Animation */
    .loading-calendar {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 20px;
        width: 100%;
    }

    .loading-calendar .spinner {
        display: inline-block;
        width: 50px;
        height: 50px;
        border: 3px solid rgba(212, 160, 23, 0.2);
        border-radius: 50%;
        border-top-color: var(--color-primary);
        animation: spin 1s cubic-bezier(0.68, -0.55, 0.27, 1.55) infinite;
        margin-bottom: 20px;
    }

    .loading-calendar p {
        color: var(--color-secondary);
        font-size: 0.95em;
    }

    /* Empty State - Updated */
    .empty-calendar {
        grid-column: 1 / -1;
        text-align: center;
        padding: 80px 20px;
        color: var(--color-secondary);
        width: 100%;
    }

    .empty-calendar i {
        font-size: 5em;
        color: var(--color-border);
        margin-bottom: 25px;
        opacity: 0.7;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .empty-calendar h3 {
        color: var(--color-text);
        margin-bottom: 15px;
        font-size: 1.4em;
        font-weight: 700;
    }

    .empty-calendar p {
        max-width: 400px;
        margin: 0 auto 25px;
        font-size: 0.95em;
        line-height: 1.6;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .calendar-days {
            grid-auto-rows: 130px;
        }

        .year-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 992px) {
        .calendar-header {
            flex-direction: column;
            align-items: stretch;
            gap: 20px;
            padding: 20px;
        }

        .calendar-controls {
            flex-direction: column;
            align-items: stretch;
            padding: 15px;
        }

        .calendar-nav {
            justify-content: center;
        }

        .view-selector {
            justify-content: center;
        }

        .calendar-days {
            grid-auto-rows: 110px;
        }

        .calendar-filters {
            flex-direction: column;
            padding: 20px;
        }

        .filter-group {
            min-width: 100%;
        }

        .calendar-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .year-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .calendar-days {
            grid-auto-rows: 90px;
        }

        .calendar-day {
            padding: 8px;
        }

        .calendar-day .date-number {
            width: 30px;
            height: 30px;
            font-size: 1.1em;
        }

        .leave-event {
            font-size: 0.7em;
            padding: 4px 6px;
        }

        .event-count {
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            font-size: 0.7em;
        }

        .calendar-legend {
            flex-direction: column;
            gap: 15px;
            padding: 20px;
        }

        .calendar-stats {
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .year-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 576px) {
        .calendar-days {
            grid-auto-rows: 80px;
        }

        .calendar-weekdays {
            font-size: 0.8em;
            padding: 15px 0;
        }

        .calendar-day .date-number {
            width: 26px;
            height: 26px;
            font-size: 1em;
        }

        .leave-event {
            font-size: 0.65em;
            padding: 3px 5px;
            margin-bottom: 3px;
        }

        .event-count {
            top: 8px;
            right: 8px;
            width: 18px;
            height: 18px;
            font-size: 0.65em;
        }

        .month-year {
            min-width: 180px;
            font-size: 1.1em;
        }

        .nav-btn {
            width: 40px;
            height: 40px;
        }

        .today-btn {
            padding: 10px 20px;
        }

        .view-btn {
            padding: 8px 16px;
        }
    }

    /* Custom Animations */
    @keyframes fadeInDay {
        from {
            opacity: 0;
            transform: translateY(15px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .calendar-day {
        animation: fadeInDay 0.5s ease-out;
    }

    @keyframes spin {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    /* Badge for leave count */
    .leave-count-badge {
        position: absolute;
        top: 5px;
        right: 5px;
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: white;
        font-size: 0.7em;
        padding: 2px 6px;
        border-radius: 10px;
        font-weight: 600;
        z-index: 2;
    }

    /* Employee name in single view */
    .employee-name-badge {
        display: inline-block;
        background: rgba(255, 255, 255, 0.2);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75em;
        margin-right: 5px;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
</style>

<!-- ANIMATED BACKGROUND ELEMENTS -->
<div class="floating-icons">
    <i class="floating-icon fas fa-calendar-alt" title="Calendar"></i>
    <i class="floating-icon fas fa-users" title="Team"></i>
    <i class="floating-icon fas fa-clock" title="Time Off"></i>
    <i class="floating-icon fas fa-chart-bar" title="Analytics"></i>
    <i class="floating-icon fas fa-file-signature" title="Approval"></i>
</div>

<div class="geometric-shape shape-1" style="--rotation: 45deg;"></div>
<div class="geometric-shape shape-2" style="--rotation: 30deg;"></div>
<div class="geometric-shape shape-3" style="--rotation: 60deg;"></div>

<div class="grid-lines"></div>
<div class="dots-pattern"></div>

<div class="decorative-line line-1"></div>
<div class="decorative-line line-2"></div>

<div class="calendar-page">
    <!-- Calendar Header -->
    <div class="calendar-header">
        <div class="calendar-title">
            <h1><i class="fas fa-calendar-alt"></i> Leave Calendar</h1>
            <p>Visualize employee leaves in an interactive timeline view</p>
        </div>
        <div class="calendar-controls">
            <div class="calendar-nav">
                <button class="nav-btn" id="prevYear" title="Previous Year">
                    <i class="fas fa-angle-double-left"></i>
                </button>
                <button class="nav-btn" id="prevMonth" title="Previous Month">
                    <i class="fas fa-angle-left"></i>
                </button>
                <div class="month-year" id="currentMonthYear">
                    <?php echo date('F Y'); ?>
                </div>
                <button class="nav-btn" id="nextMonth" title="Next Month">
                    <i class="fas fa-angle-right"></i>
                </button>
                <button class="nav-btn" id="nextYear" title="Next Year">
                    <i class="fas fa-angle-double-right"></i>
                </button>
            </div>
            <button class="today-btn" id="todayBtn">
                <i class="fas fa-calendar-day"></i> Go to Today
            </button>
            <div class="view-selector">
                <button class="view-btn active" data-view="month">
                    <i class="fas fa-calendar-alt"></i> Month View
                </button>
                <button class="view-btn" data-view="year">
                    <i class="fas fa-calendar"></i> Year View
                </button>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="calendar-filters">
        <div class="filter-group">
            <label for="employeeFilter">
                <i class="fas fa-user-tie"></i> Select Employee
            </label>
            <select id="employeeFilter" class="employee-select">
                <option value="all">All Employees (Team View)</option>
                <option value="multiple">Multiple Employees (Color Coded)</option>
                <optgroup label="Individual Employees">
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['full_name']); ?>
                            -
                            <?php echo htmlspecialchars($user['department'] ?? 'General'); ?>
                            (
                            <?php echo htmlspecialchars($user['position'] ?? 'Employee'); ?>)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="filter-group">
            <label for="statusFilter">
                <i class="fas fa-filter"></i> Leave Status
            </label>
            <select id="statusFilter" class="employee-select">
                <option value="all">All Statuses</option>
                <option value="approved">Approved Only</option>
                <option value="pending">Pending Only</option>
                <option value="rejected">Rejected Only</option>
            </select>
        </div>
        <div class="filter-buttons">
            <button class="filter-btn apply" id="applyFilters">
                <i class="fas fa-check-circle"></i> Apply Filters
            </button>
            <button class="filter-btn clear" id="clearFilters">
                <i class="fas fa-eraser"></i> Clear All
            </button>
        </div>
    </div>

    <!-- Calendar Legend -->
    <div class="calendar-legend">
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #10B981, #B8860B);"></div>
            <span class="legend-label"><i class="fas fa-check-circle"></i> Approved Leaves</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #F59E0B, #CD853F);"></div>
            <span class="legend-label"><i class="fas fa-clock"></i> Pending Leaves</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #EF4444, #8B4513);"></div>
            <span class="legend-label"><i class="fas fa-times-circle"></i> Rejected Leaves</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #D4A017, #B8860B);"></div>
            <span class="legend-label"><i class="fas fa-calendar-day"></i> Today's Date</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #8B7355, #D4A017);"></div>
            <span class="legend-label"><i class="fas fa-users"></i> Multiple Employees</span>
        </div>
    </div>

    <!-- Month View -->
    <div class="calendar-grid" id="monthView">
        <div class="calendar-weekdays">
            <div class="weekday">Sunday</div>
            <div class="weekday">Monday</div>
            <div class="weekday">Tuesday</div>
            <div class="weekday">Wednesday</div>
            <div class="weekday">Thursday</div>
            <div class="weekday">Friday</div>
            <div class="weekday">Saturday</div>
        </div>
        <div class="calendar-days" id="calendarDays">
            <!-- Calendar days will be generated by JavaScript -->
            <div class="loading-calendar">
                <div class="spinner"></div>
                <p>Loading beautiful calendar...</p>
            </div>
        </div>
    </div>

    <!-- Year View -->
    <div class="year-view-container" id="yearView">
        <div class="year-grid" id="yearGrid">
            <!-- Year view will be generated by JavaScript -->
            <div class="loading-calendar">
                <div class="spinner"></div>
                <p>Loading year view...</p>
            </div>
        </div>
    </div>

    <!-- Calendar Stats -->
    <div class="calendar-stats">
        <div class="stat-card-calendar" id="totalLeavesCard">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo count($all_leaves); ?>
                    </div>
                    <div class="stat-label">Total Leaves</div>
                </div>
                <div class="stat-icon" style="background: linear-gradient(135deg, #D4A017, #B8860B);">
                    <i class="fas fa-calendar-alt"></i>
                </div>
            </div>
        </div>
        <div class="stat-card-calendar" id="approvedLeavesCard">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo count(array_filter($all_leaves, function ($leave) {
                            return $leave['status'] === 'approved';
                        })); ?>
                    </div>
                    <div class="stat-label">Approved Leaves</div>
                </div>
                <div class="stat-icon" style="background: linear-gradient(135deg, #10B981, #B8860B);">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
        <div class="stat-card-calendar" id="pendingLeavesCard">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo count(array_filter($all_leaves, function ($leave) {
                            return $leave['status'] === 'pending';
                        })); ?>
                    </div>
                    <div class="stat-label">Pending Leaves</div>
                </div>
                <div class="stat-icon" style="background: linear-gradient(135deg, #F59E0B, #CD853F);">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
        <div class="stat-card-calendar" id="leaveDaysCard">
            <div class="stat-header">
                <div>
                    <div class="stat-value">
                        <?php echo array_sum(array_column($all_leaves, 'total_days')); ?>
                    </div>
                    <div class="stat-label">Total Leave Days</div>
                </div>
                <div class="stat-icon" style="background: linear-gradient(135deg, #8B7355, #D4A017);">
                    <i class="fas fa-calendar-day"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Details Modal -->
    <div class="leave-details-modal" id="leaveDetailsModal">
        <div class="leave-details-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Leave Details</h3>
                <button class="close-modal" id="closeModal">&times;</button>
            </div>
            <div class="modal-details" id="modalDetails">
                <!--  Details will be filled by JavaScript -->
            </div>
            <div class="modal-actions">
                <button class="modal-btn view" id="viewLeaveBtn">
                    <i class="fas fa-external-link-alt"></i> View Full Details
                </button>
                <button class="modal-btn close" id="closeModalBtn">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ========== GLOBAL VARIABLES ==========
    let currentDate = new Date();
    let currentView = 'month';
    let allLeaves = <?php echo $leaves_json ?: '[]'; ?>;
    let allUsers = <?php echo $users_json ?: '[]'; ?>;
    let allLeaveTypes = <?php echo $leave_types_json ?: '[]'; ?>;
    let filteredLeaves = [];
    let selectedUserId = 'all';
    let selectedStatus = 'all';
    let userColors = {};
    let activeLeaveId = null;

    // Professional color palette for employees - Matching leaves.php
    const professionalColors = [
        '#D4A017', // Golden Rod
        '#B8860B', // Dark Golden Rod
        '#FFD700', // Gold
        '#8B7355', // Tan Brown
        '#CD853F', // Peru
        '#D2691E', // Chocolate
        '#8B4513', // Saddle Brown
        '#A0522D', // Sienna
        '#F4A460', // Sandy Brown
        '#DEB887', // Burlywood
        '#F5DEB3', // Wheat
        '#FFF8DC', // Cornsilk
        '#FFEBCD', // Blanched Almond
        '#FFE4C4', // Bisque
        '#FFDAB9', // Peach Puff
        '#EEE8AA'  // Pale Golden Rod
    ];

    // ========== UTILITY FUNCTIONS ==========
    function formatDate(date) {
        return date.toLocaleDateString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function getMonthYearString(date) {
        return date.toLocaleDateString('en-US', {
            month: 'long',
            year: 'numeric'
        });
    }

    function getDayClass(dayDate) {
        const classes = [];
        const today = new Date();

        // Check if today
        if (dayDate.toDateString() === today.toDateString()) {
            classes.push('today');
        }

        // Check if weekend
        const dayOfWeek = dayDate.getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            classes.push('weekend');
        }

        return classes.join(' ');
    }

    function generateUserColors() {
        // Assign professional colors to users
        allUsers.forEach((user, index) => {
            userColors[user.id] = professionalColors[index % professionalColors.length];
        });
    }

    // ========== FILTER FUNCTIONS ==========
    function applyFilters() {
        filteredLeaves = allLeaves.filter(leave => {
            // Filter by employee
            if (selectedUserId !== 'all' && selectedUserId !== 'multiple') {
                if (parseInt(leave.user_id) !== parseInt(selectedUserId)) {
                    return false;
                }
            }

            // Filter by status
            if (selectedStatus !== 'all') {
                if (leave.status !== selectedStatus) {
                    return false;
                }
            }

            return true;
        });

        updateCalendar();
        updateStats();
    }

    function getLeavesForDay(dayDate) {
        return filteredLeaves.filter(leave => {
            const startDate = new Date(leave.start_date);
            const endDate = new Date(leave.end_date);

            // Check if day is within leave range
            return dayDate >= startDate && dayDate <= endDate;
        });
    }

    // ========== CALENDAR RENDERING ==========
    function renderMonthCalendar() {
        const calendarDays = document.getElementById('calendarDays');
        calendarDays.innerHTML = '';

        // Get first day of month and last day of month
        const firstDay = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        const lastDay = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);

        // Get day of week for first day (0 = Sunday, 6 = Saturday)
        const firstDayOfWeek = firstDay.getDay();

        // Calculate total days to show (6 weeks = 42 days for consistent layout)
        const totalDays = 42;

        // Calculate start date (might be in previous month)
        const startDate = new Date(firstDay);
        startDate.setDate(startDate.getDate() - firstDayOfWeek);

        // Update month/year display
        document.getElementById('currentMonthYear').textContent = getMonthYearString(currentDate);

        // Generate calendar days
        for (let i = 0; i < totalDays; i++) {
            const currentDay = new Date(startDate);
            currentDay.setDate(startDate.getDate() + i);

            const dayElement = document.createElement('div');
            dayElement.className = 'calendar-day';

            // Add classes
            const dayClass = getDayClass(currentDay);
            if (dayClass) {
                dayElement.className += ' ' + dayClass;
            }

            // Check if day is in current month
            if (currentDay.getMonth() !== currentDate.getMonth()) {
                dayElement.classList.add('other-month');
            }

            // Add date number
            const dateNumber = document.createElement('div');
            dateNumber.className = 'date-number';
            dateNumber.textContent = currentDay.getDate();
            dayElement.appendChild(dateNumber);

            // Get leaves for this day
            const dayLeaves = getLeavesForDay(currentDay);

            // Add event count badge if there are leaves
            if (dayLeaves.length > 0) {
                const eventCount = document.createElement('div');
                eventCount.className = 'event-count';
                eventCount.textContent = dayLeaves.length;
                eventCount.title = `${dayLeaves.length} leave(s) on this day`;
                dayElement.appendChild(eventCount);
            }

            // Add leave events
            const eventsContainer = document.createElement('div');
            eventsContainer.className = 'leave-events';

            // Show leaves based on view mode
            if (selectedUserId === 'multiple') {
                // Show all leaves with colors
                dayLeaves.slice(0, 4).forEach(leave => {
                    const event = createLeaveEvent(leave, currentDay);
                    eventsContainer.appendChild(event);
                });

                // Show "more" indicator if there are more leaves
                if (dayLeaves.length > 4) {
                    const moreIndicator = document.createElement('div');
                    moreIndicator.className = 'leave-event status-approved';
                    moreIndicator.style.background = 'linear-gradient(135deg, #8B7355, #D4A017)';
                    moreIndicator.innerHTML = `
                        <span class="employee-name">+${dayLeaves.length - 4} more</span>
                    `;
                    moreIndicator.title = `Click to view ${dayLeaves.length} leaves on this day`;
                    moreIndicator.onclick = (e) => {
                        e.stopPropagation();
                        showDayLeaves(dayLeaves, currentDay);
                    };
                    eventsContainer.appendChild(moreIndicator);
                }
            } else if (selectedUserId === 'all') {
                // Team view - show count of leaves
                if (dayLeaves.length > 0) {
                    const teamEvent = document.createElement('div');
                    teamEvent.className = 'leave-event status-approved';
                    teamEvent.style.background = 'linear-gradient(135deg, #D4A017, #B8860B)';
                    teamEvent.innerHTML = `
                        <span class="employee-name">${dayLeaves.length} Employee(s)</span>
                        <span class="leave-type">On Leave</span>
                    `;
                    teamEvent.title = `${dayLeaves.length} employee(s) on leave`;
                    teamEvent.onclick = (e) => {
                        e.stopPropagation();
                        showDayLeaves(dayLeaves, currentDay);
                    };
                    eventsContainer.appendChild(teamEvent);
                }
            } else {
                // Single employee view
                dayLeaves.forEach(leave => {
                    const event = createLeaveEvent(leave, currentDay);
                    eventsContainer.appendChild(event);
                });
            }

            dayElement.appendChild(eventsContainer);

            // Add click event to day
            dayElement.onclick = () => {
                if (dayLeaves.length > 0) {
                    showDayLeaves(dayLeaves, currentDay);
                }
            };

            calendarDays.appendChild(dayElement);
        }
    }

    function createLeaveEvent(leave, currentDay) {
        const event = document.createElement('div');
        event.className = `leave-event status-${leave.status}`;

        // Determine event position for multi-day leaves
        const leaveStart = new Date(leave.start_date);
        const leaveEnd = new Date(leave.end_date);

        if (currentDay.getTime() === leaveStart.getTime()) {
            event.classList.add('multi-day-start');
        } else if (currentDay.getTime() === leaveEnd.getTime()) {
            event.classList.add('multi-day-end');
        } else if (currentDay > leaveStart && currentDay < leaveEnd) {
            event.classList.add('multi-day-middle');
        }

        // Set background color based on user or status
        if (selectedUserId === 'multiple') {
            event.style.background = userColors[leave.user_id] || '#8B7355';
        }

        // Set event content - ALWAYS SHOW NAME
        if (selectedUserId === 'multiple' || selectedUserId === 'all') {
            event.innerHTML = `
                <span class="employee-name">${leave.employee_name.split(' ')[0]}</span>
                <span class="leave-type">${leave.leave_type_name}</span>
            `;
            event.title = `${leave.employee_name} - ${leave.leave_type_name} (${leave.status})`;
        } else {
            event.innerHTML = `
                <span class="employee-name">${leave.employee_name}</span>
                <span class="leave-type">${leave.leave_type_name}</span>
            `;
            event.title = `${leave.leave_type_name} (${leave.status})`;
        }

        // Add click event to show details
        event.onclick = (e) => {
            e.stopPropagation();
            showLeaveDetails(leave);
        };

        return event;
    }

    function renderYearCalendar() {
        const yearGrid = document.getElementById('yearGrid');
        yearGrid.innerHTML = '';

        const year = currentDate.getFullYear();

        // Create 12 months
        for (let month = 0; month < 12; month++) {
            const monthDiv = document.createElement('div');
            monthDiv.className = 'year-month';

            // Month header
            const monthName = new Date(year, month, 1).toLocaleDateString('en-US', { month: 'long' });
            const header = document.createElement('div');
            header.className = 'year-month-header';
            header.textContent = `${monthName} ${year}`;
            monthDiv.appendChild(header);

            // Month days grid
            const daysGrid = document.createElement('div');
            daysGrid.className = 'year-month-days';

            // Get first day of month
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);

            // Calculate starting day of week (0 = Sunday)
            const startDay = firstDay.getDay();

            // Add empty cells for days before month starts
            for (let i = 0; i < startDay; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'year-day';
                daysGrid.appendChild(emptyCell);
            }

            // Add days of month
            for (let day = 1; day <= lastDay.getDate(); day++) {
                const dayCell = document.createElement('div');
                dayCell.className = 'year-day';
                dayCell.textContent = day;

                // Check if weekend
                const dayDate = new Date(year, month, day);
                if (dayDate.getDay() === 0 || dayDate.getDay() === 6) {
                    dayCell.classList.add('weekend');
                }

                // Check if today
                const today = new Date();
                if (dayDate.getFullYear() === today.getFullYear() &&
                    dayDate.getMonth() === today.getMonth() &&
                    dayDate.getDate() === today.getDate()) {
                    dayCell.style.background = 'linear-gradient(135deg, #D4A017, #B8860B)';
                    dayCell.style.color = 'white';
                }

                // Check if has leaves
                const dayLeaves = getLeavesForDay(dayDate);
                if (dayLeaves.length > 0) {
                    dayCell.classList.add('has-leave');
                    dayCell.title = `${dayLeaves.length} leave(s) on ${monthName} ${day}`;
                    dayCell.onclick = () => {
                        if (dayLeaves.length === 1) {
                            showLeaveDetails(dayLeaves[0]);
                        } else {
                            showDayLeaves(dayLeaves, dayDate);
                        }
                    };
                }

                daysGrid.appendChild(dayCell);
            }

            monthDiv.appendChild(daysGrid);
            yearGrid.appendChild(monthDiv);
        }
    }

    // ========== VIEW MANAGEMENT ==========
    function switchView(view) {
        currentView = view;

        // Update active button
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.view === view) {
                btn.classList.add('active');
            }
        });

        // Show/hide views
        if (view === 'month') {
            document.getElementById('monthView').style.display = 'block';
            document.getElementById('yearView').style.display = 'none';
            renderMonthCalendar();
        } else {
            document.getElementById('monthView').style.display = 'none';
            document.getElementById('yearView').style.display = 'block';
            renderYearCalendar();
        }
    }

    // ========== STATS UPDATE ==========
    function updateStats() {
        const totalLeaves = filteredLeaves.length;
        const approvedLeaves = filteredLeaves.filter(l => l.status === 'approved').length;
        const pendingLeaves = filteredLeaves.filter(l => l.status === 'pending').length;
        const totalLeaveDays = filteredLeaves.reduce((sum, leave) => sum + (leave.total_days || 0), 0);

        document.getElementById('totalLeavesCard').querySelector('.stat-value').textContent = totalLeaves;
        document.getElementById('approvedLeavesCard').querySelector('.stat-value').textContent = approvedLeaves;
        document.getElementById('pendingLeavesCard').querySelector('.stat-value').textContent = pendingLeaves;
        document.getElementById('leaveDaysCard').querySelector('.stat-value').textContent = totalLeaveDays;
    }

    // ========== MODAL FUNCTIONS ==========
    function showLeaveDetails(leave) {
        activeLeaveId = leave.id;

        const modal = document.getElementById('leaveDetailsModal');
        const modalDetails = document.getElementById('modalDetails');

        const startDate = new Date(leave.start_date);
        const endDate = new Date(leave.end_date);
        const duration = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;

        const statusColors = {
            'approved': '#10B981',
            'pending': '#F59E0B',
            'rejected': '#EF4444'
        };

        const statusLabels = {
            'approved': 'Approved',
            'pending': 'Pending',
            'rejected': 'Rejected'
        };

        modalDetails.innerHTML = `
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-user"></i> Employee:</div>
                <div class="detail-value">
                    <strong style="font-size: 1.1em;">${leave.employee_name}</strong><br>
                    <small style="color: #8B7355;">
                        ${leave.employee_position || 'Employee'} | ${leave.employee_department || 'General'}
                    </small>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-tag"></i> Leave Type:</div>
                <div class="detail-value">
                    <span style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 12px; background: ${leave.leave_color || '#D4A017'}; color: white; font-weight: 600;">
                        <i class="fas fa-calendar-alt"></i>
                        ${leave.leave_type_name}
                    </span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-calendar"></i> Dates:</div>
                <div class="detail-value">
                    <div style="font-weight: 600;">${formatDate(startDate)}</div>
                    <div style="color: #8B7355; font-size: 0.9em;">
                        to ${formatDate(endDate)}
                    </div>
                    <div style="margin-top: 5px;">
                        <span style="background: rgba(212, 160, 23, 0.1); color: #D4A017; padding: 3px 10px; border-radius: 10px; font-weight: 600;">
                            ${duration} day${duration !== 1 ? 's' : ''}
                        </span>
                    </div>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-info-circle"></i> Status:</div>
                <div class="detail-value">
                    <span style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 12px; background: ${statusColors[leave.status]}; color: white; font-weight: 600;">
                        <i class="fas fa-${leave.status === 'approved' ? 'check' : leave.status === 'pending' ? 'clock' : 'times'}-circle"></i>
                        ${statusLabels[leave.status]}
                    </span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-user-check"></i> Approved By:</div>
                <div class="detail-value">
                    ${leave.approved_by_name ? `
                        <strong>${leave.approved_by_name}</strong><br>
                        <small style="color: #8B7355;">Approved on ${new Date(leave.approved_at).toLocaleDateString()}</small>
                    ` : 'Not approved yet'}
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-comment"></i> Reason:</div>
                <div class="detail-value">
                    ${leave.reason || 'No reason provided'}
                </div>
            </div>
            ${leave.approver_notes ? `
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-sticky-note"></i> Approver Notes:</div>
                <div class="detail-value">
                    <div style="background: rgba(212, 160, 23, 0.1); padding: 10px; border-radius: 8px; border-left: 4px solid #D4A017;">
                        ${leave.approver_notes}
                    </div>
                </div>
            </div>
            ` : ''}
            ${leave.half_day && leave.half_day !== 'none' ? `
            <div class="detail-row">
                <div class="detail-label"><i class="fas fa-clock"></i> Half Day:</div>
                <div class="detail-value">
                    <span style="background: rgba(139, 115, 85, 0.1); color: #8B7355; padding: 4px 12px; border-radius: 8px; font-weight: 600;">
                        ${leave.half_day.charAt(0).toUpperCase() + leave.half_day.slice(1)} Only
                    </span>
                </div>
            </div>
            ` : ''}
        `;

        // Update view button
        const viewBtn = document.getElementById('viewLeaveBtn');
        if (leave.status === 'pending') {
            viewBtn.innerHTML = '<i class="fas fa-check"></i> Review Leave';
            viewBtn.onclick = () => {
                window.location.href = `leave-approvals.php?highlight=${leave.id}`;
            };
        } else {
            viewBtn.innerHTML = '<i class="fas fa-external-link-alt"></i> View Full Details';
            viewBtn.onclick = () => {
                window.location.href = `view-leave.php?id=${leave.id}`;
            };
        }

        modal.style.display = 'flex';
    }

    function showDayLeaves(leaves, date) {
        if (leaves.length === 1) {
            showLeaveDetails(leaves[0]);
            return;
        }

        const modal = document.getElementById('leaveDetailsModal');
        const modalDetails = document.getElementById('modalDetails');

        modalDetails.innerHTML = `
            <div class="detail-row" style="border-bottom: 3px solid #D4A017; padding-bottom: 15px; margin-bottom: 20px;">
                <div class="detail-label" style="width: 100%; text-align: center; font-size: 1.2em; color: #D4A017; font-weight: 700;">
                    <i class="fas fa-calendar-day"></i> ${formatDate(date)}
                    <div style="font-size: 0.8em; color: #8B7355; margin-top: 5px; font-weight: normal;">
                        ${leaves.length} leave(s) scheduled
                    </div>
                </div>
            </div>
            ${leaves.map(leave => `
                <div class="detail-row" style="cursor: pointer; transition: all 0.3s ease;" 
                     onclick="showLeaveDetails(${JSON.stringify(leave).replace(/"/g, '&quot;')})"
                     onmouseover="this.style.backgroundColor='rgba(212, 160, 23, 0.05)'; this.style.transform='translateX(5px)';" 
                     onmouseout="this.style.backgroundColor='transparent'; this.style.transform='translateX(0)';">
                    <div class="detail-label" style="width: 100%;">
                        <div style="display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 10px; background: linear-gradient(135deg, rgba(212, 160, 23, 0.03), rgba(212, 160, 23, 0.01));">
                            <div style="width: 14px; height: 14px; border-radius: 50%; background: ${leave.status === 'approved' ? '#10B981' : leave.status === 'pending' ? '#F59E0B' : '#EF4444'}; box-shadow: 0 2px 8px ${leave.status === 'approved' ? 'rgba(16, 185, 129, 0.3)' : leave.status === 'pending' ? 'rgba(245, 158, 11, 0.3)' : 'rgba(239, 68, 68, 0.3)'};"></div>
                            <div style="flex: 1;">
                                <strong style="color: #2F2F2F;">${leave.employee_name}</strong>
                                <div style="font-size: 0.85em; color: #8B7355; margin-top: 2px;">
                                    ${leave.leave_type_name} | ${new Date(leave.start_date).toLocaleDateString()} - ${new Date(leave.end_date).toLocaleDateString()}
                                </div>
                            </div>
                            <div style="font-size: 0.8em; font-weight: 600; color: ${leave.status === 'approved' ? '#10B981' : leave.status === 'pending' ? '#F59E0B' : '#EF4444'};">
                                ${leave.status === 'approved' ? '<i class="fas fa-check-circle"></i>' : leave.status === 'pending' ? '<i class="fas fa-clock"></i>' : '<i class="fas fa-times-circle"></i>'}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('')}
        `;

        modal.style.display = 'flex';
    }

    // ========== EVENT LISTENERS ==========
    function setupEventListeners() {
        // Navigation buttons
        document.getElementById('prevYear').onclick = () => {
            currentDate.setFullYear(currentDate.getFullYear() - 1);
            updateCalendar();
        };

        document.getElementById('prevMonth').onclick = () => {
            currentDate.setMonth(currentDate.getMonth() - 1);
            updateCalendar();
        };

        document.getElementById('nextMonth').onclick = () => {
            currentDate.setMonth(currentDate.getMonth() + 1);
            updateCalendar();
        };

        document.getElementById('nextYear').onclick = () => {
            currentDate.setFullYear(currentDate.getFullYear() + 1);
            updateCalendar();
        };

        // Today button
        document.getElementById('todayBtn').onclick = () => {
            currentDate = new Date();
            updateCalendar();
        };

        // View selector
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.onclick = function () {
                switchView(this.dataset.view);
            };
        });

        // Filter controls
        document.getElementById('employeeFilter').onchange = function () {
            selectedUserId = this.value;
        };

        document.getElementById('statusFilter').onchange = function () {
            selectedStatus = this.value;
        };

        document.getElementById('applyFilters').onclick = applyFilters;

        document.getElementById('clearFilters').onclick = function () {
            document.getElementById('employeeFilter').value = 'all';
            document.getElementById('statusFilter').value = 'all';
            selectedUserId = 'all';
            selectedStatus = 'all';
            applyFilters();
        };

        // Modal controls
        document.getElementById('closeModal').onclick = closeModal;
        document.getElementById('closeModalBtn').onclick = closeModal;

        // Close modal when clicking outside
        document.getElementById('leaveDetailsModal').onclick = function (e) {
            if (e.target === this) {
                closeModal();
            }
        };

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            } else if (e.key === 'ArrowLeft' && e.ctrlKey) {
                currentDate.setFullYear(currentDate.getFullYear() - 1);
                updateCalendar();
            } else if (e.key === 'ArrowRight' && e.ctrlKey) {
                currentDate.setFullYear(currentDate.getFullYear() + 1);
                updateCalendar();
            } else if (e.key === 'ArrowLeft' && !e.ctrlKey) {
                currentDate.setMonth(currentDate.getMonth() - 1);
                updateCalendar();
            } else if (e.key === 'ArrowRight' && !e.ctrlKey) {
                currentDate.setMonth(currentDate.getMonth() + 1);
                updateCalendar();
            } else if (e.key === 't' && e.ctrlKey) {
                e.preventDefault();
                currentDate = new Date();
                updateCalendar();
            }
        });
    }

    function closeModal() {
        document.getElementById('leaveDetailsModal').style.display = 'none';
        activeLeaveId = null;
    }

    function updateCalendar() {
        // Show loading
        const calendarDays = document.getElementById('calendarDays');
        const yearGrid = document.getElementById('yearGrid');

        if (currentView === 'month') {
            calendarDays.innerHTML = '<div class="loading-calendar"><div class="spinner"></div><p>Loading calendar...</p></div>';
        } else {
            yearGrid.innerHTML = '<div class="loading-calendar"><div class="spinner"></div><p>Loading year view...</p></div>';
        }

        // Small delay to show loading and smooth transition
        setTimeout(() => {
            if (currentView === 'month') {
                renderMonthCalendar();
            } else {
                renderYearCalendar();
            }
        }, 300);
    }

    // ========== INITIALIZATION ==========
    document.addEventListener('DOMContentLoaded', function () {
        console.log('📅 Professional Calendar loaded with ocher color scheme');

        // Generate colors for users
        generateUserColors();

        // Set initial filtered leaves
        filteredLeaves = allLeaves;

        // Render calendar
        renderMonthCalendar();

        // Update stats
        updateStats();

        // Setup event listeners
        setupEventListeners();

        // Show empty state if no leaves
        if (allLeaves.length === 0) {
            setTimeout(() => {
                const calendarDays = document.getElementById('calendarDays');
                calendarDays.innerHTML = `
                    <div class="empty-calendar">
                        <i class="fas fa-calendar-plus"></i>
                        <h3>No Leaves Found</h3>
                        <p>There are no leave records in the database yet.</p>
                        <p>Employees need to submit leave requests to see them appear here.</p>
                        <div style="margin-top: 25px; display: flex; gap: 15px; justify-content: center;">
                            <a href="apply-leave.php" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: linear-gradient(135deg, #D4A017, #B8860B); color: white; text-decoration: none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 15px rgba(212, 160, 23, 0.2);">
                                <i class="fas fa-plus"></i> Apply for Leave
                            </a>
                            <a href="manage-employees.php" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: linear-gradient(135deg, #8B7355, #D4A017); color: white; text-decoration: none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 15px rgba(139, 115, 85, 0.2);">
                                <i class="fas fa-users"></i> Manage Employees
                            </a>
                        </div>
                    </div>
                `;
            }, 500);
        }

        // Add animation to stats cards
        const statsCards = document.querySelectorAll('.stat-card-calendar');
        statsCards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });

    // Make functions available globally for onclick events
    window.showLeaveDetails = showLeaveDetails;
    window.showDayLeaves = showDayLeaves;
    window.closeModal = closeModal;

    // Export debug functions
    window.calendarDebug = {
        getCurrentDate: () => currentDate,
        getAllLeaves: () => allLeaves,
        getFilteredLeaves: () => filteredLeaves,
        refreshData: () => {
            // You can add AJAX call to refresh data from server
            alert('Data refresh would be implemented here');
        },
        switchToYearView: () => switchView('year'),
        switchToMonthView: () => switchView('month')
    };
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>