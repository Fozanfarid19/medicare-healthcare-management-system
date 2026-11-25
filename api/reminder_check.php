<?php
/**
 * =====================================================================
 *  MediCare - api/reminder_check.php
 *  AJAX endpoint used by assets/js/reminders.js.
 *
 *  GET  ?action=due          -> JSON list of doses that are due now
 *  POST action=taken|snoozed|skipped + reminder_id + scheduled_at
 *                            -> writes the action into medicine_logs
 *
 *  Only a logged in PATIENT may call it, and every query is filtered by
 *  the patient id taken from the SESSION (never from the request), so a
 *  patient can never read or change another patient's data.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicine.php';

require_role_api('patient');

$patientId = current_patient_id();

// ---------------------------------------------------------------------
// POST - record Taken / Snooze / Skip
// ---------------------------------------------------------------------
if (is_post()) {
    verify_csrf_api();

    $action      = post('action');
    $reminderId  = post_int('reminder_id');
    $scheduledAt = post('scheduled_at');

    if (!in_array($action, ['taken', 'snoozed', 'skipped'], true)) {
        json_response(['success' => false, 'message' => 'Unknown action requested.'], 400);
    }

    $result = log_dose_action($reminderId, $patientId, $scheduledAt, $action);

    if (!$result['ok']) {
        json_response(['success' => false, 'message' => $result['message']], 400);
    }

    // A missed/taken dose also updates the notification centre.
    if ($action === 'taken') {
        $medicine = db_row(
            'SELECT m.medicine_name FROM medicine_reminders r
               JOIN medicines m ON m.id = r.medicine_id
              WHERE r.id = ?',
            [$reminderId]
        );
        notify(current_user_id(), 'Dose recorded',
               ($medicine['medicine_name'] ?? 'Medicine') . ' was marked as taken at ' . date('h:i A') . '.',
               'medicine', 'patient/history.php');
    }

    json_response([
        'success' => true,
        'message' => $result['message'],
        'counts'  => today_dose_counts($patientId),
    ]);
}

// ---------------------------------------------------------------------
// GET - which doses are due right now?
// ---------------------------------------------------------------------
mark_missed_doses($patientId);          // keep the database in sync first

$due  = [];
foreach (due_reminders($patientId) as $row) {
    $doctorName = trim(($row['doctor_first_name'] ?? '') . ' ' . ($row['doctor_last_name'] ?? ''));

    $due[] = [
        'reminder_id'   => (int) $row['reminder_id'],
        'medicine_id'   => (int) $row['medicine_id'],
        'medicine_name' => $row['medicine_name'],
        'dose'          => trim($row['dosage'] . ' ' . $row['dose_unit']),
        'quantity'      => $row['quantity'],
        'route'         => $row['route'],
        'frequency'     => $row['frequency'],
        'instructions'  => $row['instructions'],
        'message'       => $row['message'],
        'doctor_name'   => $doctorName !== '' ? 'Dr. ' . $doctorName : '',
        'scheduled_at'  => $row['scheduled_at'],
        'time_label'    => format_time($row['reminder_time']),
    ];
}

json_response([
    'success'    => true,
    'server_time'=> date('Y-m-d H:i:s'),
    'due'        => $due,
    'counts'     => today_dose_counts($patientId),
]);
