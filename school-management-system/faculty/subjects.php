<?php
/**
 * Faculty assigned subjects page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('faculty');
require_permission('manage_assigned_subjects');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$subjects = [];
if ($facultyId > 0) {
    $subjects = db_fetch_all(
        'SELECT ss.id, ss.schedule_text, ss.room,
                sub.code AS subject_code, sub.title AS subject_title, sub.units,
                sec.name AS section_name, sec.school_year, sec.year_level,
                p.code AS program_code
         FROM section_subjects ss
         INNER JOIN subjects sub ON sub.id = ss.subject_id
         INNER JOIN sections sec ON sec.id = ss.section_id
         INNER JOIN programs p ON p.id = sec.program_id
         WHERE ss.faculty_id = :faculty_id
         ORDER BY sec.school_year DESC, sub.code ASC'
    , ['faculty_id' => $facultyId]);
}

$title = 'My Subjects';
$activePage = 'subjects';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>Assigned Subjects</h1>
    <p class="text-muted">List of all subjects assigned to you.</p>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Title</th>
                    <th>Units</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>Year Level</th>
                    <th>Schedule</th>
                    <th>Room</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($subjects === []): ?>
                    <tr><td colspan="8">No assigned subjects yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($subjects as $row): ?>
                        <tr>
                            <td><?= e((string) $row['subject_code']) ?></td>
                            <td><?= e((string) $row['subject_title']) ?></td>
                            <td><?= e((string) $row['units']) ?></td>
                            <td><?= e((string) $row['program_code']) ?></td>
                            <td><?= e((string) $row['section_name']) ?> (<?= e((string) $row['school_year']) ?>)</td>
                            <td><?= e((string) $row['year_level']) ?></td>
                            <td><?= e((string) ($row['schedule_text'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['room'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
