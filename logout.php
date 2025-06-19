<?php
// Start the session so we can access and destroy it
session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Destroy the session
session_destroy();

// Set a success message
$message = "You have been successfully logged out.";

// Redirect to login page with message
header("Location: login.php?message=" . urlencode($message));
exit();
?>