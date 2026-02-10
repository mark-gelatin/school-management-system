<?php
/**
 * Admin sections management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('manage_sections');

$programs = db_fetch_all('SELECT id, code, name FROM programs WHERE status = "active" ORDER BY name ASC');
$facultyList = db_fetch_all(
    'SELECT f.id, f.employee_no, u.first_name, u.last_name
     FROM faculty f
     INNER JOIN users u ON u.id = f.user_id
     ORDER BY u.last_name ASC'
);
$sections = db_fetch_all(
    'SELECT s.id, s.name, s.school_year, s.year_level, s.status,
            p.code AS program_code,
            CONCAT(u.first_name, " ", u.last_name) AS adviser_name
     FROM sections s
     INNER JOIN programs p ON p.id = s.program_id
     LEFT JOIN faculty f ON f.id = s.adviser_faculty_id
     LEFT JOIN users u ON u.id = f.user_id
     ORDER BY s.created_at DESC'
);

$title = 'Manage Sections';
$activePage = 'sections';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Section Management</h1>
    <p class="text-muted">Create and organize sections by program and year level.</p>

    <section class="card">
        <h2>Create Section</h2>
        <form id="createSectionForm" class="form-grid">
            <label>Program
                <select name="program_id" required>
                    <option value="">Select program</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= e((string) $program['id']) ?>">
                            <?= e($program['code'] . ' - ' . $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Section Name
                <input type="text" name="name" required placeholder="A">
            </label>
            <label>School Year
                <input type="text" name="school_year" required placeholder="2026-2027">
            </label>
            <label>Year Level
                <input type="number" name="year_level" min="1" max="6" value="1" required>
            </label>
            <label>Adviser
                <select name="adviser_faculty_id">
                    <option value="">None</option>
                    <?php foreach ($facultyList as $faculty): ?>
                        <option value="<?= e((string) $faculty['id']) ?>">
                            <?= e($faculty['employee_no'] . ' - ' . $faculty['last_name'] . ', ' . $faculty['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-primary">Create Section</button>
        </form>
    </section>

    <section class="card">
        <h2>Sections</h2>
        <div class="table-wrap">
            <table id="sectionsTable">
                <thead>
                <tr>
                    <th>Program</th>
                    <th>Section</th>
                    <th>School Year</th>
                    <th>Year</th>
                    <th>Adviser</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sections as $section): ?>
                    <tr data-section-id="<?= e((string) $section['id']) ?>">
                        <td><?= e((string) $section['program_code']) ?></td>
                        <td><?= e((string) $section['name']) ?></td>
                        <td><?= e((string) $section['school_year']) ?></td>
                        <td><?= e((string) $section['year_level']) ?></td>
                        <td><?= e((string) ($section['adviser_name'] ?: '-')) ?></td>
                        <td class="status-text"><?= e(ucfirst((string) $section['status'])) ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-outline toggle-section-status-btn"
                                    data-next-status="<?= $section['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <?= $section['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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
    const form = document.getElementById('createSectionForm');
    const tbody = document.querySelector('#sectionsTable tbody');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('admin/api/sections.php', { method: 'POST', body: payload });
        if (!response.success) {
            showToast(response.message || 'Unable to create section.', 'error');
            return;
        }

        const section = response.data.section;
        const tr = document.createElement('tr');
        tr.dataset.sectionId = section.id;
        tr.innerHTML = `
            <td>-</td>
            <td>${section.name}</td>
            <td>${section.school_year}</td>
            <td>${section.year_level}</td>
            <td>-</td>
            <td class="status-text">${section.status.charAt(0).toUpperCase() + section.status.slice(1)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline toggle-section-status-btn" data-next-status="inactive">
                    Deactivate
                </button>
            </td>
        `;
        tbody.prepend(tr);
        form.reset();
        showToast(response.message, 'success');
    });

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.toggle-section-status-btn');
        if (!button) return;

        const row = button.closest('tr');
        const sectionId = row.dataset.sectionId;
        const nextStatus = button.dataset.nextStatus;
        const response = await apiRequest('admin/api/sections.php', {
            method: 'POST',
            body: {
                action: 'toggle_status',
                section_id: sectionId,
                status: nextStatus
            }
        });
        if (response.success) {
            row.querySelector('.status-text').textContent = nextStatus.charAt(0).toUpperCase() + nextStatus.slice(1);
            const isActive = nextStatus === 'active';
            button.textContent = isActive ? 'Deactivate' : 'Activate';
            button.dataset.nextStatus = isActive ? 'inactive' : 'active';
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update section.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
