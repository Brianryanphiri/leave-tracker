<?php
// check-passwords.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

$pdo = getPDOConnection();

echo "<h2>Checking User Passwords</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Email</th><th>Password (first 30 chars)</th><th>Length</th><th>Type</th><th>Test</th></tr>";

$stmt = $pdo->query("SELECT id, email, password FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    $password = $user['password'];
    $password_length = strlen($password);

    // Check what type of password it is
    if (password_verify('password123', $password)) {
        $type = 'Hashed (password123)';
    } elseif (password_verify('ceo123', $password)) {
        $type = 'Hashed (ceo123)';
    } elseif (password_verify('admin123', $password)) {
        $type = 'Hashed (admin123)';
    } elseif ($password === 'password123' || $password === 'ceo123' || $password === 'admin123') {
        $type = 'Plain Text';
    } else {
        $type = 'Unknown';
    }

    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['email']}</td>";
    echo "<td>" . substr($password, 0, 30) . "...</td>";
    echo "<td>$password_length</td>";
    echo "<td>$type</td>";
    echo "<td>";

    // Test some common passwords
    $test_passwords = ['password123', 'ceo123', 'admin123', '123456', 'password'];
    foreach ($test_passwords as $test_pass) {
        if (password_verify($test_pass, $password) || $password === $test_pass) {
            echo "$test_pass works<br>";
        }
    }
    echo "</td>";
    echo "</tr>";
}

echo "</table>";

// Try to login with one user
echo "<h3>Test Login for ceo@company.com</h3>";
$test_email = 'ceo@company.com';
$test_password = 'ceo123';

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$test_email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "User found: {$user['email']}<br>";
    echo "Stored password length: " . strlen($user['password']) . "<br>";

    // Test password verification
    if (password_verify($test_password, $user['password'])) {
        echo "<span style='color: green;'>✅ Password verified with password_verify()</span><br>";
    } elseif ($test_password === $user['password']) {
        echo "<span style='color: green;'>✅ Password matches as plain text</span><br>";
    } else {
        echo "<span style='color: red;'>❌ Password does NOT match</span><br>";

        // Show what we're comparing
        echo "Test password: $test_password<br>";
        echo "Stored password start: " . substr($user['password'], 0, 20) . "...<br>";
    }
} else {
    echo "User not found!";
}
?>