<?php
/**
 * Ends session and redirects to login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    log_audit('LOGOUT', 'auth', 'User logged out.');
}

logout_user();
set_flash('success', 'You have been logged out.');
header('Location: ' . app_url('auth/login.php'));
exit;
