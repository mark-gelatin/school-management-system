<?php
// Updated for Linux/VPS deployment - paths and redirects updated for Linux compatibility
// Configure session settings before starting
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', 0);
ini_set('session.gc_maxlifetime', 28800); // 8 hours
ini_set('session.cookie_path', '/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first
if (!defined('BASE_PATH')) {
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/auth
    $projectRoot = dirname($currentDir); // /www/wwwroot/72.62.65.224
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    $realPathsFile = realpath($pathsFile);
    if ($realPathsFile && file_exists($realPathsFile)) {
        require_once $realPathsFile;
    } else {
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('backend/student-management/includes/conn.php');
require_once getAbsolutePath('backend/student-management/includes/functions.php');

// Optional redirect target after login
$redirectTarget = $_GET['redirect'] ?? '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['staffid']);
    $password = $_POST['password'];

    try {
        // Check for both teacher and admin roles - allow login with username or email
        // For admin: allow login regardless of status (admins should always be able to log in)
        // For teacher: check that status is 'active'
        $stmt = $pdo->prepare("
            SELECT * FROM users 
            WHERE (username = ? OR email = ?) 
            AND (role = 'teacher' OR role = 'admin') 
            AND (role = 'admin' OR status = 'active')
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            
            // Set flag to show welcome banner on dashboard (for teachers)
            if ($user['role'] === 'teacher') {
                $_SESSION['show_welcome'] = true;
            }
            
            // Redirect to appropriate dashboard based on role
            if ($user['role'] === 'admin') {
                $destination = 'admin/student-management/admin.php';
                if ($redirectTarget === 'applications') {
                    $destination .= '?tab=applications';
                }
                redirectTo($destination);
            } else {
                redirectTo('teacher/teacher-dashboard.php');
            }
        } else {
            $error_message = "Invalid Staff ID/Email or Password!";
        }
    } catch (Exception $e) {
        $error_message = "Login error. Please try again.";
        // Log error for debugging (remove in production or use proper logging)
        error_log("Staff login error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Colegio de Amore Staff Portal</title>
  <link rel="icon" type="image/x-icon" href="../../assets/images/favicon.ico">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../assets/css/staff-login.css">
</head>
<body>
  <div class="login_page">
    <a href="../landing.html" class="back-to-home" title="Back to Home">
      <span class="home-icon"><img src="../../assets/images/home_black.png" alt="Home Icon"></span>
      <span class="home-text">Home</span>
    </a>
    <div class="figure_container" id="figureContainer">
      <img src="../../assets/images/logo.png" alt="Login Figure" class="figure_logo_bg">
      <div class="figure_text">
        <span class="text_1">EXCELLENCE</span>
        <span class="text_2">LOVE</span>
        <span class="text_3">VIRTUE</span>
      </div>
    </div>
    <div class="login_container login_dots_bg">
      <img src="../../assets/images/logo.png" alt="College Logo" class="login-logo">
      <div class="login-heading">Colegio de Amore Staff Portal</div>
      <div class="login-subheading">Sign in to continue.</div>
      <?php if (isset($error_message)): ?>
        <div class="form-warning" style="display: block; color: #DE4447; margin-bottom: 15px; text-align: center;">
          <?php echo htmlspecialchars($error_message); ?>
        </div>
      <?php endif; ?>
      <form action="" method="POST" class="login_form" id="loginForm" autocomplete="off" novalidate>
        <div class="form-warning" id="formWarning"></div>
        <label for="staffid" class="login_label">Staff ID</label>
        <div class="login_input_group">
          <span class="login_input_icon">
            <svg viewBox="0 0 24 24" fill="none" width="18" height="18"><path d="M12 12c2.7 0 4.5-1.8 4.5-4.5S14.7 3 12 3 7.5 4.8 7.5 7.5 9.3 12 12 12zm0 2.25c-2.7 0-8.25 1.35-8.25 4.05V21h16.5v-2.7c0-2.7-5.55-4.05-8.25-4.05z" fill="#222"/></svg>
          </span>
          <input type="text" id="staffid" name="staffid" class="login_input login_username" autocomplete="username" required>
        </div>
        <label for="password" class="login_label">Password</label>
        <div class="login_input_group" style="position:relative;">
          <span class="login_input_icon">
            <svg viewBox="0 0 24 24" fill="none" width="18" height="18"><path d="M17 10V7a5 5 0 0 0-10 0v3a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2zm-8-3a3 3 0 0 1 6 0v3H9V7zm10 12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v7z" fill="#222"/></svg>
          </span>
          <input type="password" id="password" name="password" class="login_input login_password" autocomplete="current-password" required>
           <span class="password-toggle" id="togglePassword" tabindex="0" aria-label="Show/Hide password" style="display:none;">
            <svg id="eyeOpen" viewBox="0 0 24 24" fill="none">
              <path d="M12 5C6.5 5 2.7 9.11 2 12c.7 2.89 4.5 7 10 7s9.3-4.11 10-7c-.7-2.89-4.5-7-10-7zm0 12c-4.41 0-8.02-3.17-8.9-5C3.98 10.17 7.59 7 12 7s8.02 3.17 8.9 5c-.88 1.83-4.49 5-8.9 5zm0-8a3 3 0 100 6 3 3 0 000-6zm0 4.5A1.5 1.5 0 1112 10a1.5 1.5 0 010 3z" fill="#888"/>
            </svg>
            <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" style="display:none;">
              <path d="M12 7c-2.49 0-4.5 2.01-4.5 4.5 0 .66.14 1.28.39 1.85l-2.95 2.95a1 1 0 101.41 1.41l2.95-2.95c.57.25 1.19.39 1.85.39 2.49 0 4.5-2.01 4.5-4.5 0-.66-.14-1.28-.39-1.85l2.95-2.95a1 1 0 10-1.41-1.41l-2.95 2.95A4.48 4.48 0 0012 7zm0 7c-1.93 0-3.5-1.57-3.5-3.5S10.07 7 12 7s3.5 1.57 3.5 3.5S13.93 14 12 14z" fill="#888"/>
            </svg>
          </span>
        </div>
        <a href="../forgot-password.html" class="forgot_password">Forgot Password?</a>
        <button type="submit" class="login_button">Login</button>
      </form>
    </div>
  </div>
  <script>
    // Responsive Figure Container Show/Hide for Tablet Only
    function handleFigureContainer() {
      const fc = document.getElementById('figureContainer');
      if (window.innerWidth < 700) {
        fc.style.display = 'none';
      } else if (window.innerWidth < 1100) {
        fc.style.display = 'flex';
        fc.style.width = "100%";
        fc.style.height = "150px";
        fc.style.borderRadius = "0 0 30px 30px";
        fc.style.flexDirection = "row";
        fc.style.justifyContent = "center";
        fc.style.alignItems = "center";
        fc.style.minWidth = "0";
      } else {
        fc.style.display = 'flex';
        fc.style.width = "";
        fc.style.height = "";
        fc.style.borderRadius = "0 50px 50px 0";
        fc.style.flexDirection = "column";
        fc.style.justifyContent = "center";
        fc.style.alignItems = "flex-end";
        fc.style.minWidth = "330px";
      }
    }
    window.addEventListener('resize', handleFigureContainer);
    window.addEventListener('DOMContentLoaded', handleFigureContainer);

    // Show/hide password eye icon dynamic logic
    document.addEventListener('DOMContentLoaded', function () {
      const passwordInput = document.getElementById('password');
      const toggleBtn = document.getElementById('togglePassword');
      const eyeOpen = document.getElementById('eyeOpen');
      const eyeClosed = document.getElementById('eyeClosed');
      let passwordHasInput = false, toggled = false;

      function showEyeIfNeeded() {
        passwordHasInput = passwordInput.value.length > 0;
        if (passwordHasInput) {
          toggleBtn.style.display = 'flex';
        } else {
          toggleBtn.style.display = 'none';
          passwordInput.type = 'password';
          eyeOpen.style.display = '';
          eyeClosed.style.display = 'none';
          toggled = false;
        }
      }
      passwordInput.addEventListener('input', showEyeIfNeeded);

      toggleBtn.addEventListener('mousedown', function (e) {
        e.preventDefault();
      });

      toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!passwordHasInput) return;
        toggled = !toggled;
        passwordInput.type = toggled ? 'text' : 'password';
        eyeOpen.style.display = toggled ? 'none' : '';
        eyeClosed.style.display = toggled ? '' : 'none';
      });

      // Hide eye if no input and click outside
      document.addEventListener('mousedown', function(e) {
        if (e.target !== passwordInput && e.target !== toggleBtn && !toggleBtn.contains(e.target)) {
          if (!passwordInput.value) {
            toggleBtn.style.display = 'none';
            passwordInput.type = 'password';
            eyeOpen.style.display = '';
            eyeClosed.style.display = 'none';
            toggled = false;
          }
        }
      });

      showEyeIfNeeded();
    });

    // Basic form validation
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('loginForm');
      const warning = document.getElementById('formWarning');
      if (form && warning) {
        form.addEventListener('submit', function (e) {
          warning.style.display = 'none';
          let valid = true;
          const staffid = form.staffid.value.trim();
          const password = form.password.value.trim();
          if (!staffid && !password) {
            warning.textContent = "Please enter your Staff ID and Password.";
            valid = false;
          } else if (!staffid) {
            warning.textContent = "Please enter your Staff ID.";
            valid = false;
          } else if (!password) {
            warning.textContent = "Please enter your Password.";
            valid = false;
          }
          if (!valid) {
            warning.style.display = 'block';
            e.preventDefault();
          }
        });
        form.addEventListener('input', function () {
          warning.style.display = 'none';
        });
      }
    });

    // Login input border color: black, turns #DE4447 on focus
    document.addEventListener('DOMContentLoaded',function(){
      const inputs = document.querySelectorAll('.login_input');
      inputs.forEach(function(inp){
        inp.style.borderColor = '#000';
        inp.addEventListener('focus',function(){
          inp.style.borderColor = '#DE4447';
        });
        inp.addEventListener('blur',function(){
          inp.style.borderColor = '#000';
        });
      });
    });
  </script>
</body>
</html>
