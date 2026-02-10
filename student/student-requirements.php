<?php
// Student Requirements Submission Page
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

// Get admission application
$admissionInfo = null;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM admission_applications 
        WHERE student_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $admissionInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Admission table might not exist
}

// Handle requirement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_requirement'])) {
    $requirement_id = $_POST['requirement_id'];
    $submission_notes = trim($_POST['submission_notes'] ?? '');
    
    if (!$admissionInfo) {
        $message = 'No application found. Please complete your registration first.';
        $message_type = 'danger';
    } else {
        // Handle file upload
        $file_path = null;
        if (isset($_FILES['requirement_file']) && $_FILES['requirement_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/requirements/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['requirement_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'req_' . $admissionInfo['id'] . '_' . $requirement_id . '_' . time() . '.' . $file_extension;
            $file_path = 'uploads/requirements/' . $file_name;
            $full_path = $upload_dir . $file_name;
            
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                if (move_uploaded_file($_FILES['requirement_file']['tmp_name'], $full_path)) {
                    // File uploaded successfully
                } else {
                    $message = 'Error uploading file.';
                    $message_type = 'danger';
                }
            } else {
                $message = 'Invalid file type. Allowed: ' . implode(', ', $allowed_extensions);
                $message_type = 'danger';
            }
        }
        
        if (!$message) {
            try {
                // Check if submission already exists
                $stmt = $pdo->prepare("SELECT id FROM application_requirement_submissions WHERE application_id = ? AND requirement_id = ?");
                $stmt->execute([$admissionInfo['id'], $requirement_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Update existing submission
                    $stmt = $pdo->prepare("
                        UPDATE application_requirement_submissions 
                        SET file_path = ?, submission_notes = ?, status = 'pending', submitted_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$file_path, $submission_notes, $existing['id']]);
                } else {
                    // Insert new submission
                    $stmt = $pdo->prepare("
                        INSERT INTO application_requirement_submissions 
                        (application_id, requirement_id, file_path, submission_notes, status, submitted_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$admissionInfo['id'], $requirement_id, $file_path, $submission_notes]);
                }
                
                $message = 'Requirement submitted successfully! It will be reviewed by the administration.';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Error submitting requirement: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

// Get all requirements
$requirements = [];
try {
    $requirements = $pdo->query("SELECT * FROM application_requirements ORDER BY requirement_name")->fetchAll();
} catch (PDOException $e) {
    // Table might not exist
}

// Get submitted requirements
$submitted_requirements = [];
if ($admissionInfo) {
    try {
        $stmt = $pdo->prepare("
            SELECT ars.*, ar.requirement_name, ar.requirement_description
            FROM application_requirement_submissions ars
            JOIN application_requirements ar ON ars.requirement_id = ar.id
            WHERE ars.application_id = ?
        ");
        $stmt->execute([$admissionInfo['id']]);
        $submitted_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist
    }
}

// Create a map of requirement_id => submission
$submission_map = [];
foreach ($submitted_requirements as $sub) {
    $submission_map[$sub['requirement_id']] = $sub;
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    redirectTo('auth/student-login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Requirements - Student Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
            margin: 0 15px 15px 15px;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s;
        }
        
        .upgrade-btn:hover {
            background: #f5f5f5;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                margin-left: 250px;
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
            
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
                width: 100%;
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
        
        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        
        .profile-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
            font-size: 1.2rem;
            transition: transform 0.2s;
        }
        
        .profile-icon:hover {
            transform: scale(1.05);
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #ffe0e0 0%, #ffcccc 100%);
            border-radius: 12px;
            padding: 25px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .welcome-content h2 {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .welcome-content p {
            color: #666;
            margin: 0;
            line-height: 1.6;
            font-size: 0.95rem;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ffe0e0;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #a11c27;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .requirement-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            background: #fafafa;
        }
        
        .requirement-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .requirement-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .requirement-status {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-not-submitted {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .requirement-description {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Montserrat', sans-serif;
        }
        
        .btn-primary {
            background: #a11c27;
            color: white;
        }
        
        .btn-primary:hover {
            background: #8b1620;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .file-info {
            margin-top: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.9rem;
            border-left: 3px solid #a11c27;
        }
        
        .file-info a {
            color: #a11c27;
            text-decoration: none;
            font-weight: 600;
        }
        
        .file-info a:hover {
            text-decoration: underline;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .info-banner {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .info-banner i {
            font-size: 1.5rem;
            color: #ffc107;
            margin-top: 3px;
        }
        
        .info-banner-content h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .info-banner-content p {
            color: #666;
            line-height: 1.6;
            margin: 0;
        }
        
        small {
            color: #666;
            font-size: 0.85rem;
        }
        
        /* Search and Filter Styles */
        .search-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.2s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .filter-select {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 150px;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .no-results i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
            display: block;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .search-filter-container {
                flex-direction: column;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .filter-select {
                width: 100%;
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
                <a href="student-requirements.php" class="nav-item active">
                    <i class="fas fa-file-upload"></i>
                    <span>Requirements</span>
                </a>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <form method="POST" style="margin: 0 15px 20px 15px;">
                <button type="submit" name="logout" class="upgrade-btn" style="background: rgba(220, 53, 69, 0.8); color: white;">Logout</button>
            </form>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <h1 class="page-title">Requirements</h1>
            <div class="profile-icon">
                <i class="fas fa-user"></i>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <div class="welcome-banner">
            <div class="welcome-content">
                <h2>Welcome, <?php if ($student): ?><?= htmlspecialchars(strtoupper($student['first_name'])) ?><?php else: ?>Student<?php endif; ?>!</h2>
                <p>Please submit all required documents for your application to be processed.</p>
            </div>
        </div>
        
        <?php if (!$admissionInfo): ?>
            <div class="card">
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div class="info-banner-content">
                        <h3>No Application Found</h3>
                        <p>Please complete your registration first before submitting requirements.</p>
                    </div>
                </div>
            </div>
        <?php elseif (empty($requirements)): ?>
            <div class="card">
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div class="info-banner-content">
                        <h3>No Requirements Available</h3>
                        <p>No requirements are currently required. Please check back later.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-file-alt"></i>
                        Required Documents
                    </h2>
                </div>
                
                <div class="search-filter-container">
                    <div class="search-box">
                        <input type="text" id="requirementSearch" placeholder="Search requirements by name..." onkeyup="filterRequirements()">
                        <i class="fas fa-search"></i>
                    </div>
                    <select class="filter-select" id="statusFilter" onchange="filterRequirements()">
                        <option value="">All Status</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="rejected">Rejected</option>
                        <option value="not_submitted">Not Submitted</option>
                    </select>
                </div>
                
                <div id="requirementsList">
                <?php foreach ($requirements as $req): 
                    $submission = $submission_map[$req['id']] ?? null;
                    $status = $submission ? $submission['status'] : 'not_submitted';
                ?>
                    <div class="requirement-item" 
                         data-requirement-name="<?= strtolower(htmlspecialchars($req['requirement_name'])) ?>"
                         data-status="<?= $status ?>">
                        <div class="requirement-header">
                            <div class="requirement-name">
                                <?= htmlspecialchars($req['requirement_name']) ?>
                                <?php if ($req['is_required']): ?>
                                    <span style="color: #dc3545;">*</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($status === 'approved'): ?>
                                    <span class="requirement-status status-approved">
                                        <i class="fas fa-check-circle"></i> Approved
                                    </span>
                                <?php elseif ($status === 'rejected'): ?>
                                    <span class="requirement-status status-rejected">
                                        <i class="fas fa-times-circle"></i> Rejected
                                    </span>
                                <?php elseif ($status === 'pending'): ?>
                                    <span class="requirement-status status-pending">
                                        <i class="fas fa-clock"></i> Pending Review
                                    </span>
                                <?php else: ?>
                                    <span class="requirement-status status-not-submitted">
                                        Not Submitted
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($req['requirement_description']): ?>
                            <div class="requirement-description">
                                <?= htmlspecialchars($req['requirement_description']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($submission && $submission['file_path']): ?>
                            <div class="file-info">
                                <strong>Submitted File:</strong> 
                                <a href="../<?= htmlspecialchars($submission['file_path']) ?>" target="_blank">
                                    <i class="fas fa-file"></i> View File
                                </a>
                                <br>
                                <small>Submitted: <?= date('M d, Y h:i A', strtotime($submission['submitted_at'])) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($submission && $submission['review_notes']): ?>
                            <div class="file-info" style="margin-top: 10px;">
                                <strong>Review Notes:</strong> <?= htmlspecialchars($submission['review_notes']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="requirement_id" value="<?= $req['id'] ?>">
                            
                            <div class="form-group">
                                <label for="requirement_file_<?= $req['id'] ?>">
                                    Upload Document <?= $req['is_required'] ? '<span style="color: #dc3545;">(Required)</span>' : '(Optional)' ?>
                                </label>
                                <input type="file" 
                                       class="form-control" 
                                       id="requirement_file_<?= $req['id'] ?>" 
                                       name="requirement_file" 
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                       <?= $status === 'approved' ? 'disabled' : '' ?>>
                                <small>Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max 10MB)</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="submission_notes_<?= $req['id'] ?>">Notes (Optional)</label>
                                <textarea class="form-control" 
                                          id="submission_notes_<?= $req['id'] ?>" 
                                          name="submission_notes" 
                                          placeholder="Add any additional notes about this document..."
                                          <?= $status === 'approved' ? 'disabled' : '' ?>><?= $submission ? htmlspecialchars($submission['submission_notes']) : '' ?></textarea>
                            </div>
                            
                            <?php if ($status !== 'approved'): ?>
                                <button type="submit" name="submit_requirement" class="btn btn-primary">
                                    <i class="fas fa-upload"></i> <?= $submission ? 'Update Submission' : 'Submit' ?>
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>
                <div id="noRequirementResults" class="no-results" style="display: none;">
                    <i class="fas fa-search"></i>
                    <p>No requirements found matching your search</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function filterRequirements() {
            const searchTerm = document.getElementById('requirementSearch').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const requirementItems = document.querySelectorAll('#requirementsList .requirement-item');
            const noResults = document.getElementById('noRequirementResults');
            let visibleCount = 0;
            
            requirementItems.forEach(item => {
                const requirementName = item.getAttribute('data-requirement-name') || '';
                const status = item.getAttribute('data-status') || '';
                
                const matchesSearch = !searchTerm || requirementName.includes(searchTerm);
                const matchesStatus = !statusFilter || status === statusFilter;
                
                if (matchesSearch && matchesStatus) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || statusFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
    
    <script>
        // Sidebar toggle functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
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
                    if (mainContent) mainContent.classList.remove('expanded');
                    document.body.classList.add('sidebar-open');
                } else {
                    // Hide sidebar
                    sidebar.classList.remove('active');
                    sidebar.classList.add('hidden');
                    if (overlay) overlay.classList.remove('active');
                    if (toggleBtn) toggleBtn.classList.remove('hide');
                    if (mainContent) mainContent.classList.add('expanded');
                    document.body.classList.remove('sidebar-open');
                }
            } else {
                // Desktop behavior
                if (isHidden) {
                    // Show sidebar
                    sidebar.classList.remove('hidden');
                    if (toggleBtn) toggleBtn.style.display = 'none';
                    if (mainContent) mainContent.classList.remove('expanded');
                } else {
                    // Hide sidebar
                    sidebar.classList.add('hidden');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
            }
        }
        
        function hideSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
            const isMobile = window.innerWidth <= 768;
            
            if (sidebar) {
                sidebar.classList.remove('active');
                sidebar.classList.add('hidden');
                if (overlay) overlay.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                
                // Remove body scroll lock
                document.body.classList.remove('sidebar-open');
                
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
            const mainContent = document.querySelector('.main-content');
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
                            if (mainContent) mainContent.classList.add('expanded');
                            if (toggleBtn) toggleBtn.classList.remove('hide');
                            document.body.classList.remove('sidebar-open');
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
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                } else if (sidebar && sidebar.classList.contains('active')) {
                    // On mobile, hide sidebar
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('active');
                        sidebar.classList.add('hidden');
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
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
                        if (toggleBtn) toggleBtn.style.display = 'none';
                    } else if (sidebar && sidebar.classList.contains('hidden')) {
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent && !sidebar.classList.contains('hidden')) {
                        mainContent.classList.remove('expanded');
                    }
                    document.body.classList.remove('sidebar-open');
                } else {
                    // Mobile: sidebar hidden by default
                    if (sidebar) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) {
                        toggleBtn.style.display = 'flex';
                        toggleBtn.classList.remove('hide');
                    }
                    document.body.classList.remove('sidebar-open');
                }
            });
            
            // Initialize sidebar state based on screen size
            if (window.innerWidth <= 768) {
                // Mobile: sidebar hidden by default
                if (sidebar) {
                    sidebar.classList.add('hidden');
                    sidebar.classList.remove('active');
                }
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) {
                    toggleBtn.style.display = 'flex';
                    toggleBtn.classList.remove('hide');
                }
                document.body.classList.remove('sidebar-open');
            } else {
                // Desktop: sidebar visible by default
                if (sidebar) {
                    sidebar.classList.remove('hidden');
                    sidebar.classList.remove('active');
                }
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        });
    </script>
</body>
</html>
