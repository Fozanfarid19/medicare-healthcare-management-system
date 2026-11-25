<?php
/**
 * MediCare - doctor/medicines.php
 * Medicines prescribed by the logged-in doctor (prescribed_by = current_doctor_id()).
 * Every write below re-checks that ownership in its WHERE clause.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('doctor');

$doctorId = current_doctor_id();
$relatedPatientsSql = "SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?
                        UNION
                        SELECT DISTINCT patient_id FROM medicines WHERE prescribed_by = ?";

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/medicines.php');
    $action = post('action');
    $id     = post_int('id');

    if ($action === 'create') {
        $patientId = post_int('patient_id');
        $name      = post('medicine_name');
        $dosage    = post('dosage');
        $startDate = post('start_date');

        $errors = [];
        if ($patientId <= 0 || !db_exists("SELECT 1 FROM patients WHERE id = ? AND id IN ($relatedPatientsSql)", [$patientId, $doctorId, $doctorId])) {
            $errors[] = 'Please select a valid patient.';
        }
        if ($name === '')                    { $errors[] = 'Please enter the medicine name.'; }
        if ($dosage === '')                  { $errors[] = 'Please enter the dosage.'; }
        if (!is_valid_date($startDate))      { $errors[] = 'Please enter a valid start date.'; }

        if ($errors) {
            flash_error(implode(' ', $errors));
            redirect('doctor/medicines.php' . ($patientId ? '?new=1&patient_id=' . $patientId : '?new=1'));
        }

        $endDate = post('end_date');
        $medicineId = db_insert(
            "INSERT INTO medicines
                (patient_id, prescribed_by, medicine_name, generic_name, category, dosage, dose_unit,
                 quantity, frequency, route, start_date, end_date, instructions, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [
                $patientId, $doctorId, $name, null_if_blank(post('generic_name')), null_if_blank(post('category')),
                $dosage,
                in_array(post('dose_unit'), dose_units(), true) ? post('dose_unit') : 'mg',
                null_if_blank(post('quantity')),
                in_array(post('frequency'), frequencies(), true) ? post('frequency') : 'Once Daily',
                in_array(post('route'), routes(), true) ? post('route') : 'Oral',
                $startDate, $endDate !== '' && is_valid_date($endDate) ? $endDate : null,
                null_if_blank(post('instructions')), null_if_blank(post('notes')),
            ]
        );

        $times = array_filter(array_map('trim', explode(',', post('reminder_times'))));
        save_reminder_times($medicineId, $times);

        $patientUser = db_scalar('SELECT user_id FROM patients WHERE id = ?', [$patientId]);
        notify((int) $patientUser, 'New medicine prescribed',
               'Dr. ' . current_name() . ' prescribed ' . $name . ' for you.', 'medicine', 'patient/medicines.php');

        flash_success('Medicine prescribed successfully.');
        redirect('doctor/medicines.php');
    } elseif ($action === 'update') {
        $owns = db_exists('SELECT 1 FROM medicines WHERE id = ? AND prescribed_by = ?', [$id, $doctorId]);
        $name      = post('medicine_name');
        $dosage    = post('dosage');
        $startDate = post('start_date');

        $errors = [];
        if (!$owns)                          { $errors[] = 'That medicine does not belong to you.'; }
        if ($name === '')                    { $errors[] = 'Please enter the medicine name.'; }
        if ($dosage === '')                  { $errors[] = 'Please enter the dosage.'; }
        if (!is_valid_date($startDate))      { $errors[] = 'Please enter a valid start date.'; }

        if ($errors) {
            flash_error(implode(' ', $errors));
        } else {
            $endDate = post('end_date');
            db_execute(
                "UPDATE medicines SET medicine_name = ?, generic_name = ?, category = ?, dosage = ?, dose_unit = ?,
                        quantity = ?, frequency = ?, route = ?, start_date = ?, end_date = ?, instructions = ?, notes = ?
                  WHERE id = ? AND prescribed_by = ?",
                [
                    $name, null_if_blank(post('generic_name')), null_if_blank(post('category')), $dosage,
                    in_array(post('dose_unit'), dose_units(), true) ? post('dose_unit') : 'mg',
                    null_if_blank(post('quantity')),
                    in_array(post('frequency'), frequencies(), true) ? post('frequency') : 'Once Daily',
                    in_array(post('route'), routes(), true) ? post('route') : 'Oral',
                    $startDate, $endDate !== '' && is_valid_date($endDate) ? $endDate : null,
                    null_if_blank(post('instructions')), null_if_blank(post('notes')),
                    $id, $doctorId,
                ]
            );
            $times = array_filter(array_map('trim', explode(',', post('reminder_times'))));
            save_reminder_times($id, $times);
            flash_success('Medicine updated.');
        }
    } elseif ($action === 'discontinue') {
        $owns = db_exists('SELECT 1 FROM medicines WHERE id = ? AND prescribed_by = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That medicine does not belong to you.');
        } else {
            db_execute("UPDATE medicines SET status = 'discontinued' WHERE id = ? AND prescribed_by = ?", [$id, $doctorId]);
            flash_success('Medicine discontinued.');
        }
    } elseif ($action === 'reactivate') {
        $owns = db_exists('SELECT 1 FROM medicines WHERE id = ? AND prescribed_by = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That medicine does not belong to you.');
        } else {
            db_execute("UPDATE medicines SET status = 'active' WHERE id = ? AND prescribed_by = ?", [$id, $doctorId]);
            flash_success('Medicine reactivated.');
        }
    }

    redirect('doctor/medicines.php');
}

// ---------- 2. Read filters + query data ----------
$search    = get_param('q');
$patientId = get_int('patient');
$status    = get_param('status');

$where  = ['m.prescribed_by = ?'];
$params = [$doctorId];

if ($search !== '') {
    $where[]  = "(m.medicine_name LIKE ? OR m.generic_name LIKE ? OR CONCAT(p.first_name,' ',p.last_name) LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($patientId > 0) {
    $where[]  = 'm.patient_id = ?';
    $params[] = $patientId;
}
if ($status !== '' && in_array($status, ['active', 'inactive', 'discontinued'], true)) {
    $where[]  = 'm.status = ?';
    $params[] = $status;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_scalar("SELECT COUNT(*) FROM medicines m JOIN patients p ON p.id = m.patient_id $whereSql", $params);
$pg    = paginate($total);

$medicines = db_all(
    "SELECT m.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.id AS patient_id
       FROM medicines m JOIN patients p ON p.id = m.patient_id
       $whereSql
       ORDER BY m.created_at DESC
       LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$myPatients = db_all(
    "SELECT id, CONCAT(first_name,' ',last_name) AS full_name FROM patients WHERE id IN ($relatedPatientsSql) ORDER BY full_name",
    [$doctorId, $doctorId]
);

$isNew = get_param('new') === '1';
$presetPatientId = get_int('patient_id');

$freqTimesMap = [];
foreach (frequencies() as $f) { $freqTimesMap[$f] = suggested_times($f); }

$page_title = 'Medicines';
$active     = 'medicines';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Medicines</div>
        <h1>Medicines</h1>
        <div class="sub">Medicines you have prescribed to your patients.</div>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#prescribeModal"><?= icon('plus') ?> Prescribe Medicine</button>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Medicine or patient name">
        </div>
        <div>
            <label class="form-label">Patient</label>
            <select class="form-select auto-submit" name="patient">
                <option value="">All Patients</option>
                <?= options_from_rows($myPatients, 'id', 'full_name', $patientId ?: '') ?>
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select auto-submit" name="status">
                <option value="">All Statuses</option>
                <?= options_from(['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'], $status) ?>
            </select>
        </div>
        <div><button class="btn btn-primary"><?= icon('search') ?> Search</button>
             <a class="btn btn-outline-secondary" href="<?= e(url('doctor/medicines.php')) ?>">Reset</a></div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Patient</th><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Reminders</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$medicines): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('pill') ?><div>No medicines found.</div></div></td></tr>
            <?php else: foreach ($medicines as $m): ?>
                <tr>
                    <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $m['patient_id'])) ?>"><?= e($m['patient_name']) ?></a></td>
                    <td><?= e($m['medicine_name']) ?><?php if ($m['generic_name']): ?><div class="text-muted small"><?= e($m['generic_name']) ?></div><?php endif; ?></td>
                    <td><?= e(dose_label($m)) ?></td>
                    <td><?= e($m['frequency']) ?></td>
                    <td><?= reminder_times_label($m['id']) ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editMedicineModal"
                            data-id="<?= (int) $m['id'] ?>"
                            data-medicine-name="<?= e($m['medicine_name']) ?>"
                            data-generic-name="<?= e($m['generic_name']) ?>"
                            data-category="<?= e($m['category']) ?>"
                            data-dosage="<?= e($m['dosage']) ?>"
                            data-dose-unit="<?= e($m['dose_unit']) ?>"
                            data-quantity="<?= e($m['quantity']) ?>"
                            data-frequency="<?= e($m['frequency']) ?>"
                            data-route="<?= e($m['route']) ?>"
                            data-start-date="<?= e($m['start_date']) ?>"
                            data-end-date="<?= e($m['end_date']) ?>"
                            data-instructions="<?= e($m['instructions']) ?>"
                            data-notes="<?= e($m['notes']) ?>"
                            data-reminder-times="<?= e(implode(', ', medicine_reminder_times($m['id']))) ?>">
                            <?= icon('edit') ?>
                        </button>
                        <?php if ($m['status'] === 'discontinued'): ?>
                            <form method="post" action="<?= e(url('doctor/medicines.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reactivate">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <button class="btn btn-sm btn-outline-success"><?= icon('check') ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('doctor/medicines.php')) ?>" class="d-inline" data-confirm="Discontinue this medicine?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="discontinue">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><?= icon('close') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'patient' => $patientId ?: '', 'status' => $status]); ?></div>
</div>

<!-- Prescribe (create) modal -->
<div class="modal" id="prescribeModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post" action="<?= e(url('doctor/medicines.php')) ?>">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Prescribe Medicine</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
       <div class="row g-3">
           <div class="col-md-6">
               <label class="form-label">Patient</label>
               <select class="form-select" name="patient_id" required>
                   <option value="">-- Select patient --</option>
                   <?php foreach ($myPatients as $p): ?>
                       <option value="<?= (int) $p['id'] ?>"<?= $presetPatientId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option>
                   <?php endforeach; ?>
               </select>
           </div>
           <div class="col-md-6">
               <label class="form-label">Medicine Name</label>
               <input type="text" class="form-control" name="medicine_name" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">Generic Name</label>
               <input type="text" class="form-control" name="generic_name">
           </div>
           <div class="col-md-6">
               <label class="form-label">Category</label>
               <select class="form-select" name="category">
                   <option value="">-- Select --</option>
                   <?= options_from(medicine_categories()) ?>
               </select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Dosage</label>
               <input type="text" class="form-control" name="dosage" placeholder="e.g. 500" required>
           </div>
           <div class="col-md-4">
               <label class="form-label">Dose Unit</label>
               <select class="form-select" name="dose_unit"><?= options_from(dose_units()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Quantity</label>
               <input type="text" class="form-control" name="quantity" placeholder="e.g. 1 tablet">
           </div>
           <div class="col-md-4">
               <label class="form-label">Route</label>
               <select class="form-select" name="route"><?= options_from(routes()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Frequency</label>
               <select class="form-select" name="frequency" id="createFrequency"><?= options_from(frequencies()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Start Date</label>
               <input type="date" class="form-control" name="start_date" value="<?= e(date('Y-m-d')) ?>" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">End Date (optional)</label>
               <input type="date" class="form-control" name="end_date">
           </div>
           <div class="col-md-6">
               <label class="form-label">Reminder Times <span class="text-muted small">(comma separated)</span></label>
               <input type="text" class="form-control" name="reminder_times" id="createReminderTimes" value="08:00">
           </div>
           <div class="col-12">
               <label class="form-label">Instructions</label>
               <input type="text" class="form-control" name="instructions" placeholder="e.g. Take after food">
           </div>
           <div class="col-12">
               <label class="form-label">Notes</label>
               <textarea class="form-control" name="notes" rows="2"></textarea>
           </div>
       </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit modal -->
<div class="modal" id="editMedicineModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post" action="<?= e(url('doctor/medicines.php')) ?>">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="id" data-fill="id">
   <div class="modal-header"><h5 class="modal-title">Edit Medicine</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
       <div class="row g-3">
           <div class="col-md-6">
               <label class="form-label">Medicine Name</label>
               <input type="text" class="form-control" name="medicine_name" data-fill="medicineName" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">Generic Name</label>
               <input type="text" class="form-control" name="generic_name" data-fill="genericName">
           </div>
           <div class="col-md-6">
               <label class="form-label">Category</label>
               <select class="form-select" name="category" data-fill="category">
                   <option value="">-- Select --</option>
                   <?= options_from(medicine_categories()) ?>
               </select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Dosage</label>
               <input type="text" class="form-control" name="dosage" data-fill="dosage" required>
           </div>
           <div class="col-md-4">
               <label class="form-label">Dose Unit</label>
               <select class="form-select" name="dose_unit" data-fill="doseUnit"><?= options_from(dose_units()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Quantity</label>
               <input type="text" class="form-control" name="quantity" data-fill="quantity">
           </div>
           <div class="col-md-4">
               <label class="form-label">Route</label>
               <select class="form-select" name="route" data-fill="route"><?= options_from(routes()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Frequency</label>
               <select class="form-select" name="frequency" data-fill="frequency"><?= options_from(frequencies()) ?></select>
           </div>
           <div class="col-md-4">
               <label class="form-label">Start Date</label>
               <input type="date" class="form-control" name="start_date" data-fill="startDate" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">End Date (optional)</label>
               <input type="date" class="form-control" name="end_date" data-fill="endDate">
           </div>
           <div class="col-md-6">
               <label class="form-label">Reminder Times <span class="text-muted small">(comma separated)</span></label>
               <input type="text" class="form-control" name="reminder_times" data-fill="reminderTimes">
           </div>
           <div class="col-12">
               <label class="form-label">Instructions</label>
               <input type="text" class="form-control" name="instructions" data-fill="instructions">
           </div>
           <div class="col-12">
               <label class="form-label">Notes</label>
               <textarea class="form-control" name="notes" rows="2" data-fill="notes"></textarea>
           </div>
       </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<script>
(function () {
    var FREQ_TIMES = <?= json_encode($freqTimesMap) ?>;

    var createFreq = document.getElementById('createFrequency');
    var createTimes = document.getElementById('createReminderTimes');
    if (createFreq && createTimes) {
        createFreq.addEventListener('change', function () {
            if (FREQ_TIMES[createFreq.value]) { createTimes.value = FREQ_TIMES[createFreq.value].join(', '); }
        });
    }

    <?php if ($isNew): ?>
    document.addEventListener('DOMContentLoaded', function () { window.MediCare.openModal('#prescribeModal'); });
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
