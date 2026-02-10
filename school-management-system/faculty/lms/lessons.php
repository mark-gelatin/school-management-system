<?php
/**
 * Faculty LMS lessons management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('faculty');
require_permission('manage_lessons');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$modules = [];
$lessons = [];
if ($facultyId > 0) {
    $modules = db_fetch_all(
        'SELECT lm.id, lm.title, sub.code AS subject_code
         FROM lms_modules lm
         INNER JOIN subjects sub ON sub.id = lm.subject_id
         WHERE lm.faculty_id = :faculty_id
         ORDER BY lm.created_at DESC',
        ['faculty_id' => $facultyId]
    );

    $lessons = db_fetch_all(
        'SELECT ll.id, ll.title, ll.order_no, ll.due_date, ll.resource_link,
                lm.id AS module_id, lm.title AS module_title, sub.code AS subject_code
         FROM lms_lessons ll
         INNER JOIN lms_modules lm ON lm.id = ll.module_id
         INNER JOIN subjects sub ON sub.id = lm.subject_id
         WHERE lm.faculty_id = :faculty_id
         ORDER BY lm.created_at DESC, ll.order_no ASC',
        ['faculty_id' => $facultyId]
    );
}

$title = 'LMS Lessons';
$activePage = 'lms_lessons';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>LMS Lessons</h1>
    <p class="text-muted">Add lessons inside your modules.</p>

    <section class="card">
        <h2>Create Lesson</h2>
        <form id="createLessonForm" class="form-grid">
            <label>Module
                <select name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['id']) ?>">
                            <?= e($module['subject_code'] . ' - ' . $module['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Lesson Title
                <input type="text" name="title" required>
            </label>
            <label>Order Number
                <input type="number" name="order_no" min="1" value="1" required>
            </label>
            <label>Due Date
                <input type="datetime-local" name="due_date">
            </label>
            <label>Resource Link
                <input type="url" name="resource_link" placeholder="https://example.com/resource">
            </label>
            <label>Content
                <textarea name="content_text" rows="4" placeholder="Lesson content and instructions"></textarea>
            </label>
            <button type="submit" class="btn btn-primary">Create Lesson</button>
        </form>
    </section>

    <section class="card">
        <h2>Lesson List</h2>
        <div class="table-wrap">
            <table id="lessonsTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Subject</th>
                    <th>Module</th>
                    <th>Title</th>
                    <th>Order</th>
                    <th>Due Date</th>
                    <th>Resource</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($lessons === []): ?>
                    <tr><td colspan="7">No lessons created yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($lessons as $lesson): ?>
                        <tr>
                            <td>#<?= e((string) $lesson['id']) ?></td>
                            <td><?= e((string) $lesson['subject_code']) ?></td>
                            <td><?= e((string) $lesson['module_title']) ?></td>
                            <td><?= e((string) $lesson['title']) ?></td>
                            <td><?= e((string) $lesson['order_no']) ?></td>
                            <td><?= e((string) ($lesson['due_date'] ?? '-')) ?></td>
                            <td>
                                <?php if (!empty($lesson['resource_link'])): ?>
                                    <a class="link-btn" href="<?= e((string) $lesson['resource_link']) ?>" target="_blank" rel="noopener">Open</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
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
    const form = document.getElementById('createLessonForm');
    const tbody = document.querySelector('#lessonsTable tbody');
    const moduleSelect = form.querySelector('select[name="module_id"]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('faculty/lms/api/lessons.php', {
            method: 'POST',
            body: payload
        });
        if (!response.success) {
            showToast(response.message || 'Unable to create lesson.', 'error');
            return;
        }

        const lesson = response.data.lesson;
        const moduleLabel = moduleSelect.options[moduleSelect.selectedIndex]?.text || '-';
        const [subjectLabel, moduleTitle] = moduleLabel.split(' - ');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>#${lesson.id}</td>
            <td>${subjectLabel || '-'}</td>
            <td>${moduleTitle || moduleLabel}</td>
            <td>${lesson.title}</td>
            <td>${lesson.order_no}</td>
            <td>${lesson.due_date || '-'}</td>
            <td>-</td>
        `;
        if (tbody.querySelector('td[colspan="7"]')) {
            tbody.innerHTML = '';
        }
        tbody.prepend(tr);
        form.reset();
        showToast(response.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
