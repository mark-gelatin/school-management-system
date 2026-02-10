<?php
/**
 * Finals-Only Grading System Validation Functions
 * Backend-first enforcement for strict finals-only grading workflow
 */

require_once __DIR__ . '/database.php';

if (!function_exists('isFinalsPeriodActive')) {
    /**
     * Check if finals grading period is currently active
     * 
     * @param PDO $pdo Database connection
     * @param string $academicYear Academic year (e.g., "2024-2025")
     * @param string $semester Semester (1st, 2nd, Summer)
     * @return array ['active' => bool, 'period' => array|null, 'message' => string]
     */
    function isFinalsPeriodActive(PDO $pdo, string $academicYear, string $semester): array {
        try {
            $now = date('Y-m-d H:i:s');
            
            $stmt = $pdo->prepare("
                SELECT id, start_date, end_date, status
                FROM grading_periods
                WHERE academic_year = ?
                AND semester = ?
                AND period_type = 'finals'
                AND status = 'active'
                AND start_date <= ?
                AND end_date >= ?
                LIMIT 1
            ");
            $stmt->execute([$academicYear, $semester, $now, $now]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($period) {
                return [
                    'active' => true,
                    'period' => $period,
                    'message' => 'Finals grading period is active'
                ];
            }
            
            // Check if period exists but is not active
            $stmt = $pdo->prepare("
                SELECT id, start_date, end_date, status
                FROM grading_periods
                WHERE academic_year = ?
                AND semester = ?
                AND period_type = 'finals'
                LIMIT 1
            ");
            $stmt->execute([$academicYear, $semester]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($period) {
                $startTime = strtotime($period['start_date']);
                $endTime = strtotime($period['end_date']);
                $currentTime = time();
                
                if ($currentTime < $startTime) {
                    return [
                        'active' => false,
                        'period' => $period,
                        'message' => 'Finals grading period has not started yet. Starts: ' . date('M d, Y h:i A', $startTime)
                    ];
                } elseif ($currentTime > $endTime) {
                    return [
                        'active' => false,
                        'period' => $period,
                        'message' => 'Finals grading period has ended. Ended: ' . date('M d, Y h:i A', $endTime)
                    ];
                } else {
                    return [
                        'active' => false,
                        'period' => $period,
                        'message' => 'Finals grading period is not active'
                    ];
                }
            }
            
            return [
                'active' => false,
                'period' => null,
                'message' => 'No finals grading period found for this semester'
            ];
        } catch (PDOException $e) {
            error_log("Error checking finals period: " . $e->getMessage());
            return [
                'active' => false,
                'period' => null,
                'message' => 'Error checking grading period status'
            ];
        }
    }
}

if (!function_exists('isTeacherAssignedToCourse')) {
    /**
     * Verify teacher is officially assigned to the course/subject
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher user ID
     * @param int $subjectId Subject ID
     * @param int $classroomId Classroom ID (optional)
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @return bool
     */
    function isTeacherAssignedToCourse(PDO $pdo, int $teacherId, int $subjectId, ?int $classroomId, string $academicYear, string $semester): bool {
        try {
            // Check via section_schedules (primary method)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM section_schedules ss
                JOIN sections s ON ss.section_id = s.id
                WHERE ss.teacher_id = ?
                AND ss.subject_id = ?
                AND s.academic_year = ?
                AND s.semester = ?
                AND ss.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$teacherId, $subjectId, $academicYear, $semester]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && (int)$result['count'] > 0) {
                return true;
            }
            
            // Check via classrooms (fallback)
            if ($classroomId) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count
                    FROM classrooms c
                    WHERE c.id = ?
                    AND c.teacher_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$classroomId, $teacherId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && (int)$result['count'] > 0) {
                    return true;
                }
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Error checking teacher assignment: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('isSemesterActive')) {
    /**
     * Check if semester is active
     * 
     * @param PDO $pdo Database connection
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @return bool
     */
    function isSemesterActive(PDO $pdo, string $academicYear, string $semester): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT status
                FROM sections
                WHERE academic_year = ?
                AND semester = ?
                LIMIT 1
            ");
            $stmt->execute([$academicYear, $semester]);
            $section = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($section) {
                return $section['status'] === 'active';
            }
            
            // If no sections found, check current date against typical semester dates
            return true; // Default to true if we can't determine
        } catch (PDOException $e) {
            error_log("Error checking semester status: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('isGradeLocked')) {
    /**
     * Check if grade is locked or course is completed
     * 
     * @param PDO $pdo Database connection
     * @param int $gradeId Grade ID (optional, if checking specific grade)
     * @param int $subjectId Subject ID
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @return array ['locked' => bool, 'reason' => string]
     */
    function isGradeLocked(PDO $pdo, ?int $gradeId, int $subjectId, string $academicYear, string $semester): array {
        try {
            // Check if specific grade is locked
            if ($gradeId) {
                $stmt = $pdo->prepare("
                    SELECT g.is_locked, g.approval_status, g.edit_request_id,
                           ger.status as edit_request_status, ger.edit_completed
                    FROM grades g
                    LEFT JOIN grade_edit_requests ger ON g.edit_request_id = ger.id
                    WHERE g.id = ?
                ");
                $stmt->execute([$gradeId]);
                $grade = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // If there's an approved edit request that hasn't been completed, allow editing
                if ($grade && $grade['edit_request_id'] && 
                    $grade['edit_request_status'] === 'approved' && 
                    (int)$grade['edit_completed'] === 0) {
                    return [
                        'locked' => false,
                        'reason' => 'Edit request approved - grade can be edited once'
                    ];
                }
                
                if ($grade && (int)$grade['is_locked'] === 1) {
                    return [
                        'locked' => true,
                        'reason' => 'Grade is locked'
                    ];
                }
                
                if ($grade && $grade['approval_status'] === 'approved' && !$grade['edit_request_id']) {
                    return [
                        'locked' => true,
                        'reason' => 'Grade has been approved and locked'
                    ];
                }
            }
            
            // Check if semester/course is completed
            $stmt = $pdo->prepare("
                SELECT status
                FROM sections s
                JOIN section_schedules ss ON s.id = ss.section_id
                WHERE ss.subject_id = ?
                AND s.academic_year = ?
                AND s.semester = ?
                LIMIT 1
            ");
            $stmt->execute([$subjectId, $academicYear, $semester]);
            $section = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($section && $section['status'] === 'completed') {
                return [
                    'locked' => true,
                    'reason' => 'Course/semester is completed'
                ];
            }
            
            return [
                'locked' => false,
                'reason' => ''
            ];
        } catch (PDOException $e) {
            error_log("Error checking grade lock status: " . $e->getMessage());
            return [
                'locked' => true, // Default to locked on error for safety
                'reason' => 'Error checking lock status'
            ];
        }
    }
}

if (!function_exists('validateGradeValue')) {
    /**
     * Validate grade value against official grading scale
     * 
     * @param float $grade Grade value
     * @param float $maxPoints Maximum points (default 100)
     * @return array ['valid' => bool, 'message' => string]
     */
    function validateGradeValue(float $grade, float $maxPoints = 100.00): array {
        if ($grade < 0) {
            return [
                'valid' => false,
                'message' => 'Grade cannot be negative'
            ];
        }
        
        if ($grade > $maxPoints) {
            return [
                'valid' => false,
                'message' => "Grade cannot exceed maximum points ({$maxPoints})"
            ];
        }
        
        // Standard grading scale: 0-100
        if ($maxPoints == 100.00 && $grade > 100) {
            return [
                'valid' => false,
                'message' => 'Grade cannot exceed 100'
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Grade is valid'
        ];
    }
}

if (!function_exists('canTeacherSubmitFinalGrade')) {
    /**
     * Master validation function - checks ALL conditions for teacher to submit final grade
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher user ID
     * @param int $subjectId Subject ID
     * @param int $classroomId Classroom ID
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @param float $grade Grade value
     * @param int|null $existingGradeId Existing grade ID if updating
     * @return array ['allowed' => bool, 'errors' => array, 'warnings' => array]
     */
    function canTeacherSubmitFinalGrade(
        PDO $pdo,
        int $teacherId,
        int $subjectId,
        int $classroomId,
        string $academicYear,
        string $semester,
        float $grade,
        ?int $existingGradeId = null
    ): array {
        $errors = [];
        $warnings = [];
        
        // 1. Validate grade value
        $gradeValidation = validateGradeValue($grade);
        if (!$gradeValidation['valid']) {
            $errors[] = $gradeValidation['message'];
        }
        
        // 2. Check if teacher is assigned to course
        if (!isTeacherAssignedToCourse($pdo, $teacherId, $subjectId, $classroomId, $academicYear, $semester)) {
            $errors[] = 'You are not assigned to this course';
        }
        
        // 3. Check if semester is active
        if (!isSemesterActive($pdo, $academicYear, $semester)) {
            $errors[] = 'Semester is not active';
        }
        
        // 4. Check if finals period is active
        $finalsCheck = isFinalsPeriodActive($pdo, $academicYear, $semester);
        if (!$finalsCheck['active']) {
            $errors[] = $finalsCheck['message'];
        }
        
        // 5. Check if grade is locked or course is completed
        $lockCheck = isGradeLocked($pdo, $existingGradeId, $subjectId, $academicYear, $semester);
        if ($lockCheck['locked']) {
            $errors[] = $lockCheck['reason'];
        }
        
        // 6. Check for duplicate grade submission (if not updating existing)
        if (!$existingGradeId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, approval_status
                    FROM grades
                    WHERE student_id = (
                        SELECT student_id FROM grades WHERE id = ? LIMIT 1
                    )
                    AND subject_id = ?
                    AND academic_year = ?
                    AND semester = ?
                    AND grade_type = 'final'
                    LIMIT 1
                ");
                // This needs student_id - would need to be passed separately
                // For now, we'll check in the calling function
            } catch (PDOException $e) {
                error_log("Error checking duplicate grade: " . $e->getMessage());
            }
        }
        
        return [
            'allowed' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}

if (!function_exists('logGradeAction')) {
    /**
     * Log grade action to audit trail
     * 
     * @param PDO $pdo Database connection
     * @param int $gradeId Grade ID
     * @param string $actionType Action type (submitted, approved, rejected, locked, etc.)
     * @param int $actorId User ID performing the action
     * @param string $actorRole Role of the actor (teacher, admin)
     * @param string|null $previousStatus Previous approval status
     * @param string|null $newStatus New approval status
     * @param string|null $notes Additional notes
     * @return bool Success
     */
    function logGradeAction(
        PDO $pdo,
        int $gradeId,
        string $actionType,
        int $actorId,
        string $actorRole,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $notes = null
    ): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO grade_audit_log 
                (grade_id, action_type, actor_id, actor_role, previous_status, new_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $gradeId,
                $actionType,
                $actorId,
                $actorRole,
                $previousStatus,
                $newStatus,
                $notes
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("Error logging grade action: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('submitFinalGrade')) {
    /**
     * Submit final grade (teacher side) - with full validation
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher ID
     * @param int $studentId Student ID
     * @param int $subjectId Subject ID
     * @param int $classroomId Classroom ID
     * @param float $grade Grade value
     * @param string $academicYear Academic year
     * @param string $semester Semester
     * @param string|null $remarks Remarks
     * @param float $maxPoints Maximum points (default 100)
     * @return array ['success' => bool, 'gradeId' => int|null, 'message' => string]
     */
    function submitFinalGrade(
        PDO $pdo,
        int $teacherId,
        int $studentId,
        int $subjectId,
        int $classroomId,
        float $grade,
        string $academicYear,
        string $semester,
        ?string $remarks = null,
        float $maxPoints = 100.00
    ): array {
        try {
            // Full validation
            $validation = canTeacherSubmitFinalGrade(
                $pdo,
                $teacherId,
                $subjectId,
                $classroomId,
                $academicYear,
                $semester,
                $grade
            );
            
            if (!$validation['allowed']) {
                return [
                    'success' => false,
                    'gradeId' => null,
                    'message' => implode(', ', $validation['errors'])
                ];
            }
            
            // Check for existing final grade for this student/subject/semester
            $checkStmt = $pdo->prepare("
                SELECT id, approval_status, is_locked
                FROM grades
                WHERE student_id = ?
                AND subject_id = ?
                AND academic_year = ?
                AND semester = ?
                AND grade_type = 'final'
                LIMIT 1
            ");
            $checkStmt->execute([$studentId, $subjectId, $academicYear, $semester]);
            $existingGrade = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingGrade) {
                // Check if there's an approved edit request that allows editing
                $editRequestStmt = $pdo->prepare("
                    SELECT ger.status, ger.edit_completed
                    FROM grade_edit_requests ger
                    WHERE ger.id = ? AND ger.status = 'approved' AND ger.edit_completed = 0
                ");
                $editRequestStmt->execute([$existingGrade['edit_request_id'] ?? 0]);
                $editRequest = $editRequestStmt->fetch(PDO::FETCH_ASSOC);
                
                // If grade is locked/approved and no approved edit request, prevent modification
                if (((int)$existingGrade['is_locked'] === 1 || $existingGrade['approval_status'] === 'approved') && !$editRequest) {
                    return [
                        'success' => false,
                        'gradeId' => null,
                        'message' => 'Grade has been approved and locked. It cannot be modified. Please request an edit if needed.'
                    ];
                }
                
                // Update existing grade
                // If this is an edit after approval, mark edit as completed
                $editRequestStmt = $pdo->prepare("
                    SELECT id FROM grade_edit_requests 
                    WHERE id = ? AND status = 'approved' AND edit_completed = 0
                ");
                $editRequestStmt->execute([$existingGrade['edit_request_id'] ?? 0]);
                $editRequest = $editRequestStmt->fetch(PDO::FETCH_ASSOC);
                
                $updateStmt = $pdo->prepare("
                    UPDATE grades
                    SET grade = ?,
                        max_points = ?,
                        remarks = ?,
                        approval_status = 'submitted',
                        submitted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$grade, $maxPoints, $remarks, $existingGrade['id']]);
                $gradeId = $existingGrade['id'];
                
                // If this was an approved edit request, mark it as completed
                if ($editRequest) {
                    $completeEditStmt = $pdo->prepare("
                        UPDATE grade_edit_requests
                        SET edit_completed = 1,
                            edit_completed_at = NOW()
                        WHERE id = ?
                    ");
                    $completeEditStmt->execute([$editRequest['id']]);
                }
                
                // Log action
                logGradeAction($pdo, $gradeId, 'submitted', $teacherId, 'teacher', $existingGrade['approval_status'], 'submitted');
                
                // Log teacher action for activity logs
                try {
                    require_once __DIR__ . '/../student-management/includes/functions.php';
                    // Get subject and course info
                    $infoStmt = $pdo->prepare("
                        SELECT s.name as subject_name, s.code as subject_code, c.id as course_id
                        FROM subjects s
                        LEFT JOIN section_schedules ss ON s.id = ss.subject_id
                        LEFT JOIN sections sec ON ss.section_id = sec.id
                        LEFT JOIN courses c ON sec.course_id = c.id
                        WHERE s.id = ? LIMIT 1
                    ");
                    $infoStmt->execute([$subjectId]);
                    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($info) {
                        $subjectName = $info['subject_name'] ?? 'Unknown Subject';
                        $subjectCode = $info['subject_code'] ?? '';
                        $description = "Resubmitted final grade for subject '{$subjectName}'" . 
                                      ($subjectCode ? " ({$subjectCode})" : "") . 
                                      " - Grade: {$grade}";
                        logTeacherAction($pdo, $teacherId, 'resubmit_final_grade', 'grade', $gradeId, $description, $info['course_id'] ?? null, $subjectId);
                    } else {
                        // Fallback if subject not found
                        logTeacherAction($pdo, $teacherId, 'resubmit_final_grade', 'grade', $gradeId, "Resubmitted final grade for subject ID: {$subjectId} - Grade: {$grade}", null, $subjectId);
                    }
                } catch (Exception $e) {
                    error_log("Failed to log teacher action: " . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'gradeId' => $gradeId,
                    'message' => 'Grade updated and submitted for review'
                ];
            } else {
                // Insert new grade
                $insertStmt = $pdo->prepare("
                    INSERT INTO grades
                    (student_id, subject_id, classroom_id, teacher_id, grade, grade_type, max_points,
                     academic_year, semester, remarks, approval_status, graded_at, submitted_at)
                    VALUES (?, ?, ?, ?, ?, 'final', ?, ?, ?, ?, 'submitted', NOW(), NOW())
                ");
                $insertStmt->execute([
                    $studentId,
                    $subjectId,
                    $classroomId,
                    $teacherId,
                    $grade,
                    $maxPoints,
                    $academicYear,
                    $semester,
                    $remarks
                ]);
                $gradeId = $pdo->lastInsertId();
                
                // Log action
                logGradeAction($pdo, $gradeId, 'submitted', $teacherId, 'teacher', null, 'submitted');
                
                // Log teacher action for activity logs
                try {
                    require_once __DIR__ . '/../student-management/includes/functions.php';
                    // Get subject and course info
                    $infoStmt = $pdo->prepare("
                        SELECT s.name as subject_name, s.code as subject_code, c.id as course_id
                        FROM subjects s
                        LEFT JOIN section_schedules ss ON s.id = ss.subject_id
                        LEFT JOIN sections sec ON ss.section_id = sec.id
                        LEFT JOIN courses c ON sec.course_id = c.id
                        WHERE s.id = ? LIMIT 1
                    ");
                    $infoStmt->execute([$subjectId]);
                    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($info) {
                        $subjectName = $info['subject_name'] ?? 'Unknown Subject';
                        $subjectCode = $info['subject_code'] ?? '';
                        $description = "Submitted final grade for subject '{$subjectName}'" . 
                                      ($subjectCode ? " ({$subjectCode})" : "") . 
                                      " - Grade: {$grade}";
                        logTeacherAction($pdo, $teacherId, 'submit_final_grade', 'grade', $gradeId, $description, $info['course_id'] ?? null, $subjectId);
                    } else {
                        // Fallback if subject not found
                        logTeacherAction($pdo, $teacherId, 'submit_final_grade', 'grade', $gradeId, "Submitted final grade for subject ID: {$subjectId} - Grade: {$grade}", null, $subjectId);
                    }
                } catch (Exception $e) {
                    error_log("Failed to log teacher action: " . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'gradeId' => $gradeId,
                    'message' => 'Grade submitted successfully for admin review'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error submitting final grade: " . $e->getMessage());
            return [
                'success' => false,
                'gradeId' => null,
                'message' => 'Error submitting grade: ' . $e->getMessage()
            ];
        }
    }
}



