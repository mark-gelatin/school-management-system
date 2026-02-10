<?php
/**
 * API endpoint: manage users (admin only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');
require_permission('manage_users');

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
        $role = clean_input($input['role'] ?? 'student');
        $email = strtolower(clean_input($input['email'] ?? ''));
        $firstName = clean_input($input['first_name'] ?? '');
        $lastName = clean_input($input['last_name'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $phone = clean_input($input['phone'] ?? '');

        if (!in_array($role, ['admin', 'student', 'faculty'], true)) {
            json_response(false, 'Invalid role.', [], 422);
        }
        if ($email === '' || $firstName === '' || $lastName === '' || $password === '') {
            json_response(false, 'Required fields are missing.', [], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Invalid email format.', [], 422);
        }
        if (strlen($password) < 8) {
            json_response(false, 'Password must be at least 8 characters.', [], 422);
        }

        $exists = db_fetch_one('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if ($exists) {
            json_response(false, 'Email already exists.', [], 409);
        }

        $roleId = $role === 'admin' ? 1 : ($role === 'student' ? 2 : 3);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        $db->prepare(
            'INSERT INTO users (role, role_id, email, password_hash, first_name, last_name, phone, status, is_verified)
             VALUES (:role, :role_id, :email, :password_hash, :first_name, :last_name, :phone, "active", 1)'
        )->execute([
            'role' => $role,
            'role_id' => $roleId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone !== '' ? $phone : null,
        ]);

        $userId = (int) $db->lastInsertId();
        if ($role === 'student') {
            $program = db_fetch_one('SELECT id FROM programs WHERE status = "active" ORDER BY id ASC LIMIT 1');
            $programId = (int) ($program['id'] ?? 1);
            $studentNo = date('Y') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $db->prepare(
                'INSERT INTO students (user_id, student_no, program_id, year_level, admission_date)
                 VALUES (:user_id, :student_no, :program_id, 1, CURDATE())'
            )->execute([
                'user_id' => $userId,
                'student_no' => $studentNo,
                'program_id' => $programId,
            ]);
        } elseif ($role === 'faculty') {
            $employeeNo = 'FAC-' . date('Y') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $db->prepare(
                'INSERT INTO faculty (user_id, employee_no, department, hire_date)
                 VALUES (:user_id, :employee_no, :department, CURDATE())'
            )->execute([
                'user_id' => $userId,
                'employee_no' => $employeeNo,
                'department' => 'General Faculty',
            ]);
        }

        $db->commit();

        log_audit('CREATE_USER', 'admin_users', "Created {$role} user {$email}");
        json_response(true, 'User created successfully.', [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'status' => 'active',
            ],
        ]);
    }

    if ($action === 'toggle_status') {
        $userId = (int) ($input['user_id'] ?? 0);
        $status = clean_input($input['status'] ?? '');
        if ($userId <= 0 || !in_array($status, ['active', 'inactive', 'suspended'], true)) {
            json_response(false, 'Invalid status update request.', [], 422);
        }

        $db->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id')
            ->execute([
                'status' => $status,
                'id' => $userId,
            ]);

        log_audit('UPDATE_USER_STATUS', 'admin_users', "Updated user #{$userId} status to {$status}");
        json_response(true, 'User status updated.', ['user_id' => $userId, 'status' => $status]);
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('admin/api/users error: ' . $e->getMessage());
    json_response(false, 'Unable to complete user request.', [], 500);
}
