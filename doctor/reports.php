<?php
/**
 * MediCare - doctor/reports.php
 * Practice reports for the logged-in doctor over a chosen date range.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- 1. Date range (default: current month) ----------
$from = get_param('from') ?: date('Y-m-01');
$to   = get_param('to') ?: date('Y-m-d');
if (!is_valid_date($from)) { $from = date('Y-m-01'); }
if (!is_valid_date($to))   { $to   = date('Y-m-d'); }

// ---------- 2. Appointments by status ----------
$statusRows = db_all(
    "SELECT status, COUNT(*) AS cnt FROM appointments
      WHERE doctor_id = ? AND appointment_date BETWEEN ? AND ?
      GROUP BY status",
    [$doctorId, $from, $to]
);
$statusCounts = [];
foreach ($statusRows as $r) { $statusCounts[$r['status']] = (int) $r['cnt']; }
$statusLabels = [];
$statusValues = [];
foreach (appointment_statuses() as $s) {
    $statusLabels[] = nice_label($s);
    $statusValues[] = $statusCounts[$s] ?? 0;
}
$totalAppointments = array_sum($statusValues);

// ---------- 3. Patients seen (completed appointments) ----------
$patientsSeen = db_all(
    "SELECT p.id, CONCAT(p.first_name,' ',p.last_name) AS patient_name, COUNT(*) AS visits
       FROM appointments a JOIN patients p ON p.id = a.patient_id
      WHERE a.doctor_id = ? AND a.status = 'completed' AND a.appointment_date BETWEEN ? AND ?
      GROUP BY p.id, patient_name
      ORDER BY visits DESC",
    [$doctorId, $from, $to]
);

// ---------- 4. Prescriptions written ----------
$prescriptionCount = (int) db_scalar(
    "SELECT COUNT(*) FROM prescriptions WHERE doctor_id = ? AND prescription_date BETWEEN ? AND ?",
    [$doctorId, $from, $to]
);

// ---------- 5. Medicines prescribed, grouped by category ----------
$categoryRows = db_all(
    "SELECT COALESCE(category, 'Uncategorised') AS label, COUNT(*) AS cnt
       FROM medicines
      WHERE prescribed_by = ? AND created_at BETWEEN ? AND ?
      GROUP BY label
      ORDER BY cnt DESC",
    [$doctorId, $from . ' 00:00:00', $to . ' 23:59:59']
);
$categoryLabels = array_map(function ($r) { return $r['label']; }, $categoryRows);
$categoryValues = array_map(function ($r) { return (int) $r['cnt']; }, $categoryRows);
$medicinesPrescribed = array_sum($categoryValues);

// ---------- 6. Revenue: completed appointments, split by payment status ----------
$revenueRows = db_all(
    "SELECT payment_status, SUM(consultation_fee) AS amt FROM appointments
      WHERE doctor_id = ? AND status = 'completed' AND appointment_date BETWEEN ? AND ?
      GROUP BY payment_status",
    [$doctorId, $from, $to]
);
$revenuePaid    = 0.0;
$revenuePending = 0.0;
foreach ($revenueRows as $r) {
    if ($r['payment_status'] === 'paid') { $revenuePaid = (float) $r['amt']; }
    else                                 { $revenuePending = (float) $r['amt']; }
}

$page_title   = 'Reports';
$active       = 'reports';
$needs_charts = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="print-head">
    <h3><?= e(APP_NAME) ?> &mdash; Doctor Report</h3>
    <div><?= e(current_name()) ?> &middot; <?= e(format_date($from)) ?> to <?= e(format_date($to)) ?></div>
</div>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Reports</div>
        <h1>Reports</h1>
        <div class="sub">Your practice performance for the selected period.</div>
    </div>
    <div>
        <button class="btn btn-outline-secondary" data-print><?= icon('print') ?> Print</button>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <form class="filter-bar" method="get">
        <div>
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
        </div>
        <div><button class="btn btn-primary"><?= icon('search') ?> Apply</button>
             <a class="btn btn-outline-secondary" href="<?= e(url('doctor/reports.php')) ?>">Reset</a></div>
    </form>
</div>

<div class="stat-grid">
    <div class="stat navy"><div class="stat-ic"><?= icon('calendar') ?></div>
        <div><div class="stat-value"><?= (int) $totalAppointments ?></div><div class="stat-label">Appointments in Period</div></div></div>
    <div class="stat green"><div class="stat-ic"><?= icon('patient') ?></div>
        <div><div class="stat-value"><?= count($patientsSeen) ?></div><div class="stat-label">Patients Seen</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('prescription') ?></div>
        <div><div class="stat-value"><?= (int) $prescriptionCount ?></div><div class="stat-label">Prescriptions Written</div></div></div>
    <div class="stat amber"><div class="stat-ic"><?= icon('pill') ?></div>
        <div><div class="stat-value"><?= (int) $medicinesPrescribed ?></div><div class="stat-label">Medicines Prescribed</div></div></div>
</div>

<div class="row mt-1">
    <div class="col-lg-6 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Appointments by Status</h6>
            <div class="chart-box"><canvas id="statusChart"></canvas></div>
            <div class="chart-legend" id="statusLegend"></div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3">Medicines Prescribed by Category</h6>
            <div class="chart-box"><canvas id="categoryChart"></canvas></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Appointments by Status</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($statusLabels as $i => $label): ?>
                        <tr><td><?= e($label) ?></td><td class="text-end"><?= (int) $statusValues[$i] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Revenue (Completed Appointments)</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Payment Status</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr><td><?= status_badge('paid') ?></td><td class="text-end"><?= e(money($revenuePaid)) ?></td></tr>
                        <tr><td><?= status_badge('pending') ?></td><td class="text-end"><?= e(money($revenuePending)) ?></td></tr>
                        <tr class="fw-bold"><td>Total</td><td class="text-end"><?= e(money($revenuePaid + $revenuePending)) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-card-head"><strong>Patients Seen (Completed Appointments)</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Patient</th><th class="text-end">Visits</th></tr></thead>
            <tbody>
            <?php if (!$patientsSeen): ?>
                <tr><td colspan="2"><div class="empty"><?= icon('patient') ?><div>No completed appointments in this period.</div></div></td></tr>
            <?php else: foreach ($patientsSeen as $p): ?>
                <tr>
                    <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $p['id'])) ?>"><?= e($p['patient_name']) ?></a></td>
                    <td class="text-end"><?= (int) $p['visits'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.renderMediCareCharts = function () {
    MediCharts.doughnut('statusChart', {
        labels: <?= json_encode($statusLabels) ?>,
        values: <?= json_encode($statusValues) ?>,
        legend: 'statusLegend',
        centerLabel: 'Appointments'
    });
    MediCharts.bar('categoryChart', {labels: <?= json_encode($categoryLabels) ?>, values: <?= json_encode($categoryValues) ?>, color: '#7a5af8'});
};
document.addEventListener('DOMContentLoaded', window.renderMediCareCharts);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
