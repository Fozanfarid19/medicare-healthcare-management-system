<?php
/**
 * MediCare - admin/doctors.php
 * Doctor directory: search/filter list, add new doctors (creates the
 * login account and the doctor profile together), edit profile details
 * and activate/deactivate accounts.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling (all writes happen here, then redirect) ----------
if (is_post()) {
    verify_csrf('admin/doctors.php');
    $action = post('action');

    if ($action === 'create') {
        $data = [
            'first_name'    => post('first_name'),
            'last_name'     => post('last_name'),
            'email'         => strtolower(post('email')),
            'phone'         => post('phone'),
            'gender'        => post('gender'),
            'dob'           => null_if_blank(post('date_of_birth')),
            'spec_id'       => post_int('specialization_id') ?: null,
            'clinic_id'     => post_int('clinic_id') ?: null,
            'qualification' => null_if_blank(post('qualification')),
            'license'       => post('license_number'),
            'experience'    => post_int('experience_years'),
            'fee'           => post('consultation_fee'),
            'address'       => null_if_blank(post('address')),
            'bio'           => null_if_blank(post('bio')),
        ];
        $password = post_raw('password');

        $errors = [];
        if ($data['first_name'] === '')                 { $errors[] = 'First name is required.'; }
        if ($data['last_name'] === '')                   { $errors[] = 'Last name is required.'; }
        if (!is_valid_email($data['email']))             { $errors[] = 'Please enter a valid e-mail address.'; }
        if (!is_valid_phone($data['phone']))             { $errors[] = 'Please enter a valid contact number.'; }
        if (!in_array($data['gender'], genders(), true)) { $errors[] = 'Please select a valid gender.'; }
        if ($data['license'] === '')                     { $errors[] = 'License number is required.'; }
        if (!is_numeric($data['fee']) || (float) $data['fee'] < 0) { $errors[] = 'Consultation fee must be zero or greater.'; }
        if ($data['experience'] < 0 || $data['experience'] > 70)   { $errors[] = 'Experience must be between 0 and 70 years.'; }
        $pwProblem = password_problem($password);
        if ($pwProblem)                                  { $errors[] = $pwProblem; }

        if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ?', [$data['email']])) {
            $errors[] = 'This email address is already registered.';
        }
        if (!$errors && db_exists('SELECT 1 FROM doctors WHERE license_number = ?', [$data['license']])) {
            $errors[] = 'This license number is already registered.';
        }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
            $roleId = (int) db_scalar("SELECT id FROM roles WHERE role_name = 'doctor'");

            db_begin();
            try {
                $userId = db_insert(
                    'INSERT INTO users (role_id, email, password, status) VALUES (?, ?, ?, ?)',
                    [$roleId, $data['email'], hash_password($password), 'active']
                );

                db_insert(
                    'INSERT INTO doctors
                        (user_id, clinic_id, specialization_id, first_name, last_name, gender, date_of_birth,
                         phone, qualification, license_number, experience_years, consultation_fee, address, bio, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $userId, $data['clinic_id'], $data['spec_id'], $data['first_name'], $data['last_name'],
                        $data['gender'], $data['dob'], $data['phone'], $data['qualification'], $data['license'],
                        $data['experience'], (float) $data['fee'], $data['address'], $data['bio'], 'active',
                    ]
                );

                db_commit();
                flash_success('Doctor added successfully.');
            } catch (Throwable $e) {
                db_rollback();
                error_log('[MediCare doctors] ' . $e->getMessage());
                flash_error('Unable to save the doctor record. Please try again.');
            }
        }
    } elseif ($action === 'update') {
        $doctorId = post_int('doctor_id');
        $doctor   = db_row('SELECT id, user_id FROM doctors WHERE id = ?', [$doctorId]);

        if (!$doctor) {
            flash_error('Doctor not found.');
        } else {
            $data = [
                'first_name'    => post('first_name'),
                'last_name'     => post('last_name'),
                'email'         => strtolower(post('email')),
                'phone'         => post('phone'),
                'gender'        => post('gender'),
                'dob'           => null_if_blank(post('date_of_birth')),
                'spec_id'       => post_int('specialization_id') ?: null,
                'clinic_id'     => post_int('clinic_id') ?: null,
                'qualification' => null_if_blank(post('qualification')),
                'license'       => post('license_number'),
                'experience'    => post_int('experience_years'),
                'fee'           => post('consultation_fee'),
                'address'       => null_if_blank(post('address')),
                'bio'           => null_if_blank(post('bio')),
            ];

            $errors = [];
            if ($data['first_name'] === '')                 { $errors[] = 'First name is required.'; }
            if ($data['last_name'] === '')                   { $errors[] = 'Last name is required.'; }
            if (!is_valid_email($data['email']))             { $errors[] = 'Please enter a valid e-mail address.'; }
            if (!is_valid_phone($data['phone']))             { $errors[] = 'Please enter a valid contact number.'; }
            if (!in_array($data['gender'], genders(), true)) { $errors[] = 'Please select a valid gender.'; }
            if ($data['license'] === '')                     { $errors[] = 'License number is required.'; }
            if (!is_numeric($data['fee']) || (float) $data['fee'] < 0) { $errors[] = 'Consultation fee must be zero or greater.'; }
            if ($data['experience'] < 0 || $data['experience'] > 70)   { $errors[] = 'Experience must be between 0 and 70 years.'; }

            if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ? AND id != ?', [$data['email'], (int) $doctor['user_id']])) {
                $errors[] = 'This email address is already registered to another account.';
            }
            if (!$errors && db_exists('SELECT 1 FROM doctors WHERE license_number = ? AND id != ?', [$data['license'], $doctorId])) {
                $errors[] = 'This license number is already registered to another doctor.';
            }

            if ($errors) {
                foreach ($errors as $msg) { flash_error($msg); }
            } else {
                db_begin();
                try {
                    db_execute('UPDATE users SET email = ? WHERE id = ?', [$data['email'], (int) $doctor['user_id']]);
                    db_execute(
                        'UPDATE doctors SET clinic_id = ?, specialization_id = ?, first_name = ?, last_name = ?,
                                gender = ?, date_of_birth = ?, phone = ?, qualification = ?, license_number = ?,
                                experience_years = ?, consultation_fee = ?, address = ?, bio = ?
                          WHERE id = ?',
                        [
                            $data['clinic_id'], $data['spec_id'], $data['first_name'], $data['last_name'],
                            $data['gender'], $data['dob'], $data['phone'], $data['qualification'], $data['license'],
                            $data['experience'], (float) $data['fee'], $data['address'], $data['bio'], $doctorId,
                        ]
                    );
                    db_commit();
                    flash_success('Doctor details updated successfully.');
                } catch (Throwable $e) {
                    db_rollback();
                    error_log('[MediCare doctors] ' . $e->getMessage());
                    flash_error('Unable to update the doctor record. Please try again.');
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $doctorId = post_int('doctor_id');
        $doctor   = db_row('SELECT id, user_id, status FROM doctors WHERE id = ?', [$doctorId]);

        if (!$doctor) {
            flash_error('Doctor not found.');
        } else {
            $newStatus = $doctor['status'] === 'active' ? 'inactive' : 'active';
            db_execute('UPDATE doctors SET status = ? WHERE id = ?', [$newStatus, $doctorId]);
            db_execute('UPDATE users SET status = ? WHERE id = ?', [$newStatus, (int) $doctor['user_id']]);
            flash_success('Doctor account ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully.');
        }
    }

    redirect('admin/doctors.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ---------- 2. Read filters + query data ----------
$search      = get_param('q');
$specFilter  = get_int('specialization');
$clinicFilter = get_int('clinic');
$statusFilter = get_param('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(CONCAT(d.first_name,' ',d.last_name) LIKE ? OR u.email LIKE ? OR d.phone LIKE ? OR d.license_number LIKE ?)";
    $like     = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($specFilter) {
    $where[]  = 'd.specialization_id = ?';
    $params[] = $specFilter;
}
if ($clinicFilter) {
    $where[]  = 'd.clinic_id = ?';
    $params[] = $clinicFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[]  = 'd.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseFrom = "FROM doctors d
             JOIN users u ON u.id = d.user_id
             LEFT JOIN specializations s ON s.id = d.specialization_id
             LEFT JOIN clinics c ON c.id = d.clinic_id";

$total = (int) db_scalar("SELECT COUNT(*) $baseFrom $whereSql", $params);
$pg    = paginate($total);

$rows = db_all(
    "SELECT d.*, u.email, s.specialization_name, c.clinic_name
     $baseFrom
     $whereSql
     ORDER BY d.first_name, d.last_name
     LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$specializations = all_specializations();
$clinics          = all_clinics(false);

$page_title = 'Doctor Management';
$active     = 'doctors';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Doctors</div>
        <h1>Doctor Management</h1>
        <div class="sub">Add, edit and manage doctor accounts.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDoctorModal"><?= icon('plus') ?> Add Doctor</button>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone or license">
        </div>
        <div>
            <label class="form-label">Specialization</label>
            <select class="form-select auto-submit" name="specialization">
                <option value="">All</option>
                <?= options_from_rows($specializations, 'id', 'specialization_name', $specFilter) ?>
            </select>
        </div>
        <div>
            <label class="form-label">Clinic</label>
            <select class="form-select auto-submit" name="clinic">
                <option value="">All</option>
                <?= options_from_rows($clinics, 'id', 'clinic_name', $clinicFilter) ?>
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
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/doctors.php')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Name</th><th>Specialization</th><th>Clinic</th><th>Phone</th><th>Experience</th><th>Fee</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><div class="empty"><?= icon('search') ?><div>No doctors found.</div></div></td></tr>
            <?php else: foreach ($rows as $d): ?>
                <tr>
                    <td><?= e(doctor_display_name($d)) ?></td>
                    <td><?= e($d['specialization_name'] ?? 'Unassigned') ?></td>
                    <td><?= e($d['clinic_name'] ?? '-') ?></td>
                    <td><?= e($d['phone']) ?></td>
                    <td><?= (int) $d['experience_years'] ?> yrs</td>
                    <td><?= e(money($d['consultation_fee'])) ?></td>
                    <td><?= status_badge($d['status']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/doctor_view.php?id=' . (int) $d['id'])) ?>"><?= icon('eye') ?></a>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#editDoctorModal"
                                data-doctor-id="<?= (int) $d['id'] ?>"
                                data-first-name="<?= e($d['first_name']) ?>"
                                data-last-name="<?= e($d['last_name']) ?>"
                                data-email="<?= e($d['email']) ?>"
                                data-phone="<?= e($d['phone']) ?>"
                                data-gender="<?= e($d['gender']) ?>"
                                data-date-of-birth="<?= e($d['date_of_birth']) ?>"
                                data-specialization-id="<?= (int) $d['specialization_id'] ?>"
                                data-clinic-id="<?= (int) $d['clinic_id'] ?>"
                                data-qualification="<?= e($d['qualification']) ?>"
                                data-license-number="<?= e($d['license_number']) ?>"
                                data-experience-years="<?= (int) $d['experience_years'] ?>"
                                data-consultation-fee="<?= e($d['consultation_fee']) ?>"
                                data-address="<?= e($d['address']) ?>"
                                data-bio="<?= e($d['bio']) ?>"><?= icon('edit') ?></button>
                        <form method="post" class="d-inline" data-confirm="<?= $d['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this doctor?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $d['status'] === 'active' ? 'danger' : 'success' ?>">
                                <?= $d['status'] === 'active' ? icon('close') : icon('check') ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'specialization' => $specFilter ?: '', 'clinic' => $clinicFilter ?: '', 'status' => $statusFilter]); ?></div>
</div>

<!-- Add Doctor modal -->
<div class="modal" id="addDoctorModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add Doctor</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="row">
       <div class="col-md-6 mb-3"><label class="form-label">First Name *</label><input type="text" class="form-control" name="first_name" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Last Name *</label><input type="text" class="form-control" name="last_name" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">E-mail Address *</label><input type="email" class="form-control" name="email" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Password *</label><input type="password" class="form-control" name="password" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Phone *</label><input type="text" class="form-control" name="phone" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Gender *</label>
         <select class="form-select" name="gender" required><option value="">-- Select --</option><?= options_from(genders()) ?></select></div>
       <div class="col-md-4 mb-3"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>"></div>
       <div class="col-md-6 mb-3"><label class="form-label">Specialization</label>
         <select class="form-select" name="specialization_id"><option value="">-- None --</option><?= options_from_rows($specializations, 'id', 'specialization_name') ?></select></div>
       <div class="col-md-6 mb-3"><label class="form-label">Clinic</label>
         <select class="form-select" name="clinic_id"><option value="">-- None --</option><?= options_from_rows($clinics, 'id', 'clinic_name') ?></select></div>
       <div class="col-md-6 mb-3"><label class="form-label">Qualification</label><input type="text" class="form-control" name="qualification"></div>
       <div class="col-md-6 mb-3"><label class="form-label">License Number *</label><input type="text" class="form-control" name="license_number" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Experience (years) *</label><input type="number" class="form-control" name="experience_years" min="0" max="70" value="0" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Consultation Fee *</label><input type="number" step="0.01" class="form-control" name="consultation_fee" min="0" value="0" required></div>
       <div class="col-12 mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
       <div class="col-12 mb-3"><label class="form-label">Bio</label><textarea class="form-control" name="bio" rows="2"></textarea></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit Doctor modal -->
<div class="modal" id="editDoctorModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="doctor_id" data-fill="doctorId">
   <div class="modal-header"><h5 class="modal-title">Edit Doctor</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="row">
       <div class="col-md-6 mb-3"><label class="form-label">First Name *</label><input type="text" class="form-control" name="first_name" data-fill="firstName" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Last Name *</label><input type="text" class="form-control" name="last_name" data-fill="lastName" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">E-mail Address *</label><input type="email" class="form-control" name="email" data-fill="email" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Phone *</label><input type="text" class="form-control" name="phone" data-fill="phone" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Gender *</label>
         <select class="form-select" name="gender" data-fill="gender" required><?= options_from(genders()) ?></select></div>
       <div class="col-md-4 mb-3"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" data-fill="dateOfBirth" max="<?= e(date('Y-m-d')) ?>"></div>
       <div class="col-md-6 mb-3"><label class="form-label">Specialization</label>
         <select class="form-select" name="specialization_id" data-fill="specializationId"><option value="">-- None --</option><?= options_from_rows($specializations, 'id', 'specialization_name') ?></select></div>
       <div class="col-md-6 mb-3"><label class="form-label">Clinic</label>
         <select class="form-select" name="clinic_id" data-fill="clinicId"><option value="">-- None --</option><?= options_from_rows($clinics, 'id', 'clinic_name') ?></select></div>
       <div class="col-md-6 mb-3"><label class="form-label">Qualification</label><input type="text" class="form-control" name="qualification" data-fill="qualification"></div>
       <div class="col-md-6 mb-3"><label class="form-label">License Number *</label><input type="text" class="form-control" name="license_number" data-fill="licenseNumber" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Experience (years) *</label><input type="number" class="form-control" name="experience_years" data-fill="experienceYears" min="0" max="70" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Consultation Fee *</label><input type="number" step="0.01" class="form-control" name="consultation_fee" data-fill="consultationFee" min="0" required></div>
       <div class="col-12 mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" data-fill="address" rows="2"></textarea></div>
       <div class="col-12 mb-3"><label class="form-label">Bio</label><textarea class="form-control" name="bio" data-fill="bio" rows="2"></textarea></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
