<?php
/**
 * MediCare - register.php
 * Public patient self-registration.
 * Creates one row in `users` (role = patient) and one row in `patients`
 * inside a single transaction, with the password stored as a BCRYPT hash.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_url());
}

if (is_post()) {
    verify_csrf('register.php');

    $data = [
        'first_name'  => post('first_name'),
        'last_name'   => post('last_name'),
        'email'       => strtolower(post('email')),
        'phone'       => post('phone'),
        'gender'      => post('gender'),
        'dob'         => post('date_of_birth'),
        'blood_group' => post('blood_group', 'Unknown'),
        'address'     => post('address'),
        'ec_name'     => post('emergency_contact_name'),
        'ec_phone'    => post('emergency_contact_phone'),
    ];
    $password = post_raw('password');
    $confirm  = post_raw('confirm_password');

    $errors = [];

    if ($data['first_name'] === '')                 { $errors[] = 'First name is required.'; }
    if ($data['last_name'] === '')                  { $errors[] = 'Last name is required.'; }
    if (!is_valid_email($data['email']))            { $errors[] = 'Please enter a valid e-mail address.'; }
    if (!is_valid_phone($data['phone']))            { $errors[] = 'Please enter a valid contact number.'; }
    if (!in_array($data['gender'], genders(), true)) { $errors[] = 'Please select your gender.'; }
    if (!is_valid_date($data['dob']))               { $errors[] = 'Please enter a valid date of birth.'; }
    elseif ($data['dob'] > date('Y-m-d'))           { $errors[] = 'Date of birth cannot be in the future.'; }
    if (!in_array($data['blood_group'], blood_groups(), true)) { $errors[] = 'Please select a valid blood group.'; }
    if ($data['address'] === '')                    { $errors[] = 'Address is required.'; }
    if ($data['ec_name'] === '')                    { $errors[] = 'Emergency contact name is required.'; }
    if (!is_valid_phone($data['ec_phone']))         { $errors[] = 'Please enter a valid emergency contact number.'; }

    $pwProblem = password_problem($password);
    if ($pwProblem)                                 { $errors[] = $pwProblem; }
    if ($password !== $confirm)                     { $errors[] = 'The two passwords do not match.'; }

    // Duplicate e-mail check
    if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ?', [$data['email']])) {
        $errors[] = 'This email address is already registered. Please sign in instead.';
    }

    if ($errors) {
        foreach ($errors as $msg) { flash_error($msg); }
        keep_old($_POST);
        redirect('register.php');
    }

    $roleId = (int) db_scalar("SELECT id FROM roles WHERE role_name = 'patient'");

    db_begin();
    try {
        $userId = db_insert(
            'INSERT INTO users (role_id, email, password, status) VALUES (?, ?, ?, ?)',
            [$roleId, $data['email'], hash_password($password), 'active']
        );

        db_insert(
            'INSERT INTO patients
                (user_id, first_name, last_name, gender, date_of_birth, blood_group, phone,
                 address, emergency_contact_name, emergency_contact_phone, registration_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $data['first_name'], $data['last_name'], $data['gender'], $data['dob'],
             $data['blood_group'], $data['phone'], $data['address'], $data['ec_name'],
             $data['ec_phone'], date('Y-m-d'), 'active']
        );

        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        error_log('[MediCare register] ' . $e->getMessage());
        flash_error('Unable to save the record. Please try again.');
        keep_old($_POST);
        redirect('register.php');
    }

    notify($userId, 'Welcome to ' . APP_NAME,
           'Your patient account has been created. You can now book appointments and set medicine reminders.',
           'system', 'patient/dashboard.php');

    clear_old();
    flash_success('Registration successful! Please sign in with your new account.');
    redirect('login.php');
}

$page_title = 'Patient Registration';
$layout     = 'auth';
include __DIR__ . '/includes/header.php';
?>

<div class="auth-card wide">
    <div class="auth-head">
        <div class="mc-logo">M+</div>
        <h1 class="fs-3 mb-1">Patient Registration</h1>
        <p class="text-muted mb-0">Create your <?= e(APP_NAME) ?> account to manage medicines and appointments.</p>
    </div>

    <?php render_flash(); ?>

    <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <h6 class="text-uppercase text-muted small mb-3">Personal Information</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="first_name">First Name *</label>
                <input type="text" class="form-control" id="first_name" name="first_name"
                       value="<?= e(old('first_name')) ?>" maxlength="60" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="last_name">Last Name *</label>
                <input type="text" class="form-control" id="last_name" name="last_name"
                       value="<?= e(old('last_name')) ?>" maxlength="60" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="gender">Gender *</label>
                <select class="form-select" id="gender" name="gender" required>
                    <option value="">-- Select --</option>
                    <?= options_from(genders(), old('gender')) ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="date_of_birth">Date of Birth *</label>
                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                       value="<?= e(old('date_of_birth')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="blood_group">Blood Group *</label>
                <select class="form-select" id="blood_group" name="blood_group" required>
                    <?= options_from(blood_groups(), old('blood_group', 'Unknown')) ?>
                </select>
            </div>
        </div>

        <h6 class="text-uppercase text-muted small mb-3 mt-2">Contact Details</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="email">E-mail Address *</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= e(old('email')) ?>" maxlength="120" required>
                <span class="form-text">This will be your login name.</span>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="phone">Contact Number *</label>
                <input type="text" class="form-control" id="phone" name="phone"
                       value="<?= e(old('phone')) ?>" maxlength="20" required>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label" for="address">Address *</label>
                <textarea class="form-control" id="address" name="address" rows="2"
                          maxlength="200" required><?= e(old('address')) ?></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="emergency_contact_name">Emergency Contact Name *</label>
                <input type="text" class="form-control" id="emergency_contact_name"
                       name="emergency_contact_name" value="<?= e(old('emergency_contact_name')) ?>"
                       maxlength="80" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="emergency_contact_phone">Emergency Contact Number *</label>
                <input type="text" class="form-control" id="emergency_contact_phone"
                       name="emergency_contact_phone" value="<?= e(old('emergency_contact_phone')) ?>"
                       maxlength="20" required>
            </div>
        </div>

        <h6 class="text-uppercase text-muted small mb-3 mt-2">Account Security</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="password">Password *</label>
                <input type="password" class="form-control" id="password" name="password" required>
                <span class="form-text">Minimum 6 characters, with at least one letter and one number.</span>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="confirm_password">Confirm Password *</label>
                <input type="password" class="form-control" id="confirm_password"
                       name="confirm_password" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100"><?= icon('check') ?> Create My Account</button>
    </form>

    <p class="text-center mt-3 mb-0">
        Already registered? <a href="<?= e(url('login.php')) ?>">Sign in here</a><br>
        <a class="small text-muted" href="<?= e(url('index.php')) ?>">&larr; Back to home page</a>
    </p>
</div>

<?php clear_old(); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
