<?php
/**
 * =====================================================================
 *  MediCare - includes/medicine.php
 *  Domain logic for medicines, reminder occurrences and adherence logs.
 *
 *  KEY IDEA
 *  --------
 *  medicine_reminders stores a reminder TIME (one row per time).
 *  A concrete "dose occurrence" is  (reminder, date)  ->  scheduled_at.
 *  medicine_logs holds ONE row per occurrence, unique on
 *  (reminder_id, scheduled_at).  No log row  =  the dose is still Pending.
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

/**
 * Every dose scheduled for one patient on one date, with its status.
 *
 * @param  int    $patientId
 * @param  string $date  Y-m-d
 * @return array
 */
function patient_schedule($patientId, $date)
{
    return db_all(
        "SELECT r.id                              AS reminder_id,
                r.reminder_time,
                r.message,
                TIMESTAMP(?, r.reminder_time)     AS scheduled_at,
                m.id                              AS medicine_id,
                m.medicine_name,
                m.generic_name,
                m.dosage,
                m.dose_unit,
                m.quantity,
                m.frequency,
                m.route,
                m.instructions,
                l.id                              AS log_id,
                l.action,
                l.action_time,
                l.snooze_until,
                d.first_name                      AS doctor_first_name,
                d.last_name                       AS doctor_last_name
           FROM medicine_reminders r
           JOIN medicines m ON m.id = r.medicine_id
           LEFT JOIN doctors d ON d.id = m.prescribed_by
           LEFT JOIN medicine_logs l
                  ON l.reminder_id = r.id
                 AND l.scheduled_at = TIMESTAMP(?, r.reminder_time)
          WHERE m.patient_id = ?
            AND m.status     = 'active'
            AND r.status     = 'active'
            AND m.start_date <= ?
            AND (m.end_date IS NULL OR m.end_date >= ?)
            AND (r.reminder_date IS NULL OR r.reminder_date = ?)
          ORDER BY r.reminder_time, m.medicine_name",
        [$date, $date, (int) $patientId, $date, $date, $date]
    );
}

/**
 * Display status of one schedule row: taken / skipped / snoozed / missed / pending
 */
function dose_status(array $row, $now = null)
{
    $now = $now ?: date('Y-m-d H:i:s');

    if (!empty($row['action']) && $row['action'] !== 'snoozed') {
        return $row['action'];                       // taken / missed / skipped
    }
    if (!empty($row['action']) && $row['action'] === 'snoozed') {
        return 'snoozed';
    }
    $graceEnd = date('Y-m-d H:i:s', strtotime($row['scheduled_at']) + REMINDER_GRACE_MINUTES * 60);
    return ($now > $graceEnd) ? 'missed' : 'pending';
}

/**
 * Writes 'missed' logs for doses whose grace period expired.
 * Called when the patient dashboard / history page is opened, so the
 * database always reflects reality without needing a cron job.
 *
 * @return int number of rows marked as missed
 */
function mark_missed_doses($patientId, $daysBack = 2)
{
    $patientId = (int) $patientId;
    $now       = date('Y-m-d H:i:s');
    $cutoff    = date('Y-m-d H:i:s', time() - REMINDER_GRACE_MINUTES * 60);
    $marked    = 0;

    for ($i = 0; $i <= $daysBack; $i++) {
        $date = date('Y-m-d', strtotime("-$i day"));

        foreach (patient_schedule($patientId, $date) as $row) {
            if ($row['scheduled_at'] > $cutoff) {
                continue;                            // still inside the grace period
            }
            if (empty($row['action'])) {
                $marked += db_execute(
                    "INSERT INTO medicine_logs (reminder_id, patient_id, scheduled_at, action, action_time, notes)
                     VALUES (?, ?, ?, 'missed', ?, 'Automatically marked as missed')
                     ON DUPLICATE KEY UPDATE action = action",
                    [(int) $row['reminder_id'], $patientId, $row['scheduled_at'], $now]
                );
            } elseif ($row['action'] === 'snoozed'
                   && !empty($row['snooze_until'])
                   && $row['snooze_until'] < $cutoff) {
                $marked += db_execute(
                    "UPDATE medicine_logs
                        SET action = 'missed', action_time = ?, notes = 'Snoozed dose was never taken'
                      WHERE id = ? AND action = 'snoozed'",
                    [$now, (int) $row['log_id']]
                );
            }
        }
    }

    return $marked;
}

/**
 * Doses that must pop up RIGHT NOW on the patient's screen.
 * (due, still pending or snoozed past its snooze time, inside grace period)
 */
function due_reminders($patientId)
{
    $now  = date('Y-m-d H:i:s');
    $date = date('Y-m-d');
    $due  = [];

    foreach (patient_schedule($patientId, $date) as $row) {
        if ($row['scheduled_at'] > $now) {
            continue;                                     // not due yet
        }
        $graceEnd = date('Y-m-d H:i:s', strtotime($row['scheduled_at']) + REMINDER_GRACE_MINUTES * 60);

        if (empty($row['action'])) {
            if ($now <= $graceEnd) { $due[] = $row; }
        } elseif ($row['action'] === 'snoozed'
               && !empty($row['snooze_until'])
               && $row['snooze_until'] <= $now) {
            $due[] = $row;
        }
    }

    return $due;
}

/** The next upcoming (not yet due) dose of the day, or null. */
function next_dose($patientId)
{
    $now = date('Y-m-d H:i:s');
    foreach (patient_schedule($patientId, date('Y-m-d')) as $row) {
        if ($row['scheduled_at'] > $now && empty($row['action'])) {
            return $row;
        }
    }
    return null;
}

/**
 * Records Taken / Skipped / Snoozed for one dose occurrence.
 * Ownership is re-checked in SQL so a patient can never log
 * somebody else's medicine.
 *
 * @return array{ok:bool, message:string}
 */
function log_dose_action($reminderId, $patientId, $scheduledAt, $action, $notes = null)
{
    $reminderId = (int) $reminderId;
    $patientId  = (int) $patientId;
    $allowed    = ['taken', 'skipped', 'snoozed', 'missed'];

    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'message' => 'Unknown action.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $scheduledAt)) {
        return ['ok' => false, 'message' => 'Invalid reminder time.'];
    }

    $owns = db_exists(
        'SELECT 1 FROM medicine_reminders r
           JOIN medicines m ON m.id = r.medicine_id
          WHERE r.id = ? AND m.patient_id = ?',
        [$reminderId, $patientId]
    );
    if (!$owns) {
        return ['ok' => false, 'message' => 'This reminder does not belong to your account.'];
    }

    $now         = date('Y-m-d H:i:s');
    $snoozeUntil = ($action === 'snoozed')
                 ? date('Y-m-d H:i:s', time() + REMINDER_SNOOZE_MINUTES * 60)
                 : null;

    db_execute(
        'INSERT INTO medicine_logs (reminder_id, patient_id, scheduled_at, action, action_time, snooze_until, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE action = VALUES(action),
                                 action_time = VALUES(action_time),
                                 snooze_until = VALUES(snooze_until),
                                 notes = VALUES(notes)',
        [$reminderId, $patientId, $scheduledAt, $action, $now, $snoozeUntil, null_if_blank($notes)]
    );

    $labels = ['taken' => 'marked as taken', 'skipped' => 'skipped',
               'snoozed' => 'snoozed for ' . REMINDER_SNOOZE_MINUTES . ' minutes',
               'missed' => 'marked as missed'];

    return ['ok' => true, 'message' => 'Dose ' . $labels[$action] . '.'];
}

/**
 * Replaces the reminder times of a medicine with the supplied list.
 * Existing rows are kept (so their history survives), removed times are
 * deactivated, and new times are inserted.
 *
 * @param array $times list of 'HH:MM' strings
 */
function save_reminder_times($medicineId, array $times)
{
    $medicineId = (int) $medicineId;

    $clean = [];
    foreach ($times as $t) {
        $t = trim((string) $t);
        if ($t === '') { continue; }
        if (preg_match('/^\d{1,2}:\d{2}$/', $t)) { $t .= ':00'; }
        if (is_valid_time($t)) { $clean[substr($t, 0, 8)] = true; }
    }
    $clean = array_keys($clean);

    $existing = db_all('SELECT id, reminder_time FROM medicine_reminders WHERE medicine_id = ?', [$medicineId]);
    $existingByTime = [];
    foreach ($existing as $row) {
        $existingByTime[$row['reminder_time']] = (int) $row['id'];
    }

    // Re-activate / insert
    foreach ($clean as $time) {
        if (isset($existingByTime[$time])) {
            db_execute("UPDATE medicine_reminders SET status = 'active' WHERE id = ?", [$existingByTime[$time]]);
        } else {
            db_insert(
                'INSERT INTO medicine_reminders (medicine_id, reminder_time, status) VALUES (?, ?, ?)',
                [$medicineId, $time, 'active']
            );
        }
    }

    // Deactivate the ones that were removed (history in medicine_logs stays intact)
    foreach ($existingByTime as $time => $id) {
        if (!in_array($time, $clean, true)) {
            db_execute("UPDATE medicine_reminders SET status = 'inactive' WHERE id = ?", [$id]);
        }
    }

    return count($clean);
}

/** 'HH:MM' reminder times of a medicine. */
function medicine_reminder_times($medicineId, $activeOnly = true)
{
    $sql = 'SELECT reminder_time FROM medicine_reminders WHERE medicine_id = ?';
    if ($activeOnly) { $sql .= " AND status = 'active'"; }
    $rows = db_all($sql . ' ORDER BY reminder_time', [(int) $medicineId]);

    return array_map(function ($r) { return substr($r['reminder_time'], 0, 5); }, $rows);
}

/** "08:00 AM, 02:00 PM, 08:00 PM" for list screens. */
function reminder_times_label($medicineId)
{
    $times = medicine_reminder_times($medicineId);
    if (!$times) { return '<span class="text-muted">No reminders</span>'; }

    $out = [];
    foreach ($times as $t) { $out[] = format_time($t); }
    return e(implode(', ', $out));
}

/** Full dose text, e.g. "500 mg" or "1 tablet". */
function dose_label(array $medicine)
{
    return trim($medicine['dosage'] . ' ' . $medicine['dose_unit']);
}

/**
 * Adherence figures for a period (used by dashboards and reports).
 *
 * @return array{taken:int, missed:int, skipped:int, total:int, rate:float}
 */
function adherence_stats($patientId, $fromDate, $toDate)
{
    $row = db_row(
        "SELECT
            SUM(action = 'taken')   AS taken,
            SUM(action = 'missed')  AS missed,
            SUM(action = 'skipped') AS skipped,
            COUNT(*)                AS total
           FROM medicine_logs
          WHERE patient_id = ?
            AND scheduled_at BETWEEN ? AND ?",
        [(int) $patientId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59']
    );

    $taken = (int) ($row['taken'] ?? 0);
    $total = (int) ($row['total'] ?? 0);

    return [
        'taken'   => $taken,
        'missed'  => (int) ($row['missed'] ?? 0),
        'skipped' => (int) ($row['skipped'] ?? 0),
        'total'   => $total,
        'rate'    => $total > 0 ? round($taken * 100 / $total, 1) : 0.0,
    ];
}

/** Counts pending / taken doses of the patient for today (dashboard cards). */
function today_dose_counts($patientId)
{
    $counts = ['total' => 0, 'taken' => 0, 'pending' => 0, 'missed' => 0, 'skipped' => 0, 'snoozed' => 0];

    foreach (patient_schedule($patientId, date('Y-m-d')) as $row) {
        $counts['total']++;
        $counts[dose_status($row)]++;
    }
    return $counts;
}
