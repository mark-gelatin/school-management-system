<?php
/**
 * Faculty attendance management page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('faculty');
require_permission('manage_attendance');

$faculty = db_fetch_one('SELECT id FROM faculty WHERE user_id = :user_id LIMIT 1', ['user_id' => current_user_id()]);
$facultyId = (int) ($faculty['id'] ?? 0);

$rows = [];
if ($facultyId > 0) {
    $rows = db_fetch_all(
        'SELECT
            ss.section_id,
            ss.subject_id,
            sec.name AS section_name,
            sec.school_year,
            sub.code AS subject_code,
            sub.title AS subject_title,
            s.id AS student_id,
            s.student_no,
            u.first_name,
            u.last_name
         FROM section_subjects ss
         INNER JOIN sections sec ON sec.id = ss.section_id
         INNER JOIN subjects sub ON sub.id = ss.subject_id
         LEFT JOIN students s ON s.section_id = ss.section_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE ss.faculty_id = :faculty_id
         ORDER BY sec.school_year DESC, sec.name ASC, sub.code ASC, u.last_name ASC'
    , ['faculty_id' => $facultyId]);
}

$title = 'Attendance';
$activePage = 'attendance';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_faculty.php';
?>
<main class="content-area">
    <h1>Attendance</h1>
    <p class="text-muted">Record attendance for students in your assigned classes.</p>

    <section class="card">
        <div class="table-wrap">
            <table id="attendanceTable">
                <thead>
                <tr>
                    <th>Section</th>
                    <th>Subject</th>
                    <th>Student</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7">No class records available.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php if (empty($row['student_id'])): continue; endif; ?>
                        <tr data-student-id="<?= e((string) $row['student_id']) ?>"
                            data-subject-id="<?= e((string) $row['subject_id']) ?>"
                            data-section-id="<?= e((string) $row['section_id']) ?>">
                            <td><?= e($row['section_name'] . ' (' . $row['school_year'] . ')') ?></td>
                            <td><?= e($row['subject_code'] . ' - ' . $row['subject_title']) ?></td>
                            <td><?= e($row['student_no'] . ' - ' . $row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><input type="date" class="attendance-date-input" value="<?= e(date('Y-m-d')) ?>"></td>
                            <td>
                                <select class="attendance-status-input">
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                    <option value="late">Late</option>
                                    <option value="excused">Excused</option>
                                </select>
                            </td>
                            <td><input type="text" class="attendance-remarks-input" placeholder="Optional remarks"></td>
                            <td><button type="button" class="btn btn-sm btn-primary save-attendance-btn">Save</button></td>
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
    const tbody = document.querySelector('#attendanceTable tbody');

    tbody.addEventListener('click', async (event) => {
        const button = event.target.closest('.save-attendance-btn');
        if (!button) return;
        const row = button.closest('tr');

        const payload = {
            student_id: row.dataset.studentId,
            subject_id: row.dataset.subjectId,
            section_id: row.dataset.sectionId,
            attendance_date: row.querySelector('.attendance-date-input').value,
            status: row.querySelector('.attendance-status-input').value,
            remarks: row.querySelector('.attendance-remarks-input').value
        };

        button.disabled = true;
        const response = await apiRequest('faculty/api/attendance.php', {
            method: 'POST',
            body: payload
        });
        button.disabled = false;
        if (response.success) {
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to save attendance.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
