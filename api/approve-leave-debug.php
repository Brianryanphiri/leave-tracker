<?php
// test-api.php
echo "<h2>Testing API Connection</h2>";

// Test 1: Direct API call
echo "<h3>Test 1: Direct API Call</h3>";
$url = 'http://localhost/leave-tracker/api/approve-leave.php?test=1';
$response = file_get_contents($url);
echo "<pre>" . htmlspecialchars($response) . "</pre>";

// Test 2: POST request
echo "<h3>Test 2: POST Request (simulated)</h3>";
$postData = json_encode(['id' => 7, 'notes' => 'test']);
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $postData
    ]
]);
$response = file_get_contents('http://localhost/leave-tracker/api/approve-leave.php', false, $context);
echo "<pre>" . htmlspecialchars($response) . "</pre>";

// Test 3: Check PHP errors
echo "<h3>Test 3: Check if errors are enabled</h3>";
echo "error_reporting: " . ini_get('error_reporting') . "<br>";
echo "display_errors: " . ini_get('display_errors') . "<br>";
echo "display_startup_errors: " . ini_get('display_startup_errors') . "<br>";
?>