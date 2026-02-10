<?php
// Student Schedule Page
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

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving student information: ' . $e->getMessage();
}

// Check student approval status
$approvalStatus = checkStudentApprovalStatus($pdo, $studentId, $student);
$isApproved = $approvalStatus['isApproved'];

// Redirect to dashboard if not approved
if (!$isApproved) {
    header("Location: student-dashboard.php?msg=" . urlencode('This page is restricted until your account is approved.') . "&type=error");
    exit();
}

// Get courses for badge count
$courses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.name as course_name, s.code as course_code,
               AVG(g.grade) as avg_grade
        FROM subjects s
        LEFT JOIN grades g ON s.id = g.subject_id AND g.student_id = ?
        WHERE EXISTS (
            SELECT 1 FROM grades g2 
            WHERE g2.subject_id = s.id AND g2.student_id = ?
        )
        GROUP BY s.id, s.name, s.code
    ");
    $stmt->execute([$studentId, $studentId]);
    $coursesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($coursesData as $course) {
        $progress = $course['avg_grade'] ? min(100, ($course['avg_grade'] / 100) * 100) : 0;
        $courses[] = ['progress' => round($progress)];
    }
} catch (PDOException $e) {
    // Ignore error for badge count
}

// Get student schedule from section_schedules based on student's section and course
$schedule = [];
try {
    // First, get the student's section, year level, and course information
    // Try multiple methods to find the student's section and course
    $studentStmt = $pdo->prepare("
        SELECT u.section, u.year_level, u.program,
               c.name as course_name, c.code as course_code, c.id as course_id,
               cl.section as classroom_section, cl.year_level as classroom_year_level,
               cl.program as classroom_program
        FROM users u
        LEFT JOIN classroom_students cs ON u.id = cs.student_id AND cs.enrollment_status = 'enrolled'
        LEFT JOIN classrooms cl ON cs.classroom_id = cl.id
        LEFT JOIN courses c ON (cl.program = c.name OR u.program = c.name)
        WHERE u.id = ? AND u.role = 'student'
        ORDER BY cs.enrolled_at DESC
        LIMIT 1
    ");
    $studentStmt->execute([$studentId]);
    $studentInfo = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
        // Check if section_schedules table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $tableExists = $tableCheck->rowCount() > 0;
        
    if ($tableExists && $studentInfo) {
        // Try to find matching sections using multiple strategies
        $sectionName = trim($studentInfo['section'] ?? $studentInfo['classroom_section'] ?? '');
        $yearLevel = trim($studentInfo['year_level'] ?? $studentInfo['classroom_year_level'] ?? '');
            $courseId = $studentInfo['course_id'] ?? null;
        $courseName = $studentInfo['course_name'] ?? $studentInfo['program'] ?? $studentInfo['classroom_program'] ?? null;
            
        if (!empty($sectionName) && !empty($yearLevel)) {
            // Strategy 1: Try exact match with course_id
            if ($courseId) {
                $stmt = $pdo->prepare("
                    SELECT ss.*, 
                           sub.name as subject_name, 
                           sub.code as subject_code,
                           c.name as classroom_name,
                           CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                           sec.section_name,
                           sec.year_level as section_year_level,
                           sec.academic_year as section_academic_year,
                           sec.semester as section_semester,
                           crs.name as course_name,
                           crs.code as course_code
                    FROM section_schedules ss
                    INNER JOIN sections sec ON ss.section_id = sec.id
                    LEFT JOIN courses crs ON sec.course_id = crs.id
                    LEFT JOIN subjects sub ON ss.subject_id = sub.id
                    LEFT JOIN users u ON ss.teacher_id = u.id
                    LEFT JOIN classrooms c ON ss.classroom_id = c.id
                    WHERE TRIM(sec.section_name) = ? 
                      AND TRIM(sec.year_level) = ?
                      AND sec.course_id = ?
                      AND ss.status = 'active'
                    ORDER BY 
                      FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                      ss.start_time
                ");
                $stmt->execute([$sectionName, $yearLevel, $courseId]);
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Strategy 2: If no results, try with course name
            if (empty($schedule) && $courseName) {
                $stmt = $pdo->prepare("
                    SELECT ss.*, 
                           sub.name as subject_name, 
                           sub.code as subject_code,
                           c.name as classroom_name,
                           CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                           sec.section_name,
                           sec.year_level as section_year_level,
                           sec.academic_year as section_academic_year,
                           sec.semester as section_semester,
                           crs.name as course_name,
                           crs.code as course_code
                    FROM section_schedules ss
                    INNER JOIN sections sec ON ss.section_id = sec.id
                    LEFT JOIN courses crs ON sec.course_id = crs.id
                    LEFT JOIN subjects sub ON ss.subject_id = sub.id
                    LEFT JOIN users u ON ss.teacher_id = u.id
                    LEFT JOIN classrooms c ON ss.classroom_id = c.id
                    WHERE TRIM(sec.section_name) = ? 
                      AND TRIM(sec.year_level) = ?
                      AND crs.name = ?
                      AND ss.status = 'active'
                    ORDER BY 
                      FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                      ss.start_time
                ");
                $stmt->execute([$sectionName, $yearLevel, $courseName]);
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Strategy 3: Try matching by section name only (in case year_level format differs)
            if (empty($schedule) && $courseId) {
                $stmt = $pdo->prepare("
                    SELECT ss.*, 
                           sub.name as subject_name, 
                           sub.code as subject_code,
                           c.name as classroom_name,
                           CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                           sec.section_name,
                           sec.year_level as section_year_level,
                           sec.academic_year as section_academic_year,
                           sec.semester as section_semester,
                           crs.name as course_name,
                           crs.code as course_code
                    FROM section_schedules ss
                    INNER JOIN sections sec ON ss.section_id = sec.id
                    LEFT JOIN courses crs ON sec.course_id = crs.id
                    LEFT JOIN subjects sub ON ss.subject_id = sub.id
                    LEFT JOIN users u ON ss.teacher_id = u.id
                    LEFT JOIN classrooms c ON ss.classroom_id = c.id
                    WHERE TRIM(sec.section_name) = ? 
                      AND sec.course_id = ?
                      AND ss.status = 'active'
                    ORDER BY 
                      FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                      ss.start_time
                ");
                $stmt->execute([$sectionName, $courseId]);
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Strategy 3b: Try matching with flexible year_level (handle "1st Year" vs "1st" variations)
            if (empty($schedule) && $courseId && !empty($yearLevel)) {
                // Extract numeric part from year_level (e.g., "1st" from "1st Year")
                $yearLevelNumeric = preg_replace('/[^0-9]/', '', $yearLevel);
                if (!empty($yearLevelNumeric)) {
                    $stmt = $pdo->prepare("
                        SELECT ss.*, 
                               sub.name as subject_name, 
                               sub.code as subject_code,
                               c.name as classroom_name,
                               CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                               sec.section_name,
                               sec.year_level as section_year_level,
                               sec.academic_year as section_academic_year,
                               sec.semester as section_semester,
                               crs.name as course_name,
                               crs.code as course_code
                        FROM section_schedules ss
                        INNER JOIN sections sec ON ss.section_id = sec.id
                        LEFT JOIN courses crs ON sec.course_id = crs.id
                        LEFT JOIN subjects sub ON ss.subject_id = sub.id
                        LEFT JOIN users u ON ss.teacher_id = u.id
                        LEFT JOIN classrooms c ON ss.classroom_id = c.id
                        WHERE TRIM(sec.section_name) = ? 
                          AND sec.course_id = ?
                          AND (TRIM(sec.year_level) LIKE ? OR TRIM(sec.year_level) LIKE ?)
                          AND ss.status = 'active'
                        ORDER BY 
                          FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                          ss.start_time
                    ");
                    $yearLevelPattern1 = $yearLevelNumeric . '%';
                    $yearLevelPattern2 = '%' . $yearLevelNumeric . '%';
                    $stmt->execute([$sectionName, $courseId, $yearLevelPattern1, $yearLevelPattern2]);
                    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            // Strategy 4: Try to find section by matching through classrooms
            if (empty($schedule)) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT ss.*, 
                           sub.name as subject_name, 
                           sub.code as subject_code,
                           c.name as classroom_name,
                           CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                           sec.section_name,
                           sec.year_level as section_year_level,
                           sec.academic_year as section_academic_year,
                           sec.semester as section_semester,
                           crs.name as course_name,
                           crs.code as course_code
                    FROM section_schedules ss
                    INNER JOIN sections sec ON ss.section_id = sec.id
                    LEFT JOIN courses crs ON sec.course_id = crs.id
                    LEFT JOIN subjects sub ON ss.subject_id = sub.id
                    LEFT JOIN users u ON ss.teacher_id = u.id
                    LEFT JOIN classrooms c ON ss.classroom_id = c.id
                    INNER JOIN classroom_students cs ON cs.student_id = ?
                    INNER JOIN classrooms cl ON cs.classroom_id = cl.id AND cs.enrollment_status = 'enrolled'
                    WHERE TRIM(sec.section_name) = TRIM(cl.section)
                      AND TRIM(sec.year_level) = TRIM(cl.year_level)
                      AND (sec.course_id = (SELECT id FROM courses WHERE name = cl.program LIMIT 1) OR crs.name = cl.program)
                      AND ss.status = 'active'
                    ORDER BY 
                      FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                      ss.start_time
                ");
                $stmt->execute([$studentId]);
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // Fallback: If no section-based schedules found, try the old method
    if (empty($schedule)) {
        // Check if old schedules table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'schedules'");
        $scheduleTableExists = $stmt->rowCount() > 0;
        
        if ($scheduleTableExists) {
            $stmt = $pdo->prepare("
                SELECT s.*, sub.name as subject_name, sub.code as subject_code,
                       c.name as classroom_name, u.first_name as teacher_first, u.last_name as teacher_last
                FROM schedules s
                LEFT JOIN subjects sub ON s.subject_id = sub.id
                LEFT JOIN classrooms c ON s.classroom_id = c.id
                LEFT JOIN users u ON s.teacher_id = u.id
                WHERE s.student_id = ?
                ORDER BY s.day_of_week, s.start_time
            ");
            $stmt->execute([$studentId]);
            $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Final fallback: Get schedule from enrolled classrooms
            $stmt = $pdo->prepare("
                SELECT DISTINCT s.id as subject_id, s.name as subject_name, s.code as subject_code,
                       c.name as classroom_name, c.id as classroom_id
                FROM subjects s
                JOIN grades g ON s.id = g.subject_id
                LEFT JOIN classrooms c ON g.classroom_id = c.id
                WHERE g.student_id = ?
                ORDER BY s.name
            ");
            $stmt->execute([$studentId]);
            $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $message = 'Error retrieving schedule: ' . $e->getMessage();
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Get current month and year from URL or use current date
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validate month and year
if ($currentMonth < 1 || $currentMonth > 12) $currentMonth = (int)date('n');
if ($currentYear < 2020 || $currentYear > 2100) $currentYear = (int)date('Y');

// Calculate previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get first day of month and number of days
$firstDay = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
$daysInMonth = date('t', $firstDay);
$dayOfWeek = date('w', $firstDay); // 0 (Sunday) to 6 (Saturday)
$dayOfWeek = $dayOfWeek == 0 ? 6 : $dayOfWeek - 1; // Convert to Monday (0) to Sunday (6)

// Map schedule to days of week for calendar display
$scheduleByDay = [];
foreach ($schedule as $item) {
    $dayName = isset($item['day_of_week']) ? strtolower($item['day_of_week']) : null;
    if ($dayName) {
        $dayIndex = array_search(ucfirst($dayName), $days);
        if ($dayIndex !== false) {
            if (!isset($scheduleByDay[$dayIndex])) {
                $scheduleByDay[$dayIndex] = [];
            }
            $scheduleByDay[$dayIndex][] = $item;
        }
    } else {
        // If no day_of_week, show on all weekdays (Monday-Friday)
        for ($i = 0; $i < 5; $i++) {
            if (!isset($scheduleByDay[$i])) {
                $scheduleByDay[$i] = [];
            }
            $scheduleByDay[$i][] = $item;
        }
    }
}

// Prepare schedule data for JavaScript (for popup and real-time updates)
$scheduleDataForJS = [];
foreach ($schedule as $item) {
    $dayName = isset($item['day_of_week']) ? ucfirst(strtolower($item['day_of_week'])) : null;
    if ($dayName) {
        if (!isset($scheduleDataForJS[$dayName])) {
            $scheduleDataForJS[$dayName] = [];
        }
        $scheduleDataForJS[$dayName][] = [
            'subject_name' => $item['subject_name'] ?? 'Subject',
            'subject_code' => $item['subject_code'] ?? 'N/A',
            'start_time' => isset($item['start_time']) ? date('h:i A', strtotime($item['start_time'])) : '',
            'end_time' => isset($item['end_time']) ? date('h:i A', strtotime($item['end_time'])) : '',
            'classroom_name' => $item['classroom_name'] ?? '',
            'room' => $item['room'] ?? '',
            'teacher_name' => $item['teacher_name'] ?? ''
        ];
    }
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
    <title>Schedule - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
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
            height: auto;
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
            z-index: 1000;
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
            .sidebar-overlay.active {
                z-index: 1000;
            }
        }
        
        /* Sidebar - Standardized */
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
            margin: 0;
            padding: 0;
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
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        
        .container.expanded {
            margin-left: 0;
        }
        
        /* Ensure calendar card doesn't force unnecessary height */
        .calendar-card {
            flex-shrink: 0;
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
                z-index: 1001;
                opacity: 1;
                visibility: visible;
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
        }
        
        /* Show toggle button when sidebar is hidden */
        .sidebar.hidden ~ * .mobile-menu-toggle:not(.hide),
        body:has(.sidebar.hidden) .mobile-menu-toggle:not(.hide) {
            display: block;
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
        .calendar-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .calendar-nav-btn {
            background: #a11c27;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-size: 1rem;
        }
        .calendar-nav-btn:hover {
            background: #b31310;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(161, 28, 39, 0.3);
        }
        .calendar-month-year {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            min-width: 200px;
            text-align: center;
        }
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 8px;
        }
        .calendar-weekday {
            text-align: center;
            font-weight: 600;
            color: #a11c27;
            padding: 12px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }
        .calendar-day {
            min-height: 100px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px;
            border: 2px solid transparent;
            transition: all 0.2s;
            position: relative;
        }
        .calendar-day.has-schedule {
            cursor: pointer;
        }
        .calendar-day.has-schedule:hover {
            border-color: #a11c27;
            background: #fff;
            box-shadow: 0 2px 8px rgba(161, 28, 39, 0.1);
        }
        .calendar-day.other-month {
            opacity: 0.3;
            background: #f0f0f0;
            cursor: default;
        }
        .calendar-day.other-month:hover {
            border-color: transparent;
            background: #f0f0f0;
            box-shadow: none;
        }
        .calendar-day.today {
            background: #ffe0e0;
            border-color: #a11c27;
            box-shadow: 0 0 0 2px rgba(161, 28, 39, 0.2);
        }
        .calendar-day.has-schedule {
            background: #fff;
        }
        .calendar-day.has-schedule .calendar-day-number::after {
            content: '';
            position: absolute;
            top: 2px;
            right: 2px;
            width: 8px;
            height: 8px;
            background: #a11c27;
            border-radius: 50%;
            display: block;
            z-index: 10;
        }
        @media (max-width: 768px) {
            .calendar-day.has-schedule .calendar-day-number::after {
                width: 6px;
                height: 6px;
                top: 1px;
                right: 1px;
            }
        }
        .calendar-day-number {
            font-weight: 700;
            color: #333;
            margin-bottom: 6px;
            font-size: 0.95rem;
            position: relative;
        }
        .calendar-day.today .calendar-day-number {
            color: #a11c27;
        }
        .calendar-day.other-month .calendar-day-number {
            color: #999;
        }
        .calendar-event {
            background: #a11c27;
            color: white;
            padding: 3px 5px;
            border-radius: 4px;
            margin-bottom: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: center;
            line-height: 1.2;
        }
        .calendar-event:hover {
            background: #b31310;
            transform: translateX(2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
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
        
        /* Schedule Popup Modal */
        .schedule-popup-overlay {
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
            padding: 20px;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .schedule-popup-overlay.show {
            display: flex;
        }
        .schedule-popup-modal {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
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
        .schedule-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .schedule-popup-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
        }
        .schedule-popup-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #999;
            cursor: pointer;
            padding: 5px;
            transition: color 0.2s;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .schedule-popup-close:hover {
            color: #a11c27;
            background: #f5f5f5;
        }
        .schedule-popup-date {
            font-size: 1rem;
            color: #666;
            margin-bottom: 20px;
        }
        .schedule-popup-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .schedule-popup-item {
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 12px;
            background: #f8f9fa;
            transition: all 0.2s;
        }
        .schedule-popup-item:hover {
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .schedule-popup-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .schedule-popup-item-title {
            font-weight: 700;
            color: #333;
            font-size: 1.1rem;
        }
        .schedule-popup-item-code {
            color: #a11c27;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .schedule-popup-item-time {
            color: #a11c27;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 8px;
        }
        .schedule-popup-item-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.9rem;
            color: #666;
        }
        .schedule-popup-item-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .schedule-popup-item-detail i {
            color: #a11c27;
            width: 16px;
        }
        .schedule-popup-empty {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .schedule-popup-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
            display: block;
        }
        
        @media (max-width: 768px) {
            .calendar-day {
                min-height: 80px;
                padding: 6px;
            }
            .calendar-event {
                font-size: 0.65rem;
                padding: 3px 4px;
            }
            .calendar-month-year {
                font-size: 1.2rem;
                min-width: 150px;
            }
            
            .calendar-header {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .calendar-nav {
                width: 100%;
                justify-content: space-between;
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
            
            .schedule-popup-overlay {
                padding: 10px;
            }
            
            .schedule-popup-modal {
                padding: 20px;
                max-height: 85vh;
                border-radius: 8px;
            }
            
            .schedule-popup-header {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .schedule-popup-title {
                font-size: 1.2rem;
            }
            
            .schedule-popup-close {
                width: 30px;
                height: 30px;
                font-size: 1.2rem;
            }
            
            .schedule-popup-item {
                padding: 12px;
            }
            
            .schedule-popup-item-title {
                font-size: 1rem;
            }
            
            .schedule-popup-item-details {
                flex-direction: column;
                gap: 8px;
            }
            
            .calendar-weekdays {
                gap: 4px;
            }
            
            .calendar-weekday {
                padding: 8px 6px;
                font-size: 0;
                line-height: 1;
                text-align: left;
            }
            
            .calendar-weekday::before {
                content: attr(data-day-letter);
                display: block;
                font-size: 0.85rem;
                font-weight: 600;
                color: #a11c27;
                text-transform: uppercase;
            }
            
            .calendar-days {
                gap: 4px;
            }
        }
        
        @media (max-width: 480px) {
            .calendar-day {
                min-height: 70px;
                padding: 4px;
            }
            
            .calendar-weekday {
                padding: 8px 4px;
                text-align: left;
            }
            
            .calendar-day-number {
                font-size: 0.85rem;
            }
            
            .calendar-event {
                font-size: 0.6rem;
                padding: 2px 3px;
            }
            
            .schedule-popup-modal {
                padding: 15px;
            }
            
            .schedule-popup-title {
                font-size: 1.1rem;
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
                    <a href="student-schedule.php" class="nav-item active">
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
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Schedule</h1>
        </div>
        
        <?php if (empty($schedule)): ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <p style="margin: 0; color: #856404;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>No schedule found.</strong> 
                    Please ensure you are assigned to a section and that schedules have been created for your section.
                </p>
            </div>
        <?php else: ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="scheduleSearch" placeholder="Search by course name or code..." onkeyup="filterSchedule()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="dayFilter" onchange="filterSchedule()">
                    <option value="">All Days</option>
                    <?php foreach ($days as $day): ?>
                        <option value="<?= strtolower($day) ?>"><?= $day ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <div class="calendar-card">
            <div class="calendar-header">
                <div class="calendar-nav">
                    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="calendar-nav-btn">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <div class="calendar-month-year">
                        <?= date('F Y', mktime(0, 0, 0, $currentMonth, 1, $currentYear)) ?>
                    </div>
                    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="calendar-nav-btn">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="calendar-nav-btn" style="width: auto; padding: 0 15px; font-size: 0.85rem;">
                    <i class="fas fa-calendar-day"></i> Today
                </a>
            </div>
            
            <div class="calendar-weekdays">
                <?php foreach ($days as $day): ?>
                    <div class="calendar-weekday" data-day-letter="<?= substr($day, 0, 1) ?>"><?= substr($day, 0, 3) ?></div>
                <?php endforeach; ?>
            </div>
            
            <div class="calendar-days">
                <?php
                // Fill empty cells before first day of month
                for ($i = 0; $i < $dayOfWeek; $i++) {
                    $prevMonthDays = date('t', mktime(0, 0, 0, $currentMonth - 1, 1, $currentYear));
                    $dayNum = $prevMonthDays - $dayOfWeek + $i + 1;
                    echo '<div class="calendar-day other-month">';
                    echo '<div class="calendar-day-number">' . $dayNum . '</div>';
                    echo '</div>';
                }
                
                // Current month days
                $today = date('Y-m-d');
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $currentDate = date('Y-m-d', mktime(0, 0, 0, $currentMonth, $day, $currentYear));
                    $isToday = $currentDate === $today;
                    $dayOfWeekIndex = date('w', mktime(0, 0, 0, $currentMonth, $day, $currentYear));
                    $dayOfWeekIndex = $dayOfWeekIndex == 0 ? 6 : $dayOfWeekIndex - 1; // Convert to Monday (0) to Sunday (6)
                    
                    $dayName = $days[$dayOfWeekIndex];
                    $hasSchedule = isset($scheduleByDay[$dayOfWeekIndex]) && !empty($scheduleByDay[$dayOfWeekIndex]);
                    
                    $dayClass = $isToday ? 'today' : '';
                    if ($hasSchedule) {
                        $dayClass .= ' has-schedule';
                    }
                    
                    $onclickAttr = $hasSchedule ? 'onclick="showSchedulePopup(\'' . $currentDate . '\', \'' . $dayName . '\')"' : '';
                    echo '<div class="calendar-day ' . $dayClass . '" 
                          data-date="' . $currentDate . '" 
                          data-day-name="' . $dayName . '"
                          ' . $onclickAttr . '>';
                    echo '<div class="calendar-day-number">' . $day . '</div>';
                    
                    // Show schedule items for this day of week
                    if ($hasSchedule) {
                        foreach ($scheduleByDay[$dayOfWeekIndex] as $item) {
                            $subjectName = htmlspecialchars($item['subject_name'] ?? 'Subject');
                            $subjectCode = htmlspecialchars($item['subject_code'] ?? 'N/A');
                            $classroomName = htmlspecialchars($item['classroom_name'] ?? '');
                            $dayNameLower = isset($item['day_of_week']) ? strtolower($item['day_of_week']) : strtolower($dayName);
                            $startTime = isset($item['start_time']) ? date('h:i A', strtotime($item['start_time'])) : '';
                            $endTime = isset($item['end_time']) ? date('h:i A', strtotime($item['end_time'])) : '';
                            $room = htmlspecialchars($item['room'] ?? '');
                            $teacherName = htmlspecialchars($item['teacher_name'] ?? '');
                            
                            // Build tooltip with more details
                            $tooltip = $subjectName . ' (' . $subjectCode . ')';
                            if ($startTime && $endTime) {
                                $tooltip .= ' - ' . $startTime . ' to ' . $endTime;
                            }
                            if ($classroomName) {
                                $tooltip .= ' - ' . $classroomName;
                            }
                            if ($room) {
                                $tooltip .= ' - ' . $room;
                            }
                            if ($teacherName) {
                                $tooltip .= ' - ' . $teacherName;
                            }
                            
                            // Show only subject code for compact display
                            echo '<div class="calendar-event" 
                                  data-subject-name="' . strtolower($subjectName) . '" 
                                  data-subject-code="' . strtolower($subjectCode) . '"
                                  data-day="' . $dayNameLower . '"
                                  title="' . $tooltip . '">';
                            echo $subjectCode;
                            if ($startTime) {
                                echo '<br><small style="font-size: 0.6rem;">' . $startTime . '</small>';
                            }
                            echo '</div>';
                        }
                    }
                    
                    echo '</div>';
                }
                
                // Fill remaining cells after last day of month
                $remainingDays = 7 - (($dayOfWeek + $daysInMonth) % 7);
                if ($remainingDays < 7) {
                    for ($day = 1; $day <= $remainingDays; $day++) {
                        echo '<div class="calendar-day other-month">';
                        echo '<div class="calendar-day-number">' . $day . '</div>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
        <div id="noScheduleResults" class="no-results" style="display: none;">
            <i class="fas fa-search"></i>
            <p>No schedule items found matching your search</p>
        </div>
    </div>
    
    <!-- Schedule Popup Modal -->
    <div id="schedulePopupOverlay" class="schedule-popup-overlay" onclick="closeSchedulePopup(event)">
        <div class="schedule-popup-modal" onclick="event.stopPropagation()">
            <div class="schedule-popup-header">
                <h3 class="schedule-popup-title"><i class="fas fa-calendar-day"></i> Schedule Details</h3>
                <button class="schedule-popup-close" onclick="closeSchedulePopup(event)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="schedule-popup-date" id="schedulePopupDate"></div>
            <ul class="schedule-popup-list" id="schedulePopupList">
                <!-- Schedule items will be inserted here -->
            </ul>
        </div>
    </div>
    
    <script>
        // Schedule data from PHP
        const scheduleData = <?= json_encode($scheduleDataForJS) ?>;
        
        // Function to show schedule popup
        function showSchedulePopup(date, dayName) {
            const overlay = document.getElementById('schedulePopupOverlay');
            const dateEl = document.getElementById('schedulePopupDate');
            const listEl = document.getElementById('schedulePopupList');
            
            if (!overlay || !dateEl || !listEl) return;
            
            // Format date for display
            const dateObj = new Date(date);
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            dateEl.textContent = formattedDate;
            
            // Get schedule items for this day
            const daySchedule = scheduleData[dayName] || [];
            
            // Clear previous items
            listEl.innerHTML = '';
            
            if (daySchedule.length === 0) {
                listEl.innerHTML = '<div class="schedule-popup-empty"><i class="fas fa-calendar-times"></i><p>No scheduled courses for this day</p></div>';
            } else {
                // Sort by start time
                daySchedule.sort((a, b) => {
                    const timeA = a.start_time || '';
                    const timeB = b.start_time || '';
                    return timeA.localeCompare(timeB);
                });
                
                // Display schedule items
                daySchedule.forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'schedule-popup-item';
                    
                    let detailsHtml = '';
                    if (item.classroom_name) {
                        detailsHtml += `<div class="schedule-popup-item-detail"><i class="fas fa-door-open"></i><span>${item.classroom_name}</span></div>`;
                    }
                    if (item.room) {
                        detailsHtml += `<div class="schedule-popup-item-detail"><i class="fas fa-map-marker-alt"></i><span>Room: ${item.room}</span></div>`;
                    }
                    if (item.teacher_name) {
                        detailsHtml += `<div class="schedule-popup-item-detail"><i class="fas fa-chalkboard-teacher"></i><span>${item.teacher_name}</span></div>`;
                    }
                    
                    li.innerHTML = `
                        <div class="schedule-popup-item-header">
                            <div>
                                <div class="schedule-popup-item-title">${item.subject_name}</div>
                                <div class="schedule-popup-item-code">${item.subject_code}</div>
                            </div>
                        </div>
                        ${item.start_time && item.end_time ? `<div class="schedule-popup-item-time"><i class="fas fa-clock"></i> ${item.start_time} - ${item.end_time}</div>` : ''}
                        ${detailsHtml ? `<div class="schedule-popup-item-details">${detailsHtml}</div>` : ''}
                    `;
                    
                    listEl.appendChild(li);
                });
            }
            
            // Show popup
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Function to close schedule popup
        function closeSchedulePopup(event) {
            if (event) {
                event.stopPropagation();
            }
            const overlay = document.getElementById('schedulePopupOverlay');
            if (overlay) {
                overlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        }
        
        // Close popup with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSchedulePopup();
            }
        });
        
        // Real-time schedule updates (polling every 30 seconds)
        let scheduleUpdateInterval = null;
        
        function updateScheduleData() {
            fetch('../../backend/api/get-student-schedule.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.schedule) {
                        // Update scheduleData
                        const newScheduleData = {};
                        data.schedule.forEach(item => {
                            const dayName = item.day_of_week;
                            if (!newScheduleData[dayName]) {
                                newScheduleData[dayName] = [];
                            }
                            newScheduleData[dayName].push({
                                subject_name: item.subject_name || 'Subject',
                                subject_code: item.subject_code || 'N/A',
                                start_time: item.start_time || '',
                                end_time: item.end_time || '',
                                classroom_name: item.classroom_name || '',
                                room: item.room || '',
                                teacher_name: item.teacher_name || ''
                            });
                        });
                        
                        // Update global scheduleData
                        Object.assign(scheduleData, newScheduleData);
                        
                        // Update calendar visual indicators
                        updateCalendarIndicators();
                    }
                })
                .catch(error => {
                    console.error('Error updating schedule:', error);
                });
        }
        
        function updateCalendarIndicators() {
            const calendarDays = document.querySelectorAll('.calendar-day:not(.other-month)');
            calendarDays.forEach(dayEl => {
                const dayName = dayEl.getAttribute('data-day-name');
                if (dayName && scheduleData[dayName] && scheduleData[dayName].length > 0) {
                    dayEl.classList.add('has-schedule');
                } else {
                    dayEl.classList.remove('has-schedule');
                }
            });
        }
        
        // Start polling when page loads (only if user is on schedule page)
        document.addEventListener('DOMContentLoaded', function() {
            // Start polling every 30 seconds
            scheduleUpdateInterval = setInterval(updateScheduleData, 30000);
            
            // Initial update after 5 seconds (to avoid immediate refresh on page load)
            setTimeout(updateScheduleData, 5000);
        });
        
        // Stop polling when page is hidden (to save resources)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (scheduleUpdateInterval) {
                    clearInterval(scheduleUpdateInterval);
                    scheduleUpdateInterval = null;
                }
            } else {
                if (!scheduleUpdateInterval) {
                    scheduleUpdateInterval = setInterval(updateScheduleData, 30000);
                    updateScheduleData(); // Update immediately when page becomes visible
                }
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
        
        // Hide sidebar when clicking outside (desktop)
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
            
            // Click outside sidebar to hide it (works on all screen sizes)
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
                } else {
                    // Mobile: sidebar hidden by default
                    if (sidebar) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (container) container.classList.add('expanded');
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
                if (container) container.classList.add('expanded');
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
                if (container) container.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        });
        
        function filterSchedule() {
            const searchTerm = document.getElementById('scheduleSearch')?.value.toLowerCase() || '';
            const dayFilter = document.getElementById('dayFilter')?.value.toLowerCase() || '';
            const calendarEvents = document.querySelectorAll('.calendar-event');
            const noResults = document.getElementById('noScheduleResults');
            let visibleCount = 0;
            
            calendarEvents.forEach(event => {
                const subjectName = event.getAttribute('data-subject-name') || '';
                const subjectCode = event.getAttribute('data-subject-code') || '';
                const day = event.getAttribute('data-day') || '';
                
                const matchesSearch = !searchTerm || subjectName.includes(searchTerm) || subjectCode.includes(searchTerm);
                const matchesDay = !dayFilter || day === dayFilter;
                
                if (matchesSearch && matchesDay) {
                    event.style.display = '';
                    visibleCount++;
                } else {
                    event.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || dayFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
    </script>
</body>
</html>

