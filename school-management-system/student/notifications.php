<?php
/**
 * Student notifications page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');

$notifications = db_fetch_all(
    'SELECT id, title, message, link_url, type, is_read, created_at
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY created_at DESC',
    ['user_id' => current_user_id()]
);

$title = 'Notifications';
$activePage = 'notifications';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar_student.php';
?>
<main class="content-area">
    <div class="page-header-with-action">
        <div>
            <h1>Notifications</h1>
            <p class="text-muted">Stay updated with enrollment, grading, and LMS alerts.</p>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="markAllReadBtn">Mark All as Read</button>
    </div>

    <section class="card">
        <div id="notificationList" class="stack-list">
            <?php if ($notifications === []): ?>
                <p>No notifications available.</p>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $rawLink = (string) ($notification['link_url'] ?? '');
                    $linkUrl = '';
                    if ($rawLink !== '') {
                        if (str_starts_with($rawLink, 'http://') || str_starts_with($rawLink, 'https://') || str_starts_with($rawLink, app_base_path() . '/')) {
                            $linkUrl = $rawLink;
                        } else {
                            $linkUrl = app_url(ltrim($rawLink, '/'));
                        }
                    }
                    ?>
                    <article class="stack-item notification-item <?= (int) $notification['is_read'] === 1 ? 'read' : 'unread' ?>"
                             data-notification-id="<?= e((string) $notification['id']) ?>">
                        <div class="notification-main">
                            <h3><?= e((string) $notification['title']) ?></h3>
                            <p><?= e((string) $notification['message']) ?></p>
                            <small><?= e((string) $notification['created_at']) ?> | <?= e((string) ucfirst($notification['type'])) ?></small>
                            <?php if ($linkUrl !== ''): ?>
                                <div><a class="link-btn" href="<?= e($linkUrl) ?>">Open</a></div>
                            <?php endif; ?>
                        </div>
                        <?php if ((int) $notification['is_read'] === 0): ?>
                            <button type="button" class="btn btn-sm btn-primary mark-read-btn">Mark Read</button>
                        <?php else: ?>
                            <span class="badge">Read</span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('notificationList');
    const markAllBtn = document.getElementById('markAllReadBtn');

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('.mark-read-btn');
        if (!button) return;
        const item = button.closest('.notification-item');
        const notificationId = item.dataset.notificationId;

        const response = await apiRequest('student/api/notifications.php', {
            method: 'POST',
            body: { action: 'mark_read', notification_id: notificationId }
        });
        if (response.success) {
            item.classList.remove('unread');
            item.classList.add('read');
            button.replaceWith(Object.assign(document.createElement('span'), {
                className: 'badge',
                textContent: 'Read'
            }));
            showToast(response.message, 'success');
        } else {
            showToast(response.message || 'Unable to update notification.', 'error');
        }
    });

    markAllBtn.addEventListener('click', async () => {
        const response = await apiRequest('student/api/notifications.php', {
            method: 'POST',
            body: { action: 'mark_all' }
        });
        if (!response.success) {
            showToast(response.message || 'Unable to mark all notifications.', 'error');
            return;
        }

        list.querySelectorAll('.notification-item').forEach((item) => {
            item.classList.remove('unread');
            item.classList.add('read');
            const button = item.querySelector('.mark-read-btn');
            if (button) {
                button.replaceWith(Object.assign(document.createElement('span'), {
                    className: 'badge',
                    textContent: 'Read'
                }));
            }
        });
        showToast(response.message, 'success');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
