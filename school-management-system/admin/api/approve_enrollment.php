<?php
/**
 * API endpoint: approve/reject enrollment requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('approve_enrollment');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$enrollmentId = (int) ($input['enrollment_id'] ?? 0);
$action = clean_input($input['action'] ?? '');
$remarks = clean_input($input['remarks'] ?? '');

if ($enrollmentId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    json_response(false, 'Invalid request payload.', [], 422);
}

$db = get_db();
$status = $action === 'approve' ? 'approved' : 'rejected';

try {
    $db->beginTransaction();

    $enrollment = db_fetch_one(
        'SELECT e.id, e.student_id, s.user_id
         FROM enrollments e
         INNER JOIN students s ON s.id = e.student_id
         WHERE e.id = :id
         LIMIT 1',
        ['id' => $enrollmentId]
    );

    if (!$enrollment) {
        $db->rollBack();
        json_response(false, 'Enrollment record not found.', [], 404);
    }

    $stmt = $db->prepare(
        'UPDATE enrollments
         SET status = :status,
             remarks = :remarks,
             reviewed_by = :reviewed_by,
             reviewed_at = NOW(),
             updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'remarks' => $remarks !== '' ? $remarks : null,
        'reviewed_by' => current_user_id(),
        'id' => $enrollmentId,
    ]);

    $notifTitle = $status === 'approved' ? 'Enrollment Approved' : 'Enrollment Rejected';
    $notifMessage = $status === 'approved'
        ? 'Your enrollment request has been approved.'
        : 'Your enrollment request has been rejected. Please check remarks and resubmit.';

    $db->prepare(
        'INSERT INTO notifications (user_id, title, message, link_url, type, is_read)
         VALUES (:user_id, :title, :message, :link_url, "enrollment", 0)'
    )->execute([
        'user_id' => $enrollment['user_id'],
        'title' => $notifTitle,
        'message' => $notifMessage,
        'link_url' => app_url('student/enrollment.php'),
    ]);

    $db->commit();
    log_audit(strtoupper($status), 'enrollment', "Enrollment #{$enrollmentId} {$status}");
    json_response(true, 'Enrollment updated successfully.', ['status' => $status, 'enrollment_id' => $enrollmentId]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('approve_enrollment API error: ' . $e->getMessage());
    json_response(false, 'Failed to process enrollment.', [], 500);
}
