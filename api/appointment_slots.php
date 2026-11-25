<?php
/**
 * =====================================================================
 *  MediCare - api/appointment_slots.php
 *  Returns the bookable time slots of one doctor on one date.
 *
 *  GET ?doctor_id=3&date=2026-05-20
 *  {
 *    "success": true,
 *    "date": "2026-05-20",
 *    "doctor": "Dr. John Smith",
 *    "fee": "$75.00",
 *    "clinic": "MediCare Central Clinic",
 *    "slots": [{"start":"09:00:00","label":"09:00 AM - 09:30 AM"}, ...],
 *    "message": ""            // filled when no slot can be offered
 *  }
 * =====================================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

require_role_api(['patient', 'admin', 'doctor']);

$doctorId = get_int('doctor_id');
$date     = get_param('date');

if (!$doctorId || !is_valid_date($date)) {
    json_response(['success' => false, 'message' => 'Please select a doctor and a valid date.'], 400);
}

$doctor = db_row(
    "SELECT d.id, d.first_name, d.last_name, d.consultation_fee, d.status,
            s.specialization_name, c.clinic_name
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c         ON c.id = d.clinic_id
      WHERE d.id = ?",
    [$doctorId]
);

if (!$doctor) {
    json_response(['success' => false, 'message' => 'The selected doctor was not found.'], 404);
}

// A patient books for themselves; staff can preview any doctor's diary.
$patientId = current_patient_id();

$slots = doctor_available_slots($doctorId, $date, $patientId);

json_response([
    'success' => true,
    'date'    => $date,
    'day'     => date('l', strtotime($date)),
    'doctor'  => 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'],
    'specialization' => $doctor['specialization_name'],
    'clinic'  => $doctor['clinic_name'],
    'fee'     => money($doctor['consultation_fee']),
    'slots'   => $slots['available'],
    'message' => $slots['blocked'],
]);
