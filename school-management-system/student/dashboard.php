<?php
/**
 * Student dashboard page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');

$userId = (int) current_user_id();
$student = db_fetch_one(
    'SELECT s.id, s.student_no, s.year_level, p.code AS program_code, p.name AS program_name
     FROM students s
     INNER JOIN programs p ON p.id = s.program_id
     WHERE s.user_id = :user_id
     LIMIT 1',
    ['user_id' => $userId]
);

$studentId = (int) ($student['id'] ?? 0);
$stats = [
    'approved_enrollment' => (int) (db_fetch_one(
        'SELECT COUNT(*) AS total FROM enrollments WHERE student_id = :student_id AND status = "approved"',
        ['student_id' => $studentId]
    )['total'] ?? 0),
    'subjects' => (int) (db_fetch_one(
        'SELECT COUNT(*) AS total
         FROM enrollment_subjects es
         INNER JOIN enrollments e ON e.id = es.enrollment_id
         WHERE e.student_id = :student_id AND e.status = "approved"',
        ['student_id' => $studentId]
    )['total'] ?? 0),
    'pending_documents' => (int) (db_fetch_one(
        'SELECT COUNT(*) AS total FROM student_documents WHERE student_id = :student_id AND status = "pending"',
        ['student_id' => $studentId]
    )['total'] ?? 0),
    'unread_notifications' => (int) (db_fetch_one(
        'SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND is_read = 0',
        ['user_id' => $userId]
    )['total'] ?? 0),
];

$announcements = db_fetch_all(
    'SELECT title, body, posted_at
     FROM announcements
     WHERE audience IN ("all", "student")
     ORDER BY posted_at DESC
     LIMIT 5'
);

$title = 'Student Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>Student Dashboard</h1>
    <p class="text-muted">
        Welcome, <?= e(current_user_name()) ?>.
        <?= $student ? 'Student No: ' . e((string) $student['student_no']) . ' | Program: ' . e((string) $student['program_code']) : '' ?>
    </p>

    <section class="card-grid">
        <article class="card stat-card">
            <h3>Approved Enrollments</h3>
            <p><?= e((string) $stats['approved_enrollment']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Enrolled Subjects</h3>
            <p><?= e((string) $stats['subjects']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Pending Documents</h3>
            <p><?= e((string) $stats['pending_documents']) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Unread Notifications</h3>
            <p><?= e((string) $stats['unread_notifications']) ?></p>
        </article>
    </section>

    <section class="card">
        <h2>Announcements</h2>
        <?php if ($announcements === []): ?>
            <p>No announcements at this time.</p>
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
