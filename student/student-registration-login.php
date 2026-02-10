<?php
// Security: Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../backend/includes/conn.php';

$login_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'login') {
    $student_num = trim($_POST['student_number'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (empty($student_num) || empty($password)) {
        $login_error = "Please enter both student number and password.";
    } else {
        // Use prepared statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT * FROM student_list WHERE student_number = ? LIMIT 1");
        $stmt->bind_param("s", $student_num);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password'])) {
                // Start session and set user data
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['student_id'] = $row['id'];
                $_SESSION['student_number'] = $row['student_number'];
                $_SESSION['logged_in'] = true;
                
                header("Location: dashboard/landing.php");
                exit;
            } else {
                $login_error = "Invalid password!";
            }
        } else {
            $login_error = "Invalid Student Number!";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Amore College Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:400,700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../assets/css/student-login.css">
  <style>
    /* Small helper so both panels sit well. Keep minimal - your original styles remain in the css file. */
    .auth-tabs { display:flex; gap:8px; margin:20px; }
    .auth-tab { padding:8px 14px; cursor:pointer; border:1px solid #ccc; border-radius:6px; }
    .auth-tab.active { background:#222; color:#fff; }
    .auth-panel { display:none; }
    .auth-panel.active { display:block; }
  </style>
</head>
<body>
  <!-- Top-level simple selector for Login / Register -->
  <div style="max-width:1100px;margin:16px auto;">
    <div class="auth-tabs" role="tablist">
      <div class="auth-tab active" data-target="loginPanel" role="tab">LOG IN</div>
      <div class="auth-tab" data-target="registerPanel" role="tab">REGISTER</div>
    </div>

    <!-- LOGIN PANEL (adapted from student-login.php) -->
    <div id="loginPanel" class="auth-panel active">
      <div class="background-blur">
        <img src="../../assets/images/background.jpg" alt="Background Image" class="background-image">
      </div>
      <div class="overlay">
        <a href="landing.html" class="back-to-home" title="Back to Home">
          <span class="home-icon"> <img src="../../assets/images/home_black.png" alt="Home Icon"></span>
          <span class="home-text">Home</span>
        </a>
        <div class="header">
          <img src="../../assets/images/logo.png" alt="Amore Logo" class="logo">
          <div class="header-text">
            <span class="amore">Colegio de Amore</span>
            <span class="student-portal">STUDENT PORTAL</span>
          </div>
        </div>
        <div class="subtitle-bar">
          Stay updated with your grades, class schedule, and more on the Amore Student Portal
        </div>
        <div class="login-box">
          <div class="login-tabs">
            <button id="loginTab" class="active">LOG IN</button>
            <button id="resetTab" class="inactive">RESET PASSWORD</button>
          </div>
          <div class="login-content">
            <h2>LOGIN YOUR ACCOUNT</h2>
            <div class="login-desc">Excellence • Love • Virtue</div>
            <!-- FORM now posts to this file and includes names expected by PHP -->
            <form id="loginForm" method="post" autocomplete="on">
              <input id="studentNumber" name="student_number" type="text" placeholder="Student Number" required>
              <div class="password-wrapper">
                <input id="passwordInput" name="password" type="password" placeholder="Password" required>
                <span id="togglePassword" class="eye" title="Show Password"></span>
              </div>
              <input type="hidden" name="action" value="login">
              <button class="login-btn" type="submit">LOG IN</button>
            </form>
            <?php if (!empty($login_error)): ?>
              <div style="color:#c00;margin-top:10px;"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
          </div>
          <div id="resetContent" class="reset-content" style="display:none;">
            <h2>RESET YOUR PASSWORD</h2>
            <div class="reset-desc">Enter your <b>Student Number</b></div>
            <form id="resetForm" autocomplete="on">
              <input type="text" placeholder="Student Number" required>
              <button class="reset-btn" type="submit">REQUEST</button>
            </form>
          </div>
        </div>
        <div class="copyright-bottom-right">
          &copy; 2025 Colegio de Amore. All rights reserved.
        </div>
      </div>
    </div>

    <!-- REGISTER PANEL (student-registration.html content inserted) -->
    <div id="registerPanel" class="auth-panel" aria-hidden="true">
      <div>
        <div class="form-group" id="formBox">
            <div>
                <!-- Step 1 -->
                <div class="form-step" id="step1Form">
                    <!-- Step 1 Header -->
                    <div class="form-step-header">
                        <span class="form-title">Admission Form</span>
                        <span class="form-step-no">Step 1</span>
                    </div>
                    <hr>
                    <div class="form-section-title">Personal Information</div>
                    <div class="form-section-desc">
                        Kindly enter the correct details below
                        <span class="required-field">* required field</span>
                    </div>
                    <!-- Name -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="firstName">First Name<span class="required-field">*</span></label>
                            <input type="text" id="firstName" name="firstName" autocomplete="given-name" required>
                        </div>
                        <div class="form-field half">
                            <label for="suffix"><span class="required-field">*</span>Suffix</label>
                            <select id="suffix" name="suffix">
                                <option value="N/A">N/A</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="I">I</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                                <option value="VI">VI</option>
                                <option value="VII">VII</option>
                                <option value="VIII">VIII</option>
                                <option value="IX">IX</option>
                                <option value="X">X</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="middleName">Middle Name<span class="required-field">*</span></label>
                            <input type="text" id="middleName" name="middleName" autocomplete="additional-name">
                            <div class="middle-name-checkbox">
                                <input type="checkbox" id="noMiddleName" name="noMiddleName">
                                <label for="noMiddleName" class="checkbox-label">I have no middle name</label>
                            </div>
                        </div>
                        <div class="form-field half">
                            <label for="lastName">Last Name<span class="required-field">*</span></label>
                            <input type="text" id="lastName" name="lastName" autocomplete="family-name" required>
                        </div>
                    </div>
                    <!-- Birthday -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="dob">Birthday<span class="required-field">*</span></label>
                            <input type="date" id="dob" name="dob" autocomplete="bday" required placeholder="mm/dd/yyyy">
                        </div>
                        <div class="form-field half nationality-box">
                            <label for="nationality">Nationality<span class="required-field">*</span></label>
                            <div class="nationality-dropdown-wrap">
                                <select id="nationality" name="nationality" required></select>
                            </div>
                        </div>
                    </div>
                    <!-- Phone Number and Gender -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="phoneNumber" class="phone-label">Phone Number<span class="required-field">*</span></label>
                            <div class="phone-group">
                                <div class="phone-country-dropdown-wrap">
                                    <img class="phone-country-flag" id="phoneCountryFlag" src="https://flagcdn.com/24x18/ph.png" alt="Flag">
                                    <div id="customPhoneSelect" class="custom-phone-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                                        <span id="selectedAbbr" class="selected-abbr">PH</span>
                                        <span class="custom-arrow">&#9662;</span>
                                        <ul class="country-list" id="countryList" tabindex="-1" hidden></ul>
                                    </div>
                                </div>
                                <span class="phone-country-divider"></span>
                                <span class="phone-country-dial" id="phoneCountryDial">+63</span>
                                <input type="tel" id="phoneNumber" name="phoneNumber" autocomplete="tel" maxlength="15" required>
                            </div>
                        </div>
                        <div class="form-field half">
                            <label>Gender<span class="required-field">*</span></label>
                            <div class="gender-box-group">
                                <label class="gender-box">
                                    <input type="radio" name="gender" value="Male" required>
                                    <span class="gender-icon"><img src="../../assets/images/male-gender.png" alt="male-gender"></span>
                                    <span class="gender-label">Male</span>
                                </label>
                                <label class="gender-box">
                                    <input type="radio" name="gender" value="Female" required>
                                    <span class="gender-icon"><img src="../../assets/images/female-gender.png" alt="female-gender"></span>
                                    <span class="gender-label">Female</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- Program to Enroll -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="program">Program to Enroll<span class="required-field">*</span></label>
                            <select id="program" name="program" class="program-select" title="Select Program to Enroll">
                                <option value="" disabled selected></option>
                                <option value="BS Criminology">Bachelor of Science in Criminology</option>
                                <option value="BS Hospitality Management">Bachelor of Science in Hospitality Management</option>
                                <option value="BS Computer Science">Bachelor of Science in Computer Science</option>
                            </select>
                        </div>
                    </div>
                    <!-- Educational Status -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="educationalStatus">Educational Status<span class="required-field">*</span></label>
                            <select id="educationalStatus" name="educationalStatus" required>
                                <option value=""></option>
                                <option value="New Student">New Student</option>
                                <option value="Transferee">Transferee</option>
                            </select>
                        </div>
                    </div>
                    <!-- Email Address -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="email">Email Address<span class="required-field">*</span></label>
                            <input type="email" id="email" name="email" autocomplete="email" required>
                        </div>
                    </div>
                    <!-- Password and Confirm Password -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="password">Password<span class="required-field">*</span></label>
                            <input type="password" id="password" name="password" autocomplete="new-password" required>
                        </div>
                        <div class="form-field half">
                            <label for="confirmPassword">Confirm Password<span class="required-field">*</span></label>
                            <input type="password" id="confirmPassword" name="confirmPassword" autocomplete="new-password" required>
                        </div>
                    </div>
                    <!-- Register Button -->
                    <div class="form-actions">
                        <button type="button" id="nextBtn">Register</button>
                    </div>
                    <div class="form-footer">
                        <p>Already have an Account? <a href="#" id="gotoLoginFromRegister">Login here</a></p>
                    </div>

                </div>

                <!-- Step 2: Address & Guardian Information -->
                <div class="form-step" id="step2Form" style="display:none;">
                    <div class="form-step-header">
                        <div class="back-step-container">
                            <button type="button" class="back-step-btn" id="backToStep1">
                                <img src="../../assets/images/back-admission.png" alt="Back" class="back-icon">
                                <p>Go Back</p>
                            </button>
                        </div>
                        <!-- Step 2 Header Right -->
                        <div class="step2-header-right">
                            <span class="form-step-no">Step 2</span>
                            <span class="form-footer small">Already have an Account? <a href="#" id="gotoLoginFromRegister2">Login here</a></span>

                            <span class="required-field-step2">* required field</span>
                        </div>
                    </div>
                    <hr>
                    <!-- Current Address -->
                    <div class="form-section-title">Current Address</div>
                    <div class="form-section-desc">Residence address where you currently live</div>
                    <!-- Country -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="country">Country<span class="required-field">*</span></label>
                            <input type="text" id="country" name="country" autocomplete="country" required>
                        </div>
                    <!-- State/Province -->
                    <div class="form-field half">
                        <label for="cityProvince">State/Province<span class="required-field">*</span></label>
                        <input type="text" id="cityProvince" name="cityProvince" autocomplete="address-level1" required>
                    </div>
                    </div>
                    <!-- Municipality/City -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="municipality">City/Municipality<span class="required-field">*</span></label>
                            <input type="text" id="municipality" name="municipality" autocomplete="address-level2" required>
                        </div>
                    </div>
                    <!-- Baranggay -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="baranggay">Baranggay<span class="required-field">*</span></label>
                            <input type="text" id="baranggay" name="baranggay" autocomplete="address-level3" required>
                        </div>
                    </div>
                    <!-- House No./Building/Street Name -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="address">House No./Building/Street Name<span class="required-field">*</span></label>
                            <input type="text" id="address" name="address" autocomplete="street-address" required>
                        </div>
                    </div>
                    <!-- Address Line 2 -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="addressLine2">Address Line 2 (optional)</label>
                            <input type="text" id="addressLine2" name="addressLine2" autocomplete="address-line2">
                        </div>
                    </div>
                    <!-- Postal Code -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="postalCode">Postal Code (optional)</label>
                            <input type="text" id="postalCode" name="postalCode" autocomplete="postal-code" maxlength="4">
                        </div>
                    </div>
                    <!-- Parents Information -->
                    <div class="form-section-title" style="margin-top:30px;">Parents Information</div>
                    <div class="form-section-desc">
                        Information about your parents or guardian
                    </div>
                    <!-- Mother's Maiden Name -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="motherName">Mother's Maiden Name<span class="required-field">*</span></label>
                            <input type="text" id="motherName" name="motherName" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="motherPhoneNumber" class="phone-label">Contact Number<span class="required-field">*</span></label>
                            <div class="phone-group">
                                <div class="phone-country-dropdown-wrap">
                                    <img class="phone-country-flag" id="motherPhoneCountryFlag" src="https://flagcdn.com/24x18/ph.png" alt="Flag">
                                    <div id="motherCustomPhoneSelect" class="custom-phone-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                                        <span id="motherSelectedAbbr" class="selected-abbr">PH</span>
                                        <span class="custom-arrow">&#9662;</span>
                                        <ul class="country-list" id="motherCountryList" tabindex="-1" hidden></ul>
                                    </div>
                                </div>
                                <span class="phone-country-divider"></span>
                                <span class="phone-country-dial" id="motherPhoneCountryDial">+63</span>
                                <input type="tel" id="motherPhoneNumber" name="motherPhoneNumber" autocomplete="tel" maxlength="15" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="motherOccupation">Occupation<span class="required-field">*</span></label>
                            <input type="text" id="motherOccupation" name="motherOccupation" autocomplete="organization-title" required>
                        </div>
                    </div>
                    <!-- Father's Full Name -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="fatherName">Father's Full Name<span class="required-field">*</span></label>
                            <input type="text" id="fatherName" name="fatherName" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="fatherPhoneNumber" class="phone-label">Contact Number<span class="required-field">*</span></label>
                            <div class="phone-group">
                                <div class="phone-country-dropdown-wrap">
                                    <img class="phone-country-flag" id="fatherPhoneCountryFlag" src="https://flagcdn.com/24x18/ph.png" alt="Flag">
                                    <div id="fatherCustomPhoneSelect" class="custom-phone-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                                        <span id="fatherSelectedAbbr" class="selected-abbr">PH</span>
                                        <span class="custom-arrow">&#9662;</span>
                                        <ul class="country-list" id="fatherCountryList" tabindex="-1" hidden></ul>
                                    </div>
                                </div>
                                <span class="phone-country-divider"></span>
                                <span class="phone-country-dial" id="fatherPhoneCountryDial">+63</span>
                                <input type="tel" id="fatherPhoneNumber" name="fatherPhoneNumber" autocomplete="tel" maxlength="15" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="fatherOccupation">Occupation<span class="required-field">*</span></label>
                            <input type="text" id="fatherOccupation" name="fatherOccupation" autocomplete="organization-title" required>
                        </div>
                    </div>
                    <!-- In Case of Emergency -->
                    <div class="form-section-title" style="margin-top:30px;">In Case of Emergency</div>
                    <div class="form-section-desc">
                        Emergency contact information
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="emergencyName">Parent's / Guardian's Name<span class="required-field">*</span></label>
                            <input type="text" id="emergencyName" name="emergencyName" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="emergencyPhoneNumber" class="phone-label">Contact Number<span class="required-field">*</span></label>
                            <div class="phone-group">
                                <div class="phone-country-dropdown-wrap">
                                    <img class="phone-country-flag" id="emergencyPhoneCountryFlag" src="https://flagcdn.com/24x18/ph.png" alt="Flag">
                                    <div id="emergencyCustomPhoneSelect" class="custom-phone-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                                        <span id="emergencySelectedAbbr" class="selected-abbr">PH</span>
                                        <span class="custom-arrow">&#9662;</span>
                                        <ul class="country-list" id="emergencyCountryList" tabindex="-1" hidden></ul>
                                    </div>
                                </div>
                                <span class="phone-country-divider"></span>
                                <span class="phone-country-dial" id="emergencyPhoneCountryDial">+63</span>
                                <input type="tel" id="emergencyPhoneNumber" name="emergencyPhoneNumber" autocomplete="tel-national" maxlength="15" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="emergencyAddress">Complete Address<span class="required-field">*</span></label>
                            <input type="text" id="emergencyAddress" name="emergencyAddress" autocomplete="street-address" required>
                        </div>
                    </div>
                    <!-- Next Button -->
                    <div class="form-actions">
                        <button type="button" id="nextToStep3">Next</button>
                    </div>
                </div>
                
                <!-- Step 3: Email and Password -->
                <div class="form-step" id="step3Form" style="display:none;">
                    <div class="form-step-header">
                        <div class="back-step-container">
                        </div>
                    </div>
                    <hr>
                    <div class="form-section-title">Email and Password</div>
                    <div class="form-section-desc">Please input your correct credentials below</div>

                    <div class="div-email-password">   
                        <!-- Keep your registration finishing code here (e.g. registration submit button, etc.) -->
                    </div>
                </div>
            </div>
        </div>
      </div>
    </div>

  </div>

  <script>
    // Minimal tab switching for the combined page
    document.querySelectorAll('.auth-tab').forEach(tab=>{
      tab.addEventListener('click', ()=>{
        document.querySelectorAll('.auth-tab').forEach(t=>t.classList.remove('active'));
        tab.classList.add('active');
        const target = tab.getAttribute('data-target');
        document.querySelectorAll('.auth-panel').forEach(p=>p.classList.remove('active'));
        document.getElementById(target).classList.add('active');
      });
    });

    // small helper to jump back to login from registration links
    document.querySelectorAll('#gotoLoginFromRegister, #gotoLoginFromRegister2').forEach(el=>{
      el && el.addEventListener('click', (e)=>{
        e.preventDefault();
        document.querySelector('.auth-tab[data-target="loginPanel"]').click();
        window.scrollTo({top:0,behavior:'smooth'});
      });
    });
  </script>

  <script src="../../assets/js/student-login.js"></script>
</body>
</html>