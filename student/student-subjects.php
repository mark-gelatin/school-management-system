<?php
// Student Subjects Page
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
require_once getAbsolutePath('backend/includes/course_status.php');
require_once getAbsolutePath('backend/includes/student_approval.php');
require_once getAbsolutePath('backend/includes/course_enrollment.php');

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

// Get student courses/subjects
$courses = [];
$coursesForBadge = [];
try {
    // First, get the student's section, year level, and course information
    // (similar to how it's done in student-schedule.php)
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
    
    // Extract student section info for section_schedules matching
    $sectionName = null;
    $yearLevel = null;
    $courseId = null;
    $courseName = null;
    
    if ($tableExists && $studentInfo) {
        $sectionName = trim($studentInfo['section'] ?? $studentInfo['classroom_section'] ?? '');
        $yearLevel = trim($studentInfo['year_level'] ?? $studentInfo['classroom_year_level'] ?? '');
        $courseId = $studentInfo['course_id'] ?? null;
        $courseName = $studentInfo['course_name'] ?? $studentInfo['program'] ?? $studentInfo['classroom_program'] ?? null;
    }
    
    // Build the section_schedules EXISTS clause conditionally
    $sectionSchedulesCondition = '';
    $sectionSchedulesParams = [];
    
    if ($tableExists && $studentInfo && !empty($sectionName) && !empty($yearLevel)) {
        $sectionSchedulesCondition = "
            OR
            -- Show subjects that are in section_schedules for the student's section (similar to schedule feature)
            EXISTS (
                SELECT 1 FROM section_schedules ss
                INNER JOIN sections sec ON ss.section_id = sec.id
                LEFT JOIN courses crs ON sec.course_id = crs.id
                WHERE ss.subject_id = s.id 
                  AND ss.status = 'active'
                  AND (
                      -- Strategy 1: Exact match with section name, year level, and course_id
                      (TRIM(sec.section_name) = ? AND TRIM(sec.year_level) = ? AND sec.course_id = ?)
                      OR
                      -- Strategy 2: Match with section name, year level, and course name
                      (TRIM(sec.section_name) = ? AND TRIM(sec.year_level) = ? AND crs.name = ?)
                      OR
                      -- Strategy 3: Match through classrooms (for students enrolled in classrooms)
                      EXISTS (
                          SELECT 1 FROM classroom_students cs2
                          INNER JOIN classrooms cl2 ON cs2.classroom_id = cl2.id
                          WHERE cs2.student_id = ? 
                            AND cs2.enrollment_status = 'enrolled'
                            AND TRIM(sec.section_name) = TRIM(cl2.section)
                            AND TRIM(sec.year_level) = TRIM(cl2.year_level)
                            AND (sec.course_id = (SELECT id FROM courses WHERE name = cl2.program LIMIT 1) OR crs.name = cl2.program)
                      )
                  )
            )
        ";
        
        // Add parameters for section_schedules query
        $sectionSchedulesParams = [
            $sectionName, // for Strategy 1
            $yearLevel,   // for Strategy 1
            $courseId,    // for Strategy 1
            $sectionName, // for Strategy 2
            $yearLevel,   // for Strategy 2
            $courseName,  // for Strategy 2
            $studentId    // for Strategy 3
        ];
    } elseif ($tableExists && $studentInfo) {
        // If section info is missing, still try Strategy 3 (classroom-based matching)
        $sectionSchedulesCondition = "
            OR
            -- Show subjects that are in section_schedules for the student's section (classroom-based matching)
            EXISTS (
                SELECT 1 FROM section_schedules ss
                INNER JOIN sections sec ON ss.section_id = sec.id
                LEFT JOIN courses crs ON sec.course_id = crs.id
                INNER JOIN classroom_students cs2 ON cs2.student_id = ?
                INNER JOIN classrooms cl2 ON cs2.classroom_id = cl2.id
                WHERE ss.subject_id = s.id 
                  AND ss.status = 'active'
                  AND cs2.enrollment_status = 'enrolled'
                  AND TRIM(sec.section_name) = TRIM(cl2.section)
                  AND TRIM(sec.year_level) = TRIM(cl2.year_level)
                  AND (sec.course_id = (SELECT id FROM courses WHERE name = cl2.program LIMIT 1) OR crs.name = cl2.program)
            )
        ";
        $sectionSchedulesParams = [$studentId];
    }
    
    // Build classroom_name subquery with section_schedules fallback
    $classroomNameSubquery = "
            COALESCE(
                (SELECT c.name FROM classrooms c 
                 JOIN grades g2 ON c.id = g2.classroom_id 
                 WHERE g2.subject_id = s.id AND g2.student_id = ? LIMIT 1),
                (SELECT cl.name FROM classrooms cl 
                 JOIN classroom_students cs ON cl.id = cs.classroom_id 
                 JOIN grades g3 ON g3.classroom_id = cl.id AND g3.subject_id = s.id
                 WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled' 
             LIMIT 1)";
    
    if ($tableExists) {
        $classroomNameSubquery .= ",
            (SELECT c.name FROM section_schedules ss
             JOIN sections sec ON ss.section_id = sec.id
             LEFT JOIN classrooms c ON ss.classroom_id = c.id
             WHERE ss.subject_id = s.id AND ss.status = 'active'
             LIMIT 1)";
    }
    
    $classroomNameSubquery .= ",
                'N/A'
        )";
    
    // Build teacher_name subquery with section_schedules fallback
    $teacherNameSubquery = "
            COALESCE(
                (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM classrooms c 
                 JOIN grades g2 ON c.id = g2.classroom_id 
                 JOIN users t ON c.teacher_id = t.id
                 WHERE g2.subject_id = s.id AND g2.student_id = ? AND c.teacher_id IS NOT NULL LIMIT 1),
                (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM classrooms cl 
                 JOIN classroom_students cs ON cl.id = cs.classroom_id 
                 JOIN grades g3 ON g3.classroom_id = cl.id AND g3.subject_id = s.id
                 JOIN users t ON cl.teacher_id = t.id
                 WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled' 
             AND cl.teacher_id IS NOT NULL LIMIT 1)";
    
    if ($tableExists) {
        $teacherNameSubquery .= ",
            (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM section_schedules ss
             JOIN users t ON ss.teacher_id = t.id
             WHERE ss.subject_id = s.id AND ss.status = 'active' AND ss.teacher_id IS NOT NULL
             LIMIT 1)";
    }
    
    $teacherNameSubquery .= ",
                'N/A'
        )";
    
    // Get subjects that the student is enrolled in:
    // 1. Subjects where student has grades (definitely enrolled)
    // 2. Subjects that are in classrooms where the student is enrolled
    // 3. Subjects that are in section_schedules for the student's section (NEW - similar to schedule feature)
    $query = "
        SELECT DISTINCT 
            s.id, 
            s.name as course_name, 
            s.code as course_code,
            s.description, 
            s.units,
            " . $classroomNameSubquery . " as classroom_name,
            " . $teacherNameSubquery . " as teacher_name,
            AVG(g.grade) as avg_grade,
            COUNT(DISTINCT g.id) as total_grades
        FROM subjects s
        LEFT JOIN grades g ON s.id = g.subject_id AND g.student_id = ?
        WHERE s.status = 'active'
        AND (
            -- Show subjects where student has grades (definitely enrolled)
            EXISTS (SELECT 1 FROM grades g2 WHERE g2.subject_id = s.id AND g2.student_id = ?)
            OR
            -- Show subjects that are in classrooms where student is enrolled
            EXISTS (
                SELECT 1 FROM classroom_students cs
                JOIN grades g3 ON g3.classroom_id = cs.classroom_id AND g3.subject_id = s.id
                WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled'
            )
            " . $sectionSchedulesCondition . "
        )
        GROUP BY s.id, s.name, s.code, s.description, s.units
        ORDER BY s.name
    ";
    
    // Prepare parameters for execution
    // Parameters order:
    // 1-2: classroom_name subquery (2x $studentId)
    // 3-4: teacher_name subquery (2x $studentId)
    // 5: LEFT JOIN grades (1x $studentId)
    // 6-7: EXISTS clauses (2x $studentId)
    // 8+: section_schedules condition parameters
    $params = [
        $studentId, // classroom_name subquery 1
        $studentId, // classroom_name subquery 2
        $studentId, // teacher_name subquery 1
        $studentId, // teacher_name subquery 2
        $studentId, // LEFT JOIN grades
        $studentId, // EXISTS grades check
        $studentId  // EXISTS classroom_students check
    ];
    $params = array_merge($params, $sectionSchedulesParams);
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate course status and determine grade visibility for each course
    foreach ($courses as &$course) {
        // Get academic info if not already in query result
        if (empty($course['academic_year']) || empty($course['semester'])) {
            $academicInfo = getCourseAcademicInfo($pdo, $studentId, $course['id']);
            if ($academicInfo) {
                $course['academic_year'] = $academicInfo['academic_year'];
                $course['semester'] = $academicInfo['semester'];
            }
        }
        
        // Determine course status
        if (!empty($course['academic_year']) && !empty($course['semester'])) {
            $course['status'] = getCourseStatus($course['academic_year'], $course['semester']);
        } else {
            $course['status'] = 'Ongoing'; // Default
        }
        
        // Determine if grades should be visible
        $course['grades_visible'] = false;
        if (!empty($course['academic_year']) && !empty($course['semester'])) {
            $course['grades_visible'] = shouldShowGrades($pdo, $studentId, $course['id'], $course['academic_year'], $course['semester']);
        }
    }
    unset($course);
    
    // For badge count (use status instead of progress)
    foreach ($courses as $course) {
        // Badge can show status indicator instead of progress
        $coursesForBadge[] = ['status' => $course['status'] ?? 'Ongoing'];
    }
} catch (PDOException $e) {
    $message = 'Error retrieving courses: ' . $e->getMessage();
}

// Handle AJAX request to get course details and enrolled students
if (isset($_GET['action']) && $_GET['action'] === 'get_course_details' && isset($_GET['subject_id'])) {
    header('Content-Type: application/json');
    $subject_id = intval($_GET['subject_id']);
    
    try {
        // Get course details with teacher name from multiple sources
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $tableExists = $tableCheck->rowCount() > 0;
        
        $teacherNameSubquery = "
                   COALESCE(
                       (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM classrooms c 
                        JOIN grades g2 ON c.id = g2.classroom_id 
                        JOIN users t ON c.teacher_id = t.id
                        WHERE g2.subject_id = s.id AND g2.student_id = ? AND c.teacher_id IS NOT NULL LIMIT 1),
                       (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM classrooms cl 
                        JOIN classroom_students cs ON cl.id = cs.classroom_id 
                        JOIN grades g3 ON g3.classroom_id = cl.id AND g3.subject_id = s.id
                        JOIN users t ON cl.teacher_id = t.id
                        WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled' 
                 AND cl.teacher_id IS NOT NULL LIMIT 1)";
        
        if ($tableExists) {
            $teacherNameSubquery .= ",
                (SELECT CONCAT(t.first_name, ' ', t.last_name) FROM section_schedules ss
                 JOIN users t ON ss.teacher_id = t.id
                 WHERE ss.subject_id = s.id AND ss.status = 'active' AND ss.teacher_id IS NOT NULL
                 LIMIT 1)";
        }
        
        $teacherNameSubquery .= ",
                       'N/A'
            )";
        
        $stmt = $pdo->prepare("
            SELECT s.*,
                   " . $teacherNameSubquery . " as teacher_name
            FROM subjects s
            WHERE s.id = ? AND s.status = 'active'
        ");
        
        $params = [$studentId, $studentId, $subject_id];
        $stmt->execute($params);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            echo json_encode(['success' => false, 'error' => 'Course not found']);
            exit();
        }
        
        // Verify student is enrolled in this course using unified function
        $enrolled = isStudentEnrolledInSubject($pdo, $studentId, $subject_id);
        
        if (!$enrolled) {
            echo json_encode(['success' => false, 'error' => 'You are not enrolled in this course']);
            exit();
        }
        
        // Get all students enrolled in this course (same subject) using unified function
        // Exclude the current student from the list
        $allStudents = getStudentsEnrolledInSubject($pdo, $subject_id);
        $students = array_filter($allStudents, function($student) use ($studentId) {
            return $student['id'] != $studentId;
        });
        // Re-index array
        $students = array_values($students);
        
        echo json_encode([
            'success' => true, 
            'course' => $course,
            'students' => $students
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
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
    <title>My Courses - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
            display: flex;
            flex-direction: column;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .container.expanded {
            margin-left: 0;
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
            
            .courses-grid {
                grid-template-columns: 1fr;
                padding: 0;
            }
            
            /* Prevent body scroll when sidebar is open on mobile */
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
                transition: none;
            }
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }
        
        .course-card {
            width: 100%;
            box-sizing: border-box;
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .course-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .course-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: #a11c27;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .course-title {
            flex: 1;
        }
        
        .course-name {
            font-weight: 700;
            color: #333;
            margin-bottom: 3px;
        }
        
        .course-code {
            font-size: 0.85rem;
            color: #999;
        }
        
        .course-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .course-info {
            display: flex;
            justify-content: space-between;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            margin-bottom: 15px;
        }
        
        .info-item {
            text-align: center;
        }
        
        .info-label {
            font-size: 0.75rem;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }
        
        .course-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .course-status.coming {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .course-status.ongoing {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .course-status.completed {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .course-teacher {
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .teacher-label {
            font-size: 0.85rem;
            color: #666;
            font-weight: 600;
        }
        
        .teacher-name {
            font-size: 0.95rem;
            color: #333;
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
                    <a href="student-subjects.php" class="nav-item active">
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
            <h1><i class="fas fa-book"></i> My Courses</h1>
        </div>
        
        <?php if (!empty($courses)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="subjectSearch" placeholder="Search by course name or code..." onkeyup="filterSubjects()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="teacherFilter" onchange="filterSubjects()">
                    <option value="">All Teachers</option>
                    <?php 
                    $teachers = [];
                    foreach ($courses as $course) {
                        if (!empty($course['teacher_name']) && $course['teacher_name'] !== 'N/A') {
                            $teachers[$course['teacher_name']] = $course['teacher_name'];
                        }
                    }
                    foreach ($teachers as $teacher): ?>
                        <option value="<?= htmlspecialchars($teacher) ?>"><?= htmlspecialchars($teacher) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="classroomFilter" onchange="filterSubjects()">
                    <option value="">All Classrooms</option>
                    <?php 
                    $classrooms = [];
                    foreach ($courses as $course) {
                        if (!empty($course['classroom_name']) && $course['classroom_name'] !== 'N/A') {
                            $classrooms[$course['classroom_name']] = $course['classroom_name'];
                        }
                    }
                    foreach ($classrooms as $classroom): ?>
                        <option value="<?= htmlspecialchars($classroom) ?>"><?= htmlspecialchars($classroom) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="statusFilter" onchange="filterSubjects()">
                    <option value="">All Status</option>
                    <option value="coming">Coming</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            
            <div class="courses-grid" id="coursesGrid">
                <?php foreach ($courses as $course): ?>
                    <?php $initial = strtoupper(substr($course['course_name'], 0, 1)); ?>
                    <div class="course-card" 
                         data-subject-id="<?= $course['id'] ?>"
                         data-subject-name="<?= strtolower(htmlspecialchars($course['course_name'])) ?>"
                         data-subject-code="<?= strtolower(htmlspecialchars($course['course_code'])) ?>"
                         data-teacher="<?= strtolower(htmlspecialchars($course['teacher_name'] ?? 'N/A')) ?>"
                         data-classroom="<?= strtolower(htmlspecialchars($course['classroom_name'] ?? 'N/A')) ?>"
                         style="cursor: pointer;"
                         onclick="showCourseDetails(<?= $course['id'] ?>, '<?= htmlspecialchars($course['course_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($course['course_code'], ENT_QUOTES) ?>')">
                        <div class="course-header">
                            <div class="course-icon"><?= $initial ?></div>
                            <div class="course-title">
                                <div class="course-name"><?= htmlspecialchars($course['course_name']) ?></div>
                                <div class="course-code"><?= htmlspecialchars($course['course_code']) ?></div>
                            </div>
                        </div>
                        <?php if ($course['description']): ?>
                            <div class="course-description"><?= htmlspecialchars($course['description']) ?></div>
                        <?php endif; ?>
                        <div class="course-info">
                            <div class="info-item">
                                <div class="info-label">Units</div>
                                <div class="info-value"><?= htmlspecialchars($course['units'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Classroom</div>
                                <div class="info-value" style="font-size: 0.9rem;"><?= htmlspecialchars($course['classroom_name'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <span class="course-status <?= strtolower($course['status'] ?? 'ongoing') ?>">
                                        <?= htmlspecialchars($course['status'] ?? 'Ongoing') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="course-teacher">
                            <div class="teacher-label">Teacher:</div>
                            <div class="teacher-name"><?= htmlspecialchars($course['teacher_name'] ?? 'N/A') ?></div>
                        </div>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0; text-align: center;">
                            <span style="color: #a11c27; font-size: 0.85rem; font-weight: 600;">
                                <i class="fas fa-info-circle"></i> Click to view details
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="noSubjectResults" class="no-results" style="display: none;">
                <i class="fas fa-search"></i>
                <p>No courses found matching your search</p>
            </div>
        <?php else: ?>
            <div class="course-card">
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <p>No courses enrolled yet</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Course Details Modal -->
    <div class="modal fade" id="courseDetailsModal" tabindex="-1" aria-labelledby="courseDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #a11c27; color: white;">
                    <h5 class="modal-title" id="courseDetailsModalLabel">
                        <i class="fas fa-book"></i> <span id="modalCourseName"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="courseLoading" style="text-align: center; padding: 40px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #a11c27;"></i>
                        <p style="margin-top: 15px; color: #666;">Loading course details...</p>
                    </div>
                    <div id="courseContent" style="display: none;">
                        <!-- Course Information -->
                        <div class="mb-4">
                            <h6 style="margin-bottom: 15px; color: #333; border-bottom: 2px solid #a11c27; padding-bottom: 8px;">
                                <i class="fas fa-info-circle"></i> Course Information
                            </h6>
                            <div id="courseInfo">
                                <!-- Course details will be loaded here -->
                            </div>
                        </div>
                        
                        <!-- Teacher Information -->
                        <div class="mb-4">
                            <h6 style="margin-bottom: 15px; color: #333; border-bottom: 2px solid #a11c27; padding-bottom: 8px;">
                                <i class="fas fa-chalkboard-teacher"></i> Assigned Teacher
                            </h6>
                            <div id="teacherInfo">
                                <!-- Teacher info will be loaded here -->
                            </div>
                        </div>
                        
                        <!-- Enrolled Students -->
                        <div class="mb-4">
                            <h6 style="margin-bottom: 15px; color: #333; border-bottom: 2px solid #a11c27; padding-bottom: 8px;">
                                <i class="fas fa-users"></i> Enrolled Students
                            </h6>
                            <div id="studentsList" style="max-height: 300px; overflow-y: auto;">
                                <!-- Students will be loaded here -->
                            </div>
                        </div>
                    </div>
                    <div id="courseError" style="display: none; text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                        <p style="margin-top: 15px;" id="errorMessage"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showCourseDetails(subjectId, courseName, courseCode) {
            // Get modal element
            const modalElement = document.getElementById('courseDetailsModal');
            const modal = new bootstrap.Modal(modalElement);
            document.getElementById('modalCourseName').textContent = courseName + ' (' + courseCode + ')';
            
            // Reset modal content
            document.getElementById('courseLoading').style.display = 'block';
            document.getElementById('courseContent').style.display = 'none';
            document.getElementById('courseError').style.display = 'none';
            document.getElementById('courseInfo').innerHTML = '';
            document.getElementById('teacherInfo').innerHTML = '';
            document.getElementById('studentsList').innerHTML = '';
            
            // Set aria-hidden to false BEFORE showing modal to prevent accessibility warning
            modalElement.setAttribute('aria-hidden', 'false');
            
            // Show modal
            modal.show();
            
            // Ensure aria-hidden stays false after modal is shown
            modalElement.addEventListener('shown.bs.modal', function handler() {
                modalElement.setAttribute('aria-hidden', 'false');
                modalElement.removeEventListener('shown.bs.modal', handler);
            }, { once: true });
            
            // Set aria-hidden to true when modal is hidden
            modalElement.addEventListener('hidden.bs.modal', function handler() {
                modalElement.setAttribute('aria-hidden', 'true');
                modalElement.removeEventListener('hidden.bs.modal', handler);
            }, { once: true });
            
            // Fetch course details
            fetch('?action=get_course_details&subject_id=' + subjectId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('courseLoading').style.display = 'none';
                    
                    if (data.success) {
                        const course = data.course;
                        const students = data.students || [];
                        
                        // Display course information
                        let courseInfoHtml = `
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <strong>Course Code:</strong> ${course.code || 'N/A'}
                                </div>
                                <div class="col-md-6 mb-3">
                                    <strong>Units:</strong> ${course.units || 'N/A'}
                                </div>
                                ${course.description ? `
                                <div class="col-12 mb-3">
                                    <strong>Description:</strong><br>
                                    <span style="color: #666;">${course.description}</span>
                                </div>
                                ` : ''}
                                ${course.program ? `
                                <div class="col-md-6 mb-3">
                                    <strong>Program:</strong> ${course.program}
                                </div>
                                ` : ''}
                                ${course.year_level ? `
                                <div class="col-md-6 mb-3">
                                    <strong>Year Level:</strong> ${course.year_level}
                                </div>
                                ` : ''}
                            </div>
                        `;
                        document.getElementById('courseInfo').innerHTML = courseInfoHtml;
                        
                        // Display teacher information
                        const teacherName = course.teacher_name && course.teacher_name !== 'N/A' 
                            ? course.teacher_name 
                            : 'Not assigned';
                        document.getElementById('teacherInfo').innerHTML = `
                            <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <strong style="font-size: 1.1rem;">${teacherName}</strong>
                            </div>
                        `;
                        
                        // Display enrolled students
                        if (students.length > 0) {
                            let studentsHtml = '<div class="list-group">';
                            students.forEach(student => {
                                const studentId = student.student_id_number || 'N/A';
                                const section = student.section || 'N/A';
                                studentsHtml += `
                                    <div class="list-group-item">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                    ${student.first_name.charAt(0).toUpperCase()}${student.last_name.charAt(0).toUpperCase()}
                                                </div>
                                            </div>
                                            <div style="flex: 1;">
                                                <strong>${student.first_name} ${student.last_name}</strong>
                                                <div style="font-size: 0.85rem; color: #666; margin-top: 4px;">
                                                    <span>ID: ${studentId}</span>
                                                    ${section !== 'N/A' ? ` | <span>Section: ${section}</span>` : ''}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                            studentsHtml += '</div>';
                            document.getElementById('studentsList').innerHTML = studentsHtml;
                        } else {
                            document.getElementById('studentsList').innerHTML = '<p class="text-muted">No classmates enrolled in this course yet.</p>';
                        }
                        
                        document.getElementById('courseContent').style.display = 'block';
                    } else {
                        document.getElementById('errorMessage').textContent = data.error || 'Error loading course details.';
                        document.getElementById('courseError').style.display = 'block';
                    }
                })
                .catch(error => {
                    document.getElementById('courseLoading').style.display = 'none';
                    document.getElementById('errorMessage').textContent = 'Error loading course details. Please try again.';
                    document.getElementById('courseError').style.display = 'block';
                });
        }
        
        function filterSubjects() {
            const searchTerm = document.getElementById('subjectSearch').value.toLowerCase();
            const teacherFilter = document.getElementById('teacherFilter').value.toLowerCase();
            const classroomFilter = document.getElementById('classroomFilter').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            const courseCards = document.querySelectorAll('#coursesGrid .course-card');
            const noResults = document.getElementById('noSubjectResults');
            let visibleCount = 0;
            
            courseCards.forEach(card => {
                const subjectName = card.getAttribute('data-subject-name') || '';
                const subjectCode = card.getAttribute('data-subject-code') || '';
                const teacher = card.getAttribute('data-teacher') || '';
                const classroom = card.getAttribute('data-classroom') || '';
                const status = card.getAttribute('data-status') || '';
                
                const matchesSearch = !searchTerm || subjectName.includes(searchTerm) || subjectCode.includes(searchTerm);
                const matchesTeacher = !teacherFilter || teacher === teacherFilter;
                const matchesClassroom = !classroomFilter || classroom === classroomFilter;
                const matchesStatus = !statusFilter || status === statusFilter;
                
                if (matchesSearch && matchesTeacher && matchesClassroom && matchesStatus) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || teacherFilter || classroomFilter || statusFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
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
    </script>
</body>
</html>
