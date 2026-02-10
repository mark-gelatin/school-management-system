<?php
/**
 * Admin Grade Approval Interface
 * Allows admins to review, approve, or reject submitted final grades
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/finals_grading_validation.php';
require_once __DIR__ . '/../includes/grade_archiving.php';

// Check if user is logged in as admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('index.php');
}

$adminId = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle grade approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve_grade') {
        $gradeId = intval($_POST['grade_id']);
        
        try {
            // Get grade details first
            $stmt = $pdo->prepare("
                SELECT g.*, s.name as subject_name, u.first_name, u.last_name
                FROM grades g
                JOIN subjects s ON g.subject_id = s.id
                JOIN users u ON g.student_id = u.id
                WHERE g.id = ?
            ");
            $stmt->execute([$gradeId]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                throw new Exception('Grade not found');
            }
            
            $previousStatus = $grade['approval_status'] ?? 'pending';
            
            // Update grade to approved and locked
            $updateStmt = $pdo->prepare("
                UPDATE grades
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    is_locked = 1,
                    locked_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$adminId, $gradeId]);
            
            // Log action
            logGradeAction($pdo, $gradeId, 'approved', $adminId, 'admin', $previousStatus, 'approved', 'Grade approved by admin');
            
            // Check if all grades for this course are approved and archive if so
            $archiveResult = checkAndArchiveCourse($pdo, $gradeId);
            
            if ($archiveResult['archived']) {
                $message = 'Grade approved successfully. Course has been archived and all grades are locked.';
            } else {
                $message = 'Grade approved successfully. The grade is now locked and visible to students.';
            }
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Error approving grade: ' . $e->getMessage();
            $message_type = 'error';
            error_log("Grade approval error: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'reject_grade') {
        $gradeId = intval($_POST['grade_id']);
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        
        try {
            // Get grade details
            $stmt = $pdo->prepare("SELECT approval_status FROM grades WHERE id = ?");
            $stmt->execute([$gradeId]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grade) {
                throw new Exception('Grade not found');
            }
            
            $previousStatus = $grade['approval_status'] ?? 'pending';
            
            // Update grade to rejected
            $updateStmt = $pdo->prepare("
                UPDATE grades
                SET approval_status = 'rejected',
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$rejectionReason, $gradeId]);
            
            // Log action
            logGradeAction($pdo, $gradeId, 'rejected', $adminId, 'admin', $previousStatus, 'rejected', $rejectionReason);
            
            $message = 'Grade rejected. The teacher will be notified.';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Error rejecting grade: ' . $e->getMessage();
            $message_type = 'error';
            error_log("Grade rejection error: " . $e->getMessage());
        }
    }
}

// Get pending grades for approval
$pendingGrades = [];
$filters = [
    'course_id' => $_GET['course_id'] ?? null,
    'teacher_id' => $_GET['teacher_id'] ?? null,
    'academic_year' => $_GET['academic_year'] ?? null,
    'semester' => $_GET['semester'] ?? null,
];

try {
    $query = "
        SELECT g.*,
               u.first_name as student_first, u.last_name as student_last, u.student_id_number,
               s.name as subject_name, s.code as subject_code,
               t.first_name as teacher_first, t.last_name as teacher_last,
               c.name as course_name, c.code as course_code
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN subjects s ON g.subject_id = s.id
        JOIN users t ON g.teacher_id = t.id
        LEFT JOIN sections sec ON g.academic_year = sec.academic_year AND g.semester = sec.semester
        LEFT JOIN courses c ON sec.course_id = c.id
        WHERE g.approval_status = 'submitted'
        AND g.grade_type = 'final'
    ";
    
    $params = [];
    
    if ($filters['course_id']) {
        $query .= " AND sec.course_id = ?";
        $params[] = $filters['course_id'];
    }
    
    if ($filters['teacher_id']) {
        $query .= " AND g.teacher_id = ?";
        $params[] = $filters['teacher_id'];
    }
    
    if ($filters['academic_year']) {
        $query .= " AND g.academic_year = ?";
        $params[] = $filters['academic_year'];
    }
    
    if ($filters['semester']) {
        $query .= " AND g.semester = ?";
        $params[] = $filters['semester'];
    }
    
    $query .= " ORDER BY g.submitted_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $pendingGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching pending grades: " . $e->getMessage());
    $message = 'Error loading pending grades';
    $message_type = 'error';
}

// Get filter options
$courses = [];
$teachers = [];
$academicYears = [];

try {
    $stmt = $pdo->query("SELECT DISTINCT id, code, name FROM courses ORDER BY code");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT DISTINCT id, first_name, last_name FROM users WHERE role = 'teacher' ORDER BY last_name, first_name");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM grades WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables might not exist
}

// Include admin header/navigation
include 'admin-header.php';
?>

<style>
    .admin-container {
        padding: 30px;
        background: #f5f5f5;
        min-height: 100vh;
    }
    
    .admin-content {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 30px;
    }
    
    .page-header h1 {
        font-size: 1.8rem;
        font-weight: 700;
        color: #333;
        margin: 0 0 8px 0;
    }
    
    .page-header .text-muted {
        color: #666;
        font-size: 0.95rem;
        margin: 0;
    }
    
    .card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 30px;
        overflow: hidden;
    }
    
    .card:last-child {
        margin-bottom: 0;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 30px;
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .card-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: #333;
        border-bottom: 3px solid #a11c27;
        padding-bottom: 10px;
        margin: 0;
        display: inline-block;
        width: auto;
    }
    
    .card-header-description {
        color: #666;
        font-size: 0.95rem;
        font-weight: 400;
        margin: 0;
        margin-left: auto;
        padding-left: 20px;
    }
    
    .card-body {
        padding: 30px;
    }
    
    .form-label {
        display: block;
        font-weight: 500;
        color: #333;
        margin-bottom: 8px;
        font-size: 0.95rem;
        line-height: 1.5;
    }
    
    /* Unified form group structure - ensures consistent alignment */
    .row.g-3 > [class*="col-"] {
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    
    .row.g-3 > [class*="col-"] > .form-label {
        flex-shrink: 0;
        margin-bottom: 8px;
    }
    
    .row.g-3 > [class*="col-"] > .form-control,
    .row.g-3 > [class*="col-"] > .form-select,
    .row.g-3 > [class*="col-"] > .btn {
        flex: 0 0 auto;
        width: 100%;
        min-width: 0;
    }
    
    /* Ensure all form controls and button have identical height */
    .form-control,
    .form-select,
    .btn-primary {
        height: 42px;
        min-height: 42px;
        max-height: 42px;
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        vertical-align: top;
    }
    
    /* Ensure all dropdowns have identical visual appearance */
    .form-select {
        box-sizing: border-box;
    }
    
    /* Standardize input field to match dropdown appearance */
    .form-control[type="text"] {
        box-sizing: border-box;
    }
    
    /* Button column specific styling */
    .filter-button-col {
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    
    .form-control,
    .form-select {
        padding: 10px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 0.95rem;
        width: 100%;
        height: 42px;
        min-height: 42px;
        max-height: 42px;
        line-height: 1.5;
        background-color: #ffffff;
        color: #333;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        font-family: inherit;
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
    }
    
    /* Dropdown-specific styling with chevron */
    .form-select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 12px;
        padding-right: 35px;
        cursor: pointer;
    }
    
    /* Input field styling (no chevron) */
    .form-control[type="text"] {
        padding-right: 15px;
    }
    
    /* Placeholder styling to match dropdown text */
    .form-control::placeholder {
        color: #999;
        opacity: 1;
        font-size: 0.95rem;
    }
    
    .form-control::-webkit-input-placeholder {
        color: #999;
        font-size: 0.95rem;
    }
    
    .form-control::-moz-placeholder {
        color: #999;
        opacity: 1;
        font-size: 0.95rem;
    }
    
    .form-control:-ms-input-placeholder {
        color: #999;
        font-size: 0.95rem;
    }
    
    .form-control:hover,
    .form-select:hover {
        border-color: #c0c0c0;
        background-color: #fafafa;
    }
    
    .form-select:hover {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23000' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    }
    
    .form-control:focus,
    .form-select:focus {
        outline: none;
        border-color: #a11c27;
        box-shadow: 0 0 0 3px rgba(161, 28, 39, 0.1);
        background-color: #ffffff;
    }
    
    .form-select:focus {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23a11c27' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    }
    
    .form-control:active,
    .form-select:active {
        border-color: #a11c27;
        background-color: #ffffff;
    }
    
    .form-select:active {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23a11c27' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    }
    
    .form-select:disabled {
        background-color: #f5f5f5;
        cursor: not-allowed;
        opacity: 0.6;
    }
    
    /* Ensure consistent dropdown appearance across browsers */
    .form-select option {
        padding: 10px 15px;
        font-size: 0.95rem;
        color: #333;
        background-color: #ffffff;
    }
    
    .form-select option:hover {
        background-color: #f8f9fa;
    }
    
    .form-select option:checked {
        background-color: #a11c27;
        color: #ffffff;
    }
    
    .btn-primary {
        background: #a11c27;
        color: white;
        border: none;
        padding: 0 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95rem;
        transition: background 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 42px;
        min-height: 42px;
        max-height: 42px;
        box-sizing: border-box;
        line-height: 1.5;
        vertical-align: top;
    }
    
    .btn-primary:hover {
        background: #b31310;
        color: white;
    }
    
    .btn-primary i {
        font-size: 0.85rem;
    }
    
    .alert-info {
        background: #d1ecf1;
        border: 1px solid #bee5eb;
        color: #0c5460;
        border-radius: 8px;
        padding: 15px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert-info i {
        color: #0c5460;
        font-size: 1.1rem;
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .table thead {
        background: #f8f9fa;
    }
    
    .table th {
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: #333;
        border-bottom: 2px solid #e0e0e0;
        font-size: 0.95rem;
    }
    
    .table td {
        padding: 12px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 0.95rem;
    }
    
    .table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 0.875rem;
        border-radius: 6px;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
        border: none;
    }
    
    .btn-success:hover {
        background: #218838;
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
        border: none;
    }
    
    .btn-danger:hover {
        background: #c82333;
        color: white;
    }
    
    /* Filter Form Responsive - Unified Grid Layout */
    .row.g-3 {
        margin: 0;
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
    }
    
    .row.g-3 > [class*="col-"] {
        padding-left: 15px;
        padding-right: 15px;
        margin-bottom: 20px;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
    }
    
    /* Align button column to match form control baseline */
    .row.g-3 > [class*="col-"].filter-button-col {
        padding-top: 28px; /* Match label height (20px) + margin-bottom (8px) */
    }
    
    /* Prevent text overflow and breaking */
    .form-control,
    .form-select {
        word-wrap: break-word;
        overflow-wrap: break-word;
        white-space: nowrap;
        text-overflow: ellipsis;
    }
    
    .form-select option {
        white-space: normal;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    /* Responsive Design */
    @media (max-width: 992px) {
        .admin-container {
            padding: 20px;
        }
        
        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .card-header-description {
            margin-left: 0;
            padding-left: 0;
            width: 100%;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .row.g-3 > [class*="col-md-"] {
            margin-bottom: 20px;
        }
    }
    
    @media (max-width: 768px) {
        .admin-container {
            padding: 15px;
        }
        
        .card-header {
            padding: 15px 20px;
        }
        
        .card-body {
            padding: 15px;
        }
        
        .card-title {
            font-size: 1.1rem;
        }
        
        .form-control,
        .form-select,
        .btn-primary {
            font-size: 0.9rem;
            height: 38px;
            min-height: 38px;
            max-height: 38px;
        }
        
        .form-control,
        .form-select {
            padding: 8px 12px;
            padding-right: 32px;
        }
        
        .btn-primary {
            padding: 0 16px;
        }
        
        .form-select {
            background-position: right 10px center;
            background-size: 10px;
        }
        
        /* Adjust button column padding on tablet */
        .row.g-3 > [class*="col-"].filter-button-col {
            padding-top: 28px;
        }
        
        .row.g-3 > [class*="col-"] {
            margin-bottom: 15px;
        }
        
        .d-flex.align-items-end {
            margin-top: 0;
        }
        
        .table {
            font-size: 0.85rem;
        }
        
        .table th,
        .table td {
            padding: 8px;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
    }
    
    @media (max-width: 576px) {
        .page-header h1 {
            font-size: 1.5rem;
        }
        
        .card-title {
            font-size: 1rem;
            padding-bottom: 8px;
        }
        
        .form-control,
        .form-select,
        .btn-primary {
            font-size: 0.85rem;
            height: 36px;
            min-height: 36px;
            max-height: 36px;
        }
        
        .form-control,
        .form-select {
            padding: 8px 10px;
            padding-right: 30px;
        }
        
        .btn-primary {
            padding: 0 12px;
        }
        
        .form-select {
            background-position: right 8px center;
            background-size: 9px;
        }
        
        /* Remove button column padding adjustment on mobile */
        .row.g-3 > [class*="col-"].filter-button-col {
            padding-top: 0;
        }
        
        .row.g-3 > [class*="col-"] {
            margin-bottom: 15px;
        }
        
        .form-label {
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        
        .row.g-3 > [class*="col-"] {
            padding-left: 10px;
            padding-right: 10px;
            margin-bottom: 15px;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .btn-group {
            flex-direction: column;
            width: 100%;
        }
        
        .btn-group .btn {
            width: 100%;
            margin-bottom: 5px;
        }
    }
</style>

<div class="admin-container">
    <div class="admin-content">
        <div class="page-header">
            <h1>Grade Review / Approval</h1>
            <p class="text-muted">Review and approve or reject submitted final grades</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Search & Filter Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Search & Filter</h2>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['id'] ?>" <?= ($filters['course_id'] == $course['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Teacher</label>
                        <select name="teacher_id" class="form-select">
                            <option value="">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>" <?= ($filters['teacher_id'] == $teacher['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" 
                               value="<?= htmlspecialchars($filters['academic_year'] ?? '') ?>" 
                               placeholder="e.g., 2024-21">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All</option>
                            <option value="1st" <?= ($filters['semester'] === '1st') ? 'selected' : '' ?>>1st</option>
                            <option value="2nd" <?= ($filters['semester'] === '2nd') ? 'selected' : '' ?>>2nd</option>
                            <option value="Summer" <?= ($filters['semester'] === 'Summer') ? 'selected' : '' ?>>Summer</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 filter-button-col">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Pending Grades Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Pending Grades (<?= count($pendingGrades) ?>)</h2>
                <p class="card-header-description">Review and approve or reject submitted final grades</p>
            </div>
            <div class="card-body">
                <?php if (empty($pendingGrades)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No pending grades for approval.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <th>Course</th>
                                    <th>Teacher</th>
                                    <th>Grade</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingGrades as $grade): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($grade['student_id_number'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($grade['subject_name']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($grade['subject_code']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($grade['course_name'] ?? 'N/A') ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($grade['course_code'] ?? '') ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($grade['teacher_first'] . ' ' . $grade['teacher_last']) ?></td>
                                        <td>
                                            <strong style="font-size: 1.2em;"><?= number_format($grade['grade'], 2) ?></strong>
                                            <br><small class="text-muted">/ <?= number_format($grade['max_points'] ?? 100, 2) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($grade['academic_year']) ?></td>
                                        <td><?= htmlspecialchars(strtoupper($grade['semester'])) ?></td>
                                        <td><?= $grade['submitted_at'] ? date('M d, Y h:i A', strtotime($grade['submitted_at'])) : 'N/A' ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        onclick="showApproveModal(<?= $grade['id'] ?>, '<?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last']) ?>', '<?= htmlspecialchars($grade['subject_name']) ?>', <?= $grade['grade'] ?>)">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" 
                                                        onclick="showRejectModal(<?= $grade['id'] ?>, '<?= htmlspecialchars($grade['student_first'] . ' ' . $grade['student_last']) ?>', '<?= htmlspecialchars($grade['subject_name']) ?>')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php if ($grade['remarks']): ?>
                                        <tr>
                                            <td colspan="9" style="padding-left: 30px; font-style: italic; color: #666;">
                                                <i class="fas fa-comment"></i> <strong>Remarks:</strong> <?= htmlspecialchars($grade['remarks']) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Grade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve_grade">
                <input type="hidden" name="grade_id" id="approve_grade_id">
                <div class="modal-body">
                    <p>Are you sure you want to approve this grade?</p>
                    <div class="alert alert-info">
                        <strong>Student:</strong> <span id="approve_student_name"></span><br>
                        <strong>Subject:</strong> <span id="approve_subject_name"></span><br>
                        <strong>Grade:</strong> <span id="approve_grade_value"></span>
                    </div>
                    <p class="text-muted small">
                        Once approved, the grade will be locked and immediately visible to the student.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Grade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_grade">
                <input type="hidden" name="grade_id" id="reject_grade_id">
                <div class="modal-body">
                    <p>Please provide a reason for rejecting this grade:</p>
                    <div class="alert alert-warning">
                        <strong>Student:</strong> <span id="reject_student_name"></span><br>
                        <strong>Subject:</strong> <span id="reject_subject_name"></span>
                    </div>
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required 
                                  placeholder="Please explain why this grade is being rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showApproveModal(gradeId, studentName, subjectName, gradeValue) {
    document.getElementById('approve_grade_id').value = gradeId;
    document.getElementById('approve_student_name').textContent = studentName;
    document.getElementById('approve_subject_name').textContent = subjectName;
    document.getElementById('approve_grade_value').textContent = gradeValue;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function showRejectModal(gradeId, studentName, subjectName) {
    document.getElementById('reject_grade_id').value = gradeId;
    document.getElementById('reject_student_name').textContent = studentName;
    document.getElementById('reject_subject_name').textContent = subjectName;
    document.getElementById('rejection_reason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include 'admin-footer.php'; ?>






