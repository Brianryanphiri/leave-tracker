<?php
// api/test-webhook.php - Test the webhook
require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json');

// Test data
$testData = [
    'secret' => 'CHANGE_THIS_TO_RANDOM_SECRET', // Match your settings
    'email' => 'john.doe@company.com', // Use existing employee email
    'full_name' => 'John Doe',
    'leave_type' => 'Annual Leave',
    'start_date' => date('Y-m-d', strtotime('+7 days')),
    'end_date' => date('Y-m-d', strtotime('+9 days')),
    'reason' => 'Test leave request from webhook',
    'submission_id' => 'test_' . time(),
    'form_id' => 1
];

// Send test request
$ch = curl_init('http://localhost/leave_tracker/api/webhook.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'http_code' => $httpCode,
    'response' => json_decode($response, true),
    'test_data' => $testData
], JSON_PRETTY_PRINT);
?>