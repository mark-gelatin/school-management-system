<?php
/**
 * Centralized Database Configuration
 * Colegio de Amore - Unified Database Connection
 * 
 * Updated for Linux/VPS deployment - paths and redirects updated for Linux compatibility
 * This file contains all database configuration settings.
 * Use environment variables or modify these values for your environment.
 */

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'amore_college');
define('DB_PASS', getenv('DB_PASS') ?: 'JFBNM4BLp88ZRZ4H');
define('DB_NAME', getenv('DB_NAME') ?: 'amore_college');

// Session configuration (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 28800); // 8 hours
    ini_set('session.cookie_path', '/');
    session_start();
}

/**
 * Get PDO Database Connection
 * 
 * @return PDO Returns a PDO instance
 * @throws PDOException if connection fails
 */
function getDatabaseConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            if (!defined('AMORE_DB_NAME')) {
                define('AMORE_DB_NAME', DB_NAME);
            }
        } catch (PDOException $e) {
            if (php_sapi_name() !== 'cli') {
                error_log('Database connection failed: ' . $e->getMessage());
                die('Database connection failed. Please ensure MySQL is running and the database exists.');
            }
            throw $e;
        }
    }
    
    return $pdo;
}

/**
 * Get MySQLi Database Connection (for legacy compatibility)
 * 
 * @return mysqli Returns a mysqli instance
 * @throws Exception if connection fails
 */
function getMysqliConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            error_log('MySQLi connection failed: ' . $conn->connect_error);
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        $conn->set_charset("utf8mb4");
    }
    
    return $conn;
}

// For backward compatibility - global $pdo variable
if (!isset($GLOBALS['pdo'])) {
    try {
        $GLOBALS['pdo'] = getDatabaseConnection();
    } catch (PDOException $e) {
        // Connection will be attempted when getDatabaseConnection() is called
    }
}

// Return PDO instance when included
return getDatabaseConnection();





