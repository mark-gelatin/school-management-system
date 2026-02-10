<?php
// Teacher Classrooms Page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    // teacher/ is now at root level, so go up one level to get project root
    $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/teacher
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

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    redirectTo('auth/staff-login.php');
}

$teacherId = $_SESSION['user_id'];

// Get teacher information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving teacher information: ' . $e->getMessage();
}

// Get teacher's classrooms
$classrooms = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM classrooms WHERE teacher_id = ? ORDER BY name");
    $stmt->execute([$teacherId]);
    $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get student count for each classroom
    foreach ($classrooms as &$classroom) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as student_count FROM classroom_students WHERE classroom_id = ?");
        $stmt->execute([$classroom['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $classroom['student_count'] = $result['student_count'] ?? 0;
    }
} catch (PDOException $e) {
    $message = 'Error retrieving classrooms: ' . $e->getMessage();
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    redirectTo('auth/staff-login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classrooms - Colegio de Amore</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <?php include __DIR__ . '/../includes/teacher-sidebar-styles.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
        }
        
        /* Legacy support - map container to main-content */
        .container {
            margin-left: 280px;
            flex: 1;
            padding: 30px;
            width: calc(100% - 280px);
            max-width: 100%;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .container.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        @media (max-width: 1024px) {
            .container {
                margin-left: 250px;
                width: calc(100% - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
                width: 100%;
                transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }
        }
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        .classrooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .classroom-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #a11c27;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .classroom-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .classroom-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .classroom-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        .classroom-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        .classroom-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 0.75rem;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 1rem;
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
        
        /* Search and Filter Styles */
        .search-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.2s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .filter-select {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 150px;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #a11c27;
            box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .no-results i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
            display: block;
        }
        
        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            .classrooms-grid {
                grid-template-columns: 1fr;
            }
            
            .search-filter-container {
                flex-direction: column;
            }
            
            .search-box,
            .filter-select {
                width: 100%;
            }
            
            .classroom-info {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .info-item {
                font-size: 0.9rem;
            }
            
            .info-value {
                font-size: 0.95rem;
            }
        }
        
        @media (max-width: 480px) {
            .classroom-info {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'classrooms';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chalkboard"></i> My Classrooms</h1>
        </div>
        
        <?php if (!empty($classrooms)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="classroomSearch" placeholder="Search classrooms by name..." onkeyup="filterClassrooms()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="programFilter" onchange="filterClassrooms()">
                    <option value="">All Programs</option>
                    <?php 
                    $programs = [];
                    foreach ($classrooms as $classroom) {
                        if (!empty($classroom['program'])) {
                            $programs[$classroom['program']] = $classroom['program'];
                        }
                    }
                    foreach ($programs as $program): ?>
                        <option value="<?= htmlspecialchars($program) ?>"><?= htmlspecialchars($program) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="yearLevelFilter" onchange="filterClassrooms()">
                    <option value="">All Year Levels</option>
                    <?php 
                    $yearLevels = [];
                    foreach ($classrooms as $classroom) {
                        if (!empty($classroom['year_level'])) {
                            $yearLevels[$classroom['year_level']] = $classroom['year_level'];
                        }
                    }
                    foreach ($yearLevels as $yearLevel): ?>
                        <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="semesterFilter" onchange="filterClassrooms()">
                    <option value="">All Semesters</option>
                    <?php 
                    $semesters = [];
                    foreach ($classrooms as $classroom) {
                        if (!empty($classroom['semester'])) {
                            $semesters[$classroom['semester']] = $classroom['semester'];
                        }
                    }
                    foreach ($semesters as $semester): ?>
                        <option value="<?= strtolower(htmlspecialchars($semester)) ?>"><?= htmlspecialchars($semester) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="academicYearFilter" onchange="filterClassrooms()">
                    <option value="">All Academic Years</option>
                    <?php 
                    $academicYears = [];
                    foreach ($classrooms as $classroom) {
                        if (!empty($classroom['academic_year'])) {
                            $academicYears[$classroom['academic_year']] = $classroom['academic_year'];
                        }
                    }
                    foreach ($academicYears as $academicYear): ?>
                        <option value="<?= htmlspecialchars($academicYear) ?>"><?= htmlspecialchars($academicYear) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="classrooms-grid" id="classroomsGrid">
                <?php foreach ($classrooms as $classroom): ?>
                    <div class="classroom-card" 
                         data-classroom-name="<?= strtolower(htmlspecialchars($classroom['name'])) ?>"
                         data-program="<?= strtolower(htmlspecialchars($classroom['program'] ?? '')) ?>"
                         data-year-level="<?= strtolower(htmlspecialchars($classroom['year_level'] ?? '')) ?>"
                         data-semester="<?= strtolower(htmlspecialchars($classroom['semester'] ?? '')) ?>"
                         data-academic-year="<?= htmlspecialchars($classroom['academic_year'] ?? '') ?>">
                        <div class="classroom-header">
                            <div>
                                <div class="classroom-name"><?= htmlspecialchars($classroom['name']) ?></div>
                            </div>
                        </div>
                        <?php if ($classroom['description']): ?>
                            <div class="classroom-description"><?= htmlspecialchars($classroom['description']) ?></div>
                        <?php endif; ?>
                        <div class="classroom-info">
                            <div class="info-item">
                                <div class="info-label">Program</div>
                                <div class="info-value"><?= htmlspecialchars($classroom['program'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Year Level</div>
                                <div class="info-value"><?= htmlspecialchars($classroom['year_level'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Section</div>
                                <div class="info-value"><?= htmlspecialchars($classroom['section'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Students</div>
                                <div class="info-value"><?= $classroom['student_count'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Academic Year</div>
                                <div class="info-value"><?= htmlspecialchars($classroom['academic_year'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Semester</div>
                                <div class="info-value"><?= htmlspecialchars($classroom['semester'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="noClassroomResults" class="no-results" style="display: none;">
                <i class="fas fa-search"></i>
                <p>No classrooms found matching your search</p>
            </div>
        <?php else: ?>
            <div class="classroom-card">
                <div class="empty-state">
                    <i class="fas fa-chalkboard"></i>
                    <p>No classrooms assigned</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
    
    <script>
        // Page-specific filtering function
        function filterClassrooms() {
            const searchTerm = document.getElementById('classroomSearch').value.toLowerCase();
            const programFilter = document.getElementById('programFilter').value.toLowerCase();
            const yearLevelFilter = document.getElementById('yearLevelFilter').value.toLowerCase();
            const semesterFilter = document.getElementById('semesterFilter').value.toLowerCase();
            const academicYearFilter = document.getElementById('academicYearFilter').value || '';
            const classroomCards = document.querySelectorAll('#classroomsGrid .classroom-card');
            const noResults = document.getElementById('noClassroomResults');
            let visibleCount = 0;
            
            classroomCards.forEach(card => {
                const classroomName = card.getAttribute('data-classroom-name') || '';
                const program = card.getAttribute('data-program') || '';
                const yearLevel = card.getAttribute('data-year-level') || '';
                const semester = card.getAttribute('data-semester') || '';
                const academicYear = card.getAttribute('data-academic-year') || '';
                
                const matchesSearch = !searchTerm || classroomName.includes(searchTerm);
                const matchesProgram = !programFilter || program === programFilter;
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                const matchesSemester = !semesterFilter || semester === semesterFilter;
                const matchesAcademicYear = !academicYearFilter || academicYear === academicYearFilter;
                
                if (matchesSearch && matchesProgram && matchesYearLevel && matchesSemester && matchesAcademicYear) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || programFilter || yearLevelFilter || semesterFilter || academicYearFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
</body>
</html>



