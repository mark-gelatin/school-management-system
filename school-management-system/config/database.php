<?php
/**
 * Core configuration and database connection bootstrap.
 * Uses PDO + prepared statements for all SQL interactions.
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Colegio De Amore - School Management System & LMS');
}

if (!defined('APP_DIRNAME')) {
    define('APP_DIRNAME', basename(dirname(__DIR__)));
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('SMS_DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('SMS_DB_PORT') ?: '3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('SMS_DB_NAME') ?: 'school_management_system');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('SMS_DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('SMS_DB_PASS') ?: '');
}
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', getenv('SMS_MAIL_FROM_ADDRESS') ?: 'noreply@colegiodeamore.edu');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('SMS_MAIL_FROM_NAME') ?: 'Colegio De Amore');
}

/**
 * Returns the app base path from current script context.
 */
function app_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $needle = '/' . APP_DIRNAME;
    $position = strpos($scriptName, $needle);
    if ($position !== false) {
        $basePath = rtrim(substr($scriptName, 0, $position + strlen($needle)), '/');
        return $basePath ?: '';
    }

    $basePath = '/' . APP_DIRNAME;
    return $basePath;
}

/**
 * Build an absolute app-relative URL.
 */
function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return app_base_path() . ($path !== '' ? '/' . $path : '');
}

/**
 * Create and return a shared PDO instance.
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}

/**
 * Standard JSON output helper for API endpoints.
 */
function json_response(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

/**
 * Parse JSON request payload safely.
 */
function get_json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}
