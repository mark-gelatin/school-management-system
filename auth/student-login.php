
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // auth/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/auth
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
require_once getAbsolutePath('backend/student-management/includes/conn.php');
require_once getAbsolutePath('backend/student-management/includes/functions.php');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['student_number'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStudentIdColumn = in_array('student_id_number', $columns, true);

        if ($hasStudentIdColumn) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'student' AND (username = ? OR student_id_number = ? OR email = ?)");
            $stmt->execute([$identifier, $identifier, $identifier]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'student' AND (username = ? OR email = ?)");
            $stmt->execute([$identifier, $identifier]);
        }

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            
            // Set flag to show welcome banner on dashboard
            $_SESSION['show_welcome'] = true;
            
            // Redirect to student dashboard
            redirectTo('student/student-dashboard.php');
        } else {
            $error_message = "Invalid Student Number/Email or Password!";
        }
    } catch (Exception $e) {
        $error_message = "Login error. Please try again.";
    }
}
?> 

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Amore College Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="../../assets/images/favicon.ico">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/student-login.css">

</head>
    <!-- <div class="loading-spinner" id="loadingSpinner" style="display:none;">
        <div class="spinner"></div> -->
<body>
  <div class="login-container">
    <a href="../landing.html" class="back-to-home" title="Back to Home">
      <span class="home-icon"> <img src="../../assets/images/home_black.png" alt="Home Icon"></span>
      <span class="home-text">Home</span>
    </a>
    
    <!-- Left Panel - Visual -->
    <div class="left-panel">
      <div class="left-panel-content">
        <div class="logo-section">
          <img src="../../assets/images/logo.png" alt="Amore Logo" class="logo">
          <div class="brand-text">
            <h1 class="brand-name">Colegio de Amore</h1>
            <p class="brand-tagline">STUDENT PORTAL</p>
          </div>
        </div>
        <div class="visual-content">
          <div class="motto">Excellence • Love • Virtue</div>
        </div>
      </div>
    </div>
    
    <!-- Right Panel - Login Form -->
    <div class="right-panel">
      <div class="form-container">
        <?php if (isset($_POST['student_number']) || isset($_POST['password'])): ?>
          <div class="error-message">
            <?php 
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
                if (isset($error_message)) {
                    echo htmlspecialchars($error_message);
                }
            }
            ?>
          </div>
        <?php endif; ?>
        
        <div class="login-content">
          <h2>Welcome Student</h2>
          <h3>Login here</h3>
          <form id="loginForm" method="POST" action="student-login.php" autocomplete="on">
            <div class="form-group">
              <input id="studentNumber" name="student_number" type="text" placeholder="Student Number or Email" required>
            </div>
            <div class="form-group password-wrapper">
              <input id="passwordInput" name="password" type="password" placeholder="Password" required>
              <span id="togglePassword" class="eye" title="Show Password"></span>
            </div>
            <button class="login-btn" type="submit">LOG IN</button>
            <div class="reset-password-link">
              <a href="#" id="resetPasswordLink">Can't access your account?</a>
            </div>
          </form>
        </div>
        
        <div id="resetContent" class="reset-content" style="display:none;">
          <h2>Reset Your Password</h2>
          <p class="reset-desc">Enter your <b>Student Number or Email</b></p>
          <form id="resetForm" method="POST" action="forgot-password.html" autocomplete="on">
            <div class="form-group">
              <input name="student_number" type="text" placeholder="Student Number or Email" required>
            </div>
            <button class="reset-btn" type="submit">REQUEST</button>
            <div class="back-to-login-link">
              <a href="#" id="backToLoginLink">Back to Login</a>
            </div>
          </form>
        </div>
        
        <div class="copyright">
          &copy; 2025 Colegio de Amore. All rights reserved.
        </div>
      </div>
    </div>
  </div>
  <script src="../../assets/js/student-login.js"></script>  
</body>
</html>
<!--
✅ Connecting login.html to portal.html 
✅ Reset button to redirection to reset password page 
✅ Loader Screen
✅ Home Icon changing color on hover
✅ Colegio Logo and Name will be big on responsive
 -->