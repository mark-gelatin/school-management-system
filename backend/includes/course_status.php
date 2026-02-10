<?php
/**
 * Course Status Helper Functions
 * Determines course status (Coming, Ongoing, Completed) and grade visibility
 */

if (!function_exists('getCourseStatus')) {
    /**
     * Determine course status based on academic year and semester
     * 
     * @param string $academicYear Academic year in format YYYY-YYYY (e.g., "2024-2025")
     * @param string $semester Semester: '1st', '2nd', or 'Summer'
     * @return string Status: 'Coming', 'Ongoing', or 'Completed'
     */
    function getCourseStatus(string $academicYear, string $semester): string {
        if (empty($academicYear) || empty($semester)) {
            return 'Ongoing'; // Default to ongoing if info is missing
        }
        
        // Parse academic year (format: YYYY-YYYY)
        $yearParts = explode('-', $academicYear);
        if (count($yearParts) !== 2) {
            return 'Ongoing';
        }
        
        $startYear = (int)$yearParts[0];
        $endYear = (int)$yearParts[1];
        $currentDate = new DateTime();
        $currentYear = (int)$currentDate->format('Y');
        $currentMonth = (int)$currentDate->format('m');
        
        // Determine semester dates based on Philippine academic calendar
        // 1st Semester: June - October
        // 2nd Semester: November - March (next year)
        // Summer: April - May
        
        $semesterStartMonth = 0;
        $semesterEndMonth = 0;
        $semesterStartYear = $startYear;
        $semesterEndYear = $startYear;
        
        switch ($semester) {
            case '1st':
                $semesterStartMonth = 6; // June
                $semesterEndMonth = 10;  // October
                $semesterStartYear = $startYear;
                $semesterEndYear = $startYear;
                break;
            case '2nd':
                $semesterStartMonth = 11; // November
                $semesterEndMonth = 3;    // March (next year)
                $semesterStartYear = $startYear;
                $semesterEndYear = $endYear; // March is in the end year
                break;
            case 'Summer':
                $semesterStartMonth = 4; // April
                $semesterEndMonth = 5;   // May
                $semesterStartYear = $endYear;
                $semesterEndYear = $endYear;
                break;
            default:
                return 'Ongoing';
        }
        
        // Create semester start and end dates
        $semesterStart = new DateTime("$semesterStartYear-$semesterStartMonth-01");
        
        // Get last day of end month
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $semesterEndMonth, $semesterEndYear);
        $semesterEnd = new DateTime("$semesterEndYear-$semesterEndMonth-$daysInMonth");
        $semesterEnd->setTime(23, 59, 59); // End of the last day
        
        // Compare current date with semester dates
        if ($currentDate < $semesterStart) {
            return 'Coming';
        } elseif ($currentDate > $semesterEnd) {
            return 'Completed';
        } else {
            return 'Ongoing';
        }
    }
}

if (!function_exists('shouldShowGrades')) {
    /**
     * Determine if grades should be visible to students
     * Grades are only visible after Prelims, Midterms, and Finals periods
     * when teachers have encoded them.
     * 
     * @param PDO $pdo Database connection
     * @param int $studentId Student ID
     * @param int $subjectId Subject ID
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @return bool True if grades should be visible
     */
    function shouldShowGrades(PDO $pdo, int $studentId, int $subjectId, string $academicYear, string $semester): bool {
        // Check if student has grades for Prelims, Midterms, or Finals
        // These grade types indicate that the grading period has passed and grades are encoded
        
        // Based on the database schema, grade types are:
        // 'quiz', 'assignment', 'exam', 'project', 'participation', 'final'
        // We'll map these to periods:
        // - 'quiz', 'assignment' -> Prelims period
        // - 'exam', 'project' -> Midterms period
        // - 'final' -> Finals period
        
        // Check for any grade types that indicate a grading period has been encoded
        // Exclude 'participation' as it's just an enrollment marker
        $gradeTypes = ['quiz', 'assignment', 'exam', 'project', 'final'];
        $placeholders = implode(',', array_fill(0, count($gradeTypes), '?'));
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as grade_count
            FROM grades
            WHERE student_id = ?
              AND subject_id = ?
              AND grade_type IN ($placeholders)
        ");
        
        $params = array_merge([$studentId, $subjectId], $gradeTypes);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If student has any of these grade types, show grades
        return ($result['grade_count'] > 0);
    }
}

if (!function_exists('getCourseAcademicInfo')) {
    /**
     * Get academic year and semester for a course/subject from section_schedules or sections
     * 
     * @param PDO $pdo Database connection
     * @param int $studentId Student ID
     * @param int $subjectId Subject ID
     * @return array ['academic_year' => string, 'semester' => string] or null
     */
    function getCourseAcademicInfo(PDO $pdo, int $studentId, int $subjectId): ?array {
        // First try to get from section_schedules
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
        $tableExists = $tableCheck->rowCount() > 0;
        
        if ($tableExists) {
            // Get student's section info
            $studentStmt = $pdo->prepare("
                SELECT u.section, u.year_level, u.program,
                       c.id as course_id, c.name as course_name
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
            
            if ($studentInfo) {
                $sectionName = trim($studentInfo['section'] ?? '');
                $yearLevel = trim($studentInfo['year_level'] ?? '');
                $courseId = $studentInfo['course_id'] ?? null;
                $courseName = $studentInfo['course_name'] ?? $studentInfo['program'] ?? null;
                
                if (!empty($sectionName) && !empty($yearLevel)) {
                    $scheduleStmt = $pdo->prepare("
                        SELECT ss.academic_year, ss.semester
                        FROM section_schedules ss
                        INNER JOIN sections sec ON ss.section_id = sec.id
                        LEFT JOIN courses crs ON sec.course_id = crs.id
                        WHERE ss.subject_id = ?
                          AND ss.status = 'active'
                          AND (
                              (TRIM(sec.section_name) = ? AND TRIM(sec.year_level) = ? AND sec.course_id = ?)
                              OR
                              (TRIM(sec.section_name) = ? AND TRIM(sec.year_level) = ? AND crs.name = ?)
                          )
                        LIMIT 1
                    ");
                    
                    if ($courseId) {
                        $scheduleStmt->execute([$subjectId, $sectionName, $yearLevel, $courseId, $sectionName, $yearLevel, $courseName]);
                    } else {
                        $scheduleStmt->execute([$subjectId, $sectionName, $yearLevel, null, $sectionName, $yearLevel, $courseName]);
                    }
                    
                    $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
                    if ($schedule && !empty($schedule['academic_year'])) {
                        return [
                            'academic_year' => $schedule['academic_year'],
                            'semester' => $schedule['semester'] ?? '1st'
                        ];
                    }
                }
            }
        }
        
        // Fallback: try to get from grades (if student has grades)
        $gradeStmt = $pdo->prepare("
            SELECT DISTINCT cl.academic_year, cl.semester
            FROM grades g
            JOIN classrooms cl ON g.classroom_id = cl.id
            WHERE g.student_id = ? AND g.subject_id = ?
            LIMIT 1
        ");
        $gradeStmt->execute([$studentId, $subjectId]);
        $gradeInfo = $gradeStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($gradeInfo && !empty($gradeInfo['academic_year'])) {
            return [
                'academic_year' => $gradeInfo['academic_year'],
                'semester' => $gradeInfo['semester'] ?? '1st'
            ];
        }
        
        // Final fallback: get current academic year
        if (function_exists('getCurrentAcademicYearRange')) {
            return [
                'academic_year' => getCurrentAcademicYearRange(),
                'semester' => '1st'
            ];
        }
        
        return null;
    }
}

