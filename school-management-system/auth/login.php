<?php
/**
 * User login page for all roles.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    $user = current_user();
    if ($user) {
        redirect_to_dashboard_by_role($user['role']);
    }
}

$error = '';

if (is_post_request()) {
    $email = strtolower(clean_input($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid login credentials.';
        } elseif ((int) $user['is_verified'] !== 1 || $user['status'] !== 'active') {
            set_flash('warning', 'Please verify your email using OTP before logging in.');
            header('Location: ' . app_url('auth/verify_otp.php?email=' . urlencode($email)));
            exit;
        } else {
            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);
            login_user($user);
            log_audit('LOGIN', 'auth', 'User logged in.');
            redirect_to_dashboard_by_role($user['role']);
        }
    }
}

$title = 'Login';
include __DIR__ . '/../includes/header.php';
?>
<main class="content-area no-sidebar">
    <section class="card auth-card">
        <h1>Login</h1>
        <p class="text-muted">Access Colegio De Amore School Management & LMS portal.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <label>Email
                <input type="email" name="email" required autocomplete="email">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>

        <div class="auth-links">
            <a href="<?= e(app_url('auth/register.php')) ?>">Create Account</a>
            <a href="<?= e(app_url('auth/verify_otp.php')) ?>">Verify OTP</a>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
