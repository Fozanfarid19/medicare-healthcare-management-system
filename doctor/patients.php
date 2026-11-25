<?php
/**
 * MediCare - doctor/patients.php
 * All patients related to the logged-in doctor (via an appointment OR a
 * medicine they prescribed). Search + pagination.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- Read filters ----------
$search = get_param('q');

$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = 'AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)';
    $like = "%$search%";
    $searchParams = [$like, $like, $like];
}

// The set of patients related to this doctor (appointment OR prescribed medicine).
$relatedSql = "SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?
               UNION
               SELECT DISTINCT patient_id FROM medicines WHERE prescribed_by = ?";

$total = (int) db_scalar(
    "SELECT COUNT(*) FROM patients p WHERE p.id IN ($relatedSql) $searchSql",
    array_merge([$doctorId, $doctorId], $searchParams)
);
$pg = paginate($total);

$patients = db_all(
    "SELECT p.*,
            (SELECT MAX(a2.appointment_date) FROM appointments a2 WHERE a2.patient_id = p.id AND a2.doctor_id = ?) AS last_visit,
            (SELECT COUNT(*) FROM appointments a3 WHERE a3.patient_id = p.id AND a3.doctor_id = ?) AS appt_count,
            (SELECT COUNT(*) FROM medicines m2 WHERE m2.patient_id = p.id AND m2.prescribed_by = ? AND m2.status = 'active') AS active_meds
       FROM patients p
      WHERE p.id IN ($relatedSql) $searchSql
      ORDER BY p.first_name, p.last_name
      LIMIT ? OFFSET ?",
    array_merge([$doctorId, $doctorId, $doctorId, $doctorId, $doctorId], $searchParams, [$pg['per_page'], $pg['offset']])
);

$page_title = 'My Patients';
$active     = 'patients';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / My Patients</div>
        <h1>My Patients</h1>
        <div class="sub">Patients who have an appointment with you or a medicine you prescribed.</div>
    </div>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <div class="grow">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Patient name or phone">
        </div>
        <div><button class="btn btn-primary"><?= icon('search') ?> Search</button>
             <a class="btn btn-outline-secondary" href="<?= e(url('doctor/patients.php')) ?>">Reset</a></div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th><th>Age</th><th>Gender</th><th>Blood Group</th><th>Phone</th>
                    <th>Last Visit</th><th># Appointments</th><th># Active Medicines</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$patients): ?>
                <tr><td colspan="9"><div class="empty"><?= icon('search') ?><div>No patients found.</div></div></td></tr>
            <?php else: foreach ($patients as $p): ?>
                <tr>
                    <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= e(calculate_age($p['date_of_birth'])) ?></td>
                    <td><?= e($p['gender']) ?></td>
                    <td><?= e($p['blood_group']) ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td><?= $p['last_visit'] ? e(format_date($p['last_visit'])) : '-' ?></td>
                    <td><?= (int) $p['appt_count'] ?></td>
                    <td><?= (int) $p['active_meds'] ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('doctor/patient_record.php?id=' . (int) $p['id'])) ?>">
                            <?= icon('eye') ?> View Record
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, ['q' => $search]); ?></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
