<?php
/**
 * Course Enrollment Helper Functions
 * 
 * Provides unified functions for getting students enrolled in courses/subjects
 */

/**
 * Get all students enrolled in a specific subject
 * 
 * This function retrieves students who are enrolled in a subject through:
 * 1. Students in classrooms that have this subject (via grades or section_schedules)
 * 2. Students who have grades for this subject
 * 
 * @param PDO $pdo Database connection
 * @param int $subjectId Subject ID
 * @param int|null $teacherId Optional: Filter by teacher ID (for teacher view)
 * @return array Array of student records with id, first_name, last_name, student_id_number, section
 */
function getStudentsEnrolledInSubject($pdo, $subjectId, $teacherId = null) {
    try {
        // Check if section_schedules table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $sectionSchedulesExists = $tableCheck->rowCount() > 0;
        
        // Build query to get students enrolled in this subject
        // Students are enrolled if they are in classrooms that have this subject
        $query = "
            SELECT DISTINCT 
                u.id,
                u.first_name,
                u.last_name,
                u.student_id_number,
                u.section,
                u.year_level,
                u.program,
                cl.name as classroom_name
            FROM users u
            INNER JOIN classroom_students cs ON u.id = cs.student_id
            INNER JOIN classrooms cl ON cs.classroom_id = cl.id
            WHERE u.role = 'student'
            AND cs.enrollment_status = 'enrolled'
            AND (
                -- Method 1: Students in classrooms that have grades for this subject
                EXISTS (
                    SELECT 1 FROM grades g
                    WHERE g.classroom_id = cl.id 
                    AND g.subject_id = ?
                    " . ($teacherId ? "AND g.teacher_id = ?" : "") . "
                )
                OR
                -- Method 2: Students in classrooms that match section_schedules for this subject
                " . ($sectionSchedulesExists ? "
                EXISTS (
                    SELECT 1 FROM section_schedules ss
                    INNER JOIN sections sec ON ss.section_id = sec.id
                    WHERE ss.subject_id = ?
                    AND ss.status = 'active'
                    AND (
                        -- Match by section name, year level, and program
                        (TRIM(sec.section_name) = TRIM(cl.section)
                         AND TRIM(sec.year_level) = TRIM(cl.year_level)
                         AND (
                             sec.course_id = (SELECT id FROM courses WHERE name = cl.program LIMIT 1)
                             OR EXISTS (SELECT 1 FROM courses c WHERE c.id = sec.course_id AND c.name = cl.program)
                         ))
                    )
                )
                " : "FALSE") . "
            )
            ORDER BY u.last_name, u.first_name
        ";
        
        // Prepare parameters
        $params = [$subjectId];
        if ($teacherId) {
            $params[] = $teacherId;
        }
        if ($sectionSchedulesExists) {
            $params[] = $subjectId;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no students found via classrooms, try direct grade relationship
        if (empty($students)) {
            $fallbackQuery = "
                SELECT DISTINCT 
                    u.id,
                    u.first_name,
                    u.last_name,
                    u.student_id_number,
                    u.section,
                    u.year_level,
                    u.program,
                    COALESCE(cl.name, 'N/A') as classroom_name
                FROM users u
                INNER JOIN grades g ON u.id = g.student_id
                LEFT JOIN classrooms cl ON g.classroom_id = cl.id
                WHERE g.subject_id = ?
                AND u.role = 'student'
                " . ($teacherId ? "AND g.teacher_id = ?" : "") . "
                ORDER BY u.last_name, u.first_name
            ";
            
            $fallbackParams = [$subjectId];
            if ($teacherId) {
                $fallbackParams[] = $teacherId;
            }
            
            $fallbackStmt = $pdo->prepare($fallbackQuery);
            $fallbackStmt->execute($fallbackParams);
            $students = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $students;
    } catch (PDOException $e) {
        error_log("Error getting students enrolled in subject: " . $e->getMessage());
        return [];
    }
}

/**
 * Verify if a student is enrolled in a specific subject
 * 
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID
 * @param int $subjectId Subject ID
 * @return bool True if student is enrolled, false otherwise
 */
function isStudentEnrolledInSubject($pdo, $studentId, $subjectId) {
    try {
        // Check if section_schedules table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $sectionSchedulesExists = $tableCheck->rowCount() > 0;
        
        // Method 1: Check if student has grades for this subject
        $checkGradeStmt = $pdo->prepare("
            SELECT 1 FROM grades 
            WHERE subject_id = ? AND student_id = ?
            LIMIT 1
        ");
        $checkGradeStmt->execute([$subjectId, $studentId]);
        if ($checkGradeStmt->rowCount() > 0) {
            return true;
        }
        
        // Method 2: Check if student is in a classroom that has this subject
        $checkClassroomStmt = $pdo->prepare("
            SELECT 1 FROM classroom_students cs
            INNER JOIN classrooms cl ON cs.classroom_id = cl.id
            WHERE cs.student_id = ?
            AND cs.enrollment_status = 'enrolled'
            AND EXISTS (
                SELECT 1 FROM grades g
                WHERE g.classroom_id = cl.id AND g.subject_id = ?
            )
            LIMIT 1
        ");
        $checkClassroomStmt->execute([$studentId, $subjectId]);
        if ($checkClassroomStmt->rowCount() > 0) {
            return true;
        }
        
        // Method 3: Check via section_schedules (if table exists)
        if ($sectionSchedulesExists) {
            // Get student's section info
            $studentInfoStmt = $pdo->prepare("
                SELECT u.section, u.year_level, u.program,
                       cl.section as classroom_section, 
                       cl.year_level as classroom_year_level,
                       cl.program as classroom_program
                FROM users u
                LEFT JOIN classroom_students cs ON u.id = cs.student_id AND cs.enrollment_status = 'enrolled'
                LEFT JOIN classrooms cl ON cs.classroom_id = cl.id
                WHERE u.id = ? AND u.role = 'student'
                ORDER BY cs.enrolled_at DESC
                LIMIT 1
            ");
            $studentInfoStmt->execute([$studentId]);
            $studentInfo = $studentInfoStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($studentInfo) {
                $sectionName = trim($studentInfo['section'] ?? $studentInfo['classroom_section'] ?? '');
                $yearLevel = trim($studentInfo['year_level'] ?? $studentInfo['classroom_year_level'] ?? '');
                
                if (!empty($sectionName) && !empty($yearLevel)) {
                    $checkScheduleStmt = $pdo->prepare("
                        SELECT 1 FROM section_schedules ss
                        INNER JOIN sections sec ON ss.section_id = sec.id
                        LEFT JOIN courses crs ON sec.course_id = crs.id
                        WHERE ss.subject_id = ? 
                        AND ss.status = 'active'
                        AND (
                            (TRIM(sec.section_name) = ? AND TRIM(sec.year_level) = ?)
                            OR
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
                        LIMIT 1
                    ");
                    $checkScheduleStmt->execute([
                        $subjectId, 
                        $sectionName, 
                        $yearLevel,
                        $studentId
                    ]);
                    if ($checkScheduleStmt->rowCount() > 0) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Error checking student enrollment: " . $e->getMessage());
        return false;
    }
}



