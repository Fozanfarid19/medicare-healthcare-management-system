<?php
/**
 * MediCare - doctor/patient_record.php
 * Full record of one patient, restricted to patients related to this doctor
 * (appointment with them OR a medicine they prescribed).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('doctor');

$doctorId  = current_doctor_id();
$patientId = get_int('id');

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/patient_record.php?id=' . $patientId);
    $action = post('action');

    if ($action === 'add_note') {
        $note = post('note');

        // Only append to the most recent appointment THIS doctor has with THIS patient.
        $appt = db_row(
            "SELECT id, notes FROM appointments
              WHERE doctor_id = ? AND patient_id = ?
              ORDER BY appointment_date DESC, start_time DESC LIMIT 1",
            [$doctorId, $patientId]
        );

        if ($note === '') {
            flash_error('Please enter a note before saving.');
        } elseif (!$appt) {
            flash_error('You have no appointment on record with this patient to attach a note to.');
        } else {
            $stamp    = date('d M Y H:i');
            $addition = '[' . $stamp . '] ' . $note;
            $newNotes = trim(($appt['notes'] ?? '') !== '' ? $appt['notes'] . "\n" . $addition : $addition);

            db_execute('UPDATE appointments SET notes = ? WHERE id = ? AND doctor_id = ?', [$newNotes, (int) $appt['id'], $doctorId]);
            flash_success('Note added to your most recent appointment with this patient.');
        }
    }

    redirect('doctor/patient_record.php?id=' . $patientId);
}

// ---------- 2. Ownership check ----------
$owns = db_exists(
    "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ?
     UNION
     SELECT 1 FROM medicines WHERE patient_id = ? AND prescribed_by = ?",
    [$patientId, $doctorId, $patientId, $doctorId]
);
if (!$owns) {
    flash_error('You can only view patients who are assigned to you.');
    redirect('doctor/patients.php');
}

// ---------- 3. Load data ----------
$patient = db_row(
    'SELECT p.*, u.email FROM patients p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
    [$patientId]
);
if (!$patient) {
    flash_error('You can only view patients who are assigned to you.');
    redirect('doctor/patients.php');
}

$apptHistory = db_all(
    appointment_select_sql() . ' WHERE a.patient_id = ? AND a.doctor_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC',
    [$patientId, $doctorId]
);

$currentMedicines = db_all(
    "SELECT m.* FROM medicines m WHERE m.patient_id = ? AND m.status = 'active' ORDER BY m.start_date DESC",
    [$patientId]
);

$previousMedicines = db_all(
    "SELECT m.* FROM medicines m WHERE m.patient_id = ? AND m.status IN ('inactive','discontinued') ORDER BY m.start_date DESC",
    [$patientId]
);

$prescriptions = db_all(
    "SELECT pr.*, (SELECT COUNT(*) FROM prescription_medicines pm WHERE pm.prescription_id = pr.id) AS med_count
       FROM prescriptions pr
      WHERE pr.patient_id = ? AND pr.doctor_id = ?
      ORDER BY pr.prescription_date DESC, pr.id DESC",
    [$patientId, $doctorId]
);

$adherence = adherence_stats($patientId, date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));

$recentLogs = db_all(
    "SELECT l.*, mr.reminder_time, m.medicine_name
       FROM medicine_logs l
       JOIN medicine_reminders mr ON mr.id = l.reminder_id
       JOIN medicines m ON m.id = mr.medicine_id
      WHERE l.patient_id = ?
      ORDER BY l.scheduled_at DESC
      LIMIT 15",
    [$patientId]
);

$page_title = 'Patient Record';
$active     = 'patients';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> /
            <a href="<?= e(url('doctor/patients.php')) ?>">My Patients</a> / <?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></div>
        <h1><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
        <div class="sub">Patient record</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-primary" href="<?= e(url('doctor/prescriptions.php?patient_id=' . (int) $patient['id'] . '&new=1')) ?>">
            <?= icon('prescription') ?> New Prescription
        </a>
        <a class="btn btn-outline-primary" href="<?= e(url('doctor/medicines.php?patient_id=' . (int) $patient['id'] . '&new=1')) ?>">
            <?= icon('pill') ?> Prescribe Medicine
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="table-card p-3">
            <div class="profile-head mb-3">
                <div class="profile-avatar"><?= e(strtoupper(substr($patient['first_name'], 0, 1))) ?></div>
                <div>
                    <h5 class="mb-0"><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h5>
                    <div class="text-muted small"><?= e($patient['email']) ?></div>
                </div>
            </div>
            <div class="kv"><span class="k">Phone</span><span class="v"><?= e($patient['phone']) ?></span></div>
            <div class="kv"><span class="k">Age</span><span class="v"><?= e(calculate_age($patient['date_of_birth'])) ?></span></div>
            <div class="kv"><span class="k">Gender</span><span class="v"><?= e($patient['gender']) ?></span></div>
            <div class="kv"><span class="k">Blood Group</span><span class="v"><?= e($patient['blood_group']) ?></span></div>
            <div class="kv"><span class="k">Address</span><span class="v"><?= e($patient['address'] ?? '-') ?></span></div>
            <div class="kv"><span class="k">Emergency Contact</span><span class="v"><?= e($patient['emergency_contact_name'] ?? '-') ?></span></div>
            <div class="kv"><span class="k">Emergency Phone</span><span class="v"><?= e($patient['emergency_contact_phone'] ?? '-') ?></span></div>
        </div>

        <div class="table-card p-3 mt-3">
            <h6 class="mb-3">Adherence (Last 30 Days)</h6>
            <div class="kv"><span class="k">Taken</span><span class="v"><?= (int) $adherence['taken'] ?></span></div>
            <div class="kv"><span class="k">Missed</span><span class="v"><?= (int) $adherence['missed'] ?></span></div>
            <div class="kv"><span class="k">Skipped</span><span class="v"><?= (int) $adherence['skipped'] ?></span></div>
            <div class="kv"><span class="k">Total Doses</span><span class="v"><?= (int) $adherence['total'] ?></span></div>
            <div class="kv"><span class="k">Adherence Rate</span><span class="v"><?= e($adherence['rate']) ?>%</span></div>
        </div>

        <div class="table-card p-3 mt-3">
            <h6 class="mb-3">Add a Note</h6>
            <div class="text-muted small mb-2">Appends a timestamped note to your most recent appointment with this patient.</div>
            <form method="post" action="<?= e(url('doctor/patient_record.php?id=' . (int) $patient['id'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_note">
                <textarea class="form-control mb-2" name="note" rows="3" placeholder="Type a note..." required></textarea>
                <button class="btn btn-primary w-100"><?= icon('edit') ?> Save Note</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8 mb-3">

        <div class="table-card mb-3">
            <div class="table-card-head"><strong>Appointment History with You</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Time</th><th>Reason</th><th>Status</th><th>Payment</th></tr></thead>
                    <tbody>
                    <?php if (!$apptHistory): ?>
                        <tr><td colspan="5"><div class="empty"><?= icon('calendar') ?><div>No appointments on record.</div></div></td></tr>
                    <?php else: foreach ($apptHistory as $a): ?>
                        <tr>
                            <td><?= e(format_date($a['appointment_date'])) ?></td>
                            <td><?= e(format_time($a['start_time'])) ?></td>
                            <td><?= e(str_limit($a['reason'] ?? '-', 40)) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td><?= status_badge($a['payment_status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card mb-3">
            <div class="table-card-head"><strong>Current Medicines</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Reminders</th><th>Prescribed By</th></tr></thead>
                    <tbody>
                    <?php if (!$currentMedicines): ?>
                        <tr><td colspan="5"><div class="empty"><?= icon('pill') ?><div>No active medicines.</div></div></td></tr>
                    <?php else: foreach ($currentMedicines as $m): ?>
                        <tr>
                            <td><?= e($m['medicine_name']) ?></td>
                            <td><?= e(dose_label($m)) ?></td>
                            <td><?= e($m['frequency']) ?></td>
                            <td><?= reminder_times_label($m['id']) ?></td>
                            <td>
                                <?php if ((int) $m['prescribed_by'] === $doctorId): ?>
                                    <span class="badge bg-primary">You</span>
                                <?php elseif ($m['prescribed_by']): ?>
                                    <span class="badge bg-secondary">Another Doctor</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Self-added</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card mb-3">
            <div class="table-card-head"><strong>Previous / Discontinued Medicines</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Period</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$previousMedicines): ?>
                        <tr><td colspan="4"><div class="empty"><?= icon('history') ?><div>No previous medicines.</div></div></td></tr>
                    <?php else: foreach ($previousMedicines as $m): ?>
                        <tr>
                            <td><?= e($m['medicine_name']) ?></td>
                            <td><?= e(dose_label($m)) ?></td>
                            <td><?= e(format_date($m['start_date'])) ?> &rarr; <?= $m['end_date'] ? e(format_date($m['end_date'])) : 'ongoing' ?></td>
                            <td><?= status_badge($m['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card mb-3">
            <div class="table-card-head"><strong>Prescriptions Written by You</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Diagnosis</th><th># Medicines</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$prescriptions): ?>
                        <tr><td colspan="4"><div class="empty"><?= icon('prescription') ?><div>No prescriptions written for this patient.</div></div></td></tr>
                    <?php else: foreach ($prescriptions as $pr): ?>
                        <tr>
                            <td><?= e(format_date($pr['prescription_date'])) ?></td>
                            <td><?= e($pr['diagnosis'] ?? '-') ?></td>
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
        </div>

        <div class="table-card">
            <div class="table-card-head"><strong>Recent Dose Log (Last 15)</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Scheduled</th><th>Medicine</th><th>Action</th><th>Action Time</th></tr></thead>
                    <tbody>
                    <?php if (!$recentLogs): ?>
                        <tr><td colspan="4"><div class="empty"><?= icon('history') ?><div>No dose log entries yet.</div></div></td></tr>
                    <?php else: foreach ($recentLogs as $l): ?>
                        <tr>
                            <td><?= e(format_datetime($l['scheduled_at'])) ?></td>
                            <td><?= e($l['medicine_name']) ?></td>
                            <td><?= status_badge($l['action']) ?></td>
                            <td><?= e(format_datetime($l['action_time'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
