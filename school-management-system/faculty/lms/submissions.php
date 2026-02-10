<?php
/**
 * Faculty LMS submissions grading page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('faculty');
require_permission('grade_submissions');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$submissions = [];
if ($facultyId > 0) {
    $submissions = db_fetch_all(
        'SELECT
            ls.id, ls.status, ls.submitted_at, ls.submission_text, ls.attachment_path, ls.score, ls.feedback, ls.graded_at,
            ll.title AS lesson_title,
            lm.title AS module_title,
            sub.code AS subject_code,
            s.student_no,
            CONCAT(u.first_name, " ", u.last_name) AS student_name
         FROM lms_submissions ls
         INNER JOIN lms_lessons ll ON ll.id = ls.lesson_id
         INNER JOIN lms_modules lm ON lm.id = ll.module_id
         INNER JOIN subjects sub ON sub.id = lm.subject_id
         INNER JOIN students s ON s.id = ls.student_id
         INNER JOIN users u ON u.id = s.user_id
         WHERE lm.faculty_id = :faculty_id
         ORDER BY ls.submitted_at DESC'
    , ['faculty_id' => $facultyId]);
}

$title = 'LMS Submissions';
$activePage = 'lms_submissions';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>LMS Submissions</h1>
    <p class="text-muted">Review and grade student submissions without page reload.</p>

    <section class="card">
        <div class="table-wrap">
            <table id="submissionsTable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Subject / Module / Lesson</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th>Submission</th>
                    <th>Attachment</th>
                    <th>Score</th>
                    <th>Feedback</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($submissions === []): ?>
                    <tr><td colspan="10">No submissions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): ?>
                        <tr data-submission-id="<?= e((string) $submission['id']) ?>">
                            <td>#<?= e((string) $submission['id']) ?></td>
                            <td><?= e($submission['student_no'] . ' - ' . $submission['student_name']) ?></td>
                            <td>
                                <?= e((string) $submission['subject_code']) ?><br>
                                <small><?= e((string) $submission['module_title']) ?> / <?= e((string) $submission['lesson_title']) ?></small>
                            </td>
                            <td><?= e((string) $submission['submitted_at']) ?></td>
                            <td class="status-text"><span class="badge"><?= e(ucfirst((string) $submission['status'])) ?></span></td>
                            <td><?= e((string) ($submission['submission_text'] ?: '-')) ?></td>
                            <td>
                                <?php if (!empty($submission['attachment_path'])): ?>
                                    <a class="link-btn" href="<?= e(app_url((string) $submission['attachment_path'])) ?>" target="_blank" rel="noopener">View File</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" max="100"
                                       class="score-input" value="<?= e((string) ($submission['score'] ?? '')) ?>">
                            </td>
                            <td>
                                <textarea class="feedback-input" rows="2"><?= e((string) ($submission['feedback'] ?? '')) ?></textarea>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary grade-submission-btn">Save Grade</button>
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
    const tbody = document.querySelector('#submissionsTable tbody');

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.grade-submission-btn');
        if (!button) return;

        const row = button.closest('tr');
        const submissionId = row.dataset.submissionId;
        const score = row.querySelector('.score-input').value;
        const feedback = row.querySelector('.feedback-input').value;

        button.disabled = true;
        const response = await apiRequest('faculty/lms/api/grade_submission.php', {
            method: 'POST',
            body: {
                submission_id: submissionId,
                score,
                feedback
            }
        });
        button.disabled = false;

        if (!response.success) {
            showToast(response.message || 'Unable to grade submission.', 'error');
            return;
        }

        row.querySelector('.status-text').innerHTML = '<span class="badge">Graded</span>';
        showToast(response.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
