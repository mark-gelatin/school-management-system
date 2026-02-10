<?php
/**
 * Admin portal sidebar.
 */

declare(strict_types=1);

$activePage = $activePage ?? '';
?>
<aside class="sidebar" id="appSidebar">
    <nav class="sidebar-nav">
        <h3>Admin Portal</h3>
        <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('admin/dashboard.php')) ?>">Dashboard</a>
        <a class="<?= $activePage === 'users' ? 'active' : '' ?>" href="<?= e(app_url('admin/users.php')) ?>">Users</a>
        <a class="<?= $activePage === 'programs' ? 'active' : '' ?>" href="<?= e(app_url('admin/programs.php')) ?>">Programs</a>
        <a class="<?= $activePage === 'subjects' ? 'active' : '' ?>" href="<?= e(app_url('admin/subjects.php')) ?>">Subjects</a>
        <a class="<?= $activePage === 'sections' ? 'active' : '' ?>" href="<?= e(app_url('admin/sections.php')) ?>">Sections</a>
        <a class="<?= $activePage === 'enrollment_approval' ? 'active' : '' ?>" href="<?= e(app_url('admin/enrollment_approval.php')) ?>">Enrollment Approval</a>
        <a class="<?= $activePage === 'document_verification' ? 'active' : '' ?>" href="<?= e(app_url('admin/document_verification.php')) ?>">Document Verification</a>
        <a class="<?= $activePage === 'reports' ? 'active' : '' ?>" href="<?= e(app_url('admin/reports.php')) ?>">Reports</a>
        <a class="<?= $activePage === 'audit_logs' ? 'active' : '' ?>" href="<?= e(app_url('admin/audit_logs.php')) ?>">Audit Logs</a>
    </nav>
</aside>
