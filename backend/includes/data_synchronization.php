<?php
/**
 * Data Synchronization Module
 * 
 * Ensures consistent data flow and CRUD operations across Teacher, Student, and Admin sides
 */

/**
 * Execute a grade operation with proper transaction handling and synchronization
 * 
 * @param PDO $pdo Database connection
 * @param string $operation 'create', 'update', 'delete'
 * @param array $data Grade data
 * @param int $teacherId Teacher performing the action
 * @return array Result with success status and message
 */
function synchronizeGradeOperation($pdo, $operation, $data, $teacherId) {
    try {
        $pdo->beginTransaction();
        
        $result = ['success' => false, 'message' => '', 'grade_id' => null];
        
        switch ($operation) {
            case 'create':
                $result = createGradeWithSync($pdo, $data, $teacherId);
                break;
            case 'update':
                $result = updateGradeWithSync($pdo, $data, $teacherId);
                break;
            case 'delete':
                $result = deleteGradeWithSync($pdo, $data, $teacherId);
                break;
            default:
                throw new Exception('Invalid operation: ' . $operation);
        }
        
        if ($result['success']) {
            $pdo->commit();
            
            // Log the action for admin visibility
            logTeacherActionForAdmin($pdo, $teacherId, $operation, 'grade', $result['grade_id'], $data);
            
            return $result;
        } else {
            $pdo->rollBack();
            return $result;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Grade operation error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Grade operation error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create a grade with proper validation and synchronization
 */
function createGradeWithSync($pdo, $data, $teacherId) {
    // Validate required fields
    $required = ['student_id', 'subject_id', 'classroom_id', 'grade', 'grade_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }
    
    // Validate student exists and is enrolled
    $studentCheck = $pdo->prepare("
        SELECT u.id, u.role 
        FROM users u
        INNER JOIN classroom_students cs ON u.id = cs.student_id
        WHERE u.id = ? AND u.role = 'student' 
        AND cs.classroom_id = ? AND cs.enrollment_status = 'enrolled'
    ");
    $studentCheck->execute([$data['student_id'], $data['classroom_id']]);
    if ($studentCheck->rowCount() === 0) {
        return ['success' => false, 'message' => 'Student not found or not enrolled in this classroom'];
    }
    
    // Validate subject exists
    $subjectCheck = $pdo->prepare("SELECT id FROM subjects WHERE id = ?");
    $subjectCheck->execute([$data['subject_id']]);
    if ($subjectCheck->rowCount() === 0) {
        return ['success' => false, 'message' => 'Subject not found'];
    }
    
    // Validate classroom belongs to teacher
    $classroomCheck = $pdo->prepare("SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?");
    $classroomCheck->execute([$data['classroom_id'], $teacherId]);
    if ($classroomCheck->rowCount() === 0) {
        return ['success' => false, 'message' => 'Classroom not found or access denied'];
    }
    
    // Validate grade value
    $maxPoints = floatval($data['max_points'] ?? 100);
    $grade = floatval($data['grade']);
    if ($grade < 0 || $grade > $maxPoints) {
        return ['success' => false, 'message' => "Grade must be between 0 and $maxPoints"];
    }
    
    // Check for duplicate grade
    $duplicateCheck = $pdo->prepare("
        SELECT id FROM grades 
        WHERE student_id = ? AND subject_id = ? AND classroom_id = ? AND grade_type = ?
    ");
    $duplicateCheck->execute([
        $data['student_id'], 
        $data['subject_id'], 
        $data['classroom_id'], 
        $data['grade_type']
    ]);
    
    if ($duplicateCheck->rowCount() > 0) {
        // Update existing instead of creating duplicate
        $existing = $duplicateCheck->fetch(PDO::FETCH_ASSOC);
        $data['grade_id'] = $existing['id'];
        return updateGradeWithSync($pdo, $data, $teacherId);
    }
    
    // Insert new grade
    $insertStmt = $pdo->prepare("
        INSERT INTO grades (
            student_id, subject_id, classroom_id, teacher_id, 
            grade, grade_type, max_points, academic_year, semester, 
            remarks, graded_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $insertStmt->execute([
        $data['student_id'],
        $data['subject_id'],
        $data['classroom_id'],
        $teacherId,
        $grade,
        $data['grade_type'],
        $maxPoints,
        $data['academic_year'] ?? null,
        $data['semester'] ?? null,
        $data['remarks'] ?? null
    ]);
    
    $gradeId = $pdo->lastInsertId();
    
    return [
        'success' => true, 
        'message' => 'Grade created successfully',
        'grade_id' => $gradeId
    ];
}

/**
 * Update a grade with proper validation and synchronization
 */
function updateGradeWithSync($pdo, $data, $teacherId) {
    if (!isset($data['grade_id'])) {
        return ['success' => false, 'message' => 'Grade ID is required for update'];
    }
    
    $gradeId = intval($data['grade_id']);
    
    // Verify grade exists and belongs to teacher
    $gradeCheck = $pdo->prepare("
        SELECT * FROM grades 
        WHERE id = ? AND teacher_id = ?
    ");
    $gradeCheck->execute([$gradeId, $teacherId]);
    $existingGrade = $gradeCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingGrade) {
        return ['success' => false, 'message' => 'Grade not found or access denied'];
    }
    
    // Validate grade value if provided
    if (isset($data['grade'])) {
        $maxPoints = floatval($data['max_points'] ?? $existingGrade['max_points'] ?? 100);
        $grade = floatval($data['grade']);
        if ($grade < 0 || $grade > $maxPoints) {
            return ['success' => false, 'message' => "Grade must be between 0 and $maxPoints"];
        }
    }
    
    // Build update query dynamically
    $updateFields = [];
    $updateValues = [];
    
    if (isset($data['grade'])) {
        $updateFields[] = 'grade = ?';
        $updateValues[] = floatval($data['grade']);
    }
    if (isset($data['max_points'])) {
        $updateFields[] = 'max_points = ?';
        $updateValues[] = floatval($data['max_points']);
    }
    if (isset($data['remarks'])) {
        $updateFields[] = 'remarks = ?';
        $updateValues[] = $data['remarks'];
    }
    if (isset($data['academic_year'])) {
        $updateFields[] = 'academic_year = ?';
        $updateValues[] = $data['academic_year'];
    }
    if (isset($data['semester'])) {
        $updateFields[] = 'semester = ?';
        $updateValues[] = $data['semester'];
    }
    
    // Mark as manually edited
    $updateFields[] = 'manually_edited = 1';
    $updateFields[] = 'edited_by = ?';
    $updateValues[] = $teacherId;
    $updateFields[] = 'edited_at = NOW()';
    $updateFields[] = 'updated_at = NOW()';
    
    // Add grade ID for WHERE clause
    $updateValues[] = $gradeId;
    
    $updateStmt = $pdo->prepare("
        UPDATE grades 
        SET " . implode(', ', $updateFields) . "
        WHERE id = ?
    ");
    $updateStmt->execute($updateValues);
    
    return [
        'success' => true, 
        'message' => 'Grade updated successfully',
        'grade_id' => $gradeId
    ];
}

/**
 * Delete a grade with proper validation and synchronization
 */
function deleteGradeWithSync($pdo, $data, $teacherId) {
    if (!isset($data['grade_id'])) {
        return ['success' => false, 'message' => 'Grade ID is required for deletion'];
    }
    
    $gradeId = intval($data['grade_id']);
    
    // Verify grade exists and belongs to teacher
    $gradeCheck = $pdo->prepare("
        SELECT * FROM grades 
        WHERE id = ? AND teacher_id = ?
    ");
    $gradeCheck->execute([$gradeId, $teacherId]);
    
    if ($gradeCheck->rowCount() === 0) {
        return ['success' => false, 'message' => 'Grade not found or access denied'];
    }
    
    // Delete the grade (CASCADE will handle related records)
    $deleteStmt = $pdo->prepare("DELETE FROM grades WHERE id = ?");
    $deleteStmt->execute([$gradeId]);
    
    return [
        'success' => true, 
        'message' => 'Grade deleted successfully',
        'grade_id' => $gradeId
    ];
}

/**
 * Log teacher action (wrapper for compatibility)
 * This function logs teacher actions to both admin_logs and teacher_logs if they exist
 */
if (!function_exists('logTeacherAction')) {
    function logTeacherAction($pdo, $teacherId, $action, $entityType, $entityId, $description, $courseId = null, $subjectId = null) {
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // Log to admin_logs if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_logs'");
            if ($tableCheck->rowCount() > 0) {
                $logStmt = $pdo->prepare("
                    INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                // Use teacher ID as admin_id for teacher actions (or NULL)
                $logStmt->execute([$teacherId, $action, $entityType, $entityId, $description, $ip_address, $user_agent]);
            }
            
            // Also log to teacher_logs if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'teacher_logs'");
            if ($tableCheck->rowCount() > 0) {
                $logStmt = $pdo->prepare("
                    INSERT INTO teacher_logs (teacher_id, action, entity_type, entity_id, description, course_id, subject_id, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $logStmt->execute([$teacherId, $action, $entityType, $entityId, $description, $courseId, $subjectId, $ip_address, $user_agent]);
            }
            
            return true;
        } catch (PDOException $e) {
            // Don't fail the operation if logging fails
            error_log("Failed to log teacher action: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log teacher action for admin visibility
 */
function logTeacherActionForAdmin($pdo, $teacherId, $action, $entityType, $entityId, $data) {
    try {
        // Get teacher info
        $teacherStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
        $teacherName = $teacher ? ($teacher['first_name'] . ' ' . $teacher['last_name']) : 'Unknown';
        
        // Build description
        $description = "Teacher: $teacherName (ID: $teacherId) - ";
        switch ($action) {
            case 'create':
                $description .= "Created grade for student ID: {$data['student_id']}, Subject ID: {$data['subject_id']}, Grade: {$data['grade']}";
                break;
            case 'update':
                $description .= "Updated grade ID: $entityId";
                if (isset($data['grade'])) {
                    $description .= ", New Grade: {$data['grade']}";
                }
                break;
            case 'delete':
                $description .= "Deleted grade ID: $entityId";
                break;
        }
        
        // Log to admin_logs if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_logs'");
        if ($tableCheck->rowCount() > 0) {
            $logStmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, description, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            // Use teacher ID as admin_id for teacher actions (or NULL if preferred)
            $logStmt->execute([$teacherId, $action . '_grade', $entityType, $entityId, $description]);
        }
        
        // Also log to teacher_logs if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'teacher_logs'");
        if ($tableCheck->rowCount() > 0) {
            $logStmt = $pdo->prepare("
                INSERT INTO teacher_logs (teacher_id, action, entity_type, entity_id, description, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([$teacherId, $action, $entityType, $entityId, $description]);
        }
    } catch (PDOException $e) {
        // Don't fail the operation if logging fails
        error_log("Failed to log teacher action: " . $e->getMessage());
    }
}

/**
 * Verify data consistency after an operation
 * 
 * @param PDO $pdo Database connection
 * @param string $entityType Type of entity to verify
 * @param int $entityId Entity ID
 * @return array Verification result
 */
function verifyDataConsistency($pdo, $entityType, $entityId) {
    $issues = [];
    
    try {
        switch ($entityType) {
            case 'grade':
                // Verify grade has valid foreign keys
                $gradeCheck = $pdo->prepare("
                    SELECT g.*, 
                           u.id as student_exists,
                           s.id as subject_exists,
                           c.id as classroom_exists,
                           t.id as teacher_exists
                    FROM grades g
                    LEFT JOIN users u ON g.student_id = u.id
                    LEFT JOIN subjects s ON g.subject_id = s.id
                    LEFT JOIN classrooms c ON g.classroom_id = c.id
                    LEFT JOIN users t ON g.teacher_id = t.id
                    WHERE g.id = ?
                ");
                $gradeCheck->execute([$entityId]);
                $grade = $gradeCheck->fetch(PDO::FETCH_ASSOC);
                
                if (!$grade) {
                    $issues[] = 'Grade not found';
                } else {
                    if (!$grade['student_exists']) {
                        $issues[] = 'Student reference is invalid';
                    }
                    if (!$grade['subject_exists']) {
                        $issues[] = 'Subject reference is invalid';
                    }
                    if ($grade['classroom_id'] && !$grade['classroom_exists']) {
                        $issues[] = 'Classroom reference is invalid';
                    }
                    if ($grade['teacher_id'] && !$grade['teacher_exists']) {
                        $issues[] = 'Teacher reference is invalid';
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        $issues[] = 'Verification error: ' . $e->getMessage();
    }
    
    return [
        'consistent' => empty($issues),
        'issues' => $issues
    ];
}

/**
 * Get real-time data for a student (for student view synchronization)
 * 
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID
 * @return array Student data with latest grades
 */
function getStudentRealTimeData($pdo, $studentId) {
    try {
        // Get student info
        $studentStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['success' => false, 'message' => 'Student not found'];
        }
        
        // Get latest grades
        $gradesStmt = $pdo->prepare("
            SELECT g.*, 
                   s.name as subject_name, 
                   s.code as subject_code,
                   CONCAT(t.first_name, ' ', t.last_name) as teacher_name
            FROM grades g
            LEFT JOIN subjects s ON g.subject_id = s.id
            LEFT JOIN users t ON g.teacher_id = t.id
            WHERE g.student_id = ?
            ORDER BY g.graded_at DESC, g.updated_at DESC
        ");
        $gradesStmt->execute([$studentId]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'student' => $student,
            'grades' => $grades,
            'last_updated' => date('Y-m-d H:i:s')
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Error fetching student data: ' . $e->getMessage()];
    }
}

/**
 * Get teacher activities for admin view
 * Uses teacher_logs table if available, otherwise falls back to grades table
 * 
 * @param PDO $pdo Database connection
 * @param int|null $teacherId Optional: filter by specific teacher
 * @param int $limit Number of activities to return
 * @return array Teacher activities
 */
function getTeacherActivitiesForAdmin($pdo, $teacherId = null, $limit = 50) {
    try {
        // First check if teacher_logs table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'teacher_logs'");
        $hasTeacherLogs = $tableCheck->rowCount() > 0;
        
        if ($hasTeacherLogs) {
            // Use teacher_logs table (preferred method)
            $query = "
                SELECT 
                    tl.id as activity_id,
                    tl.action as activity_type,
                    tl.created_at as activity_date,
                    CONCAT(t.first_name, ' ', t.last_name) as teacher_name,
                    t.id as teacher_id,
                    tl.description,
                    s.name as subject_name,
                    s.code as subject_code,
                    c.name as course_name,
                    c.code as course_code,
                    tl.entity_id,
                    tl.entity_type
                FROM teacher_logs tl
                INNER JOIN users t ON tl.teacher_id = t.id
                LEFT JOIN subjects s ON tl.subject_id = s.id
                LEFT JOIN courses c ON tl.course_id = c.id
                WHERE 1=1
            ";
            
            $params = [];
            if ($teacherId) {
                $query .= " AND tl.teacher_id = ?";
                $params[] = $teacherId;
            }
            
            $query .= " ORDER BY tl.created_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Fallback to grades table
            $query = "
                SELECT 
                    g.id as activity_id,
                    'grade' as activity_type,
                    g.graded_at as activity_date,
                    CONCAT(t.first_name, ' ', t.last_name) as teacher_name,
                    t.id as teacher_id,
                    CONCAT(s.first_name, ' ', s.last_name) as student_name,
                    s.id as student_id,
                    sub.name as subject_name,
                    sub.code as subject_code,
                    g.grade,
                    g.grade_type,
                    g.manually_edited,
                    g.edited_at,
                    g.id as entity_id,
                    'grade' as entity_type
                FROM grades g
                INNER JOIN users t ON g.teacher_id = t.id
                INNER JOIN users s ON g.student_id = s.id
                LEFT JOIN subjects sub ON g.subject_id = sub.id
                WHERE 1=1
            ";
            
            $params = [];
            if ($teacherId) {
                $query .= " AND g.teacher_id = ?";
                $params[] = $teacherId;
            }
            
            $query .= " ORDER BY g.graded_at DESC, g.updated_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return ['success' => true, 'activities' => $activities];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Error fetching teacher activities: ' . $e->getMessage()];
    }
}

