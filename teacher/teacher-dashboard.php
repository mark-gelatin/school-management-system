<?php
// Teacher Dashboard - Manage Student Grades
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
require_once getAbsolutePath('backend/includes/grade_converter.php');

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    redirectTo('auth/staff-login.php');
}

// Update session timestamp to keep it alive
$_SESSION['last_activity'] = time();

$teacherId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
}

// Check if we should show welcome banner (set during login)
$showWelcome = isset($_SESSION['show_welcome']) && $_SESSION['show_welcome'] === true;
// Clear the flag after checking (so it only shows once per login)
if ($showWelcome) {
    unset($_SESSION['show_welcome']);
}

// Get teacher information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving teacher information: ' . $e->getMessage();
    $message_type = 'error';
}

// Get only subjects assigned to this teacher (handled courses)
// Initialize as empty array to prevent undefined variable errors
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.* 
        FROM subjects s
        INNER JOIN section_schedules ss ON s.id = ss.subject_id
        INNER JOIN sections sec ON ss.section_id = sec.id
        WHERE ss.teacher_id = ? AND sec.status = 'active'
        ORDER BY s.name
    ");
    $stmt->execute([$teacherId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fallback: try teacher_subjects table if section_schedules doesn't work
    if (empty($subjects)) {
        try {
            $stmt = $pdo->prepare("
                SELECT s.* 
                FROM subjects s
                INNER JOIN teacher_subjects ts ON s.id = ts.subject_id
                WHERE ts.teacher_id = ?
                ORDER BY s.name
            ");
            $stmt->execute([$teacherId]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // If teacher_subjects table doesn't exist, keep empty array
            error_log("Error fetching teacher subjects from teacher_subjects: " . $e2->getMessage());
        }
    }
} catch (PDOException $e) {
    // Subjects table might not exist or query failed
    error_log("Error fetching teacher subjects: " . $e->getMessage());
    // Keep $subjects as empty array
    $subjects = [];
}

// Ensure $subjects is always an array (safety check)
if (!is_array($subjects)) {
    $subjects = [];
}


// Get teacher's classrooms
$classrooms = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM classrooms WHERE teacher_id = ? ORDER BY name");
    $stmt->execute([$teacherId]);
    $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Classroom table might not exist
    $classrooms = [];
}

// Initialize students array (will be populated dynamically via AJAX)
$students = [];

// Get recent grades
$recentGrades = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.*, u.first_name, u.last_name, s.name as subject_name, c.name as classroom_name
        FROM grades g
        JOIN users u ON g.student_id = u.id
        LEFT JOIN subjects s ON g.subject_id = s.id
        LEFT JOIN classrooms c ON g.classroom_id = c.id
        WHERE g.teacher_id = ?
        ORDER BY g.graded_at DESC
        LIMIT 10
    ");
    $stmt->execute([$teacherId]);
    $recentGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error - ensure array is set
    $recentGrades = [];
    error_log("Error fetching recent grades: " . $e->getMessage());
}

// Ensure all arrays are properly initialized (safety checks)
if (!is_array($recentGrades)) {
    $recentGrades = [];
}
if (!is_array($classrooms)) {
    $classrooms = [];
}
if (!is_array($students)) {
    $students = [];
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
    <title>Teacher Dashboard - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
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
        
        /* Sidebar */
        .sidebar {
            width: 300px;
            background: linear-gradient(180deg, #a11c27 0%, #b31310 100%);
            height: 100vh;
            padding-top: 25px;
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        visibility 0.35s,
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
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
            padding: 0 20px 15px 20px;
            position: relative;
            min-width: 0;
            flex-shrink: 0;
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
            max-width: 50px;
            display: block;
        }
        
        .school-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            text-align: left;
            white-space: nowrap;
            overflow: visible;
            text-overflow: clip;
            min-width: 0;
            flex: 1;
            word-break: keep-all;
            letter-spacing: 0.3px;
            display: block;
            width: 100%;
            max-width: 100%;
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
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.15);
        }
        
        .nav-item i {
            width: 18px;
            text-align: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .nav-item span:not(.nav-badge) {
            flex: 1;
            font-size: 0.95rem;
        }
        
        .nav-badge {
            margin-left: auto;
            background: rgba(255,255,255,0.25);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .sidebar-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            padding-bottom: 20px;
            -webkit-overflow-scrolling: touch;
            min-width: 0;
        }
        
        /* User Profile in Sidebar */
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
            margin-bottom: 12px;
            flex-shrink: 0;
            font-weight: 700;
        }
        
        .sidebar .user-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
            text-align: center;
            color: white;
        }
        
        .sidebar .user-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-align: center;
            color: rgba(255,255,255,0.95);
            font-weight: 500;
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
            width: 100%;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s;
        }
        
        .upgrade-btn:hover {
            background: #f5f5f5;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 300px;
            flex: 1;
            padding: 30px;
            width: calc(100% - 300px);
            max-width: 100%;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        /* Top Header */
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
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 20px;
            margin-left: auto;
            flex-shrink: 0;
        }
        
        .profile-dropdown {
            position: relative;
            margin-left: auto;
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
        
        .profile-dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
            overflow: hidden;
        }
        
        /* Ensure dropdown doesn't go off-screen on mobile */
        @media (max-width: 768px) {
            .profile-dropdown-menu {
                right: 0;
                left: auto;
                min-width: 180px;
            }
        }
        
        @media (max-width: 480px) {
            .profile-dropdown-menu {
                right: 0;
                left: auto;
                min-width: 160px;
            }
        }
        
        .profile-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .profile-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s;
            font-size: 0.95rem;
        }
        
        .profile-dropdown-item:hover {
            background: #f5f5f5;
        }
        
        .profile-dropdown-item i {
            width: 18px;
            text-align: center;
            color: #a11c27;
        }
        
        .profile-dropdown-item:first-child {
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        
        .profile-dropdown-item:last-child {
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        
        /* Welcome Banner Container */
        .welcome-banner-container {
            margin-bottom: 30px;
            transition: opacity 0.3s ease, margin 0.3s ease, max-height 0.3s ease;
            overflow: hidden;
        }
        
        .welcome-banner-container.hidden {
            opacity: 0;
            margin-bottom: 0;
            max-height: 0;
            padding: 0;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #ffe0e0 0%, #ffcccc 100%);
            border-radius: 12px;
            padding: 25px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .welcome-content {
            width: 100%;
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
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }
        
        /* Content Grid - Changed to Flexbox for better space distribution */
        .content-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            align-items: stretch;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
        }
        
        /* Recent Grades Card - Expand to fill space */
        .card.recent-grades-card {
            flex: 1 1 100%;
            min-width: 300px;
            min-height: 400px;
            max-height: calc(100vh - 400px);
            width: 100%;
        }
        
        /* Scrollable grades container */
        .grades-table-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            min-height: 200px;
            margin-top: 20px;
        }
        
        /* Ensure search filter doesn't interfere with flex layout */
        .card.recent-grades-card .search-filter-container {
            flex-shrink: 0;
            margin-bottom: 0;
        }
        
        .grades-table-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .grades-table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .grades-table-container::-webkit-scrollbar-thumb {
            background: #a11c27;
            border-radius: 4px;
        }
        
        .grades-table-container::-webkit-scrollbar-thumb:hover {
            background: #8a1620;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-shrink: 0;
        }
        
        .card.recent-grades-card .card-header {
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
            border-bottom: 3px solid #a11c27;
            padding-bottom: 10px;
            margin: 0;
        }
        
        .full-width {
            flex: 1 1 100%;
            width: 100%;
        }
        
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
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #a11c27;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn {
            background: #a11c27;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background: #b31310;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
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
        
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .grades-table th,
        .grades-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .grades-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .grades-table td {
            color: #666;
            font-size: 0.9rem;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            font-style: italic;
            padding: 40px;
        }
        
        .classroom-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #a11c27;
            margin-bottom: 15px;
        }
        
        .classroom-card h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .classroom-card p {
            color: #666;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .classroom-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: #999;
        }
        
        @media (max-width: 1024px) {
            .sidebar {
                width: 280px;
            }
            
            .main-content {
                margin-left: 280px;
                width: calc(100% - 280px);
                padding: 20px;
            }
            
            .school-name {
                font-size: 1rem;
            }
            
            .content-grid {
                flex-direction: column;
            }
            
            .card.recent-grades-card {
                flex: 1 1 100%;
                width: 100%;
                min-height: 300px;
                max-height: calc(100vh - 350px);
            }
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none; /* Hidden by default on desktop */
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
        
        /* Show toggle button on mobile */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex !important; /* Force display on mobile */
            }
            
            .mobile-menu-toggle.hide {
                display: none !important; /* Hide when sidebar is open */
            }
        }
        
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
        
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex !important; /* Force display on mobile */
            }
            
            .mobile-menu-toggle.hide {
                display: none !important; /* Hide when sidebar is open */
            }
            
            .mobile-menu-toggle:hover {
                background: #b31310;
                transform: scale(1.05);
                box-shadow: 0 3px 12px rgba(0,0,0,0.2);
            }
            
            .mobile-menu-toggle:active {
                transform: scale(0.95);
            }

            /* Mobile sidebar behavior */
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            visibility 0.35s;
                position: fixed;
                z-index: 1000;
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
            
            .school-name {
                font-size: 1rem;
            }
            
            .card.recent-grades-card {
                min-height: 300px;
                max-height: calc(100vh - 250px);
                height: auto;
            }
            
            .grades-table-container {
                max-height: calc(100vh - 400px);
            }
            
            /* Prevent body scroll when sidebar is open on mobile */
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
                transition: none;
            }
        }
        
        /* Responsive adjustments for toggle button */
        @media (max-width: 480px) {
            .sidebar {
                width: 100%;
                max-width: 280px;
            }
            
            .mobile-menu-toggle {
                padding: 7px 10px;
                font-size: 0.9rem;
                min-width: 36px;
                min-height: 36px;
                top: 12px;
                left: 12px;
            }
            
            .school-name {
                font-size: 0.95rem;
            }
            
            .logo {
                padding: 0 15px 15px 15px;
            }
            
            .logo img {
                height: 45px;
                max-width: 45px;
            }
            
            .card.recent-grades-card {
                flex: 1 1 100%;
                width: 100%;
                min-height: 250px;
                max-height: calc(100vh - 200px);
                padding: 15px;
            }
            
            .grades-table-container {
                max-height: calc(100vh - 350px);
            }
            
            .grades-table {
                font-size: 0.85rem;
            }
            
            .grades-table th,
            .grades-table td {
                padding: 10px 8px;
            }
        }
        
        @media (max-width: 360px) {
            .school-name {
                font-size: 0.9rem;
                letter-spacing: 0.2px;
            }
            
            .logo {
                gap: 10px;
                padding: 0 12px 15px 12px;
            }
            
            .logo img {
                height: 40px;
                max-width: 40px;
            }
        }
            
            /* Prevent body scroll when sidebar is open on mobile */
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
                transition: none;
            }
            
            .top-header {
                flex-direction: row;
                gap: 15px;
                padding: 15px 20px;
                align-items: center;
            }
            
            .page-title {
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
                font-size: 1.5rem;
            }
            
            .header-actions {
                flex-wrap: nowrap;
                width: auto;
                margin-left: auto;
                justify-content: flex-end;
            }
            
            .profile-dropdown {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }
            
            /* Progressive font scaling - maintain table structure */
            .table {
                font-size: 0.8rem;
                width: 100%;
                table-layout: auto;
            }
            
            .table th {
                font-size: 0.75rem;
                padding: 8px 6px;
            }
            
            .table td {
                font-size: 0.75rem;
                padding: 8px 6px;
            }
            
            .table .badge {
                font-size: 0.7rem;
                padding: 3px 6px;
            }
            
            .table .btn-sm {
                padding: 3px 6px;
                font-size: 0.7rem;
            }
            
            .card {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
                padding-top: 70px;
            }
            
            .top-header {
                padding: 12px 15px;
                flex-direction: row;
                align-items: center;
            }
            
            .page-title {
                font-size: 1.2rem;
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
                white-space: nowrap;
            }
            
            .header-actions {
                margin-left: auto;
                justify-content: flex-end;
            }
            
            .profile-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
            
            .card {
                padding: 12px;
            }
            
            /* Further font reduction for small screens */
            .table {
                font-size: 0.7rem;
                width: 100%;
            }
            
            .table th {
                font-size: 0.65rem;
                padding: 6px 4px;
            }
            
            .table td {
                font-size: 0.65rem;
                padding: 6px 4px;
            }
            
            .table .badge {
                font-size: 0.65rem;
                padding: 2px 5px;
            }
            
            .table .btn-sm {
                padding: 2px 5px;
                font-size: 0.65rem;
            }
            
            .btn {
                padding: 6px 10px;
                font-size: 0.8rem;
            }
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
    </style>
    
    <!-- CRITICAL: Define functions in head so they're available when onclick handlers are parsed -->
    <script>
        // Immediately define toggleProfileDropdown in global scope
        // This MUST be executed before any HTML with onclick handlers
        (function() {
            'use strict';
            
            // Define toggleProfileDropdown function
            window.toggleProfileDropdown = function(e) {
                if (e) {
                    e.stopPropagation(); // Prevent event from bubbling to welcome banner handler
                }
                const dropdown = document.getElementById('profileDropdown');
                if (dropdown) {
                    const isShowing = dropdown.classList.contains('show');
                    dropdown.classList.toggle('show');
                    console.log('Profile dropdown toggled', !isShowing);
                } else {
                    console.error('Profile dropdown element not found');
                }
            };
            
            // Define toggleSidebar function early
            window.toggleSidebar = function() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const toggleBtn = document.getElementById('mobileMenuToggle');
                const mainContent = document.querySelector('.main-content');
                
                if (!sidebar) {
                    console.warn('Sidebar element not found');
                    return;
                }
                
                const isHidden = sidebar.classList.contains('hidden');
                const isActive = sidebar.classList.contains('active');
                const isMobile = window.innerWidth <= 768;
                
                if (isHidden) {
                    sidebar.classList.remove('hidden');
                    if (isMobile) {
                        sidebar.classList.add('active');
                        if (overlay) overlay.classList.add('active');
                        if (toggleBtn) toggleBtn.classList.add('hide');
                        document.body.classList.add('sidebar-open');
                    } else {
                        if (toggleBtn) toggleBtn.style.display = 'none';
                    }
                    if (mainContent) mainContent.classList.remove('expanded');
                } else {
                    if (isMobile) {
                        const newActiveState = !isActive;
                        sidebar.classList.toggle('active', newActiveState);
                        if (overlay) overlay.classList.toggle('active', newActiveState);
                        if (toggleBtn) toggleBtn.classList.toggle('hide', newActiveState);
                        if (mainContent) {
                            if (newActiveState) {
                                mainContent.classList.remove('expanded');
                            } else {
                                mainContent.classList.add('expanded');
                            }
                        }
                        if (newActiveState) {
                            document.body.classList.add('sidebar-open');
                        } else {
                            document.body.classList.remove('sidebar-open');
                        }
                    } else {
                        sidebar.classList.add('hidden');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                        document.body.classList.remove('sidebar-open');
                    }
                }
            };
            
            console.log('Global functions (toggleProfileDropdown, toggleSidebar) defined in head');
        })();
    </script>
</head>
<body>
    <?php 
    $currentPage = 'dashboard';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <h1 class="page-title">Teacher Dashboard</h1>
            <div class="header-actions">
                <div class="profile-dropdown">
                    <div class="profile-icon" onclick="toggleProfileDropdown(event)">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-dropdown-menu" id="profileDropdown">
                        <a href="teacher-profile.php" class="profile-dropdown-item">
                            <i class="fas fa-user-edit"></i>
                            <span>Update Profile</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Banner -->
        <div class="welcome-banner-container" id="welcomeBannerContainer">
            <div class="welcome-banner" id="welcomeBanner">
                <div class="welcome-content">
                    <h2>Welcome back, <?php if ($teacher): ?><?= htmlspecialchars($teacher['first_name']) ?><?php else: ?>Teacher<?php endif; ?>!</h2>
                    <p>Manage your classrooms, students, and grades efficiently.</p>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= count($classrooms ?? []) ?></div>
                    <div class="stat-label">Classrooms</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= count($students ?? []) ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= is_array($subjects) ? count($subjects) : 0 ?></div>
                    <div class="stat-label">Handled Courses</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= is_array($recentGrades) ? count($recentGrades) : 0 ?></div>
                    <div class="stat-label">Recent Grades</div>
                </div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Recent Grades -->
            <div class="card recent-grades-card">
                <div class="card-header">
                    <h2 class="card-title">Recent Grades</h2>
                </div>
                
                <?php if (!empty($recentGrades)): ?>
                    <div class="search-filter-container">
                        <div class="search-box">
                            <input type="text" id="recentGradeSearch" placeholder="Search by student or subject..." onkeyup="filterRecentGrades()">
                            <i class="fas fa-search"></i>
                        </div>
                        <select class="filter-select" id="recentGradeTypeFilter" onchange="filterRecentGrades()">
                            <option value="">All Grades</option>
                            <option value="final">Final</option>
                        </select>
                    </div>
                    
                    <div class="grades-table-container">
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Type</th>
                                    <th>Grade</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="recentGradesTableBody">
                                <?php foreach ($recentGrades as $grade): 
                                    $phGrade = scoreToPhilippineGrade($grade['grade'], $grade['max_points'] ?? 100);
                                    $badgeClass = getPhilippineGradeBadgeClass($phGrade);
                                ?>
                                    <tr class="recent-grade-row" 
                                        data-student-name="<?= strtolower(htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name'])) ?>"
                                        data-subject-name="<?= strtolower(htmlspecialchars($grade['subject_name'] ?? 'N/A')) ?>"
                                        data-grade-type="<?= strtolower(htmlspecialchars($grade['grade_type'] ?? '')) ?>">
                                        <td><?= htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name']) ?></td>
                                        <td><?= htmlspecialchars($grade['subject_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php 
                                            $gradeType = $grade['grade_type'] ?? '';
                                            $approvalStatus = $grade['approval_status'] ?? 'pending';
                                            
                                            if (!empty($gradeType)) {
                                                // Show approval status badge
                                                if ($gradeType === 'final') {
                                                    $displayType = 'Final';
                                                    if ($approvalStatus === 'approved' || $approvalStatus === 'locked') {
                                                        $displayType .= ' <span class="badge bg-success" style="font-size: 0.7em; margin-left: 5px;">Approved</span>';
                                                    } elseif ($approvalStatus === 'submitted') {
                                                        $displayType .= ' <span class="badge bg-warning" style="font-size: 0.7em; margin-left: 5px;">Pending</span>';
                                                    } elseif ($approvalStatus === 'rejected') {
                                                        $displayType .= ' <span class="badge bg-danger" style="font-size: 0.7em; margin-left: 5px;">Rejected</span>';
                                                    }
                                                    echo $displayType;
                                                } else {
                                                    // Legacy grade types (should not appear in new system)
                                                    echo ucfirst($gradeType);
                                                }
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="grade-badge <?= $badgeClass ?>">
                                                <strong><?= formatPhilippineGrade($phGrade) ?></strong>
                                                <small style="display: block; font-size: 0.75rem; opacity: 0.8;">
                                                    (<?= number_format($grade['grade'], 2) ?>/<?= number_format($grade['max_points'] ?? 100, 2) ?>)
                                                </small>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($grade['graded_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="noRecentGradeResults" class="no-results" style="display: none;">
                        <i class="fas fa-search"></i>
                        <p>No grades found matching your search</p>
                    </div>
                <?php else: ?>
                    <div class="no-data" style="flex: 1; display: flex; align-items: center; justify-content: center;">No grades added yet</div>
                <?php endif; ?>
            </div>
            
            <!-- My Classrooms -->
            <div class="card full-width">
                <div class="card-header">
                    <h2 class="card-title">My Classrooms</h2>
                </div>
                <?php if (!empty($classrooms)): ?>
                    <div class="search-filter-container">
                        <div class="search-box">
                            <input type="text" id="classroomSearch" placeholder="Search classrooms by name..." onkeyup="filterClassrooms()">
                            <i class="fas fa-search"></i>
                        </div>
                        <select class="filter-select" id="classroomProgramFilter" onchange="filterClassrooms()">
                            <option value="">All Programs</option>
                            <?php 
                            $programs = [];
                            foreach ($classrooms as $classroom) {
                                if (!empty($classroom['program'])) {
                                    $programs[$classroom['program']] = $classroom['program'];
                                }
                            }
                            foreach ($programs as $program): ?>
                                <option value="<?= htmlspecialchars($program) ?>"><?= htmlspecialchars($program) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="classroomYearLevelFilter" onchange="filterClassrooms()">
                            <option value="">All Year Levels</option>
                            <?php 
                            $yearLevels = [];
                            foreach ($classrooms as $classroom) {
                                if (!empty($classroom['year_level'])) {
                                    $yearLevels[$classroom['year_level']] = $classroom['year_level'];
                                }
                            }
                            foreach ($yearLevels as $yearLevel): ?>
                                <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;" id="classroomsGrid">
                        <?php foreach ($classrooms as $classroom): ?>
                            <div class="classroom-card" 
                                 data-classroom-name="<?= strtolower(htmlspecialchars($classroom['name'])) ?>"
                                 data-program="<?= strtolower(htmlspecialchars($classroom['program'] ?? '')) ?>"
                                 data-year-level="<?= strtolower(htmlspecialchars($classroom['year_level'] ?? '')) ?>">
                                <h3><?= htmlspecialchars($classroom['name']) ?></h3>
                                <p><?= htmlspecialchars($classroom['description'] ?? 'No description') ?></p>
                                <div class="classroom-info">
                                    <span>
                                        <?= htmlspecialchars($classroom['program'] ?? '') ?> - 
                                        <?= htmlspecialchars($classroom['year_level'] ?? '') ?>
                                    </span>
                                    <span>
                                        <?= htmlspecialchars($classroom['academic_year'] ?? '') ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                        No classrooms assigned
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Additional functions and DOMContentLoaded handlers
        // Note: toggleProfileDropdown and toggleSidebar are already defined in <head>
        
        // Close dropdown when clicking outside - set up after DOM loads
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', function(event) {
                const dropdown = document.getElementById('profileDropdown');
                const profileIcon = document.querySelector('.profile-icon');
                
                if (dropdown && profileIcon) {
                    if (!profileIcon.contains(event.target) && !dropdown.contains(event.target)) {
                        dropdown.classList.remove('show');
                    }
                }
            });
        });
        
        // Note: toggleSidebar is already defined in <head> section
        // The sidebar script (teacher-sidebar-script.php) will override it with enhanced version if needed
        
        // Welcome banner visibility control
        document.addEventListener('DOMContentLoaded', function() {
            const welcomeBannerContainer = document.getElementById('welcomeBannerContainer');
            const welcomeBanner = document.getElementById('welcomeBanner');
            
            // Flag to track if welcome banner has been hidden (prevent duplicate hiding)
            let welcomeBannerHidden = false;
            
            if (welcomeBannerContainer && welcomeBanner) {
                const shouldShowWelcome = <?php echo $showWelcome ? 'true' : 'false'; ?>;
                
                if (shouldShowWelcome) {
                    welcomeBannerContainer.style.display = 'block';
                    welcomeBanner.style.display = 'flex';
                    welcomeBannerHidden = false; // Reset flag when showing
                } else {
                    welcomeBannerContainer.style.display = 'none';
                    welcomeBannerHidden = true; // Already hidden
                }
            }
            
            // Function to check if welcome banner is visible
            function isWelcomeBannerVisible() {
                if (!welcomeBannerContainer) return false;
                // Check multiple ways to ensure we catch it
                const computedStyle = window.getComputedStyle(welcomeBannerContainer);
                const isDisplayed = computedStyle.display !== 'none';
                const hasHeight = welcomeBannerContainer.offsetHeight > 0;
                const isVisible = welcomeBannerContainer.style.display !== 'none';
                return (isDisplayed || isVisible) && hasHeight;
            }
            
            // Function to hide welcome banner
            function hideWelcomeBanner() {
                if (welcomeBannerContainer && !welcomeBannerHidden) {
                    welcomeBannerHidden = true;
                    console.log('Hiding welcome banner'); // Debug log
                    
                    // Immediately hide with smooth transition
                    welcomeBannerContainer.style.transition = 'opacity 0.3s ease, margin 0.3s ease, max-height 0.3s ease';
                    welcomeBannerContainer.style.opacity = '0';
                    welcomeBannerContainer.style.marginBottom = '0';
                    welcomeBannerContainer.style.maxHeight = '0';
                    
                    setTimeout(() => {
                        if (welcomeBannerContainer) {
                            welcomeBannerContainer.style.display = 'none';
                        }
                    }, 300);
                }
            }
            
            // Use a simpler approach: hide welcome banner on ANY interaction
            // Use a single event listener that doesn't interfere with button functionality
            function handleInteraction(e) {
                if (welcomeBannerHidden) return; // Already hidden, skip
                
                const target = e.target;
                
                // Skip if clicking inside welcome banner itself
                if (welcomeBannerContainer && welcomeBannerContainer.contains(target)) {
                    return;
                }
                
                // Check if it's an interactive element
                const isInteractive = target.tagName === 'BUTTON' || 
                                    (target.tagName === 'INPUT' && (target.type === 'button' || target.type === 'submit')) ||
                                    target.tagName === 'SELECT' ||
                                    target.id === 'mobileMenuToggle' ||
                                    target.closest('button') !== null ||
                                    target.closest('.btn') !== null ||
                                    target.closest('[role="button"]') !== null ||
                                    target.closest('.profile-icon') !== null ||
                                    target.closest('.profile-dropdown-item') !== null ||
                                    target.closest('.nav-item') !== null ||
                                    target.closest('a.btn') !== null ||
                                    target.closest('.mobile-menu-toggle') !== null ||
                                    target.closest('#mobileMenuToggle') !== null ||
                                    target.closest('.filter-select') !== null ||
                                    target.closest('form button') !== null ||
                                    target.closest('form input[type="submit"]') !== null ||
                                    target.closest('select') !== null;
                
                // Hide welcome banner on any interactive element click
                // Use requestAnimationFrame to ensure it runs after other handlers
                if (isInteractive) {
                    requestAnimationFrame(() => {
                        if (!welcomeBannerHidden && welcomeBannerContainer && isWelcomeBannerVisible()) {
                            console.log('Interactive element clicked, hiding welcome banner', target); // Debug
                            hideWelcomeBanner();
                        }
                    });
                }
            }
            
            // Add event listener - use bubble phase so button handlers run first
            document.addEventListener('click', handleInteraction, false);
            
            // Also listen for touch events on mobile
            document.addEventListener('touchstart', function(e) {
                if (welcomeBannerHidden) return; // Already hidden, skip
                
                const target = e.target;
                
                // Skip if touching inside welcome banner
                if (welcomeBannerContainer && welcomeBannerContainer.contains(target)) {
                    return;
                }
                
                // Check if it's an interactive element
                const isInteractive = target.tagName === 'BUTTON' || 
                                    (target.tagName === 'INPUT' && (target.type === 'button' || target.type === 'submit')) ||
                                    target.tagName === 'SELECT' ||
                                    target.id === 'mobileMenuToggle' ||
                                    target.closest('button') !== null ||
                                    target.closest('.btn') !== null ||
                                    target.closest('[role="button"]') !== null ||
                                    target.closest('.profile-icon') !== null ||
                                    target.closest('.profile-dropdown-item') !== null ||
                                    target.closest('.nav-item') !== null ||
                                    target.closest('a.btn') !== null ||
                                    target.closest('.mobile-menu-toggle') !== null ||
                                    target.closest('#mobileMenuToggle') !== null ||
                                    target.closest('.filter-select') !== null ||
                                    target.closest('form button') !== null ||
                                    target.closest('form input[type="submit"]') !== null ||
                                    target.closest('select') !== null;
                
                // Hide welcome banner on touch
                if (isInteractive) {
                    requestAnimationFrame(() => {
                        if (!welcomeBannerHidden && welcomeBannerContainer && isWelcomeBannerVisible()) {
                            console.log('Touch on interactive element, hiding welcome banner', target); // Debug
                            hideWelcomeBanner();
                        }
                    });
                }
            }, { passive: true, capture: false });
            
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            
            // Define mainContent in this scope to avoid ReferenceError
            const mainContent = document.querySelector('.main-content');
            
            // Mobile sidebar handling (preserve existing functionality)
            // Note: Welcome banner hiding is already handled in setupButtonClickListeners above
            if (window.innerWidth <= 768) {
                const navItems = document.querySelectorAll('.nav-item');
                navItems.forEach(item => {
                    item.addEventListener('click', function() {
                        if (!welcomeBannerHidden) {
                            hideWelcomeBanner();
                        }
                        if (sidebar) {
                            sidebar.classList.remove('active');
                            sidebar.classList.add('hidden');
                        }
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.classList.remove('hide');
                    });
                });
            }
            
            // Hide sidebar when clicking outside (desktop)
            document.addEventListener('click', function(event) {
                if (sidebar && sidebar.contains(event.target)) return;
                if (toggleBtn && (toggleBtn.contains(event.target) || toggleBtn === event.target)) return;
                if (overlay && event.target === overlay) return;
                
                if (sidebar && !sidebar.classList.contains('hidden') && !sidebar.classList.contains('active')) {
                    if (window.innerWidth > 768) {
                        sidebar.classList.add('hidden');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    }
                } else if (sidebar && sidebar.classList.contains('active')) {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.classList.remove('hide');
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const toggleBtn = document.getElementById('mobileMenuToggle');
                const mainContent = document.querySelector('.main-content');
                
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
                } else {
                    // Mobile: sidebar hidden by default
                    if (sidebar) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) {
                        toggleBtn.style.display = 'block';
                        toggleBtn.classList.remove('hide');
                    }
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
                    toggleBtn.style.display = 'block';
                    toggleBtn.classList.remove('hide');
                }
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
        
        function filterClassrooms() {
            const searchTerm = document.getElementById('classroomSearch')?.value.toLowerCase() || '';
            const programFilter = document.getElementById('classroomProgramFilter')?.value.toLowerCase() || '';
            const yearLevelFilter = document.getElementById('classroomYearLevelFilter')?.value.toLowerCase() || '';
            const classroomCards = document.querySelectorAll('#classroomsGrid .classroom-card');
            const noResults = document.getElementById('noClassroomResults');
            let visibleCount = 0;
            
            classroomCards.forEach(card => {
                const classroomName = card.getAttribute('data-classroom-name') || '';
                const program = card.getAttribute('data-program') || '';
                const yearLevel = card.getAttribute('data-year-level') || '';
                
                const matchesSearch = !searchTerm || classroomName.includes(searchTerm);
                const matchesProgram = !programFilter || program === programFilter;
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                
                if (matchesSearch && matchesProgram && matchesYearLevel) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || programFilter || yearLevelFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
        
        // Session Keep-Alive: Ping server every 5 minutes to keep session alive
        (function() {
            let keepAliveInterval;
            let isPageVisible = true;
            
            function pingServer() {
                if (!isPageVisible) return; // Don't ping if tab is not visible
                
                fetch('session-keepalive.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'expired') {
                        // Session expired, redirect to login
                        clearInterval(keepAliveInterval);
                        window.location.href = '../auth/staff-login.php';
                    }
                })
                .catch(error => {
                    console.error('Keep-alive ping failed:', error);
                });
            }
            
            // Start keep-alive when page loads
            document.addEventListener('DOMContentLoaded', function() {
                // Ping immediately, then every 5 minutes (300000 ms)
                pingServer();
                keepAliveInterval = setInterval(pingServer, 300000);
            });
            
            // Handle page visibility (pause when tab is hidden)
            document.addEventListener('visibilitychange', function() {
                isPageVisible = !document.hidden;
                if (isPageVisible) {
                    // Tab became visible, ping immediately
                    pingServer();
                }
            });
            
            // Also ping on user activity (mouse move, click, keypress)
            let activityTimeout;
            ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
                document.addEventListener(event, function() {
                    clearTimeout(activityTimeout);
                    activityTimeout = setTimeout(pingServer, 60000); // Ping 1 minute after last activity
                }, { passive: true });
            });
        })();
    </script>
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
</body>
</html>

