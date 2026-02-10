<?php
/**
 * Store OTP verification status in session
 * Used to maintain verification state across page loads
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$purpose = $_POST['purpose'] ?? 'registration';
$verified = isset($_POST['verified']) && $_POST['verified'] === '1';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email']);
    exit;
}

// Verify OTP was actually validated (security check)
require_once __DIR__ . '/validate_otp.php';

// Store verification status based on purpose
if ($verified) {
    if ($purpose === 'reset' && isset($_SESSION['reset_email']) && $_SESSION['reset_email'] === $email) {
        $_SESSION['otp_verified'] = true;
        echo json_encode(['success' => true, 'message' => 'Verification stored']);
    } elseif ($purpose === 'registration' && isset($_SESSION['registration_email']) && $_SESSION['registration_email'] === $email) {
        $_SESSION['registration_otp_verified'] = true;
        echo json_encode(['success' => true, 'message' => 'Verification stored']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Email mismatch or session expired']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Verification failed']);
}

