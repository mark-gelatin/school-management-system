<?php
// connect to student management database
session_start();
require_once __DIR__ . '/../../backend/student-management/includes/conn.php';

$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // simple helper
    function val($k) { return isset($_POST[$k]) ? trim($_POST[$k]) : ''; }

    // Personal Information
    $firstName = htmlspecialchars(val('firstName'));
    $middleName = htmlspecialchars(val('middleName'));
    $lastName = htmlspecialchars(val('lastName'));
    $birthdate = htmlspecialchars(val('birthdate'));
    $sex = htmlspecialchars(val('sex'));

    // Admission Information
    $programToEnroll = htmlspecialchars(val('programToEnroll'));
    $educationalStatus = htmlspecialchars(val('educationalStatus'));

    // Contact Information
    $currentAddress = htmlspecialchars(val('currentAddress'));
    $permanentAddress = htmlspecialchars(val('permanentAddress'));
    $mobileNumber = htmlspecialchars(val('mobileNumber'));
    $landlineNumber = htmlspecialchars(val('landlineNumber'));

    // Account Information
    $email = filter_var(val('email'), FILTER_VALIDATE_EMAIL) ? val('email') : '';
    $initialPassword = val('initialPassword');
    $confirmPassword = val('confirmPassword');

    if (!$email) {
        $message = 'Enter a valid email.';
    } elseif ($initialPassword === '' || $confirmPassword === '') {
        $message = 'Enter and confirm password.';
    } elseif ($initialPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
    } else {
        // Check for existing records with same email or same name + birthdate
        // 1) Email check — use SELECT 1 to avoid assuming a specific PK column name
        $stmt = $conn->prepare("SELECT 1 FROM account_info WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $message = 'Database error: ' . $conn->error;
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->close();
                $message = 'This email is already registered.';
            } else {
                $stmt->close();
                // 2) Name + birthdate check — again SELECT 1 to avoid unknown column errors
                $stmt = $conn->prepare("SELECT 1 FROM personal_info WHERE firstname = ? AND lastname = ? AND birthdate = ? LIMIT 1");
                if (!$stmt) {
                    $message = 'Database error: ' . $conn->error;
                } else {
                    $stmt->bind_param("sss", $firstName, $lastName, $birthdate);
                    $stmt->execute();
                    $stmt->store_result();
                    if ($stmt->num_rows > 0) {
                        $stmt->close();
                        $message = 'An account with the same name and birthdate already exists.';
                    } else {
                        $stmt->close();
                    }
                }
            }
        }

        // only proceed if no duplicate message
        if ($message === '') {
            $passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);

            // Use transaction to keep inserts consistent
            $conn->begin_transaction();
            try {
                // personal_info
                $stmt = $conn->prepare("INSERT INTO personal_info (firstname, middlename, lastname, birthdate, sex) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $firstName, $middleName, $lastName, $birthdate, $sex);
                $stmt->execute();
                $stmt->close();

                // admission_info
                $stmt = $conn->prepare("INSERT INTO admission_info (program_to_enroll, educational_status) VALUES (?, ?)");
                $stmt->bind_param("ss", $programToEnroll, $educationalStatus);
                $stmt->execute();
                $stmt->close();

                // contact_info
                $stmt = $conn->prepare("INSERT INTO contact_info (current_address, permanent_address, mobile_number, landline_number) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $currentAddress, $permanentAddress, $mobileNumber, $landlineNumber);
                $stmt->execute();
                $stmt->close();

                // account_info
                $stmt = $conn->prepare("INSERT INTO account_info (email, password) VALUES (?, ?)");
                $stmt->bind_param("ss", $email, $passwordHash);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $message = 'Registration successful.';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Student Admission</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <style>
        /* minimal styling for clarity */
        .parent-container { max-width:600px; margin:30px auto; font-family:Arial,Helvetica,sans-serif; }
        label { font-weight:600; display:block; margin-top:12px; }
        input, select { width:100%; padding:8px; margin:6px 0; box-sizing:border-box; }
        .row { display:flex; gap:8px; }
        .row input { flex:1; }
        .btn { padding:10px 16px; background:#2b7cff; color:white; border:none; border-radius:4px; cursor:pointer; }
        .msg { margin:12px 0; color:green; }
        .err { margin:12px 0; color:crimson; }
    </style>
</head>
<body>
<div class="parent-container">
    <h2>Student Registration</h2>

    <?php if ($message): ?>
        <div class="<?= (strpos($message, 'successful') !== false) ? 'msg' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <label>Personal Information</label>
        <div class="row">
            <input name="firstName" placeholder="First Name" required>
            <input name="middleName" placeholder="Middle Name">
            <input name="lastName" placeholder="Last Name" required>
        </div>
        <div class="row">
            <input name="birthdate" type="date" placeholder="Birthdate" required>
            <select name="sex" required>
                <option value="">Sex</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>

        <label>Admission</label>
        <input name="programToEnroll" placeholder="Program to Enroll" required>
        <input name="educationalStatus" placeholder="Educational Status (New student, Transferee)" required>

        <label>Contact Information</label>
        <input name="currentAddress" placeholder="Current Address" required>
        <input name="permanentAddress" placeholder="Permanent Address">
        <div class="row">
            <input name="mobileNumber" placeholder="Mobile Number" required>
            <input name="landlineNumber" placeholder="Landline Number">
        </div>

        <label>Account</label>
        <input name="email" type="email" placeholder="Email" required>
        <div class="row">
            <input name="initialPassword" type="password" placeholder="Password" required>
            <input name="confirmPassword" type="password" placeholder="Confirm Password" required>
        </div>

        <!-- register button connected to conn.php via this script which includes conn.php -->
        <button type="submit" class="btn">Register</button>
    </form>
</div>
</body>
</html>