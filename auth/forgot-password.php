<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $studentIdentifier = trim($_POST['student_number'] ?? '');

    if ($studentIdentifier === '') {
        $error = "Please enter your student number or email.";
    } else {
        try {
            $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
            $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
            $hasStudentIdColumn = in_array('student_id_number', $columns, true);

            $query = "SELECT id, email FROM users WHERE role = 'student' AND (username = ? OR email = ?";
            $params = [$studentIdentifier, $studentIdentifier];

            if ($hasStudentIdColumn) {
                $query .= " OR student_id_number = ?";
                $params[] = $studentIdentifier;
            }

            $query .= ") LIMIT 1";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                $message = "Password reset instructions have been sent to your registered email address.";
            } else {
                $error = "Student number or email not found. Please check your information and try again.";
            }
        } catch (PDOException $e) {
            $error = "Unable to process your request right now. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Colegio de Amore</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student-login.css">
</head>
<body>
    <div class="background-blur">
        <img src="../../assets/images/background.jpg" alt="Background Image" class="background-image">
    </div>
    <div class="overlay">
        <a href="student-login.php" class="back-to-home" title="Back to Login">
            <span class="home-icon"><img src="../../assets/images/home_black.png" alt="Home Icon"></span>
            <span class="home-text">Back</span>
        </a>
        
        <div class="header">
            <img src="../../assets/images/logo.png" alt="Amore Logo" class="logo">
            <div class="header-text">
                <span class="amore">Colegio de Amore</span>
                <span class="student-portal">RESET PASSWORD</span>
            </div>
        </div>
        
        <div class="subtitle-bar">
            Enter your student number to receive password reset instructions
        </div>
        
        <div class="login-box">
            <?php if ($message): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="login-content">
                <h2>RESET YOUR PASSWORD</h2>
                <div class="login-desc">Enter your <b>Student Number</b></div>
                <form method="POST" action="">
                    <input name="student_number" type="text" placeholder="Student Number" required>
                    <button class="login-btn" type="submit">SEND RESET LINK</button>
                </form>
                <div class="login-assist">
                    <a href="student-login.php">← Back to Login</a>
                </div>
            </div>
        </div>
        
        <div class="copyright-bottom-right">
            &copy; 2025 Colegio de Amore. All rights reserved.
        </div>
    </div>
</body>
</html>
