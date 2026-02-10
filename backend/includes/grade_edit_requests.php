<?php
/**
 * Grade Edit Request Helper Functions
 * Handles grade edit requests from teachers
 */

require_once __DIR__ . '/database.php';

if (!function_exists('createGradeEditRequest')) {
    /**
     * Create a grade edit request
     * 
     * @param PDO $pdo Database connection
     * @param int $teacherId Teacher ID
     * @param int $gradeId Grade ID
     * @param string $requestReason Reason for edit request
     * @return array ['success' => bool, 'requestId' => int|null, 'message' => string]
     */
    function createGradeEditRequest(PDO $pdo, int $teacherId, int $gradeId, string $requestReason): array {
        try {
            // Check if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
            if ($tableCheck->rowCount() === 0) {
                return [
                    'success' => false,
                    'requestId' => null,
                    'message' => 'Database table not found. Please run the setup script to create required tables.'
                ];
            }
            
            // Verify grade exists and belongs to teacher
            $gradeStmt = $pdo->prepare("
                SELECT g.*, s.id as subject_id, c.id as course_id
                FROM grades g
                LEFT JOIN subjects s ON g.subject_id = s.id
                LEFT JOIN section_schedules ss ON s.id = ss.subject_id
                LEFT JOIN sections sec ON ss.section_id = sec.id
                LEFT JOIN courses c ON sec.course_id = c.id
                WHERE g.id = ? AND g.teacher_id = ?
                LIMIT 1
            ");
            $gradeStmt->execute([$gradeId, $teacherId]);
            $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                return [
                    'success' => false,
                    'requestId' => null,
                    'message' => 'Grade not found or you do not have permission to edit it'
                ];
            }
            
            // Check if grade is locked
            if ((int)($grade['is_locked'] ?? 0) === 0 && $grade['approval_status'] !== 'approved' && $grade['approval_status'] !== 'locked') {
                return [
                    'success' => false,
                    'requestId' => null,
                    'message' => 'Grade is not locked. You can edit it directly.'
                ];
            }
            
            // Check if there's already a pending request for this grade
            $existingStmt = $pdo->prepare("
                SELECT id FROM grade_edit_requests
                WHERE grade_id = ? AND status = 'pending'
                LIMIT 1
            ");
            $existingStmt->execute([$gradeId]);
            if ($existingStmt->fetch()) {
                return [
                    'success' => false,
                    'requestId' => null,
                    'message' => 'You already have a pending edit request for this grade'
                ];
            }
            
            // Check if there's an approved request that hasn't been completed yet
            $approvedStmt = $pdo->prepare("
                SELECT id FROM grade_edit_requests
                WHERE grade_id = ? AND status = 'approved' AND edit_completed = 0
                LIMIT 1
            ");
            $approvedStmt->execute([$gradeId]);
            if ($approvedStmt->fetch()) {
                return [
                    'success' => false,
                    'requestId' => null,
                    'message' => 'You have an approved edit request for this grade. Please complete the edit first.'
                ];
            }
            
            // Create the request
            $insertStmt = $pdo->prepare("
                INSERT INTO grade_edit_requests
                (teacher_id, grade_id, subject_id, course_id, academic_year, semester, request_reason, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $insertStmt->execute([
                $teacherId,
                $gradeId,
                $grade['subject_id'],
                $grade['course_id'] ?? null,
                $grade['academic_year'],
                $grade['semester'],
                $requestReason
            ]);
            
            $requestId = $pdo->lastInsertId();
            
            return [
                'success' => true,
                'requestId' => $requestId,
                'message' => 'Edit request submitted successfully. Waiting for admin approval.'
            ];
        } catch (PDOException $e) {
            error_log("Error creating grade edit request: " . $e->getMessage());
            return [
                'success' => false,
                'requestId' => null,
                'message' => 'Error creating edit request: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('approveGradeEditRequest')) {
    /**
     * Approve a grade edit request
     * 
     * @param PDO $pdo Database connection
     * @param int $requestId Request ID
     * @param int $adminId Admin ID
     * @param string|null $reviewNotes Review notes
     * @return array ['success' => bool, 'message' => string]
     */
    function approveGradeEditRequest(PDO $pdo, int $requestId, int $adminId, ?string $reviewNotes = null): array {
        try {
            // Check if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
            if ($tableCheck->rowCount() === 0) {
                return ['success' => false, 'message' => 'Database table not found. Please run the setup script.'];
            }
            
            // Get request details
            $requestStmt = $pdo->prepare("
                SELECT * FROM grade_edit_requests WHERE id = ?
            ");
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                return ['success' => false, 'message' => 'Request not found'];
            }
            
            if ($request['status'] !== 'pending') {
                return ['success' => false, 'message' => 'Request is not pending'];
            }
            
            // Update request status
            $updateStmt = $pdo->prepare("
                UPDATE grade_edit_requests
                SET status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_notes = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$adminId, $reviewNotes, $requestId]);
            
            // Temporarily unlock the grade for editing
            $unlockStmt = $pdo->prepare("
                UPDATE grades
                SET is_locked = 0,
                    edit_request_id = ?
                WHERE id = ?
            ");
            $unlockStmt->execute([$requestId, $request['grade_id']]);
            
            return [
                'success' => true,
                'message' => 'Edit request approved. Teacher can now edit the grade once.'
            ];
        } catch (PDOException $e) {
            error_log("Error approving grade edit request: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error approving request: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('denyGradeEditRequest')) {
    /**
     * Deny a grade edit request
     * 
     * @param PDO $pdo Database connection
     * @param int $requestId Request ID
     * @param int $adminId Admin ID
     * @param string|null $reviewNotes Denial reason
     * @return array ['success' => bool, 'message' => string]
     */
    function denyGradeEditRequest(PDO $pdo, int $requestId, int $adminId, ?string $reviewNotes = null): array {
        try {
            // Check if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
            if ($tableCheck->rowCount() === 0) {
                return ['success' => false, 'message' => 'Database table not found. Please run the setup script.'];
            }
            
            $updateStmt = $pdo->prepare("
                UPDATE grade_edit_requests
                SET status = 'denied',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_notes = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$adminId, $reviewNotes, $requestId]);
            
            return [
                'success' => true,
                'message' => 'Edit request denied'
            ];
        } catch (PDOException $e) {
            error_log("Error denying grade edit request: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error denying request: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('completeGradeEdit')) {
    /**
     * Mark grade edit as completed and re-lock the grade
     * 
     * @param PDO $pdo Database connection
     * @param int $gradeId Grade ID
     * @param int $adminId Admin ID (for re-approval)
     * @return array ['success' => bool, 'message' => string]
     */
    function completeGradeEdit(PDO $pdo, int $gradeId, int $adminId): array {
        try {
            // Check if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
            if ($tableCheck->rowCount() === 0) {
                return ['success' => false, 'message' => 'Database table not found. Please run the setup script.'];
            }
            
            // Get grade and request info
            $gradeStmt = $pdo->prepare("
                SELECT g.*, ger.id as request_id
                FROM grades g
                LEFT JOIN grade_edit_requests ger ON g.edit_request_id = ger.id
                WHERE g.id = ?
            ");
            $gradeStmt->execute([$gradeId]);
            $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                return ['success' => false, 'message' => 'Grade not found'];
            }
            
            // Mark request as completed
            if ($grade['request_id']) {
                $requestStmt = $pdo->prepare("
                    UPDATE grade_edit_requests
                    SET edit_completed = 1,
                        edit_completed_at = NOW(),
                        re_approved_by = ?,
                        re_approved_at = NOW(),
                        status = 'completed'
                    WHERE id = ?
                ");
                $requestStmt->execute([$adminId, $grade['request_id']]);
            }
            
            // Re-approve and lock the grade
            $lockStmt = $pdo->prepare("
                UPDATE grades
                SET is_locked = 1,
                    locked_at = NOW(),
                    approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    edit_request_id = NULL
                WHERE id = ?
            ");
            $lockStmt->execute([$adminId, $gradeId]);
            
            return [
                'success' => true,
                'message' => 'Grade edit completed and grade is now locked again'
            ];
        } catch (PDOException $e) {
            error_log("Error completing grade edit: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error completing edit: ' . $e->getMessage()
            ];
        }
    }
}

