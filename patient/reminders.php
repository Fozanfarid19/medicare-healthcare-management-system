<?php
/**
 * MediCare - patient/reminders.php
 * Explains and demonstrates the reminder engine: doses due right now,
 * today's full schedule, and management of every active reminder time.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role('patient');

$patientId = current_patient_id();
mark_missed_doses($patientId);

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('patient/reminders.php');
    $action = post('action');

    if (in_array($action, ['taken', 'skipped', 'snoozed'], true)) {
        $reminderId  = post_int('reminder_id');
        $scheduledAt = post('scheduled_at');
        $result      = log_dose_action($reminderId, $patientId, $scheduledAt, $action);
        if ($result['ok']) { flash_success($result['message']); } else { flash_error($result['message']); }
    } elseif ($action === 'update_times') {
        $medId = post_int('medicine_id');
        $owns  = db_exists('SELECT 1 FROM medicines WHERE id = ? AND patient_id = ?', [$medId, $patientId]);
        if (!$owns) {
            flash_error('This medicine does not belong to your account.');
        } else {
            save_reminder_times($medId, explode(',', post('reminder_times')));
            flash_success('Reminder times updated.');
        }
    } elseif ($action === 'toggle_reminder') {
        $reminderId = post_int('reminder_id');
        $reminder   = db_row(
            'SELECT r.id, r.status FROM medicine_reminders r
               JOIN medicines m ON m.id = r.medicine_id
              WHERE r.id = ? AND m.patient_id = ?',
            [$reminderId, $patientId]
        );
        if (!$reminder) {
            flash_error('This reminder does not belong to your account.');
        } else {
            $newStatus = $reminder['status'] === 'active' ? 'inactive' : 'active';
            db_execute('UPDATE medicine_reminders SET status = ? WHERE id = ?', [$newStatus, $reminderId]);
            flash_success('Reminder ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . '.');
        }
    } else {
        flash_error('Unknown action requested.');
    }

    redirect('patient/reminders.php');
}

// ---------- 2. Data ----------
$dueNow   = due_reminders($patientId);
$schedule = patient_schedule($patientId, date('Y-m-d'));

$medicines = db_all(
    "SELECT * FROM medicines WHERE patient_id = ? AND status = 'active' ORDER BY medicine_name",
    [$patientId]
);
$remindersByMedicine = [];
foreach ($medicines as $m) {
    $remindersByMedicine[$m['id']] = db_all(
        'SELECT * FROM medicine_reminders WHERE medicine_id = ? ORDER BY reminder_time',
        [$m['id']]
    );
}

$page_title = 'Reminders';
$active     = 'reminders';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="breadcrumbs"><a href="<?= e(url('patient/dashboard.php')) ?>">Dashboard</a> / Reminders</div>
        <h1>Medicine Reminders</h1>
        <div class="sub">Manage every reminder time and see today's status live.</div>
    </div>
</div>

<div class="table-card p-3 mb-3">
    <h6 class="mb-2"><?= icon('info') ?> How the reminder engine works</h6>
    <p class="mb-0 small text-muted">
        Every 30 seconds your browser calls <code>api/reminder_check.php</code>, which reads
        <code>medicines</code>, <code>medicine_reminders</code> and <code>medicine_logs</code> in MySQL to
        find doses that are due right now. If one is found, a popup appears with
        <strong>Taken</strong>, <strong>Snooze</strong> and <strong>Skip</strong> buttons; your choice is
        written back into <code>medicine_logs</code> and the page updates without a reload. The same
        actions are also available below as plain forms, so everything keeps working even with
        JavaScript disabled.
    </p>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong><?= icon('bell') ?> Due Now</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Medicine</th><th>Dose</th><th>Route</th><th>Time</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php if (!$dueNow): ?>
                <tr><td colspan="5"><div class="empty"><?= icon('check-circle') ?><div>No doses are due right now.</div></div></td></tr>
            <?php else: foreach ($dueNow as $row): ?>
                <tr data-reminder="<?= (int) $row['reminder_id'] ?>">
                    <td><?= e($row['medicine_name']) ?></td>
                    <td><?= e(trim($row['dosage'] . ' ' . $row['dose_unit'])) ?></td>
                    <td><?= e($row['route']) ?></td>
                    <td class="dose-time"><?= e(format_time($row['reminder_time'])) ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reminder_id" value="<?= (int) $row['reminder_id'] ?>">
                            <input type="hidden" name="scheduled_at" value="<?= e($row['scheduled_at']) ?>">
                            <button type="submit" name="action" value="taken" class="btn btn-sm btn-success">Taken</button>
                            <button type="submit" name="action" value="snoozed" class="btn btn-sm btn-warning">Snooze</button>
                            <button type="submit" name="action" value="skipped" class="btn btn-sm btn-outline-secondary">Skip</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card mb-3">
    <div class="table-card-head"><strong><?= icon('calendar') ?> Today's Schedule</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Medicine</th><th>Dose</th><th>Time</th><th>Frequency</th><th>Status</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$schedule): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('pill') ?><div>No medicine reminders scheduled for today.</div></div></td></tr>
            <?php else: foreach ($schedule as $row):
                $status = dose_status($row);
                $rowCls = 'dose-row' . ($status === 'taken' ? ' taken' : ($status === 'missed' ? ' missed' : ''));
            ?>
                <tr class="<?= e($rowCls) ?>" data-reminder="<?= (int) $row['reminder_id'] ?>">
                    <td><?= e($row['medicine_name']) ?></td>
                    <td><?= e(trim($row['dosage'] . ' ' . $row['dose_unit'])) ?></td>
                    <td class="dose-time"><?= e(format_time($row['reminder_time'])) ?></td>
                    <td><?= e($row['frequency']) ?></td>
                    <td data-status><?= status_badge($status) ?></td>
                    <td class="text-end" data-dose-actions>
                        <?php if (in_array($status, ['pending', 'snoozed'], true)): ?>
                        <form method="post" class="d-inline-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reminder_id" value="<?= (int) $row['reminder_id'] ?>">
                            <input type="hidden" name="scheduled_at" value="<?= e($row['scheduled_at']) ?>">
                            <button type="submit" name="action" value="taken" class="btn btn-sm btn-success"><?= icon('check') ?></button>
                            <button type="submit" name="action" value="snoozed" class="btn btn-sm btn-warning"><?= icon('clock') ?></button>
                            <button type="submit" name="action" value="skipped" class="btn btn-sm btn-outline-secondary"><?= icon('close') ?></button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">Recorded</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card p-3">
    <h6 class="mb-3"><?= icon('pill') ?> All Active Reminders</h6>
    <?php if (!$medicines): ?>
        <div class="empty"><?= icon('pill') ?><div>You have no active medicines.</div></div>
    <?php else: foreach ($medicines as $m): $reminders = $remindersByMedicine[$m['id']]; ?>
        <div class="table-card mb-3">
            <div class="table-card-head">
                <div>
                    <strong><?= e($m['medicine_name']) ?></strong>
                    <div class="small text-muted"><?= e(trim($m['dosage'] . ' ' . $m['dose_unit'])) ?> &middot; <?= e($m['frequency']) ?></div>
                </div>
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_times">
                    <input type="hidden" name="medicine_id" value="<?= (int) $m['id'] ?>">
                    <div>
                        <label class="form-label small mb-1">Reminder Times</label>
                        <input type="text" class="form-control form-control-sm" name="reminder_times"
                               value="<?= e(implode(', ', array_map(function ($r) { return substr($r['reminder_time'], 0, 5); }, array_filter($reminders, function ($r) { return $r['status'] === 'active'; })))) ?>"
                               placeholder="08:00, 20:00" style="min-width:220px">
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><?= icon('check') ?> Save</button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Time</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php if (!$reminders): ?>
                        <tr><td colspan="3"><div class="empty py-2"><div>No reminder times set for this medicine.</div></div></td></tr>
                    <?php else: foreach ($reminders as $r): ?>
                        <tr>
                            <td><?= e(format_time($r['reminder_time'])) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td class="text-end">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_reminder">
                                    <input type="hidden" name="reminder_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $r['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
