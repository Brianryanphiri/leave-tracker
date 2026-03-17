<?php
// api/get-leave-balance.php - FIXED VERSION
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$pdo = getPDOConnection();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

try {
    // Get user's leave allocations
    $stmt = $pdo->prepare("
        SELECT 
            annual_leave_days,
            sick_leave_days,
            emergency_leave_days
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // Get current year
    $currentYear = date('Y');

    // Query 1: Get total approved leaves for annual (type 1)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) as used 
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type_id = 1 
        AND status = 'approved'
        AND YEAR(created_at) = ?
    ");
    $stmt->execute([$user_id, $currentYear]);
    $annualUsed = $stmt->fetchColumn();

    // Query 2: Get total approved leaves for sick (type 2)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) as used 
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type_id = 2 
        AND status = 'approved'
        AND YEAR(created_at) = ?
    ");
    $stmt->execute([$user_id, $currentYear]);
    $sickUsed = $stmt->fetchColumn();

    // Query 3: Get total approved leaves for emergency (type 3)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) as used 
        FROM leaves 
        WHERE user_id = ? 
        AND leave_type_id = 3 
        AND status = 'approved'
        AND YEAR(created_at) = ?
    ");
    $stmt->execute([$user_id, $currentYear]);
    $emergencyUsed = $stmt->fetchColumn();

    // Calculate balances
    $balance = [
        'annual' => max(0, $user['annual_leave_days'] - $annualUsed),
        'sick' => max(0, $user['sick_leave_days'] - $sickUsed),
        'emergency' => max(0, $user['emergency_leave_days'] - $emergencyUsed)
    ];

    echo json_encode([
        'success' => true,
        'balance' => $balance,
        'allocated' => [
            'annual' => (int) $user['annual_leave_days'],
            'sick' => (int) $user['sick_leave_days'],
            'emergency' => (int) $user['emergency_leave_days']
        ],
        'used' => [
            'annual' => (int) $annualUsed,
            'sick' => (int) $sickUsed,
            'emergency' => (int) $emergencyUsed
        ]
    ]);

} catch (PDOException $e) {
    error_log("Error fetching leave balance: " . $e->getMessage());
    // Return default values in case of error
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'balance' => [
            'annual' => 21,
            'sick' => 14,
            'emergency' => 5
        ]
    ]);
}
?>