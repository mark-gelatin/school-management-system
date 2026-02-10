<?php
/**
 * Admin document verification page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('verify_documents');

$documents = db_fetch_all(
    'SELECT sd.id, sd.file_path, sd.status, sd.uploaded_at, sd.remarks,
            d.name AS document_name,
            s.student_no,
            CONCAT(u.first_name, " ", u.last_name) AS student_name
     FROM student_documents sd
     INNER JOIN documents d ON d.id = sd.document_id
     INNER JOIN students s ON s.id = sd.student_id
     INNER JOIN users u ON u.id = s.user_id
     ORDER BY
         CASE sd.status
             WHEN "pending" THEN 1
             WHEN "verified" THEN 2
             WHEN "rejected" THEN 3
             ELSE 4
         END,
         sd.uploaded_at DESC'
);

$title = 'Document Verification';
$activePage = 'document_verification';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Document Verification</h1>
    <p class="text-muted">Review uploaded student records and verify them without reloading.</p>

    <section class="card">
        <div class="table-wrap">
            <table id="documentTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Document</th>
                    <th>File</th>
                    <th>Uploaded At</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $row): ?>
                    <tr data-student-document-id="<?= e((string) $row['id']) ?>">
                        <td>#<?= e((string) $row['id']) ?></td>
                        <td><?= e($row['student_no'] . ' - ' . $row['student_name']) ?></td>
                        <td><?= e((string) $row['document_name']) ?></td>
                        <td>
                            <a href="<?= e(app_url($row['file_path'])) ?>" target="_blank" rel="noopener" class="link-btn">View File</a>
                        </td>
                        <td><?= e((string) $row['uploaded_at']) ?></td>
                        <td class="status-text"><span class="badge"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <input type="text" class="remarks-input" value="<?= e((string) ($row['remarks'] ?? '')) ?>"
                                   placeholder="Optional remarks" <?= $row['status'] !== 'pending' ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <div class="inline-actions">
                                    <button type="button" class="btn btn-sm btn-primary document-action-btn" data-action="verify">Verify</button>
                                    <button type="button" class="btn btn-sm btn-danger document-action-btn" data-action="reject">Reject</button>
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
    const tbody = document.querySelector('#documentTable tbody');

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.document-action-btn');
        if (!button) return;

        const row = button.closest('tr');
        const studentDocumentId = row.dataset.studentDocumentId;
        const action = button.dataset.action;
        const remarks = row.querySelector('.remarks-input')?.value || '';
        button.disabled = true;

        const response = await apiRequest('admin/api/verify_document.php', {
            method: 'POST',
            body: {
                student_document_id: studentDocumentId,
                action,
                remarks
            }
        });

        if (response.success) {
            const status = response.data.status;
            row.querySelector('.status-text').innerHTML = `<span class="badge">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
            row.querySelector('.remarks-input').disabled = true;
            row.querySelectorAll('.document-action-btn').forEach((btn) => btn.remove());
            row.querySelector('td:last-child').innerHTML = '<span class="text-muted">Processed</span>';
            showToast(response.message, 'success');
        } else {
            button.disabled = false;
            showToast(response.message || 'Failed to update document.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
