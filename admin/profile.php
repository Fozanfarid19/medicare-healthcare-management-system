<?php
/**
 * MediCare - admin/profile.php
 * The signed-in administrator's own account: change e-mail or password.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling (all writes happen here, then redirect) ----------
if (is_post()) {
    verify_csrf('admin/profile.php');
    $action = post('action');

    if ($action === 'update_email') {
        $email = strtolower(post('email'));

        if (!is_valid_email($email)) {
            flash_error('Please enter a valid e-mail address.');
        } elseif (db_exists('SELECT 1 FROM users WHERE email = ? AND id != ?', [$email, current_user_id()])) {
            flash_error('This email address is already registered to another account.');
        } else {
            db_execute('UPDATE users SET email = ? WHERE id = ?', [$email, current_user_id()]);
            $_SESSION['user']['email'] = $email;
            flash_success('E-mail address updated successfully.');
        }
    } elseif ($action === 'change_password') {
        $current = post_raw('current_password');
        $new     = post_raw('new_password');
        $confirm = post_raw('confirm_password');

        $user = db_row('SELECT password FROM users WHERE id = ?', [current_user_id()]);

        if (!$user || !password_verify($current, $user['password'])) {
            flash_error('Your current password is incorrect.');
        } else {
            $pwProblem = password_problem($new);
            if ($pwProblem) {
                flash_error($pwProblem);
            } elseif ($new !== $confirm) {
                flash_error('The two new passwords do not match.');
            } else {
                db_execute('UPDATE users SET password = ? WHERE id = ?', [hash_password($new), current_user_id()]);
                flash_success('Password changed successfully.');
            }
        }
    }

    redirect('admin/profile.php');
}

// ---------- 2. Read current account ----------
$account = db_row(
    "SELECT u.email, u.last_login, u.created_at, r.role_name
       FROM users u
       JOIN roles r ON r.id = u.role_id
      WHERE u.id = ?",
    [current_user_id()]
);

$page_title = 'My Profile';
$active     = '';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / My Profile</div>
        <h1>My Profile</h1>
        <div class="sub">Manage your administrator account.</div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <div class="profile-head">
        <div class="profile-avatar"><?= e(strtoupper(substr($account['email'], 0, 1))) ?></div>
        <div>
            <h4 class="mb-1">Administrator</h4>
            <div class="text-muted"><?= e($account['email']) ?></div>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-md-6">
            <div class="kv"><span>Role</span><span><?= e(nice_label($account['role_name'])) ?></span></div>
            <div class="kv"><span>Last Login</span><span><?= $account['last_login'] ? e(format_datetime($account['last_login'])) : 'Never' ?></span></div>
        </div>
        <div class="col-md-6">
            <div class="kv"><span>Member Since</span><span><?= e(format_datetime($account['created_at'])) ?></span></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Update E-mail Address</h6>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_email">
                <div class="mb-3">
                    <label class="form-label">E-mail Address</label>
                    <input type="email" class="form-control" name="email" value="<?= e($account['email']) ?>" required maxlength="120">
                </div>
                <button class="btn btn-primary"><?= icon('check') ?> Save E-mail</button>
            </form>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Change Password</h6>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                    <span class="form-text">Minimum 6 characters, with at least one letter and one number.</span>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                </div>
                <button class="btn btn-primary"><?= icon('lock') ?> Change Password</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
