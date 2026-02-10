<?php
/**
 * Faculty grade encoding page (AJAX).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('faculty');
require_permission('encode_grades');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$rows = [];
if ($facultyId > 0) {
    $rows = db_fetch_all(
        'SELECT
            ss.section_id,
            ss.subject_id,
            sec.school_year,
            sec.name AS section_name,
            sub.code AS subject_code,
            sub.title AS subject_title,
            s.id AS student_id,
            s.student_no,
            u.first_name,
            u.last_name,
            g.prelim,
            g.midterm,
            g.finals,
            g.final_grade,
            g.remarks
         FROM section_subjects ss
         INNER JOIN sections sec ON sec.id = ss.section_id
         INNER JOIN subjects sub ON sub.id = ss.subject_id
         LEFT JOIN students s ON s.section_id = ss.section_id
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN grades g
            ON g.student_id = s.id
           AND g.subject_id = ss.subject_id
           AND g.faculty_id = ss.faculty_id
           AND g.school_year = sec.school_year
         WHERE ss.faculty_id = :faculty_id
         ORDER BY sec.school_year DESC, sec.name ASC, sub.code ASC, u.last_name ASC'
    , ['faculty_id' => $facultyId]);
}

$title = 'Grade Encoding';
$activePage = 'grade_encoding';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>Grade Encoding</h1>
    <p class="text-muted">Encode and update grades without page reload.</p>

    <section class="card">
        <div class="table-wrap">
            <table id="gradeTable">
                <thead>
                <tr>
                    <th>Section</th>
                    <th>Subject</th>
                    <th>Student</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11">No students available for grade encoding.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php if (empty($row['student_id'])): continue; endif; ?>
                        <tr
                            data-student-id="<?= e((string) $row['student_id']) ?>"
                            data-subject-id="<?= e((string) $row['subject_id']) ?>"
                            data-section-id="<?= e((string) $row['section_id']) ?>">
                            <td><?= e($row['section_name']) ?></td>
                            <td><?= e($row['subject_code'] . ' - ' . $row['subject_title']) ?></td>
                            <td><?= e($row['student_no'] . ' - ' . $row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><input type="text" class="school-year-input" value="<?= e((string) $row['school_year']) ?>"></td>
                            <td>
                                <select class="semester-input">
                                    <option value="1st">1st</option>
                                    <option value="2nd">2nd</option>
                                    <option value="summer">Summer</option>
                                </select>
                            </td>
                            <td><input type="number" step="0.01" min="0" max="100" class="grade-input prelim-input" value="<?= e((string) ($row['prelim'] ?? '')) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" class="grade-input midterm-input" value="<?= e((string) ($row['midterm'] ?? '')) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" class="grade-input finals-input" value="<?= e((string) ($row['finals'] ?? '')) ?>"></td>
                            <td class="final-grade-text"><?= e((string) ($row['final_grade'] ?? '-')) ?></td>
                            <td class="remarks-text"><?= e((string) ($row['remarks'] ?? 'INCOMPLETE')) ?></td>
                            <td><button type="button" class="btn btn-sm btn-primary save-grade-btn">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.querySelector('#gradeTable tbody');

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.save-grade-btn');
        if (!button) return;

        const row = button.closest('tr');
        const payload = {
            student_id: row.dataset.studentId,
            subject_id: row.dataset.subjectId,
            section_id: row.dataset.sectionId,
            school_year: row.querySelector('.school-year-input').value,
            semester: row.querySelector('.semester-input').value,
            prelim: row.querySelector('.prelim-input').value,
            midterm: row.querySelector('.midterm-input').value,
            finals: row.querySelector('.finals-input').value
        };

        button.disabled = true;
        const response = await apiRequest('faculty/api/save_grade.php', {
            method: 'POST',
            body: payload
        });
        button.disabled = false;

        if (!response.success) {
            showToast(response.message || 'Unable to save grade.', 'error');
            return;
        }

        row.querySelector('.final-grade-text').textContent = response.data.grade.final_grade ?? '-';
        row.querySelector('.remarks-text').textContent = response.data.grade.remarks;
        showToast(response.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
