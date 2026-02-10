<?php
// Teacher Handled Courses Page
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
require_once getAbsolutePath('backend/includes/course_enrollment.php');

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

// Get only subjects assigned to this teacher (excluding archived)
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.* 
        FROM subjects s
        INNER JOIN teacher_subjects ts ON s.id = ts.subject_id
        LEFT JOIN archived_courses ac ON s.id = ac.subject_id 
            AND ac.teacher_id = ts.teacher_id
        WHERE ts.teacher_id = ?
        AND ac.id IS NULL
        ORDER BY s.name
    ");
    $stmt->execute([$teacherId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error retrieving handled courses: ' . $e->getMessage();
}

// Handle AJAX request to get students for a subject
if (isset($_GET['action']) && $_GET['action'] === 'get_subject_students' && isset($_GET['subject_id'])) {
    header('Content-Type: application/json');
    $subject_id = intval($_GET['subject_id']);
    
    try {
        // Verify teacher is assigned to this subject
        $check_stmt = $pdo->prepare("SELECT id FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?");
        $check_stmt->execute([$teacherId, $subject_id]);
        
        if ($check_stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'You are not assigned to this course']);
            exit();
        }
        
        // Get students enrolled in this subject using unified function
        $students = getStudentsEnrolledInSubject($pdo, $subject_id, $teacherId);
        
        echo json_encode(['success' => true, 'students' => $students]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
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
    <title>Handled Courses - Colegio de Amore</title>
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
            border-left: 4px solid #a11c27;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
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
            background: #a11c27;
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
        
        /* Action Button Styles */
        .action-button {
            width: 100%;
            background: #a11c27;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 15px;
        }
        
        .action-button:hover {
            background: #8a1620;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(161, 28, 39, 0.3);
        }
        
        .action-button i {
            font-size: 0.9rem;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.show {
            display: flex;
        }
        
        .modal-dialog {
            background: white;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #a11c27 0%, #b31310 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-header h5 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .list-group {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .list-group-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .list-group-item > div:last-child {
            flex: 1;
        }
        
        .list-group-item:last-child {
            border-bottom: none;
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
            flex-shrink: 0;
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .modal-dialog {
                width: 95%;
                max-height: 90vh;
            }
            
            .modal-body {
                padding: 15px;
            }
            
            .list-group-item {
                padding: 12px;
                gap: 10px;
            }
            
            .student-avatar {
                width: 35px;
                height: 35px;
                font-size: 0.8rem;
            }
            
            .list-group-item > div:last-child {
                font-size: 0.9rem;
            }
            
            .list-group-item > div:last-child > div {
                font-size: 0.75rem;
            }
        }
        
    </style>
</head>
<body>
    <?php 
    $currentPage = 'subjects';
    include __DIR__ . '/../includes/teacher-sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-book"></i> Handled Courses</h1>
        </div>
        
        <?php if (!empty($subjects)): ?>
            <div class="search-filter-container">
                <div class="search-box">
                    <input type="text" id="subjectSearch" placeholder="Search handled courses by name or code..." onkeyup="filterSubjects()">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="programFilter" onchange="filterSubjects()">
                    <option value="">All Programs</option>
                    <?php 
                    $programs = [];
                    foreach ($subjects as $subject) {
                        if (!empty($subject['program'])) {
                            $programs[$subject['program']] = $subject['program'];
                        }
                    }
                    foreach ($programs as $program): ?>
                        <option value="<?= htmlspecialchars($program) ?>"><?= htmlspecialchars($program) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="yearLevelFilter" onchange="filterSubjects()">
                    <option value="">All Year Levels</option>
                    <?php 
                    $yearLevels = [];
                    foreach ($subjects as $subject) {
                        if (!empty($subject['year_level'])) {
                            $yearLevels[$subject['year_level']] = $subject['year_level'];
                        }
                    }
                    foreach ($yearLevels as $yearLevel): ?>
                        <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="subjects-grid" id="subjectsGrid">
                <?php foreach ($subjects as $subject): ?>
                    <?php $initial = strtoupper(substr($subject['name'], 0, 1)); ?>
                    <div class="subject-card" 
                         data-subject-id="<?= $subject['id'] ?>"
                         data-subject-name="<?= strtolower(htmlspecialchars($subject['name'])) ?>"
                         data-subject-code="<?= strtolower(htmlspecialchars($subject['code'])) ?>"
                         data-program="<?= strtolower(htmlspecialchars($subject['program'] ?? '')) ?>"
                         data-year-level="<?= strtolower(htmlspecialchars($subject['year_level'] ?? '')) ?>">
                        <div class="subject-header">
                            <div class="subject-icon"><?= $initial ?></div>
                            <div class="subject-title">
                                <div class="subject-name"><?= htmlspecialchars($subject['name']) ?></div>
                                <div class="subject-code"><?= htmlspecialchars($subject['code']) ?></div>
                            </div>
                        </div>
                        <?php if ($subject['description']): ?>
                            <div class="subject-description"><?= htmlspecialchars($subject['description']) ?></div>
                        <?php endif; ?>
                        <div class="subject-info">
                            <div class="info-item">
                                <div class="info-label">Units</div>
                                <div class="info-value"><?= htmlspecialchars($subject['units'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Program</div>
                                <div class="info-value" style="font-size: 0.9rem;"><?= htmlspecialchars($subject['program'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Year Level</div>
                                <div class="info-value" style="font-size: 0.9rem;"><?= htmlspecialchars($subject['year_level'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <button type="button" class="action-button" onclick="event.stopPropagation(); showCourseStudents(<?= $subject['id'] ?>, '<?= htmlspecialchars($subject['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($subject['code'], ENT_QUOTES) ?>')">
                            <i class="fas fa-users"></i>
                            <span>Click to view students</span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="noSubjectResults" class="no-results" style="display: none;">
                <i class="fas fa-search"></i>
                <p>No handled courses found matching your search</p>
            </div>
        <?php else: ?>
            <div class="subject-card">
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <p>No handled courses assigned yet. Please contact the administrator to assign courses to you.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Course Students Modal -->
    <div class="modal-overlay" id="courseStudentsModal" onclick="closeModal(event)">
        <div class="modal-dialog" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h5>
                    <i class="fas fa-users"></i> <span id="modalCourseName"></span>
                </h5>
                <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="studentsLoading" style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #a11c27;"></i>
                    <p style="margin-top: 15px; color: #666;">Loading students...</p>
                </div>
                <div id="studentsContent" style="display: none;">
                    <h6 style="margin-bottom: 15px; color: #333; font-weight: 600;">Enrolled Students</h6>
                    <ul class="list-group" id="studentsList">
                        <!-- Students will be loaded here -->
                    </ul>
                </div>
                <div id="studentsError" style="display: none; text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                    <p style="margin-top: 15px;" id="errorMessage"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>
    <script>
        function showCourseStudents(subjectId, courseName, courseCode) {
            // Show modal
            const modal = document.getElementById('courseStudentsModal');
            document.getElementById('modalCourseName').textContent = courseName + ' (' + courseCode + ')';
            
            // Reset modal content
            document.getElementById('studentsLoading').style.display = 'block';
            document.getElementById('studentsContent').style.display = 'none';
            document.getElementById('studentsError').style.display = 'none';
            document.getElementById('studentsList').innerHTML = '';
            
            modal.classList.add('show');
            
            // Fetch students
            fetch('?action=get_subject_students&subject_id=' + subjectId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('studentsLoading').style.display = 'none';
                    
                    if (data.success && data.students && data.students.length > 0) {
                        let html = '';
                        data.students.forEach(student => {
                            const studentId = student.student_id_number || 'N/A';
                            const section = student.section || 'N/A';
                            html += `
                                <li class="list-group-item">
                                    <div class="student-avatar">
                                        ${student.first_name.charAt(0).toUpperCase()}${student.last_name.charAt(0).toUpperCase()}
                                    </div>
                                    <div style="flex: 1;">
                                        <strong>${student.first_name} ${student.last_name}</strong>
                                        <div style="font-size: 0.85rem; color: #666; margin-top: 4px;">
                                            <span>ID: ${studentId}</span>
                                            ${section !== 'N/A' ? ` | <span>Section: ${section}</span>` : ''}
                                        </div>
                                    </div>
                                </li>
                            `;
                        });
                        document.getElementById('studentsList').innerHTML = html;
                        document.getElementById('studentsContent').style.display = 'block';
                    } else {
                        document.getElementById('errorMessage').textContent = data.error || 'No students enrolled in this course yet.';
                        document.getElementById('studentsError').style.display = 'block';
                    }
                })
                .catch(error => {
                    document.getElementById('studentsLoading').style.display = 'none';
                    document.getElementById('errorMessage').textContent = 'Error loading students. Please try again.';
                    document.getElementById('studentsError').style.display = 'block';
                });
        }
        
        function closeModal(event) {
            if (event && event.target !== event.currentTarget) {
                return;
            }
            const modal = document.getElementById('courseStudentsModal');
            modal.classList.remove('show');
        }
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
        
        function filterSubjects() {
            const searchTerm = document.getElementById('subjectSearch').value.toLowerCase();
            const programFilter = document.getElementById('programFilter').value.toLowerCase();
            const yearLevelFilter = document.getElementById('yearLevelFilter').value.toLowerCase();
            const subjectCards = document.querySelectorAll('#subjectsGrid .subject-card');
            const noResults = document.getElementById('noSubjectResults');
            let visibleCount = 0;
            
            subjectCards.forEach(card => {
                const subjectName = card.getAttribute('data-subject-name') || '';
                const subjectCode = card.getAttribute('data-subject-code') || '';
                const program = card.getAttribute('data-program') || '';
                const yearLevel = card.getAttribute('data-year-level') || '';
                
                const matchesSearch = !searchTerm || subjectName.includes(searchTerm) || subjectCode.includes(searchTerm);
                const matchesProgram = !programFilter || program === programFilter;
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                
                if (matchesSearch && matchesProgram && matchesYearLevel) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && (searchTerm || programFilter || yearLevelFilter)) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
    
    <?php include __DIR__ . '/../includes/teacher-sidebar-script.php'; ?>
</body>
</html>



