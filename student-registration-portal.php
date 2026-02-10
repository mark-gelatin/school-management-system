<?php
// Student Registration Portal - Connected to Enhanced Student Management Database

// Use centralized database configuration
require_once __DIR__ . '/config/database.php';

// Get database connection
try {
    $pdo = getDatabaseConnection();
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/**
 * Generate a unique username based on the email prefix.
 */
function generateUniqueUsername(PDO $pdo, string $baseUsername): string {
    $sanitized = preg_replace('/[^a-zA-Z0-9_.-]/', '', strtolower($baseUsername));
    if (empty($sanitized)) {
        $sanitized = 'user';
    }
    
    $candidate = $sanitized;
    $suffix = 0;
    
    while (true) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $checkStmt->execute([$candidate]);
            if ($checkStmt->rowCount() === 0) {
                return $candidate;
            }
        } catch (PDOException $e) {
            // If the table doesn't exist or another error occurs, break loop and return candidate
            error_log('Username uniqueness check failed: ' . $e->getMessage());
            return $candidate;
        }
        
        $suffix++;
        $candidate = $sanitized . $suffix;
        
        if ($suffix > 9999) {
            return $sanitized . uniqid();
        }
    }
}

/**
 * Insert a user using only columns available in the users table.
 *
 * @throws Exception if required columns are missing or insert fails.
 */
function insertUserDynamic(PDO $pdo, array $data): int {
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$columns) {
        throw new Exception('Unable to read users table structure.');
    }
    
    $fields = [];
    $placeholders = [];
    $values = [];
    
    foreach ($columns as $column) {
        $field = $column['Field'];
        if ($field === 'id') {
            continue;
        }
        
        if (array_key_exists($field, $data)) {
            $fields[] = $field;
            $placeholders[] = '?';
            $values[] = $data[$field];
        } else {
            $isRequired = ($column['Null'] === 'NO' && $column['Default'] === null && stripos($column['Extra'], 'auto_increment') === false);
            if ($isRequired) {
                throw new Exception("Missing required field '{$field}' for users table.");
            }
        }
    }
    
    if (empty($fields)) {
        throw new Exception('No columns available for user insert.');
    }
    
    $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    return (int)$pdo->lastInsertId();
}

$message = '';
$message_type = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get form data
    $firstName = htmlspecialchars(trim($_POST['firstName'] ?? ''));
    $lastName = htmlspecialchars(trim($_POST['lastName'] ?? ''));
    $middleName = htmlspecialchars(trim($_POST['middleName'] ?? ''));
    $suffix = htmlspecialchars(trim($_POST['suffix'] ?? ''));
    $birthday = htmlspecialchars(trim($_POST['dob'] ?? ''));
    $nationality = htmlspecialchars(trim($_POST['nationality'] ?? ''));
    $phoneNumber = htmlspecialchars(trim($_POST['phoneNumber'] ?? ''));
    $gender = htmlspecialchars(trim($_POST['gender'] ?? ''));
    $program = htmlspecialchars(trim($_POST['program'] ?? ''));
    $educationalStatus = htmlspecialchars(trim($_POST['educationalStatus'] ?? ''));
    
    // Address information
    $country = htmlspecialchars(trim($_POST['country'] ?? ''));
    $cityProvince = htmlspecialchars(trim($_POST['cityProvince'] ?? ''));
    $municipality = htmlspecialchars(trim($_POST['municipality'] ?? ''));
    $baranggay = htmlspecialchars(trim($_POST['baranggay'] ?? ''));
    $address = htmlspecialchars(trim($_POST['address'] ?? ''));
    $addressLine2 = htmlspecialchars(trim($_POST['addressLine2'] ?? ''));
    $postalCode = htmlspecialchars(trim($_POST['postalCode'] ?? ''));
    
    // Parents information
    $motherName = htmlspecialchars(trim($_POST['motherName'] ?? ''));
    $motherPhoneNumber = htmlspecialchars(trim($_POST['motherPhoneNumber'] ?? ''));
    $motherOccupation = htmlspecialchars(trim($_POST['motherOccupation'] ?? ''));
    $fatherName = htmlspecialchars(trim($_POST['fatherName'] ?? ''));
    $fatherPhoneNumber = htmlspecialchars(trim($_POST['fatherPhoneNumber'] ?? ''));
    $fatherOccupation = htmlspecialchars(trim($_POST['fatherOccupation'] ?? ''));
    
    // Emergency contact
    $emergencyName = htmlspecialchars(trim($_POST['emergencyName'] ?? ''));
    $emergencyPhoneNumber = htmlspecialchars(trim($_POST['emergencyPhoneNumber'] ?? ''));
    $emergencyAddress = htmlspecialchars(trim($_POST['emergencyAddress'] ?? ''));
    
    // Email and password
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $confirmEmail = filter_var(trim($_POST['confirmEmail'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    
    // Validation
    if (empty($firstName) || empty($lastName) || !$email || empty($password)) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } elseif ($email !== $confirmEmail) {
        $message = 'Email addresses do not match.';
        $message_type = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $message_type = 'error';
    } elseif (!empty($motherName) && !preg_match('/^[a-zA-Z\s\'.-]+$/', $motherName)) {
        // Check if mother's name contains only valid name characters (letters, spaces, apostrophes, hyphens, periods)
        $message = "Mother's name can only contain letters, spaces, apostrophes, hyphens, and periods. Numbers and other special characters are not allowed.";
        $message_type = 'error';
    } elseif (!empty($fatherName) && !preg_match('/^[a-zA-Z\s\'.-]+$/', $fatherName)) {
        // Check if father's name contains only valid name characters (letters, spaces, apostrophes, hyphens, periods)
        $message = "Father's name can only contain letters, spaces, apostrophes, hyphens, and periods. Numbers and other special characters are not allowed.";
        $message_type = 'error';
    } else {
        try {
            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            
            if ($checkStmt->rowCount() > 0) {
                $message = 'Email already exists. Please use a different email.';
                $message_type = 'error';
            } else {
                // Generate username from email
                $baseUsername = explode('@', $email)[0];
                $username = generateUniqueUsername($pdo, $baseUsername);
                
                // Hash password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                // Generate student ID number for immediate access (guest access before approval)
                // Include functions file to use generateStudentNumber
                $functionsPath = __DIR__ . '/backend/student-management/includes/functions.php';
                if (file_exists($functionsPath)) {
                    require_once $functionsPath;
                    $studentIdNumber = generateStudentNumber($pdo);
                } else {
                    // Fallback: Generate consistent format YYYY-NNNN
                    $year = date('Y');
                    try {
                        // Get the highest student number for this year
                        $stmt = $pdo->prepare("
                            SELECT student_id_number 
                            FROM users 
                            WHERE student_id_number IS NOT NULL
                            AND (student_id_number REGEXP '^[0-9]{4}-[0-9]{4}$' OR student_id_number LIKE ?)
                            ORDER BY 
                                CASE 
                                    WHEN student_id_number REGEXP '^[0-9]{4}-[0-9]{4}$' THEN CAST(SUBSTRING_INDEX(student_id_number, '-', -1) AS UNSIGNED)
                                    WHEN student_id_number LIKE ? THEN CAST(SUBSTRING(student_id_number, 8) AS UNSIGNED)
                                    ELSE 0
                                END DESC
                            LIMIT 1
                        ");
                        $yearPattern = $year . '-%';
                        $stuPattern = 'STU' . $year . '%';
                        $stmt->execute([$yearPattern, $stuPattern]);
                        $result = $stmt->fetch();
                        
                        if ($result) {
                            $existingId = $result['student_id_number'];
                            if (preg_match('/^[0-9]{4}-[0-9]{4}$/', $existingId)) {
                                // YYYY-NNNN format
                                $parts = explode('-', $existingId);
                                $number = intval($parts[1]) + 1;
                            } else if (preg_match('/^STU' . $year . '(\d+)$/', $existingId, $matches)) {
                                // STU format - extract number and convert
                                $number = intval($matches[1]) + 1;
                            } else {
                                $number = 1;
                            }
                        } else {
                            $number = 1;
                        }
                        
                        // Format: YYYY-NNNN (consistent format)
                        $studentIdNumber = sprintf('%s-%04d', $year, $number);
                        
                        // Check uniqueness
                        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE student_id_number = ?");
                        $check_stmt->execute([$studentIdNumber]);
                        $attempts = 0;
                        while ($check_stmt->rowCount() > 0 && $attempts < 100) {
                            $number++;
                            $studentIdNumber = sprintf('%s-%04d', $year, $number);
                            $check_stmt->execute([$studentIdNumber]);
                            $attempts++;
                        }
                    } catch (Exception $e) {
                        // Fallback: use timestamp-based number
                        $studentIdNumber = $year . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    }
                }
                
                // Check if enhanced database is being used
                $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
                $tableExists = $stmt->rowCount() > 0;
                
                if ($tableExists) {
                    $userData = [
                        'username' => $username,
                        'password' => $passwordHash,
                        'email' => $email,
                        'role' => 'student',
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'middle_name' => $middleName,
                        'suffix' => $suffix,
                        'birthday' => $birthday,
                        'nationality' => $nationality,
                        'phone_number' => $phoneNumber,
                        'gender' => $gender,
                        'program' => $program,
                        'educational_status' => $educationalStatus,
                        'student_id_number' => $studentIdNumber,
                        'country' => $country,
                        'city_province' => $cityProvince,
                        'municipality' => $municipality,
                        'baranggay' => $baranggay,
                        'address' => $address,
                        'address_line2' => $addressLine2,
                        'postal_code' => $postalCode,
                        'mother_name' => $motherName,
                        'mother_phone' => $motherPhoneNumber,
                        'mother_occupation' => $motherOccupation,
                        'father_name' => $fatherName,
                        'father_phone' => $fatherPhoneNumber,
                        'father_occupation' => $fatherOccupation,
                        'emergency_name' => $emergencyName,
                        'emergency_phone' => $emergencyPhoneNumber,
                        'emergency_address' => $emergencyAddress,
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    try {
                        $userId = insertUserDynamic($pdo, $userData);
                    } catch (Exception $insertException) {
                        error_log('Dynamic registration insert failed: ' . $insertException->getMessage());
                        throw new PDOException($insertException->getMessage(), (int)$insertException->getCode(), $insertException);
                    }
                    
                    // Create admission application record if table exists
                    try {
                        $admissionTableStmt = $pdo->query("SHOW TABLES LIKE 'admission_applications'");
                        $admissionTableExists = $admissionTableStmt->rowCount() > 0;
                        
                        if ($admissionTableExists) {
                            $applicationNumber = 'APP' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                            
                            $stmt = $pdo->prepare("INSERT INTO admission_applications (student_id, application_number, application_date, program_applied, educational_status, status, created_at) VALUES (?, ?, CURDATE(), ?, ?, 'pending', NOW())");
                            $stmt->execute([$userId, $applicationNumber, $program, $educationalStatus]);
                        }
                    } catch (PDOException $inner) {
                        // Silently ignore optional table insert failure
                        error_log('Optional admission application insert failed: ' . $inner->getMessage());
                    }
                } else {
                    throw new Exception('Users table does not exist in the connected database.');
                }
                
				// Clear form data after successful registration
				$_POST = array();
				
				// Redirect to success page after successful registration
				header('Location: confirmation-success.html');
				exit();
            }
		} catch (PDOException $e) {
			error_log('Registration failed: ' . $e->getMessage());
			$message = 'Registration failed. Please try again.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Application</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/student-registration.css">
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
</head>
<body>
    
    <div class="main-content">
        <div class="sticky-header">
            <a href="landing.html" class="back-to-home" title="Back to Home">
                <span class="home-icon"><img src="assets/images/home_black.png" alt="Home Icon"></span>
                <span class="home-text">Home</span>
            </a>

            <div class="logo-bar">
                <a href="landing.html"><img src="assets/images/logo.png" alt="Colegio de Amore Logo"></a>
                <span class="school-name">Colegio de Amore</span>
            </div>
        </div>

        <div class="progress-bar-stepper">
            <div class="step active" id="step1">1</div>
            <div class="step-connector"></div>
            <div class="step" id="step2">2</div>
            <div class="step-connector"></div>
            <div class="step" id="step3">3</div>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $message_type === 'error' ? 'error' : 'success' ?>" style="margin: 20px auto; max-width: 600px; padding: 15px; border-radius: 5px; text-align: center; <?= $message_type === 'error' ? 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="form-group" id="formBox">
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
                            <input type="text" id="firstName" name="firstName" autocomplete="given-name" required value="<?= htmlspecialchars($_POST['firstName'] ?? '') ?>">
                        </div>
                        <div class="form-field half">
                            <label for="customSuffixSelect"><span class="required-field">*</span>Suffix</label>
                            <div class="custom-dropdown-group">
                                <div id="customSuffixSelect" class="custom-dropdown-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false" role="combobox">
                                    <span id="selectedSuffix" class="selected-option">N/A</span>
                                    <span class="custom-arrow">&#9662;</span>
                                    <ul class="dropdown-list" id="suffixList" tabindex="-1" hidden role="listbox"></ul>
                                </div>
                                <input type="hidden" id="suffix" name="suffix" value="N/A">
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="middleName">Middle Name<span class="required-field">*</span></label>
                            <input type="text" id="middleName" name="middleName" autocomplete="additional-name" value="<?= htmlspecialchars($_POST['middleName'] ?? '') ?>">
                            <div class="middle-name-checkbox">
                                <input type="checkbox" id="noMiddleName" name="noMiddleName" <?= isset($_POST['noMiddleName']) ? 'checked' : '' ?>>
                                <label for="noMiddleName" class="checkbox-label">I have no middle name</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="lastName">Last Name<span class="required-field">*</span></label>
                            <input type="text" id="lastName" name="lastName" autocomplete="family-name" required value="<?= htmlspecialchars($_POST['lastName'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- Birthday -->
                    <div class="form-field-row">
                        <div class="form-field half">
                            <label for="dob">Birthday<span class="required-field">*</span></label>
                            <input type="date" id="dob" name="dob" autocomplete="bday" required placeholder="mm/dd/yyyy" value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
                        </div>
                        <div class="form-field half nationality-box">
                            <label for="customNationalitySelect">Nationality<span class="required-field">*</span></label>
                            <div class="custom-dropdown-group">
                                <div id="customNationalitySelect" class="custom-dropdown-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false" role="combobox" aria-labelledby="nationality-label">
                                    <span id="selectedNationality" class="selected-option">Select Nationality</span>
                                    <span class="custom-arrow">&#9662;</span>
                                    <ul class="dropdown-list" id="nationalityList" tabindex="-1" hidden role="listbox"></ul>
                                </div>
                                <input type="hidden" id="nationality" name="nationality" required>
                            </div>
                        </div>
                    </div>
                    <!-- Phone Number -->
                    <div class="form-field-row">
                        <div class="form-field full">
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
                                <input type="tel" id="phoneNumber" name="phoneNumber" autocomplete="tel-national" maxlength="15" required value="<?= htmlspecialchars($_POST['phoneNumber'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <!-- Gender -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label>Sex<span class="required-field">*</span></label>
                            <div class="gender-box-group">
                                <label class="gender-box">
                                    <input type="radio" name="gender" value="Male" required <?= ($_POST['gender'] ?? '') === 'Male' ? 'checked' : '' ?>>
                                    <span class="gender-icon"><img src="assets/images/male-gender.png" alt="male-gender"></span>
                                    <span class="gender-label">Male</span>
                                </label>
                                <label class="gender-box">
                                    <input type="radio" name="gender" value="Female" required <?= ($_POST['gender'] ?? '') === 'Female' ? 'checked' : '' ?>>
                                    <span class="gender-icon"><img src="assets/images/female-gender.png" alt="female-gender"></span>
                                    <span class="gender-label">Female</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- Program to Enroll -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="customProgramSelect">Program to Enroll<span class="required-field">*</span></label>
                            <div class="custom-dropdown-group">
                                <div id="customProgramSelect" class="custom-dropdown-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false" role="combobox">
                                    <span id="selectedProgram" class="selected-option">Select Program</span>
                                    <span class="custom-arrow">&#9662;</span>
                                    <ul class="dropdown-list" id="programList" tabindex="-1" hidden role="listbox"></ul>
                                </div>
                                <input type="hidden" id="program" name="program" required>
                            </div>
                        </div>
                    </div>
                    <!-- Educational Status -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="customEducationalStatusSelect">Educational Status<span class="required-field">*</span></label>
                            <div class="custom-dropdown-group">
                                <div id="customEducationalStatusSelect" class="custom-dropdown-select" tabindex="0" aria-haspopup="listbox" aria-expanded="false" role="combobox">
                                    <span id="selectedEducationalStatus" class="selected-option">Select Status</span>
                                    <span class="custom-arrow">&#9662;</span>
                                    <ul class="dropdown-list" id="educationalStatusList" tabindex="-1" hidden role="listbox"></ul>
                                </div>
                                <input type="hidden" id="educationalStatus" name="educationalStatus" required>
                            </div>
                        </div>
                    </div>
                    <!-- Next Button -->
                    <div class="form-actions">
                        <button type="submit" id="nextBtn">Next</button>
                    </div>
                    <div class="form-footer step1">
                        <p>Already have an Account? <a href="auth/staff-login.php">Login here</a></p>
                    </div>

                </div>

                <!-- Step 2: Address & Guardian Information -->
                <div class="form-step" id="step2Form" style="display:none;">
                    <div class="form-step-header">
                        <div class="back-step-container">
                            <button type="button" class="back-step-btn" id="backToStep1">
                                <img src="assets/images/back-admission.png" alt="Back" class="back-icon">
                                <p>Go Back</p>
                            </button>
                        </div>
                        <!-- Step 2 Header Right -->
                        <div class="step2-header-right">
                            <span class="form-step-no">Step 2</span>
                            <span class="form-footer small">Already have an Account? <a href="auth/staff-login.php">Login here</a></span>

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
                            <input type="text" id="country" name="country" autocomplete="country" required value="<?= htmlspecialchars($_POST['country'] ?? '') ?>">
                        </div>
                    <!-- State/Province -->
                    <div class="form-field half">
                        <label for="cityProvince">State/Province<span class="required-field">*</span></label>
                        <input type="text" id="cityProvince" name="cityProvince" autocomplete="address-level1" required value="<?= htmlspecialchars($_POST['cityProvince'] ?? '') ?>">
                    </div>
                    </div>
                    <!-- Municipality/City -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="municipality">City/Municipality<span class="required-field">*</span></label>
                            <input type="text" id="municipality" name="municipality" autocomplete="address-level2" required value="<?= htmlspecialchars($_POST['municipality'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- Baranggay -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="baranggay">Baranggay<span class="required-field">*</span></label>
                            <input type="text" id="baranggay" name="baranggay" autocomplete="address-level3" required value="<?= htmlspecialchars($_POST['baranggay'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- House No./Building/Street Name -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="address">House No./Building/Street Name<span class="required-field">*</span></label>
                            <input type="text" id="address" name="address" autocomplete="street-address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- Address Line 2 -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="addressLine2">Address Line 2 (optional)</label>
                            <input type="text" id="addressLine2" name="addressLine2" autocomplete="address-line2" value="<?= htmlspecialchars($_POST['addressLine2'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- Postal Code -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="postalCode">Postal Code (optional)</label>
                            <input type="text" id="postalCode" name="postalCode" autocomplete="postal-code" maxlength="4" value="<?= htmlspecialchars($_POST['postalCode'] ?? '') ?>">
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
                            <input type="text" id="motherName" name="motherName" autocomplete="off" required pattern="^[a-zA-Z\s'.-]+$" title="Only letters, spaces, apostrophes, hyphens, and periods are allowed. Numbers are not permitted." value="<?= htmlspecialchars($_POST['motherName'] ?? '') ?>">
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
                                <input type="tel" id="motherPhoneNumber" name="motherPhoneNumber" autocomplete="tel-national" maxlength="15" required value="<?= htmlspecialchars($_POST['motherPhoneNumber'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="motherOccupation">Occupation<span class="required-field">*</span></label>
                            <input type="text" id="motherOccupation" name="motherOccupation" autocomplete="organization-title" required value="<?= htmlspecialchars($_POST['motherOccupation'] ?? '') ?>">
                        </div>
                    </div>
                    <!-- Father's Full Name -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="fatherName">Father's Full Name<span class="required-field">*</span></label>
                            <input type="text" id="fatherName" name="fatherName" autocomplete="off" required pattern="^[a-zA-Z\s'.-]+$" title="Only letters, spaces, apostrophes, hyphens, and periods are allowed. Numbers are not permitted." value="<?= htmlspecialchars($_POST['fatherName'] ?? '') ?>">
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
                                <input type="tel" id="fatherPhoneNumber" name="fatherPhoneNumber" autocomplete="tel-national" maxlength="15" required value="<?= htmlspecialchars($_POST['fatherPhoneNumber'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="fatherOccupation">Occupation<span class="required-field">*</span></label>
                            <input type="text" id="fatherOccupation" name="fatherOccupation" autocomplete="organization-title" required value="<?= htmlspecialchars($_POST['fatherOccupation'] ?? '') ?>">
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
                            <input type="text" id="emergencyName" name="emergencyName" autocomplete="off" required value="<?= htmlspecialchars($_POST['emergencyName'] ?? '') ?>">
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
                                <input type="tel" id="emergencyPhoneNumber" name="emergencyPhoneNumber" autocomplete="tel-national" maxlength="15" required value="<?= htmlspecialchars($_POST['emergencyPhoneNumber'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="emergencyAddress">Complete Address<span class="required-field">*</span></label>
                            <input type="text" id="emergencyAddress" name="emergencyAddress" autocomplete="street-address" required value="<?= htmlspecialchars($_POST['emergencyAddress'] ?? '') ?>">
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
                            <button type="button" class="back-step-btn" id="backToStep2">
                                <img src="assets/images/back-admission.png" alt="Back" class="back-icon">
                                <p>Go Back</p>
                            </button>
                        </div>
                        <!-- Step 3 Header Right -->
                        <div class="step2-header-right">
                            <span class="form-step-no">Step 3</span>
                            <span class="form-footer small">Already have an Account? <a href="auth/staff-login.php">Login here</a></span>
                            <span class="required-field-step2">* required field</span>
                        </div>
                    </div>
                    <hr>
                    <div class="form-section-title">Email and Password</div>
                    <div class="form-section-desc">Please input your correct credentials below</div>

                    <!-- Email -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="email">Email<span class="required-field">*</span></label>
                            <input type="email" id="email" name="email" autocomplete="email" required placeholder="username@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <!-- Confirm Email -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="confirmEmail">Confirm Email<span class="required-field">*</span></label>
                            <input type="email" id="confirmEmail" name="confirmEmail" autocomplete="email" required value="<?= htmlspecialchars($_POST['confirmEmail'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="form-field-row">
                        <div class="form-field full">
                            <label for="password">Password<span class="required-field">*</span></label>
                            <div class="password-input-container">
                                <input type="password" id="password" name="password" autocomplete="new-password" required>
                                <span class="password-toggle" id="togglePassword">
                                    <img src="assets/images/view.png" alt="Show password">
                                </span>
                            </div>
                            
                            <!-- Password Strength Indicator -->
                            <div class="password-strength-container" id="passwordStrengthContainer">
                                <div class="password-strength-bar-wrapper">
                                    <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                </div>
                                <span class="password-strength-label" id="passwordStrengthLabel">Strong Password</span>
                            </div>
                            
                            <!-- Password Checklist -->
                            <ul class="password-checklist" id="passwordChecklist">
                                <li class="checklist-item" id="check-length">
                                    <span class="check-icon">&#10003;</span>
                                    <span class="check-text">Atleast 8 characters</span>
                                </li>
                                <li class="checklist-item" id="check-uppercase">
                                    <span class="check-icon">&#10003;</span>
                                    <span class="check-text">Atleast one uppercase letter [A-Z]</span>
                                </li>
                                <li class="checklist-item" id="check-lowercase">
                                    <span class="check-icon">&#10003;</span>
                                    <span class="check-text">Atleast one lowercase letter [a-z]</span>
                                </li>
                                <li class="checklist-item" id="check-number">
                                    <span class="check-icon">&#10003;</span>
                                    <span class="check-text">Atleast one number [0-9]</span>
                                </li>
                                <li class="checklist-item" id="check-special">
                                    <span class="check-icon">&#10003;</span>
                                    <span class="check-text">Aleast one special character [!@#$%^&*]</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-field-row" id="confirmPasswordSection">
                        <div class="form-field full">
                            <label for="confirmPassword">Confirm Password<span class="required-field">*</span></label>
                            <div class="password-input-container">
                                <input type="password" id="confirmPassword" name="confirmPassword" autocomplete="new-password" required>
                                <span class="password-toggle" id="toggleConfirmPassword">
                                    <img src="assets/images/view.png" alt="Show password">
                                </span>
                            </div>
                        </div>
                    </div>
                    <!-- Submit Button -->
                    <div class="form-actions">
                        <button type="submit" id="submitRegistration">Next</button>
                    </div>
                </div>
                
                <!-- Step 4 removed - confirmation now on separate page -->
                 
            </div>
        </form>

        <!-- <div class="copyright-bottom-right">&copy; 2025 Colegio de Amore. All rights reserved.</div> -->
    </div>
</div>
	<script src="assets/js/student-registration.js"></script>
</body>
</html>

