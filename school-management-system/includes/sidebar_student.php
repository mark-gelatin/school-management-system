<?php
/**
 * Student portal sidebar.
 */

declare(strict_types=1);

$activePage = $activePage ?? '';
?>
<aside class="sidebar" id="appSidebar">
    <nav class="sidebar-nav">
        <h3>Student Portal</h3>
        <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('student/dashboard.php')) ?>">Dashboard</a>
        <a class="<?= $activePage === 'profile' ? 'active' : '' ?>" href="<?= e(app_url('student/profile.php')) ?>">Profile</a>
        <a class="<?= $activePage === 'enrollment' ? 'active' : '' ?>" href="<?= e(app_url('student/enrollment.php')) ?>">Enrollment</a>
        <a class="<?= $activePage === 'grades' ? 'active' : '' ?>" href="<?= e(app_url('student/grades.php')) ?>">Grades & GPA</a>
        <a class="<?= $activePage === 'documents' ? 'active' : '' ?>" href="<?= e(app_url('student/documents.php')) ?>">Documents</a>
        <a class="<?= $activePage === 'notifications' ? 'active' : '' ?>" href="<?= e(app_url('student/notifications.php')) ?>">Notifications</a>
        <a class="<?= $activePage === 'lms_modules' ? 'active' : '' ?>" href="<?= e(app_url('student/lms/modules.php')) ?>">LMS Modules</a>
    </nav>
</aside>
