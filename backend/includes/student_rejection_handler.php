<?php
/**
 * Student Rejection Handler
 * Handles student application rejection, student number removal, and dynamic reassignment
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/database_migration_helper.php';

/**
 * Handle student application rejection
 * 
 * @param PDO $pdo Database connection
 * @param int $applicationId Application ID to reject
 * @param int $adminId Admin ID performing the rejection
 * @param string|null $rejectionReason Reason for rejection
 * @return array Result with success status and message
 */
function handleStudentRejection($pdo, $applicationId, $adminId, $rejectionReason = null) {
    // Check if transaction is already active
    $transactionStarted = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $transactionStarted = true;
    }
    
    try {
        
        // Get application details
        $appStmt = $pdo->prepare("
            SELECT student_id, status 
            FROM admission_applications 
            WHERE id = ?
        ");
        $appStmt->execute([$applicationId]);
        $application = $appStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            throw new Exception('Application not found');
        }
        
        $studentId = $application['student_id'];
        
        // Ensure rejection_reason column exists (will add it if missing)
        $hasRejectionReasonColumn = ensureRejectionReasonColumn($pdo);
        
        // Update application status to rejected
        if ($hasRejectionReasonColumn) {
            // Column exists, use it
            $updateAppStmt = $pdo->prepare("
                UPDATE admission_applications 
                SET status = 'rejected', 
                    reviewed_by = ?, 
                    reviewed_at = NOW(), 
                    rejection_reason = ?,
                    notes = ?
                WHERE id = ?
            ");
            $updateAppStmt->execute([
                $adminId, 
                $rejectionReason, 
                $rejectionReason, // Also store in notes for backward compatibility
                $applicationId
            ]);
        } else {
            // Column doesn't exist, use notes field only
            $updateAppStmt = $pdo->prepare("
                UPDATE admission_applications 
                SET status = 'rejected', 
                    reviewed_by = ?, 
                    reviewed_at = NOW(), 
                    notes = ?
                WHERE id = ?
            ");
            $updateAppStmt->execute([
                $adminId, 
                $rejectionReason ?: 'Application rejected', // Store reason in notes
                $applicationId
            ]);
        }
        
        // Get student's current student_id_number before removal
        $studentStmt = $pdo->prepare("SELECT student_id_number, status FROM users WHERE id = ?");
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        
        $hadStudentNumber = !empty($student['student_id_number']);
        
        // Remove student number and update status
        $updateStudentStmt = $pdo->prepare("
            UPDATE users 
            SET student_id_number = NULL, 
                status = 'inactive'
            WHERE id = ? AND role = 'student'
        ");
        $updateStudentStmt->execute([$studentId]);
        
        // Remove student from all classrooms
        $removeClassroomStmt = $pdo->prepare("
            DELETE FROM classroom_students 
            WHERE student_id = ?
        ");
        $removeClassroomStmt->execute([$studentId]);
        
        // Remove all grades for this student (optional - you may want to keep them for records)
        // Uncomment if you want to remove grades:
        // $removeGradesStmt = $pdo->prepare("DELETE FROM grades WHERE student_id = ?");
        // $removeGradesStmt->execute([$studentId]);
        
        // Reassign student numbers if this student had one
        if ($hadStudentNumber) {
            reassignStudentNumbers($pdo);
        }
        
        // Log the action
        require_once __DIR__ . '/../student-management/includes/functions.php';
        if (function_exists('logAdminAction')) {
            $logMessage = "Rejected student application (ID: $applicationId). Student number removed.";
            if ($rejectionReason) {
                $logMessage .= " Reason: $rejectionReason";
            }
            logAdminAction($pdo, $adminId, 'reject_application', 'admission_application', $applicationId, $logMessage);
        }
        
        // Only commit if we started the transaction
        if ($transactionStarted) {
            $pdo->commit();
        }
        
        return [
            'success' => true,
            'message' => 'Application rejected successfully. Student number removed and numbers reassigned.',
            'student_id' => $studentId
        ];
    } catch (Exception $e) {
        // Only rollback if we started the transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Student rejection error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error rejecting application: ' . $e->getMessage()
        ];
    }
}

/**
 * Reassign student numbers to maintain sequential order
 * Only assigns numbers to enrolled students (those with approved applications)
 * 
 * @param PDO $pdo Database connection
 * @return array Result with success status and reassigned count
 */
function reassignStudentNumbers($pdo) {
    // Check if transaction is already active
    $transactionStarted = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $transactionStarted = true;
    }
    
    try {
        // Get current year for student number format
        $year = date('Y');
        
        // Get all enrolled students (approved applications) ordered by application date
        // This ensures students get numbers in the order they were approved
        // Exclude rejected students
        $enrolledStudentsStmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.student_id_number, aa.created_at, aa.reviewed_at
            FROM users u
            INNER JOIN admission_applications aa ON u.id = aa.student_id
            WHERE u.role = 'student'
            AND aa.status = 'approved'
            AND u.status = 'active'
            AND u.student_id_number IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM admission_applications aa2 
                WHERE aa2.student_id = u.id 
                AND aa2.status = 'rejected'
            )
            ORDER BY 
                COALESCE(aa.reviewed_at, aa.created_at) ASC,
                u.id ASC
        ");
        $enrolledStudentsStmt->execute();
        $enrolledStudents = $enrolledStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reassignedCount = 0;
        $number = 1;
        
        foreach ($enrolledStudents as $student) {
            $expectedNumber = sprintf('%s-%04d', $year, $number);
            
            // Always update to ensure sequential order
            if ($student['student_id_number'] !== $expectedNumber) {
                // Check if the expected number is already taken by another student
                $checkStmt = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE student_id_number = ? AND id != ?
                ");
                $checkStmt->execute([$expectedNumber, $student['id']]);
                
                if ($checkStmt->rowCount() > 0) {
                    // Number is taken, find next available
                    $availableNumber = findNextAvailableStudentNumber($pdo, $year, $number);
                    $expectedNumber = $availableNumber;
                    // Update number counter based on the assigned number
                    $parts = explode('-', $expectedNumber);
                    $number = intval($parts[1]);
                }
                
                // Update student number
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET student_id_number = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$expectedNumber, $student['id']]);
                $reassignedCount++;
            }
            
            $number++;
        }
        
        // Only commit if we started the transaction
        if ($transactionStarted) {
            $pdo->commit();
        }
        
        return [
            'success' => true,
            'reassigned_count' => $reassignedCount,
            'message' => "Reassigned $reassignedCount student number(s)"
        ];
    } catch (Exception $e) {
        // Only rollback if we started the transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Student number reassignment error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error reassigning student numbers: ' . $e->getMessage(),
            'reassigned_count' => 0
        ];
    }
}

/**
 * Find next available student number
 * 
 * @param PDO $pdo Database connection
 * @param string $year Year for student number format
 * @param int $startNumber Starting number to check from
 * @return string Next available student number
 */
function findNextAvailableStudentNumber($pdo, $year, $startNumber = 1) {
    $number = $startNumber;
    $maxAttempts = 10000; // Prevent infinite loop
    $attempts = 0;
    
    while ($attempts < $maxAttempts) {
        $studentNumber = sprintf('%s-%04d', $year, $number);
        
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE student_id_number = ?");
        $checkStmt->execute([$studentNumber]);
        
        if ($checkStmt->rowCount() === 0) {
            return $studentNumber;
        }
        
        $number++;
        $attempts++;
    }
    
    // Fallback: use timestamp-based number
    return $year . '-' . str_pad(time() % 10000, 4, '0', STR_PAD_LEFT);
}

/**
 * Check if student is rejected
 * 
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID
 * @return array Status information
 */
function isStudentRejected($pdo, $studentId) {
    try {
        // Check if rejection_reason column exists
        $hasRejectionReasonColumn = ensureRejectionReasonColumn($pdo);
        
        // Check admission application status
        if ($hasRejectionReasonColumn) {
            $appStmt = $pdo->prepare("
                SELECT status, rejection_reason, reviewed_at, reviewed_by, notes
                FROM admission_applications
                WHERE student_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
        } else {
            $appStmt = $pdo->prepare("
                SELECT status, notes as rejection_reason, reviewed_at, reviewed_by
                FROM admission_applications
                WHERE student_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
        }
        $appStmt->execute([$studentId]);
        $application = $appStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($application && $application['status'] === 'rejected') {
            return [
                'rejected' => true,
                'rejection_reason' => $application['rejection_reason'] ?? $application['notes'] ?? null,
                'rejected_at' => $application['reviewed_at'],
                'rejected_by' => $application['reviewed_by']
            ];
        }
        
        // Also check if student has no student number (indicates rejection)
        $studentStmt = $pdo->prepare("
            SELECT student_id_number, status 
            FROM users 
            WHERE id = ? AND role = 'student'
        ");
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student && empty($student['student_id_number']) && $student['status'] === 'inactive') {
            return [
                'rejected' => true,
                'rejection_reason' => 'Application was rejected',
                'rejected_at' => null,
                'rejected_by' => null
            ];
        }
        
        return ['rejected' => false];
    } catch (Exception $e) {
        error_log("Error checking student rejection status: " . $e->getMessage());
        return ['rejected' => false];
    }
}

/**
 * Get rejection notification message for student
 * 
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID
 * @return string|null Rejection message or null if not rejected
 */
function getRejectionNotification($pdo, $studentId) {
    $rejectionStatus = isStudentRejected($pdo, $studentId);
    
    if ($rejectionStatus['rejected']) {
        $message = "Your application has been rejected.";
        if (!empty($rejectionStatus['rejection_reason'])) {
            $message .= " Reason: " . htmlspecialchars($rejectionStatus['rejection_reason']);
        }
        return $message;
    }
    
    return null;
}

/**
 * Exclude rejected students from query results
 * This is a helper function to add WHERE clauses that exclude rejected students
 * 
 * @return string SQL WHERE clause to exclude rejected students
 */
function getExcludeRejectedStudentsClause() {
    return "
        AND u.id NOT IN (
            SELECT DISTINCT student_id 
            FROM admission_applications 
            WHERE status = 'rejected'
        )
        AND (u.student_id_number IS NOT NULL OR u.status = 'active')
    ";
}

