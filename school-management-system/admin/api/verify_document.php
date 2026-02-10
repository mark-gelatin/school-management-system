<?php
/**
 * API endpoint: verify/reject uploaded student documents.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('verify_documents');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$studentDocumentId = (int) ($input['student_document_id'] ?? 0);
$action = clean_input($input['action'] ?? '');
$remarks = clean_input($input['remarks'] ?? '');

if ($studentDocumentId <= 0 || !in_array($action, ['verify', 'reject'], true)) {
    json_response(false, 'Invalid request payload.', [], 422);
}

$status = $action === 'verify' ? 'verified' : 'rejected';
$db = get_db();

try {
    $db->beginTransaction();

    $record = db_fetch_one(
        'SELECT sd.id, sd.student_id, s.user_id, d.name AS document_name
         FROM student_documents sd
         INNER JOIN students s ON s.id = sd.student_id
         INNER JOIN documents d ON d.id = sd.document_id
         WHERE sd.id = :id
         LIMIT 1',
        ['id' => $studentDocumentId]
    );
    if (!$record) {
        $db->rollBack();
        json_response(false, 'Document record not found.', [], 404);
    }

    $db->prepare(
        'UPDATE student_documents
         SET status = :status,
             verified_by = :verified_by,
             verified_at = NOW(),
             remarks = :remarks,
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'status' => $status,
        'verified_by' => current_user_id(),
        'remarks' => $remarks !== '' ? $remarks : null,
        'id' => $studentDocumentId,
    ]);

    $title = $status === 'verified' ? 'Document Verified' : 'Document Rejected';
    $message = $status === 'verified'
        ? "{$record['document_name']} has been verified."
        : "{$record['document_name']} was rejected. Please re-upload a valid file.";

    $db->prepare(
        'INSERT INTO notifications (user_id, title, message, link_url, type, is_read)
         VALUES (:user_id, :title, :message, :link_url, "system", 0)'
    )->execute([
        'user_id' => $record['user_id'],
        'title' => $title,
        'message' => $message,
        'link_url' => app_url('student/documents.php'),
    ]);

    $db->commit();
    log_audit(strtoupper($status) . '_DOCUMENT', 'documents', "Document #{$studentDocumentId} {$status}");
    json_response(true, 'Document status updated.', ['status' => $status, 'student_document_id' => $studentDocumentId]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('admin/api/verify_document error: ' . $e->getMessage());
    json_response(false, 'Unable to update document status.', [], 500);
}
