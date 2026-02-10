<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/system_functions.php';
include 'includes/email.php';
include 'includes/export_import.php';
include 'includes/backup_restore.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/finals_grading_validation.php';
require_once __DIR__ . '/../includes/grade_edit_requests.php';
require_once __DIR__ . '/../includes/grade_archiving.php';
requireRole('admin');

// Generate CSRF token for forms
generateCSRFToken();

$message = '';
$message_type = '';

// Get message from URL if redirected (only show once, then clear URL)
// Messages in URL indicate a redirect after POST action, so they're valid
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'];
    // URL will be cleared by JavaScript after message is displayed
}

// For courses tab, keep specific messages; only fall back to a contextual default when none is provided
if (($message_type === 'success') && (($_GET['tab'] ?? '') === 'subjects')) {
    $contextAction = $_GET['context'] ?? '';
    if (empty($message)) {
        switch ($contextAction) {
            case 'course_create':
                $message = 'Course added successfully!';
                break;
            case 'course_update':
                $message = 'Course updated successfully!';
                break;
            case 'course_delete':
                $message = 'Course deleted successfully!';
                break;
            default:
                $message = 'Course change saved.';
        }
    }
}

if (!function_exists('getCurrentAcademicYearRange')) {
    /**
     * Determine the current academic year range in the format YYYY-YYYY.
     */
    function getCurrentAcademicYearRange(): string
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        if ($month >= 6) {
            return $year . '-' . ($year + 1);
        }
        return ($year - 1) . '-' . $year;
    }
}

if (!function_exists('findCourseForProgram')) {
    /**
     * Attempt to locate a matching course record based on a program string.
     */
    function findCourseForProgram(PDO $pdo, string $program, ?string $fallback = null): ?array
    {
        $candidates = array_filter(array_map('trim', [$program, $fallback]));

        $keywords = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $keywords[] = $candidate;
            $keywords[] = preg_replace('/^bs\\.?\\s+/i', '', $candidate);
            $keywords[] = preg_replace('/^bachelor of science in\\s+/i', '', $candidate);
            $keywords[] = preg_replace('/^bachelor of science\\s+/i', '', $candidate);
        }
        $keywords = array_unique(array_filter($keywords, fn($value) => !empty($value)));

        if (empty($keywords)) {
            return null;
        }

        $exactStmt = $pdo->prepare("SELECT * FROM courses WHERE LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?) LIMIT 1");
        foreach ($keywords as $keyword) {
            $exactStmt->execute([$keyword, $keyword]);
            $course = $exactStmt->fetch(PDO::FETCH_ASSOC);
            if ($course) {
                return $course;
            }
        }

        $likeStmt = $pdo->prepare("SELECT * FROM courses WHERE LOWER(name) LIKE ? OR LOWER(code) LIKE ? LIMIT 1");
        foreach ($keywords as $keyword) {
            $likePattern = '%' . strtolower($keyword) . '%';
            $likeStmt->execute([$likePattern, $likePattern]);
            $course = $likeStmt->fetch(PDO::FETCH_ASSOC);
            if ($course) {
                return $course;
            }
        }

        return null;
    }
}

if (!function_exists('ensureSectionAndClassroom')) {
    /**
     * Ensure a section and classroom exist for the given course/year/section.
     * Creates missing records and returns the section data array.
     */
    function ensureSectionAndClassroom(PDO $pdo, array $course, string $yearLevel, string $sectionName, string $academicYear, string $semester = '1st', ?int $teacherId = null): array
    {
        $sectionStmt = $pdo->prepare("
            SELECT * FROM sections
            WHERE course_id = ? AND section_name = ? AND year_level = ?
            ORDER BY academic_year DESC
            LIMIT 1
        ");
        $sectionStmt->execute([$course['id'], $sectionName, $yearLevel]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            $maxStudents = 50;
            $insertSection = $pdo->prepare("
                INSERT INTO sections (course_id, section_name, year_level, academic_year, semester, teacher_id, max_students, current_students, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'active')
            ");
            $insertSection->execute([$course['id'], $sectionName, $yearLevel, $academicYear, $semester, $teacherId, $maxStudents]);
            $sectionId = (int)$pdo->lastInsertId();

            $insertClassroom = $pdo->prepare("
                INSERT INTO classrooms (name, description, teacher_id, program, year_level, section, academic_year, semester, max_students, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insertClassroom->execute([
                sprintf('%s %s - Section %s', $course['code'], $yearLevel, $sectionName),
                "Auto-generated classroom for {$course['name']} ({$yearLevel} - Section {$sectionName})",
                $teacherId,
                $course['name'],
                $yearLevel,
                $sectionName,
                $academicYear,
                $semester,
                $maxStudents
            ]);

            return [
                'id' => $sectionId,
                'section_name' => $sectionName,
                'year_level' => $yearLevel,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'teacher_id' => $teacherId,
                'max_students' => $maxStudents
            ];
        }

        $classroomStmt = $pdo->prepare("
            SELECT id FROM classrooms
            WHERE section = ? AND program = ? AND year_level = ?
            ORDER BY id
            LIMIT 1
        ");
        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
        if (!$classroomStmt->fetch(PDO::FETCH_ASSOC)) {
            $insertClassroom = $pdo->prepare("
                INSERT INTO classrooms (name, description, teacher_id, program, year_level, section, academic_year, semester, max_students, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insertClassroom->execute([
                sprintf('%s %s - Section %s', $course['code'], $section['year_level'], $section['section_name']),
                "Auto-generated classroom for {$course['name']} ({$section['year_level']} - Section {$section['section_name']})",
                $section['teacher_id'],
                $course['name'],
                $section['year_level'],
                $section['section_name'],
                $section['academic_year'],
                $section['semester'],
                $section['max_students'] ?? 50
            ]);
        }

        return $section;
    }
}

if (!function_exists('enrollStudentInSectionCourses')) {
    /**
     * Automatically enroll a student in all courses assigned to a section via section_schedules.
     * Creates initial grade entries to mark enrollment.
     * 
     * @param PDO $pdo Database connection
     * @param int $studentId Student ID
     * @param int $sectionId Section ID
     * @param int $classroomId Default classroom ID to use if schedule doesn't specify one
     * @param int|null $defaultTeacherId Default teacher ID to use if schedule doesn't specify one
     * @return int Number of courses enrolled
     */
    function enrollStudentInSectionCourses(PDO $pdo, int $studentId, int $sectionId, int $classroomId, ?int $defaultTeacherId = null): int {
        // Check if section_schedules table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $tableExists = $tableCheck->rowCount() > 0;
        
        if (!$tableExists) {
            return 0;
        }
        
        // Get section details for fallback teacher
        $sectionStmt = $pdo->prepare("SELECT teacher_id FROM sections WHERE id = ?");
        $sectionStmt->execute([$sectionId]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
        $sectionTeacherId = $section['teacher_id'] ?? $defaultTeacherId;
        
        // Get all subjects from section_schedules for this section
        $scheduleSubjectsStmt = $pdo->prepare("
            SELECT DISTINCT ss.subject_id, ss.teacher_id, ss.classroom_id
            FROM section_schedules ss
            WHERE ss.section_id = ? AND ss.status = 'active'
        ");
        $scheduleSubjectsStmt->execute([$sectionId]);
        $scheduleSubjects = $scheduleSubjectsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $enrolledCount = 0;
        foreach ($scheduleSubjects as $scheduleSubject) {
            $subjectId = $scheduleSubject['subject_id'];
            // Use teacher_id from schedule, fallback to section teacher, then default teacher
            $teacherId = $scheduleSubject['teacher_id'] ?? $sectionTeacherId;
            $scheduleClassroomId = $scheduleSubject['classroom_id'] ?? $classroomId;
            
            // If still no teacher_id, try to get it from the classroom
            if (!$teacherId) {
                $classroomTeacherStmt = $pdo->prepare("SELECT teacher_id FROM classrooms WHERE id = ?");
                $classroomTeacherStmt->execute([$scheduleClassroomId]);
                $classroomTeacher = $classroomTeacherStmt->fetch(PDO::FETCH_ASSOC);
                $teacherId = $classroomTeacher['teacher_id'] ?? null;
            }
            
            // Skip if no teacher_id is available (grades table requires teacher_id)
            // Note: Student will still see the course in their list via section_schedules,
            // but won't have a grade entry until a teacher is assigned
            if (!$teacherId) {
                error_log("Warning: Cannot create grade entry for student $studentId in subject $subjectId: No teacher assigned. Student will see course via section_schedules but enrollment record will be created when teacher is assigned.");
                continue;
            }
            
            // Check if grade entry already exists for this student and subject
            $checkGradeStmt = $pdo->prepare("
                SELECT id FROM grades 
                WHERE student_id = ? AND subject_id = ? AND classroom_id = ?
                LIMIT 1
            ");
            $checkGradeStmt->execute([$studentId, $subjectId, $scheduleClassroomId]);
            
            if (!$checkGradeStmt->fetch(PDO::FETCH_ASSOC)) {
                // Create initial enrollment record (grade entry with 0 to mark enrollment)
                // This ensures the student is properly enrolled and the course appears in their account
                try {
                    $insertGradeStmt = $pdo->prepare("
                        INSERT INTO grades (student_id, subject_id, classroom_id, teacher_id, grade, grade_type, max_points, remarks, graded_at)
                        VALUES (?, ?, ?, ?, 0, 'participation', 100, 'Initial enrollment - course assigned to section', NOW())
                    ");
                    $insertGradeStmt->execute([$studentId, $subjectId, $scheduleClassroomId, $teacherId]);
                    $enrolledCount++;
                } catch (PDOException $e) {
                    // Log error but continue with other enrollments
                    error_log("Failed to enroll student $studentId in subject $subjectId: " . $e->getMessage());
                }
            }
        }
        
        return $enrolledCount;
    }
}

if (!function_exists('propagateTeacherToSectionSchedules')) {
    /**
     * When a teacher is assigned to a subject, propagate the assignment to all section_schedules
     * that use that subject (if they don't already have a teacher assigned).
     * Also enrolls all students in those sections who don't have grade entries yet.
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher ID
     * @param int $subjectId Subject ID
     * @return array ['schedules_updated' => int, 'students_enrolled' => int]
     */
    function propagateTeacherToSectionSchedules(PDO $pdo, int $teacherId, int $subjectId): array {
        $result = ['schedules_updated' => 0, 'students_enrolled' => 0];
        
        // Check if section_schedules table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $tableExists = $tableCheck->rowCount() > 0;
        
        if (!$tableExists) {
            return $result;
        }
        
        // Get all section_schedules for this subject that don't have a teacher assigned
        $schedulesStmt = $pdo->prepare("
            SELECT ss.id, ss.section_id, ss.classroom_id
            FROM section_schedules ss
            WHERE ss.subject_id = ? AND ss.status = 'active' AND (ss.teacher_id IS NULL OR ss.teacher_id = 0)
        ");
        $schedulesStmt->execute([$subjectId]);
        $schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($schedules as $schedule) {
            // Update the schedule with the teacher
            $updateStmt = $pdo->prepare("UPDATE section_schedules SET teacher_id = ? WHERE id = ?");
            $updateStmt->execute([$teacherId, $schedule['id']]);
            $result['schedules_updated']++;
            
            // Get section details
            $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
            $sectionStmt->execute([$schedule['section_id']]);
            $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($section) {
                // Get classroom ID (use from schedule or find/create)
                $classroomId = $schedule['classroom_id'];
                if (!$classroomId) {
                    $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
                    $courseStmt->execute([$section['course_id']]);
                    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($course) {
                        $classroomStmt = $pdo->prepare("
                            SELECT id FROM classrooms
                            WHERE section = ? AND program = ? AND year_level = ?
                            ORDER BY id LIMIT 1
                        ");
                        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
                        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
                        $classroomId = $classroom['id'] ?? null;
                    }
                }
                
                if ($classroomId) {
                    // Get all students in this classroom who don't have a grade entry for this subject
                    $studentsStmt = $pdo->prepare("
                        SELECT DISTINCT cs.student_id
                        FROM classroom_students cs
                        WHERE cs.classroom_id = ? AND cs.enrollment_status = 'enrolled'
                        AND NOT EXISTS (
                            SELECT 1 FROM grades g
                            WHERE g.student_id = cs.student_id 
                            AND g.subject_id = ? 
                            AND g.classroom_id = ?
                        )
                    ");
                    $studentsStmt->execute([$classroomId, $subjectId, $classroomId]);
                    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create grade entries for these students
                    foreach ($students as $student) {
                        try {
                            $insertGradeStmt = $pdo->prepare("
                                INSERT INTO grades (student_id, subject_id, classroom_id, teacher_id, grade, grade_type, max_points, remarks, graded_at)
                                VALUES (?, ?, ?, ?, 0, 'participation', 100, 'Initial enrollment - teacher assigned to section', NOW())
                            ");
                            $insertGradeStmt->execute([$student['student_id'], $subjectId, $classroomId, $teacherId]);
                            $result['students_enrolled']++;
                        } catch (PDOException $e) {
                            error_log("Failed to enroll student {$student['student_id']} in subject $subjectId: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        return $result;
    }
}

if (!function_exists('enrollStudentsWhenTeacherAssigned')) {
    /**
     * When a teacher is assigned to a section_schedule, enroll all students in that section
     * who don't have grade entries yet.
     * 
     * @param PDO $pdo Database connection
     * @param int $scheduleId Section schedule ID
     * @param int $teacherId Teacher ID
     * @return int Number of students enrolled
     */
    function enrollStudentsWhenTeacherAssigned(PDO $pdo, int $scheduleId, int $teacherId): int {
        $enrolledCount = 0;
        
        // Get schedule details
        $scheduleStmt = $pdo->prepare("
            SELECT ss.subject_id, ss.section_id, ss.classroom_id
            FROM section_schedules ss
            WHERE ss.id = ?
        ");
        $scheduleStmt->execute([$scheduleId]);
        $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule) {
            return 0;
        }
        
        $subjectId = $schedule['subject_id'];
        $classroomId = $schedule['classroom_id'];
        
        // If no classroom_id in schedule, find it from section
        if (!$classroomId) {
            $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
            $sectionStmt->execute([$schedule['section_id']]);
            $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($section) {
                $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
                $courseStmt->execute([$section['course_id']]);
                $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($course) {
                    $classroomStmt = $pdo->prepare("
                        SELECT id FROM classrooms
                        WHERE section = ? AND program = ? AND year_level = ?
                        ORDER BY id LIMIT 1
                    ");
                    $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
                    $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
                    $classroomId = $classroom['id'] ?? null;
                }
            }
        }
        
        if ($classroomId) {
            // Get all students in this classroom who don't have a grade entry for this subject
            $studentsStmt = $pdo->prepare("
                SELECT DISTINCT cs.student_id
                FROM classroom_students cs
                WHERE cs.classroom_id = ? AND cs.enrollment_status = 'enrolled'
                AND NOT EXISTS (
                    SELECT 1 FROM grades g
                    WHERE g.student_id = cs.student_id 
                    AND g.subject_id = ? 
                    AND g.classroom_id = ?
                )
            ");
            $studentsStmt->execute([$classroomId, $subjectId, $classroomId]);
            $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create grade entries for these students
            foreach ($students as $student) {
                try {
                    $insertGradeStmt = $pdo->prepare("
                        INSERT INTO grades (student_id, subject_id, classroom_id, teacher_id, grade, grade_type, max_points, remarks, graded_at)
                        VALUES (?, ?, ?, ?, 0, 'participation', 100, 'Initial enrollment - teacher assigned to section', NOW())
                    ");
                    $insertGradeStmt->execute([$student['student_id'], $subjectId, $classroomId, $teacherId]);
                    $enrolledCount++;
                } catch (PDOException $e) {
                    error_log("Failed to enroll student {$student['student_id']} in subject $subjectId: " . $e->getMessage());
                }
            }
        }
        
        return $enrolledCount;
    }
}

if (!function_exists('ensureSectionSchedulesTable')) {
    /**
     * Ensure the section_schedules table exists to prevent runtime errors.
     */
    function ensureSectionSchedulesTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
            $exists = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $exists = false;
        }

        if ($exists) {
            return;
        }

        $createSql = "
            CREATE TABLE IF NOT EXISTS `section_schedules` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `section_id` int(11) NOT NULL,
                `subject_id` int(11) NOT NULL,
                `teacher_id` int(11) DEFAULT NULL,
                `classroom_id` int(11) DEFAULT NULL,
                `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
                `start_time` time NOT NULL,
                `end_time` time NOT NULL,
                `room` varchar(50) DEFAULT NULL,
                `academic_year` varchar(20) NOT NULL,
                `semester` enum('1st','2nd','Summer') DEFAULT '1st',
                `status` enum('active','inactive') DEFAULT 'active',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `section_id` (`section_id`),
                KEY `subject_id` (`subject_id`),
                KEY `teacher_id` (`teacher_id`),
                KEY `classroom_id` (`classroom_id`),
                KEY `idx_section_day` (`section_id`, `day_of_week`),
                KEY `idx_academic_year` (`academic_year`, `semester`),
                CONSTRAINT `section_schedules_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
                CONSTRAINT `section_schedules_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
                CONSTRAINT `section_schedules_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `section_schedules_ibfk_4` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        $pdo->exec($createSql);
    }
}

// Legacy CSRF token refresh endpoint (redirects to dedicated endpoint)
// Kept for backward compatibility, but new code should use api/get_csrf.php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh_csrf'])) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['csrf_token' => generateCSRFToken()]);
        exit();
    }
}

// AJAX endpoint for email uniqueness check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_email') {
    $email = trim($_GET['email'] ?? '');
    $user_id = intval($_GET['user_id'] ?? 0);
    
    header('Content-Type: application/json');
    
    if (empty($email)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['exists' => true, 'user_info' => 'Invalid format']);
        exit();
    }
    
    try {
        $check_stmt = $pdo->prepare("SELECT id, username, first_name, last_name FROM users WHERE email = ? AND id != ?");
        $check_stmt->execute([$email, $user_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'exists' => true,
                'user_info' => $existing_user['first_name'] . ' ' . $existing_user['last_name'] . ' (' . $existing_user['username'] . ')'
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
    } catch (Exception $e) {
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle form submissions
// Handle grade approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'approve_grade' || $_POST['action'] === 'reject_grade')) {
    $adminId = $_SESSION['user_id'];
    
    if ($_POST['action'] === 'approve_grade') {
        $gradeId = intval($_POST['grade_id'] ?? 0);
        
        try {
            // Get grade details first
            $stmt = $pdo->prepare("
                SELECT g.*, s.name as subject_name, u.first_name, u.last_name
                FROM grades g
                JOIN subjects s ON g.subject_id = s.id
                JOIN users u ON g.student_id = u.id
                WHERE g.id = ?
            ");
            $stmt->execute([$gradeId]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                throw new Exception('Grade not found');
            }
            
            $previousStatus = $grade['approval_status'] ?? 'pending';
            
            // Update grade to approved and locked
            $updateStmt = $pdo->prepare("
                UPDATE grades
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    is_locked = 1,
                    locked_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$adminId, $gradeId]);
            
            // Log action
            if (function_exists('logGradeAction')) {
                logGradeAction($pdo, $gradeId, 'approved', $adminId, 'admin', $previousStatus, 'approved', 'Grade approved by admin');
            }
            
            logAdminAction($pdo, $adminId, 'approve_grade', 'grade', $gradeId, "Approved final grade for student: {$grade['first_name']} {$grade['last_name']}, Subject: {$grade['subject_name']}");
            
            // Check if all grades for this course are approved and archive if so
            if (function_exists('checkAndArchiveCourse')) {
                $archiveResult = checkAndArchiveCourse($pdo, $gradeId);
                if ($archiveResult['archived']) {
                    $message = 'Grade approved successfully. Course has been archived and all grades are locked.';
                } else {
                    $message = 'Grade approved successfully. The grade is now locked and visible to students.';
                }
            } else {
                $message = 'Grade approved successfully. The grade is now locked and visible to students.';
            }
            $message_type = 'success';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=grade_approval&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $message = 'Error approving grade: ' . $e->getMessage();
            $message_type = 'error';
            error_log("Grade approval error: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'reject_grade') {
        $gradeId = intval($_POST['grade_id'] ?? 0);
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        
        try {
            // Get grade details
            $stmt = $pdo->prepare("
                SELECT g.*, s.name as subject_name, u.first_name, u.last_name
                FROM grades g
                JOIN subjects s ON g.subject_id = s.id
                JOIN users u ON g.student_id = u.id
                WHERE g.id = ?
            ");
            $stmt->execute([$gradeId]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                throw new Exception('Grade not found');
            }
            
            $previousStatus = $grade['approval_status'] ?? 'pending';
            
            // Update grade to rejected
            $updateStmt = $pdo->prepare("
                UPDATE grades
                SET approval_status = 'rejected',
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$rejectionReason, $gradeId]);
            
            // Log action
            if (function_exists('logGradeAction')) {
                logGradeAction($pdo, $gradeId, 'rejected', $adminId, 'admin', $previousStatus, 'rejected', $rejectionReason);
            }
            
            logAdminAction($pdo, $adminId, 'reject_grade', 'grade', $gradeId, "Rejected final grade for student: {$grade['first_name']} {$grade['last_name']}, Subject: {$grade['subject_name']}, Reason: $rejectionReason");
            
            $message = 'Grade rejected. The teacher will be notified.';
            $message_type = 'success';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=grade_approval&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $message = 'Error rejecting grade: ' . $e->getMessage();
            $message_type = 'error';
            error_log("Grade rejection error: " . $e->getMessage());
        }
    }
}

// Handle teacher edit requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'approve_edit_request' || $_POST['action'] === 'deny_edit_request' || $_POST['action'] === 'complete_edit')) {
    $adminId = $_SESSION['user_id'];
    
    if ($_POST['action'] === 'approve_edit_request') {
        $requestId = intval($_POST['request_id'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        
        $result = approveGradeEditRequest($pdo, $requestId, $adminId, $reviewNotes);
        
        if ($result['success']) {
            $message = $result['message'];
            $message_type = 'success';
            logAdminAction($pdo, $adminId, 'approve_edit_request', 'grade_edit_request', $requestId, "Approved grade edit request ID: $requestId");
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=teacher_requests&msg=" . urlencode($message) . "&type=" . $message_type);
        exit();
    } elseif ($_POST['action'] === 'deny_edit_request') {
        $requestId = intval($_POST['request_id'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        
        $result = denyGradeEditRequest($pdo, $requestId, $adminId, $reviewNotes);
        
        if ($result['success']) {
            $message = $result['message'];
            $message_type = 'success';
            logAdminAction($pdo, $adminId, 'deny_edit_request', 'grade_edit_request', $requestId, "Denied grade edit request ID: $requestId");
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=teacher_requests&msg=" . urlencode($message) . "&type=" . $message_type);
        exit();
    } elseif ($_POST['action'] === 'complete_edit') {
        $gradeId = intval($_POST['grade_id'] ?? 0);
        
        $result = completeGradeEdit($pdo, $gradeId, $adminId);
        
        if ($result['success']) {
            $message = $result['message'];
            $message_type = 'success';
            logAdminAction($pdo, $adminId, 'complete_grade_edit', 'grade', $gradeId, "Completed and re-locked grade edit for grade ID: $gradeId");
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=teacher_requests&msg=" . urlencode($message) . "&type=" . $message_type);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an AJAX request (check both header and form field)
    $isAjaxRequest = false;
    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1') {
        $isAjaxRequest = true;
    } elseif (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $isAjaxRequest = true;
    }
    
    // Validate CSRF token for all POST requests (except AJAX)
    if (!$isAjaxRequest) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        
        // Debug logging (remove in production)
        error_log("CSRF Validation - Token received: " . (!empty($csrfToken) ? substr($csrfToken, 0, 10) . '...' : 'EMPTY'));
        error_log("CSRF Validation - POST data keys: " . implode(', ', array_keys($_POST)));
        error_log("CSRF Validation - Session token exists: " . (isset($_SESSION['csrf_token']) ? 'YES' : 'NO'));
        error_log("CSRF Validation - Request URI: " . $_SERVER['REQUEST_URI']);
        error_log("CSRF Validation - Request Method: " . $_SERVER['REQUEST_METHOD']);
        
        // Mandatory server-side CSRF validation
        if (empty($csrfToken)) {
            // Log what was actually received
            error_log("CSRF Error - POST data: " . print_r($_POST, true));
            error_log("CSRF Error - Raw input: " . file_get_contents('php://input'));
            $message = "Security token is missing! Please refresh the page and try again.";
            $message_type = "danger";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . ($_GET['tab'] ?? 'dashboard') . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        // Validate token against session (using secure comparison)
        if (!validateCSRFToken($csrfToken)) {
            // Log token mismatch
            error_log("CSRF Error - Token mismatch. Received: " . substr($csrfToken, 0, 10) . ", Expected: " . (isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) : 'NONE'));
            // Regenerate token for next attempt
            generateCSRFToken();
            $message = "Invalid security token! This usually happens if the page was open for too long. Please refresh the page and try again.";
            $message_type = "danger";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . ($_GET['tab'] ?? 'dashboard') . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        // Token is valid - log success (remove in production)
        error_log("CSRF Validation - Token validated successfully");
    }
    
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        
        // Optional student-specific fields
        $program = trim($_POST['program'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        
        // Validation
        if (empty($username) || empty($password) || empty($role) || empty($first_name) || empty($last_name)) {
            $message = "All required fields must be filled!";
            $message_type = "danger";
        } elseif (strlen($password) < 6) {
            $message = "Password must be at least 6 characters long!";
            $message_type = "danger";
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format!";
            $message_type = "danger";
        } else {
            // Check if username already exists
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->execute([$username]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "Username already exists!";
                $message_type = "danger";
            } elseif (!empty($email)) {
                // Check if email already exists (only if email is provided)
                $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $check_email->execute([$email]);
                if ($check_email->rowCount() > 0) {
                    $message = "Email already exists!";
                    $message_type = "danger";
                } else {
                    // Create user with email
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $hashed_password, $email, $role, $first_name, $last_name]);
                    $user_id = $pdo->lastInsertId();
                    
                    // If student, initialize student-specific data
                    if ($role === 'student') {
                        $studentData = [];
                        if (!empty($program)) $studentData['program'] = $program;
                        if (!empty($year_level)) $studentData['year_level'] = $year_level;
                        if (!empty($section)) $studentData['section'] = $section;
                        
                        $initResult = initializeStudentData($pdo, $user_id, $studentData);
                        
                        if (!$initResult['success']) {
                            // Rollback user creation if initialization fails
                            $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                            $deleteStmt->execute([$user_id]);
                            $message = "Failed to create student: " . $initResult['message'];
                            $message_type = "danger";
                        } else {
                            logAdminAction($pdo, $_SESSION['user_id'], 'create_user', 'user', $user_id, "Created student: $first_name $last_name (ID: {$initResult['student_id_number']})");
                            $message = "Student added successfully with Student ID: {$initResult['student_id_number']}!";
                            $message_type = "success";
                        }
                    } else {
                        logAdminAction($pdo, $_SESSION['user_id'], 'create_user', 'user', $user_id, "Created user: $first_name $last_name ($role)");
                        $message = "User added successfully!";
                        $message_type = "success";
                    }
                    
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                    exit();
                }
            } else {
                // Create user without email (email is optional)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_password, $email ?: '', $role, $first_name, $last_name]);
                $user_id = $pdo->lastInsertId();
                
                // If student, initialize student-specific data
                if ($role === 'student') {
                    $studentData = [];
                    if (!empty($program)) $studentData['program'] = $program;
                    if (!empty($year_level)) $studentData['year_level'] = $year_level;
                    if (!empty($section)) $studentData['section'] = $section;
                    
                    $initResult = initializeStudentData($pdo, $user_id, $studentData);
                    
                    if (!$initResult['success']) {
                        // Rollback user creation if initialization fails
                        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $deleteStmt->execute([$user_id]);
                        $message = "Failed to create student: " . $initResult['message'];
                        $message_type = "danger";
                    } else {
                        logAdminAction($pdo, $_SESSION['user_id'], 'create_user', 'user', $user_id, "Created student: $first_name $last_name (ID: {$initResult['student_id_number']})");
                        $message = "Student added successfully with Student ID: {$initResult['student_id_number']}!";
                        $message_type = "success";
                    }
                } else {
                    logAdminAction($pdo, $_SESSION['user_id'], 'create_user', 'user', $user_id, "Created user: $first_name $last_name ($role)");
                    $message = "User added successfully!";
                    $message_type = "success";
                }
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    if (isset($_POST['add_subject'])) {
        // CSRF validation already done at top of POST handler
        $name = trim($_POST['name']);
        $code = trim($_POST['code']);
        $description = trim($_POST['description'] ?? '');
        $units = isset($_POST['units']) ? floatval($_POST['units']) : 3.0;
        $program = trim($_POST['program'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $prerequisites = trim($_POST['prerequisites'] ?? '');
        $isAjaxRequest = (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1') ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        // Validation
        if (empty($name) || empty($code)) {
            $message = "Course name and code are required!";
            $message_type = "danger";
            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        } else {
            // Check if subject code or name already exists (case-insensitive)
            $check_stmt = $pdo->prepare("SELECT id, name, code FROM subjects WHERE LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)");
            $check_stmt->execute([$code, $name]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if (strtolower($existing['code']) === strtolower($code)) {
                    $message = "Course code '{$code}' already exists!";
                } else {
                    $message = "Course name '{$name}' already exists!";
                }
                $message_type = "danger";
                if ($isAjaxRequest) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit();
                }
            } else {
                // Ensure program is stored correctly (use course name if it matches)
                $programValue = $program ?: null;
                if ($programValue) {
                    // Check if it's a valid course name
                    $courseCheck = $pdo->prepare("SELECT name FROM courses WHERE name = ? LIMIT 1");
                    $courseCheck->execute([$programValue]);
                    $course = $courseCheck->fetch(PDO::FETCH_ASSOC);
                    if ($course) {
                        $programValue = $course['name']; // Use exact course name
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, units, program, year_level, prerequisites, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$name, $code, $description, $units, $programValue, $year_level ?: null, $prerequisites ?: null]);
                $subject_id = $pdo->lastInsertId();
                logAdminAction($pdo, $_SESSION['user_id'], 'create_subject', 'subject', $subject_id, "Created course: $name ($code)");
                $message = "Course added successfully!";
                $message_type = "success";
                
                if ($isAjaxRequest) {
                    // Fetch the newly added course with assigned teachers
                    $subject_stmt = $pdo->prepare("
                        SELECT s.*, 
                               GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as assigned_teachers
                        FROM subjects s
                        LEFT JOIN teacher_subjects ts ON s.id = ts.subject_id
                        LEFT JOIN users u ON ts.teacher_id = u.id
                        WHERE s.id = ?
                        GROUP BY s.id
                        LIMIT 1
                    ");
                    $subject_stmt->execute([$subject_id]);
                    $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);

                    $assigned_teachers = $subject['assigned_teachers'] ?: null;
                    $row_html = '<tr class="subject-row"'
                        . ' data-subject-name="' . strtolower(htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-subject-code="' . strtolower(htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-program="' . strtolower(htmlspecialchars($subject['program'] ?? '', ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-year-level="' . strtolower(htmlspecialchars($subject['year_level'] ?? '', ENT_QUOTES, 'UTF-8')) . '">'
                        . '<td>' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . htmlspecialchars($subject['units'] ?? '3.0', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . ($assigned_teachers ? '<small>' . htmlspecialchars($assigned_teachers, ENT_QUOTES, 'UTF-8') . '</small>' : '<span class="text-muted">None</span>') . '</td>'
                        . '<td>' . htmlspecialchars($subject['description'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>'
                        . '<button class="btn btn-sm btn-outline-primary admin-action-btn touch-friendly" data-bs-toggle="modal" data-bs-target="#editSubjectModal"'
                        . ' data-id="' . $subject['id'] . '"'
                        . ' data-name="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-code="' . htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-description="' . htmlspecialchars($subject['description'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-units="' . htmlspecialchars($subject['units'] ?? '3.0', ENT_QUOTES, 'UTF-8') . '">'
                        . '<i class="fas fa-edit"></i> Edit</button> '
                        . '<a href="?action=delete_subject&id=' . $subject['id'] . '" class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"'
                        . ' data-confirm-action="delete_subject"'
                        . ' data-id="' . $subject['id'] . '"'
                        . ' data-confirm-target="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-confirm-warning="This will also delete all grades associated with it."'
                        . ' data-item-name="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' title="Delete ' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '">'
                        . '<i class="fas fa-trash"></i> Delete</a>'
                        . '</td>'
                        . '</tr>';

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'row_html' => $row_html
                    ]);
                    exit();
                }
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=subjects&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    if (isset($_POST['update_user'])) {
        $user_id = intval($_POST['user_id']);
        $username = trim($_POST['username']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'];
        
        // Get redirect tab from form, default to 'users' if not provided
        $redirect_tab = $_POST['redirect_tab'] ?? 'users';
        
        // Get current user role for validation
        $current_user_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $current_user_stmt->execute([$user_id]);
        $current_user = $current_user_stmt->fetch(PDO::FETCH_ASSOC);
        $current_role = $current_user['role'] ?? $role;
        
        // Validation
        if (empty($username) || empty($role) || empty($first_name) || empty($last_name)) {
            $message = "All required fields must be filled!";
            $message_type = "danger";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        // Validate email format if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format!";
            $message_type = "danger";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        // Check if username already exists (excluding current user)
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_stmt->execute([$username, $user_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = "Username already exists!";
            $message_type = "danger";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        // Check if email already exists (excluding current user) - only if email is provided
        if (!empty($email)) {
            $check_email_stmt = $pdo->prepare("SELECT id, username, first_name, last_name FROM users WHERE email = ? AND id != ?");
            $check_email_stmt->execute([$email, $user_id]);
            
            if ($check_email_stmt->rowCount() > 0) {
                $existing_user = $check_email_stmt->fetch(PDO::FETCH_ASSOC);
                $message = "Email already exists! It is currently used by: " . htmlspecialchars($existing_user['first_name'] . ' ' . $existing_user['last_name'] . ' (' . $existing_user['username'] . ')');
                $message_type = "danger";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
        
        try {
            // Get current user data for logging
            $current_user_stmt = $pdo->prepare("SELECT username, email, first_name, last_name FROM users WHERE id = ?");
            $current_user_stmt->execute([$user_id]);
            $current_user = $current_user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_user) {
                throw new Exception("User not found");
            }
            
            $old_email = $current_user['email'];
            $old_username = $current_user['username'];
            
            // Handle profile picture upload if provided
            $profilePicture = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_picture'];
                $maxSize = getSystemSetting('max_upload_size', 5242880);
                $allowedTypes = explode(',', getSystemSetting('allowed_file_types', 'jpg,jpeg,png,gif'));
                $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($fileExt, $allowedTypes) && $file['size'] <= $maxSize) {
                    $uploadDir = __DIR__ . '/../../assets/uploads/profiles/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExt;
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($file['tmp_name'], $filePath)) {
                        $profilePicture = 'uploads/profiles/' . $fileName;
                    }
                }
            }
            
            // Update user with or without profile picture
            if ($profilePicture) {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, role = ?, profile_picture = ? WHERE id = ?");
                $stmt->execute([$username, $first_name, $last_name, $email, $role, $profilePicture, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $first_name, $last_name, $email, $role, $user_id]);
            }
            
            // Build log message with changes
            $logChanges = [];
            if ($old_username !== $username) {
                $logChanges[] = "username: {$old_username} → {$username}";
            }
            if ($old_email !== $email) {
                $logChanges[] = "email: {$old_email} → {$email}";
            }
            
            $logMessage = "Updated user: $first_name $last_name";
            if (!empty($logChanges)) {
                $logMessage .= " (" . implode(", ", $logChanges) . ")";
            }
            
            logAdminAction($pdo, $_SESSION['user_id'], 'update_user', 'user', $user_id, $logMessage);
    
            $message = "User updated successfully!";
            $message_type = "success";
            
            // Use redirect_tab from form, default to 'users' if not set
            $redirect_tab = $_POST['redirect_tab'] ?? 'users';
            
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $message = "Error updating user: " . $e->getMessage();
            $message_type = "danger";
            // Use redirect_tab from form, default to 'users' if not set
            $error_redirect_tab = $_POST['redirect_tab'] ?? 'users';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $error_redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
    
    if (isset($_POST['update_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $message = "Passwords do not match!";
            $message_type = "danger";
        } elseif (strlen($new_password) < 6) {
            $message = "Password must be at least 6 characters long!";
            $message_type = "danger";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            logAdminAction($pdo, $_SESSION['user_id'], 'reset_password', 'user', $user_id, "Reset password for user ID: $user_id");
            $message = "Password updated successfully!";
            $message_type = "success";
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
    
    if (isset($_POST['update_full_student'])) {
        $user_id = $_POST['user_id'];
        
        // Get current student data for comparison
        $current_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
        $current_stmt->execute([$user_id]);
        $current_data = $current_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_data) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => 'Student not found!']);
                exit();
            } else {
                $message = "Student not found!";
                $message_type = "danger";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
        
        // Get all form data
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $birthday = $_POST['birthday'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $nationality = trim($_POST['nationality'] ?? 'Filipino');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $student_id_number = trim($_POST['student_id_number'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $educational_status = $_POST['educational_status'] ?? 'New Student';
        $status = $_POST['status'] ?? 'active';
        $address = trim($_POST['address'] ?? '');
        $baranggay = trim($_POST['baranggay'] ?? '');
        $municipality = trim($_POST['municipality'] ?? '');
        $city_province = trim($_POST['city_province'] ?? '');
        $country = trim($_POST['country'] ?? 'Philippines');
        $postal_code = trim($_POST['postal_code'] ?? '');
        $mother_name = trim($_POST['mother_name'] ?? '');
        $mother_phone = trim($_POST['mother_phone'] ?? '');
        $mother_occupation = trim($_POST['mother_occupation'] ?? '');
        $father_name = trim($_POST['father_name'] ?? '');
        $father_phone = trim($_POST['father_phone'] ?? '');
        $father_occupation = trim($_POST['father_occupation'] ?? '');
        $emergency_name = trim($_POST['emergency_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $emergency_address = trim($_POST['emergency_address'] ?? '');
        
        // Normalize values for comparison (empty string = null, trim strings, handle dates)
        $normalizeValue = function($value) {
            if ($value === '' || $value === null) return null;
            return is_string($value) ? trim($value) : $value;
        };
        
        $getCurrentValue = function($key, $default = null) use ($current_data) {
            $value = $current_data[$key] ?? $default;
            if ($value === '' || $value === null) return null;
            // Handle date fields - convert to string format for comparison
            if ($key === 'birthday' && $value) {
                return date('Y-m-d', strtotime($value));
            }
            return is_string($value) ? trim($value) : $value;
        };
        
        // Check if any data has changed
        $has_changes = false;
        
        // Compare each field - normalize both sides
        $compareField = function($new, $current) {
            $newNorm = $new === '' || $new === null ? null : (is_string($new) ? trim($new) : $new);
            $currentNorm = $current === '' || $current === null ? null : (is_string($current) ? trim($current) : $current);
            return $newNorm !== $currentNorm;
        };
        
        // Compare all fields
        if ($compareField($first_name, $current_data['first_name'] ?? '')) $has_changes = true;
        if ($compareField($middle_name, $current_data['middle_name'] ?? null)) $has_changes = true;
        if ($compareField($last_name, $current_data['last_name'] ?? '')) $has_changes = true;
        if ($compareField($suffix, $current_data['suffix'] ?? null)) $has_changes = true;
        // Handle birthday specially - normalize date format
        $new_birthday = $birthday ? date('Y-m-d', strtotime($birthday)) : null;
        $current_birthday = $current_data['birthday'] ? date('Y-m-d', strtotime($current_data['birthday'])) : null;
        if ($new_birthday !== $current_birthday) $has_changes = true;
        if ($compareField($gender, $current_data['gender'] ?? null)) $has_changes = true;
        if ($compareField($nationality, $current_data['nationality'] ?? 'Filipino')) $has_changes = true;
        if ($compareField($phone_number, $current_data['phone_number'] ?? null)) $has_changes = true;
        if ($compareField($email, $current_data['email'] ?? '')) $has_changes = true;
        if ($compareField($student_id_number, $current_data['student_id_number'] ?? null)) $has_changes = true;
        if ($compareField($program, $current_data['program'] ?? null)) $has_changes = true;
        if ($compareField($year_level, $current_data['year_level'] ?? null)) $has_changes = true;
        if ($compareField($section, $current_data['section'] ?? null)) $has_changes = true;
        if ($compareField($educational_status, $current_data['educational_status'] ?? 'New Student')) $has_changes = true;
        if ($compareField($status, $current_data['status'] ?? 'active')) $has_changes = true;
        if ($compareField($address, $current_data['address'] ?? null)) $has_changes = true;
        if ($compareField($baranggay, $current_data['baranggay'] ?? null)) $has_changes = true;
        if ($compareField($municipality, $current_data['municipality'] ?? null)) $has_changes = true;
        if ($compareField($city_province, $current_data['city_province'] ?? null)) $has_changes = true;
        if ($compareField($country, $current_data['country'] ?? 'Philippines')) $has_changes = true;
        if ($compareField($postal_code, $current_data['postal_code'] ?? null)) $has_changes = true;
        if ($compareField($mother_name, $current_data['mother_name'] ?? null)) $has_changes = true;
        if ($compareField($mother_phone, $current_data['mother_phone'] ?? null)) $has_changes = true;
        if ($compareField($mother_occupation, $current_data['mother_occupation'] ?? null)) $has_changes = true;
        if ($compareField($father_name, $current_data['father_name'] ?? null)) $has_changes = true;
        if ($compareField($father_phone, $current_data['father_phone'] ?? null)) $has_changes = true;
        if ($compareField($father_occupation, $current_data['father_occupation'] ?? null)) $has_changes = true;
        if ($compareField($emergency_name, $current_data['emergency_name'] ?? null)) $has_changes = true;
        if ($compareField($emergency_phone, $current_data['emergency_phone'] ?? null)) $has_changes = true;
        if ($compareField($emergency_address, $current_data['emergency_address'] ?? null)) $has_changes = true;
        
        // If no changes detected
        if (!$has_changes) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'no_changes' => true, 'message' => 'No changes were made to the student data.']);
                exit();
            } else {
                $message = "No changes were made to the student data.";
                $message_type = "info";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $message = "First Name, Last Name, and Email are required!";
            $message_type = "danger";
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
        
        // Check if email already exists (excluding current user)
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_stmt->execute([$email, $user_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = "Email already exists!";
            $message_type = "danger";
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
        
        // Update all student fields
        $stmt = $pdo->prepare("
            UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, suffix = ?,
                birthday = ?, gender = ?, nationality = ?, phone_number = ?, email = ?,
                student_id_number = ?, program = ?, year_level = ?, section = ?,
                educational_status = ?, status = ?,
                address = ?, baranggay = ?, municipality = ?, city_province = ?,
                country = ?, postal_code = ?,
                mother_name = ?, mother_phone = ?, mother_occupation = ?,
                father_name = ?, father_phone = ?, father_occupation = ?,
                emergency_name = ?, emergency_phone = ?, emergency_address = ?
            WHERE id = ? AND role = 'student'
        ");
        $stmt->execute([
            $first_name, $middle_name, $last_name, $suffix,
            $birthday ?: null, $gender ?: null, $nationality, $phone_number, $email,
            $student_id_number, $program, $year_level, $section,
            $educational_status, $status,
            $address, $baranggay, $municipality, $city_province,
            $country, $postal_code,
            $mother_name, $mother_phone, $mother_occupation,
            $father_name, $father_phone, $father_occupation,
            $emergency_name, $emergency_phone, $emergency_address,
            $user_id
        ]);
        
        logAdminAction($pdo, $_SESSION['user_id'], 'update_full_student', 'user', $user_id, "Updated full student data for: $first_name $last_name");
        
        // Check if this is an AJAX request
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'message' => 'Student data updated successfully!']);
            exit();
        } else {
            $message = "Student data updated successfully!";
            $message_type = "success";
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
    
    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $name = trim($_POST['name']);
        $code = trim($_POST['code']);
        $description = trim($_POST['description'] ?? '');
        $units = isset($_POST['units']) ? floatval($_POST['units']) : 3.0;
        
        // Validation
        if (empty($name) || empty($code)) {
            $message = "Course name and code are required!";
            $message_type = "danger";
        } else {
            // Check if subject code already exists (excluding current subject)
            $check_stmt = $pdo->prepare("SELECT id FROM subjects WHERE code = ? AND id != ?");
            $check_stmt->execute([$code, $subject_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "Program code already exists!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, description = ?, units = ? WHERE id = ?");
                $stmt->execute([$name, $code, $description, $units, $subject_id]);
                logAdminAction($pdo, $_SESSION['user_id'], 'update_subject', 'subject', $subject_id, "Updated course: $name");
                $message = "Course updated successfully!";
                $message_type = "success";
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=subjects&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    // Handle application approval/rejection
    if (isset($_POST['review_application'])) {
        error_log("=== REVIEW APPLICATION POST RECEIVED ===");
        error_log("POST data: " . print_r($_POST, true));
        
        $application_id = $_POST['application_id'] ?? null;
        $action = strtolower(trim($_POST['action'] ?? '')); // 'approve' or 'reject'
        $notes = trim($_POST['notes'] ?? '');
        
        error_log("Parsed - Application ID: " . ($application_id ?? 'NULL') . ", Action: " . ($action ?: 'NULL'));
        
        if (empty($application_id)) {
            $message = "Application ID is required.";
            $message_type = "danger";
            error_log("ERROR: Application ID is empty");
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } elseif (empty($action) || !in_array($action, ['approve', 'reject'])) {
            $message = "Invalid action. Action must be 'approve' or 'reject'. Received: " . htmlspecialchars($action ?: 'empty');
            $message_type = "danger";
            error_log("ERROR: Invalid action - " . ($action ?: 'empty'));
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get application details
                $stmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ?");
                $stmt->execute([$application_id]);
                $application = $stmt->fetch();
                
                if (!$application) {
                    throw new Exception("Application not found!");
                }
                
                if ($action === 'approve') {
                    // Check if all requirements are submitted and approved
                    $stmt = $pdo->prepare("
                        SELECT COUNT(DISTINCT requirement_name) as total_required,
                               (SELECT COUNT(DISTINCT ar.requirement_name) FROM application_requirement_submissions ars 
                                JOIN application_requirements ar ON ars.requirement_id = ar.id 
                                WHERE ars.application_id = ? AND ar.is_required = 1 AND ars.status = 'approved') as requirements_approved
                        FROM application_requirements 
                        WHERE is_required = 1
                    ");
                    $stmt->execute([$application_id]);
                    $req_check = $stmt->fetch();
                    
                    // Check if payment is verified
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_payments WHERE application_id = ? AND status = 'verified'");
                    $stmt->execute([$application_id]);
                    $payment_verified = $stmt->fetchColumn() > 0;
                    
                    // Log validation checks for debugging
                    error_log("Approval validation - App ID: $application_id, Total Required: " . ($req_check['total_required'] ?? 0) . ", Approved: " . ($req_check['requirements_approved'] ?? 0) . ", Payment Verified: " . ($payment_verified ? 'Yes' : 'No'));
                    
                    // Allow approval if no requirements are set OR if requirements are met
                    if (($req_check['total_required'] ?? 0) > 0 && ($req_check['requirements_approved'] ?? 0) < ($req_check['total_required'] ?? 0)) {
                        $errorMsg = "Cannot approve: Not all required documents have been submitted and approved. (" . ($req_check['requirements_approved'] ?? 0) . "/" . ($req_check['total_required'] ?? 0) . " approved)";
                        error_log("Approval blocked: " . $errorMsg);
                        throw new Exception($errorMsg);
                    }
                    
                    // Allow approval if payment is verified OR if no payment record exists (for testing/development)
                    if (!$payment_verified) {
                        // Check if any payment record exists at all
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_payments WHERE application_id = ?");
                        $stmt->execute([$application_id]);
                        $payment_exists = $stmt->fetchColumn() > 0;
                        
                        if ($payment_exists) {
                            $errorMsg = "Cannot approve: Payment has not been verified.";
                            error_log("Approval blocked: " . $errorMsg);
                            throw new Exception($errorMsg);
                        } else {
                            // No payment record exists - log warning but allow approval (for development/testing)
                            error_log("Warning: No payment record found for application $application_id, but allowing approval to proceed.");
                        }
                    }
                    
                    // Check if student already has a student ID (from registration)
                    $stmt = $pdo->prepare("SELECT student_id_number FROM users WHERE id = ?");
                    $stmt->execute([$application['student_id']]);
                    $existingStudent = $stmt->fetch();
                    
                    // Only generate new student number if one doesn't exist
                    if (empty($existingStudent['student_id_number'])) {
                        $student_number = generateStudentNumber($pdo);
                        // Update student record with student number
                        $stmt = $pdo->prepare("UPDATE users SET student_id_number = ?, status = 'active' WHERE id = ?");
                        $stmt->execute([$student_number, $application['student_id']]);
                    } else {
                        // Keep existing student ID, just update status
                        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                        $stmt->execute([$application['student_id']]);
                    }

                    $course = findCourseForProgram($pdo, $application['program_applied'], $application['program_applied']);
                    $enrolledAlready = false;
                    if ($course) {
                        $studentInfoStmt = $pdo->prepare("SELECT program, year_level, section FROM users WHERE id = ?");
                        $studentInfoStmt->execute([$application['student_id']]);
                        $studentInfo = $studentInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                        $yearLevel = $studentInfo['year_level'] ?: '1st Year';
                        $sectionName = $studentInfo['section'] ?: 'A';
                        $academicYear = getCurrentAcademicYearRange();
                        $sectionData = ensureSectionAndClassroom($pdo, $course, $yearLevel, $sectionName, $academicYear);

                        $classroomStmt = $pdo->prepare("
                            SELECT id FROM classrooms
                            WHERE section = ? AND program = ? AND year_level = ?
                            ORDER BY id
                            LIMIT 1
                        ");
                        $classroomStmt->execute([$sectionData['section_name'], $course['name'], $sectionData['year_level']]);
                        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);

                        if ($classroom) {
                            $checkEnroll = $pdo->prepare("SELECT id FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
                            $checkEnroll->execute([$classroom['id'], $application['student_id']]);
                            $enrolledAlready = (bool)$checkEnroll->fetch(PDO::FETCH_ASSOC);
                            if (!$enrolledAlready) {
                                $insertEnroll = $pdo->prepare("INSERT INTO classroom_students (classroom_id, student_id) VALUES (?, ?)");
                                $insertEnroll->execute([$classroom['id'], $application['student_id']]);
                                
                                // Automatically enroll student in all courses assigned to this section
                                // Get the section ID from sectionData (it should have 'id' if section was found/created)
                                $sectionIdForEnrollment = null;
                                if (isset($sectionData['id'])) {
                                    $sectionIdForEnrollment = $sectionData['id'];
                                } else {
                                    // If sectionData doesn't have id, try to find the section
                                    $findSectionStmt = $pdo->prepare("
                                        SELECT id FROM sections 
                                        WHERE course_id = ? AND section_name = ? AND year_level = ?
                                        ORDER BY academic_year DESC LIMIT 1
                                    ");
                                    $findSectionStmt->execute([$course['id'], $sectionData['section_name'], $sectionData['year_level']]);
                                    $foundSection = $findSectionStmt->fetch(PDO::FETCH_ASSOC);
                                    $sectionIdForEnrollment = $foundSection['id'] ?? null;
                                }
                                
                                if ($sectionIdForEnrollment) {
                                    $enrolledCount = enrollStudentInSectionCourses($pdo, $application['student_id'], $sectionIdForEnrollment, $classroom['id'], $sectionData['teacher_id'] ?? null);
                                    if ($enrolledCount > 0) {
                                        logAdminAction($pdo, $_SESSION['user_id'], 'auto_enroll_courses', 'enrollment', $application['student_id'], "Automatically enrolled student in $enrolledCount course(s) from section schedule during application approval");
                                    }
                                }
                            }
                        }

                        $updateStudent = $pdo->prepare("
                            UPDATE users 
                            SET program = ?, year_level = ?, section = ?, educational_status = ?, department = COALESCE(department, ?)
                            WHERE id = ?
                        ");
                        $updateStudent->execute([
                            $course['name'],
                            $sectionData['year_level'],
                            $sectionData['section_name'],
                            $application['educational_status'],
                            $course['name'],
                            $application['student_id']
                        ]);

                        if (!$enrolledAlready) {
                            $incrementSection = $pdo->prepare("UPDATE sections SET current_students = current_students + 1 WHERE id = ?");
                            $incrementSection->execute([$sectionData['id']]);
                        }
                    } else {
                        $fallbackStatusStmt = $pdo->prepare("UPDATE users SET educational_status = ? WHERE id = ?");
                        $fallbackStatusStmt->execute([$application['educational_status'], $application['student_id']]);
                    }

                    // Update application status
                    $stmt = $pdo->prepare("UPDATE admission_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), notes = ? WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $notes, $application_id]);
                    
                    logAdminAction($pdo, $_SESSION['user_id'], 'approve_application', 'admission_application', $application_id, "Approved application and assigned student number: $student_number");
                    $message = "Application approved! Student number assigned: $student_number";
                    $message_type = "success";
                    
                    $pdo->commit();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
                    exit();
                } elseif ($action === 'reject') {
                    // Reject application using rejection handler
                    require_once __DIR__ . '/../includes/student_rejection_handler.php';
                    $rejectionResult = handleStudentRejection($pdo, $application_id, $_SESSION['user_id'], $notes);
                    
                    if ($rejectionResult['success']) {
                        $message = $rejectionResult['message'];
                        $message_type = "success";
                    } else {
                        $message = $rejectionResult['message'];
                        $message_type = "danger";
                    }
                    
                    $pdo->commit();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
                    exit();
                } else {
                    $pdo->rollBack();
                    throw new Exception("Invalid action specified. Action must be 'approve' or 'reject'.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMsg = $e->getMessage();
                $message = "Error processing application: " . $errorMsg;
                $message_type = "danger";
                error_log("Application approval error: " . $errorMsg);
                // Redirect with error message
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    // Handle requirement review (mark as received face-to-face)
    if (isset($_POST['mark_requirement_received'])) {
        $application_id = $_POST['application_id'];
        $requirement_id = $_POST['requirement_id'];
        // Check if requirement checkbox was checked
        $is_received = isset($_POST['is_received']) && $_POST['is_received'] == 1 ? 1 : 0;
        $review_notes = trim($_POST['review_notes'] ?? '');
        
        // Check if this is an AJAX request
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        try {
            // Check if submission already exists
            $stmt = $pdo->prepare("SELECT id FROM application_requirement_submissions WHERE application_id = ? AND requirement_id = ?");
            $stmt->execute([$application_id, $requirement_id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing submission
                $status = $is_received ? 'approved' : 'pending';
                $stmt = $pdo->prepare("
                    UPDATE application_requirement_submissions 
                    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $_SESSION['user_id'], $review_notes, $existing['id']]);
                $submission_id = $existing['id'];
            } else {
                // Create new submission record (face-to-face submission)
                $status = $is_received ? 'approved' : 'pending';
                $stmt = $pdo->prepare("
                    INSERT INTO application_requirement_submissions 
                    (application_id, requirement_id, status, reviewed_by, reviewed_at, review_notes, submitted_at)
                    VALUES (?, ?, ?, ?, NOW(), ?, NOW())
                ");
                $stmt->execute([$application_id, $requirement_id, $status, $_SESSION['user_id'], $review_notes]);
                $submission_id = $pdo->lastInsertId();
            }
            
            logAdminAction($pdo, $_SESSION['user_id'], 'mark_requirement_received', 'application_requirement_submission', $submission_id, ($is_received ? "Marked requirement as received" : "Unmarked requirement"));
            
            // If AJAX request, return JSON instead of redirecting
            if ($is_ajax || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $is_received ? "Requirement marked as received successfully!" : "Requirement unmarked successfully!"]);
                exit();
            }
            
            $message = $is_received ? "Requirement marked as received successfully!" : "Requirement unmarked successfully!";
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            // If AJAX request, return JSON error
            if ($is_ajax || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => "Error marking requirement: " . $e->getMessage()]);
                exit();
            }
            $message = "Error marking requirement: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    // Handle requirement review (legacy - for backward compatibility)
    if (isset($_POST['review_requirement'])) {
        $submission_id = $_POST['submission_id'];
        $status = $_POST['status']; // 'approved' or 'rejected'
        $review_notes = trim($_POST['review_notes'] ?? '');
        
        try {
            $stmt = $pdo->prepare("
                UPDATE application_requirement_submissions 
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $_SESSION['user_id'], $review_notes, $submission_id]);
            
            logAdminAction($pdo, $_SESSION['user_id'], 'review_requirement', 'application_requirement_submission', $submission_id, ucfirst($status) . " requirement submission");
            $message = "Requirement " . $status . " successfully!";
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $message = "Error reviewing requirement: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    // Handle payment verification (mark as received face-to-face)
    if (isset($_POST['mark_payment_received'])) {
        $application_id = $_POST['application_id'];
        $is_received = isset($_POST['is_received']) ? 1 : 0;
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $verification_notes = trim($_POST['verification_notes'] ?? '');
        
        // Check if this is an AJAX request
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        try {
            // Check if payment already exists
            $stmt = $pdo->prepare("SELECT id FROM application_payments WHERE application_id = ? ORDER BY submitted_at DESC LIMIT 1");
            $stmt->execute([$application_id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing payment
                $status = $is_received ? 'verified' : 'pending';
                $stmt = $pdo->prepare("
                    UPDATE application_payments 
                    SET status = ?, verified_by = ?, verified_at = NOW(), verification_notes = ?, amount = ?, payment_method = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $_SESSION['user_id'], $verification_notes, $amount, $payment_method, $existing['id']]);
                $payment_id = $existing['id'];
            } else {
                // Create new payment record (face-to-face payment)
                $status = $is_received ? 'verified' : 'pending';
                $stmt = $pdo->prepare("
                    INSERT INTO application_payments 
                    (application_id, amount, payment_method, status, verified_by, verified_at, verification_notes, submitted_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW())
                ");
                $stmt->execute([$application_id, $amount, $payment_method, $status, $_SESSION['user_id'], $verification_notes]);
                $payment_id = $pdo->lastInsertId();
            }
            
            logAdminAction($pdo, $_SESSION['user_id'], 'mark_payment_received', 'application_payment', $payment_id, ($is_received ? "Marked payment as received" : "Unmarked payment"));
            
            // If AJAX request, return JSON instead of redirecting
            if ($is_ajax || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $is_received ? "Payment marked as received successfully!" : "Payment unmarked successfully!"]);
                exit();
            }
            
            $message = $is_received ? "Payment marked as received successfully!" : "Payment unmarked successfully!";
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            // If AJAX request, return JSON error
            if ($is_ajax || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => "Error marking payment: " . $e->getMessage()]);
                exit();
            }
            $message = "Error marking payment: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    // Handle payment verification (legacy - for backward compatibility)
    if (isset($_POST['verify_payment'])) {
        $payment_id = $_POST['payment_id'];
        $status = $_POST['status']; // 'verified' or 'rejected'
        $verification_notes = trim($_POST['verification_notes'] ?? '');
        
        try {
            $stmt = $pdo->prepare("
                UPDATE application_payments 
                SET status = ?, verified_by = ?, verified_at = NOW(), verification_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $_SESSION['user_id'], $verification_notes, $payment_id]);
            
            logAdminAction($pdo, $_SESSION['user_id'], 'verify_payment', 'application_payment', $payment_id, ucfirst($status) . " payment");
            $message = "Payment " . $status . " successfully!";
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=applications&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $message = "Error verifying payment: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    // Handle teacher creation with auto-generated credentials
    if (isset($_POST['add_teacher'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $subject_ids = isset($_POST['subject_ids']) ? $_POST['subject_ids'] : [];
        
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $message = "First name, last name, and email are required!";
            $message_type = "danger";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Generate username and password
                $username = generateTeacherUsername($pdo, $first_name, $last_name);
                $password = generatePassword(12);
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Create teacher account
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, first_name, last_name, department, status) VALUES (?, ?, ?, 'teacher', ?, ?, ?, 'active')");
                $stmt->execute([$username, $hashed_password, $email, $first_name, $last_name, $department]);
                $teacher_id = $pdo->lastInsertId();
                
                // Assign subjects
                $totalSchedulesUpdated = 0;
                $totalStudentsEnrolled = 0;
                
                if (!empty($subject_ids) && is_array($subject_ids)) {
                    $stmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
                    foreach ($subject_ids as $subject_id) {
                        $stmt->execute([$teacher_id, $subject_id]);
                        
                        // Propagate teacher assignment to all section_schedules for this subject
                        $propagationResult = propagateTeacherToSectionSchedules($pdo, $teacher_id, $subject_id);
                        $totalSchedulesUpdated += $propagationResult['schedules_updated'];
                        $totalStudentsEnrolled += $propagationResult['students_enrolled'];
                    }
                }
                
                $logMessage = "Created teacher: $first_name $last_name";
                if ($totalSchedulesUpdated > 0) {
                    $logMessage .= ". Updated $totalSchedulesUpdated schedule(s) and enrolled $totalStudentsEnrolled student(s)";
                }
                logAdminAction($pdo, $_SESSION['user_id'], 'create_teacher', 'user', $teacher_id, $logMessage);
                
                $pdo->commit();
                $message = "Teacher created successfully!";
                $message_type = "success";
                $_SESSION['new_teacher_credentials'] = ['username' => $username, 'password' => $password];
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=teachers&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error creating teacher: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
    
    // Handle teacher subject assignment update
    if (isset($_POST['update_teacher_subjects'])) {
        $teacher_id = intval($_POST['teacher_id']);
        $teacher_name = trim($_POST['teacher_name'] ?? '');
        $subject_ids = isset($_POST['subject_ids']) ? array_map('intval', $_POST['subject_ids']) : [];
        $original_teacher_name = trim($_POST['original_teacher_name'] ?? '');
        $original_subject_ids = isset($_POST['original_subject_ids']) ? explode(',', $_POST['original_subject_ids']) : [];
        $original_subject_ids = array_filter(array_map('intval', $original_subject_ids));
        
        // Get redirect tab from form, default to 'teachers' if not provided
        $redirect_tab = $_POST['redirect_tab'] ?? 'teachers';
        
        // Sort arrays for comparison
        sort($subject_ids);
        sort($original_subject_ids);
        
        // Check if anything changed
        $name_changed = ($teacher_name !== $original_teacher_name);
        $subjects_changed = ($subject_ids !== $original_subject_ids);
        
        if (!$name_changed && !$subjects_changed) {
            $message = "Nothing has been updated.";
            $message_type = "info";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Update teacher name if changed
            if ($name_changed && !empty($teacher_name)) {
                // Parse first and last name from full name
                $name_parts = explode(' ', $teacher_name, 2);
                $first_name = $name_parts[0] ?? '';
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                
                if (!empty($first_name)) {
                    $updateStmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
                    $updateStmt->execute([$first_name, $last_name, $teacher_id]);
                }
            }
            
            // Update subject assignments if changed
            if ($subjects_changed) {
                // Remove all existing subject assignments for this teacher
                $stmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
                $stmt->execute([$teacher_id]);
                
                // Add new subject assignments
                $totalSchedulesUpdated = 0;
                $totalStudentsEnrolled = 0;
                
                if (!empty($subject_ids) && is_array($subject_ids)) {
                    $stmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
                    foreach ($subject_ids as $subject_id) {
                        $stmt->execute([$teacher_id, $subject_id]);
                        
                        // Propagate teacher assignment to all section_schedules for this subject
                        $propagationResult = propagateTeacherToSectionSchedules($pdo, $teacher_id, $subject_id);
                        $totalSchedulesUpdated += $propagationResult['schedules_updated'];
                        $totalStudentsEnrolled += $propagationResult['students_enrolled'];
                    }
                }
            }
            
            $logMessage = "Updated teacher handled courses for teacher ID: $teacher_id";
            if ($name_changed) {
                $logMessage .= ". Name updated to: $teacher_name";
            }
            if ($subjects_changed) {
                $logMessage .= ". Subject assignments updated";
                if (isset($totalSchedulesUpdated) && $totalSchedulesUpdated > 0) {
                    $logMessage .= " (Updated $totalSchedulesUpdated schedule(s) and enrolled $totalStudentsEnrolled student(s))";
                }
            }
            logAdminAction($pdo, $_SESSION['user_id'], 'update_teacher_subjects', 'user', $teacher_id, $logMessage);
            
            $pdo->commit();
            $message = "Teacher handled courses updated successfully!";
            $message_type = "success";
            // Use redirect_tab from form, default to 'teachers' if not set
            $redirect_tab = $_POST['redirect_tab'] ?? 'teachers';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error updating teacher subjects: " . $e->getMessage();
            $message_type = "danger";
            // Use redirect_tab from form, default to 'teachers' if not set
            $redirect_tab = $_POST['redirect_tab'] ?? 'teachers';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab . "&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
    
    // Handle subject creation with units
    // Note: This handler appears to be duplicate/old code. The main add_subject handler is at line 273.
    // Keeping this for backward compatibility but it should not be reached if the main handler works correctly.
    if (isset($_POST['add_subject']) && !isset($_POST['program'])) {
        $name = trim($_POST['name']);
        $code = trim($_POST['code']);
        $description = trim($_POST['description'] ?? '');
        $units = isset($_POST['units']) ? floatval($_POST['units']) : 3.0;
        $isAjaxRequest = (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1') ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        // Validation
        if (empty($name) || empty($code)) {
            $message = "Course name and code are required!";
            $message_type = "danger";
            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        } else {
            // Check if subject code or name already exists (case-insensitive)
            $check_stmt = $pdo->prepare("SELECT id, name, code FROM subjects WHERE LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)");
            $check_stmt->execute([$code, $name]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if (strtolower($existing['code']) === strtolower($code)) {
                    $message = "Course code '{$code}' already exists!";
                } else {
                    $message = "Course name '{$name}' already exists!";
                }
                $message_type = "danger";
                if ($isAjaxRequest) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit();
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, units, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$name, $code, $description, $units]);
                $subject_id = $pdo->lastInsertId();
                logAdminAction($pdo, $_SESSION['user_id'], 'create_subject', 'subject', $subject_id, "Created course: $name ($code)");
                $message = "Course added successfully!";
                $message_type = "success";
                
                if ($isAjaxRequest) {
                    // Fetch the newly added course with assigned teachers
                    $subject_stmt = $pdo->prepare("
                        SELECT s.*, 
                               GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as assigned_teachers
                        FROM subjects s
                        LEFT JOIN teacher_subjects ts ON s.id = ts.subject_id
                        LEFT JOIN users u ON ts.teacher_id = u.id
                        WHERE s.id = ?
                        GROUP BY s.id
                        LIMIT 1
                    ");
                    $subject_stmt->execute([$subject_id]);
                    $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);

                    $assigned_teachers = $subject['assigned_teachers'] ?: null;
                    $row_html = '<tr class="subject-row"'
                        . ' data-subject-name="' . strtolower(htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-subject-code="' . strtolower(htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-program="' . strtolower(htmlspecialchars($subject['program'] ?? '', ENT_QUOTES, 'UTF-8')) . '"'
                        . ' data-year-level="' . strtolower(htmlspecialchars($subject['year_level'] ?? '', ENT_QUOTES, 'UTF-8')) . '">'
                        . '<td>' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . htmlspecialchars($subject['units'] ?? '3.0', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . ($assigned_teachers ? '<small>' . htmlspecialchars($assigned_teachers, ENT_QUOTES, 'UTF-8') . '</small>' : '<span class="text-muted">None</span>') . '</td>'
                        . '<td>' . htmlspecialchars($subject['description'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>'
                        . '<button class="btn btn-sm btn-outline-primary admin-action-btn touch-friendly" data-bs-toggle="modal" data-bs-target="#editSubjectModal"'
                        . ' data-id="' . $subject['id'] . '"'
                        . ' data-name="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-code="' . htmlspecialchars($subject['code'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-description="' . htmlspecialchars($subject['description'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-units="' . htmlspecialchars($subject['units'] ?? '3.0', ENT_QUOTES, 'UTF-8') . '">'
                        . '<i class="fas fa-edit"></i> Edit</button> '
                        . '<a href="?action=delete_subject&id=' . $subject['id'] . '" class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"'
                        . ' data-confirm-action="delete_subject"'
                        . ' data-id="' . $subject['id'] . '"'
                        . ' data-confirm-target="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-confirm-warning="This will also delete all grades associated with it."'
                        . ' data-item-name="' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '"'
                        . ' title="Delete ' . htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') . '">'
                        . '<i class="fas fa-trash"></i> Delete</a>'
                        . '</td>'
                        . '</tr>';

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'row_html' => $row_html
                    ]);
                    exit();
                }

                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=subjects&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    // Handle course creation
    if (isset($_POST['add_course'])) {
        $code = trim($_POST['code']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $duration_years = isset($_POST['duration_years']) ? intval($_POST['duration_years']) : 4;
        
        if (empty($code) || empty($name)) {
            $message = "Course code and name are required!";
            $message_type = "danger";
        } else {
            // Check if course code already exists
            $check_stmt = $pdo->prepare("SELECT id FROM courses WHERE code = ?");
            $check_stmt->execute([$code]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "Program code already exists!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("INSERT INTO courses (code, name, description, duration_years) VALUES (?, ?, ?, ?)");
                $stmt->execute([$code, $name, $description, $duration_years]);
                $course_id = $pdo->lastInsertId();
                logAdminAction($pdo, $_SESSION['user_id'], 'create_course', 'course', $course_id, "Created program: $name");
                $message = "Program added successfully!";
                $message_type = "success";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=courses&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    // Handle course update
    if (isset($_POST['update_course'])) {
        $course_id = $_POST['course_id'];
        $code = trim($_POST['code']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $duration_years = isset($_POST['duration_years']) ? intval($_POST['duration_years']) : 4;
        $status = $_POST['status'] ?? 'active';
        
        if (empty($code) || empty($name)) {
            $message = "Course code and name are required!";
            $message_type = "danger";
        } else {
            // Check if course code already exists (excluding current course)
            $check_stmt = $pdo->prepare("SELECT id FROM courses WHERE code = ? AND id != ?");
            $check_stmt->execute([$code, $course_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "Program code already exists!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("UPDATE courses SET code = ?, name = ?, description = ?, duration_years = ?, status = ? WHERE id = ?");
                $stmt->execute([$code, $name, $description, $duration_years, $status, $course_id]);
                logAdminAction($pdo, $_SESSION['user_id'], 'update_course', 'course', $course_id, "Updated course: $name");
                $message = "Program updated successfully!";
                $message_type = "success";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=courses&msg=" . urlencode($message) . "&type=" . $message_type . "&context=course_update");
                exit();
            }
        }
    }
    
    // Handle section creation
    if (isset($_POST['add_section'])) {
        $course_id = $_POST['course_id'];
        $section_name = trim($_POST['section_name']);
        $year_level = trim($_POST['year_level']);
        $academic_year = trim($_POST['academic_year']);
        $semester = $_POST['semester'] ?? '1st';
        $teacher_id = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
        $max_students = isset($_POST['max_students']) ? intval($_POST['max_students']) : 50;
        
        if (empty($course_id) || empty($section_name) || empty($year_level) || empty($academic_year)) {
            $message = "Course, section name, year level, and academic year are required!";
            $message_type = "danger";
        } else {
            // Check if section already exists for this course, year level, and academic year
            $check_stmt = $pdo->prepare("SELECT id FROM sections WHERE course_id = ? AND section_name = ? AND year_level = ? AND academic_year = ?");
            $check_stmt->execute([$course_id, $section_name, $year_level, $academic_year]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "This section already exists for the selected course, year level, and academic year!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("INSERT INTO sections (course_id, section_name, year_level, academic_year, semester, teacher_id, max_students) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$course_id, $section_name, $year_level, $academic_year, $semester, $teacher_id, $max_students]);
                $section_id = $pdo->lastInsertId();
                
                // Also create a classroom entry for this section
                $course_stmt = $pdo->prepare("SELECT name, code FROM courses WHERE id = ?");
                $course_stmt->execute([$course_id]);
                $course = $course_stmt->fetch();
                $classroom_name = ($course ? $course['code'] . ' ' : '') . $year_level . ' - Section ' . $section_name;
                
                $classroom_stmt = $pdo->prepare("INSERT INTO classrooms (name, description, teacher_id, program, year_level, section, academic_year, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $classroom_stmt->execute([$classroom_name, "Section $section_name for " . ($course ? $course['name'] : ''), $teacher_id, ($course ? $course['name'] : null), $year_level, $section_name, $academic_year, $semester]);
                
                logAdminAction($pdo, $_SESSION['user_id'], 'create_section', 'section', $section_id, "Created section: $section_name for course ID: $course_id");
                $message = "Section created successfully!";
                $message_type = "success";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=sections&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
    
    // Handle section update
    if (isset($_POST['update_section'])) {
        $section_id = $_POST['section_id'];
        $course_id = $_POST['course_id'];
        $section_name = trim($_POST['section_name']);
        $year_level = trim($_POST['year_level']);
        $academic_year = trim($_POST['academic_year']);
        $semester = $_POST['semester'] ?? '1st';
        $teacher_id = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
        $max_students = isset($_POST['max_students']) ? intval($_POST['max_students']) : 50;
        $status = $_POST['status'] ?? 'active';
        
        if (empty($course_id) || empty($section_name) || empty($year_level) || empty($academic_year)) {
            $message = "Course, section name, year level, and academic year are required!";
            $message_type = "danger";
        } else {
            // Check if section already exists (excluding current section)
            $check_stmt = $pdo->prepare("SELECT id FROM sections WHERE course_id = ? AND section_name = ? AND year_level = ? AND academic_year = ? AND id != ?");
            $check_stmt->execute([$course_id, $section_name, $year_level, $academic_year, $section_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $message = "This section already exists for the selected course, year level, and academic year!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("UPDATE sections SET course_id = ?, section_name = ?, year_level = ?, academic_year = ?, semester = ?, teacher_id = ?, max_students = ?, status = ? WHERE id = ?");
                $stmt->execute([$course_id, $section_name, $year_level, $academic_year, $semester, $teacher_id, $max_students, $status, $section_id]);
                logAdminAction($pdo, $_SESSION['user_id'], 'update_section', 'section', $section_id, "Updated section: $section_name");
                $message = "Section updated successfully!";
                $message_type = "success";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=sections&msg=" . urlencode($message) . "&type=" . $message_type);
                exit();
            }
        }
    }
}

// Ensure schedules table exists before any schedule operations
ensureSectionSchedulesTable($pdo);

// Handle schedule creation
if (isset($_POST['add_schedule'])) {
    $section_id = $_POST['section_id'];
    $subject_id = $_POST['subject_id'];
    $teacher_id = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
    $classroom_id = !empty($_POST['classroom_id']) ? intval($_POST['classroom_id']) : null;
    $day_of_week = $_POST['day_of_week'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $room = trim($_POST['room'] ?? '');
    $academic_year = trim($_POST['academic_year']);
    $semester = $_POST['semester'] ?? '1st';
    
    if (empty($section_id) || empty($subject_id) || empty($day_of_week) || empty($start_time) || empty($end_time) || empty($academic_year)) {
        $message = "Section, subject, day, time, and academic year are required!";
        $message_type = "danger";
    } elseif ($start_time >= $end_time) {
        $message = "End time must be after start time!";
        $message_type = "danger";
    } else {
        // Check for time conflicts in the same section on the same day
        $conflictStmt = $pdo->prepare("
            SELECT id FROM section_schedules 
            WHERE section_id = ? AND day_of_week = ? AND status = 'active'
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $conflictStmt->execute([$section_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
        
        if ($conflictStmt->rowCount() > 0) {
            $message = "Time conflict detected! Another schedule exists at this time.";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("INSERT INTO section_schedules (section_id, subject_id, teacher_id, classroom_id, day_of_week, start_time, end_time, room, academic_year, semester, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$section_id, $subject_id, $teacher_id, $classroom_id, $day_of_week, $start_time, $end_time, $room ?: null, $academic_year, $semester]);
            $schedule_id = $pdo->lastInsertId();
            
            // If teacher is assigned, enroll all students in this section who don't have grade entries yet
            $enrolledCount = 0;
            if ($teacher_id) {
                $enrolledCount = enrollStudentsWhenTeacherAssigned($pdo, $schedule_id, $teacher_id);
            }
            
            $logMessage = "Created schedule for section ID: $section_id";
            if ($enrolledCount > 0) {
                $logMessage .= ". Enrolled $enrolledCount student(s)";
            }
            logAdminAction($pdo, $_SESSION['user_id'], 'create_schedule', 'schedule', $schedule_id, $logMessage);
            
            $message = "Schedule added successfully!";
            if ($enrolledCount > 0) {
                $message .= " $enrolledCount student(s) automatically enrolled.";
            }
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
}

// Handle schedule update
if (isset($_POST['update_schedule'])) {
    $schedule_id = $_POST['schedule_id'];
    $section_id = $_POST['section_id'];
    $subject_id = $_POST['subject_id'];
    $teacher_id = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
    $classroom_id = !empty($_POST['classroom_id']) ? intval($_POST['classroom_id']) : null;
    $day_of_week = $_POST['day_of_week'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $room = trim($_POST['room'] ?? '');
    $academic_year = trim($_POST['academic_year']);
    $semester = $_POST['semester'] ?? '1st';
    $status = $_POST['status'] ?? 'active';
    
    if (empty($section_id) || empty($subject_id) || empty($day_of_week) || empty($start_time) || empty($end_time) || empty($academic_year)) {
        $message = "Section, subject, day, time, and academic year are required!";
        $message_type = "danger";
    } elseif ($start_time >= $end_time) {
        $message = "End time must be after start time!";
        $message_type = "danger";
    } else {
        // Check for time conflicts (excluding current schedule)
        $conflictStmt = $pdo->prepare("
            SELECT id FROM section_schedules 
            WHERE section_id = ? AND day_of_week = ? AND id != ? AND status = 'active'
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");
        $conflictStmt->execute([$section_id, $day_of_week, $schedule_id, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
        
        if ($conflictStmt->rowCount() > 0) {
            $message = "Time conflict detected! Another schedule exists at this time.";
            $message_type = "danger";
        } else {
            // Check if teacher_id is being set/changed
            $oldScheduleStmt = $pdo->prepare("SELECT teacher_id FROM section_schedules WHERE id = ?");
            $oldScheduleStmt->execute([$schedule_id]);
            $oldSchedule = $oldScheduleStmt->fetch(PDO::FETCH_ASSOC);
            $oldTeacherId = $oldSchedule['teacher_id'] ?? null;
            $isTeacherBeingAssigned = ($teacher_id && (!$oldTeacherId || $oldTeacherId != $teacher_id));
            
            $stmt = $pdo->prepare("UPDATE section_schedules SET section_id = ?, subject_id = ?, teacher_id = ?, classroom_id = ?, day_of_week = ?, start_time = ?, end_time = ?, room = ?, academic_year = ?, semester = ?, status = ? WHERE id = ?");
            $stmt->execute([$section_id, $subject_id, $teacher_id, $classroom_id, $day_of_week, $start_time, $end_time, $room ?: null, $academic_year, $semester, $status, $schedule_id]);
            
            // If teacher is being assigned, enroll all students in this section who don't have grade entries yet
            $enrolledCount = 0;
            if ($isTeacherBeingAssigned && $teacher_id) {
                $enrolledCount = enrollStudentsWhenTeacherAssigned($pdo, $schedule_id, $teacher_id);
            }
            
            $logMessage = "Updated schedule ID: $schedule_id";
            if ($enrolledCount > 0) {
                $logMessage .= ". Enrolled $enrolledCount student(s)";
            }
            logAdminAction($pdo, $_SESSION['user_id'], 'update_schedule', 'schedule', $schedule_id, $logMessage);
            
            $message = "Schedule updated successfully!";
            if ($enrolledCount > 0) {
                $message .= " $enrolledCount student(s) automatically enrolled.";
            }
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
}

// Handle schedule deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_schedule' && isset($_GET['id'])) {
    $schedule_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM section_schedules WHERE id = ?");
        $stmt->execute([$schedule_id]);
        logAdminAction($pdo, $_SESSION['user_id'], 'delete_schedule', 'schedule', $schedule_id, "Deleted schedule ID: $schedule_id");
        $message = "Schedule deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// ==================== ENROLLMENT MANAGEMENT ====================

// Ensure enrollment tables exist
if (!function_exists('ensureEnrollmentTables')) {
    function ensureEnrollmentTables(PDO $pdo): void {
        try {
            // Check if enrollment_periods table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'enrollment_periods'");
            if ($stmt->rowCount() === 0) {
                // Table doesn't exist, create it
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `enrollment_periods` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `course_id` int(11) NOT NULL,
                      `academic_year` varchar(20) NOT NULL,
                      `semester` enum('1st','2nd','Summer') NOT NULL,
                      `start_date` datetime NOT NULL,
                      `end_date` datetime NOT NULL,
                      `status` enum('active','closed','scheduled') DEFAULT 'scheduled',
                      `auto_close` tinyint(1) DEFAULT 1,
                      `created_by` int(11) DEFAULT NULL,
                      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `course_id` (`course_id`),
                      KEY `created_by` (`created_by`),
                      KEY `idx_course_period` (`course_id`, `academic_year`, `semester`),
                      KEY `idx_status` (`status`),
                      KEY `idx_dates` (`start_date`, `end_date`),
                      CONSTRAINT `enrollment_periods_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `enrollment_periods_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
            }
            
            // Check if enrollment_requests table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'enrollment_requests'");
            if ($stmt->rowCount() === 0) {
                // Table doesn't exist, create it
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `enrollment_requests` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `student_id` int(11) NOT NULL,
                      `course_id` int(11) NOT NULL,
                      `enrollment_period_id` int(11) NOT NULL,
                      `academic_year` varchar(20) NOT NULL,
                      `semester` enum('1st','2nd','Summer') NOT NULL,
                      `status` enum('pending','approved','rejected','voided') DEFAULT 'pending',
                      `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      `reviewed_by` int(11) DEFAULT NULL,
                      `reviewed_at` timestamp NULL DEFAULT NULL,
                      `rejection_reason` text DEFAULT NULL,
                      `requirements_verified` tinyint(1) DEFAULT 0,
                      `notes` text DEFAULT NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `unique_student_period` (`student_id`, `enrollment_period_id`),
                      KEY `student_id` (`student_id`),
                      KEY `course_id` (`course_id`),
                      KEY `enrollment_period_id` (`enrollment_period_id`),
                      KEY `reviewed_by` (`reviewed_by`),
                      KEY `idx_status` (`status`),
                      KEY `idx_academic_year` (`academic_year`, `semester`),
                      CONSTRAINT `enrollment_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `enrollment_requests_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `enrollment_requests_ibfk_3` FOREIGN KEY (`enrollment_period_id`) REFERENCES `enrollment_periods` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `enrollment_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
            }
        } catch (PDOException $e) {
            error_log("Error ensuring enrollment tables: " . $e->getMessage());
        }
    }
}

// Ensure enrollment tables exist
ensureEnrollmentTables($pdo);

// Auto-close enrollment periods that have passed their end date
try {
    $pdo->exec("
        UPDATE enrollment_periods 
        SET status = 'closed' 
        WHERE status = 'active' 
        AND auto_close = 1 
        AND end_date < NOW()
    ");
} catch (PDOException $e) {
    error_log("Error auto-closing enrollment periods: " . $e->getMessage());
}

// Handle enrollment period creation
if (isset($_POST['add_enrollment_period'])) {
    $course_id = intval($_POST['course_id']);
    $academic_year = trim($_POST['academic_year']);
    $semester = $_POST['semester'] ?? '1st';
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $auto_close = isset($_POST['auto_close']) ? 1 : 0;
    
    if (empty($course_id) || empty($academic_year) || empty($start_date) || empty($end_date)) {
        $message = "All fields are required!";
        $message_type = "danger";
    } elseif (strtotime($start_date) >= strtotime($end_date)) {
        $message = "End date must be after start date!";
        $message_type = "danger";
    } else {
        // Check if period already exists for this course/academic year/semester
        $checkStmt = $pdo->prepare("
            SELECT id FROM enrollment_periods 
            WHERE course_id = ? AND academic_year = ? AND semester = ?
        ");
        $checkStmt->execute([$course_id, $academic_year, $semester]);
        
        if ($checkStmt->rowCount() > 0) {
            $message = "Enrollment period already exists for this program and semester!";
            $message_type = "danger";
        } else {
            $status = (strtotime($start_date) <= time() && strtotime($end_date) >= time()) ? 'active' : 'scheduled';
            $stmt = $pdo->prepare("
                INSERT INTO enrollment_periods (course_id, academic_year, semester, start_date, end_date, status, auto_close, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$course_id, $academic_year, $semester, $start_date, $end_date, $status, $auto_close, $_SESSION['user_id']]);
            $period_id = $pdo->lastInsertId();
            
            logAdminAction($pdo, $_SESSION['user_id'], 'create_enrollment_period', 'enrollment_period', $period_id, "Created enrollment period for program ID: $course_id");
            $message = "Enrollment period created successfully!";
            $message_type = "success";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
    }
}

// Handle enrollment period update
if (isset($_POST['update_enrollment_period'])) {
    $period_id = intval($_POST['period_id']);
    $course_id = intval($_POST['course_id']);
    $academic_year = trim($_POST['academic_year']);
    $semester = $_POST['semester'] ?? '1st';
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'] ?? 'scheduled';
    $auto_close = isset($_POST['auto_close']) ? 1 : 0;
    
    if (empty($course_id) || empty($academic_year) || empty($start_date) || empty($end_date)) {
        $message = "All fields are required!";
        $message_type = "danger";
    } elseif (strtotime($start_date) >= strtotime($end_date)) {
        $message = "End date must be after start date!";
        $message_type = "danger";
    } else {
        $stmt = $pdo->prepare("
            UPDATE enrollment_periods 
            SET course_id = ?, academic_year = ?, semester = ?, start_date = ?, end_date = ?, status = ?, auto_close = ? 
            WHERE id = ?
        ");
        $stmt->execute([$course_id, $academic_year, $semester, $start_date, $end_date, $status, $auto_close, $period_id]);
        
        logAdminAction($pdo, $_SESSION['user_id'], 'update_enrollment_period', 'enrollment_period', $period_id, "Updated enrollment period ID: $period_id");
        $message = "Enrollment period updated successfully!";
        $message_type = "success";
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
        exit();
    }
}

// Handle enrollment period deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_enrollment_period' && isset($_GET['id'])) {
    $period_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM enrollment_periods WHERE id = ?");
        $stmt->execute([$period_id]);
        logAdminAction($pdo, $_SESSION['user_id'], 'delete_enrollment_period', 'enrollment_period', $period_id, "Deleted enrollment period ID: $period_id");
        $message = "Enrollment period deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=schedules&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle enrollment request approval
if (isset($_POST['approve_enrollment_request'])) {
    $request_id = intval($_POST['request_id']);
    $requirements_verified = isset($_POST['requirements_verified']) ? 1 : 0;
    
    if (!$requirements_verified) {
        $message = "You must verify that requirements are met before approving!";
        $message_type = "danger";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get enrollment request details
            $requestStmt = $pdo->prepare("
                SELECT er.*, ep.course_id as period_course_id 
                FROM enrollment_requests er
                JOIN enrollment_periods ep ON er.enrollment_period_id = ep.id
                WHERE er.id = ?
            ");
            $requestStmt->execute([$request_id]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                throw new Exception("Enrollment request not found");
            }
            
            // Update request status
            $updateStmt = $pdo->prepare("
                UPDATE enrollment_requests 
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), requirements_verified = 1 
                WHERE id = ?
            ");
            $updateStmt->execute([$_SESSION['user_id'], $request_id]);
            
            // Auto-enroll student in courses for that semester
            // Get student's current course
            $studentStmt = $pdo->prepare("SELECT course_id FROM users WHERE id = ?");
            $studentStmt->execute([$request['student_id']]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student && $student['course_id']) {
                // Get sections for the student's course and target semester
                // Try to match by course_id first, then by course name
                $sectionsStmt = $pdo->prepare("
                    SELECT s.id, s.section_name, s.year_level 
                    FROM sections s
                    WHERE s.course_id = ? AND s.academic_year = ? AND s.semester = ?
                ");
                $sectionsStmt->execute([$student['course_id'], $request['academic_year'], $request['semester']]);
                $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no sections found, try to get student's current section and create enrollment for that section's next semester
                if (empty($sections)) {
                    // Get student's current section from classroom_students
                    $currentSectionStmt = $pdo->prepare("
                        SELECT cl.section, cl.year_level, cl.program
                        FROM classroom_students cs
                        JOIN classrooms cl ON cs.classroom_id = cl.id
                        WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled'
                        ORDER BY cs.id DESC
                        LIMIT 1
                    ");
                    $currentSectionStmt->execute([$request['student_id']]);
                    $currentSection = $currentSectionStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($currentSection) {
                        // Try to find section by matching section name and year level
                        $sectionMatchStmt = $pdo->prepare("
                            SELECT s.id FROM sections s
                            JOIN courses c ON s.course_id = c.id
                            WHERE s.section_name = ? AND s.year_level = ? 
                            AND s.academic_year = ? AND s.semester = ?
                            AND c.id = ?
                            LIMIT 1
                        ");
                        $sectionMatchStmt->execute([$currentSection['section'], $currentSection['year_level'], $request['academic_year'], $request['semester'], $student['course_id']]);
                        $matchedSection = $sectionMatchStmt->fetch(PDO::FETCH_ASSOC);
                        if ($matchedSection) {
                            $sections = [['id' => $matchedSection['id']]];
                        }
                    }
                }
                
                // Get section schedules for enrollment
                foreach ($sections as $section) {
                    $schedulesStmt = $pdo->prepare("
                        SELECT DISTINCT subject_id, teacher_id 
                        FROM section_schedules 
                        WHERE section_id = ? AND status = 'active'
                    ");
                    $schedulesStmt->execute([$section['id']]);
                    $schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($schedules as $schedule) {
                        // Check if grade entry already exists
                        $gradeCheckStmt = $pdo->prepare("
                            SELECT id FROM grades 
                            WHERE student_id = ? AND subject_id = ? 
                            AND academic_year = ? AND semester = ?
                        ");
                        $gradeCheckStmt->execute([$request['student_id'], $schedule['subject_id'], $request['academic_year'], $request['semester']]);
                        
                        if ($gradeCheckStmt->rowCount() === 0) {
                            // Create initial grade entry (enrollment marker)
                            $gradeStmt = $pdo->prepare("
                                INSERT INTO grades (student_id, subject_id, teacher_id, grade_type, grade, academic_year, semester, created_at) 
                                VALUES (?, ?, ?, 'participation', 0, ?, ?, NOW())
                            ");
                            $gradeStmt->execute([$request['student_id'], $schedule['subject_id'], $schedule['teacher_id'], $request['academic_year'], $request['semester']]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            logAdminAction($pdo, $_SESSION['user_id'], 'approve_enrollment', 'enrollment_request', $request_id, "Approved enrollment request ID: $request_id");
            $message = "Enrollment request approved successfully! Student has been enrolled in courses.";
            $message_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=enrollment&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle enrollment request rejection
if (isset($_POST['reject_enrollment_request'])) {
    $request_id = intval($_POST['request_id']);
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    $stmt = $pdo->prepare("
        UPDATE enrollment_requests 
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $rejection_reason, $request_id]);
    
    logAdminAction($pdo, $_SESSION['user_id'], 'reject_enrollment', 'enrollment_request', $request_id, "Rejected enrollment request ID: $request_id");
    $message = "Enrollment request rejected.";
    $message_type = "success";
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=enrollment&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle AJAX request for section students
if (isset($_GET['action']) && $_GET['action'] === 'get_student_full_data' && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $user_id = (int)$_GET['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$user_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($student) {
            echo json_encode(['success' => true, 'student' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_section_students' && isset($_GET['section_id'])) {
    header('Content-Type: application/json');
    
    try {
        $sectionId = $_GET['section_id'];
        
        // Get section details
        $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
        $sectionStmt->execute([$sectionId]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section) {
            echo json_encode(['success' => false, 'error' => 'Section not found']);
            exit();
        }
        
        // Get course details
        $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $courseStmt->execute([$section['course_id']]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
        
        // Find classroom
        $classroomStmt = $pdo->prepare("
            SELECT id FROM classrooms
            WHERE section = ? AND program = ? AND year_level = ?
            ORDER BY id
            LIMIT 1
        ");
        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($classroom) {
            // Get students in this classroom
            $studentsStmt = $pdo->prepare("
                SELECT u.id, u.first_name, u.last_name, u.student_id_number, u.email
                FROM users u
                JOIN classroom_students cs ON u.id = cs.student_id
                WHERE cs.classroom_id = ?
                ORDER BY u.last_name, u.first_name
            ");
            $studentsStmt->execute([$classroom['id']]);
            $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'students' => $students]);
        } else {
            echo json_encode(['success' => true, 'students' => []]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for teacher sections
if (isset($_GET['action']) && $_GET['action'] === 'get_teacher_sections' && isset($_GET['teacher_id'])) {
    header('Content-Type: application/json');
    $teacher_id = intval($_GET['teacher_id']);
    
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.section, c.year_level, c.program, c.academic_year, c.semester, c.status, c.description
            FROM classrooms c
            WHERE c.teacher_id = ?
            ORDER BY c.academic_year DESC, c.year_level, c.section
        ");
        $stmt->execute([$teacher_id]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'sections' => $sections
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit();
    }
}

// Handle delete actions
// Handle get_application_details separately (doesn't need 'id' parameter)
// Handle AJAX check for duplicate subject
if (isset($_GET['action']) && $_GET['action'] === 'check_subject_duplicate' && isset($_GET['name']) && isset($_GET['code'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['name']);
    $code = trim($_GET['code']);
    
    $check_stmt = $pdo->prepare("SELECT id, name, code FROM subjects WHERE LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)");
    $check_stmt->execute([$code, $name]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        if (strtolower($existing['code']) === strtolower($code)) {
            $message = "Course code '{$code}' already exists!";
            $details = "A course with code '{$existing['code']}' and name '{$existing['name']}' is already in the system.";
        } else {
            $message = "Course name '{$name}' already exists!";
            $details = "A course with name '{$existing['name']}' and code '{$existing['code']}' is already in the system.";
        }
        echo json_encode([
            'exists' => true,
            'message' => $message,
            'details' => $details
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_application_details') {
    $application_id = $_GET['application_id'] ?? 0;
    try {
        // Check if tables exist
        $tables_exist = true;
        try {
            $pdo->query("SELECT 1 FROM application_requirements LIMIT 1");
        } catch (Exception $e) {
            $tables_exist = false;
        }
        
        if (!$tables_exist) {
            // Tables don't exist, return empty arrays
            header('Content-Type: application/json');
            echo json_encode(['requirements' => [], 'payment' => null]);
            exit();
        }
        
        // Get requirements - ensure only one row per requirement
        // First, get all unique requirements by name (in case there are duplicate records in DB)
        $stmt = $pdo->prepare("
            SELECT MIN(id) as id, requirement_name 
            FROM application_requirements 
            GROUP BY requirement_name 
            ORDER BY requirement_name
        ");
        $stmt->execute();
        $all_reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Then get the latest submission for each requirement for this application
        $requirements = [];
        foreach ($all_reqs as $req) {
            // Get the latest submission for this requirement (check all requirement IDs with this name)
            $stmt = $pdo->prepare("
                SELECT ars.id as submission_id, ars.status, ars.file_path, ars.requirement_id
                FROM application_requirement_submissions ars
                JOIN application_requirements ar ON ars.requirement_id = ar.id
                WHERE ar.requirement_name = ? AND ars.application_id = ?
                ORDER BY ars.submitted_at DESC
                LIMIT 1
            ");
            $stmt->execute([$req['requirement_name'], $application_id]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $requirements[] = [
                'requirement_id' => $req['id'],
                'requirement_name' => $req['requirement_name'],
                'submission_id' => $submission ? $submission['submission_id'] : null,
                'status' => $submission ? $submission['status'] : null,
                'file_path' => $submission ? $submission['file_path'] : null
            ];
        }
        
        // Get payment
        $payment = null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM application_payments WHERE application_id = ? ORDER BY submitted_at DESC LIMIT 1");
            $stmt->execute([$application_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Payment table might not exist, set to null
            $payment = null;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['requirements' => $requirements, 'payment' => $payment]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    switch ($action) {
        case 'delete_user':
            // Get user info before deletion for logging
            $stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user_info = $stmt->fetch();
            
            $result = deleteUser($pdo, $id);
            if ($result === true) {
                if ($user_info) {
                    logAdminAction($pdo, $_SESSION['user_id'], 'delete_user', 'user', $id, "Deleted user: " . $user_info['first_name'] . " " . $user_info['last_name'] . " (" . $user_info['role'] . ")");
                }
                $message = "User deleted successfully!";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
            break;
            
        case 'delete_subject':
            // Get subject info before deletion for logging
            $stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
            $stmt->execute([$id]);
            $subject_info = $stmt->fetch();
            
            $result = deleteSubject($pdo, $id);
            if ($result === true) {
                if ($subject_info) {
                    logAdminAction($pdo, $_SESSION['user_id'], 'delete_subject', 'subject', $id, "Deleted course: " . $subject_info['name']);
                }
                $message = "Course deleted successfully!";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=subjects&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
            break;
            
        case 'delete_course':
            // Get course info before deletion for logging
            $stmt = $pdo->prepare("SELECT name FROM courses WHERE id = ?");
            $stmt->execute([$id]);
            $course_info = $stmt->fetch();
            
            try {
                $pdo->beginTransaction();
                // Delete associated sections first
                $stmt = $pdo->prepare("DELETE FROM sections WHERE course_id = ?");
                $stmt->execute([$id]);
                // Delete the course
                $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
                
                if ($course_info) {
                    logAdminAction($pdo, $_SESSION['user_id'], 'delete_course', 'course', $id, "Deleted course: " . $course_info['name']);
                }
                $message = "Program deleted successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error deleting course: " . $e->getMessage();
                $message_type = "danger";
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=courses&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
            break;
            
        case 'delete_section':
            // Get section info before deletion for logging
            $stmt = $pdo->prepare("SELECT section_name FROM sections WHERE id = ?");
            $stmt->execute([$id]);
            $section_info = $stmt->fetch();
            
            try {
                $pdo->beginTransaction();
                // Delete associated classroom_students
                $stmt = $pdo->prepare("DELETE cs FROM classroom_students cs JOIN classrooms c ON cs.classroom_id = c.id WHERE c.section = (SELECT section_name FROM sections WHERE id = ?)");
                $stmt->execute([$id]);
                // Delete associated classrooms
                $stmt = $pdo->prepare("DELETE FROM classrooms WHERE section = (SELECT section_name FROM sections WHERE id = ?)");
                $stmt->execute([$id]);
                // Delete the section
                $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
                
                if ($section_info) {
                    logAdminAction($pdo, $_SESSION['user_id'], 'delete_section', 'section', $id, "Deleted section: " . $section_info['section_name']);
                }
                $message = "Section deleted successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error deleting section: " . $e->getMessage();
                $message_type = "danger";
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=sections&msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
            break;
            
    }
}

// Handle adding student to section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student_to_section'])) {
    $sectionId = $_POST['section_id'];
    $studentId = $_POST['student_id'];
    
    try {
        // Get section details
        $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
        $sectionStmt->execute([$sectionId]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section) {
            throw new Exception("Section not found");
        }
        
        // Get course details
        $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $courseStmt->execute([$section['course_id']]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
        
        // Find or create classroom for this section
        $classroomStmt = $pdo->prepare("
            SELECT id FROM classrooms
            WHERE section = ? AND program = ? AND year_level = ?
            ORDER BY id
            LIMIT 1
        ");
        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$classroom) {
            // Create classroom if it doesn't exist
            $insertClassroom = $pdo->prepare("
                INSERT INTO classrooms (name, description, teacher_id, program, year_level, section, academic_year, semester, max_students, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insertClassroom->execute([
                sprintf('%s %s - Section %s', $course['code'], $section['year_level'], $section['section_name']),
                "Auto-generated classroom for {$course['name']} ({$section['year_level']} - Section {$section['section_name']})",
                $section['teacher_id'],
                $course['name'],
                $section['year_level'],
                $section['section_name'],
                $section['academic_year'],
                $section['semester'],
                $section['max_students'] ?? 50
            ]);
            $classroomId = $pdo->lastInsertId();
        } else {
            $classroomId = $classroom['id'];
        }
        
        // Check if student is already in classroom
        $checkStmt = $pdo->prepare("SELECT id FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
        $checkStmt->execute([$classroomId, $studentId]);
        
        if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
            $message = "Student is already in this section!";
            $message_type = "warning";
        } else {
            // Check course prerequisites if subjects are assigned to this classroom
            $subjectsStmt = $pdo->prepare("
                SELECT s.id, s.name, s.code, s.prerequisites
                FROM subjects s
                JOIN classrooms c ON (s.program = c.program OR s.program IS NULL) 
                    AND (s.year_level = c.year_level OR s.year_level IS NULL)
                WHERE c.id = ? AND s.status = 'active'
            ");
            $subjectsStmt->execute([$classroomId]);
            $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $prerequisiteWarnings = [];
            foreach ($subjects as $subject) {
                if (!empty($subject['prerequisites'])) {
                    $prereqCheck = checkCoursePrerequisites($pdo, $studentId, $subject['id']);
                    if (!$prereqCheck['met']) {
                        $prerequisiteWarnings[] = $subject['name'] . ': ' . $prereqCheck['message'];
                    }
                }
            }
            
            if (!empty($prerequisiteWarnings)) {
                $message = "Warning: Student may not meet prerequisites for some subjects: " . implode('; ', $prerequisiteWarnings);
                $message_type = "warning";
                // Continue with enrollment but show warning
            }
            // Add student to classroom
            $insertStmt = $pdo->prepare("INSERT INTO classroom_students (classroom_id, student_id) VALUES (?, ?)");
            $insertStmt->execute([$classroomId, $studentId]);
            
            // Automatically enroll student in all courses assigned to this section via section_schedules
            try {
                $enrolledCount = enrollStudentInSectionCourses($pdo, $studentId, $sectionId, $classroomId, $section['teacher_id']);
                
                if ($enrolledCount > 0) {
                    logAdminAction($pdo, $_SESSION['user_id'], 'auto_enroll_courses', 'enrollment', $studentId, "Automatically enrolled student in $enrolledCount course(s) from section schedule");
                } else {
                    // Log if no courses were enrolled (might be because section has no schedules or no teachers assigned)
                    $scheduleCheck = $pdo->prepare("SELECT COUNT(*) FROM section_schedules WHERE section_id = ? AND status = 'active'");
                    $scheduleCheck->execute([$sectionId]);
                    $scheduleCount = $scheduleCheck->fetchColumn();
                    if ($scheduleCount > 0) {
                        error_log("Warning: Student $studentId added to section $sectionId but no courses were enrolled. Section has $scheduleCount active schedule(s).");
                    }
                }
            } catch (Exception $e) {
                // Log error but don't fail the section assignment
                error_log("Error enrolling student $studentId in section courses: " . $e->getMessage());
            }
            
            // Recalculate and update section student count from actual classroom_students
            $countStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT cs.student_id) 
                FROM classroom_students cs 
                WHERE cs.classroom_id = ?
            ");
            $countStmt->execute([$classroomId]);
            $actualCount = $countStmt->fetchColumn();
            
            $updateStmt = $pdo->prepare("UPDATE sections SET current_students = ? WHERE id = ?");
            $updateStmt->execute([$actualCount, $sectionId]);
            
            // Update student's section in users table
            $updateUserStmt = $pdo->prepare("UPDATE users SET section = ?, year_level = ? WHERE id = ?");
            $updateUserStmt->execute([$section['section_name'], $section['year_level'], $studentId]);
            
            $enrollmentMsg = $tableExists && isset($enrolledCount) && $enrolledCount > 0 
                ? " and automatically enrolled in $enrolledCount course(s)" 
                : "";
            $message = "Student added to section successfully{$enrollmentMsg}!";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=sections&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle removing student from section
if (isset($_GET['action']) && $_GET['action'] === 'remove_student_from_section' && isset($_GET['section_id']) && isset($_GET['student_id'])) {
    $sectionId = $_GET['section_id'];
    $studentId = $_GET['student_id'];
    
    try {
        // Get section details
        $sectionStmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
        $sectionStmt->execute([$sectionId]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section) {
            throw new Exception("Section not found");
        }
        
        // Get course details
        $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $courseStmt->execute([$section['course_id']]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
        
        // Find classroom
        $classroomStmt = $pdo->prepare("
            SELECT id FROM classrooms
            WHERE section = ? AND program = ? AND year_level = ?
            ORDER BY id
            LIMIT 1
        ");
        $classroomStmt->execute([$section['section_name'], $course['name'], $section['year_level']]);
        $classroom = $classroomStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($classroom) {
            // Remove student from classroom
            $deleteStmt = $pdo->prepare("DELETE FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
            $deleteStmt->execute([$classroom['id'], $studentId]);
            
            // Recalculate and update section student count from actual classroom_students
            $countStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT cs.student_id) 
                FROM classroom_students cs 
                WHERE cs.classroom_id = ?
            ");
            $countStmt->execute([$classroom['id']]);
            $actualCount = $countStmt->fetchColumn();
            
            $updateStmt = $pdo->prepare("UPDATE sections SET current_students = ? WHERE id = ?");
            $updateStmt->execute([$actualCount, $sectionId]);
            
            $message = "Student removed from section successfully!";
            $message_type = "success";
        } else {
            $message = "Classroom not found!";
            $message_type = "warning";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=sections&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_picture'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Invalid security token!";
        $message_type = "danger";
    } else {
        $userId = $_POST['user_id'] ?? $_SESSION['user_id'];
        $maxSize = getSystemSetting('max_upload_size', 5242880); // 5MB default
        $allowedTypes = explode(',', getSystemSetting('allowed_file_types', 'jpg,jpeg,png,gif'));
        
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExt, $allowedTypes)) {
                $message = "Invalid file type. Allowed: " . implode(', ', $allowedTypes);
                $message_type = "danger";
            } elseif ($file['size'] > $maxSize) {
                $message = "File too large. Maximum size: " . round($maxSize / 1024 / 1024, 2) . "MB";
                $message_type = "danger";
            } else {
                $uploadDir = __DIR__ . '/../../assets/uploads/profiles/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'profile_' . $userId . '_' . time() . '.' . $fileExt;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $relativePath = 'uploads/profiles/' . $fileName;
                    $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                    $stmt->execute([$relativePath, $userId]);
                    
                    $message = "Profile picture uploaded successfully!";
                    $message_type = "success";
                } else {
                    $message = "Failed to upload file!";
                    $message_type = "danger";
                }
            }
        } else {
            $message = "No file uploaded or upload error!";
            $message_type = "danger";
        }
    }
    
    $redirectTab = $_POST['redirect_tab'] ?? 'users';
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=$redirectTab&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle export requests
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $type = $_GET['type'] ?? 'students';
    $format = $_GET['format'] ?? 'csv';
    
    if ($type === 'students') {
        exportStudents($pdo, $format);
    } elseif ($type === 'grades') {
        $studentId = $_GET['student_id'] ?? null;
        $subjectId = $_GET['subject_id'] ?? null;
        exportGrades($pdo, $format, $studentId, $subjectId);
    }
    exit();
}

// Handle import requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Invalid security token!";
        $message_type = "danger";
    } else {
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../assets/uploads/imports/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = 'import_' . time() . '_' . basename($_FILES['import_file']['name']);
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['import_file']['tmp_name'], $filePath)) {
                $result = importStudents($pdo, $filePath);
                $message = $result['message'];
                $message_type = $result['imported'] > 0 ? "success" : "warning";
                
                // Clean up file
                unlink($filePath);
            } else {
                $message = "Failed to upload import file!";
                $message_type = "danger";
            }
        } else {
            $message = "No file uploaded!";
            $message_type = "danger";
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Invalid security token!";
        $message_type = "danger";
    } else {
        $action = $_POST['bulk_action'];
        $idsInput = $_POST['selected_ids'] ?? [];
        if (is_string($idsInput)) {
            $ids = array_filter(array_map('intval', array_filter(array_map('trim', explode(',', $idsInput)))));
        } elseif (is_array($idsInput)) {
            $ids = array_filter(array_map('intval', $idsInput));
        } else {
            $ids = [];
        }
        
        if (empty($ids)) {
            $message = "No items selected!";
            $message_type = "warning";
        } else {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            try {
                if ($action === 'delete_users') {
                    $totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id IN ($placeholders) AND role = 'admin'");
                    $adminCountStmt->execute($ids);
                    $adminsMarkedForDeletion = (int)$adminCountStmt->fetchColumn();
                    
                    if ($totalAdmins > 0 && ($totalAdmins - $adminsMarkedForDeletion) <= 0) {
                        $message = "Cannot delete all administrator accounts. Keep at least one admin active.";
                        $message_type = "danger";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                        $stmt->execute($ids);
                        $deletedCount = $stmt->rowCount();
                        $message = $deletedCount . " user(s) deleted successfully!";
                        $message_type = "success";
                    }
                } elseif ($action === 'activate_users') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $message = count($ids) . " user(s) activated successfully!";
                    $message_type = "success";
                } elseif ($action === 'deactivate_users') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $message = count($ids) . " user(s) deactivated successfully!";
                    $message_type = "success";
                }
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
    
    $redirectTab = $_POST['redirect_tab'] ?? 'users';
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=$redirectTab&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle backup creation
if (isset($_GET['action']) && $_GET['action'] === 'create_backup') {
    $result = createDatabaseBackup($pdo, 'manual');
    $message = $result['message'];
    $message_type = $result['success'] ? "success" : "danger";
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=settings&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle backup deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_backup' && isset($_GET['id'])) {
    $backupId = $_GET['id'];
    $result = deleteBackup($pdo, $backupId);
    $message = $result['message'];
    $message_type = $result['success'] ? "success" : "danger";
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=settings&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle backup restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Invalid security token!";
        $message_type = "danger";
    } else {
        $backupId = $_POST['backup_id'] ?? null;
        if ($backupId) {
            $stmt = $pdo->prepare("SELECT backup_path FROM database_backups WHERE id = ?");
            $stmt->execute([$backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($backup && file_exists($backup['backup_path'])) {
                $result = restoreDatabaseBackup($pdo, $backup['backup_path']);
                $message = $result['message'];
                $message_type = $result['success'] ? "success" : "danger";
            } else {
                $message = "Backup file not found!";
                $message_type = "danger";
            }
        } else {
            $message = "No backup selected!";
            $message_type = "warning";
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=settings&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle system settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Invalid security token!";
        $message_type = "danger";
    } else {
        $settings = $_POST['settings'] ?? [];
        $updated = 0;
        
        foreach ($settings as $key => $value) {
            if (setSystemSetting($key, $value)) {
                $updated++;
            }
        }
        
        $message = "$updated setting(s) updated successfully!";
        $message_type = "success";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=settings&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle user preferences (language)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preference'])) {
    if (isset($_SESSION['user_id'])) {
        $key = $_POST['preference_key'] ?? '';
        $value = $_POST['preference_value'] ?? '';
        
        if (setUserPreference($_SESSION['user_id'], $key, $value)) {
            // Also set cookie for immediate effect
            setcookie($key, $value, time() + (365 * 24 * 60 * 60), '/');
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update preference']);
        }
        exit();
    }
}

// Get admin user information
$admin = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Continue without admin info
}

// Get statistics and data
$total_students = 0;
$total_enrolled_students = 0;
$enrollmentEligibilityClauseStats = getEnrolledStudentEligibilityCondition('u');
try {
    $approvedStudentQuery = "
        SELECT COUNT(*) AS total
        FROM users u
        WHERE u.role = 'student'
          AND {$enrollmentEligibilityClauseStats}
    ";
    $total_students = (int)$pdo->query($approvedStudentQuery)->fetchColumn();
    $total_enrolled_students = $total_students;
} catch (PDOException $e) {
    // admission_applications table might not exist yet; fallback to legacy counts
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $total_enrolled_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND student_id_number IS NOT NULL")->fetchColumn();
}

// ============================================
// IRREGULAR STUDENTS MANAGEMENT HANDLERS
// ============================================

// Handle grade update for irregular students
if (isset($_POST['update_irregular_grade'])) {
    $grade_id = $_POST['grade_id'];
    $new_grade = floatval($_POST['grade']);
    $remarks = trim($_POST['remarks'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Update grade
        $stmt = $pdo->prepare("
            UPDATE grades 
            SET grade = ?, remarks = ?, manually_edited = 1, edited_by = ?, edited_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_grade, $remarks, $_SESSION['user_id'], $grade_id]);
        
        logAdminAction($pdo, $_SESSION['user_id'], 'update_irregular_grade', 'grade', $grade_id, "Updated grade for irregular student: Grade ID $grade_id to $new_grade");
        
        $pdo->commit();
        $message = "Grade updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error updating grade: " . $e->getMessage();
        $message_type = "danger";
    }
    // TEMPORARILY DISABLED - Redirect to users tab instead of irregular_students
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
    // header("Location: " . $_SERVER['PHP_SELF'] . "?tab=irregular_students&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle adding back subject for irregular student
if (isset($_POST['add_back_subject'])) {
    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];
    $required_units = floatval($_POST['required_units'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Check if already exists
        $check_stmt = $pdo->prepare("SELECT id FROM student_back_subjects WHERE student_id = ? AND subject_id = ?");
        $check_stmt->execute([$student_id, $subject_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = "Back subject already exists for this student!";
            $message_type = "warning";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO student_back_subjects (student_id, subject_id, required_units, notes, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$student_id, $subject_id, $required_units, $notes]);
            
            logAdminAction($pdo, $_SESSION['user_id'], 'add_back_subject', 'back_subject', $pdo->lastInsertId(), "Added back subject for student ID: $student_id");
            
            $message = "Back subject added successfully!";
            $message_type = "success";
        }
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error adding back subject: " . $e->getMessage();
        $message_type = "danger";
    }
    // TEMPORARILY DISABLED - Redirect to users tab instead of irregular_students
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
    // header("Location: " . $_SERVER['PHP_SELF'] . "?tab=irregular_students&student_id=" . $student_id . "&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle marking back subject as completed
if (isset($_POST['complete_back_subject'])) {
    $back_subject_id = $_POST['back_subject_id'];
    $completed_units = floatval($_POST['completed_units'] ?? 0);
    $completion_date = $_POST['completion_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Get back subject info
        $get_stmt = $pdo->prepare("SELECT student_id, subject_id, required_units FROM student_back_subjects WHERE id = ?");
        $get_stmt->execute([$back_subject_id]);
        $back_subject = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$back_subject) {
            throw new Exception("Back subject not found");
        }
        
        // Update back subject
        $update_stmt = $pdo->prepare("
            UPDATE student_back_subjects 
            SET status = 'completed', 
                completed_units = ?, 
                completion_date = ?, 
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$completed_units, $completion_date, $notes, $back_subject_id]);
        
        logAdminAction($pdo, $_SESSION['user_id'], 'complete_back_subject', 'back_subject', $back_subject_id, "Marked back subject as completed for student ID: {$back_subject['student_id']}");
        
        $pdo->commit();
        $message = "Back subject marked as completed!";
        $message_type = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
    // TEMPORARILY DISABLED - Redirect to users tab instead of irregular_students
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
    // header("Location: " . $_SERVER['PHP_SELF'] . "?tab=irregular_students&student_id=" . ($back_subject['student_id'] ?? '') . "&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Handle updating required units for back subject
// NOTE: Cannot update required units for completed back subjects
if (isset($_POST['update_back_subject_units'])) {
    $back_subject_id = $_POST['back_subject_id'];
    $required_units = floatval($_POST['required_units']);
    $completed_units = floatval($_POST['completed_units'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        // Check if back subject is completed - prevent updating required units if completed
        $check_stmt = $pdo->prepare("SELECT status, student_id FROM student_back_subjects WHERE id = ?");
        $check_stmt->execute([$back_subject_id]);
        $back_subject = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$back_subject) {
            throw new Exception("Back subject not found");
        }
        
        if ($back_subject['status'] === 'completed') {
            throw new Exception("Cannot update required units for completed back subjects. Required units are locked once a back subject is marked as completed.");
        }
        
        $stmt = $pdo->prepare("
            UPDATE student_back_subjects 
            SET required_units = ?, completed_units = ?, updated_at = NOW()
            WHERE id = ? AND status != 'completed'
        ");
        $stmt->execute([$required_units, $completed_units, $back_subject_id]);
        
        $student_id = $back_subject['student_id'];
        
        logAdminAction($pdo, $_SESSION['user_id'], 'update_back_subject_units', 'back_subject', $back_subject_id, "Updated units for back subject ID: $back_subject_id");
        
        $pdo->commit();
        $message = "Required units updated successfully!";
        $message_type = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
        $student_id = $_POST['student_id'] ?? ($back_subject['student_id'] ?? '');
    }
    // TEMPORARILY DISABLED - Redirect to users tab instead of irregular_students
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users&msg=" . urlencode($message) . "&type=" . $message_type);
    // header("Location: " . $_SERVER['PHP_SELF'] . "?tab=irregular_students&student_id=" . $student_id . "&msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

$total_teachers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$total_subjects = $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
// Get application counts with error handling
$total_applications = 0;
$pending_applications = 0;
try {
    $total_applications = $pdo->query("SELECT COUNT(*) FROM admission_applications")->fetchColumn();
    $pending_applications = $pdo->query("SELECT COUNT(*) FROM admission_applications WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist - set defaults
    $total_applications = 0;
    $pending_applications = 0;
}

// Get student profile update logs - moved after requireRole to ensure session is established
$profile_logs = [];
$teacher_logs = [];
$log_type = $_GET['log_type'] ?? 'student'; // 'student' or 'teacher'
$log_filters = [
    'student_id' => $_GET['student_filter'] ?? $_GET['teacher_filter'] ?? null, // Support both for persistence
    'teacher_id' => $_GET['teacher_filter'] ?? $_GET['student_filter'] ?? null, // Support both for persistence
    'action_type' => $_GET['action_filter'] ?? null,
    'course_id' => $_GET['course_filter'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null,
];

if (isset($pdo) && $pdo instanceof PDO) {
    // Get student logs with filters
    try {
        $studentLogQuery = "
            SELECT al.*, 
                   u.first_name, u.last_name, u.email,
                   u.student_id_number,
                   u.program as course_name
            FROM admin_logs al
            LEFT JOIN users u ON al.entity_id = u.id
            WHERE al.action = 'student_profile_update'
        ";
        $studentLogParams = [];
        
        if ($log_filters['student_id']) {
            $studentLogQuery .= " AND al.entity_id = ?";
            $studentLogParams[] = $log_filters['student_id'];
        }
        
        if ($log_filters['action_type']) {
            $studentLogQuery .= " AND al.action = ?";
            $studentLogParams[] = $log_filters['action_type'];
        }
        
        if ($log_filters['course_id']) {
            // Filter by student's program/course - match course name with student's program
            $courseStmt = $pdo->prepare("SELECT name FROM courses WHERE id = ? LIMIT 1");
            $courseStmt->execute([$log_filters['course_id']]);
            $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
            if ($course) {
                $studentLogQuery .= " AND u.program = ?";
                $studentLogParams[] = $course['name'];
            }
        }
        
        if ($log_filters['date_from']) {
            $studentLogQuery .= " AND DATE(al.created_at) >= ?";
            $studentLogParams[] = $log_filters['date_from'];
        }
        
        if ($log_filters['date_to']) {
            $studentLogQuery .= " AND DATE(al.created_at) <= ?";
            $studentLogParams[] = $log_filters['date_to'];
        }
        
        $studentLogQuery .= " ORDER BY al.created_at DESC LIMIT 500";
        
        $stmt = $pdo->prepare($studentLogQuery);
        if ($stmt) {
            $stmt->execute($studentLogParams);
            $profile_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Table might not exist - silently fail and show empty logs
        $profile_logs = [];
        error_log("Error fetching student logs: " . $e->getMessage());
    } catch (Exception $e) {
        // Any other error - silently fail
        $profile_logs = [];
    }
    
    // Get teacher logs with filters
    try {
        $teacherLogQuery = "
            SELECT tl.*, 
                   u.first_name, u.last_name, u.email as teacher_email,
                   s.name as subject_name, s.code as subject_code,
                   c.name as course_name, c.code as course_code
            FROM teacher_logs tl
            LEFT JOIN users u ON tl.teacher_id = u.id
            LEFT JOIN subjects s ON tl.subject_id = s.id
            LEFT JOIN courses c ON tl.course_id = c.id
            WHERE 1=1
        ";
        $teacherLogParams = [];
        
        if ($log_filters['teacher_id']) {
            $teacherLogQuery .= " AND tl.teacher_id = ?";
            $teacherLogParams[] = $log_filters['teacher_id'];
        }
        
        if ($log_filters['action_type']) {
            $teacherLogQuery .= " AND tl.action = ?";
            $teacherLogParams[] = $log_filters['action_type'];
        }
        
        if ($log_filters['course_id']) {
            $teacherLogQuery .= " AND tl.course_id = ?";
            $teacherLogParams[] = $log_filters['course_id'];
        }
        
        if ($log_filters['date_from']) {
            $teacherLogQuery .= " AND DATE(tl.created_at) >= ?";
            $teacherLogParams[] = $log_filters['date_from'];
        }
        
        if ($log_filters['date_to']) {
            $teacherLogQuery .= " AND DATE(tl.created_at) <= ?";
            $teacherLogParams[] = $log_filters['date_to'];
        }
        
        $teacherLogQuery .= " ORDER BY tl.created_at DESC LIMIT 500";
        
        $stmt = $pdo->prepare($teacherLogQuery);
        if ($stmt) {
            $stmt->execute($teacherLogParams);
            $teacher_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Table might not exist - silently fail and show empty logs
        $teacher_logs = [];
        error_log("Error fetching teacher logs: " . $e->getMessage());
    } catch (Exception $e) {
        $teacher_logs = [];
    }
}

// Get filter options for both student and teacher logs
$filter_students = [];
$filter_teachers = [];
$filter_courses = [];
$student_action_types = [];
$teacher_action_types = [];

try {
    // Get students for filter
    $stmt = $pdo->query("SELECT DISTINCT id, first_name, last_name, student_id_number FROM users WHERE role = 'student' ORDER BY last_name, first_name");
    $filter_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teachers for filter
    $stmt = $pdo->query("SELECT DISTINCT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY last_name, first_name");
    $filter_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get courses for filter
    $stmt = $pdo->query("SELECT DISTINCT id, name, code FROM courses ORDER BY code");
    $filter_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get student action types (from admin_logs)
    try {
        $stmt = $pdo->query("SELECT DISTINCT action FROM admin_logs WHERE action = 'student_profile_update' ORDER BY action");
        $student_action_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $student_action_types = [];
    }
    
    // Get teacher action types (from teacher_logs)
    try {
        $stmt = $pdo->query("SELECT DISTINCT action FROM teacher_logs ORDER BY action");
        $teacher_action_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist yet - set empty array
        $teacher_action_types = [];
    }
} catch (PDOException $e) {
    // Tables might not exist - set empty arrays
    $filter_students = [];
    $filter_teachers = [];
    $filter_courses = [];
    $student_action_types = [];
    $teacher_action_types = [];
}
// Get approved and rejected application counts with error handling
$approved_applications = 0;
$rejected_applications = 0;
try {
    $approved_applications = $pdo->query("SELECT COUNT(*) FROM admission_applications WHERE status = 'approved'")->fetchColumn();
    $rejected_applications = $pdo->query("SELECT COUNT(*) FROM admission_applications WHERE status = 'rejected'")->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist - set defaults
    $approved_applications = 0;
    $rejected_applications = 0;
}

// Get irregular students data
$irregular_students = [];
$selected_student = null;
$selected_student_grades = [];
$selected_student_back_subjects = [];
$all_subjects = [];

try {
    // Get all irregular students
    $irregular_students = $pdo->query("
        SELECT u.*, 
               COUNT(DISTINCT sbs.id) as back_subjects_count,
               COUNT(DISTINCT CASE WHEN sbs.status = 'completed' THEN sbs.id END) as completed_back_subjects
        FROM users u
        LEFT JOIN student_back_subjects sbs ON u.id = sbs.student_id
        WHERE u.role = 'student' AND u.educational_status = 'Irregular'
        GROUP BY u.id
        ORDER BY u.last_name, u.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected student details if student_id is provided
    if (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
        $student_id = intval($_GET['student_id']);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' AND educational_status = 'Irregular'");
        $stmt->execute([$student_id]);
        $selected_student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_student) {
            // Get student grades
            $grades_stmt = $pdo->prepare("
                SELECT g.*, 
                       s.name as subject_name, 
                       s.code as subject_code,
                       s.units as subject_units,
                       c.name as classroom_name,
                       u_teacher.first_name as teacher_first,
                       u_teacher.last_name as teacher_last
                FROM grades g
                LEFT JOIN subjects s ON g.subject_id = s.id
                LEFT JOIN classrooms c ON g.classroom_id = c.id
                LEFT JOIN users u_teacher ON g.teacher_id = u_teacher.id
                WHERE g.student_id = ?
                ORDER BY g.graded_at DESC
            ");
            $grades_stmt->execute([$student_id]);
            $selected_student_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get back subjects
            $back_subjects_stmt = $pdo->prepare("
                SELECT sbs.*, 
                       s.name as subject_name,
                       s.code as subject_code,
                       s.units as default_units
                FROM student_back_subjects sbs
                LEFT JOIN subjects s ON sbs.subject_id = s.id
                WHERE sbs.student_id = ?
                ORDER BY sbs.status, sbs.created_at DESC
            ");
            $back_subjects_stmt->execute([$student_id]);
            $selected_student_back_subjects = $back_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Get all subjects for dropdown
    $all_subjects = $pdo->query("SELECT id, name, code, units FROM subjects WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist yet
    $irregular_students = [];
    $all_subjects = [];
}

// Get applications with student details and check requirements/payment status
$applications = [];
try {
    $applications = $pdo->query("
        SELECT aa.*, 
               u.first_name, u.last_name, u.middle_name, u.email, u.phone_number, 
               u.birthday, u.gender, u.address, u.city_province, u.municipality,
               u.mother_name, u.father_name, u.student_id_number,
               admin.first_name as reviewer_first, admin.last_name as reviewer_last,
               (SELECT COUNT(DISTINCT requirement_name) FROM application_requirements WHERE is_required = 1) as total_required,
               (SELECT COUNT(DISTINCT ar.requirement_name) FROM application_requirement_submissions ars 
                JOIN application_requirements ar ON ars.requirement_id = ar.id 
                WHERE ars.application_id = aa.id AND ar.is_required = 1 AND ars.status = 'approved') as requirements_approved,
               (SELECT COUNT(*) FROM application_payments ap 
                WHERE ap.application_id = aa.id AND ap.status = 'verified') as payment_verified
        FROM admission_applications aa
        JOIN users u ON aa.student_id = u.id
        LEFT JOIN users admin ON aa.reviewed_by = admin.id
        ORDER BY aa.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    // Table might not exist - set empty array
    $applications = [];
}

// Get users with search filter
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role_filter']) ? $_GET['role_filter'] : '';

// Check if admission_applications table exists
$admissionTableExists = false;
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'admission_applications'");
    $admissionTableExists = $checkStmt->rowCount() > 0;
} catch (PDOException $e) {
    $admissionTableExists = false;
}

// For students, use admission_applications.created_at if available, otherwise use users.created_at
if ($admissionTableExists) {
    $users_query = "SELECT u.*, 
                    COALESCE(aa.created_at, u.created_at) as display_created_at,
                    u.created_at as account_created_at
                    FROM users u
                    LEFT JOIN (
                        SELECT a1.student_id, a1.created_at
                        FROM admission_applications a1
                        INNER JOIN (
                            SELECT student_id, MAX(created_at) as max_created_at
                            FROM admission_applications
                            GROUP BY student_id
                        ) a2 ON a1.student_id = a2.student_id AND a1.created_at = a2.max_created_at
                    ) aa ON u.id = aa.student_id AND u.role = 'student'
                    WHERE 1=1";
} else {
    $users_query = "SELECT *, created_at as display_created_at, created_at as account_created_at FROM users WHERE 1=1";
}
$params = [];
if (!empty($search_query)) {
    $users_query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $search_param = "%$search_query%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}
if (!empty($role_filter)) {
    $users_query .= " AND u.role = ?";
    $params[] = $role_filter;
    
    if ($role_filter === 'student') {
        $users_query .= " AND " . getEnrolledStudentEligibilityCondition('u');
    }
} elseif (empty($search_query)) {
    // Default dashboard view: hide pending applicants from the student list
    $users_query .= " AND (u.role != 'student' OR " . getEnrolledStudentEligibilityCondition('u') . ")";
}
$users_query .= " ORDER BY u.id DESC";

$stmt = $pdo->prepare($users_query);
$stmt->execute($params);
$users = $stmt->fetchAll();

$recent_users = array_slice($users, 0, 5);

$maxUsersPerRole = 10;
$usersByRoleFull = [
    'admin' => [],
    'teacher' => [],
    'student' => [],
];

$usersByRoleLimited = [
    'admin' => [],
    'teacher' => [],
    'student' => [],
];

$shouldLimitDefaultUsers = empty($search_query) && empty($role_filter);

foreach ($users as $user) {
    $role = $user['role'];
    if (!isset($usersByRoleFull[$role])) {
        continue;
    }

    $usersByRoleFull[$role][] = $user;

    if ($shouldLimitDefaultUsers && count($usersByRoleLimited[$role]) < $maxUsersPerRole) {
        $usersByRoleLimited[$role][] = $user;
    }
}

$usersByRoleDisplay = $shouldLimitDefaultUsers ? $usersByRoleLimited : $usersByRoleFull;

// Get subjects with teacher assignments
$subjects = $pdo->query("
    SELECT s.*, 
           GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as assigned_teachers
    FROM subjects s
    LEFT JOIN teacher_subjects ts ON s.id = ts.subject_id
    LEFT JOIN users u ON ts.teacher_id = u.id
    GROUP BY s.id
    ORDER BY s.id DESC
")->fetchAll();

// Get teachers with their assigned subjects
$teachers = $pdo->query("
    SELECT u.*, 
           GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as assigned_subjects,
           GROUP_CONCAT(DISTINCT ts.subject_id SEPARATOR ',') as assigned_subject_ids
    FROM users u
    LEFT JOIN teacher_subjects ts ON u.id = ts.teacher_id
    LEFT JOIN subjects s ON ts.subject_id = s.id
    WHERE u.role = 'teacher'
    GROUP BY u.id
    ORDER BY u.first_name, u.last_name
")->fetchAll();

// Fetch all courses
$courses = $pdo->query("
    SELECT c.*,
           COUNT(DISTINCT s.id) as total_sections
    FROM courses c
    LEFT JOIN sections s ON c.id = s.course_id
    GROUP BY c.id
    ORDER BY c.code, c.name
")->fetchAll();

// Fetch all sections with course and teacher info
$sections = $pdo->query("
    SELECT sec.*,
           c.code as course_code,
           c.name as course_name,
           CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
           COALESCE((
               SELECT COUNT(DISTINCT cs.student_id)
               FROM classrooms cl
               JOIN classroom_students cs ON cl.id = cs.classroom_id
               WHERE cl.section = sec.section_name 
                 AND cl.program = c.name 
                 AND cl.year_level = sec.year_level
           ), sec.current_students, 0) as enrolled_students
    FROM sections sec
    LEFT JOIN courses c ON sec.course_id = c.id
    LEFT JOIN users u ON sec.teacher_id = u.id
    ORDER BY c.code, sec.year_level, sec.section_name, sec.academic_year
")->fetchAll();

// Fetch all schedules with related information
$schedules = [];
try {
    $schedules = $pdo->query("
        SELECT ss.*,
               sec.section_name,
               sec.year_level as section_year_level,
               sec.academic_year as section_academic_year,
               sec.semester as section_semester,
               c.code as course_code,
               c.name as course_name,
               sub.name as subject_name,
               sub.code as subject_code,
               CONCAT(t.first_name, ' ', t.last_name) as teacher_name,
               cl.name as classroom_name,
               cl.id as classroom_id
        FROM section_schedules ss
        LEFT JOIN sections sec ON ss.section_id = sec.id
        LEFT JOIN courses c ON sec.course_id = c.id
        LEFT JOIN subjects sub ON ss.subject_id = sub.id
        LEFT JOIN users t ON ss.teacher_id = t.id
        LEFT JOIN classrooms cl ON ss.classroom_id = cl.id
        ORDER BY sec.academic_year DESC, sec.semester, c.code, sec.section_name, 
                 FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                 ss.start_time
    ")->fetchAll();
} catch (PDOException $e) {
    // Table might not exist yet, schedules will be empty
    $schedules = [];
}

// Fetch all subjects for dropdown
$all_subjects = $pdo->query("SELECT id, name, code FROM subjects WHERE status = 'active' ORDER BY code, name")->fetchAll();

// Fetch all classrooms for dropdown
$all_classrooms = $pdo->query("SELECT id, name FROM classrooms WHERE status = 'active' ORDER BY name")->fetchAll();

// Fetch enrollment periods
$enrollment_periods = [];
try {
    $enrollment_periods = $pdo->query("
        SELECT ep.*, c.name as course_name, c.code as course_code,
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM enrollment_periods ep
        LEFT JOIN courses c ON ep.course_id = c.id
        LEFT JOIN users u ON ep.created_by = u.id
        ORDER BY ep.start_date DESC, ep.academic_year DESC, ep.semester
    ")->fetchAll();
} catch (PDOException $e) {
    $enrollment_periods = [];
}

// Fetch enrollment requests
$enrollment_requests = [];
try {
    $enrollment_requests = $pdo->query("
        SELECT er.*, 
               CONCAT(s.first_name, ' ', s.last_name) as student_name,
               s.student_id_number, s.email as student_email,
               c.name as course_name, c.code as course_code,
               CONCAT(r.first_name, ' ', r.last_name) as reviewed_by_name,
               ep.start_date as period_start, ep.end_date as period_end
        FROM enrollment_requests er
        LEFT JOIN users s ON er.student_id = s.id
        LEFT JOIN courses c ON er.course_id = c.id
        LEFT JOIN users r ON er.reviewed_by = r.id
        LEFT JOIN enrollment_periods ep ON er.enrollment_period_id = ep.id
        ORDER BY er.requested_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $enrollment_requests = [];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php 
    // Generate CSRF token once for the page
    $pageCsrfToken = generateCSRFToken();
    
    // Calculate base path for assets - use web-relative paths, not file system paths
    // Always use SCRIPT_NAME to get the web path, never use file system paths
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    
    // Extract base path from script path
    if (strpos($scriptPath, '/admin/') !== false) {
        $basePath = substr($scriptPath, 0, strpos($scriptPath, '/admin/'));
    } elseif (strpos($scriptPath, '/backend/') !== false) {
        $basePath = substr($scriptPath, 0, strpos($scriptPath, '/backend/'));
    } else {
        // Fallback: use project folder name from script path
        $pathParts = explode('/', trim($scriptPath, '/'));
        if (!empty($pathParts[0])) {
            $basePath = '/' . $pathParts[0];
        } else {
            $basePath = '/amore-web-refactored_b4 hosting';
        }
    }
    
    // Ensure base path doesn't have trailing slash and is URL-encoded properly
    $basePath = rtrim($basePath, '/');
    $assetsPath = $basePath . '/assets';
    $baseUrl = $basePath; // For use in JavaScript
    ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($pageCsrfToken) ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="<?= $assetsPath ?>/images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            width: 100%;
            overflow-x: hidden;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            position: relative;
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

        /* Confirmation Modal */
        .confirmation-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none; /* Changed from flex to none - only show when .show class is added */
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            z-index: 1100;
            padding: 20px;
        }
        
        .confirmation-modal-overlay.show {
            display: flex; /* Show as flex when .show class is present */
            visibility: visible;
            opacity: 1;
        }

        body.confirmation-modal-open {
            overflow: hidden;
        }

        .confirmation-modal {
            background: #fff;
            border-radius: 14px;
            max-width: 420px;
            width: 100%;
            padding: 32px 28px;
            box-shadow: 0 25px 65px rgba(0, 0, 0, 0.25);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .confirmation-modal::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 14px;
            padding: 2px;
            background: linear-gradient(135deg, #a11c27, #f76b1c);
            -webkit-mask:
                linear-gradient(#fff 0 0) content-box,
                linear-gradient(#fff 0 0);
            -webkit-mask-composite: destination-out;
            mask-composite: exclude;
            opacity: 0.25;
            pointer-events: none;
        }

        .confirmation-modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 18px;
            background: rgba(161, 28, 39, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a11c27;
            font-size: 1.8rem;
        }

        .confirmation-modal h3 {
            margin-bottom: 10px;
            font-size: 1.3rem;
            color: #1f1f1f;
        }

        .confirmation-modal p {
            color: #555;
            margin-bottom: 6px;
            line-height: 1.5;
        }

        .confirmation-modal-target {
            font-weight: 600;
            color: #a11c27;
        }

        .confirmation-modal-actions {
            margin-top: 22px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .confirmation-modal-actions .btn {
            min-width: 130px;
            border-radius: 999px;
            font-weight: 600;
            padding: 10px 18px;
        }
        
        /* Mobile responsive styles for Edit User Modal */
        @media (max-width: 768px) {
            #editUserModal .modal-dialog {
                margin: 10px;
                max-width: calc(100% - 20px);
            }
            
            #editUserModal .modal-content {
                border-radius: 8px;
            }
            
            #editUserModal .modal-header {
                padding: 12px 15px;
            }
            
            #editUserModal .modal-title {
                font-size: 1.1rem;
            }
            
            #editUserModal .modal-body {
                padding: 15px;
            }
            
            #editUserModal .modal-footer {
                padding: 12px 15px;
                flex-direction: column;
            }
            
            #editUserModal .modal-footer .btn {
                width: 100%;
                margin: 4px 0;
            }
            
            #editUserModal .row {
                margin: 0;
            }
            
            #editUserModal .col-md-6 {
                padding: 0 8px;
                margin-bottom: 10px;
            }
            
            #editUserModal #profilePicturePreview {
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 480px) {
            #editUserModal .modal-dialog {
                margin: 5px;
                max-width: calc(100% - 10px);
            }
            
            #editUserModal .modal-body {
                padding: 12px;
            }
            
            #editUserModal .col-md-6 {
                padding: 0 4px;
            }
        }
        
        /* Mobile responsive styles for Edit Teacher Subjects Modal */
        @media (max-width: 768px) {
            #editTeacherSubjectsModal .modal-dialog {
                margin: 10px;
                max-width: calc(100% - 20px);
            }
            
            #editTeacherSubjectsModal .modal-content {
                border-radius: 8px;
            }
            
            #editTeacherSubjectsModal .modal-header {
                padding: 12px 15px;
            }
            
            #editTeacherSubjectsModal .modal-title {
                font-size: 1.1rem;
            }
            
            #editTeacherSubjectsModal .modal-body {
                padding: 15px;
            }
            
            #editTeacherSubjectsModal .subject-checkbox-container {
                max-height: 250px !important;
                padding: 10px !important;
            }
            
            #editTeacherSubjectsModal .subject-checkbox-item {
                padding: 10px !important;
                margin-bottom: 8px !important;
            }
            
            #editTeacherSubjectsModal .form-check-label {
                font-size: 0.9rem;
            }
            
            #editTeacherSubjectsModal .modal-footer {
                padding: 12px 15px;
                flex-direction: column;
            }
            
            #editTeacherSubjectsModal .modal-footer .btn {
                width: 100%;
                margin: 4px 0;
            }
            
            #editTeacherSubjectsModal .btn-sm {
                font-size: 0.75rem !important;
                padding: 6px 12px !important;
            }
        }
        
        @media (max-width: 480px) {
            #editTeacherSubjectsModal .modal-dialog {
                margin: 5px;
                max-width: calc(100% - 10px);
            }
            
            #editTeacherSubjectsModal .subject-checkbox-container {
                max-height: 200px !important;
            }
            
            #editTeacherSubjectsModal .mb-2 {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 10px !important;
            }
            
            #editTeacherSubjectsModal .mb-2 > div {
                width: 100%;
                display: flex;
                gap: 8px;
            }
        }
        
        /* Mobile responsive styles for confirmation modal */
        @media (max-width: 768px) {
            .confirmation-modal-overlay {
                padding: 15px;
            }
            
            .confirmation-modal {
                max-width: 100%;
                padding: 24px 20px;
                border-radius: 12px;
            }
            
            .confirmation-modal-icon {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
                margin-bottom: 16px;
            }
            
            .confirmation-modal h3 {
                font-size: 1.15rem;
                margin-bottom: 8px;
            }
            
            .confirmation-modal p {
                font-size: 0.95rem;
                margin-bottom: 4px;
            }
            
            .confirmation-modal-actions {
                margin-top: 20px;
                gap: 10px;
            }
            
            .confirmation-modal-actions .btn {
                min-width: 100%;
                width: 100%;
                padding: 12px 18px;
            }
        }
        
        @media (max-width: 480px) {
            .confirmation-modal-overlay {
                padding: 10px;
            }
            
            .confirmation-modal {
                padding: 20px 16px;
            }
            
            .confirmation-modal-icon {
                width: 48px;
                height: 48px;
                font-size: 1.3rem;
            }
            
            .confirmation-modal h3 {
                font-size: 1.05rem;
            }
            
            .confirmation-modal p {
                font-size: 0.9rem;
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
            overflow: hidden;
            display: flex;
            flex-direction: column;
            z-index: 100;
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
        
        /* Prevent sidebar flicker on page load - hide until state is restored */
        .sidebar:not([data-state-restored]) {
            opacity: 0 !important;
            visibility: hidden !important;
            transition: none !important;
            pointer-events: none !important;
        }
        
        .sidebar[data-state-restored] {
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        visibility 0.35s;
            pointer-events: auto;
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
            height: 45px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .school-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            text-align: left;
            white-space: nowrap;
            flex: 1;
        }
        
        .sidebar-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding-bottom: 20px;
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
        
        .nav-section {
            margin-bottom: 25px;
            overflow-y: auto;
            flex: 1;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE/Edge */
        }
        
        .nav-section::-webkit-scrollbar {
            width: 0;
            height: 0;
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
        
        .nav-item span {
            flex: 1;
            font-size: 0.95rem;
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
            transition: background 0.2s, color 0.2s, transform 0.1s;
            min-height: 44px; /* Minimum touch target size for mobile */
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-tap-highlight-color: transparent; /* Remove mobile tap highlight */
        }
        
        .upgrade-btn:hover {
            background: #f5f5f5;
        }
        
        .upgrade-btn:active {
            transform: scale(0.98); /* Visual feedback on click/tap */
        }
        
        /* Mobile-specific enhancements for logout button */
        @media (max-width: 768px) {
            .upgrade-btn {
                padding: 12px 18px;
                font-size: 1rem;
                min-height: 48px; /* Larger touch target on mobile */
            }
        }
        
        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .upgrade-btn {
                padding: 12px 18px;
                min-height: 48px;
            }
            
            .upgrade-btn:active {
                background: #e8e8e8;
                transform: scale(0.97);
            }
        }
        
        /* Main Content */
        .main-content {
            margin: 0 auto;
            padding: 30px;
            width: 100%;
            max-width: 1400px;
            min-height: 100vh;
            overflow-x: hidden;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Ensure main content stays centered on desktop */
        @media (min-width: 769px) {
            .main-content {
                margin-left: auto;
                margin-right: auto;
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
            margin: 0;
        }
        
        /* Add padding when toggle button is visible */
        .top-header.has-toggle {
            padding-left: 60px;
        }
        
        .welcome-banner-container {
            margin-bottom: 30px;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #ffe0e0 0%, #ffcccc 100%);
            border-radius: 12px;
            padding: 25px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
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
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            position: relative;
            z-index: 1000;
            cursor: default;
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
            max-width: 100%;
            box-sizing: border-box;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Mobile responsiveness for notifications */
        @media (max-width: 768px) {
            .message {
                padding: 12px;
                margin-bottom: 15px;
                font-size: 14px;
                border-radius: 6px;
            }
        }
        
        @media (max-width: 480px) {
            .message {
                padding: 10px;
                margin-bottom: 12px;
                font-size: 13px;
                line-height: 1.4;
            }
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table thead {
            background: #f8f9fa;
        }
        
        .table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .center-cell {
            text-align: center;
            vertical-align: middle;
        }
        
        .center-cell__inner {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            gap: 4px;
        }
        
        /* Applications table specific styles */
        #applications .table {
            table-layout: auto;
            width: 100%;
        }
        
        #applications .table td {
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        #applications .table-responsive {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }
        
        #applications .table th {
            white-space: nowrap;
            word-wrap: normal;
        }
        
        #applications .table .badge {
            display: inline-block;
            white-space: nowrap;
        }
        
        /* Standardized Admin Action Buttons - Base Style */
        /* All action buttons (Edit, Password, Delete) use this base */
        .admin-action-btn,
        .btn.btn-sm.btn-outline-primary,
        .btn.btn-sm.btn-outline-warning,
        .btn.btn-sm.btn-outline-danger {
            min-height: 38px;
            min-width: 80px;
            padding: 8px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
            touch-action: manipulation;
            -webkit-tap-highlight-color: rgba(161, 28, 39, 0.2);
            user-select: none;
            transition: all 0.2s ease;
            position: relative;
            z-index: 1;
            white-space: nowrap;
            vertical-align: middle;
        }
        
        /* Edit Button (Primary - matches system theme) */
        .btn.btn-sm.btn-outline-primary {
            background: transparent;
            color: #a11c27;
            border-color: #a11c27;
        }
        
        .btn.btn-sm.btn-outline-primary:hover {
            background: #a11c27;
            color: white;
            border-color: #a11c27;
        }
        
        .btn.btn-sm.btn-outline-primary:active {
            background: #b31310;
            border-color: #b31310;
            transform: scale(0.96);
        }
        
        /* Password Button (Warning) */
        .btn.btn-sm.btn-outline-warning {
            background: transparent;
            color: #ff9800;
            border-color: #ff9800;
        }
        
        .btn.btn-sm.btn-outline-warning:hover {
            background: #ff9800;
            color: white;
            border-color: #ff9800;
        }
        
        .btn.btn-sm.btn-outline-warning:active {
            background: #f57c00;
            border-color: #f57c00;
            transform: scale(0.96);
        }
        
        /* Delete Button (Danger - matches system theme) */
        .btn.btn-sm.btn-outline-danger,
        .delete-btn {
            background: transparent;
            color: #a11c27;
            border-color: #a11c27;
        }
        
        .btn.btn-sm.btn-outline-danger:hover,
        .delete-btn:hover {
            background: #a11c27;
            color: white;
            border-color: #a11c27;
        }
        
        .btn.btn-sm.btn-outline-danger:active,
        .delete-btn:active {
            background: #b31310;
            border-color: #b31310;
            transform: scale(0.96);
        }
        
        /* Disabled state for all buttons */
        .admin-action-btn:disabled,
        .delete-btn:disabled,
        .btn.btn-sm.btn-outline-primary:disabled,
        .btn.btn-sm.btn-outline-warning:disabled,
        .btn.btn-sm.btn-outline-danger:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        /* Ensure buttons are visible and accessible */
        .admin-action-btn:not(:disabled):not(.disabled),
        .delete-btn:not(:disabled):not(.disabled) {
            cursor: pointer;
            pointer-events: auto;
        }
        
        .admin-action-btn .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.15em;
        }
        
        /* Button Group Mobile Responsive */
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        
        @media (max-width: 768px) {
            /* Standardized mobile sizing for all admin action buttons */
            .admin-action-btn,
            .btn.btn-sm.btn-outline-primary,
            .btn.btn-sm.btn-outline-warning,
            .btn.btn-sm.btn-outline-danger,
            .delete-btn {
                min-height: 44px;
                min-width: 100px;
                padding: 10px 16px !important;
                font-size: 0.9rem !important;
                flex: 1 1 auto;
            }
            
            .btn-group {
                width: 100%;
                gap: 8px;
            }
            
            .btn-group .admin-action-btn,
            .btn-group .btn.btn-sm.btn-outline-primary,
            .btn-group .btn.btn-sm.btn-outline-warning,
            .btn-group .btn.btn-sm.btn-outline-danger,
            .btn-group .delete-btn {
                flex: 1 1 calc(33.333% - 6px);
                min-width: calc(33.333% - 6px);
            }
            
            .btn-group .admin-action-btn i,
            .btn-group .btn.btn-sm i {
                margin-right: 4px;
            }
        }
        
        @media (max-width: 480px) {
            /* Stack buttons vertically on very small screens */
            .btn-group .admin-action-btn,
            .btn-group .btn.btn-sm.btn-outline-primary,
            .btn-group .btn.btn-sm.btn-outline-warning,
            .btn-group .btn.btn-sm.btn-outline-danger,
            .btn-group .delete-btn {
                flex: 1 1 100%;
                min-width: 100%;
                margin-bottom: 4px;
            }
        }
        
        @media (max-width: 480px) {
            .admin-action-btn {
                min-height: 48px;
                padding: 12px 16px;
                font-size: 0.95rem;
            }
            
            .btn-group .admin-action-btn {
                flex: 1 1 100%;
                min-width: 100%;
                margin-bottom: 4px;
            }
        }
        
        /* Loading State Styles */
        .admin-action-btn.loading {
            position: relative;
            color: transparent !important;
        }
        
        .admin-action-btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Touch-friendly class for better mobile interaction */
        .touch-friendly {
            -webkit-tap-highlight-color: rgba(161, 28, 39, 0.2);
            touch-action: manipulation;
            cursor: pointer;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #a11c27;
            color: white;
        }
        
        .btn-primary:hover {
            background: #b31310;
        }
        
        /* Override outline button styles to match standardized admin buttons */
        .btn-outline-primary:not(.admin-action-btn):not(.delete-btn) {
            background: transparent;
            color: #a11c27;
            border: 1px solid #a11c27;
        }
        
        .btn-outline-primary:not(.admin-action-btn):not(.delete-btn):hover {
            background: #a11c27;
            color: white;
        }
        
        /* Delete buttons use system theme color */
        .btn-outline-danger,
        .delete-btn {
            background: transparent;
            color: #a11c27;
            border: 1px solid #a11c27;
        }
        
        .btn-outline-danger:hover,
        .delete-btn:hover {
            background: #a11c27;
            color: white;
            border-color: #a11c27;
        }
        
        .btn-outline-warning:not(.admin-action-btn) {
            background: transparent;
            color: #ff9800;
            border: 1px solid #ff9800;
        }
        
        .btn-outline-warning:not(.admin-action-btn):hover {
            background: #ff9800;
            color: white;
            border-color: #ff9800;
        }
        
        /* Override .btn-sm for admin action buttons to use standardized sizing */
        .admin-action-btn.btn-sm,
        .btn.btn-sm.btn-outline-primary,
        .btn.btn-sm.btn-outline-warning,
        .btn.btn-sm.btn-outline-danger {
            padding: 8px 16px !important;
            font-size: 0.875rem !important;
        }
        
        /* Keep original .btn-sm for other buttons that need smaller size */
        .btn-sm:not(.admin-action-btn):not(.btn-outline-primary):not(.btn-outline-warning):not(.btn-outline-danger) {
            padding: 5px 10px;
            font-size: 0.875rem;
        }
        
        .form-control, .form-select {
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        /* Modal z-index to appear above sidebar */
        .modal {
            z-index: 1050 !important;
        }
        
        .modal-backdrop {
            z-index: 1040 !important;
        }
        
        /* Prevent body expansion when modal opens */
        body.modal-open {
            overflow: hidden !important;
            padding-right: 0 !important;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            color: white;
        }
        
        .password-toggle {
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .password-toggle:hover {
            color: #a11c27 !important;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge.bg-danger {
            background: #dc3545;
            color: white;
        }
        
        .badge.bg-success {
            background: #28a745;
            color: white;
        }
        
        .badge.bg-primary {
            background: #a11c27;
            color: white;
        }
        
        .badge.bg-secondary {
            background: #6c757d;
            color: white;
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
        
        .mobile-menu-toggle:focus {
            outline: 2px solid rgba(161, 28, 39, 0.5);
            outline-offset: 2px;
        }
        
        /* Ensure button is visible on mobile devices */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex !important;
            }
            
            .mobile-menu-toggle.hide {
                display: none !important;
            }
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
        
        @media (max-width: 360px) {
            .mobile-menu-toggle {
                padding: 6px 8px;
                font-size: 0.85rem;
                min-width: 32px;
                min-height: 32px;
                top: 10px;
                left: 10px;
            }
        }
        
        /* Adjust header padding when toggle button is visible */
        @media (max-width: 768px) {
            .top-header {
                padding-left: 60px;
                padding-right: 20px;
            }
        }
        
        /* Desktop: adjust when toggle is visible (sidebar hidden) */
        @media (min-width: 769px) {
            body:has(.mobile-menu-toggle:not(.hide)) .top-header,
            .main-content.expanded .top-header {
                padding-left: 60px;
            }
        }

        /* Mobile spacing for course selector */
        @media (max-width: 768px) {
            .subject-checkbox-container .mb-2 {
                flex-wrap: wrap;
                gap: 8px;
                padding-bottom: 12px !important;
                margin-bottom: 12px !important;
            }
            .subject-checkbox-list {
                margin-top: 6px;
            }
            .subject-checkbox-item {
                margin-bottom: 8px;
            }
        }
        /* Form labels */
        .form-check-label {
            font-weight: 500;
        }
        @media (max-width: 768px) {
            .form-check-label {
                font-size: 0.95rem;
                line-height: 1.35;
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
            z-index: 1000; /* Below sidebar (1001) but above content */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        visibility 0.35s;
            cursor: pointer;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            touch-action: none; /* Prevent scrolling when overlay is active on mobile */
        }
        
        .sidebar-overlay.active {
            display: block;
            opacity: 1;
            visibility: visible;
        }
        
        /* Tablet and smaller desktop (900px - 1024px) */
        @media (max-width: 1024px) and (min-width: 901px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                padding: 20px;
                max-width: 1400px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Slight font reduction for tablets */
            .table {
                font-size: 0.9rem;
            }
            
            .table th {
                font-size: 0.85rem;
                padding: 10px 8px;
            }
            
            .table td {
                font-size: 0.85rem;
                padding: 10px 8px;
            }
            
            #applications .table {
                font-size: 0.85rem;
            }
            
            #applications .table th {
                font-size: 0.8rem;
                padding: 8px 6px;
            }
            
            #applications .table td {
                font-size: 0.8rem;
                padding: 8px 6px;
            }
            
            .table .badge {
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            .table .btn-sm {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }
        
        /* Tablet portrait (768px - 900px) */
        @media (max-width: 900px) and (min-width: 769px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                margin-left: 250px;
                padding: 18px;
                width: calc(100% - 250px);
                max-width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Moderate font reduction */
            .table {
                font-size: 0.85rem;
            }
            
            .table th {
                font-size: 0.8rem;
                padding: 9px 7px;
            }
            
            .table td {
                font-size: 0.8rem;
                padding: 9px 7px;
            }
            
            #applications .table {
                font-size: 0.8rem;
            }
            
            #applications .table th {
                font-size: 0.75rem;
                padding: 7px 5px;
            }
            
            #applications .table td {
                font-size: 0.75rem;
                padding: 7px 5px;
            }
            
            .table .badge {
                font-size: 0.7rem;
                padding: 3px 7px;
            }
            
            .table .btn-sm {
                padding: 3px 7px;
                font-size: 0.7rem;
            }
        }
        
        /* Activity Logs Responsive Styles */
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            color: #666;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 10px 20px;
            transition: all 0.2s;
            background: none;
            cursor: pointer;
        }
        
        .nav-tabs .nav-link:hover {
            border-bottom-color: #a11c27;
            color: #a11c27;
            background: rgba(161, 28, 39, 0.05);
        }
        
        .nav-tabs .nav-link.active {
            color: #a11c27;
            border-bottom-color: #a11c27;
            font-weight: 600;
            background: rgba(161, 28, 39, 0.1);
        }
        
        /* Unified filter form styling */
        #logs .row.g-3 {
            margin: 0;
        }
        
        #logs .row.g-3 > [class*="col-"] {
            margin-bottom: 15px;
        }
        
        #logs .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        #logs .form-control,
        #logs .form-select {
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
            height: 42px;
            box-sizing: border-box;
        }
        
        #logs .form-control:focus,
        #logs .form-select:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        #logs .btn-primary {
            background: #a11c27;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background 0.2s;
        }
        
        #logs .btn-primary:hover {
            background: #b31310;
            color: white;
        }
        
        #logs .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background 0.2s;
        }
        
        #logs .btn-secondary:hover {
            background: #5a6268;
            color: white;
        }
        
        /* Table styling for logs */
        #logs .table {
            margin-bottom: 0;
        }
        
        #logs .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
            padding: 12px;
            font-size: 0.95rem;
        }
        
        #logs .table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.95rem;
            vertical-align: middle;
        }
        
        #logs .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        #logs .badge {
            padding: 6px 12px;
            font-size: 0.85rem;
            font-weight: 600;
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
                height: 100vh;
                z-index: 1000;
                opacity: 0;
                visibility: hidden;
            }
            
            /* Activity Logs Mobile Styles */
            .nav-tabs {
                flex-wrap: wrap;
                border-bottom: 2px solid #dee2e6;
            }
            
            .nav-tabs .nav-link {
                padding: 8px 12px;
                font-size: 0.9rem;
                flex: 1;
                min-width: 0;
                text-align: center;
            }
            
            .nav-tabs .nav-link i {
                display: none;
            }
            
            /* Filter form mobile */
            #logs .row.g-3 > [class*="col-"] {
                margin-bottom: 15px;
                padding-left: 10px;
                padding-right: 10px;
            }
            
            #logs .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -15px;
                padding: 0 15px;
            }
            
            #logs .table {
                font-size: 0.85rem;
                min-width: 900px;
            }
            
            #logs .table th,
            #logs .table td {
                padding: 8px 6px;
            }
            
            #logs .table th {
                white-space: nowrap;
            }
            
            #logs .table td {
                white-space: normal;
                word-wrap: break-word;
            }
            
            #logs .badge {
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            #logs .btn {
                font-size: 0.9rem;
                padding: 8px 12px;
                width: 100%;
                margin-bottom: 8px;
            }
            
            #logs .btn:last-child {
                margin-bottom: 0;
            }
        }
        
        @media (max-width: 576px) {
            .nav-tabs .nav-link {
                padding: 6px 8px;
                font-size: 0.85rem;
            }
            
            #logs .table {
                font-size: 0.8rem;
                min-width: 800px;
            }
            
            #logs .table th,
            #logs .table td {
                padding: 6px 4px;
            }
            
            #logs .card-body {
                padding: 15px;
            }
            
            #logs .form-label {
                font-size: 0.9rem;
                margin-bottom: 5px;
            }
            
            #logs .form-control,
            #logs .form-select {
                font-size: 0.9rem;
                padding: 8px 10px;
                height: 38px;
            }
            
            #logs .row.g-3 > [class*="col-"] {
                padding-left: 8px;
                padding-right: 8px;
            }
        }
            
            .mobile-menu-toggle {
                z-index: 1001;
            }
            
            .sidebar-overlay {
                z-index: 999;
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
                max-width: 100%;
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
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px 20px;
                padding-left: 70px; /* Space for toggle button */
            }
            
            .page-title {
                font-size: 1.4rem;
            }
            
            .welcome-banner {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            
            .stat-value {
                font-size: 1.6rem;
            }
            
            .card {
                padding: 15px;
            }
            
            .card-title {
                font-size: 1.1rem;
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
                white-space: nowrap;
            }
            
            .table td {
                font-size: 0.75rem;
                padding: 8px 6px;
            }
            
            #applications .table {
                font-size: 0.75rem;
                width: 100%;
            }
            
            #applications .table th {
                font-size: 0.7rem;
                padding: 8px 5px;
                white-space: nowrap;
            }
            
            #applications .table td {
                font-size: 0.7rem;
                padding: 8px 5px;
            }
            
            .table .badge {
                font-size: 0.7rem;
                padding: 3px 6px;
                white-space: nowrap;
            }
            
            .table .btn-sm {
                padding: 3px 6px;
                font-size: 0.7rem;
                min-width: auto;
            }
            
            .table i {
                font-size: 0.75rem;
            }
            
            .btn {
                padding: 6px 12px;
                font-size: 0.85rem;
            }
            
            .btn-sm {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
            
            .form-control {
                font-size: 0.9rem;
                padding: 8px 12px;
            }
            
            .modal-dialog {
                margin: 10px;
                max-width: calc(100% - 20px);
            }
            
            #editSectionModal .modal-dialog {
                max-width: 600px;
            }
            
            .modal-content {
                padding: 15px;
            }
            
            .modal-header h5 {
                font-size: 1.1rem;
            }
        }
        
        /* Grade Approval Page Responsive Styles */
        @media (max-width: 768px) {
            /* Stack filter form columns on mobile */
            #grade_approval .card-body .row > div {
                margin-bottom: 15px;
            }
            
            /* Make filter button full width on mobile */
            #grade_approval .d-flex.align-items-end {
                margin-top: 0;
            }
            
            /* Adjust table for mobile */
            #grade_approval .table-responsive {
                font-size: 0.85rem;
            }
            
            #grade_approval .table th,
            #grade_approval .table td {
                padding: 8px 4px;
                white-space: nowrap;
            }
            
            /* Stack action buttons on mobile */
            #grade_approval .btn-group {
                flex-direction: column;
                width: 100%;
            }
            
            #grade_approval .btn-group .btn {
                width: 100%;
                margin-bottom: 5px;
                border-radius: 4px !important;
            }
            
            #grade_approval .btn-group .btn:last-child {
                margin-bottom: 0;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
                padding-top: 70px;
                width: 100%;
                max-width: 100%;
            }
            
            .top-header {
                padding: 12px 15px;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .welcome-content h2 {
                font-size: 1.2rem;
            }
            
            .welcome-content p {
                font-size: 0.85rem;
            }
            
            .stat-value {
                font-size: 1.4rem;
            }
            
            .stat-label {
                font-size: 0.8rem;
            }
            
            /* Grade Approval Mobile Adjustments */
            #grade_approval .card-header h2 {
                font-size: 1.3rem;
            }
            
            #grade_approval .table {
                font-size: 0.75rem;
            }
            
            #grade_approval .table th,
            #grade_approval .table td {
                padding: 6px 3px;
            }
            
            #grade_approval .btn-sm {
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            .card {
                padding: 12px;
            }
            
            /* Further font reduction for small screens - maintain table integrity */
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
            
            #applications .table {
                font-size: 0.7rem;
            }
            
            #applications .table th {
                font-size: 0.65rem;
                padding: 6px 4px;
            }
            
            #applications .table td {
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
            
            #editSectionModal .modal-dialog {
                max-width: calc(100% - 20px);
            }
            
            .table i {
                font-size: 0.7rem;
            }
            
            .btn {
                padding: 5px 10px;
                font-size: 0.75rem;
            }
            
            .badge {
                font-size: 0.65rem;
                padding: 3px 6px;
            }
        }
        
        /* Search and Filter Styles */
        .search-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
        }

        .search-filter-container > * {
            flex: 1 1 200px;
        }
        
        /* Subject Checkbox Styles */
        .subject-checkbox-item {
            cursor: pointer;
        }
        
        .subject-checkbox-item:hover {
            background: #f0f7ff !important;
            border-color: #4a90e2 !important;
            transform: translateX(2px);
            box-shadow: 0 2px 5px rgba(74, 144, 226, 0.15);
        }
        
        .subject-checkbox-item input[type="checkbox"]:checked + label strong {
            color: #2c5aa0;
        }
        
        .subject-checkbox-item input[type="checkbox"]:checked + label {
            color: #2c5aa0;
        }
        
        .search-box {
            flex: 2 1 260px;
            min-width: 220px;
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
            min-width: 0;
            width: 100%;
            flex: 1 1 200px;
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

        .add-subject-row .form-control,
        .add-subject-row .form-select,
        .add-subject-row .add-subject-btn {
            min-height: 48px;
            border-radius: 8px;
        }

        .add-subject-row .add-subject-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Overlay for mobile menu -->
    <div class="sidebar-overlay" id="sidebarOverlay" role="button" aria-label="Close menu" tabindex="-1"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <script>
        // IMMEDIATE restoration - runs synchronously as sidebar element is parsed
        // This prevents flicker by restoring state before browser paints
        (function() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return;
            
            try {
                const savedState = localStorage.getItem('sidebarState');
                const isMobile = window.innerWidth <= 768;
                
                if (savedState) {
                    const state = JSON.parse(savedState);
                    const wasMobile = state.screenWidth <= 768;
                    
                    // Only restore if screen size category matches
                    if ((isMobile && wasMobile) || (!isMobile && !wasMobile)) {
                        // Apply sidebar classes immediately
                        if (state.isHidden) {
                            sidebar.classList.add('hidden');
                            sidebar.classList.remove('active');
                            // Set body class for desktop
                            if (!isMobile) {
                                document.body.classList.add('sidebar-closed');
                            }
                        } else {
                            sidebar.classList.remove('hidden');
                            if (isMobile && state.isActive) {
                                sidebar.classList.add('active');
                            } else if (!isMobile) {
                                sidebar.classList.remove('active');
                                document.body.classList.remove('sidebar-closed');
                            }
                        }
                        
                        // Update other elements if they exist (may not exist yet)
                        const overlay = document.getElementById('sidebarOverlay');
                        const toggleBtn = document.getElementById('mobileMenuToggle');
                        const mainContent = document.querySelector('.main-content');
                        
                        if (state.isHidden) {
                            if (mainContent) mainContent.classList.add('expanded');
                            if (overlay) overlay.classList.remove('active');
                            if (toggleBtn) {
                                if (isMobile) {
                                    toggleBtn.classList.remove('hide');
                                } else {
                                    toggleBtn.style.display = 'block';
                                }
                            }
                        } else {
                            if (isMobile && state.isActive) {
                                if (overlay) overlay.classList.add('active');
                                if (toggleBtn) toggleBtn.classList.add('hide');
                                if (mainContent) mainContent.classList.remove('expanded');
                            } else if (!isMobile) {
                                if (mainContent) {
                                    if (state.isExpanded) {
                                        mainContent.classList.add('expanded');
                                    } else {
                                        mainContent.classList.remove('expanded');
                                    }
                                }
                                if (toggleBtn) toggleBtn.style.display = 'none';
                            }
                        }
                    } else {
                        // Screen size changed - apply default
                        if (isMobile) {
                            sidebar.classList.add('hidden');
                            sidebar.classList.remove('active');
                        } else {
                            sidebar.classList.remove('hidden');
                            sidebar.classList.remove('active');
                        }
                    }
                } else {
                    // No saved state - apply default based on screen size
                    if (isMobile) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                    } else {
                        sidebar.classList.remove('hidden');
                        sidebar.classList.remove('active');
                    }
                }
            } catch (e) {
                // On error, apply safe default
                const isMobile = window.innerWidth <= 768;
                if (isMobile) {
                    sidebar.classList.add('hidden');
                    document.body.classList.remove('sidebar-closed');
                } else {
                    sidebar.classList.remove('hidden');
                    document.body.classList.remove('sidebar-closed');
                }
            }
            
            // Mark as restored to enable transitions and show sidebar
            sidebar.setAttribute('data-state-restored', 'true');
        })();
        </script>
        <div class="sidebar-content">
            <div class="logo">
                <img src="<?= $assetsPath ?>/images/logo.png" alt="Colegio de Amore logo" />
                <h1 class="school-name">Colegio de Amore</h1>
            </div>
            
            <div class="nav-section">
                <?php 
                $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
                
                // TEMPORARILY DISABLED - Redirect irregular_students tab to users tab
                if ($current_tab === 'irregular_students') {
                    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users");
                    exit();
                }
                ?>
                <a href="?tab=dashboard" class="nav-item <?php echo ($current_tab === 'dashboard') ? 'active' : ''; ?>">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="?tab=applications" class="nav-item <?php echo ($current_tab === 'applications') ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span>Applications <?php if ($pending_applications > 0): ?><span class="badge bg-danger ms-2"><?= $pending_applications ?></span><?php endif; ?></span>
                </a>
                <a href="?tab=users" class="nav-item <?php echo ($current_tab === 'users') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Manage Users</span>
                </a>
                <?php /* TEMPORARILY DISABLED - Irregular Students Feature
                <a href="?tab=irregular_students" class="nav-item <?php echo ($current_tab === 'irregular_students') ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock"></i>
                    <span>Irregular Students</span>
                </a>
                END TEMPORARILY DISABLED */ ?>
                <a href="?tab=teachers" class="nav-item <?php echo ($current_tab === 'teachers') ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Teachers</span>
                </a>
                <a href="?tab=subjects" class="nav-item <?php echo ($current_tab === 'subjects') ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i>
                    <span>Courses</span>
                </a>
                <a href="?tab=courses" class="nav-item <?php echo ($current_tab === 'courses') ? 'active' : ''; ?>">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Programs</span>
                </a>
                <a href="?tab=sections" class="nav-item <?php echo ($current_tab === 'sections') ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Sections</span>
                </a>
                <a href="?tab=schedules" class="nav-item <?php echo ($current_tab === 'schedules') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Schedules</span>
                </a>
                <a href="?tab=enrollment" class="nav-item <?php echo ($current_tab === 'enrollment') ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i>
                    <span>Enrollment</span>
                </a>
                <?php
                // Get pending grades count for badge
                $pendingGradesCount = 0;
                try {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM grades WHERE approval_status = 'submitted' AND grade_type = 'final'");
                    $countStmt->execute();
                    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $pendingGradesCount = $countResult ? (int)$countResult['count'] : 0;
                } catch (PDOException $e) {
                    // Ignore
                }
                ?>
                <a href="?tab=grade_approval" class="nav-item <?php echo ($current_tab === 'grade_approval') ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i>
                    <span>Grade Review / Approval <?php if ($pendingGradesCount > 0): ?><span class="badge bg-danger ms-2"><?= $pendingGradesCount ?></span><?php endif; ?></span>
                </a>
                <?php
                // Get pending teacher requests count for badge
                $pendingRequestsCount = 0;
                try {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM grade_edit_requests WHERE status = 'pending'");
                    $countStmt->execute();
                    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $pendingRequestsCount = $countResult ? (int)$countResult['count'] : 0;
                } catch (PDOException $e) {
                    // Ignore
                }
                ?>
                <a href="?tab=teacher_requests" class="nav-item <?php echo ($current_tab === 'teacher_requests') ? 'active' : ''; ?>">
                    <i class="fas fa-user-edit"></i>
                    <span>Teacher Requests <?php if ($pendingRequestsCount > 0): ?><span class="badge bg-danger ms-2"><?= $pendingRequestsCount ?></span><?php endif; ?></span>
                </a>
                <a href="?tab=settings" class="nav-item <?php echo ($current_tab === 'settings') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <a href="?tab=logs" class="nav-item <?php echo ($current_tab === 'logs') ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <form method="POST" style="margin: 0 15px 20px 15px;" action="logout.php">
                <button type="submit" class="upgrade-btn" style="background: rgba(220, 53, 69, 0.8); color: white; text-align: center; display: block; text-decoration: none; width: 100%; border: none; cursor: pointer;">Logout</button>
            </form>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <?php if ($message && ($_GET['tab'] ?? '') !== 'subjects'): ?>
            <div class="message <?= $message_type === 'success' ? 'success' : 'danger' ?>" id="pageMessage">
                <?= htmlspecialchars($message) ?>
            </div>
            <script>
                (function() {
                    // Clear URL parameters immediately after displaying message to prevent re-showing on refresh
                    // This ensures alerts only appear once after an action, not on subsequent page loads
                    if (window.location.search.includes('msg=') || window.location.search.includes('type=')) {
                        const url = new URL(window.location);
                        url.searchParams.delete('msg');
                        url.searchParams.delete('type');
                        url.searchParams.delete('context');
                        // Use replaceState to avoid adding to history and prevent re-triggering
                        window.history.replaceState({}, '', url);
                    }
                    
                    // Auto-dismiss notification on outside interaction
                    const pageMessage = document.getElementById('pageMessage');
                    if (pageMessage) {
                        let isDismissing = false;
                        let autoDismissTimeout = null;
                        
                        function dismissNotification() {
                            if (isDismissing) return;
                            isDismissing = true;
                            
                            // Clear auto-dismiss timeout if it exists
                            if (autoDismissTimeout) {
                                clearTimeout(autoDismissTimeout);
                                autoDismissTimeout = null;
                            }
                            
                            // Remove event listeners
                            document.removeEventListener('click', handleOutsideClick, true);
                            document.removeEventListener('touchstart', handleOutsideClick, true);
                            
                            // Add fade-out animation
                            pageMessage.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out, margin-bottom 0.3s ease-out';
                            pageMessage.style.opacity = '0';
                            pageMessage.style.transform = 'translateY(-10px)';
                            pageMessage.style.marginBottom = '0';
                            
                            // Remove from DOM after animation
                            setTimeout(function() {
                                if (pageMessage && pageMessage.parentNode) {
                                    pageMessage.parentNode.removeChild(pageMessage);
                                }
                            }, 300);
                        }
                        
                        // Handle clicks/touches outside the notification
                        function handleOutsideClick(e) {
                            // Don't dismiss if clicking inside the notification itself
                            if (pageMessage && pageMessage.contains(e.target)) {
                                return;
                            }
                            
                            // Dismiss on any other interaction
                            dismissNotification();
                        }
                        
                        // Add event listeners for both mouse and touch
                        // Use capture phase to catch events early, and a small delay to prevent immediate dismissal on page load
                        setTimeout(function() {
                            if (pageMessage && !isDismissing) {
                                // Use capture phase (true) to catch events before they bubble
                                document.addEventListener('click', handleOutsideClick, true);
                                document.addEventListener('touchstart', handleOutsideClick, true);
                            }
                        }, 100);
                        
                        // Auto-dismiss after 5 seconds if no interaction
                        autoDismissTimeout = setTimeout(function() {
                            if (!isDismissing) {
                                dismissNotification();
                            }
                        }, 5000);
                    }
                })();
            </script>
        <?php endif; ?>
        
        <div class="tab-content">
            <!-- Dashboard Tab -->
            <div class="tab-pane fade<?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'dashboard') ? ' show active' : ''; ?>" id="dashboard" role="tabpanel">
                
                <!-- Top Header -->
                <div class="top-header">
                    <h1 class="page-title">Admin Dashboard</h1>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $total_applications ?></div>
                            <div class="stat-label" style="font-size: 0.9rem; color: #666; font-weight: 500;">Total Applications</div>
                            <div class="stat-sublabel" style="font-size: 0.75rem; color: #999; margin-top: 5px;">
                                <?= $pending_applications ?> pending
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $total_enrolled_students ?></div>
                            <div class="stat-label">Enrolled Students</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $total_teachers ?></div>
                            <div class="stat-label">Total Teachers</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffe0e0; color: #a11c27;">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $total_subjects ?></div>
                            <div class="stat-label">Courses</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px; margin-top: 30px;">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Recent Users</h2>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th>Username</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                        <td><span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'teacher' ? 'success' : 'primary'); ?>"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'users') ? ' show active' : ''; ?>" id="users" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Search & Filter Users</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-3">
                            <input type="hidden" name="tab" value="users">
                            <div class="row">
                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="search" placeholder="Search by name, email, or username..." value="<?= htmlspecialchars($search_query) ?>">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="role_filter">
                                        <option value="">All Roles</option>
                                        <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                                        <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>>Student</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search"></i>
                                    </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                </div>

                <!-- Bulk Operations -->
                <div class="card" style="margin-top: 30px; background: linear-gradient(135deg, #ffe0e0 0%, #fff5f5 100%); border: 1px solid #a11c27;">
                    <div class="card-body">
                        <form method="POST" id="bulkActionForm">
                            <?= getCSRFTokenField() ?>
                            <input type="hidden" name="redirect_tab" value="users">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <select class="form-select" name="bulk_action" id="bulkActionSelect" required>
                                        <option value="">Select Bulk Action</option>
                                        <option value="activate_users">Activate Selected</option>
                                        <option value="deactivate_users">Deactivate Selected</option>
                                        <option value="delete_users">Delete Selected</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-danger" style="background: #a11c27; border-color: #a11c27;" id="bulkActionBtn" disabled>
                                        <i class="fas fa-tasks"></i> Apply to Selected
                                    </button>
                                    <span id="selectedCount" class="ms-2 text-muted">0 selected</span>
                                </div>
                                <div class="col-md-4 text-end">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAllUsers()">
                                        <i class="fas fa-check-square"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAllUsers()">
                                        <i class="fas fa-square"></i> Deselect All
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="selected_ids" id="selectedIds" value="">
                        </form>
                    </div>
                </div>

                <?php if (empty($role_filter) || $role_filter === 'admin'): ?>
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Administrators</h2>
                    </div>
                    <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th width="40"><input type="checkbox" id="selectAllAdmins" onchange="toggleAdminSelection(this.checked)"></th>
                                                <th>ID</th>
                                                <th>Photo</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Date Added</th>
                                                <th>Role</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($usersByRoleDisplay['admin'] as $user): ?>
                                            <tr>
                                                <td><input type="checkbox" class="user-checkbox" data-user-id="<?php echo $user['id']; ?>" onchange="updateBulkSelection()"></td>
                                                <td><?php echo $user['id']; ?></td>
                                                <td>
                                                    <?php if (!empty($user['profile_picture'])): ?>
                                                        <img src="<?= $assetsPath ?>/<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #a11c27;">
                                                    <?php else: ?>
                                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem;">
                                                            <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                <td><?php echo $user['username']; ?></td>
                                                <td><?php echo $user['email']; ?></td>
                                                <td>
                                                    <?php 
                                                    $displayDate = !empty($user['display_created_at']) ? $user['display_created_at'] : ($user['created_at'] ?? null);
                                                    if ($displayDate): ?>
                                                        <div><?php echo date('M d, Y', strtotime($displayDate)); ?></div>
                                                        <small class="text-muted"><?php echo date('h:i A', strtotime($displayDate)); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($user['role']) {
                                                            case 'admin': echo 'danger'; break;
                                                            case 'teacher': echo 'success'; break;
                                                            case 'student': echo 'primary'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>"><?php echo $user['role']; ?></span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary admin-action-btn touch-friendly"
                                                            data-modal-target="#editUserModal"
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-firstname="<?php echo $user['first_name']; ?>"
                                                            data-lastname="<?php echo $user['last_name']; ?>"
                                                            data-email="<?php echo $user['email']; ?>"
                                                            data-role="<?php echo $user['role']; ?>"
                                                            data-profile="<?php echo htmlspecialchars($user['profile_picture'] ?? ''); ?>"
                                                            data-confirm-action="update"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning admin-action-btn touch-friendly"
                                                            data-modal-target="#changePasswordModal"
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-confirm-action="password"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-key"></i> Password
                                                    </button>
                                                    <a href="?action=delete_user&id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                       data-confirm-action="delete"
                                                       data-id="<?php echo $user['id']; ?>"
                                                       data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       data-item-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       title="Delete <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($role_filter) || $role_filter === 'teacher'): ?>
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Staffs</h2>
                    </div>
                    <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th width="40"><input type="checkbox" id="selectAllStaffs" onchange="toggleStaffSelection(this.checked)"></th>
                                                <th>ID</th>
                                                <th>Photo</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Date Added</th>
                                                <th>Role</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($usersByRoleDisplay['teacher'] as $user): ?>
                                            <tr>
                                                <td><input type="checkbox" class="user-checkbox" data-user-id="<?php echo $user['id']; ?>" onchange="updateBulkSelection()"></td>
                                                <td><?php echo $user['id']; ?></td>
                                                <td>
                                                    <?php if (!empty($user['profile_picture'])): ?>
                                                        <img src="<?= $assetsPath ?>/<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #a11c27;">
                                                    <?php else: ?>
                                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem;">
                                                            <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                <td><?php echo $user['username']; ?></td>
                                                <td><?php echo $user['email']; ?></td>
                                                <td>
                                                    <?php 
                                                    $displayDate = !empty($user['display_created_at']) ? $user['display_created_at'] : ($user['created_at'] ?? null);
                                                    if ($displayDate): ?>
                                                        <div><?php echo date('M d, Y', strtotime($displayDate)); ?></div>
                                                        <small class="text-muted"><?php echo date('h:i A', strtotime($displayDate)); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($user['role']) {
                                                            case 'admin': echo 'danger'; break;
                                                            case 'teacher': echo 'success'; break;
                                                            case 'student': echo 'primary'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>"><?php echo $user['role']; ?></span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            data-modal-target="#editUserModal" 
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-firstname="<?php echo $user['first_name']; ?>"
                                                            data-lastname="<?php echo $user['last_name']; ?>"
                                                            data-email="<?php echo $user['email']; ?>"
                                                            data-role="<?php echo $user['role']; ?>"
                                                            data-profile="<?php echo htmlspecialchars($user['profile_picture'] ?? ''); ?>"
                                                            data-confirm-action="update"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning"
                                                            data-modal-target="#changePasswordModal"
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-confirm-action="password"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-key"></i> Password
                                                    </button>
                                                    <a href="?action=delete_user&id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                       data-confirm-action="delete"
                                                       data-id="<?php echo $user['id']; ?>"
                                                       data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       data-item-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       title="Delete <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($role_filter) || $role_filter === 'student'): ?>
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Students</h2>
                    </div>
                    <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th width="40"><input type="checkbox" id="selectAllStudents" onchange="toggleStudentSelection(this.checked)"></th>
                                                <th>ID</th>
                                                <th>Photo</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Date Added</th>
                                                <th>Role</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($usersByRoleDisplay['student'] as $user): ?>
                                            <tr>
                                                <td><input type="checkbox" class="user-checkbox" data-user-id="<?php echo $user['id']; ?>" onchange="updateBulkSelection()"></td>
                                                <td><?php echo $user['id']; ?></td>
                                                <td>
                                                    <?php if (!empty($user['profile_picture'])): ?>
                                                        <img src="<?= $assetsPath ?>/<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #a11c27;">
                                                    <?php else: ?>
                                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem;">
                                                            <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                <td><?php echo $user['username']; ?></td>
                                                <td><?php echo $user['email']; ?></td>
                                                <td>
                                                    <?php 
                                                    $displayDate = !empty($user['display_created_at']) ? $user['display_created_at'] : ($user['created_at'] ?? null);
                                                    if ($displayDate): ?>
                                                        <div><?php echo date('M d, Y', strtotime($displayDate)); ?></div>
                                                        <small class="text-muted"><?php echo date('h:i A', strtotime($displayDate)); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($user['role']) {
                                                            case 'admin': echo 'danger'; break;
                                                            case 'teacher': echo 'success'; break;
                                                            case 'student': echo 'primary'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>"><?php echo $user['role']; ?></span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            data-modal-target="#editUserModal" 
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-firstname="<?php echo $user['first_name']; ?>"
                                                            data-lastname="<?php echo $user['last_name']; ?>"
                                                            data-email="<?php echo $user['email']; ?>"
                                                            data-role="<?php echo $user['role']; ?>"
                                                            data-profile="<?php echo htmlspecialchars($user['profile_picture'] ?? ''); ?>"
                                                            data-confirm-action="update"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning"
                                                            data-modal-target="#changePasswordModal"
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo $user['username']; ?>"
                                                            data-confirm-action="password"
                                                            data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-key"></i> Password
                                                    </button>
                                                    <a href="?action=delete_user&id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                       data-confirm-action="delete"
                                                       data-id="<?php echo $user['id']; ?>"
                                                       data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       data-item-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                       title="Delete <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                <?php endif; ?>

                <!-- Add New User Section -->
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Add New User</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="addUserForm">
                            <?= getCSRFTokenField() ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="text" class="form-control mb-3" name="first_name" placeholder="First Name" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control mb-3" name="last_name" placeholder="Last Name" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="text" class="form-control mb-3" name="username" placeholder="Username" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="email" class="form-control mb-3" name="email" placeholder="Email">
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select mb-3" name="role" id="userRole" required onchange="toggleStudentFields()">
                                        <option value="">Select Role</option>
                                        <option value="admin">Admin</option>
                                        <option value="teacher">Teacher</option>
                                        <option value="student">Student</option>
                                    </select>
                                    <small class="text-muted">When adding a student, a Student ID will be automatically generated and all required student data will be initialized.</small>
                                </div>
                            </div>
                            <!-- Student-specific fields (shown only when role is student) -->
                            <div class="row" id="studentFields" style="display: none;">
                                <div class="col-md-4">
                                    <select class="form-select mb-3" name="program" id="studentProgram">
                                        <option value="">Program (Optional)</option>
                                        <?php
                                        try {
                                            $programs = $pdo->query("SELECT DISTINCT name FROM courses WHERE status = 'active' ORDER BY name")->fetchAll();
                                            foreach ($programs as $program): ?>
                                                <option value="<?= htmlspecialchars($program['name']) ?>"><?= htmlspecialchars($program['name']) ?></option>
                                            <?php endforeach;
                                        } catch (Exception $e) {
                                            // If courses table doesn't exist or error, show common programs
                                            $commonPrograms = ['BS Computer Science', 'BS Information Technology', 'BS Business Administration', 'BS Criminology', 'BS Hospitality Management'];
                                            foreach ($commonPrograms as $prog): ?>
                                                <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                                            <?php endforeach;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select mb-3" name="year_level" id="studentYearLevel">
                                        <option value="">Year Level (Optional)</option>
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" class="form-control mb-3" name="section" id="studentSection" placeholder="Section (Optional)">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="input-group mb-3">
                                        <input type="password" class="form-control" name="password" id="new_password" placeholder="Password" required>
                                        <span class="input-group-text password-toggle" onclick="togglePassword('new_password', 'new_password_icon')">
                                            <i class="fas fa-eye" id="new_password_icon"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" name="add_user" class="btn btn-primary w-100">Add User</button>
                                </div>
                            </div>
                        </form>
                            </div>
                        </div>
                
                <!-- Export/Import Section -->
                <div class="card" style="margin-top: 30px; background: linear-gradient(135deg, #ffe0e0 0%, #fff5f5 100%); border: 1px solid #a11c27;">
                    <div class="card-header" style="background: #a11c27; color: white;">
                        <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-file-export"></i> Export & Import</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 style="color: #a11c27; margin-bottom: 15px;"><i class="fas fa-download"></i> Export Data</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="?action=export&type=students&format=csv" class="btn btn-outline-danger">
                                        <i class="fas fa-file-csv"></i> Export Students (CSV)
                                    </a>
                                    <a href="?action=export&type=students&format=excel" class="btn btn-outline-danger">
                                        <i class="fas fa-file-excel"></i> Export Students (Excel)
                                    </a>
                                    <a href="?action=export&type=grades&format=csv" class="btn btn-outline-danger">
                                        <i class="fas fa-file-csv"></i> Export Grades (CSV)
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 style="color: #a11c27; margin-bottom: 15px;"><i class="fas fa-upload"></i> Import Data</h5>
                                <form method="POST" enctype="multipart/form-data">
                                    <?= getCSRFTokenField() ?>
                                    <div class="input-group">
                                        <input type="file" class="form-control" name="import_file" accept=".csv,.xls,.xlsx" required>
                                        <button type="submit" name="import_students" class="btn btn-danger" style="background: #a11c27; border-color: #a11c27;">
                                            <i class="fas fa-upload"></i> Import Students
                                        </button>
                                    </div>
                                    <small class="text-muted">Upload CSV file with columns: first_name, last_name, email, program, year_level, section</small>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                        <br>
                    </div>

            <!-- Applications Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'applications') ? ' show active' : ''; ?>" id="applications" role="tabpanel">
                <div class="card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ffe0e0; padding-bottom: 15px; margin-bottom: 0;">
                        <h2 class="card-title" style="margin-bottom: 0;">Student Admission Applications</h2>
                        <div style="display: flex; gap: 10px;">
                            <span class="badge bg-warning"><?= $pending_applications ?> Pending</span>
                            <span class="badge bg-success"><?= $approved_applications ?> Approved</span>
                            <span class="badge bg-danger"><?= $rejected_applications ?> Rejected</span>
                        </div>
                    </div>
                    <div class="card-body" style="padding-top: 20px;">
                        <?php if (!empty($applications)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="applicationSearch" placeholder="Search by name, app number, email..." onkeyup="filterApplications()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="applicationStatusFilter" onchange="filterApplications()">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                                <select class="filter-select" id="applicationProgramFilter" onchange="filterApplications()">
                                    <option value="">All Programs</option>
                                    <?php 
                                    $programs = [];
                                    foreach ($applications as $app) {
                                        if (!empty($app['program_applied'])) {
                                            $programs[$app['program_applied']] = $app['program_applied'];
                                        }
                                    }
                                    foreach ($programs as $program): ?>
                                        <option value="<?= htmlspecialchars($program) ?>"><?= htmlspecialchars($program) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="date" class="filter-select" id="applicationDateFilter" placeholder="Filter by Date" onchange="filterApplications()" style="min-width: 150px;">
                            </div>
                        <?php endif; ?>
                        <style>
                            @media (max-width: 576px) {
                                #applicationsTableWrapper table {
                                    min-width: 1100px !important;
                                    table-layout: auto !important;
                                }
                                #applicationsTableWrapper th,
                                #applicationsTableWrapper td {
                                    white-space: normal;
                                }
                                /* Give program/contact more breathing room on mobile */
                                #applicationsTableWrapper th:nth-child(3),
                                #applicationsTableWrapper td:nth-child(3),
                                #applicationsTableWrapper th:nth-child(4),
                                #applicationsTableWrapper td:nth-child(4) {
                                    min-width: 200px;
                                }
                            }
                        </style>
                        <div class="table-responsive" id="applicationsTableWrapper" style="overflow-x: auto;">
                            <table class="table table-striped" style="margin-bottom: 0; width: 100%; min-width: 960px; table-layout: fixed;">
                                <thead>
                                    <tr>
                                        <th style="width: 10%; vertical-align: middle; font-size: 0.9rem;">App #</th>
                                        <th style="width: 18%; vertical-align: middle; font-size: 0.9rem;">Student Name</th>
                                        <th style="width: 12%; vertical-align: middle; font-size: 0.9rem;">Program</th>
                                        <th style="width: 16%; vertical-align: middle; font-size: 0.9rem;">Contact</th>
                                        <th style="width: 11%; text-align: center; vertical-align: middle; font-size: 0.9rem; white-space: nowrap; padding: 10px 16px;">Status</th>
                                        <th style="width: 14%; text-align: center; vertical-align: middle; font-size: 0.9rem; white-space: nowrap; padding: 10px 24px 10px 16px; border-right: 1px solid rgba(0,0,0,0.05);">Requirements</th>
                                        <th style="width: 12%; text-align: center; vertical-align: middle; font-size: 0.9rem; white-space: nowrap; padding: 10px 16px 10px 24px;">Payment</th>
                                        <th style="width: 8%; vertical-align: middle; font-size: 0.9rem;">Date</th>
                                        <th style="width: 12%; text-align: center; vertical-align: middle; font-size: 0.9rem; white-space: nowrap;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="applicationsTableBody">
                                    <?php foreach($applications as $app): 
                                        $total_required = $app['total_required'] ?? 0;
                                        $requirements_approved = $app['requirements_approved'] ?? 0;
                                        $payment_verified = $app['payment_verified'] ?? 0;
                                        $can_approve = ($total_required == 0 || $requirements_approved >= $total_required) && $payment_verified > 0;
                                    ?>
                                    <tr class="application-row" 
                                        data-app-number="<?= strtolower(htmlspecialchars($app['application_number'])) ?>"
                                        data-student-name="<?= strtolower(htmlspecialchars($app['first_name'] . ' ' . $app['last_name'])) ?>"
                                        data-email="<?= strtolower(htmlspecialchars($app['email'])) ?>"
                                        data-program="<?= strtolower(htmlspecialchars($app['program_applied'])) ?>"
                                        data-status="<?= strtolower(htmlspecialchars($app['status'])) ?>"
                                        data-application-date="<?= date('Y-m-d', strtotime($app['application_date'])) ?>">
                                        <td style="vertical-align: top; padding: 10px; font-size: 0.85rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= htmlspecialchars($app['application_number']) ?></td>
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="line-height: 1.3; font-size: 0.85rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;">
                                                <strong style="display: block; margin-bottom: 3px; word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= htmlspecialchars(strtoupper($app['first_name'] . ' ' . ($app['middle_name'] ? $app['middle_name'] . ' ' : '') . $app['last_name'])) ?></strong>
                                                <?php if ($app['student_id_number']): ?>
                                                    <small class="text-muted" style="font-size: 0.75rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;">ID: <?= htmlspecialchars($app['student_id_number']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="vertical-align: top; padding: 10px; font-size: 0.85rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= htmlspecialchars($app['program_applied']) ?></td>
                                        <td style="vertical-align: top; padding: 10px;">
                                            <div style="line-height: 1.3; font-size: 0.8rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;">
                                                <div style="word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= htmlspecialchars($app['email']) ?></div>
                                                <div class="text-muted" style="font-size: 0.75rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= htmlspecialchars($app['phone_number'] ?? 'N/A') ?></div>
                                            </div>
                                        </td>
                                        <td class="center-cell" style="padding: 10px 24px 10px 16px; border-right: 1px solid rgba(0,0,0,0.05);">
                                            <div class="center-cell__inner">
                                                <?php
                                                $status_class = '';
                                                switch($app['status']) {
                                                    case 'pending': $status_class = 'warning'; break;
                                                    case 'approved': $status_class = 'success'; break;
                                                    case 'rejected': $status_class = 'danger'; break;
                                                    default: $status_class = 'secondary';
                                                }
                                                ?>
                                                <span class="badge bg-<?= $status_class ?>" style="font-size: 0.75rem; white-space: nowrap;"><?= ucfirst($app['status']) ?></span>
                                            </div>
                                        </td>
                                        <td class="center-cell" style="padding: 10px 24px 10px 16px; border-right: 1px solid rgba(0,0,0,0.05);">
                                            <div class="center-cell__inner">
                                                <?php if ($total_required > 0): ?>
                                                    <span class="badge bg-<?= $requirements_approved >= $total_required ? 'success' : 'warning' ?>" style="font-size: 0.75rem; white-space: nowrap;">
                                                        <?= $requirements_approved ?>/<?= $total_required ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary" style="font-size: 0.75rem;">N/A</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="center-cell" style="padding: 10px 16px 10px 24px;">
                                            <div class="center-cell__inner">
                                                <?php if ($payment_verified > 0): ?>
                                                    <span class="badge bg-success" style="font-size: 0.75rem; white-space: nowrap;"><i class="fas fa-check"></i></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger" style="font-size: 0.75rem; white-space: nowrap;"><i class="fas fa-times"></i></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="vertical-align: top; padding: 10px; font-size: 0.85rem; word-break: normal; overflow-wrap: anywhere; white-space: normal;"><?= date('M d, Y', strtotime($app['application_date'])) ?></td>
                                        <td style="text-align: center; vertical-align: middle; padding: 10px 16px;">
                                            <div style="display: flex; flex-direction: row; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap;">
                                                <?php if ($app['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-success approve-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#reviewApplicationModal"
                                                            data-id="<?= $app['id'] ?>"
                                                            data-name="<?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>"
                                                            data-action="approve"
                                                            data-can-approve="<?= $can_approve ? '1' : '0' ?>"
                                                            <?= !$can_approve ? 'title="Requirements and payment must be complete before approval"' : '' ?>
                                                            style="width: 70px; padding: 4px 8px; font-size: 0.75rem; <?= !$can_approve ? 'opacity: 0.5; cursor: not-allowed;' : '' ?>">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" data-bs-target="#reviewApplicationModal"
                                                            data-id="<?= $app['id'] ?>"
                                                            data-name="<?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>"
                                                            data-action="reject"
                                                            style="width: 70px; padding: 4px 8px; font-size: 0.75rem;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-info" 
                                                        data-bs-toggle="modal" data-bs-target="#viewApplicationModal"
                                                        data-app='<?= htmlspecialchars(json_encode($app)) ?>'
                                                        style="width: 70px; padding: 4px 8px; font-size: 0.75rem;">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noApplicationResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <p>No applications found matching your search</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teachers Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'teachers') ? ' show active' : ''; ?>" id="teachers" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Add New Teacher</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="text" class="form-control mb-3" name="first_name" placeholder="First Name" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control mb-3" name="last_name" placeholder="Last Name" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="email" class="form-control mb-3" name="email" placeholder="Email" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control mb-3" name="department" placeholder="Department">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Assign Courses (Optional)</label>
                                <div class="subject-checkbox-container" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                                    <?php
                                    $all_subjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
                                    if (empty($all_subjects)):
                                    ?>
                                        <p class="text-muted mb-0">No courses available</p>
                                    <?php else: ?>
                                        <div class="mb-2" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid #ddd; margin-bottom: 10px;">
                                            <span style="font-weight: 600; color: #333;">Select Courses</span>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllSubjects('teacher_subjects')" style="font-size: 0.8rem; padding: 2px 8px;">Select All</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllSubjects('teacher_subjects')" style="font-size: 0.8rem; padding: 2px 8px; margin-left: 5px;">Deselect All</button>
                                            </div>
                                        </div>
                                        <div class="subject-checkbox-list" id="teacher_subjects">
                                            <?php foreach($all_subjects as $subj): ?>
                                                <div class="form-check subject-checkbox-item" style="padding: 8px; margin-bottom: 5px; background: white; border-radius: 5px; border: 1px solid #e0e0e0; transition: all 0.2s;">
                                                    <input class="form-check-input" type="checkbox" name="subject_ids[]" value="<?= $subj['id'] ?>" id="subject_<?= $subj['id'] ?>" style="cursor: pointer;">
                                                    <label class="form-check-label" for="subject_<?= $subj['id'] ?>" style="cursor: pointer; width: 100%; margin-left: 8px;">
                                                        <strong><?= htmlspecialchars($subj['name']) ?></strong>
                                                        <span class="text-muted" style="font-size: 0.9rem;">(<?= htmlspecialchars($subj['code']) ?>)</span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <small class="form-text text-muted">Check the boxes to assign subjects to this teacher</small>
                            </div>
                            <button type="submit" name="add_teacher" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Teacher Account
                            </button>
                        </form>
                        <?php if (isset($_SESSION['new_teacher_credentials'])): ?>
                            <div class="alert alert-success mt-3">
                                <strong>Credentials Generated:</strong><br>
                                Username: <code><?= htmlspecialchars($_SESSION['new_teacher_credentials']['username']) ?></code><br>
                                Password: <code><?= htmlspecialchars($_SESSION['new_teacher_credentials']['password']) ?></code><br>
                                <small>Please save these credentials securely. They will not be shown again.</small>
                            </div>
                            <?php unset($_SESSION['new_teacher_credentials']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Teachers</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($teachers)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="teacherSearch" placeholder="Search by name, email, or department..." onkeyup="filterTeachers()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="teacherDepartmentFilter" onchange="filterTeachers()">
                                    <option value="">All Departments</option>
                                    <?php 
                                    $departments = [];
                                    foreach ($teachers as $teacher) {
                                        if (!empty($teacher['department'])) {
                                            $departments[$teacher['department']] = $teacher['department'];
                                        }
                                    }
                                    foreach ($departments as $dept): ?>
                                        <option value="<?= strtolower(htmlspecialchars($dept)) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;"><i class="fas fa-grip-vertical text-muted"></i></th>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Assigned Courses</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="teachersTableBody">
                                    <?php foreach($teachers as $teacher): ?>
                                    <tr class="teacher-row" 
                                        data-teacher-name="<?= strtolower(htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name'])) ?>"
                                        data-teacher-email="<?= strtolower(htmlspecialchars($teacher['email'] ?? '')) ?>"
                                        data-department="<?= strtolower(htmlspecialchars($teacher['department'] ?? '')) ?>">
                                        <td style="cursor: move;">
                                            <i class="fas fa-grip-vertical text-muted" style="cursor: move;" title="Drag to reorder"></i>
                                        </td>
                                        <td><?= $teacher['id'] ?></td>
                                        <td><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></td>
                                        <td><?= htmlspecialchars($teacher['username']) ?></td>
                                        <td><?= htmlspecialchars($teacher['email']) ?></td>
                                        <td><?= htmlspecialchars($teacher['department'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php if ($teacher['assigned_subjects']): ?>
                                                <small><?= htmlspecialchars($teacher['assigned_subjects']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group" style="flex-wrap: wrap; gap: 4px;">
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal" 
                                                        data-id="<?= $teacher['id'] ?>"
                                                        data-username="<?= htmlspecialchars($teacher['username']) ?>"
                                                        data-firstname="<?= htmlspecialchars($teacher['first_name']) ?>"
                                                        data-lastname="<?= htmlspecialchars($teacher['last_name']) ?>"
                                                        data-email="<?= htmlspecialchars($teacher['email'] ?? '') ?>"
                                                        data-role="<?= htmlspecialchars($teacher['role']) ?>"
                                                        data-profile="<?= htmlspecialchars($teacher['profile_picture'] ?? '') ?>"
                                                        title="Edit Username & Email">
                                                    <i class="fas fa-user-edit"></i> <span class="d-none d-md-inline">Edit</span>
                                                </button>
                                                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#editTeacherSubjectsModal" 
                                                        data-teacher-id="<?= $teacher['id'] ?>"
                                                        data-teacher-name="<?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>"
                                                        data-assigned-subject-ids="<?= htmlspecialchars($teacher['assigned_subject_ids'] ?? '') ?>"
                                                        title="Edit Courses">
                                                    <i class="fas fa-book"></i> <span class="d-none d-md-inline">Courses</span>
                                                </button>
                                                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewTeacherSectionsModal" 
                                                        data-teacher-id="<?= $teacher['id'] ?>"
                                                        data-teacher-name="<?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>"
                                                        title="View Sections">
                                                    <i class="fas fa-list"></i> <span class="d-none d-md-inline">Sections</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noTeacherResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <p>No teachers found matching your search</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Courses Tab (formerly Subjects) -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'subjects') ? ' show active' : ''; ?>" id="subjects" role="tabpanel">
                <?php 
                    // Use a single alert above the form for all course actions on this tab
                    $inlineCourseMsg = '';
                    $inlineCourseType = '';
                    if (($_GET['tab'] ?? '') === 'subjects' && !empty($message)) {
                        $inlineCourseMsg = $message;
                        $inlineCourseType = $message_type ?: 'info';
                        // Clear the global message so it doesn't render elsewhere
                        $message = '';
                        $message_type = '';
                    }
                    $courseAlertClass = 'mb-3';
                    if ($inlineCourseMsg) {
                        $courseAlertClass .= ' alert alert-' . htmlspecialchars($inlineCourseType ?: 'info');
                    }
                ?>
                <div id="courseAlert" class="<?php echo $courseAlertClass; ?>"
                     style="<?php echo $inlineCourseMsg ? '' : 'display:none;'; ?>">
                    <?php if ($inlineCourseMsg): ?>
                        <?php echo htmlspecialchars($inlineCourseMsg); ?>
                        <script>
                            // Clear URL parameters after displaying course message to prevent re-showing on refresh
                            (function() {
                                if (window.location.search.includes('msg=') || window.location.search.includes('type=')) {
                                    const url = new URL(window.location);
                                    url.searchParams.delete('msg');
                                    url.searchParams.delete('type');
                                    url.searchParams.delete('context');
                                    window.history.replaceState({}, '', url);
                                }
                            })();
                        </script>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Add New Course</h2>
                    </div>
                    <div class="card-body">
                                <form method="POST" id="addSubjectForm" onsubmit="return window.confirmAddSubject ? confirmAddSubject(event) : true;">
                                    <?= getCSRFTokenField() ?>
                                    <input type="hidden" name="add_subject" value="1">
                                    <div class="row g-3 align-items-end add-subject-row">
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">Course Name</label>
                                            <input type="text" class="form-control" name="name" placeholder="Course Name" required>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label">Course Code</label>
                                            <input type="text" class="form-control" name="code" placeholder="Course Code" required>
                                        </div>
                                        <div class="col-6 col-md-1">
                                            <label class="form-label">Units</label>
                                            <input type="number" step="0.5" min="0.5" max="6" class="form-control" name="units" placeholder="Units" value="3.0" required>
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">Program</label>
                                            <select class="form-select" name="program">
                                                <option value="">All Programs</option>
                                                <?php 
                                                $programs = $pdo->query("SELECT DISTINCT name FROM courses WHERE status = 'active' ORDER BY name")->fetchAll();
                                                foreach($programs as $prog): ?>
                                                    <option value="<?= htmlspecialchars($prog['name']) ?>"><?= htmlspecialchars($prog['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">Year Level</label>
                                            <select class="form-select" name="year_level">
                                                <option value="">All Levels</option>
                                                <option value="1st Year">1st Year</option>
                                                <option value="2nd Year">2nd Year</option>
                                                <option value="3rd Year">3rd Year</option>
                                                <option value="4th Year">4th Year</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-2 d-flex">
                                            <button type="submit" name="add_subject" class="btn btn-primary w-100 add-subject-btn" style="background: #a11c27; border-color: #a11c27;">Add</button>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control mb-3" name="description" placeholder="Course Description" rows="2"></textarea>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Prerequisites (Course IDs, comma-separated)</label>
                                            <input type="text" class="form-control mb-3" name="prerequisites" placeholder="e.g., 1,2,3">
                                            <small class="text-muted">Enter course IDs separated by commas</small>
                                        </div>
                                        <!-- enforce_prerequisites column removed - not in database schema -->
                                        <!-- <div class="col-md-3">
                                            <label class="form-label">Enforce Prerequisites</label>
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="enforce_prerequisites" value="1" id="enforcePrereq" checked>
                                                <label class="form-check-label" for="enforcePrereq">Require prerequisites to be completed</label>
                                            </div>
                                        </div> -->
                                    </div>
                                </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Courses</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($subjects)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="subjectSearch" placeholder="Search courses by name or code..." onkeyup="filterSubjects()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="subjectProgramFilter" onchange="filterSubjects()">
                                    <option value="">All Programs</option>
                                    <?php 
                                    // Get programs only from courses table to avoid duplicates
                                    $allCourses = $pdo->query("SELECT DISTINCT name FROM courses WHERE status = 'active' ORDER BY name")->fetchAll();
                                    foreach ($allCourses as $course) {
                                        if (!empty($course['name'])) {
                                            echo '<option value="' . htmlspecialchars($course['name']) . '">' . htmlspecialchars($course['name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <select class="filter-select" id="subjectYearLevelFilter" onchange="filterSubjects()">
                                    <option value="">All Year Levels</option>
                                    <?php 
                                    // Define year levels in correct order
                                    $orderedYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
                                    $existingYearLevels = [];
                                    foreach ($subjects as $subject) {
                                        if (!empty($subject['year_level'])) {
                                            $existingYearLevels[$subject['year_level']] = true;
                                        }
                                    }
                                    // Show year levels in correct order, only if they exist in subjects
                                    foreach ($orderedYearLevels as $yearLevel): 
                                        if (isset($existingYearLevels[$yearLevel])): ?>
                                        <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                                        <?php endif; 
                                    endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Units</th>
                                                <th>Assigned Teachers</th>
                                                <th>Description</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="subjectsTableBody">
                                            <?php foreach($subjects as $subject): ?>
                                            <tr class="subject-row" 
                                                data-subject-name="<?= strtolower(htmlspecialchars($subject['name'])) ?>"
                                                data-subject-code="<?= strtolower(htmlspecialchars($subject['code'])) ?>"
                                                data-program="<?= strtolower(htmlspecialchars($subject['program'] ?? '')) ?>"
                                                data-year-level="<?= strtolower(htmlspecialchars($subject['year_level'] ?? '')) ?>">
                                                <td><?php echo htmlspecialchars($subject['name']); ?></td>
                                                <td><?php echo htmlspecialchars($subject['code']); ?></td>
                                                <td><?php echo htmlspecialchars($subject['units'] ?? '3.0'); ?></td>
                                                <td>
                                                    <?php if ($subject['assigned_teachers']): ?>
                                                        <small><?= htmlspecialchars($subject['assigned_teachers']) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($subject['description'] ?? ''); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                            data-id="<?php echo $subject['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                            data-code="<?php echo htmlspecialchars($subject['code']); ?>"
                                                            data-description="<?php echo htmlspecialchars($subject['description'] ?? ''); ?>"
                                                            data-units="<?php echo htmlspecialchars($subject['units'] ?? '3.0'); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <a href="?action=delete_subject&id=<?php echo $subject['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                       data-confirm-action="delete_subject"
                                                       data-id="<?php echo $subject['id']; ?>"
                                                       data-confirm-target="<?= htmlspecialchars($subject['name']); ?>"
                                                       data-confirm-warning="This will also delete all grades associated with it."
                                                       data-item-name="<?= htmlspecialchars($subject['name']); ?>"
                                                       title="Delete <?= htmlspecialchars($subject['name']); ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div id="noSubjectResults" class="no-results" style="display: none;">
                                        <i class="fas fa-search"></i>
                                        <p>No subjects found matching your search</p>
                                    </div>
                                </div>
                    </div>
                </div>
            </div>

            <!-- Programs Tab (formerly Courses) -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'courses') ? ' show active' : ''; ?>" id="courses" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Add New Program</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <div class="row">
                                <div class="col-md-3">
                                    <input type="text" class="form-control mb-3" name="code" placeholder="Program Code (e.g., BSBA)" required>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" class="form-control mb-3" name="name" placeholder="Program Name" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" class="form-control mb-3" name="duration_years" placeholder="Duration (Years)" value="4" min="1" max="10" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" name="add_course" class="btn btn-primary w-100">Add Program</button>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <textarea class="form-control" name="description" placeholder="Program Description (Optional)" rows="2"></textarea>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Programs</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($courses)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="courseSearch" placeholder="Search programs by name or code..." onkeyup="filterCourses()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="courseStatusFilter" onchange="filterCourses()">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Duration</th>
                                        <th>Sections</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="coursesTableBody">
                                    <?php foreach($courses as $course): ?>
                                    <tr class="course-row" 
                                        data-course-name="<?= strtolower(htmlspecialchars($course['name'])) ?>"
                                        data-course-code="<?= strtolower(htmlspecialchars($course['code'])) ?>"
                                        data-course-status="<?= strtolower(htmlspecialchars($course['status'] ?? 'active')) ?>">
                                        <td><?= $course['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($course['code']) ?></strong></td>
                                        <td><?= htmlspecialchars($course['name']) ?></td>
                                        <td><?= $course['duration_years'] ?> year(s)</td>
                                        <td><?= $course['total_sections'] ?? 0 ?></td>
                                        <td>
                                            <span class="badge bg-<?= $course['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst($course['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCourseModal"
                                                    data-id="<?= $course['id'] ?>"
                                                    data-code="<?= htmlspecialchars($course['code']) ?>"
                                                    data-name="<?= htmlspecialchars($course['name']) ?>"
                                                    data-description="<?= htmlspecialchars($course['description'] ?? '') ?>"
                                                    data-duration="<?= $course['duration_years'] ?>"
                                                    data-status="<?= $course['status'] ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="?action=delete_course&id=<?= $course['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                               data-confirm-action="delete_course"
                                               data-id="<?= $course['id'] ?>"
                                               data-confirm-target="<?= htmlspecialchars($course['name']); ?>"
                                               data-confirm-warning="This will also delete all associated sections."
                                               data-item-name="<?= htmlspecialchars($course['name']); ?>"
                                               title="Delete <?= htmlspecialchars($course['name']); ?>">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noCourseResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <p>No courses found matching your search</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sections Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'sections') ? ' show active' : ''; ?>" id="sections" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Add New Section</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                                    <label class="form-label">Course</label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="">Select Course</option>
                                        <?php foreach($courses as $course): ?>
                                            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-4 col-md-2">
                                    <label class="form-label">Section Name</label>
                                    <input type="text" class="form-control" name="section_name" placeholder="e.g., A" required>
                                </div>
                                <div class="col-6 col-sm-4 col-md-2">
                                    <label class="form-label">Year Level</label>
                                    <select class="form-select" name="year_level" required>
                                        <option value="">Select Year</option>
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-4 col-md-2">
                                    <label class="form-label">Academic Year</label>
                                    <input type="text" class="form-control" name="academic_year" placeholder="e.g., 2024-2025" required>
                                </div>
                                <div class="col-6 col-sm-4 col-md-2">
                                    <label class="form-label">Semester</label>
                                    <select class="form-select" name="semester">
                                        <option value="1st">1st Semester</option>
                                        <option value="2nd">2nd Semester</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-4 col-md-1 d-flex">
                                    <button type="submit" name="add_section" class="btn btn-primary w-100 mt-0">Add</button>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label class="form-label">Teacher (Optional)</label>
                                    <select class="form-select" name="teacher_id">
                                        <option value="">Select Teacher</option>
                                        <?php 
                                        $all_teachers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY first_name, last_name")->fetchAll();
                                        foreach($all_teachers as $teacher): 
                                        ?>
                                            <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3 col-lg-2">
                                    <label class="form-label">Max Students</label>
                                    <input type="number" class="form-control" name="max_students" placeholder="Max Students" value="50" min="1" required>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Sections</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($sections)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="sectionSearch" placeholder="Search by section, program..." onkeyup="filterSections()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="sectionCourseFilter" onchange="filterSections()">
                                    <option value="">All Programs</option>
                                    <?php 
                                    $courseCodes = [];
                                    foreach ($sections as $section) {
                                        if (!empty($section['course_code'])) {
                                            $courseCodes[$section['course_code']] = $section['course_code'];
                                        }
                                    }
                                    foreach ($courseCodes as $code): ?>
                                        <option value="<?= strtolower(htmlspecialchars($code)) ?>"><?= htmlspecialchars($code) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="filter-select" id="sectionYearLevelFilter" onchange="filterSections()">
                                    <option value="">All Year Levels</option>
                                    <?php 
                                    // Define year levels in correct order
                                    $orderedYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
                                    $existingYearLevels = [];
                                    foreach ($sections as $section) {
                                        if (!empty($section['year_level'])) {
                                            $existingYearLevels[$section['year_level']] = true;
                                        }
                                    }
                                    // Show year levels in correct order, only if they exist in sections
                                    foreach ($orderedYearLevels as $yearLevel): 
                                        if (isset($existingYearLevels[$yearLevel])): ?>
                                        <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                                        <?php endif; 
                                    endforeach; ?>
                                </select>
                                <select class="filter-select" id="sectionSemesterFilter" onchange="filterSections()">
                                    <option value="">All Semesters</option>
                                    <option value="1st">1st Semester</option>
                                    <option value="2nd">2nd Semester</option>
                                    <option value="Summer">Summer</option>
                                </select>
                                <select class="filter-select" id="sectionAcademicYearFilter" onchange="filterSections()">
                                    <option value="">All Academic Years</option>
                                    <?php 
                                    $academicYears = [];
                                    foreach ($sections as $section) {
                                        if (!empty($section['academic_year'])) {
                                            $academicYears[$section['academic_year']] = $section['academic_year'];
                                        }
                                    }
                                    foreach ($academicYears as $academicYear): ?>
                                        <option value="<?= htmlspecialchars($academicYear) ?>"><?= htmlspecialchars($academicYear) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Section</th>
                                        <th>Year Level</th>
                                        <th>Academic Year</th>
                                        <th>Semester</th>
                                        <th>Teacher</th>
                                        <th>Students</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="sectionsTableBody">
                                    <?php foreach($sections as $section): ?>
                                    <tr class="section-row" 
                                        data-section-name="<?= strtolower(htmlspecialchars($section['section_name'])) ?>"
                                        data-course-name="<?= strtolower(htmlspecialchars($section['course_name'] ?? '')) ?>"
                                        data-course-code="<?= strtolower(htmlspecialchars($section['course_code'] ?? '')) ?>"
                                        data-year-level="<?= strtolower(htmlspecialchars($section['year_level'] ?? '')) ?>"
                                        data-semester="<?= strtolower(htmlspecialchars($section['semester'] ?? '')) ?>"
                                        data-academic-year="<?= htmlspecialchars($section['academic_year'] ?? '') ?>">
                                        <td><?= $section['id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($section['course_code']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($section['course_name']) ?></small>
                                        </td>
                                        <td><strong><?= htmlspecialchars($section['section_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($section['year_level']) ?></td>
                                        <td><?= htmlspecialchars($section['academic_year']) ?></td>
                                        <td><?= htmlspecialchars($section['semester']) ?></td>
                                        <td><?= htmlspecialchars($section['teacher_name'] ?? 'Not Assigned') ?></td>
                                        <td>
                                            <?= $section['enrolled_students'] ?? 0 ?> / <?= $section['max_students'] ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $section['status'] === 'active' ? 'success' : ($section['status'] === 'closed' ? 'danger' : 'secondary') ?>">
                                                <?= ucfirst($section['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSectionModal"
                                                        data-id="<?= $section['id'] ?>"
                                                        data-course-id="<?= $section['course_id'] ?>"
                                                        data-section-name="<?= htmlspecialchars($section['section_name']) ?>"
                                                        data-year-level="<?= htmlspecialchars($section['year_level']) ?>"
                                                        data-academic-year="<?= htmlspecialchars($section['academic_year']) ?>"
                                                        data-semester="<?= htmlspecialchars($section['semester']) ?>"
                                                        data-teacher-id="<?= $section['teacher_id'] ?? '' ?>"
                                                        data-max-students="<?= $section['max_students'] ?>"
                                                        data-status="<?= $section['status'] ?>">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#manageSectionStudentsModal"
                                                        data-section-id="<?= $section['id'] ?>"
                                                        data-section-name="<?= htmlspecialchars($section['section_name']) ?>"
                                                        data-course-name="<?= htmlspecialchars($section['course_name']) ?>"
                                                        data-year-level="<?= htmlspecialchars($section['year_level']) ?>">
                                                    <i class="fas fa-users"></i> Students
                                                </button>
                                                <a href="?action=delete_section&id=<?= $section['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                   data-confirm-action="delete_section"
                                                   data-id="<?= $section['id'] ?>"
                                                   data-confirm-target="<?= htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'] . ' (' . $section['year_level'] . ')') ?>"
                                                   data-confirm-warning="This will also delete all associated classroom data."
                                                   data-item-name="<?= htmlspecialchars($section['section_name']); ?>"
                                                   title="Delete <?= htmlspecialchars($section['section_name']); ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noSectionResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <p>No sections found matching your search</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedules Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'schedules') ? ' show active' : ''; ?>" id="schedules" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Add New Schedule</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Section <span class="text-danger">*</span></label>
                                    <select class="form-select" name="section_id" required>
                                        <option value="">Select Section</option>
                                        <?php foreach($sections as $section): ?>
                                            <option value="<?= $section['id'] ?>">
                                                <?= htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'] . ' (' . $section['year_level'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Course <span class="text-danger">*</span></label>
                                    <select class="form-select" name="subject_id" required>
                                        <option value="">Select Course</option>
                                        <?php foreach($all_subjects as $subject): ?>
                                            <option value="<?= $subject['id'] ?>">
                                                <?= htmlspecialchars($subject['code'] . ' - ' . $subject['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Day of Week <span class="text-danger">*</span></label>
                                    <select class="form-select" name="day_of_week" required>
                                        <option value="">Select Day</option>
                                        <option value="Monday">Monday</option>
                                        <option value="Tuesday">Tuesday</option>
                                        <option value="Wednesday">Wednesday</option>
                                        <option value="Thursday">Thursday</option>
                                        <option value="Friday">Friday</option>
                                        <option value="Saturday">Saturday</option>
                                        <option value="Sunday">Sunday</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="start_time" required>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="end_time" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Teacher (Optional)</label>
                                    <select class="form-select" name="teacher_id">
                                        <option value="">Select Teacher</option>
                                        <?php 
                                        $all_teachers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY first_name, last_name")->fetchAll();
                                        foreach($all_teachers as $teacher): 
                                        ?>
                                            <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Room</label>
                                    <input type="text" class="form-control" name="room" placeholder="e.g., Room 101">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="academic_year" placeholder="e.g., 2024-2025" required>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Semester</label>
                                    <select class="form-select" name="semester">
                                        <option value="1st">1st Semester</option>
                                        <option value="2nd">2nd Semester</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="submit" name="add_schedule" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Add Schedule
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Schedules</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($schedules)): ?>
                            <div class="search-filter-container">
                                <div class="search-box">
                                    <input type="text" id="scheduleSearch" placeholder="Search by section, course..." onkeyup="filterSchedules()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <select class="filter-select" id="scheduleSectionFilter" onchange="filterSchedules()">
                                    <option value="">All Sections</option>
                                    <?php 
                                    $sectionNames = [];
                                    foreach ($schedules as $schedule) {
                                        if (!empty($schedule['section_name'])) {
                                            $key = $schedule['section_name'] . '|' . $schedule['course_code'];
                                            if (!isset($sectionNames[$key])) {
                                                $sectionNames[$key] = $schedule['course_code'] . ' - ' . $schedule['section_name'];
                                            }
                                        }
                                    }
                                    foreach ($sectionNames as $key => $name): ?>
                                        <option value="<?= strtolower(htmlspecialchars($name)) ?>"><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="filter-select" id="scheduleDayFilter" onchange="filterSchedules()">
                                    <option value="">All Days</option>
                                    <option value="Monday">Monday</option>
                                    <option value="Tuesday">Tuesday</option>
                                    <option value="Wednesday">Wednesday</option>
                                    <option value="Thursday">Thursday</option>
                                    <option value="Friday">Friday</option>
                                    <option value="Saturday">Saturday</option>
                                    <option value="Sunday">Sunday</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Section</th>
                                        <th>Course</th>
                                        <th>Day</th>
                                        <th>Time</th>
                                        <th>Room</th>
                                        <th>Teacher</th>
                                        <th>Academic Year</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="schedulesTableBody">
                                    <?php foreach($schedules as $schedule): ?>
                                    <tr class="schedule-row" 
                                        data-section="<?= strtolower(htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['section_name'])) ?>"
                                        data-subject="<?= strtolower(htmlspecialchars($schedule['subject_name'] ?? '')) ?>"
                                        data-day="<?= strtolower(htmlspecialchars($schedule['day_of_week'] ?? '')) ?>">
                                        <td><?= $schedule['id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($schedule['course_code'] ?? 'N/A') ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($schedule['section_name'] ?? '') ?> (<?= htmlspecialchars($schedule['section_year_level'] ?? '') ?>)</small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($schedule['subject_code'] ?? 'N/A') ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($schedule['subject_name'] ?? '') ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($schedule['day_of_week']) ?></td>
                                        <td>
                                            <?= date('h:i A', strtotime($schedule['start_time'])) ?> - 
                                            <?= date('h:i A', strtotime($schedule['end_time'])) ?>
                                        </td>
                                        <td><?= htmlspecialchars($schedule['room'] ?? $schedule['classroom_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($schedule['teacher_name'] ?? 'Not Assigned') ?></td>
                                        <td>
                                            <?= htmlspecialchars($schedule['academic_year']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($schedule['semester']) ?> Semester</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $schedule['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst($schedule['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editScheduleModal"
                                                        data-id="<?= $schedule['id'] ?>"
                                                        data-section-id="<?= $schedule['section_id'] ?>"
                                                        data-subject-id="<?= $schedule['subject_id'] ?>"
                                                        data-teacher-id="<?= $schedule['teacher_id'] ?? '' ?>"
                                                        data-day-of-week="<?= htmlspecialchars($schedule['day_of_week']) ?>"
                                                        data-start-time="<?= $schedule['start_time'] ?>"
                                                        data-end-time="<?= $schedule['end_time'] ?>"
                                                        data-room="<?= htmlspecialchars($schedule['room'] ?? '') ?>"
                                                        data-academic-year="<?= htmlspecialchars($schedule['academic_year']) ?>"
                                                        data-semester="<?= htmlspecialchars($schedule['semester']) ?>"
                                                        data-status="<?= $schedule['status'] ?>">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <a href="?action=delete_schedule&id=<?= $schedule['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                   data-confirm-action="delete_schedule"
                                                   data-id="<?= $schedule['id'] ?>"
                                                   data-confirm-target="this schedule"
                                                   data-item-name="this schedule"
                                                   title="Delete this schedule">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noScheduleResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <p>No schedules found matching your search</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Periods Management -->
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Enrollment Periods</h2>
                        <p class="text-muted mb-0">Manage enrollment periods for each program with strict start and end times</p>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Program <span class="text-danger">*</span></label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="">Select Program</option>
                                        <?php foreach($courses as $course): ?>
                                            <option value="<?= $course['id'] ?>">
                                                <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="academic_year" placeholder="e.g., 2024-2025" required>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Semester <span class="text-danger">*</span></label>
                                    <select class="form-select" name="semester" required>
                                        <option value="1st">1st Semester</option>
                                        <option value="2nd">2nd Semester</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Start Date & Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" name="start_date" required>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">End Date & Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" name="end_date" required>
                                </div>
                                <div class="col-md-1 mb-3">
                                    <label class="form-label">Auto-Close</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" name="auto_close" checked>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="submit" name="add_enrollment_period" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Add Enrollment Period
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">All Enrollment Periods</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($enrollment_periods)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Program</th>
                                            <th>Academic Year</th>
                                            <th>Semester</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                            <th>Auto-Close</th>
                                            <th>Created By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($enrollment_periods as $period): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($period['course_code'] ?? 'N/A') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($period['course_name'] ?? '') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($period['academic_year']) ?></td>
                                            <td><?= htmlspecialchars($period['semester']) ?></td>
                                            <td><?= date('M d, Y h:i A', strtotime($period['start_date'])) ?></td>
                                            <td><?= date('M d, Y h:i A', strtotime($period['end_date'])) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $period['status'] === 'active' ? 'success' : ($period['status'] === 'closed' ? 'danger' : 'warning') ?>">
                                                    <?= ucfirst($period['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $period['auto_close'] ? 'info' : 'secondary' ?>">
                                                    <?= $period['auto_close'] ? 'Yes' : 'No' ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($period['created_by_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editEnrollmentPeriodModal"
                                                            data-id="<?= $period['id'] ?>"
                                                            data-course-id="<?= $period['course_id'] ?>"
                                                            data-academic-year="<?= htmlspecialchars($period['academic_year']) ?>"
                                                            data-semester="<?= htmlspecialchars($period['semester']) ?>"
                                                            data-start-date="<?= date('Y-m-d\TH:i', strtotime($period['start_date'])) ?>"
                                                            data-end-date="<?= date('Y-m-d\TH:i', strtotime($period['end_date'])) ?>"
                                                            data-status="<?= $period['status'] ?>"
                                                            data-auto-close="<?= $period['auto_close'] ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <a href="?action=delete_enrollment_period&id=<?= $period['id'] ?>" 
                                                       class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                       data-confirm-action="delete_enrollment_period"
                                                       data-id="<?= $period['id'] ?>"
                                                       data-confirm-target="this enrollment period"
                                                       data-item-name="this enrollment period"
                                                       title="Delete this enrollment period">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No enrollment periods found. Create one to allow students to enroll for the next semester.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enrollment Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'enrollment') ? ' show active' : ''; ?>" id="enrollment" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Enrollment Requests</h2>
                        <p class="text-muted mb-0">View, approve, or reject student enrollment requests for the next semester</p>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($enrollment_requests)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Program</th>
                                            <th>Academic Year</th>
                                            <th>Semester</th>
                                            <th>Requested At</th>
                                            <th>Status</th>
                                            <th>Period</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($enrollment_requests as $request): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($request['student_name'] ?? 'N/A') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($request['student_id_number'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($request['course_code'] ?? 'N/A') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($request['course_name'] ?? '') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($request['academic_year']) ?></td>
                                            <td><?= htmlspecialchars($request['semester']) ?></td>
                                            <td><?= date('M d, Y h:i A', strtotime($request['requested_at'])) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : ($request['status'] === 'voided' ? 'secondary' : 'warning')) ?>">
                                                    <?= ucfirst($request['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?= date('M d, Y', strtotime($request['period_start'])) ?> - 
                                                    <?= date('M d, Y', strtotime($request['period_end'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveEnrollmentModal"
                                                                data-id="<?= $request['id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($request['student_name']) ?>">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectEnrollmentModal"
                                                                data-id="<?= $request['id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($request['student_name']) ?>">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <small class="text-muted">
                                                        Reviewed by: <?= htmlspecialchars($request['reviewed_by_name'] ?? 'N/A') ?><br>
                                                        <?php if ($request['status'] === 'rejected' && $request['rejection_reason']): ?>
                                                            Reason: <?= htmlspecialchars($request['rejection_reason']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No enrollment requests found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php /* TEMPORARILY DISABLED - Irregular Students Feature UI
            <!-- Irregular Students Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'irregular_students') ? ' show active' : ''; ?>" id="irregular_students" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Irregular Students Management</h2>
                        <p class="text-muted mb-0">View and manage grades, back subjects, and required units for irregular students</p>
                    </div>
                    <div class="card-body">
                        <?php if (empty($irregular_students)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No irregular students found. Students with "Irregular" educational status will appear here.
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0"><i class="fas fa-list"></i> Irregular Students</h5>
                                        </div>
                                        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                            <div class="list-group">
                                                <?php foreach ($irregular_students as $student): ?>
                                                    <a href="?tab=irregular_students&student_id=<?= $student['id'] ?>" 
                                                       class="list-group-item list-group-item-action <?= (isset($_GET['student_id']) && $_GET['student_id'] == $student['id']) ? 'active' : '' ?>">
                                                        <div class="d-flex w-100 justify-content-between">
                                                            <h6 class="mb-1"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h6>
                                                        </div>
                                                        <p class="mb-1 small text-muted">
                                                            ID: <?= htmlspecialchars($student['student_id_number'] ?? 'N/A') ?><br>
                                                            Program: <?= htmlspecialchars($student['program'] ?? 'N/A') ?><br>
                                                            Back Courses: <?= $student['back_subjects_count'] ?> (<?= $student['completed_back_subjects'] ?> completed)
                                                        </p>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <?php if ($selected_student): ?>
                                        <div class="card">
                                            <div class="card-header bg-success text-white">
                                                <h5 class="mb-0">
                                                    <i class="fas fa-user-graduate"></i> 
                                                    <?= htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']) ?>
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <!-- Student Info -->
                                                <div class="mb-4">
                                                    <h6>Student Information</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <p><strong>Student ID:</strong> <?= htmlspecialchars($selected_student['student_id_number'] ?? 'N/A') ?></p>
                                                            <p><strong>Program:</strong> <?= htmlspecialchars($selected_student['program'] ?? 'N/A') ?></p>
                                                            <p><strong>Year Level:</strong> <?= htmlspecialchars($selected_student['year_level'] ?? 'N/A') ?></p>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <p><strong>Email:</strong> <?= htmlspecialchars($selected_student['email'] ?? 'N/A') ?></p>
                                                            <p><strong>Status:</strong> <span class="badge bg-warning">Irregular</span></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Grades Section -->
                                                <div class="mb-4">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h6>Grades</h6>
                                                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#gradesSection">
                                                            <i class="fas fa-chevron-down"></i> Toggle
                                                        </button>
                                                    </div>
                                                    <div class="collapse show" id="gradesSection">
                                                        <?php if (empty($selected_student_grades)): ?>
                                                            <p class="text-muted">No grades recorded yet.</p>
                                                        <?php else: ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Course</th>
                                                                            <th>Code</th>
                                                                            <th>Grade</th>
                                                                            <th>Type</th>
                                                                            <th>Classroom</th>
                                                                            <th>Teacher</th>
                                                                            <th>Date</th>
                                                                            <th>Actions</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($selected_student_grades as $grade): ?>
                                                                            <tr>
                                                                                <td><?= htmlspecialchars($grade['subject_name'] ?? 'N/A') ?></td>
                                                                                <td><?= htmlspecialchars($grade['subject_code'] ?? 'N/A') ?></td>
                                                                                <td>
                                                                                    <strong><?= number_format($grade['grade'], 2) ?></strong>
                                                                                    <?php if ($grade['manually_edited']): ?>
                                                                                        <span class="badge bg-warning" title="Manually edited">M</span>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td><?= htmlspecialchars($grade['grade_type'] ?? 'N/A') ?></td>
                                                                                <td><?= htmlspecialchars($grade['classroom_name'] ?? 'N/A') ?></td>
                                                                                <td><?= htmlspecialchars(($grade['teacher_first'] ?? '') . ' ' . ($grade['teacher_last'] ?? '')) ?></td>
                                                                                <td><?= $grade['graded_at'] ? date('M d, Y', strtotime($grade['graded_at'])) : 'N/A' ?></td>
                                                                                <td>
                                                                                    <button class="btn btn-sm btn-warning" 
                                                                                            data-bs-toggle="modal" 
                                                                                            data-bs-target="#editGradeModal<?= $grade['id'] ?>">
                                                                                        <i class="fas fa-edit"></i> Edit
                                                                                    </button>
                                                                                </td>
                                                                            </tr>
                                                                            
                                                                            <!-- Edit Grade Modal -->
                                                                            <div class="modal fade" id="editGradeModal<?= $grade['id'] ?>" tabindex="-1">
                                                                                <div class="modal-dialog">
                                                                                    <div class="modal-content">
                                                                                        <div class="modal-header">
                                                                                            <h5 class="modal-title">Edit Grade</h5>
                                                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                                        </div>
                                                                                        <form method="POST">
                                                                                            <?= getCSRFTokenField() ?>
                                                                                            <input type="hidden" name="grade_id" value="<?= $grade['id'] ?>">
                                                                                            <div class="modal-body">
                                                                                                <div class="mb-3">
                                                                                                    <label class="form-label">Subject</label>
                                                                                                    <input type="text" class="form-control" value="<?= htmlspecialchars($grade['subject_name'] . ' (' . $grade['subject_code'] . ')') ?>" readonly>
                                                                                                </div>
                                                                                                <div class="mb-3">
                                                                                                    <label class="form-label">Grade <span class="text-danger">*</span></label>
                                                                                                    <input type="number" class="form-control" name="grade" value="<?= $grade['grade'] ?>" min="0" max="100" step="0.01" required>
                                                                                                </div>
                                                                                                <div class="mb-3">
                                                                                                    <label class="form-label">Remarks</label>
                                                                                                    <textarea class="form-control" name="remarks" rows="2"><?= htmlspecialchars($grade['remarks'] ?? '') ?></textarea>
                                                                                                </div>
                                                                                            </div>
                                                                                            <div class="modal-footer">
                                                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                                                <button type="submit" name="update_irregular_grade" class="btn btn-primary">Update Grade</button>
                                                                                            </div>
                                                                                        </form>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- Back Courses Section -->
                                                <div class="mb-4">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h6>Back Courses</h6>
                                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addBackSubjectModal">
                                                            <i class="fas fa-plus"></i> Add Back Course
                                                        </button>
                                                    </div>
                                                    <?php if (empty($selected_student_back_subjects)): ?>
                                                        <p class="text-muted">No back courses recorded.</p>
                                                    <?php else: ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-sm table-bordered">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Subject</th>
                                                                        <th>Code</th>
                                                                        <th>Required Units</th>
                                                                        <th>Completed Units</th>
                                                                        <th>Status</th>
                                                                        <th>Completion Date</th>
                                                                        <th>Actions</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($selected_student_back_subjects as $back_subject): ?>
                                                                        <tr>
                                                                            <td><?= htmlspecialchars($back_subject['subject_name'] ?? 'N/A') ?></td>
                                                                            <td><?= htmlspecialchars($back_subject['subject_code'] ?? 'N/A') ?></td>
                                                                            <td><?= number_format($back_subject['required_units'] ?? 0, 1) ?></td>
                                                                            <td><?= number_format($back_subject['completed_units'] ?? 0, 1) ?></td>
                                                                            <td>
                                                                                <span class="badge bg-<?= $back_subject['status'] === 'completed' ? 'success' : ($back_subject['status'] === 'in_progress' ? 'warning' : 'secondary') ?>">
                                                                                    <?= ucfirst($back_subject['status']) ?>
                                                                                </span>
                                                                            </td>
                                                                            <td><?= $back_subject['completion_date'] ? date('M d, Y', strtotime($back_subject['completion_date'])) : 'N/A' ?></td>
                                                                            <td>
                                                                                <?php if ($back_subject['status'] !== 'completed'): ?>
                                                                                    <button class="btn btn-sm btn-primary" 
                                                                                            data-bs-toggle="modal" 
                                                                                            data-bs-target="#updateUnitsModal<?= $back_subject['id'] ?>">
                                                                                        <i class="fas fa-edit"></i> Update Units
                                                                                    </button>
                                                                                    <button class="btn btn-sm btn-success" 
                                                                                            data-bs-toggle="modal" 
                                                                                            data-bs-target="#completeBackSubjectModal<?= $back_subject['id'] ?>">
                                                                                        <i class="fas fa-check"></i> Mark Complete
                                                                                    </button>
                                                                                <?php else: ?>
                                                                                    <span class="text-muted small">
                                                                                        <i class="fas fa-lock"></i> Units locked (completed)
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                        
                                                                        <!-- Update Units Modal -->
                                                                        <div class="modal fade" id="updateUnitsModal<?= $back_subject['id'] ?>" tabindex="-1">
                                                                            <div class="modal-dialog">
                                                                                <div class="modal-content">
                                                                                    <div class="modal-header">
                                                                                        <h5 class="modal-title">Update Required Units</h5>
                                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                                    </div>
                                                                                    <form method="POST">
                                                                                        <?= getCSRFTokenField() ?>
                                                                                        <input type="hidden" name="back_subject_id" value="<?= $back_subject['id'] ?>">
                                                                                        <div class="modal-body">
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Subject</label>
                                                                                                <input type="text" class="form-control" value="<?= htmlspecialchars($back_subject['subject_name'] . ' (' . $back_subject['subject_code'] . ')') ?>" readonly>
                                                                                            </div>
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Required Units <span class="text-danger">*</span></label>
                                                                                                <input type="number" class="form-control" name="required_units" value="<?= $back_subject['required_units'] ?? 0 ?>" min="0" step="0.1" required>
                                                                                            </div>
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Completed Units</label>
                                                                                                <input type="number" class="form-control" name="completed_units" value="<?= $back_subject['completed_units'] ?? 0 ?>" min="0" step="0.1">
                                                                                            </div>
                                                                                        </div>
                                                                                        <div class="modal-footer">
                                                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                                            <button type="submit" name="update_back_subject_units" class="btn btn-primary">Update Units</button>
                                                                                        </div>
                                                                                    </form>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        <!-- Complete Back Course Modal -->
                                                                        <div class="modal fade" id="completeBackSubjectModal<?= $back_subject['id'] ?>" tabindex="-1">
                                                                            <div class="modal-dialog">
                                                                                <div class="modal-content">
                                                                                    <div class="modal-header">
                                                                                        <h5 class="modal-title">Mark Back Course as Completed</h5>
                                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                                    </div>
                                                                                    <form method="POST">
                                                                                        <?= getCSRFTokenField() ?>
                                                                                        <input type="hidden" name="back_subject_id" value="<?= $back_subject['id'] ?>">
                                                                                        <div class="modal-body">
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Subject</label>
                                                                                                <input type="text" class="form-control" value="<?= htmlspecialchars($back_subject['subject_name'] . ' (' . $back_subject['subject_code'] . ')') ?>" readonly>
                                                                                            </div>
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Completed Units <span class="text-danger">*</span></label>
                                                                                                <input type="number" class="form-control" name="completed_units" value="<?= $back_subject['required_units'] ?? 0 ?>" min="0" step="0.1" required>
                                                                                            </div>
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Completion Date <span class="text-danger">*</span></label>
                                                                                                <input type="date" class="form-control" name="completion_date" value="<?= date('Y-m-d') ?>" required>
                                                                                            </div>
                                                                                            <div class="mb-3">
                                                                                                <label class="form-label">Notes</label>
                                                                                                <textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($back_subject['notes'] ?? '') ?></textarea>
                                                                                            </div>
                                                                                        </div>
                                                                                        <div class="modal-footer">
                                                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                                            <button type="submit" name="complete_back_subject" class="btn btn-success">Mark as Completed</button>
                                                                                        </div>
                                                                                    </form>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Add Back Subject Modal -->
                                        <div class="modal fade" id="addBackSubjectModal" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Add Back Subject</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <?= getCSRFTokenField() ?>
                                                        <input type="hidden" name="student_id" value="<?= $selected_student['id'] ?>">
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Subject <span class="text-danger">*</span></label>
                                                                <select class="form-select" name="subject_id" required>
                                                                    <option value="">Select Subject</option>
                                                                    <?php foreach ($all_subjects as $subject): ?>
                                                                        <option value="<?= $subject['id'] ?>">
                                                                            <?= htmlspecialchars($subject['name'] . ' (' . $subject['code'] . ') - ' . $subject['units'] . ' units') ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Required Units</label>
                                                                <input type="number" class="form-control" name="required_units" value="0" min="0" step="0.1">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes</label>
                                                                <textarea class="form-control" name="notes" rows="3"></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="add_back_subject" class="btn btn-primary">Add Back Subject</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Please select a student from the list to view their details.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            END TEMPORARILY DISABLED */ ?>

            <!-- Grade Approval Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'grade_approval') ? ' show active' : ''; ?>" id="grade_approval" role="tabpanel">
                <?php
                // Get pending grades for approval
                $pendingGrades = [];
                $filters = [
                    'course_id' => $_GET['course_id'] ?? null,
                    'teacher_id' => $_GET['teacher_id'] ?? null,
                    'academic_year' => $_GET['academic_year'] ?? null,
                    'semester' => $_GET['semester'] ?? null,
                ];

                try {
                    $query = "
                        SELECT g.*,
                               u.first_name as student_first, u.last_name as student_last, u.student_id_number,
                               s.name as subject_name, s.code as subject_code,
                               t.first_name as teacher_first, t.last_name as teacher_last,
                               c.name as course_name, c.code as course_code
                        FROM grades g
                        JOIN users u ON g.student_id = u.id
                        JOIN subjects s ON g.subject_id = s.id
                        JOIN users t ON g.teacher_id = t.id
                        LEFT JOIN sections sec ON g.academic_year = sec.academic_year AND g.semester = sec.semester
                        LEFT JOIN courses c ON sec.course_id = c.id
                        WHERE g.approval_status = 'submitted'
                        AND g.grade_type = 'final'
                    ";
                    
                    $params = [];
                    
                    if ($filters['course_id']) {
                        $query .= " AND sec.course_id = ?";
                        $params[] = $filters['course_id'];
                    }
                    
                    if ($filters['teacher_id']) {
                        $query .= " AND g.teacher_id = ?";
                        $params[] = $filters['teacher_id'];
                    }
                    
                    if ($filters['academic_year']) {
                        $query .= " AND g.academic_year = ?";
                        $params[] = $filters['academic_year'];
                    }
                    
                    if ($filters['semester']) {
                        $query .= " AND g.semester = ?";
                        $params[] = $filters['semester'];
                    }
                    
                    $query .= " ORDER BY g.submitted_at DESC";
                    
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    $pendingGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Error fetching pending grades: " . $e->getMessage());
                }

                // Get filter options
                $gradeApprovalCourses = [];
                $gradeApprovalTeachers = [];
                $gradeApprovalAcademicYears = [];

                try {
                    $stmt = $pdo->query("SELECT DISTINCT id, code, name FROM courses ORDER BY code");
                    $gradeApprovalCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->query("SELECT DISTINCT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY last_name, first_name");
                    $gradeApprovalTeachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM grades WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
                    $gradeApprovalAcademicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Tables might not exist
                }
                ?>
                
                <!-- Filters Card -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header">
                        <h2 class="card-title">Search & Filter</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row">
                            <input type="hidden" name="tab" value="grade_approval">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Course</label>
                                <select name="course_id" class="form-select">
                                    <option value="">All Courses</option>
                                    <?php foreach ($gradeApprovalCourses as $course): ?>
                                        <option value="<?= $course['id'] ?>" <?= ($filters['course_id'] == $course['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Teacher</label>
                                <select name="teacher_id" class="form-select">
                                    <option value="">All Teachers</option>
                                    <?php foreach ($gradeApprovalTeachers as $teacher): ?>
                                        <option value="<?= $teacher['id'] ?>" <?= ($filters['teacher_id'] == $teacher['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" 
                                       value="<?= htmlspecialchars($filters['academic_year'] ?? '') ?>" 
                                       placeholder="e.g., 2024-2025">
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Semester</label>
                                <select name="semester" class="form-select">
                                    <option value="">All</option>
                                    <option value="1st" <?= ($filters['semester'] === '1st') ? 'selected' : '' ?>>1st</option>
                                    <option value="2nd" <?= ($filters['semester'] === '2nd') ? 'selected' : '' ?>>2nd</option>
                                    <option value="Summer" <?= ($filters['semester'] === 'Summer') ? 'selected' : '' ?>>Summer</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Pending Grades Card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Pending Grades (<?= count($pendingGrades) ?>)</h2>
                        <p class="text-muted mb-0">Review and approve or reject submitted final grades</p>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingGrades)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No pending grades for approval.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Course</th>
                                            <th>Teacher</th>
                                            <th>Grade</th>
                                            <th>Academic Year</th>
                                            <th>Semester</th>
                                            <th>Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingGrades as $grade): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($grade['student_id_number'] ?? 'N/A') ?></small>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($grade['subject_name']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($grade['subject_code']) ?></small>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($grade['course_name'] ?? 'N/A') ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($grade['course_code'] ?? '') ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($grade['teacher_first'] . ' ' . $grade['teacher_last']) ?></td>
                                                <td>
                                                    <strong><?= number_format($grade['grade'], 2) ?></strong>
                                                    <br><small class="text-muted">/ <?= number_format($grade['max_points'] ?? 100, 2) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($grade['academic_year']) ?></td>
                                                <td><?= htmlspecialchars(strtoupper($grade['semester'])) ?></td>
                                                <td><?= $grade['submitted_at'] ? date('M d, Y h:i A', strtotime($grade['submitted_at'])) : 'N/A' ?></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-sm btn-success admin-action-btn" 
                                                                data-grade-id="<?= $grade['id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last'], ENT_QUOTES) ?>"
                                                                data-subject-name="<?= htmlspecialchars($grade['subject_name'], ENT_QUOTES) ?>"
                                                                data-grade-value="<?= $grade['grade'] ?>"
                                                                data-action="approve"
                                                                id="approve-btn-<?= $grade['id'] ?>">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger admin-action-btn" 
                                                                data-grade-id="<?= $grade['id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last'], ENT_QUOTES) ?>"
                                                                data-subject-name="<?= htmlspecialchars($grade['subject_name'], ENT_QUOTES) ?>"
                                                                data-action="reject"
                                                                id="reject-btn-<?= $grade['id'] ?>">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php if ($grade['remarks']): ?>
                                                <tr>
                                                    <td colspan="9" class="text-muted" style="padding-left: 30px; font-style: italic;">
                                                        <i class="fas fa-comment"></i> <strong>Remarks:</strong> <?= htmlspecialchars($grade['remarks']) ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Teacher Requests Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'teacher_requests') ? ' show active' : ''; ?>" id="teacher_requests" role="tabpanel">
                <?php
                // Get teacher edit requests
                $editRequests = [];
                $statusFilter = $_GET['status_filter'] ?? 'all';
                
                try {
                    $query = "
                        SELECT ger.*,
                               g.grade as current_grade, g.max_points,
                               u.first_name as student_first, u.last_name as student_last, u.student_id_number,
                               s.name as subject_name, s.code as subject_code,
                               t.first_name as teacher_first, t.last_name as teacher_last,
                               c.name as course_name, c.code as course_code,
                               admin.first_name as reviewer_first, admin.last_name as reviewer_last
                        FROM grade_edit_requests ger
                        JOIN grades g ON ger.grade_id = g.id
                        JOIN users u ON g.student_id = u.id
                        JOIN subjects s ON ger.subject_id = s.id
                        JOIN users t ON ger.teacher_id = t.id
                        LEFT JOIN courses c ON ger.course_id = c.id
                        LEFT JOIN users admin ON ger.reviewed_by = admin.id
                        WHERE 1=1
                    ";
                    
                    $params = [];
                    
                    if ($statusFilter !== 'all') {
                        $query .= " AND ger.status = ?";
                        $params[] = $statusFilter;
                    }
                    
                    $query .= " ORDER BY ger.requested_at DESC";
                    
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    $editRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Error fetching edit requests: " . $e->getMessage());
                }
                ?>
                
                <div class="top-header">
                    <h1 class="page-title">Teacher Requests</h1>
                    <p class="text-muted">Manage grade edit requests from teachers</p>
                </div>
                
                <!-- Filters -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="tab" value="teacher_requests">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status_filter" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Requests</option>
                                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="denied" <?= $statusFilter === 'denied' ? 'selected' : '' ?>>Denied</option>
                                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Requests Table -->
                <div class="card">
                    <div class="card-header" style="background: #a11c27; color: white;">
                        <h2 class="card-title" style="color: white; border-bottom: none;">Grade Edit Requests (<?= count($editRequests) ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($editRequests)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No edit requests found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Teacher</th>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Current Grade</th>
                                            <th>Request Reason</th>
                                            <th>Status</th>
                                            <th>Requested</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($editRequests as $request): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($request['teacher_first'] . ' ' . $request['teacher_last']) ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($request['student_first'] . ' ' . $request['student_last']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($request['student_id_number'] ?? 'N/A') ?></small>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($request['subject_name']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($request['subject_code']) ?></small>
                                                </td>
                                                <td>
                                                    <strong><?= number_format($request['current_grade'], 2) ?></strong>
                                                    <br><small class="text-muted">/ <?= number_format($request['max_points'] ?? 100, 2) ?></small>
                                                </td>
                                                <td>
                                                    <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                        <?= htmlspecialchars($request['request_reason']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = $request['status'];
                                                    $badgeClass = 'bg-secondary';
                                                    if ($status === 'pending') $badgeClass = 'bg-warning';
                                                    elseif ($status === 'approved') $badgeClass = 'bg-success';
                                                    elseif ($status === 'denied') $badgeClass = 'bg-danger';
                                                    elseif ($status === 'completed') $badgeClass = 'bg-info';
                                                    ?>
                                                    <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                                                </td>
                                                <td><?= date('M d, Y h:i A', strtotime($request['requested_at'])) ?></td>
                                                <td>
                                                    <?php if ($status === 'pending'): ?>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-success admin-action-btn" 
                                                                    data-request-id="<?= $request['id'] ?>"
                                                                    data-teacher-name="<?= htmlspecialchars($request['teacher_first'] . ' ' . $request['teacher_last'], ENT_QUOTES) ?>"
                                                                    data-subject-name="<?= htmlspecialchars($request['subject_name'], ENT_QUOTES) ?>"
                                                                    data-action="approve-request"
                                                                    id="approve-request-btn-<?= $request['id'] ?>">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-danger admin-action-btn" 
                                                                    data-request-id="<?= $request['id'] ?>"
                                                                    data-teacher-name="<?= htmlspecialchars($request['teacher_first'] . ' ' . $request['teacher_last'], ENT_QUOTES) ?>"
                                                                    data-subject-name="<?= htmlspecialchars($request['subject_name'], ENT_QUOTES) ?>"
                                                                    data-action="deny-request"
                                                                    id="deny-request-btn-<?= $request['id'] ?>">
                                                                <i class="fas fa-times"></i> Deny
                                                            </button>
                                                        </div>
                                                    <?php elseif ($status === 'approved' && (int)$request['edit_completed'] === 0): ?>
                                                        <span class="text-muted small">Waiting for teacher to complete edit</span>
                                                    <?php elseif ($status === 'approved' && (int)$request['edit_completed'] === 1): ?>
                                                        <button type="button" class="btn btn-sm btn-primary admin-action-btn" 
                                                                data-grade-id="<?= $request['grade_id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($request['student_first'] . ' ' . $request['student_last'], ENT_QUOTES) ?>"
                                                                data-subject-name="<?= htmlspecialchars($request['subject_name'], ENT_QUOTES) ?>"
                                                                data-action="complete-edit"
                                                                id="complete-edit-btn-<?= $request['grade_id'] ?>">
                                                            <i class="fas fa-lock"></i> Re-approve & Lock
                                                        </button>
                                                    <?php elseif ($status === 'denied'): ?>
                                                        <?php if ($request['reviewer_first']): ?>
                                                            <small class="text-muted">
                                                                Denied by: <?= htmlspecialchars($request['reviewer_first'] . ' ' . $request['reviewer_last']) ?><br>
                                                                <?= $request['reviewed_at'] ? date('M d, Y', strtotime($request['reviewed_at'])) : '' ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php elseif ($status === 'completed'): ?>
                                                        <span class="badge bg-success">Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? ' show active' : ''; ?>" id="settings" role="tabpanel">
                <!-- System Settings -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header" style="background: #a11c27; color: white;">
                        <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-cog"></i> System Settings</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= getCSRFTokenField() ?>
                            <input type="hidden" name="update_settings" value="1">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Site Name</label>
                                    <input type="text" class="form-control" name="settings[site_name]" value="<?= htmlspecialchars(getSystemSetting('site_name', 'Colegio de Amore')) ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Site Email</label>
                                    <input type="email" class="form-control" name="settings[site_email]" value="<?= htmlspecialchars(getSystemSetting('site_email', 'admin@colegiodeamore.edu')) ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Default Language</label>
                                    <select class="form-select" name="settings[default_language]">
                                        <option value="en" <?= getSystemSetting('default_language', 'en') === 'en' ? 'selected' : '' ?>>English</option>
                                        <option value="es" <?= getSystemSetting('default_language', 'en') === 'es' ? 'selected' : '' ?>>Español (Spanish)</option>
                                        <option value="tl" <?= getSystemSetting('default_language', 'en') === 'tl' ? 'selected' : '' ?>>Tagalog</option>
                                        <option value="zh" <?= getSystemSetting('default_language', 'en') === 'zh' ? 'selected' : '' ?>>中文 (Chinese)</option>
                                        <option value="fr" <?= getSystemSetting('default_language', 'en') === 'fr' ? 'selected' : '' ?>>Français (French)</option>
                                        <option value="de" <?= getSystemSetting('default_language', 'en') === 'de' ? 'selected' : '' ?>>Deutsch (German)</option>
                                        <option value="ja" <?= getSystemSetting('default_language', 'en') === 'ja' ? 'selected' : '' ?>>日本語 (Japanese)</option>
                                        <option value="ko" <?= getSystemSetting('default_language', 'en') === 'ko' ? 'selected' : '' ?>>한국어 (Korean)</option>
                                        <option value="pt" <?= getSystemSetting('default_language', 'en') === 'pt' ? 'selected' : '' ?>>Português (Portuguese)</option>
                                        <option value="it" <?= getSystemSetting('default_language', 'en') === 'it' ? 'selected' : '' ?>>Italiano (Italian)</option>
                                        <option value="ru" <?= getSystemSetting('default_language', 'en') === 'ru' ? 'selected' : '' ?>>Русский (Russian)</option>
                                        <option value="ar" <?= getSystemSetting('default_language', 'en') === 'ar' ? 'selected' : '' ?>>العربية (Arabic)</option>
                                        <option value="hi" <?= getSystemSetting('default_language', 'en') === 'hi' ? 'selected' : '' ?>>हिन्दी (Hindi)</option>
                                        <option value="th" <?= getSystemSetting('default_language', 'en') === 'th' ? 'selected' : '' ?>>ไทย (Thai)</option>
                                        <option value="vi" <?= getSystemSetting('default_language', 'en') === 'vi' ? 'selected' : '' ?>>Tiếng Việt (Vietnamese)</option>
                                        <option value="id" <?= getSystemSetting('default_language', 'en') === 'id' ? 'selected' : '' ?>>Bahasa Indonesia</option>
                                        <option value="ms" <?= getSystemSetting('default_language', 'en') === 'ms' ? 'selected' : '' ?>>Bahasa Melayu (Malay)</option>
                                        <option value="nl" <?= getSystemSetting('default_language', 'en') === 'nl' ? 'selected' : '' ?>>Nederlands (Dutch)</option>
                                        <option value="pl" <?= getSystemSetting('default_language', 'en') === 'pl' ? 'selected' : '' ?>>Polski (Polish)</option>
                                        <option value="tr" <?= getSystemSetting('default_language', 'en') === 'tr' ? 'selected' : '' ?>>Türkçe (Turkish)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Max Upload Size (MB)</label>
                                    <input type="number" class="form-control" name="settings[max_upload_size]" value="<?= round(getSystemSetting('max_upload_size', 5242880) / 1024 / 1024, 0) ?>" min="1" max="100">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Allowed File Types (comma-separated)</label>
                                    <input type="text" class="form-control" name="settings[allowed_file_types]" value="<?= htmlspecialchars(getSystemSetting('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx')) ?>" placeholder="jpg,jpeg,png,gif,pdf">
                                </div>
                            </div>
                            
                            <hr style="border-color: #ddd; margin: 20px 0;">
                            <h5 style="color: #a11c27; margin-bottom: 15px;"><i class="fas fa-envelope"></i> Email Configuration</h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">SMTP Host</label>
                                    <input type="text" class="form-control" name="settings[smtp_host]" value="<?= htmlspecialchars(getSystemSetting('smtp_host', '')) ?>" placeholder="smtp.gmail.com">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">SMTP Port</label>
                                    <input type="number" class="form-control" name="settings[smtp_port]" value="<?= getSystemSetting('smtp_port', 587) ?>" placeholder="587">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Encryption</label>
                                    <select class="form-select" name="settings[smtp_encryption]">
                                        <option value="tls" <?= getSystemSetting('smtp_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="ssl" <?= getSystemSetting('smtp_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">SMTP Username</label>
                                    <input type="text" class="form-control" name="settings[smtp_username]" value="<?= htmlspecialchars(getSystemSetting('smtp_username', '')) ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">SMTP Password</label>
                                    <input type="password" class="form-control" name="settings[smtp_password]" value="<?= htmlspecialchars(getSystemSetting('smtp_password', '')) ?>" placeholder="Leave blank to keep current">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="settings[email_enabled]" value="1" id="emailEnabled" <?= getSystemSetting('email_enabled', '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="emailEnabled">Enable Email Notifications</label>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27;">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Backup & Restore -->
                <div class="card" style="margin-bottom: 30px;">
                    <div class="card-header" style="background: #a11c27; color: white;">
                        <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-database"></i> Database Backup & Restore</h2>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <a href="?action=create_backup" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27;" data-confirm-action="create_backup" data-confirm-target="a new database backup" data-item-name="a new database backup">
                                    <i class="fas fa-download"></i> Create Backup Now
                                </a>
                            </div>
                        </div>
                        
                        <h5 style="color: #a11c27; margin-bottom: 15px;">Available Backups</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Backup Name</th>
                                        <th>Size</th>
                                        <th>Type</th>
                                        <th>Created By</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $backups = getBackupList($pdo, 20);
                                    if (empty($backups)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No backups available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($backups as $backup): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($backup['backup_name']) ?></td>
                                            <td><?= round($backup['backup_size'] / 1024, 2) ?> KB</td>
                                            <td><span class="badge bg-<?= $backup['backup_type'] === 'automatic' ? 'info' : 'primary' ?>"><?= ucfirst($backup['backup_type']) ?></span></td>
                                            <td><?= $backup['first_name'] ? htmlspecialchars($backup['first_name'] . ' ' . $backup['last_name']) : 'System' ?></td>
                                            <td><?= date('Y-m-d H:i:s', strtotime($backup['created_at'])) ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Restore this backup? This will replace all current data!')">
                                                    <?= getCSRFTokenField() ?>
                                                    <input type="hidden" name="restore_backup" value="1">
                                                    <input type="hidden" name="backup_id" value="<?= $backup['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-upload"></i> Restore
                                                    </button>
                                                </form>
                                                <a href="?action=delete_backup&id=<?= $backup['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger delete-btn admin-action-btn touch-friendly"
                                                   data-confirm-action="delete_backup"
                                                   data-id="<?= $backup['id'] ?>"
                                                   data-confirm-target="this backup"
                                                   data-item-name="this backup"
                                                   title="Delete this backup">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity Logs Tab -->
            <div class="tab-pane fade<?php echo (isset($_GET['tab']) && $_GET['tab'] === 'logs') ? ' show active' : ''; ?>" id="logs" role="tabpanel">
                <!-- Log Type Tabs -->
                <ul class="nav nav-tabs mb-3" id="activityLogTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $log_type === 'student' ? 'active' : '' ?>" 
                                id="student-logs-tab"
                                onclick="switchLogType('student')" 
                                type="button">
                            <i class="fas fa-user-graduate"></i> Student Logs
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $log_type === 'teacher' ? 'active' : '' ?>" 
                                id="teacher-logs-tab"
                                onclick="switchLogType('teacher')" 
                                type="button">
                            <i class="fas fa-chalkboard-teacher"></i> Teacher Logs
                        </button>
                    </li>
                </ul>
                
                <script>
                function switchLogType(type) {
                    // Save current sidebar state before navigation
                    if (typeof saveSidebarState === 'function') {
                        saveSidebarState();
                    }
                    
                    // Preserve current filters when switching - do not affect sidebar
                    const urlParams = new URLSearchParams(window.location.search);
                    urlParams.set('tab', 'logs');
                    urlParams.set('log_type', type);
                    
                    // Get current filter values from form or URL
                    const currentFilters = {
                        action_filter: document.querySelector('select[name="action_filter"]')?.value || urlParams.get('action_filter') || '',
                        course_filter: document.querySelector('select[name="course_filter"]')?.value || urlParams.get('course_filter') || '',
                        date_from: document.querySelector('input[name="date_from"]')?.value || urlParams.get('date_from') || '',
                        date_to: document.querySelector('input[name="date_to"]')?.value || urlParams.get('date_to') || ''
                    };
                    
                    // Get actor filter based on current log type
                    const currentActorFilter = type === 'student' 
                        ? (document.querySelector('select[name="student_filter"]')?.value || urlParams.get('student_filter') || '')
                        : (document.querySelector('select[name="teacher_filter"]')?.value || urlParams.get('teacher_filter') || '');
                    
                    // Clear actor-specific filters
                    urlParams.delete('student_filter');
                    urlParams.delete('teacher_filter');
                    
                    // Set appropriate actor filter for new log type
                    if (currentActorFilter) {
                        if (type === 'student') {
                            urlParams.set('student_filter', currentActorFilter);
                        } else {
                            urlParams.set('teacher_filter', currentActorFilter);
                        }
                    }
                    
                    // Preserve other filters
                    if (currentFilters.action_filter) {
                        urlParams.set('action_filter', currentFilters.action_filter);
                    } else {
                        urlParams.delete('action_filter');
                    }
                    
                    if (currentFilters.course_filter) {
                        urlParams.set('course_filter', currentFilters.course_filter);
                    } else {
                        urlParams.delete('course_filter');
                    }
                    
                    if (currentFilters.date_from) {
                        urlParams.set('date_from', currentFilters.date_from);
                    } else {
                        urlParams.delete('date_from');
                    }
                    
                    if (currentFilters.date_to) {
                        urlParams.set('date_to', currentFilters.date_to);
                    } else {
                        urlParams.delete('date_to');
                    }
                    
                    // Navigate - sidebar state will be restored on page load via restoreSidebarState()
                    window.location.href = '?' + urlParams.toString();
                }
                
                // Preserve sidebar state on filter form submissions and Clear button clicks
                document.addEventListener('DOMContentLoaded', function() {
                    // Handle filter form submissions within Activity Logs
                    const filterForms = document.querySelectorAll('#logs form[method="GET"]');
                    filterForms.forEach(form => {
                        form.addEventListener('submit', function(e) {
                            // Save sidebar state before form submission
                            if (typeof saveSidebarState === 'function') {
                                saveSidebarState();
                            }
                            // Allow form to submit normally - state will be restored on page load
                        });
                    });
                    
                    // Handle Clear button clicks within Activity Logs
                    const clearButtons = document.querySelectorAll('#logs .btn-secondary[href*="tab=logs"]');
                    clearButtons.forEach(button => {
                        button.addEventListener('click', function(e) {
                            // Save sidebar state before navigation
                            if (typeof saveSidebarState === 'function') {
                                saveSidebarState();
                            }
                            // Allow navigation to proceed - state will be restored on page load
                        });
                    });
                    
                    // Handle any other navigation links within Activity Logs section
                    // This covers pagination, sorting, or any other links that might cause page reload
                    const logsSection = document.getElementById('logs');
                    if (logsSection) {
                        logsSection.addEventListener('click', function(e) {
                            const link = e.target.closest('a[href]');
                            if (link && link.href && !link.href.startsWith('javascript:') && !link.hasAttribute('data-bs-toggle')) {
                                // Check if this is a navigation link within Activity Logs
                                const href = link.getAttribute('href');
                                if (href && (href.includes('tab=logs') || href.includes('log_type=') || (href.startsWith('?') && logsSection.contains(link)))) {
                                    // Save sidebar state before navigation
                                    if (typeof saveSidebarState === 'function') {
                                        saveSidebarState();
                                    }
                                }
                            }
                        }, true); // Use capture phase to catch all clicks
                    }
                });
                </script>
                
                <?php if ($log_type === 'student'): ?>
                    <!-- Student Logs -->
                    <div class="card">
                        <div class="card-header" style="background: #a11c27; color: white;">
                            <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-history"></i> Student Activity Logs</h2>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Monitor all student profile changes with timestamps and detailed change information.</p>
                            
                            <!-- Unified Filters -->
                            <form method="GET" class="mb-4">
                                <input type="hidden" name="tab" value="logs">
                                <input type="hidden" name="log_type" value="student">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Student</label>
                                        <select name="student_filter" class="form-select">
                                            <option value="">All Students</option>
                                            <?php foreach ($filter_students as $student): ?>
                                                <option value="<?= $student['id'] ?>" <?= ($log_filters['student_id'] == $student['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                                    <?php if ($student['student_id_number']): ?>
                                                        (<?= htmlspecialchars($student['student_id_number']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Action Type</label>
                                        <select name="action_filter" class="form-select">
                                            <option value="">All Actions</option>
                                            <?php foreach ($student_action_types as $action): ?>
                                                <option value="<?= htmlspecialchars($action['action']) ?>" <?= ($log_filters['action_type'] === $action['action']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $action['action']))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Course</label>
                                        <select name="course_filter" class="form-select">
                                            <option value="">All Courses</option>
                                            <?php foreach ($filter_courses as $course): ?>
                                                <option value="<?= $course['id'] ?>" <?= ($log_filters['course_id'] == $course['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Date From</label>
                                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($log_filters['date_from'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Date To</label>
                                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($log_filters['date_to'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="?tab=logs&log_type=student" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </form>
                            
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Student</th>
                                            <th>Student ID</th>
                                            <th>Action</th>
                                            <th>Course</th>
                                            <th>Description</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($profile_logs)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted">
                                                    <i class="fas fa-info-circle"></i> No student activity logs found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($profile_logs as $log): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= date('Y-m-d', strtotime($log['created_at'])) ?></strong><br>
                                                        <small class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['first_name'] && $log['last_name']): ?>
                                                            <strong><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong>
                                                        <?php else: ?>
                                                            <span class="text-muted">Student ID: <?= $log['entity_id'] ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($log['student_id_number'] ?? $log['entity_id']) ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action']))) ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['course_name']): ?>
                                                            <span class="text-muted"><?= htmlspecialchars($log['course_name']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div style="max-width: 400px; word-wrap: break-word;">
                                                            <?= htmlspecialchars($log['description']) ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Teacher Logs -->
                    <div class="card">
                        <div class="card-header" style="background: #a11c27; color: white;">
                            <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-history"></i> Teacher Activity Logs</h2>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Monitor all teacher actions including grade uploads, submissions, and profile updates.</p>
                            
                            <!-- Filters -->
                            <form method="GET" class="mb-4">
                                <input type="hidden" name="tab" value="logs">
                                <input type="hidden" name="log_type" value="teacher">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Teacher</label>
                                        <select name="teacher_filter" class="form-select">
                                            <option value="">All Teachers</option>
                                            <?php foreach ($filter_teachers as $teacher): ?>
                                                <option value="<?= $teacher['id'] ?>" <?= ($log_filters['teacher_id'] == $teacher['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Action Type</label>
                                        <select name="action_filter" class="form-select">
                                            <option value="">All Actions</option>
                                            <?php foreach ($teacher_action_types as $action): ?>
                                                <option value="<?= htmlspecialchars($action['action']) ?>" <?= ($log_filters['action_type'] === $action['action']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $action['action']))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Course</label>
                                        <select name="course_filter" class="form-select">
                                            <option value="">All Courses</option>
                                            <?php foreach ($filter_courses as $course): ?>
                                                <option value="<?= $course['id'] ?>" <?= ($log_filters['course_id'] == $course['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Date From</label>
                                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($log_filters['date_from'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Date To</label>
                                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($log_filters['date_to'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="?tab=logs&log_type=teacher" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </form>
                            
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Teacher</th>
                                            <th>Action</th>
                                            <th>Subject/Course</th>
                                            <th>Description</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($teacher_logs)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">
                                                    <i class="fas fa-info-circle"></i> No teacher activity logs found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($teacher_logs as $log): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= date('Y-m-d', strtotime($log['created_at'])) ?></strong><br>
                                                        <small class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['first_name'] && $log['last_name']): ?>
                                                            <strong><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong>
                                                        <?php else: ?>
                                                            <span class="text-muted">Teacher ID: <?= $log['teacher_id'] ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action']))) ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['subject_name']): ?>
                                                            <strong><?= htmlspecialchars($log['subject_name']) ?></strong>
                                                            <?php if ($log['subject_code']): ?>
                                                                <br><small class="text-muted"><?= htmlspecialchars($log['subject_code']) ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($log['course_name']): ?>
                                                                <br><small class="text-muted">Course: <?= htmlspecialchars($log['course_name']) ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div style="max-width: 400px; word-wrap: break-word;">
                                                            <?= htmlspecialchars($log['description']) ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: #a11c27; color: white;">
                    <h5 class="modal-title" style="color: white;">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="editUserForm">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <input type="hidden" name="redirect_tab" id="edit_user_redirect_tab" value="<?= isset($_GET['tab']) && $_GET['tab'] === 'users' ? 'users' : (isset($_GET['tab']) ? $_GET['tab'] : 'users') ?>">
                        
                        <!-- Profile Picture Section -->
                        <div class="mb-3 text-center">
                            <div id="profilePicturePreview" style="margin-bottom: 15px;">
                                <img id="profileImgPreview" src="" alt="Profile" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #a11c27; display: none;">
                                <div id="profileInitials" style="width: 100px; height: 100px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 2rem; margin: 0 auto;"></div>
                            </div>
                            <label class="form-label">Profile Picture (Optional)</label>
                            <input type="file" class="form-control" name="profile_picture" id="profile_picture_input" accept="image/*" onchange="previewProfilePicture(this)">
                            <small class="text-muted">Max size: <?= round(getSystemSetting('max_upload_size', 5242880) / 1024 / 1024, 0) ?>MB. Allowed: jpg, jpeg, png, gif</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" id="edit_username" required>
                            <small class="form-text text-muted">Username must be unique</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email" placeholder="user@example.com">
                            <small class="form-text text-muted">Email must be unique if provided. Used as primary identifier for login.</small>
                            <div id="email_validation_feedback" class="invalid-feedback" style="display: none;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role" id="edit_role" required>
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer" style="flex-wrap: wrap; gap: 8px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="flex: 1; min-width: 100px;">Cancel</button>
                        <button type="button" class="btn btn-info" id="editFullDataBtn" style="display: none; background: #17a2b8; border-color: #17a2b8; color: white; flex: 1; min-width: 100px;" onclick="openFullStudentEdit()">
                            <i class="fas fa-edit"></i> Edit Full Student Data
                        </button>
                        <button type="submit" name="update_user" id="updateUserBtn" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27; flex: 1; min-width: 100px;">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Comprehensive Student Data Edit Modal -->
    <div class="modal fade" id="editFullStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #a11c27; color: white;">
                    <h5 class="modal-title" style="color: white;">Edit Full Student Data</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="fullStudentEditForm">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <input type="hidden" name="user_id" id="full_edit_user_id">
                        <input type="hidden" name="update_full_student" value="1">
                        
                        <!-- Personal Information Section -->
                        <h6 class="text-primary mb-3" style="border-bottom: 2px solid #a11c27; padding-bottom: 5px;">Personal Information</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="first_name" id="full_first_name" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" name="middle_name" id="full_middle_name">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="last_name" id="full_last_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Suffix</label>
                                    <input type="text" class="form-control" name="suffix" id="full_suffix" placeholder="Jr., Sr., etc.">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Birthday</label>
                                    <input type="date" class="form-control" name="birthday" id="full_birthday">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Gender</label>
                                    <select class="form-select" name="gender" id="full_gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Nationality</label>
                                    <input type="text" class="form-control" name="nationality" id="full_nationality" value="Filipino">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone_number" id="full_phone_number">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" id="full_email" required>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information Section -->
                        <h6 class="text-primary mb-3 mt-4" style="border-bottom: 2px solid #a11c27; padding-bottom: 5px;">Academic Information</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Student ID Number</label>
                                    <input type="text" class="form-control" name="student_id_number" id="full_student_id_number">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Program</label>
                                    <input type="text" class="form-control" name="program" id="full_program">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Year Level</label>
                                    <select class="form-select" name="year_level" id="full_year_level">
                                        <option value="">Select Year Level</option>
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Section</label>
                                    <input type="text" class="form-control" name="section" id="full_section">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Educational Status</label>
                                    <select class="form-select" name="educational_status" id="full_educational_status">
                                        <option value="New Student">New Student</option>
                                        <option value="Transferee">Transferee</option>
                                        <option value="Returning Student">Returning Student</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="full_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="graduated">Graduated</option>
                            </select>
                        </div>

                        <!-- Address Information Section -->
                        <h6 class="text-primary mb-3 mt-4" style="border-bottom: 2px solid #a11c27; padding-bottom: 5px;">Address Information</h6>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" name="address" id="full_address">
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Barangay</label>
                                    <input type="text" class="form-control" name="baranggay" id="full_baranggay">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">City/Municipality</label>
                                    <input type="text" class="form-control" name="municipality" id="full_municipality">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Province</label>
                                    <input type="text" class="form-control" name="city_province" id="full_city_province">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" name="country" id="full_country" value="Philippines">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Postal Code</label>
                                    <input type="text" class="form-control" name="postal_code" id="full_postal_code">
                                </div>
                            </div>
                        </div>

                        <!-- Parents Information Section -->
                        <h6 class="text-primary mb-3 mt-4" style="border-bottom: 2px solid #a11c27; padding-bottom: 5px;">Parents Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Mother's Name</label>
                                    <input type="text" class="form-control" name="mother_name" id="full_mother_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Mother's Phone</label>
                                    <input type="text" class="form-control" name="mother_phone" id="full_mother_phone">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mother's Occupation</label>
                            <input type="text" class="form-control" name="mother_occupation" id="full_mother_occupation">
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Father's Name</label>
                                    <input type="text" class="form-control" name="father_name" id="full_father_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Father's Phone</label>
                                    <input type="text" class="form-control" name="father_phone" id="full_father_phone">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Father's Occupation</label>
                            <input type="text" class="form-control" name="father_occupation" id="full_father_occupation">
                        </div>

                        <!-- Emergency Contact Section -->
                        <h6 class="text-primary mb-3 mt-4" style="border-bottom: 2px solid #a11c27; padding-bottom: 5px;">Emergency Contact</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emergency Contact Name</label>
                                    <input type="text" class="form-control" name="emergency_name" id="full_emergency_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emergency Contact Phone</label>
                                    <input type="text" class="form-control" name="emergency_phone" id="full_emergency_phone">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Emergency Contact Address</label>
                            <input type="text" class="form-control" name="emergency_address" id="full_emergency_address">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_full_student" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27;">Update Full Student Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Confirmation Modal -->
    <div class="modal fade" id="successConfirmationModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #28a745; color: white;">
                    <h5 class="modal-title" style="color: white;">
                        <i class="fas fa-check-circle"></i> Success
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: #28a745; margin-bottom: 15px;"></i>
                    <h5 id="successModalTitle">Student Data Updated Successfully!</h5>
                    <p class="text-muted" id="successModalMessage">All student information has been updated.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="location.reload()">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- No Changes Info Modal -->
    <div class="modal fade" id="noChangesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #17a2b8; color: white;">
                    <h5 class="modal-title" style="color: white;">
                        <i class="fas fa-info-circle"></i> No Changes Detected
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-info-circle" style="font-size: 3rem; color: #17a2b8; margin-bottom: 15px;"></i>
                    <h5>No Changes Made</h5>
                    <p class="text-muted">No changes were detected in the student data. All fields remain the same.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-info" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Picture Upload Modal -->
    <div class="modal fade" id="uploadProfilePictureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #a11c27; color: white;">
                    <h5 class="modal-title" style="color: white;">Upload Profile Picture</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="upload_profile_picture" value="1">
                    <input type="hidden" name="user_id" id="upload_user_id">
                    <input type="hidden" name="redirect_tab" value="users">
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <div id="uploadPreview" style="margin-bottom: 15px;">
                                <img id="uploadImgPreview" src="" alt="Preview" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #a11c27; display: none; margin: 0 auto;">
                                <div id="uploadInitials" style="width: 150px; height: 150px; border-radius: 50%; background: #a11c27; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 3rem; margin: 0 auto;"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select Profile Picture</label>
                            <input type="file" class="form-control" name="profile_picture" id="upload_profile_picture" accept="image/*" required onchange="previewUploadPicture(this)">
                            <small class="text-muted">Max size: <?= round(getSystemSetting('max_upload_size', 5242880) / 1024 / 1024, 0) ?>MB. Allowed: jpg, jpeg, png, gif</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27;">
                            <i class="fas fa-upload"></i> Upload Picture
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="password_user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="password_username" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="change_password" required>
                                <span class="input-group-text password-toggle" onclick="togglePassword('change_password', 'change_password_icon')">
                                    <i class="fas fa-eye" id="change_password_icon"></i>
                                </span>
                            </div>
                            <div class="form-text">Password must be at least 6 characters long.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password', 'confirm_password_icon')">
                                    <i class="fas fa-eye" id="confirm_password_icon"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_password" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editSubjectForm">
                    <?php 
                    // Generate fresh token for this form - ensure it matches session
                    $editSubjectFormToken = generateCSRFToken();
                    ?>
                    <input type="hidden" name="csrf_token" id="edit_subject_csrf_token" value="<?= htmlspecialchars($editSubjectFormToken) ?>" required>
                    <div class="modal-body">
                        <input type="hidden" name="subject_id" id="edit_subject_id">
                        <div class="mb-3">
                            <label class="form-label">Course Name</label>
                            <input type="text" class="form-control" name="name" id="edit_subject_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course Code</label>
                            <input type="text" class="form-control" name="code" id="edit_subject_code" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Credit Units</label>
                            <input type="number" step="0.5" min="0.5" max="6" class="form-control" name="units" id="edit_subject_units" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" id="edit_subject_description">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_subject" class="btn btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manage Section Students Modal -->
    <div class="modal fade" id="manageSectionStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Students - <span id="modal_section_name"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>Course:</strong> <span id="modal_course_name"></span><br>
                        <strong>Year Level:</strong> <span id="modal_year_level"></span>
                    </div>
                    
                    <hr>
                    
                    <h6>Add Student to Section</h6>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="section_id" id="modal_section_id">
                        <div class="row">
                            <div class="col-md-8">
                                <select class="form-select" name="student_id" required>
                                    <option value="">Select Student...</option>
                                    <?php 
                                    $sectionStudentEligibility = getEnrolledStudentEligibilityCondition('users');
                                    $all_students = $pdo->query("
                                        SELECT id, first_name, last_name, student_id_number, email
                                        FROM users
                                        WHERE role = 'student'
                                          AND {$sectionStudentEligibility}
                                        ORDER BY last_name, first_name
                                    ")->fetchAll();
                                    foreach($all_students as $student): 
                                    ?>
                                        <option value="<?= $student['id'] ?>">
                                            <?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?> 
                                            <?php if ($student['student_id_number']): ?>
                                                (<?= htmlspecialchars($student['student_id_number']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="add_student_to_section" class="btn btn-success w-100">
                                    <i class="fas fa-user-plus"></i> Add Student
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <hr>
                    
                    <h6>Current Students in Section</h6>
                    <div id="section_students_list" class="table-responsive">
                        <p class="text-muted">Loading students...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Teacher Sections Modal -->
    <div class="modal fade" id="viewTeacherSectionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sections Handled by Teacher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><strong>Teacher:</strong></label>
                        <p id="sections_teacher_name" class="mb-0"></p>
                    </div>
                    <div id="teacher_sections_content">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Teacher Subjects Modal -->
    <div class="modal fade" id="editTeacherSubjectsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: #a11c27; color: white;">
                    <h5 class="modal-title">Assign Courses to Teacher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editTeacherSubjectsForm">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="teacher_id" id="edit_teacher_id">
                        <input type="hidden" name="original_teacher_name" id="original_teacher_name">
                        <input type="hidden" name="original_subject_ids" id="original_subject_ids">
                        <input type="hidden" name="redirect_tab" id="edit_teacher_redirect_tab" value="<?= isset($_GET['tab']) && $_GET['tab'] === 'users' ? 'users' : (isset($_GET['tab']) ? $_GET['tab'] : 'teachers') ?>">
                        <div class="mb-3">
                            <label class="form-label">Teacher Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_teacher_name" name="teacher_name" required>
                            <small class="form-text text-muted">You can edit the teacher's name here</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign Courses</label>
                            <div class="subject-checkbox-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                                <?php
                                $all_subjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
                                if (empty($all_subjects)):
                                ?>
                                    <p class="text-muted mb-0">No subjects available</p>
                                <?php else: ?>
                                    <div class="mb-2" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid #ddd; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                        <span style="font-weight: 600; color: #333;">Select Subjects</span>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllSubjects('edit_teacher_subjects')" style="font-size: 0.8rem; padding: 4px 10px; white-space: nowrap;">Select All</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllSubjects('edit_teacher_subjects')" style="font-size: 0.8rem; padding: 4px 10px; white-space: nowrap;">Deselect All</button>
                                        </div>
                                    </div>
                                    <div class="subject-checkbox-list" id="edit_teacher_subjects">
                                        <?php foreach($all_subjects as $subj): ?>
                                            <div class="form-check subject-checkbox-item" style="padding: 8px; margin-bottom: 5px; background: white; border-radius: 5px; border: 1px solid #e0e0e0; transition: all 0.2s;">
                                                <input class="form-check-input edit-subject-checkbox" type="checkbox" name="subject_ids[]" value="<?= $subj['id'] ?>" id="edit_subject_<?= $subj['id'] ?>" style="cursor: pointer;">
                                                <label class="form-check-label" for="edit_subject_<?= $subj['id'] ?>" style="cursor: pointer; width: 100%; margin-left: 8px;">
                                                    <strong><?= htmlspecialchars($subj['name']) ?></strong>
                                                    <span class="text-muted" style="font-size: 0.9rem;">(<?= htmlspecialchars($subj['code']) ?>)</span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <small class="form-text text-muted">Check the boxes to assign subjects to this teacher</small>
                        </div>
                    </div>
                    <div class="modal-footer" style="flex-wrap: wrap; gap: 8px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="flex: 1; min-width: 100px;">Cancel</button>
                        <button type="button" id="updateTeacherSubjectsBtn" class="btn btn-primary" style="background: #a11c27; border-color: #a11c27; flex: 1; min-width: 100px;">Update Courses</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Review Application Modal -->
    <div class="modal fade" id="reviewApplicationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewModalTitle">Review Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] . '?tab=applications') ?>" id="reviewApplicationForm">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="review_application" value="1">
                    <div class="modal-body">
                        <input type="hidden" name="application_id" id="review_application_id" value="" required>
                        <input type="hidden" name="action" id="review_action" value="" required>
                        <div class="mb-3">
                            <label class="form-label">Student Name</label>
                            <input type="text" class="form-control" id="review_student_name" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Add any notes about this review..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <span id="review_action_text"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="review_application" value="1" class="btn" id="review_submit_btn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Application Modal -->
    <div class="modal fade" id="viewApplicationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Application Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="view_application_content">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="saveApplicationChangesBtn" onclick="saveApplicationChanges()">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div class="modal fade" id="editCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editCourseForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] . '?tab=' . ($_GET['tab'] ?? 'courses')) ?>" onsubmit="return validateEditCourseForm(this)">
                    <?php 
                    // Generate fresh token for this form - ensure it matches session
                    $editFormToken = generateCSRFToken();
                    ?>
                    <input type="hidden" name="csrf_token" id="edit_course_csrf_token" value="<?= htmlspecialchars($editFormToken) ?>" required>
                    <div class="modal-body">
                        <input type="hidden" name="course_id" id="edit_course_id">
                        <input type="hidden" name="update_course" value="1">
                        <div class="mb-3">
                            <label class="form-label">Program Code</label>
                            <input type="text" class="form-control" name="code" id="edit_course_code" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Program Name</label>
                            <input type="text" class="form-control" name="name" id="edit_course_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (Years)</label>
                            <input type="number" class="form-control" name="duration_years" id="edit_course_duration" min="1" max="10" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_course_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_course_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_course" class="btn btn-primary" onclick="return validateEditCourseForm(this.form)">Update Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Section</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="section_id" id="edit_section_id">
                        <div class="mb-3">
                            <label class="form-label">Course</label>
                            <select class="form-select" name="course_id" id="edit_section_course_id" required>
                                <option value="">Select Course</option>
                                <?php foreach($courses as $course): ?>
                                    <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Section Name</label>
                                    <input type="text" class="form-control" name="section_name" id="edit_section_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Year Level</label>
                                    <select class="form-select" name="year_level" id="edit_section_year_level" required>
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Academic Year</label>
                                    <input type="text" class="form-control" name="academic_year" id="edit_section_academic_year" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Semester</label>
                                    <select class="form-select" name="semester" id="edit_section_semester" required>
                                        <option value="1st">1st Semester</option>
                                        <option value="2nd">2nd Semester</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Teacher (Optional)</label>
                                    <select class="form-select" name="teacher_id" id="edit_section_teacher_id">
                                        <option value="">Not Assigned</option>
                                        <?php 
                                        $all_teachers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY first_name, last_name")->fetchAll();
                                        foreach($all_teachers as $teacher): 
                                        ?>
                                            <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Max Students</label>
                                    <input type="number" class="form-control" name="max_students" id="edit_section_max_students" min="1" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_section_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_section" class="btn btn-primary">Update Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div class="modal fade" id="editScheduleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="schedule_id" id="edit_schedule_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Section <span class="text-danger">*</span></label>
                                <select class="form-select" name="section_id" id="edit_schedule_section_id" required>
                                    <option value="">Select Section</option>
                                    <?php foreach($sections as $section): ?>
                                        <option value="<?= $section['id'] ?>">
                                            <?= htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'] . ' (' . $section['year_level'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select" name="subject_id" id="edit_schedule_subject_id" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach($all_subjects as $subject): ?>
                                        <option value="<?= $subject['id'] ?>">
                                            <?= htmlspecialchars($subject['code'] . ' - ' . $subject['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Day of Week <span class="text-danger">*</span></label>
                                <select class="form-select" name="day_of_week" id="edit_schedule_day_of_week" required>
                                    <option value="Monday">Monday</option>
                                    <option value="Tuesday">Tuesday</option>
                                    <option value="Wednesday">Wednesday</option>
                                    <option value="Thursday">Thursday</option>
                                    <option value="Friday">Friday</option>
                                    <option value="Saturday">Saturday</option>
                                    <option value="Sunday">Sunday</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="start_time" id="edit_schedule_start_time" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">End Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="end_time" id="edit_schedule_end_time" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teacher (Optional)</label>
                                <select class="form-select" name="teacher_id" id="edit_schedule_teacher_id">
                                    <option value="">Select Teacher</option>
                                    <?php 
                                    $all_teachers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY first_name, last_name")->fetchAll();
                                    foreach($all_teachers as $teacher): 
                                    ?>
                                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Room</label>
                                <input type="text" class="form-control" name="room" id="edit_schedule_room" placeholder="e.g., Room 101">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="academic_year" id="edit_schedule_academic_year" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Semester</label>
                                <select class="form-select" name="semester" id="edit_schedule_semester">
                                    <option value="1st">1st Semester</option>
                                    <option value="2nd">2nd Semester</option>
                                    <option value="Summer">Summer</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_schedule_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_schedule" class="btn btn-primary">Update Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="actionConfirmOverlay" class="confirmation-modal-overlay" aria-hidden="true" style="display: none !important;">
        <div class="confirmation-modal" role="dialog" aria-modal="true" aria-labelledby="actionConfirmTitle">
            <div class="confirmation-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 id="actionConfirmTitle">Please Confirm</h3>
            <p id="actionConfirmMessage">Are you sure you want to continue?</p>
            <p id="actionConfirmTarget" class="confirmation-modal-target"></p>
            <div class="confirmation-modal-actions">
                <button type="button" class="btn btn-danger" id="actionConfirmProceed">Yes, Continue</button>
                <button type="button" class="btn btn-light" id="actionConfirmCancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Edit Enrollment Period Modal -->
    <div class="modal fade" id="editEnrollmentPeriodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Enrollment Period</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="period_id" id="edit_period_id">
                        <div class="mb-3">
                            <label class="form-label">Program <span class="text-danger">*</span></label>
                            <select class="form-select" name="course_id" id="edit_period_course_id" required>
                                <option value="">Select Program</option>
                                <?php foreach($courses as $course): ?>
                                    <option value="<?= $course['id'] ?>">
                                        <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="academic_year" id="edit_period_academic_year" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" id="edit_period_semester" required>
                                <option value="1st">1st Semester</option>
                                <option value="2nd">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="start_date" id="edit_period_start_date" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="end_date" id="edit_period_end_date" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_period_status">
                                <option value="scheduled">Scheduled</option>
                                <option value="active">Active</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="auto_close" id="edit_period_auto_close">
                                <label class="form-check-label" for="edit_period_auto_close">Auto-Close</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_enrollment_period" class="btn btn-primary">Update Period</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approve Enrollment Request Modal -->
    <div class="modal fade" id="approveEnrollmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Enrollment Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="request_id" id="approve_enrollment_request_id">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" class="form-control" id="approve_student_name" readonly>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requirements_verified" id="approve_requirements_verified" required>
                                <label class="form-check-label" for="approve_requirements_verified">
                                    I verify that all requirements are met
                                </label>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Upon approval, the student will be automatically enrolled in all courses for the specified semester.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="approve_enrollment_request" class="btn btn-success">Approve Enrollment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Enrollment Request Modal -->
    <div class="modal fade" id="rejectEnrollmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Enrollment Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="request_id" id="reject_request_id">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" class="form-control" id="reject_student_name" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rejection Reason</label>
                            <textarea class="form-control" name="rejection_reason" rows="3" placeholder="Enter reason for rejection..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reject_enrollment_request" class="btn btn-danger">Reject Enrollment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Prevent body expansion when modals open
        document.addEventListener('DOMContentLoaded', function() {
            // Override Bootstrap's padding adjustment
            const originalGetScrollbarWidth = bootstrap.Modal.prototype._getScrollbarWidth;
            bootstrap.Modal.prototype._getScrollbarWidth = function() {
                return 0;
            };
            
            // Prevent padding-right from being added to body
            document.addEventListener('show.bs.modal', function() {
                document.body.style.paddingRight = '0';
            });
            
            document.addEventListener('hidden.bs.modal', function() {
                document.body.style.paddingRight = '';
            });
        });
        // Initialize confirmation modal elements - ensure it's hidden on load
        const actionConfirmOverlay = document.getElementById('actionConfirmOverlay');
        const actionConfirmTitleEl = document.getElementById('actionConfirmTitle');
        const actionConfirmMessageEl = document.getElementById('actionConfirmMessage');
        const actionConfirmTargetEl = document.getElementById('actionConfirmTarget');
        const actionConfirmProceedBtn = document.getElementById('actionConfirmProceed');
        const actionConfirmCancelBtn = document.getElementById('actionConfirmCancel');
        const defaultConfirmButtonLabel = actionConfirmProceedBtn ? actionConfirmProceedBtn.textContent : 'Yes, Continue';
        const defaultCancelButtonLabel = actionConfirmCancelBtn ? actionConfirmCancelBtn.textContent : 'Cancel';
        let actionConfirmResolver = null;
        let pendingConfirmTrigger = null;
        
        // IMMEDIATELY ensure modal is hidden - runs before any other code
        if (actionConfirmOverlay) {
            actionConfirmOverlay.classList.remove('show');
            actionConfirmOverlay.setAttribute('aria-hidden', 'true');
            actionConfirmOverlay.style.display = 'none';
            document.body.classList.remove('confirmation-modal-open');
        }

        function openActionConfirm({ title, message, targetLabel, confirmLabel, cancelLabel, showCancel = true } = {}) {
            if (!actionConfirmOverlay) {
                return Promise.resolve(true);
            }
            
            // Safety check: ensure this is not being called during page load
            // Only allow if there's a pending trigger (user-initiated action)
            if (!pendingConfirmTrigger && document.readyState === 'loading') {
                console.warn('openActionConfirm called during page load - ignoring');
                return Promise.resolve(false);
            }

            if (title && actionConfirmTitleEl) {
                actionConfirmTitleEl.textContent = title;
            }
            if (actionConfirmMessageEl) {
                actionConfirmMessageEl.textContent = message || 'Are you sure you want to continue?';
            }
            if (actionConfirmTargetEl) {
                actionConfirmTargetEl.textContent = targetLabel || '';
            }
            if (actionConfirmProceedBtn) {
                actionConfirmProceedBtn.textContent = confirmLabel || defaultConfirmButtonLabel;
            }
            if (actionConfirmCancelBtn) {
                actionConfirmCancelBtn.textContent = cancelLabel || defaultCancelButtonLabel;
                actionConfirmCancelBtn.style.display = showCancel ? '' : 'none';
            }

            // Only show modal if explicitly called by user action
            actionConfirmOverlay.style.display = 'flex';
            actionConfirmOverlay.classList.add('show');
            actionConfirmOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('confirmation-modal-open');
            
            // Focus management: focus the proceed button for accessibility
            // Use setTimeout to ensure the modal is visible before focusing
            setTimeout(() => {
                if (actionConfirmProceedBtn) {
                    actionConfirmProceedBtn.focus();
                }
            }, 100);
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';

            return new Promise((resolve) => {
                actionConfirmResolver = resolve;
            });
        }

        function closeActionConfirm(result) {
            if (!actionConfirmOverlay) {
                if (actionConfirmResolver) {
                    actionConfirmResolver(result);
                    actionConfirmResolver = null;
                }
                return;
            }

            actionConfirmOverlay.classList.remove('show');
            actionConfirmOverlay.setAttribute('aria-hidden', 'true');
            actionConfirmOverlay.style.display = 'none'; // Explicitly hide
            actionConfirmOverlay.style.visibility = 'hidden';
            actionConfirmOverlay.style.opacity = '0';
            document.body.classList.remove('confirmation-modal-open');
            document.body.style.overflow = '';
            
            // Clear pending trigger after closing
            pendingConfirmTrigger = null;
            
            // Return focus to the trigger element if available
            // Use setTimeout to ensure modal is fully hidden before focusing
            if (pendingConfirmTrigger && typeof pendingConfirmTrigger.focus === 'function') {
                setTimeout(() => {
                    try {
                        pendingConfirmTrigger.focus();
                    } catch (e) {
                        // If focus fails, focus body as fallback
                        document.body.focus();
                    }
                }, 100);
            }
            
            actionConfirmProceedBtn.textContent = defaultConfirmButtonLabel;
            if (actionConfirmCancelBtn) {
                actionConfirmCancelBtn.textContent = defaultCancelButtonLabel;
                actionConfirmCancelBtn.style.display = '';
            }

            if (actionConfirmResolver) {
                actionConfirmResolver(result);
                actionConfirmResolver = null;
            }
        }

        function executeConfirmedAction(trigger) {
            if (!trigger) {
                console.error('executeConfirmedAction: trigger is null or undefined');
                return;
            }

            const tagName = trigger.tagName.toLowerCase();
            const modalTarget = trigger.getAttribute('data-modal-target');

            if (modalTarget) {
                const modalElement = document.querySelector(modalTarget);
                if (modalElement && window.bootstrap && bootstrap.Modal) {
                    trigger.dataset.confirmBypass = 'true';
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
                    modalInstance.show(trigger);
                    setTimeout(() => delete trigger.dataset.confirmBypass, 0);
                }
                return;
            }

            if (tagName === 'a') {
                const href = trigger.getAttribute('href');
                if (!href || href === '#') {
                    console.error('executeConfirmedAction: Invalid href for link element', trigger);
                    showErrorMessage('Invalid action URL. Please refresh the page and try again.');
                    return;
                }
                
                // Extract action and ID for logging
                const itemId = trigger.getAttribute('data-id') || 
                             (href.match(/[?&]id=([^&]+)/) ? href.match(/[?&]id=([^&]+)/)[1] : 'unknown');
                const action = href.match(/[?&]action=([^&]+)/) ? href.match(/[?&]action=([^&]+)/)[1] : 'unknown';
                
                console.log(`Executing delete action: ${action} for ID: ${itemId}`);
                
                // Show loading state on button
                setButtonLoading(trigger, true);
                
                // For delete actions, try AJAX first for better UX, fallback to redirect
                if (action.includes('delete') && typeof fetch !== 'undefined') {
                    // Try AJAX deletion for better UX
                    fetch(href, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    })
                    .then(response => {
                        setButtonLoading(trigger, false);
                        
                        // If response is a redirect (status 302/301), follow it
                        if (response.redirected || response.status === 302 || response.status === 301) {
                            window.location.href = response.url || href;
                            return;
                        }
                        
                        // Try to parse as JSON
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                // Not JSON, likely HTML redirect - follow it
                                window.location.href = href;
                                return null;
                            }
                        });
                    })
                    .then(data => {
                        if (data && data.success !== undefined) {
                            // AJAX response received
                            if (data.success) {
                                // Remove the row from UI
                                const row = trigger.closest('tr');
                                if (row) {
                                    row.style.transition = 'opacity 0.3s ease';
                                    row.style.opacity = '0';
                                    setTimeout(() => {
                                        row.remove();
                                        showSuccessMessage(data.message || 'Item deleted successfully!');
                                    }, 300);
                                } else {
                                    showSuccessMessage(data.message || 'Item deleted successfully!');
                                    // Reload after a short delay to refresh the list
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1500);
                                }
                            } else {
                                showErrorMessage(data.message || 'Failed to delete item. Please try again.');
                            }
                        }
                    })
                    .catch(error => {
                        console.error('AJAX delete error:', error);
                        setButtonLoading(trigger, false);
                        // Fallback to regular redirect
                        window.location.href = href;
                    });
                } else {
                    // Fallback to regular redirect for non-AJAX or non-delete actions
                    const target = trigger.getAttribute('target');
                    if (target && target !== '_self') {
                        window.open(href, target);
                    } else {
                        window.location.href = href;
                    }
                }
                return;
            }

            if ((tagName === 'button' || tagName === 'input') && trigger.type === 'submit' && trigger.form) {
                trigger.dataset.confirmBypass = 'true';
                if (typeof trigger.form.requestSubmit === 'function') {
                    trigger.form.requestSubmit(trigger);
                } else {
                    trigger.form.submit();
                }
                setTimeout(() => delete trigger.dataset.confirmBypass, 0);
                return;
            }

            trigger.dataset.confirmBypass = 'true';
            trigger.click();
            setTimeout(() => delete trigger.dataset.confirmBypass, 0);
        }

        if (actionConfirmProceedBtn) {
            actionConfirmProceedBtn.addEventListener('click', function() {
                closeActionConfirm(true);
            });
        }

        if (actionConfirmCancelBtn) {
            actionConfirmCancelBtn.addEventListener('click', function() {
                closeActionConfirm(false);
            });
        }

        if (actionConfirmOverlay) {
            actionConfirmOverlay.addEventListener('click', function(event) {
                if (event.target === actionConfirmOverlay) {
                    closeActionConfirm(false);
                }
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && actionConfirmOverlay && actionConfirmOverlay.classList.contains('show')) {
                closeActionConfirm(false);
            }
        });
        // Ensure confirmation modal is hidden on page load (prevent auto-show)
        // This runs IMMEDIATELY, not waiting for DOMContentLoaded
        (function() {
            const overlay = document.getElementById('actionConfirmOverlay');
            if (overlay) {
                overlay.classList.remove('show');
                overlay.setAttribute('aria-hidden', 'true');
                overlay.style.display = 'none';
                overlay.style.visibility = 'hidden';
                overlay.style.opacity = '0';
            }
            document.body.classList.remove('confirmation-modal-open');
            document.body.style.overflow = '';
        })();
        
        // Also ensure on DOMContentLoaded and after a short delay
        document.addEventListener('DOMContentLoaded', function() {
            // Force close confirmation modal if it's somehow open
            if (actionConfirmOverlay) {
                actionConfirmOverlay.classList.remove('show');
                actionConfirmOverlay.setAttribute('aria-hidden', 'true');
                actionConfirmOverlay.style.display = 'none';
                actionConfirmOverlay.style.visibility = 'hidden';
                actionConfirmOverlay.style.opacity = '0';
                document.body.classList.remove('confirmation-modal-open');
                document.body.style.overflow = '';
            }
            
            // Double-check after a short delay to catch any delayed triggers
            setTimeout(function() {
                if (actionConfirmOverlay && actionConfirmOverlay.classList.contains('show')) {
                    actionConfirmOverlay.classList.remove('show');
                    actionConfirmOverlay.setAttribute('aria-hidden', 'true');
                    actionConfirmOverlay.style.display = 'none';
                    actionConfirmOverlay.style.visibility = 'hidden';
                    actionConfirmOverlay.style.opacity = '0';
                    document.body.classList.remove('confirmation-modal-open');
                    document.body.style.overflow = '';
                }
            }, 100);
        });
        
        // Additional safety: prevent modal from showing on window load
        window.addEventListener('load', function() {
            if (actionConfirmOverlay) {
                actionConfirmOverlay.classList.remove('show');
                actionConfirmOverlay.setAttribute('aria-hidden', 'true');
                actionConfirmOverlay.style.display = 'none';
                actionConfirmOverlay.style.visibility = 'hidden';
                actionConfirmOverlay.style.opacity = '0';
                document.body.classList.remove('confirmation-modal-open');
                document.body.style.overflow = '';
            }
        });
        
        // MutationObserver to catch any unexpected changes to the modal during page load
        if (actionConfirmOverlay && typeof MutationObserver !== 'undefined') {
            let isPageLoading = true;
            let loadingCheckTimeout = null;
            
            // Mark page as loaded after a short delay
            window.addEventListener('load', function() {
                setTimeout(function() {
                    isPageLoading = false;
                }, 500);
            });
            
            const modalObserver = new MutationObserver(function(mutations) {
                // Only intervene during page load or if there's no user trigger
                if (actionConfirmOverlay.classList.contains('show') && !pendingConfirmTrigger) {
                    if (isPageLoading || document.readyState === 'loading') {
                        // Page is still loading - hide modal immediately
                        actionConfirmOverlay.classList.remove('show');
                        actionConfirmOverlay.setAttribute('aria-hidden', 'true');
                        actionConfirmOverlay.style.display = 'none';
                        actionConfirmOverlay.style.visibility = 'hidden';
                        actionConfirmOverlay.style.opacity = '0';
                        document.body.classList.remove('confirmation-modal-open');
                        document.body.style.overflow = '';
                    }
                }
            });
            
            modalObserver.observe(actionConfirmOverlay, {
                attributes: true,
                attributeFilter: ['class', 'style', 'aria-hidden']
            });
        }
        
        // Tab navigation is handled by PHP via URL parameters
        document.addEventListener('DOMContentLoaded', function() {

            // Edit User Modal
            var editUserModal = document.getElementById('editUserModal')
            var originalUsername = ''
            var originalEmail = ''
            
            editUserModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var userId = button.getAttribute('data-id')
                var username = button.getAttribute('data-username')
                var firstName = button.getAttribute('data-firstname')
                var lastName = button.getAttribute('data-lastname')
                var email = button.getAttribute('data-email') || ''
                var role = button.getAttribute('data-role')
                var profile = button.getAttribute('data-profile')
                
                var modal = this
                modal.querySelector('#edit_user_id').value = userId
                modal.querySelector('#edit_username').value = username
                modal.querySelector('#edit_first_name').value = firstName
                modal.querySelector('#edit_last_name').value = lastName
                modal.querySelector('#edit_email').value = email
                modal.querySelector('#edit_role').value = role
                
                // Set redirect_tab based on current URL tab parameter
                var urlParams = new URLSearchParams(window.location.search)
                var currentTab = urlParams.get('tab') || 'users'
                modal.querySelector('#edit_user_redirect_tab').value = currentTab
                
                // Store original values for validation
                originalUsername = username
                originalEmail = email
                
                // Clear validation feedback
                var emailFeedback = document.getElementById('email_validation_feedback')
                if (emailFeedback) {
                    emailFeedback.style.display = 'none'
                    emailFeedback.textContent = ''
                }
                var emailInput = modal.querySelector('#edit_email')
                if (emailInput) {
                    emailInput.classList.remove('is-invalid', 'is-valid')
                }
                
                // Update profile picture preview
                var imgPreview = modal.querySelector('#profileImgPreview')
                var initialsDiv = modal.querySelector('#profileInitials')
                if (profile) {
                    // Profile picture already includes 'uploads/profiles/' path from database
                    imgPreview.src = assetsBasePath + '/' + profile
                    imgPreview.style.display = 'block'
                    initialsDiv.style.display = 'none'
                } else {
                    imgPreview.style.display = 'none'
                    initialsDiv.style.display = 'flex'
                    initialsDiv.textContent = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase()
                }
                
                // Show/hide "Edit Full Student Data" button based on role
                var editFullDataBtn = document.getElementById('editFullDataBtn')
                if (role === 'student') {
                    editFullDataBtn.style.display = 'inline-block'
                    editFullDataBtn.setAttribute('data-user-id', userId)
                } else {
                    editFullDataBtn.style.display = 'none'
                }
            })
            
            // Real-time email validation
            var emailValidationTimeout
            var emailValidationHandler = function() {
                var emailInput = document.getElementById('edit_email')
                if (!emailInput) return
                
                emailInput.addEventListener('input', function() {
                    var email = this.value.trim()
                    var userId = document.getElementById('edit_user_id').value
                    var feedbackDiv = document.getElementById('email_validation_feedback')
                    var currentOriginalEmail = document.getElementById('edit_user_id').getAttribute('data-original-email') || ''
                    
                    // Clear previous timeout
                    clearTimeout(emailValidationTimeout)
                    
                    // Remove previous validation classes
                    this.classList.remove('is-invalid', 'is-valid')
                    if (feedbackDiv) {
                        feedbackDiv.style.display = 'none'
                        feedbackDiv.textContent = ''
                    }
                    
                    // Only validate if email is provided and different from original
                    if (email && email !== currentOriginalEmail) {
                        // Validate email format
                        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
                        if (!emailRegex.test(email)) {
                            this.classList.add('is-invalid')
                            if (feedbackDiv) {
                                feedbackDiv.textContent = 'Invalid email format'
                                feedbackDiv.style.display = 'block'
                            }
                            return
                        }
                        
                        // Debounce API call
                        emailValidationTimeout = setTimeout(function() {
                            // Check email uniqueness via AJAX
                            fetch('?action=check_email&email=' + encodeURIComponent(email) + '&user_id=' + userId, {
                                method: 'GET',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                var emailInputEl = document.getElementById('edit_email')
                                var feedbackDivEl = document.getElementById('email_validation_feedback')
                                if (data.exists) {
                                    if (emailInputEl) emailInputEl.classList.add('is-invalid')
                                    if (feedbackDivEl) {
                                        feedbackDivEl.textContent = 'Email already exists! Used by: ' + (data.user_info || 'another user')
                                        feedbackDivEl.style.display = 'block'
                                    }
                                } else {
                                    if (emailInputEl) emailInputEl.classList.add('is-valid')
                                    if (feedbackDivEl) {
                                        feedbackDivEl.style.display = 'none'
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Email validation error:', error)
                            })
                        }, 500) // 500ms debounce
                    } else if (email === currentOriginalEmail) {
                        // Reset to neutral state if back to original
                        this.classList.remove('is-invalid', 'is-valid')
                        if (feedbackDiv) {
                            feedbackDiv.style.display = 'none'
                        }
                    }
                })
            }
            
            // Set up email validation when modal is shown
            editUserModal.addEventListener('shown.bs.modal', emailValidationHandler)
            
            // Handle form submission with validation
            var editUserForm = document.getElementById('editUserForm')
            var updateUserBtn = document.getElementById('updateUserBtn')
            
            if (editUserForm && updateUserBtn) {
                editUserForm.addEventListener('submit', function(e) {
                    var username = document.getElementById('edit_username').value.trim()
                    var email = document.getElementById('edit_email').value.trim()
                    var userId = document.getElementById('edit_user_id').value
                    
                    // Check if email is invalid
                    if (email && document.getElementById('edit_email').classList.contains('is-invalid')) {
                        e.preventDefault()
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Invalid Email',
                                text: 'The email address is already in use. Please choose a different email.',
                                confirmButtonColor: '#a11c27'
                            })
                        } else {
                            alert('The email address is already in use. Please choose a different email.')
                        }
                        return false
                    }
                    
                    // Validate email format if provided
                    if (email) {
                        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
                        if (!emailRegex.test(email)) {
                            e.preventDefault()
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Invalid Email Format',
                                    text: 'Please enter a valid email address.',
                                    confirmButtonColor: '#a11c27'
                                })
                            } else {
                                alert('Please enter a valid email address.')
                            }
                            return false
                        }
                    }
                    
                    // Check if username changed and validate uniqueness
                    if (username !== originalUsername) {
                        // Username uniqueness will be checked server-side
                        // Client-side check can be added here if needed
                    }
                    
                    // Show confirmation if email or username changed
                    if (email !== originalEmail || username !== originalUsername) {
                        var changeMsg = []
                        if (email !== originalEmail) {
                            changeMsg.push('email')
                        }
                        if (username !== originalUsername) {
                            changeMsg.push('username')
                        }
                        
                        if (changeMsg.length > 0 && typeof Swal !== 'undefined') {
                            e.preventDefault()
                            Swal.fire({
                                icon: 'question',
                                title: 'Confirm Update',
                                text: 'You are updating the ' + changeMsg.join(' and ') + '. Are you sure you want to continue?',
                                showCancelButton: true,
                                confirmButtonColor: '#a11c27',
                                cancelButtonColor: '#6c757d',
                                confirmButtonText: 'Yes, Update',
                                cancelButtonText: 'Cancel'
                            }).then(function(result) {
                                if (result.isConfirmed) {
                                    editUserForm.submit()
                                }
                            })
                            return false
                        }
                    }
                })
            }
            
            // Handle basic edit user form submission
            
            // Handle full student edit form submission
            var fullStudentEditForm = document.getElementById('fullStudentEditForm')
            if (fullStudentEditForm) {
                fullStudentEditForm.addEventListener('submit', function(e) {
                    e.preventDefault()
                    
                    var formData = new FormData(this)
                    formData.append('update_full_student', '1')
                    
                    // Show loading state
                    var submitBtn = this.querySelector('button[type="submit"]')
                    var originalText = submitBtn.innerHTML
                    submitBtn.disabled = true
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...'
                    
                    fetch('', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Close the edit modal
                            var editModal = bootstrap.Modal.getInstance(document.getElementById('editFullStudentModal'))
                            if (editModal) editModal.hide()
                            
                            // Show success confirmation modal
                            var successModal = new bootstrap.Modal(document.getElementById('successConfirmationModal'))
                            // Update modal message for student update
                            var titleEl = document.getElementById('successModalTitle')
                            var messageEl = document.getElementById('successModalMessage')
                            if (titleEl) titleEl.textContent = 'Student Data Updated Successfully!'
                            if (messageEl) messageEl.textContent = 'All student information has been updated.'
                            successModal.show()
                        } else if (data.no_changes) {
                            // Show no changes modal
                            var noChangesModal = new bootstrap.Modal(document.getElementById('noChangesModal'))
                            noChangesModal.show()
                            submitBtn.disabled = false
                            submitBtn.innerHTML = originalText
                        } else {
                            alert('Error: ' + (data.message || 'Failed to update student data'))
                            submitBtn.disabled = false
                            submitBtn.innerHTML = originalText
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error)
                        alert('An error occurred while updating student data. Please try again.')
                        submitBtn.disabled = false
                        submitBtn.innerHTML = originalText
                    })
                })
            }
            
            // Function to open full student edit modal
            window.openFullStudentEdit = function() {
                var userId = document.getElementById('editFullDataBtn').getAttribute('data-user-id')
                if (!userId) return
                
                // Fetch student data via AJAX
                fetch('?action=get_student_full_data&user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            var student = data.student
                            // Populate all fields
                            document.getElementById('full_edit_user_id').value = student.id
                            document.getElementById('full_first_name').value = student.first_name || ''
                            document.getElementById('full_middle_name').value = student.middle_name || ''
                            document.getElementById('full_last_name').value = student.last_name || ''
                            document.getElementById('full_suffix').value = student.suffix || ''
                            document.getElementById('full_birthday').value = student.birthday || ''
                            document.getElementById('full_gender').value = student.gender || ''
                            document.getElementById('full_nationality').value = student.nationality || 'Filipino'
                            document.getElementById('full_phone_number').value = student.phone_number || ''
                            document.getElementById('full_email').value = student.email || ''
                            document.getElementById('full_student_id_number').value = student.student_id_number || ''
                            document.getElementById('full_program').value = student.program || ''
                            document.getElementById('full_year_level').value = student.year_level || ''
                            document.getElementById('full_section').value = student.section || ''
                            document.getElementById('full_educational_status').value = student.educational_status || 'New Student'
                            document.getElementById('full_status').value = student.status || 'active'
                            document.getElementById('full_address').value = student.address || ''
                            document.getElementById('full_baranggay').value = student.baranggay || ''
                            document.getElementById('full_municipality').value = student.municipality || ''
                            document.getElementById('full_city_province').value = student.city_province || ''
                            document.getElementById('full_country').value = student.country || 'Philippines'
                            document.getElementById('full_postal_code').value = student.postal_code || ''
                            document.getElementById('full_mother_name').value = student.mother_name || ''
                            document.getElementById('full_mother_phone').value = student.mother_phone || ''
                            document.getElementById('full_mother_occupation').value = student.mother_occupation || ''
                            document.getElementById('full_father_name').value = student.father_name || ''
                            document.getElementById('full_father_phone').value = student.father_phone || ''
                            document.getElementById('full_father_occupation').value = student.father_occupation || ''
                            document.getElementById('full_emergency_name').value = student.emergency_name || ''
                            document.getElementById('full_emergency_phone').value = student.emergency_phone || ''
                            document.getElementById('full_emergency_address').value = student.emergency_address || ''
                            
                            // Close the basic edit modal and open the comprehensive one
                            var basicModal = bootstrap.Modal.getInstance(document.getElementById('editUserModal'))
                            if (basicModal) basicModal.hide()
                            
                            var fullModal = new bootstrap.Modal(document.getElementById('editFullStudentModal'))
                            fullModal.show()
                        } else {
                            alert('Error loading student data: ' + (data.message || 'Unknown error'))
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error)
                        alert('Error loading student data. Please try again.')
                    })
            }

            // Change Password Modal
            var changePasswordModal = document.getElementById('changePasswordModal')
            changePasswordModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var userId = button.getAttribute('data-id')
                var username = button.getAttribute('data-username')
                
                var modal = this
                modal.querySelector('#password_user_id').value = userId
                modal.querySelector('#password_username').value = username
            })

            // View Teacher Sections Modal
            var viewTeacherSectionsModal = document.getElementById('viewTeacherSectionsModal')
            viewTeacherSectionsModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var teacherId = button.getAttribute('data-teacher-id')
                var teacherName = button.getAttribute('data-teacher-name')
                
                var modal = this
                modal.querySelector('#sections_teacher_name').textContent = teacherName
                
                // Fetch sections via AJAX
                var contentDiv = modal.querySelector('#teacher_sections_content')
                contentDiv.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading sections...</div>'
                
                fetch('?action=get_teacher_sections&teacher_id=' + teacherId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.sections && data.sections.length > 0) {
                            var html = '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Classroom Name</th><th>Section</th><th>Year Level</th><th>Program</th><th>Academic Year</th><th>Semester</th><th>Status</th></tr></thead><tbody>'
                            data.sections.forEach(function(section) {
                                html += '<tr>'
                                html += '<td>' + (section.name || 'N/A') + '</td>'
                                html += '<td>' + (section.section || 'N/A') + '</td>'
                                html += '<td>' + (section.year_level || 'N/A') + '</td>'
                                html += '<td>' + (section.program || 'N/A') + '</td>'
                                html += '<td>' + (section.academic_year || 'N/A') + '</td>'
                                html += '<td>' + (section.semester || 'N/A') + '</td>'
                                html += '<td><span class="badge bg-' + (section.status === 'active' ? 'success' : 'secondary') + '">' + (section.status || 'N/A') + '</span></td>'
                                html += '</tr>'
                            })
                            html += '</tbody></table></div>'
                            contentDiv.innerHTML = html
                        } else {
                            contentDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> This teacher is not assigned to any sections yet.</div>'
                        }
                    })
                    .catch(error => {
                        contentDiv.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Error loading sections: ' + error + '</div>'
                    })
            })

            // Edit Teacher Subjects Modal
            var editTeacherSubjectsModal = document.getElementById('editTeacherSubjectsModal')
            var originalTeacherName = ''
            var originalSubjectIds = []
            
            editTeacherSubjectsModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var teacherId = button.getAttribute('data-teacher-id')
                var teacherName = button.getAttribute('data-teacher-name')
                var assignedSubjectIds = button.getAttribute('data-assigned-subject-ids') || ''
                
                var modal = this
                modal.querySelector('#edit_teacher_id').value = teacherId
                modal.querySelector('#edit_teacher_name').value = teacherName
                
                // Set redirect_tab based on current URL tab parameter
                var urlParams = new URLSearchParams(window.location.search)
                var currentTab = urlParams.get('tab') || 'teachers'
                modal.querySelector('#edit_teacher_redirect_tab').value = currentTab
                
                // Store original values for change detection
                originalTeacherName = teacherName
                originalSubjectIds = assignedSubjectIds ? assignedSubjectIds.split(',').map(function(id) { return parseInt(id); }).filter(function(id) { return !isNaN(id); }) : []
                originalSubjectIds.sort(function(a, b) { return a - b; })
                
                // Store in hidden fields
                modal.querySelector('#original_teacher_name').value = teacherName
                modal.querySelector('#original_subject_ids').value = assignedSubjectIds
                
                // Clear all checkbox selections first
                var checkboxes = modal.querySelectorAll('.edit-subject-checkbox')
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = false
                })
                
                // Select currently assigned subjects
                if (assignedSubjectIds) {
                    var subjectIdArray = assignedSubjectIds.split(',').map(function(id) { return id.trim(); })
                    checkboxes.forEach(function(checkbox) {
                        if (subjectIdArray.indexOf(checkbox.value) !== -1) {
                            checkbox.checked = true
                        }
                    })
                }
            })
            
            // Handle form submission with change detection and confirmation
            var editTeacherSubjectsForm = document.getElementById('editTeacherSubjectsForm')
            var updateTeacherSubjectsBtn = document.getElementById('updateTeacherSubjectsBtn')
            
            if (updateTeacherSubjectsBtn) {
                updateTeacherSubjectsBtn.addEventListener('click', function(e) {
                    e.preventDefault()
                    
                    var form = editTeacherSubjectsForm
                    var teacherName = form.querySelector('#edit_teacher_name').value.trim()
                    var originalName = form.querySelector('#original_teacher_name').value.trim()
                    
                    // Get current selected subject IDs
                    var checkboxes = form.querySelectorAll('.edit-subject-checkbox:checked')
                    var currentSubjectIds = Array.from(checkboxes).map(function(cb) { return parseInt(cb.value); })
                    currentSubjectIds.sort(function(a, b) { return a - b; })
                    
                    // Get original subject IDs
                    var originalIdsStr = form.querySelector('#original_subject_ids').value
                    var originalIds = originalIdsStr ? originalIdsStr.split(',').map(function(id) { return parseInt(id.trim()); }).filter(function(id) { return !isNaN(id); }) : []
                    originalIds.sort(function(a, b) { return a - b; })
                    
                    // Check if anything changed
                    var nameChanged = (teacherName !== originalName)
                    var subjectsChanged = (JSON.stringify(currentSubjectIds) !== JSON.stringify(originalIds))
                    
                    if (!nameChanged && !subjectsChanged) {
                        // Show "Nothing has been updated" popup
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'info',
                                title: 'No Changes',
                                text: 'Nothing has been updated.',
                                confirmButtonColor: '#a11c27',
                                confirmButtonText: 'OK'
                            })
                        } else {
                            alert('Nothing has been updated.')
                        }
                        return false
                    }
                    
                    // Show confirmation popup
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'question',
                            title: 'Confirm Update',
                            text: 'Are you sure you want to update?',
                            showCancelButton: true,
                            confirmButtonColor: '#a11c27',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'Yes, Update',
                            cancelButtonText: 'Cancel'
                        }).then(function(result) {
                            if (result.isConfirmed) {
                                form.submit()
                            }
                        })
                    } else {
                        if (confirm('Are you sure you want to update?')) {
                            form.submit()
                        }
                    }
                })
            }
            
            // Select All / Deselect All functions for subject checkboxes
            function selectAllSubjects(containerId) {
                var container = document.getElementById(containerId)
                if (container) {
                    var checkboxes = container.querySelectorAll('input[type="checkbox"]')
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = true
                    })
                }
            }
            
            function deselectAllSubjects(containerId) {
                var container = document.getElementById(containerId)
                if (container) {
                    var checkboxes = container.querySelectorAll('input[type="checkbox"]')
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = false
                    })
                }
            }

            // Edit Subject Modal
            var editSubjectModal = document.getElementById('editSubjectModal')
            if (editSubjectModal) {
                // Refresh CSRF token when modal opens
                editSubjectModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget
                    var subjectId = button.getAttribute('data-id')
                    var name = button.getAttribute('data-name')
                    var code = button.getAttribute('data-code')
                    var description = button.getAttribute('data-description')
                    var units = button.getAttribute('data-units') || '3.0'
                    
                    var modal = this
                    modal.querySelector('#edit_subject_id').value = subjectId
                    modal.querySelector('#edit_subject_name').value = name
                    modal.querySelector('#edit_subject_code').value = code
                    modal.querySelector('#edit_subject_description').value = description
                    modal.querySelector('#edit_subject_units').value = units
                    
                    // Refresh CSRF token from backend endpoint
                    fetch(getApiUrl('api/get_csrf.php'), {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        cache: 'no-cache'
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Failed to get CSRF token')
                        }
                        return response.json()
                    })
                    .then(data => {
                        if (data.csrf_token) {
                            var csrfInput = modal.querySelector('#edit_subject_csrf_token')
                            var metaToken = document.querySelector('meta[name="csrf-token"]')
                            if (csrfInput) {
                                csrfInput.value = data.csrf_token
                                console.log('Edit Subject CSRF token refreshed from backend')
                            }
                            if (metaToken) {
                                metaToken.setAttribute('content', data.csrf_token)
                            }
                        } else {
                            console.error('No CSRF token in response:', data)
                        }
                    })
                    .catch(error => {
                        console.error('Error refreshing CSRF token from backend:', error)
                    })
                })
                
                // Verify CSRF token is present on form submission (server-side validation is mandatory)
                var editSubjectForm = editSubjectModal.querySelector('#editSubjectForm')
                if (editSubjectForm) {
                    editSubjectForm.addEventListener('submit', function(event) {
                        var csrfInput = this.querySelector('input[name="csrf_token"]')
                        // Token should always be in form markup from backend
                        // If missing, prevent submission and alert user
                        if (!csrfInput || !csrfInput.value || csrfInput.value.length < 10) {
                            console.error('CSRF token missing or invalid in edit subject form!')
                            event.preventDefault()
                            alert('Security token is missing. Please refresh the page and try again.')
                            location.reload()
                            return false
                        }
                        console.log('Edit Subject form submitting with CSRF token (server will validate)')
                    })
                }
            }

            // Handle approve button clicks - prevent disabled buttons from opening modal
            document.addEventListener('click', function(e) {
                if (e.target.closest('.approve-btn')) {
                    var btn = e.target.closest('.approve-btn')
                    var canApprove = btn.getAttribute('data-can-approve') === '1'
                    if (!canApprove) {
                        e.preventDefault()
                        e.stopPropagation()
                        alert('Cannot approve: Requirements and payment must be complete before approval.')
                        return false
                    }
                }
            }, true)

            // Review Application Modal
            var reviewApplicationModal = document.getElementById('reviewApplicationModal')
            if (reviewApplicationModal) {
                reviewApplicationModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget
                    if (!button) {
                        console.error('No button found in event')
                        return;
                    }
                    
                    var appId = button.getAttribute('data-id')
                    var studentName = button.getAttribute('data-name')
                    var action = button.getAttribute('data-action')
                    
                    console.log('Modal opening with data:', {appId, studentName, action})
                    
                    if (!appId || !action) {
                        console.error('Missing required data attributes:', {appId, action})
                        alert('Error: Missing application data. Please refresh the page and try again.')
                        event.preventDefault()
                        return;
                    }
                    
                    var modal = this
                    var appIdInput = modal.querySelector('#review_application_id')
                    var actionInput = modal.querySelector('#review_action')
                    var studentNameInput = modal.querySelector('#review_student_name')
                    
                    if (!appIdInput || !actionInput) {
                        console.error('Missing form inputs in modal')
                        alert('Error: Form elements not found. Please refresh the page.')
                        event.preventDefault()
                        return
                    }
                    
                    appIdInput.value = appId
                    actionInput.value = action
                    if (studentNameInput) studentNameInput.value = studentName || ''
                    
                    var title = modal.querySelector('#reviewModalTitle')
                    var actionText = modal.querySelector('#review_action_text')
                    var submitBtn = modal.querySelector('#review_submit_btn')
                    
                    if (action === 'approve') {
                        if (title) title.textContent = 'Approve Application'
                        if (actionText) actionText.textContent = 'This will approve the application and automatically generate a student number (format: YYYY-NNNN). The student will be enrolled.'
                        if (submitBtn) {
                            submitBtn.textContent = 'Approve Application'
                            submitBtn.className = 'btn btn-success'
                            submitBtn.disabled = false
                            submitBtn.removeAttribute('disabled')
                        }
                    } else {
                        if (title) title.textContent = 'Reject Application'
                        if (actionText) actionText.textContent = 'This will reject the application. The student will not be enrolled.'
                        if (submitBtn) {
                            submitBtn.textContent = 'Reject Application'
                            submitBtn.className = 'btn btn-danger'
                            submitBtn.disabled = false
                            submitBtn.removeAttribute('disabled')
                        }
                    }
                })
                
                // Handle form submission - use native form.submit() to bypass event handlers
                var reviewForm = reviewApplicationModal.querySelector('#reviewApplicationForm')
                if (reviewForm) {
                    // Handle submit button click
                    var submitBtn = reviewForm.querySelector('#review_submit_btn')
                    if (submitBtn) {
                        submitBtn.addEventListener('click', function(e) {
                            var form = this.form || reviewForm
                            var appId = form.querySelector('#review_application_id')?.value
                            var action = form.querySelector('#review_action')?.value
                            
                            console.log('Submit button clicked:', {appId, action})
                            
                            if (!appId || !action) {
                                e.preventDefault()
                                e.stopPropagation()
                                alert('Error: Missing application data. Please refresh the page and try again.')
                                return false
                            }
                            
                            // Prevent default button behavior
                            e.preventDefault()
                            e.stopPropagation()
                            
                            // Show loading state
                            this.disabled = true
                            this.textContent = action === 'approve' ? 'Approving...' : 'Rejecting...'
                            
                            // Use native form.submit() which bypasses submit event handlers
                            console.log('Submitting form using native submit() method')
                            setTimeout(function() {
                                form.submit()
                            }, 50)
                            
                            return false
                        })
                    }
                    
                    // Also handle form submit as fallback (but shouldn't be needed)
                    reviewForm.addEventListener('submit', function(e) {
                        var appId = this.querySelector('#review_application_id')?.value
                        var action = this.querySelector('#review_action')?.value
                        
                        if (!appId || !action) {
                            e.preventDefault()
                            alert('Error: Missing application data.')
                            return false
                        }
                        
                        // Don't prevent - allow normal submission if button handler didn't work
                        console.log('Form submit event - allowing normal submission')
                    })
                } else {
                    console.error('Review form #reviewApplicationForm not found in modal')
                }
            } else {
                console.error('Review application modal not found')
            }
            
            // Simple inline validation function for form submission
            window.validateReviewForm = function(form) {
                var appId = form.querySelector('#review_application_id')?.value
                var action = form.querySelector('#review_action')?.value
                
                console.log('validateReviewForm called:', {appId, action})
                
                if (!appId || !action) {
                    alert('Error: Missing application data. Please refresh the page and try again.')
                    return false
                }
                
                var submitBtn = form.querySelector('#review_submit_btn')
                if (submitBtn) {
                    submitBtn.disabled = true
                    submitBtn.textContent = action === 'approve' ? 'Approving...' : 'Rejecting...'
                }
                
                console.log('Form validation passed, submitting...')
                return true
            }

            // Simple inline validation function for form submission
            window.validateReviewForm = function(form) {
                if (!form) {
                    console.error('validateReviewForm: form is null')
                    return false
                }
                
                var appId = form.querySelector('#review_application_id')?.value
                var action = form.querySelector('#review_action')?.value
                
                console.log('validateReviewForm called:', {appId, action, formId: form.id})
                
                if (!appId || !action) {
                    alert('Error: Missing application data. Please refresh the page and try again.')
                    console.error('Validation failed - missing data:', {appId, action})
                    return false
                }
                
                // Verify hidden input exists
                var reviewInput = form.querySelector('input[name="review_application"]')
                if (!reviewInput) {
                    console.warn('review_application input not found, creating it')
                    var hiddenInput = document.createElement('input')
                    hiddenInput.type = 'hidden'
                    hiddenInput.name = 'review_application'
                    hiddenInput.value = '1'
                    form.appendChild(hiddenInput)
                }
                
                var submitBtn = form.querySelector('#review_submit_btn')
                if (submitBtn) {
                    submitBtn.disabled = true
                    submitBtn.textContent = action === 'approve' ? 'Approving...' : 'Rejecting...'
                }
                
                console.log('Form validation passed, allowing submission')
                return true
            }

            // View Application Modal
            var viewApplicationModal = document.getElementById('viewApplicationModal')
            
            viewApplicationModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var appData = JSON.parse(button.getAttribute('data-app'))
                var appId = appData.id
                var content = this.querySelector('#view_application_content')
                
                // Reset original checkbox states (using global variable)
                originalCheckboxStates = {}
                
                var html = '<div class="row">'
                html += '<div class="col-md-6"><strong>Application Number:</strong><br>' + appData.application_number + '</div>'
                html += '<div class="col-md-6"><strong>Status:</strong><br><span class="badge bg-' + (appData.status === 'pending' ? 'warning' : (appData.status === 'approved' ? 'success' : 'danger')) + '">' + appData.status.toUpperCase() + '</span></div>'
                html += '</div><hr>'
                html += '<div class="row"><div class="col-md-6"><strong>Student Name:</strong><br>' + appData.first_name + ' ' + (appData.middle_name || '') + ' ' + appData.last_name + '</div>'
                html += '<div class="col-md-6"><strong>Student ID:</strong><br>' + (appData.student_id_number || 'Not assigned') + '</div></div><hr>'
                html += '<div class="row"><div class="col-md-6"><strong>Email:</strong><br>' + appData.email + '</div>'
                html += '<div class="col-md-6"><strong>Phone:</strong><br>' + (appData.phone_number || 'N/A') + '</div></div><hr>'
                html += '<div class="row"><div class="col-md-6"><strong>Program Applied:</strong><br>' + appData.program_applied + '</div>'
                html += '<div class="col-md-6"><strong>Educational Status:</strong><br>' + appData.educational_status + '</div></div><hr>'
                html += '<div class="row"><div class="col-md-6"><strong>Application Date:</strong><br>' + new Date(appData.application_date).toLocaleDateString() + '</div>'
                if (appData.reviewed_by) {
                    html += '<div class="col-md-6"><strong>Reviewed By:</strong><br>' + appData.reviewer_first + ' ' + appData.reviewer_last + '</div>'
                }
                html += '</div>'
                
                // Add Requirements and Payment sections
                html += '<hr><h5>Requirements Status</h5>'
                html += '<div id="requirements_section_' + appId + '">Loading...</div>'
                html += '<hr><h5>Payment Status</h5>'
                html += '<div id="payment_section_' + appId + '">Loading...</div>'
                
                if (appData.notes) {
                    html += '<hr><div><strong>Notes:</strong><br>' + appData.notes + '</div>'
                }
                
                content.innerHTML = html
                
                // Load requirements and payment via AJAX
                // Clear sections first to prevent duplicates
                var reqSection = document.getElementById('requirements_section_' + appId)
                var paySection = document.getElementById('payment_section_' + appId)
                if (reqSection) reqSection.innerHTML = 'Loading...'
                if (paySection) paySection.innerHTML = 'Loading...'
                
                fetch('?action=get_application_details&application_id=' + appId)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok')
                        }
                        return response.json()
                    })
                    .then(data => {
                        // Check if there's an error in the response
                        if (data.error) {
                            throw new Error(data.error)
                        }
                        
                        // Debug: log the data to see if duplicates are coming from server
                        console.log('Requirements data:', data.requirements)
                        
                        var reqHtml = ''
                        if (data.requirements && data.requirements.length > 0) {
                            // Deduplicate requirements by requirement_name (not just ID, in case DB has duplicate records)
                            var seenRequirementNames = {}
                            var uniqueRequirements = []
                            data.requirements.forEach(function(req) {
                                var reqName = req.requirement_name.toLowerCase().trim()
                                if (!seenRequirementNames[reqName]) {
                                    uniqueRequirements.push(req)
                                    seenRequirementNames[reqName] = true
                                } else {
                                    console.log('Duplicate requirement name found:', req.requirement_name, 'ID:', req.requirement_id)
                                }
                            })
                            
                            console.log('Unique requirements count:', uniqueRequirements.length, 'Total:', data.requirements.length)
                            
                            reqHtml += '<form method="POST" id="requirements_form_' + appId + '">'
                            reqHtml += '<input type="hidden" name="mark_requirement_received" value="1">'
                            reqHtml += '<input type="hidden" name="application_id" value="' + appId + '">'
                            reqHtml += '<table class="table table-sm"><thead><tr><th>Requirement</th><th>Received (F2F)</th><th>Status</th></tr></thead><tbody>'
                            uniqueRequirements.forEach(function(req) {
                                var isChecked = req.status === 'approved' ? 'checked' : ''
                                var statusBadge = req.status === 'approved' ? 'success' : 'secondary'
                                var statusText = req.status === 'approved' ? 'Received' : 'Not Received'
                                reqHtml += '<tr>'
                                reqHtml += '<td>' + req.requirement_name + '</td>'
                                reqHtml += '<td style="text-align: center;">'
                                var wasCheckedAttr = isChecked ? 'data-was-checked="true" ' : ''
                                reqHtml += '<input type="checkbox" name="requirement_' + req.requirement_id + '_received" value="1" ' + isChecked + ' '
                                reqHtml += 'data-requirement-id="' + req.requirement_id + '" ' + wasCheckedAttr + '>'
                                reqHtml += '</td>'
                                reqHtml += '<td><span class="badge bg-' + statusBadge + '">' + statusText + '</span></td>'
                                reqHtml += '</tr>'
                            })
                            reqHtml += '</tbody></table>'
                            reqHtml += '</form>'
                            reqHtml += '<p class="text-muted" style="font-size: 0.85rem; margin-top: 10px;"><i class="fas fa-info-circle"></i> Check the boxes when you receive requirements face-to-face. Changes will be saved when you close the modal.</p>'
                        } else {
                            reqHtml = '<p class="text-muted">No requirements available. Please add requirements in the system settings.</p>'
                        }
                        // Clear and set content to prevent duplicates
                        var reqSection = document.getElementById('requirements_section_' + appId)
                        if (reqSection) {
                            reqSection.innerHTML = ''
                            reqSection.innerHTML = reqHtml
                            
                            // Store original checkbox states after rendering
                            setTimeout(function() {
                                var reqForm = document.getElementById('requirements_form_' + appId)
                                if (reqForm) {
                                    var checkboxes = reqForm.querySelectorAll('input[type="checkbox"]')
                                    checkboxes.forEach(function(cb) {
                                        originalCheckboxStates[cb.name] = cb.checked
                                        // Update data-was-checked attribute
                                        if (cb.checked) {
                                            cb.setAttribute('data-was-checked', 'true')
                                        }
                                    })
                                }
                            }, 100)
                        }
                        
                        var payHtml = ''
                        payHtml += '<form method="POST" id="payment_form_' + appId + '">'
                        payHtml += '<input type="hidden" name="mark_payment_received" value="1">'
                        payHtml += '<input type="hidden" name="application_id" value="' + appId + '">'
                        if (data.payment) {
                            var isChecked = data.payment.status === 'verified' ? 'checked' : ''
                            var payStatusBadge = data.payment.status === 'verified' ? 'success' : 'secondary'
                            var payStatusText = data.payment.status === 'verified' ? 'Received' : 'Not Received'
                            payHtml += '<div class="card"><div class="card-body">'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Payment Received (F2F):</strong></label><br>'
                            payHtml += '<div class="form-check form-switch" style="margin-top: 10px;">'
                            payHtml += '<input class="form-check-input" type="checkbox" name="is_received" value="1" ' + isChecked + '>'
                            payHtml += '<label class="form-check-label">Mark as received</label>'
                            payHtml += '</div>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Amount:</strong></label>'
                            payHtml += '<input type="number" class="form-control payment-amount-input" name="amount" step="0.01" min="0" value="' + (data.payment.amount || 0) + '" required>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Payment Method:</strong></label>'
                            payHtml += '<select class="form-control" name="payment_method" required>'
                            payHtml += '<option value="Cash"' + (data.payment.payment_method === 'Cash' ? ' selected' : '') + '>Cash</option>'
                            payHtml += '</select>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Notes (Optional):</strong></label>'
                            payHtml += '<textarea class="form-control" name="verification_notes" rows="2">' + (data.payment.verification_notes || '') + '</textarea>'
                            payHtml += '</div>'
                            payHtml += '<p><strong>Status:</strong> <span class="badge bg-' + payStatusBadge + '">' + payStatusText + '</span></p>'
                            if (data.payment.receipt_path) {
                                payHtml += '<p><a href="../' + data.payment.receipt_path + '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-file"></i> View Receipt</a></p>'
                            }
                            payHtml += '</div></div>'
                        } else {
                            payHtml += '<div class="card"><div class="card-body">'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Payment Received (F2F):</strong></label><br>'
                            payHtml += '<div class="form-check form-switch" style="margin-top: 10px;">'
                            payHtml += '<input class="form-check-input" type="checkbox" name="is_received" value="1" '
                            payHtml += 'onchange="markPaymentReceived(' + appId + ', this.checked)">'
                            payHtml += '<label class="form-check-label">Mark as received</label>'
                            payHtml += '</div>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Amount:</strong></label>'
                            payHtml += '<input type="number" class="form-control payment-amount-input" name="amount" step="0.01" min="0" value="0" required>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Payment Method:</strong></label>'
                            payHtml += '<select class="form-control" name="payment_method" required>'
                            payHtml += '<option value="Cash" selected>Cash</option>'
                            payHtml += '</select>'
                            payHtml += '</div>'
                            payHtml += '<div class="form-group mb-3">'
                            payHtml += '<label><strong>Notes (Optional):</strong></label>'
                            payHtml += '<textarea class="form-control" name="verification_notes" rows="2"></textarea>'
                            payHtml += '</div>'
                            payHtml += '<p class="text-muted">No payment recorded yet.</p>'
                            payHtml += '</div></div>'
                        }
                        payHtml += '</form>'
                        payHtml += '<p class="text-muted" style="font-size: 0.85rem; margin-top: 10px;"><i class="fas fa-info-circle"></i> Check the box when you receive the payment face-to-face.</p>'
                        // Clear and set content to prevent duplicates
                        var paySection = document.getElementById('payment_section_' + appId)
                        if (paySection) {
                            paySection.innerHTML = ''
                            paySection.innerHTML = payHtml
                            
                            // Auto-clear "0" from amount input when user focuses or starts typing
                            var amountInput = paySection.querySelector('.payment-amount-input')
                            if (amountInput) {
                                // Clear "0" on focus if value is "0"
                                amountInput.addEventListener('focus', function() {
                                    if (this.value === '0' || this.value === 0) {
                                        this.value = ''
                                    }
                                })
                                
                                // Clear "0" when user starts typing
                                amountInput.addEventListener('keydown', function(e) {
                                    // If value is "0" and user presses a number key, clear it first
                                    if ((this.value === '0' || this.value === 0) && e.key >= '0' && e.key <= '9' && !e.ctrlKey && !e.metaKey) {
                                        this.value = ''
                                    }
                                })
                                
                                // Also handle input event for paste scenarios
                                amountInput.addEventListener('input', function() {
                                    // If user pastes and value starts with "0" followed by a number, remove leading zero
                                    if (this.value && this.value.length > 1 && this.value[0] === '0' && this.value[1] !== '.') {
                                        this.value = this.value.replace(/^0+/, '') || '0'
                                    }
                                })
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading details:', error)
                        var errorMsg = error.message || 'Unknown error occurred'
                        document.getElementById('requirements_section_' + appId).innerHTML = '<p class="text-danger">Error loading requirements: ' + errorMsg + '</p>'
                        document.getElementById('payment_section_' + appId).innerHTML = '<p class="text-danger">Error loading payment: ' + errorMsg + '</p>'
                    })
            })
            
            // Save changes when modal is hidden (optional - user can also use Save button)
            viewApplicationModal.addEventListener('hide.bs.modal', function (event) {
                var content = this.querySelector('#view_application_content')
                var reqSection = content.querySelector('[id^="requirements_section_"]')
                if (reqSection) {
                    var appIdMatch = reqSection.id.match(/requirements_section_(\d+)/)
                    if (appIdMatch) {
                        var appId = appIdMatch[1]
                        saveAllRequirements(appId).then(function() {
                            // Also save payment if changed
                            savePaymentIfChanged(appId)
                        }).catch(function(error) {
                            console.error('Error saving requirements:', error)
                        })
                    }
                }
            })
            
            // Store current application ID for save button
            var currentApplicationId = null
            viewApplicationModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var appData = JSON.parse(button.getAttribute('data-app'))
                currentApplicationId = appData.id
            })
        });

        // Password toggle functionality
        function toggleStudentFields() {
            const roleSelect = document.getElementById('userRole');
            const studentFields = document.getElementById('studentFields');
            
            if (roleSelect && studentFields) {
                if (roleSelect.value === 'student') {
                    studentFields.style.display = 'block';
                } else {
                    studentFields.style.display = 'none';
                    // Clear student-specific fields when hiding
                    document.getElementById('studentProgram').value = '';
                    document.getElementById('studentYearLevel').value = '';
                    document.getElementById('studentSection').value = '';
                }
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleStudentFields();
        });
        
        function togglePassword(passwordFieldId, iconId) {
            var passwordField = document.getElementById(passwordFieldId);
            var icon = document.getElementById(iconId);
            
            if (passwordField.type === "password") {
                passwordField.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                passwordField.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        // Password strength indicator (optional enhancement)
        function checkPasswordStrength(password) {
            var strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            return strength;
        }
        
        // Tab navigation is handled by PHP - no JavaScript needed for tab switching
        // The page reloads with the correct tab parameter, and PHP handles showing the right content

        // Validate edit course form before submission (fallback if JavaScript fails)
        function validateEditCourseForm(form) {
            if (!form) return false
            
            var csrfInput = form.querySelector('input[name="csrf_token"]')
            if (!csrfInput) {
                console.error('CSRF token input not found in form!')
                // Try to get from meta tag and create input
                var metaToken = document.querySelector('meta[name="csrf-token"]')
                if (metaToken && metaToken.getAttribute('content')) {
                    csrfInput = document.createElement('input')
                    csrfInput.type = 'hidden'
                    csrfInput.name = 'csrf_token'
                    csrfInput.id = 'edit_course_csrf_token'
                    csrfInput.value = metaToken.getAttribute('content')
                    form.insertBefore(csrfInput, form.firstChild)
                    console.log('CSRF token input created from meta tag')
                } else {
                    alert('Security token is missing. Please refresh the page and try again.')
                    return false
                }
            }
            
            if (!csrfInput.value || csrfInput.value.length < 10) {
                console.error('CSRF token value is empty or invalid:', csrfInput.value)
                // Try to get from meta tag
                var metaToken = document.querySelector('meta[name="csrf-token"]')
                if (metaToken && metaToken.getAttribute('content')) {
                    csrfInput.value = metaToken.getAttribute('content')
                    console.log('CSRF token set from meta tag')
                } else {
                    alert('Security token is invalid. Please refresh the page and try again.')
                    return false
                }
            }
            
            console.log('Form validation passed, CSRF token present')
            return true
        }
        
        // Edit Course Modal
        var editCourseModal = document.getElementById('editCourseModal')
        if (editCourseModal) {
            var editCourseForm = editCourseModal.querySelector('form')

            function getCourseFormData(form) {
                if (!form) return null
                return {
                    code: form.querySelector('#edit_course_code').value.trim(),
                    name: form.querySelector('#edit_course_name').value.trim(),
                    description: form.querySelector('#edit_course_description').value.trim(),
                    duration: form.querySelector('#edit_course_duration').value.trim(),
                    status: form.querySelector('#edit_course_status').value
                }
            }

            function storeCourseSnapshot(form) {
                if (!form) return
                var snapshot = getCourseFormData(form)
                if (snapshot) {
                    form.dataset.courseSnapshot = JSON.stringify(snapshot)
                }
            }

            editCourseModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                var form = modal.querySelector('form')
                
                // Refresh CSRF token from backend endpoint when modal opens
                // Token should already be in form markup from backend, but refresh to ensure it's current
                fetch(getApiUrl('api/get_csrf.php'), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    cache: 'no-cache'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Failed to get CSRF token')
                    }
                    return response.json()
                })
                .then(data => {
                    if (data.csrf_token) {
                        var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null
                        var metaToken = document.querySelector('meta[name="csrf-token"]')
                        if (csrfInput) {
                            csrfInput.value = data.csrf_token
                            console.log('Edit Course CSRF token refreshed from backend')
                        }
                        if (metaToken) {
                            metaToken.setAttribute('content', data.csrf_token)
                        }
                    } else {
                        console.error('No CSRF token in response:', data)
                    }
                })
                .catch(error => {
                    console.error('Error refreshing CSRF token from backend:', error)
                })
                
                modal.querySelector('#edit_course_id').value = button.getAttribute('data-id')
                modal.querySelector('#edit_course_code').value = button.getAttribute('data-code')
                modal.querySelector('#edit_course_name').value = button.getAttribute('data-name')
                modal.querySelector('#edit_course_description').value = button.getAttribute('data-description') || ''
                modal.querySelector('#edit_course_duration').value = button.getAttribute('data-duration')
                modal.querySelector('#edit_course_status').value = button.getAttribute('data-status')
                if (form) {
                    storeCourseSnapshot(form)
                }
            })

            if (editCourseForm) {
                // Flag to prevent duplicate popup calls
                var isProcessing = false
                
                editCourseForm.addEventListener('submit', function (event) {
                    // Always ensure CSRF token is present before allowing submission
                    var csrfInput = this.querySelector('input[name="csrf_token"]')
                    
                    // If bypass is set, just verify and allow
                    if (this.dataset.confirmBypass === 'true') {
                        if (!csrfInput || !csrfInput.value) {
                            console.error('CSRF token missing in bypass mode!')
                            event.preventDefault()
                            return false
                        }
                        console.log('Bypass mode - token verified:', csrfInput.value.substring(0, 10) + '...')
                        return true
                    }
                    
                    // Check token before any processing
                    if (!csrfInput) {
                        console.error('CSRF token input not found!')
                        // Try to create it from meta tag
                        var metaToken = document.querySelector('meta[name="csrf-token"]')
                        if (metaToken && metaToken.getAttribute('content')) {
                            csrfInput = document.createElement('input')
                            csrfInput.type = 'hidden'
                            csrfInput.name = 'csrf_token'
                            csrfInput.id = 'edit_course_csrf_token'
                            csrfInput.value = metaToken.getAttribute('content')
                            this.insertBefore(csrfInput, this.firstChild)
                            console.log('CSRF token input created from meta tag')
                        } else {
                            event.preventDefault()
                            alert('Security token is missing. Please refresh the page and try again.')
                            location.reload()
                            return false
                        }
                    }
                    
                    if (!csrfInput.value || csrfInput.value.length < 10) {
                        console.error('CSRF token value is empty or invalid!')
                        // Try to get from meta tag
                        var metaToken = document.querySelector('meta[name="csrf-token"]')
                        if (metaToken && metaToken.getAttribute('content')) {
                            csrfInput.value = metaToken.getAttribute('content')
                            console.log('CSRF token set from meta tag')
                        } else {
                            event.preventDefault()
                            alert('Security token is invalid. Please refresh the page and try again.')
                            location.reload()
                            return false
                        }
                    }

                    // Prevent duplicate calls
                    if (isProcessing) {
                        event.preventDefault()
                        return false
                    }

                    event.preventDefault()
                    isProcessing = true
                    
                    var currentData = getCourseFormData(this)
                    var initialData = this.dataset.courseSnapshot ? JSON.parse(this.dataset.courseSnapshot) : null
                    var hasChanges = !initialData

                    if (initialData) {
                        hasChanges = Object.keys(currentData).some(function (key) {
                            return (initialData[key] || '') !== (currentData[key] || '')
                        })
                    }

                    var courseName = currentData && currentData.name ? currentData.name : 'this course'
                    var form = this

                    if (!hasChanges) {
                        openActionConfirm({
                            title: 'No Changes Detected',
                            message: 'Please update at least one field before saving.',
                            targetLabel: courseName,
                            confirmLabel: 'Got it',
                            showCancel: false
                        }).then(function() {
                            isProcessing = false
                        })
                        return
                    }

                    openActionConfirm({
                        title: 'Apply Course Changes',
                        message: 'Do you want to save these updates?',
                        targetLabel: courseName,
                        confirmLabel: 'Yes, Save'
                    }).then(function (confirmed) {
                        isProcessing = false
                        if (!confirmed) {
                            return
                        }
                        
                        // Ensure CSRF token is present before submitting
                        var csrfInput = form.querySelector('input[name="csrf_token"]')
                        if (!csrfInput) {
                            console.error('CSRF token input not found! Creating one...')
                            csrfInput = document.createElement('input')
                            csrfInput.type = 'hidden'
                            csrfInput.name = 'csrf_token'
                            csrfInput.id = 'edit_course_csrf_token'
                            form.insertBefore(csrfInput, form.firstChild)
                        }
                        
                        // Get token from meta tag (synchronous)
                        var metaToken = document.querySelector('meta[name="csrf-token"]')
                        if (metaToken && metaToken.getAttribute('content')) {
                            csrfInput.value = metaToken.getAttribute('content')
                        }
                        
                        // If still no token, fetch from backend endpoint (but wait for it)
                        if (!csrfInput.value || csrfInput.value.length < 10) {
                            console.log('Fetching fresh CSRF token from backend...')
                            fetch(getApiUrl('api/get_csrf.php'), {
                                method: 'GET',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                credentials: 'same-origin',
                                cache: 'no-cache'
                            })
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Failed to get CSRF token')
                                }
                                return response.json()
                            })
                            .then(data => {
                                if (data.csrf_token) {
                                    csrfInput.value = data.csrf_token
                                    var metaToken = document.querySelector('meta[name="csrf-token"]')
                                    if (metaToken) {
                                        metaToken.setAttribute('content', data.csrf_token)
                                    }
                                    console.log('CSRF token fetched from backend, submitting form...')
                                    form.dataset.confirmBypass = 'true'
                                    form.submit()
                                } else {
                                    alert('Failed to get security token. Please refresh the page and try again.')
                                    location.reload()
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching CSRF token from backend:', error)
                                alert('Security token error. Please refresh the page and try again.')
                                location.reload()
                            })
                            return // Don't submit yet, wait for token
                        }
                        
                        // Final verification - ensure token exists and is valid
                        if (!csrfInput.value || csrfInput.value.length < 10) {
                            console.error('CSRF token is invalid!', csrfInput.value)
                            alert('Security token is invalid. Please refresh the page and try again.')
                            location.reload()
                            return
                        }
                        
                        // Final verification - ensure token exists and is valid
                        if (!csrfInput.value || csrfInput.value.length < 10) {
                            console.error('CSRF token is invalid!', csrfInput.value)
                            alert('Security token is invalid. Please refresh the page and try again.')
                            location.reload()
                            return
                        }
                        
                        console.log('CSRF token verified:', csrfInput.value.substring(0, 20) + '...')
                        
                        // Ensure token is physically in the form DOM
                        if (!csrfInput.parentNode || csrfInput.parentNode !== form) {
                            console.warn('CSRF token input not in form, re-adding...')
                            form.insertBefore(csrfInput, form.firstChild)
                        }
                        
                        // Verify all required fields are present
                        var formData = new FormData(form)
                        var requiredFields = ['csrf_token', 'course_id', 'update_course', 'code', 'name']
                        var missingFields = []
                        requiredFields.forEach(function(field) {
                            if (!formData.get(field)) {
                                missingFields.push(field)
                            }
                        })
                        
                        if (missingFields.length > 0) {
                            console.error('Missing form fields:', missingFields)
                            alert('Form data is incomplete. Please refresh the page and try again.')
                            location.reload()
                            return
                        }
                        
                        console.log('All required fields present. Submitting form...')
                        console.log('Form action:', form.action)
                        console.log('Form method:', form.method)
                        console.log('CSRF token value:', csrfInput.value.substring(0, 20) + '...')
                        
                        // Create a new form element to submit (ensures all fields are included)
                        var newForm = document.createElement('form')
                        newForm.method = 'POST'
                        newForm.action = form.action || window.location.href
                        newForm.style.display = 'none'
                        
                        // Copy all form fields to the new form
                        var allInputs = form.querySelectorAll('input, textarea, select')
                        allInputs.forEach(function(input) {
                            if (input.name && input.name !== '') {
                                var newInput = input.cloneNode(true)
                                if (input.type === 'checkbox' || input.type === 'radio') {
                                    newInput.checked = input.checked
                                }
                                newForm.appendChild(newInput)
                            }
                        })
                        
                        // Ensure CSRF token is in the new form
                        var newCsrfInput = newForm.querySelector('input[name="csrf_token"]')
                        if (!newCsrfInput) {
                            var csrfClone = csrfInput.cloneNode(true)
                            csrfClone.value = csrfInput.value
                            newForm.insertBefore(csrfClone, newForm.firstChild)
                        }
                        
                        // Append to body and submit
                        document.body.appendChild(newForm)
                        console.log('Submitting form with all fields...')
                        newForm.submit()
                    })
                })
            }
        }

        // Edit Section Modal
        var editSectionModal = document.getElementById('editSectionModal')
        if (editSectionModal) {
            editSectionModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                modal.querySelector('#edit_section_id').value = button.getAttribute('data-id')
                modal.querySelector('#edit_section_course_id').value = button.getAttribute('data-course-id')
                modal.querySelector('#edit_section_name').value = button.getAttribute('data-section-name')
                modal.querySelector('#edit_section_year_level').value = button.getAttribute('data-year-level')
                modal.querySelector('#edit_section_academic_year').value = button.getAttribute('data-academic-year')
                modal.querySelector('#edit_section_semester').value = button.getAttribute('data-semester')
                modal.querySelector('#edit_section_teacher_id').value = button.getAttribute('data-teacher-id') || ''
                modal.querySelector('#edit_section_max_students').value = button.getAttribute('data-max-students')
                modal.querySelector('#edit_section_status').value = button.getAttribute('data-status')
                
                // Prevent body expansion
                document.body.style.paddingRight = '0'
            })
            
            editSectionModal.addEventListener('hidden.bs.modal', function (event) {
                // Restore body padding if needed
                document.body.style.paddingRight = ''
            })
        }

        // Edit Schedule Modal
        var editScheduleModal = document.getElementById('editScheduleModal')
        if (editScheduleModal) {
            editScheduleModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                modal.querySelector('#edit_schedule_id').value = button.getAttribute('data-id')
                modal.querySelector('#edit_schedule_section_id').value = button.getAttribute('data-section-id')
                modal.querySelector('#edit_schedule_subject_id').value = button.getAttribute('data-subject-id')
                modal.querySelector('#edit_schedule_teacher_id').value = button.getAttribute('data-teacher-id') || ''
                modal.querySelector('#edit_schedule_day_of_week').value = button.getAttribute('data-day-of-week')
                modal.querySelector('#edit_schedule_start_time').value = button.getAttribute('data-start-time')
                modal.querySelector('#edit_schedule_end_time').value = button.getAttribute('data-end-time')
                modal.querySelector('#edit_schedule_room').value = button.getAttribute('data-room') || ''
                modal.querySelector('#edit_schedule_academic_year').value = button.getAttribute('data-academic-year')
                modal.querySelector('#edit_schedule_semester').value = button.getAttribute('data-semester')
                modal.querySelector('#edit_schedule_status').value = button.getAttribute('data-status')
            })
        }

        // Edit Enrollment Period Modal
        var editEnrollmentPeriodModal = document.getElementById('editEnrollmentPeriodModal')
        if (editEnrollmentPeriodModal) {
            editEnrollmentPeriodModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                modal.querySelector('#edit_period_id').value = button.getAttribute('data-id')
                modal.querySelector('#edit_period_course_id').value = button.getAttribute('data-course-id')
                modal.querySelector('#edit_period_academic_year').value = button.getAttribute('data-academic-year')
                modal.querySelector('#edit_period_semester').value = button.getAttribute('data-semester')
                modal.querySelector('#edit_period_start_date').value = button.getAttribute('data-start-date')
                modal.querySelector('#edit_period_end_date').value = button.getAttribute('data-end-date')
                modal.querySelector('#edit_period_status').value = button.getAttribute('data-status')
                modal.querySelector('#edit_period_auto_close').checked = button.getAttribute('data-auto-close') === '1'
            })
        }

        // Approve Enrollment Request Modal
        var approveEnrollmentModal = document.getElementById('approveEnrollmentModal')
        if (approveEnrollmentModal) {
            approveEnrollmentModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                modal.querySelector('#approve_enrollment_request_id').value = button.getAttribute('data-id')
                modal.querySelector('#approve_student_name').value = button.getAttribute('data-student-name')
                modal.querySelector('#approve_requirements_verified').checked = false
            })
        }

        // Reject Enrollment Request Modal
        var rejectEnrollmentModal = document.getElementById('rejectEnrollmentModal')
        if (rejectEnrollmentModal) {
            rejectEnrollmentModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                modal.querySelector('#reject_request_id').value = button.getAttribute('data-id')
                modal.querySelector('#reject_student_name').value = button.getAttribute('data-student-name')
            })
        }
        
        // Manage Section Students Modal
        var manageSectionStudentsModal = document.getElementById('manageSectionStudentsModal')
        if (manageSectionStudentsModal) {
            manageSectionStudentsModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var modal = this
                var sectionId = button.getAttribute('data-section-id')
                var sectionName = button.getAttribute('data-section-name')
                var courseName = button.getAttribute('data-course-name')
                var yearLevel = button.getAttribute('data-year-level')
                
                modal.querySelector('#modal_section_id').value = sectionId
                modal.querySelector('#modal_section_name').textContent = sectionName
                modal.querySelector('#modal_course_name').textContent = courseName
                modal.querySelector('#modal_year_level').textContent = yearLevel
                
                // Load students for this section
                loadSectionStudents(sectionId)
            })
        }
        
        // Function to load students in a section
        function loadSectionStudents(sectionId) {
            var studentsListDiv = document.getElementById('section_students_list')
            if (!studentsListDiv) return
            
            studentsListDiv.innerHTML = '<p class="text-muted">Loading students...</p>'
            
            // Fetch students via AJAX
            fetch('?action=get_section_students&section_id=' + sectionId)
                .then(function(response) {
                    return response.json()
                })
                .then(function(data) {
                    if (data.success && data.students) {
                        if (data.students.length === 0) {
                            studentsListDiv.innerHTML = '<p class="text-muted">No students in this section yet.</p>'
                        } else {
                            var html = '<table class="table table-sm table-striped"><thead><tr><th>Name</th><th>Student ID</th><th>Email</th><th>Actions</th></tr></thead><tbody>'
                            data.students.forEach(function(student) {
                                html += '<tr>'
                                html += '<td>' + (student.last_name || '') + ', ' + (student.first_name || '') + '</td>'
                                html += '<td>' + (student.student_id_number || 'N/A') + '</td>'
                                html += '<td>' + (student.email || 'N/A') + '</td>'
                                html += '<td><a href="?action=remove_student_from_section&section_id=' + sectionId + '&student_id=' + student.id + '" class="btn btn-sm btn-outline-danger touch-friendly" data-confirm-action="remove_student" data-confirm-target="' + (student.first_name + ' ' + student.last_name) + '" data-item-name="this student"><i class="fas fa-user-minus"></i> Remove</a></td>'
                                html += '</tr>'
                            })
                            html += '</tbody></table>'
                            studentsListDiv.innerHTML = html
                        }
                    } else {
                        studentsListDiv.innerHTML = '<p class="text-danger">Error loading students: ' + (data.error || 'Unknown error') + '</p>'
                    }
                })
                .catch(function(error) {
                    console.error('Error loading section students:', error)
                    studentsListDiv.innerHTML = '<p class="text-danger">Error loading students. Please refresh the page.</p>'
                })
        }
        
        // Global variable to store original checkbox states
        var originalCheckboxStates = {}
        
        // Save all requirement checkboxes when modal closes
        function saveAllRequirements(applicationId) {
            var form = document.getElementById('requirements_form_' + applicationId)
            if (!form) {
                console.log('No requirements form found')
                return Promise.resolve()
            }
            
            // Get all checkboxes and compare with original states
            var allRequirements = form.querySelectorAll('input[type="checkbox"]')
            
            // Only save requirements that changed state
            var changedRequirements = []
            allRequirements.forEach(function(checkbox) {
                var reqId = checkbox.name.match(/requirement_(\d+)_received/)[1]
                var wasChecked = originalCheckboxStates[checkbox.name] === true
                var isNowChecked = checkbox.checked
                
                if (wasChecked !== isNowChecked) {
                    changedRequirements.push({id: reqId, checked: isNowChecked})
                }
            })
            
            if (changedRequirements.length === 0) {
                console.log('No requirement changes to save')
                return Promise.resolve() // No changes to save
            }
            
            console.log('Saving', changedRequirements.length, 'requirement changes')
            
            // Save all changed requirements
            var savePromises = []
            changedRequirements.forEach(function(req) {
                var formData = new FormData()
                formData.append('mark_requirement_received', '1')
                formData.append('application_id', applicationId)
                formData.append('requirement_id', req.id)
                if (req.checked) {
                    formData.append('is_received', '1')
                }
                formData.append('review_notes', req.checked ? 'Marked as received face-to-face' : 'Unmarked requirement')
                
                savePromises.push(
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).then(function(response) {
                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status)
                        }
                        return response.json().catch(function() {
                            // If not JSON, return text
                            return response.text().then(function(text) {
                                return {success: true, message: text}
                            })
                        })
                    }).then(function(data) {
                        if (data.success === false) {
                            throw new Error(data.error || 'Unknown error')
                        }
                        console.log('Requirement saved:', req.id, data)
                        return data
                    }).catch(function(error) {
                        console.error('Error saving requirement:', req.id, error)
                        throw error
                    })
                )
            })
            
            return Promise.all(savePromises)
        }
        
        // Save payment when modal closes
        function savePaymentIfChanged(applicationId) {
            var form = document.getElementById('payment_form_' + applicationId)
            if (!form) {
                console.log('No payment form found')
                return Promise.resolve()
            }
            
            var isReceived = form.querySelector('input[name="is_received"]') && form.querySelector('input[name="is_received"]').checked
            var amountInput = form.querySelector('input[name="amount"]')
            var amount = amountInput ? amountInput.value : '0'
            var paymentMethodSelect = form.querySelector('select[name="payment_method"]')
            var paymentMethod = paymentMethodSelect ? paymentMethodSelect.value : 'Cash'
            var notesTextarea = form.querySelector('textarea[name="verification_notes"]')
            var notes = notesTextarea ? notesTextarea.value : ''
            
            // Always save payment data when form exists (even if checkbox is unchecked, to update amount/method)
            console.log('Saving payment - isReceived:', isReceived, 'amount:', amount, 'method:', paymentMethod)
            
            // Create form data
            var formData = new FormData()
            formData.append('mark_payment_received', '1')
            formData.append('application_id', applicationId)
            if (isReceived) {
                formData.append('is_received', '1')
            }
            formData.append('amount', amount || '0')
            formData.append('payment_method', paymentMethod || 'Cash')
            formData.append('verification_notes', notes)
            
            console.log('Saving payment for application:', applicationId)
            
            // Submit via fetch
            return fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status)
                }
                return response.json().catch(function() {
                    // If not JSON, return text
                    return response.text().then(function(text) {
                        return {success: true, message: text}
                    })
                })
            }).then(function(data) {
                if (data.success === false) {
                    throw new Error(data.error || 'Unknown error')
                }
                console.log('Payment saved:', data)
                return data
            }).catch(function(error) {
                console.error('Error saving payment:', error)
                throw error
            })
        }
        
        // Save application changes (called by Save button)
        function saveApplicationChanges() {
            var viewApplicationModal = document.getElementById('viewApplicationModal')
            var content = viewApplicationModal.querySelector('#view_application_content')
            var reqSection = content.querySelector('[id^="requirements_section_"]')
            var saveBtn = document.getElementById('saveApplicationChangesBtn')
            
            if (!reqSection) {
                alert('No application data found')
                return
            }
            
            var appIdMatch = reqSection.id.match(/requirements_section_(\d+)/)
            if (!appIdMatch) {
                alert('Could not find application ID')
                return
            }
            
            var appId = appIdMatch[1]
            
            // Disable save button and show loading
            saveBtn.disabled = true
            var originalText = saveBtn.innerHTML
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'
            
            console.log('Starting save for application:', appId)
            
            // Save requirements and payment with timeout
            var saveTimeout = setTimeout(function() {
                console.error('Save operation timed out')
                alert('Save operation is taking too long. The page will reload to check the status.')
                window.location.reload()
            }, 10000) // 10 second timeout
            
            Promise.all([
                saveAllRequirements(appId),
                savePaymentIfChanged(appId)
            ]).then(function(results) {
                clearTimeout(saveTimeout)
                console.log('Save completed successfully', results)
                
                // Show success message
                saveBtn.innerHTML = '<i class="fas fa-check"></i> Saved!'
                saveBtn.classList.remove('btn-primary')
                saveBtn.classList.add('btn-success')
                
                // Reload page after 1.5 seconds to show updated status in table
                setTimeout(function() {
                    window.location.reload()
                }, 1500)
            }).catch(function(error) {
                clearTimeout(saveTimeout)
                console.error('Error saving:', error)
                alert('Error saving changes: ' + (error.message || 'Unknown error') + '. Please check the console for details.')
                saveBtn.disabled = false
                saveBtn.innerHTML = originalText
            })
        }
        
        // Review requirement function (legacy - for backward compatibility)
        function reviewRequirement(submissionId, status) {
            var notes = prompt('Enter review notes (optional):')
            if (notes === null) return
            
            var form = document.createElement('form')
            form.method = 'POST'
            form.innerHTML = '<input type="hidden" name="review_requirement" value="1">' +
                            '<input type="hidden" name="submission_id" value="' + submissionId + '">' +
                            '<input type="hidden" name="status" value="' + status + '">' +
                            '<input type="hidden" name="review_notes" value="' + (notes || '') + '">'
            document.body.appendChild(form)
            form.submit()
        }
        
        // Verify payment function (legacy - for backward compatibility)
        function verifyPayment(paymentId, status) {
            var notes = prompt('Enter verification notes (optional):')
            if (notes === null) return
            
            var form = document.createElement('form')
            form.method = 'POST'
            form.innerHTML = '<input type="hidden" name="verify_payment" value="1">' +
                            '<input type="hidden" name="payment_id" value="' + paymentId + '">' +
                            '<input type="hidden" name="status" value="' + status + '">' +
                            '<input type="hidden" name="verification_notes" value="' + (notes || '') + '">'
            document.body.appendChild(form)
            form.submit()
        }
        
        // Sidebar state persistence
        function saveSidebarState() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return;
            
            const state = {
                isHidden: sidebar.classList.contains('hidden'),
                isActive: sidebar.classList.contains('active'),
                isExpanded: document.querySelector('.main-content')?.classList.contains('expanded') || false,
                screenWidth: window.innerWidth
            };
            
            try {
                localStorage.setItem('sidebarState', JSON.stringify(state));
            } catch (e) {
                // localStorage might not be available
            }
        }
        
        function restoreSidebarState() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
            
            if (!sidebar) return;
            
            try {
                const savedState = localStorage.getItem('sidebarState');
                if (!savedState) return;
                
                const state = JSON.parse(savedState);
                const isMobile = window.innerWidth <= 768;
                const wasMobile = state.screenWidth <= 768;
                
                // Only restore if screen size category hasn't changed (mobile to mobile, or desktop to desktop)
                if ((isMobile && wasMobile) || (!isMobile && !wasMobile)) {
                    if (state.isHidden) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (overlay) overlay.classList.remove('active');
                        if (toggleBtn) {
                            if (isMobile) {
                                toggleBtn.classList.remove('hide');
                            } else {
                                toggleBtn.style.display = 'block';
                            }
                        }
                        // Set body class for desktop
                        if (!isMobile) {
                            document.body.classList.add('sidebar-closed');
                        }
                    } else {
                        sidebar.classList.remove('hidden');
                        if (isMobile && state.isActive) {
                            sidebar.classList.add('active');
                            if (overlay) overlay.classList.add('active');
                            if (toggleBtn) toggleBtn.classList.add('hide');
                            if (mainContent) mainContent.classList.remove('expanded');
                        } else if (!isMobile) {
                            sidebar.classList.remove('active');
                            document.body.classList.remove('sidebar-closed');
                            if (mainContent) {
                                if (state.isExpanded) {
                                    mainContent.classList.add('expanded');
                                } else {
                                    mainContent.classList.remove('expanded');
                                }
                            }
                            if (toggleBtn) toggleBtn.style.display = 'none';
                        }
                    }
                    
                    // Update header padding after restore
                    setTimeout(updateHeaderPadding, 0);
                }
            } catch (e) {
                // If restoration fails, use default initialization
            }
        }
        
        // Sidebar toggle functions
        // Make toggleSidebar globally accessible for onclick handler
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
                // Show sidebar
                sidebar.classList.remove('hidden');
                if (isMobile) {
                    // Mobile: show sidebar with active class and overlay
                    sidebar.classList.add('active');
                    if (overlay) {
                        overlay.classList.add('active');
                        overlay.style.display = 'block';
                    }
                    if (toggleBtn) {
                        toggleBtn.classList.add('hide');
                        toggleBtn.setAttribute('aria-expanded', 'true');
                    }
                    // Prevent body scroll when sidebar is open on mobile
                    document.body.classList.add('sidebar-open');
                } else {
                    // Desktop: just show it, no active class needed
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-closed');
                    if (toggleBtn) {
                        toggleBtn.style.display = 'none';
                        toggleBtn.classList.remove('hide');
                    }
                }
                if (mainContent) {
                    mainContent.classList.remove('expanded');
                }
            } else {
                // Sidebar is visible, toggle it
                if (isMobile) {
                    // Mobile: toggle active state
                    const newActiveState = !isActive;
                    sidebar.classList.toggle('active', newActiveState);
                    
                    if (overlay) {
                        overlay.classList.toggle('active', newActiveState);
                        overlay.style.display = newActiveState ? 'block' : 'none';
                    }
                    
                    if (toggleBtn) {
                        toggleBtn.classList.toggle('hide', newActiveState);
                        toggleBtn.setAttribute('aria-expanded', String(newActiveState));
                    }
                    
                    if (mainContent) {
                        if (newActiveState) {
                            mainContent.classList.remove('expanded');
                        } else {
                            mainContent.classList.add('expanded');
                        }
                    }
                    
                    // Toggle body scroll lock
                    if (newActiveState) {
                        document.body.classList.add('sidebar-open');
                    } else {
                        document.body.classList.remove('sidebar-open');
                    }
                } else {
                    // Desktop: hide sidebar
                    sidebar.classList.add('hidden');
                    sidebar.classList.remove('active');
                    document.body.classList.add('sidebar-closed');
                    if (mainContent) {
                        mainContent.classList.add('expanded');
                    }
                    if (toggleBtn) {
                        toggleBtn.style.display = 'block';
                        toggleBtn.classList.remove('hide');
                    }
                    if (overlay) {
                        overlay.classList.remove('active');
                        overlay.style.display = 'none';
                    }
                }
            }
            
            // Save sidebar state after toggle
            if (typeof saveSidebarState === 'function') {
                saveSidebarState();
            }
            
            // Update header padding after toggle
            if (typeof updateHeaderPadding === 'function') {
                setTimeout(updateHeaderPadding, 0);
            }
        };
        
        // Also create a non-window version for internal use
        function toggleSidebar() {
            window.toggleSidebar();
        }
        
        // Make hideSidebar globally accessible
        window.hideSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
            const isMobile = window.innerWidth <= 768;
            
            if (sidebar) {
                sidebar.classList.remove('active');
                sidebar.classList.add('hidden');
                
                if (overlay) {
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                }
                
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
                
                if (toggleBtn) {
                    if (isMobile) {
                        toggleBtn.classList.remove('hide');
                        toggleBtn.setAttribute('aria-expanded', 'false');
                    } else {
                        toggleBtn.style.display = 'block';
                        toggleBtn.classList.remove('hide');
                        toggleBtn.setAttribute('aria-expanded', 'false');
                    }
                }
                
                // Remove body scroll lock on mobile
                if (isMobile) {
                    document.body.classList.remove('sidebar-open');
                }
            }
            
            // Save sidebar state after hiding
            if (typeof saveSidebarState === 'function') {
                saveSidebarState();
            }
            
            // Update header padding after hiding
            if (typeof updateHeaderPadding === 'function') {
                setTimeout(updateHeaderPadding, 0);
            }
        };
        
        // Also create a non-window version for internal use
        function hideSidebar() {
            window.hideSidebar();
        }
        
        // Function to update header padding based on toggle button visibility
        function updateHeaderPadding() {
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const topHeader = document.querySelector('.top-header');
            
            if (toggleBtn && topHeader) {
                const isVisible = toggleBtn.offsetParent !== null && !toggleBtn.classList.contains('hide');
                if (isVisible) {
                    topHeader.classList.add('has-toggle');
                } else {
                    topHeader.classList.remove('has-toggle');
                }
            }
        }
        
        // Close sidebar when clicking on a nav item (mobile)
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.nav-item');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('mobileMenuToggle');
            const mainContent = document.querySelector('.main-content');
            const csrfToken = "<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>";
            
            // Ensure toggle button is visible on mobile and properly initialized
            if (!toggleBtn) {
                console.error('Mobile menu toggle button not found!');
                return;
            }
            
            const isMobile = window.innerWidth <= 768;
            if (isMobile) {
                // On mobile, show toggle button unless sidebar is active
                const sidebarEl = document.getElementById('sidebar');
                if (sidebarEl && sidebarEl.classList.contains('active')) {
                    toggleBtn.classList.add('hide');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                } else {
                    toggleBtn.style.display = 'flex';
                    toggleBtn.classList.remove('hide');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            } else {
                // On desktop, hide toggle button if sidebar is visible
                const sidebarEl = document.getElementById('sidebar');
                if (sidebarEl && !sidebarEl.classList.contains('hidden')) {
                    toggleBtn.style.display = 'none';
                    toggleBtn.setAttribute('aria-expanded', 'true');
                } else {
                    toggleBtn.style.display = 'block';
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            }
            
            // Add touch event support for better mobile experience
            // Remove onclick handler to prevent conflicts - use event listeners instead
            if (toggleBtn) {
                // Remove inline onclick to prevent double-firing
                toggleBtn.removeAttribute('onclick');
                
                // Prevent double-tap zoom on mobile and handle touch
                let touchStartTime = 0;
                toggleBtn.addEventListener('touchstart', function(e) {
                    touchStartTime = Date.now();
                }, { passive: true });
                
                toggleBtn.addEventListener('touchend', function(e) {
                    const touchDuration = Date.now() - touchStartTime;
                    // Only prevent default if it's a quick tap (not a scroll)
                    if (touchDuration < 300) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof window.toggleSidebar === 'function') {
                            window.toggleSidebar();
                        }
                    }
                });
                
                // Handle click events (for desktop and mobile)
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    if (typeof window.toggleSidebar === 'function') {
                        window.toggleSidebar();
                    }
                });
                
                // Ensure button is accessible
                toggleBtn.setAttribute('role', 'button');
                toggleBtn.setAttribute('aria-label', 'Toggle navigation menu');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
            
            // Ensure overlay closes sidebar on mobile
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    const sidebarEl = document.getElementById('sidebar');
                    if (window.innerWidth <= 768 && sidebarEl && sidebarEl.classList.contains('active')) {
                        if (typeof window.hideSidebar === 'function') {
                            window.hideSidebar();
                        }
                    }
                });
                
                // Add touch support for overlay
                overlay.addEventListener('touchend', function(e) {
                    const sidebarEl = document.getElementById('sidebar');
                    if (window.innerWidth <= 768 && sidebarEl && sidebarEl.classList.contains('active')) {
                        e.preventDefault();
                        if (typeof window.hideSidebar === 'function') {
                            window.hideSidebar();
                        }
                    }
                });
            }
            
            // Initial check for header padding
            if (typeof updateHeaderPadding === 'function') {
                updateHeaderPadding();
            }
            
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        if (sidebar) {
                            sidebar.classList.remove('active');
                            sidebar.classList.add('hidden');
                        }
                        if (overlay) {
                            overlay.classList.remove('active');
                            overlay.style.display = 'none';
                        }
                        if (mainContent) {
                            mainContent.classList.add('expanded');
                        }
                        if (toggleBtn) {
                            toggleBtn.classList.remove('hide');
                            toggleBtn.setAttribute('aria-expanded', 'false');
                        }
                        // Remove body scroll lock
                        document.body.classList.remove('sidebar-open');
                        if (typeof saveSidebarState === 'function') {
                            saveSidebarState();
                        }
                        if (typeof updateHeaderPadding === 'function') {
                            setTimeout(updateHeaderPadding, 0);
                        }
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
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'block';
                        saveSidebarState();
                        setTimeout(updateHeaderPadding, 0);
                    }
                } else if (sidebar && sidebar.classList.contains('active')) {
                    // On mobile, hide sidebar
                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('active');
                        sidebar.classList.add('hidden');
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.add('expanded');
                        if (toggleBtn) toggleBtn.classList.remove('hide');
                        saveSidebarState();
                        setTimeout(updateHeaderPadding, 0);
                    }
                }
            });
            
            // Handle window resize - preserve user's preference when possible
            let resizeTimeout;
            let previousScreenWidth = window.innerWidth;
            
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(function() {
                    const isMobile = window.innerWidth <= 768;
                    const wasMobile = previousScreenWidth <= 768;
                    
                    // Only reset if switching between mobile and desktop
                    // Otherwise, preserve current state
                    if (isMobile && !wasMobile) {
                        // Switched to mobile: hide sidebar
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
                        document.body.classList.remove('sidebar-closed');
                        saveSidebarState();
                    } else if (!isMobile && wasMobile) {
                        // Switched to desktop: show sidebar (default)
                        if (sidebar) {
                            sidebar.classList.remove('active');
                            sidebar.classList.remove('hidden');
                        }
                        document.body.classList.remove('sidebar-closed');
                        if (overlay) overlay.classList.remove('active');
                        if (mainContent) mainContent.classList.remove('expanded');
                        if (toggleBtn) toggleBtn.style.display = 'none';
                        saveSidebarState();
                    } else {
                        // Same category - just save current state
                        saveSidebarState();
                    }
                    
                    // Update previous screen width
                    previousScreenWidth = window.innerWidth;
                    
                    // Update header padding after resize
                    setTimeout(updateHeaderPadding, 0);
                }, 150);
            });
            
            // Initialize previous screen width
            previousScreenWidth = window.innerWidth;
            
            // Sidebar state is already restored by inline script before DOMContentLoaded
            // Just ensure the data attribute is set and update header padding
            const sidebarCheck = document.getElementById('sidebar');
            if (sidebarCheck && !sidebarCheck.hasAttribute('data-state-restored')) {
                // Fallback: if inline script didn't run, restore now
                restoreSidebarState();
                sidebarCheck.setAttribute('data-state-restored', 'true');
            }
            
            // Update header padding after initialization
            setTimeout(updateHeaderPadding, 0);
            
            // Ensure all POST forms carry a CSRF token (guard against dynamic modals)
            if (csrfToken) {
                document.querySelectorAll('form[method="POST"]').forEach(form => {
                    const hasToken = form.querySelector('input[name="csrf_token"]');
                    if (!hasToken) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'csrf_token';
                        input.value = csrfToken;
                        form.appendChild(input);
                    } else if (!hasToken.value) {
                        hasToken.value = csrfToken;
                    }
                });
            }
            
        });

        // Global confirmation handler for user actions (edit, password, delete, etc.)
        (function setupUserActionConfirmation() {
            const actionVerbMap = {
                delete: 'delete',
                update: 'update',
                password: 'change the password for',
                delete_subject: 'delete this course',
                delete_course: 'delete this course',
                delete_section: 'delete this section',
                delete_schedule: 'delete this schedule',
                delete_enrollment_period: 'delete this enrollment period',
                delete_backup: 'delete this backup',
                create_backup: 'create',
                remove_student: 'remove'
            };

            // Actions that should open modals directly without confirmation
            const directModalActions = ['update', 'password'];

            // Handle both click and touch events for mobile compatibility
            const handleActionClick = function(event) {
                const trigger = event.target.closest('[data-confirm-action]');
                if (!trigger || trigger.dataset.confirmBypass === 'true') {
                    return;
                }
                
                // Only proceed if this is a genuine user click/touch event
                if (!event.isTrusted) {
                    return; // Ignore programmatic clicks
                }
                
                // Prevent disabled buttons from triggering
                if (trigger.disabled || trigger.classList.contains('disabled')) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                
                // Check if button is visible (not hidden)
                const styles = window.getComputedStyle(trigger);
                if (styles.display === 'none' || styles.visibility === 'hidden' || styles.opacity === '0') {
                    return;
                }

                const actionKey = trigger.getAttribute('data-confirm-action');
                
                // For Edit and Password actions, open modal directly without confirmation
                if (directModalActions.includes(actionKey)) {
                    const modalTarget = trigger.getAttribute('data-modal-target');
                    if (modalTarget) {
                        const modalElement = document.querySelector(modalTarget);
                        if (modalElement && window.bootstrap && bootstrap.Modal) {
                            event.preventDefault();
                            event.stopPropagation();
                            // Bootstrap modal event will populate data via event.relatedTarget
                            const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
                            modalInstance.show(trigger);
                            return;
                        }
                    }
                }

                // For other actions (like delete), show confirmation dialog
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                pendingConfirmTrigger = trigger;
                const verb = actionVerbMap[actionKey] || actionKey;
                const targetName = trigger.getAttribute('data-confirm-target')
                    || trigger.getAttribute('data-user-name')
                    || trigger.getAttribute('data-subject-name')
                    || 'this item';
                const warning = trigger.getAttribute('data-confirm-warning');
                const targetLabel = warning ? `${targetName}\n${warning}` : targetName;
                const customTitle = trigger.getAttribute('data-confirm-title');
                const customMessage = trigger.getAttribute('data-confirm-message');
                const customButton = trigger.getAttribute('data-confirm-button');

                openActionConfirm({
                    title: customTitle || 'Confirm Action',
                    message: customMessage || `Are you sure you want to ${verb}?`,
                    targetLabel,
                    confirmLabel: customButton || `Yes, ${verb.charAt(0).toUpperCase()}${verb.slice(1)}`
                }).then((confirmed) => {
                    if (!confirmed) {
                        pendingConfirmTrigger = null;
                        return;
                    }
                    executeConfirmedAction(pendingConfirmTrigger);
                    pendingConfirmTrigger = null;
                });
            };
            
            // Attach to both click and touchstart events for mobile compatibility
            document.addEventListener('click', handleActionClick, true);
            
            // Also handle touch events for better mobile support
            if ('ontouchstart' in window) {
                document.addEventListener('touchstart', function(event) {
                    const trigger = event.target.closest('[data-confirm-action]');
                    if (trigger && !trigger.dataset.confirmBypass) {
                        // Convert touch to click for consistency
                        const clickEvent = new MouseEvent('click', {
                            bubbles: true,
                            cancelable: true,
                            view: window,
                            isTrusted: true
                        });
                        trigger.dispatchEvent(clickEvent);
                    }
                }, { passive: true });
            }
        })();

        // Removed auto-show modal function - modals should only appear on user action
        // Course update feedback is now shown inline in the course alert area
        
        
        // Bulk Operations Functions
        function updateBulkSelection() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.getAttribute('data-user-id'));
            document.getElementById('selectedIds').value = selectedIds.join(',');
            document.getElementById('selectedCount').textContent = selectedIds.length + ' selected';
            document.getElementById('bulkActionBtn').disabled = selectedIds.length === 0 || !document.getElementById('bulkActionSelect').value;
        }
        
        function selectAllUsers() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = true);
            updateBulkSelection();
        }
        
        function deselectAllUsers() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
            updateBulkSelection();
        }
        
        function toggleAdminSelection(checked) {
            document.querySelectorAll('tbody tr').forEach(row => {
                const checkbox = row.querySelector('.user-checkbox');
                if (checkbox && row.closest('table').querySelector('thead th:first-child input') === document.getElementById('selectAllAdmins')) {
                    checkbox.checked = checked;
                }
            });
            updateBulkSelection();
        }
        
        function toggleStaffSelection(checked) {
            document.querySelectorAll('tbody tr').forEach(row => {
                const checkbox = row.querySelector('.user-checkbox');
                if (checkbox && row.closest('table').querySelector('thead th:first-child input') === document.getElementById('selectAllStaffs')) {
                    checkbox.checked = checked;
                }
            });
            updateBulkSelection();
        }
        
        function toggleStudentSelection(checked) {
            document.querySelectorAll('tbody tr').forEach(row => {
                const checkbox = row.querySelector('.user-checkbox');
                if (checkbox && row.closest('table').querySelector('thead th:first-child input') === document.getElementById('selectAllStudents')) {
                    checkbox.checked = checked;
                }
            });
            updateBulkSelection();
        }
        
        // Enable/disable bulk action button based on selection
        document.addEventListener('DOMContentLoaded', function() {
            const bulkActionSelect = document.getElementById('bulkActionSelect');
            const bulkActionBtn = document.getElementById('bulkActionBtn');
            
            if (bulkActionSelect) {
                bulkActionSelect.addEventListener('change', function() {
                    const selectedIds = document.getElementById('selectedIds').value;
                    bulkActionBtn.disabled = !this.value || !selectedIds;
                });
            }
            
            // Update form submission to include selected IDs
            const bulkForm = document.getElementById('bulkActionForm');
            if (bulkForm) {
                bulkForm.addEventListener('submit', function(e) {
                    const selectedIdsValue = document.getElementById('selectedIds').value;
                    if (!selectedIdsValue) {
                        e.preventDefault();
                        alert('Please select at least one user');
                        return false;
                    }
                    const selectedIds = selectedIdsValue.split(',').filter(Boolean);
                    if (selectedIds.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one user');
                        return false;
                    }

                    const actionKey = document.getElementById('bulkActionSelect').value;
                    if (!actionKey) {
                        e.preventDefault();
                        alert('Please choose an action to apply.');
                        return false;
                    }
                    const bulkVerbMap = {
                        delete_users: 'delete',
                        activate_users: 'activate',
                        deactivate_users: 'deactivate'
                    };
                    const verb = bulkVerbMap[actionKey] || 'apply this action to';

                    e.preventDefault();
                    openActionConfirm({
                        title: 'Confirm Bulk Action',
                        message: `Are you sure you want to ${verb} ${selectedIds.length} selected user(s)?`,
                        targetLabel: 'This operation cannot be undone.',
                        confirmLabel: 'Yes, Proceed'
                    }).then((confirmed) => {
                        if (!confirmed) {
                            return;
                        }
                        // Ensure the hidden field carries the cleaned list for the server
                        document.getElementById('selectedIds').value = selectedIds.join(',');
                        bulkForm.submit();
                    });
                });
            }
        });
        
        // Profile Picture Preview Functions
        function previewProfilePicture(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileImgPreview').src = e.target.result;
                    document.getElementById('profileImgPreview').style.display = 'block';
                    document.getElementById('profileInitials').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Make base URL and assets path available to JavaScript
        const baseUrl = '<?= $baseUrl ?>';
        const assetsBasePath = '<?= $assetsPath ?>';
        
        // Helper function to build API URLs
        function getApiUrl(endpoint) {
            // Remove leading slash from endpoint if present
            endpoint = endpoint.replace(/^\//, '');
            // Check if endpoint already includes base path
            if (endpoint.startsWith('backend/') || endpoint.startsWith('admin/') || endpoint.startsWith('student/') || endpoint.startsWith('teacher/') || endpoint.startsWith('auth/')) {
                return baseUrl + '/' + endpoint;
            }
            // For relative paths like 'api/get_csrf.php', determine if it's backend or frontend
            if (endpoint.startsWith('api/')) {
                return baseUrl + '/backend/student-management/' + endpoint;
            }
            return baseUrl + '/' + endpoint;
        }
        
        function previewUploadPicture(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('uploadImgPreview').src = e.target.result;
                    document.getElementById('uploadImgPreview').style.display = 'block';
                    document.getElementById('uploadInitials').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Search and Filter Functions
        function filterApplications() {
            const searchTerm = document.getElementById('applicationSearch')?.value.toLowerCase() || '';
            const statusFilter = document.getElementById('applicationStatusFilter')?.value.toLowerCase() || '';
            const programFilter = document.getElementById('applicationProgramFilter')?.value.toLowerCase() || '';
            const filterDate = document.getElementById('applicationDateFilter')?.value || '';
            const rows = document.querySelectorAll('#applicationsTableBody .application-row');
            const noResults = document.getElementById('noApplicationResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const appNumber = row.getAttribute('data-app-number') || '';
                const studentName = row.getAttribute('data-student-name') || '';
                const email = row.getAttribute('data-email') || '';
                const program = row.getAttribute('data-program') || '';
                const status = row.getAttribute('data-status') || '';
                const appDate = row.getAttribute('data-application-date') || '';
                
                const matchesSearch = !searchTerm || appNumber.includes(searchTerm) || studentName.includes(searchTerm) || email.includes(searchTerm);
                const matchesStatus = !statusFilter || status === statusFilter;
                const matchesProgram = !programFilter || program === programFilter;
                const matchesDate = !filterDate || appDate === filterDate;
                
                if (matchesSearch && matchesStatus && matchesProgram && matchesDate) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || statusFilter || programFilter || filterDate)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
        
        function filterTeachers() {
            const searchTerm = document.getElementById('teacherSearch')?.value.toLowerCase() || '';
            const departmentFilter = document.getElementById('teacherDepartmentFilter')?.value.toLowerCase() || '';
            const rows = document.querySelectorAll('#teachersTableBody .teacher-row');
            const noResults = document.getElementById('noTeacherResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const teacherName = row.getAttribute('data-teacher-name') || '';
                const email = row.getAttribute('data-teacher-email') || '';
                const department = row.getAttribute('data-department') || '';
                
                const matchesSearch = !searchTerm || teacherName.includes(searchTerm) || email.includes(searchTerm) || department.includes(searchTerm);
                const matchesDepartment = !departmentFilter || department === departmentFilter;
                
                if (matchesSearch && matchesDepartment) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || departmentFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
        
        // Helper to show alerts near the add course form (no auto-hide by default)
        function showCourseAlert(message, type = 'success', autoHideMs = null) {
            const alertBox = document.getElementById('courseAlert');
            if (!alertBox) return;
            alertBox.className = `mb-3 alert alert-${type}`;
            alertBox.textContent = message;
            alertBox.style.display = 'block';
            if (autoHideMs && Number.isFinite(autoHideMs)) {
                setTimeout(() => {
                    alertBox.style.display = 'none';
                }, autoHideMs);
            }
        }
        
        // Confirmation dialog for adding new subject (AJAX submit + live refresh)
        function confirmAddSubject(event) {
            event.preventDefault();
            const form = event.target;
            const subjectName = form.querySelector('input[name="name"]').value.trim();
            const subjectCode = form.querySelector('input[name="code"]').value.trim();
            const units = form.querySelector('input[name="units"]').value || '3.0';
            const program = form.querySelector('select[name="program"]').value || '';
            const yearLevel = form.querySelector('select[name="year_level"]').value || '';
            const submitBtn = form.querySelector('.add-subject-btn');
            const resetButtonState = () => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Add';
                }
            };
            if (submitBtn) {
                submitBtn.dataset.originalText = submitBtn.textContent;
                submitBtn.textContent = 'Saving...';
                submitBtn.disabled = true;
            }
            
            if (!subjectName || !subjectCode) {
                resetButtonState();
                form.submit();
                return false;
            }
            
            // First check for duplicates via AJAX
            fetch('?action=check_subject_duplicate&name=' + encodeURIComponent(subjectName) + '&code=' + encodeURIComponent(subjectCode), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    // Show error popup
                    openActionConfirm({
                        title: 'Duplicate Course',
                        message: data.message,
                        targetLabel: data.details || '',
                        confirmLabel: 'OK',
                        showCancel: false
                    }).then(() => {
                        resetButtonState();
                        showCourseAlert(data.message, 'danger');
                    });
                } else {
                    // No duplicate, proceed with confirmation
                    // Build details string
                    let details = `${subjectCode}`;
                    if (subjectName !== subjectCode) {
                        details += ` - ${subjectName}`;
                    }
                    if (program && program !== '') {
                        details += `\nProgram: ${program}`;
                    }
                    if (yearLevel && yearLevel !== '') {
                        details += `\nYear Level: ${yearLevel}`;
                    }
                    details += `\nUnits: ${units}`;
                    
                    // Show confirmation modal
                    openActionConfirm({
                        title: 'Confirm Action',
                        message: 'Are you sure you want to add this course?',
                        targetLabel: details,
                        confirmLabel: 'Yes, Add this course'
                    }).then((confirmed) => {
                        if (confirmed) {
                            // Proceed with AJAX submission so the list updates without a full reload
                            const formData = new FormData(form);
                            // Ensure the PHP handler sees the submit action
                            if (!formData.has('add_subject')) {
                                formData.append('add_subject', '1');
                            }
                            formData.append('is_ajax', '1');
                            
                            fetch(form.getAttribute('action') || window.location.href, {
                                method: 'POST',
                                body: formData,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(async resp => {
                                if (!resp.ok) {
                                    throw new Error('Request failed');
                                }
                                try {
                                    return await resp.json();
                                } catch (err) {
                                    throw new Error('Invalid response');
                                }
                            })
                            .then(data => {
                                if (data && data.success) {
                                    const tbody = document.getElementById('subjectsTableBody');
                                    if (tbody && data.row_html) {
                                        const tmp = document.createElement('tbody');
                                        tmp.innerHTML = data.row_html.trim();
                                        const newRow = tmp.firstElementChild;
                                        if (newRow) {
                                            tbody.insertBefore(newRow, tbody.firstChild);
                                        }
                                    }
                                    form.reset();
                                    if (typeof filterSubjects === 'function') {
                                        filterSubjects();
                                    }
                                    showCourseAlert(data.message || 'Course added successfully!', 'success');
                                    // Ensure the newest data is reflected even if DOM injection fails
                                    setTimeout(() => {
                                        if (!tbody) {
                                            window.location.reload();
                                        }
                                    }, 300);
                                } else {
                                    showCourseAlert((data && data.message) || 'Please check the form and try again.', 'danger');
                                }
                            })
                            .catch(() => {
                                showCourseAlert('Network error, retrying with page submit...', 'warning');
                                form.submit();
                            })
                            .finally(resetButtonState);
                        } else {
                            resetButtonState();
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error checking duplicate:', error);
                let details = `${subjectCode}`;
                if (subjectName !== subjectCode) {
                    details += ` - ${subjectName}`;
                }
                if (program && program !== '') {
                    details += `\nProgram: ${program}`;
                }
                if (yearLevel && yearLevel !== '') {
                    details += `\nYear Level: ${yearLevel}`;
                }
                details += `\nUnits: ${units}`;
                
                openActionConfirm({
                    title: 'Confirm Action',
                    message: 'Are you sure you want to add this course?',
                    targetLabel: details,
                    confirmLabel: 'Yes, Add this course'
                }).then((confirmed) => {
                    if (confirmed) {
                        form.submit();
                    }
                    resetButtonState();
                });
            });
            
            return false;
        }
        
        function filterSubjects() {
            const searchTerm = document.getElementById('subjectSearch')?.value.toLowerCase() || '';
            const programFilter = document.getElementById('subjectProgramFilter')?.value.toLowerCase() || '';
            const yearLevelFilter = document.getElementById('subjectYearLevelFilter')?.value.toLowerCase() || '';
            const rows = document.querySelectorAll('#subjectsTableBody .subject-row');
            const noResults = document.getElementById('noSubjectResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const subjectName = row.getAttribute('data-subject-name') || '';
                const subjectCode = row.getAttribute('data-subject-code') || '';
                const program = row.getAttribute('data-program') || '';
                const yearLevel = row.getAttribute('data-year-level') || '';
                
                const matchesSearch = !searchTerm || subjectName.includes(searchTerm) || subjectCode.includes(searchTerm);
                const matchesProgram = !programFilter || (program && program.toLowerCase() === programFilter.toLowerCase());
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                
                if (matchesSearch && matchesProgram && matchesYearLevel) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || programFilter || yearLevelFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
        
        function filterCourses() {
            const searchTerm = document.getElementById('courseSearch')?.value.toLowerCase() || '';
            const statusFilter = document.getElementById('courseStatusFilter')?.value.toLowerCase() || '';
            const rows = document.querySelectorAll('#coursesTableBody .course-row');
            const noResults = document.getElementById('noCourseResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const courseName = row.getAttribute('data-course-name') || '';
                const courseCode = row.getAttribute('data-course-code') || '';
                const courseStatus = row.getAttribute('data-course-status') || '';
                
                const matchesSearch = !searchTerm || courseName.includes(searchTerm) || courseCode.includes(searchTerm);
                const matchesStatus = !statusFilter || courseStatus === statusFilter;
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || statusFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
        
        function filterSections() {
            const searchTerm = document.getElementById('sectionSearch')?.value.toLowerCase() || '';
            const courseFilter = document.getElementById('sectionCourseFilter')?.value.toLowerCase() || '';
            const yearLevelFilter = document.getElementById('sectionYearLevelFilter')?.value.toLowerCase() || '';
            const semesterFilter = document.getElementById('sectionSemesterFilter')?.value.toLowerCase() || '';
            const academicYearFilter = document.getElementById('sectionAcademicYearFilter')?.value || '';
            const rows = document.querySelectorAll('#sectionsTableBody .section-row');
            const noResults = document.getElementById('noSectionResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const sectionName = row.getAttribute('data-section-name') || '';
                const courseName = row.getAttribute('data-course-name') || '';
                const courseCode = row.getAttribute('data-course-code') || '';
                const yearLevel = row.getAttribute('data-year-level') || '';
                const semester = row.getAttribute('data-semester') || '';
                const academicYear = row.getAttribute('data-academic-year') || '';
                
                const matchesSearch = !searchTerm || sectionName.includes(searchTerm) || courseName.includes(searchTerm) || courseCode.includes(searchTerm);
                const matchesCourse = !courseFilter || courseCode === courseFilter;
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                const matchesSemester = !semesterFilter || semester === semesterFilter;
                const matchesAcademicYear = !academicYearFilter || academicYear === academicYearFilter;
                
                if (matchesSearch && matchesCourse && matchesYearLevel && matchesSemester && matchesAcademicYear) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || courseFilter || yearLevelFilter || semesterFilter || academicYearFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }

        function filterSchedules() {
            const searchTerm = document.getElementById('scheduleSearch')?.value.toLowerCase() || '';
            const sectionFilter = document.getElementById('scheduleSectionFilter')?.value.toLowerCase() || '';
            const dayFilter = document.getElementById('scheduleDayFilter')?.value.toLowerCase() || '';
            const rows = document.querySelectorAll('#schedulesTableBody .schedule-row');
            const noResults = document.getElementById('noScheduleResults');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const section = row.getAttribute('data-section') || '';
                const subject = row.getAttribute('data-subject') || '';
                const day = row.getAttribute('data-day') || '';
                
                const matchesSearch = !searchTerm || section.includes(searchTerm) || subject.includes(searchTerm);
                const matchesSection = !sectionFilter || section === sectionFilter;
                const matchesDay = !dayFilter || day === dayFilter;
                
                if (matchesSearch && matchesSection && matchesDay) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || sectionFilter || dayFilter)) {
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
                
                // Use baseUrl from PHP to ensure correct web path (not file:///)
                const keepAliveUrl = baseUrl + '/session-keepalive.php';
                
                fetch(keepAliveUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Expected JSON but got: ' + contentType);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'expired') {
                        // Session expired, redirect to login
                        clearInterval(keepAliveInterval);
                        window.location.href = '../../auth/staff-login.php';
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
    
    <!-- Grade Approval Modals -->
    <div class="modal fade" id="approveGradeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="action" value="approve_grade">
                    <input type="hidden" name="grade_id" id="approve_grade_id">
                    <div class="modal-body">
                        <p>Are you sure you want to approve this grade?</p>
                        <div class="alert alert-info">
                            <strong>Student:</strong> <span id="approve_student_name"></span><br>
                            <strong>Subject:</strong> <span id="approve_subject_name"></span><br>
                            <strong>Grade:</strong> <span id="approve_grade_value"></span>
                        </div>
                        <p class="text-muted small">
                            Once approved, the grade will be locked and immediately visible to the student.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Grade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectGradeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="action" value="reject_grade">
                    <input type="hidden" name="grade_id" id="reject_grade_id">
                    <div class="modal-body">
                        <p>Please provide a reason for rejecting this grade:</p>
                        <div class="alert alert-warning">
                            <strong>Student:</strong> <span id="reject_student_name"></span><br>
                            <strong>Subject:</strong> <span id="reject_subject_name"></span>
                        </div>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                            <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required 
                                      placeholder="Please explain why this grade is being rejected..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Grade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Enhanced Admin Panel Button Handlers with Mobile Support and Loading States
    
    // Utility function to show loading state
    function setButtonLoading(button, isLoading) {
        if (!button) return;
        
        if (isLoading) {
            button.dataset.originalText = button.innerHTML;
            button.disabled = true;
            button.style.opacity = '0.6';
            button.style.cursor = 'not-allowed';
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
        } else {
            button.disabled = false;
            button.style.opacity = '1';
            button.style.cursor = 'pointer';
            if (button.dataset.originalText) {
                button.innerHTML = button.dataset.originalText;
                delete button.dataset.originalText;
            }
        }
    }
    
    // Utility function to show error message
    function showErrorMessage(message, container) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
        alertDiv.innerHTML = `
            <strong>Error:</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        const targetContainer = container || document.querySelector('.main-content');
        if (targetContainer) {
            targetContainer.insertBefore(alertDiv, targetContainer.firstChild);
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
    }
    
    // Utility function to show success message
    function showSuccessMessage(message, container) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show';
        alertDiv.innerHTML = `
            <strong>Success:</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        const targetContainer = container || document.querySelector('.main-content');
        if (targetContainer) {
            targetContainer.insertBefore(alertDiv, targetContainer.firstChild);
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 3000);
        }
    }
    
    // Modal functions with error handling
    function showApproveModal(gradeId, studentName, subjectName, gradeValue) {
        try {
            const gradeIdEl = document.getElementById('approve_grade_id');
            const studentNameEl = document.getElementById('approve_student_name');
            const subjectNameEl = document.getElementById('approve_subject_name');
            const gradeValueEl = document.getElementById('approve_grade_value');
            const modalEl = document.getElementById('approveGradeModal');
            
            if (!gradeIdEl || !studentNameEl || !subjectNameEl || !gradeValueEl || !modalEl) {
                console.error('Approve modal elements not found');
                showErrorMessage('Unable to open approval modal. Please refresh the page.');
                return;
            }
            
            gradeIdEl.value = gradeId;
            studentNameEl.textContent = studentName;
            subjectNameEl.textContent = subjectName;
            gradeValueEl.textContent = gradeValue;
            
            if (window.bootstrap && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                $(modalEl).modal('show');
            }
        } catch (error) {
            console.error('Error showing approve modal:', error);
            showErrorMessage('An error occurred while opening the approval modal.');
        }
    }

    function showRejectModal(gradeId, studentName, subjectName) {
        try {
            const gradeIdEl = document.getElementById('reject_grade_id');
            const studentNameEl = document.getElementById('reject_student_name');
            const subjectNameEl = document.getElementById('reject_subject_name');
            const rejectionReasonEl = document.getElementById('rejection_reason');
            const modalEl = document.getElementById('rejectGradeModal');
            
            if (!gradeIdEl || !studentNameEl || !subjectNameEl || !modalEl) {
                console.error('Reject modal elements not found');
                showErrorMessage('Unable to open rejection modal. Please refresh the page.');
                return;
            }
            
            gradeIdEl.value = gradeId;
            studentNameEl.textContent = studentName;
            subjectNameEl.textContent = subjectName;
            if (rejectionReasonEl) {
                rejectionReasonEl.value = '';
            }
            
            if (window.bootstrap && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                $(modalEl).modal('show');
            }
        } catch (error) {
            console.error('Error showing reject modal:', error);
            showErrorMessage('An error occurred while opening the rejection modal.');
        }
    }
    
    // Teacher Requests Modals
    function showApproveRequestModal(requestId, teacherName, subjectName) {
        try {
            const requestIdEl = document.getElementById('approve_edit_request_id');
            const teacherEl = document.getElementById('approve_request_teacher');
            const subjectEl = document.getElementById('approve_request_subject');
            const notesEl = document.getElementById('approve_request_notes');
            const modalEl = document.getElementById('approveRequestModal');
            
            if (!requestIdEl || !teacherEl || !subjectEl || !modalEl) {
                console.error('Approve request modal elements not found');
                showErrorMessage('Unable to open approval modal. Please refresh the page.');
                return;
            }
            
            requestIdEl.value = requestId;
            teacherEl.textContent = teacherName;
            subjectEl.textContent = subjectName;
            if (notesEl) {
                notesEl.value = '';
            }
            
            if (window.bootstrap && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                $(modalEl).modal('show');
            }
        } catch (error) {
            console.error('Error showing approve request modal:', error);
            showErrorMessage('An error occurred while opening the approval modal.');
        }
    }
    
    function showDenyRequestModal(requestId, teacherName, subjectName) {
        try {
            const requestIdEl = document.getElementById('deny_request_id');
            const teacherEl = document.getElementById('deny_request_teacher');
            const subjectEl = document.getElementById('deny_request_subject');
            const notesEl = document.getElementById('deny_request_notes');
            const modalEl = document.getElementById('denyRequestModal');
            
            if (!requestIdEl || !teacherEl || !subjectEl || !modalEl) {
                console.error('Deny request modal elements not found');
                showErrorMessage('Unable to open denial modal. Please refresh the page.');
                return;
            }
            
            requestIdEl.value = requestId;
            teacherEl.textContent = teacherName;
            subjectEl.textContent = subjectName;
            if (notesEl) {
                notesEl.value = '';
            }
            
            if (window.bootstrap && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                $(modalEl).modal('show');
            }
        } catch (error) {
            console.error('Error showing deny request modal:', error);
            showErrorMessage('An error occurred while opening the denial modal.');
        }
    }
    
    function showCompleteEditModal(gradeId, studentName, subjectName) {
        try {
            const gradeIdEl = document.getElementById('complete_grade_id');
            const studentNameEl = document.getElementById('complete_student_name');
            const subjectNameEl = document.getElementById('complete_subject_name');
            const modalEl = document.getElementById('completeEditModal');
            
            if (!gradeIdEl || !studentNameEl || !subjectNameEl || !modalEl) {
                console.error('Complete edit modal elements not found');
                showErrorMessage('Unable to open completion modal. Please refresh the page.');
                return;
            }
            
            gradeIdEl.value = gradeId;
            studentNameEl.textContent = studentName;
            subjectNameEl.textContent = subjectName;
            
            if (window.bootstrap && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                $(modalEl).modal('show');
            }
        } catch (error) {
            console.error('Error showing complete edit modal:', error);
            showErrorMessage('An error occurred while opening the completion modal.');
        }
    }
    
        // Enhanced event listeners for all admin buttons
        // Use event delegation to handle dynamically generated buttons
        // This runs immediately, not waiting for DOMContentLoaded, to catch all buttons
        (function() {
            document.addEventListener('click', function(e) {
                const button = e.target.closest('.admin-action-btn');
                if (!button || button.disabled) return;
                
                // Don't interfere with data-confirm-action buttons
                if (button.hasAttribute('data-confirm-action')) {
                    return; // Let the existing confirmation system handle it
                }
                
                e.preventDefault();
                e.stopPropagation();
                
                const action = button.getAttribute('data-action');
                if (!action) return;
                
                try {
                    if (action === 'approve') {
                        const gradeId = button.getAttribute('data-grade-id');
                        const studentName = button.getAttribute('data-student-name');
                        const subjectName = button.getAttribute('data-subject-name');
                        const gradeValue = button.getAttribute('data-grade-value');
                        if (gradeId && studentName && subjectName) {
                            showApproveModal(gradeId, studentName, subjectName, gradeValue);
                        }
                    } else if (action === 'reject') {
                        const gradeId = button.getAttribute('data-grade-id');
                        const studentName = button.getAttribute('data-student-name');
                        const subjectName = button.getAttribute('data-subject-name');
                        if (gradeId && studentName && subjectName) {
                            showRejectModal(gradeId, studentName, subjectName);
                        }
                    } else if (action === 'approve-request') {
                        const requestId = button.getAttribute('data-request-id');
                        const teacherName = button.getAttribute('data-teacher-name');
                        const subjectName = button.getAttribute('data-subject-name');
                        if (requestId && teacherName && subjectName) {
                            showApproveRequestModal(requestId, teacherName, subjectName);
                        }
                    } else if (action === 'deny-request') {
                        const requestId = button.getAttribute('data-request-id');
                        const teacherName = button.getAttribute('data-teacher-name');
                        const subjectName = button.getAttribute('data-subject-name');
                        if (requestId && teacherName && subjectName) {
                            showDenyRequestModal(requestId, teacherName, subjectName);
                        }
                    } else if (action === 'complete-edit') {
                        const gradeId = button.getAttribute('data-grade-id');
                        const studentName = button.getAttribute('data-student-name');
                        const subjectName = button.getAttribute('data-subject-name');
                        if (gradeId && studentName && subjectName) {
                            showCompleteEditModal(gradeId, studentName, subjectName);
                        }
                    }
                } catch (error) {
                    console.error('Error handling admin action:', error);
                    showErrorMessage('An error occurred. Please try again.');
                }
            }, true); // Use capture phase to ensure we catch events early
        })();
        
        // Initialize existing buttons on page load
        document.addEventListener('DOMContentLoaded', function() {
        
        // Handle form submissions with loading states
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
                if (submitButton && !submitButton.disabled) {
                    setButtonLoading(submitButton, true);
                }
            });
        });
        
        // Enhanced confirmation for delete actions with mobile support
        document.querySelectorAll('a[href*="action=delete"], button[data-action="delete"]').forEach(button => {
            if (!button.hasAttribute('data-confirm-action')) {
                button.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    const action = this.getAttribute('data-action');
                    
                    if (!href && !action) return;
                    
                    // Check if already confirmed
                    if (this.dataset.confirmed === 'true') {
                        this.dataset.confirmed = 'false';
                        return; // Allow default action
                    }
                    
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const itemName = this.getAttribute('data-item-name') || 'this item';
                    const warning = this.getAttribute('data-warning') || '';
                    
                    if (confirm(`Are you sure you want to delete ${itemName}?${warning ? '\n\n' + warning : ''}`)) {
                        setButtonLoading(this, true);
                        this.dataset.confirmed = 'true';
                        if (href) {
                            window.location.href = href;
                        } else {
                            this.click();
                        }
                    }
                });
            }
        });
        
        // Mobile touch event support for buttons
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, button, a.btn').forEach(button => {
                button.addEventListener('touchstart', function(e) {
                    this.style.transform = 'scale(0.98)';
                }, { passive: true });
                
                button.addEventListener('touchend', function(e) {
                    this.style.transform = '';
                }, { passive: true });
            });
        }
        
        // Ensure all buttons with data-confirm-action work properly
        document.querySelectorAll('[data-confirm-action]').forEach(button => {
            // Add touch-friendly class
            button.classList.add('touch-friendly');
            
            // Ensure button is not disabled incorrectly
            if (button.hasAttribute('disabled') && button.getAttribute('disabled') === 'false') {
                button.removeAttribute('disabled');
            }
        });
        
        // Handle AJAX form submissions
        document.querySelectorAll('form[data-ajax="true"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const submitButton = form.querySelector('button[type="submit"]');
                const formData = new FormData(form);
                
                setButtonLoading(submitButton, true);
                
                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    setButtonLoading(submitButton, false);
                    if (data.success) {
                        showSuccessMessage(data.message || 'Operation completed successfully.');
                        if (data.redirect) {
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 1500);
                        } else if (data.reload) {
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        showErrorMessage(data.message || 'An error occurred.');
                    }
                })
                .catch(error => {
                    setButtonLoading(submitButton, false);
                    console.error('AJAX error:', error);
                    showErrorMessage('Network error. Please try again.');
                });
            });
        });
    });
    
    // Make functions globally available for backward compatibility
    window.showApproveModal = showApproveModal;
    window.showRejectModal = showRejectModal;
    window.showApproveRequestModal = showApproveRequestModal;
    window.showDenyRequestModal = showDenyRequestModal;
    window.showCompleteEditModal = showCompleteEditModal;
    </script>
    
    <!-- Teacher Requests Modals -->
    <div class="modal fade" id="approveRequestModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Edit Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="action" value="approve_edit_request">
                    <input type="hidden" name="request_id" id="approve_edit_request_id">
                    <div class="modal-body">
                        <p>Are you sure you want to approve this edit request?</p>
                        <div class="alert alert-info">
                            <strong>Teacher:</strong> <span id="approve_request_teacher"></span><br>
                            <strong>Subject:</strong> <span id="approve_request_subject"></span>
                        </div>
                        <div class="mb-3">
                            <label for="approve_request_notes" class="form-label">Review Notes (Optional)</label>
                            <textarea name="review_notes" id="approve_request_notes" class="form-control" rows="3" 
                                      placeholder="Add any notes about this approval..."></textarea>
                        </div>
                        <p class="text-muted small">
                            Once approved, the teacher will be able to edit the grade once. After they submit the edit, you'll need to re-approve it.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="denyRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deny Edit Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="action" value="deny_edit_request">
                    <input type="hidden" name="request_id" id="deny_request_id">
                    <div class="modal-body">
                        <p>Please provide a reason for denying this edit request:</p>
                        <div class="alert alert-warning">
                            <strong>Teacher:</strong> <span id="deny_request_teacher"></span><br>
                            <strong>Subject:</strong> <span id="deny_request_subject"></span>
                        </div>
                        <div class="mb-3">
                            <label for="deny_request_notes" class="form-label">Denial Reason <span class="text-danger">*</span></label>
                            <textarea name="review_notes" id="deny_request_notes" class="form-control" rows="3" required 
                                      placeholder="Please explain why this request is being denied..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Deny Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="completeEditModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Re-approve & Lock Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= getCSRFTokenField() ?>
                    <input type="hidden" name="action" value="complete_edit">
                    <input type="hidden" name="grade_id" id="complete_grade_id">
                    <div class="modal-body">
                        <p>Are you sure you want to re-approve and permanently lock this grade?</p>
                        <div class="alert alert-info">
                            <strong>Student:</strong> <span id="complete_student_name"></span><br>
                            <strong>Subject:</strong> <span id="complete_subject_name"></span>
                        </div>
                        <p class="text-muted small">
                            Once re-approved, the grade will be permanently locked and cannot be edited again without a new request.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Re-approve & Lock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>