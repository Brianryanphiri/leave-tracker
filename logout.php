<?php
// logout.php
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Clear remember me cookie
setcookie('remember_email', '', time() - 3600, "/");

// Redirect to login page
header('Location: login.php');
exit();
?>