<?php
// Teacher Students Page
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

// Get teacher's classrooms with sections and subjects
$sectionsData = [];
try {
    // Get classrooms with their sections
    // Try to get section and year_level if they exist
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                c.id as classroom_id,
                c.name as classroom_name,
                COALESCE(c.section, '') as section,
                COALESCE(c.year_level, '') as year_level,
                c.description as classroom_description
            FROM classrooms c
            WHERE c.teacher_id = ?
            ORDER BY COALESCE(c.year_level, ''), COALESCE(c.section, ''), c.name
        ");
        $stmt->execute([$teacherId]);
        $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If section/year_level columns don't exist, fall back to basic query
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                c.id as classroom_id,
                c.name as classroom_name,
                '' as section,
                '' as year_level,
                c.description as classroom_description
            FROM classrooms c
            WHERE c.teacher_id = ?
            ORDER BY c.name
        ");
        $stmt->execute([$teacherId]);
        $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // For each classroom, get subjects and students
    foreach ($classrooms as $classroom) {
        $classroomId = $classroom['classroom_id'];
        
        // Get subjects taught in this classroom by this teacher
        $subjects_stmt = $pdo->prepare("
            SELECT DISTINCT 
                s.id as subject_id,
                s.name as subject_name,
                s.code as subject_code,
                s.description as subject_description
            FROM subjects s
            JOIN grades g ON s.id = g.subject_id
            WHERE g.classroom_id = ? AND g.teacher_id = ?
            ORDER BY s.name
        ");
        $subjects_stmt->execute([$classroomId, $teacherId]);
        $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no subjects from grades, try to get from classroom directly (if there's a subject_id in classrooms)
        // Otherwise, we'll show the classroom without specific subjects
        
        // Get students in this classroom
        $students_stmt = $pdo->prepare("
            SELECT DISTINCT 
                u.*,
                cs.classroom_id
            FROM users u
            JOIN classroom_students cs ON u.id = cs.student_id
            WHERE cs.classroom_id = ? AND u.role = 'student'
            ORDER BY u.last_name, u.first_name
        ");
        $students_stmt->execute([$classroomId]);
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Only add section if it has students or subjects
        if (!empty($students) || !empty($subjects)) {
            $sectionsData[] = [
                'classroom' => $classroom,
                'subjects' => $subjects,
                'students' => $students
            ];
        }
    }
} catch (PDOException $e) {
    $message = 'Error retrieving data: ' . $e->getMessage();
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
    <title>My Students - Colegio de Amore</title>
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
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .students-table {
            width: 100%;
            border-collapse: collapse;
        }
        .students-table th,
        .students-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .students-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .students-table td {
            color: #666;
        }
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #a11c27;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
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
        .section-group {
            margin-bottom: 40px;
        }
        .section-header {
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 0;
        }
        .section-header h2 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        .section-header .section-info {
            font-size: 0.95rem;
            opacity: 0.95;
            margin-bottom: 5px;
        }
        .subject-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 8px;
            margin-top: 8px;
        }
        .subject-badge .subject-name {
            font-weight: 600;
        }
        .subject-badge .subject-desc {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-left: 5px;
        }
        .section-table-wrapper {
            border-radius: 0 0 12px 12px;
            overflow: hidden;
        }
        .section-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .section-table th,
        .section-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .section-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .section-table td {
            color: #666;
        }
        .section-table tbody tr:last-child td {
            border-bottom: none;
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
            <h1><i class="fas fa-user-graduate"></i> My Students</h1>
        </div>
        
        <?php if (!empty($sectionsData)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="studentSearch" placeholder="Search students by name, email, or ID..." onkeyup="filterStudents()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="sectionFilter" onchange="filterStudents()">
                    <option value="">All Sections</option>
                    <?php foreach ($sectionsData as $idx => $section): 
                        $classroom = $section['classroom'];
                        $sectionName = $classroom['classroom_name'];
                        if (!empty($classroom['section'])) {
                            $sectionName = ($classroom['year_level'] ? $classroom['year_level'] . ' - ' : '') . 'Section ' . $classroom['section'];
                        }
                    ?>
                        <option value="section-<?= $idx ?>"><?= htmlspecialchars($sectionName) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="programFilter" onchange="filterStudents()">
                    <option value="">All Programs</option>
                    <?php 
                    $programs = [];
                    foreach ($sectionsData as $section) {
                        $classroom = $section['classroom'];
                        if (!empty($classroom['program'])) {
                            $programs[$classroom['program']] = $classroom['program'];
                        }
                    }
                    foreach ($programs as $program): ?>
                        <option value="<?= strtolower(htmlspecialchars($program)) ?>"><?= htmlspecialchars($program) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="yearLevelFilter" onchange="filterStudents()">
                    <option value="">All Year Levels</option>
                    <?php 
                    $yearLevels = [];
                    foreach ($sectionsData as $section) {
                        $classroom = $section['classroom'];
                        if (!empty($classroom['year_level'])) {
                            $yearLevels[$classroom['year_level']] = $classroom['year_level'];
                        }
                    }
                    foreach ($yearLevels as $yearLevel): ?>
                        <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php foreach ($sectionsData as $idx => $section): 
                $classroom = $section['classroom'];
                $subjects = $section['subjects'];
                $students = $section['students'];
                
                // Build section display name
                $sectionName = $classroom['classroom_name'];
                if (!empty($classroom['section'])) {
                    $sectionName = ($classroom['year_level'] ? $classroom['year_level'] . ' - ' : '') . 'Section ' . $classroom['section'];
                }
            ?>
                <div class="section-group" 
                     data-section-index="<?= $idx ?>"
                     data-program="<?= strtolower(htmlspecialchars($classroom['program'] ?? '')) ?>"
                     data-year-level="<?= strtolower(htmlspecialchars($classroom['year_level'] ?? '')) ?>">
                    <div class="section-header">
                        <h2><i class="fas fa-chalkboard"></i> <?= htmlspecialchars($sectionName) ?></h2>
                        <?php if (!empty($classroom['classroom_description'])): ?>
                            <div class="section-info"><?= htmlspecialchars($classroom['classroom_description']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($subjects)): ?>
                            <div class="section-info">
                                <strong>Subjects:</strong>
                                <?php foreach ($subjects as $subject): ?>
                                    <span class="subject-badge">
                                        <span class="subject-name"><?= htmlspecialchars($subject['subject_name']) ?></span>
                                        <?php if (!empty($subject['subject_description'])): ?>
                                            <span class="subject-desc">- <?= htmlspecialchars($subject['subject_description']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="section-info">No subjects assigned yet</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-table-wrapper">
                        <?php if (!empty($students)): ?>
                            <table class="section-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Email</th>
                                        <th>Student ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr class="student-row" 
                                            data-student-name="<?= strtolower(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])) ?>"
                                            data-student-email="<?= strtolower(htmlspecialchars($student['email'] ?? '')) ?>"
                                            data-student-id="<?= strtolower(htmlspecialchars($student['student_id_number'] ?? '')) ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 12px;">
                                                    <div class="student-avatar">
                                                        <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($student['email'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($student['student_id_number'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 40px 20px; background: white;">
                                <i class="fas fa-user-graduate"></i>
                                <p>No students enrolled in this section</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div id="noStudentResults" class="no-results" style="display: none;">
                <i class="fas fa-search"></i>
                <p>No students found matching your search</p>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <p>No sections or students found</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
        function filterStudents() {
            const searchTerm = document.getElementById('studentSearch').value.toLowerCase();
            const sectionFilter = document.getElementById('sectionFilter').value;
            const programFilter = document.getElementById('programFilter').value.toLowerCase();
            const yearLevelFilter = document.getElementById('yearLevelFilter').value.toLowerCase();
            const sectionGroups = document.querySelectorAll('.section-group');
            const studentRows = document.querySelectorAll('.student-row');
            const noResults = document.getElementById('noStudentResults');
            let visibleCount = 0;
            let anySectionVisible = false;
            
            sectionGroups.forEach((sectionGroup, idx) => {
                const sectionIndex = sectionGroup.getAttribute('data-section-index');
                const sectionProgram = sectionGroup.getAttribute('data-program') || '';
                const sectionYearLevel = sectionGroup.getAttribute('data-year-level') || '';
                
                const matchesSection = !sectionFilter || sectionFilter === 'section-' + sectionIndex;
                const matchesProgram = !programFilter || sectionProgram === programFilter;
                const matchesYearLevel = !yearLevelFilter || sectionYearLevel === yearLevelFilter;
                
                const rowsInSection = sectionGroup.querySelectorAll('.student-row');
                let hasVisibleRows = false;
                
                rowsInSection.forEach(row => {
                    const studentName = row.getAttribute('data-student-name') || '';
                    const studentEmail = row.getAttribute('data-student-email') || '';
                    const studentId = row.getAttribute('data-student-id') || '';
                    
                    const matchesSearch = !searchTerm || 
                        studentName.includes(searchTerm) || 
                        studentEmail.includes(searchTerm) || 
                        studentId.includes(searchTerm);
                    
                    if (matchesSearch && matchesSection && matchesProgram && matchesYearLevel) {
                        row.style.display = '';
                        visibleCount++;
                        hasVisibleRows = true;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                if (hasVisibleRows && matchesSection && matchesProgram && matchesYearLevel) {
                    sectionGroup.style.display = '';
                    anySectionVisible = true;
                } else if (!sectionFilter && !programFilter && !yearLevelFilter && hasVisibleRows) {
                    sectionGroup.style.display = '';
                    anySectionVisible = true;
                } else {
                    sectionGroup.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || sectionFilter || programFilter || yearLevelFilter)) {
                if (noResults) noResults.style.display = 'block';
            } else {
                if (noResults) noResults.style.display = 'none';
            }
        }
    </script>
</body>
</html>

