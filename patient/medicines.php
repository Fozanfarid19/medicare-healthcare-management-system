<?php
/**
 * MediCare - patient/medicines.php
 * The patient's medicines: doctor-prescribed and self-added.
 * A patient may fully manage medicines they added themselves; for
 * doctor-prescribed medicines only the reminder times can be changed.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('patient');

$patientId = current_patient_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('patient/medicines.php');
    $action = post('action');

    if ($action === 'create') {
        $name      = post('medicine_name');
        $generic   = post('generic_name');
        $category  = post('category');
        $dosage    = post('dosage');
        $doseUnit  = post('dose_unit');
        $quantity  = post('quantity');
        $frequency = post('frequency');
        $route     = post('route');
        $startDate = post('start_date');
        $endDate   = post('end_date');
        $instructions = post('instructions');
        $notes     = post('notes');
        $timesRaw  = post('reminder_times');

        $errors = [];
        if ($name === '')                                  { $errors[] = 'Medicine name is required.'; }
        if ($dosage === '')                                 { $errors[] = 'Dosage is required.'; }
        if (!in_array($doseUnit, dose_units(), true))       { $errors[] = 'Please select a valid dose unit.'; }
        if (!in_array($frequency, frequencies(), true))     { $errors[] = 'Please select a valid frequency.'; }
        if (!in_array($route, routes(), true))              { $errors[] = 'Please select a valid route.'; }
        if (!is_valid_date($startDate))                     { $errors[] = 'Please enter a valid start date.'; }
        if ($endDate !== '' && !is_valid_date($endDate))    { $errors[] = 'Please enter a valid end date.'; }
        if ($endDate !== '' && $startDate !== '' && $endDate < $startDate) { $errors[] = 'The end date cannot be before the start date.'; }
        if (!in_array($category, medicine_categories(), true)) { $errors[] = 'Please select a valid category.'; }

        if ($errors) {
            foreach ($errors as $msg) { flash_error($msg); }
        } else {
            $newId = db_insert(
                'INSERT INTO medicines
                    (patient_id, prescribed_by, medicine_name, generic_name, category, dosage, dose_unit,
                     quantity, frequency, route, start_date, end_date, instructions, notes, status)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$patientId, $name, null_if_blank($generic), $category, $dosage, $doseUnit,
                 null_if_blank($quantity), $frequency, $route, $startDate, null_if_blank($endDate),
                 null_if_blank($instructions), null_if_blank($notes), 'active']
            );
            save_reminder_times($newId, explode(',', $timesRaw));
            flash_success('Medicine added successfully.');
        }
    } elseif ($action === 'update') {
        $medId = post_int('medicine_id');
        $med   = db_row('SELECT * FROM medicines WHERE id = ? AND patient_id = ?', [$medId, $patientId]);

        if (!$med) {
            flash_error('Medicine not found.');
        } else {
            $timesRaw = post('reminder_times');

            if ($med['prescribed_by'] === null) {
                // Self-added medicine: the patient may edit everything.
                $name      = post('medicine_name');
                $generic   = post('generic_name');
                $category  = post('category');
                $dosage    = post('dosage');
                $doseUnit  = post('dose_unit');
                $quantity  = post('quantity');
                $frequency = post('frequency');
                $route     = post('route');
                $startDate = post('start_date');
                $endDate   = post('end_date');
                $instructions = post('instructions');
                $notes     = post('notes');

                $errors = [];
                if ($name === '')                               { $errors[] = 'Medicine name is required.'; }
                if ($dosage === '')                              { $errors[] = 'Dosage is required.'; }
                if (!in_array($doseUnit, dose_units(), true))    { $errors[] = 'Please select a valid dose unit.'; }
                if (!in_array($frequency, frequencies(), true))  { $errors[] = 'Please select a valid frequency.'; }
                if (!in_array($route, routes(), true))           { $errors[] = 'Please select a valid route.'; }
                if (!is_valid_date($startDate))                  { $errors[] = 'Please enter a valid start date.'; }
                if ($endDate !== '' && !is_valid_date($endDate)) { $errors[] = 'Please enter a valid end date.'; }
                if (!in_array($category, medicine_categories(), true)) { $errors[] = 'Please select a valid category.'; }

                if ($errors) {
                    foreach ($errors as $msg) { flash_error($msg); }
                } else {
                    db_execute(
                        'UPDATE medicines SET medicine_name = ?, generic_name = ?, category = ?, dosage = ?,
                                dose_unit = ?, quantity = ?, frequency = ?, route = ?, start_date = ?,
                                end_date = ?, instructions = ?, notes = ?
                          WHERE id = ? AND patient_id = ?',
                        [$name, null_if_blank($generic), $category, $dosage, $doseUnit, null_if_blank($quantity),
                         $frequency, $route, $startDate, null_if_blank($endDate), null_if_blank($instructions),
                         null_if_blank($notes), $medId, $patientId]
                    );
                    save_reminder_times($medId, explode(',', $timesRaw));
                    flash_success('Medicine updated successfully.');
                }
            } else {
                // Doctor-prescribed medicine: only reminder times may change.
                save_reminder_times($medId, explode(',', $timesRaw));
                flash_success('Reminder times updated. Other details of a doctor-prescribed medicine cannot be changed here.');
            }
        }
    } elseif ($action === 'deactivate' || $action === 'activate') {
        $medId    = post_int('medicine_id');
        $newState = $action === 'deactivate' ? 'inactive' : 'active';
        $affected = db_execute(
            'UPDATE medicines SET status = ? WHERE id = ? AND patient_id = ?',
            [$newState, $medId, $patientId]
        );
        if ($affected) {
            flash_success('Medicine ' . ($newState === 'active' ? 'activated' : 'deactivated') . '.');
        } else {
            flash_error('Medicine not found.');
        }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/medicines.php');
}

// ---------- 2. Filters + list ----------
$search       = get_param('q');
$statusFilter = get_param('status');
$prescribedBy = get_param('prescribed_by'); // '' = all, '0' = self added, numeric = doctor id

$where  = ['m.patient_id = ?'];
$params = [$patientId];

if ($search !== '') {
    $where[] = '(m.medicine_name LIKE ? OR m.generic_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive', 'discontinued'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $statusFilter;
}
if ($prescribedBy === '0') {
    $where[] = 'm.prescribed_by IS NULL';
} elseif ($prescribedBy !== '') {
    $where[] = 'm.prescribed_by = ?';
    $params[] = (int) $prescribedBy;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_scalar("SELECT COUNT(*) FROM medicines m $whereSql", $params);
$pg    = paginate($total);

$medicines = db_all(
    "SELECT m.*, d.first_name AS doc_first, d.last_name AS doc_last
       FROM medicines m
       LEFT JOIN doctors d ON d.id = m.prescribed_by
      $whereSql
      ORDER BY m.status = 'active' DESC, m.medicine_name
      LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$prescribers = db_all(
    "SELECT DISTINCT d.id, d.first_name, d.last_name
       FROM medicines m JOIN doctors d ON d.id = m.prescribed_by
      WHERE m.patient_id = ? ORDER BY d.first_name",
    [$patientId]
);

// Suggested reminder times per frequency, embedded for the JS pre-fill.
$suggestedTimesMap = [];
foreach (frequencies() as $f) { $suggestedTimesMap[$f] = suggested_times($f); }

$page_title = 'My Medicines';
$active     = 'medicines';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / My Medicines</div>
        <h1>My Medicines</h1>
        <div class="sub">Doctor-prescribed and self-added medicines.</div>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><?= icon('plus') ?> Add Medicine</button>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Medicine or generic name">
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select auto-submit" name="status">
                <option value="">All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="discontinued" <?= $statusFilter === 'discontinued' ? 'selected' : '' ?>>Discontinued</option>
            </select>
        </div>
        <div>
            <label class="form-label">Prescribed By</label>
            <select class="form-select auto-submit" name="prescribed_by">
                <option value="">All</option>
                <option value="0" <?= $prescribedBy === '0' ? 'selected' : '' ?>>Self added</option>
                <?php foreach ($prescribers as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $prescribedBy === (string) $p['id'] ? 'selected' : '' ?>>
                        Dr. <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button class="btn btn-primary"><?= icon('search') ?> Search</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('patient/medicines.php')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Medicine</th><th>Category</th><th>Dose</th><th>Quantity</th><th>Frequency</th>
                    <th>Route</th><th>Period</th><th>Prescribed By</th><th>Reminder Times</th><th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$medicines): ?>
                <tr><td colspan="11"><div class="empty"><?= icon('pill') ?><div>No medicines found.</div></div></td></tr>
            <?php else: foreach ($medicines as $m):
                $owned = $m['prescribed_by'] === null;
                $times = implode(', ', medicine_reminder_times($m['id'], false));
            ?>
                <tr>
                    <td><?= e($m['medicine_name']) ?><?php if ($m['generic_name']): ?><div class="small text-muted"><?= e($m['generic_name']) ?></div><?php endif; ?></td>
                    <td><?= e($m['category'] ?? '-') ?></td>
                    <td><?= e(trim($m['dosage'] . ' ' . $m['dose_unit'])) ?></td>
                    <td><?= e($m['quantity'] ?? '-') ?></td>
                    <td><?= e($m['frequency']) ?></td>
                    <td><?= e($m['route']) ?></td>
                    <td><?= e(format_date($m['start_date'])) ?> - <?= $m['end_date'] ? e(format_date($m['end_date'])) : 'Ongoing' ?></td>
                    <td><?= $owned ? '<span class="badge bg-secondary">Self added</span>' : 'Dr. ' . e($m['doc_first'] . ' ' . $m['doc_last']) ?></td>
                    <td><?= reminder_times_label($m['id']) ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal"
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
                            data-reminder-times="<?= e($times) ?>"
                            data-owned="<?= $owned ? '1' : '0' ?>"
                        ><?= icon('edit') ?></button>
                        <?php if ($m['status'] === 'active'): ?>
                        <form method="post" class="d-inline" data-confirm="Deactivate this medicine?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="medicine_id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= icon('close') ?></button>
                        </form>
                        <?php elseif ($m['status'] === 'inactive'): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="medicine_id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success"><?= icon('check') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search, 'status' => $statusFilter, 'prescribed_by' => $prescribedBy]); ?></div>
</div>

<!-- Add Medicine Modal -->
<div class="modal" id="addModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add a Medicine</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Medicine Name *</label>
            <input type="text" class="form-control" name="medicine_name" maxlength="120" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Generic Name</label>
            <input type="text" class="form-control" name="generic_name" maxlength="120">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Category *</label>
            <select class="form-select" name="category" required>
                <?= options_from(medicine_categories()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Dosage *</label>
            <input type="text" class="form-control" name="dosage" placeholder="e.g. 500" maxlength="30" required>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Dose Unit *</label>
            <select class="form-select" name="dose_unit" required>
                <?= options_from(dose_units()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Quantity</label>
            <input type="text" class="form-control" name="quantity" placeholder="e.g. 1 tablet" maxlength="30">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Frequency *</label>
            <select class="form-select" name="frequency" id="add_frequency" required>
                <?= options_from(frequencies()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Route *</label>
            <select class="form-select" name="route" required>
                <?= options_from(routes()) ?>
            </select>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Start Date *</label>
            <input type="date" class="form-control" name="start_date" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control" name="end_date">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Instructions</label>
            <input type="text" class="form-control" name="instructions" maxlength="200" placeholder="e.g. Take after food">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Reminder Times *</label>
            <input type="text" class="form-control" id="add_reminder_times" name="reminder_times" placeholder="e.g. 08:00, 20:00" required>
            <span class="form-text">Comma separated 24-hour times. Pre-filled from the chosen frequency; you can edit them.</span>
        </div>
        <div class="col-12 mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2" maxlength="500"></textarea>
        </div>
    </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary"><?= icon('check') ?> Save Medicine</button></div>
  </form>
 </div></div>
</div>

<!-- Edit Medicine Modal -->
<div class="modal" id="editModal" data-autofill tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post" id="editForm">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="medicine_id" data-fill="id">
   <div class="modal-header"><h5 class="modal-title">Edit Medicine</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
    <div class="alert alert-info" id="editNote" style="display:none">
        This medicine was prescribed by a doctor. You may only change its reminder times here.
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Medicine Name *</label>
            <input type="text" class="form-control" name="medicine_name" data-fill="medicineName" maxlength="120" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Generic Name</label>
            <input type="text" class="form-control" name="generic_name" data-fill="genericName" maxlength="120">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Category *</label>
            <select class="form-select" name="category" data-fill="category" required>
                <?= options_from(medicine_categories()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Dosage *</label>
            <input type="text" class="form-control" name="dosage" data-fill="dosage" maxlength="30" required>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Dose Unit *</label>
            <select class="form-select" name="dose_unit" data-fill="doseUnit" required>
                <?= options_from(dose_units()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Quantity</label>
            <input type="text" class="form-control" name="quantity" data-fill="quantity" maxlength="30">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Frequency *</label>
            <select class="form-select" name="frequency" data-fill="frequency" required>
                <?= options_from(frequencies()) ?>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Route *</label>
            <select class="form-select" name="route" data-fill="route" required>
                <?= options_from(routes()) ?>
            </select>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Start Date *</label>
            <input type="date" class="form-control" name="start_date" data-fill="startDate" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control" name="end_date" data-fill="endDate">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Instructions</label>
            <input type="text" class="form-control" name="instructions" data-fill="instructions" maxlength="200">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Reminder Times *</label>
            <input type="text" class="form-control" name="reminder_times" data-fill="reminderTimes" required>
            <span class="form-text">Comma separated 24-hour times, e.g. 08:00, 20:00.</span>
        </div>
        <div class="col-12 mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" data-fill="notes" rows="2" maxlength="500"></textarea>
        </div>
    </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary"><?= icon('check') ?> Save Changes</button></div>
  </form>
 </div></div>
</div>

<script>
var MEDICARE_SUGGESTED_TIMES = <?= json_encode($suggestedTimesMap) ?>;

document.addEventListener('DOMContentLoaded', function () {
    var addFreq  = document.getElementById('add_frequency');
    var addTimes = document.getElementById('add_reminder_times');

    function fillSuggested(select, input) {
        var times = MEDICARE_SUGGESTED_TIMES[select.value] || [];
        input.value = times.join(', ');
    }
    if (addFreq && addTimes) {
        addFreq.addEventListener('change', function () { fillSuggested(addFreq, addTimes); });
        fillSuggested(addFreq, addTimes);
    }

    // Disable every field except reminder_times for doctor-prescribed medicines.
    var editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget || (event.detail && event.detail.relatedTarget) || null;
            var owned = trigger ? trigger.dataset.owned === '1' : true;
            var note  = document.getElementById('editNote');
            note.style.display = owned ? 'none' : 'block';

            editModal.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.name === 'reminder_times' || field.name === 'medicine_id' || field.name === 'csrf_token' || field.name === 'action') {
                    field.disabled = false;
                    return;
                }
                field.disabled = !owned;
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
