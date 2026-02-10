<?php
/**
 * Faculty LMS modules management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('faculty');
require_permission('manage_modules');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$subjects = [];
$modules = [];
if ($facultyId > 0) {
    $subjects = db_fetch_all(
        'SELECT DISTINCT sub.id, sub.code, sub.title
         FROM section_subjects ss
         INNER JOIN subjects sub ON sub.id = ss.subject_id
         WHERE ss.faculty_id = :faculty_id
         ORDER BY sub.code ASC',
        ['faculty_id' => $facultyId]
    );

    $modules = db_fetch_all(
        'SELECT lm.id, lm.title, lm.description, lm.status, lm.published_at, sub.code AS subject_code, sub.title AS subject_title
         FROM lms_modules lm
         INNER JOIN subjects sub ON sub.id = lm.subject_id
         WHERE lm.faculty_id = :faculty_id
         ORDER BY lm.created_at DESC',
        ['faculty_id' => $facultyId]
    );
}

$title = 'LMS Modules';
$activePage = 'lms_modules';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>LMS Modules</h1>
    <p class="text-muted">Create and publish learning modules for your subjects.</p>

    <section class="card">
        <h2>Create Module</h2>
        <form id="createModuleForm" class="form-grid">
            <label>Subject
                <select name="subject_id" required>
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= e((string) $subject['id']) ?>">
                            <?= e($subject['code'] . ' - ' . $subject['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Title
                <input type="text" name="title" required>
            </label>
            <label>Status
                <select name="status" required>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                </select>
            </label>
            <label>Description
                <textarea name="description" rows="3"></textarea>
            </label>
            <button type="submit" class="btn btn-primary">Create Module</button>
        </form>
    </section>

    <section class="card">
        <h2>My Modules</h2>
        <div class="table-wrap">
            <table id="modulesTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Subject</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Published</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($modules === []): ?>
                    <tr><td colspan="6">No modules yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($modules as $module): ?>
                        <tr data-module-id="<?= e((string) $module['id']) ?>">
                            <td>#<?= e((string) $module['id']) ?></td>
                            <td><?= e($module['subject_code'] . ' - ' . $module['subject_title']) ?></td>
                            <td><?= e((string) $module['title']) ?></td>
                            <td class="status-text"><?= e(ucfirst((string) $module['status'])) ?></td>
                            <td><?= e((string) ($module['published_at'] ?? '-')) ?></td>
                            <td>
                                <select class="module-status-select">
                                    <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $status === $module['status'] ? 'selected' : '' ?>>
                                            <?= e(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline update-module-status-btn">Update</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('createModuleForm');
    const tbody = document.querySelector('#modulesTable tbody');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('faculty/lms/api/modules.php', {
            method: 'POST',
            body: payload
        });
        if (!response.success) {
            showToast(response.message || 'Unable to create module.', 'error');
            return;
        }
        showToast(response.message, 'success');
        window.location.reload();
    });

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.update-module-status-btn');
        if (!button) return;

        const row = button.closest('tr');
        const moduleId = row.dataset.moduleId;
        const status = row.querySelector('.module-status-select').value;

        const response = await apiRequest('faculty/lms/api/modules.php', {
            method: 'POST',
            body: {
                action: 'update_status',
                module_id: moduleId,
                status
            }
        });

        if (response.success) {
            row.querySelector('.status-text').textContent = status.charAt(0).toUpperCase() + status.slice(1);
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update module status.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
