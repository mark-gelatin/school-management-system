<?php
/**
 * API endpoint: grade student LMS submissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/permissions.php';
require_once __DIR__ . '/../../../includes/functions.php';

require_role('faculty');
require_permission('grade_submissions');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$submissionId = (int) ($input['submission_id'] ?? 0);
$score = is_numeric($input['score'] ?? null) ? (float) $input['score'] : null;
$feedback = clean_input($input['feedback'] ?? '');

if ($submissionId <= 0 || $score === null || $score < 0 || $score > 100) {
    json_response(false, 'Submission ID and score (0-100) are required.', [], 422);
}

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$faculty) {
    json_response(false, 'Faculty profile not found.', [], 404);
}
$facultyId = (int) $faculty['id'];

$submission = db_fetch_one(
    'SELECT ls.id, ls.student_id, ll.title AS lesson_title, lm.faculty_id,
            s.user_id AS student_user_id
     FROM lms_submissions ls
     INNER JOIN lms_lessons ll ON ll.id = ls.lesson_id
     INNER JOIN lms_modules lm ON lm.id = ll.module_id
     INNER JOIN students s ON s.id = ls.student_id
     WHERE ls.id = :id
     LIMIT 1',
    ['id' => $submissionId]
);
if (!$submission) {
    json_response(false, 'Submission not found.', [], 404);
}
if ((int) $submission['faculty_id'] !== $facultyId) {
    json_response(false, 'Not authorized to grade this submission.', [], 403);
}

$db = get_db();
try {
    $db->beginTransaction();
    $db->prepare(
        'UPDATE lms_submissions
         SET score = :score,
             feedback = :feedback,
             status = "graded",
             graded_by = :graded_by,
             graded_at = NOW(),
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'score' => $score,
        'feedback' => $feedback !== '' ? $feedback : null,
        'graded_by' => $facultyId,
        'id' => $submissionId,
    ]);

    $db->prepare(
        'INSERT INTO notifications (user_id, title, message, link_url, type, is_read)
         VALUES (:user_id, :title, :message, :link_url, "lms", 0)'
    )->execute([
        'user_id' => $submission['student_user_id'],
        'title' => 'LMS Submission Graded',
        'message' => 'Your submission for "' . $submission['lesson_title'] . '" has been graded.',
        'link_url' => app_url('student/lms/modules.php'),
    ]);

    $db->commit();
    log_audit('GRADE_LMS_SUBMISSION', 'faculty_lms', "Graded submission #{$submissionId}");
    json_response(true, 'Submission graded successfully.', [
        'submission' => [
            'id' => $submissionId,
            'score' => $score,
            'feedback' => $feedback,
            'status' => 'graded',
        ],
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('faculty/lms/api/grade_submission error: ' . $e->getMessage());
    json_response(false, 'Unable to grade submission.', [], 500);
}
