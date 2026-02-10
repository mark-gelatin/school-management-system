<?php
/**
 * OTP System Configuration
 * Colegio de Amore - Email-based OTP Configuration
 * 
 * Configure PHPMailer settings for Hostinger hosting
 */

// OTP Configuration
define('OTP_LENGTH', 6); // 6-digit OTP
define('OTP_EXPIRY_MINUTES', 10); // OTP expires in 10 minutes
define('OTP_MAX_ATTEMPTS', 5); // Maximum validation attempts
define('OTP_RATE_LIMIT_COUNT', 3); // Max OTP requests per time window
define('OTP_RATE_LIMIT_WINDOW', 3600); // Time window in seconds (1 hour)
define('OTP_BLOCK_DURATION', 1800); // Block duration in seconds (30 minutes) if rate limit exceeded

// Email Configuration (for Hostinger)
// These can be overridden by system settings if available
define('OTP_SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.hostinger.com');
define('OTP_SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('OTP_SMTP_USERNAME', getenv('SMTP_USERNAME') ?: ''); // Set via environment or system settings
define('OTP_SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: ''); // Set via environment or system settings
define('OTP_SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // tls or ssl
define('OTP_FROM_EMAIL', getenv('OTP_FROM_EMAIL') ?: 'noreply@colegiodeamore.edu');
define('OTP_FROM_NAME', getenv('OTP_FROM_NAME') ?: 'Colegio de Amore');

// Site Configuration
define('OTP_SITE_NAME', 'Colegio de Amore');
define('OTP_SITE_URL', getenv('SITE_URL') ?: (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : ''));

/**
 * Get SMTP configuration from system settings or constants
 * Falls back to constants if system settings not available
 */
function getOTPEmailConfig() {
    global $pdo;
    
    $config = [
        'host' => OTP_SMTP_HOST,
        'port' => OTP_SMTP_PORT,
        'username' => OTP_SMTP_USERNAME,
        'password' => OTP_SMTP_PASSWORD,
        'encryption' => OTP_SMTP_ENCRYPTION,
        'from_email' => OTP_FROM_EMAIL,
        'from_name' => OTP_FROM_NAME
    ];
    
    // Try to get from system settings if function exists
    if (function_exists('getSystemSetting')) {
        $config['host'] = getSystemSetting('smtp_host', $config['host']);
        $config['port'] = getSystemSetting('smtp_port', $config['port']);
        $config['username'] = getSystemSetting('smtp_username', $config['username']);
        $config['password'] = getSystemSetting('smtp_password', $config['password']);
        $config['encryption'] = getSystemSetting('smtp_encryption', $config['encryption']);
        $config['from_email'] = getSystemSetting('site_email', $config['from_email']);
        $config['from_name'] = getSystemSetting('site_name', $config['from_name']);
    }
    
    return $config;
}











