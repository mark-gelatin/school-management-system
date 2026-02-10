<?php
/**
 * Admin Logout Handler
 * Securely terminates admin session and redirects to staff login page
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Prevent caching of this page
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Calculate the correct path to staff-login.php
// Handle both admin wrapper and direct backend access
$currentScript = $_SERVER['PHP_SELF'];
$basePath = dirname($currentScript);

// Determine root path based on access method
if (strpos($currentScript, '/admin/') !== false) {
    // Accessed via admin/student-management/logout.php
    // Go up 2 levels: admin/student-management -> admin -> root
    $rootPath = dirname(dirname($basePath));
} else {
    // Accessed directly via backend/student-management/logout.php
    // Go up 2 levels: backend/student-management -> backend -> root
    $rootPath = dirname(dirname($basePath));
}

// Construct redirect URL to staff login page
$redirectUrl = $rootPath . '/auth/staff-login.php';
// Normalize path (remove double slashes)
$redirectUrl = str_replace('//', '/', $redirectUrl);

// Redirect to staff login page
header("Location: " . $redirectUrl);
exit();