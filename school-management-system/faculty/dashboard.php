<?php
/**
 * Faculty dashboard page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('faculty');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$stats = [
    'assigned_subjects' => 0,
    'assigned_sections' => 0,
    'pending_submissions' => 0,
    'attendance_today' => 0,
];

if ($facultyId > 0) {
    $stats['assigned_subjects'] = (int) (db_fetch_one(
        'SELECT COUNT(DISTINCT subject_id) AS total FROM section_subjects WHERE faculty_id = :faculty_id',
        ['faculty_id' => $facultyId]
    )['total'] ?? 0);
    $stats['assigned_sections'] = (int) (db_fetch_one(
        'SELECT COUNT(DISTINCT section_id) AS total FROM section_subjects WHERE faculty_id = :faculty_id',
        ['faculty_id' => $facultyId]
    )['total'] ?? 0);
    $stats['pending_submissions'] = (int) (db_fetch_one(
        'SELECT COUNT(*) AS total
         FROM lms_submissions ls
         INNER JOIN lms_lessons ll ON ll.id = ls.lesson_id
         INNER JOIN lms_modules lm ON lm.id = ll.module_id
         WHERE lm.faculty_id = :faculty_id
           AND ls.status IN ("submitted", "late", "resubmitted")',
        ['faculty_id' => $facultyId]
    )['total'] ?? 0);
    $stats['attendance_today'] = (int) (db_fetch_one(
        'SELECT COUNT(*) AS total
         FROM attendance_records
         WHERE faculty_id = :faculty_id AND attendance_date = CURDATE()',
        ['faculty_id' => $facultyId]
    )['total'] ?? 0);
}

$announcements = db_fetch_all(
    'SELECT title, body, posted_at
     FROM announcements
     WHERE audience IN ("all", "faculty")
     ORDER BY posted_at DESC
     LIMIT 5'
);

$title = 'Faculty Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>Faculty Dashboard</h1>
    <p class="text-muted">Welcome, <?= e(current_user_name()) ?>.</p>

    <section class="card-grid">
        <article class="card stat-card">
            <h3>Assigned Subjects</h3>
            <p><?= e((string) $stats['assigned_subjects']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Assigned Sections</h3>
            <p><?= e((string) $stats['assigned_sections']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Pending LMS Submissions</h3>
            <p><?= e((string) $stats['pending_submissions']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Attendance Logged Today</h3>
            <p><?= e((string) $stats['attendance_today']) ?></p>
        </article>
    </section>

    <section class="card">
        <h2>Announcements</h2>
        <?php if ($announcements === []): ?>
            <p>No announcements available.</p>
        <?php else: ?>
            <div class="stack-list">
                <?php foreach ($announcements as $announcement): ?>
                    <article class="stack-item">
                        <h3><?= e((string) $announcement['title']) ?></h3>
                        <p><?= nl2br(e((string) $announcement['body'])) ?></p>
                        <small><?= e((string) $announcement['posted_at']) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
