<?php
/**
 * Grade Archiving Helper Functions
 * Handles automatic archiving of courses after grade approval
 */

require_once __DIR__ . '/database.php';

if (!function_exists('archiveCourseAfterApproval')) {
    /**
     * Archive a course for a teacher after all grades are approved
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher ID
     * @param int $subjectId Subject ID
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @param int|null $courseId Course ID (optional)
     * @param int|null $sectionId Section ID (optional)
     * @return array ['success' => bool, 'archived' => bool, 'message' => string]
     */
    function archiveCourseAfterApproval(
        PDO $pdo,
        int $teacherId,
        int $subjectId,
        string $academicYear,
        string $semester,
        ?int $courseId = null,
        ?int $sectionId = null
    ): array {
        try {
            // Check if already archived
            $checkStmt = $pdo->prepare("
                SELECT id FROM archived_courses
                WHERE teacher_id = ?
                AND subject_id = ?
                AND academic_year = ?
                AND semester = ?
                LIMIT 1
            ");
            $checkStmt->execute([$teacherId, $subjectId, $academicYear, $semester]);
            if ($checkStmt->fetch()) {
                return [
                    'success' => true,
                    'archived' => false,
                    'message' => 'Course already archived'
                ];
            }
            
            // Get all students for this subject/semester
            $studentsStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT g.student_id) as total_students,
                       SUM(CASE WHEN g.approval_status = 'approved' THEN 1 ELSE 0 END) as approved_students
                FROM grades g
                WHERE g.teacher_id = ?
                AND g.subject_id = ?
                AND g.academic_year = ?
                AND g.semester = ?
                AND g.grade_type = 'final'
            ");
            $studentsStmt->execute([$teacherId, $subjectId, $academicYear, $semester]);
            $stats = $studentsStmt->fetch(PDO::FETCH_ASSOC);
            
            $totalStudents = (int)($stats['total_students'] ?? 0);
            $approvedStudents = (int)($stats['approved_students'] ?? 0);
            
            // Check if all students have approved grades
            $allApproved = ($totalStudents > 0 && $totalStudents === $approvedStudents);
            
            // Archive the course
            $archiveStmt = $pdo->prepare("
                INSERT INTO archived_courses
                (teacher_id, subject_id, course_id, section_id, academic_year, semester,
                 archived_by, all_grades_approved, total_students, approved_students)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $archiveStmt->execute([
                $teacherId,
                $subjectId,
                $courseId,
                $sectionId,
                $academicYear,
                $semester,
                null, // System archived
                $allApproved ? 1 : 0,
                $totalStudents,
                $approvedStudents
            ]);
            
            // Lock all grades for this course
            $lockStmt = $pdo->prepare("
                UPDATE grades
                SET is_locked = 1,
                    locked_at = NOW(),
                    approval_status = CASE 
                        WHEN approval_status = 'approved' THEN 'locked'
                        ELSE approval_status
                    END
                WHERE teacher_id = ?
                AND subject_id = ?
                AND academic_year = ?
                AND semester = ?
                AND grade_type = 'final'
            ");
            $lockStmt->execute([$teacherId, $subjectId, $academicYear, $semester]);
            
            return [
                'success' => true,
                'archived' => true,
                'message' => 'Course archived successfully',
                'total_students' => $totalStudents,
                'approved_students' => $approvedStudents
            ];
        } catch (PDOException $e) {
            error_log("Error archiving course: " . $e->getMessage());
            return [
                'success' => false,
                'archived' => false,
                'message' => 'Error archiving course: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('checkAndArchiveCourse')) {
    /**
     * Check if all grades for a course are approved and archive if so
     * 
     * @param PDO $pdo Database connection
     * @param int $gradeId Grade ID that was just approved
     * @return array ['archived' => bool, 'message' => string]
     */
    function checkAndArchiveCourse(PDO $pdo, int $gradeId): array {
        try {
            // Get grade details
            $gradeStmt = $pdo->prepare("
                SELECT teacher_id, subject_id, academic_year, semester, classroom_id
                FROM grades
                WHERE id = ?
            ");
            $gradeStmt->execute([$gradeId]);
            $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                return ['archived' => false, 'message' => 'Grade not found'];
            }
            
            // Get course and section info
            $courseId = null;
            $sectionId = null;
            
            if ($grade['classroom_id']) {
                $classroomStmt = $pdo->prepare("
                    SELECT c.id as course_id, s.id as section_id
                    FROM classrooms cl
                    LEFT JOIN courses c ON cl.program = c.name
                    LEFT JOIN sections s ON s.course_id = c.id 
                        AND s.academic_year = ?
                        AND s.semester = ?
                    WHERE cl.id = ?
                    LIMIT 1
                ");
                $classroomStmt->execute([
                    $grade['academic_year'],
                    $grade['semester'],
                    $grade['classroom_id']
                ]);
                $classroomInfo = $classroomStmt->fetch(PDO::FETCH_ASSOC);
                if ($classroomInfo) {
                    $courseId = $classroomInfo['course_id'];
                    $sectionId = $classroomInfo['section_id'];
                }
            }
            
            // Check if all students have approved grades
            $checkStmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT student_id) as total_students,
                    SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_count
                FROM grades
                WHERE teacher_id = ?
                AND subject_id = ?
                AND academic_year = ?
                AND semester = ?
                AND grade_type = 'final'
            ");
            $checkStmt->execute([
                $grade['teacher_id'],
                $grade['subject_id'],
                $grade['academic_year'],
                $grade['semester']
            ]);
            $stats = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            $totalStudents = (int)($stats['total_students'] ?? 0);
            $approvedCount = (int)($stats['approved_count'] ?? 0);
            
            // If all students have approved grades, archive the course
            if ($totalStudents > 0 && $totalStudents === $approvedCount) {
                $result = archiveCourseAfterApproval(
                    $pdo,
                    $grade['teacher_id'],
                    $grade['subject_id'],
                    $grade['academic_year'],
                    $grade['semester'],
                    $courseId,
                    $sectionId
                );
                
                return [
                    'archived' => $result['archived'],
                    'message' => $result['message']
                ];
            }
            
            return [
                'archived' => false,
                'message' => "Not all grades approved yet ({$approvedCount}/{$totalStudents})"
            ];
        } catch (PDOException $e) {
            error_log("Error checking course archiving: " . $e->getMessage());
            return [
                'archived' => false,
                'message' => 'Error checking archiving status'
            ];
        }
    }
}

