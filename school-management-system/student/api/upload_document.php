<?php
/**
 * API endpoint: upload student document.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('student');
require_permission('upload_documents');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$documentId = (int) ($_POST['document_id'] ?? 0);
if ($documentId <= 0) {
    json_response(false, 'Please select a document type.', [], 422);
}
if (!isset($_FILES['document_file'])) {
    json_response(false, 'Document file is required.', [], 422);
}

$file = $_FILES['document_file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(false, 'File upload failed.', [], 422);
}

$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
$originalName = (string) ($file['name'] ?? 'document');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    json_response(false, 'Invalid file type.', [], 422);
}

$maxSize = 10 * 1024 * 1024;
if ((int) ($file['size'] ?? 0) > $maxSize) {
    json_response(false, 'File size exceeds 10MB limit.', [], 422);
}

$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$student) {
    json_response(false, 'Student profile not found.', [], 404);
}

$uploadDir = dirname(__DIR__, 2) . '/uploads/documents';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    json_response(false, 'Unable to create upload directory.', [], 500);
}

$safeFilename = 'doc_' . $student['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$absolutePath = $uploadDir . '/' . $safeFilename;
$relativePath = 'uploads/documents/' . $safeFilename;

if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
    json_response(false, 'Unable to save uploaded file.', [], 500);
}

$db = get_db();
try {
    $db->prepare(
        'INSERT INTO student_documents (student_id, document_id, file_path, status, uploaded_at)
         VALUES (:student_id, :document_id, :file_path, "pending", NOW())'
    )->execute([
        'student_id' => $student['id'],
        'document_id' => $documentId,
        'file_path' => $relativePath,
    ]);

    $id = (int) $db->lastInsertId();
    log_audit('UPLOAD_DOCUMENT', 'student_documents', "Uploaded student document #{$id}");
    json_response(true, 'Document uploaded successfully.', [
        'document' => [
            'id' => $id,
            'file_path' => $relativePath,
            'status' => 'pending',
            'uploaded_at' => date('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    if (file_exists($absolutePath)) {
        @unlink($absolutePath);
    }
    error_log('student/api/upload_document error: ' . $e->getMessage());
    json_response(false, 'Unable to save document record.', [], 500);
}
