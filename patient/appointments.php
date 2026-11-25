<?php
/**
 * MediCare - patient/appointments.php
 * List of the patient's appointments (upcoming/past/cancelled/all) with a
 * cancel action, plus an always-available booking panel that loads slots
 * from api/appointment_slots.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('patient');

$patientId = current_patient_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('patient/appointments.php');
    $action = post('action');

    if ($action === 'book') {
        $doctorId = post_int('doctor_id');
        $date     = post('appointment_date');
        $start    = post('start_time');
        $reason   = post('reason');

        $result = book_appointment($patientId, $doctorId, $date, $start, $reason);
        if ($result['ok']) { flash_success($result['message']); } else { flash_error($result['message']); }
    } elseif ($action === 'cancel') {
        $apptId = post_int('appointment_id');
        $appt   = db_row(
            "SELECT id, status FROM appointments WHERE id = ? AND patient_id = ?",
            [$apptId, $patientId]
        );
        if (!$appt) {
            flash_error('Appointment not found.');
        } elseif (!in_array($appt['status'], ['pending', 'confirmed'], true)) {
            flash_error('Only pending or confirmed appointments can be cancelled.');
        } else {
            $result = change_appointment_status($apptId, 'cancelled', 'patient');
            if ($result['ok']) { flash_success($result['message']); } else { flash_error($result['message']); }
        }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/appointments.php');
}

// ---------- 2. Filters + list ----------
$view    = get_param('view', 'upcoming');
$status  = get_param('status');
$search  = get_param('q');
$bookFlag = get_int('book');
$preselectDoctor = get_int('doctor_id');

$where  = ['a.patient_id = ?'];
$params = [$patientId];

if ($view === 'upcoming') {
    $where[] = "a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')";
} elseif ($view === 'past') {
    $where[] = "(a.appointment_date < CURDATE() OR a.status IN ('completed','no_show'))";
} elseif ($view === 'cancelled') {
    $where[] = "a.status = 'cancelled'";
}
// 'all' adds no extra condition

if ($status !== '' && in_array($status, appointment_statuses(), true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = "CONCAT(d.first_name,' ',d.last_name) LIKE ?";
    $params[] = "%$search%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_scalar(
    "SELECT COUNT(*) FROM appointments a JOIN doctors d ON d.id = a.doctor_id $whereSql",
    $params
);
$pg = paginate($total);

$appointments = db_all(
    appointment_select_sql() . " $whereSql ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pg['per_page'], $pg['offset']])
);

$doctors = db_all(
    "SELECT id, first_name, last_name FROM doctors WHERE status = 'active' ORDER BY first_name, last_name"
);

$page_title = 'My Appointments';
$active     = 'appointments';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / Appointments</div>
        <h1>My Appointments</h1>
        <div class="sub">Note: payment is simulated and is marked as paid by the clinic/admin.</div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-3">
        <div class="table-card mb-3">
            <div class="table-card-head">
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-sm <?= $view === 'upcoming' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/appointments.php?view=upcoming')) ?>">Upcoming</a>
                    <a class="btn btn-sm <?= $view === 'past' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/appointments.php?view=past')) ?>">Past</a>
                    <a class="btn btn-sm <?= $view === 'cancelled' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/appointments.php?view=cancelled')) ?>">Cancelled</a>
                    <a class="btn btn-sm <?= $view === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('patient/appointments.php?view=all')) ?>">All</a>
                </div>
            </div>
            <form class="filter-bar" method="get">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <div class="grow">
                    <label class="form-label">Search Doctor</label>
                    <input type="text" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Doctor name">
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All</option>
                        <?= options_from(array_combine(appointment_statuses(), array_map('nice_label', appointment_statuses())), $status) ?>
                    </select>
                </div>
                <div>
                    <button class="btn btn-primary"><?= icon('search') ?> Search</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('patient/appointments.php?view=' . $view)) ?>">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr><th>Doctor</th><th>Clinic</th><th>Date / Time</th><th>Status</th><th>Fee</th><th>Payment</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$appointments): ?>
                        <tr><td colspan="7"><div class="empty"><?= icon('calendar') ?><div>No appointments found.</div></div></td></tr>
                    <?php else: foreach ($appointments as $a): ?>
                        <tr>
                            <td><?= e($a['doctor_name']) ?><div class="small text-muted"><?= e($a['specialization_name'] ?? '') ?></div></td>
                            <td><?= e($a['clinic_name'] ?? '-') ?></td>
                            <td><?= e(format_date($a['appointment_date'])) ?><div class="small text-muted"><?= e(format_time($a['start_time'])) ?></div></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td><?= e(money($a['consultation_fee'])) ?></td>
                            <td><?= status_badge($a['payment_status']) ?></td>
                            <td class="text-end">
                                <?php if (in_array($a['status'], ['pending', 'confirmed'], true)): ?>
                                <form method="post" data-confirm="Cancel this appointment?" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= icon('close') ?> Cancel</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-3 pb-3"><?php render_pagination($pg, ['view' => $view, 'q' => $search, 'status' => $status]); ?></div>
        </div>
    </div>

    <div class="col-lg-4 mb-3">
        <div class="table-card p-3" id="bookingPanel">
            <h6 class="mb-3"><?= icon('plus') ?> Book a New Appointment</h6>
            <form method="post" id="bookForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book">
                <input type="hidden" name="start_time" id="start_time" value="">

                <div class="mb-3">
                    <label class="form-label" for="doctor_id">Doctor *</label>
                    <select class="form-select" id="doctor_id" name="doctor_id" required>
                        <option value="">-- Select a doctor --</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= $preselectDoctor === (int) $d['id'] ? 'selected' : '' ?>>
                                Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="appointment_date">Date *</label>
                    <input type="date" class="form-control" id="appointment_date" name="appointment_date" min="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <button type="button" class="btn btn-outline-primary mb-3 w-100" id="loadSlotsBtn"><?= icon('search') ?> Load Available Times</button>

                <div id="slotMessage" class="small text-muted mb-2"></div>
                <div class="slot-grid mb-3" id="slotGrid"></div>

                <div class="mb-3">
                    <label class="form-label" for="reason">Reason for Visit</label>
                    <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="200"
                              placeholder="Briefly describe the reason for your visit"></textarea>
                </div>

                <button type="submit" class="btn btn-primary w-100" id="bookBtn" disabled><?= icon('check') ?> Book This Slot</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var doctorSelect = document.getElementById('doctor_id');
    var dateInput  = document.getElementById('appointment_date');
    var loadBtn    = document.getElementById('loadSlotsBtn');
    var slotGrid   = document.getElementById('slotGrid');
    var slotMsg    = document.getElementById('slotMessage');
    var startInput = document.getElementById('start_time');
    var bookBtn    = document.getElementById('bookBtn');

    function loadSlots() {
        var doctorId = doctorSelect.value;
        var date     = dateInput.value;
        slotGrid.innerHTML = '';
        slotMsg.textContent = '';
        startInput.value = '';
        bookBtn.disabled = true;

        if (!doctorId || !date) {
            slotMsg.textContent = 'Please choose a doctor and a date first.';
            return;
        }

        window.MediCare.get('api/appointment_slots.php?doctor_id=' + doctorId + '&date=' + date)
            .then(function (res) {
                if (!res || !res.success) {
                    slotMsg.textContent = (res && res.message) || 'Unable to load available times.';
                    return;
                }
                if (!res.slots || !res.slots.length) {
                    slotMsg.textContent = res.message || 'No slots are available on this date.';
                    return;
                }
                res.slots.forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'slot';
                    btn.textContent = slot.label;
                    btn.dataset.start = slot.start;
                    btn.addEventListener('click', function () {
                        slotGrid.querySelectorAll('.slot').forEach(function (s) { s.classList.remove('selected'); });
                        btn.classList.add('selected');
                        startInput.value = slot.start;
                        bookBtn.disabled = false;
                    });
                    slotGrid.appendChild(btn);
                });
            })
            .catch(function () { slotMsg.textContent = 'Connection problem while loading slots.'; });
    }

    loadBtn.addEventListener('click', loadSlots);

    <?php if ($bookFlag === 1): ?>
    document.getElementById('bookingPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
