<?php
// API endpoint to get student schedule for real-time updates
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    $currentDir = __DIR__;
    $parentDir = dirname($currentDir);
    $projectRoot = dirname($parentDir);
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    if (file_exists($pathsFile)) {
        require_once $pathsFile;
    } else {
        // Fallback to VPS path
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('config/database.php');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$studentId = $_SESSION['user_id'];
$schedule = [];

try {
    // Get student information with multiple fallback strategies
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
            
            // Format times for JSON response
            foreach ($schedule as &$item) {
                if (isset($item['start_time'])) {
                    $item['start_time'] = date('h:i A', strtotime($item['start_time']));
                }
                if (isset($item['end_time'])) {
                    $item['end_time'] = date('h:i A', strtotime($item['end_time']));
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'schedule' => $schedule,
        'timestamp' => time()
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error retrieving schedule: ' . $e->getMessage()
    ]);
}
?>
