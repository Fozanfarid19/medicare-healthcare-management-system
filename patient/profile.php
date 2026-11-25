<?php
/**
 * MediCare - patient/profile.php
 * The patient's own profile: personal details, e-mail and password.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('patient');

$patientId = current_patient_id();
$userId    = current_user_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('patient/profile.php');
    $action = post('action');

    if ($action === 'update_profile') {
        $firstName  = post('first_name');
        $lastName   = post('last_name');
        $phone      = post('phone');
        $gender     = post('gender');
        $dob        = post('date_of_birth');
        $bloodGroup = post('blood_group');
        $address    = post('address');
        $ecName     = post('emergency_contact_name');
        $ecPhone    = post('emergency_contact_phone');

        $errors = [];
        if ($firstName === '')                          { $errors[] = 'First name is required.'; }
        if ($lastName === '')                            { $errors[] = 'Last name is required.'; }
        if (!is_valid_phone($phone))                      { $errors[] = 'Please enter a valid phone number.'; }
        if (!in_array($gender, genders(), true))          { $errors[] = 'Please select a valid gender.'; }
        if (!is_valid_date($dob))                         { $errors[] = 'Please enter a valid date of birth.'; }
        elseif ($dob > date('Y-m-d'))                     { $errors[] = 'Date of birth cannot be in the future.'; }
        if (!in_array($bloodGroup, blood_groups(), true)) { $errors[] = 'Please select a valid blood group.'; }
        if ($ecPhone !== '' && !is_valid_phone($ecPhone)) { $errors[] = 'Please enter a valid emergency contact number.'; }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
            db_execute(
                'UPDATE patients SET first_name = ?, last_name = ?, phone = ?, gender = ?,
                        date_of_birth = ?, blood_group = ?, address = ?,
                        emergency_contact_name = ?, emergency_contact_phone = ?
                  WHERE id = ?',
                [$firstName, $lastName, $phone, $gender, $dob, $bloodGroup,
                 null_if_blank($address), null_if_blank($ecName), null_if_blank($ecPhone), $patientId]
            );
            $_SESSION['user']['name'] = $firstName . ' ' . $lastName;
            flash_success('Your profile has been updated.');
        }
    } elseif ($action === 'update_email') {
        $email = strtolower(post('email'));

        if (!is_valid_email($email)) {
            flash_error('Please enter a valid e-mail address.');
        } elseif (db_exists('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $userId])) {
            flash_error('This email address is already registered.');
        } else {
            db_execute('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
            $_SESSION['user']['email'] = $email;
            flash_success('Your e-mail address has been updated.');
        }
    } elseif ($action === 'change_password') {
        $current = post_raw('current_password');
        $new     = post_raw('new_password');
        $confirm = post_raw('confirm_password');

        $hash = db_scalar('SELECT password FROM users WHERE id = ?', [$userId], '');

        if (!password_verify($current, (string) $hash)) {
            flash_error('Your current password is incorrect.');
        } else {
            $problem = password_problem($new);
            if ($problem) {
                flash_error($problem);
            } elseif ($new !== $confirm) {
                flash_error('The two new passwords do not match.');
            } else {
                db_execute('UPDATE users SET password = ? WHERE id = ?', [hash_password($new), $userId]);
                flash_success('Your password has been changed.');
            }
        }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/profile.php');
}

// ---------- 2. Data ----------
$patient = db_row(
    'SELECT p.*, u.email FROM patients p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
    [$patientId]
);
if (!$patient) {
    flash_error('Your patient profile could not be found.');
    redirect(dashboard_url());
}

$totalAppointments = (int) db_scalar('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$patientId]);
$activeMedicines    = (int) db_scalar("SELECT COUNT(*) FROM medicines WHERE patient_id = ? AND status = 'active'", [$patientId]);

$page_title = 'My Profile';
$active     = 'profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / My Profile</div>
        <h1>My Profile</h1>
        <div class="sub">Manage your personal details, e-mail and password.</div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="table-card p-3 mb-3">
            <h6 class="mb-3">Personal Information</h6>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="first_name">First Name *</label>
                        <input type="text" class="form-control" id="first_name" name="first_name"
                               value="<?= e($patient['first_name']) ?>" maxlength="60" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="last_name">Last Name *</label>
                        <input type="text" class="form-control" id="last_name" name="last_name"
                               value="<?= e($patient['last_name']) ?>" maxlength="60" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="phone">Phone *</label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?= e($patient['phone']) ?>" maxlength="20" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="gender">Gender *</label>
                        <select class="form-select" id="gender" name="gender" required>
                            <?= options_from(genders(), $patient['gender']) ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="date_of_birth">Date of Birth *</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                               value="<?= e($patient['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="blood_group">Blood Group *</label>
                        <select class="form-select" id="blood_group" name="blood_group" required>
                            <?= options_from(blood_groups(), $patient['blood_group']) ?>
                        </select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label" for="address">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"
                                  maxlength="200"><?= e($patient['address']) ?></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="emergency_contact_name">Emergency Contact Name</label>
                        <input type="text" class="form-control" id="emergency_contact_name"
                               name="emergency_contact_name" value="<?= e($patient['emergency_contact_name']) ?>" maxlength="80">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="emergency_contact_phone">Emergency Contact Number</label>
                        <input type="text" class="form-control" id="emergency_contact_phone"
                               name="emergency_contact_phone" value="<?= e($patient['emergency_contact_phone']) ?>" maxlength="20">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save Changes</button>
            </form>
        </div>

        <div class="table-card p-3 mb-3">
            <h6 class="mb-3">E-mail Address</h6>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_email">
                <div class="col-md-8">
                    <label class="form-label" for="email">E-mail</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= e($patient['email']) ?>" maxlength="120" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary w-100"><?= icon('edit') ?> Update E-mail</button>
                </div>
            </form>
        </div>

        <div class="table-card p-3 mb-3">
            <h6 class="mb-3">Change Password</h6>
            <form method="post" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="col-md-4">
                    <label class="form-label" for="current_password">Current Password *</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="new_password">New Password *</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                    <span class="form-text">Minimum 6 characters, with at least one letter and one number.</span>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="confirm_password">Confirm New Password *</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-outline-primary"><?= icon('lock') ?> Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4 mb-3">
        <div class="table-card p-3">
            <div class="profile-head mb-3">
                <div class="profile-avatar"><?= e(strtoupper(substr($patient['first_name'], 0, 1))) ?></div>
                <div>
                    <div class="fw-bold fs-5"><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></div>
                    <div class="text-muted small"><?= e($patient['email']) ?></div>
                </div>
            </div>
            <div class="kv"><span class="k">Registered On</span><span class="v"><?= e(format_date($patient['registration_date'])) ?></span></div>
            <div class="kv"><span class="k">Blood Group</span><span class="v"><?= e($patient['blood_group']) ?></span></div>
            <div class="kv"><span class="k">Age</span><span class="v"><?= e((string) calculate_age($patient['date_of_birth'])) ?></span></div>
            <div class="kv"><span class="k">Total Appointments</span><span class="v"><?= (int) $totalAppointments ?></span></div>
            <div class="kv"><span class="k">Active Medicines</span><span class="v"><?= (int) $activeMedicines ?></span></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
