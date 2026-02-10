<?php
// Enhanced Database Setup Script
// ⚠️ DEPRECATED: This script is obsolete and references archived database files.
// Use 'setup-database.php' instead, which uses the unified amore_unified_complete.sql file.
// This file is kept for backward compatibility only.

session_start();

$host = 'localhost';
$db_username = 'root';
$db_password = '';

$message = '';
$message_type = '';
$dbExists = false;
$enhancedExists = false;

try {
    // Connect to MySQL server (without selecting a database)
    $pdo = new PDO("mysql:host=$host", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if databases exist
    $stmt = $pdo->prepare("SHOW DATABASES LIKE 'student_grade_management'");
    $stmt->execute();
    $dbExists = $stmt->rowCount() > 0;

    $stmt = $pdo->prepare("SHOW DATABASES LIKE 'student_grade_management_enhanced'");
    $stmt->execute();
    $enhancedExists = $stmt->rowCount() > 0;
    
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'create_enhanced') {
            // Create enhanced database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS student_grade_management_enhanced");
            $pdo->exec("USE student_grade_management_enhanced");
            
            // Read and execute the enhanced SQL file
            $sql = file_get_contents(__DIR__ . '/../database/enhanced_student_grade_management.sql');
            $pdo->exec($sql);
            
            $message = 'Enhanced database created successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'migrate_data') {
            if (!$dbExists) {
                $message = 'Original database does not exist. Please create it first.';
                $message_type = 'error';
            } else {
                // Connect to original database
                $pdo_original = new PDO("mysql:host=$host;dbname=student_grade_management", $db_username, $db_password);
                $pdo_original->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Connect to enhanced database
                $pdo_enhanced = new PDO("mysql:host=$host;dbname=student_grade_management_enhanced", $db_username, $db_password);
                $pdo_enhanced->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Migrate users data
                $stmt = $pdo_original->query("SELECT * FROM users");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($users as $user) {
                    $stmt = $pdo_enhanced->prepare("
                        INSERT INTO users (id, username, password, email, role, first_name, last_name, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        username = VALUES(username), 
                        password = VALUES(password), 
                        email = VALUES(email), 
                        role = VALUES(role), 
                        first_name = VALUES(first_name), 
                        last_name = VALUES(last_name)
                    ");
                    $stmt->execute([
                        $user['id'], $user['username'], $user['password'], $user['email'], 
                        $user['role'], $user['first_name'], $user['last_name'], $user['created_at']
                    ]);
                }
                
                // Migrate other tables if they exist
                $tables = ['classrooms', 'classroom_students', 'subjects', 'grades'];
                foreach ($tables as $table) {
                    try {
                        $stmt = $pdo_original->query("SELECT * FROM $table");
                        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($data)) {
                            // Get column names
                            $columns = array_keys($data[0]);
                            $placeholders = str_repeat('?,', count($columns) - 1) . '?';
                            $columnList = implode(', ', $columns);
                            
                            $stmt = $pdo_enhanced->prepare("INSERT INTO $table ($columnList) VALUES ($placeholders)");
                            
                            foreach ($data as $row) {
                                $stmt->execute(array_values($row));
                            }
                        }
                    } catch (PDOException $e) {
                        // Table might not exist, continue
                        continue;
                    }
                }
                
                $message = 'Data migrated successfully to enhanced database!';
                $message_type = 'success';
            }
            
        } elseif ($action === 'backup_original') {
            if (!$dbExists) {
                $message = 'Original database does not exist.';
                $message_type = 'error';
            } else {
                // Create backup
                $backup_name = 'student_grade_management_backup_' . date('Y-m-d_H-i-s');
                $pdo->exec("CREATE DATABASE $backup_name");
                $pdo->exec("USE $backup_name");
                
                // Read and execute the original SQL file
                $sql = file_get_contents(__DIR__ . '/../database/student_grade_management.sql');
                $pdo->exec($sql);
                
                $message = "Backup created successfully as '$backup_name'!";
                $message_type = 'success';
            }
        }
    }
    
} catch (PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Database Setup - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 800px;
            width: 100%;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #333;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 1.1rem;
        }
        
        .status-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .status-item:last-child {
            border-bottom: none;
        }
        
        .status-label {
            font-weight: 600;
            color: #333;
        }
        
        .status-value {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-exists {
            background: #d4edda;
            color: #155724;
        }
        
        .status-not-exists {
            background: #f8d7da;
            color: #721c24;
        }
        
        .actions {
            display: grid;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            border-left: 5px solid #007bff;
        }
        
        .action-card h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.3rem;
        }
        
        .action-card p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .warning h4 {
            margin-bottom: 10px;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Enhanced Database Setup</h1>
            <p>Colegio de Amore - Student Management System</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <div class="status-box">
            <h3 style="margin-bottom: 20px; color: #333;">Database Status</h3>
            <div class="status-item">
                <span class="status-label">Original Database (student_grade_management)</span>
                <span class="status-value <?= $dbExists ? 'status-exists' : 'status-not-exists' ?>">
                    <?= $dbExists ? 'Exists' : 'Not Found' ?>
                </span>
            </div>
            <div class="status-item">
                <span class="status-label">Enhanced Database (student_grade_management_enhanced)</span>
                <span class="status-value <?= $enhancedExists ?? false ? 'status-exists' : 'status-not-exists' ?>">
                    <?= $enhancedExists ?? false ? 'Exists' : 'Not Found' ?>
                </span>
            </div>
        </div>
        
        <div class="warning">
            <h4>⚠️ Important Notes:</h4>
            <ul style="margin-left: 20px; margin-top: 10px;">
                <li>Make sure XAMPP MySQL service is running</li>
                <li>Always backup your data before making changes</li>
                <li>The enhanced database includes all admission portal fields</li>
                <li>Migration will preserve existing user data</li>
            </ul>
        </div>
        
        <div class="actions">
            <div class="action-card">
                <h3>1. Create Enhanced Database</h3>
                <p>Create the new enhanced database structure with all admission portal fields and improved grade management features.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="create_enhanced">
                    <button type="submit" class="btn btn-success">Create Enhanced Database</button>
                </form>
            </div>
            
            <div class="action-card">
                <h3>2. Backup Original Database</h3>
                <p>Create a backup of your current database before migration to ensure data safety.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="backup_original">
                    <button type="submit" class="btn" style="background: #ffc107; color: #000;">Create Backup</button>
                </form>
            </div>
            
            <div class="action-card">
                <h3>3. Migrate Data</h3>
                <p>Migrate existing data from the original database to the enhanced structure. This preserves all current users and grades.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="migrate_data">
                    <button type="submit" class="btn">Migrate Data</button>
                </form>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; 2025 Colegio de Amore. All rights reserved.</p>
            <p><a href="landing.html" style="color: #007bff; text-decoration: none;">← Back to Home</a></p>
        </div>
    </div>
</body>
</html>

