<?php
/**
 * API endpoint: sections CRUD actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('manage_sections');

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
        $name = strtoupper(clean_input($input['name'] ?? ''));
        $schoolYear = clean_input($input['school_year'] ?? '');
        $yearLevel = (int) ($input['year_level'] ?? 1);
        $adviserFacultyId = (int) ($input['adviser_faculty_id'] ?? 0);

        if ($programId <= 0 || $name === '' || $schoolYear === '') {
            json_response(false, 'Program, section name, and school year are required.', [], 422);
        }
        if ($yearLevel < 1 || $yearLevel > 6) {
            $yearLevel = 1;
        }

        $stmt = $db->prepare(
            'INSERT INTO sections (program_id, name, school_year, year_level, adviser_faculty_id, status)
             VALUES (:program_id, :name, :school_year, :year_level, :adviser_faculty_id, "active")'
        );
        $stmt->execute([
            'program_id' => $programId,
            'name' => $name,
            'school_year' => $schoolYear,
            'year_level' => $yearLevel,
            'adviser_faculty_id' => $adviserFacultyId > 0 ? $adviserFacultyId : null,
        ]);

        $sectionId = (int) $db->lastInsertId();
        log_audit('CREATE_SECTION', 'admin_sections', "Created section {$name}");
        json_response(true, 'Section created.', [
            'section' => [
                'id' => $sectionId,
                'name' => $name,
                'school_year' => $schoolYear,
                'year_level' => $yearLevel,
                'status' => 'active',
            ],
        ]);
    }

    if ($action === 'toggle_status') {
        $sectionId = (int) ($input['section_id'] ?? 0);
        $status = clean_input($input['status'] ?? '');
        if ($sectionId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            json_response(false, 'Invalid request payload.', [], 422);
        }

        $db->prepare('UPDATE sections SET status = :status, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => $status, 'id' => $sectionId]);

        log_audit('UPDATE_SECTION_STATUS', 'admin_sections', "Section #{$sectionId} set to {$status}");
        json_response(true, 'Section status updated.', ['section_id' => $sectionId, 'status' => $status]);
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    error_log('admin/api/sections error: ' . $e->getMessage());
    json_response(false, 'Unable to save section.', [], 500);
}
