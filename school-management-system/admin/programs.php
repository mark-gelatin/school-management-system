<?php
/**
 * Admin programs management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('manage_programs');

$programs = db_fetch_all('SELECT * FROM programs ORDER BY created_at DESC');

$title = 'Manage Programs';
$activePage = 'programs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Program Management</h1>
    <p class="text-muted">Create and maintain academic programs.</p>

    <section class="card">
        <h2>Create Program</h2>
        <form id="createProgramForm" class="form-grid">
            <label>Program Code
                <input type="text" name="code" required placeholder="BSIT">
            </label>
            <label>Program Name
                <input type="text" name="name" required>
            </label>
            <label>Years to Complete
                <input type="number" name="years_to_complete" min="1" max="8" value="4" required>
            </label>
            <label>Description
                <textarea name="description" rows="3"></textarea>
            </label>
            <button type="submit" class="btn btn-primary">Create Program</button>
        </form>
    </section>

    <section class="card">
        <h2>Programs</h2>
        <div class="table-wrap">
            <table id="programsTable">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Years</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($programs as $program): ?>
                    <tr data-program-id="<?= e((string) $program['id']) ?>">
                        <td><?= e((string) $program['code']) ?></td>
                        <td><?= e((string) $program['name']) ?></td>
                        <td><?= e((string) $program['years_to_complete']) ?></td>
                        <td class="status-text"><?= e(ucfirst((string) $program['status'])) ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-outline toggle-program-status-btn"
                                    data-next-status="<?= $program['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <?= $program['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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
    const form = document.getElementById('createProgramForm');
    const tbody = document.querySelector('#programsTable tbody');

    function renderProgramRow(program) {
        const tr = document.createElement('tr');
        tr.dataset.programId = program.id;
        tr.innerHTML = `
            <td>${program.code}</td>
            <td>${program.name}</td>
            <td>${program.years_to_complete}</td>
            <td class="status-text">${program.status.charAt(0).toUpperCase() + program.status.slice(1)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline toggle-program-status-btn" data-next-status="inactive">
                    Deactivate
                </button>
            </td>
        `;
        return tr;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('admin/api/programs.php', { method: 'POST', body: payload });
        if (response.success) {
            tbody.prepend(renderProgramRow(response.data.program));
            form.reset();
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to create program.', 'error');
        }
    });

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.toggle-program-status-btn');
        if (!button) return;

        const row = button.closest('tr');
        const programId = row.dataset.programId;
        const nextStatus = button.dataset.nextStatus;
        const response = await apiRequest('admin/api/programs.php', {
            method: 'POST',
            body: {
                action: 'toggle_status',
                program_id: programId,
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
            showToast(response.message || 'Unable to update program.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
