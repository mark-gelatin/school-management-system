<?php
/**
 * Real-time Student Grades Synchronization API
 * Returns latest grades for a student to ensure data consistency
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
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
require_once getAbsolutePath('config/database.php');
require_once getAbsolutePath('backend/includes/data_synchronization.php');

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$studentId = $_SESSION['user_id'];
$lastSync = $_GET['last_sync'] ?? null; // Optional: timestamp of last sync

try {
    // Get real-time student data
    $data = getStudentRealTimeData($pdo, $studentId);
    
    if ($data['success']) {
        // Filter grades if last_sync is provided (only return new/updated grades)
        if ($lastSync) {
            $lastSyncTime = strtotime($lastSync);
            $filteredGrades = array_filter($data['grades'], function($grade) use ($lastSyncTime) {
                $gradedAt = strtotime($grade['graded_at']);
                $updatedAt = isset($grade['updated_at']) ? strtotime($grade['updated_at']) : $gradedAt;
                return $gradedAt > $lastSyncTime || $updatedAt > $lastSyncTime;
            });
            $data['grades'] = array_values($filteredGrades);
        }
        
        echo json_encode($data);
    } else {
        http_response_code(404);
        echo json_encode($data);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}



