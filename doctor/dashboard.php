<?php
/**
 * MediCare - doctor/dashboard.php
 * Doctor overview: live stat cards, today's schedule, pending requests, charts.
 * Every query is scoped to the logged in doctor (current_doctor_id()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- 1. Stat cards (all values come from live, doctor-scoped queries) ----------
$stats = [
    'patients' => (int) db_scalar(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?
            UNION
            SELECT DISTINCT patient_id FROM medicines WHERE prescribed_by = ?
         ) t",
        [$doctorId, $doctorId]
    ),
    'today_appts' => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE()",
        [$doctorId]
    ),
    'upcoming_appts' => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments
          WHERE doctor_id = ? AND appointment_date > CURDATE() AND status IN ('pending','confirmed')",
        [$doctorId]
    ),
    'pending_appts' => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'pending'",
        [$doctorId]
    ),
    'completed_appts' => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'completed'",
        [$doctorId]
    ),
    'cancelled_appts' => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'cancelled'",
        [$doctorId]
    ),
    'prescriptions' => (int) db_scalar(
        "SELECT COUNT(*) FROM prescriptions WHERE doctor_id = ?",
        [$doctorId]
    ),
    'active_medicines' => (int) db_scalar(
        "SELECT COUNT(*) FROM medicines WHERE prescribed_by = ? AND status = 'active'",
        [$doctorId]
    ),
];

// ---------- 2. Chart (a): appointments per month, last 6 months, this doctor ----------
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months[$ym] = date('M Y', strtotime("-$i months"));
}
$firstMonthStart = date('Y-m-01', strtotime('-5 months'));

$monthRows = db_all(
    "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS cnt
       FROM appointments
      WHERE doctor_id = ? AND appointment_date >= ?
      GROUP BY ym",
    [$doctorId, $firstMonthStart]
);
$monthCounts = [];
foreach ($monthRows as $r) { $monthCounts[$r['ym']] = (int) $r['cnt']; }

$apptLabels = [];
$apptValues = [];
foreach ($months as $ym => $label) {
    $apptLabels[] = $label;
    $apptValues[] = $monthCounts[$ym] ?? 0;
}

// ---------- 3. Chart (b): appointment status doughnut, this doctor ----------
$statusRows = db_all(
    'SELECT status, COUNT(*) AS cnt FROM appointments WHERE doctor_id = ? GROUP BY status',
    [$doctorId]
);
$statusCounts = [];
foreach ($statusRows as $r) { $statusCounts[$r['status']] = (int) $r['cnt']; }

$statusLabels = [];
$statusValues = [];
foreach (appointment_statuses() as $s) {
    $statusLabels[] = nice_label($s);
    $statusValues[] = $statusCounts[$s] ?? 0;
}

// ---------- 4. Today's schedule ----------
$todaySchedule = db_all(
    appointment_select_sql() . ' WHERE a.doctor_id = ? AND a.appointment_date = CURDATE() ORDER BY a.start_time',
    [$doctorId]
);

// ---------- 5. Pending appointment requests (next 5) ----------
$pendingRequests = db_all(
    appointment_select_sql()
        . " WHERE a.doctor_id = ? AND a.status = 'pending'
            ORDER BY a.appointment_date, a.start_time LIMIT 5",
    [$doctorId]
);

$page_title   = 'Doctor Dashboard';
$active       = 'dashboard';
$needs_charts = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs">Dashboard</div>
        <h1>Welcome, <?= e(current_name()) ?></h1>
        <div class="sub">Your practice at a glance.</div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat"><div class="stat-ic"><?= icon('patient') ?></div>
        <div><div class="stat-value"><?= (int) $stats['patients'] ?></div><div class="stat-label">Total Patients</div></div></div>
    <div class="stat navy"><div class="stat-ic"><?= icon('calendar') ?></div>
        <div><div class="stat-value"><?= (int) $stats['today_appts'] ?></div><div class="stat-label">Today's Appointments</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('calendar-check') ?></div>
        <div><div class="stat-value"><?= (int) $stats['upcoming_appts'] ?></div><div class="stat-label">Upcoming Appointments</div></div></div>
    <div class="stat amber"><div class="stat-ic"><?= icon('clock') ?></div>
        <div><div class="stat-value"><?= (int) $stats['pending_appts'] ?></div><div class="stat-label">Pending Requests</div></div></div>

    <div class="stat green"><div class="stat-ic"><?= icon('check-circle') ?></div>
        <div><div class="stat-value"><?= (int) $stats['completed_appts'] ?></div><div class="stat-label">Completed</div></div></div>
    <div class="stat red"><div class="stat-ic"><?= icon('close') ?></div>
        <div><div class="stat-value"><?= (int) $stats['cancelled_appts'] ?></div><div class="stat-label">Cancelled</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('prescription') ?></div>
        <div><div class="stat-value"><?= (int) $stats['prescriptions'] ?></div><div class="stat-label">Prescriptions Written</div></div></div>
    <div class="stat navy"><div class="stat-ic"><?= icon('pill') ?></div>
        <div><div class="stat-value"><?= (int) $stats['active_medicines'] ?></div><div class="stat-label">Active Medicines Prescribed</div></div></div>
</div>

<div class="row mt-3">
    <div class="col-lg-8 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Appointments Per Month (Last 6 Months)</h6>
            <div class="chart-box"><canvas id="apptChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Appointment Status</h6>
            <div class="chart-box"><canvas id="statusChart"></canvas></div>
            <div class="chart-legend" id="statusLegend"></div>
        </div>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Today's Schedule</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Time</th><th>Patient</th><th>Phone</th><th>Reason</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php if (!$todaySchedule): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('calendar') ?><div>No appointments scheduled for today.</div></div></td></tr>
            <?php else: foreach ($todaySchedule as $a): ?>
                <tr>
                    <td><?= e(format_time($a['start_time'])) ?></td>
                    <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $a['p_id'])) ?>"><?= e($a['patient_name']) ?></a></td>
                    <td><?= e($a['patient_phone']) ?></td>
                    <td><?= e(str_limit($a['reason'] ?? '-', 40)) ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td class="text-end">
                        <?php if ($a['status'] === 'pending'): ?>
                            <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="status" value="confirmed">
                                <button class="btn btn-sm btn-primary"><?= icon('check') ?> Confirm</button>
                            </form>
                        <?php elseif ($a['status'] === 'confirmed'): ?>
                            <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="status" value="completed">
                                <button class="btn btn-sm btn-outline-primary"><?= icon('check-circle') ?> Complete</button>
                            </form>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Pending Appointment Requests</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Time</th><th>Patient</th><th>Reason</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php if (!$pendingRequests): ?>
                <tr><td colspan="5"><div class="empty"><?= icon('clock') ?><div>No pending requests.</div></div></td></tr>
            <?php else: foreach ($pendingRequests as $a): ?>
                <tr>
                    <td><?= e(format_date($a['appointment_date'])) ?></td>
                    <td><?= e(format_time($a['start_time'])) ?></td>
                    <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $a['p_id'])) ?>"><?= e($a['patient_name']) ?></a></td>
                    <td><?= e(str_limit($a['reason'] ?? '-', 40)) ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="status" value="confirmed">
                            <button class="btn btn-sm btn-primary"><?= icon('check') ?> Confirm</button>
                        </form>
                        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline" data-confirm="Cancel this appointment request?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button class="btn btn-sm btn-outline-danger"><?= icon('close') ?> Cancel</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.renderMediCareCharts = function () {
    MediCharts.bar('apptChart', {labels: <?= json_encode($apptLabels) ?>, values: <?= json_encode($apptValues) ?>});
    MediCharts.doughnut('statusChart', {
        labels: <?= json_encode($statusLabels) ?>,
        values: <?= json_encode($statusValues) ?>,
        legend: 'statusLegend',
        centerLabel: 'Appointments'
    });
};
document.addEventListener('DOMContentLoaded', window.renderMediCareCharts);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
