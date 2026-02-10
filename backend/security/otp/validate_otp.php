<?php
/**
 * Validate OTP Token
 * Verifies OTP input against stored OTP in database
 */

require_once __DIR__ . '/config.php';

if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config/database.php';
    $pdo = getDatabaseConnection();
}

/**
 * Validate OTP
 */
function validateOTP($email, $otp, $purpose = 'registration') {
    global $pdo;
    
    // Validate inputs
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid email address'
        ];
    }
    
    if (!preg_match('/^\d{6}$/', $otp)) {
        return [
            'success' => false,
            'message' => 'OTP must be 6 digits'
        ];
    }
    
    if (!in_array($purpose, ['registration', 'reset'])) {
        return [
            'success' => false,
            'message' => 'Invalid purpose'
        ];
    }
    
    try {
        // Find valid OTP
        $stmt = $pdo->prepare("
            SELECT id, user_id, otp, expiry, attempts, max_attempts, used
            FROM otp_tokens
            WHERE email = ? AND purpose = ? AND used = 0 AND expiry > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$email, $purpose]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            return [
                'success' => false,
                'message' => 'Invalid or expired OTP. Please request a new one.'
            ];
        }
        
        // Check if max attempts exceeded
        if ($otpRecord['attempts'] >= $otpRecord['max_attempts']) {
            // Mark as used to prevent further attempts
            $updateStmt = $pdo->prepare("UPDATE otp_tokens SET used = 1 WHERE id = ?");
            $updateStmt->execute([$otpRecord['id']]);
            
            return [
                'success' => false,
                'message' => 'Maximum attempts exceeded. Please request a new OTP.'
            ];
        }
        
        // Verify OTP
        if (!hash_equals($otpRecord['otp'], $otp)) {
            // Increment attempts
            $attemptStmt = $pdo->prepare("
                UPDATE otp_tokens 
                SET attempts = attempts + 1 
                WHERE id = ?
            ");
            $attemptStmt->execute([$otpRecord['id']]);
            
            $remainingAttempts = $otpRecord['max_attempts'] - $otpRecord['attempts'] - 1;
            
            return [
                'success' => false,
                'message' => 'Invalid OTP. ' . ($remainingAttempts > 0 ? "You have {$remainingAttempts} attempt(s) remaining." : 'Maximum attempts reached.'),
                'remaining_attempts' => max(0, $remainingAttempts)
            ];
        }
        
        // OTP is valid - mark as used
        $updateStmt = $pdo->prepare("
            UPDATE otp_tokens 
            SET used = 1, updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$otpRecord['id']]);
        
        return [
            'success' => true,
            'message' => 'OTP verified successfully',
            'user_id' => $otpRecord['user_id'],
            'otp_id' => $otpRecord['id']
        ];
        
    } catch (PDOException $e) {
        error_log("Error validating OTP: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error validating OTP. Please try again.'
        ];
    }
}

/**
 * Check if OTP exists and is valid (without consuming it)
 */
function checkOTPStatus($email, $purpose = 'registration') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, expiry, attempts, max_attempts, used
            FROM otp_tokens
            WHERE email = ? AND purpose = ? AND used = 0 AND expiry > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$email, $purpose]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            return [
                'exists' => false,
                'message' => 'No active OTP found'
            ];
        }
        
        $expiryTime = new DateTime($otpRecord['expiry']);
        $now = new DateTime();
        $secondsRemaining = $expiryTime->getTimestamp() - $now->getTimestamp();
        
        return [
            'exists' => true,
            'expiry' => $otpRecord['expiry'],
            'seconds_remaining' => max(0, $secondsRemaining),
            'attempts' => $otpRecord['attempts'],
            'max_attempts' => $otpRecord['max_attempts'],
            'remaining_attempts' => $otpRecord['max_attempts'] - $otpRecord['attempts']
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking OTP status: " . $e->getMessage());
        return [
            'exists' => false,
            'message' => 'Error checking OTP status'
        ];
    }
}

// API endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) === 'validate_otp.php') {
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $otp = preg_replace('/[^0-9]/', '', $input['otp'] ?? '');
    $purpose = $input['purpose'] ?? 'registration';
    
    // Validate CSRF token if available
    if (isset($_SESSION['csrf_token']) && isset($input['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid security token'
            ]);
            exit;
        }
    }
    
    $result = validateOTP($email, $otp, $purpose);
    echo json_encode($result);
    exit;
}











