<?php
/**
 * MediCare - admin/appointments.php
 * Full appointment list with filters, status/payment actions and the
 * ability to book a new appointment on behalf of a patient.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('admin');

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('admin/appointments.php');
    $action = post('action');

    if ($action === 'set_status') {
        $id     = post_int('id');
        $status = post('status');
        $result = change_appointment_status($id, $status, 'admin');
        $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);
    } elseif ($action === 'mark_paid') {
        $id     = post_int('id');
        $result = mark_appointment_paid($id);
        $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);
    } elseif ($action === 'book') {
        $patientId = post_int('patient_id');
        $doctorId  = post_int('doctor_id');
        $date      = post('appointment_date');
        $start     = post('start_time');
        $reason    = post('reason');

        if (!$patientId || !$doctorId || $date === '' || $start === '') {
            flash_error('Please select a patient, a doctor, a date and a time slot.');
        } else {
            $result = book_appointment($patientId, $doctorId, $date, $start, $reason);
            $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);
        }
    }

    redirect('admin/appointments.php');
}

// ---------- 2. Read filters + query data ----------
$search    = get_param('q');
$status    = get_param('status');
$doctorId  = get_int('doctor_id');
$clinicId  = get_int('clinic_id');
$dateFrom  = get_param('date_from');
$dateTo    = get_param('date_to');

$where  = []; $params = [];
if ($search !== '') {
    $where[]  = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR CONCAT(d.first_name,' ',d.last_name) LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status !== '' && in_array($status, appointment_statuses(), true)) {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}
if ($doctorId) {
    $where[]  = 'a.doctor_id = ?';
    $params[] = $doctorId;
}
if ($clinicId) {
    $where[]  = 'a.clinic_id = ?';
    $params[] = $clinicId;
}
if ($dateFrom !== '' && is_valid_date($dateFrom)) {
    $where[]  = 'a.appointment_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && is_valid_date($dateTo)) {
    $where[]  = 'a.appointment_date <= ?';
    $params[] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_scalar('SELECT COUNT(*) FROM (' . appointment_select_sql() . " $whereSql) AS t", $params);
$pg    = paginate($total);
$rows  = db_all(
    appointment_select_sql() . " $whereSql ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// ---------- Summary stats (system wide, not affected by the filter bar) ----------
$statToday     = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()");
$statPending   = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'pending'");
$statConfirmed = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'confirmed'");
$statCompleted = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'completed'");
$statCancelled = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'cancelled'");
$statRevenue   = (float) db_scalar("SELECT COALESCE(SUM(consultation_fee),0) FROM appointments WHERE payment_status = 'paid'");

// ---------- Drop-down data ----------
$allDoctors  = db_all("SELECT id, CONCAT('Dr. ', first_name, ' ', last_name) AS name FROM doctors WHERE status = 'active' ORDER BY first_name, last_name");
$allPatients = db_all("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM patients WHERE status = 'active' ORDER BY first_name, last_name");
$allClinics  = db_all('SELECT id, clinic_name FROM clinics ORDER BY clinic_name');

// ---------- 3. Render ----------
$page_title = 'Appointments';
$active     = 'appointments';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Appointments</div>
    <h1>Appointment Management</h1>
    <div class="sub">Review, filter and manage every appointment in the system.</div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookModal"><?= icon('plus') ?> New Appointment</button>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat-ic"><?= icon('calendar') ?></div>
    <div><div class="stat-value"><?= (int) $total ?></div><div class="stat-label">Matching Filter</div></div></div>
  <div class="stat navy"><div class="stat-ic"><?= icon('clock') ?></div>
    <div><div class="stat-value"><?= $statToday ?></div><div class="stat-label">Today</div></div></div>
  <div class="stat amber"><div class="stat-ic"><?= icon('alert-circle') ?></div>
    <div><div class="stat-value"><?= $statPending ?></div><div class="stat-label">Pending</div></div></div>
  <div class="stat"><div class="stat-ic"><?= icon('check-circle') ?></div>
    <div><div class="stat-value"><?= $statConfirmed ?></div><div class="stat-label">Confirmed</div></div></div>
  <div class="stat green"><div class="stat-ic"><?= icon('check') ?></div>
    <div><div class="stat-value"><?= $statCompleted ?></div><div class="stat-label">Completed</div></div></div>
  <div class="stat red"><div class="stat-ic"><?= icon('close') ?></div>
    <div><div class="stat-value"><?= $statCancelled ?></div><div class="stat-label">Cancelled</div></div></div>
  <div class="stat green"><div class="stat-ic"><?= icon('money') ?></div>
    <div><div class="stat-value"><?= e(money($statRevenue)) ?></div><div class="stat-label">Revenue Collected</div></div></div>
</div>

<div class="table-card mt-4">
  <form class="filter-bar" method="get">
    <div class="grow">
      <label class="form-label">Search</label>
      <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Patient or doctor name">
    </div>
    <div>
      <label class="form-label">Status</label>
      <select class="form-select auto-submit" name="status">
        <option value="">All</option>
        <?php foreach (appointment_statuses() as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(nice_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Doctor</label>
      <select class="form-select auto-submit" name="doctor_id">
        <option value="">All</option>
        <?= options_from_rows($allDoctors, 'id', 'name', $doctorId) ?>
      </select>
    </div>
    <div>
      <label class="form-label">Clinic</label>
      <select class="form-select auto-submit" name="clinic_id">
        <option value="">All</option>
        <?= options_from_rows($allClinics, 'id', 'clinic_name', $clinicId) ?>
      </select>
    </div>
    <div>
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
    </div>
    <div>
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
    </div>
    <div>
      <button class="btn btn-primary"><?= icon('search') ?> Search</button>
      <a class="btn btn-outline-secondary" href="<?= e(url('admin/appointments.php')) ?>">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>#</th><th>Patient</th><th>Doctor</th><th>Date &amp; Time</th>
          <th>Fee</th><th>Payment</th><th>Status</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8"><div class="empty"><?= icon('search') ?><div>No appointments found.</div></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= (int) $r['id'] ?></td>
          <td><?= e($r['patient_name']) ?></td>
          <td><?= e($r['doctor_name']) ?></td>
          <td><?= e(format_date($r['appointment_date'])) ?> <?= e(format_time($r['start_time'])) ?></td>
          <td><?= e(money($r['consultation_fee'])) ?></td>
          <td><?= status_badge($r['payment_status']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#viewModal"
                    data-id="<?= (int) $r['id'] ?>"
                    data-patient-name="<?= e($r['patient_name']) ?>"
                    data-patient-phone="<?= e($r['patient_phone']) ?>"
                    data-doctor-name="<?= e($r['doctor_name']) ?>"
                    data-specialization-name="<?= e($r['specialization_name'] ?: '-') ?>"
                    data-clinic-name="<?= e($r['clinic_name'] ?: '-') ?>"
                    data-appointment-date="<?= e(format_date($r['appointment_date'])) ?>"
                    data-time-range="<?= e(format_time($r['start_time']) . ' - ' . format_time($r['end_time'])) ?>"
                    data-reason="<?= e($r['reason'] ?: '-') ?>"
                    data-notes="<?= e($r['notes'] ?: '-') ?>"
                    data-fee="<?= e(money($r['consultation_fee'])) ?>"
                    data-payment-status="<?= e(nice_label($r['payment_status'])) ?>"
                    data-status="<?= e(nice_label($r['status'])) ?>">
              <?= icon('eye') ?>
            </button>

            <?php if (!in_array($r['status'], ['completed', 'cancelled'], true)): ?>
            <div class="dropdown d-inline">
              <form method="post" class="d-inline-flex gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="width:auto">
                  <?php foreach (appointment_statuses() as $s): ?>
                    <option value="<?= e($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= e(nice_label($s)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary" data-confirm="Update the status of this appointment?"><?= icon('check') ?></button>
              </form>
            </div>
            <?php endif; ?>

            <?php if ($r['payment_status'] !== 'paid'): ?>
            <form method="post" class="d-inline" data-confirm="Mark this consultation fee as paid?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-success"><?= icon('money') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="px-3 pb-3">
    <?php render_pagination($pg, ['q' => $search, 'status' => $status, 'doctor_id' => $doctorId, 'clinic_id' => $clinicId, 'date_from' => $dateFrom, 'date_to' => $dateTo]); ?>
  </div>
</div>

<!-- View Modal -->
<div class="modal" id="viewModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog"><div class="modal-content">
   <div class="modal-header"><h5 class="modal-title">Appointment Details</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <table class="table table-sm">
       <tr><th>Patient</th><td data-fill="patientName"></td></tr>
       <tr><th>Patient Phone</th><td data-fill="patientPhone"></td></tr>
       <tr><th>Doctor</th><td data-fill="doctorName"></td></tr>
       <tr><th>Specialization</th><td data-fill="specializationName"></td></tr>
       <tr><th>Clinic</th><td data-fill="clinicName"></td></tr>
       <tr><th>Date</th><td data-fill="appointmentDate"></td></tr>
       <tr><th>Time</th><td data-fill="timeRange"></td></tr>
       <tr><th>Reason</th><td data-fill="reason"></td></tr>
       <tr><th>Notes</th><td data-fill="notes"></td></tr>
       <tr><th>Fee</th><td data-fill="fee"></td></tr>
       <tr><th>Payment</th><td data-fill="paymentStatus"></td></tr>
       <tr><th>Status</th><td data-fill="status"></td></tr>
     </table>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
   </div>
 </div></div>
</div>

<!-- New Appointment (on behalf of a patient) Modal -->
<div class="modal" id="bookModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post" id="bookForm">
   <?= csrf_field() ?><input type="hidden" name="action" value="book">
   <input type="hidden" name="start_time" id="bookSlotInput" value="">
   <div class="modal-header"><h5 class="modal-title">New Appointment</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-2">
       <label class="form-label">Patient</label>
       <select class="form-select" name="patient_id" required>
         <option value="">-- Select a patient --</option>
         <?= options_from_rows($allPatients, 'id', 'name') ?>
       </select>
     </div>
     <div class="mb-2">
       <label class="form-label">Doctor</label>
       <select class="form-select" name="doctor_id" id="bookDoctor" required>
         <option value="">-- Select a doctor --</option>
         <?= options_from_rows($allDoctors, 'id', 'name') ?>
       </select>
     </div>
     <div class="mb-2">
       <label class="form-label">Date</label>
       <input type="date" class="form-control" name="appointment_date" id="bookDate" min="<?= e(date('Y-m-d')) ?>" required>
     </div>
     <div class="mb-2">
       <label class="form-label">Available Slots</label>
       <div id="bookSlots" class="d-flex flex-wrap gap-2"></div>
       <div id="bookSlotMsg" class="text-muted small mt-1"></div>
     </div>
     <div class="mb-2">
       <label class="form-label">Reason</label>
       <input class="form-control" name="reason" maxlength="200" placeholder="Reason for the visit">
     </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Book Appointment</button></div>
  </form>
 </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var doctorSelect = document.getElementById('bookDoctor');
  var dateInput    = document.getElementById('bookDate');
  var slotsBox     = document.getElementById('bookSlots');
  var slotInput    = document.getElementById('bookSlotInput');
  var msgBox       = document.getElementById('bookSlotMsg');

  function loadSlots() {
    var doctorId = doctorSelect.value;
    var date     = dateInput.value;
    slotsBox.innerHTML = '';
    slotInput.value    = '';
    msgBox.textContent  = '';
    if (!doctorId || !date) { return; }

    window.MediCare.get('api/appointment_slots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(date))
      .then(function (res) {
        if (!res.success || !res.slots || !res.slots.length) {
          msgBox.textContent = (res && res.message) ? res.message : 'No slots available for this date.';
          return;
        }
        res.slots.forEach(function (slot) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot';
          btn.textContent = slot.label;
          btn.addEventListener('click', function () {
            slotsBox.querySelectorAll('.slot').forEach(function (s) { s.classList.remove('selected'); });
            btn.classList.add('selected');
            slotInput.value = slot.start;
          });
          slotsBox.appendChild(btn);
        });
      });
  }

  if (doctorSelect && dateInput) {
    doctorSelect.addEventListener('change', loadSlots);
    dateInput.addEventListener('change', loadSlots);
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
