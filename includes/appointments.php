<?php
/**
 * =====================================================================
 *  MediCare - includes/appointments.php
 *  Slot generation and the complete booking validation chain.
 *
 *  A slot is offered ONLY when all of these are true:
 *    1. the doctor exists and is active
 *    2. the clinic (if any) is active
 *    3. the date is today or later
 *    4. the time is inside doctor_availability for that weekday
 *    5. the doctor is not on approved time off that day
 *    6. no other appointment already occupies the slot
 *    7. the patient has no clashing appointment of their own
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

/** True when the doctor has approved time off covering $date. */
function doctor_is_off($doctorId, $date)
{
    return db_exists(
        "SELECT 1 FROM doctor_time_off
          WHERE doctor_id = ? AND status = 'approved'
            AND ? BETWEEN start_date AND end_date",
        [(int) $doctorId, $date]
    );
}

/** The time-off row covering $date (for the "why is it blocked" message). */
function doctor_time_off_on($doctorId, $date)
{
    return db_row(
        "SELECT * FROM doctor_time_off
          WHERE doctor_id = ? AND status = 'approved'
            AND ? BETWEEN start_date AND end_date
          LIMIT 1",
        [(int) $doctorId, $date]
    );
}

/** Active weekly working blocks of a doctor for the weekday of $date. */
function doctor_availability_for_date($doctorId, $date)
{
    $dayOfWeek = (int) date('N', strtotime($date));   // 1 = Monday ... 7 = Sunday

    return db_all(
        "SELECT * FROM doctor_availability
          WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
          ORDER BY start_time",
        [(int) $doctorId, $dayOfWeek]
    );
}

/** Slots already occupied by a live (not cancelled) appointment. */
function booked_slots($doctorId, $date)
{
    $rows = db_all(
        "SELECT start_time FROM appointments
          WHERE doctor_id = ? AND appointment_date = ?
            AND status IN ('pending','confirmed','completed')",
        [(int) $doctorId, $date]
    );

    $taken = [];
    foreach ($rows as $r) { $taken[substr($r['start_time'], 0, 5)] = true; }
    return $taken;
}

/**
 * Generates every bookable slot for one doctor on one date.
 *
 * @return array{
 *   available: array<int, array{start:string,end:string,label:string}>,
 *   blocked:   string   empty when slots could be generated
 * }
 */
function doctor_available_slots($doctorId, $date, $patientId = 0)
{
    $result = ['available' => [], 'blocked' => ''];

    if (!is_valid_date($date)) {
        $result['blocked'] = 'Please choose a valid date.';
        return $result;
    }
    if ($date < date('Y-m-d')) {
        $result['blocked'] = 'Appointments cannot be booked for a past date.';
        return $result;
    }

    $doctor = db_row("SELECT id, status FROM doctors WHERE id = ?", [(int) $doctorId]);
    if (!$doctor) {
        $result['blocked'] = 'The selected doctor was not found.';
        return $result;
    }
    if ($doctor['status'] !== 'active') {
        $result['blocked'] = 'This doctor is currently not accepting appointments.';
        return $result;
    }

    if (doctor_is_off($doctorId, $date)) {
        $off = doctor_time_off_on($doctorId, $date);
        $result['blocked'] = 'The doctor is unavailable on the selected date ('
                           . e($off['reason'] ?? 'Time off') . ').';
        return $result;
    }

    $blocks = doctor_availability_for_date($doctorId, $date);
    if (!$blocks) {
        $result['blocked'] = 'The doctor does not consult on '
                           . date('l', strtotime($date)) . '. Please choose another day.';
        return $result;
    }

    $taken       = booked_slots($doctorId, $date);
    $patientBusy = [];
    if ($patientId) {
        foreach (db_all(
            "SELECT start_time FROM appointments
              WHERE patient_id = ? AND appointment_date = ?
                AND status IN ('pending','confirmed')",
            [(int) $patientId, $date]
        ) as $r) {
            $patientBusy[substr($r['start_time'], 0, 5)] = true;
        }
    }

    $isToday = ($date === date('Y-m-d'));
    $nowHM   = date('H:i');

    foreach ($blocks as $block) {
        $duration = max(5, (int) $block['slot_duration']);
        $cursor   = strtotime($date . ' ' . $block['start_time']);
        $blockEnd = strtotime($date . ' ' . $block['end_time']);

        while (($cursor + $duration * 60) <= $blockEnd) {
            $start = date('H:i', $cursor);
            $end   = date('H:i', $cursor + $duration * 60);
            $cursor += $duration * 60;

            if ($isToday && $start <= $nowHM)        { continue; } // already passed
            if (isset($taken[$start]))               { continue; } // doctor busy
            if (isset($patientBusy[$start]))         { continue; } // patient busy

            $result['available'][] = [
                'start' => $start . ':00',
                'end'   => $end . ':00',
                'label' => format_time($start) . ' - ' . format_time($end),
            ];
        }
    }

    if (!$result['available']) {
        $result['blocked'] = 'All slots for this date are already booked. Please select another date.';
    }

    return $result;
}

/**
 * Books an appointment after running the full validation chain.
 * Everything happens inside a transaction with SELECT ... FOR UPDATE so
 * two patients clicking at the same moment can never take one slot.
 *
 * @return array{ok:bool, message:string, id:int}
 */
function book_appointment($patientId, $doctorId, $date, $startTime, $reason = '', $notes = '')
{
    $patientId = (int) $patientId;
    $doctorId  = (int) $doctorId;

    // ---- 1. patient ---------------------------------------------------
    $patient = db_row("SELECT id, user_id, first_name, last_name, status FROM patients WHERE id = ?", [$patientId]);
    if (!$patient || $patient['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Your patient profile is not active.', 'id' => 0];
    }

    // ---- 2. doctor + clinic + fee ------------------------------------
    $doctor = db_row(
        "SELECT d.*, c.status AS clinic_status, c.clinic_name
           FROM doctors d
           LEFT JOIN clinics c ON c.id = d.clinic_id
          WHERE d.id = ?",
        [$doctorId]
    );
    if (!$doctor) {
        return ['ok' => false, 'message' => 'The selected doctor was not found.', 'id' => 0];
    }
    if ($doctor['status'] !== 'active') {
        return ['ok' => false, 'message' => 'This doctor is currently not accepting appointments.', 'id' => 0];
    }
    if ($doctor['clinic_id'] !== null && $doctor['clinic_status'] !== 'active') {
        return ['ok' => false, 'message' => 'The clinic of this doctor is temporarily closed.', 'id' => 0];
    }

    // ---- 3. date / time sanity ---------------------------------------
    if (!is_valid_date($date)) {
        return ['ok' => false, 'message' => 'Please choose a valid appointment date.', 'id' => 0];
    }
    if ($date < date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Appointments cannot be booked for a past date.', 'id' => 0];
    }
    if (!is_valid_time($startTime)) {
        return ['ok' => false, 'message' => 'Please choose an appointment time.', 'id' => 0];
    }
    $startTime = substr($startTime, 0, 5) . ':00';
    if ($date === date('Y-m-d') && substr($startTime, 0, 5) <= date('H:i')) {
        return ['ok' => false, 'message' => 'That time has already passed today. Please pick a later slot.', 'id' => 0];
    }

    // ---- 4. + 5. availability and time off ---------------------------
    $slots = doctor_available_slots($doctorId, $date, $patientId);
    if ($slots['blocked'] !== '') {
        return ['ok' => false, 'message' => $slots['blocked'], 'id' => 0];
    }

    $chosen = null;
    foreach ($slots['available'] as $slot) {
        if ($slot['start'] === $startTime) { $chosen = $slot; break; }
    }
    if (!$chosen) {
        return ['ok' => false, 'message' => 'This appointment slot is already booked or outside the doctor\'s working hours. Please select another time.', 'id' => 0];
    }

    // ---- 6. + 7. race-safe insert ------------------------------------
    db_begin();
    try {
        $clash = db_row(
            "SELECT id FROM appointments
              WHERE doctor_id = ? AND appointment_date = ? AND start_time = ?
                AND status IN ('pending','confirmed','completed')
              FOR UPDATE",
            [$doctorId, $date, $startTime]
        );
        if ($clash) {
            db_rollback();
            return ['ok' => false, 'message' => 'This appointment slot is already booked. Please select another time.', 'id' => 0];
        }

        $selfClash = db_row(
            "SELECT id FROM appointments
              WHERE patient_id = ? AND appointment_date = ? AND start_time = ?
                AND status IN ('pending','confirmed')
              FOR UPDATE",
            [$patientId, $date, $startTime]
        );
        if ($selfClash) {
            db_rollback();
            return ['ok' => false, 'message' => 'You already have an appointment at that time.', 'id' => 0];
        }

        $appointmentId = db_insert(
            "INSERT INTO appointments
                (patient_id, doctor_id, clinic_id, appointment_date, start_time, end_time,
                 status, reason, notes, consultation_fee, payment_status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, 'pending')",
            [
                $patientId,
                $doctorId,
                $doctor['clinic_id'] !== null ? (int) $doctor['clinic_id'] : null,
                $date,
                $startTime,
                $chosen['end'],
                null_if_blank($reason),
                null_if_blank($notes),
                (float) $doctor['consultation_fee'],   // 10. fee captured now
            ]
        );

        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        error_log('[MediCare booking] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unable to save the appointment. Please try again.', 'id' => 0];
    }

    // ---- 11. notifications -------------------------------------------
    $patientName = $patient['first_name'] . ' ' . $patient['last_name'];
    $doctorName  = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];

    notify(
        (int) $doctor['user_id'],
        'New appointment request',
        $patientName . ' booked ' . format_date($date) . ' at ' . format_time($startTime) . '.',
        'appointment',
        'doctor/appointments.php'
    );
    notify(
        (int) $patient['user_id'],
        'Appointment requested',
        'Your appointment with ' . $doctorName . ' on ' . format_date($date) . ' at '
            . format_time($startTime) . ' is awaiting confirmation.',
        'appointment',
        'patient/appointments.php'
    );

    return ['ok' => true, 'message' => 'Appointment booked successfully. Fee: '
        . money($doctor['consultation_fee']) . ' (payment pending).', 'id' => $appointmentId];
}

/**
 * Changes the status of an appointment and notifies the other party.
 *
 * @param string $actorRole admin|doctor|patient
 * @return array{ok:bool, message:string}
 */
function change_appointment_status($appointmentId, $newStatus, $actorRole)
{
    $appointmentId = (int) $appointmentId;

    if (!in_array($newStatus, appointment_statuses(), true)) {
        return ['ok' => false, 'message' => 'Unknown appointment status.'];
    }

    $appt = db_row(
        "SELECT a.*, p.user_id AS patient_user_id, p.first_name AS p_first, p.last_name AS p_last,
                d.user_id AS doctor_user_id, d.first_name AS d_first, d.last_name AS d_last
           FROM appointments a
           JOIN patients p ON p.id = a.patient_id
           JOIN doctors  d ON d.id = a.doctor_id
          WHERE a.id = ?",
        [$appointmentId]
    );
    if (!$appt) {
        return ['ok' => false, 'message' => 'Appointment not found.'];
    }
    if (in_array($appt['status'], ['completed', 'cancelled'], true) && $actorRole === 'patient') {
        return ['ok' => false, 'message' => 'This appointment can no longer be changed.'];
    }

    db_execute('UPDATE appointments SET status = ? WHERE id = ?', [$newStatus, $appointmentId]);

    $doctorName = 'Dr. ' . $appt['d_first'] . ' ' . $appt['d_last'];
    $when       = format_date($appt['appointment_date']) . ' ' . format_time($appt['start_time']);

    if ($actorRole === 'patient') {
        notify((int) $appt['doctor_user_id'], 'Appointment ' . $newStatus,
               $appt['p_first'] . ' ' . $appt['p_last'] . ' ' . $newStatus . ' the appointment on ' . $when . '.',
               'appointment', 'doctor/appointments.php');
    } else {
        notify((int) $appt['patient_user_id'], 'Appointment ' . nice_label($newStatus),
               'Your appointment with ' . $doctorName . ' on ' . $when . ' is now ' . nice_label($newStatus) . '.',
               'appointment', 'patient/appointments.php');
    }

    return ['ok' => true, 'message' => 'Appointment marked as ' . nice_label($newStatus) . '.'];
}

/** Marks the (simulated) consultation fee as paid. */
function mark_appointment_paid($appointmentId)
{
    $appt = db_row('SELECT a.*, p.user_id AS patient_user_id FROM appointments a
                      JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [(int) $appointmentId]);
    if (!$appt) {
        return ['ok' => false, 'message' => 'Appointment not found.'];
    }
    if ($appt['payment_status'] === 'paid') {
        return ['ok' => false, 'message' => 'This appointment is already marked as paid.'];
    }

    db_execute("UPDATE appointments SET payment_status = 'paid' WHERE id = ?", [(int) $appointmentId]);
    notify((int) $appt['patient_user_id'], 'Payment received',
           'Consultation fee of ' . money($appt['consultation_fee']) . ' has been marked as paid.',
           'appointment', 'patient/appointments.php');

    return ['ok' => true, 'message' => 'Consultation fee marked as paid.'];
}

/** Common SELECT used by every appointment list screen (JOIN, never duplicated data). */
function appointment_select_sql()
{
    return "SELECT a.*,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   p.phone AS patient_phone,
                   p.id    AS p_id,
                   CONCAT('Dr. ',d.first_name,' ',d.last_name) AS doctor_name,
                   d.id    AS d_id,
                   s.specialization_name,
                   c.clinic_name
              FROM appointments a
              JOIN patients p ON p.id = a.patient_id
              JOIN doctors  d ON d.id = a.doctor_id
              LEFT JOIN specializations s ON s.id = d.specialization_id
              LEFT JOIN clinics c ON c.id = a.clinic_id";
}
