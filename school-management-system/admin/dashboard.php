<?php
/**
 * Admin dashboard page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$stats = [
    'students' => (int) (db_fetch_one('SELECT COUNT(*) AS total FROM students')['total'] ?? 0),
    'faculty' => (int) (db_fetch_one('SELECT COUNT(*) AS total FROM faculty')['total'] ?? 0),
    'pending_enrollments' => (int) (db_fetch_one('SELECT COUNT(*) AS total FROM enrollments WHERE status = "pending"')['total'] ?? 0),
    'pending_documents' => (int) (db_fetch_one('SELECT COUNT(*) AS total FROM student_documents WHERE status = "pending"')['total'] ?? 0),
    'modules' => (int) (db_fetch_one('SELECT COUNT(*) AS total FROM lms_modules')['total'] ?? 0),
];

$recentLogs = db_fetch_all(
    'SELECT al.created_at, al.action, al.module, al.description, CONCAT(u.first_name, " ", u.last_name) AS actor
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 10'
);

$title = 'Admin Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Admin Dashboard</h1>
    <p class="text-muted">System-wide overview for Colegio De Amore.</p>

    <section class="card-grid">
        <article class="card stat-card">
            <h3>Total Students</h3>
            <p><?= e((string) $stats['students']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Total Faculty</h3>
            <p><?= e((string) $stats['faculty']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Pending Enrollments</h3>
            <p><?= e((string) $stats['pending_enrollments']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Pending Documents</h3>
            <p><?= e((string) $stats['pending_documents']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>LMS Modules</h3>
            <p><?= e((string) $stats['modules']) ?></p>
        </article>
    </section>

    <section class="card">
        <h2>Recent Audit Activity</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($recentLogs === []): ?>
                    <tr><td colspan="5">No recent logs available.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?= e((string) $log['created_at']) ?></td>
                            <td><?= e((string) ($log['actor'] ?: 'System')) ?></td>
                            <td><?= e((string) $log['action']) ?></td>
                            <td><?= e((string) $log['module']) ?></td>
                            <td><?= e((string) $log['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
