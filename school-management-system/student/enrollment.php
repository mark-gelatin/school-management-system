<?php
/**
 * Student enrollment page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
require_permission('enroll_subjects');

$student = db_fetch_one(
    'SELECT s.id, s.program_id, p.code AS program_code, p.name AS program_name
     FROM students s
     INNER JOIN programs p ON p.id = s.program_id
     WHERE s.user_id = :user_id
     LIMIT 1',
    ['user_id' => current_user_id()]
);

$sections = [];
$subjects = [];
$enrollments = [];
if ($student) {
    $sections = db_fetch_all(
        'SELECT id, name, school_year, year_level
         FROM sections
         WHERE program_id = :program_id AND status = "active"
         ORDER BY school_year DESC, year_level ASC, name ASC',
        ['program_id' => $student['program_id']]
    );

    $subjects = db_fetch_all(
        'SELECT id, code, title, units, semester, year_level
         FROM subjects
         WHERE status = "active" AND (program_id = :program_id OR program_id IS NULL)
         ORDER BY year_level ASC, code ASC',
        ['program_id' => $student['program_id']]
    );

    $enrollments = db_fetch_all(
        'SELECT e.id, e.school_year, e.semester, e.status, e.submitted_at, e.remarks
         FROM enrollments e
         WHERE e.student_id = :student_id
         ORDER BY e.submitted_at DESC',
        ['student_id' => $student['id']]
    );
}

$title = 'Enrollment';
$activePage = 'enrollment';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>Enrollment</h1>
    <p class="text-muted">Submit your subject enrollment request for the current term.</p>

    <?php if (!$student): ?>
        <section class="card"><p>Student profile is not configured.</p></section>
    <?php else: ?>
        <section class="card">
            <h2>New Enrollment Request</h2>
            <p><strong>Program:</strong> <?= e((string) $student['program_code'] . ' - ' . $student['program_name']) ?></p>
            <form id="enrollmentForm" class="form-grid">
                <label>School Year
                    <input type="text" name="school_year" required placeholder="2026-2027" value="<?= e(date('Y') . '-' . (date('Y') + 1)) ?>">
                </label>
                <label>Semester
                    <select name="semester" required>
                        <option value="1st">1st Semester</option>
                        <option value="2nd">2nd Semester</option>
                        <option value="summer">Summer</option>
                    </select>
                </label>
                <label>Preferred Section
                    <select name="section_id">
                        <option value="">Auto-assigned</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= e((string) $section['id']) ?>">
                                <?= e($section['name'] . ' | Y' . $section['year_level'] . ' | ' . $section['school_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="full-width">
                    <label>Choose Subjects</label>
                    <div class="check-grid">
                        <?php foreach ($subjects as $subject): ?>
                            <label class="check-item">
                                <input type="checkbox" name="subjects[]" value="<?= e((string) $subject['id']) ?>">
                                <span><?= e($subject['code'] . ' - ' . $subject['title'] . ' (' . $subject['units'] . ' units)') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Submit Enrollment</button>
            </form>
        </section>

        <section class="card">
            <h2>Enrollment History</h2>
            <div class="table-wrap">
                <table id="enrollmentHistoryTable">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Remarks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($enrollments === []): ?>
                        <tr><td colspan="6">No enrollment records yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($enrollments as $row): ?>
                            <tr>
                                <td>#<?= e((string) $row['id']) ?></td>
                                <td><?= e((string) $row['school_year']) ?></td>
                                <td><?= e((string) ucfirst($row['semester'])) ?></td>
                                <td><span class="badge"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                                <td><?= e((string) $row['submitted_at']) ?></td>
                                <td><?= e((string) ($row['remarks'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('enrollmentForm');
    const historyBody = document.querySelector('#enrollmentHistoryTable tbody');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        const subjects = formData.getAll('subjects[]');
        const payload = {
            school_year: formData.get('school_year'),
            semester: formData.get('semester'),
            section_id: formData.get('section_id') || '',
            subjects
        };

        const response = await apiRequest('student/api/enrollment.php', {
            method: 'POST',
            body: payload
        });

        if (!response.success) {
            showToast(response.message || 'Enrollment submission failed.', 'error');
            return;
        }

        const enrollment = response.data.enrollment;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>#${enrollment.id}</td>
            <td>${enrollment.school_year}</td>
            <td>${enrollment.semester}</td>
            <td><span class="badge">${enrollment.status.charAt(0).toUpperCase() + enrollment.status.slice(1)}</span></td>
            <td>Just now</td>
            <td>-</td>
        `;
        if (historyBody.querySelector('td[colspan="6"]')) {
            historyBody.innerHTML = '';
        }
        historyBody.prepend(tr);
        form.reset();
        showToast(response.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
