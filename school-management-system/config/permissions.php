<?php
/**
 * Role/permission management for RBAC checks.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Fetch permission keys assigned to a role.
 */
function get_permissions_for_role(string $role): array
{
    if ($role === '') {
        return [];
    }

    if (isset($_SESSION['role_permissions_cache'][$role]) && is_array($_SESSION['role_permissions_cache'][$role])) {
        return $_SESSION['role_permissions_cache'][$role];
    }

    $db = get_db();
    $sql = 'SELECT p.permission_key
            FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            INNER JOIN roles r ON r.id = rp.role_id
            WHERE r.name = :role';
    $stmt = $db->prepare($sql);
    $stmt->execute(['role' => $role]);
    $permissions = array_column($stmt->fetchAll(), 'permission_key');

    $_SESSION['role_permissions_cache'][$role] = $permissions;
    return $permissions;
}

/**
 * Validate if logged user has a specific permission key.
 */
function has_permission(string $permissionKey): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    $permissions = get_permissions_for_role($user['role']);
    return in_array($permissionKey, $permissions, true);
}

/**
 * Stops request when permission does not exist.
 */
function require_permission(string $permissionKey): void
{
    require_login();
    if (!has_permission($permissionKey)) {
        if (wants_json()) {
            json_response(false, 'Permission denied.', [], 403);
        }
        header('Location: ' . app_url('index.php'));
        exit;
    }
}
