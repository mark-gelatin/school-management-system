<?php
/**
 * Student document upload and status page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
require_permission('upload_documents');

$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$studentId = (int) ($student['id'] ?? 0);

$documentTypes = db_fetch_all(
    'SELECT id, name, description
     FROM documents
     WHERE status = "active"
     ORDER BY name ASC'
);
$uploads = [];
if ($studentId > 0) {
    $uploads = db_fetch_all(
        'SELECT sd.id, d.name AS document_name, sd.file_path, sd.status, sd.uploaded_at, sd.remarks
         FROM student_documents sd
         INNER JOIN documents d ON d.id = sd.document_id
         WHERE sd.student_id = :student_id
         ORDER BY sd.uploaded_at DESC',
        ['student_id' => $studentId]
    );
}

$title = 'Documents';
$activePage = 'documents';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>Document Uploads</h1>
    <p class="text-muted">Upload required admissions and registrar documents.</p>

    <section class="card">
        <h2>Upload Document</h2>
        <form id="uploadDocumentForm" class="form-grid" enctype="multipart/form-data">
            <label>Document Type
                <select name="document_id" required>
                    <option value="">Select document</option>
                    <?php foreach ($documentTypes as $doc): ?>
                        <option value="<?= e((string) $doc['id']) ?>">
                            <?= e((string) $doc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>File (PDF/JPG/PNG/DOC up to 10MB)
                <input type="file" name="document_file" required>
            </label>
            <button type="submit" class="btn btn-primary">Upload</button>
        </form>
    </section>

    <section class="card">
        <h2>Uploaded Documents</h2>
        <div class="table-wrap">
            <table id="uploadedDocumentsTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Document</th>
                    <th>File</th>
                    <th>Status</th>
                    <th>Uploaded At</th>
                    <th>Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($uploads === []): ?>
                    <tr><td colspan="6">No uploaded documents yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($uploads as $upload): ?>
                        <tr>
                            <td>#<?= e((string) $upload['id']) ?></td>
                            <td><?= e((string) $upload['document_name']) ?></td>
                            <td><a href="<?= e(app_url($upload['file_path'])) ?>" target="_blank" rel="noopener" class="link-btn">View</a></td>
                            <td><span class="badge"><?= e(ucfirst((string) $upload['status'])) ?></span></td>
                            <td><?= e((string) $upload['uploaded_at']) ?></td>
                            <td><?= e((string) ($upload['remarks'] ?? '-')) ?></td>
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
    const form = document.getElementById('uploadDocumentForm');
    const tbody = document.querySelector('#uploadedDocumentsTable tbody');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        const response = await fetch(resolveAppUrl('student/api/upload_document.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!result.success) {
            showToast(result.message || 'Upload failed.', 'error');
            return;
        }

        const docName = form.querySelector('select[name="document_id"] option:checked').textContent.trim();
        const doc = result.data.document;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>#${doc.id}</td>
            <td>${docName}</td>
            <td><a href="${resolveAppUrl(doc.file_path)}" target="_blank" rel="noopener" class="link-btn">View</a></td>
            <td><span class="badge">Pending</span></td>
            <td>${doc.uploaded_at}</td>
            <td>-</td>
        `;
        if (tbody.querySelector('td[colspan="6"]')) {
            tbody.innerHTML = '';
        }
        tbody.prepend(tr);
        form.reset();
        showToast(result.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
