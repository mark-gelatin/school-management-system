<?php
/**
 * Student/faculty registration with OTP flow.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    $user = current_user();
    if ($user) {
        redirect_to_dashboard_by_role($user['role']);
    }
}

$error = '';

if (is_post_request()) {
    $role = clean_input($_POST['role'] ?? 'student');
    $firstName = clean_input($_POST['first_name'] ?? '');
    $lastName = clean_input($_POST['last_name'] ?? '');
    $email = strtolower(clean_input($_POST['email'] ?? ''));
    $phone = clean_input($_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!in_array($role, ['student', 'faculty'], true)) {
        $error = 'Invalid role selected.';
    } elseif ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $db = get_db();
        $exists = db_fetch_one('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if ($exists) {
            $error = 'Email is already registered.';
        } else {
            try {
                $db->beginTransaction();

                $roleId = $role === 'student' ? 2 : 3;
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $db->prepare(
                    'INSERT INTO users (role, role_id, email, password_hash, first_name, last_name, phone, status, is_verified)
                     VALUES (:role, :role_id, :email, :password_hash, :first_name, :last_name, :phone, "pending", 0)'
                );
                $stmt->execute([
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
                } else {
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

                $otpCode = generate_otp_code();
                create_email_otp($userId, $otpCode);
                $mailSent = send_otp_email($email, $firstName . ' ' . $lastName, $otpCode);

                $db->commit();
                log_audit('REGISTER', 'auth', "New {$role} registration: {$email}");

                if ($mailSent) {
                    set_flash('success', 'Registration successful. OTP has been sent to your email.');
                } else {
                    set_flash('warning', 'Registration successful, but OTP email could not be sent. Use resend OTP.');
                }

                header('Location: ' . app_url('auth/verify_otp.php?email=' . urlencode($email)));
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Registration failed. Please try again.';
                error_log('Registration error: ' . $e->getMessage());
            }
        }
    }
}

$title = 'Register';
include __DIR__ . '/../includes/header.php';
?>
<main class="content-area no-sidebar">
    <section class="card auth-card">
        <h1>Create Account</h1>
        <p class="text-muted">Register for Colegio De Amore student or faculty portal.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <label>Role
                <select name="role" required>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                </select>
            </label>
            <label>First Name
                <input type="text" name="first_name" required>
            </label>
            <label>Last Name
                <input type="text" name="last_name" required>
            </label>
            <label>Email
                <input type="email" name="email" required>
            </label>
            <label>Phone
                <input type="text" name="phone">
            </label>
            <label>Password
                <input type="password" name="password" minlength="8" required>
            </label>
            <label>Confirm Password
                <input type="password" name="confirm_password" minlength="8" required>
            </label>
            <button type="submit" class="btn btn-primary">Register</button>
        </form>

        <div class="auth-links">
            <a href="<?= e(app_url('auth/login.php')) ?>">Already have an account? Login</a>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
