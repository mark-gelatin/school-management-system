<?php
/**
 * Rate Limiting for OTP Requests
 * Prevents abuse and brute-force attacks
 */

if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config/database.php';
    $pdo = getDatabaseConnection();
}

require_once __DIR__ . '/config.php';

/**
 * Check if OTP request is allowed based on rate limiting
 */
function checkOTPRateLimit($email, $purpose) {
    global $pdo;
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    try {
        // Check if blocked
        $blockedStmt = $pdo->prepare("
            SELECT blocked_until 
            FROM otp_rate_limits 
            WHERE email = ? AND ip_address = ? AND purpose = ? AND blocked_until > NOW()
            LIMIT 1
        ");
        $blockedStmt->execute([$email, $ipAddress, $purpose]);
        $blocked = $blockedStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($blocked) {
            $blockedUntil = new DateTime($blocked['blocked_until']);
            $now = new DateTime();
            $minutesRemaining = ceil(($blockedUntil->getTimestamp() - $now->getTimestamp()) / 60);
            
            return [
                'allowed' => false,
                'message' => "Too many requests. Please try again in {$minutesRemaining} minute(s).",
                'blocked_until' => $blocked['blocked_until']
            ];
        }
        
        // Check request count in time window
        $windowStart = date('Y-m-d H:i:s', time() - OTP_RATE_LIMIT_WINDOW);
        
        $checkStmt = $pdo->prepare("
            SELECT request_count, first_request, last_request
            FROM otp_rate_limits
            WHERE email = ? AND ip_address = ? AND purpose = ?
            LIMIT 1
        ");
        $checkStmt->execute([$email, $ipAddress, $purpose]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Check if within time window
            $lastRequest = new DateTime($existing['last_request']);
            $windowStartTime = new DateTime($windowStart);
            
            if ($lastRequest > $windowStartTime) {
                // Within time window
                if ($existing['request_count'] >= OTP_RATE_LIMIT_COUNT) {
                    // Block this email/IP combination
                    $blockedUntil = date('Y-m-d H:i:s', time() + OTP_BLOCK_DURATION);
                    
                    $updateStmt = $pdo->prepare("
                        UPDATE otp_rate_limits 
                        SET blocked_until = ?, last_request = NOW()
                        WHERE email = ? AND ip_address = ? AND purpose = ?
                    ");
                    $updateStmt->execute([$blockedUntil, $email, $ipAddress, $purpose]);
                    
                    $minutes = ceil(OTP_BLOCK_DURATION / 60);
                    return [
                        'allowed' => false,
                        'message' => "Too many OTP requests. Please try again in {$minutes} minute(s).",
                        'blocked_until' => $blockedUntil
                    ];
                }
            } else {
                // Outside time window, reset count
                $resetStmt = $pdo->prepare("
                    UPDATE otp_rate_limits 
                    SET request_count = 1, first_request = NOW(), last_request = NOW(), blocked_until = NULL
                    WHERE email = ? AND ip_address = ? AND purpose = ?
                ");
                $resetStmt->execute([$email, $ipAddress, $purpose]);
            }
        }
        
        return ['allowed' => true];
        
    } catch (PDOException $e) {
        error_log("Rate limit check error: " . $e->getMessage());
        // On error, allow the request but log it
        return ['allowed' => true];
    }
}

/**
 * Record an OTP request for rate limiting
 */
function recordOTPRequest($email, $purpose) {
    global $pdo;
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO otp_rate_limits (email, ip_address, purpose, request_count, first_request, last_request)
            VALUES (?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                request_count = request_count + 1,
                last_request = NOW()
        ");
        
        $stmt->execute([$email, $ipAddress, $purpose]);
        
    } catch (PDOException $e) {
        error_log("Error recording OTP request: " . $e->getMessage());
    }
}

/**
 * Clean up old rate limit records (call via cron or scheduled task)
 */
function cleanupRateLimits() {
    global $pdo;
    
    try {
        // Delete records older than 24 hours that are not blocked
        $stmt = $pdo->prepare("
            DELETE FROM otp_rate_limits 
            WHERE last_request < DATE_SUB(NOW(), INTERVAL 24 HOUR) 
            AND (blocked_until IS NULL OR blocked_until < NOW())
        ");
        $stmt->execute();
        
        // Delete expired OTP tokens
        $otpStmt = $pdo->prepare("
            DELETE FROM otp_tokens 
            WHERE expiry < NOW() OR (used = 1 AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
        ");
        $otpStmt->execute();
        
    } catch (PDOException $e) {
        error_log("Error cleaning up rate limits: " . $e->getMessage());
    }
}











