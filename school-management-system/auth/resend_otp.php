<?php
/**
 * Resend OTP endpoint/page handler.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$email = strtolower(clean_input($_POST['email'] ?? $_GET['email'] ?? ''));

if ($email === '') {
    set_flash('danger', 'Email is required to resend OTP.');
    header('Location: ' . app_url('auth/verify_otp.php'));
    exit;
}

$user = db_fetch_one('SELECT id, first_name, last_name, is_verified FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
if (!$user) {
    set_flash('danger', 'No account found for the provided email.');
    header('Location: ' . app_url('auth/verify_otp.php?email=' . urlencode($email)));
    exit;
}

if ((int) $user['is_verified'] === 1) {
    set_flash('info', 'Account is already verified. Please login.');
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$otpCode = generate_otp_code();
$stored = create_email_otp((int) $user['id'], $otpCode);
$sent = false;
if ($stored) {
    $sent = send_otp_email($email, trim($user['first_name'] . ' ' . $user['last_name']), $otpCode);
}

if ($stored && $sent) {
    set_flash('success', 'A new OTP has been sent to your email.');
    log_audit('RESEND_OTP', 'auth', 'OTP resent to: ' . $email);
} else {
    set_flash('warning', 'OTP generated but email sending failed. Check email server settings.');
}

header('Location: ' . app_url('auth/verify_otp.php?email=' . urlencode($email)));
exit;
