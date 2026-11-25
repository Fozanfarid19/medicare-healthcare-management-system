<?php
/**
 * MediCare - admin/notifications.php
 * The administrator's own notification centre, plus a broadcast tool
 * to send a notification to all patients, all doctors, or one user.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('admin/notifications.php');
    $action = post('action');

    if ($action === 'mark_read') {
        $id = post_int('id');
        db_execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, current_user_id()]);
        flash_success('Notification marked as read.');
    } elseif ($action === 'read_all') {
        db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [current_user_id()]);
        flash_success('All notifications marked as read.');
    } elseif ($action === 'delete') {
        $id = post_int('id');
        db_execute('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, current_user_id()]);
        flash_success('Notification deleted.');
    } elseif ($action === 'broadcast') {
        $target  = post('target');
        $title   = post('title');
        $message = post('message');
        $userId  = post_int('user_id');

        if ($title === '' || $message === '') {
            flash_error('Please enter a title and a message.');
        } elseif (!in_array($target, ['all_patients', 'all_doctors', 'single'], true)) {
            flash_error('Please choose a valid recipient.');
        } else {
            $recipients = [];
            if ($target === 'all_patients') {
                $recipients = db_all("SELECT u.id FROM users u JOIN patients p ON p.user_id = u.id WHERE u.status = 'active'");
            } elseif ($target === 'all_doctors') {
                $recipients = db_all("SELECT u.id FROM users u JOIN doctors d ON d.user_id = u.id WHERE u.status = 'active'");
            } elseif ($target === 'single' && $userId) {
                $recipients = db_all("SELECT id FROM users WHERE id = ? AND status = 'active'", [$userId]);
            }

            if (!$recipients) {
                flash_error('No matching recipients were found.');
            } else {
                $count = 0;
                foreach ($recipients as $rec) {
                    notify((int) $rec['id'], $title, $message, 'system');
                    $count++;
                }
                flash_success('Notification sent to ' . $count . ' user(s).');
            }
        }
    }

    redirect('admin/notifications.php');
}

// ---------- 2. Read filters + query data ----------
$filter = get_param('filter', 'all');
if (!in_array($filter, ['all', 'unread'], true)) { $filter = 'all'; }

$where  = ['user_id = ?']; $params = [current_user_id()];
if ($filter === 'unread') { $where[] = 'is_read = 0'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_scalar("SELECT COUNT(*) FROM notifications $whereSql", $params);
$pg    = paginate($total);
$rows  = db_all(
    "SELECT * FROM notifications $whereSql ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// ---------- Drop-down data for the broadcast card ----------
$allUsers = db_all(
    "SELECT u.id, u.email, r.role_name FROM users u
       JOIN roles r ON r.id = u.role_id
      WHERE u.status = 'active'
      ORDER BY r.role_name, u.email"
);

// ---------- 3. Render ----------
$page_title = 'Notifications';
$active     = 'notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Notifications</div>
    <h1>Notifications</h1>
    <div class="sub">Your own notification centre and a tool to broadcast messages.</div>
  </div>
  <div class="d-flex gap-2">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="read_all">
      <button class="btn btn-outline-secondary"><?= icon('check') ?> Mark All as Read</button>
    </form>
  </div>
</div>

<div class="table-card">
  <form class="filter-bar" method="get">
    <div>
      <label class="form-label">Show</label>
      <select class="form-select auto-submit" name="filter">
        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread Only</option>
      </select>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Title</th><th>Message</th><th>Type</th><th>Received</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="empty"><?= icon('bell') ?><div>No notifications found.</div></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr class="<?= $r['is_read'] ? '' : 'fw-semibold' ?>">
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['message']) ?></td>
          <td><?= e(nice_label($r['notification_type'])) ?></td>
          <td><?= e(format_datetime($r['created_at'])) ?></td>
          <td><?= $r['is_read'] ? '<span class="badge bg-secondary">Read</span>' : '<span class="badge bg-warning">Unread</span>' ?></td>
          <td class="text-end">
            <?php if (!$r['is_read']): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary"><?= icon('check') ?></button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline" data-confirm="Delete this notification?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><?= icon('trash') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="px-3 pb-3"><?php render_pagination($pg, ['filter' => $filter]); ?></div>
</div>

<div class="table-card mt-4">
  <div class="table-card-head"><h2>Send Notification</h2></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="broadcast">
      <div class="row">
        <div class="col-md-4 mb-2">
          <label class="form-label">Send To</label>
          <select class="form-select" name="target" id="broadcastTarget" required>
            <option value="all_patients">All Patients</option>
            <option value="all_doctors">All Doctors</option>
            <option value="single">A Single User</option>
          </select>
        </div>
        <div class="col-md-4 mb-2" id="singleUserBox">
          <label class="form-label">User</label>
          <select class="form-select" name="user_id">
            <option value="">-- Select a user --</option>
            <?php foreach ($allUsers as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e(nice_label($u['role_name'])) ?> - <?= e($u['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label">Title</label>
        <input class="form-control" name="title" maxlength="120" required>
      </div>
      <div class="mb-2">
        <label class="form-label">Message</label>
        <textarea class="form-control" name="message" rows="3" maxlength="255" required></textarea>
      </div>
      <button class="btn btn-primary"><?= icon('bell') ?> Send Notification</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var target = document.getElementById('broadcastTarget');
  var box    = document.getElementById('singleUserBox');
  function toggle() { box.style.display = (target.value === 'single') ? '' : 'none'; }
  if (target && box) { target.addEventListener('change', toggle); toggle(); }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
