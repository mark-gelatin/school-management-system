<?php
// Student Completed Subjects Page
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

// Get completed subjects (subjects with final grade >= passing grade, typically 75)
$completedSubjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name as subject_name, s.code as subject_code,
               s.description, s.units,
               c.name as classroom_name,
               AVG(g.grade) as final_grade,
               MAX(g.graded_at) as completed_date
        FROM subjects s
        JOIN grades g ON s.id = g.subject_id
        LEFT JOIN classrooms c ON g.classroom_id = c.id
        WHERE g.student_id = ?
        GROUP BY s.id, s.name, s.code, s.description, s.units, c.name
        HAVING AVG(g.grade) >= 75
        ORDER BY completed_date DESC
    ");
    $stmt->execute([$studentId]);
    $completedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving completed subjects: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Courses - Colegio de Amore</title>
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
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .course-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #28a745;
        }
        .course-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .course-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: #28a745;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .course-title {
            flex: 1;
        }
        .course-name {
            font-weight: 700;
            color: #333;
            margin-bottom: 3px;
        }
        .course-code {
            font-size: 0.85rem;
            color: #999;
        }
        .course-grade {
            text-align: center;
            padding: 15px;
            background: #d4edda;
            border-radius: 8px;
            margin-top: 15px;
        }
        .grade-label {
            font-size: 0.75rem;
            color: #155724;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .grade-value {
            font-size: 2rem;
            font-weight: 700;
            color: #155724;
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
            <h1><i class="fas fa-check-circle"></i> Completed Courses</h1>
            <a href="student-dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <?php if (!empty($completedSubjects)): ?>
            <div class="courses-grid">
                <?php foreach ($completedSubjects as $subject): ?>
                    <?php $initial = strtoupper(substr($subject['subject_name'], 0, 1)); ?>
                    <div class="course-card">
                        <div class="course-header">
                            <div class="course-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="course-title">
                                <div class="course-name"><?= htmlspecialchars($subject['subject_name']) ?></div>
                                <div class="course-code"><?= htmlspecialchars($subject['subject_code']) ?></div>
                            </div>
                        </div>
                        <div class="course-grade">
                            <div class="grade-label">Final Grade</div>
                            <div class="grade-value"><?= number_format($subject['final_grade'], 2) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="course-card">
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>No completed courses yet</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>



