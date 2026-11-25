<?php
/**
 * MediCare - patient/doctor_view.php
 * Full profile of one doctor: bio, availability, time off, consultation
 * fee, a slot picker (via api/appointment_slots.php) and booking form.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('patient');

$patientId = current_patient_id();
$doctorId  = get_int('id');

$doctor = db_row(
    "SELECT d.*, s.specialization_name, c.clinic_name, c.address AS clinic_address,
            c.phone AS clinic_phone, c.opening_time, c.closing_time, c.city
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c ON c.id = d.clinic_id
      WHERE d.id = ?",
    [$doctorId]
);
if (!$doctor) {
    flash_error('The selected doctor was not found.');
    redirect('patient/doctors.php');
}

// ---------- 1. POST handling (book this doctor) ----------
if (is_post()) {
    verify_csrf('patient/doctor_view.php?id=' . $doctorId);
    $action = post('action');

    if ($action === 'book') {
        $date   = post('appointment_date');
        $start  = post('start_time');
        $reason = post('reason');

        $result = book_appointment($patientId, $doctorId, $date, $start, $reason);
        if ($result['ok']) { flash_success($result['message']); } else { flash_error($result['message']); }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/doctor_view.php?id=' . $doctorId);
}

// ---------- 2. Data ----------
$availability = db_all(
    "SELECT * FROM doctor_availability WHERE doctor_id = ? AND status = 'active'
      ORDER BY day_of_week, start_time",
    [$doctorId]
);

$timeOff = db_all(
    "SELECT * FROM doctor_time_off
      WHERE doctor_id = ? AND status = 'approved' AND end_date >= CURDATE()
      ORDER BY start_date",
    [$doctorId]
);

$pastAppointments = db_all(
    appointment_select_sql() .
    ' WHERE a.patient_id = ? AND a.doctor_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 10',
    [$patientId, $doctorId]
);

$page_title = doctor_display_name($doctor);
$active     = 'doctors';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> /
            <a href="<?= e(url('patient/doctors.php')) ?>">Find a Doctor</a> / <?= e(doctor_display_name($doctor)) ?></div>
        <h1><?= e(doctor_display_name($doctor)) ?></h1>
        <div class="sub"><?= e($doctor['specialization_name'] ?? 'General Practice') ?></div>
    </div>
</div>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="table-card p-3 mb-3">
            <div class="doc-top">
                <div class="doc-av" style="width:64px;height:64px;font-size:1.4rem"><?= e(strtoupper(substr($doctor['first_name'], 0, 1))) ?></div>
                <div>
                    <div class="fw-bold fs-5"><?= e(doctor_display_name($doctor)) ?></div>
                    <div class="text-muted small"><?= e($doctor['qualification'] ?? '-') ?></div>
                    <div class="doc-fee mt-1"><?= e(money($doctor['consultation_fee'])) ?> <span class="small text-muted fw-normal">per consultation</span></div>
                </div>
            </div>
            <?php if ($doctor['bio']): ?><p class="mt-2 mb-2"><?= e($doctor['bio']) ?></p><?php endif; ?>
            <div class="kv"><span class="k">License Number</span><span class="v"><?= e($doctor['license_number']) ?></span></div>
            <div class="kv"><span class="k">Experience</span><span class="v"><?= (int) $doctor['experience_years'] ?> years</span></div>
            <div class="kv"><span class="k">Clinic</span><span class="v"><?= e($doctor['clinic_name'] ?? '-') ?></span></div>
            <?php if ($doctor['clinic_name']): ?>
            <div class="kv"><span class="k">Clinic Address</span><span class="v"><?= e($doctor['clinic_address'] . ', ' . $doctor['city']) ?></span></div>
            <div class="kv"><span class="k">Clinic Phone</span><span class="v"><?= e($doctor['clinic_phone'] ?? '-') ?></span></div>
            <div class="kv"><span class="k">Opening Hours</span><span class="v"><?= e(format_time($doctor['opening_time']) . ' - ' . format_time($doctor['closing_time'])) ?></span></div>
            <?php endif; ?>
        </div>

        <div class="table-card p-3 mb-3">
            <h6 class="mb-3"><?= icon('clock') ?> Weekly Availability</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Day</th><th>Working Hours</th><th>Slot Length</th></tr></thead>
                    <tbody>
                    <?php if (!$availability): ?>
                        <tr><td colspan="3"><div class="empty"><?= icon('clock') ?><div>No weekly availability has been set up yet.</div></div></td></tr>
                    <?php else: foreach ($availability as $a): ?>
                        <tr>
                            <td><?= e(day_name($a['day_of_week'])) ?></td>
                            <td><?= e(format_time($a['start_time']) . ' - ' . format_time($a['end_time'])) ?></td>
                            <td><?= (int) $a['slot_duration'] ?> min</td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($timeOff): ?>
        <div class="table-card p-3 mb-3">
            <h6 class="mb-3"><?= icon('calendar-check') ?> Upcoming Time Off</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Dates</th><th>Reason</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($timeOff as $t): ?>
                        <tr>
                            <td><?= e(format_date($t['start_date'])) ?> - <?= e(format_date($t['end_date'])) ?></td>
                            <td><?= e($t['reason']) ?></td>
                            <td><?= e($t['notes'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="table-card p-3">
            <h6 class="mb-3"><?= icon('history') ?> Your Past Appointments With This Doctor</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>Status</th><th>Fee</th></tr></thead>
                    <tbody>
                    <?php if (!$pastAppointments): ?>
                        <tr><td colspan="3"><div class="empty"><?= icon('history') ?><div>No previous appointments with this doctor.</div></div></td></tr>
                    <?php else: foreach ($pastAppointments as $a): ?>
                        <tr>
                            <td><?= e(format_date($a['appointment_date'])) ?> <?= e(format_time($a['start_time'])) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td><?= e(money($a['consultation_fee'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <div class="table-card p-3">
            <h6 class="mb-3"><?= icon('calendar') ?> Book an Appointment</h6>
            <form method="post" id="bookForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book">
                <input type="hidden" name="start_time" id="start_time" value="">

                <div class="mb-3">
                    <label class="form-label" for="appointment_date">Date *</label>
                    <input type="date" class="form-control" id="appointment_date" name="appointment_date"
                           min="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <button type="button" class="btn btn-outline-primary mb-3" id="loadSlotsBtn"><?= icon('search') ?> Load Available Times</button>

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
    var doctorId   = <?= (int) $doctorId ?>;
    var dateInput  = document.getElementById('appointment_date');
    var loadBtn    = document.getElementById('loadSlotsBtn');
    var slotGrid   = document.getElementById('slotGrid');
    var slotMsg    = document.getElementById('slotMessage');
    var startInput = document.getElementById('start_time');
    var bookBtn    = document.getElementById('bookBtn');

    function loadSlots() {
        var date = dateInput.value;
        slotGrid.innerHTML = '';
        slotMsg.textContent = '';
        startInput.value = '';
        bookBtn.disabled = true;

        if (!date) {
            slotMsg.textContent = 'Please choose a date first.';
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
