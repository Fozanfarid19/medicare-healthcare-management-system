<?php
/**
 * MediCare - doctor/profile.php
 * The logged-in doctor's own profile: editable details + password change.
 * Clinic and specialization are set by the admin and shown read-only.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/profile.php');
    $action = post('action');

    if ($action === 'update_profile') {
        $firstName  = post('first_name');
        $lastName   = post('last_name');
        $phone      = post('phone');
        $gender     = post('gender');
        $dob        = post('date_of_birth');
        $qualification = post('qualification');
        $experience = post_int('experience_years');
        $fee        = post('consultation_fee');
        $address    = post('address');
        $bio        = post('bio');

        $errors = [];
        if ($firstName === '' || $lastName === '')      { $errors[] = 'First and last name are required.'; }
        if (!is_valid_phone($phone))                    { $errors[] = 'Please enter a valid phone number.'; }
        if (!in_array($gender, genders(), true))         { $errors[] = 'Please select a valid gender.'; }
        if ($dob !== '' && !is_valid_date($dob))         { $errors[] = 'Please enter a valid date of birth.'; }
        if ($experience < 0 || $experience > 80)         { $errors[] = 'Please enter a valid number of years of experience.'; }
        if (!is_numeric($fee) || (float) $fee < 0)       { $errors[] = 'Please enter a valid consultation fee.'; }

        if ($errors) {
            flash_error(implode(' ', $errors));
        } else {
            db_execute(
                "UPDATE doctors SET first_name = ?, last_name = ?, phone = ?, gender = ?, date_of_birth = ?,
                        qualification = ?, experience_years = ?, consultation_fee = ?, address = ?, bio = ?
                  WHERE id = ?",
                [
                    $firstName, $lastName, $phone, $gender, null_if_blank($dob),
                    null_if_blank($qualification), $experience, (float) $fee,
                    null_if_blank($address), null_if_blank($bio), $doctorId,
                ]
            );
            flash_success('Your profile has been updated.');
        }
    } elseif ($action === 'change_password') {
        $current  = post_raw('current_password');
        $new      = post_raw('new_password');
        $confirm  = post_raw('confirm_password');

        $userRow = db_row('SELECT u.id, u.password FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$doctorId]);

        if (!$userRow || !password_verify($current, $userRow['password'])) {
            flash_error('Your current password is incorrect.');
        } else {
            $problem = password_problem($new);
            if ($problem) {
                flash_error($problem);
            } elseif ($new !== $confirm) {
                flash_error('The new password and confirmation do not match.');
            } else {
                db_execute('UPDATE users SET password = ? WHERE id = ?', [hash_password($new), (int) $userRow['id']]);
                flash_success('Your password has been changed.');
            }
        }
    }

    redirect('doctor/profile.php');
}

// ---------- 2. Load data ----------
$doctor = db_row(
    "SELECT d.*, u.email, u.created_at AS user_created_at,
            c.clinic_name, s.specialization_name
       FROM doctors d
       JOIN users u ON u.id = d.user_id
       LEFT JOIN clinics c ON c.id = d.clinic_id
       LEFT JOIN specializations s ON s.id = d.specialization_id
      WHERE d.id = ?",
    [$doctorId]
);

if (!$doctor) {
    flash_error('Your doctor profile could not be found.');
    redirect('logout.php');
}

$totalAppointments = (int) db_scalar('SELECT COUNT(*) FROM appointments WHERE doctor_id = ?', [$doctorId]);
$totalPatients = (int) db_scalar(
    "SELECT COUNT(*) FROM (
        SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?
        UNION
        SELECT DISTINCT patient_id FROM medicines WHERE prescribed_by = ?
     ) t",
    [$doctorId, $doctorId]
);

$page_title = 'My Profile';
$active     = 'profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / My Profile</div>
        <h1>My Profile</h1>
        <div class="sub">Manage your personal and professional details.</div>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="table-card p-3">
            <div class="profile-head mb-3">
                <div class="profile-avatar"><?= e(strtoupper(substr($doctor['first_name'], 0, 1))) ?></div>
                <div>
                    <h5 class="mb-0">Dr. <?= e($doctor['first_name'] . ' ' . $doctor['last_name']) ?></h5>
                    <div class="text-muted small"><?= e($doctor['email']) ?></div>
                </div>
            </div>
            <div class="kv"><span class="k">License Number</span><span class="v"><?= e($doctor['license_number']) ?></span></div>
            <div class="kv"><span class="k">Clinic</span><span class="v"><?= e($doctor['clinic_name'] ?? 'Not assigned') ?></span></div>
            <div class="kv"><span class="k">Specialization</span><span class="v"><?= e($doctor['specialization_name'] ?? 'Not assigned') ?></span></div>
            <div class="kv"><span class="k">Member Since</span><span class="v"><?= e(format_date($doctor['created_at'])) ?></span></div>
            <div class="kv"><span class="k">Total Appointments</span><span class="v"><?= (int) $totalAppointments ?></span></div>
            <div class="kv"><span class="k">Total Patients</span><span class="v"><?= (int) $totalPatients ?></span></div>
        </div>

        <div class="table-card p-3 mt-3">
            <h6 class="mb-3"><?= icon('lock') ?> Change Password</h6>
            <form method="post" action="<?= e(url('doctor/profile.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                </div>
                <button class="btn btn-primary w-100"><?= icon('lock') ?> Update Password</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3"><?= icon('edit') ?> Edit Profile</h6>
            <form method="post" action="<?= e(url('doctor/profile.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" name="first_name" value="<?= e($doctor['first_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-control" name="last_name" value="<?= e($doctor['last_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" value="<?= e($doctor['phone']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gender</label>
                        <select class="form-select" name="gender" required>
                            <?= options_from(genders(), $doctor['gender']) ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" class="form-control" name="date_of_birth" value="<?= e($doctor['date_of_birth']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Qualification</label>
                        <input type="text" class="form-control" name="qualification" value="<?= e($doctor['qualification']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Experience (years)</label>
                        <input type="number" class="form-control" name="experience_years" min="0" max="80" value="<?= (int) $doctor['experience_years'] ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Consultation Fee (<?= e(APP_CURRENCY) ?>)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="consultation_fee" value="<?= e($doctor['consultation_fee']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Clinic <span class="text-muted small">(set by administrator)</span></label>
                        <input type="text" class="form-control" value="<?= e($doctor['clinic_name'] ?? 'Not assigned') ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Specialization <span class="text-muted small">(set by administrator)</span></label>
                        <input type="text" class="form-control" value="<?= e($doctor['specialization_name'] ?? 'Not assigned') ?>" disabled>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" name="address" value="<?= e($doctor['address']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Bio</label>
                        <textarea class="form-control" name="bio" rows="4"><?= e($doctor['bio']) ?></textarea>
                    </div>
                </div>

                <button class="btn btn-primary mt-3"><?= icon('check') ?> Save Changes</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
