<?php
/**
 * API endpoint: subjects CRUD actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('manage_subjects');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$action = clean_input($input['action'] ?? '');
$db = get_db();

try {
    if ($action === 'create') {
        $programId = (int) ($input['program_id'] ?? 0);
        $code = strtoupper(clean_input($input['code'] ?? ''));
        $title = clean_input($input['title'] ?? '');
        $units = (float) ($input['units'] ?? 3);
        $yearLevel = (int) ($input['year_level'] ?? 1);
        $semester = clean_input($input['semester'] ?? '1st');
        $description = clean_input($input['description'] ?? '');

        if ($code === '' || $title === '') {
            json_response(false, 'Subject code and title are required.', [], 422);
        }
        if (!in_array($semester, ['1st', '2nd', 'summer'], true)) {
            $semester = '1st';
        }
        if ($units <= 0 || $units > 6) {
            $units = 3.0;
        }
        if ($yearLevel < 1 || $yearLevel > 6) {
            $yearLevel = 1;
        }

        $stmt = $db->prepare(
            'INSERT INTO subjects (program_id, code, title, units, description, year_level, semester, status, created_by)
             VALUES (:program_id, :code, :title, :units, :description, :year_level, :semester, "active", :created_by)'
        );
        $stmt->execute([
            'program_id' => $programId > 0 ? $programId : null,
            'code' => $code,
            'title' => $title,
            'units' => $units,
            'description' => $description !== '' ? $description : null,
            'year_level' => $yearLevel,
            'semester' => $semester,
            'created_by' => current_user_id(),
        ]);

        $subjectId = (int) $db->lastInsertId();
        log_audit('CREATE_SUBJECT', 'admin_subjects', "Created subject {$code}");
        json_response(true, 'Subject created.', [
            'subject' => [
                'id' => $subjectId,
                'code' => $code,
                'title' => $title,
                'units' => $units,
                'semester' => $semester,
                'status' => 'active',
            ],
        ]);
    }

    if ($action === 'toggle_status') {
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $status = clean_input($input['status'] ?? '');
        if ($subjectId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            json_response(false, 'Invalid request payload.', [], 422);
        }

        $db->prepare('UPDATE subjects SET status = :status, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => $status, 'id' => $subjectId]);

        log_audit('UPDATE_SUBJECT_STATUS', 'admin_subjects', "Subject #{$subjectId} set to {$status}");
        json_response(true, 'Subject status updated.', ['subject_id' => $subjectId, 'status' => $status]);
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    error_log('admin/api/subjects error: ' . $e->getMessage());
    json_response(false, 'Unable to save subject.', [], 500);
}
