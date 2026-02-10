<?php
// View Registration Form (View-Only)
// Students can view their submitted registration information but cannot download or print it
// Configure session to work properly when opened in new tabs
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

// If session ID is provided in URL (for new tab scenarios), use it
if (isset($_GET['sid']) && !empty($_GET['sid']) && session_status() === PHP_SESSION_NONE) {
    session_id($_GET['sid']);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load path configuration first - use open_basedir compatible method
if (!defined('BASE_PATH')) {
    // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
    $currentDir = __DIR__;
    $parentDir = dirname($currentDir);
    $projectRoot = dirname($parentDir);
    $pathsFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'paths.php';
    if (file_exists($pathsFile)) {
        require_once $pathsFile;
    } else {
        // Fallback to VPS path
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
    
    if (!$student) {
        die('Student record not found.');
    }
    
    // Get admission application info
    $admissionInfo = null;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'admission_applications'");
        $admissionTableExists = $stmt->rowCount() > 0;
        
        if ($admissionTableExists) {
            $stmt = $pdo->prepare("
                SELECT * FROM admission_applications 
                WHERE student_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $admissionInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Continue without admission info
    }
    
    // Get course and section information
    $studentCourseCode = null;
    $studentSection = null;
    $studentCourseName = null;
    $activeSemester = null;
    try {
        $stmt = $pdo->prepare("
            SELECT c.code, c.name as course_name, s.section_name, s.academic_year, s.semester
            FROM courses c
            JOIN sections s ON c.id = s.course_id
            JOIN classrooms cl ON (cl.section = s.section_name AND cl.program = c.name AND cl.year_level = s.year_level)
            JOIN classroom_students cs ON cl.id = cs.classroom_id
            WHERE cs.student_id = ? AND cs.enrollment_status = 'enrolled'
            ORDER BY s.academic_year DESC, s.semester DESC
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $studentCourseCode = $result['code'];
            $studentSection = $result['section_name'];
            $studentCourseName = $result['course_name'];
            $activeSemester = $result['academic_year'] . ' - ' . strtoupper($result['semester']);
        }
    } catch (PDOException $e) {
        // Use fallback data
        if (!empty($student['section'])) {
            $studentSection = $student['section'];
        }
        if (!empty($student['program'])) {
            $studentCourseName = $student['program'];
        }
    }
    
} catch (PDOException $e) {
    die('Error retrieving student information.');
}

// Generate HTML registration form (can be printed as PDF or converted)
// For actual PDF generation, install TCPDF or use wkhtmltopdf
generateHTMLRegistrationForm($student, $admissionInfo, $studentCourseCode, $studentSection, $studentCourseName, $activeSemester);


function generateHTMLRegistrationForm($student, $admissionInfo, $courseCode, $section, $courseName, $semester) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registration Form - <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></title>
        <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            @media print {
                @page {
                    margin: 1.5cm;
                    size: A4;
                }
                body {
                    margin: 0;
                    padding: 0;
                }
                .no-print {
                    display: none !important;
                }
                .info-row {
                    page-break-inside: avoid;
                }
                /* Disable printing - show message instead */
                body::before {
                    content: "Printing and downloading of this registration form is not allowed.";
                    display: block;
                    text-align: center;
                    padding: 50px;
                    font-size: 18px;
                    color: #a11c27;
                }
                body > *:not(body::before) {
                    display: none !important;
                }
            }
            
            /* Allow text selection for readability, but prevent easy downloading */
            /* Text can be selected for reading purposes */
            * {
                box-sizing: border-box;
            }
            body {
                font-family: 'Arial', 'Helvetica', sans-serif;
                max-width: 800px;
                margin: 20px auto;
                padding: 20px;
                background: white;
                color: #333;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px solid #a11c27;
                padding-bottom: 15px;
            }
            .header h1 {
                color: #a11c27;
                margin: 0;
                font-size: 28px;
                font-weight: bold;
                letter-spacing: 1px;
            }
            .header h2 {
                color: #333;
                margin: 8px 0 0 0;
                font-size: 18px;
                font-weight: normal;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .section {
                margin-bottom: 30px;
                page-break-inside: avoid;
            }
            .section-title {
                background: #a11c27;
                color: white;
                padding: 10px 15px;
                font-weight: bold;
                margin-bottom: 15px;
                font-size: 14px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .info-row {
                display: flex;
                margin-bottom: 12px;
                border-bottom: 1px dotted #ddd;
                padding-bottom: 8px;
                min-height: 25px;
            }
            .info-label {
                width: 220px;
                font-weight: bold;
                color: #333;
                flex-shrink: 0;
            }
            .info-value {
                flex: 1;
                color: #333;
                word-wrap: break-word;
            }
            .print-btn {
                display: none; /* Hide print button - view only */
            }
            .return-btn {
                background: #a11c27;
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                margin: 20px 10px;
                transition: background 0.3s;
                text-decoration: none;
                display: inline-block;
            }
            .return-btn:hover {
                background: #b31310;
            }
            .button-group {
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 11px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="text-align: center; margin-bottom: 20px;">
            <div class="button-group">
                <a href="student-dashboard.php" class="return-btn">
                    <i class="fas fa-arrow-left"></i> Return to Dashboard
                </a>
            </div>
            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; color: #856404; font-size: 0.9rem;">
                <i class="fas fa-info-circle"></i> This is a view-only form. Download and print functionality has been disabled.
            </div>
        </div>
        
        <div class="header">
            <h1>COLEGIO DE AMORE</h1>
            <h2>REGISTRATION FORM</h2>
        </div>
        
        <div class="section">
            <div class="section-title">STUDENT INFORMATION</div>
            <div class="info-row">
                <div class="info-label">Student ID Number:</div>
                <div class="info-value"><?= htmlspecialchars($student['student_id_number'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Name:</div>
                <div class="info-value"><?= htmlspecialchars(strtoupper($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'] . ($student['suffix'] && $student['suffix'] !== 'N/A' ? ' ' . $student['suffix'] : ''))) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value"><?= htmlspecialchars($student['email'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone Number:</div>
                <div class="info-value"><?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Date of Birth:</div>
                <div class="info-value"><?= htmlspecialchars($student['birthday'] ?? $student['birth_date'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Gender:</div>
                <div class="info-value"><?= htmlspecialchars($student['gender'] ?? $student['sex'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Course:</div>
                <div class="info-value"><?= htmlspecialchars($courseName ?? $student['program'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Section:</div>
                <div class="info-value"><?= htmlspecialchars($section ?? $student['section'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Academic Year/Semester:</div>
                <div class="info-value"><?= htmlspecialchars($semester ?? 'N/A') ?></div>
            </div>
            <?php if ($admissionInfo && !empty($admissionInfo['application_number'])): ?>
            <div class="info-row">
                <div class="info-label">Application Number:</div>
                <div class="info-value"><?= htmlspecialchars($admissionInfo['application_number']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-title">ADDRESS INFORMATION</div>
            <div class="info-row">
                <div class="info-label">Address:</div>
                <div class="info-value"><?= htmlspecialchars($student['address'] ?? $student['street_address'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Barangay:</div>
                <div class="info-value"><?= htmlspecialchars($student['baranggay'] ?? $student['barangay'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">City/Municipality:</div>
                <div class="info-value"><?= htmlspecialchars($student['municipality'] ?? $student['city'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Province:</div>
                <div class="info-value"><?= htmlspecialchars($student['city_province'] ?? $student['province'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Country:</div>
                <div class="info-value"><?= htmlspecialchars($student['country'] ?? 'Philippines') ?></div>
            </div>
        </div>
        
        <div style="margin-top: 40px; text-align: center; font-size: 12px; color: #666;">
            Generated on: <?= date('F d, Y h:i A') ?>
        </div>
        
        <script>
            // Disable common download/print shortcuts
            document.addEventListener('keydown', function(e) {
                // Disable Ctrl+P (Print)
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    alert('Printing and downloading of this registration form is not allowed.');
                    return false;
                }
                // Disable Ctrl+S (Save)
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    alert('Saving and downloading of this registration form is not allowed.');
                    return false;
                }
                // Disable F12 (Developer Tools - can be used to inspect/download)
                if (e.key === 'F12') {
                    e.preventDefault();
                    return false;
                }
                // Disable Ctrl+Shift+I (Developer Tools)
                if (e.ctrlKey && e.shiftKey && e.key === 'I') {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Disable right-click context menu
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Disable drag and drop
            document.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Disable text selection shortcuts
            document.addEventListener('selectstart', function(e) {
                // Allow text selection for readability, but prevent easy copying
                // This is a soft prevention - users can still select if needed for reading
                return true;
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

