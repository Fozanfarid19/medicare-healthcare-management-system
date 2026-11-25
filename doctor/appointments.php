<?php
/**
 * MediCare - doctor/appointments.php
 * The logged-in doctor's appointment list, with filters, status changes,
 * payment marking and consultation notes. Every write is ownership-checked.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/appointments.php');
    $action = post('action');
    $id     = post_int('id');

    // Every write below re-checks ownership: the appointment must belong to this doctor.
    $owns = $id > 0 && db_exists('SELECT 1 FROM appointments WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);

    if (!$owns) {
        flash_error('That appointment does not belong to you.');
    } elseif ($action === 'set_status') {
        $status = post('status');
        $result = change_appointment_status($id, $status, 'doctor');
        $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);
    } elseif ($action === 'mark_paid') {
        $result = mark_appointment_paid($id);
        $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);
    } elseif ($action === 'save_notes') {
        $notes = post('notes');
        db_execute('UPDATE appointments SET notes = ? WHERE id = ? AND doctor_id = ?', [null_if_blank($notes), $id, $doctorId]);
        flash_success('Consultation notes saved.');
    }

    redirect('doctor/appointments.php');
}

// ---------- 2. Read filters + query data ----------
$search   = get_param('q');
$status   = get_param('status');
$dateFrom = get_param('date_from');
$dateTo   = get_param('date_to');
$view     = get_param('view');

$where  = ['a.doctor_id = ?'];
$params = [$doctorId];

if ($search !== '') {
    $where[]  = "CONCAT(p.first_name,' ',p.last_name) LIKE ?";
    $params[] = "%$search%";
}
if ($status !== '' && in_array($status, appointment_statuses(), true)) {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}
if ($dateFrom !== '' && is_valid_date($dateFrom)) {
    $where[]  = 'a.appointment_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && is_valid_date($dateTo)) {
    $where[]  = 'a.appointment_date <= ?';
    $params[] = $dateTo;
}
if ($view === 'today') {
    $where[] = 'a.appointment_date = CURDATE()';
} elseif ($view === 'upcoming') {
    $where[] = "a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')";
} elseif ($view === 'past') {
    $where[] = "(a.appointment_date < CURDATE() OR a.status IN ('completed','cancelled','no_show'))";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_scalar(
    "SELECT COUNT(*) FROM appointments a JOIN patients p ON p.id = a.patient_id $whereSql",
    $params
);
$pg = paginate($total);

$appointments = db_all(
    appointment_select_sql() . " $whereSql ORDER BY a.appointment_date DESC, a.start_time ASC LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

// ---------- 3. Stat cards (not affected by filters) ----------
$cardStats = [
    'today'     => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE()", [$doctorId]),
    'pending'   => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'pending'", [$doctorId]),
    'confirmed' => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'confirmed'", [$doctorId]),
    'completed' => (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'completed'", [$doctorId]),
];

$queryKeep = ['q' => $search, 'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'view' => $view];

$page_title = 'Appointments';
$active     = 'appointments';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Appointments</div>
        <h1>Appointments</h1>
        <div class="sub">Manage your appointment requests and schedule.</div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat navy"><div class="stat-ic"><?= icon('calendar') ?></div>
        <div><div class="stat-value"><?= (int) $cardStats['today'] ?></div><div class="stat-label">Today</div></div></div>
    <div class="stat amber"><div class="stat-ic"><?= icon('clock') ?></div>
        <div><div class="stat-value"><?= (int) $cardStats['pending'] ?></div><div class="stat-label">Pending</div></div></div>
    <div class="stat"><div class="stat-ic"><?= icon('calendar-check') ?></div>
        <div><div class="stat-value"><?= (int) $cardStats['confirmed'] ?></div><div class="stat-label">Confirmed</div></div></div>
    <div class="stat green"><div class="stat-ic"><?= icon('check-circle') ?></div>
        <div><div class="stat-value"><?= (int) $cardStats['completed'] ?></div><div class="stat-label">Completed</div></div></div>
</div>

<div class="d-flex gap-2 flex-wrap mb-3">
    <a class="btn btn-sm <?= $view === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('doctor/appointments.php')) ?>">All</a>
    <a class="btn btn-sm <?= $view === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('doctor/appointments.php?view=today')) ?>">Today</a>
    <a class="btn btn-sm <?= $view === 'upcoming' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('doctor/appointments.php?view=upcoming')) ?>">Upcoming</a>
    <a class="btn btn-sm <?= $view === 'past' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('doctor/appointments.php?view=past')) ?>">Past</a>
</div>

<div class="table-card">
    <form class="filter-bar" method="get">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="grow">
            <label class="form-label">Search Patient</label>
            <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Patient name">
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All Statuses</option>
                <?= options_from(array_combine(appointment_statuses(), array_map('nice_label', appointment_statuses())), $status) ?>
            </select>
        </div>
        <div>
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div>
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div><button class="btn btn-primary"><?= icon('search') ?> Search</button>
             <a class="btn btn-outline-secondary" href="<?= e(url('doctor/appointments.php')) ?>">Reset</a></div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Date</th><th>Time</th><th>Patient</th><th>Reason</th><th>Status</th><th>Payment</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$appointments): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('search') ?><div>No appointments found.</div></div></td></tr>
            <?php else: foreach ($appointments as $a): ?>
                <tr>
                    <td><?= e(format_date($a['appointment_date'])) ?></td>
                    <td><?= e(format_time($a['start_time'])) ?></td>
                    <td><a href="<?= e(url('doctor/patient_record.php?id=' . (int) $a['p_id'])) ?>"><?= e($a['patient_name']) ?></a></td>
                    <td><?= e(str_limit($a['reason'] ?? '-', 30)) ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td><?= status_badge($a['payment_status']) ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#apptDetailModal"
                            data-appt-id="<?= (int) $a['id'] ?>"
                            data-patient-name="<?= e($a['patient_name']) ?>"
                            data-patient-phone="<?= e($a['patient_phone']) ?>"
                            data-date="<?= e(format_date($a['appointment_date'])) ?>"
                            data-time="<?= e(format_time($a['start_time']) . ' - ' . format_time($a['end_time'])) ?>"
                            data-reason="<?= e($a['reason'] ?? '-') ?>"
                            data-status="<?= e($a['status']) ?>"
                            data-payment-status="<?= e($a['payment_status']) ?>"
                            data-fee="<?= e(money($a['consultation_fee'])) ?>"
                            data-notes="<?= e($a['notes'] ?? '') ?>"
                            data-patient-record-url="<?= e(url('doctor/patient_record.php?id=' . (int) $a['p_id'])) ?>">
                            <?= icon('eye') ?> Details
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 pb-3"><?php render_pagination($pg, $queryKeep); ?></div>
</div>

<!-- Detail modal: filled from the trigger button's data-* attributes -->
<div class="modal" id="apptDetailModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog modal-lg"><div class="modal-content">
   <div class="modal-header">
     <h5 class="modal-title">Appointment Details</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
     <div class="row">
        <div class="col-md-6">
            <div class="kv"><span class="k">Patient</span><span class="v" data-fill="patientName"></span></div>
            <div class="kv"><span class="k">Phone</span><span class="v" data-fill="patientPhone"></span></div>
            <div class="kv"><span class="k">Date</span><span class="v" data-fill="date"></span></div>
            <div class="kv"><span class="k">Time</span><span class="v" data-fill="time"></span></div>
        </div>
        <div class="col-md-6">
            <div class="kv"><span class="k">Reason</span><span class="v" data-fill="reason"></span></div>
            <div class="kv"><span class="k">Status</span><span class="v" data-fill="status"></span></div>
            <div class="kv"><span class="k">Payment</span><span class="v" data-fill="paymentStatus"></span></div>
            <div class="kv"><span class="k">Fee</span><span class="v" data-fill="fee"></span></div>
        </div>
     </div>

     <div class="d-flex gap-2 flex-wrap my-3">
        <a id="modalPatientLink" href="#" class="btn btn-sm btn-outline-secondary"><?= icon('patient') ?> View Patient Record</a>

        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" class="js-appt-id">
            <input type="hidden" name="status" value="confirmed">
            <button class="btn btn-sm btn-primary"><?= icon('check') ?> Confirm</button>
        </form>
        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" class="js-appt-id">
            <input type="hidden" name="status" value="completed">
            <button class="btn btn-sm btn-outline-primary"><?= icon('check-circle') ?> Mark Completed</button>
        </form>
        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline" data-confirm="Cancel this appointment?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" class="js-appt-id">
            <input type="hidden" name="status" value="no_show">
            <button class="btn btn-sm btn-outline-secondary"><?= icon('alert-circle') ?> No Show</button>
        </form>
        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline" data-confirm="Cancel this appointment?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" class="js-appt-id">
            <input type="hidden" name="status" value="cancelled">
            <button class="btn btn-sm btn-outline-danger"><?= icon('close') ?> Cancel</button>
        </form>
        <form method="post" action="<?= e(url('doctor/appointments.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="id" class="js-appt-id">
            <button class="btn btn-sm btn-outline-success"><?= icon('money') ?> Mark Paid</button>
        </form>
     </div>

     <form method="post" action="<?= e(url('doctor/appointments.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_notes">
        <input type="hidden" name="id" class="js-appt-id">
        <label class="form-label">Consultation Notes</label>
        <textarea class="form-control mb-2" name="notes" rows="4" data-fill="notes"></textarea>
        <button class="btn btn-primary"><?= icon('edit') ?> Save Notes</button>
     </form>
   </div>
 </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('apptDetailModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget || (event.detail && event.detail.relatedTarget) || null;
        if (!trigger) { return; }
        var id = trigger.dataset.apptId || '';
        modal.querySelectorAll('.js-appt-id').forEach(function (el) { el.value = id; });
        var link = modal.querySelector('#modalPatientLink');
        if (link) { link.href = trigger.dataset.patientRecordUrl || '#'; }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
