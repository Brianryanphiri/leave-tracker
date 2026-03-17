<?php
// api-test.php
echo '<h1>Testing API Files</h1>';

$files = [
    'api/approve-leave.php',
    'api/reject-leave.php',
    'api/get-dashboard-stats.php',
    'config/database.php',
    'includes/functions.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<p style='color:green'>✓ $file exists</p>";
    } else {
        echo "<p style='color:red'>✗ $file NOT FOUND</p>";
    }
}

// Test database connection
echo '<h2>Testing Database Connection</h2>';
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
    require_once 'includes/functions.php';

    try {
        $pdo = getPDOConnection();
        if ($pdo) {
            echo "<p style='color:green'>✓ Database connection successful</p>";
        } else {
            echo "<p style='color:red'>✗ Database connection failed</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
    }
}
?>