<?php
/**
 * API endpoint: faculty LMS lesson actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/permissions.php';
require_once __DIR__ . '/../../../includes/functions.php';

require_role('faculty');
require_permission('manage_lessons');

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

if ($action !== 'create') {
    json_response(false, 'Unknown action.', [], 422);
}

$moduleId = (int) ($input['module_id'] ?? 0);
$title = clean_input($input['title'] ?? '');
$contentText = clean_input($input['content_text'] ?? '');
$resourceLink = clean_input($input['resource_link'] ?? '');
$dueDate = clean_input($input['due_date'] ?? '');
$orderNo = (int) ($input['order_no'] ?? 1);

if ($moduleId <= 0 || $title === '') {
    json_response(false, 'Module and lesson title are required.', [], 422);
}

$module = db_fetch_one(
    'SELECT id FROM lms_modules WHERE id = :id AND faculty_id = :faculty_id LIMIT 1',
    ['id' => $moduleId, 'faculty_id' => $facultyId]
);
if (!$module) {
    json_response(false, 'Module not found or unauthorized.', [], 403);
}

if ($resourceLink !== '' && !filter_var($resourceLink, FILTER_VALIDATE_URL)) {
    json_response(false, 'Invalid resource link URL.', [], 422);
}

if ($orderNo < 1) {
    $orderNo = 1;
}

try {
    get_db()->prepare(
        'INSERT INTO lms_lessons (module_id, title, content_text, resource_link, due_date, order_no)
         VALUES (:module_id, :title, :content_text, :resource_link, :due_date, :order_no)'
    )->execute([
        'module_id' => $moduleId,
        'title' => $title,
        'content_text' => $contentText !== '' ? $contentText : null,
        'resource_link' => $resourceLink !== '' ? $resourceLink : null,
        'due_date' => $dueDate !== '' ? $dueDate : null,
        'order_no' => $orderNo,
    ]);

    $lessonId = (int) get_db()->lastInsertId();
    log_audit('CREATE_LMS_LESSON', 'faculty_lms', "Created lesson #{$lessonId} in module #{$moduleId}");
    json_response(true, 'Lesson created successfully.', [
        'lesson' => [
            'id' => $lessonId,
            'module_id' => $moduleId,
            'title' => $title,
            'due_date' => $dueDate,
            'order_no' => $orderNo,
        ],
    ]);
} catch (Throwable $e) {
    error_log('faculty/lms/api/lessons error: ' . $e->getMessage());
    json_response(false, 'Unable to create lesson.', [], 500);
}
