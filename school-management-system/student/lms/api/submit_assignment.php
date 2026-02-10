<?php
/**
 * API endpoint: submit/re-submit LMS lesson work.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/permissions.php';
require_once __DIR__ . '/../../../includes/functions.php';

require_role('student');
require_permission('submit_lms_work');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$lessonId = (int) ($_POST['lesson_id'] ?? 0);
$submissionText = clean_input($_POST['submission_text'] ?? '');
if ($lessonId <= 0) {
    json_response(false, 'Lesson ID is required.', [], 422);
}
if ($submissionText === '' && (!isset($_FILES['attachment_file']) || ($_FILES['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
    json_response(false, 'Provide a text submission or an attachment.', [], 422);
}

$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$student) {
    json_response(false, 'Student profile not found.', [], 404);
}
$studentId = (int) $student['id'];

$lesson = db_fetch_one(
    'SELECT ll.id, ll.due_date, lm.title AS module_title, ll.title AS lesson_title
     FROM lms_lessons ll
     INNER JOIN lms_modules lm ON lm.id = ll.module_id
     WHERE ll.id = :id
     LIMIT 1',
    ['id' => $lessonId]
);
if (!$lesson) {
    json_response(false, 'Lesson not found.', [], 404);
}

$attachmentPath = null;
if (isset($_FILES['attachment_file']) && ($_FILES['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['attachment_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(false, 'Attachment upload failed.', [], 422);
    }

    $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'];
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        json_response(false, 'Invalid attachment file type.', [], 422);
    }
    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        json_response(false, 'Attachment exceeds 10MB.', [], 422);
    }

    $uploadDir = dirname(__DIR__, 3) . '/uploads/documents';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        json_response(false, 'Unable to prepare upload directory.', [], 500);
    }

    $filename = 'submission_' . $studentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        json_response(false, 'Unable to save attachment file.', [], 500);
    }
    $attachmentPath = 'uploads/documents/' . $filename;
}

$existing = db_fetch_one(
    'SELECT id, status FROM lms_submissions WHERE lesson_id = :lesson_id AND student_id = :student_id LIMIT 1',
    ['lesson_id' => $lessonId, 'student_id' => $studentId]
);

$isLate = !empty($lesson['due_date']) && strtotime((string) $lesson['due_date']) < time();
$status = $isLate ? 'late' : ($existing ? 'resubmitted' : 'submitted');

$db = get_db();
try {
    if ($existing) {
        $db->prepare(
            'UPDATE lms_submissions
             SET submission_text = :submission_text,
                 attachment_path = COALESCE(:attachment_path, attachment_path),
                 submitted_at = NOW(),
                 status = :status,
                 score = NULL,
                 feedback = NULL,
                 graded_by = NULL,
                 graded_at = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'submission_text' => $submissionText !== '' ? $submissionText : null,
            'attachment_path' => $attachmentPath,
            'status' => $status,
            'id' => $existing['id'],
        ]);
        $submissionId = (int) $existing['id'];
    } else {
        $db->prepare(
            'INSERT INTO lms_submissions (lesson_id, student_id, submission_text, attachment_path, submitted_at, status)
             VALUES (:lesson_id, :student_id, :submission_text, :attachment_path, NOW(), :status)'
        )->execute([
            'lesson_id' => $lessonId,
            'student_id' => $studentId,
            'submission_text' => $submissionText !== '' ? $submissionText : null,
            'attachment_path' => $attachmentPath,
            'status' => $status,
        ]);
        $submissionId = (int) $db->lastInsertId();
    }

    log_audit('SUBMIT_LMS_WORK', 'student_lms', "Submission #{$submissionId} for lesson #{$lessonId}");
    json_response(true, 'Submission saved successfully.', [
        'submission' => [
            'id' => $submissionId,
            'status' => $status,
            'submitted_at' => date('Y-m-d H:i:s'),
            'attachment_path' => $attachmentPath,
        ],
    ]);
} catch (Throwable $e) {
    error_log('student/lms/api/submit_assignment error: ' . $e->getMessage());
    json_response(false, 'Unable to save LMS submission.', [], 500);
}
