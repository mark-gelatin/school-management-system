<?php
/**
 * Admin reports and analytics page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_permission('view_reports');

$enrollmentByStatus = db_fetch_all(
    'SELECT status, COUNT(*) AS total
     FROM enrollments
     GROUP BY status
     ORDER BY status'
);
$gradeSummary = db_fetch_all(
    'SELECT remarks, COUNT(*) AS total
     FROM grades
     GROUP BY remarks
     ORDER BY remarks'
);
$programPopulation = db_fetch_all(
    'SELECT p.code, p.name, COUNT(s.id) AS total_students
     FROM programs p
     LEFT JOIN students s ON s.program_id = p.id
     GROUP BY p.id
     ORDER BY total_students DESC, p.code ASC'
);

$title = 'Reports';
$activePage = 'reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="content-area">
    <h1>Reports</h1>
    <p class="text-muted">Enrollment, grading, and program population analytics.</p>

    <section class="card-grid">
        <article class="card">
            <h2>Enrollment by Status</h2>
            <ul class="list-clean">
                <?php foreach ($enrollmentByStatus as $row): ?>
                    <li>
                        <strong><?= e(ucfirst((string) $row['status'])) ?>:</strong>
                        <?= e((string) $row['total']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($enrollmentByStatus === []): ?>
                    <li>No data available.</li>
                <?php endif; ?>
            </ul>
        </article>

        <article class="card">
            <h2>Grade Outcome Summary</h2>
            <ul class="list-clean">
                <?php foreach ($gradeSummary as $row): ?>
                    <li>
                        <strong><?= e((string) $row['remarks']) ?>:</strong>
                        <?= e((string) $row['total']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($gradeSummary === []): ?>
                    <li>No data available.</li>
                <?php endif; ?>
            </ul>
        </article>
    </section>

    <section class="card">
        <h2>Program Population</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Program Code</th>
                    <th>Program Name</th>
                    <th>Total Students</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($programPopulation as $row): ?>
                    <tr>
                        <td><?= e((string) $row['code']) ?></td>
                        <td><?= e((string) $row['name']) ?></td>
                        <td><?= e((string) $row['total_students']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($programPopulation === []): ?>
                    <tr><td colspan="3">No program data available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
