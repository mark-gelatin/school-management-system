<?php
/**
 * Student Approval Status Check
 * 
 * This function checks if a student account has been approved by the admin.
 * It checks the admission_applications table first, then falls back to checking
 * the user's status field if the admission table doesn't exist.
 * 
 * @param PDO $pdo Database connection
 * @param int $studentId Student user ID
 * @param array|null $student Optional: Pre-fetched student data to avoid extra query
 * @return array Returns array with 'isApproved' (bool) and 'admissionInfo' (array|null)
 */
function checkStudentApprovalStatus($pdo, $studentId, $student = null) {
    $isApproved = false;
    $admissionInfo = null;
    
    try {
        // Check if admission_applications table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'admission_applications'");
        $admissionTableExists = $stmt->rowCount() > 0;
        
        if ($admissionTableExists) {
            $stmt = $pdo->prepare("
                SELECT * FROM admission_applications 
                WHERE student_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $admissionInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Student is approved ONLY if their admission application status is 'approved'
            if ($admissionInfo && $admissionInfo['status'] === 'approved') {
                $isApproved = true;
            }
        } else {
            // Fallback: If admission_applications table doesn't exist, 
            // check if student has student_id_number and status is 'active'
            if ($student === null) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($student && !empty($student['student_id_number']) && isset($student['status']) && $student['status'] === 'active') {
                $isApproved = true;
            }
        }
    } catch (PDOException $e) {
        // If there's an error, default to not approved
        error_log("Error checking student approval status: " . $e->getMessage());
        $isApproved = false;
    }
    
    return [
        'isApproved' => $isApproved,
        'admissionInfo' => $admissionInfo
    ];
}



