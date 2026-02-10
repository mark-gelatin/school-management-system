<?php
/**
 * Setup script for grade_edit_requests and archived_courses tables
 * Run this once to create the required tables
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Grade Edit Requests Tables</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #a11c27;
            margin-bottom: 20px;
        }
        .success {
            color: #28a745;
            padding: 10px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            color: #dc3545;
            padding: 10px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .info {
            color: #0c5460;
            padding: 10px;
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 4px;
            margin: 10px 0;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Setup Grade Edit Requests Tables</h1>
        
        <?php
        $errors = [];
        $success = [];
        
        try {
            // Create archived_courses table
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `archived_courses` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `teacher_id` int(11) NOT NULL,
                      `subject_id` int(11) NOT NULL,
                      `course_id` int(11) DEFAULT NULL,
                      `section_id` int(11) DEFAULT NULL,
                      `academic_year` varchar(20) NOT NULL,
                      `semester` enum('1st','2nd','Summer') NOT NULL,
                      `archived_by` int(11) DEFAULT NULL,
                      `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      `all_grades_approved` tinyint(1) DEFAULT 0,
                      `total_students` int(11) DEFAULT 0,
                      `approved_students` int(11) DEFAULT 0,
                      `notes` text DEFAULT NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `unique_teacher_subject_period` (`teacher_id`, `subject_id`, `academic_year`, `semester`),
                      KEY `teacher_id` (`teacher_id`),
                      KEY `subject_id` (`subject_id`),
                      KEY `course_id` (`course_id`),
                      KEY `section_id` (`section_id`),
                      KEY `archived_by` (`archived_by`),
                      KEY `idx_academic_year` (`academic_year`, `semester`),
                      CONSTRAINT `archived_courses_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `archived_courses_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `archived_courses_ibfk_3` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
                      CONSTRAINT `archived_courses_ibfk_4` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
                      CONSTRAINT `archived_courses_ibfk_5` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
                $success[] = "Table 'archived_courses' created successfully";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    $errors[] = "Error creating archived_courses: " . $e->getMessage();
                } else {
                    $success[] = "Table 'archived_courses' already exists";
                }
            }
            
            // Create grade_edit_requests table
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `grade_edit_requests` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `teacher_id` int(11) NOT NULL,
                      `grade_id` int(11) NOT NULL,
                      `subject_id` int(11) NOT NULL,
                      `course_id` int(11) DEFAULT NULL,
                      `academic_year` varchar(20) DEFAULT NULL,
                      `semester` enum('1st','2nd','Summer') DEFAULT NULL,
                      `request_reason` text NOT NULL,
                      `status` enum('pending','approved','denied','completed') DEFAULT 'pending',
                      `reviewed_by` int(11) DEFAULT NULL,
                      `reviewed_at` timestamp NULL DEFAULT NULL,
                      `review_notes` text DEFAULT NULL,
                      `edit_completed` tinyint(1) DEFAULT 0,
                      `edit_completed_at` timestamp NULL DEFAULT NULL,
                      `re_approved_by` int(11) DEFAULT NULL,
                      `re_approved_at` timestamp NULL DEFAULT NULL,
                      `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `teacher_id` (`teacher_id`),
                      KEY `grade_id` (`grade_id`),
                      KEY `subject_id` (`subject_id`),
                      KEY `course_id` (`course_id`),
                      KEY `reviewed_by` (`reviewed_by`),
                      KEY `re_approved_by` (`re_approved_by`),
                      KEY `idx_status` (`status`),
                      KEY `idx_academic_year` (`academic_year`, `semester`),
                      CONSTRAINT `grade_edit_requests_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `grade_edit_requests_ibfk_2` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `grade_edit_requests_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `grade_edit_requests_ibfk_4` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
                      CONSTRAINT `grade_edit_requests_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                      CONSTRAINT `grade_edit_requests_ibfk_6` FOREIGN KEY (`re_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
                $success[] = "Table 'grade_edit_requests' created successfully";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    $errors[] = "Error creating grade_edit_requests: " . $e->getMessage();
                } else {
                    $success[] = "Table 'grade_edit_requests' already exists";
                }
            }
            
            // Add columns to grades table if they don't exist
            $columnsToAdd = [
                'approval_status' => "enum('pending','submitted','approved','rejected','locked') DEFAULT 'pending'",
                'is_locked' => "tinyint(1) DEFAULT 0",
                'academic_year' => "varchar(20) DEFAULT NULL",
                'semester' => "enum('1st','2nd','Summer') DEFAULT NULL",
                'locked_at' => "timestamp NULL DEFAULT NULL",
                'approved_by' => "int(11) DEFAULT NULL",
                'approved_at' => "timestamp NULL DEFAULT NULL",
                'rejected_at' => "timestamp NULL DEFAULT NULL",
                'rejection_reason' => "text DEFAULT NULL",
                'submitted_at' => "timestamp NULL DEFAULT NULL",
                'edit_request_id' => "int(11) DEFAULT NULL"
            ];
            
            // Check if grades table exists first
            $gradesTableCheck = $pdo->query("SHOW TABLES LIKE 'grades'");
            if ($gradesTableCheck->rowCount() === 0) {
                $errors[] = "The 'grades' table does not exist. Please create it first.";
            } else {
            
                foreach ($columnsToAdd as $columnName => $columnDef) {
                    try {
                        $checkStmt = $pdo->query("
                            SELECT COUNT(*) as count 
                            FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'grades' 
                            AND COLUMN_NAME = '$columnName'
                        ");
                        $exists = $checkStmt->fetch()['count'] > 0;
                        
                        if (!$exists) {
                            $pdo->exec("ALTER TABLE `grades` ADD COLUMN `$columnName` $columnDef");
                            $success[] = "Column '$columnName' added to 'grades' table";
                        } else {
                            $success[] = "Column '$columnName' already exists in 'grades' table";
                        }
                    } catch (PDOException $e) {
                        // Check if error is about duplicate column
                        if (strpos($e->getMessage(), 'Duplicate column') !== false || 
                            strpos($e->getMessage(), 'already exists') !== false) {
                            $success[] = "Column '$columnName' already exists (skipped)";
                        } else {
                            $errors[] = "Error adding column '$columnName': " . $e->getMessage();
                        }
                    }
                }
            }
            
            // Add foreign key for edit_request_id if column exists and grade_edit_requests table exists
            $editRequestsTableExists = false;
            try {
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'grade_edit_requests'");
                $editRequestsTableExists = $tableCheck->rowCount() > 0;
            } catch (PDOException $e) {
                // Table doesn't exist
            }
            
            if ($editRequestsTableExists) {
                try {
                    // Check if edit_request_id column exists
                    $colCheck = $pdo->query("
                        SELECT COUNT(*) as count 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'grades' 
                        AND COLUMN_NAME = 'edit_request_id'
                    ");
                    $colExists = $colCheck->fetch()['count'] > 0;
                    
                    if ($colExists) {
                        $fkCheck = $pdo->query("
                            SELECT COUNT(*) as count 
                            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'grades' 
                            AND CONSTRAINT_NAME = 'grades_ibfk_edit_request'
                        ");
                        if ($fkCheck->fetch()['count'] == 0) {
                            // First add the index if it doesn't exist
                            try {
                                $idxCheck = $pdo->query("
                                    SELECT COUNT(*) as count 
                                    FROM INFORMATION_SCHEMA.STATISTICS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'grades' 
                                    AND INDEX_NAME = 'edit_request_id'
                                ");
                                if ($idxCheck->fetch()['count'] == 0) {
                                    $pdo->exec("ALTER TABLE `grades` ADD KEY `edit_request_id` (`edit_request_id`)");
                                }
                            } catch (PDOException $e) {
                                // Index might already exist
                            }
                            
                            // Then add the foreign key constraint
                            $pdo->exec("
                                ALTER TABLE `grades` 
                                ADD CONSTRAINT `grades_ibfk_edit_request` 
                                FOREIGN KEY (`edit_request_id`) REFERENCES `grade_edit_requests` (`id`) ON DELETE SET NULL
                            ");
                            $success[] = "Foreign key constraint added for edit_request_id";
                        } else {
                            $success[] = "Foreign key constraint for edit_request_id already exists";
                        }
                    }
                } catch (PDOException $e) {
                    // Ignore if constraint already exists or column doesn't exist yet
                    if (strpos($e->getMessage(), 'Duplicate') === false && 
                        strpos($e->getMessage(), 'Cannot add foreign key') === false) {
                        $errors[] = "Error adding foreign key: " . $e->getMessage();
                    }
                }
            }
            
            // Add indexes if they don't exist
            $indexes = [
                'idx_approval_status' => 'approval_status',
                'idx_is_locked' => 'is_locked'
            ];
            
            foreach ($indexes as $indexName => $columnName) {
                try {
                    $idxCheck = $pdo->query("
                        SELECT COUNT(*) as count 
                        FROM INFORMATION_SCHEMA.STATISTICS 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'grades' 
                        AND INDEX_NAME = '$indexName'
                    ");
                    if ($idxCheck->fetch()['count'] == 0) {
                        $pdo->exec("ALTER TABLE `grades` ADD INDEX `$indexName` (`$columnName`)");
                        $success[] = "Index '$indexName' added to 'grades' table";
                    }
                } catch (PDOException $e) {
                    // Ignore duplicate index errors
                }
            }
            
            // Verify tables exist
            $tablesToCheck = ['grade_edit_requests', 'archived_courses'];
            foreach ($tablesToCheck as $table) {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    $success[] = "✓ Verified: Table '$table' exists";
                } else {
                    $errors[] = "✗ Table '$table' was not created";
                }
            }
            
        } catch (Exception $e) {
            $errors[] = "Fatal error: " . $e->getMessage();
        }
        ?>
        
        <?php if (!empty($success)): ?>
            <div class="success">
                <strong>Success:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <?php foreach ($success as $msg): ?>
                        <li><?= htmlspecialchars($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <strong>Errors:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <?php foreach ($errors as $msg): ?>
                        <li><?= htmlspecialchars($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (empty($errors)): ?>
            <div class="info">
                <strong>Setup Complete!</strong><br>
                The grade_edit_requests and archived_courses tables have been created successfully.
                You can now use the grade edit request functionality.
            </div>
            <p><a href="teacher-grades.php">← Back to Grades Page</a></p>
        <?php else: ?>
            <div class="error">
                <strong>Setup Incomplete</strong><br>
                Please review the errors above and try again.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>




