<?php
/**
 * Faculty section masterlists page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('faculty');
require_permission('view_masterlists');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$sections = [];
$studentsBySection = [];
if ($facultyId > 0) {
    $sections = db_fetch_all(
        'SELECT DISTINCT sec.id, sec.name, sec.school_year, sec.year_level, p.code AS program_code
         FROM section_subjects ss
         INNER JOIN sections sec ON sec.id = ss.section_id
         INNER JOIN programs p ON p.id = sec.program_id
         WHERE ss.faculty_id = :faculty_id
         ORDER BY sec.school_year DESC, sec.year_level ASC, sec.name ASC',
        ['faculty_id' => $facultyId]
    );

    if ($sections !== []) {
        $sectionIds = array_map(static fn($s) => (int) $s['id'], $sections);
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = get_db()->prepare(
            "SELECT s.section_id, s.student_no, u.first_name, u.last_name, u.email
             FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.section_id IN ({$placeholders})
             ORDER BY u.last_name ASC, u.first_name ASC"
        );
        $stmt->execute($sectionIds);
        foreach ($stmt->fetchAll() as $row) {
            $studentsBySection[(int) $row['section_id']][] = $row;
        }
    }
}

$title = 'Sections & Masterlists';
$activePage = 'sections';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>Sections & Masterlists</h1>
    <p class="text-muted">Review students per assigned section.</p>

    <?php if ($sections === []): ?>
        <section class="card"><p>No assigned sections found.</p></section>
    <?php else: ?>
        <?php foreach ($sections as $section): ?>
            <?php $sectionId = (int) $section['id']; $students = $studentsBySection[$sectionId] ?? []; ?>
            <section class="card">
                <h2>
                    <?= e((string) $section['program_code']) ?>
                    - Section <?= e((string) $section['name']) ?>
                    (Year <?= e((string) $section['year_level']) ?>, <?= e((string) $section['school_year']) ?>)
                </h2>
                <p><strong>Total Students:</strong> <?= e((string) count($students)) ?></p>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Student No</th>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($students === []): ?>
                            <tr><td colspan="3">No students in this section yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?= e((string) $student['student_no']) ?></td>
                                    <td><?= e($student['last_name'] . ', ' . $student['first_name']) ?></td>
                                    <td><?= e((string) $student['email']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
