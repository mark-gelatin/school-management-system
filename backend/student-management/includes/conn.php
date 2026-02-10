<?php
// Shared PDO connection for the student management module

if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings for better reliability
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 0); // Session cookie (expires when browser closes)
    ini_set('session.gc_maxlifetime', 28800); // 8 hours (increased from 1 hour)
    ini_set('session.cookie_path', '/'); // Ensure cookie is available for all paths
    
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    $currentDir = __DIR__; // backend/student-management/includes
    $includesDir = dirname($currentDir); // backend/student-management
    $backendDir = dirname($includesDir); // backend
    $projectRoot = dirname($backendDir); // project root
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    if (file_exists($pathsFile)) {
        require_once $pathsFile;
    } else {
        // Fallback to VPS path
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('config/database.php');
?>