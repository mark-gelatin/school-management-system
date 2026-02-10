<?php
/**
 * Session Keep-Alive Endpoint
 * This endpoint is called periodically to keep the session alive
 */

// Set proper headers for JSON response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_path', '/');
    session_start();
}

// Update session timestamp to keep it alive
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
} else {
    echo json_encode(['status' => 'expired']);
}
exit;
?>

