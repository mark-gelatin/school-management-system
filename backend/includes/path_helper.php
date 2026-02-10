<?php
/**
 * Path Helper Functions
 * Provides helper functions for resolving file paths in the reorganized structure
 * 
 * This file now uses the centralized path configuration from config/paths.php
 */

// Load centralized path configuration - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    $currentDir = __DIR__;
    $parentDir = dirname($currentDir);
    $projectRoot = dirname($parentDir);
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

/**
 * Get the base path of the project
 * 
 * @return string Base path
 */
function getBasePath() {
    return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
}

/**
 * Get path to config directory
 * 
 * @return string Config path
 */
function getConfigPath() {
    return defined('CONFIG_PATH') ? CONFIG_PATH : getBasePath() . '/config';
}

/**
 * Get path to backend directory
 * 
 * @return string Backend path
 */
function getBackendPath() {
    return defined('BACKEND_PATH') ? BACKEND_PATH : getBasePath() . '/backend';
}

/**
 * Get path to frontend directory
 * Note: Frontend files are now at root level, not in a frontend/ subdirectory
 * 
 * @return string Frontend path (now returns base path)
 */
function getFrontendPath() {
    return defined('FRONTEND_PATH') ? FRONTEND_PATH : getBasePath();
}

/**
 * Get path to assets directory
 * 
 * @return string Assets path
 */
function getAssetsPath() {
    return defined('ASSETS_PATH') ? ASSETS_PATH : getBasePath() . '/assets';
}

// URL helper functions are now defined in config/paths.php
// These functions are kept for backward compatibility but delegate to the centralized functions









