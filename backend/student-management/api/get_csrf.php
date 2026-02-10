<?php
/**
 * CSRF Token Refresh Endpoint
 * Returns a fresh CSRF token from the server session
 * 
 * Security: Only returns token if user is authenticated
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_path', '/');
    
    session_start();
}

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include CSRF functions
require_once __DIR__ . '/../includes/functions.php';

// Generate and return fresh token
$token = generateCSRFToken();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode([
    'csrf_token' => $token,
    'timestamp' => time()
]);

