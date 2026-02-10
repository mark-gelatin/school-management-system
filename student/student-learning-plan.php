<?php
// Student Learning Plan Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // student/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/student
    $projectRoot = dirname($currentDir); // /www/wwwroot/72.62.65.224
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    
    // Use realpath to resolve any symbolic links and get absolute path
    $realPathsFile = realpath($pathsFile);
    if ($realPathsFile && file_exists($realPathsFile)) {
        require_once $realPathsFile;
    } else {
        // Fallback to VPS path (absolute path)
        $vpsPathsFile = '/www/wwwroot/72.62.65.224/config/paths.php';
        if (file_exists($vpsPathsFile)) {
            require_once $vpsPathsFile;
        }
    }
}
require_once getAbsolutePath('config/database.php');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirectTo('auth/student-login.php');
}

$studentId = $_SESSION['user_id'];

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving student information: ' . $e->getMessage();
}

// Get all enrolled subjects
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.*, c.name as classroom_name,
               AVG(g.grade) as avg_grade
        FROM subjects s
        JOIN grades g ON s.id = g.subject_id
        LEFT JOIN classrooms c ON g.classroom_id = c.id
        WHERE g.student_id = ?
        GROUP BY s.id, s.name, s.code, s.description, s.units, c.name
        ORDER BY s.name
    ");
    $stmt->execute([$studentId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving subjects: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Plan - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        .back-btn {
            background: #a11c27;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .back-btn:hover { background: #b31310; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .plan-item {
            padding: 20px;
            border-left: 4px solid #a11c27;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .plan-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .plan-subject {
            font-weight: 700;
            color: #333;
            font-size: 1.1rem;
        }
        .plan-code {
            color: #666;
            font-size: 0.9rem;
        }
        .plan-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .plan-detail {
            text-align: center;
        }
        .plan-detail-label {
            font-size: 0.75rem;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .plan-detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-file-alt"></i> Learning Plan</h1>
            <a href="student-dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <div class="card">
            <?php if (!empty($subjects)): ?>
                <?php foreach ($subjects as $subject): ?>
                    <div class="plan-item">
                        <div class="plan-item-header">
                            <div>
                                <div class="plan-subject"><?= htmlspecialchars($subject['name']) ?></div>
                                <div class="plan-code"><?= htmlspecialchars($subject['code']) ?></div>
                            </div>
                        </div>
                        <?php if ($subject['description']): ?>
                            <p style="color: #666; margin-bottom: 15px;"><?= htmlspecialchars($subject['description']) ?></p>
                        <?php endif; ?>
                        <div class="plan-details">
                            <div class="plan-detail">
                                <div class="plan-detail-label">Units</div>
                                <div class="plan-detail-value"><?= htmlspecialchars($subject['units'] ?? 'N/A') ?></div>
                            </div>
                            <div class="plan-detail">
                                <div class="plan-detail-label">Classroom</div>
                                <div class="plan-detail-value" style="font-size: 0.9rem;"><?= htmlspecialchars($subject['classroom_name'] ?? 'N/A') ?></div>
                            </div>
                            <div class="plan-detail">
                                <div class="plan-detail-label">Average Grade</div>
                                <div class="plan-detail-value"><?= $subject['avg_grade'] ? number_format($subject['avg_grade'], 2) : 'N/A' ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <p>No learning plan available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



