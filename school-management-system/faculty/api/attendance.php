<?php
/**
 * API endpoint: create/update attendance records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('faculty');
require_permission('manage_attendance');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$studentId = (int) ($input['student_id'] ?? 0);
$subjectId = (int) ($input['subject_id'] ?? 0);
$sectionId = (int) ($input['section_id'] ?? 0);
$attendanceDate = clean_input($input['attendance_date'] ?? '');
$status = clean_input($input['status'] ?? 'present');
$remarks = clean_input($input['remarks'] ?? '');

if ($studentId <= 0 || $subjectId <= 0 || $sectionId <= 0 || $attendanceDate === '' || !in_array($status, ['present', 'absent', 'late', 'excused'], true)) {
    json_response(false, 'Invalid attendance payload.', [], 422);
}

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$faculty) {
    json_response(false, 'Faculty profile not found.', [], 404);
}

try {
    get_db()->prepare(
        'INSERT INTO attendance_records (
            subject_id, section_id, student_id, faculty_id, attendance_date, status, remarks
         ) VALUES (
            :subject_id, :section_id, :student_id, :faculty_id, :attendance_date, :status, :remarks
         )
         ON DUPLICATE KEY UPDATE
            section_id = VALUES(section_id),
            faculty_id = VALUES(faculty_id),
            status = VALUES(status),
            remarks = VALUES(remarks)'
    )->execute([
        'subject_id' => $subjectId,
        'section_id' => $sectionId,
        'student_id' => $studentId,
        'faculty_id' => $faculty['id'],
        'attendance_date' => $attendanceDate,
        'status' => $status,
        'remarks' => $remarks !== '' ? $remarks : null,
    ]);

    log_audit('SAVE_ATTENDANCE', 'faculty_attendance', "Saved attendance for student #{$studentId}");
    json_response(true, 'Attendance saved successfully.', [
        'attendance' => [
            'student_id' => $studentId,
            'status' => $status,
            'attendance_date' => $attendanceDate,
        ],
    ]);
} catch (Throwable $e) {
    error_log('faculty/api/attendance error: ' . $e->getMessage());
    json_response(false, 'Unable to save attendance.', [], 500);
}
