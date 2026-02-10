<?php
/**
 * Send OTP via Email
 * Uses PHPMailer or PHP mail() function to send OTP
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/generate_otp.php';

// Include email functions from backend
$backendEmailPath = __DIR__ . '/../../../backend/student-management/includes/email.php';
if (file_exists($backendEmailPath) && !function_exists('sendEmail')) {
    require_once $backendEmailPath;
}

if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config/database.php';
    $pdo = getDatabaseConnection();
}

/**
 * Send OTP via email
 */
function sendOTPEmail($email, $purpose = 'registration', $userId = null, $userName = null) {
    // Generate OTP first
    $otpResult = generateOTP($email, $purpose, $userId);
    
    if (!$otpResult['success']) {
        return $otpResult;
    }
    
    $otp = $otpResult['otp'];
    $expiryMinutes = $otpResult['expiry_minutes'];
    
    // Get email configuration
    $emailConfig = getOTPEmailConfig();
    
    // Prepare email content
    $subject = $purpose === 'registration' 
        ? 'Email Verification - ' . OTP_SITE_NAME
        : 'Password Reset Verification - ' . OTP_SITE_NAME;
    
    $userDisplayName = $userName ?: 'User';
    
    $message = getOTPEmailTemplate($otp, $purpose, $userDisplayName, $expiryMinutes);
    
    // Try to use existing email system first
    if (function_exists('sendEmail')) {
        $result = sendEmail($email, $subject, $message, true);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'OTP sent successfully to your email',
                'expiry_minutes' => $expiryMinutes
            ];
        }
    }
    
    // Fallback to direct SMTP or PHP mail
    return sendOTPDirect($email, $subject, $message, $emailConfig);
}

/**
 * Send OTP email directly using PHPMailer or PHP mail()
 */
function sendOTPDirect($to, $subject, $message, $config) {
    // Try PHPMailer first
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = !empty($config['username']);
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';
            
            // Recipients
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = strip_tags($message);
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => 'OTP sent successfully to your email'
            ];
            
        } catch (Exception $e) {
            error_log("PHPMailer error: " . $mail->ErrorInfo);
            // Fall through to PHP mail()
        }
    }
    
    // Fallback to PHP mail()
    $headers = [];
    $headers[] = "From: {$config['from_name']} <{$config['from_email']}>";
    $headers[] = "Reply-To: {$config['from_email']}";
    $headers[] = "X-Mailer: PHP/" . phpversion();
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-type: text/html; charset=UTF-8";
    
    $result = @mail($to, $subject, $message, implode("\r\n", $headers));
    
    return [
        'success' => $result,
        'message' => $result ? 'OTP sent successfully' : 'Failed to send OTP email. Please try again.'
    ];
}

/**
 * Get OTP email template
 */
function getOTPEmailTemplate($otp, $purpose, $userName, $expiryMinutes) {
    $purposeText = $purpose === 'registration' ? 'Email Verification' : 'Password Reset';
    $instructions = $purpose === 'registration' 
        ? 'Please enter this code to verify your email address and complete your registration.'
        : 'Please enter this code to verify your identity and reset your password.';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .header { background: #a11c27; color: white; padding: 30px 20px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { padding: 30px 20px; }
            .otp-box { background: #f8f9fa; border: 2px dashed #a11c27; border-radius: 8px; padding: 20px; text-align: center; margin: 30px 0; }
            .otp-code { font-size: 32px; font-weight: bold; color: #a11c27; letter-spacing: 8px; font-family: 'Courier New', monospace; }
            .instructions { color: #666; margin: 20px 0; }
            .expiry { color: #999; font-size: 14px; margin-top: 15px; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
            @media only screen and (max-width: 600px) {
                .container { margin: 10px; }
                .otp-code { font-size: 24px; letter-spacing: 4px; }
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(OTP_SITE_NAME) . "</h1>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($userName) . ",</p>
                <p>$instructions</p>
                
                <div class='otp-box'>
                    <div style='color: #666; font-size: 14px; margin-bottom: 10px;'>Your Verification Code</div>
                    <div class='otp-code'>" . htmlspecialchars($otp) . "</div>
                </div>
                
                <div class='expiry'>
                    This code will expire in $expiryMinutes minutes.
                </div>
                
                <div class='warning'>
                    <strong>Security Notice:</strong> Never share this code with anyone. " . htmlspecialchars(OTP_SITE_NAME) . " staff will never ask for your verification code.
                </div>
                
                <p>If you did not request this code, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>&copy; " . date('Y') . " " . htmlspecialchars(OTP_SITE_NAME) . ". All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";
}

// API endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) === 'send_otp.php') {
    header('Content-Type: application/json');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $purpose = $input['purpose'] ?? 'registration';
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    $userName = isset($input['user_name']) ? trim($input['user_name']) : null;
    
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
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Valid email address is required'
        ]);
        exit;
    }
    
    $result = sendOTPEmail($email, $purpose, $userId, $userName);
    echo json_encode($result);
    exit;
}

