<?php
/**
 * CSRF Debug Endpoint
 * Use this to debug CSRF token issues
 */
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('admin');

header('Content-Type: application/json');

$debug = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'csrf_token_in_session' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 20) . '...' : 'NOT SET',
    'csrf_token_length' => isset($_SESSION['csrf_token']) ? strlen($_SESSION['csrf_token']) : 0,
    'post_data' => $_POST,
    'post_csrf_token' => $_POST['csrf_token'] ?? 'NOT PROVIDED',
    'post_csrf_token_length' => isset($_POST['csrf_token']) ? strlen($_POST['csrf_token']) : 0,
    'validation_result' => false,
    'token_match' => false
];

if (isset($_POST['csrf_token'])) {
    $debug['validation_result'] = validateCSRFToken($_POST['csrf_token']);
    $debug['token_match'] = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

echo json_encode($debug, JSON_PRETTY_PRINT);
exit;
?>

