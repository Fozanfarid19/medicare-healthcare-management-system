<?php
/**
 * MediCare - doctor/availability.php
 * Weekly working-hours blocks for the logged-in doctor, plus a slot
 * preview that proves the booking engine (doctor_available_slots) works.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role('doctor');

$doctorId = current_doctor_id();
$slotDurations = [10, 15, 20, 30, 45, 60];

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('doctor/availability.php');
    $action = post('action');
    $id     = post_int('id');

    if ($action === 'create' || $action === 'update') {
        $dayOfWeek = post_int('day_of_week');
        $startTime = post('start_time');
        $endTime   = post('end_time');
        $duration  = post_int('slot_duration');

        $errors = [];
        if ($dayOfWeek < 1 || $dayOfWeek > 7)                 { $errors[] = 'Please choose a valid day of the week.'; }
        if (!is_valid_time($startTime) || !is_valid_time($endTime)) { $errors[] = 'Please enter valid start and end times.'; }
        if (!in_array($duration, $slotDurations, true))       { $errors[] = 'Please choose a valid slot duration.'; }

        if (!$errors && $startTime >= $endTime) {
            $errors[] = 'The end time must be later than the start time.';
        }
        if (!$errors) {
            $minutes = (strtotime($endTime) - strtotime($startTime)) / 60;
            if ($minutes < $duration) {
                $errors[] = 'The slot duration does not fit inside the chosen time block.';
            }
        }

        // Ownership check for edits.
        if ($action === 'update' && !$errors) {
            $owns = db_exists('SELECT 1 FROM doctor_availability WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
            if (!$owns) { $errors[] = 'That availability block does not belong to you.'; }
        }

        // Any existing block (active or inactive) with the exact same day + start time
        // would violate the UNIQUE(doctor_id, day_of_week, start_time) constraint.
        if (!$errors) {
            $dupSql = 'SELECT 1 FROM doctor_availability WHERE doctor_id = ? AND day_of_week = ? AND start_time = ?';
            $dupParams = [$doctorId, $dayOfWeek, $startTime . ':00'];
            if ($action === 'update') { $dupSql .= ' AND id != ?'; $dupParams[] = $id; }
            if (db_exists($dupSql, $dupParams)) {
                $errors[] = 'You already have an availability block starting at that time on that day.';
            }
        }

        // Overlap check against existing ACTIVE blocks on the same day.
        if (!$errors) {
            $overlapSql = "SELECT 1 FROM doctor_availability
                             WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
                               AND start_time < ? AND end_time > ?";
            $overlapParams = [$doctorId, $dayOfWeek, $endTime . ':00', $startTime . ':00'];
            if ($action === 'update') { $overlapSql .= ' AND id != ?'; $overlapParams[] = $id; }
            if (db_exists($overlapSql, $overlapParams)) {
                $errors[] = 'This time block overlaps with an existing availability block on the same day.';
            }
        }

        if ($errors) {
            flash_error(implode(' ', $errors));
        } elseif ($action === 'create') {
            db_insert(
                'INSERT INTO doctor_availability (doctor_id, day_of_week, start_time, end_time, slot_duration, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$doctorId, $dayOfWeek, $startTime . ':00', $endTime . ':00', $duration, 'active']
            );
            flash_success('Availability block added.');
        } else {
            db_execute(
                'UPDATE doctor_availability SET day_of_week = ?, start_time = ?, end_time = ?, slot_duration = ?
                  WHERE id = ? AND doctor_id = ?',
                [$dayOfWeek, $startTime . ':00', $endTime . ':00', $duration, $id, $doctorId]
            );
            flash_success('Availability block updated.');
        }
    } elseif ($action === 'toggle_status') {
        $owns = db_exists('SELECT 1 FROM doctor_availability WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That availability block does not belong to you.');
        } else {
            db_execute(
                "UPDATE doctor_availability SET status = IF(status = 'active','inactive','active') WHERE id = ? AND doctor_id = ?",
                [$id, $doctorId]
            );
            flash_success('Availability status updated.');
        }
    } elseif ($action === 'delete') {
        $owns = db_exists('SELECT 1 FROM doctor_availability WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
        if (!$owns) {
            flash_error('That availability block does not belong to you.');
        } else {
            db_execute('DELETE FROM doctor_availability WHERE id = ? AND doctor_id = ?', [$id, $doctorId]);
            flash_success('Availability block deleted.');
        }
    }

    redirect('doctor/availability.php');
}

// ---------- 2. Load data ----------
$blocks = db_all('SELECT * FROM doctor_availability WHERE doctor_id = ? ORDER BY day_of_week, start_time', [$doctorId]);
$byDay  = [];
foreach ($blocks as $b) { $byDay[(int) $b['day_of_week']][] = $b; }

// ---------- 3. Slot preview ----------
$previewDate = get_param('preview');
$previewResult = null;
if ($previewDate !== '') {
    $previewResult = doctor_available_slots($doctorId, $previewDate);
}

$page_title = 'Availability';
$active     = 'availability';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('doctor/dashboard.php')) ?>">Dashboard</a> / Availability</div>
        <h1>Weekly Availability</h1>
        <div class="sub">Define the time blocks in which patients can book appointments with you.</div>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAvailModal"><?= icon('plus') ?> Add Block</button>
    </div>
</div>

<div class="row">
    <?php foreach (week_days() as $dayNum => $dayLabel): ?>
    <div class="col-lg-6 mb-3">
        <div class="table-card">
            <div class="table-card-head"><strong><?= e($dayLabel) ?></strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Start</th><th>End</th><th>Slot</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (empty($byDay[$dayNum])): ?>
                        <tr><td colspan="5"><div class="empty"><?= icon('clock') ?><div>No blocks on this day.</div></div></td></tr>
                    <?php else: foreach ($byDay[$dayNum] as $b): ?>
                        <tr>
                            <td><?= e(format_time($b['start_time'])) ?></td>
                            <td><?= e(format_time($b['end_time'])) ?></td>
                            <td><?= (int) $b['slot_duration'] ?> min</td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAvailModal"
                                    data-id="<?= (int) $b['id'] ?>"
                                    data-day-of-week="<?= (int) $b['day_of_week'] ?>"
                                    data-start-time="<?= e(substr($b['start_time'], 0, 5)) ?>"
                                    data-end-time="<?= e(substr($b['end_time'], 0, 5)) ?>"
                                    data-slot-duration="<?= (int) $b['slot_duration'] ?>">
                                    <?= icon('edit') ?>
                                </button>
                                <form method="post" action="<?= e(url('doctor/availability.php')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary"><?= icon($b['status'] === 'active' ? 'close' : 'check') ?></button>
                                </form>
                                <form method="post" action="<?= e(url('doctor/availability.php')) ?>" class="d-inline" data-confirm="Delete this availability block?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-card p-3 mt-1">
    <h6 class="mb-3"><?= icon('eye') ?> Slot Preview</h6>
    <form class="filter-bar" method="get">
        <div>
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="preview" value="<?= e($previewDate) ?>" min="<?= e(date('Y-m-d')) ?>">
        </div>
        <div><button class="btn btn-primary"><?= icon('search') ?> Preview Slots</button></div>
    </form>

    <?php if ($previewResult !== null): ?>
        <?php if ($previewResult['blocked'] !== ''): ?>
            <div class="alert alert-warning mt-3 mb-0"><?= icon('alert-circle') ?> <?= e($previewResult['blocked']) ?></div>
        <?php else: ?>
            <div class="slot-grid mt-3">
                <?php foreach ($previewResult['available'] as $slot): ?>
                    <div class="slot"><?= e($slot['label']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Add block modal -->
<div class="modal" id="addAvailModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post" action="<?= e(url('doctor/availability.php')) ?>">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add Availability Block</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
       <div class="mb-3">
           <label class="form-label">Day of Week</label>
           <select class="form-select" name="day_of_week" required>
               <?php foreach (week_days() as $dayNum => $dayLabel): ?>
                   <option value="<?= (int) $dayNum ?>"><?= e($dayLabel) ?></option>
               <?php endforeach; ?>
           </select>
       </div>
       <div class="row g-3">
           <div class="col-md-6">
               <label class="form-label">Start Time</label>
               <input type="time" class="form-control" name="start_time" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">End Time</label>
               <input type="time" class="form-control" name="end_time" required>
           </div>
       </div>
       <div class="mb-3 mt-3">
           <label class="form-label">Slot Duration</label>
           <select class="form-select" name="slot_duration" required>
               <?php foreach ($slotDurations as $d): ?>
                   <option value="<?= (int) $d ?>"<?= $d === 30 ? ' selected' : '' ?>><?= (int) $d ?> minutes</option>
               <?php endforeach; ?>
           </select>
       </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit block modal -->
<div class="modal" id="editAvailModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog"><div class="modal-content">
  <form method="post" action="<?= e(url('doctor/availability.php')) ?>">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="id" data-fill="id">
   <div class="modal-header"><h5 class="modal-title">Edit Availability Block</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
       <div class="mb-3">
           <label class="form-label">Day of Week</label>
           <select class="form-select" name="day_of_week" data-fill="dayOfWeek" required>
               <?php foreach (week_days() as $dayNum => $dayLabel): ?>
                   <option value="<?= (int) $dayNum ?>"><?= e($dayLabel) ?></option>
               <?php endforeach; ?>
           </select>
       </div>
       <div class="row g-3">
           <div class="col-md-6">
               <label class="form-label">Start Time</label>
               <input type="time" class="form-control" name="start_time" data-fill="startTime" required>
           </div>
           <div class="col-md-6">
               <label class="form-label">End Time</label>
               <input type="time" class="form-control" name="end_time" data-fill="endTime" required>
           </div>
       </div>
       <div class="mb-3 mt-3">
           <label class="form-label">Slot Duration</label>
           <select class="form-select" name="slot_duration" data-fill="slotDuration" required>
               <?php foreach ($slotDurations as $d): ?>
                   <option value="<?= (int) $d ?>"><?= (int) $d ?> minutes</option>
               <?php endforeach; ?>
           </select>
       </div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
