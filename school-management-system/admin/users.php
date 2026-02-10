<?php
/**
 * Admin users management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('manage_users');

$users = db_fetch_all(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at,
            s.student_no, f.employee_no
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     LEFT JOIN faculty f ON f.user_id = u.id
     ORDER BY u.created_at DESC'
);

$title = 'Manage Users';
$activePage = 'users';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>User Management</h1>
    <p class="text-muted">Create and manage student/faculty/admin accounts.</p>

    <section class="card">
        <h2>Create User</h2>
        <form id="createUserForm" class="form-grid">
            <label>Role
                <select name="role" required>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="admin">Admin</option>
                </select>
            </label>
            <label>First Name
                <input type="text" name="first_name" required>
            </label>
            <label>Last Name
                <input type="text" name="last_name" required>
            </label>
            <label>Email
                <input type="email" name="email" required>
            </label>
            <label>Phone
                <input type="text" name="phone">
            </label>
            <label>Temporary Password
                <input type="password" name="password" minlength="8" required>
            </label>
            <button type="submit" class="btn btn-primary">Create User</button>
        </form>
    </section>

    <section class="card">
        <h2>Existing Users</h2>
        <div class="table-wrap">
            <table id="usersTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Identifier</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                    $identifier = $row['student_no'] ?: ($row['employee_no'] ?: '-');
                    ?>
                    <tr data-user-id="<?= e((string) $row['id']) ?>">
                        <td><?= e((string) $row['id']) ?></td>
                        <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td><?= e((string) $row['email']) ?></td>
                        <td><span class="badge"><?= e(ucfirst((string) $row['role'])) ?></span></td>
                        <td><?= e((string) $identifier) ?></td>
                        <td class="status-text"><?= e(ucfirst((string) $row['status'])) ?></td>
                        <td>
                            <div class="inline-actions">
                                <select class="status-select">
                                    <?php foreach (['active', 'inactive', 'suspended'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $status === $row['status'] ? 'selected' : '' ?>>
                                            <?= e(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline update-status-btn">Update</button>
                            </div>
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
    const form = document.getElementById('createUserForm');
    const usersTable = document.getElementById('usersTable').querySelector('tbody');

    function renderRow(user) {
        const tr = document.createElement('tr');
        tr.dataset.userId = user.id;
        tr.innerHTML = `
            <td>${user.id}</td>
            <td>${user.first_name} ${user.last_name}</td>
            <td>${user.email}</td>
            <td><span class="badge">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</span></td>
            <td>-</td>
            <td class="status-text">${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</td>
            <td>
                <div class="inline-actions">
                    <select class="status-select">
                        <option value="active" ${user.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="inactive" ${user.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                        <option value="suspended" ${user.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline update-status-btn">Update</button>
                </div>
            </td>
        `;
        return tr;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.action = 'create';
        const response = await apiRequest('admin/api/users.php', {
            method: 'POST',
            body: payload
        });
        if (response.success) {
            usersTable.prepend(renderRow(response.data.user));
            form.reset();
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to create user.', 'error');
        }
    });

    usersTable.addEventListener('click', async (event) => {
        const button = event.target.closest('.update-status-btn');
        if (!button) return;

        const row = button.closest('tr');
        const userId = row.dataset.userId;
        const status = row.querySelector('.status-select').value;
        const response = await apiRequest('admin/api/users.php', {
            method: 'POST',
            body: {
                action: 'toggle_status',
                user_id: userId,
                status
            }
        });
        if (response.success) {
            row.querySelector('.status-text').textContent = status.charAt(0).toUpperCase() + status.slice(1);
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update status.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
