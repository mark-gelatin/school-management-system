<?php
// Teacher Archived Classes Page
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
$message = '';
$message_type = '';

// Get teacher information
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving teacher information: ' . $e->getMessage();
    $message_type = 'error';
}

// Get archived courses for this teacher
$archivedCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT ac.*,
               s.id as subject_id, s.name as subject_name, s.code as subject_code, s.description as subject_description,
               s.units, s.program, s.year_level,
               c.name as course_name, c.code as course_code,
               sec.section_name, sec.year_level as section_year_level
        FROM archived_courses ac
        INNER JOIN subjects s ON ac.subject_id = s.id
        LEFT JOIN courses c ON ac.course_id = c.id
        LEFT JOIN sections sec ON ac.section_id = sec.id
        WHERE ac.teacher_id = ?
        ORDER BY ac.archived_at DESC
    ");
    $stmt->execute([$teacherId]);
    $archivedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving archived courses: ' . $e->getMessage();
    $message_type = 'error';
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
    <title>Archived Classes - Colegio de Amore</title>
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
        .header p {
            color: #666;
            margin-top: 5px;
            font-size: 0.95rem;
        }
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .subject-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #6c757d;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .archived-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #6c757d;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .subject-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .subject-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: #6c757d;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .subject-title {
            flex: 1;
        }
        .subject-name {
            font-weight: 700;
            color: #333;
            margin-bottom: 3px;
        }
        .subject-code {
            font-size: 0.85rem;
            color: #999;
        }
        .subject-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        .subject-info {
            display: flex;
            justify-content: space-between;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            margin-bottom: 15px;
        }
        .info-item {
            text-align: center;
        }
        .info-label {
            font-size: 0.75rem;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }
        .archived-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 0.85rem;
            color: #666;
        }
        .archived-details strong {
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
            border-color: #6c757d;
            box-shadow: 0 0 0 3px rgba(108, 117, 125, 0.1);
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
            border-color: #6c757d;
            box-shadow: 0 0 0 3px rgba(108, 117, 125, 0.1);
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
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .subjects-grid {
                grid-template-columns: 1fr;
            }
            
            .search-filter-container {
                flex-direction: column;
            }
            
            .search-box,
            .filter-select {
                width: 100%;
            }
            
            .subject-info {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .info-item {
                flex: 1;
                min-width: calc(50% - 5px);
            }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'archived';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-archive"></i> Archived Classes</h1>
            <p>View your previously completed and archived courses</p>
        </div>
        
        <?php if (!empty($archivedCourses)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="courseSearch" placeholder="Search archived courses by name or code..." onkeyup="filterCourses()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="academicYearFilter" onchange="filterCourses()">
                    <option value="">All Academic Years</option>
                    <?php 
                    $academicYears = [];
                    foreach ($archivedCourses as $course) {
                        if (!empty($course['academic_year'])) {
                            $academicYears[$course['academic_year']] = $course['academic_year'];
                        }
                    }
                    foreach ($academicYears as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="semesterFilter" onchange="filterCourses()">
                    <option value="">All Semesters</option>
                    <option value="1st">1st Semester</option>
                    <option value="2nd">2nd Semester</option>
                    <option value="Summer">Summer</option>
                </select>
            </div>
            
            <div class="subjects-grid" id="coursesGrid">
                <?php foreach ($archivedCourses as $course): ?>
                    <?php $initial = strtoupper(substr($course['subject_name'], 0, 1)); ?>
                    <div class="subject-card" 
                         data-course-name="<?= strtolower(htmlspecialchars($course['subject_name'])) ?>"
                         data-course-code="<?= strtolower(htmlspecialchars($course['subject_code'])) ?>"
                         data-academic-year="<?= strtolower(htmlspecialchars($course['academic_year'] ?? '')) ?>"
                         data-semester="<?= strtolower(htmlspecialchars($course['semester'] ?? '')) ?>">
                        <span class="archived-badge">
                            <i class="fas fa-archive"></i> Archived
                        </span>
                        <div class="subject-header">
                            <div class="subject-icon"><?= $initial ?></div>
                            <div class="subject-title">
                                <div class="subject-name"><?= htmlspecialchars($course['subject_name']) ?></div>
                                <div class="subject-code"><?= htmlspecialchars($course['subject_code']) ?></div>
                            </div>
                        </div>
                        <?php if ($course['subject_description']): ?>
                            <div class="subject-description"><?= htmlspecialchars($course['subject_description']) ?></div>
                        <?php endif; ?>
                        <div class="subject-info">
                            <div class="info-item">
                                <div class="info-label">Units</div>
                                <div class="info-value"><?= htmlspecialchars($course['units'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Program</div>
                                <div class="info-value" style="font-size: 0.9rem;"><?= htmlspecialchars($course['program'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Year Level</div>
                                <div class="info-value" style="font-size: 0.9rem;"><?= htmlspecialchars($course['year_level'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="archived-details">
                            <div><strong>Academic Year:</strong> <?= htmlspecialchars($course['academic_year']) ?></div>
                            <div><strong>Semester:</strong> <?= htmlspecialchars(ucfirst($course['semester'])) ?></div>
                            <div><strong>Archived:</strong> <?= date('M d, Y', strtotime($course['archived_at'])) ?></div>
                            <?php if ($course['all_grades_approved']): ?>
                                <div style="color: #28a745; margin-top: 5px;">
                                    <i class="fas fa-check-circle"></i> All grades approved
                                </div>
                            <?php endif; ?>
                            <?php if ($course['total_students'] > 0): ?>
                                <div style="margin-top: 5px;">
                                    <strong>Students:</strong> <?= $course['approved_students'] ?>/<?= $course['total_students'] ?> approved
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="noCourseResults" class="no-results" style="display: none;">
                <i class="fas fa-search"></i>
                <p>No archived courses found matching your search</p>
            </div>
        <?php else: ?>
            <div class="subject-card">
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived classes yet. Courses will appear here after all grades are approved and the course is archived.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function filterCourses() {
            const searchTerm = document.getElementById('courseSearch').value.toLowerCase();
            const academicYearFilter = document.getElementById('academicYearFilter').value.toLowerCase();
            const semesterFilter = document.getElementById('semesterFilter').value.toLowerCase();
            const courseCards = document.querySelectorAll('#coursesGrid .subject-card');
            const noResults = document.getElementById('noCourseResults');
            let visibleCount = 0;
            
            courseCards.forEach(card => {
                const courseName = card.getAttribute('data-course-name') || '';
                const courseCode = card.getAttribute('data-course-code') || '';
                const academicYear = card.getAttribute('data-academic-year') || '';
                const semester = card.getAttribute('data-semester') || '';
                
                const matchesSearch = !searchTerm || courseName.includes(searchTerm) || courseCode.includes(searchTerm);
                const matchesAcademicYear = !academicYearFilter || academicYear === academicYearFilter;
                const matchesSemester = !semesterFilter || semester === semesterFilter;
                
                if (matchesSearch && matchesAcademicYear && matchesSemester) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || academicYearFilter || semesterFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
</body>
</html>




