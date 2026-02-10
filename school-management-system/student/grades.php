<?php
/**
 * Student grades and GPA page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
require_permission('view_grades');

$student = db_fetch_one('SELECT id FROM students WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$studentId = (int) ($student['id'] ?? 0);

$grades = [];
$lmsGrades = [];
$gpa = 0.0;
if ($studentId > 0) {
    $grades = db_fetch_all(
        'SELECT g.school_year, g.semester, g.prelim, g.midterm, g.finals, g.final_grade, g.remarks,
                sub.code AS subject_code, sub.title AS subject_title
         FROM grades g
         INNER JOIN subjects sub ON sub.id = g.subject_id
         WHERE g.student_id = :student_id
         ORDER BY g.school_year DESC, g.semester ASC, sub.code ASC',
        ['student_id' => $studentId]
    );
    $gpa = compute_gpa($grades);

    $lmsGrades = db_fetch_all(
        'SELECT lm.title AS module_title, ll.title AS lesson_title, ls.score, ls.feedback, ls.graded_at
         FROM lms_submissions ls
         INNER JOIN lms_lessons ll ON ll.id = ls.lesson_id
         INNER JOIN lms_modules lm ON lm.id = ll.module_id
         WHERE ls.student_id = :student_id AND ls.status = "graded"
         ORDER BY ls.graded_at DESC',
        ['student_id' => $studentId]
    );
}

$title = 'Grades & GPA';
$activePage = 'grades';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>Grades & GPA</h1>
    <p class="text-muted">View your academic and LMS performance.</p>

    <section class="card-grid">
        <article class="card stat-card">
            <h3>Current GPA</h3>
            <p><?= e(number_format($gpa, 2)) ?></p>
        </article>
        <article class="card stat-card">
            <h3>Total Graded Subjects</h3>
            <p><?= e((string) count($grades)) ?></p>
        </article>
        <article class="card stat-card">
            <h3>LMS Graded Submissions</h3>
            <p><?= e((string) count($lmsGrades)) ?></p>
        </article>
    </section>

    <section class="card">
        <h2>Academic Grades</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Subject</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($grades === []): ?>
                    <tr><td colspan="8">No grades available yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $row): ?>
                        <tr>
                            <td><?= e($row['subject_code'] . ' - ' . $row['subject_title']) ?></td>
                            <td><?= e((string) $row['school_year']) ?></td>
                            <td><?= e((string) ucfirst($row['semester'])) ?></td>
                            <td><?= e((string) ($row['prelim'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['midterm'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['finals'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['final_grade'] ?? '-')) ?></td>
                            <td><span class="badge"><?= e((string) $row['remarks']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>LMS Grades</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Module</th>
                    <th>Lesson</th>
                    <th>Score</th>
                    <th>Feedback</th>
                    <th>Graded At</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($lmsGrades === []): ?>
                    <tr><td colspan="5">No LMS grade records yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($lmsGrades as $row): ?>
                        <tr>
                            <td><?= e((string) $row['module_title']) ?></td>
                            <td><?= e((string) $row['lesson_title']) ?></td>
                            <td><?= e((string) ($row['score'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['feedback'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['graded_at'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
