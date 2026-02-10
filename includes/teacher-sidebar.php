<?php
/**
 * Shared Teacher Sidebar Component
 * Use this include for consistent sidebar across all teacher pages
 * 
 * Required variables:
 * - $teacher: Teacher user data array
 * - $currentPage: Current page identifier for active nav item
 */
if (!isset($teacher)) {
    $teacher = null;
}
if (!isset($currentPage)) {
    $currentPage = '';
}
?>
<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay for mobile menu -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="hideSidebar()"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-content">
        <div class="logo">
            <img src="../../assets/images/logo.png" alt="Colegio de Amore logo" />
            <h1 class="school-name" title="Colegio de Amore">Colegio de Amore</h1>
        </div>
        
        <!-- User Profile Card -->
        <div class="user-profile">
            <div class="profile-picture">
                <?php if ($teacher): ?>
                    <?= strtoupper(substr($teacher['first_name'], 0, 1) . substr($teacher['last_name'], 0, 1)) ?>
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="user-name">
                <?php if ($teacher): ?>
                    <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>
                <?php else: ?>
                    Teacher
                <?php endif; ?>
            </div>
            <div class="user-role">Teacher</div>
        </div>
        
        <div class="nav-section">
            <a href="teacher-dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
            </a>
            <a href="teacher-classrooms.php" class="nav-item <?= $currentPage === 'classrooms' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard"></i>
                <span>My Classrooms</span>
            </a>
            <a href="teacher-subjects.php" class="nav-item <?= $currentPage === 'subjects' ? 'active' : '' ?>">
                <i class="fas fa-book"></i>
                <span>Handled Courses</span>
            </a>
            <a href="teacher-archived-classes.php" class="nav-item <?= $currentPage === 'archived' ? 'active' : '' ?>">
                <i class="fas fa-archive"></i>
                <span>Archived Classes</span>
            </a>
            <a href="teacher-grades.php" class="nav-item <?= $currentPage === 'grades' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Grades</span>
            </a>
            <a href="teacher-schedule.php" class="nav-item <?= $currentPage === 'schedule' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>Schedule</span>
            </a>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <form method="POST" style="margin: 0 15px 20px 15px;">
            <button type="submit" name="logout" class="upgrade-btn" style="background: rgba(220, 53, 69, 0.8); color: white;">Logout</button>
        </form>
    </div>
</div>
