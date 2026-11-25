<?php
/**
 * MediCare - doctor/prescriptions.php
 * Prescriptions written by the logged-in doctor: list, detail view and
 * a "new prescription" form that can create several medicines at once.
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
    verify_csrf('doctor/prescriptions.php');
    $action = post('action');

    if ($action === 'create') {
        $patientId        = post_int('patient_id');
        $appointmentId    = post_int('appointment_id');
        $prescriptionDate = post('prescription_date');
        $diagnosis        = post('diagnosis');
        $notes            = post('notes');
        $medBlocks        = $_POST['medicines'] ?? [];

        $errors = [];

        // Patient must be one this doctor is actually allowed to treat.
        $patient = null;
        if ($patientId <= 0 || !db_exists("SELECT 1 FROM patients WHERE id = ? AND id IN ($relatedPatientsSql)", [$patientId, $doctorId, $doctorId])) {
            $errors[] = 'Please select a valid patient.';
        } else {
            $patient = db_row('SELECT p.*, u.id AS user_id FROM patients p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$patientId]);
        }

        if ($appointmentId > 0
            && !db_exists('SELECT 1 FROM appointments WHERE id = ? AND doctor_id = ? AND patient_id = ?', [$appointmentId, $doctorId, $patientId])) {
            $errors[] = 'The selected appointment does not belong to you and this patient.';
        }

        if (!is_valid_date($prescriptionDate)) {
            $errors[] = 'Please enter a valid prescription date.';
        }

        // Collect the filled medicine blocks and validate each one.
        $filledBlocks = [];
        foreach ($medBlocks as $blk) {
            $name = trim((string) ($blk['medicine_name'] ?? ''));
            if ($name === '') { continue; }

            $dosage    = trim((string) ($blk['dosage'] ?? ''));
            $startDate = trim((string) ($blk['start_date'] ?? ''));

            if ($dosage === '')                  { $errors[] = 'Please enter the dosage for ' . e($name) . '.'; }
            if (!is_valid_date($startDate))      { $errors[] = 'Please enter a valid start date for ' . e($name) . '.'; }

            $filledBlocks[] = $blk;
        }
        if (!$filledBlocks) {
            $errors[] = 'Please fill in at least one medicine.';
        }

        if ($errors) {
            flash_error(implode(' ', array_unique($errors)));
            redirect('doctor/prescriptions.php?new=1' . ($patientId ? '&patient_id=' . $patientId : ''));
        }

        // ---- Transactional insert: prescription header + medicines + link rows ----
        $prescriptionId = 0;
        db_begin();
        try {
            $prescriptionId = db_insert(
                'INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, prescription_date, diagnosis, notes)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$patientId, $doctorId, $appointmentId ?: null, $prescriptionDate, null_if_blank($diagnosis), null_if_blank($notes)]
            );

            foreach ($filledBlocks as $blk) {
                $medicineId = db_insert(
                    "INSERT INTO medicines
                        (patient_id, prescribed_by, medicine_name, generic_name, category, dosage, dose_unit,
                         quantity, frequency, route, start_date, end_date, instructions, notes, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                    [
                        $patientId, $doctorId,
                        trim($blk['medicine_name']),
                        null_if_blank($blk['generic_name'] ?? ''),
                        null_if_blank($blk['category'] ?? ''),
                        trim($blk['dosage']),
                        in_array($blk['dose_unit'] ?? '', dose_units(), true) ? $blk['dose_unit'] : 'mg',
                        null_if_blank($blk['quantity'] ?? ''),
                        in_array($blk['frequency'] ?? '', frequencies(), true) ? $blk['frequency'] : 'Once Daily',
                        in_array($blk['route'] ?? '', routes(), true) ? $blk['route'] : 'Oral',
                        trim($blk['start_date']),
                        null_if_blank($blk['end_date'] ?? ''),
                        null_if_blank($blk['instructions'] ?? ''),
                        null_if_blank($blk['notes'] ?? ''),
                    ]
                );

                $times = array_filter(array_map('trim', explode(',', $blk['reminder_times'] ?? '')));
                save_reminder_times($medicineId, $times);

                db_insert('INSERT INTO prescription_medicines (prescription_id, medicine_id) VALUES (?, ?)', [$prescriptionId, $medicineId]);
            }

            db_commit();
        } catch (Throwable $e) {
            db_rollback();
            error_log('[MediCare prescription] ' . $e->getMessage());
            flash_error('Unable to save the prescription. Please check the information entered and try again.');
            redirect('doctor/prescriptions.php?new=1' . ($patientId ? '&patient_id=' . $patientId : ''));
        }

        if ($patient) {
            notify((int) $patient['user_id'], 'New prescription created',
                   'Dr. ' . current_name() . ' created a new prescription for you.', 'prescription', 'patient/medicines.php');
        }

        flash_success('Prescription created successfully.');
        redirect('doctor/prescriptions.php?view=' . $prescriptionId);
    }

    redirect('doctor/prescriptions.php');
}

// ---------- 2. Detail view ----------
$viewId = get_int('view');
$detail = null;
$detailMedicines = [];
if ($viewId) {
    $detail = db_row(
        "SELECT pr.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.id AS patient_id, p.phone AS patient_phone,
                a.appointment_date, a.start_time
           FROM prescriptions pr
           JOIN patients p ON p.id = pr.patient_id
           LEFT JOIN appointments a ON a.id = pr.appointment_id
          WHERE pr.id = ? AND pr.doctor_id = ?",
        [$viewId, $doctorId]
    );
    if (!$detail) {
        flash_error('That prescription does not belong to you.');
        redirect('doctor/prescriptions.php');
    }
    $detailMedicines = db_all(
        "SELECT m.* FROM prescription_medicines pm JOIN medicines m ON m.id = pm.medicine_id
          WHERE pm.prescription_id = ? ORDER BY m.id",
        [$viewId]
    );
}

// ---------- 3. New prescription form data ----------
$isNew = get_param('new') === '1';
$presetPatientId = get_int('patient_id');
$myPatients = [];
$myAppointments = [];
if ($isNew) {
    $myPatients = db_all(
        "SELECT id, CONCAT(first_name,' ',last_name) AS full_name FROM patients WHERE id IN ($relatedPatientsSql) ORDER BY full_name",
        [$doctorId, $doctorId]
    );
    if ($presetPatientId) {
        $myAppointments = db_all(
            "SELECT id, appointment_date, start_time FROM appointments
              WHERE doctor_id = ? AND patient_id = ? ORDER BY appointment_date DESC, start_time DESC",
            [$doctorId, $presetPatientId]
        );
    }

    $freqTimesMap = [];
    foreach (frequencies() as $f) { $freqTimesMap[$f] = suggested_times($f); }
}

// ---------- 4. List (only when not viewing/creating) ----------
$search = get_param('q');
$prescriptions = [];
$pg = null;
if (!$viewId && !$isNew) {
    $where  = ['pr.doctor_id = ?'];
    $params = [$doctorId];
    if ($search !== '') {
        $where[]  = "(CONCAT(p.first_name,' ',p.last_name) LIKE ? OR pr.diagnosis LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $total = (int) db_scalar("SELECT COUNT(*) FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id $whereSql", $params);
    $pg    = paginate($total);

    $prescriptions = db_all(
        "SELECT pr.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.id AS patient_id,
                (SELECT COUNT(*) FROM prescription_medicines pm WHERE pm.prescription_id = pr.id) AS med_count
           FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id
           $whereSql
           ORDER BY pr.prescription_date DESC, pr.id DESC
           LIMIT ? OFFSET ?",
        array_merge($params, [$pg['per_page'], $pg['offset']])
    );
}

$page_title = 'Prescriptions';
$active     = 'prescriptions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Prescriptions</div>
        <h1>Prescriptions</h1>
        <div class="sub">Prescriptions you have written for your patients.</div>
    </div>
    <?php if (!$isNew && !$viewId): ?>
    <div>
        <a class="btn btn-primary" href="<?= e(url('doctor/prescriptions.php?new=1')) ?>"><?= icon('plus') ?> New Prescription</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail): ?>
    <!-- ================= Detail panel ================= -->
    <div class="table-card p-3 mb-3">
        <a class="btn btn-sm btn-outline-secondary mb-3" href="<?= e(url('doctor/prescriptions.php')) ?>">&larr; Back to list</a>
        <div class="row">
            <div class="col-md-6">
                <div class="kv"><span class="k">Patient</span><span class="v"><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $detail['patient_id'])) ?>"><?= e($detail['patient_name']) ?></a></span></div>
                <div class="kv"><span class="k">Phone</span><span class="v"><?= e($detail['patient_phone']) ?></span></div>
                <div class="kv"><span class="k">Date</span><span class="v"><?= e(format_date($detail['prescription_date'])) ?></span></div>
            </div>
            <div class="col-md-6">
                <div class="kv"><span class="k">Diagnosis</span><span class="v"><?= e($detail['diagnosis'] ?? '-') ?></span></div>
                <div class="kv"><span class="k">Linked Appointment</span><span class="v">
                    <?= $detail['appointment_date'] ? e(format_date($detail['appointment_date']) . ' ' . format_time($detail['start_time'])) : 'None' ?>
                </span></div>
                <div class="kv"><span class="k">Notes</span><span class="v"><?= e($detail['notes'] ?? '-') ?></span></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card-head"><strong>Prescribed Medicines</strong></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Route</th><th>Reminder Times</th><th>Period</th></tr></thead>
                <tbody>
                <?php if (!$detailMedicines): ?>
                    <tr><td colspan="6"><div class="empty"><?= icon('pill') ?><div>No medicines linked to this prescription.</div></div></td></tr>
                <?php else: foreach ($detailMedicines as $m): ?>
                    <tr>
                        <td><?= e($m['medicine_name']) ?><?php if ($m['generic_name']): ?><div class="text-muted small"><?= e($m['generic_name']) ?></div><?php endif; ?></td>
                        <td><?= e(dose_label($m)) ?></td>
                        <td><?= e($m['frequency']) ?></td>
                        <td><?= e($m['route']) ?></td>
                        <td><?= reminder_times_label($m['id']) ?></td>
                        <td><?= e(format_date($m['start_date'])) ?> &rarr; <?= $m['end_date'] ? e(format_date($m['end_date'])) : 'ongoing' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($isNew): ?>
    <!-- ================= New prescription form ================= -->
    <div class="table-card p-3">
        <form method="post" action="<?= e(url('doctor/prescriptions.php')) ?>" id="newPrescriptionForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Patient</label>
                    <select class="form-select" name="patient_id" id="patientSelect" required>
                        <option value="">-- Select patient --</option>
                        <?php foreach ($myPatients as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"<?= $presetPatientId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$myPatients): ?>
                        <div class="text-muted small mt-1">You have no patients yet. A patient must have an appointment with you first.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Linked Appointment (optional)</label>
                    <select class="form-select" name="appointment_id">
                        <option value="">-- None --</option>
                        <?php foreach ($myAppointments as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= e(format_date($a['appointment_date']) . ' ' . format_time($a['start_time'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prescription Date</label>
                    <input type="date" class="form-control" name="prescription_date" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Diagnosis</label>
                    <input type="text" class="form-control" name="diagnosis">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" name="notes">
                </div>
            </div>

            <hr class="my-4">
            <h6 class="mb-3">Medicines <span class="text-muted small">(fill in at least one)</span></h6>

            <div id="medicineBlocks">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="table-card p-3 mb-3 med-block">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Medicine Name</label>
                                <input type="text" class="form-control" name="medicines[<?= $i ?>][medicine_name]">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Generic Name</label>
                                <input type="text" class="form-control" name="medicines[<?= $i ?>][generic_name]">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="medicines[<?= $i ?>][category]">
                                    <option value="">-- Select --</option>
                                    <?= options_from(medicine_categories()) ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Dosage</label>
                                <input type="text" class="form-control" name="medicines[<?= $i ?>][dosage]" placeholder="e.g. 500">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Dose Unit</label>
                                <select class="form-select" name="medicines[<?= $i ?>][dose_unit]">
                                    <?= options_from(dose_units()) ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Quantity</label>
                                <input type="text" class="form-control" name="medicines[<?= $i ?>][quantity]" placeholder="e.g. 1 tablet">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Route</label>
                                <select class="form-select" name="medicines[<?= $i ?>][route]">
                                    <?= options_from(routes()) ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Frequency</label>
                                <select class="form-select freq-select" name="medicines[<?= $i ?>][frequency]">
                                    <?= options_from(frequencies()) ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="medicines[<?= $i ?>][start_date]" value="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date (optional)</label>
                                <input type="date" class="form-control" name="medicines[<?= $i ?>][end_date]">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instructions</label>
                                <input type="text" class="form-control" name="medicines[<?= $i ?>][instructions]" placeholder="e.g. Take after food">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reminder Times <span class="text-muted small">(comma separated, e.g. 08:00, 20:00)</span></label>
                                <input type="text" class="form-control reminder-times-input" name="medicines[<?= $i ?>][reminder_times]" value="08:00">
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

            <button type="button" class="btn btn-outline-secondary mb-3" id="addMedicineBlockBtn"><?= icon('plus') ?> Add Another Medicine</button>

            <div class="d-flex gap-2">
                <button class="btn btn-primary"><?= icon('check') ?> Save Prescription</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('doctor/prescriptions.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var FREQ_TIMES = <?= json_encode($freqTimesMap) ?>;

        // Reload the page with the newly selected patient so the appointment
        // dropdown reflects THIS doctor's appointments with THAT patient.
        var patientSelect = document.getElementById('patientSelect');
        if (patientSelect) {
            patientSelect.addEventListener('change', function () {
                var url = <?= json_encode(url('doctor/prescriptions.php')) ?> + '?new=1' + (this.value ? '&patient_id=' + encodeURIComponent(this.value) : '');
                window.location.href = url;
            });
        }

        // Pre-fill the reminder times field from the suggested times of the chosen frequency.
        document.querySelectorAll('.freq-select').forEach(function (select) {
            select.addEventListener('change', function () {
                var block = select.closest('.med-block');
                var input = block ? block.querySelector('.reminder-times-input') : null;
                if (input && FREQ_TIMES[select.value]) {
                    input.value = FREQ_TIMES[select.value].join(', ');
                }
            });
        });

        // Allow adding extra medicine blocks beyond the initial 3.
        var addBtn = document.getElementById('addMedicineBlockBtn');
        var container = document.getElementById('medicineBlocks');
        var blockIndex = <?= (int) 3 ?>;
        if (addBtn && container) {
            addBtn.addEventListener('click', function () {
                var first = container.querySelector('.med-block');
                if (!first) { return; }
                var clone = first.cloneNode(true);
                clone.querySelectorAll('input').forEach(function (input) {
                    input.name = input.name.replace(/medicines\[\d+\]/, 'medicines[' + blockIndex + ']');
                    if (input.type !== 'date') { input.value = (input.classList.contains('reminder-times-input') ? '08:00' : ''); }
                });
                clone.querySelectorAll('select').forEach(function (select) {
                    select.name = select.name.replace(/medicines\[\d+\]/, 'medicines[' + blockIndex + ']');
                    select.selectedIndex = 0;
                    select.addEventListener('change', function () {
                        var block = select.closest('.med-block');
                        var input = block ? block.querySelector('.reminder-times-input') : null;
                        if (input && FREQ_TIMES[select.value]) {
                            input.value = FREQ_TIMES[select.value].join(', ');
                        }
                    });
                });
                container.appendChild(clone);
                blockIndex++;
            });
        }
    })();
    </script>

<?php else: ?>
    <!-- ================= List ================= -->
    <div class="table-card">
        <form class="filter-bar" method="get">
            <div class="grow">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Patient name or diagnosis">
            </div>
            <div><button class="btn btn-primary"><?= icon('search') ?> Search</button>
                 <a class="btn btn-outline-secondary" href="<?= e(url('doctor/prescriptions.php')) ?>">Reset</a></div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Date</th><th>Patient</th><th>Diagnosis</th><th># Medicines</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$prescriptions): ?>
                    <tr><td colspan="5"><div class="empty"><?= icon('prescription') ?><div>No prescriptions found.</div></div></td></tr>
                <?php else: foreach ($prescriptions as $pr): ?>
                    <tr>
                        <td><?= e(format_date($pr['prescription_date'])) ?></td>
                        <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $pr['patient_id'])) ?>"><?= e($pr['patient_name']) ?></a></td>
                        <td><?= e(str_limit($pr['diagnosis'] ?? '-', 40)) ?></td>
                        <td><?= (int) $pr['med_count'] ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('doctor/prescriptions.php?view=' . (int) $pr['id'])) ?>">
                                <?= icon('eye') ?> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search]); ?></div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
