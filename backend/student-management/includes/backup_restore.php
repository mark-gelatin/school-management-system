<?php
/**
 * Database Backup and Restore Functions for Colegio de Amore
 * Updated for Linux/VPS deployment - paths and redirects updated for Linux compatibility
 */

if (!function_exists('createDatabaseBackup')) {
    /**
     * Create database backup
     */
    function createDatabaseBackup($pdo, $backupType = 'manual') {
        global $DB_HOST, $DB_USER, $DB_PASS, $PRIMARY_DB;
        
        require_once __DIR__ . '/../../config/database.php';
        
        $backupDir = __DIR__ . '/../../backups/';
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupName = 'backup_' . $PRIMARY_DB . '_' . date('Y-m-d_H-i-s') . '.sql';
        $backupPath = $backupDir . $backupName;
        
        // Use mysqldump if available
        $mysqldumpPath = 'mysqldump'; // Default path, adjust if needed
        
        // Try to find mysqldump
        // Updated for Linux/VPS deployment - Windows paths removed
        $possiblePaths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump'
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqldumpPath = $path;
                break;
            }
        }
        
        $command = sprintf(
            '%s --host=%s --user=%s %s %s > %s 2>&1',
            escapeshellarg($mysqldumpPath),
            escapeshellarg($DB_HOST),
            escapeshellarg($DB_USER),
            !empty($DB_PASS) ? '--password=' . escapeshellarg($DB_PASS) : '',
            escapeshellarg($PRIMARY_DB),
            escapeshellarg($backupPath)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($backupPath) || filesize($backupPath) < 100) {
            // Fallback: Manual backup using PHP
            return createManualBackup($pdo, $backupPath, $backupType);
        }
        
        $backupSize = filesize($backupPath);
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        // Save backup record
        try {
            $stmt = $pdo->prepare("
                INSERT INTO database_backups (backup_name, backup_path, backup_size, backup_type, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$backupName, $backupPath, $backupSize, $backupType, $userId]);
        } catch (PDOException $e) {
            error_log("Error saving backup record: " . $e->getMessage());
        }
        
        return [
            'success' => true,
            'backup_path' => $backupPath,
            'backup_name' => $backupName,
            'backup_size' => $backupSize,
            'message' => 'Backup created successfully'
        ];
    }
}

if (!function_exists('createManualBackup')) {
    /**
     * Create backup manually using PHP (fallback method)
     */
    function createManualBackup($pdo, $backupPath, $backupType) {
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = "-- Database Backup\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET AUTOCOMMIT = 0;\n";
        $output .= "START TRANSACTION;\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
            $output .= "-- Table structure for `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $output .= $row[1] . ";\n\n";
            
            $output .= "-- Dumping data for table `$table`\n";
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $output .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = $pdo->quote($value);
                        }
                    }
                    $values[] = "(" . implode(', ', $rowValues) . ")";
                }
                
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $output .= "COMMIT;\n";
        
        if (file_put_contents($backupPath, $output) === false) {
            return ['success' => false, 'message' => 'Failed to write backup file'];
        }
        
        $backupSize = filesize($backupPath);
        $backupName = basename($backupPath);
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        // Save backup record
        try {
            $stmt = $pdo->prepare("
                INSERT INTO database_backups (backup_name, backup_path, backup_size, backup_type, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$backupName, $backupPath, $backupSize, $backupType, $userId]);
        } catch (PDOException $e) {
            error_log("Error saving backup record: " . $e->getMessage());
        }
        
        return [
            'success' => true,
            'backup_path' => $backupPath,
            'backup_name' => $backupName,
            'backup_size' => $backupSize,
            'message' => 'Backup created successfully (manual method)'
        ];
    }
}

if (!function_exists('restoreDatabaseBackup')) {
    /**
     * Restore database from backup file
     */
    function restoreDatabaseBackup($pdo, $backupPath) {
        if (!file_exists($backupPath)) {
            return ['success' => false, 'message' => 'Backup file not found'];
        }
        
        global $DB_HOST, $DB_USER, $DB_PASS, $PRIMARY_DB;
        
        // Use mysql command if available
        // Updated for Linux/VPS deployment - Windows paths removed
        $mysqlPath = 'mysql';
        $possiblePaths = [
            'mysql',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql'
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqlPath = $path;
                break;
            }
        }
        
        $command = sprintf(
            '%s --host=%s --user=%s %s %s < %s 2>&1',
            escapeshellarg($mysqlPath),
            escapeshellarg($DB_HOST),
            escapeshellarg($DB_USER),
            !empty($DB_PASS) ? '--password=' . escapeshellarg($DB_PASS) : '',
            escapeshellarg($PRIMARY_DB),
            escapeshellarg($backupPath)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            // Fallback: Manual restore using PHP
            return restoreManualBackup($pdo, $backupPath);
        }
        
        return ['success' => true, 'message' => 'Database restored successfully'];
    }
}

if (!function_exists('restoreManualBackup')) {
    /**
     * Restore backup manually using PHP (fallback method)
     */
    function restoreManualBackup($pdo, $backupPath) {
        $sql = file_get_contents($backupPath);
        
        if ($sql === false) {
            return ['success' => false, 'message' => 'Cannot read backup file'];
        }
        
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec($sql);
            return ['success' => true, 'message' => 'Database restored successfully (manual method)'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('getBackupList')) {
    /**
     * Get list of available backups
     */
    function getBackupList($pdo, $limit = 50) {
        try {
            $stmt = $pdo->prepare("
                SELECT b.*, u.first_name, u.last_name
                FROM database_backups b
                LEFT JOIN users u ON b.created_by = u.id
                ORDER BY b.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting backup list: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('deleteBackup')) {
    /**
     * Delete backup file and record
     */
    function deleteBackup($pdo, $backupId) {
        try {
            $stmt = $pdo->prepare("SELECT backup_path FROM database_backups WHERE id = ?");
            $stmt->execute([$backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($backup && file_exists($backup['backup_path'])) {
                unlink($backup['backup_path']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM database_backups WHERE id = ?");
            $stmt->execute([$backupId]);
            
            return ['success' => true, 'message' => 'Backup deleted successfully'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error deleting backup: ' . $e->getMessage()];
        }
    }
}

