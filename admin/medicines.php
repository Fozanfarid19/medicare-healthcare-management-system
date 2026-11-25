<?php
/**
 * MediCare - admin/medicines.php
 * Every medicine registered in the system, with filters and a status
 * management action. A "view" panel shows reminder times and the last
 * 10 adherence log rows for one medicine.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('admin');

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('admin/medicines.php');
    $action = post('action');

    if ($action === 'set_status') {
        $id        = post_int('id');
        $newStatus = post('status');

        if (!in_array($newStatus, ['active', 'inactive', 'discontinued'], true)) {
            flash_error('Unknown medicine status.');
        } else {
            $affected = db_execute('UPDATE medicines SET status = ? WHERE id = ?', [$newStatus, $id]);
            $affected ? flash_success('Medicine status updated.') : flash_error('Medicine not found.');
        }
    }

    redirect('admin/medicines.php');
}

// ---------- 2. Read filters + query data ----------
$search   = get_param('q');
$category = get_param('category');
$status   = get_param('status');
$doctorId = get_int('doctor_id');

$where  = []; $params = [];
if ($search !== '') {
    $where[]  = "(m.medicine_name LIKE ? OR m.generic_name LIKE ? OR CONCAT(p.first_name,' ',p.last_name) LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category !== '') {
    $where[]  = 'm.category = ?';
    $params[] = $category;
}
if ($status !== '') {
    $where[]  = 'm.status = ?';
    $params[] = $status;
}
if ($doctorId) {
    $where[]  = 'm.prescribed_by = ?';
    $params[] = $doctorId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_scalar(
    "SELECT COUNT(*) FROM medicines m
       JOIN patients p ON p.id = m.patient_id
       LEFT JOIN doctors d ON d.id = m.prescribed_by
       $whereSql",
    $params
);
$pg   = paginate($total);
$rows = db_all(
    "SELECT m.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
            d.first_name AS doc_first, d.last_name AS doc_last
       FROM medicines m
       JOIN patients p ON p.id = m.patient_id
       LEFT JOIN doctors d ON d.id = m.prescribed_by
       $whereSql
      ORDER BY m.created_at DESC
      LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// ---------- Detail panel (?view=id) ----------
$viewId       = get_int('view');
$viewMedicine = null;
$viewLogs     = [];
if ($viewId) {
    $viewMedicine = db_row(
        "SELECT m.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name
           FROM medicines m JOIN patients p ON p.id = m.patient_id
          WHERE m.id = ?",
        [$viewId]
    );
    if ($viewMedicine) {
        $viewLogs = db_all(
            "SELECT l.* FROM medicine_logs l
               JOIN medicine_reminders r ON r.id = l.reminder_id
              WHERE r.medicine_id = ?
              ORDER BY l.scheduled_at DESC LIMIT 10",
            [$viewId]
        );
    }
}

// ---------- Stat cards ----------
$statTotalMeds       = (int) db_scalar('SELECT COUNT(*) FROM medicines');
$statActiveMeds      = (int) db_scalar("SELECT COUNT(*) FROM medicines WHERE status = 'active'");
$statDiscontinued    = (int) db_scalar("SELECT COUNT(*) FROM medicines WHERE status = 'discontinued'");
$statActiveReminders = (int) db_scalar("SELECT COUNT(*) FROM medicine_reminders WHERE status = 'active'");
$statDosesToday      = (int) db_scalar('SELECT COUNT(*) FROM medicine_logs WHERE DATE(action_time) = CURDATE()');

// ---------- Drop-down data ----------
$allDoctors = db_all("SELECT id, CONCAT('Dr. ', first_name, ' ', last_name) AS name FROM doctors ORDER BY first_name, last_name");

// ---------- 3. Render ----------
$page_title = 'Medicines';
$active     = 'medicines';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Medicines</div>
    <h1>Medicine Oversight</h1>
    <div class="sub">Every medicine registered by every patient, self-added or prescribed.</div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat-ic"><?= icon('pill') ?></div>
    <div><div class="stat-value"><?= $statTotalMeds ?></div><div class="stat-label">Total Medicines</div></div></div>
  <div class="stat green"><div class="stat-ic"><?= icon('check-circle') ?></div>
    <div><div class="stat-value"><?= $statActiveMeds ?></div><div class="stat-label">Active</div></div></div>
  <div class="stat red"><div class="stat-ic"><?= icon('close') ?></div>
    <div><div class="stat-value"><?= $statDiscontinued ?></div><div class="stat-label">Discontinued</div></div></div>
  <div class="stat navy"><div class="stat-ic"><?= icon('bell') ?></div>
    <div><div class="stat-value"><?= $statActiveReminders ?></div><div class="stat-label">Active Reminders</div></div></div>
  <div class="stat amber"><div class="stat-ic"><?= icon('history') ?></div>
    <div><div class="stat-value"><?= $statDosesToday ?></div><div class="stat-label">Doses Logged Today</div></div></div>
</div>

<?php if ($viewId): ?>
  <div class="table-card mt-4">
    <div class="table-card-head d-flex justify-content-between align-items-center">
      <h2>Medicine Detail</h2>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/medicines.php')) ?>"><?= icon('close') ?> Close</a>
    </div>
    <div class="card-body">
      <?php if (!$viewMedicine): ?>
        <div class="empty"><?= icon('search') ?><div>Medicine not found.</div></div>
      <?php else: ?>
        <p><strong><?= e($viewMedicine['medicine_name']) ?></strong> for <?= e($viewMedicine['patient_name']) ?></p>
        <p>Reminder times: <?= reminder_times_label($viewMedicine['id']) ?></p>
        <h6 class="mt-3">Last 10 log entries</h6>
        <?php if (!$viewLogs): ?>
          <div class="empty"><?= icon('history') ?><div>No adherence logs recorded yet.</div></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Scheduled</th><th>Action</th><th>Recorded</th><th>Notes</th></tr></thead>
              <tbody>
              <?php foreach ($viewLogs as $log): ?>
                <tr>
                  <td><?= e(format_datetime($log['scheduled_at'])) ?></td>
                  <td><?= status_badge($log['action']) ?></td>
                  <td><?= e(format_datetime($log['action_time'])) ?></td>
                  <td><?= e($log['notes'] ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="table-card mt-4">
  <form class="filter-bar" method="get">
    <div class="grow">
      <label class="form-label">Search</label>
      <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Medicine, generic or patient name">
    </div>
    <div>
      <label class="form-label">Category</label>
      <select class="form-select auto-submit" name="category">
        <option value="">All</option>
        <?= options_from(medicine_categories(), $category) ?>
      </select>
    </div>
    <div>
      <label class="form-label">Status</label>
      <select class="form-select auto-submit" name="status">
        <option value="">All</option>
        <?= options_from(['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'], $status) ?>
      </select>
    </div>
    <div>
      <label class="form-label">Prescribed By</label>
      <select class="form-select auto-submit" name="doctor_id">
        <option value="">All</option>
        <?= options_from_rows($allDoctors, 'id', 'name', $doctorId) ?>
      </select>
    </div>
    <div>
      <button class="btn btn-primary"><?= icon('search') ?> Search</button>
      <a class="btn btn-outline-secondary" href="<?= e(url('admin/medicines.php')) ?>">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Medicine</th><th>Category</th><th>Patient</th><th>Prescribed By</th>
          <th>Dose</th><th>Frequency</th><th>Route</th><th>Period</th>
          <th>Reminders</th><th>Status</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11"><div class="empty"><?= icon('search') ?><div>No medicines found.</div></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['medicine_name']) ?><br><span class="text-muted small"><?= e($r['generic_name'] ?: '-') ?></span></td>
          <td><?= e($r['category'] ?: '-') ?></td>
          <td><a href="<?= e(url('admin/patient_view.php?id=' . (int) $r['patient_id'])) ?>"><?= e($r['patient_name']) ?></a></td>
          <td><?= $r['prescribed_by'] ? 'Dr. ' . e($r['doc_first'] . ' ' . $r['doc_last']) : 'Self added' ?></td>
          <td><?= e($r['dosage'] . ' ' . $r['dose_unit']) ?></td>
          <td><?= e($r['frequency']) ?></td>
          <td><?= e($r['route']) ?></td>
          <td><?= e(format_date($r['start_date'])) ?> - <?= $r['end_date'] ? e(format_date($r['end_date'])) : 'Ongoing' ?></td>
          <td><?= reminder_times_label($r['id']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(url('admin/medicines.php?' . http_build_query(array_merge($_GET, ['view' => $r['id']])))) ?>">
              <?= icon('eye') ?>
            </a>
            <form method="post" class="d-inline-flex gap-1" data-confirm="Change the status of this medicine?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <select name="status" class="form-select form-select-sm" style="width:auto">
                <?= options_from(['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'], $r['status']) ?>
              </select>
              <button class="btn btn-sm btn-outline-primary"><?= icon('check') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="px-3 pb-3">
    <?php render_pagination($pg, ['q' => $search, 'category' => $category, 'status' => $status, 'doctor_id' => $doctorId]); ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
