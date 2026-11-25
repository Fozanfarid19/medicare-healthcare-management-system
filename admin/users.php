<?php
/**
 * MediCare - admin/users.php
 * Every login account (admin / doctor / patient) in one screen: search,
 * filter, create new administrators, reset passwords, activate /
 * deactivate and delete accounts that have no historical records.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling (all writes happen here, then redirect) ----------
if (is_post()) {
    verify_csrf('admin/users.php');
    $action = post('action');

    if ($action === 'create_admin') {
        $email    = strtolower(post('email'));
        $password = post_raw('password');
        $confirm  = post_raw('confirm_password');

        $errors = [];
        if (!is_valid_email($email))                 { $errors[] = 'Please enter a valid e-mail address.'; }
        $pwProblem = password_problem($password);
        if ($pwProblem)                               { $errors[] = $pwProblem; }
        if ($password !== $confirm)                   { $errors[] = 'The two passwords do not match.'; }
        if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'This email address is already registered.';
        }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
            $roleId = (int) db_scalar("SELECT id FROM roles WHERE role_name = 'admin'");
            db_insert(
                'INSERT INTO users (role_id, email, password, status) VALUES (?, ?, ?, ?)',
                [$roleId, $email, hash_password($password), 'active']
            );
            flash_success('Administrator account created successfully.');
        }
    } elseif ($action === 'toggle_status') {
        $userId = post_int('user_id');

        if ($userId === current_user_id()) {
            flash_error('You cannot deactivate your own account.');
        } else {
            $user = db_row('SELECT id, status FROM users WHERE id = ?', [$userId]);
            if (!$user) {
                flash_error('User not found.');
            } else {
                $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
                db_execute('UPDATE users SET status = ? WHERE id = ?', [$newStatus, $userId]);
                flash_success('Account ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully.');
            }
        }
    } elseif ($action === 'reset_password') {
        $userId   = post_int('user_id');
        $password = post_raw('new_password');
        $confirm  = post_raw('confirm_new_password');

        $errors = [];
        if (!db_exists('SELECT 1 FROM users WHERE id = ?', [$userId])) { $errors[] = 'User not found.'; }
        $pwProblem = password_problem($password);
        if ($pwProblem)                { $errors[] = $pwProblem; }
        if ($password !== $confirm)    { $errors[] = 'The two passwords do not match.'; }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
            db_execute('UPDATE users SET password = ? WHERE id = ?', [hash_password($password), $userId]);
            flash_success('Password reset successfully.');
        }
    } elseif ($action === 'delete') {
        $userId = post_int('user_id');

        if ($userId === current_user_id()) {
            flash_error('You cannot delete your own account.');
        } else {
            $user = db_row(
                "SELECT u.id, r.role_name,
                        d.id AS doctor_id, p.id AS patient_id
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                   LEFT JOIN doctors  d ON d.user_id = u.id
                   LEFT JOIN patients p ON p.user_id = u.id
                  WHERE u.id = ?",
                [$userId]
            );

            if (!$user) {
                flash_error('User not found.');
            } else {
                // Check for historical records BEFORE attempting the delete,
                // because appointments/medicines/prescriptions use ON DELETE
                // RESTRICT against doctors/patients.
                $hasHistory = false;
                if ($user['doctor_id']) {
                    $hasHistory = db_exists('SELECT 1 FROM appointments WHERE doctor_id = ?', [(int) $user['doctor_id']])
                               || db_exists('SELECT 1 FROM medicines WHERE prescribed_by = ?', [(int) $user['doctor_id']])
                               || db_exists('SELECT 1 FROM prescriptions WHERE doctor_id = ?', [(int) $user['doctor_id']]);
                } elseif ($user['patient_id']) {
                    $hasHistory = db_exists('SELECT 1 FROM appointments WHERE patient_id = ?', [(int) $user['patient_id']])
                               || db_exists('SELECT 1 FROM medicines WHERE patient_id = ?', [(int) $user['patient_id']])
                               || db_exists('SELECT 1 FROM prescriptions WHERE patient_id = ?', [(int) $user['patient_id']]);
                }

                if ($hasHistory) {
                    flash_error('This user has historical records and cannot be deleted. Deactivate the account instead.');
                } else {
                    try {
                        db_execute('DELETE FROM users WHERE id = ?', [$userId]);
                        flash_success('User deleted successfully.');
                    } catch (Throwable $e) {
                        error_log('[MediCare users] ' . $e->getMessage());
                        flash_error('This user has historical records and cannot be deleted. Deactivate the account instead.');
                    }
                }
            }
        }
    }

    redirect('admin/users.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ---------- 2. Read filters + query data ----------
$search       = get_param('q');
$roleFilter   = get_param('role');
$statusFilter = get_param('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(u.email LIKE ? OR d.first_name LIKE ? OR d.last_name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($roleFilter !== '' && in_array($roleFilter, ['admin', 'doctor', 'patient'], true)) {
    $where[]  = 'r.role_name = ?';
    $params[] = $roleFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[]  = 'u.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseFrom = "FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN doctors  d ON d.user_id = u.id
             LEFT JOIN patients p ON p.user_id = u.id";

$total = (int) db_scalar("SELECT COUNT(*) $baseFrom $whereSql", $params);
$pg    = paginate($total);

$rows = db_all(
    "SELECT u.id, u.email, u.status, u.last_login, u.created_at, r.role_name,
            d.id AS doctor_id, p.id AS patient_id,
            COALESCE(CONCAT(d.first_name,' ',d.last_name), CONCAT(p.first_name,' ',p.last_name), 'Administrator') AS display_name
     $baseFrom
     $whereSql
     ORDER BY u.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$page_title = 'User Management';
$active     = 'users';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Users</div>
        <h1>User Management</h1>
        <div class="sub">All administrator, doctor and patient login accounts.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal"><?= icon('plus') ?> Add Administrator</button>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Name or email">
        </div>
        <div>
            <label class="form-label">Role</label>
            <select class="form-select auto-submit" name="role">
                <option value="">All Roles</option>
                <option value="admin"   <?= $roleFilter === 'admin'   ? 'selected' : '' ?>>Administrator</option>
                <option value="doctor"  <?= $roleFilter === 'doctor'  ? 'selected' : '' ?>>Doctor</option>
                <option value="patient" <?= $roleFilter === 'patient' ? 'selected' : '' ?>>Patient</option>
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select auto-submit" name="status">
                <option value="">All Status</option>
                <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div>
            <button class="btn btn-primary"><?= icon('search') ?> Search</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/users.php')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('search') ?><div>No users found.</div></div></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e($r['display_name']) ?></td>
                    <td><?= e($r['email']) ?></td>
                    <td><span class="badge bg-secondary"><?= e(nice_label($r['role_name'])) ?></span></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= $r['last_login'] ? e(format_datetime($r['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="text-end">
                        <?php if ($r['role_name'] === 'doctor' && $r['doctor_id']): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/doctor_view.php?id=' . (int) $r['doctor_id'])) ?>"><?= icon('eye') ?></a>
                        <?php elseif ($r['role_name'] === 'patient' && $r['patient_id']): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/patient_view.php?id=' . (int) $r['patient_id'])) ?>"><?= icon('eye') ?></a>
                        <?php endif; ?>

                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#resetPwModal"
                                data-user-id="<?= (int) $r['id'] ?>"
                                data-user-name="<?= e($r['display_name']) ?>"><?= icon('lock') ?></button>

                        <?php if ((int) $r['id'] !== current_user_id()): ?>
                            <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this account?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $r['status'] === 'active' ? 'danger' : 'success' ?>">
                                    <?= $r['status'] === 'active' ? icon('close') : icon('check') ?>
                                </button>
                            </form>
                            <form method="post" class="d-inline" data-confirm="Delete this user permanently? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'role' => $roleFilter, 'status' => $statusFilter]); ?></div>
</div>

<!-- Add Administrator modal -->
<div class="modal" id="addAdminModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create_admin">
   <div class="modal-header"><h5 class="modal-title">Add Administrator</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-3">
       <label class="form-label">E-mail Address</label>
       <input type="email" class="form-control" name="email" required maxlength="120">
     </div>
     <div class="mb-3">
       <label class="form-label">Password</label>
       <input type="password" class="form-control" name="password" required>
       <span class="form-text">Minimum 6 characters, with at least one letter and one number.</span>
     </div>
     <div class="mb-3">
       <label class="form-label">Confirm Password</label>
       <input type="password" class="form-control" name="confirm_password" required>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Reset Password modal (shared by every row, filled via data-autofill) -->
<div class="modal" id="resetPwModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
   <input type="hidden" name="user_id" data-fill="userId">
   <div class="modal-header"><h5 class="modal-title">Reset Password for <span data-fill="userName"></span></h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-3">
       <label class="form-label">New Password</label>
       <input type="password" class="form-control" name="new_password" required>
     </div>
     <div class="mb-3">
       <label class="form-label">Confirm New Password</label>
       <input type="password" class="form-control" name="confirm_new_password" required>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Reset Password</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
