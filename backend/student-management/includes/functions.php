<?php
// CSRF Protection Functions
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('getCSRFTokenField')) {
    function getCSRFTokenField() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function redirect($url) {
    // Load path configuration if not already loaded - use open_basedir compatible method
    if (!defined('BASE_PATH')) {
        // Use dirname() instead of ../ in path strings to avoid open_basedir restrictions
        // From backend/student-management/includes/ go up 3 levels to root
        $currentDir = __DIR__; // /www/wwwroot/72.62.65.224/backend/student-management/includes
        $projectRoot = dirname(dirname(dirname($currentDir))); // /www/wwwroot/72.62.65.224
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
    
    // Use redirectTo if available, otherwise use standard redirect
    if (function_exists('redirectTo')) {
        redirectTo($url);
    } else {
        // Fallback to standard redirect
        header("Location: $url");
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function requireRole($role) {
    // Ensure session is started with proper configuration
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings for better reliability
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', 0);
        ini_set('session.gc_maxlifetime', 28800); // 8 hours instead of 1 hour
        ini_set('session.cookie_path', '/');
        
        session_start();
    }
    
    // Double-check session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Update session timestamp on each request to prevent expiration
    // This keeps the session alive as long as the user is active
    if (isset($_SESSION['user_id'])) {
        // Update last activity time
        $_SESSION['last_activity'] = time();
        
        // Regenerate session ID periodically (every 30 requests) to prevent fixation attacks
        if (!isset($_SESSION['request_count'])) {
            $_SESSION['request_count'] = 0;
        }
        $_SESSION['request_count']++;
        
        // Regenerate every 30 requests, but keep CSRF token
        if ($_SESSION['request_count'] % 30 === 0) {
            // Store CSRF token before regeneration
            $csrfToken = $_SESSION['csrf_token'] ?? null;
            session_regenerate_id(true);
            // Restore CSRF token after regeneration
            if ($csrfToken) {
                $_SESSION['csrf_token'] = $csrfToken;
            }
        }
    }
    
    // Check if user is logged in and has the correct role
    $isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $hasCorrectRole = isset($_SESSION['role']) && $_SESSION['role'] === $role;
    
    // Debug: Log session state if not logged in (only in development)
    if (!$isLoggedIn || !$hasCorrectRole) {
        // Log for debugging (remove in production)
        error_log("requireRole failed - isLoggedIn: " . ($isLoggedIn ? 'true' : 'false') . 
                  ", hasCorrectRole: " . ($hasCorrectRole ? 'true' : 'false') . 
                  ", expectedRole: " . $role . 
                  ", actualRole: " . ($_SESSION['role'] ?? 'not set') . 
                  ", userId: " . ($_SESSION['user_id'] ?? 'not set'));
        
        // Use base path for redirect
        if (function_exists('getFrontendUrl')) {
            $redirectUrl = getFrontendUrl('landing.html');
        } else {
            $redirectUrl = '/landing.html';
        }
        redirect($redirectUrl);
    }
}

function getDashboardUrl() {
    if (!isLoggedIn()) return 'index.php';
    
    // Redirect to appropriate dashboard based on role
    switch ($_SESSION['role']) {
        case 'admin': return 'admin.php';
        case 'teacher': return 'teacher.php';
        case 'student': return 'students.php';
        default: return 'index.php';
    }
}

function calculateGradePoint($grade) {
    if ($grade >= 90) return 'A';
    if ($grade >= 80) return 'B';
    if ($grade >= 70) return 'C';
    if ($grade >= 60) return 'D';
    return 'F';
}

function getGradeColor($grade) {
    if ($grade >= 90) return 'success';
    if ($grade >= 80) return 'info';
    if ($grade >= 70) return 'warning';
    return 'danger';
}

function deleteUser($pdo, $user_id) {
    // Don't allow users to delete themselves
    if ($user_id == $_SESSION['user_id']) {
        return "You cannot delete your own account!";
    }

    // Ensure the user exists and capture their role for safety checks
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRecord) {
        return "User not found.";
    }

    // Prevent removing the last administrator account
    if ($userRecord['role'] === 'admin') {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            return "At least one administrator account must remain in the system.";
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete user's grades first
        $stmt = $pdo->prepare("DELETE FROM grades WHERE student_id = ? OR teacher_id = ?");
        $stmt->execute([$user_id, $user_id]);
        
        // Delete from classroom_students
        $stmt = $pdo->prepare("DELETE FROM classroom_students WHERE student_id = ?");
        $stmt->execute([$user_id]);
        
        // Update classrooms if user is a teacher
        $stmt = $pdo->prepare("UPDATE classrooms SET teacher_id = NULL WHERE teacher_id = ?");
        $stmt->execute([$user_id]);
        
        // Finally delete the user
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error deleting user: " . $e->getMessage();
    }
}

function deleteSubject($pdo, $subject_id) {
    try {
        $pdo->beginTransaction();
        
        // Delete grades associated with this subject
        $stmt = $pdo->prepare("DELETE FROM grades WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        
        // Delete the subject
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([$subject_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error deleting subject: " . $e->getMessage();
    }
}

function deleteClassroom($pdo, $classroom_id) {
    try {
        $pdo->beginTransaction();
        
        // Delete grades associated with this classroom
        $stmt = $pdo->prepare("DELETE FROM grades WHERE classroom_id = ?");
        $stmt->execute([$classroom_id]);
        
        // Delete classroom students
        $stmt = $pdo->prepare("DELETE FROM classroom_students WHERE classroom_id = ?");
        $stmt->execute([$classroom_id]);
        
        // Delete the classroom
        $stmt = $pdo->prepare("DELETE FROM classrooms WHERE id = ?");
        $stmt->execute([$classroom_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error deleting classroom: " . $e->getMessage();
    }
}

function deleteGrade($pdo, $grade_id, $teacher_id) {
    // Verify the grade belongs to the teacher
    $stmt = $pdo->prepare("SELECT * FROM grades WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$grade_id, $teacher_id]);
    
    if ($stmt->rowCount() === 0) {
        return "Grade not found or you don't have permission to delete it!";
    }
    
    $stmt = $pdo->prepare("DELETE FROM grades WHERE id = ?");
    $stmt->execute([$grade_id]);
    return true;
}

function removeStudentFromClassroom($pdo, $classroom_id, $student_id) {
    try {
        $pdo->beginTransaction();
        
        // Delete grades for this student in this classroom
        $stmt = $pdo->prepare("DELETE FROM grades WHERE classroom_id = ? AND student_id = ?");
        $stmt->execute([$classroom_id, $student_id]);
        
        // Remove student from classroom
        $stmt = $pdo->prepare("DELETE FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
        $stmt->execute([$classroom_id, $student_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error removing student: " . $e->getMessage();
    }
}

function getClassroomStudents($pdo, $classroom_id) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.username, u.email 
        FROM users u 
        JOIN classroom_students cs ON u.id = cs.student_id 
        WHERE cs.classroom_id = ? 
        ORDER BY u.first_name, u.last_name
    ");
    $stmt->execute([$classroom_id]);
    return $stmt->fetchAll();
}

/**
 * Log admin action to admin_logs table
 */
function logAdminAction($pdo, $admin_id, $action, $entity_type = null, $entity_id = null, $description = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$admin_id, $action, $entity_type, $entity_id, $description, $ip_address, $user_agent]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log admin action: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate unique student number in format: YYYY-NNNN (e.g., 2025-0001)
 */
function generateStudentNumber($pdo, $year = null) {
    if ($year === null) {
        $year = date('Y');
    }
    
    try {
        // First, reassign student numbers to ensure sequential order
        // This handles gaps from rejected students
        require_once __DIR__ . '/../../includes/student_rejection_handler.php';
        if (function_exists('reassignStudentNumbers')) {
            reassignStudentNumbers($pdo);
        }
        
        // Get the highest student number for this year (only for enrolled students)
        // Only count students with approved applications, exclude rejected
        $stmt = $pdo->prepare("
            SELECT u.student_id_number 
            FROM users u
            INNER JOIN admission_applications aa ON u.id = aa.student_id
            WHERE u.student_id_number IS NOT NULL
            AND u.student_id_number REGEXP '^[0-9]{4}-[0-9]{4}$'
            AND u.student_id_number LIKE ?
            AND aa.status = 'approved'
            AND u.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM admission_applications aa2 
                WHERE aa2.student_id = u.id 
                AND aa2.status = 'rejected'
            )
            ORDER BY u.student_id_number DESC 
            LIMIT 1
        ");
        $pattern = $year . '-%';
        $stmt->execute([$pattern]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Extract the number part and increment
            $parts = explode('-', $result['student_id_number']);
            $number = intval($parts[1]) + 1;
        } else {
            // Check if there are any existing student IDs with STU format for this year
            $stmt2 = $pdo->prepare("
                SELECT u.student_id_number 
                FROM users u
                INNER JOIN admission_applications aa ON u.id = aa.student_id
                WHERE u.student_id_number IS NOT NULL
                AND u.student_id_number LIKE ?
                AND aa.status = 'approved'
                AND u.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM admission_applications aa2 
                    WHERE aa2.student_id = u.id 
                    AND aa2.status = 'rejected'
                )
                ORDER BY u.student_id_number DESC 
                LIMIT 1
            ");
            $stuPattern = 'STU' . $year . '%';
            $stmt2->execute([$stuPattern]);
            $stuResult = $stmt2->fetch();
            
            if ($stuResult) {
                // Extract number from STU format (e.g., STU20254778 -> 4778)
                $stuNumber = preg_replace('/^STU' . $year . '/', '', $stuResult['student_id_number']);
                $number = intval($stuNumber) + 1;
            } else {
                // First student for this year
                $number = 1;
            }
        }
        
        // Format: YYYY-NNNN (consistent format)
        $student_number = sprintf('%s-%04d', $year, $number);
        
        // Double-check uniqueness and increment if needed
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE student_id_number = ?");
        $check_stmt->execute([$student_number]);
        
        while ($check_stmt->rowCount() > 0) {
            // If exists, increment and try again
            $number++;
            $student_number = sprintf('%s-%04d', $year, $number);
            $check_stmt->execute([$student_number]);
        }
        
        return $student_number;
    } catch (Exception $e) {
        error_log("Error generating student number: " . $e->getMessage());
        // Fallback: use timestamp-based number
        return $year . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

/**
 * Generate random password for teachers
 */
function generatePassword($length = 12) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

/**
 * Generate username for teacher based on name
 */
function generateTeacherUsername($pdo, $first_name, $last_name) {
    $base_username = strtolower(substr($first_name, 0, 1) . $last_name);
    $base_username = preg_replace('/[^a-z0-9]/', '', $base_username);
    
    $username = $base_username;
    $counter = 1;
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    
    while ($stmt->rowCount() > 0) {
        $username = $base_username . $counter;
        $stmt->execute([$username]);
        $counter++;
    }
    
    return $username;
}

if (!function_exists('getEnrolledStudentEligibilityCondition')) {
    /**
     * SQL condition that ensures a student either has an approved application
     * or no admission application record (legacy/manual accounts).
     *
     * @param string $alias Table alias referencing the users table.
     */
    function getEnrolledStudentEligibilityCondition(string $alias = 'users'): string {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        if ($alias === '') {
            $alias = 'users';
        }
        
        return "(
            -- Exclude rejected students
            NOT EXISTS (
                SELECT 1 FROM admission_applications aa2 
                WHERE aa2.student_id = {$alias}.id 
                AND aa2.status = 'rejected'
            )
            AND (
                NOT EXISTS (
                    SELECT 1 FROM admission_applications aa_pending
                    WHERE aa_pending.student_id = {$alias}.id
                )
                OR EXISTS (
                    SELECT 1 FROM admission_applications aa_approved
                    WHERE aa_approved.student_id = {$alias}.id
                      AND aa_approved.status = 'approved'
                )
            )
        )";
    }
}
?>