<?php
/**
 * Student LMS modules and lessons page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('student');
require_permission('access_lms');

$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$studentId = (int) ($student['id'] ?? 0);

$modules = [];
if ($studentId > 0) {
    $modules = db_fetch_all(
        'SELECT DISTINCT lm.id, lm.title, lm.description, lm.status, lm.published_at,
                sub.code AS subject_code, sub.title AS subject_title,
                CONCAT(u.first_name, " ", u.last_name) AS faculty_name
         FROM lms_modules lm
         INNER JOIN subjects sub ON sub.id = lm.subject_id
         INNER JOIN faculty f ON f.id = lm.faculty_id
         INNER JOIN users u ON u.id = f.user_id
         WHERE lm.status = "published"
           AND EXISTS (
                SELECT 1
                FROM enrollments e
                INNER JOIN enrollment_subjects es ON es.enrollment_id = e.id
                WHERE e.student_id = :student_id
                  AND e.status = "approved"
                  AND es.subject_id = lm.subject_id
           )
         ORDER BY lm.published_at DESC, lm.created_at DESC',
        ['student_id' => $studentId]
    );
}

$moduleIds = array_map(static fn($m) => (int) $m['id'], $modules);
$lessonsByModule = [];
if ($moduleIds !== []) {
    $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $sql = "SELECT ll.id, ll.module_id, ll.title, ll.content_text, ll.resource_link, ll.due_date, ll.order_no,
                   ls.id AS submission_id, ls.status AS submission_status, ls.score, ls.feedback, ls.submitted_at, ls.attachment_path
            FROM lms_lessons ll
            LEFT JOIN lms_submissions ls
                ON ls.lesson_id = ll.id
               AND ls.student_id = ?
            WHERE ll.module_id IN ({$placeholders})
            ORDER BY ll.module_id ASC, ll.order_no ASC";

    $stmt = get_db()->prepare($sql);
    $stmt->execute(array_merge([$studentId], $moduleIds));
    foreach ($stmt->fetchAll() as $lesson) {
        $moduleId = (int) $lesson['module_id'];
        $lessonsByModule[$moduleId][] = $lesson;
    }
}

$title = 'LMS Modules';
$activePage = 'lms_modules';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>LMS Modules</h1>
    <p class="text-muted">Access learning modules, lessons, and submit your work.</p>

    <?php if ($modules === []): ?>
        <section class="card"><p>No published modules available yet.</p></section>
    <?php else: ?>
        <?php foreach ($modules as $module): ?>
            <?php $moduleId = (int) $module['id']; ?>
            <section class="card">
                <h2><?= e((string) $module['title']) ?></h2>
                <p><strong>Subject:</strong> <?= e($module['subject_code'] . ' - ' . $module['subject_title']) ?></p>
                <p><strong>Instructor:</strong> <?= e((string) $module['faculty_name']) ?></p>
                <p><?= nl2br(e((string) ($module['description'] ?? 'No description'))) ?></p>

                <?php if (empty($lessonsByModule[$moduleId])): ?>
                    <p class="text-muted">No lessons yet for this module.</p>
                <?php else: ?>
                    <div class="stack-list">
                        <?php foreach ($lessonsByModule[$moduleId] as $lesson): ?>
                            <article class="stack-item lesson-item" data-lesson-id="<?= e((string) $lesson['id']) ?>">
                                <h3><?= e((string) $lesson['title']) ?></h3>
                                <p><?= nl2br(e((string) ($lesson['content_text'] ?? ''))) ?></p>
                                <?php if (!empty($lesson['resource_link'])): ?>
                                    <p><a href="<?= e((string) $lesson['resource_link']) ?>" target="_blank" rel="noopener" class="link-btn">Open Resource</a></p>
                                <?php endif; ?>
                                <p><strong>Due:</strong> <?= e((string) ($lesson['due_date'] ?? 'No deadline')) ?></p>
                                <p>
                                    <strong>Submission Status:</strong>
                                    <span class="badge submission-status"><?= e(ucfirst((string) ($lesson['submission_status'] ?: 'not submitted'))) ?></span>
                                </p>
                                <?php if (!empty($lesson['score'])): ?>
                                    <p><strong>Score:</strong> <?= e((string) $lesson['score']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($lesson['feedback'])): ?>
                                    <p><strong>Feedback:</strong> <?= e((string) $lesson['feedback']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($lesson['attachment_path'])): ?>
                                    <p><a class="link-btn" target="_blank" rel="noopener" href="<?= e(app_url((string) $lesson['attachment_path'])) ?>">View Submitted File</a></p>
                                <?php endif; ?>

                                <form class="lmsSubmissionForm form-grid" enctype="multipart/form-data">
                                    <input type="hidden" name="lesson_id" value="<?= e((string) $lesson['id']) ?>">
                                    <label>Submission Text
                                        <textarea name="submission_text" rows="3" placeholder="Write your submission or reflection here."></textarea>
                                    </label>
                                    <label>Attachment (optional)
                                        <input type="file" name="attachment_file">
                                    </label>
                                    <button type="submit" class="btn btn-primary btn-sm">Submit Work</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lmsSubmissionForm').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(form);
            const response = await fetch(resolveAppUrl('student/lms/api/submit_assignment.php'), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || 'Submission failed.', 'error');
                return;
            }

            const lessonItem = form.closest('.lesson-item');
            const badge = lessonItem.querySelector('.submission-status');
            badge.textContent = result.data.submission.status.charAt(0).toUpperCase() + result.data.submission.status.slice(1);
            form.reset();
            showToast(result.message, 'success');
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
