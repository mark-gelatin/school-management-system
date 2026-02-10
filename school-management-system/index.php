<?php
/**
 * Main entry point and role router.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    $user = current_user();
    if ($user) {
        redirect_to_dashboard_by_role((string) $user['role']);
    }
}

$title = 'Welcome';
include __DIR__ . '/includes/header.php';
?>
<main class="content-area no-sidebar">
    <section class="card auth-card">
        <h1>Colegio De Amore</h1>
        <p class="text-muted">School Management System & Learning Portal</p>
        <p>
            Manage enrollment, grades, documents, and LMS activities through role-based portals.
        </p>
        <div class="inline-actions">
            <a href="<?= e(app_url('auth/login.php')) ?>" class="btn btn-primary">Login</a>
            <a href="<?= e(app_url('auth/register.php')) ?>" class="btn btn-outline">Register</a>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
