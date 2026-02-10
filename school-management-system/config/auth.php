<?php
/**
 * Authentication and session utilities.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Determines whether request expects JSON.
 */
function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($accept, 'application/json') || str_contains($requestUri, '/api/');
}

/**
 * Returns currently authenticated user from session.
 */
function current_user(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

/**
 * Returns the current authenticated user id.
 */
function current_user_id(): ?int
{
    $user = current_user();
    return $user ? (int) $user['id'] : null;
}

/**
 * Returns whether a user is authenticated.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['auth_user']['id']);
}

/**
 * Persist user in session after successful login.
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
    ];
}

/**
 * End authenticated session.
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Load authenticated user record from DB.
 */
function refresh_session_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT id, email, role, first_name, last_name, status, is_verified FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => current_user_id()]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || (int) $user['is_verified'] !== 1) {
        logout_user();
        return null;
    }

    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
    ];

    return $_SESSION['auth_user'];
}

/**
 * Ensure user is logged in, else redirect / JSON error.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        if (wants_json()) {
            json_response(false, 'Authentication required.', [], 401);
        }
        header('Location: ' . app_url('auth/login.php'));
        exit;
    }

    if (!refresh_session_user()) {
        if (wants_json()) {
            json_response(false, 'Session expired. Please login again.', [], 401);
        }
        header('Location: ' . app_url('auth/login.php'));
        exit;
    }
}

/**
 * Restrict route access to one or more roles.
 */
function require_role(string|array $roles): void
{
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        if (wants_json()) {
            json_response(false, 'You do not have access to this resource.', [], 403);
        }
        header('Location: ' . app_url('index.php'));
        exit;
    }
}

/**
 * Redirect authenticated users to role dashboard.
 */
function redirect_to_dashboard_by_role(string $role): void
{
    $map = [
        'admin' => 'admin/dashboard.php',
        'student' => 'student/dashboard.php',
        'faculty' => 'faculty/dashboard.php',
    ];
    $target = $map[$role] ?? 'index.php';
    header('Location: ' . app_url($target));
    exit;
}
