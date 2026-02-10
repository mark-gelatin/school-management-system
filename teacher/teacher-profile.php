<?php
// Teacher Profile Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // teacher/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/teacher
    $projectRoot = dirname($currentDir); // /www/wwwroot/72.62.65.224
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    
    // Use realpath to resolve any symbolic links and get absolute path
    $realPathsFile = realpath($pathsFile);
    if ($realPathsFile && file_exists($realPathsFile)) {
        require_once $realPathsFile;
    } else {
        // Fallback to VPS path (absolute path)
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('config/database.php');

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    redirectTo('auth/staff-login.php');
}

$teacherId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
}

// Get teacher information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        $message = 'Teacher record not found.';
        $message_type = 'error';
    }
} catch (PDOException $e) {
    $message = 'Error retrieving teacher information: ' . $e->getMessage();
    $message_type = 'error';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        // Get editable fields from POST
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Validate email
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email address.';
            $message_type = 'error';
        } else {
            // Update teacher information
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET phone_number = ?, email = ?
                WHERE id = ? AND role = 'teacher'
            ");
            $updateStmt->execute([$phoneNumber, $email, $teacherId]);
            
            // Refresh teacher data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
            $stmt->execute([$teacherId]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $message = 'Profile updated successfully.';
            $message_type = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Error updating profile: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Verify current password
        if (password_verify($currentPassword, $teacher['password'])) {
            if ($newPassword === $confirmPassword) {
                if (strlen($newPassword) >= 8) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $passwordStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'teacher'");
                    $passwordStmt->execute([$hashedPassword, $teacherId]);
                    $message = 'Password changed successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'New password must be at least 8 characters long.';
                    $message_type = 'error';
                }
            } else {
                $message = 'New password and confirmation do not match.';
                $message_type = 'error';
            }
        } else {
            $message = 'Current password is incorrect.';
            $message_type = 'error';
        }
    } catch (PDOException $e) {
        $message = 'Error changing password: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    redirectTo('auth/staff-login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="../../assets/images/favicon.ico">
    <?php include __DIR__ . '/../includes/teacher-sidebar-styles.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
        }
        
        /* Top Header - Match Dashboard Style */
        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: relative;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            white-space: nowrap;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto;
            flex-shrink: 0;
        }
        
        /* Profile Container */
        .profile-container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* Profile Header Card */
        .profile-header-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .profile-picture-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5rem;
            color: white;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(161, 28, 39, 0.3);
            font-weight: 700;
        }
        
        .profile-header-card h1 {
            margin: 0 0 10px 0;
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        
        .profile-header-card p {
            margin: 0;
            color: #666;
            font-size: 1rem;
        }
        
        /* Profile Section Cards - Match Dashboard Card Style */
        .profile-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .profile-section h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #a11c27;
        }
        
        /* Form Styles - Match Dashboard */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .form-group input[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
            color: #666;
        }
        
        /* Button Styles - Match Dashboard */
        .btn {
            background: #a11c27;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.2s;
            display: inline-block;
        }
        
        .btn:hover {
            background: #b31310;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        /* Message Styles - Match Dashboard */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
                padding-top: 70px;
            }
            
            .top-header {
                padding: 15px 20px;
                margin-bottom: 20px;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .profile-container {
                max-width: 100%;
            }
            
            .profile-header-card {
                padding: 20px;
            }
            
            .profile-picture-large {
                width: 100px;
                height: 100px;
                font-size: 2rem;
            }
            
            .profile-section {
                padding: 20px;
            }
            
            .profile-section h2 {
                font-size: 1.2rem;
            }
            
            .btn {
                width: 100%;
                padding: 12px 20px;
            }
        }
        
        @media (max-width: 480px) {
            .top-header {
                padding: 12px 15px;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .profile-header-card {
                padding: 15px;
            }
            
            .profile-picture-large {
                width: 80px;
                height: 80px;
                font-size: 1.5rem;
            }
            
            .profile-section {
                padding: 15px;
            }
            
            .form-group input {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'profile';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <div class="main-content">
        <!-- Top Header - Match Dashboard Style -->
        <div class="top-header">
            <h1 class="page-title">Teacher Profile</h1>
            <div class="header-actions">
                <!-- Profile actions can be added here if needed -->
            </div>
        </div>
        
        <div class="profile-container">
            <!-- Profile Header Card -->
            <div class="profile-header-card">
                <div class="profile-picture-large">
                    <?= strtoupper(substr($teacher['first_name'] ?? 'T', 0, 1) . substr($teacher['last_name'] ?? 'E', 0, 1)) ?>
                </div>
                <h1><?= htmlspecialchars(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')) ?></h1>
                <p>Teacher Account</p>
            </div>

            <?php if ($message): ?>
                <div class="message <?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Personal Information -->
            <div class="profile-section">
                <h2>Personal Information</h2>
                <form method="POST" action="teacher-profile.php">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" value="<?= htmlspecialchars($teacher['first_name'] ?? '') ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" value="<?= htmlspecialchars($teacher['last_name'] ?? '') ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($teacher['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone_number" value="<?= htmlspecialchars($teacher['phone_number'] ?? '') ?>">
                    </div>
                    <button type="submit" name="update_profile" class="btn">Update Profile</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="profile-section">
                <h2>Change Password</h2>
                <form method="POST" action="teacher-profile.php">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" name="change_password" class="btn">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
</body>
</html>

