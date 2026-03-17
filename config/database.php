<?php
// config/database.php - PDO Version (Recommended)

// Database configuration - Only define if not already defined
if (!defined('DB_HOST'))
    define('DB_HOST', 'localhost');
if (!defined('DB_USER'))
    define('DB_USER', 'root');
if (!defined('DB_PASS'))
    define('DB_PASS', '');
if (!defined('DB_NAME'))
    define('DB_NAME', 'leave_tracker');
if (!defined('DB_CHARSET'))
    define('DB_CHARSET', 'utf8mb4');

// Create PDO connection with error handling
function getPDOConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;

    } catch (PDOException $e) {
        // Log error for debugging
        error_log("PDO Connection failed: " . $e->getMessage());
        return false;
    }
}

// For backward compatibility - keep mysqli version
function getConnection()
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        return false;
    }

    $conn->set_charset("utf8mb4");
    return $conn;
}

// Execute query with parameters (for PDO)
function executeQuery($sql, $params = [])
{
    $pdo = getPDOConnection();
    if ($pdo === false)
        return false;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query execution failed: " . $e->getMessage());
        return false;
    }
}

// Fetch single row
function fetchOne($sql, $params = [])
{
    $stmt = executeQuery($sql, $params);
    if ($stmt === false)
        return false;

    return $stmt->fetch();
}

// Fetch all rows
function fetchAll($sql, $params = [])
{
    $stmt = executeQuery($sql, $params);
    if ($stmt === false)
        return false;

    return $stmt->fetchAll();
}

// Insert data and return last insert ID
function insertData($table, $data)
{
    $pdo = getPDOConnection();
    if ($pdo === false)
        return false;

    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));

    $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Insert failed: " . $e->getMessage());
        return false;
    }
}

// Update data
function updateData($table, $data, $where, $whereParams = [])
{
    $pdo = getPDOConnection();
    if ($pdo === false)
        return false;

    $setClause = [];
    foreach (array_keys($data) as $column) {
        $setClause[] = "$column = :$column";
    }
    $setClause = implode(', ', $setClause);

    $sql = "UPDATE $table SET $setClause WHERE $where";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($data, $whereParams));
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Update failed: " . $e->getMessage());
        return false;
    }
}

// Check if connection is working
function isDatabaseConnected()
{
    $conn = getConnection();
    if ($conn === false) {
        return false;
    }
    $conn->close();
    return true;
}

// Test database connection (for debugging)
function testDatabase()
{
    if (!isDatabaseConnected()) {
        return [
            'status' => 'error',
            'message' => 'Database connection failed'
        ];
    }

    $conn = getConnection();

    // Test query
    $result = $conn->query("SELECT 1 as test");
    if (!$result) {
        $conn->close();
        return [
            'status' => 'error',
            'message' => 'Query test failed: ' . $conn->error
        ];
    }

    // Check tables
    $tables = [];
    $tableResult = $conn->query("SHOW TABLES");
    if ($tableResult) {
        while ($row = $tableResult->fetch_array()) {
            $tables[] = $row[0];
        }
    }

    $conn->close();

    return [
        'status' => 'success',
        'message' => 'Database connection successful',
        'tables' => $tables
    ];
}
?>