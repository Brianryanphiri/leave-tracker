<?php
// debug_db.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Connection Debug</h2>";

// Test basic PHP
echo "<h3>PHP Version: " . phpversion() . "</h3>";

// Test PDO extension
if (!extension_loaded('pdo')) {
    echo "<p style='color: red;'>PDO extension is NOT loaded!</p>";
} else {
    echo "<p style='color: green;'>PDO extension is loaded</p>";
}

if (!extension_loaded('pdo_mysql')) {
    echo "<p style='color: red;'>PDO MySQL extension is NOT loaded!</p>";
} else {
    echo "<p style='color: green;'>PDO MySQL extension is loaded</p>";
}

// Test database configuration file
echo "<h3>Checking config/database.php</h3>";
$config_file = __DIR__ . '/config/database.php';
if (file_exists($config_file)) {
    echo "<p style='color: green;'>Config file exists: " . $config_file . "</p>";

    // Read config file
    $config_content = file_get_contents($config_file);
    echo "<pre>Config content (first 500 chars):<br>" .
        htmlspecialchars(substr($config_content, 0, 500)) . "</pre>";
} else {
    echo "<p style='color: red;'>Config file NOT found: " . $config_file . "</p>";
}

// Try to include and test the connection
echo "<h3>Testing Database Connection</h3>";
try {
    require_once $config_file;

    // Test getPDOConnection function
    if (!function_exists('getPDOConnection')) {
        echo "<p style='color: red;'>getPDOConnection() function not found!</p>";
    } else {
        echo "<p style='color: green;'>getPDOConnection() function exists</p>";

        // Try to connect
        $pdo = getPDOConnection();

        if ($pdo) {
            echo "<p style='color: green;'>✅ Database connection SUCCESSFUL!</p>";

            // Test a query
            $stmt = $pdo->query("SELECT 1 as test");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Test query result: " . $result['test'] . "</p>";

            // Check users table
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p>Users in database: " . $result['count'] . "</p>";

                // List users
                $stmt = $pdo->query("SELECT id, email, role, status FROM users LIMIT 10");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo "<h4>Users in database:</h4>";
                echo "<table border='1' cellpadding='5'>";
                echo "<tr><th>ID</th><th>Email</th><th>Role</th><th>Status</th></tr>";
                foreach ($users as $user) {
                    echo "<tr>";
                    echo "<td>" . $user['id'] . "</td>";
                    echo "<td>" . $user['email'] . "</td>";
                    echo "<td>" . $user['role'] . "</td>";
                    echo "<td>" . $user['status'] . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } catch (Exception $e) {
                echo "<p style='color: orange;'>Users table error: " . $e->getMessage() . "</p>";
            }

        } else {
            echo "<p style='color: red;'>❌ Database connection FAILED - getPDOConnection() returned null</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ ERROR: " . $e->getMessage() . "</p>";
    echo "<pre>Stack trace:\n" . $e->getTraceAsString() . "</pre>";
}

// Check environment
echo "<h3>Environment Check</h3>";
echo "<p>Current directory: " . __DIR__ . "</p>";
echo "<p>Web root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";

// Check if we can write to logs
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    echo "<p>Logs directory doesn't exist</p>";
} else {
    echo "<p>Logs directory exists</p>";
}
?>