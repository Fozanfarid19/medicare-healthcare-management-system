<?php
/**
 * =====================================================================
 *  MediCare - api/notifications.php
 *  In-app notification centre.
 *
 *  GET  ?action=list          -> JSON: unread count + latest notifications
 *  GET  ?action=open&id=12    -> marks one as read and redirects to its link
 *  POST action=read_all       -> marks every notification as read
 * =====================================================================
 */
require_once __DIR__ . '/../includes/auth.php';

require_login();

$userId = current_user_id();
$action = is_post() ? post('action') : get_param('action', 'list');

// ---------------------------------------------------------------------
// POST - mark all as read
// ---------------------------------------------------------------------
if (is_post()) {
    $wantsRedirect = post('redirect') === '1';

    if ($wantsRedirect) {
        verify_csrf(dashboard_url());
    } else {
        verify_csrf_api();
    }

    if ($action === 'read_all') {
        $changed = db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);

        if ($wantsRedirect) {
            flash_success($changed . ' notification(s) marked as read.');
            $back = $_SERVER['HTTP_REFERER'] ?? url(dashboard_url());
            header('Location: ' . $back);
            exit;
        }
        json_response(['success' => true, 'message' => 'All notifications marked as read.', 'unread' => 0]);
    }

    if ($action === 'delete') {
        db_execute('DELETE FROM notifications WHERE id = ? AND user_id = ?', [post_int('id'), $userId]);
        if ($wantsRedirect) {
            flash_success('Notification deleted.');
            $back = $_SERVER['HTTP_REFERER'] ?? url(dashboard_url());
            header('Location: ' . $back);
            exit;
        }
        json_response(['success' => true, 'message' => 'Notification deleted.']);
    }

    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

// ---------------------------------------------------------------------
// GET ?action=open  - mark one as read, then go to its target page
// ---------------------------------------------------------------------
if ($action === 'open') {
    $id  = get_int('id');
    $row = db_row('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);

    if (!$row) {
        flash_error('Notification not found.');
        redirect(dashboard_url());
    }

    db_execute('UPDATE notifications SET is_read = 1 WHERE id = ?', [$id]);
    redirect($row['link'] ?: dashboard_url());
}

// ---------------------------------------------------------------------
// GET ?action=list  - JSON for the navigation bar
// ---------------------------------------------------------------------
$rows = recent_notifications($userId, 10);
$list = [];

foreach ($rows as $r) {
    $list[] = [
        'id'      => (int) $r['id'],
        'title'   => $r['title'],
        'message' => $r['message'],
        'type'    => $r['notification_type'],
        'is_read' => (int) $r['is_read'],
        'link'    => $r['link'] ? url($r['link']) : '',
        'time'    => format_datetime($r['created_at']),
    ];
}

json_response([
    'success'       => true,
    'unread'        => unread_notification_count($userId),
    'notifications' => $list,
]);
