<?php
/**
 * Admin subjects management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('manage_subjects');

$programs = db_fetch_all('SELECT id, code, name FROM programs WHERE status = "active" ORDER BY name ASC');
$subjects = db_fetch_all(
    'SELECT s.id, s.code, s.title, s.units, s.year_level, s.semester, s.status, p.code AS program_code
     FROM subjects s
     LEFT JOIN programs p ON p.id = s.program_id
     ORDER BY s.created_at DESC'
);

$title = 'Manage Subjects';
$activePage = 'subjects';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Subject Management</h1>
    <p class="text-muted">Create and manage curriculum subjects.</p>

    <section class="card">
        <h2>Create Subject</h2>
        <form id="createSubjectForm" class="form-grid">
            <label>Program
                <select name="program_id">
                    <option value="">General</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= e((string) $program['id']) ?>">
                            <?= e($program['code'] . ' - ' . $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Code
                <input type="text" name="code" required placeholder="IT201">
            </label>
            <label>Title
                <input type="text" name="title" required>
            </label>
            <label>Units
                <input type="number" step="0.5" min="1" max="6" name="units" value="3" required>
            </label>
            <label>Year Level
                <input type="number" min="1" max="6" name="year_level" value="1" required>
            </label>
            <label>Semester
                <select name="semester" required>
                    <option value="1st">1st Semester</option>
                    <option value="2nd">2nd Semester</option>
                    <option value="summer">Summer</option>
                </select>
            </label>
            <label>Description
                <textarea name="description" rows="3"></textarea>
            </label>
            <button type="submit" class="btn btn-primary">Create Subject</button>
        </form>
    </section>

    <section class="card">
        <h2>Subject List</h2>
        <div class="table-wrap">
            <table id="subjectsTable">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Program</th>
                    <th>Units</th>
                    <th>Year</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr data-subject-id="<?= e((string) $subject['id']) ?>">
                        <td><?= e((string) $subject['code']) ?></td>
                        <td><?= e((string) $subject['title']) ?></td>
                        <td><?= e((string) ($subject['program_code'] ?: '-')) ?></td>
                        <td><?= e((string) $subject['units']) ?></td>
                        <td><?= e((string) $subject['year_level']) ?></td>
                        <td><?= e((string) ucfirst($subject['semester'])) ?></td>
                        <td class="status-text"><?= e((string) ucfirst($subject['status'])) ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-outline toggle-subject-status-btn"
                                    data-next-status="<?= $subject['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <?= $subject['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('createSubjectForm');
    const tbody = document.querySelector('#subjectsTable tbody');

    function semesterLabel(value) {
        if (value === '1st') return '1st';
        if (value === '2nd') return '2nd';
        return 'Summer';
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('admin/api/subjects.php', { method: 'POST', body: payload });
        if (!response.success) {
            showToast(response.message || 'Unable to create subject.', 'error');
            return;
        }

        const subject = response.data.subject;
        const tr = document.createElement('tr');
        tr.dataset.subjectId = subject.id;
        tr.innerHTML = `
            <td>${subject.code}</td>
            <td>${subject.title}</td>
            <td>-</td>
            <td>${subject.units}</td>
            <td>-</td>
            <td>${semesterLabel(subject.semester)}</td>
            <td class="status-text">${subject.status.charAt(0).toUpperCase() + subject.status.slice(1)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline toggle-subject-status-btn" data-next-status="inactive">
                    Deactivate
                </button>
            </td>
        `;
        tbody.prepend(tr);
        form.reset();
        showToast(response.message, 'success');
    });

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.toggle-subject-status-btn');
        if (!button) return;
        const row = button.closest('tr');
        const subjectId = row.dataset.subjectId;
        const nextStatus = button.dataset.nextStatus;

        const response = await apiRequest('admin/api/subjects.php', {
            method: 'POST',
            body: {
                action: 'toggle_status',
                subject_id: subjectId,
                status: nextStatus
            }
        });

        if (response.success) {
            row.querySelector('.status-text').textContent = nextStatus.charAt(0).toUpperCase() + nextStatus.slice(1);
            const isActive = nextStatus === 'active';
            button.dataset.nextStatus = isActive ? 'inactive' : 'active';
            button.textContent = isActive ? 'Deactivate' : 'Activate';
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update subject.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
