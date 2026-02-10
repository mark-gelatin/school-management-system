<?php
/**
 * Admin audit logs page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('view_audit_logs');

$moduleFilter = clean_input($_GET['module'] ?? '');
$params = [];
$whereSql = '';
if ($moduleFilter !== '') {
    $whereSql = 'WHERE al.module = :module';
    $params['module'] = $moduleFilter;
}

$logs = db_fetch_all(
    "SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$whereSql}
     ORDER BY al.created_at DESC
     LIMIT 300",
    $params
);
$modules = db_fetch_all('SELECT DISTINCT module FROM audit_logs ORDER BY module ASC');

$title = 'Audit Logs';
$activePage = 'audit_logs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Audit Logs</h1>
    <p class="text-muted">Track critical actions and system events.</p>

    <section class="card">
        <form method="get" class="form-inline">
            <label>Filter by module:
                <select name="module">
                    <option value="">All modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module']) ?>" <?= $moduleFilter === $module['module'] ? 'selected' : '' ?>>
                            <?= e((string) $module['module']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Apply Filter</button>
            <a href="<?= e(app_url('admin/audit_logs.php')) ?>" class="btn btn-outline btn-sm">Clear</a>
        </form>
    </section>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                    <th>IP Address</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="6">No audit logs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e((string) $log['created_at']) ?></td>
                            <td><?= e((string) ($log['actor_name'] ?: 'System')) ?></td>
                            <td><?= e((string) $log['action']) ?></td>
                            <td><?= e((string) $log['module']) ?></td>
                            <td><?= e((string) $log['description']) ?></td>
                            <td><?= e((string) ($log['ip_address'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
