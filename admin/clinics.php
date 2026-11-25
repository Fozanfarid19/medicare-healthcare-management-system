<?php
/**
 * MediCare - admin/clinics.php
 * Manage clinics (add / edit / activate-deactivate) and assign doctors
 * to a clinic.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling (all writes happen here, then redirect) ----------
if (is_post()) {
    verify_csrf('admin/clinics.php');
    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $id          = post_int('id');
        $clinicName  = post('clinic_name');
        $address     = post('address');
        $city        = post('city');
        $state       = null_if_blank(post('state'));
        $postalCode  = null_if_blank(post('postal_code'));
        $phone       = null_if_blank(post('phone'));
        $email       = null_if_blank(post('email'));
        $opening     = post('opening_time');
        $closing     = post('closing_time');

        if ($clinicName === '' || $address === '' || $city === '') {
            flash_error('Clinic name, address and city are required.');
        } elseif ($email !== null && !is_valid_email($email)) {
            flash_error('Please enter a valid e-mail address.');
        } elseif ($phone !== null && !is_valid_phone($phone)) {
            flash_error('Please enter a valid phone number.');
        } elseif (!is_valid_time($opening) || !is_valid_time($closing)) {
            flash_error('Please enter valid opening and closing times.');
        } elseif ($closing <= $opening) {
            flash_error('Closing time must be later than opening time.');
        } elseif (db_exists('SELECT 1 FROM clinics WHERE clinic_name = ? AND id <> ?', [$clinicName, $id])) {
            flash_error('A clinic with this name already exists.');
        } else {
            $params = [$clinicName, $address, $city, $state, $postalCode, $phone, $email, $opening, $closing];

            if ($action === 'create') {
                db_insert(
                    'INSERT INTO clinics (clinic_name, address, city, state, postal_code, phone, email, opening_time, closing_time)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $params
                );
                flash_success('Clinic added successfully.');
            } else {
                $params[] = $id;
                $affected = db_execute(
                    'UPDATE clinics SET clinic_name = ?, address = ?, city = ?, state = ?, postal_code = ?,
                            phone = ?, email = ?, opening_time = ?, closing_time = ? WHERE id = ?',
                    $params
                );
                $affected ? flash_success('Clinic updated successfully.') : flash_error('Clinic not found.');
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = post_int('id');
        db_execute("UPDATE clinics SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        flash_success('Clinic status updated.');
    } elseif ($action === 'assign_doctor') {
        $doctorId = post_int('doctor_id');
        $clinicId = post_int('clinic_id');

        if (!$doctorId || !$clinicId) {
            flash_error('Please select both a doctor and a clinic.');
        } else {
            $affected = db_execute('UPDATE doctors SET clinic_id = ? WHERE id = ?', [$clinicId, $doctorId]);
            $affected ? flash_success('Doctor assigned to the clinic.') : flash_error('Doctor not found.');
        }
    }

    redirect('admin/clinics.php');
}

// ---------- 2. Read filters + query data ----------
$search = get_param('q');
$status = get_param('status');

$where  = []; $params = [];
if ($search !== '') {
    $where[]  = '(c.clinic_name LIKE ? OR c.city LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status !== '') {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_scalar("SELECT COUNT(*) FROM clinics c $whereSql", $params);
$pg    = paginate($total);
$rows  = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM doctors d WHERE d.clinic_id = c.id AND d.status = 'active') AS doctor_count
       FROM clinics c
       $whereSql
      ORDER BY c.clinic_name
      LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// Doctors for the "assign doctors" card
$allDoctors = db_all(
    "SELECT id, CONCAT('Dr. ', first_name, ' ', last_name) AS name
       FROM doctors ORDER BY first_name, last_name"
);
$unassignedDoctors = db_all(
    "SELECT id, first_name, last_name, phone, status
       FROM doctors WHERE clinic_id IS NULL ORDER BY first_name, last_name"
);

// ---------- 3. Render ----------
$page_title = 'Clinics';
$active     = 'clinics';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Clinics</div>
    <h1>Clinic Management</h1>
    <div class="sub">Add, edit and deactivate clinics.</div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><?= icon('plus') ?> Add Clinic</button>
  </div>
</div>

<div class="table-card">
  <form class="filter-bar" method="get">
    <div class="grow">
      <label class="form-label">Search</label>
      <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Clinic name or city">
    </div>
    <div>
      <label class="form-label">Status</label>
      <select class="form-select auto-submit" name="status">
        <option value="">All</option>
        <?= options_from(['active' => 'Active', 'inactive' => 'Inactive'], $status) ?>
      </select>
    </div>
    <div>
      <button class="btn btn-primary"><?= icon('search') ?> Search</button>
      <a class="btn btn-outline-secondary" href="<?= e(url('admin/clinics.php')) ?>">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>#</th><th>Clinic</th><th>City</th><th>Phone</th><th>Hours</th>
          <th>Doctors</th><th>Status</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8"><div class="empty"><?= icon('search') ?><div>No clinics found.</div></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= (int) $r['id'] ?></td>
          <td><?= e($r['clinic_name']) ?><br><span class="text-muted small"><?= e($r['address']) ?></span></td>
          <td><?= e($r['city']) ?></td>
          <td><?= e($r['phone'] ?: '-') ?></td>
          <td><?= e(format_time($r['opening_time'])) ?> - <?= e(format_time($r['closing_time'])) ?></td>
          <td><?= (int) $r['doctor_count'] ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-id="<?= (int) $r['id'] ?>"
                    data-clinic-name="<?= e($r['clinic_name']) ?>"
                    data-address="<?= e($r['address']) ?>"
                    data-city="<?= e($r['city']) ?>"
                    data-state="<?= e($r['state']) ?>"
                    data-postal-code="<?= e($r['postal_code']) ?>"
                    data-phone="<?= e($r['phone']) ?>"
                    data-email="<?= e($r['email']) ?>"
                    data-opening-time="<?= e(substr($r['opening_time'], 0, 5)) ?>"
                    data-closing-time="<?= e(substr($r['closing_time'], 0, 5)) ?>">
              <?= icon('edit') ?>
            </button>
            <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this clinic?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><?= icon($r['status'] === 'active' ? 'close' : 'check') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'status' => $status]); ?></div>
</div>

<div class="table-card mt-4">
  <div class="table-card-head"><h2>Assign doctors to clinics</h2></div>
  <div class="card-body">
    <form method="post" class="filter-bar mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_doctor">
      <div>
        <label class="form-label">Doctor</label>
        <select class="form-select" name="doctor_id" required>
          <option value="">-- Select a doctor --</option>
          <?= options_from_rows($allDoctors, 'id', 'name') ?>
        </select>
      </div>
      <div>
        <label class="form-label">Clinic</label>
        <select class="form-select" name="clinic_id" required>
          <option value="">-- Select a clinic --</option>
          <?= options_from_rows(all_clinics(), 'id', 'clinic_name') ?>
        </select>
      </div>
      <div>
        <button class="btn btn-primary"><?= icon('check') ?> Assign</button>
      </div>
    </form>

    <h6 class="mb-2">Doctors not yet assigned to a clinic</h6>
    <?php if (!$unassignedDoctors): ?>
      <div class="empty"><?= icon('doctor') ?><div>Every doctor is currently assigned to a clinic.</div></div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Doctor</th><th>Phone</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($unassignedDoctors as $d): ?>
            <tr>
              <td>Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?></td>
              <td><?= e($d['phone']) ?></td>
              <td><?= status_badge($d['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add Clinic Modal -->
<div class="modal" id="addModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add Clinic</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-2"><label class="form-label">Clinic Name</label><input class="form-control" name="clinic_name" required></div>
     <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="address" required></div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">City</label><input class="form-control" name="city" required></div>
       <div class="col-md-6 mb-2"><label class="form-label">State</label><input class="form-control" name="state"></div>
     </div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">Postal Code</label><input class="form-control" name="postal_code"></div>
       <div class="col-md-6 mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
     </div>
     <div class="mb-2"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email"></div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">Opening Time</label><input type="time" class="form-control" name="opening_time" value="09:00" required></div>
       <div class="col-md-6 mb-2"><label class="form-label">Closing Time</label><input type="time" class="form-control" name="closing_time" value="18:00" required></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit Clinic Modal -->
<div class="modal" id="editModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="id" data-fill="id">
   <div class="modal-header"><h5 class="modal-title">Edit Clinic</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-2"><label class="form-label">Clinic Name</label><input class="form-control" name="clinic_name" data-fill="clinicName" required></div>
     <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="address" data-fill="address" required></div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">City</label><input class="form-control" name="city" data-fill="city" required></div>
       <div class="col-md-6 mb-2"><label class="form-label">State</label><input class="form-control" name="state" data-fill="state"></div>
     </div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">Postal Code</label><input class="form-control" name="postal_code" data-fill="postalCode"></div>
       <div class="col-md-6 mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" data-fill="phone"></div>
     </div>
     <div class="mb-2"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email" data-fill="email"></div>
     <div class="row">
       <div class="col-md-6 mb-2"><label class="form-label">Opening Time</label><input type="time" class="form-control" name="opening_time" data-fill="openingTime" required></div>
       <div class="col-md-6 mb-2"><label class="form-label">Closing Time</label><input type="time" class="form-control" name="closing_time" data-fill="closingTime" required></div>
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
