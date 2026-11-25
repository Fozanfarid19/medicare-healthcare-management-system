<?php
/**
 * MediCare - doctor/time_off.php
 * Approved leave for the logged-in doctor. Patients cannot book appointments
 * during an approved time-off period (enforced by includes/appointments.php).
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$doctorId = current_doctor_id();

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/time_off.php');
    $action = post('action');
    $id     = post_int('id');

    if ($action === 'create') {
        $startDate = post('start_date');
        $endDate   = post('end_date');
        $reason    = post('reason');
        $notes     = post('notes');

        $errors = [];
        if (!is_valid_date($startDate) || !is_valid_date($endDate)) {
            $errors[] = 'Please enter valid start and end dates.';
        } else {
            if ($endDate < $startDate)          { $errors[] = 'The end date must be on or after the start date.'; }
            if ($startDate < date('Y-m-d'))     { $errors[] = 'The start date cannot be in the past.'; }
        }
        if (!in_array($reason, time_off_reasons(), true)) { $errors[] = 'Please select a valid reason.'; }

        // Block if the doctor already has booked appointments inside the range.
        if (!$errors) {
            $clashing = db_all(
                "SELECT a.appointment_date, a.start_time, CONCAT(p.first_name,' ',p.last_name) AS patient_name
                   FROM appointments a
                   JOIN patients p ON p.id = a.patient_id
                  WHERE a.doctor_id = ? AND a.status IN ('pending','confirmed')
                    AND a.appointment_date BETWEEN ? AND ?
                  ORDER BY a.appointment_date, a.start_time",
                [$doctorId, $startDate, $endDate]
            );
            if ($clashing) {
                $list = [];
                foreach ($clashing as $c) {
                    $list[] = format_date($c['appointment_date']) . ' ' . format_time($c['start_time']) . ' (' . e($c['patient_name']) . ')';
                }
                $errors[] = 'You already have booked appointments in this period: ' . implode('; ', $list)
                          . '. Please cancel or reschedule them first.';
            }
        }

        if ($errors) {
            flash_error(implode(' ', $errors));
        } else {
            db_insert(
                "INSERT INTO doctor_time_off (doctor_id, start_date, end_date, reason, notes, status)
                 VALUES (?, ?, ?, ?, ?, 'approved')",
                [$doctorId, $startDate, $endDate, $reason, null_if_blank($notes)]
            );
            flash_success('Time off has been scheduled.');
        }
    } elseif ($action === 'cancel') {
        $owns = db_exists('SELECT 1 FROM doctor_time_off WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That time off record does not belong to you.');
        } else {
            db_execute("UPDATE doctor_time_off SET status = 'cancelled' WHERE id = ? AND doctor_id = ?", [$id, $doctorId]);
            flash_success('Time off cancelled.');
        }
    } elseif ($action === 'delete') {
        $owns = db_exists('SELECT 1 FROM doctor_time_off WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That time off record does not belong to you.');
        } else {
            db_execute('DELETE FROM doctor_time_off WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
            flash_success('Time off record deleted.');
        }
    }

    redirect('doctor/time_off.php');
}

// ---------- 2. Load data (upcoming first) ----------
$rows = db_all(
    "SELECT *, DATEDIFF(end_date, start_date) + 1 AS days
       FROM doctor_time_off
      WHERE doctor_id = ?
      ORDER BY (end_date < CURDATE()) ASC, start_date ASC",
    [$doctorId]
);

$page_title = 'Time Off';
$active     = 'time_off';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Time Off</div>
        <h1>Time Off</h1>
        <div class="sub">Schedule leave so patients cannot book appointments during that period.</div>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTimeOffModal"><?= icon('plus') ?> Request Time Off</button>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info') ?> Patients cannot book appointments with you on days covered by an <strong>approved</strong> time off
    record. Cancelling a time off record immediately re-opens those days for booking.
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Reason</th><th>Start</th><th>End</th><th># Days</th><th>Notes</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('calendar-check') ?><div>No time off scheduled.</div></div></td></tr>
            <?php else: foreach ($rows as $t): ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= e($t['reason']) ?></span></td>
                    <td><?= e(format_date($t['start_date'])) ?></td>
                    <td><?= e(format_date($t['end_date'])) ?></td>
                    <td><?= (int) $t['days'] ?></td>
                    <td><?= e(str_limit($t['notes'] ?? '-', 40)) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="text-end">
                        <?php if ($t['status'] === 'approved'): ?>
                            <form method="post" action="<?= e(url('doctor/time_off.php')) ?>" class="d-inline" data-confirm="Cancel this time off?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><?= icon('close') ?> Cancel</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('doctor/time_off.php')) ?>" class="d-inline" data-confirm="Delete this time off record?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add time off modal -->
<div class="modal" id="addTimeOffModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post" action="<?= e(url('doctor/time_off.php')) ?>">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Request Time Off</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
       <div class="row g-3">
           <div class="col-md-6">
               <label class="form-label">Start Date</label>
               <input type="date" class="form-control" name="start_date" min="<?= e(date('Y-m-d')) ?>" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">End Date</label>
               <input type="date" class="form-control" name="end_date" min="<?= e(date('Y-m-d')) ?>" required>
           </div>
       </div>
       <div class="mb-3 mt-3">
           <label class="form-label">Reason</label>
           <select class="form-select" name="reason" required>
               <?= options_from(time_off_reasons()) ?>
           </select>
       </div>
       <div class="mb-3">
           <label class="form-label">Notes</label>
           <textarea class="form-control" name="notes" rows="2"></textarea>
       </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
