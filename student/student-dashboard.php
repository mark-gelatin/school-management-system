<?php
// Student Dashboard - View Grades and Credentials
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
require_once getAbsolutePath('backend/includes/grade_converter.php');
require_once getAbsolutePath('backend/includes/student_approval.php');
require_once getAbsolutePath('backend/includes/student_rejection_handler.php');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirectTo('auth/student-login.php');
}

// Update session timestamp to keep it alive
$_SESSION['last_activity'] = time();

$studentId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
}

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

// Get student's course code, section, and semester information
$studentCourseCode = null;
$studentSection = null;
$studentCourseName = null;
$activeSemester = null;
try {
    // Try to get course code, section, and semester from sections via classroom_students
    $stmt = $pdo->prepare("
        SELECT c.code, c.name as course_name, s.section_name, s.academic_year, s.semester
        FROM courses c
        JOIN sections s ON c.id = s.course_id
        JOIN classrooms cl ON (cl.section = s.section_name AND cl.program = c.name AND cl.year_level = s.year_level)
        JOIN classroom_students cs ON cl.id = cs.classroom_id
        WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled'
        ORDER BY s.academic_year DESC, s.semester DESC
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $studentCourseCode = $result['code'];
        $studentSection = $result['section_name'];
        $studentCourseName = $result['course_name'];
        $activeSemester = $result['academic_year'] . ' - ' . strtoupper($result['semester']);
    } else {
        // Fallback: try to match program name with course name
        if (!empty($student['program'])) {
            $stmt = $pdo->prepare("
                SELECT code, name FROM courses 
                WHERE name = ? OR name LIKE ? OR ? LIKE CONCAT('%', name, '%')
                LIMIT 1
            ");
            $stmt->execute([$student['program'], '%' . $student['program'] . '%', $student['program']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $studentCourseCode = $result['code'];
                $studentCourseName = $result['name'];
            }
        }
        // Get section from user table as fallback
        if (!empty($student['section'])) {
            $studentSection = $student['section'];
        }
    }
} catch (PDOException $e) {
    // If tables don't exist, course code will remain null
    $studentCourseCode = null;
    // Get section from user table as fallback
    if (!empty($student['section'])) {
        $studentSection = $student['section'];
    }
    if (!empty($student['program'])) {
        $studentCourseName = $student['program'];
    }
}

// Handle enrollment request submission
if (isset($_POST['submit_enrollment_request'])) {
    try {
        // Ensure enrollment tables exist
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'enrollment_periods'");
        $periodsTableExists = $tableCheck->rowCount() > 0;
        
        if (!$periodsTableExists) {
            $message = 'Enrollment system is not available.';
            $message_type = 'error';
        } else {
            // Get student's course
            $studentCourseStmt = $pdo->prepare("SELECT course_id FROM users WHERE id = ?");
            $studentCourseStmt->execute([$studentId]);
            $studentCourse = $studentCourseStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$studentCourse || !$studentCourse['course_id']) {
                $message = 'Your course information is not available.';
                $message_type = 'error';
            } else {
                // Get current academic year and determine next semester
                $currentYear = (int)date('Y');
                $currentMonth = (int)date('m');
                $nextAcademicYear = '';
                $nextSemester = '';
                
                // Determine next semester based on current date
                if ($currentMonth >= 6 && $currentMonth <= 10) {
                    // Currently in 1st semester, next is 2nd
                    $nextAcademicYear = $currentYear . '-' . ($currentYear + 1);
                    $nextSemester = '2nd';
                } elseif ($currentMonth >= 11 || $currentMonth <= 3) {
                    // Currently in 2nd semester, next is Summer or 1st of next year
                    if ($currentMonth >= 11) {
                        $nextAcademicYear = ($currentYear + 1) . '-' . ($currentYear + 2);
                        $nextSemester = '1st';
                    } else {
                        $nextAcademicYear = $currentYear . '-' . ($currentYear + 1);
                        $nextSemester = 'Summer';
                    }
                } else {
                    // Summer, next is 1st of next year
                    $nextAcademicYear = $currentYear . '-' . ($currentYear + 1);
                    $nextSemester = '1st';
                }
                
                // Check if enrollment period exists for student's course
                $periodStmt = $pdo->prepare("
                    SELECT id, start_date, end_date, status 
                    FROM enrollment_periods 
                    WHERE course_id = ? AND academic_year = ? AND semester = ?
                    AND status IN ('active', 'scheduled')
                    ORDER BY start_date DESC
                    LIMIT 1
                ");
                $periodStmt->execute([$studentCourse['course_id'], $nextAcademicYear, $nextSemester]);
                $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$period) {
                    $message = 'Wait for your program\'s turn to enroll for next semester.';
                    $message_type = 'warning';
                } elseif ($period['status'] === 'closed' || strtotime($period['end_date']) < time()) {
                    $message = 'The enrollment period has ended.';
                    $message_type = 'error';
                } elseif (strtotime($period['start_date']) > time()) {
                    $message = 'The enrollment period has not started yet.';
                    $message_type = 'warning';
                } else {
                    // Check if request already exists
                    $existingStmt = $pdo->prepare("
                        SELECT id, status FROM enrollment_requests 
                        WHERE student_id = ? AND enrollment_period_id = ?
                    ");
                    $existingStmt->execute([$studentId, $period['id']]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        if ($existing['status'] === 'pending') {
                            $message = 'Your enrollment request is already being processed.';
                            $message_type = 'info';
                        } elseif ($existing['status'] === 'approved') {
                            $message = 'Your enrollment request has already been approved.';
                            $message_type = 'success';
                        } elseif ($existing['status'] === 'rejected') {
                            $message = 'Your enrollment request was rejected. Please contact the registrar.';
                            $message_type = 'error';
                        } else {
                            $message = 'Your enrollment request is already being processed.';
                            $message_type = 'info';
                        }
                    } else {
                        // Create new enrollment request
                        $insertStmt = $pdo->prepare("
                            INSERT INTO enrollment_requests (student_id, course_id, enrollment_period_id, academic_year, semester, status) 
                            VALUES (?, ?, ?, ?, ?, 'pending')
                        ");
                        $insertStmt->execute([$studentId, $studentCourse['course_id'], $period['id'], $nextAcademicYear, $nextSemester]);
                        $message = 'Your enrollment request has been submitted successfully. Please wait for admin approval.';
                        $message_type = 'success';
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $message = 'Error submitting enrollment request: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Check enrollment request status for popup on login
$enrollmentRequestStatus = null;
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'enrollment_requests'");
    if ($tableCheck->rowCount() > 0) {
        // Check if rejection_reason column exists in enrollment_requests
        $hasRejectionReason = false;
        try {
            $colCheck = $pdo->query("
                SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'enrollment_requests' 
                AND COLUMN_NAME = 'rejection_reason'
            ");
            $hasRejectionReason = ($colCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
        } catch (Exception $e) {
            $hasRejectionReason = false;
        }
        
        if ($hasRejectionReason) {
            $requestStmt = $pdo->prepare("
                SELECT status, reviewed_at, rejection_reason 
                FROM enrollment_requests 
                WHERE student_id = ? 
                ORDER BY requested_at DESC 
                LIMIT 1
            ");
        } else {
            $requestStmt = $pdo->prepare("
                SELECT status, reviewed_at, notes as rejection_reason 
                FROM enrollment_requests 
                WHERE student_id = ? 
                ORDER BY requested_at DESC 
                LIMIT 1
            ");
        }
        $requestStmt->execute([$studentId]);
        $enrollmentRequestStatus = $requestStmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignore errors
}

// Check student approval status using shared function
$approvalStatus = checkStudentApprovalStatus($pdo, $studentId, $student);
$isApproved = $approvalStatus['isApproved'];
$admissionInfo = $approvalStatus['admissionInfo'];

// Check if student is rejected
$rejectionStatus = isStudentRejected($pdo, $studentId);
$isRejected = $rejectionStatus['rejected'];
$rejectionMessage = null;
if ($isRejected) {
    $rejectionMessage = getRejectionNotification($pdo, $studentId);
}

// Get enrollment status timeline
$enrollmentStatus = null;
$enrollmentTimeline = [];
try {
    // Check if admission_applications table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'admission_applications'");
    $admissionTableExists = $stmt->rowCount() > 0;
    
    if ($admissionTableExists) {
        $stmt = $pdo->prepare("
            SELECT * FROM admission_applications 
            WHERE student_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $admissionApp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admissionApp) {
            // Stage 1: Request Enrolment
            $enrollmentTimeline[] = [
                'stage' => 'Request Enrolment',
                'date' => date('M-d', strtotime($admissionApp['created_at'])),
                'time' => date('h:i a', strtotime($admissionApp['created_at'])),
                'status' => 'completed',
                'color' => '#ff9800' // Orange
            ];
            
            // Stage 2: Registrar Review
            if ($admissionApp['reviewed_at']) {
                $enrollmentTimeline[] = [
                    'stage' => 'Registrar Review',
                    'date' => date('M-d', strtotime($admissionApp['reviewed_at'])),
                    'time' => date('h:i a', strtotime($admissionApp['reviewed_at'])),
                    'status' => 'completed',
                    'color' => '#2196F3' // Light blue
                ];
            } else {
                $enrollmentTimeline[] = [
                    'stage' => 'Registrar Review',
                    'date' => '',
                    'time' => '',
                    'status' => 'pending',
                    'color' => '#2196F3'
                ];
            }
            
            // Stage 3: Enrolled
            if ($admissionApp['status'] === 'approved') {
                // Check if student is enrolled in classrooms
                $stmt = $pdo->prepare("
                    SELECT enrolled_at FROM classroom_students 
                    WHERE student_id = ? AND enrollment_status = 'enrolled'
                    ORDER BY enrolled_at ASC
                    LIMIT 1
                ");
                $stmt->execute([$studentId]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($enrollment) {
                    $enrollmentTimeline[] = [
                        'stage' => 'Enrolled',
                        'date' => date('M-d', strtotime($enrollment['enrolled_at'])),
                        'time' => date('h:i a', strtotime($enrollment['enrolled_at'])),
                        'status' => 'completed',
                        'color' => '#dc3545' // Red
                    ];
                } else {
                    $enrollmentTimeline[] = [
                        'stage' => 'Enrolled',
                        'date' => date('M-d', strtotime($admissionApp['reviewed_at'] ?? $admissionApp['created_at'])),
                        'time' => date('h:i a', strtotime($admissionApp['reviewed_at'] ?? $admissionApp['created_at'])),
                        'status' => 'completed',
                        'color' => '#dc3545'
                    ];
                }
            } else {
                $enrollmentTimeline[] = [
                    'stage' => 'Enrolled',
                    'date' => '',
                    'time' => '',
                    'status' => 'pending',
                    'color' => '#dc3545'
                ];
            }
            
            $enrollmentStatus = [
                'type' => $admissionApp['educational_status'] ?? 'Regular Student',
                'timeline' => $enrollmentTimeline,
                'application_number' => $admissionApp['application_number'] ?? null
            ];
        }
    }
} catch (PDOException $e) {
    // Enrollment status will remain null
}

// Get student courses with progress
$courses = [];
$quizResults = [];
$overallProgress = 0;
$gpa = null;

try {
    // Check if enhanced structure exists
    $stmt = $pdo->query("SHOW COLUMNS FROM grades LIKE 'grade_type'");
    $enhancedGrades = $stmt->rowCount() > 0;
    
    if ($enhancedGrades) {
        // Get enrolled courses with progress calculation
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.name as course_name, s.code as course_code,
                   c.name as classroom_name,
                   COUNT(DISTINCT g.id) as total_grades,
                   AVG(g.grade) as avg_grade,
                   MAX(g.grade) as max_grade,
                   MIN(g.grade) as min_grade
            FROM subjects s
            LEFT JOIN grades g ON s.id = g.subject_id AND g.student_id = ?
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            WHERE EXISTS (
                SELECT 1 FROM grades g2 
                WHERE g2.subject_id = s.id AND g2.student_id = ?
            )
            GROUP BY s.id, s.name, s.code, c.name
            ORDER BY s.name
        ");
        $stmt->execute([$studentId, $studentId]);
        $coursesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate progress for each course (simplified: based on average grade)
        foreach ($coursesData as $course) {
            $progress = 0;
            if ($course['avg_grade'] !== null) {
                $progress = min(100, ($course['avg_grade'] / 100) * 100);
            }
            $courses[] = [
                'id' => $course['id'],
                'name' => $course['course_name'],
                'code' => $course['course_code'],
                'classroom' => $course['classroom_name'],
                'progress' => round($progress)
            ];
        }
        
        // Get recent quiz results
        $stmt = $pdo->prepare("
            SELECT g.*, s.name as subject_name, s.code as subject_code
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            WHERE g.student_id = ? AND g.grade_type = 'quiz'
            ORDER BY g.graded_at DESC
            LIMIT 5
        ");
        $stmt->execute([$studentId]);
        $quizResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate overall progress
        if (!empty($courses)) {
            $totalProgress = array_sum(array_column($courses, 'progress'));
            $overallProgress = round($totalProgress / count($courses));
        }
        
        // Get GPA
        $stmt = $pdo->prepare("
            SELECT * FROM student_gpa 
            WHERE student_id = ? 
            ORDER BY academic_year DESC, semester DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $gpa = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } else {
        // Basic structure - get courses from grades
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.name as course_name, s.code as course_code,
                   c.name as classroom_name,
                   AVG(g.grade) as avg_grade
            FROM subjects s
            JOIN grades g ON s.id = g.subject_id
            LEFT JOIN classrooms c ON g.classroom_id = c.id
            WHERE g.student_id = ?
            GROUP BY s.id, s.name, s.code, c.name
            ORDER BY s.name
        ");
        $stmt->execute([$studentId]);
        $coursesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($coursesData as $course) {
            $progress = $course['avg_grade'] ? min(100, ($course['avg_grade'] / 100) * 100) : 0;
            $courses[] = [
                'id' => $course['id'],
                'name' => $course['course_name'],
                'code' => $course['course_code'],
                'classroom' => $course['classroom_name'],
                'progress' => round($progress)
            ];
        }
        
        // Get quiz results
        $stmt = $pdo->prepare("
            SELECT g.*, s.name as subject_name, s.code as subject_code
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            WHERE g.student_id = ? AND (g.grade_type = 'quiz' OR g.grade_type IS NULL)
            ORDER BY g.graded_at DESC
            LIMIT 5
        ");
        $stmt->execute([$studentId]);
        $quizResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($courses)) {
            $totalProgress = array_sum(array_column($courses, 'progress'));
            $overallProgress = round($totalProgress / count($courses));
        }
    }
    
} catch (PDOException $e) {
    $message = 'Error retrieving data: ' . $e->getMessage();
    $message_type = 'error';
}

// Check if we should show welcome banner (set during login)
$showWelcome = isset($_SESSION['show_welcome']) && $_SESSION['show_welcome'] === true;
// Clear the flag after checking (so it only shows once per login)
if ($showWelcome) {
    unset($_SESSION['show_welcome']);
}

// Handle logout
if (isset($_POST['logout'])) {
    // Clear sessionStorage for welcome banner
    session_destroy();
    redirectTo('auth/student-login.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Colegio de Amore</title>
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
        
        /* Welcome Banner */
        .welcome-banner-container {
            margin-bottom: 35px;
        }
        
        .nav-section {
            margin-bottom: 25px;
        }
        
        .nav-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            opacity: 0.8;
            margin-bottom: 12px;
            padding: 0 20px;
            font-weight: 700;
            letter-spacing: 1.2px;
            color: white;
            text-align: left;
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
        
        .upgrade-box {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 18px 15px;
            margin: 0 15px 15px 15px;
            text-align: center;
        }
        
        .upgrade-box p {
            font-size: 0.85rem;
            margin-bottom: 12px;
            line-height: 1.4;
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
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .main-content.expanded {
            margin-left: 0;
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
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .profile-dropdown {
            position: relative;
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
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #ffe0e0 0%, #ffcccc 100%);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            margin-bottom: 30px;
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
        
        /* Profile Summary Card */
        .profile-summary-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            gap: 25px;
            align-items: flex-start;
        }
        
        .profile-summary-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
            border: 3px solid #ffe0e0;
        }
        
        .profile-summary-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: none;
        }
        
        .profile-summary-avatar.has-image img {
            display: block;
        }
        
        .profile-summary-avatar.has-image {
            background: transparent;
            font-size: 0;
        }
        
        .profile-summary-content {
            flex: 1;
            min-width: 0;
        }
        
        .profile-summary-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .profile-summary-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .profile-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            color: #666;
        }
        
        .profile-info-item i {
            width: 20px;
            text-align: center;
            color: #a11c27;
            font-size: 1rem;
        }
        
        .profile-info-item strong {
            color: #333;
            font-weight: 600;
            margin-right: 5px;
        }
        
        .profile-summary-footer {
            display: none;
        }
        
        /* Enrollment Status and Quick Actions Grid */
        .enrollment-actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        /* Enrollment Status Card */
        .enrollment-status-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 0;
        }
        
        .enrollment-status-header {
            margin-bottom: 25px;
        }
        
        .enrollment-status-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .enrollment-status-subtitle {
            font-size: 0.9rem;
            color: #999;
        }
        
        .enrollment-timeline {
            position: relative;
            padding-left: 80px;
        }
        
        .enrollment-timeline::before {
            content: '';
            position: absolute;
            left: 50px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }
        
        .enrollment-timeline-item {
            position: relative;
            margin-bottom: 25px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .enrollment-timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .enrollment-timeline-date {
            position: absolute;
            left: -80px;
            top: 0;
            font-size: 0.85rem;
            color: #666;
            width: 60px;
            text-align: right;
            padding-right: 15px;
            min-height: 20px;
            line-height: 1.4;
        }
        
        .enrollment-timeline-marker {
            position: relative;
            flex-shrink: 0;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid;
            background: white;
            z-index: 2;
            box-sizing: border-box;
            margin-top: 3px;
        }
        
        .enrollment-timeline-marker.completed {
            border-color: inherit;
        }
        
        .enrollment-timeline-marker.pending {
            border-color: #ccc;
            background: #f5f5f5;
        }
        
        .enrollment-timeline-content {
            flex: 1;
            padding-left: 0;
            margin-left: 0;
        }
        
        .enrollment-timeline-stage {
            font-size: 0.95rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .enrollment-timeline-time {
            font-size: 0.85rem;
            color: #999;
            margin-left: 0;
        }
        
        .enrollment-buttons-container {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .enrollment-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            flex: 1;
            min-width: 180px;
            color: white;
            background: #a11c27;
            position: relative;
        }
        
        .enrollment-button i {
            font-size: 0.95rem;
        }
        
        /* View Registration Button - Theme Red */
        .enrollment-view-button {
            background: #a11c27;
            color: white;
        }
        
        .enrollment-view-button:hover {
            background: #b31310;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(161, 28, 39, 0.3);
        }
        
        .enrollment-view-button:focus {
            outline: 2px solid #a11c27;
            outline-offset: 2px;
            background: #b31310;
        }
        
        .enrollment-view-button:active {
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(161, 28, 39, 0.2);
        }
        
        /* Enroll for Next Semester Button - Theme Red */
        .enrollment-enroll-button {
            color: white;
            background: #a11c27;
        }
        
        .enrollment-enroll-button:not(.disabled):hover {
            background: #b31310 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(161, 28, 39, 0.3);
        }
        
        .enrollment-enroll-button:not(.disabled):focus {
            outline: 2px solid #a11c27;
            outline-offset: 2px;
            background: #b31310 !important;
        }
        
        .enrollment-enroll-button:not(.disabled):active {
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(161, 28, 39, 0.2);
        }
        
        .enrollment-enroll-button.disabled {
            cursor: not-allowed;
            opacity: 0.6;
            background: #6c757d !important;
            pointer-events: none;
        }
        
        .enrollment-enroll-button.disabled:hover {
            transform: none;
            box-shadow: none;
            background: #6c757d !important;
        }
        
        .enrollment-enroll-button.disabled:focus {
            outline: 2px solid #6c757d;
            outline-offset: 2px;
        }
        
        /* Ensure enabled button is clickable */
        .enrollment-enroll-button:not(.disabled) {
            pointer-events: auto;
            cursor: pointer;
        }
        
        /* Remove any z-index or overlay issues */
        .enrollment-buttons-container {
            position: relative;
            z-index: 1;
        }
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
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
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .card:last-child {
            margin-bottom: 0;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
        }
        
        .view-more-link {
            color: #a11c27;
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .view-more-link:hover {
            color: #b31310;
        }
        
        /* Course List */
        
        .course-list {
            margin-bottom: 20px;
        }
        
        .course-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px 15px;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 8px;
        }
        
        .course-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .course-initial {
            width: 45px;
            height: 45px;
            border-radius: 8px;
            background: #a11c27;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .course-info {
            flex: 1;
        }
        
        .course-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }
        
        .course-code {
            font-size: 0.85rem;
            color: #999;
        }
        
        .course-progress {
            text-align: right;
        }
        
        .progress-percentage {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .progress-percentage.high {
            color: #28a745;
        }
        
        .progress-percentage.medium {
            color: #333;
        }
        
        .progress-percentage.low {
            color: #dc3545;
        }
        
        .view-course-btn {
            background: transparent;
            border: none;
            color: #a11c27;
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 5px;
            transition: transform 0.2s;
            text-decoration: none;
        }
        
        .view-course-btn:hover {
            color: #b31310;
            transform: translateX(3px);
        }
        
        
        /* Recent Results */
        .result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 0;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 8px;
        }
        
        .result-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .result-info {
            flex: 1;
        }
        
        .result-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        .result-type {
            font-size: 0.8rem;
            color: #999;
            margin-bottom: 8px;
        }
        
        .result-progress-bar {
            width: 100%;
            max-width: 200px;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .result-progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s;
        }
        
        .result-progress-fill.red {
            background: #dc3545;
        }
        
        .result-progress-fill.green {
            background: #28a745;
        }
        
        .result-progress-fill.blue {
            background: #007bff;
        }
        
        .result-progress-fill.orange {
            background: #ff9800;
        }
        
        .result-progress-fill.yellow {
            background: #ffc107;
        }
        
        .result-progress-fill.black {
            background: #333;
        }
        
        .result-percentage {
            font-weight: 700;
            min-width: 50px;
            text-align: right;
            font-size: 1.1rem;
            color: #333;
        }
        
        /* GPA Display Card */
        .gpa-display-card {
            padding: 10px 0;
        }
        
        .gpa-main {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, #ffe0e0 0%, #ffcccc 100%);
            border-radius: 12px;
            margin-bottom: 25px;
        }
        
        .gpa-number {
            font-size: 3.5rem;
            font-weight: 700;
            color: #a11c27;
            line-height: 1;
            margin-bottom: 8px;
        }
        
        .gpa-label {
            font-size: 1rem;
            color: #666;
            font-weight: 600;
        }
        
        .gpa-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .gpa-detail-item {
            display: flex;
            flex-direction: column;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .gpa-detail-label {
            font-size: 0.75rem;
            color: #999;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .gpa-detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        .status-passed {
            color: #28a745;
        }
        
        .status-failed {
            color: #dc3545;
        }
        
        /* Action Cards */
        .action-cards {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .action-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 0;
        }
        
        .action-card:hover {
            border-color: #a11c27;
            box-shadow: 0 2px 8px rgba(161, 28, 39, 0.1);
            transform: translateX(5px);
        }
        
        .action-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: #ffe0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a11c27;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
            font-size: 0.95rem;
        }
        
        .action-description {
            font-size: 0.8rem;
            color: #999;
        }
        
        .action-arrow {
            color: #a11c27;
            font-size: 0.9rem;
            transition: transform 0.2s;
        }
        
        .action-card:hover .action-arrow {
            color: #b31310;
            transform: translateX(3px);
        }
        
        /* Ensure enrollment button is always visible */
        .enrollment-action-card {
            display: flex !important;
            visibility: visible !important;
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
        
        /* Mobile Menu Toggle */
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
        
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                margin-left: 250px;
                padding: 20px;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
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
            
            .sidebar {
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
            
            .top-header {
                flex-direction: row;
                gap: 15px;
                padding: 15px 20px;
                justify-content: space-between;
                align-items: center;
            }
            
            .page-title {
                flex: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            .header-actions {
                flex-wrap: nowrap;
                width: auto;
                flex-shrink: 0;
            }
            
            /* Hide dropdown menu on mobile */
            .profile-dropdown-menu {
                display: none !important;
            }
            
            /* Make profile icon redirect directly on mobile */
            .profile-icon {
                cursor: pointer;
            }
            
            .welcome-banner {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }
            
            .action-cards {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .card {
                padding: 15px;
            }
            
            .profile-summary-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 20px;
            }
            
            .profile-summary-info {
                grid-template-columns: 1fr;
                text-align: left;
            }
            
            .enrollment-actions-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .enrollment-status-card {
                padding: 20px;
                margin-bottom: 0;
            }
            
            .enrollment-timeline {
                padding-left: 60px;
            }
            
            .enrollment-timeline::before {
                left: 35px;
            }
            
            .enrollment-timeline-date {
                left: -60px;
                width: 50px;
                font-size: 0.75rem;
                padding-right: 10px;
            }
            
            .enrollment-timeline-item {
                gap: 12px;
            }
            
            .enrollment-timeline-marker {
                width: 14px;
                height: 14px;
                margin-top: 2px;
            }
            
            .enrollment-timeline-content {
                padding-left: 0;
            }
            
            .enrollment-buttons-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .enrollment-button {
                width: 100%;
                min-width: unset;
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
            
            .btn {
                padding: 8px 12px;
                font-size: 0.85rem;
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
        
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
                padding-top: 70px;
            }
            
            .top-header {
                padding: 12px 15px;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .welcome-banner h2 {
                font-size: 1.2rem;
            }
            
            .stat-value {
                font-size: 1.4rem;
            }
            
            .card {
                padding: 12px;
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
                <a href="student-dashboard.php" class="nav-item active">
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
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <h1 class="page-title">Dashboard</h1>
            <div class="header-actions">
                <div class="profile-dropdown">
                    <div class="profile-icon" id="profileIcon" onclick="handleProfileIconClick()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-dropdown-menu" id="profileDropdown">
                        <a href="student-profile.php" class="profile-dropdown-item">
                            <i class="fas fa-user-edit"></i>
                            <span>Profile</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($isRejected && $rejectionMessage): ?>
            <div class="message rejection-notice" style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; flex-shrink: 0;"></i>
                <div style="flex: 1;">
                    <strong style="display: block; margin-bottom: 5px; font-size: 1.1rem;">Application Rejected</strong>
                    <p style="margin: 0; line-height: 1.6;"><?= htmlspecialchars($rejectionMessage) ?></p>
                    <?php if (!empty($rejectionStatus['rejected_at'])): ?>
                        <p style="margin: 5px 0 0 0; font-size: 0.85rem; opacity: 0.8;">
                            Rejected on: <?= date('F d, Y', strtotime($rejectionStatus['rejected_at'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$isApproved): ?>
            <!-- Pending Approval View -->
            <div class="welcome-banner-container">
                <div class="welcome-banner" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);">
                    <div class="welcome-content">
                        <h2>Welcome, <?php if ($student): ?><?= htmlspecialchars($student['first_name']) ?><?php else: ?>Student<?php endif; ?>!</h2>
                        <p>Your enrollment is under review.</p>
                    </div>
                </div>
            </div>
            
            <div class="card" style="max-width: 800px; margin: 0 auto;">
                <div class="card-header" style="text-align: center; border-bottom: 2px solid #ffe0e0; padding-bottom: 20px; margin-bottom: 30px;">
                    <h2 class="card-title" style="font-size: 1.8rem; color: #a11c27;">
                        <i class="fas fa-hourglass-half" style="margin-right: 10px;"></i>
                        Enrollment Status
                    </h2>
                </div>
                
                <div style="text-align: center; padding: 20px 0;">
                    <?php if ($admissionInfo && !empty($admissionInfo['application_number'])): ?>
                        <div style="margin-bottom: 30px;">
                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">
                                Admission Number
                            </div>
                            <div style="font-size: 2rem; font-weight: 700; color: #a11c27; letter-spacing: 2px;">
                                <?= htmlspecialchars($admissionInfo['application_number']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; border-radius: 8px; margin-bottom: 30px; text-align: left;">
                        <div style="display: flex; align-items: flex-start; gap: 15px;">
                            <i class="fas fa-info-circle" style="font-size: 1.5rem; color: #ffc107; margin-top: 3px;"></i>
                            <div>
                                <h3 style="font-size: 1.1rem; font-weight: 600; color: #333; margin-bottom: 10px;">
                                    Your enrollment is under review
                                </h3>
                                <p style="color: #666; line-height: 1.6; margin: 0;">
                                    We are currently reviewing your enrollment application. You will be notified once your application has been processed.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 20px; border-radius: 8px; text-align: left; margin-bottom: 20px;">
                        <div style="display: flex; align-items: flex-start; gap: 15px;">
                            <i class="fas fa-file-upload" style="font-size: 1.5rem; color: #2196F3; margin-top: 3px;"></i>
                            <div style="flex: 1;">
                                <h3 style="font-size: 1.1rem; font-weight: 600; color: #333; margin-bottom: 10px;">
                                    Submit Your Requirements
                                </h3>
                                <p style="color: #666; line-height: 1.6; margin: 0;">
                                    Please submit all required documents face-to-face at the administration office. The admin will mark your requirements as received after reviewing them.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #e7f5e7; border-left: 4px solid #28a745; padding: 20px; border-radius: 8px; text-align: left;">
                        <div style="display: flex; align-items: flex-start; gap: 15px;">
                            <i class="fas fa-money-bill-wave" style="font-size: 1.5rem; color: #28a745; margin-top: 3px;"></i>
                            <div style="flex: 1;">
                                <h3 style="font-size: 1.1rem; font-weight: 600; color: #333; margin-bottom: 10px;">
                                    Submit Payment
                                </h3>
                                <p style="color: #666; line-height: 1.6; margin: 0;">
                                    Please submit your payment face-to-face at the administration office. The admin will mark your payment as received after verification.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Approved Student View -->
            <!-- Welcome Banner -->
            <div class="welcome-banner-container" id="welcomeBannerContainer">
                <div class="welcome-banner" id="welcomeBanner">
                    <div class="welcome-content">
                        <h2>Welcome back, <?php if ($student): ?><?= htmlspecialchars($student['first_name']) ?><?php else: ?>Student<?php endif; ?>!</h2>
                        <p>Keep up the excellent work!</p>
                    </div>
                </div>
            </div>
            
            <!-- Profile Summary Card -->
            <?php if ($student): ?>
                <?php 
                $profilePic = $student['profile_picture'] ?? null;
                $hasProfilePic = false;
                if ($profilePic) {
                    $relativePath = __DIR__ . '/' . $profilePic;
                    $absolutePath = strpos($profilePic, 'public/') === 0 ? __DIR__ . '/../' . $profilePic : $relativePath;
                    $hasProfilePic = file_exists($relativePath) || file_exists($absolutePath);
                }
                ?>
                <div class="profile-summary-card">
                    <div class="profile-summary-avatar <?= $hasProfilePic ? 'has-image' : '' ?>">
                        <?php if ($hasProfilePic && $profilePic): ?>
                            <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile Picture" onerror="this.style.display='none'; this.parentElement.classList.remove('has-image');">
                        <?php endif; ?>
                        <?php if (!$hasProfilePic): ?>
                            <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-summary-content">
                        <div class="profile-summary-name">
                            <?= htmlspecialchars(strtoupper($student['first_name'] . ' ' . $student['last_name'])) ?>
                        </div>
                        <div class="profile-summary-info">
                            <?php if (!empty($student['student_id_number'])): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-id-card"></i>
                                    <span><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id_number']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($student['email'])): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?= htmlspecialchars($student['email']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($student['phone_number'])): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?= htmlspecialchars($student['phone_number']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($studentCourseName)): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-graduation-cap"></i>
                                    <span><?= htmlspecialchars($studentCourseName) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($activeSemester): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><strong>Active Semester:</strong> <?= htmlspecialchars($activeSemester) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($studentSection)): ?>
                                <div class="profile-info-item">
                                    <i class="fas fa-users"></i>
                                    <span><strong>Section:</strong> <?= htmlspecialchars($studentSection) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="profile-summary-footer">
                            <!-- Footer content removed -->
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php
            /**
             * COMPREHENSIVE ENROLLMENT ELIGIBILITY CHECK
             * Implements full logic flow for enabling "Enroll for Next Semester" button
             */
            
            // Initialize variables
            $canEnroll = false;
            $enrollmentButtonText = 'Enroll for Next Semester';
            $enrollmentButtonDisabled = true; // Default to disabled for safety
            $enrollmentMessage = '';
            
            // Eligibility flags
            $scheduleActive = false;
            $dateInRange = false;
            $programMatch = false;
            $nextSemFlag = true; // Default to true if field doesn't exist
            $noPendingRequests = true;
            $gradesComplete = true; // Default to true if check not applicable
            $activeStatus = false;
            $noHolds = true; // Default to true if holds system not implemented
            
            try {
                // ============================================
                // STEP 1: FETCH ENROLLMENT REQUIREMENTS
                // ============================================
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'enrollment_periods'");
                if ($tableCheck->rowCount() > 0) {
                    // Get student's course_id and program info
                    $studentCourseId = null;
                    $studentProgram = null;
                    
                    // Method 1: Check if course_id is directly in users table
                    $studentCourseStmt = $pdo->prepare("SELECT course_id, program, status FROM users WHERE id = ?");
                    $studentCourseStmt->execute([$studentId]);
                    $studentData = $studentCourseStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($studentData) {
                        $studentCourseId = $studentData['course_id'];
                        $studentProgram = $studentData['program'] ?? null;
                        $activeStatus = ($studentData['status'] === 'active' || $studentData['status'] === 'enrolled');
                        
                        // Method 2: If no course_id, try to find it by matching program name to course name
                        if (!$studentCourseId && $studentProgram) {
                            $courseMatchStmt = $pdo->prepare("
                                SELECT id FROM courses 
                                WHERE name = ? OR name LIKE ? OR ? LIKE CONCAT('%', name, '%')
                                OR LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(?)
                                LIMIT 1
                            ");
                            $courseMatchStmt->execute([
                                $studentProgram, 
                                '%' . $studentProgram . '%', 
                                $studentProgram,
                                $studentProgram,
                                '%' . $studentProgram . '%'
                            ]);
                            $matchedCourse = $courseMatchStmt->fetch(PDO::FETCH_ASSOC);
                            if ($matchedCourse) {
                                $studentCourseId = $matchedCourse['id'];
                            }
                        }
                        
                        // Method 3: Try to get course_id from sections/classrooms if student is enrolled
                        if (!$studentCourseId) {
                            $enrollmentCourseStmt = $pdo->prepare("
                                SELECT DISTINCT c.id as course_id
                                FROM courses c
                                JOIN sections s ON c.id = s.course_id
                                JOIN classrooms cl ON (cl.section = s.section_name AND cl.program = c.name)
                                JOIN classroom_students cs ON cl.id = cs.classroom_id
                                WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled'
                                ORDER BY s.academic_year DESC, s.semester DESC
                                LIMIT 1
                            ");
                            $enrollmentCourseStmt->execute([$studentId]);
                            $enrollmentCourse = $enrollmentCourseStmt->fetch(PDO::FETCH_ASSOC);
                            if ($enrollmentCourse) {
                                $studentCourseId = $enrollmentCourse['course_id'];
                            }
                        }
                    }
                    
                    if ($studentCourseId) {
                        $programMatch = true;
                        $now = date('Y-m-d H:i:s');
                        $currentTime = time();
                        
                        // Check for active enrollment period
                        $periodStmt = $pdo->prepare("
                            SELECT id, start_date, end_date, status, academic_year, semester,
                                   CASE WHEN COLUMN_NAME = 'next_semester_flag' THEN next_semester_flag ELSE 1 END as next_semester_flag
                            FROM enrollment_periods 
                            WHERE course_id = ?
                            AND status = 'active'
                            ORDER BY start_date DESC
                            LIMIT 1
                        ");
                        $periodStmt->execute([$studentCourseId]);
                        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($period) {
                            $scheduleActive = true;
                            $startTime = strtotime($period['start_date']);
                            $endTime = strtotime($period['end_date']);
                            
                            // Check date range
                            $dateInRange = ($currentTime >= $startTime && $currentTime <= $endTime);
                            
                            // Check next_semester_flag (if column exists, default to true)
                            $nextSemFlag = isset($period['next_semester_flag']) ? (bool)$period['next_semester_flag'] : true;
                            
                            // ============================================
                            // STEP 2: VERIFY STUDENT ELIGIBILITY
                            // ============================================
                            
                            // 2.1 Check for pending enrollment requests
                            $pendingRequestStmt = $pdo->prepare("
                                SELECT status FROM enrollment_requests 
                                WHERE student_id = ? 
                                AND enrollment_period_id = ?
                                AND status = 'pending'
                                LIMIT 1
                            ");
                            $pendingRequestStmt->execute([$studentId, $period['id']]);
                            $pendingRequest = $pendingRequestStmt->fetch(PDO::FETCH_ASSOC);
                            $noPendingRequests = !$pendingRequest;
                            
                            if ($pendingRequest) {
                                $enrollmentButtonText = 'Processing Request';
                                $enrollmentButtonDisabled = true;
                                $enrollmentMessage = 'Your enrollment request is being processed.';
                            } else {
                                // 2.2 Check if all grades for previous semester are finalized
                                // Get current/previous semester info
                                $currentYear = (int)date('Y');
                                $currentMonth = (int)date('m');
                                
                                // Determine previous semester
                                $prevAcademicYear = '';
                                $prevSemester = '';
                                if ($currentMonth >= 6 && $currentMonth <= 10) {
                                    // Currently in 1st semester, previous was 2nd of last year
                                    $prevAcademicYear = ($currentYear - 1) . '-' . $currentYear;
                                    $prevSemester = '2nd';
                                } elseif ($currentMonth >= 11 || $currentMonth <= 3) {
                                    if ($currentMonth >= 11) {
                                        // Currently in 2nd semester, previous was 1st
                                        $prevAcademicYear = $currentYear . '-' . ($currentYear + 1);
                                        $prevSemester = '1st';
                                    } else {
                                        // Currently in Summer, previous was 2nd
                                        $prevAcademicYear = ($currentYear - 1) . '-' . $currentYear;
                                        $prevSemester = '2nd';
                                    }
                                } else {
                                    // Currently in Summer, previous was 2nd
                                    $prevAcademicYear = ($currentYear - 1) . '-' . $currentYear;
                                    $prevSemester = '2nd';
                                }
                                
                                // Check if student has missing grades (subjects without final grades)
                                $gradesCheckStmt = $pdo->prepare("
                                    SELECT COUNT(DISTINCT s.id) as missing_grades
                                    FROM sections sec
                                    JOIN section_schedules ss ON sec.id = ss.section_id
                                    JOIN subjects s ON ss.subject_id = s.id
                                    JOIN classroom_students cs ON sec.section_name = (
                                        SELECT cl.section FROM classrooms cl 
                                        JOIN classroom_students cs2 ON cl.id = cs2.classroom_id 
                                        WHERE cs2.student_id = ? AND cs2.enrollment_status = 'enrolled'
                                        LIMIT 1
                                    )
                                    WHERE sec.course_id = ?
                                    AND sec.academic_year = ?
                                    AND sec.semester = ?
                                    AND s.id NOT IN (
                                        SELECT DISTINCT subject_id FROM grades 
                                        WHERE student_id = ? 
                                        AND grade_type IN ('final', 'final_exam')
                                        AND (approval_status = 'approved' OR is_locked = 1)
                                    )
                                ");
                                $gradesCheckStmt->execute([$studentId, $studentCourseId, $prevAcademicYear, $prevSemester, $studentId]);
                                $gradesResult = $gradesCheckStmt->fetch(PDO::FETCH_ASSOC);
                                
                                // If query fails or returns no results, assume grades are complete
                                $gradesComplete = !$gradesResult || (int)$gradesResult['missing_grades'] === 0;
                                
                                if (!$gradesComplete) {
                                    $enrollmentButtonText = 'Complete Previous Grades';
                                    $enrollmentButtonDisabled = true;
                                    $enrollmentMessage = 'Please ensure all final grades for the previous semester are approved by the administration before enrolling.';
                                } else {
                                    // 2.3 Check student status (already checked above)
                                    // 2.4 Check for unresolved holds (fees, violations, etc.)
                                    // Check if student_holds table exists
                                    $holdsTableCheck = $pdo->query("SHOW TABLES LIKE 'student_holds'");
                                    if ($holdsTableCheck->rowCount() > 0) {
                                        $holdsStmt = $pdo->prepare("
                                            SELECT COUNT(*) as hold_count 
                                            FROM student_holds 
                                            WHERE student_id = ? 
                                            AND status = 'active'
                                        ");
                                        $holdsStmt->execute([$studentId]);
                                        $holdsResult = $holdsStmt->fetch(PDO::FETCH_ASSOC);
                                        $noHolds = !$holdsResult || (int)$holdsResult['hold_count'] === 0;
                                        
                                        if (!$noHolds) {
                                            $enrollmentButtonText = 'Resolve Holds';
                                            $enrollmentButtonDisabled = true;
                                            $enrollmentMessage = 'You have unresolved holds. Please contact the registrar.';
                                        }
                                    }
                                    
                                    // Check for any existing approved/rejected requests
                                    $existingRequestStmt = $pdo->prepare("
                                        SELECT status FROM enrollment_requests 
                                        WHERE student_id = ? AND enrollment_period_id = ?
                                        LIMIT 1
                                    ");
                                    $existingRequestStmt->execute([$studentId, $period['id']]);
                                    $existingRequest = $existingRequestStmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($existingRequest) {
                                        if ($existingRequest['status'] === 'approved') {
                                            $enrollmentButtonText = 'Enrollment Approved';
                                            $enrollmentButtonDisabled = true;
                                            $enrollmentMessage = 'Your enrollment has been approved.';
                                        } elseif ($existingRequest['status'] === 'rejected') {
                                            $enrollmentButtonText = 'Request Rejected';
                                            $enrollmentButtonDisabled = true;
                                            $enrollmentMessage = 'Your enrollment request was rejected.';
                                        }
                                    } else {
                                        // ============================================
                                        // STEP 3: DETERMINE FINAL ELIGIBILITY
                                        // ============================================
                                        $canEnroll = $scheduleActive 
                                                  && $dateInRange 
                                                  && $programMatch 
                                                  && $nextSemFlag 
                                                  && $noPendingRequests 
                                                  && $gradesComplete 
                                                  && $activeStatus 
                                                  && $noHolds;
                                        
                                        if ($canEnroll) {
                                            // ============================================
                                            // STEP 4: UPDATE UI STATE
                                            // ============================================
                                            $enrollmentButtonDisabled = false;
                                            $enrollmentButtonText = 'Enroll for Next Semester';
                                            $enrollmentMessage = 'Click to request enrollment for the next semester.';
                                        } else {
                                            // Determine which condition failed
                                            if (!$scheduleActive) {
                                                $enrollmentButtonText = 'No Active Schedule';
                                                $enrollmentMessage = 'No active enrollment period found for your program.';
                                            } elseif (!$dateInRange) {
                                                if ($currentTime < $startTime) {
                                                    $enrollmentButtonText = 'Enrollment Not Started';
                                                    $enrollmentMessage = 'The enrollment period starts on ' . date('M d, Y h:i A', $startTime) . '.';
                                                } else {
                                                    $enrollmentButtonText = 'Enrollment Closed';
                                                    $enrollmentMessage = 'The enrollment period ended on ' . date('M d, Y h:i A', $endTime) . '.';
                                                }
                                            } elseif (!$programMatch) {
                                                $enrollmentButtonText = 'Program Mismatch';
                                                $enrollmentMessage = 'Unable to match your program with an enrollment period.';
                                            } elseif (!$nextSemFlag) {
                                                $enrollmentButtonText = 'Not Available';
                                                $enrollmentMessage = 'Enrollment for next semester is not available at this time.';
                                            } elseif (!$activeStatus) {
                                                $enrollmentButtonText = 'Account Inactive';
                                                $enrollmentMessage = 'Your account is not active. Please contact the registrar.';
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // No active enrollment period found
                            $enrollmentButtonText = 'Wait for Your Turn';
                            $enrollmentButtonDisabled = true;
                            $enrollmentMessage = 'No enrollment period available for your program yet. Please check back later.';
                        }
                    } else {
                        // Student's course cannot be determined
                        $enrollmentButtonText = 'Enroll for Next Semester';
                        $enrollmentButtonDisabled = true;
                        $enrollmentMessage = 'Unable to determine your program. Please contact the registrar.';
                    }
                } else {
                    // enrollment_periods table doesn't exist
                    $enrollmentButtonText = 'Enroll for Next Semester';
                    $enrollmentButtonDisabled = true;
                    $enrollmentMessage = 'Enrollment system not available. Please contact support.';
                }
            } catch (PDOException $e) {
                // Set default values on error
                $enrollmentButtonText = 'Enroll for Next Semester';
                $enrollmentButtonDisabled = true;
                $enrollmentMessage = 'Unable to check enrollment status. Please contact support.';
                error_log("Enrollment eligibility check error: " . $e->getMessage());
            }
            ?>
            
            <!-- Enrollment Status and Quick Actions Grid -->
            <div class="enrollment-actions-grid">
                <!-- Enrollment Status Card -->
                <?php if ($enrollmentStatus && !empty($enrollmentStatus['timeline'])): ?>
                    <div class="enrollment-status-card">
                        <div class="enrollment-status-header">
                            <h2 class="enrollment-status-title">My Enrollment Status</h2>
                            <p class="enrollment-status-subtitle"><?= htmlspecialchars($enrollmentStatus['type']) ?></p>
                        </div>
                        <div class="enrollment-timeline">
                            <?php 
                            $previousDate = '';
                            foreach ($enrollmentStatus['timeline'] as $index => $item): 
                                // Only show date if it's different from the previous one
                                $showDate = ($item['date'] && $item['date'] !== $previousDate);
                                if ($item['date']) {
                                    $previousDate = $item['date'];
                                }
                            ?>
                                <div class="enrollment-timeline-item">
                                    <div class="enrollment-timeline-date">
                                        <?php if ($showDate): ?>
                                            <?= htmlspecialchars($item['date']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="enrollment-timeline-marker <?= $item['status'] ?>" style="border-color: <?= htmlspecialchars($item['color']) ?>"></div>
                                    <div class="enrollment-timeline-content">
                                        <div class="enrollment-timeline-stage"><?= htmlspecialchars($item['stage']) ?></div>
                                        <?php if ($item['time']): ?>
                                            <div class="enrollment-timeline-time"><?= htmlspecialchars($item['time']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Enrollment Action Buttons Container -->
                        <div class="enrollment-buttons-container">
                            <?php if ($enrollmentStatus['timeline'][count($enrollmentStatus['timeline']) - 1]['status'] === 'completed'): ?>
                                <a href="download-registration-form.php?sid=<?= session_id() ?>" target="_blank" class="enrollment-button enrollment-view-button">
                                    <i class="fas fa-file-download"></i>
                                    <span>View Registration form</span>
                                </a>
                            <?php endif; ?>
                            
                            <!-- Enroll for Next Semester Button - Always visible -->
                            <button type="button" 
                                    id="enrollNextSemesterBtn"
                                    class="enrollment-button enrollment-enroll-button <?= $enrollmentButtonDisabled ? 'disabled' : '' ?>" 
                                    <?php if ($enrollmentButtonDisabled): ?>
                                        disabled
                                    <?php else: ?>
                                        onclick="showEnrollmentConfirmation()"
                                    <?php endif; ?>
                                    data-can-enroll="<?= $canEnroll ? 'true' : 'false' ?>"
                                    data-disabled="<?= $enrollmentButtonDisabled ? 'true' : 'false' ?>"
                                    title="<?= htmlspecialchars($enrollmentMessage) ?>">
                                <i class="fas fa-user-plus"></i>
                                <span><?= htmlspecialchars($enrollmentButtonText) ?></span>
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Show enrollment button even when enrollment status card doesn't exist -->
                    <div class="enrollment-status-card">
                        <div class="enrollment-status-header">
                            <h2 class="enrollment-status-title">Enrollment</h2>
                        </div>
                        <div class="enrollment-buttons-container" style="border-top: none; padding-top: 0; margin-top: 0;">
                            <!-- Enroll for Next Semester Button - Always visible -->
                            <button type="button" 
                                    id="enrollNextSemesterBtnFallback"
                                    class="enrollment-button enrollment-enroll-button <?= $enrollmentButtonDisabled ? 'disabled' : '' ?>" 
                                    <?php if ($enrollmentButtonDisabled): ?>
                                        disabled
                                    <?php else: ?>
                                        onclick="showEnrollmentConfirmation()"
                                    <?php endif; ?>
                                    data-can-enroll="<?= $canEnroll ? 'true' : 'false' ?>"
                                    data-disabled="<?= $enrollmentButtonDisabled ? 'true' : 'false' ?>"
                                    title="<?= htmlspecialchars($enrollmentMessage) ?>">
                                <i class="fas fa-user-plus"></i>
                                <span><?= htmlspecialchars($enrollmentButtonText) ?></span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Quick Actions Card -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">
                        <h2 class="card-title">Quick Actions</h2>
                    </div>
                    
                    <div class="action-cards">
                        <a href="student-schedule.php" class="action-card" style="text-decoration: none; color: inherit;">
                            <div class="action-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">View Schedule</div>
                                <div class="action-description">Check your class schedule</div>
                            </div>
                            <i class="fas fa-chevron-right action-arrow"></i>
                        </a>
                        
                        <a href="student-grades.php" class="action-card" style="text-decoration: none; color: inherit;">
                            <div class="action-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">My Grades</div>
                                <div class="action-description">View all your grades</div>
                            </div>
                            <i class="fas fa-chevron-right action-arrow"></i>
                        </a>
                        
                        <a href="student-profile.php" class="action-card" style="text-decoration: none; color: inherit;">
                            <div class="action-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div class="action-content">
                                <div class="action-title">Profile</div>
                                <div class="action-description">Update your information</div>
                            </div>
                            <i class="fas fa-chevron-right action-arrow"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="content-grid">
                
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function handleProfileIconClick() {
            // On mobile, redirect directly to profile edit page
            if (window.innerWidth <= 768) {
                window.location.href = 'student-profile.php';
            } else {
                // On desktop, show dropdown
                toggleProfileDropdown();
            }
        }
        
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside (desktop only)
        document.addEventListener('click', function(event) {
            // Only handle dropdown closing on desktop
            if (window.innerWidth > 768) {
                const dropdown = document.getElementById('profileDropdown');
                const profileIcon = document.querySelector('.profile-icon');
                
                if (!profileIcon.contains(event.target) && !dropdown.contains(event.target)) {
                    dropdown.classList.remove('show');
                }
            }
        });
        
        // Sidebar toggle functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
            
            if (!sidebar) return;
            
            const isHidden = sidebar.classList.contains('hidden');
            const isActive = sidebar.classList.contains('active');
            const isMobile = window.innerWidth <= 768;
            
            if (isHidden) {
                // Show sidebar
                sidebar.classList.remove('hidden');
                if (isMobile) {
                    sidebar.classList.add('active');
                    if (overlay) overlay.classList.add('active');
                    if (toggleBtn) toggleBtn.classList.add('hide');
                } else {
                    // Desktop: just show it, no active class needed
                    if (toggleBtn) toggleBtn.style.display = 'none';
                }
                if (mainContent) mainContent.classList.remove('expanded');
            } else {
                // Sidebar is visible, toggle it
                if (isMobile) {
                    // Mobile: toggle active state
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
                } else {
                    // Desktop: hide sidebar
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
            
            if (sidebar) {
                sidebar.classList.remove('active');
                sidebar.classList.add('hidden');
                if (overlay) overlay.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn && window.innerWidth <= 768) {
                    toggleBtn.classList.remove('hide');
                }
            }
        }
        
        // Welcome banner visibility control
        document.addEventListener('DOMContentLoaded', function() {
            const welcomeBannerContainer = document.getElementById('welcomeBannerContainer');
            const welcomeBanner = document.getElementById('welcomeBanner');
            
            if (welcomeBannerContainer && welcomeBanner) {
                // Check if PHP set the show_welcome flag
                const shouldShowWelcome = <?php echo $showWelcome ? 'true' : 'false'; ?>;
                
                if (shouldShowWelcome) {
                    // Show the banner on login
                    welcomeBannerContainer.style.display = 'block';
                    welcomeBanner.style.display = 'flex';
                } else {
                    // Hide if not a fresh login
                    welcomeBannerContainer.style.display = 'none';
                }
            }
            
            // Hide welcome banner when navigation tabs are clicked
            const navItems = document.querySelectorAll('.nav-item');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Hide welcome banner when any nav item is clicked
                    if (welcomeBannerContainer) {
                        welcomeBannerContainer.style.display = 'none';
                    }
                    
                    // Close sidebar on mobile
                    if (window.innerWidth <= 768) {
                        if (sidebar) {
                            sidebar.classList.remove('active');
                            sidebar.classList.add('hidden');
                        }
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.classList.remove('hide');
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
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    // Desktop: sidebar visible by default
                    if (sidebar) {
                        sidebar.classList.remove('active');
                        sidebar.classList.remove('hidden');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.remove('expanded');
                    if (toggleBtn) toggleBtn.classList.add('hide');
                } else {
                    // Mobile: sidebar hidden by default
                    if (sidebar) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) toggleBtn.classList.remove('hide');
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
            
            // Also hide when clicking on quick action cards
            const actionCards = document.querySelectorAll('.action-card');
            actionCards.forEach(card => {
                card.addEventListener('click', function() {
                    if (welcomeBannerContainer) {
                        welcomeBannerContainer.style.display = 'none';
                    }
                });
            });
        });
        
        // Filter Courses
        function filterCourses() {
            const searchTerm = document.getElementById('courseSearch').value.toLowerCase();
            const progressFilter = document.getElementById('progressFilter').value;
            const courseItems = document.querySelectorAll('#courseList .course-item');
            const noResults = document.getElementById('noCourseResults');
            let visibleCount = 0;
            
            courseItems.forEach(item => {
                const courseName = item.getAttribute('data-course-name') || '';
                const courseCode = item.getAttribute('data-course-code') || '';
                const progress = item.getAttribute('data-progress') || '';
                
                const matchesSearch = courseName.includes(searchTerm) || courseCode.includes(searchTerm);
                const matchesFilter = !progressFilter || progress === progressFilter;
                
                if (matchesSearch && matchesFilter) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || progressFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
        
        // Filter Grades
        function filterGrades() {
            const searchTerm = document.getElementById('gradeSearch').value.toLowerCase();
            const typeFilter = document.getElementById('gradeTypeFilter').value.toLowerCase();
            const gradeItems = document.querySelectorAll('#gradeList .result-item');
            const noResults = document.getElementById('noGradeResults');
            let visibleCount = 0;
            
            gradeItems.forEach(item => {
                const subjectName = item.getAttribute('data-subject-name') || '';
                const gradeType = item.getAttribute('data-grade-type') || '';
                
                const matchesSearch = subjectName.includes(searchTerm);
                const matchesFilter = !typeFilter || gradeType === typeFilter;
                
                if (matchesSearch && matchesFilter) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || typeFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
        
        // Session Keep-Alive: Ping server every 5 minutes to keep session alive
        (function() {
            let keepAliveInterval;
            let isPageVisible = true;
            
            function pingServer() {
                if (!isPageVisible) return; // Don't ping if tab is not visible
                
                fetch('../session-keepalive.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'expired') {
                        // Session expired, redirect to login
                        clearInterval(keepAliveInterval);
                        window.location.href = '../auth/student-login.php';
                    }
                })
                .catch(error => {
                    // Silently fail - don't spam console with keep-alive errors
                    // Only log if it's a critical error (not a 404)
                    if (error.message && !error.message.includes('404')) {
                        console.error('Keep-alive ping failed:', error);
                    }
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
        // Enrollment Request Functionality
        function showEnrollmentConfirmation() {
            const btn = document.getElementById('enrollNextSemesterBtn') || document.getElementById('enrollNextSemesterBtnFallback');
            if (btn && btn.hasAttribute('disabled')) {
                return false;
            }
            
            if (confirm('Are you sure you want to submit an enrollment request for the next semester? This action requires admin approval.')) {
                // Create and submit form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href;
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'submit_enrollment_request';
                input.value = '1';
                form.appendChild(input);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // ============================================
        // STEP 5: FORCE DASHBOARD REFRESH
        // ============================================
        // Ensure button state is correct on page load and refresh eligibility
        document.addEventListener('DOMContentLoaded', function() {
            // Force refresh enrollment eligibility state
            refreshEnrollmentEligibility();
            
            // Also refresh on visibility change (when user returns to tab)
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    refreshEnrollmentEligibility();
                }
            });
        });
        
        /**
         * Refresh enrollment eligibility state from backend
         * Removes any cached or outdated UI states
         */
        function refreshEnrollmentEligibility() {
            const enrollBtn = document.getElementById('enrollNextSemesterBtn') || document.getElementById('enrollNextSemesterBtnFallback');
            if (!enrollBtn) return;
            
            // Get current state from data attributes (set by PHP)
            const canEnroll = enrollBtn.getAttribute('data-can-enroll') === 'true';
            const isDisabled = enrollBtn.getAttribute('data-disabled') === 'true';
            
            // Remove ALL previous states to prevent conflicts
            enrollBtn.removeAttribute('disabled');
            enrollBtn.classList.remove('disabled', 'btn-disabled', 'enrollment-disabled');
            enrollBtn.onclick = null;
            enrollBtn.style.pointerEvents = '';
            enrollBtn.style.opacity = '';
            enrollBtn.style.cursor = '';
            
            // Apply fresh state based on backend response
            if (canEnroll && !isDisabled) {
                // Button should be enabled
                enrollBtn.removeAttribute('disabled');
                enrollBtn.classList.remove('disabled');
                enrollBtn.onclick = showEnrollmentConfirmation;
                enrollBtn.style.pointerEvents = 'auto';
                enrollBtn.style.cursor = 'pointer';
                enrollBtn.style.opacity = '1';
            } else {
                // Button should be disabled
                enrollBtn.setAttribute('disabled', 'disabled');
                enrollBtn.classList.add('disabled');
                enrollBtn.onclick = null;
                enrollBtn.style.pointerEvents = 'none';
                enrollBtn.style.cursor = 'not-allowed';
                enrollBtn.style.opacity = '0.6';
            }
        }
        
        // Expose refresh function globally for manual refresh if needed
        window.refreshEnrollmentEligibility = refreshEnrollmentEligibility;
        
        // Show enrollment status popup on login if applicable
        <?php if ($enrollmentRequestStatus): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const status = <?= json_encode($enrollmentRequestStatus['status']) ?>;
            const reviewedAt = <?= json_encode($enrollmentRequestStatus['reviewed_at']) ?>;
            const rejectionReason = <?= json_encode($enrollmentRequestStatus['rejection_reason'] ?? '') ?>;
            
            if (status === 'approved') {
                alert('Your enrollment request has been approved! You have been automatically enrolled in courses for the next semester.');
            } else if (status === 'rejected') {
                let message = 'Your enrollment request has been rejected.';
                if (rejectionReason) {
                    message += '\n\nReason: ' + rejectionReason;
                }
                message += '\n\nPlease contact the registrar for more information.';
                alert(message);
            }
        });
        <?php endif; ?>
    </script>
    
    <!-- Enrollment Confirmation Modal -->
    <div id="enrollmentConfirmationModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 10px; max-width: 400px; width: 90%; text-align: center;">
            <h3 style="margin-bottom: 20px; color: #a11c27;">Confirm Enrollment Request</h3>
            <p style="margin-bottom: 20px;">Are you sure you want to submit an enrollment request for the next semester? This action requires admin approval.</p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="submitEnrollmentRequest()" style="background: #a11c27; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Confirm</button>
                <button onclick="closeEnrollmentModal()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Cancel</button>
            </div>
        </div>
    </div>
    
    <script>
        function submitEnrollmentRequest() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'submit_enrollment_request';
            input.value = '1';
            form.appendChild(input);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function closeEnrollmentModal() {
            document.getElementById('enrollmentConfirmationModal').style.display = 'none';
        }
    </script>
</body>
</html>

