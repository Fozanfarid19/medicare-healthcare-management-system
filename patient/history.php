<?php
/**
 * MediCare - patient/history.php
 * Medicine adherence history: date range filters, adherence summary and
 * the full log of taken / missed / skipped / snoozed doses.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('patient');

$patientId = current_patient_id();
mark_missed_doses($patientId);

// ---------- 1. Date range ----------
$range = get_param('range', 'today');

switch ($range) {
    case 'yesterday':
        $from = $to = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        break;
    case 'month':
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
    case 'custom':
        $from = get_param('from');
        $to   = get_param('to');
        if (!is_valid_date($from)) { $from = date('Y-m-d'); }
        if (!is_valid_date($to))   { $to   = date('Y-m-d'); }
        if ($to < $from) { $to = $from; }
        break;
    case 'today':
    default:
        $range = 'today';
        $from = $to = date('Y-m-d');
        break;
}

// ---------- 2. Other filters ----------
$statusFilter = get_param('status');
$medFilter    = get_int('medicine_id');

$where  = ['l.patient_id = ?', 'l.scheduled_at BETWEEN ? AND ?'];
$params = [$patientId, $from . ' 00:00:00', $to . ' 23:59:59'];

if ($statusFilter !== '' && in_array($statusFilter, ['taken', 'missed', 'skipped', 'snoozed'], true)) {
    $where[] = 'l.action = ?';
    $params[] = $statusFilter;
}
if ($medFilter > 0) {
    $where[] = 'm.id = ?';
    $params[] = $medFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$baseSql = "FROM medicine_logs l
              JOIN medicine_reminders r ON r.id = l.reminder_id
              JOIN medicines m ON m.id = r.medicine_id
             $whereSql";

$total = (int) db_scalar("SELECT COUNT(*) $baseSql", $params);
$pg    = paginate($total);

$logs = db_all(
    "SELECT l.*, m.medicine_name, m.dosage, m.dose_unit $baseSql
      ORDER BY l.scheduled_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// ---------- 3. Adherence summary ----------
$stats = adherence_stats($patientId, $from, $to);

$perMedicine = db_all(
    "SELECT m.id, m.medicine_name,
            SUM(l.action = 'taken')   AS taken,
            SUM(l.action = 'missed')  AS missed,
            SUM(l.action = 'skipped') AS skipped,
            COUNT(*) AS total
       FROM medicine_logs l
       JOIN medicine_reminders r ON r.id = l.reminder_id
       JOIN medicines m ON m.id = r.medicine_id
      WHERE l.patient_id = ? AND l.scheduled_at BETWEEN ? AND ?
      GROUP BY m.id, m.medicine_name
      ORDER BY m.medicine_name",
    [$patientId, $from . ' 00:00:00', $to . ' 23:59:59']
);

$myMedicines = db_all(
    'SELECT id, medicine_name FROM medicines WHERE patient_id = ? ORDER BY medicine_name',
    [$patientId]
);

$page_title = 'Medicine History';
$active     = 'history';
include __DIR__ . '/../includes/header.php';
?>

<div class="print-head">
    <h3><?= e(APP_NAME) ?> - Medicine History</h3>
    <div><?= e(current_name()) ?> &middot; <?= e(format_date($from)) ?> to <?= e(format_date($to)) ?></div>
</div>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / Medicine History</div>
        <h1>Medicine History</h1>
        <div class="sub">Adherence summary and full dose log.</div>
    </div>
    <div>
        <button class="btn btn-outline-secondary" data-print><?= icon('print') ?> Print</button>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <div class="d-flex gap-2 flex-wrap mb-3">
        <a class="btn btn-sm <?= $range === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/history.php?range=today')) ?>">Today</a>
        <a class="btn btn-sm <?= $range === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/history.php?range=yesterday')) ?>">Yesterday</a>
        <a class="btn btn-sm <?= $range === 'week' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/history.php?range=week')) ?>">This Week</a>
        <a class="btn btn-sm <?= $range === 'month' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/history.php?range=month')) ?>">This Month</a>
    </div>
    <form class="filter-bar" method="get">
        <input type="hidden" name="range" value="custom">
        <div>
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <option value="taken" <?= $statusFilter === 'taken' ? 'selected' : '' ?>>Taken</option>
                <option value="missed" <?= $statusFilter === 'missed' ? 'selected' : '' ?>>Missed</option>
                <option value="skipped" <?= $statusFilter === 'skipped' ? 'selected' : '' ?>>Skipped</option>
                <option value="snoozed" <?= $statusFilter === 'snoozed' ? 'selected' : '' ?>>Snoozed</option>
            </select>
        </div>
        <div>
            <label class="form-label">Medicine</label>
            <select class="form-select" name="medicine_id">
                <option value="0">All</option>
                <?= options_from_rows($myMedicines, 'id', 'medicine_name', (string) $medFilter) ?>
            </select>
        </div>
        <div>
            <button class="btn btn-primary"><?= icon('search') ?> Apply</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('patient/history.php')) ?>">Reset</a>
        </div>
    </form>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-3">Adherence Summary (<?= e(format_date($from)) ?> - <?= e(format_date($to)) ?>)</h6>
    <div class="d-flex justify-content-between small text-muted mb-1">
        <span>Adherence Rate</span><span><?= e((string) $stats['rate']) ?>%</span>
    </div>
    <div class="progress mb-3" style="height:10px">
        <div class="progress-bar bg-success" role="progressbar" style="width: <?= e((string) $stats['rate']) ?>%"></div>
    </div>
    <div class="row text-center">
        <div class="col-4"><div class="fw-bold fs-5 text-success"><?= (int) $stats['taken'] ?></div><div class="small text-muted">Taken</div></div>
        <div class="col-4"><div class="fw-bold fs-5 text-danger"><?= (int) $stats['missed'] ?></div><div class="small text-muted">Missed</div></div>
        <div class="col-4"><div class="fw-bold fs-5 text-secondary"><?= (int) $stats['skipped'] ?></div><div class="small text-muted">Skipped</div></div>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Per-Medicine Breakdown</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Medicine</th><th>Taken</th><th>Missed</th><th>Skipped</th><th>Total</th><th>Rate</th></tr></thead>
            <tbody>
            <?php if (!$perMedicine): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('pill') ?><div>No dose activity in this period.</div></div></td></tr>
            <?php else: foreach ($perMedicine as $pm):
                $rate = $pm['total'] > 0 ? round($pm['taken'] * 100 / $pm['total'], 1) : 0;
            ?>
                <tr>
                    <td><?= e($pm['medicine_name']) ?></td>
                    <td><?= (int) $pm['taken'] ?></td>
                    <td><?= (int) $pm['missed'] ?></td>
                    <td><?= (int) $pm['skipped'] ?></td>
                    <td><?= (int) $pm['total'] ?></td>
                    <td><?= e((string) $rate) ?>%</td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card">
    <div class="table-card-head"><strong><?= icon('history') ?> Dose Log</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Medicine</th><th>Dose</th><th>Scheduled</th><th>Action Time</th><th>Status</th><th>Notes</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('history') ?><div>No log entries for the selected filters.</div></div></td></tr>
            <?php else: foreach ($logs as $l): ?>
                <tr>
                    <td><?= e($l['medicine_name']) ?></td>
                    <td><?= e(trim($l['dosage'] . ' ' . $l['dose_unit'])) ?></td>
                    <td><?= e(format_datetime($l['scheduled_at'])) ?></td>
                    <td><?= e(format_datetime($l['action_time'])) ?></td>
                    <td><?= status_badge($l['action']) ?></td>
                    <td><?= e($l['notes'] ?? '-') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['range' => $range, 'from' => $from, 'to' => $to, 'status' => $statusFilter, 'medicine_id' => $medFilter]); ?></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
