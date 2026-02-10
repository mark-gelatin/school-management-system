<?php
/**
 * API endpoint: programs CRUD actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('manage_programs');

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
        $code = strtoupper(clean_input($input['code'] ?? ''));
        $name = clean_input($input['name'] ?? '');
        $description = clean_input($input['description'] ?? '');
        $years = (int) ($input['years_to_complete'] ?? 4);

        if ($code === '' || $name === '') {
            json_response(false, 'Code and name are required.', [], 422);
        }
        if ($years < 1 || $years > 8) {
            $years = 4;
        }

        $stmt = $db->prepare(
            'INSERT INTO programs (code, name, description, years_to_complete, status, created_by)
             VALUES (:code, :name, :description, :years, "active", :created_by)'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'years' => $years,
            'created_by' => current_user_id(),
        ]);
        $programId = (int) $db->lastInsertId();

        log_audit('CREATE_PROGRAM', 'admin_programs', "Created program {$code}");
        json_response(true, 'Program created.', [
            'program' => [
                'id' => $programId,
                'code' => $code,
                'name' => $name,
                'years_to_complete' => $years,
                'status' => 'active',
            ],
        ]);
    }

    if ($action === 'toggle_status') {
        $programId = (int) ($input['program_id'] ?? 0);
        $status = clean_input($input['status'] ?? '');

        if ($programId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            json_response(false, 'Invalid request.', [], 422);
        }

        $db->prepare('UPDATE programs SET status = :status, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => $status, 'id' => $programId]);

        log_audit('UPDATE_PROGRAM_STATUS', 'admin_programs', "Program #{$programId} set to {$status}");
        json_response(true, 'Program status updated.', ['program_id' => $programId, 'status' => $status]);
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    error_log('admin/api/programs error: ' . $e->getMessage());
    json_response(false, 'Unable to save program.', [], 500);
}
