<?php
/**
 * API endpoint: faculty grade encoding.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('faculty');
require_permission('encode_grades');

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
$schoolYear = clean_input($input['school_year'] ?? '');
$semester = clean_input($input['semester'] ?? '1st');

$prelim = is_numeric($input['prelim'] ?? null) ? (float) $input['prelim'] : null;
$midterm = is_numeric($input['midterm'] ?? null) ? (float) $input['midterm'] : null;
$finals = is_numeric($input['finals'] ?? null) ? (float) $input['finals'] : null;

if ($studentId <= 0 || $subjectId <= 0 || $schoolYear === '' || !in_array($semester, ['1st', '2nd', 'summer'], true)) {
    json_response(false, 'Invalid grade payload.', [], 422);
}

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
if (!$faculty) {
    json_response(false, 'Faculty profile not found.', [], 404);
}
$facultyId = (int) $faculty['id'];

$gradeParts = array_filter([$prelim, $midterm, $finals], static fn($v) => $v !== null);
$finalGrade = $gradeParts ? round(array_sum($gradeParts) / count($gradeParts), 2) : null;
$remarks = 'INCOMPLETE';
if ($finalGrade !== null) {
    $remarks = $finalGrade >= 75 ? 'PASSED' : 'FAILED';
}

$db = get_db();
try {
    $db->prepare(
        'INSERT INTO grades (
            student_id, subject_id, faculty_id, section_id, school_year, semester,
            prelim, midterm, finals, final_grade, remarks, encoded_at
         ) VALUES (
            :student_id, :subject_id, :faculty_id, :section_id, :school_year, :semester,
            :prelim, :midterm, :finals, :final_grade, :remarks, NOW()
         )
         ON DUPLICATE KEY UPDATE
            faculty_id = VALUES(faculty_id),
            section_id = VALUES(section_id),
            prelim = VALUES(prelim),
            midterm = VALUES(midterm),
            finals = VALUES(finals),
            final_grade = VALUES(final_grade),
            remarks = VALUES(remarks),
            encoded_at = NOW(),
            updated_at = NOW()'
    )->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
        'faculty_id' => $facultyId,
        'section_id' => $sectionId > 0 ? $sectionId : null,
        'school_year' => $schoolYear,
        'semester' => $semester,
        'prelim' => $prelim,
        'midterm' => $midterm,
        'finals' => $finals,
        'final_grade' => $finalGrade,
        'remarks' => $remarks,
    ]);

    $studentUser = db_fetch_one('SELECT user_id FROM students WHERE id = :id LIMIT 1', ['id' => $studentId]);
    if ($studentUser) {
        $db->prepare(
            'INSERT INTO notifications (user_id, title, message, link_url, type, is_read)
             VALUES (:user_id, :title, :message, :link_url, "grade", 0)'
        )->execute([
            'user_id' => $studentUser['user_id'],
            'title' => 'Grade Updated',
            'message' => 'A faculty member has encoded/updated your grade.',
            'link_url' => app_url('student/grades.php'),
        ]);
    }

    log_audit('SAVE_GRADE', 'faculty_grading', "Saved grade for student #{$studentId}, subject #{$subjectId}");
    json_response(true, 'Grade saved successfully.', [
        'grade' => [
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'prelim' => $prelim,
            'midterm' => $midterm,
            'finals' => $finals,
            'final_grade' => $finalGrade,
            'remarks' => $remarks,
        ],
    ]);
} catch (Throwable $e) {
    error_log('faculty/api/save_grade error: ' . $e->getMessage());
    json_response(false, 'Unable to save grade.', [], 500);
}
