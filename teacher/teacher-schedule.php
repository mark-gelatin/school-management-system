<?php
// Teacher Schedule Page
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

// Get teacher's schedules from section_schedules table
$schedule = [];
try {
    // Check if section_schedules table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'section_schedules'");
    $tableExists = $tableCheck->rowCount() > 0;
    
    if ($tableExists) {
        // Query section_schedules for this teacher
        $stmt = $pdo->prepare("
            SELECT 
                ss.id,
                ss.day_of_week,
                ss.start_time,
                ss.end_time,
                ss.room,
                ss.academic_year,
                ss.semester,
                ss.status,
                sub.id as subject_id,
                sub.name as subject_name,
                sub.code as subject_code,
                c.name as classroom_name,
                c.id as classroom_id,
                sec.section_name,
                sec.year_level,
                crs.name as course_name,
                crs.code as course_code
            FROM section_schedules ss
            INNER JOIN subjects sub ON ss.subject_id = sub.id
            LEFT JOIN sections sec ON ss.section_id = sec.id
            LEFT JOIN courses crs ON sec.course_id = crs.id
            LEFT JOIN classrooms c ON ss.classroom_id = c.id
            WHERE ss.teacher_id = ? 
            AND ss.status = 'active'
            ORDER BY 
                FIELD(ss.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                ss.start_time,
                sub.name
        ");
        $stmt->execute([$teacherId]);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Error retrieving teacher schedule: ' . $e->getMessage());
    $message = 'Error retrieving schedule: ' . $e->getMessage();
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

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
    <title>Schedule - Colegio de Amore</title>
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
            margin-left: 300px;
            flex: 1;
            padding: 30px;
            width: calc(100% - 300px);
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
                margin-left: 280px;
                width: calc(100% - 280px);
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
        .schedule-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .schedule-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .schedule-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .schedule-item:hover {
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.15);
            border-color: #a11c27;
            transform: translateY(-2px);
        }
        
        .schedule-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .schedule-course-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: #a11c27;
            margin: 0;
            flex: 1;
            min-width: 200px;
        }
        
        .schedule-course-code {
            font-size: 0.9rem;
            color: #666;
            margin-top: 4px;
        }
        
        .schedule-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #333;
            font-weight: 500;
        }
        
        .schedule-badge i {
            color: #a11c27;
        }
        
        .schedule-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }
        
        .schedule-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .schedule-detail-item i {
            color: #a11c27;
            width: 18px;
            text-align: center;
        }
        
        .schedule-detail-label {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
            margin-right: 4px;
        }
        
        .schedule-detail-value {
            font-size: 0.95rem;
            color: #333;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            color: #a11c27;
        }
        
        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: #666;
        }
        
        .empty-state p {
            font-size: 0.95rem;
            color: #999;
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
            padding: 60px 20px;
            color: #999;
        }
        
        .no-results i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
            display: block;
            color: #a11c27;
        }
        
        .no-results p {
            font-size: 1rem;
            color: #666;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .schedule-item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .schedule-course-name {
                font-size: 1.1rem;
                min-width: 100%;
            }
            
            .schedule-details {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .schedule-detail-item {
                flex-wrap: wrap;
            }
            
            .search-filter-container {
                flex-direction: column;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .filter-select {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .schedule-card {
                padding: 15px;
            }
            
            .schedule-item {
                padding: 15px;
            }
            
            .schedule-course-name {
                font-size: 1rem;
            }
            
            .schedule-detail-label {
                display: block;
                margin-bottom: 4px;
            }
        }
        
    </style>
</head>
<body>
    <?php 
    $currentPage = 'schedule';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Schedule</h1>
        </div>
        
        <?php if (!empty($schedule)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="scheduleSearch" placeholder="Search by course name, code, or section..." onkeyup="filterSchedule()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="dayFilter" onchange="filterSchedule()">
                    <option value="">All Days</option>
                    <?php foreach ($days as $day): ?>
                        <option value="<?= strtolower($day) ?>"><?= $day ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <div class="schedule-card">
            <?php if (!empty($schedule)): ?>
                <div class="schedule-list" id="scheduleList">
                    <?php foreach ($schedule as $item): 
                        // Format time
                        $startTime = $item['start_time'] ? date('g:i A', strtotime($item['start_time'])) : 'N/A';
                        $endTime = $item['end_time'] ? date('g:i A', strtotime($item['end_time'])) : 'N/A';
                        $timeRange = $startTime . ' - ' . $endTime;
                        
                        // Get location (room or classroom name)
                        $location = !empty($item['room']) ? $item['room'] : (!empty($item['classroom_name']) ? $item['classroom_name'] : 'TBA');
                        
                        // Get section info
                        $sectionInfo = '';
                        if (!empty($item['section_name'])) {
                            $sectionInfo = $item['section_name'];
                            if (!empty($item['year_level'])) {
                                $sectionInfo .= ' - ' . $item['year_level'];
                            }
                        }
                        
                        // Course name (subject name or course name)
                        $courseName = !empty($item['subject_name']) ? $item['subject_name'] : (!empty($item['course_name']) ? $item['course_name'] : 'Course');
                        $courseCode = !empty($item['subject_code']) ? $item['subject_code'] : (!empty($item['course_code']) ? $item['course_code'] : '');
                    ?>
                        <div class="schedule-item" 
                             data-course-name="<?= strtolower(htmlspecialchars($courseName)) ?>"
                             data-course-code="<?= strtolower(htmlspecialchars($courseCode)) ?>"
                             data-day="<?= strtolower($item['day_of_week'] ?? '') ?>"
                             data-section="<?= strtolower(htmlspecialchars($sectionInfo)) ?>">
                            <div class="schedule-item-header">
                                <div>
                                    <h3 class="schedule-course-name"><?= htmlspecialchars($courseName) ?></h3>
                                    <?php if (!empty($courseCode)): ?>
                                        <div class="schedule-course-code"><?= htmlspecialchars($courseCode) ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="schedule-badge">
                                    <i class="fas fa-calendar-day"></i>
                                    <?= htmlspecialchars($item['day_of_week'] ?? 'N/A') ?>
                                </span>
                            </div>
                            <div class="schedule-details">
                                <div class="schedule-detail-item">
                                    <i class="fas fa-clock"></i>
                                    <span class="schedule-detail-label">Time:</span>
                                    <span class="schedule-detail-value"><?= htmlspecialchars($timeRange) ?></span>
                                </div>
                                <div class="schedule-detail-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="schedule-detail-label">Room/Location:</span>
                                    <span class="schedule-detail-value"><?= htmlspecialchars($location) ?></span>
                                </div>
                                <?php if (!empty($sectionInfo)): ?>
                                    <div class="schedule-detail-item">
                                        <i class="fas fa-users"></i>
                                        <span class="schedule-detail-label">Section:</span>
                                        <span class="schedule-detail-value"><?= htmlspecialchars($sectionInfo) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['academic_year'])): ?>
                                    <div class="schedule-detail-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span class="schedule-detail-label">Academic Year:</span>
                                        <span class="schedule-detail-value"><?= htmlspecialchars($item['academic_year']) ?> - <?= htmlspecialchars($item['semester'] ?? '') ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="noScheduleResults" class="no-results" style="display: none;">
                    <i class="fas fa-search"></i>
                    <p>No schedule items found matching your search</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>No Scheduled Courses</h3>
                    <p>You don't have any scheduled courses assigned yet. Please contact the administrator.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function filterSchedule() {
            const searchTerm = document.getElementById('scheduleSearch')?.value.toLowerCase() || '';
            const dayFilter = document.getElementById('dayFilter')?.value.toLowerCase() || '';
            const scheduleItems = document.querySelectorAll('.schedule-item');
            const noResults = document.getElementById('noScheduleResults');
            const scheduleList = document.getElementById('scheduleList');
            let visibleCount = 0;
            
            scheduleItems.forEach(item => {
                const courseName = item.getAttribute('data-course-name') || '';
                const courseCode = item.getAttribute('data-course-code') || '';
                const section = item.getAttribute('data-section') || '';
                const day = item.getAttribute('data-day') || '';
                
                const matchesSearch = !searchTerm || 
                    courseName.includes(searchTerm) || 
                    courseCode.includes(searchTerm) ||
                    section.includes(searchTerm);
                const matchesDay = !dayFilter || day === dayFilter;
                
                if (matchesSearch && matchesDay) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || dayFilter)) {
                if (noResults) noResults.style.display = 'block';
                if (scheduleList) scheduleList.style.display = 'none';
            } else {
                if (noResults) noResults.style.display = 'none';
                if (scheduleList) scheduleList.style.display = 'flex';
            }
        }
    </script>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
</body>
</html>



