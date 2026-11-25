<?php
/**
 * MediCare - admin/reports.php
 * A report selector with six built-in, print-friendly reports.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('admin');

// ---------- 1. Report + date range selection ----------
$reportTitles = [
    'registrations'      => 'Patient Registrations',
    'doctors'            => 'Doctor Performance',
    'appointments'        => 'Appointments Report',
    'medicines'          => 'Medicines Report',
    'adherence'          => 'Medicine Adherence',
    'appointment_status' => 'Appointment Status Breakdown',
];

$report = get_param('report', 'registrations');
if (!isset($reportTitles[$report])) {
    $report = 'registrations';
}

$from = get_param('from', date('Y-m-01'));
$to   = get_param('to', date('Y-m-d'));
if (!is_valid_date($from)) { $from = date('Y-m-01'); }
if (!is_valid_date($to))   { $to   = date('Y-m-d'); }
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

$fromStart = $from . ' 00:00:00';
$toEnd     = $to . ' 23:59:59';

// Chart data prepared by whichever report branch runs below.
$chartType   = '';
$chartLabels = [];
$chartValues = [];

// ---------- 2. Build the data for the selected report ----------
if ($report === 'registrations') {
    $spanDays = (strtotime($to) - strtotime($from)) / 86400;
    if ($spanDays <= 62) {
        $regRows = db_all(
            "SELECT DATE(registration_date) AS period, COUNT(*) AS cnt
               FROM patients WHERE registration_date BETWEEN ? AND ?
              GROUP BY DATE(registration_date) ORDER BY period",
            [$from, $to]
        );
        foreach ($regRows as $rr) { $chartLabels[] = format_date($rr['period']); $chartValues[] = (int) $rr['cnt']; }
    } else {
        $regRows = db_all(
            "SELECT DATE_FORMAT(registration_date, '%Y-%m') AS period, COUNT(*) AS cnt
               FROM patients WHERE registration_date BETWEEN ? AND ?
              GROUP BY period ORDER BY period",
            [$from, $to]
        );
        foreach ($regRows as $rr) { $chartLabels[] = date('M Y', strtotime($rr['period'] . '-01')); $chartValues[] = (int) $rr['cnt']; }
    }
    $chartType       = 'line';
    $totalRegistered = array_sum($chartValues);

} elseif ($report === 'doctors') {
    $doctorRows = db_all(
        "SELECT d.id, CONCAT('Dr. ', d.first_name, ' ', d.last_name) AS doctor_name,
                s.specialization_name, c.clinic_name, d.experience_years, d.consultation_fee,
                (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id AND a.appointment_date BETWEEN ? AND ?) AS appt_count,
                (SELECT COALESCE(SUM(a2.consultation_fee), 0) FROM appointments a2
                  WHERE a2.doctor_id = d.id AND a2.appointment_date BETWEEN ? AND ? AND a2.payment_status = 'paid') AS revenue
           FROM doctors d
           LEFT JOIN specializations s ON s.id = d.specialization_id
           LEFT JOIN clinics c ON c.id = d.clinic_id
          ORDER BY d.first_name, d.last_name",
        [$from, $to, $from, $to]
    );

} elseif ($report === 'appointments') {
    $statusRows    = db_all(
        "SELECT status, COUNT(*) AS cnt FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status",
        [$from, $to]
    );
    $statusCounts  = array_fill_keys(appointment_statuses(), 0);
    foreach ($statusRows as $sr) { $statusCounts[$sr['status']] = (int) $sr['cnt']; }
    foreach ($statusCounts as $key => $cnt) { $chartLabels[] = nice_label($key); $chartValues[] = $cnt; }
    $chartType = 'bar';

    $apptRows = db_all(
        appointment_select_sql() . ' WHERE a.appointment_date BETWEEN ? AND ? ORDER BY a.appointment_date, a.start_time',
        [$from, $to]
    );

} elseif ($report === 'medicines') {
    $catRows = db_all(
        "SELECT COALESCE(category, 'Other') AS category, COUNT(*) AS cnt
           FROM medicines WHERE created_at BETWEEN ? AND ?
          GROUP BY COALESCE(category, 'Other') ORDER BY cnt DESC",
        [$fromStart, $toEnd]
    );
    foreach ($catRows as $cr) { $chartLabels[] = $cr['category']; $chartValues[] = (int) $cr['cnt']; }
    $chartType = 'bar';

    $medRows = db_all(
        "SELECT m.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name
           FROM medicines m JOIN patients p ON p.id = m.patient_id
          WHERE m.created_at BETWEEN ? AND ?
          ORDER BY m.created_at DESC",
        [$fromStart, $toEnd]
    );

} elseif ($report === 'adherence') {
    $adhRows = db_all(
        "SELECT p.id AS patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                SUM(l.action = 'taken') AS taken, SUM(l.action = 'missed') AS missed,
                SUM(l.action = 'skipped') AS skipped, COUNT(*) AS total
           FROM medicine_logs l
           JOIN patients p ON p.id = l.patient_id
          WHERE l.scheduled_at BETWEEN ? AND ?
          GROUP BY p.id, patient_name
         HAVING total > 0
          ORDER BY (taken / total) ASC",
        [$fromStart, $toEnd]
    );
    foreach ($adhRows as &$ar) {
        $ar['taken']   = (int) $ar['taken'];
        $ar['missed']  = (int) $ar['missed'];
        $ar['skipped'] = (int) $ar['skipped'];
        $ar['total']   = (int) $ar['total'];
        $ar['rate']    = $ar['total'] > 0 ? round($ar['taken'] * 100 / $ar['total'], 1) : 0.0;
    }
    unset($ar);

    $overall = db_row(
        "SELECT SUM(action = 'taken') AS taken, SUM(action = 'missed') AS missed, SUM(action = 'skipped') AS skipped
           FROM medicine_logs WHERE scheduled_at BETWEEN ? AND ?",
        [$fromStart, $toEnd]
    );
    $chartLabels = ['Taken', 'Missed', 'Skipped'];
    $chartValues = [
        (int) ($overall['taken'] ?? 0),
        (int) ($overall['missed'] ?? 0),
        (int) ($overall['skipped'] ?? 0),
    ];
    $chartType = 'doughnut';

} elseif ($report === 'appointment_status') {
    $statusRows2  = db_all(
        "SELECT status, COUNT(*) AS cnt FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status",
        [$from, $to]
    );
    $statusCounts2 = array_fill_keys(appointment_statuses(), 0);
    foreach ($statusRows2 as $sr) { $statusCounts2[$sr['status']] = (int) $sr['cnt']; }
    $totalAppts = array_sum($statusCounts2);
    foreach ($statusCounts2 as $key => $cnt) { $chartLabels[] = nice_label($key); $chartValues[] = $cnt; }
    $chartType = 'doughnut';
}

// ---------- 3. Render ----------
$page_title   = 'Reports';
$active       = 'reports';
$needs_charts = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Reports</div>
    <h1>Reports</h1>
    <div class="sub">Generate and print operational reports for a chosen date range.</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-secondary" data-print><?= icon('print') ?> Print</button>
  </div>
</div>

<div class="table-card">
  <form class="filter-bar" method="get">
    <div>
      <label class="form-label">Report</label>
      <select class="form-select auto-submit" name="report">
        <?php foreach ($reportTitles as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $report === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
    </div>
    <div>
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
    </div>
    <div>
      <button class="btn btn-primary"><?= icon('search') ?> Generate</button>
    </div>
  </form>
</div>

<div class="print-head">
  <h2><?= e(APP_NAME) ?></h2>
  <h4><?= e($reportTitles[$report]) ?></h4>
  <div><?= e(format_date($from)) ?> &ndash; <?= e(format_date($to)) ?></div>
</div>

<?php if ($report === 'registrations'): ?>

  <div class="stat-grid mt-4">
    <div class="stat"><div class="stat-ic"><?= icon('patient') ?></div>
      <div><div class="stat-value"><?= (int) $totalRegistered ?></div><div class="stat-label">New Patients in Period</div></div></div>
  </div>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Registrations Over Time</h2></div>
    <div class="card-body"><div class="chart-box"><canvas id="reportChart"></canvas></div></div>
  </div>

  <div class="table-card mt-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Period</th><th>New Patients</th></tr></thead>
        <tbody>
        <?php if (!$chartLabels): ?>
          <tr><td colspan="2"><div class="empty"><?= icon('search') ?><div>No registrations in this period.</div></div></td></tr>
        <?php else: foreach ($chartLabels as $i => $label): ?>
          <tr><td><?= e($label) ?></td><td><?= (int) $chartValues[$i] ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($report === 'doctors'): ?>

  <div class="table-card mt-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr><th>Doctor</th><th>Specialization</th><th>Clinic</th><th>Experience</th><th>Fee</th>
              <th>Appointments</th><th>Revenue Collected</th></tr>
        </thead>
        <tbody>
        <?php if (!$doctorRows): ?>
          <tr><td colspan="7"><div class="empty"><?= icon('search') ?><div>No doctors found.</div></div></td></tr>
        <?php else: foreach ($doctorRows as $dr): ?>
          <tr>
            <td><?= e($dr['doctor_name']) ?></td>
            <td><?= e($dr['specialization_name'] ?: '-') ?></td>
            <td><?= e($dr['clinic_name'] ?: '-') ?></td>
            <td><?= (int) $dr['experience_years'] ?> yrs</td>
            <td><?= e(money($dr['consultation_fee'])) ?></td>
            <td><?= (int) $dr['appt_count'] ?></td>
            <td><?= e(money($dr['revenue'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($report === 'appointments'): ?>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Appointments by Status</h2></div>
    <div class="card-body"><div class="chart-box"><canvas id="reportChart"></canvas></div></div>
  </div>

  <div class="table-card mt-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>#</th><th>Patient</th><th>Doctor</th><th>Date</th><th>Status</th><th>Payment</th><th>Fee</th></tr></thead>
        <tbody>
        <?php if (!$apptRows): ?>
          <tr><td colspan="7"><div class="empty"><?= icon('search') ?><div>No appointments in this period.</div></div></td></tr>
        <?php else: foreach ($apptRows as $ar): ?>
          <tr>
            <td><?= (int) $ar['id'] ?></td>
            <td><?= e($ar['patient_name']) ?></td>
            <td><?= e($ar['doctor_name']) ?></td>
            <td><?= e(format_date($ar['appointment_date'])) ?> <?= e(format_time($ar['start_time'])) ?></td>
            <td><?= status_badge($ar['status']) ?></td>
            <td><?= status_badge($ar['payment_status']) ?></td>
            <td><?= e(money($ar['consultation_fee'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($report === 'medicines'): ?>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Medicines by Category</h2></div>
    <div class="card-body"><div class="chart-box"><canvas id="reportChart"></canvas></div></div>
  </div>

  <div class="table-card mt-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Medicine</th><th>Category</th><th>Patient</th><th>Added</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$medRows): ?>
          <tr><td colspan="5"><div class="empty"><?= icon('search') ?><div>No medicines added in this period.</div></div></td></tr>
        <?php else: foreach ($medRows as $mr): ?>
          <tr>
            <td><?= e($mr['medicine_name']) ?></td>
            <td><?= e($mr['category'] ?: '-') ?></td>
            <td><?= e($mr['patient_name']) ?></td>
            <td><?= e(format_date($mr['created_at'])) ?></td>
            <td><?= status_badge($mr['status']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($report === 'adherence'): ?>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Overall Adherence</h2></div>
    <div class="card-body">
      <div class="chart-box"><canvas id="reportChart"></canvas></div>
      <div class="chart-legend" id="reportLegend"></div>
    </div>
  </div>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Adherence by Patient (worst first)</h2></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Patient</th><th>Taken</th><th>Missed</th><th>Skipped</th><th>Total</th><th>Adherence</th></tr></thead>
        <tbody>
        <?php if (!$adhRows): ?>
          <tr><td colspan="6"><div class="empty"><?= icon('search') ?><div>No dose logs recorded in this period.</div></div></td></tr>
        <?php else: foreach ($adhRows as $ar): ?>
          <tr>
            <td><?= e($ar['patient_name']) ?></td>
            <td><?= $ar['taken'] ?></td>
            <td><?= $ar['missed'] ?></td>
            <td><?= $ar['skipped'] ?></td>
            <td><?= $ar['total'] ?></td>
            <td><?= e($ar['rate']) ?>%</td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($report === 'appointment_status'): ?>

  <div class="table-card mt-4">
    <div class="table-card-head"><h2>Appointment Status Breakdown</h2></div>
    <div class="card-body">
      <div class="chart-box"><canvas id="reportChart"></canvas></div>
      <div class="chart-legend" id="reportLegend"></div>
    </div>
  </div>

  <div class="table-card mt-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Status</th><th>Count</th><th>Percentage</th></tr></thead>
        <tbody>
        <?php foreach ($chartLabels as $i => $label): ?>
          <tr>
            <td><?= e($label) ?></td>
            <td><?= (int) $chartValues[$i] ?></td>
            <td><?= $totalAppts > 0 ? round($chartValues[$i] * 100 / $totalAppts, 1) : 0 ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php if ($chartType): ?>
<script>
window.renderMediCareCharts = function () {
  var labels = <?= json_encode($chartLabels) ?>;
  var values = <?= json_encode($chartValues) ?>;
  <?php if ($chartType === 'line'): ?>
    MediCharts.line('reportChart', {labels: labels, values: values});
  <?php elseif ($chartType === 'bar'): ?>
    MediCharts.bar('reportChart', {labels: labels, values: values});
  <?php elseif ($chartType === 'doughnut'): ?>
    MediCharts.doughnut('reportChart', {labels: labels, values: values, legend: 'reportLegend', centerLabel: 'Total'});
  <?php endif; ?>
};
document.addEventListener('DOMContentLoaded', window.renderMediCareCharts);
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
