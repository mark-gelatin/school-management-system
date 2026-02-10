<?php
/**
 * Centralized Path Configuration
 * Colegio de Amore - Base Path Configuration for VPS Deployment
 * 
 * This file defines the base path for the application, ensuring consistent
 * path resolution across local and VPS environments.
 */

// VPS Base Path - Update this for production deployment
define('VPS_BASE_PATH', '/www/wwwroot/72.62.65.224');

/**
 * Calculate base path without using ../ in path strings (for open_basedir compatibility)
 * Uses dirname() function calls instead of string concatenation with ../
 */
function calculateBasePath() {
    // First, try VPS path
    if (file_exists(VPS_BASE_PATH) && is_dir(VPS_BASE_PATH)) {
        return VPS_BASE_PATH;
    }
    
    // For local development, calculate from current file location
    // This file is in config/, so go up one level
    $configDir = __DIR__;
    $basePath = dirname($configDir);
    
    // Verify the path exists
    if (file_exists($basePath) && is_dir($basePath)) {
        return $basePath;
    }
    
    // Fallback to document root
    return $_SERVER['DOCUMENT_ROOT'] ?? $basePath;
}

// Detect if we're running on VPS or local
$detectedBasePath = calculateBasePath();
if ($detectedBasePath === VPS_BASE_PATH && file_exists(VPS_BASE_PATH) && is_dir(VPS_BASE_PATH)) {
    // Running on VPS
    define('BASE_PATH', VPS_BASE_PATH);
    define('IS_VPS', true);
} else {
    // Running locally
    define('BASE_PATH', $detectedBasePath);
    define('IS_VPS', false);
}

// Define subdirectory paths
define('BACKEND_PATH', BASE_PATH . '/backend');
// Note: Frontend files are now at root level, not in a frontend/ subdirectory
define('FRONTEND_PATH', BASE_PATH); // Frontend is now at root
define('ASSETS_PATH', BASE_PATH . '/assets');
define('CONFIG_PATH', BASE_PATH . '/config');
define('DATABASE_PATH', BASE_PATH . '/database');

// URL Base Path (for web-accessible URLs)
// This should be the web-accessible path, not the file system path
if (IS_VPS) {
    // On VPS, the base URL might be the root or a subdirectory
    // Adjust this based on your VPS web server configuration
    define('BASE_URL', '');
} else {
    // Local development - adjust based on your local setup
    // This assumes the project is in a subdirectory
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseUrl = str_replace(['/index.php', '/index.html'], '', dirname($scriptName));
    define('BASE_URL', $baseUrl === '/' ? '' : $baseUrl);
}

/**
 * Get the absolute file system path to a file/directory
 * 
 * @param string $relativePath Path relative to project root
 * @return string Absolute file system path
 */
function getAbsolutePath($relativePath = '') {
    $path = BASE_PATH;
    if ($relativePath) {
        $path .= '/' . ltrim($relativePath, '/');
    }
    return $path;
}

/**
 * Get the web-accessible URL path
 * 
 * @param string $relativePath Path relative to web root
 * @return string URL path
 */
function getUrlPath($relativePath = '') {
    $path = BASE_URL;
    if ($relativePath) {
        $path .= '/' . ltrim($relativePath, '/');
    }
    return $path === '' ? '/' : $path;
}

/**
 * Get asset URL (for CSS, JS, images)
 * 
 * @param string $assetPath Path to asset relative to assets directory
 * @return string Full URL path to asset
 */
if (!function_exists('getAssetUrl')) {
    function getAssetUrl($assetPath = '') {
        return getUrlPath('assets/' . ltrim($assetPath, '/'));
    }
}

/**
 * Get backend URL
 * 
 * @param string $path Path relative to backend directory
 * @return string Full URL path
 */
if (!function_exists('getBackendUrl')) {
    function getBackendUrl($path = '') {
        return getUrlPath('backend/' . ltrim($path, '/'));
    }
}

/**
 * Get frontend URL
 * 
 * @param string $path Path relative to root (frontend files are now at root level)
 * @return string Full URL path
 */
if (!function_exists('getFrontendUrl')) {
    function getFrontendUrl($path = '') {
        // Frontend files are now at root, so no 'frontend/' prefix needed
        return getUrlPath(ltrim($path, '/'));
    }
}

/**
 * Get API URL
 * 
 * @param string $path Path relative to backend/api directory
 * @return string Full URL path
 */
function getApiUrl($path = '') {
    return getUrlPath('backend/api/' . ltrim($path, '/'));
}

/**
 * Redirect to a URL using the base path
 * 
 * @param string $path Relative path or full URL
 * @param int $statusCode HTTP status code (default: 302)
 */
function redirectTo($path, $statusCode = 302) {
    // If it's already a full URL, use it as-is
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        header("Location: $path", true, $statusCode);
        exit();
    }
    
    // Otherwise, treat as relative path and prepend base URL
    $url = getUrlPath($path);
    header("Location: $url", true, $statusCode);
    exit();
}

/**
 * Include a file using absolute path from project root
 * 
 * @param string $relativePath Path relative to project root
 * @return mixed Result of include/require
 */
function includeFromRoot($relativePath) {
    $absolutePath = getAbsolutePath($relativePath);
    if (file_exists($absolutePath)) {
        return include $absolutePath;
    }
    throw new RuntimeException("File not found: $absolutePath");
}

/**
 * Require a file using absolute path from project root
 * 
 * @param string $relativePath Path relative to project root
 * @return mixed Result of require
 */
function requireFromRoot($relativePath) {
    $absolutePath = getAbsolutePath($relativePath);
    if (file_exists($absolutePath)) {
        return require $absolutePath;
    }
    throw new RuntimeException("File not found: $absolutePath");
}

/**
 * Require once a file using absolute path from project root
 * 
 * @param string $relativePath Path relative to project root
 * @return mixed Result of require_once
 */
function requireOnceFromRoot($relativePath) {
    $absolutePath = getAbsolutePath($relativePath);
    if (file_exists($absolutePath)) {
        return require_once $absolutePath;
    }
    throw new RuntimeException("File not found: $absolutePath");
}

