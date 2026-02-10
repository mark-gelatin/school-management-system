<?php
/**
 * Admin enrollment approval page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('approve_enrollment');

$enrollments = db_fetch_all(
    'SELECT e.id, e.school_year, e.semester, e.status, e.submitted_at, e.remarks,
            s.student_no, CONCAT(u.first_name, " ", u.last_name) AS student_name,
            p.code AS program_code, sec.name AS section_name
     FROM enrollments e
     INNER JOIN students s ON s.id = e.student_id
     INNER JOIN users u ON u.id = s.user_id
     INNER JOIN programs p ON p.id = e.program_id
     LEFT JOIN sections sec ON sec.id = e.section_id
     ORDER BY
         CASE e.status
             WHEN "pending" THEN 1
             WHEN "approved" THEN 2
             WHEN "rejected" THEN 3
             ELSE 4
         END,
         e.submitted_at DESC'
);

$title = 'Enrollment Approval';
$activePage = 'enrollment_approval';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Enrollment Approval</h1>
    <p class="text-muted">Approve or reject student enrollment requests in real-time.</p>

    <section class="card">
        <div class="table-wrap">
            <table id="enrollmentTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($enrollments as $row): ?>
                    <tr data-enrollment-id="<?= e((string) $row['id']) ?>">
                        <td>#<?= e((string) $row['id']) ?></td>
                        <td><?= e($row['student_no'] . ' - ' . $row['student_name']) ?></td>
                        <td><?= e((string) $row['program_code']) ?></td>
                        <td><?= e((string) ($row['section_name'] ?: '-')) ?></td>
                        <td><?= e((string) $row['school_year']) ?></td>
                        <td><?= e((string) ucfirst($row['semester'])) ?></td>
                        <td><?= e((string) $row['submitted_at']) ?></td>
                        <td class="status-text"><span class="badge"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <input type="text" class="remarks-input" value="<?= e((string) ($row['remarks'] ?? '')) ?>"
                                   placeholder="Optional remarks" <?= $row['status'] !== 'pending' ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <div class="inline-actions">
                                    <button type="button" class="btn btn-sm btn-primary enrollment-action-btn" data-action="approve">Approve</button>
                                    <button type="button" class="btn btn-sm btn-danger enrollment-action-btn" data-action="reject">Reject</button>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Processed</span>
                            <?php endif; ?>
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
    const tableBody = document.querySelector('#enrollmentTable tbody');

    tableBody.addEventListener('click', async (event) => {
        const button = event.target.closest('.enrollment-action-btn');
        if (!button) return;

        const row = button.closest('tr');
        const enrollmentId = row.dataset.enrollmentId;
        const action = button.dataset.action;
        const remarks = row.querySelector('.remarks-input')?.value || '';
        button.disabled = true;

        const response = await apiRequest('admin/api/approve_enrollment.php', {
            method: 'POST',
            body: {
                enrollment_id: enrollmentId,
                action,
                remarks
            }
        });

        if (response.success) {
            const status = response.data.status;
            row.querySelector('.status-text').innerHTML = `<span class="badge">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
            row.querySelector('.remarks-input').disabled = true;
            row.querySelectorAll('.enrollment-action-btn').forEach((btn) => btn.remove());
            row.querySelector('td:last-child').innerHTML = '<span class="text-muted">Processed</span>';
            showToast(response.message, 'success');
        } else {
            button.disabled = false;
            showToast(response.message || 'Failed to process enrollment.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
