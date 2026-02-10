<?php
/**
 * Student profile page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');

$profile = db_fetch_one(
    'SELECT u.email, u.first_name, u.last_name, u.phone,
            s.student_no, s.year_level, s.address, s.guardian_name, s.guardian_phone,
            p.code AS program_code, p.name AS program_name
     FROM users u
     INNER JOIN students s ON s.user_id = u.id
     INNER JOIN programs p ON p.id = s.program_id
     WHERE u.id = :user_id
     LIMIT 1',
    ['user_id' => current_user_id()]
);

$title = 'My Profile';
$activePage = 'profile';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <h1>My Profile</h1>
    <p class="text-muted">View and update your student profile details.</p>

    <?php if (!$profile): ?>
        <section class="card"><p>Student profile not found.</p></section>
    <?php else: ?>
        <section class="card">
            <h2>Academic Details</h2>
            <div class="responsive-columns">
                <p><strong>Student No:</strong> <?= e((string) $profile['student_no']) ?></p>
                <p><strong>Program:</strong> <?= e((string) $profile['program_code'] . ' - ' . $profile['program_name']) ?></p>
                <p><strong>Year Level:</strong> <?= e((string) $profile['year_level']) ?></p>
                <p><strong>Email:</strong> <?= e((string) $profile['email']) ?></p>
            </div>
        </section>

        <section class="card">
            <h2>Personal Information</h2>
            <form id="profileForm" class="form-grid">
                <label>First Name
                    <input type="text" name="first_name" required value="<?= e((string) $profile['first_name']) ?>">
                </label>
                <label>Last Name
                    <input type="text" name="last_name" required value="<?= e((string) $profile['last_name']) ?>">
                </label>
                <label>Phone
                    <input type="text" name="phone" value="<?= e((string) ($profile['phone'] ?? '')) ?>">
                </label>
                <label>Address
                    <textarea name="address" rows="3"><?= e((string) ($profile['address'] ?? '')) ?></textarea>
                </label>
                <label>Guardian Name
                    <input type="text" name="guardian_name" value="<?= e((string) ($profile['guardian_name'] ?? '')) ?>">
                </label>
                <label>Guardian Phone
                    <input type="text" name="guardian_phone" value="<?= e((string) ($profile['guardian_phone'] ?? '')) ?>">
                </label>
                <button type="submit" class="btn btn-primary">Save Profile</button>
            </form>
        </section>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('profileForm');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        const response = await apiRequest('student/api/profile.php', {
            method: 'POST',
            body: payload
        });
        if (response.success) {
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update profile.', 'error');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
