<?php
/**
 * MediCare - admin/doctor_view.php
 * Full profile of a single doctor: availability, time off, appointment
 * history and the patients they have treated.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('admin');

$doctorId = get_int('id');

// ---------- 1. POST handling (update consultation fee) ----------
if (is_post()) {
    verify_csrf('admin/doctor_view.php?id=' . $doctorId);
    $action = post('action');

    if ($action === 'update_fee') {
        $fee = post('consultation_fee');
        if (!is_numeric($fee) || (float) $fee < 0) {
            flash_error('Consultation fee must be zero or greater.');
        } elseif (!db_exists('SELECT 1 FROM doctors WHERE id = ?', [$doctorId])) {
            flash_error('Doctor not found.');
        } else {
            db_execute('UPDATE doctors SET consultation_fee = ? WHERE id = ?', [(float) $fee, $doctorId]);
            flash_success('Consultation fee updated successfully.');
        }
    }

    redirect('admin/doctor_view.php?id=' . $doctorId);
}

$doctor = db_row(
    "SELECT d.*, u.email, u.status AS user_status, u.last_login, s.specialization_name, c.clinic_name
       FROM doctors d
       JOIN users u ON u.id = d.user_id
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c ON c.id = d.clinic_id
      WHERE d.id = ?",
    [$doctorId]
);

if (!$doctorId || !$doctor) {
    flash_error('Doctor not found.');
    redirect('admin/doctors.php');
}

$availability = db_all(
    "SELECT * FROM doctor_availability WHERE doctor_id = ? AND status = 'active' ORDER BY day_of_week, start_time",
    [$doctorId]
);

$timeOff = db_all(
    "SELECT * FROM doctor_time_off
      WHERE doctor_id = ? AND status = 'approved' AND end_date >= CURDATE()
      ORDER BY start_date",
    [$doctorId]
);

$appointments = db_all(
    appointment_select_sql() . ' WHERE a.doctor_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 10',
    [$doctorId]
);

$patients = db_all(
    "SELECT DISTINCT p.id, p.first_name, p.last_name, p.phone
       FROM patients p
      WHERE p.id IN (
                SELECT patient_id FROM appointments WHERE doctor_id = ?
                UNION
                SELECT patient_id FROM medicines WHERE prescribed_by = ?
            )
      ORDER BY p.first_name, p.last_name",
    [$doctorId, $doctorId]
);

$initials = strtoupper(substr($doctor['first_name'], 0, 1) . substr($doctor['last_name'], 0, 1));

$page_title = doctor_display_name($doctor);
$active     = 'doctors';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> /
            <a href="<?= e(url('admin/doctors.php')) ?>">Doctors</a> / <?= e(doctor_display_name($doctor)) ?></div>
        <h1><?= e(doctor_display_name($doctor)) ?></h1>
        <div class="sub">Full profile, schedule and patient history.</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/doctors.php')) ?>"><?= icon('doctor') ?> Back to Doctors</a>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <div class="profile-head">
        <div class="profile-avatar"><?= e($initials) ?></div>
        <div>
            <h4 class="mb-1"><?= e(doctor_display_name($doctor)) ?> <?= status_badge($doctor['status']) ?></h4>
            <div class="text-muted"><?= e($doctor['specialization_name'] ?? 'Unassigned specialization') ?>
                &middot; <?= e($doctor['clinic_name'] ?? 'No clinic assigned') ?></div>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-md-6">
            <div class="kv"><span>E-mail</span><span><?= e($doctor['email']) ?></span></div>
            <div class="kv"><span>Phone</span><span><?= e($doctor['phone']) ?></span></div>
            <div class="kv"><span>Gender</span><span><?= e($doctor['gender']) ?></span></div>
            <div class="kv"><span>Date of Birth</span><span><?= e(format_date($doctor['date_of_birth'])) ?></span></div>
        </div>
        <div class="col-md-6">
            <div class="kv"><span>License Number</span><span><?= e($doctor['license_number']) ?></span></div>
            <div class="kv"><span>Qualification</span><span><?= e($doctor['qualification'] ?: '-') ?></span></div>
            <div class="kv"><span>Experience</span><span><?= (int) $doctor['experience_years'] ?> years</span></div>
            <div class="kv"><span>Consultation Fee</span><span><?= e(money($doctor['consultation_fee'])) ?></span></div>
        </div>
    </div>
    <?php if ($doctor['address']): ?><div class="kv"><span>Address</span><span><?= e($doctor['address']) ?></span></div><?php endif; ?>
    <?php if ($doctor['bio']): ?>
        <div class="mt-2"><strong>Bio</strong><p class="mb-0 text-muted"><?= e($doctor['bio']) ?></p></div>
    <?php endif; ?>
</div>

<!-- Update consultation fee -->
<div class="table-card p-3 mb-3">
    <h6 class="mb-3">Update Consultation Fee</h6>
    <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_fee">
        <div>
            <label class="form-label">Consultation Fee</label>
            <input type="number" step="0.01" min="0" class="form-control" name="consultation_fee"
                   value="<?= e($doctor['consultation_fee']) ?>" required>
        </div>
        <button class="btn btn-primary"><?= icon('money') ?> Update Fee</button>
    </form>
</div>

<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Weekly Availability</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Slot Duration</th></tr></thead>
                    <tbody>
                    <?php if (!$availability): ?>
                        <tr><td colspan="4"><div class="empty"><?= icon('clock') ?><div>No availability configured.</div></div></td></tr>
                    <?php else: foreach ($availability as $a): ?>
                        <tr>
                            <td><?= e(day_name($a['day_of_week'])) ?></td>
                            <td><?= e(format_time($a['start_time'])) ?></td>
                            <td><?= e(format_time($a['end_time'])) ?></td>
                            <td><?= (int) $a['slot_duration'] ?> min</td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong>Upcoming Time Off</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>From</th><th>To</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php if (!$timeOff): ?>
                        <tr><td colspan="3"><div class="empty"><?= icon('calendar-check') ?><div>No upcoming time off.</div></div></td></tr>
                    <?php else: foreach ($timeOff as $t): ?>
                        <tr>
                            <td><?= e(format_date($t['start_date'])) ?></td>
                            <td><?= e(format_date($t['end_date'])) ?></td>
                            <td><?= e($t['reason']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Recent Appointments</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Time</th><th>Patient</th><th>Status</th><th>Fee</th><th>Payment</th></tr></thead>
            <tbody>
            <?php if (!$appointments): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('calendar') ?><div>No appointments yet.</div></div></td></tr>
            <?php else: foreach ($appointments as $a): ?>
                <tr>
                    <td><?= e(format_date($a['appointment_date'])) ?></td>
                    <td><?= e(format_time($a['start_time'])) ?></td>
                    <td><?= e($a['patient_name']) ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td><?= e(money($a['consultation_fee'])) ?></td>
                    <td><?= status_badge($a['payment_status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong>Patients Treated</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Phone</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$patients): ?>
                <tr><td colspan="3"><div class="empty"><?= icon('patient') ?><div>No patients treated yet.</div></div></td></tr>
            <?php else: foreach ($patients as $p): ?>
                <tr>
                    <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/patient_view.php?id=' . (int) $p['id'])) ?>"><?= icon('eye') ?> View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
