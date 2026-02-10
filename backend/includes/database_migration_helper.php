<?php
/**
 * Database Migration Helper
 * Provides functions to safely add columns and handle schema migrations
 */

require_once __DIR__ . '/database.php';

/**
 * Add rejection_reason column to admission_applications table if it doesn't exist
 * 
 * @param PDO $pdo Database connection
 * @return array Result with success status and message
 */
function addRejectionReasonColumn($pdo) {
    try {
        // Check if column already exists
        $checkStmt = $pdo->query("
            SELECT COUNT(*) as col_count 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'admission_applications' 
            AND COLUMN_NAME = 'rejection_reason'
        ");
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['col_count'] > 0) {
            return [
                'success' => true,
                'message' => 'Column rejection_reason already exists',
                'column_exists' => true
            ];
        }
        
        // Column doesn't exist, add it
        $pdo->exec("
            ALTER TABLE admission_applications 
            ADD COLUMN rejection_reason TEXT NULL AFTER notes
        ");
        
        return [
            'success' => true,
            'message' => 'Column rejection_reason added successfully',
            'column_exists' => true
        ];
    } catch (PDOException $e) {
        error_log("Error adding rejection_reason column: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error adding column: ' . $e->getMessage(),
            'column_exists' => false
        ];
    }
}

/**
 * Ensure rejection_reason column exists (called automatically on rejection)
 * 
 * @param PDO $pdo Database connection
 * @return bool True if column exists or was created successfully
 */
function ensureRejectionReasonColumn($pdo) {
    $result = addRejectionReasonColumn($pdo);
    return $result['success'] && $result['column_exists'];
}



