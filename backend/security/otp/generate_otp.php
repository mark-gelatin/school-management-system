<?php
/**
 * Generate OTP Token
 * Creates a 6-digit numeric OTP and stores it in the database
 * 
 * @param string $email Email address
 * @param string $purpose 'registration' or 'reset'
 * @param int|null $userId User ID (for password reset, NULL for registration)
 * @return array Result array with success status and OTP data
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limit.php';

// Ensure database connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config/database.php';
    $pdo = getDatabaseConnection();
}

/**
 * Generate a 6-digit numeric OTP
 */
function generateOTPCode() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Generate and store OTP token
 */
function generateOTP($email, $purpose = 'registration', $userId = null) {
    global $pdo;
    
    // Validate purpose
    if (!in_array($purpose, ['registration', 'reset'])) {
        return [
            'success' => false,
            'message' => 'Invalid OTP purpose'
        ];
    }
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid email address'
        ];
    }
    
    // Check rate limiting
    $rateLimitCheck = checkOTPRateLimit($email, $purpose);
    if (!$rateLimitCheck['allowed']) {
        return [
            'success' => false,
            'message' => $rateLimitCheck['message'],
            'blocked_until' => $rateLimitCheck['blocked_until'] ?? null
        ];
    }
    
    // Invalidate any existing unused OTPs for this email and purpose
    try {
        $invalidateStmt = $pdo->prepare("
            UPDATE otp_tokens 
            SET used = 1 
            WHERE email = ? AND purpose = ? AND used = 0 AND expiry > NOW()
        ");
        $invalidateStmt->execute([$email, $purpose]);
    } catch (PDOException $e) {
        error_log("Error invalidating old OTPs: " . $e->getMessage());
    }
    
    // Generate OTP
    $otp = generateOTPCode();
    $expiry = date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60));
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Store OTP in database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO otp_tokens (user_id, email, otp, purpose, expiry, max_attempts, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $email,
            $otp,
            $purpose,
            $expiry,
            OTP_MAX_ATTEMPTS,
            $ipAddress
        ]);
        
        $otpId = $pdo->lastInsertId();
        
        // Record rate limit
        recordOTPRequest($email, $purpose);
        
        return [
            'success' => true,
            'otp_id' => $otpId,
            'otp' => $otp, // Only return in development, remove in production
            'expiry' => $expiry,
            'expiry_minutes' => OTP_EXPIRY_MINUTES,
            'message' => 'OTP generated successfully'
        ];
        
    } catch (PDOException $e) {
        error_log("Error generating OTP: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to generate OTP. Please try again.'
        ];
    }
}

// API endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) === 'generate_otp.php') {
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $purpose = $input['purpose'] ?? 'registration';
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    
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
    
    $result = generateOTP($email, $purpose, $userId);
    
    // Don't expose OTP in production response
    if (isset($result['otp'])) {
        unset($result['otp']);
    }
    
    echo json_encode($result);
    exit;
}

