<?php
/**
 * Faculty portal sidebar.
 */

declare(strict_types=1);

$activePage = $activePage ?? '';
?>
<aside class="sidebar" id="appSidebar">
    <nav class="sidebar-nav">
        <h3>Faculty Portal</h3>
        <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('faculty/dashboard.php')) ?>">Dashboard</a>
        <a class="<?= $activePage === 'subjects' ? 'active' : '' ?>" href="<?= e(app_url('faculty/subjects.php')) ?>">Subjects</a>
        <a class="<?= $activePage === 'sections' ? 'active' : '' ?>" href="<?= e(app_url('faculty/sections.php')) ?>">Sections</a>
        <a class="<?= $activePage === 'grade_encoding' ? 'active' : '' ?>" href="<?= e(app_url('faculty/grade_encoding.php')) ?>">Grade Encoding</a>
        <a class="<?= $activePage === 'attendance' ? 'active' : '' ?>" href="<?= e(app_url('faculty/attendance.php')) ?>">Attendance</a>
        <a class="<?= $activePage === 'lms_modules' ? 'active' : '' ?>" href="<?= e(app_url('faculty/lms/modules.php')) ?>">LMS Modules</a>
        <a class="<?= $activePage === 'lms_lessons' ? 'active' : '' ?>" href="<?= e(app_url('faculty/lms/lessons.php')) ?>">Lessons</a>
        <a class="<?= $activePage === 'lms_submissions' ? 'active' : '' ?>" href="<?= e(app_url('faculty/lms/submissions.php')) ?>">Submissions</a>
    </nav>
</aside>
