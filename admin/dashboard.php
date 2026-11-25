<?php
/**
 * MediCare - admin/dashboard.php
 * Administrator overview: live stat cards, charts and quick lists.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('admin');

// ---------- 1. Stat cards (all values come from live queries) ----------
$stats = [
    'users'            => (int) db_scalar('SELECT COUNT(*) FROM users'),
    'doctors'          => (int) db_scalar('SELECT COUNT(*) FROM doctors'),
    'patients'         => (int) db_scalar('SELECT COUNT(*) FROM patients'),
    'clinics'          => (int) db_scalar('SELECT COUNT(*) FROM clinics'),
    'today_appts'      => (int) db_scalar('SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()'),
    'upcoming_appts'   => (int) db_scalar(
        "SELECT COUNT(*) FROM appointments
          WHERE appointment_date > CURDATE() AND status IN ('pending','confirmed')"
    ),
    'pending_appts'    => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'pending'"),
    'completed_appts'  => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'completed'"),
    'medicines'        => (int) db_scalar('SELECT COUNT(*) FROM medicines'),
    'active_reminders' => (int) db_scalar("SELECT COUNT(*) FROM medicine_reminders WHERE status = 'active'"),
    'missed_doses'     => (int) db_scalar(
        "SELECT COUNT(*) FROM medicine_logs WHERE action = 'missed' AND scheduled_at >= ?",
        [date('Y-m-d H:i:s', strtotime('-7 days'))]
    ),
];

// ---------- 2. Chart (a): appointments per month, last 6 months --------
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months[$ym] = date('M Y', strtotime("-$i months"));
}
$firstMonthStart = date('Y-m-01', strtotime('-5 months'));

$monthRows = db_all(
    "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS cnt
       FROM appointments
      WHERE appointment_date >= ?
      GROUP BY ym",
    [$firstMonthStart]
);
$monthCounts = [];
foreach ($monthRows as $r) { $monthCounts[$r['ym']] = (int) $r['cnt']; }

$apptLabels = [];
$apptValues = [];
foreach ($months as $ym => $label) {
    $apptLabels[] = $label;
    $apptValues[] = $monthCounts[$ym] ?? 0;
}

// ---------- 3. Chart (b): appointment status doughnut ------------------
$statusRows = db_all('SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status');
$statusCounts = [];
foreach ($statusRows as $r) { $statusCounts[$r['status']] = (int) $r['cnt']; }

$statusLabels = [];
$statusValues = [];
foreach (appointment_statuses() as $s) {
    $statusLabels[] = nice_label($s);
    $statusValues[] = $statusCounts[$s] ?? 0;
}

// ---------- 4. Chart (c): doctors by specialization ---------------------
$specRows = db_all(
    "SELECT COALESCE(s.specialization_name, 'Unassigned') AS label, COUNT(*) AS cnt
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
      GROUP BY label
      ORDER BY cnt DESC"
);
$specLabels = array_map(function ($r) { return $r['label']; }, $specRows);
$specValues = array_map(function ($r) { return (int) $r['cnt']; }, $specRows);

// ---------- 5. Tables ----------------------------------------------------
$todayAppointments = db_all(
    appointment_select_sql() . ' WHERE a.appointment_date = CURDATE() ORDER BY a.start_time LIMIT 10'
);

$recentPatients = db_all(
    'SELECT p.*, u.email
       FROM patients p
       JOIN users u ON u.id = p.user_id
      ORDER BY p.registration_date DESC, p.id DESC
      LIMIT 5'
);

$page_title   = 'Admin Dashboard';
$active       = 'dashboard';
$needs_charts = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs">Dashboard</div>
        <h1>Administrator Dashboard</h1>
        <div class="sub">Live overview of users, appointments and medicine adherence.</div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat"><div class="stat-ic"><?= icon('users') ?></div>
        <div><div class="stat-value"><?= (int) $stats['users'] ?></div><div class="stat-label">Total Users</div></div></div>
    <div class="stat navy"><div class="stat-ic"><?= icon('doctor') ?></div>
        <div><div class="stat-value"><?= (int) $stats['doctors'] ?></div><div class="stat-label">Total Doctors</div></div></div>
    <div class="stat green"><div class="stat-ic"><?= icon('patient') ?></div>
        <div><div class="stat-value"><?= (int) $stats['patients'] ?></div><div class="stat-label">Total Patients</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('clinic') ?></div>
        <div><div class="stat-value"><?= (int) $stats['clinics'] ?></div><div class="stat-label">Total Clinics</div></div></div>

    <div class="stat navy"><div class="stat-ic"><?= icon('calendar') ?></div>
        <div><div class="stat-value"><?= (int) $stats['today_appts'] ?></div><div class="stat-label">Today's Appointments</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('calendar-check') ?></div>
        <div><div class="stat-value"><?= (int) $stats['upcoming_appts'] ?></div><div class="stat-label">Upcoming Appointments</div></div></div>
    <div class="stat amber"><div class="stat-ic"><?= icon('clock') ?></div>
        <div><div class="stat-value"><?= (int) $stats['pending_appts'] ?></div><div class="stat-label">Pending Appointments</div></div></div>
    <div class="stat green"><div class="stat-ic"><?= icon('check-circle') ?></div>
        <div><div class="stat-value"><?= (int) $stats['completed_appts'] ?></div><div class="stat-label">Completed Appointments</div></div></div>

    <div class="stat"><div class="stat-ic"><?= icon('pill') ?></div>
        <div><div class="stat-value"><?= (int) $stats['medicines'] ?></div><div class="stat-label">Total Medicines</div></div></div>
    <div class="stat green"><div class="stat-ic"><?= icon('bell') ?></div>
        <div><div class="stat-value"><?= (int) $stats['active_reminders'] ?></div><div class="stat-label">Active Medicine Reminders</div></div></div>
    <div class="stat red"><div class="stat-ic"><?= icon('alert-circle') ?></div>
        <div><div class="stat-value"><?= (int) $stats['missed_doses'] ?></div><div class="stat-label">Missed Doses (Last 7 Days)</div></div></div>
</div>

<div class="row mt-3">
    <div class="col-lg-6 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Appointments Per Month (Last 6 Months)</h6>
            <div class="chart-box"><canvas id="apptChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-3 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Appointment Status</h6>
            <div class="chart-box"><canvas id="statusChart"></canvas></div>
            <div class="chart-legend" id="statusLegend"></div>
        </div>
    </div>
    <div class="col-lg-3 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Doctors by Specialization</h6>
            <div class="chart-box"><canvas id="specChart"></canvas></div>
        </div>
    </div>
</div>

<div class="row mt-1">
    <div class="col-lg-7 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Today's Appointments</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$todayAppointments): ?>
                        <tr><td colspan="4"><div class="empty"><?= icon('calendar') ?><div>No appointments scheduled for today.</div></div></td></tr>
                    <?php else: foreach ($todayAppointments as $a): ?>
                        <tr>
                            <td><?= e(format_time($a['start_time'])) ?></td>
                            <td><?= e($a['patient_name']) ?></td>
                            <td><?= e($a['doctor_name']) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Recently Registered Patients</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Name</th><th>Registered On</th></tr></thead>
                    <tbody>
                    <?php if (!$recentPatients): ?>
                        <tr><td colspan="2"><div class="empty"><?= icon('patient') ?><div>No patients yet.</div></div></td></tr>
                    <?php else: foreach ($recentPatients as $p): ?>
                        <tr>
                            <td><a href="<?= e(url('admin/patient_view.php?id=' . (int) $p['id'])) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
                            <td><?= e(format_date($p['registration_date'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-3">Quick Actions</h6>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="<?= e(url('admin/doctors.php')) ?>"><?= icon('doctor') ?> Manage Doctors</a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/patients.php')) ?>"><?= icon('patient') ?> Manage Patients</a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/clinics.php')) ?>"><?= icon('clinic') ?> Manage Clinics</a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/reports.php')) ?>"><?= icon('report') ?> View Reports</a>
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
    MediCharts.bar('specChart', {labels: <?= json_encode($specLabels) ?>, values: <?= json_encode($specValues) ?>, color: '#7a5af8'});
};
document.addEventListener('DOMContentLoaded', window.renderMediCareCharts);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
