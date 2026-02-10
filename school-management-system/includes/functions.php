<?php
/**
 * Shared utility functions used across modules.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

/**
 * Escape output for safe HTML rendering.
 */
function e(string|null $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize and trim user input.
 */
function clean_input(string|null $value): string
{
    return trim((string) ($value ?? ''));
}

/**
 * Sets one-time flash message.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Returns and clears one-time flash message.
 */
function get_flash(): ?array
{
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return is_array($flash) ? $flash : null;
}

/**
 * CSRF token generator.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF token validator.
 */
function verify_csrf_token(?string $token): bool
{
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Returns true for HTTP POST method.
 */
function is_post_request(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Create 6-digit OTP code.
 */
function generate_otp_code(): string
{
    return (string) random_int(100000, 999999);
}

/**
 * Store OTP verification record for a user.
 */
function create_email_otp(int $userId, string $otpCode): bool
{
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE email_verification SET status = "expired", updated_at = NOW() WHERE user_id = :user_id AND status = "pending"')
            ->execute(['user_id' => $userId]);

        $db->prepare('INSERT INTO email_verification (user_id, otp_code, expires_at, status) VALUES (:user_id, :otp_code, DATE_ADD(NOW(), INTERVAL 10 MINUTE), "pending")')
            ->execute([
                'user_id' => $userId,
                'otp_code' => $otpCode,
            ]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        return false;
    }
}

/**
 * Send OTP email via PHPMailer (with mail() fallback).
 */
function send_otp_email(string $recipientEmail, string $recipientName, string $otpCode): bool
{
    $subject = 'Colegio De Amore - Email Verification OTP';
    $htmlBody = '<h2>Colegio De Amore</h2>'
        . '<p>Your one-time password (OTP) is:</p>'
        . '<h1 style="letter-spacing:4px;">' . e($otpCode) . '</h1>'
        . '<p>This OTP expires in <strong>10 minutes</strong>.</p>'
        . '<p>If you did not request this, you can ignore this email.</p>';

    $plainBody = "Colegio De Amore\n\nYour OTP is: {$otpCode}\nThis code expires in 10 minutes.";
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $smtpHost = getenv('SMS_SMTP_HOST') ?: '';

            if ($smtpHost !== '') {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->Port = (int) (getenv('SMS_SMTP_PORT') ?: 587);
                $mail->SMTPAuth = true;
                $mail->Username = getenv('SMS_SMTP_USER') ?: '';
                $mail->Password = getenv('SMS_SMTP_PASS') ?: '';
                $secure = strtolower((string) (getenv('SMS_SMTP_SECURE') ?: 'tls'));
                $mail->SMTPSecure = $secure === 'ssl'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $plainBody;
            return $mail->send();
        } catch (Throwable $e) {
            error_log('PHPMailer OTP send failed: ' . $e->getMessage());
            return false;
        }
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . ">\r\n";
    return mail($recipientEmail, $subject, $htmlBody, $headers);
}

/**
 * Add audit trail record for user actions.
 */
function log_audit(string $action, string $module, string $description = ''): void
{
    try {
        $db = get_db();
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, module, description, ip_address, user_agent)
             VALUES (:user_id, :action, :module, :description, :ip, :agent)'
        );
        $stmt->execute([
            'user_id' => current_user_id(),
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Safely fetch a single DB row.
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Safely fetch multiple DB rows.
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Build the current user's full display name.
 */
function current_user_name(): string
{
    $user = current_user();
    if (!$user) {
        return 'Guest';
    }
    return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}

/**
 * Computes student GPA from numeric final grades.
 */
function compute_gpa(array $rows): float
{
    if ($rows === []) {
        return 0.0;
    }

    $points = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        $grade = isset($row['final_grade']) ? (float) $row['final_grade'] : 0.0;
        if ($grade <= 0) {
            continue;
        }

        if ($grade >= 97) {
            $gp = 1.0;
        } elseif ($grade >= 94) {
            $gp = 1.25;
        } elseif ($grade >= 91) {
            $gp = 1.5;
        } elseif ($grade >= 88) {
            $gp = 1.75;
        } elseif ($grade >= 85) {
            $gp = 2.0;
        } elseif ($grade >= 82) {
            $gp = 2.25;
        } elseif ($grade >= 79) {
            $gp = 2.5;
        } elseif ($grade >= 76) {
            $gp = 2.75;
        } elseif ($grade >= 75) {
            $gp = 3.0;
        } else {
            $gp = 5.0;
        }

        $points += $gp;
        $count++;
    }

    return $count > 0 ? round($points / $count, 2) : 0.0;
}
