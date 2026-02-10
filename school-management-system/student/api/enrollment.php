<?php
/**
 * API endpoint: create student enrollment request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('student');
require_permission('enroll_subjects');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$schoolYear = clean_input($input['school_year'] ?? '');
$semester = clean_input($input['semester'] ?? '1st');
$sectionId = (int) ($input['section_id'] ?? 0);
$subjectsInput = $input['subjects'] ?? [];
$subjects = is_array($subjectsInput) ? array_values(array_unique(array_map('intval', $subjectsInput))) : [];
$subjects = array_filter($subjects, static fn($id) => $id > 0);

if ($schoolYear === '' || !in_array($semester, ['1st', '2nd', 'summer'], true) || $subjects === []) {
    json_response(false, 'School year, semester, and at least one subject are required.', [], 422);
}

$student = db_fetch_one(
    'SELECT id, program_id FROM students WHERE user_id = :user_id LIMIT 1',
    ['user_id' => current_user_id()]
);
if (!$student) {
    json_response(false, 'Student profile not found.', [], 404);
}

$db = get_db();
try {
    $exists = db_fetch_one(
        'SELECT id FROM enrollments WHERE student_id = :student_id AND school_year = :school_year AND semester = :semester LIMIT 1',
        [
            'student_id' => $student['id'],
            'school_year' => $schoolYear,
            'semester' => $semester,
        ]
    );
    if ($exists) {
        json_response(false, 'You already submitted enrollment for this term.', [], 409);
    }

    $db->beginTransaction();
    $db->prepare(
        'INSERT INTO enrollments (student_id, program_id, section_id, school_year, semester, status, submitted_at)
         VALUES (:student_id, :program_id, :section_id, :school_year, :semester, "pending", NOW())'
    )->execute([
        'student_id' => $student['id'],
        'program_id' => $student['program_id'],
        'section_id' => $sectionId > 0 ? $sectionId : null,
        'school_year' => $schoolYear,
        'semester' => $semester,
    ]);

    $enrollmentId = (int) $db->lastInsertId();
    $stmt = $db->prepare(
        'INSERT INTO enrollment_subjects (enrollment_id, subject_id, status)
         VALUES (:enrollment_id, :subject_id, "enrolled")'
    );
    foreach ($subjects as $subjectId) {
        $stmt->execute([
            'enrollment_id' => $enrollmentId,
            'subject_id' => $subjectId,
        ]);
    }

    $db->commit();
    log_audit('SUBMIT_ENROLLMENT', 'student_enrollment', "Student submitted enrollment #{$enrollmentId}");
    json_response(true, 'Enrollment request submitted successfully.', [
        'enrollment' => [
            'id' => $enrollmentId,
            'status' => 'pending',
            'school_year' => $schoolYear,
            'semester' => $semester,
        ],
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('student/api/enrollment error: ' . $e->getMessage());
    json_response(false, 'Unable to submit enrollment.', [], 500);
}
