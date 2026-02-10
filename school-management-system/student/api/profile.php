<?php
/**
 * API endpoint: update student profile.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('student');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$firstName = clean_input($input['first_name'] ?? '');
$lastName = clean_input($input['last_name'] ?? '');
$phone = clean_input($input['phone'] ?? '');
$address = clean_input($input['address'] ?? '');
$guardianName = clean_input($input['guardian_name'] ?? '');
$guardianPhone = clean_input($input['guardian_phone'] ?? '');

if ($firstName === '' || $lastName === '') {
    json_response(false, 'First name and last name are required.', [], 422);
}

$userId = (int) current_user_id();
$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => $userId]);
if (!$student) {
    json_response(false, 'Student profile not found.', [], 404);
}

$db = get_db();
try {
    $db->beginTransaction();
    $db->prepare(
        'UPDATE users
         SET first_name = :first_name, last_name = :last_name, phone = :phone, updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone !== '' ? $phone : null,
        'id' => $userId,
    ]);

    $db->prepare(
        'UPDATE students
         SET address = :address, guardian_name = :guardian_name, guardian_phone = :guardian_phone, updated_at = NOW()
         WHERE id = :student_id'
    )->execute([
        'address' => $address !== '' ? $address : null,
        'guardian_name' => $guardianName !== '' ? $guardianName : null,
        'guardian_phone' => $guardianPhone !== '' ? $guardianPhone : null,
        'student_id' => $student['id'],
    ]);
    $db->commit();

    $_SESSION['auth_user']['first_name'] = $firstName;
    $_SESSION['auth_user']['last_name'] = $lastName;

    log_audit('UPDATE_PROFILE', 'student_profile', "Student updated profile #{$userId}");
    json_response(true, 'Profile updated successfully.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('student/api/profile error: ' . $e->getMessage());
    json_response(false, 'Unable to update profile.', [], 500);
}
