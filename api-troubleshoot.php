<?php
// api-troubleshoot.php
echo '<!DOCTYPE html><html><head><title>API Troubleshooting</title></head><body>';
echo '<h1>API Troubleshooting</h1>';

// Test direct access to API files
echo '<h2>1. Direct File Access Test</h2>';

$api_files = ['api/approve-leave.php', 'api/reject-leave.php', 'api/get-dashboard-stats.php'];

foreach ($api_files as $file) {
    echo "<h3>Testing: $file</h3>";

    if (file_exists($file)) {
        echo "<p style='color:green'>✓ File exists</p>";

        // Try to include it
        ob_start();
        try {
            // Set a test parameter
            $_GET['test'] = 1;
            include($file);
            $output = ob_get_clean();

            echo "<p>Output (first 500 chars):</p>";
            echo "<pre style='background:#f0f0f0;padding:10px;'>" . htmlspecialchars(substr($output, 0, 500)) . "</pre>";

            // Check if it's JSON
            if (json_decode($output) !== null) {
                echo "<p style='color:green'>✓ Valid JSON output</p>";
            } else {
                echo "<p style='color:orange'>⚠ Output is not valid JSON</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color:red'>✗ Error including file: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color:red'>✗ File does not exist</p>";
    }
}

// Test database connection
echo '<h2>2. Database Connection Test</h2>';
if (file_exists('config/database.php') && file_exists('includes/functions.php')) {
    try {
        require_once 'config/database.php';
        require_once 'includes/functions.php';

        $pdo = getPDOConnection();
        if ($pdo) {
            echo "<p style='color:green'>✓ Database connection successful</p>";

            // Test leaves table
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Pending leaves: " . ($pending['count'] ?? 0) . "</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
    }
}

// Test session
echo '<h2>3. Session Test</h2>';
session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p style='color:green'>✓ Session is active</p>";
    echo "<p>Session ID: " . session_id() . "</p>";

    // Set a test session variable
    $_SESSION['test_time'] = date('Y-m-d H:i:s');
    echo "<p>Test session variable set: " . $_SESSION['test_time'] . "</p>";
} else {
    echo "<p style='color:red'>✗ Session not active</p>";
}

// URL structure
echo '<h2>4. URL Structure</h2>';
echo "<p>Current URL: " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p>Document root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p>Script filename: " . $_SERVER['SCRIPT_FILENAME'] . "</p>";

// Check relative paths
echo '<h2>5. Relative Paths from dashboard.php</h2>';
$dashboard_path = dirname(__FILE__) . '/dashboard.php';
echo "<p>Dashboard.php path: $dashboard_path</p>";

$api_path_from_dashboard = realpath(dirname(__FILE__) . '/api/approve-leave.php');
echo "<p>API path from dashboard: $api_path_from_dashboard</p>";

echo '<h2>6. Quick Fixes to Try</h2>';
echo '<ol>';
echo '<li>Make sure the api/ folder has proper permissions (usually 755)</li>';
echo '<li>Check if .htaccess file is blocking access to the api/ folder</li>';
echo '<li>Try accessing the API directly: <a href="api/approve-leave.php?test=1" target="_blank">api/approve-leave.php?test=1</a></li>';
echo '<li>Check browser console for CORS errors</li>';
echo '<li>Try using full URL in JavaScript: http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/api/approve-leave.php</li>';
echo '</ol>';

echo '</body></html>';
?>