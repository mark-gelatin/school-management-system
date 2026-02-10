<?php
// Student Profile Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // student/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/student
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
require_once getAbsolutePath('backend/includes/student_approval.php');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirectTo('auth/student-login.php');
}

$studentId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $message = 'Student record not found.';
        $message_type = 'error';
    }
} catch (PDOException $e) {
    $message = 'Error retrieving student information: ' . $e->getMessage();
    $message_type = 'error';
}

// Check student approval status
$approvalStatus = checkStudentApprovalStatus($pdo, $studentId, $student);
$isApproved = $approvalStatus['isApproved'];
$admissionInfo = $approvalStatus['admissionInfo'];

// Block profile editing if not approved - show popup and redirect
if (!$isApproved) {
    // Set message for popup
    $message = 'This action is restricted until your account is approved.';
    $message_type = 'error';
    
    // If this is a POST request (form submission), redirect to prevent submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        header("Location: student-profile.php?msg=" . urlencode($message) . "&type=" . $message_type);
        exit();
    }
}

// Function to log student profile changes
function logStudentProfileChange($pdo, $studentId, $changes) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Get student name for description
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        $studentName = $student ? ($student['first_name'] . ' ' . $student['last_name']) : 'Unknown';
        
        $description = "Student (ID: {$studentId}, Name: {$studentName}) updated profile. Changes: " . implode("; ", $changes);
        
        // Get admin user ID (or use system user ID 1 if exists, otherwise NULL)
        $adminId = null;
        try {
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            $admin = $stmt->fetch();
            if ($admin) {
                $adminId = $admin['id'];
            }
        } catch (Exception $e) {
            // If no admin exists, use NULL
        }
        
        // Insert log with student_id in description or use a student_logs approach
        // For now, we'll use admin_logs but with student_id in description
        $stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent) 
            VALUES (?, 'student_profile_update', 'user', ?, ?, ?, ?)
        ");
        $stmt->execute([$adminId, $studentId, $description, $ip_address, $user_agent]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log student profile change: " . $e->getMessage());
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Ensure session is maintained during POST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Re-check authentication after POST
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
        redirectTo('auth/student-login.php');
    }
    
    try {
        // Get old values for comparison - map to actual database columns
        $oldValues = [
            'first_name' => $student['first_name'] ?? '',
            'middle_name' => $student['middle_name'] ?? '',
            'last_name' => $student['last_name'] ?? '',
            'suffix' => $student['suffix'] ?? '',
            'sex' => $student['gender'] ?? $student['sex'] ?? '',
            'birth_date' => $student['birthday'] ?? $student['birth_date'] ?? '',
            'phone_number' => $student['phone_number'] ?? '',
            'email' => $student['email'] ?? '',
            'address' => $student['address'] ?? '',
            'baranggay' => $student['baranggay'] ?? $student['barangay'] ?? '',
            'city_province' => $student['city_province'] ?? $student['province'] ?? '',
            'municipality' => $student['municipality'] ?? $student['city'] ?? '',
        ];
        
        // Get new values - Note: first_name, middle_name, last_name, sex, birth_date, and email are read-only
        // Use existing values from database for read-only fields
        $firstName = $student['first_name'] ?? '';
        $middleName = $student['middle_name'] ?? '';
        $lastName = $student['last_name'] ?? '';
        $sex = $student['gender'] ?? $student['sex'] ?? '';
        $birthDate = $student['birthday'] ?? $student['birth_date'] ?? '';
        $email = $student['email'] ?? '';
        
        // Get editable fields from POST - map to database columns
        $suffix = trim($_POST['suffix'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['street_address'] ?? ''); // Maps to 'address' column
        $baranggay = trim($_POST['barangay'] ?? ''); // Maps to 'baranggay' column (note spelling)
        $cityProvince = trim($_POST['province'] ?? ''); // Maps to 'city_province' column
        $municipality = trim($_POST['city'] ?? ''); // Maps to 'municipality' column
        
        // Track changes - only track editable fields (first_name, middle_name, last_name, sex, birth_date, email are read-only)
        $changes = [];
        
        // Only track changes for editable fields
        if ($oldValues['suffix'] !== $suffix) {
            $changes[] = "Suffix: '{$oldValues['suffix']}' → '{$suffix}'";
        }
        if ($oldValues['phone_number'] !== $phoneNumber) {
            $changes[] = "Phone Number: '{$oldValues['phone_number']}' → '{$phoneNumber}'";
        }
        if ($oldValues['address'] !== $address) {
            $changes[] = "Address: '{$oldValues['address']}' → '{$address}'";
        }
        if ($oldValues['baranggay'] !== $baranggay) {
            $changes[] = "Barangay: '{$oldValues['baranggay']}' → '{$baranggay}'";
        }
        if ($oldValues['city_province'] !== $cityProvince) {
            $changes[] = "Province: '{$oldValues['city_province']}' → '{$cityProvince}'";
        }
        if ($oldValues['municipality'] !== $municipality) {
            $changes[] = "City/Municipality: '{$oldValues['municipality']}' → '{$municipality}'";
        }
        
        // Handle password change
        $passwordChanged = false;
        if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];
            
            // Verify current password
            if (password_verify($currentPassword, $student['password'])) {
                // Check if new password matches confirmation
                if ($newPassword === $confirmPassword) {
                    // Validate password strength (minimum 8 characters)
                    if (strlen($newPassword) >= 8) {
                        // Hash and update password
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        try {
                            $passwordStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'student'");
                            $passwordStmt->execute([$hashedPassword, $studentId]);
                            $passwordChanged = true;
                            $changes[] = "Password: Changed";
                        } catch (PDOException $e) {
                            $message = 'Error updating password: ' . $e->getMessage();
                            $message_type = 'error';
                        }
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
        } elseif (!empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_password'])) {
            // Partial password fields filled
            $message = 'Please fill in all password fields to change your password.';
            $message_type = 'error';
        }
        
        // Handle profile picture upload
        $profilePicture = $student['profile_picture'] ?? null;
        $profilePictureChanged = false;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $allowedTypes = ['jpg', 'jpeg', 'png'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($fileExt, $allowedTypes) && $file['size'] <= 5242880) { // 5MB max
                $uploadDir = __DIR__ . '/uploads/profiles/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'profile_' . $studentId . '_' . time() . '.' . $fileExt;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    // Delete old profile picture if exists
                    if ($profilePicture) {
                        $oldPath1 = __DIR__ . '/' . $profilePicture;
                        $oldPath2 = strpos($profilePicture, 'public/') === 0 ? __DIR__ . '/../' . $profilePicture : $oldPath1;
                        if (file_exists($oldPath1)) {
                            @unlink($oldPath1);
                        } elseif (file_exists($oldPath2)) {
                            @unlink($oldPath2);
                        }
                    }
                    $profilePicture = 'uploads/profiles/' . $fileName;
                    $profilePictureChanged = true;
                    $changes[] = "Profile Picture: Updated";
                } else {
                    $message = 'Failed to upload profile picture. Please check file permissions.';
                    $message_type = 'error';
                }
            }
        }
        
        // Only proceed if there are actual changes
        if (empty($changes) && !$profilePictureChanged && !$passwordChanged) {
            // Redirect with message to show popup
            header("Location: student-profile.php?msg=" . urlencode('No changes detected.') . "&type=info");
            exit();
        } else {
            // Build update query dynamically based on what changed
            $updateFields = [];
            $updateValues = [];
            
            // Add fields that need updating - check if columns exist first
            // Check which columns exist in the database
            $existingColumns = [];
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM users");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $existingColumns = array_flip($columns);
            } catch (PDOException $e) {
                // If we can't check, assume standard columns exist
                $existingColumns = ['suffix' => true, 'phone_number' => true, 'address' => true, 
                                   'baranggay' => true, 'city_province' => true, 'municipality' => true];
            }
            
            // Add fields that need updating (only if column exists)
            if (isset($existingColumns['suffix']) && (isset($oldValues['suffix']) || !empty($suffix))) {
                $updateFields[] = 'suffix = ?';
                $updateValues[] = $suffix ?? '';
            }
            if (isset($existingColumns['phone_number']) && (isset($oldValues['phone_number']) || !empty($phoneNumber))) {
                $updateFields[] = 'phone_number = ?';
                $updateValues[] = $phoneNumber ?? '';
            }
            // Always update address fields if they exist in the database and were submitted
            // Check if field was in POST (even if empty) or if it has a value
            if (isset($existingColumns['address'])) {
                $updateFields[] = 'address = ?';
                $updateValues[] = $address ?? '';
            }
            if (isset($existingColumns['baranggay'])) {
                $updateFields[] = 'baranggay = ?';
                $updateValues[] = $baranggay ?? '';
            }
            if (isset($existingColumns['city_province'])) {
                $updateFields[] = 'city_province = ?';
                $updateValues[] = $cityProvince ?? '';
            }
            if (isset($existingColumns['municipality'])) {
                $updateFields[] = 'municipality = ?';
                $updateValues[] = $municipality ?? '';
            }
            if ($profilePictureChanged && $profilePicture !== null) {
                $updateFields[] = 'profile_picture = ?';
                $updateValues[] = $profilePicture;
            }
            
            // Always update the timestamp
            $updateFields[] = 'updated_at = NOW()';
            
            // Execute update - always update at least the timestamp
            $updateValues[] = $studentId; // Add student ID for WHERE clause
            $updateSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ? AND role = 'student'";
            
            try {
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($updateValues);
            } catch (PDOException $e) {
                throw new PDOException("Error updating profile: " . $e->getMessage());
            }
            
            // Log the changes
            if (!empty($changes)) {
                $studentName = $firstName . ' ' . $lastName;
                $logDescription = "Student (ID: {$studentId}, Name: {$studentName}) updated profile. Changes: " . implode("; ", $changes);
                logStudentProfileChange($pdo, $studentId, $changes);
            }
            
            // Redirect to profile page with success message to show popup
            header("Location: student-profile.php?msg=" . urlencode('Profile updated successfully!') . "&type=success");
            exit();
        }
    } catch (PDOException $e) {
        $message = 'Error updating profile: ' . $e->getMessage();
        $message_type = 'error';
        error_log("Profile update error: " . $e->getMessage());
    }
}

// Refresh student data after POST to get updated profile picture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$studentId]);
        $updatedStudent = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($updatedStudent) {
            $student = $updatedStudent;
        }
    } catch (PDOException $e) {
        // Silently fail - use existing student data
    }
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    redirectTo('auth/student-login.php');
}

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Information - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <script src="../../assets/js/philippine-locations.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html {
            height: 100%;
            overflow-x: hidden;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
            padding-left: 0;
            transition: padding-left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Add padding when sidebar is visible on desktop */
        @media (min-width: 769px) {
            body:not(.sidebar-closed) {
                padding-left: 280px;
            }
            
            body.sidebar-closed {
                padding-left: 0;
            }
        }
        
        @media (max-width: 1024px) and (min-width: 769px) {
            body:not(.sidebar-closed) {
                padding-left: 250px;
            }
        }
        
        /* Prevent body scroll when sidebar is open on mobile */
        body.sidebar-open {
            overflow: hidden;
            position: fixed;
            width: 100%;
            height: 100%;
        }
        
        @media (min-width: 769px) {
            body.sidebar-open {
                overflow: visible;
                position: static;
                width: auto;
                height: auto;
            }
        }
        
        /* Mobile Menu Toggle Button */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1001;
            background: #a11c27;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            width: auto;
            height: auto;
            min-width: 40px;
            min-height: 40px;
            align-items: center;
            justify-content: center;
        }
        
        .mobile-menu-toggle:not(.hide) {
            display: flex;
        }
        
        .mobile-menu-toggle.hide {
            display: none !important;
        }
        
        .mobile-menu-toggle:hover {
            background: #b31310;
            transform: scale(1.05);
            box-shadow: 0 3px 12px rgba(0,0,0,0.2);
        }
        
        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }
        
        /* Responsive adjustments for toggle button */
        @media (max-width: 480px) {
            .mobile-menu-toggle {
                padding: 7px 10px;
                font-size: 0.9rem;
                min-width: 36px;
                min-height: 36px;
                top: 12px;
                left: 12px;
            }
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        visibility 0.35s;
            cursor: pointer;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        
        .sidebar-overlay.active {
            display: block;
            opacity: 1;
            visibility: visible;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #a11c27 0%, #b31310 100%);
            height: 100vh;
            padding-top: 25px;
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        visibility 0.35s;
            transform: translateX(0);
            opacity: 1;
            visibility: visible;
        }
        
        .sidebar.hidden {
            transform: translateX(-100%);
            opacity: 0;
            visibility: hidden;
        }
        
        .sidebar.active {
            transform: translateX(0);
            opacity: 1;
            visibility: visible;
            z-index: 1001;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding: 0 20px 15px 15px;
            position: relative;
            min-width: 0;
        }
        
        .logo::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 20px;
            right: 20px;
            height: 2px;
            background: rgba(255,255,255,0.3);
        }
        
        .logo img {
            width: auto;
            height: 50px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .school-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            text-align: left;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
            flex: 1;
            min-width: 0;
        }
        
        .nav-section {
            margin-bottom: 25px;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            margin-bottom: 2px;
            border-radius: 0;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: white;
            position: relative;
            min-width: 0;
        }
        
        .nav-item:link,
        .nav-item:visited,
        .nav-item:active {
            color: white;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        
        .nav-item.active i {
            color: white;
        }
        
        .nav-item:hover i {
            color: white;
        }
        
        .nav-item i {
            width: 18px;
            text-align: center;
            font-size: 1rem;
            flex-shrink: 0;
            color: white;
        }
        
        .nav-item span:not(.nav-badge) {
            flex: 1;
            font-size: 0.95rem;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .sidebar-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            padding-bottom: 20px;
            -webkit-overflow-scrolling: touch;
        }
        
        .sidebar .user-profile {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            margin: 0 15px 20px 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            width: calc(100% - 30px);
            min-width: 0;
            box-sizing: border-box;
        }
        
        .sidebar .user-profile:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .sidebar .profile-picture {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            border: 3px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #a11c27;
            margin: 0 auto 12px;
            flex-shrink: 0;
            font-weight: 700;
            overflow: hidden;
            position: relative;
        }
        
        .sidebar .profile-picture img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            border-radius: 50%;
            display: none;
            position: absolute;
            top: 0;
            left: 0;
        }
        
        .sidebar .profile-picture.has-image img {
            display: block;
        }
        
        .sidebar .profile-picture.has-image {
            background: transparent;
            font-size: 0;
        }
        
        .sidebar .user-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
            text-align: center;
            color: white;
            width: 100%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            padding: 0 5px;
            box-sizing: border-box;
        }
        
        .sidebar .user-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-align: center;
            color: rgba(255,255,255,0.95);
            font-weight: 500;
            width: 100%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            padding: 0 5px;
            box-sizing: border-box;
        }
        
        .sidebar-footer {
            flex-shrink: 0;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .upgrade-btn {
            background: white;
            color: #a11c27;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            width: calc(100% - 30px);
            margin: 0 15px 20px 15px;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s;
        }
        
        .upgrade-btn:hover {
            background: #f5f5f5;
        }
        
        .container {
            margin: 30px auto;
            padding: 30px;
            max-width: 900px;
            width: 100%;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Ensure container stays centered on desktop */
        @media (min-width: 769px) {
            .container {
                margin-left: auto;
                margin-right: auto;
            }
        }
        
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
        }
        
        @media (max-width: 768px) {
            .mobile-menu-toggle:not(.hide) {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                opacity: 0;
                visibility: hidden;
            }
            
            .sidebar.active {
                transform: translateX(0);
                opacity: 1;
                visibility: visible;
                z-index: 1001;
            }
            
            .sidebar.hidden {
                transform: translateX(-100%);
                opacity: 0;
                visibility: hidden;
            }
            
            .container {
                margin-left: 0;
                padding: 20px;
                padding-top: 70px;
                width: 100%;
                margin: 0;
                border-radius: 0;
                transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            /* Prevent body scroll when sidebar is open on mobile */
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
                transition: none;
            }
        }
        .form-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        .form-subtitle {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 30px;
        }
        
        .home-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #a11c27;
            color: white;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(161, 28, 39, 0.2);
        }
        
        .home-btn:hover {
            background: #b31310;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        
        .home-btn i {
            font-size: 1rem;
        }
        .form-section {
            margin-bottom: 35px;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
            background: #f8f9fa;
            transition: all 0.2s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #a11c27;
            background: white;
        }
        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-note {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            transition: color 0.2s;
            z-index: 10;
            padding: 5px;
        }
        
        .password-toggle:hover {
            color: #a11c27;
        }
        
        .form-group > div[style*="position: relative"] {
            position: relative;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .radio-option label {
            font-weight: 400;
            cursor: pointer;
            color: #333;
        }
        .profile-picture-section {
            margin-bottom: 30px;
        }
        .profile-picture-upload {
            position: relative;
            width: 200px;
            height: 200px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
        }
        .profile-picture-upload:hover {
            border-color: #a11c27;
            background: #fff;
        }
        .profile-picture-upload img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }
        .profile-picture-upload.has-image img {
            display: block;
        }
        .profile-picture-upload .upload-icon {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #a11c27;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            z-index: 2;
        }
        .profile-picture-upload .upload-placeholder {
            text-align: center;
            color: #999;
            padding: 20px;
        }
        .profile-picture-upload .upload-placeholder i {
            font-size: 3rem;
            margin-bottom: 10px;
            color: #ccc;
        }
        .profile-picture-upload input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 3;
        }
        .btn-primary {
            background: #a11c27;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary:hover {
            background: #b31310;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
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
        .message.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        /* Popup Modal Styles */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .popup-overlay.show {
            display: flex;
        }
        .popup-modal {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: popupSlideIn 0.3s ease-out;
        }
        @keyframes popupSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .popup-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .popup-icon.success {
            color: #28a745;
        }
        .popup-icon.info {
            color: #17a2b8;
        }
        .popup-icon.error {
            color: #dc3545;
        }
        .popup-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        .popup-message {
            font-size: 1rem;
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        .popup-button {
            background: #a11c27;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
        }
        .popup-button:hover {
            background: #b31310;
        }
        @media (max-width: 768px) {
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            .container {
                padding: 20px;
            }
            .container > div:first-child {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .home-btn {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Overlay for mobile menu -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="hideSidebar()"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-content">
            <div class="logo">
                <img src="../../assets/images/logo.png" alt="Colegio de Amore logo" />
                <h1 class="school-name">Colegio de Amore</h1>
            </div>
            
            <!-- User Profile Card -->
            <a href="student-profile.php" class="user-profile" style="text-decoration: none; color: inherit; display: block;">
                <div class="profile-picture <?php 
                    $profilePic = $student['profile_picture'] ?? null;
                    $hasProfilePic = false;
                    if ($profilePic) {
                        $relativePath = __DIR__ . '/' . $profilePic;
                        $absolutePath = strpos($profilePic, 'public/') === 0 ? __DIR__ . '/../' . $profilePic : $relativePath;
                        $hasProfilePic = file_exists($relativePath) || file_exists($absolutePath);
                    }
                    echo $hasProfilePic ? 'has-image' : '';
                ?>">
                    <?php if ($hasProfilePic && $profilePic): ?>
                        <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile Picture" onerror="this.style.display='none'; this.parentElement.classList.remove('has-image');">
                    <?php endif; ?>
                    <?php if ($student && !$hasProfilePic): ?>
                        <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                    <?php elseif (!$student): ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="user-name">
                    <?php if ($student): ?>
                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                    <?php else: ?>
                        Student
                    <?php endif; ?>
                </div>
                <div class="user-role">Student</div>
            </a>
            
            <div class="nav-section">
                <a href="student-dashboard.php" class="nav-item">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <?php if ($isApproved): ?>
                    <a href="student-schedule.php" class="nav-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedule</span>
                    </a>
                    <a href="student-subjects.php" class="nav-item">
                        <i class="fas fa-book"></i>
                        <span>Courses</span>
                    </a>
                    <a href="student-grades.php" class="nav-item">
                        <i class="fas fa-chart-bar"></i>
                        <span>Grades</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <form method="POST" style="margin: 0 15px 20px 15px;">
                <button type="submit" name="logout" class="upgrade-btn" style="background: rgba(220, 53, 69, 0.8); color: white;">Logout</button>
            </form>
        </div>
    </div>
    
    <div class="container">
        <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 20px;">
            <a href="student-dashboard.php" class="home-btn" style="text-decoration: none;">
                <i class="fas fa-home"></i> Home
            </a>
        </div>
        
        <?php if ($message && ($message_type ?? '') !== 'success' && ($message_type ?? '') !== 'info'): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Popup Modal -->
        <div id="popupOverlay" class="popup-overlay">
            <div class="popup-modal">
                <div class="popup-icon <?= $message_type ?? 'info' ?>" id="popupIcon">
                    <?php if (($message_type ?? '') === 'success'): ?>
                        <i class="fas fa-check-circle"></i>
                    <?php elseif (($message_type ?? '') === 'error'): ?>
                        <i class="fas fa-exclamation-circle"></i>
                    <?php else: ?>
                        <i class="fas fa-info-circle"></i>
                    <?php endif; ?>
                </div>
                <h3 class="popup-title" id="popupTitle">
                    <?php if (($message_type ?? '') === 'success'): ?>
                        Success!
                    <?php elseif (($message_type ?? '') === 'error'): ?>
                        Error
                    <?php else: ?>
                        Notice
                    <?php endif; ?>
                </h3>
                <p class="popup-message" id="popupMessage"><?= htmlspecialchars($message ?? '') ?></p>
                <button class="popup-button" onclick="closePopup()">OK</button>
            </div>
        </div>
        
        <form method="POST" enctype="multipart/form-data" id="profileForm" <?= !$isApproved ? 'onsubmit="event.preventDefault(); showRestrictionPopup(); return false;"' : '' ?>>
            <!-- Title Section -->
            <div style="margin-bottom: 30px;">
                <h1 class="form-title">Personal Information</h1>
                <p class="form-subtitle">Update your personal information</p>
                <?php if (!$isApproved): ?>
                    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px; margin-top: 15px; color: #856404;">
                        <i class="fas fa-exclamation-triangle"></i> Your account is pending approval. Profile editing is restricted until your account is approved by the administrator.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Profile Picture Section -->
            <div class="form-section profile-picture-section">
                <label class="form-label">Profile Picture</label>
                <?php 
                $profilePic = $student['profile_picture'] ?? null;
                // Check if file exists - handle both relative and absolute paths
                $hasImage = false;
                $imagePath = null;
                if ($profilePic) {
                    // Try relative path first (from public folder)
                    $relativePath = __DIR__ . '/' . $profilePic;
                    // Also try if path already includes public
                    $absolutePath = strpos($profilePic, 'public/') === 0 ? __DIR__ . '/../' . $profilePic : $relativePath;
                    
                    if (file_exists($relativePath)) {
                        $hasImage = true;
                        $imagePath = $profilePic;
                    } elseif (file_exists($absolutePath)) {
                        $hasImage = true;
                        $imagePath = $profilePic;
                    }
                }
                ?>
                <div class="profile-picture-upload <?= ($hasImage && $imagePath) ? 'has-image' : '' ?>" id="profilePictureUpload">
                    <div class="upload-icon">
                        <i class="fas fa-pencil-alt"></i>
                    </div>
                    <?php if ($hasImage && $imagePath): ?>
                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="Profile Picture" id="profileImagePreview">
                    <?php else: ?>
                        <div class="upload-placeholder">
                            <i class="fas fa-user"></i>
                            <div>Click to upload</div>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="profile_picture" id="profilePictureInput" accept="image/png,image/jpeg,image/jpg" onchange="previewProfilePicture(this)">
                </div>
                <p class="form-note">NOTE: Allowed file types: .png, .jpg, .jpeg. Please upload 1x1 or 2x2 picture.</p>
            </div>
            
            <!-- Personal Details Section -->
            <div class="form-section">
                <h2 class="section-title">Personal Details</h2>
                
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($student['first_name'] ?? '') ?>" readonly disabled style="background: #f0f0f0; cursor: not-allowed;">
                    <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-input" value="<?= htmlspecialchars($student['middle_name'] ?? '') ?>" readonly disabled style="background: #f0f0f0; cursor: not-allowed;">
                    <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                    </div>
                    
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($student['last_name'] ?? '') ?>" readonly disabled style="background: #f0f0f0; cursor: not-allowed;">
                        <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Enter Suffix here</label>
                        <input type="text" name="suffix" class="form-input" placeholder="Jr., Sr., III, etc." value="<?= htmlspecialchars($student['suffix'] ?? '') ?>">
                    </div>
                </div>
                <p class="form-note">Please leave it blank if not applicable.</p>
                
                <div class="form-group">
                    <label class="form-label">Sex</label>
                    <div class="radio-group" style="opacity: 0.6; pointer-events: none;">
                        <div class="radio-option">
                            <input type="radio" name="sex" id="sex_male" value="Male" <?= (($student['gender'] ?? $student['sex'] ?? '') === 'Male') ? 'checked' : '' ?> disabled>
                            <label for="sex_male">Male</label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" name="sex" id="sex_female" value="Female" <?= (($student['gender'] ?? $student['sex'] ?? '') === 'Female') ? 'checked' : '' ?> disabled>
                            <label for="sex_female">Female</label>
                        </div>
                    </div>
                    <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Birth date</label>
                    <input type="text" name="birth_date" class="form-input" placeholder="mm/dd/yyyy" value="<?= htmlspecialchars($student['birthday'] ?? $student['birth_date'] ?? '') ?>" readonly disabled style="background: #f0f0f0; cursor: not-allowed;">
                    <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                </div>
            </div>
            
            <!-- Contact Info Section -->
            <div class="form-section">
                <h2 class="section-title">Contact Info</h2>
                
                <div class="form-group">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="phone_number" class="form-input" placeholder="09XX 123 4567" value="<?= htmlspecialchars($student['phone_number'] ?? '') ?>">
                    <p class="form-note">Phone number mask: 09XX 123 4567</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($student['email'] ?? '') ?>" readonly disabled style="background: #f0f0f0; cursor: not-allowed;">
                    <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fas fa-lock"></i> This field cannot be changed</p>
                </div>
            </div>
            
            <!-- Address Section -->
            <div class="form-section">
                <h2 class="section-title">Address</h2>
                
                <div class="form-group">
                    <label class="form-label">Street Address</label>
                    <textarea name="street_address" class="form-textarea" rows="2"><?= htmlspecialchars($student['address'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Barangay</label>
                    <input type="text" name="barangay" class="form-input" value="<?= htmlspecialchars($student['baranggay'] ?? $student['barangay'] ?? '') ?>">
                    </div>
                    
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <select name="region" id="region" class="form-select" onchange="updateProvinces()">
                            <option value="">Select Region</option>
                            <option value="REGION IV-A (CALABARZON)" <?= ($student['region'] ?? '') === 'REGION IV-A (CALABARZON)' ? 'selected' : '' ?>>REGION IV-A (CALABARZON)</option>
                            <option value="REGION I (ILOCOS REGION)">REGION I (ILOCOS REGION)</option>
                            <option value="REGION II (CAGAYAN VALLEY)">REGION II (CAGAYAN VALLEY)</option>
                            <option value="REGION III (CENTRAL LUZON)">REGION III (CENTRAL LUZON)</option>
                            <option value="REGION IV-B (MIMAROPA)">REGION IV-B (MIMAROPA)</option>
                            <option value="REGION V (BICOL REGION)">REGION V (BICOL REGION)</option>
                            <option value="REGION VI (WESTERN VISAYAS)">REGION VI (WESTERN VISAYAS)</option>
                            <option value="REGION VII (CENTRAL VISAYAS)">REGION VII (CENTRAL VISAYAS)</option>
                            <option value="REGION VIII (EASTERN VISAYAS)">REGION VIII (EASTERN VISAYAS)</option>
                            <option value="REGION IX (ZAMBOANGA PENINSULA)">REGION IX (ZAMBOANGA PENINSULA)</option>
                            <option value="REGION X (NORTHERN MINDANAO)">REGION X (NORTHERN MINDANAO)</option>
                            <option value="REGION XI (DAVAO REGION)">REGION XI (DAVAO REGION)</option>
                            <option value="REGION XII (SOCCSKSARGEN)">REGION XII (SOCCSKSARGEN)</option>
                            <option value="NCR (NATIONAL CAPITAL REGION)">NCR (NATIONAL CAPITAL REGION)</option>
                            <option value="CAR (CORDILLERA ADMINISTRATIVE REGION)">CAR (CORDILLERA ADMINISTRATIVE REGION)</option>
                            <option value="ARMM (AUTONOMOUS REGION IN MUSLIM MINDANAO)">ARMM (AUTONOMOUS REGION IN MUSLIM MINDANAO)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <select name="province" id="province" class="form-select" onchange="updateCities()">
                            <option value="">Select Province</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">City/Municipality</label>
                    <select name="city" id="city" class="form-select" onchange="updateBarangays()">
                        <option value="">Select City/Municipality</option>
                    </select>
                </div>
                
                <script>
                    // Set initial values for province and city from database
                    document.addEventListener('DOMContentLoaded', function() {
                        <?php if (isset($student['city_province']) && $student['city_province']): ?>
                        const provinceSelect = document.getElementById('province');
                        if (provinceSelect) {
                            setTimeout(() => {
                                provinceSelect.value = '<?= htmlspecialchars($student['city_province']) ?>';
                                updateCities();
                            }, 100);
                        }
                        <?php endif; ?>
                        
                        <?php if (isset($student['municipality']) && $student['municipality']): ?>
                        setTimeout(() => {
                            const citySelect = document.getElementById('city');
                            if (citySelect) {
                                citySelect.value = '<?= htmlspecialchars($student['municipality']) ?>';
                            }
                        }, 200);
                        <?php endif; ?>
                    });
                </script>
                    </div>
                    
                    <!-- Change Password Section -->
                    <div class="form-section">
                        <h2 class="section-title">Change Password</h2>
                        
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <div style="position: relative;">
                                <input type="password" name="current_password" id="current_password" class="form-input" placeholder="Enter your current password">
                                <span class="password-toggle" onclick="togglePassword('current_password', 'toggle_current')" id="toggle_current" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666;">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="new_password" id="new_password" class="form-input" placeholder="Enter your new password (min. 8 characters)">
                                <span class="password-toggle" onclick="togglePassword('new_password', 'toggle_new')" id="toggle_new" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666;">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <p class="form-note">Password must be at least 8 characters long.</p>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Confirm your new password">
                                <span class="password-toggle" onclick="togglePassword('confirm_password', 'toggle_confirm')" id="toggle_confirm" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666;">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <p class="form-note" style="color: #999; font-size: 0.85rem; margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Leave password fields blank if you don't want to change your password.
                        </p>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
    </div>
    
    <script>
        // Password Toggle Function
        function togglePassword(inputId, toggleId) {
            const input = document.getElementById(inputId);
            const toggle = document.getElementById(toggleId);
            const icon = toggle.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Profile Picture Preview
        function previewProfilePicture(input) {
            const uploadDiv = document.getElementById('profilePictureUpload');
            const placeholder = uploadDiv.querySelector('.upload-placeholder');
            const img = uploadDiv.querySelector('img');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (!img) {
                        const newImg = document.createElement('img');
                        newImg.id = 'profileImagePreview';
                        newImg.src = e.target.result;
                        newImg.alt = 'Profile Picture';
                        uploadDiv.appendChild(newImg);
                    } else {
                        img.src = e.target.result;
                    }
                    if (placeholder) placeholder.style.display = 'none';
                    uploadDiv.classList.add('has-image');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Philippine Locations Cascading Dropdowns
        const philippineProvinces = {
            "REGION IV-A (CALABARZON)": ["Cavite", "Laguna", "Batangas", "Rizal", "Quezon"],
            "REGION I (ILOCOS REGION)": ["Ilocos Norte", "Ilocos Sur", "La Union", "Pangasinan"],
            "REGION II (CAGAYAN VALLEY)": ["Batanes", "Cagayan", "Isabela", "Nueva Vizcaya", "Quirino"],
            "REGION III (CENTRAL LUZON)": ["Aurora", "Bataan", "Bulacan", "Nueva Ecija", "Pampanga", "Tarlac", "Zambales"],
            "REGION IV-B (MIMAROPA)": ["Marinduque", "Occidental Mindoro", "Oriental Mindoro", "Palawan", "Romblon"],
            "REGION V (BICOL REGION)": ["Albay", "Camarines Norte", "Camarines Sur", "Catanduanes", "Masbate", "Sorsogon"],
            "REGION VI (WESTERN VISAYAS)": ["Aklan", "Antique", "Capiz", "Guimaras", "Iloilo", "Negros Occidental"],
            "REGION VII (CENTRAL VISAYAS)": ["Bohol", "Cebu", "Negros Oriental", "Siquijor"],
            "REGION VIII (EASTERN VISAYAS)": ["Biliran", "Eastern Samar", "Leyte", "Northern Samar", "Samar", "Southern Leyte"],
            "REGION IX (ZAMBOANGA PENINSULA)": ["Zamboanga del Norte", "Zamboanga del Sur", "Zamboanga Sibugay"],
            "REGION X (NORTHERN MINDANAO)": ["Bukidnon", "Camiguin", "Lanao del Norte", "Misamis Occidental", "Misamis Oriental"],
            "REGION XI (DAVAO REGION)": ["Davao del Norte", "Davao del Sur", "Davao Occidental", "Davao Oriental", "Davao de Oro"],
            "REGION XII (SOCCSKSARGEN)": ["Cotabato", "Sarangani", "South Cotabato", "Sultan Kudarat"],
            "NCR (NATIONAL CAPITAL REGION)": ["Manila", "Quezon City", "Caloocan", "Las Piñas", "Makati", "Malabon", "Mandaluyong", "Marikina", "Muntinlupa", "Navotas", "Parañaque", "Pasay", "Pasig", "Pateros", "San Juan", "Taguig", "Valenzuela"],
            "CAR (CORDILLERA ADMINISTRATIVE REGION)": ["Abra", "Apayao", "Benguet", "Ifugao", "Kalinga", "Mountain Province"],
            "ARMM (AUTONOMOUS REGION IN MUSLIM MINDANAO)": ["Basilan", "Lanao del Sur", "Maguindanao", "Sulu", "Tawi-Tawi"]
        };
        
        function updateProvinces() {
            const regionSelect = document.getElementById('region');
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const selectedRegion = regionSelect.value;
            
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            
            if (selectedRegion && philippineProvinces[selectedRegion]) {
                philippineProvinces[selectedRegion].forEach(province => {
                    const option = document.createElement('option');
                    option.value = province;
                    option.textContent = province;
                    if (province === '<?= htmlspecialchars($student['city_province'] ?? $student['province'] ?? '') ?>') {
                        option.selected = true;
                    }
                    provinceSelect.appendChild(option);
                });
                
                if (provinceSelect.value) {
                    updateCities();
                }
            }
        }
        
        function updateCities() {
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const selectedProvince = provinceSelect.value;
            
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            
            if (selectedProvince && typeof philippineLocations !== 'undefined' && philippineLocations[selectedProvince]) {
                Object.keys(philippineLocations[selectedProvince]).forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    if (city === '<?= htmlspecialchars($student['municipality'] ?? $student['city'] ?? '') ?>') {
                        option.selected = true;
                    }
                    citySelect.appendChild(option);
                });
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateProvinces();
            <?php if (isset($student['city_province']) && $student['city_province']): ?>
            setTimeout(() => {
                updateCities();
            }, 100);
            <?php endif; ?>
            
            // Show popup if there's a message
            <?php if ($message): ?>
            showPopup('<?= $message_type ?>', '<?= addslashes($message) ?>');
            <?php endif; ?>
            
            // Disable form fields if not approved
            <?php if (!$isApproved): ?>
            const form = document.getElementById('profileForm');
            if (form) {
                const inputs = form.querySelectorAll('input:not([readonly]):not([type="file"]), textarea, select, button[type="submit"]');
                inputs.forEach(input => {
                    input.disabled = true;
                    input.style.opacity = '0.6';
                    input.style.cursor = 'not-allowed';
                });
                
                // Disable file input
                const fileInput = form.querySelector('input[type="file"]');
                if (fileInput) {
                    fileInput.disabled = true;
                    fileInput.style.pointerEvents = 'none';
                }
                
                // Disable profile picture upload area
                const uploadArea = document.getElementById('profilePictureUpload');
                if (uploadArea) {
                    uploadArea.style.pointerEvents = 'none';
                    uploadArea.style.opacity = '0.6';
                }
            }
            <?php endif; ?>
        });
        
        // Popup Functions
        function showPopup(type, message) {
            const overlay = document.getElementById('popupOverlay');
            const icon = document.getElementById('popupIcon');
            const title = document.getElementById('popupTitle');
            const messageEl = document.getElementById('popupMessage');
            
            if (!overlay) return;
            
            // Update icon and title based on type
            let iconClass = 'fas fa-info-circle';
            let titleText = 'Notice';
            
            if (type === 'success') {
                iconClass = 'fas fa-check-circle';
                titleText = 'Success!';
            } else if (type === 'error') {
                iconClass = 'fas fa-exclamation-circle';
                titleText = 'Error';
            }
            
            icon.className = 'popup-icon ' + type;
            icon.innerHTML = '<i class="' + iconClass + '"></i>';
            title.textContent = titleText;
            messageEl.textContent = message;
            
            overlay.classList.add('show');
        }
        
        function closePopup() {
            const overlay = document.getElementById('popupOverlay');
            if (overlay) {
                overlay.classList.remove('show');
                // Remove message from URL after closing
                if (window.location.search.includes('msg=')) {
                    const url = new URL(window.location);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, '', url);
                }
            }
        }
        
        function showRestrictionPopup() {
            showPopup('error', 'This action is restricted until your account is approved.');
        }
        
        // Close popup when clicking outside
        document.getElementById('popupOverlay')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closePopup();
            }
        });
        
        // Sidebar toggle functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const container = document.querySelector('.container');
            const isMobile = window.innerWidth <= 768;
            
            if (!sidebar) {
                console.error('Sidebar element not found');
                return;
            }
            
            const isHidden = sidebar.classList.contains('hidden');
            const isActive = sidebar.classList.contains('active');
            
            if (isMobile) {
                // Mobile behavior
                if (isHidden || !isActive) {
                    // Show sidebar
                    sidebar.classList.remove('hidden');
                    sidebar.classList.add('active');
                    if (overlay) overlay.classList.add('active');
                    if (toggleBtn) toggleBtn.classList.add('hide');
                    if (container) container.classList.remove('expanded');
                    document.body.classList.add('sidebar-open');
                } else {
                    // Hide sidebar
                    sidebar.classList.remove('active');
                    sidebar.classList.add('hidden');
                    if (overlay) overlay.classList.remove('active');
                    if (toggleBtn) toggleBtn.classList.remove('hide');
                    if (container) container.classList.add('expanded');
                    document.body.classList.remove('sidebar-open');
                }
            } else {
                // Desktop behavior
                if (isHidden) {
                    // Show sidebar
                    sidebar.classList.remove('hidden');
                    document.body.classList.remove('sidebar-closed');
                    if (toggleBtn) toggleBtn.style.display = 'none';
                    if (container) container.classList.remove('expanded');
                } else {
                    // Hide sidebar
                    sidebar.classList.add('hidden');
                    document.body.classList.add('sidebar-closed');
                    if (container) container.classList.add('expanded');
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
            }
        }
        
        function hideSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const container = document.querySelector('.container');
            const isMobile = window.innerWidth <= 768;
            
            if (sidebar) {
                sidebar.classList.remove('active');
                sidebar.classList.add('hidden');
                if (overlay) overlay.classList.remove('active');
                if (container) container.classList.add('expanded');
                
                // Remove body scroll lock
                document.body.classList.remove('sidebar-open');
                
                // Add sidebar-closed class on desktop
                if (!isMobile) {
                    document.body.classList.add('sidebar-closed');
                }
                
                // Show toggle button
                if (toggleBtn) {
                    if (isMobile) {
                        toggleBtn.classList.remove('hide');
                    } else {
                        toggleBtn.style.display = 'block';
                    }
                }
            }
        }
        
        // Initialize sidebar and add event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const container = document.querySelector('.container');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            
            // Close sidebar when nav items are clicked on mobile
            const navItems = document.querySelectorAll('.sidebar .nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        setTimeout(() => {
                            if (sidebar) {
                                sidebar.classList.remove('active');
                                sidebar.classList.add('hidden');
                            }
                            if (overlay) overlay.classList.remove('active');
                            if (container) container.classList.add('expanded');
                            if (toggleBtn) toggleBtn.classList.remove('hide');
                            document.body.classList.remove('sidebar-open');
                            document.body.classList.remove('sidebar-closed');
                        }, 100);
                    }
                });
            });
            
            // Hide sidebar when clicking outside (desktop)
            document.addEventListener('click', function(event) {
                // Don't hide if clicking on sidebar, toggle button, or overlay
                if (sidebar && sidebar.contains(event.target)) {
                    return;
                }
                if (toggleBtn && (toggleBtn.contains(event.target) || toggleBtn === event.target)) {
                    return; // Let toggleSidebar() handle it
                }
                if (overlay && event.target === overlay) {
                    return;
                }
                
                // Hide sidebar if it's visible (but not if toggle button was just clicked)
                if (sidebar && !sidebar.classList.contains('hidden') && !sidebar.classList.contains('active')) {
                    // On desktop, hide sidebar
                    if (window.innerWidth > 768) {
                        sidebar.classList.add('hidden');
                        document.body.classList.add('sidebar-closed');
                        if (container) container.classList.add('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                } else if (sidebar && sidebar.classList.contains('active')) {
                    // On mobile, hide sidebar
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('active');
                        sidebar.classList.add('hidden');
                        if (overlay) overlay.classList.remove('active');
                        if (container) container.classList.add('expanded');
                        if (toggleBtn) toggleBtn.classList.remove('hide');
                        document.body.classList.remove('sidebar-open');
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    // Desktop: sidebar visible by default (unless user hid it)
                    if (sidebar && !sidebar.classList.contains('hidden')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-closed');
                        if (toggleBtn) toggleBtn.style.display = 'none';
                    } else if (sidebar && sidebar.classList.contains('hidden')) {
                        document.body.classList.add('sidebar-closed');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (container && !sidebar.classList.contains('hidden')) {
                        container.classList.remove('expanded');
                    }
                    document.body.classList.remove('sidebar-open');
                } else {
                    // Mobile: sidebar hidden by default
                    if (sidebar) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (container) container.classList.add('expanded');
                    if (toggleBtn) {
                        toggleBtn.style.display = 'flex';
                        toggleBtn.classList.remove('hide');
                    }
                    document.body.classList.remove('sidebar-open');
                    document.body.classList.remove('sidebar-closed');
                }
            });
            
            // Initialize sidebar state based on screen size
            if (window.innerWidth <= 768) {
                // Mobile: sidebar hidden by default
                if (sidebar) {
                    sidebar.classList.add('hidden');
                    sidebar.classList.remove('active');
                }
                if (container) container.classList.add('expanded');
                if (toggleBtn) {
                    toggleBtn.style.display = 'flex';
                    toggleBtn.classList.remove('hide');
                }
                document.body.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-closed');
            } else {
                // Desktop: sidebar visible by default
                if (sidebar) {
                    sidebar.classList.remove('hidden');
                    sidebar.classList.remove('active');
                }
                document.body.classList.remove('sidebar-closed');
                if (container) container.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        });
        
        // Close popup with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePopup();
            }
        });
    </script>
</body>
</html>
