<?php
/**
 * MediCare - patient/dashboard.php
 * Patient home page: live clock, today's dose stats, next reminder,
 * today's medicine schedule, appointments overview and "my doctors".
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('patient');

$patientId = current_patient_id();

// Keep the database up to date before we read anything from it.
mark_missed_doses($patientId);

// ---------- 1. POST handling (Taken / Skipped / Snoozed from the table) ----------
if (is_post()) {
    verify_csrf('patient/dashboard.php');
    $action = post('action');

    if (in_array($action, ['taken', 'skipped', 'snoozed'], true)) {
        $reminderId  = post_int('reminder_id');
        $scheduledAt = post('scheduled_at');
        $result      = log_dose_action($reminderId, $patientId, $scheduledAt, $action);
        if ($result['ok']) { flash_success($result['message']); } else { flash_error($result['message']); }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/dashboard.php');
}

// ---------- 2. Data for the page ----------
$counts   = today_dose_counts($patientId);
$next     = next_dose($patientId);
$schedule = patient_schedule($patientId, date('Y-m-d'));

$upcomingAppointments = db_all(
    appointment_select_sql() .
    " WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')
      ORDER BY a.appointment_date, a.start_time LIMIT 5",
    [$patientId]
);

$previousAppointments = db_all(
    appointment_select_sql() .
    " WHERE a.patient_id = ? AND a.status = 'completed'
      ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 5",
    [$patientId]
);

// Distinct doctors the patient has interacted with, via appointments or prescriptions.
$myDoctors = db_all(
    "SELECT DISTINCT d.id, d.first_name, d.last_name, d.consultation_fee,
            s.specialization_name, c.clinic_name
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c ON c.id = d.clinic_id
      WHERE d.id IN (SELECT doctor_id FROM appointments WHERE patient_id = ?
                      UNION
                      SELECT doctor_id FROM prescriptions WHERE patient_id = ?)
      ORDER BY d.first_name, d.last_name
      LIMIT 6",
    [$patientId, $patientId]
);

$page_title = 'My Dashboard';
$active     = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs">Dashboard</div>
        <h1>Welcome, <?= e(current_name()) ?></h1>
        <div class="sub"><span id="liveClock"></span></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-primary" href="<?= e(url('patient/doctors.php')) ?>"><?= icon('plus') ?> Book Appointment</a>
        <a class="btn btn-outline-primary" href="<?= e(url('patient/medicines.php')) ?>"><?= icon('pill') ?> My Medicines</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('patient/history.php')) ?>"><?= icon('history') ?> Medicine History</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-ic"><?= icon('pill') ?></div>
        <div><div class="stat-value" data-count="total"><?= (int) $counts['total'] ?></div><div class="stat-label">Today's Doses</div></div>
    </div>
    <div class="stat green">
        <div class="stat-ic"><?= icon('check-circle') ?></div>
        <div><div class="stat-value" data-count="taken"><?= (int) $counts['taken'] ?></div><div class="stat-label">Taken</div></div>
    </div>
    <div class="stat amber">
        <div class="stat-ic"><?= icon('clock') ?></div>
        <div><div class="stat-value" data-count="pending"><?= (int) $counts['pending'] ?></div><div class="stat-label">Pending</div></div>
    </div>
    <div class="stat red">
        <div class="stat-ic"><?= icon('alert-circle') ?></div>
        <div><div class="stat-value" data-count="missed"><?= (int) $counts['missed'] ?></div><div class="stat-label">Missed</div></div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-3"><?= icon('bell') ?> Next Medicine Reminder</h6>
    <?php if ($next): ?>
        <div class="reminder-pill mb-0">
            <span class="rp-ic"><?= icon('pill') ?></span>
            <div>
                <div class="fw-bold fs-5"><?= e($next['medicine_name']) ?></div>
                <div class="text-muted small">
                    Dose: <?= e(trim($next['dosage'] . ' ' . $next['dose_unit'])) ?>
                    <?php if ($next['instructions'] !== null && $next['instructions'] !== ''): ?>
                        &middot; <?= e($next['instructions']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ms-auto text-end">
                <div class="reminder-time"><?= e(format_time($next['reminder_time'])) ?></div>
                <div class="small text-muted"><?= e($next['frequency']) ?></div>
            </div>
        </div>
    <?php else: ?>
        <div class="empty py-3"><?= icon('check-circle') ?><div>No more scheduled doses for today. Well done!</div></div>
    <?php endif; ?>
</div>

<div class="table-card">
    <div class="table-card-head"><strong><?= icon('calendar') ?> Today's Medicine Schedule</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Medicine</th><th>Dose</th><th>Quantity</th><th>Route</th>
                    <th>Time</th><th>Frequency</th><th>Status</th><th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$schedule): ?>
                <tr><td colspan="8"><div class="empty"><?= icon('pill') ?><div>No medicine reminders scheduled for today.</div></div></td></tr>
            <?php else: foreach ($schedule as $row):
                $status = dose_status($row);
                $rowCls = 'dose-row' . ($status === 'taken' ? ' taken' : ($status === 'missed' ? ' missed' : ''));
            ?>
                <tr class="<?= e($rowCls) ?>" data-reminder="<?= (int) $row['reminder_id'] ?>">
                    <td><?= e($row['medicine_name']) ?></td>
                    <td><?= e(trim($row['dosage'] . ' ' . $row['dose_unit'])) ?></td>
                    <td><?= e($row['quantity'] ?? '-') ?></td>
                    <td><?= e($row['route']) ?></td>
                    <td class="dose-time"><?= e(format_time($row['reminder_time'])) ?></td>
                    <td><?= e($row['frequency']) ?></td>
                    <td data-status><?= status_badge($status) ?></td>
                    <td class="text-end" data-dose-actions>
                        <?php if (in_array($status, ['pending', 'snoozed'], true)): ?>
                        <form method="post" class="d-inline-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reminder_id" value="<?= (int) $row['reminder_id'] ?>">
                            <input type="hidden" name="scheduled_at" value="<?= e($row['scheduled_at']) ?>">
                            <button type="submit" name="action" value="taken" class="btn btn-sm btn-success"><?= icon('check') ?></button>
                            <button type="submit" name="action" value="snoozed" class="btn btn-sm btn-warning"><?= icon('clock') ?></button>
                            <button type="submit" name="action" value="skipped" class="btn btn-sm btn-outline-secondary"><?= icon('close') ?></button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">Recorded</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row mt-1">
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head">
                <strong><?= icon('calendar-check') ?> Upcoming Appointments</strong>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('patient/appointments.php')) ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Doctor</th><th>Clinic</th><th>Date / Time</th><th>Status</th><th>Fee</th></tr></thead>
                    <tbody>
                    <?php if (!$upcomingAppointments): ?>
                        <tr><td colspan="5"><div class="empty"><?= icon('calendar') ?><div>No upcoming appointments.</div></div></td></tr>
                    <?php else: foreach ($upcomingAppointments as $a): ?>
                        <tr>
                            <td><?= e($a['doctor_name']) ?><div class="small text-muted"><?= e($a['specialization_name'] ?? '') ?></div></td>
                            <td><?= e($a['clinic_name'] ?? '-') ?></td>
                            <td><?= e(format_date($a['appointment_date'])) ?><div class="small text-muted"><?= e(format_time($a['start_time'])) ?></div></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td><?= e(money($a['consultation_fee'])) ?><div class="small text-muted"><?= e(nice_label($a['payment_status'])) ?></div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong><?= icon('history') ?> Previous Appointments</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Doctor</th><th>Clinic</th><th>Date / Time</th><th>Status</th><th>Payment</th></tr></thead>
                    <tbody>
                    <?php if (!$previousAppointments): ?>
                        <tr><td colspan="5"><div class="empty"><?= icon('history') ?><div>No completed appointments yet.</div></div></td></tr>
                    <?php else: foreach ($previousAppointments as $a): ?>
                        <tr>
                            <td><?= e($a['doctor_name']) ?></td>
                            <td><?= e($a['clinic_name'] ?? '-') ?></td>
                            <td><?= e(format_date($a['appointment_date'])) ?><div class="small text-muted"><?= e(format_time($a['start_time'])) ?></div></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td><?= status_badge($a['payment_status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-3"><?= icon('doctor') ?> My Doctors</h6>
    <?php if (!$myDoctors): ?>
        <div class="empty"><?= icon('doctor') ?><div>You have not consulted a doctor on MediCare yet.</div></div>
    <?php else: ?>
        <div class="row">
        <?php foreach ($myDoctors as $d): ?>
            <div class="col-md-4 mb-3">
                <div class="doc-card">
                    <div class="doc-top">
                        <div class="doc-av"><?= e(strtoupper(substr($d['first_name'], 0, 1))) ?></div>
                        <div>
                            <div class="fw-bold">Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?></div>
                            <div class="small text-muted"><?= e($d['specialization_name'] ?? 'General') ?></div>
                        </div>
                    </div>
                    <div class="small text-muted mb-2"><?= e($d['clinic_name'] ?? 'No clinic assigned') ?></div>
                    <a class="btn btn-sm btn-outline-primary w-100" href="<?= e(url('patient/doctor_view.php?id=' . (int) $d['id'])) ?>"><?= icon('eye') ?> View Profile</a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
