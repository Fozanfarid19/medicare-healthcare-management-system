<?php
/**
 * MediCare - admin/patient_view.php
 * Full profile of a single patient: appointment history, current
 * medicines with reminder times, and a 30 day medicine adherence summary.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('admin');

$patientId = get_int('id');

$patient = db_row(
    "SELECT p.*, u.email, u.status AS user_status, u.last_login
       FROM patients p
       JOIN users u ON u.id = p.user_id
      WHERE p.id = ?",
    [$patientId]
);

if (!$patientId || !$patient) {
    flash_error('Patient not found.');
    redirect('admin/patients.php');
}

$appointments = db_all(
    appointment_select_sql() . ' WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC',
    [$patientId]
);

$medicines = db_all(
    "SELECT m.*,
            CASE WHEN m.prescribed_by IS NULL THEN 'Self added' ELSE CONCAT('Dr. ', d.first_name, ' ', d.last_name) END AS prescriber
       FROM medicines m
       LEFT JOIN doctors d ON d.id = m.prescribed_by
      WHERE m.patient_id = ?
      ORDER BY (m.status = 'active') DESC, m.start_date DESC",
    [$patientId]
);

$fromDate = date('Y-m-d', strtotime('-29 days'));
$toDate   = date('Y-m-d');
$adherence = adherence_stats($patientId, $fromDate, $toDate);

$logs = db_all(
    "SELECT l.*, r.reminder_time, m.medicine_name
       FROM medicine_logs l
       JOIN medicine_reminders r ON r.id = l.reminder_id
       JOIN medicines m ON m.id = r.medicine_id
      WHERE l.patient_id = ?
      ORDER BY l.scheduled_at DESC
      LIMIT 20",
    [$patientId]
);

$page_title = $patient['first_name'] . ' ' . $patient['last_name'];
$active     = 'patients';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> /
            <a href="<?= e(url('admin/patients.php')) ?>">Patients</a> / <?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></div>
        <h1><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
        <div class="sub">Full profile, appointment history and medicine adherence.</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/patients.php')) ?>"><?= icon('patient') ?> Back to Patients</a>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <div class="profile-head">
        <div class="profile-avatar"><?= e(strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1))) ?></div>
        <div>
            <h4 class="mb-1"><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?> <?= status_badge($patient['status']) ?></h4>
            <div class="text-muted"><?= (int) calculate_age($patient['date_of_birth']) ?> years old &middot; <?= e($patient['gender']) ?> &middot; <?= e($patient['blood_group']) ?></div>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-md-6">
            <div class="kv"><span>E-mail</span><span><?= e($patient['email']) ?></span></div>
            <div class="kv"><span>Phone</span><span><?= e($patient['phone']) ?></span></div>
            <div class="kv"><span>Registration Date</span><span><?= e(format_date($patient['registration_date'])) ?></span></div>
        </div>
        <div class="col-md-6">
            <div class="kv"><span>Address</span><span><?= e($patient['address'] ?: '-') ?></span></div>
            <div class="kv"><span>Emergency Contact</span><span><?= e($patient['emergency_contact_name'] ?: '-') ?> <?= $patient['emergency_contact_phone'] ? '(' . e($patient['emergency_contact_phone']) . ')' : '' ?></span></div>
        </div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-3">Medicine Adherence &mdash; Last 30 Days</h6>
    <div class="progress mb-2">
        <div class="progress-bar" role="progressbar" style="width: <?= (float) $adherence['rate'] ?>%"></div>
    </div>
    <div class="d-flex gap-4 flex-wrap small">
        <span><strong><?= (float) $adherence['rate'] ?>%</strong> adherence rate</span>
        <span class="text-success">Taken: <?= (int) $adherence['taken'] ?></span>
        <span class="text-danger">Missed: <?= (int) $adherence['missed'] ?></span>
        <span class="text-secondary">Skipped: <?= (int) $adherence['skipped'] ?></span>
        <span class="text-muted">Total Doses: <?= (int) $adherence['total'] ?></span>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Appointment History</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Time</th><th>Doctor</th><th>Clinic</th><th>Status</th><th>Fee</th><th>Payment</th></tr></thead>
            <tbody>
            <?php if (!$appointments): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('calendar') ?><div>No appointments yet.</div></div></td></tr>
            <?php else: foreach ($appointments as $a): ?>
                <tr>
                    <td><?= e(format_date($a['appointment_date'])) ?></td>
                    <td><?= e(format_time($a['start_time'])) ?></td>
                    <td><?= e($a['doctor_name']) ?></td>
                    <td><?= e($a['clinic_name'] ?? '-') ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td><?= e(money($a['consultation_fee'])) ?></td>
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
            <thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Route</th><th>Prescribed By</th><th>Start</th><th>End</th><th>Status</th><th>Reminders</th></tr></thead>
            <tbody>
            <?php if (!$medicines): ?>
                <tr><td colspan="9"><div class="empty"><?= icon('pill') ?><div>No medicines recorded.</div></div></td></tr>
            <?php else: foreach ($medicines as $m): ?>
                <tr>
                    <td><?= e($m['medicine_name']) ?></td>
                    <td><?= e($m['dosage'] . ' ' . $m['dose_unit']) ?></td>
                    <td><?= e($m['frequency']) ?></td>
                    <td><?= e($m['route']) ?></td>
                    <td><?= e($m['prescriber']) ?></td>
                    <td><?= e(format_date($m['start_date'])) ?></td>
                    <td><?= $m['end_date'] ? e(format_date($m['end_date'])) : '<span class="text-muted">Ongoing</span>' ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td><?= reminder_times_label($m['id']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Recent Dose History (Last 20)</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Medicine</th><th>Scheduled</th><th>Action Taken</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="4"><div class="empty"><?= icon('history') ?><div>No dose history yet.</div></div></td></tr>
            <?php else: foreach ($logs as $l): ?>
                <tr>
                    <td><?= e($l['medicine_name']) ?></td>
                    <td><?= e(format_datetime($l['scheduled_at'])) ?></td>
                    <td><?= e(format_datetime($l['action_time'])) ?></td>
                    <td><?= status_badge($l['action']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
