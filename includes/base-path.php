<?php
/**
 * Base Path Configuration for HTML/PHP Pages
 * Include this file at the top of PHP files that output HTML
 * to get access to base path functions for assets
 */

// Load path configuration if not already loaded - use open_basedir compatible method
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

// Set base URL for HTML base tag
$htmlBaseUrl = BASE_URL === '' ? '/' : BASE_URL;
if (substr($htmlBaseUrl, -1) !== '/') {
    $htmlBaseUrl .= '/';
}

// Function to output base tag
function outputBaseTag() {
    global $htmlBaseUrl;
    echo '<base href="' . htmlspecialchars($htmlBaseUrl) . '">';
}

// Helper function for PHP files to get asset URLs
function asset($path) {
    return getAssetUrl($path);
}

// Helper function for PHP files to get frontend URLs
// Note: Frontend files are now at root, so this just returns the path as-is
function frontend($path) {
    return getFrontendUrl($path);
}

// Helper function for PHP files to get backend URLs
function backend($path) {
    return getBackendUrl($path);
}

