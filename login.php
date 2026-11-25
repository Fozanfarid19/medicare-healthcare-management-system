<?php
/**
 * MediCare - login.php
 * Single login page for all three roles (admin / doctor / patient).
 */
require_once __DIR__ . '/includes/auth.php';

// Already signed in? go straight to the right dashboard.
if (is_logged_in()) {
    redirect(dashboard_url());
}

$email = '';
$role  = '';

if (is_post()) {
    verify_csrf('login.php');

    $email    = post('email');
    $password = post_raw('password');
    $role     = post('role');

    if ($email === '' || $password === '') {
        flash_error('Please enter both your e-mail address and password.');
    } elseif (!is_valid_email($email)) {
        flash_error('Please enter a valid e-mail address.');
    } else {
        $result = attempt_login($email, $password, $role);

        if ($result['ok']) {
            flash_success($result['message']);
            redirect(dashboard_url($result['role']));
        }
        flash_error($result['message']);
    }

    keep_old(['email' => $email, 'role' => $role]);
    redirect('login.php');
}

$email  = old('email');
$role   = old('role');
clear_old();

$page_title = 'Sign In';
$layout     = 'auth';
include __DIR__ . '/includes/header.php';
?>

<div class="auth-card">
    <div class="auth-head">
        <div class="mc-logo">M+</div>
        <h1 class="fs-3 mb-1"><?= e(APP_NAME) ?></h1>
        <p class="text-muted mb-0"><?= e(APP_TAGLINE) ?></p>
    </div>

    <?php render_flash(); ?>

    <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label" for="role">Login As</label>
            <select class="form-select" id="role" name="role" required>
                <option value="">-- Select your role --</option>
                <option value="admin"   <?= $role === 'admin'   ? 'selected' : '' ?>>Administrator</option>
                <option value="doctor"  <?= $role === 'doctor'  ? 'selected' : '' ?>>Doctor</option>
                <option value="patient" <?= $role === 'patient' ? 'selected' : '' ?>>Patient</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">E-mail Address</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= e($email) ?>" placeholder="you@example.com" required autofocus>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Your password" required>
                <button class="btn btn-outline-secondary" type="button"
                        data-toggle-password="#password">Show</button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 btn-lg"><?= icon('lock') ?> Sign In</button>
    </form>

    <p class="text-center mt-3 mb-0">
        New patient? <a href="<?= e(url('register.php')) ?>">Create an account</a><br>
        <a class="small text-muted" href="<?= e(url('index.php')) ?>">&larr; Back to home page</a>
    </p>

    <div class="demo-box">
        <b>Demo accounts for the project demonstration</b>
        <div class="row-line"><span>Administrator</span><span>admin@medicare.local / Admin@123</span></div>
        <div class="row-line"><span>Doctor</span><span>doctor@medicare.local / Doctor@123</span></div>
        <div class="row-line"><span>Patient</span><span>patient@medicare.local / Patient@123</span></div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
