<?php
// Student Grades Page
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

// Include database connection with error handling
try {
    require_once getAbsolutePath('config/database.php');
    require_once getAbsolutePath('backend/includes/grade_converter.php');
    require_once getAbsolutePath('backend/includes/course_status.php');
    require_once getAbsolutePath('backend/includes/student_approval.php');
} catch (Exception $e) {
    error_log("Database include error: " . $e->getMessage());
    redirectTo('auth/student-login.php?error=database');
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirectTo('auth/student-login.php');
}

// Ensure $pdo is available
if (!isset($pdo)) {
    error_log("Database connection not available");
    redirectTo('auth/student-login.php?error=connection');
}

$studentId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving student information: ' . $e->getMessage();
    $message_type = 'error';
}

// Check student approval status
$approvalStatus = checkStudentApprovalStatus($pdo, $studentId, $student);
$isApproved = $approvalStatus['isApproved'];

// Redirect to dashboard if not approved
if (!$isApproved) {
    header("Location: student-dashboard.php?msg=" . urlencode('This page is restricted until your account is approved.') . "&type=error");
    exit();
}

// Get grades grouped by academic year and semester
$gradesBySemester = [];
try {
    // Get grades for the student
    // Check which columns exist in the grades table
    $hasApprovalStatus = false;
    $hasIsLocked = false;
    try {
        $checkStmt = $pdo->query("SHOW COLUMNS FROM grades");
        $columns = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasApprovalStatus = in_array('approval_status', $columns);
        $hasIsLocked = in_array('is_locked', $columns);
    } catch (PDOException $e) {
        // If we can't check columns, assume they don't exist
        error_log("Error checking grades table columns: " . $e->getMessage());
    }
    
    // Build query based on which columns exist
    if ($hasApprovalStatus && $hasIsLocked) {
        // Full query with both approval_status and is_locked
        $stmt = $pdo->prepare("
            SELECT 
                g.*,
                s.name as subject_name,
                s.code as subject_code,
                s.units as subject_units,
                c.name as classroom_name,
                u.first_name as teacher_first,
                u.last_name as teacher_last
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            LEFT JOIN users u ON g.teacher_id = u.id
            WHERE g.student_id = ?
            AND (
                g.grade_type = 'participation' 
                OR (g.grade_type = 'final' AND (g.approval_status = 'approved' OR g.is_locked = 1))
            )
            ORDER BY g.graded_at DESC, s.code ASC
        ");
    } else if ($hasIsLocked) {
        // Query with only is_locked (no approval_status)
        $stmt = $pdo->prepare("
            SELECT 
                g.*,
                s.name as subject_name,
                s.code as subject_code,
                s.units as subject_units,
                c.name as classroom_name,
                u.first_name as teacher_first,
                u.last_name as teacher_last
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            LEFT JOIN users u ON g.teacher_id = u.id
            WHERE g.student_id = ?
            AND (
                g.grade_type = 'participation' 
                OR (g.grade_type = 'final' AND g.is_locked = 1)
            )
            ORDER BY g.graded_at DESC, s.code ASC
        ");
    } else {
        // Fallback query without approval_status or is_locked
        // Show all final grades and participation grades
        $stmt = $pdo->prepare("
            SELECT 
                g.*,
                s.name as subject_name,
                s.code as subject_code,
                s.units as subject_units,
                c.name as classroom_name,
                u.first_name as teacher_first,
                u.last_name as teacher_last
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            LEFT JOIN users u ON g.teacher_id = u.id
            WHERE g.student_id = ?
            AND (
                g.grade_type = 'participation' 
                OR g.grade_type = 'final'
            )
            ORDER BY g.graded_at DESC, s.code ASC
        ");
    }
    $stmt->execute([$studentId]);
    $allGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Try to get semester info from sections if available (simplified approach)
    if (!empty($allGrades)) {
        // Get all section info in one query if possible
        try {
            $classroomIds = array_filter(array_column($allGrades, 'classroom_id'));
            if (!empty($classroomIds)) {
                $placeholders = implode(',', array_fill(0, count($classroomIds), '?'));
                $sectionStmt = $pdo->prepare("
                    SELECT DISTINCT c.id as classroom_id, sec.academic_year, sec.semester
                    FROM classrooms c
                    LEFT JOIN sections sec ON c.section = sec.section_name 
                        AND c.year_level = sec.year_level
                    WHERE c.id IN ($placeholders)
                ");
                $sectionStmt->execute($classroomIds);
                $sectionMap = [];
                while ($row = $sectionStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['academic_year'])) {
                        $sectionMap[$row['classroom_id']] = [
                            'academic_year' => $row['academic_year'],
                            'semester' => $row['semester']
                        ];
                    }
                }
                
                // Apply section info to grades
                foreach ($allGrades as &$grade) {
                    if (!empty($grade['classroom_id']) && isset($sectionMap[$grade['classroom_id']])) {
                        $grade['academic_year'] = $sectionMap[$grade['classroom_id']]['academic_year'];
                        $grade['semester'] = $sectionMap[$grade['classroom_id']]['semester'];
                    }
                }
                unset($grade);
            }
        } catch (PDOException $e) {
            // Ignore section lookup errors - grades will still display
            error_log("Section lookup error: " . $e->getMessage());
        }
    }
    
    // Group by academic year and semester, filtering by grade visibility
    foreach ($allGrades as $grade) {
        $academicYear = $grade['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
        $semester = $grade['semester'] ?? '1st';
        $subjectId = $grade['subject_id'];
        
        // Check if grades should be visible for this subject
        // Only show grades after Prelims, Midterms, or Finals have been encoded
        $shouldShow = shouldShowGrades($pdo, $studentId, $subjectId, $academicYear, $semester);
        
        // If grades shouldn't be shown, skip this grade entry
        // Exception: Always show 'participation' type grades (enrollment markers)
        if (!$shouldShow && $grade['grade_type'] !== 'participation') {
            continue;
        }
        
        $key = $academicYear . '-' . $semester;
        
        if (!isset($gradesBySemester[$key])) {
            $gradesBySemester[$key] = [
                'academic_year' => $academicYear,
                'semester' => $semester,
                'grades' => []
            ];
        }
        $gradesBySemester[$key]['grades'][] = $grade;
    }
} catch (PDOException $e) {
    $message = 'Error retrieving grades: ' . $e->getMessage();
    $message_type = 'error';
    error_log("Grades query error: " . $e->getMessage());
    // Don't redirect on error, just show empty state
    $gradesBySemester = [];
}

// Use centralized grade converter functions from grade_converter.php
// gradeToGPA and getRemarks functions are now in grade_converter.php

// Get GPA
$gpa = null;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM student_gpa 
        WHERE student_id = ? 
        ORDER BY academic_year DESC, semester DESC 
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $gpa = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // GPA will remain null
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
    <title>My Grades - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
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
            width: 100%;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s;
        }
        
        .upgrade-btn:hover {
            background: #f5f5f5;
        }
        
        .container {
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .container.expanded {
            margin-left: 0;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        
        .back-btn {
            background: #a11c27;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .back-btn:hover {
            background: #b31310;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .grades-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .grades-table th,
        .grades-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .grades-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .grades-table td {
            color: #666;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .grade-badge.high {
            background: #d4edda;
            color: #155724;
        }
        
        .grade-badge.medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .grade-badge.low {
            background: #f8d7da;
            color: #721c24;
        }
        
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            background: #e9ecef;
            color: #495057;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 1.1rem;
        }
        
        /* Semester Navigation */
        .semester-nav {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .semester-item {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .semester-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .semester-item.expanded {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .semester-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .semester-item.expanded .semester-header {
            background: #e3f2fd;
        }
        
        .semester-title {
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        
        .semester-header i {
            color: #666;
            transition: transform 0.3s ease;
        }
        
        .semester-content {
            padding: 0;
        }
        
        .final-score-section {
            padding: 25px;
        }
        
        .final-score-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        
        .final-score-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .final-score-table thead {
            background: #e3f2fd;
        }
        
        .final-score-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            border-bottom: 2px solid #90caf9;
        }
        
        .final-score-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            color: #666;
            font-size: 0.9rem;
        }
        
        .final-score-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .final-score-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .summary-row {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .summary-row td {
            padding: 15px;
            color: #333;
            font-size: 1rem;
        }
        
        .download-cog-btn {
            background: #2196F3;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
            font-size: 0.9rem;
        }
        
        .download-cog-btn:hover {
            background: #1976D2;
        }
        
        .download-cog-btn i {
            font-size: 0.9rem;
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
        
        /* GPA Stat Card */
        .gpa-stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .gpa-stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .gpa-stat-content {
            flex: 1;
        }
        
        .gpa-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .gpa-stat-label {
            font-size: 0.95rem;
            color: #666;
        }
        
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            
            .container {
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
            
            .container {
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
            .gpa-stat-card {
                padding: 20px;
            }
            
            .gpa-stat-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
            
            .gpa-stat-value {
                font-size: 1.5rem;
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
                    <a href="student-grades.php" class="nav-item active">
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
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chart-bar"></i> My Grades</h1>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="background: <?= $message_type === 'error' ? '#f8d7da' : '#d4edda' ?>; color: <?= $message_type === 'error' ? '#721c24' : '#155724' ?>;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- GPA Stat Card -->
        <div class="gpa-stat-card">
            <div class="gpa-stat-icon" style="background: #ffe0e0; color: #a11c27;">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="gpa-stat-content">
                <div class="gpa-stat-value">
                    <?php if ($gpa): ?>
                        <?= number_format($gpa['gpa'], 2) ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
                <div class="gpa-stat-label">Current GPA</div>
            </div>
        </div>
        
        <?php if (!empty($gradesBySemester)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="gradeSearch" placeholder="Search by course name or code..." onkeyup="filterAllGrades()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="semesterFilter" onchange="filterAllGrades()">
                    <option value="">All Semesters</option>
                    <?php foreach ($gradesBySemester as $key => $semesterData): 
                        $semesterLabel = ucfirst($semesterData['semester']) . ' Semester';
                        if ($semesterData['semester'] === 'Summer') $semesterLabel = 'Summer';
                    ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($semesterData['academic_year']) ?> - <?= $semesterLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="remarksFilter" onchange="filterAllGrades()">
                    <option value="">All Remarks</option>
                    <option value="Passed">Passed</option>
                    <option value="Failed">Failed</option>
                </select>
            </div>
        <?php endif; ?>
        
        <!-- Academic Year/Semester Navigation -->
        <div class="semester-nav" id="semesterNav">
            <?php 
            $firstKey = null;
            $semesterIndex = 0;
            foreach ($gradesBySemester as $key => $semesterData): 
                $semesterIndex++;
                if ($firstKey === null) $firstKey = $key;
                $isExpanded = ($key === $firstKey);
                $semesterLabel = ucfirst($semesterData['semester']) . ' SEMESTER';
                if ($semesterData['semester'] === 'Summer') $semesterLabel = 'SUMMER';
            ?>
                <div class="semester-item <?= $isExpanded ? 'expanded' : '' ?>" data-semester-key="<?= htmlspecialchars($key) ?>" onclick="toggleSemester('<?= $key ?>')">
                    <div class="semester-header">
                        <span class="semester-title"><?= htmlspecialchars($semesterData['academic_year']) ?> - <?= $semesterLabel ?></span>
                        <i class="fas fa-chevron-<?= $isExpanded ? 'down' : 'right' ?>"></i>
                    </div>
                    <div class="semester-content" id="semester-<?= $key ?>" style="display: <?= $isExpanded ? 'block' : 'none' ?>;">
                        <div class="final-score-section">
                            <h2 class="final-score-title">Final Score</h2>
                            
                            <?php if (!empty($semesterData['grades'])): ?>
                                <table class="final-score-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Title</th>
                                            <th>Grade</th>
                                            <th>Re-Exam</th>
                                            <th>Credit Unit</th>
                                            <th>Remarks</th>
                                            <th>Instructor</th>
                                        </tr>
                                    </thead>
                                    <tbody class="grade-rows" data-semester-key="<?= htmlspecialchars($key) ?>">
                                        <?php 
                                        $totalUnits = 0;
                                        $totalGradePoints = 0;
                                        foreach ($semesterData['grades'] as $grade): 
                                            $rawScore = $grade['grade'];
                                            $maxPoints = $grade['max_points'] ?? 100;
                                            $percentage = $maxPoints > 0 ? ($rawScore / $maxPoints) * 100 : $rawScore;
                                            $phGrade = scoreToPhilippineGrade($rawScore, $maxPoints);
                                            $gpaValue = $phGrade; // Use Philippine grade as GPA value
                                            $units = floatval($grade['subject_units'] ?? 3.0);
                                            $totalUnits += $units;
                                            $totalGradePoints += ($gpaValue * $units);
                                            $remarks = $phGrade <= 3.0 ? 'Passed' : 'Failed';
                                        ?>
                                            <tr class="grade-row" 
                                                data-subject-name="<?= strtolower(htmlspecialchars($grade['subject_name'] ?? 'N/A')) ?>"
                                                data-subject-code="<?= strtolower(htmlspecialchars($grade['subject_code'] ?? 'N/A')) ?>"
                                                data-remarks="<?= strtolower($remarks) ?>">
                                                <td><?= htmlspecialchars($grade['subject_code'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($grade['subject_name'] ?? 'N/A') ?></td>
                                                <td>
                                                    <strong><?= formatPhilippineGrade($phGrade) ?></strong>
                                                    <small style="display: block; font-size: 0.75rem; color: #999;">
                                                        (<?= number_format($rawScore, 2) ?>/<?= number_format($maxPoints, 2) ?>)
                                                    </small>
                                                </td>
                                                <td></td>
                                                <td><?= number_format($units, 1) ?></td>
                                                <td><?= $remarks ?></td>
                                                <td><?= htmlspecialchars(($grade['teacher_first'] ?? '') . ' ' . ($grade['teacher_last'] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="summary-row">
                                            <td colspan="2" style="font-weight: 600;">GPA</td>
                                            <td style="font-weight: 600;"><?= $totalUnits > 0 ? number_format($totalGradePoints / $totalUnits, 2) : '0.00' ?></td>
                                            <td></td>
                                            <td style="font-weight: 600;"><?= number_format($totalUnits, 1) ?></td>
                                            <td colspan="2" style="text-align: right;">
                                                <button class="download-cog-btn" onclick="downloadCOG('<?= $key ?>')">
                                                    <i class="fas fa-download"></i> Download COG
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <p>No grades available for this semester</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($gradesBySemester)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No grades available yet</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div id="noGradeResults" class="no-results" style="display: none;">
            <i class="fas fa-search"></i>
            <p>No grades found matching your search</p>
        </div>
    </div>
    
    <script>
        function toggleSemester(key) {
            const content = document.getElementById('semester-' + key);
            const item = content.closest('.semester-item');
            const icon = item.querySelector('.semester-header i');
            
            if (content.style.display === 'none') {
                // Close all other semesters
                document.querySelectorAll('.semester-content').forEach(el => {
                    el.style.display = 'none';
                });
                document.querySelectorAll('.semester-item').forEach(el => {
                    el.classList.remove('expanded');
                });
                document.querySelectorAll('.semester-header i').forEach(el => {
                    el.className = 'fas fa-chevron-right';
                });
                
                // Open this semester
                content.style.display = 'block';
                item.classList.add('expanded');
                icon.className = 'fas fa-chevron-down';
            } else {
                content.style.display = 'none';
                item.classList.remove('expanded');
                icon.className = 'fas fa-chevron-right';
            }
        }
        
        function downloadCOG(semesterKey) {
            alert('Download COG functionality will be implemented soon.');
            // TODO: Implement COG download
        }
        
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
                    if (toggleBtn) toggleBtn.style.display = 'none';
                    if (container) container.classList.remove('expanded');
                } else {
                    // Hide sidebar
                    sidebar.classList.add('hidden');
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
                        if (toggleBtn) toggleBtn.style.display = 'none';
                    } else if (sidebar && sidebar.classList.contains('hidden')) {
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
            } else {
                // Desktop: sidebar visible by default
                if (sidebar) {
                    sidebar.classList.remove('hidden');
                    sidebar.classList.remove('active');
                }
                if (container) container.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        });
        
        // Filter all grades across semesters
        function filterAllGrades() {
            const searchTerm = document.getElementById('gradeSearch')?.value.toLowerCase() || '';
            const semesterFilter = document.getElementById('semesterFilter')?.value || '';
            const remarksFilter = document.getElementById('remarksFilter')?.value.toLowerCase() || '';
            
            const semesterItems = document.querySelectorAll('.semester-item');
            let anyVisible = false;
            
            semesterItems.forEach(semesterItem => {
                const semesterKey = semesterItem.getAttribute('data-semester-key');
                const gradeRows = semesterItem.querySelectorAll('.grade-row');
                let hasVisibleRows = false;
                
                // Check if this semester matches the filter
                const matchesSemester = !semesterFilter || semesterKey === semesterFilter;
                
                gradeRows.forEach(row => {
                    const subjectName = row.getAttribute('data-subject-name') || '';
                    const subjectCode = row.getAttribute('data-subject-code') || '';
                    const remarks = row.getAttribute('data-remarks') || '';
                    
                    const matchesSearch = !searchTerm || subjectName.includes(searchTerm) || subjectCode.includes(searchTerm);
                    const matchesRemarks = !remarksFilter || remarks === remarksFilter;
                    const matchesAll = matchesSearch && matchesRemarks && matchesSemester;
                    
                    if (matchesAll) {
                        row.style.display = '';
                        hasVisibleRows = true;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Show/hide semester based on visible rows
                if (hasVisibleRows && matchesSemester) {
                    semesterItem.style.display = '';
                    anyVisible = true;
                } else if (semesterFilter && semesterKey === semesterFilter) {
                    semesterItem.style.display = '';
                } else if (!semesterFilter && hasVisibleRows) {
                    semesterItem.style.display = '';
                    anyVisible = true;
                } else {
                    semesterItem.style.display = 'none';
                }
            });
            
            // Show no results message if needed
            const noResults = document.getElementById('noGradeResults');
            if (noResults) {
                if (!anyVisible && (searchTerm || semesterFilter || remarksFilter)) {
                    noResults.style.display = 'block';
                } else {
                    noResults.style.display = 'none';
                }
            }
        }
        
        // Real-time grade synchronization
        let lastSyncTime = new Date().toISOString();
        let syncInterval = null;
        
        function syncGrades() {
            fetch(`api/get-student-grades-sync.php?last_sync=${encodeURIComponent(lastSyncTime)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.grades && data.grades.length > 0) {
                        // New or updated grades detected - refresh page to show them
                        location.reload();
                    }
                    // Update last sync time
                    lastSyncTime = new Date().toISOString();
                })
                .catch(error => {
                    console.error('Grade sync error:', error);
                });
        }
        
        // Start auto-sync every 30 seconds (only if page is visible)
        function startAutoSync() {
            if (syncInterval) clearInterval(syncInterval);
            syncInterval = setInterval(() => {
                if (!document.hidden) {
                    syncGrades();
                }
            }, 30000); // Sync every 30 seconds
        }
        
        // Sync on page visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                syncGrades();
                startAutoSync();
            }
        });
        
        // Start auto-sync when page loads
        if (document.visibilityState !== 'hidden') {
            startAutoSync();
        }
        
        // Manual refresh button (if exists)
        const refreshBtn = document.getElementById('refreshGradesBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                syncGrades();
            });
        }
    </script>
</body>
</html>

