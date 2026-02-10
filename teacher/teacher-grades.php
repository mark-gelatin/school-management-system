<?php
// Teacher Grades Page
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
require_once getAbsolutePath('backend/includes/grade_edit_requests.php');
require_once getAbsolutePath('backend/includes/finals_grading_validation.php');

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    redirectTo('auth/staff-login.php');
}

$teacherId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle grade submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    // Ensure session is maintained
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Re-check authentication after POST
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
        redirectTo('auth/staff-login.php');
    }
    
    if ($_POST['action'] === 'add_grade') {
        $studentId = $_POST['student_id'];
        $subjectId = $_POST['subject_id'];
        $sectionId = $_POST['section_id'] ?? null;
        $grade = floatval($_POST['grade']);
        $gradeType = $_POST['grade_type'] ?? 'final';
        $maxPoints = floatval($_POST['max_points'] ?? 100);
        $remarks = $_POST['remarks'] ?? '';
        
        // STRICT ENFORCEMENT: Only allow final grades
        if ($gradeType !== 'final') {
            $message = 'Only final grades are allowed. Grade encoding is restricted to finals period only.';
            $message_type = 'error';
        } else {
            try {
                // Get section details to get academic_year and semester
                $academicYear = '';
                $semester = '';
                $classroomId = null;
                
                if ($sectionId) {
                    // Get section details
                    $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
                    $sectionStmt->execute([$sectionId]);
                    $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($section) {
                        $academicYear = $section['academic_year'];
                        $semester = $section['semester'];
                        
                        // Get course details
                        $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
                        $courseStmt->execute([$section['course_id']]);
                        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($course) {
                            // Find classroom for this section
                            $classroomStmt = $pdo->prepare("
                                SELECT id FROM classrooms
                                WHERE section = ? AND program = ? AND year_level = ?
                                ORDER BY id
                                LIMIT 1
                            ");
                            $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
                            $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($classroom) {
                                $classroomId = $classroom['id'];
                            }
                        }
                    }
                }
                
                if (!$classroomId || !$academicYear || !$semester) {
                    throw new Exception("Could not find classroom or semester information for the selected section");
                }
                
                // Use the new validation and submission function
                $result = submitFinalGrade(
                    $pdo,
                    $teacherId,
                    $studentId,
                    $subjectId,
                    $classroomId,
                    $grade,
                    $academicYear,
                    $semester,
                    $remarks,
                    $maxPoints
                );
                
                if ($result['success']) {
                    header("Location: teacher-grades.php?msg=" . urlencode($result['message']) . "&type=success");
                    exit();
                } else {
                    $message = $result['message'];
                    $message_type = 'error';
                }
            } catch (PDOException $e) {
                $message = 'Error adding grade: ' . $e->getMessage();
                $message_type = 'error';
                error_log("Grade submission error: " . $e->getMessage());
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
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

// Handle grade edit request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_edit') {
    $gradeId = intval($_POST['grade_id'] ?? 0);
    $requestReason = trim($_POST['request_reason'] ?? '');
    
    if (empty($requestReason)) {
        $message = 'Please provide a reason for the edit request.';
        $message_type = 'error';
    } else {
        $result = createGradeEditRequest($pdo, $teacherId, $gradeId, $requestReason);
        
        if ($result['success']) {
            $message = $result['message'];
            $message_type = 'success';
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
    }
}

// Check if grade_edit_requests table exists, if not show setup link
$tableExists = false;
$approvalStatusExists = false;
$isLockedExists = false;
$academicYearExists = false;
$semesterExists = false;
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
    $tableExists = $checkStmt->rowCount() > 0;
    
    // Check if required columns exist in grades table
    $columnsCheck = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'grades'
    ");
    $existingColumns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
    $approvalStatusExists = in_array('approval_status', $existingColumns);
    $isLockedExists = in_array('is_locked', $existingColumns);
    $academicYearExists = in_array('academic_year', $existingColumns);
    $semesterExists = in_array('semester', $existingColumns);
} catch (PDOException $e) {
    $tableExists = false;
    $approvalStatusExists = false;
    $isLockedExists = false;
    $academicYearExists = false;
    $semesterExists = false;
}

// Get all grades assigned by this teacher
$grades = [];
try {
    // Build query with explicit columns to avoid selecting non-existent columns
    // Base columns that should always exist in grades table
    $selectFields = "g.id, g.student_id, g.subject_id, g.classroom_id, g.teacher_id, 
                     g.grade, g.grade_type, g.max_points, 
                     g.remarks, g.graded_at, g.updated_at,
                     u.first_name, u.last_name, 
                     s.name as subject_name, s.code as subject_code,
                     c.name as classroom_name, c.section as classroom_section";
    
    // Add academic_year if it exists (already checked above)
    if ($academicYearExists) {
        $selectFields .= ", g.academic_year";
    } else {
        $selectFields .= ", NULL as academic_year";
    }
    
    // Add semester if it exists
    if ($semesterExists) {
        $selectFields .= ", g.semester";
    } else {
        $selectFields .= ", NULL as semester";
    }
    
    // Check for other optional columns once
    $optionalColumns = ['approved_by', 'approved_at', 'rejected_at', 'rejection_reason', 'submitted_at', 'edit_request_id', 'locked_at'];
    $existingOptionalColumns = [];
    try {
        $allColumnsCheck = $pdo->query("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'grades'
        ");
        $allExistingColumns = $allColumnsCheck->fetchAll(PDO::FETCH_COLUMN);
        foreach ($optionalColumns as $col) {
            $existingOptionalColumns[$col] = in_array($col, $allExistingColumns);
        }
    } catch (PDOException $e) {
        // If we can't check, assume columns don't exist
        foreach ($optionalColumns as $col) {
            $existingOptionalColumns[$col] = false;
        }
    }
    
    // Add optional columns if they exist
    if ($approvalStatusExists) {
        $selectFields .= ", g.approval_status";
    } else {
        $selectFields .= ", 'pending' as approval_status";
    }
    
    if ($isLockedExists) {
        $selectFields .= ", g.is_locked";
    } else {
        $selectFields .= ", 0 as is_locked";
    }
    
    // Add other optional columns
    foreach ($optionalColumns as $col) {
        if ($existingOptionalColumns[$col] ?? false) {
            $selectFields .= ", g.$col";
        } else {
            $selectFields .= ", NULL as $col";
        }
    }
    
    if ($tableExists) {
        $selectFields .= ", ger.status as edit_request_status, ger.id as edit_request_id_full";
        $joinClause = "LEFT JOIN grade_edit_requests ger ON g.edit_request_id = ger.id";
    } else {
        $selectFields .= ", NULL as edit_request_status, NULL as edit_request_id_full";
        $joinClause = "";
    }
    
    $query = "
        SELECT $selectFields
        FROM grades g
        JOIN users u ON g.student_id = u.id
        LEFT JOIN subjects s ON g.subject_id = s.id
        LEFT JOIN classrooms c ON g.classroom_id = c.id
        $joinClause
        WHERE g.teacher_id = ?
        ORDER BY g.graded_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$teacherId]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check if any grades have NULL grade_type (temporary - remove after fixing)
    // foreach ($grades as $g) {
    //     if (empty($g['grade_type']) || $g['grade_type'] === null) {
    //         error_log("Grade ID {$g['id']} has NULL grade_type");
    //     }
    // }
} catch (PDOException $e) {
    $message = 'Error retrieving grades: ' . $e->getMessage();
    $message_type = 'error';
    
    // If error is due to missing table or column, provide setup link
    if (strpos($e->getMessage(), 'grade_edit_requests') !== false || 
        strpos($e->getMessage(), 'approval_status') !== false ||
        strpos($e->getMessage(), 'is_locked') !== false ||
        strpos($e->getMessage(), 'academic_year') !== false ||
        strpos($e->getMessage(), 'semester') !== false) {
        $message .= ' <a href="setup-grade-edit-requests.php" style="color: #a11c27; text-decoration: underline; font-weight: 600;">Click here to set up the required database tables and columns</a>';
    }
}

// Handle AJAX requests for cascading dropdowns
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_sections') {
        $courseId = $_GET['course_id'] ?? null;
        if ($courseId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT s.*, c.code as course_code, c.name as course_name
                    FROM sections s
                    JOIN courses c ON s.course_id = c.id
                    WHERE s.course_id = ? AND s.status = 'active'
                    ORDER BY s.year_level, s.section_name
                ");
                $stmt->execute([$courseId]);
                $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($sections);
            } catch (PDOException $e) {
                echo json_encode([]);
            }
        } else {
            echo json_encode([]);
        }
        exit();
    }
    
    if ($_GET['action'] === 'get_students') {
        $sectionId = $_GET['section_id'] ?? null;
        if ($sectionId) {
            try {
                // Get section details
                $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
                $sectionStmt->execute([$sectionId]);
                $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($section) {
                    // Get course details
                    $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
                    $courseStmt->execute([$section['course_id']]);
                    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($course) {
                        // Find classroom for this section
                        $classroomStmt = $pdo->prepare("
                            SELECT id FROM classrooms
                            WHERE section = ? AND program = ? AND year_level = ?
                            ORDER BY id
                            LIMIT 1
                        ");
                        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
                        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($classroom) {
                            // Get students in this classroom, sorted by surname first, alphabetically
                            $stmt = $pdo->prepare("
                                SELECT DISTINCT u.*, cs.classroom_id
                                FROM users u
                                JOIN classroom_students cs ON u.id = cs.student_id
                                WHERE cs.classroom_id = ? AND u.role = 'student'
                                ORDER BY u.last_name ASC, u.first_name ASC
                            ");
                            $stmt->execute([$classroom['id']]);
                            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            echo json_encode($students);
                        } else {
                            echo json_encode([]);
                        }
                    } else {
                        echo json_encode([]);
                    }
                } else {
                    echo json_encode([]);
                }
            } catch (PDOException $e) {
                echo json_encode([]);
            }
        } else {
            echo json_encode([]);
        }
        exit();
    }
    
    // Check finals period status for AJAX requests
    if ($_GET['action'] === 'check_finals_period') {
        $academicYear = $_GET['academic_year'] ?? '';
        $semester = $_GET['semester'] ?? '';
        
        if ($academicYear && $semester) {
            $finalsCheck = isFinalsPeriodActive($pdo, $academicYear, $semester);
            echo json_encode($finalsCheck);
        } else {
            echo json_encode(['active' => false, 'message' => 'Invalid parameters']);
        }
        exit();
    }
}

// Get courses for dropdown - separate active and completed
$courses = [];
$activeCourses = [];
$completedCourses = [];

try {
    // Get teacher's courses via section_schedules
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.*, s.academic_year, s.semester, s.status as section_status
        FROM courses c
        JOIN sections s ON c.id = s.course_id
        JOIN section_schedules ss ON s.id = ss.section_id
        WHERE ss.teacher_id = ?
        ORDER BY s.academic_year DESC, s.semester DESC, c.code
    ");
    $stmt->execute([$teacherId]);
    $teacherCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate into active and completed
    foreach ($teacherCourses as $course) {
        if ($course['section_status'] === 'active') {
            if (!isset($activeCourses[$course['id']])) {
                $activeCourses[$course['id']] = $course;
            }
        } elseif ($course['section_status'] === 'completed' || $course['section_status'] === 'archived') {
            if (!isset($completedCourses[$course['id']])) {
                $completedCourses[$course['id']] = $course;
            }
        }
    }
    
    // For dropdown, show only active courses
    $courses = array_values($activeCourses);
    
    // Fallback: if no courses found via section_schedules, try direct query
    if (empty($courses)) {
        $stmt = $pdo->query("SELECT * FROM courses WHERE status = 'active' ORDER BY code");
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Courses table might not exist or query failed
    error_log("Error fetching teacher courses: " . $e->getMessage());
}

// Get only subjects assigned to this teacher (handled courses)
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
        $stmt = $pdo->prepare("
            SELECT s.* 
            FROM subjects s
            INNER JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
            ORDER BY s.name
        ");
        $stmt->execute([$teacherId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Subjects table might not exist
    error_log("Error fetching teacher subjects: " . $e->getMessage());
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
    <title>Grades - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <?php include __DIR__ . '/../includes/teacher-sidebar-styles.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
        }
        
        /* Legacy support - map container to main-content */
        .container {
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            width: calc(100% - 280px);
            max-width: 100%;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .container.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        @media (max-width: 1024px) {
            .container {
                margin-left: 250px;
                width: calc(100% - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
                width: 100%;
                transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
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
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        @media (max-width: 768px) {
            .grades-table {
                font-size: 0.85rem;
            }
            
            .grades-table th,
            .grades-table td {
                padding: 10px 8px;
            }
            
            .grades-table th {
                font-size: 0.75rem;
            }
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
        
        .btn-primary:hover {
            background: #8a1620 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        
        /* Search and Filter Styles */
        .search-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            background: white;
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
            pointer-events: none;
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
            color: #333;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .filter-select option {
            padding: 10px;
        }
        
        /* Date input styling */
        input[type="date"].filter-select {
            position: relative;
            cursor: pointer;
        }
        
        input[type="date"].filter-select::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.7;
            margin-left: 5px;
        }
        
        input[type="date"].filter-select::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }
        
        .date-filter-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-filter-wrapper label {
            font-size: 0.85rem;
            color: #666;
            white-space: nowrap;
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
            
            .container {
                margin-left: 250px;
                width: calc(100% - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
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
            
            .container {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
                width: 100%;
                transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .search-filter-container {
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
                min-width: 100%;
            }
            
            .filter-select {
                width: 100%;
            }
            
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
                transition: none;
            }
        }
        
        @media (min-width: 769px) {
            body.sidebar-open {
                overflow: visible;
                position: static;
                width: auto;
                height: auto;
            }
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        
        .modal-dialog {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-header h5 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-primary {
            background: #a11c27;
            color: white;
        }
        
        .btn-primary:hover {
            background: #8a1620;
        }
        
        /* Mobile Modal Styles */
        @media (max-width: 768px) {
            .modal-dialog {
                width: 95%;
                max-height: 85vh;
                margin: 10px;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-header h5 {
                font-size: 1.1rem;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .modal-footer {
                padding: 12px 20px;
                flex-direction: column;
            }
            
            .modal-footer .btn {
                width: 100%;
            }
        }
        
        /* Form Styles for Add Grade */
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
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-success:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr !important;
            }
            
            .card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'grades';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header" style="display: flex; justify-content: space-between; align-items: center;">
            <h1><i class="fas fa-chart-bar"></i> Grades</h1>
            <button type="button" id="bulkGradesBtn" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: #a11c27; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.2s; font-size: 0.95rem; border: none; cursor: pointer; opacity: 0.6;" onclick="showBulkFeaturePopup()">
                <i class="fas fa-plus-circle"></i> Add Grades (Bulk)
            </button>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= $message_type === 'error' && strpos($message, 'grade_edit_requests') !== false ? $message : htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Add Grade Form -->
        <div class="card" style="margin-bottom: 30px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="card-title" style="font-size: 1.3rem; font-weight: 700; color: #333; border-bottom: 3px solid #a11c27; padding-bottom: 10px; margin: 0;">Add Grade</h2>
            </div>
            <form method="POST" id="addGradeForm" onsubmit="return validateGradeForm()">
                <input type="hidden" name="action" value="add_grade">
                <input type="hidden" name="section_id" id="section_id" value="">
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="course_id" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Course Code</label>
                        <select name="course_id" id="course_id" required style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                            <option value="">Select Course Code</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['id'] ?>">
                                    <?= htmlspecialchars($course['code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="section_select" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Section</label>
                        <select id="section_select" disabled style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                            <option value="">Select Section</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="student_id" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Student</label>
                    <select name="student_id" id="student_id" required disabled style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                        <option value="">Select Student</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="subject_id" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Course</label>
                    <select name="subject_id" id="subject_id" required style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                        <option value="">Select Course</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= $subject['id'] ?>">
                                <?= htmlspecialchars($subject['name'] . ' (' . $subject['code'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="grade" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Grade</label>
                        <input type="number" name="grade" id="grade" step="0.01" min="0" max="100" required style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="max_points" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Max Points</label>
                        <input type="number" name="max_points" id="max_points" value="100" min="1" required style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s;">
                    </div>
                </div>
                
                <!-- Finals-Only Grading: Grade type is always 'final' -->
                <input type="hidden" name="grade_type" id="grade_type" value="final">
                
                <!-- Finals Period Status Indicator -->
                <div id="finals-period-status" class="form-group" style="display: none; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                    <div id="finals-status-message" style="font-weight: 500;"></div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Grade Type</label>
                    <div style="padding: 10px; background: #f0f0f0; border-radius: 4px; color: #666;">
                        <i class="fas fa-info-circle"></i> Final Grade (Finals-only grading system)
                    </div>
                    <small style="color: #999; display: block; margin-top: 5px;">
                        Grade encoding is only available during the finals period for active courses.
                    </small>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="remarks" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem;">Remarks (Optional)</label>
                    <textarea name="remarks" id="remarks" rows="3" placeholder="Additional comments..." style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: 'Montserrat', sans-serif; transition: border-color 0.2s; resize: vertical;"></textarea>
                </div>
                
                <button type="submit" id="submitGradeBtn" class="btn btn-success" disabled style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-size: 1rem; font-weight: 600; transition: all 0.2s;">
                    <i class="fas fa-upload"></i> Submit Final Grade for Review
                </button>
                <small id="submit-btn-help" style="display: block; margin-top: 8px; color: #999; font-style: italic;">
                    Grade submission is only available during the active finals period.
                </small>
            </form>
        </div>
        
        <?php if (!$tableExists || !$approvalStatusExists || !$isLockedExists || !$academicYearExists || !$semesterExists): ?>
            <div class="message error">
                <strong>Database Setup Required:</strong> 
                <?php 
                $missingItems = [];
                if (!$tableExists) $missingItems[] = 'grade_edit_requests table';
                if (!$approvalStatusExists) $missingItems[] = 'approval_status column';
                if (!$isLockedExists) $missingItems[] = 'is_locked column';
                if (!$academicYearExists) $missingItems[] = 'academic_year column';
                if (!$semesterExists) $missingItems[] = 'semester column';
                ?>
                Missing: <?= implode(', ', $missingItems) ?>. 
                <a href="setup-grade-edit-requests.php" style="color: #a11c27; text-decoration: underline; font-weight: 600;">Click here to set up the required database tables and columns</a>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($grades)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="gradeSearch" placeholder="Search by student name, subject, or classroom..." onkeyup="filterGrades()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="subjectFilter" onchange="filterGrades()">
                    <option value="">All Subjects</option>
                    <?php 
                    $subjects = [];
                    foreach ($grades as $grade) {
                        if (!empty($grade['subject_name'])) {
                            $subjects[$grade['subject_name']] = $grade['subject_name'];
                        }
                    }
                    foreach ($subjects as $subject): ?>
                        <option value="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars($subject) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="typeFilter" onchange="filterGrades()">
                    <option value="">All Types</option>
                    <?php 
                    $types = [];
                    foreach ($grades as $grade) {
                        if (!empty($grade['grade_type'])) {
                            $types[$grade['grade_type']] = $grade['grade_type'];
                        }
                    }
                    foreach ($types as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= ucfirst(htmlspecialchars($type)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="classroomFilter" onchange="filterGrades()">
                    <option value="">All Classrooms</option>
                    <?php 
                    $classrooms = [];
                    foreach ($grades as $grade) {
                        if (!empty($grade['classroom_name'])) {
                            $classrooms[$grade['classroom_name']] = $grade['classroom_name'];
                        }
                    }
                    foreach ($classrooms as $classroom): ?>
                        <option value="<?= strtolower(htmlspecialchars($classroom)) ?>"><?= htmlspecialchars($classroom) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="sectionFilter" onchange="filterGrades()">
                    <option value="">All Sections</option>
                    <?php 
                    $sections = [];
                    foreach ($grades as $grade) {
                        if (!empty($grade['classroom_section'])) {
                            $sections[$grade['classroom_section']] = $grade['classroom_section'];
                        }
                    }
                    asort($sections); // Sort sections alphabetically
                    foreach ($sections as $section): ?>
                        <option value="<?= strtolower(htmlspecialchars($section)) ?>"><?= htmlspecialchars($section) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="date-filter-wrapper">
                    <input type="date" class="filter-select" id="gradeDate" onchange="filterGrades()" title="Filter by Date">
                </div>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <?php if (!empty($grades)): ?>
                <div class="table-responsive" style="overflow-x: auto;">
                <table class="grades-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Classroom</th>
                            <th>Section</th>
                            <th>Grade</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="gradesTableBody">
                        <?php foreach ($grades as $grade): ?>
                            <?php
                            $percentage = $grade['max_points'] > 0 ? round(($grade['grade'] / $grade['max_points']) * 100) : round($grade['grade']);
                            $phGrade = scoreToPhilippineGrade($grade['grade'], $grade['max_points'] ?? 100);
                            $badgeClass = getPhilippineGradeBadgeClass($phGrade);
                            $gradeDescription = getPhilippineGradeDescription($phGrade);
                            ?>
                            <tr class="grade-row" 
                                data-student-name="<?= strtolower(htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name'])) ?>"
                                data-subject-name="<?= strtolower(htmlspecialchars($grade['subject_name'] ?? 'N/A')) ?>"
                                data-classroom-name="<?= strtolower(htmlspecialchars($grade['classroom_name'] ?? 'N/A')) ?>"
                                data-section-name="<?= strtolower(htmlspecialchars($grade['classroom_section'] ?? '')) ?>"
                                data-grade-type="<?= strtolower(htmlspecialchars($grade['grade_type'] ?? '')) ?>"
                                data-grade-date="<?= date('Y-m-d', strtotime($grade['graded_at'])) ?>">
                                <td>
                                    <strong><?= htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name']) ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars($grade['subject_name'] ?? 'N/A') ?><br>
                                    <small style="color: #999;"><?= htmlspecialchars($grade['subject_code'] ?? '') ?></small>
                                </td>
                                <td><?= htmlspecialchars($grade['classroom_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if (!empty($grade['classroom_section'])): ?>
                                        <span style="display: inline-block; padding: 4px 10px; border-radius: 4px; background: #e3f2fd; color: #1976d2; font-size: 0.85rem; font-weight: 600;">
                                            <?= htmlspecialchars($grade['classroom_section']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-style: italic;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="grade-badge <?= $badgeClass ?>" title="<?= htmlspecialchars($gradeDescription) ?>">
                                        <strong><?= formatPhilippineGrade($phGrade) ?></strong>
                                        <small style="display: block; font-size: 0.75rem; opacity: 0.8;">
                                            (<?= number_format($grade['grade'], 2) ?>/<?= number_format($grade['max_points'] ?? 100, 2) ?>)
                                        </small>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    // Get grade_type from the database result - explicitly check for NULL
                                    $gradeType = null;
                                    if (isset($grade['grade_type'])) {
                                        $gradeType = $grade['grade_type'];
                                        if ($gradeType === null || $gradeType === 'NULL' || trim($gradeType) === '' || strtolower(trim($gradeType)) === 'null') {
                                            $gradeType = null;
                                        } else {
                                            $gradeType = trim($gradeType);
                                        }
                                    }
                                    
                                    if (!empty($gradeType)) {
                                        $displayType = ucfirst(strtolower($gradeType));
                                        if (strtolower($gradeType) === 'midterm') {
                                            $displayType = 'Midterm';
                                        } elseif (strtolower($gradeType) === 'finals') {
                                            $displayType = 'Finals';
                                        }
                                        echo '<span class="type-badge" style="display: inline-block; padding: 4px 12px; background: #e9ecef; color: #495057; border-radius: 4px; font-size: 0.85rem; font-weight: 600;">' . htmlspecialchars($displayType) . '</span>';
                                    } else {
                                        // Grade type is NULL or empty - this means old grades need to be updated
                                        echo '<span style="color: #999; font-style: italic;" title="Grade type not set. Please update this grade.">-</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $approvalStatus = $grade['approval_status'] ?? 'pending';
                                    $isLocked = (int)($grade['is_locked'] ?? 0) === 1;
                                    $editRequestStatus = $grade['edit_request_status'] ?? null;
                                    
                                    if ($approvalStatus === 'approved' || $approvalStatus === 'locked') {
                                        if ($isLocked) {
                                            echo '<span class="badge" style="background: #dc3545; color: white; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">Locked</span>';
                                        } else {
                                            echo '<span class="badge" style="background: #28a745; color: white; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">Approved</span>';
                                        }
                                    } elseif ($approvalStatus === 'pending') {
                                        echo '<span class="badge" style="background: #ffc107; color: #333; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">Pending</span>';
                                    } elseif ($approvalStatus === 'rejected') {
                                        echo '<span class="badge" style="background: #dc3545; color: white; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">Rejected</span>';
                                    } else {
                                        echo '<span class="badge" style="background: #6c757d; color: white; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">' . htmlspecialchars(ucfirst($approvalStatus)) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($grade['graded_at'])) ?></td>
                                <td>
                                    <?php
                                    $canEdit = !$isLocked && ($approvalStatus !== 'approved' && $approvalStatus !== 'locked');
                                    $hasPendingRequest = $editRequestStatus === 'pending';
                                    $hasApprovedRequest = $editRequestStatus === 'approved' && !$isLocked;
                                    
                                    if ($isLocked || $approvalStatus === 'approved' || $approvalStatus === 'locked') {
                                        if ($hasPendingRequest) {
                                            echo '<span class="badge" style="background: #ffc107; color: #333; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem;">Request Pending</span>';
                                        } elseif ($hasApprovedRequest) {
                                            echo '<button onclick="editGrade(' . $grade['id'] . ')" class="btn-sm" style="background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">Edit (Approved)</button>';
                                        } else {
                                            echo '<button onclick="showEditRequestModal(' . $grade['id'] . ', \'' . htmlspecialchars($grade['subject_name'] ?? 'N/A', ENT_QUOTES) . '\', \'' . htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name'], ENT_QUOTES) . '\')" class="btn-sm" style="background: #a11c27; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">Request Edit</button>';
                                        }
                                    } elseif ($canEdit) {
                                        echo '<span style="color: #28a745; font-size: 0.85rem;">Editable</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div id="noGradeResults" class="no-results" style="display: none;">
                    <i class="fas fa-search"></i>
                    <p>No grades found matching your search</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <p>No grades assigned yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bulk Feature Popup Modal -->
    <div id="bulkFeatureModal" class="modal-overlay" style="display: none;" onclick="if(event.target === this) closeBulkFeatureModal();">
        <div class="modal-dialog" onclick="event.stopPropagation();">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle"></i> Feature Coming Soon
                </h5>
                <button type="button" class="modal-close" onclick="closeBulkFeatureModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p style="font-size: 1rem; line-height: 1.6; color: #333; margin: 0;">
                    The feature will be implemented soon.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeBulkFeatureModal()" class="btn btn-primary">OK</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Request Modal -->
    <div id="editRequestModal" class="modal-overlay" style="display: none;" onclick="if(event.target === this) closeEditRequestModal();">
        <div class="modal-dialog" onclick="event.stopPropagation();">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> Request Grade Edit
                </h5>
                <button type="button" class="modal-close" onclick="closeEditRequestModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="editRequestForm" class="modal-body">
                <input type="hidden" name="action" value="request_edit">
                <input type="hidden" name="grade_id" id="editRequestGradeId">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Student:</label>
                    <p id="editRequestStudent" style="margin: 0; padding: 10px; background: #f8f9fa; border-radius: 6px; color: #666;"></p>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Subject:</label>
                    <p id="editRequestSubject" style="margin: 0; padding: 10px; background: #f8f9fa; border-radius: 6px; color: #666;"></p>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="request_reason" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Reason for Edit Request <span style="color: #dc3545;">*</span>:</label>
                    <textarea name="request_reason" id="request_reason" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-family: 'Montserrat', sans-serif; font-size: 0.95rem; min-height: 120px; resize: vertical;" placeholder="Please provide a detailed reason for requesting to edit this grade..."></textarea>
                </div>
            </form>
            <div class="modal-footer">
                <button type="button" onclick="closeEditRequestModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" form="editRequestForm" class="btn btn-primary">Submit Request</button>
            </div>
        </div>
    </div>
    
    <script>
        // Bulk Feature Popup Functions
        function showBulkFeaturePopup() {
            const modal = document.getElementById('bulkFeatureModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeBulkFeatureModal() {
            const modal = document.getElementById('bulkFeatureModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }
        
        // Cascading dropdowns for Add Grade form
        document.addEventListener('DOMContentLoaded', function() {
            const courseSelect = document.getElementById('course_id');
            const sectionSelect = document.getElementById('section_select');
            const studentSelect = document.getElementById('student_id');
            const hiddenSectionId = document.getElementById('section_id');
            
            // Course Code change handler
            if (courseSelect) {
                courseSelect.addEventListener('change', function() {
                    const courseId = this.value;
                    
                    // Reset section and student dropdowns
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    sectionSelect.disabled = true;
                    studentSelect.innerHTML = '<option value="">Select Student</option>';
                    studentSelect.disabled = true;
                    hiddenSectionId.value = '';
                    
                    if (courseId) {
                        // Fetch sections for this course
                        fetch(`teacher-grades.php?action=get_sections&course_id=${courseId}`)
                            .then(response => response.json())
                            .then(sections => {
                                sectionSelect.innerHTML = '<option value="">Select Section</option>';
                                
                                if (sections && sections.length > 0) {
                                    sections.forEach(section => {
                                        const option = document.createElement('option');
                                        option.value = section.id;
                                        option.textContent = `${section.year_level} - Section ${section.section_name}`;
                                        // Add data attributes for finals period checking
                                        option.setAttribute('data-academic-year', section.academic_year || '');
                                        option.setAttribute('data-semester', section.semester || '');
                                        sectionSelect.appendChild(option);
                                    });
                                    sectionSelect.disabled = false;
                                } else {
                                    sectionSelect.innerHTML = '<option value="">No sections available</option>';
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching sections:', error);
                                sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                            });
                    }
                });
            }
            
            // Section change handler
            if (sectionSelect) {
                sectionSelect.addEventListener('change', function() {
                    const sectionId = this.value;
                    
                    // Reset student dropdown
                    studentSelect.innerHTML = '<option value="">Select Student</option>';
                    studentSelect.disabled = true;
                    hiddenSectionId.value = '';
                    
                    if (sectionId) {
                        // Set hidden field
                        hiddenSectionId.value = sectionId;
                        
                        // Fetch students for this section
                        fetch(`teacher-grades.php?action=get_students&section_id=${sectionId}`)
                            .then(response => response.json())
                            .then(students => {
                                studentSelect.innerHTML = '<option value="">Select Student</option>';
                                
                                if (students && students.length > 0) {
                                    students.forEach(student => {
                                        const option = document.createElement('option');
                                        option.value = student.id;
                                        // Display surname first, then first name (alphabetically sorted by last_name, first_name)
                                        const lastName = student.last_name || '';
                                        const firstName = student.first_name || '';
                                        const middleName = student.middle_name || '';
                                        const fullName = middleName ? 
                                            `${lastName}, ${firstName} ${middleName}`.trim() : 
                                            `${lastName}, ${firstName}`.trim();
                                        option.textContent = fullName;
                                        studentSelect.appendChild(option);
                                    });
                                    studentSelect.disabled = false;
                                } else {
                                    studentSelect.innerHTML = '<option value="">No students in this section</option>';
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching students:', error);
                                studentSelect.innerHTML = '<option value="">Error loading students</option>';
                            });
                    }
                });
            }
        });
        
        // Finals Period Status Management
        let currentFinalsStatus = {
            active: false,
            message: ''
        };
        
        // Check finals period status for selected section
        function checkFinalsPeriodStatus() {
            const sectionSelect = document.getElementById('section_select');
            const sectionId = sectionSelect.value;
            
            if (!sectionId) {
                disableGradeSubmission('Please select a section first');
                return;
            }
            
            // Get academic year and semester from selected section option
            const selectedOption = sectionSelect.options[sectionSelect.selectedIndex];
            const academicYear = selectedOption.getAttribute('data-academic-year');
            const semester = selectedOption.getAttribute('data-semester');
            
            if (!academicYear || !semester) {
                disableGradeSubmission('Unable to determine semester information');
                return;
            }
            
            // Check finals period via AJAX
            fetch(`teacher-grades.php?action=check_finals_period&academic_year=${encodeURIComponent(academicYear)}&semester=${encodeURIComponent(semester)}`)
                .then(response => response.json())
                .then(data => {
                    currentFinalsStatus = data;
                    updateGradeFormState(data);
                })
                .catch(error => {
                    console.error('Error checking finals period:', error);
                    disableGradeSubmission('Error checking finals period status');
                });
        }
        
        // Update form state based on finals period status
        function updateGradeFormState(status) {
            const statusDiv = document.getElementById('finals-period-status');
            const statusMessage = document.getElementById('finals-status-message');
            const submitBtn = document.getElementById('submitGradeBtn');
            const submitHelp = document.getElementById('submit-btn-help');
            const gradeInput = document.getElementById('grade');
            const remarksInput = document.getElementById('remarks');
            
            if (status.active) {
                // Finals period is active - enable form
                statusDiv.style.display = 'block';
                statusDiv.style.background = '#d4edda';
                statusDiv.style.border = '1px solid #c3e6cb';
                statusDiv.style.color = '#155724';
                statusMessage.innerHTML = '<i class="fas fa-check-circle"></i> Finals period is active. You can submit grades.';
                
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-secondary');
                submitBtn.classList.add('btn-success');
                gradeInput.disabled = false;
                remarksInput.disabled = false;
                submitHelp.style.display = 'none';
            } else {
                // Finals period is not active - disable form
                statusDiv.style.display = 'block';
                statusDiv.style.background = '#f8d7da';
                statusDiv.style.border = '1px solid #f5c6cb';
                statusDiv.style.color = '#721c24';
                statusMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (status.message || 'Finals period is not active');
                
                submitBtn.disabled = true;
                submitBtn.classList.remove('btn-success');
                submitBtn.classList.add('btn-secondary');
                gradeInput.disabled = true;
                remarksInput.disabled = true;
                submitHelp.style.display = 'block';
                submitHelp.innerHTML = status.message || 'Grade submission is only available during the active finals period.';
            }
        }
        
        // Disable grade submission
        function disableGradeSubmission(message) {
            const statusDiv = document.getElementById('finals-period-status');
            const statusMessage = document.getElementById('finals-status-message');
            const submitBtn = document.getElementById('submitGradeBtn');
            const gradeInput = document.getElementById('grade');
            const remarksInput = document.getElementById('remarks');
            
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.border = '1px solid #ffeaa7';
            statusDiv.style.color = '#856404';
            statusMessage.innerHTML = '<i class="fas fa-info-circle"></i> ' + (message || 'Select a section to check finals period status');
            
            submitBtn.disabled = true;
            submitBtn.classList.remove('btn-success');
            submitBtn.classList.add('btn-secondary');
            gradeInput.disabled = true;
            remarksInput.disabled = true;
        }
        
        // Update section select to include academic year and semester data
        document.addEventListener('DOMContentLoaded', function() {
            const sectionSelect = document.getElementById('section_select');
            if (sectionSelect) {
                // Monitor section changes
                sectionSelect.addEventListener('change', function() {
                    checkFinalsPeriodStatus();
                });
            }
            
            // Initially disable form
            disableGradeSubmission('Please select a section to enable grade submission');
            
            // Close bulk modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const bulkModal = document.getElementById('bulkFeatureModal');
                    if (bulkModal && bulkModal.style.display === 'flex') {
                        closeBulkFeatureModal();
                    }
                }
            });
        });
        
        // Form validation
        function validateGradeForm() {
            const courseId = document.getElementById('course_id').value;
            const sectionSelect = document.getElementById('section_select');
            const sectionId = document.getElementById('section_id').value;
            const studentId = document.getElementById('student_id').value;
            
            if (!courseId) {
                alert('Please select a course code');
                return false;
            }
            
            if (!sectionId || !sectionSelect.value) {
                alert('Please select a section');
                return false;
            }
            
            if (!studentId) {
                alert('Please select a student');
                return false;
            }
            
            // Check finals period status
            if (!currentFinalsStatus.active) {
                alert('Cannot submit grades: ' + (currentFinalsStatus.message || 'Finals period is not active'));
                return false;
            }
            
            // Confirm submission
            return confirm('Submit this final grade for admin review? Once submitted, it will be pending approval.');
        }
        
        function filterGrades() {
            const searchTerm = document.getElementById('gradeSearch').value.toLowerCase();
            const subjectFilter = document.getElementById('subjectFilter').value.toLowerCase();
            const typeFilter = document.getElementById('typeFilter').value.toLowerCase();
            const classroomFilter = document.getElementById('classroomFilter').value.toLowerCase();
            const sectionFilter = document.getElementById('sectionFilter').value.toLowerCase();
            const gradeDate = document.getElementById('gradeDate').value || '';
            const gradeRows = document.querySelectorAll('#gradesTableBody .grade-row');
            const noResults = document.getElementById('noGradeResults');
            let visibleCount = 0;
            
            gradeRows.forEach(row => {
                const studentName = row.getAttribute('data-student-name') || '';
                const subjectName = row.getAttribute('data-subject-name') || '';
                const classroomName = row.getAttribute('data-classroom-name') || '';
                const sectionName = row.getAttribute('data-section-name') || '';
                const gradeType = row.getAttribute('data-grade-type') || '';
                const rowGradeDate = row.getAttribute('data-grade-date') || '';
                
                const matchesSearch = !searchTerm || 
                    studentName.includes(searchTerm) || 
                    subjectName.includes(searchTerm) || 
                    classroomName.includes(searchTerm) ||
                    sectionName.includes(searchTerm);
                const matchesSubject = !subjectFilter || subjectName === subjectFilter;
                const matchesType = !typeFilter || gradeType === typeFilter;
                const matchesClassroom = !classroomFilter || classroomName === classroomFilter;
                const matchesSection = !sectionFilter || sectionName === sectionFilter;
                const matchesDate = !gradeDate || rowGradeDate === gradeDate;
                
                if (matchesSearch && matchesSubject && matchesType && matchesClassroom && matchesSection && matchesDate) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || subjectFilter || typeFilter || classroomFilter || sectionFilter || gradeDate)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
    </script>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
    
    <script>
        // Edit Request Modal Functions
        function showEditRequestModal(gradeId, subjectName, studentName) {
            const modal = document.getElementById('editRequestModal');
            const gradeIdInput = document.getElementById('editRequestGradeId');
            const subjectElement = document.getElementById('editRequestSubject');
            const studentElement = document.getElementById('editRequestStudent');
            const reasonTextarea = document.getElementById('request_reason');
            
            if (modal && gradeIdInput && subjectElement && studentElement && reasonTextarea) {
                gradeIdInput.value = gradeId;
                subjectElement.textContent = subjectName || 'N/A';
                studentElement.textContent = studentName || 'N/A';
                reasonTextarea.value = '';
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeEditRequestModal() {
            const modal = document.getElementById('editRequestModal');
            const form = document.getElementById('editRequestForm');
            
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
            
            if (form) {
                form.reset();
            }
        }
        
        // Initialize modal event handlers
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('editRequestModal');
            if (modal) {
                // Close modal when clicking outside (on overlay)
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeEditRequestModal();
                    }
                });
            }
            
            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('editRequestModal');
                    if (modal && modal.style.display === 'flex') {
                        closeEditRequestModal();
                    }
                }
            });
        });
        
        function editGrade(gradeId) {
            // Redirect to dashboard to edit the grade
            window.location.href = 'teacher-dashboard.php?edit_grade=' + gradeId;
        }
    </script>
</body>
</html>



