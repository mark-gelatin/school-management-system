<?php
/**
 * OTP verification page for account activation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$email = strtolower(clean_input($_GET['email'] ?? $_POST['email'] ?? ''));
$error = '';
$success = '';

if (is_post_request()) {
    $otp = clean_input($_POST['otp_code'] ?? '');
    $email = strtolower(clean_input($_POST['email'] ?? ''));

    if ($email === '' || $otp === '') {
        $error = 'Email and OTP code are required.';
    } else {
        $db = get_db();
        $user = db_fetch_one('SELECT id, email, first_name FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if (!$user) {
            $error = 'Account not found for the given email.';
        } else {
            $verification = db_fetch_one(
                'SELECT * FROM email_verification WHERE user_id = :user_id AND status = "pending" ORDER BY id DESC LIMIT 1',
                ['user_id' => $user['id']]
            );

            if (!$verification) {
                $error = 'No active OTP found. Please request a new OTP.';
            } elseif (strtotime((string) $verification['expires_at']) < time()) {
                $db->prepare('UPDATE email_verification SET status = "expired", updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $verification['id']]);
                $error = 'OTP has expired. Please request a new OTP.';
            } elseif (!hash_equals((string) $verification['otp_code'], $otp)) {
                $db->prepare('UPDATE email_verification SET attempts = attempts + 1, updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $verification['id']]);
                $error = 'Invalid OTP code.';
            } else {
                try {
                    $db->beginTransaction();
                    $db->prepare(
                        'UPDATE users
                         SET is_verified = 1, status = "active", updated_at = NOW()
                         WHERE id = :user_id'
                    )->execute(['user_id' => $user['id']]);

                    $db->prepare(
                        'UPDATE email_verification
                         SET status = "verified", verified_at = NOW(), updated_at = NOW()
                         WHERE id = :id'
                    )->execute(['id' => $verification['id']]);

                    $db->commit();
                    log_audit('VERIFY_EMAIL', 'auth', 'OTP verified for email: ' . $email);
                    $success = 'Email verified successfully. You can now login.';
                    set_flash('success', $success);
                    header('Location: ' . app_url('auth/login.php'));
                    exit;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'Verification failed. Please try again.';
                    error_log('OTP verification error: ' . $e->getMessage());
                }
            }
        }
    }
}

$title = 'Verify OTP';
include __DIR__ . '/../includes/header.php';
?>
<main class="content-area no-sidebar">
    <section class="card auth-card">
        <h1>Verify Email OTP</h1>
        <p class="text-muted">Enter the OTP sent to your email address.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <label>Email
                <input type="email" name="email" value="<?= e($email) ?>" required>
            </label>
            <label>OTP Code
                <input type="text" name="otp_code" maxlength="6" pattern="\d{6}" required>
            </label>
            <button type="submit" class="btn btn-primary">Verify OTP</button>
        </form>

        <form method="post" action="<?= e(app_url('auth/resend_otp.php')) ?>" class="form-inline">
            <input type="hidden" name="email" value="<?= e($email) ?>">
            <button type="submit" class="btn btn-outline">Resend OTP</button>
        </form>

        <div class="auth-links">
            <a href="<?= e(app_url('auth/login.php')) ?>">Back to Login</a>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
