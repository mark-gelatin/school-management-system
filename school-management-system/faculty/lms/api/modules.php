<?php
/**
 * API endpoint: faculty LMS module actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/permissions.php';
require_once __DIR__ . '/../../../includes/functions.php';

require_role('faculty');
require_permission('manage_modules');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$action = clean_input($input['action'] ?? '');
$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$faculty) {
    json_response(false, 'Faculty profile not found.', [], 404);
}
$facultyId = (int) $faculty['id'];
$db = get_db();

try {
    if ($action === 'create') {
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $title = clean_input($input['title'] ?? '');
        $description = clean_input($input['description'] ?? '');
        $status = clean_input($input['status'] ?? 'draft');
        if ($subjectId <= 0 || $title === '' || !in_array($status, ['draft', 'published'], true)) {
            json_response(false, 'Subject, title, and status are required.', [], 422);
        }

        $db->prepare(
            'INSERT INTO lms_modules (subject_id, faculty_id, title, description, status, published_at)
             VALUES (:subject_id, :faculty_id, :title, :description, :status, :published_at)'
        )->execute([
            'subject_id' => $subjectId,
            'faculty_id' => $facultyId,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        $id = (int) $db->lastInsertId();

        log_audit('CREATE_LMS_MODULE', 'faculty_lms', "Created LMS module #{$id}");
        json_response(true, 'Module created successfully.', [
            'module' => [
                'id' => $id,
                'title' => $title,
                'status' => $status,
            ],
        ]);
    }

    if ($action === 'update_status') {
        $moduleId = (int) ($input['module_id'] ?? 0);
        $status = clean_input($input['status'] ?? '');
        if ($moduleId <= 0 || !in_array($status, ['draft', 'published', 'archived'], true)) {
            json_response(false, 'Invalid module status request.', [], 422);
        }

        $db->prepare(
            'UPDATE lms_modules
             SET status = :status,
                 published_at = CASE WHEN :status = "published" THEN NOW() ELSE published_at END,
                 updated_at = NOW()
             WHERE id = :id AND faculty_id = :faculty_id'
        )->execute([
            'status' => $status,
            'id' => $moduleId,
            'faculty_id' => $facultyId,
        ]);

        log_audit('UPDATE_LMS_MODULE_STATUS', 'faculty_lms', "Module #{$moduleId} status {$status}");
        json_response(true, 'Module status updated.', ['module_id' => $moduleId, 'status' => $status]);
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    error_log('faculty/lms/api/modules error: ' . $e->getMessage());
    json_response(false, 'Unable to process module request.', [], 500);
}
