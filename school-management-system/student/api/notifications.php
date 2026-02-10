<?php
/**
 * API endpoint: mark notifications as read.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('student');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

$input = get_json_input();
if ($input === []) {
    $input = $_POST;
}

$action = clean_input($input['action'] ?? '');
$userId = (int) current_user_id();
$db = get_db();

try {
    if ($action === 'mark_read') {
        $notificationId = (int) ($input['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            json_response(false, 'Notification id is required.', [], 422);
        }
        $db->prepare(
            'UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = :id AND user_id = :user_id'
        )->execute([
            'id' => $notificationId,
            'user_id' => $userId,
        ]);
        json_response(true, 'Notification marked as read.', ['notification_id' => $notificationId]);
    }

    if ($action === 'mark_all') {
        $db->prepare('UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
        json_response(true, 'All notifications marked as read.');
    }

    json_response(false, 'Unknown action.', [], 422);
} catch (Throwable $e) {
    error_log('student/api/notifications error: ' . $e->getMessage());
    json_response(false, 'Unable to update notification status.', [], 500);
}
