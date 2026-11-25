<?php
/**
 * MediCare - patient/doctors.php
 * "Find a Doctor" search: name, specialization, clinic and city filters.
 * Only active doctors are shown.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('patient');

// ---------- 1. Filters ----------
$search  = get_param('q');
$specId  = get_int('specialization_id');
$clinicId = get_int('clinic_id');
$city    = get_param('city');

$where  = ["d.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(CONCAT(d.first_name,' ',d.last_name) LIKE ? OR d.qualification LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($specId > 0) {
    $where[] = 'd.specialization_id = ?';
    $params[] = $specId;
}
if ($clinicId > 0) {
    $where[] = 'd.clinic_id = ?';
    $params[] = $clinicId;
}
if ($city !== '') {
    $where[] = 'c.city LIKE ?';
    $params[] = "%$city%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// ---------- 2. Data ----------
$total = (int) db_scalar(
    "SELECT COUNT(*) FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c ON c.id = d.clinic_id $whereSql",
    $params
);
$pg = paginate($total);

$doctors = db_all(
    "SELECT d.*, s.specialization_name, c.clinic_name, c.city
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c ON c.id = d.clinic_id
      $whereSql
      ORDER BY d.first_name, d.last_name
      LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$page_title = 'Find a Doctor';
$active     = 'doctors';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / Find a Doctor</div>
        <h1>Find a Doctor</h1>
        <div class="sub">Search our network of active doctors and book an appointment.</div>
    </div>
</div>

<div class="table-card mb-3">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Doctor name or qualification">
        </div>
        <div>
            <label class="form-label">Specialization</label>
            <select class="form-select" name="specialization_id">
                <option value="0">All</option>
                <?= options_from_rows(all_specializations(), 'id', 'specialization_name', (string) $specId) ?>
            </select>
        </div>
        <div>
            <label class="form-label">Clinic</label>
            <select class="form-select" name="clinic_id">
                <option value="0">All</option>
                <?= options_from_rows(all_clinics(), 'id', 'clinic_name', (string) $clinicId) ?>
            </select>
        </div>
        <div>
            <label class="form-label">City</label>
            <input type="text" class="form-control" name="city" value="<?= e($city) ?>" placeholder="City">
        </div>
        <div>
            <button class="btn btn-primary"><?= icon('search') ?> Search</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('patient/doctors.php')) ?>">Reset</a>
        </div>
    </form>
</div>

<?php if (!$doctors): ?>
    <div class="table-card"><div class="empty"><?= icon('search') ?><div>No doctors match your search.</div></div></div>
<?php else: ?>
    <div class="row">
    <?php foreach ($doctors as $d): ?>
        <div class="col-md-4 mb-3">
            <div class="doc-card">
                <div class="doc-top">
                    <div class="doc-av"><?= e(strtoupper(substr($d['first_name'], 0, 1))) ?></div>
                    <div>
                        <div class="fw-bold">Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?></div>
                        <div class="small text-muted"><?= e($d['specialization_name'] ?? 'General') ?></div>
                    </div>
                </div>
                <div class="small text-muted mb-1"><?= e($d['qualification'] ?? '-') ?></div>
                <div class="small text-muted mb-1"><?= (int) $d['experience_years'] ?> years experience</div>
                <div class="small text-muted mb-2">
                    <?= e($d['clinic_name'] ?? 'No clinic assigned') ?><?= $d['city'] ? ', ' . e($d['city']) : '' ?>
                </div>
                <div class="doc-fee mb-2"><?= e(money($d['consultation_fee'])) ?></div>
                <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-primary flex-fill" href="<?= e(url('patient/doctor_view.php?id=' . (int) $d['id'])) ?>"><?= icon('eye') ?> View Profile</a>
                    <a class="btn btn-sm btn-primary flex-fill" href="<?= e(url('patient/appointments.php?doctor_id=' . (int) $d['id'] . '&book=1')) ?>"><?= icon('calendar') ?> Book</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="px-1"><?php render_pagination($pg, ['q' => $search, 'specialization_id' => $specId, 'clinic_id' => $clinicId, 'city' => $city]); ?></div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
