<?php
/**
 * MediCare - admin/patients.php
 * Patient directory: search/filter list, register new patients (creates
 * the login account and the patient profile together), edit profile
 * details and activate/deactivate accounts.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling (all writes happen here, then redirect) ----------
if (is_post()) {
    verify_csrf('admin/patients.php');
    $action = post('action');

    if ($action === 'create') {
        $data = [
            'first_name'  => post('first_name'),
            'last_name'   => post('last_name'),
            'email'       => strtolower(post('email')),
            'phone'       => post('phone'),
            'gender'      => post('gender'),
            'dob'         => post('date_of_birth'),
            'blood_group' => post('blood_group', 'Unknown'),
            'address'     => null_if_blank(post('address')),
            'ec_name'     => null_if_blank(post('emergency_contact_name')),
            'ec_phone'    => null_if_blank(post('emergency_contact_phone')),
        ];
        $password = post_raw('password');

        $errors = [];
        if ($data['first_name'] === '')                     { $errors[] = 'First name is required.'; }
        if ($data['last_name'] === '')                       { $errors[] = 'Last name is required.'; }
        if (!is_valid_email($data['email']))                 { $errors[] = 'Please enter a valid e-mail address.'; }
        if (!is_valid_phone($data['phone']))                 { $errors[] = 'Please enter a valid contact number.'; }
        if (!in_array($data['gender'], genders(), true))     { $errors[] = 'Please select a valid gender.'; }
        if (!is_valid_date($data['dob']))                    { $errors[] = 'Please enter a valid date of birth.'; }
        elseif ($data['dob'] > date('Y-m-d'))                { $errors[] = 'Date of birth cannot be in the future.'; }
        if (!in_array($data['blood_group'], blood_groups(), true)) { $errors[] = 'Please select a valid blood group.'; }
        $pwProblem = password_problem($password);
        if ($pwProblem)                                      { $errors[] = $pwProblem; }

        if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ?', [$data['email']])) {
            $errors[] = 'This email address is already registered.';
        }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
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
                    [
                        $userId, $data['first_name'], $data['last_name'], $data['gender'], $data['dob'],
                        $data['blood_group'], $data['phone'], $data['address'], $data['ec_name'],
                        $data['ec_phone'], date('Y-m-d'), 'active',
                    ]
                );

                db_commit();
                flash_success('Patient registered successfully.');
            } catch (Throwable $e) {
                db_rollback();
                error_log('[MediCare patients] ' . $e->getMessage());
                flash_error('Unable to save the patient record. Please try again.');
            }
        }
    } elseif ($action === 'update') {
        $patientId = post_int('patient_id');
        $patient   = db_row('SELECT id, user_id FROM patients WHERE id = ?', [$patientId]);

        if (!$patient) {
            flash_error('Patient not found.');
        } else {
            $data = [
                'first_name'  => post('first_name'),
                'last_name'   => post('last_name'),
                'email'       => strtolower(post('email')),
                'phone'       => post('phone'),
                'gender'      => post('gender'),
                'dob'         => post('date_of_birth'),
                'blood_group' => post('blood_group', 'Unknown'),
                'address'     => null_if_blank(post('address')),
                'ec_name'     => null_if_blank(post('emergency_contact_name')),
                'ec_phone'    => null_if_blank(post('emergency_contact_phone')),
            ];

            $errors = [];
            if ($data['first_name'] === '')                     { $errors[] = 'First name is required.'; }
            if ($data['last_name'] === '')                       { $errors[] = 'Last name is required.'; }
            if (!is_valid_email($data['email']))                 { $errors[] = 'Please enter a valid e-mail address.'; }
            if (!is_valid_phone($data['phone']))                 { $errors[] = 'Please enter a valid contact number.'; }
            if (!in_array($data['gender'], genders(), true))     { $errors[] = 'Please select a valid gender.'; }
            if (!is_valid_date($data['dob']))                    { $errors[] = 'Please enter a valid date of birth.'; }
            elseif ($data['dob'] > date('Y-m-d'))                { $errors[] = 'Date of birth cannot be in the future.'; }
            if (!in_array($data['blood_group'], blood_groups(), true)) { $errors[] = 'Please select a valid blood group.'; }

            if (!$errors && db_exists('SELECT 1 FROM users WHERE email = ? AND id != ?', [$data['email'], (int) $patient['user_id']])) {
                $errors[] = 'This email address is already registered to another account.';
            }

            if ($errors) {
                foreach ($errors as $msg) { flash_error($msg); }
            } else {
                db_begin();
                try {
                    db_execute('UPDATE users SET email = ? WHERE id = ?', [$data['email'], (int) $patient['user_id']]);
                    db_execute(
                        'UPDATE patients SET first_name = ?, last_name = ?, gender = ?, date_of_birth = ?,
                                blood_group = ?, phone = ?, address = ?, emergency_contact_name = ?,
                                emergency_contact_phone = ?
                          WHERE id = ?',
                        [
                            $data['first_name'], $data['last_name'], $data['gender'], $data['dob'],
                            $data['blood_group'], $data['phone'], $data['address'], $data['ec_name'],
                            $data['ec_phone'], $patientId,
                        ]
                    );
                    db_commit();
                    flash_success('Patient details updated successfully.');
                } catch (Throwable $e) {
                    db_rollback();
                    error_log('[MediCare patients] ' . $e->getMessage());
                    flash_error('Unable to update the patient record. Please try again.');
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $patientId = post_int('patient_id');
        $patient   = db_row('SELECT id, user_id, status FROM patients WHERE id = ?', [$patientId]);

        if (!$patient) {
            flash_error('Patient not found.');
        } else {
            $newStatus = $patient['status'] === 'active' ? 'inactive' : 'active';
            db_execute('UPDATE patients SET status = ? WHERE id = ?', [$newStatus, $patientId]);
            db_execute('UPDATE users SET status = ? WHERE id = ?', [$newStatus, (int) $patient['user_id']]);
            flash_success('Patient account ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully.');
        }
    }

    redirect('admin/patients.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ---------- 2. Read filters + query data ----------
$search       = get_param('q');
$bloodFilter  = get_param('blood_group');
$genderFilter = get_param('gender');
$statusFilter = get_param('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR u.email LIKE ? OR p.phone LIKE ?)";
    $like     = "%$search%";
    array_push($params, $like, $like, $like);
}
if ($bloodFilter !== '' && in_array($bloodFilter, blood_groups(), true)) {
    $where[]  = 'p.blood_group = ?';
    $params[] = $bloodFilter;
}
if ($genderFilter !== '' && in_array($genderFilter, genders(), true)) {
    $where[]  = 'p.gender = ?';
    $params[] = $genderFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[]  = 'p.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseFrom = 'FROM patients p JOIN users u ON u.id = p.user_id';

$total = (int) db_scalar("SELECT COUNT(*) $baseFrom $whereSql", $params);
$pg    = paginate($total);

$rows = db_all(
    "SELECT p.*, u.email
     $baseFrom
     $whereSql
     ORDER BY p.first_name, p.last_name
     LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$page_title = 'Patient Management';
$active     = 'patients';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Patients</div>
        <h1>Patient Management</h1>
        <div class="sub">Register, edit and manage patient accounts.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPatientModal"><?= icon('plus') ?> Add Patient</button>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Name, email or phone">
        </div>
        <div>
            <label class="form-label">Blood Group</label>
            <select class="form-select auto-submit" name="blood_group">
                <option value="">All</option>
                <?= options_from(blood_groups(), $bloodFilter) ?>
            </select>
        </div>
        <div>
            <label class="form-label">Gender</label>
            <select class="form-select auto-submit" name="gender">
                <option value="">All</option>
                <?= options_from(genders(), $genderFilter) ?>
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
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/patients.php')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Name</th><th>Age</th><th>Gender</th><th>Blood Group</th><th>Phone</th><th>Registered On</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><div class="empty"><?= icon('search') ?><div>No patients found.</div></div></td></tr>
            <?php else: foreach ($rows as $p): ?>
                <tr>
                    <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= e((string) calculate_age($p['date_of_birth'])) ?></td>
                    <td><?= e($p['gender']) ?></td>
                    <td><?= e($p['blood_group']) ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td><?= e(format_date($p['registration_date'])) ?></td>
                    <td><?= status_badge($p['status']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/patient_view.php?id=' . (int) $p['id'])) ?>"><?= icon('eye') ?></a>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#editPatientModal"
                                data-patient-id="<?= (int) $p['id'] ?>"
                                data-first-name="<?= e($p['first_name']) ?>"
                                data-last-name="<?= e($p['last_name']) ?>"
                                data-email="<?= e($p['email']) ?>"
                                data-phone="<?= e($p['phone']) ?>"
                                data-gender="<?= e($p['gender']) ?>"
                                data-date-of-birth="<?= e($p['date_of_birth']) ?>"
                                data-blood-group="<?= e($p['blood_group']) ?>"
                                data-address="<?= e($p['address']) ?>"
                                data-emergency-contact-name="<?= e($p['emergency_contact_name']) ?>"
                                data-emergency-contact-phone="<?= e($p['emergency_contact_phone']) ?>"><?= icon('edit') ?></button>
                        <form method="post" class="d-inline" data-confirm="<?= $p['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this patient?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $p['status'] === 'active' ? 'danger' : 'success' ?>">
                                <?= $p['status'] === 'active' ? icon('close') : icon('check') ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'blood_group' => $bloodFilter, 'gender' => $genderFilter, 'status' => $statusFilter]); ?></div>
</div>

<!-- Add Patient modal -->
<div class="modal" id="addPatientModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add Patient</h5>
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
       <div class="col-md-4 mb-3"><label class="form-label">Date of Birth *</label><input type="date" class="form-control" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Blood Group *</label>
         <select class="form-select" name="blood_group" required><?= options_from(blood_groups(), 'Unknown') ?></select></div>
       <div class="col-12 mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
       <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Name</label><input type="text" class="form-control" name="emergency_contact_name"></div>
       <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Phone</label><input type="text" class="form-control" name="emergency_contact_phone"></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit Patient modal -->
<div class="modal" id="editPatientModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="patient_id" data-fill="patientId">
   <div class="modal-header"><h5 class="modal-title">Edit Patient</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="row">
       <div class="col-md-6 mb-3"><label class="form-label">First Name *</label><input type="text" class="form-control" name="first_name" data-fill="firstName" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Last Name *</label><input type="text" class="form-control" name="last_name" data-fill="lastName" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">E-mail Address *</label><input type="email" class="form-control" name="email" data-fill="email" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Phone *</label><input type="text" class="form-control" name="phone" data-fill="phone" required></div>
       <div class="col-md-4 mb-3"><label class="form-label">Gender *</label>
         <select class="form-select" name="gender" data-fill="gender" required><?= options_from(genders()) ?></select></div>
       <div class="col-md-4 mb-3"><label class="form-label">Date of Birth *</label><input type="date" class="form-control" name="date_of_birth" data-fill="dateOfBirth" max="<?= e(date('Y-m-d')) ?>" required></div>
       <div class="col-md-6 mb-3"><label class="form-label">Blood Group *</label>
         <select class="form-select" name="blood_group" data-fill="bloodGroup" required><?= options_from(blood_groups()) ?></select></div>
       <div class="col-12 mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" data-fill="address" rows="2"></textarea></div>
       <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Name</label><input type="text" class="form-control" name="emergency_contact_name" data-fill="emergencyContactName"></div>
       <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Phone</label><input type="text" class="form-control" name="emergency_contact_phone" data-fill="emergencyContactPhone"></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
