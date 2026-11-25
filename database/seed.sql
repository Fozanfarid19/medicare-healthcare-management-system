-- =====================================================================
--  MediCare - database/seed.sql        (import AFTER schema.sql)
--
--  Demo credentials  (passwords are stored as BCRYPT hashes, never plain
--  text - they were produced with PHP password_hash()):
--
--     Administrator : admin@medicare.local    / Admin@123
--     Doctor        : doctor@medicare.local   / Doctor@123
--     Patient       : patient@medicare.local  / Patient@123
--
--     Extra doctors  : priya.sharma@medicare.local , david.chen@medicare.local
--                      (password Doctor@123)
--     Extra patients : maria.garcia@medicare.local , ahmed.khan@medicare.local ,
--                      linda.owusu@medicare.local  , tom.becker@medicare.local
--                      (password Patient@123)
--
--  All dates are inserted RELATIVE to CURDATE(), so the demo data is
--  always "today", no matter when the file is imported.
-- =====================================================================

USE `medicare_db`;

-- Clear any previous demo data (DELETE works on every MySQL/MariaDB
-- version; foreign key checks are disabled so the order does not matter).
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `prescription_medicines`;
DELETE FROM `prescriptions`;
DELETE FROM `medicine_logs`;
DELETE FROM `medicine_reminders`;
DELETE FROM `medicines`;
DELETE FROM `appointments`;
DELETE FROM `doctor_time_off`;
DELETE FROM `doctor_availability`;
DELETE FROM `notifications`;
DELETE FROM `doctors`;
DELETE FROM `patients`;
DELETE FROM `users`;
DELETE FROM `clinics`;
DELETE FROM `specializations`;
DELETE FROM `roles`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. ROLES
-- ---------------------------------------------------------------------
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'admin',   'System administrator with full access'),
(2, 'doctor',  'Registered medical practitioner'),
(3, 'patient', 'Registered patient');

-- ---------------------------------------------------------------------
-- 2. SPECIALIZATIONS
-- ---------------------------------------------------------------------
INSERT INTO `specializations` (`id`, `specialization_name`, `description`) VALUES
(1, 'Cardiologist',       'Heart and blood vessel disorders'),
(2, 'Dermatologist',      'Skin, hair and nail conditions'),
(3, 'Pediatrician',       'Medical care of infants and children'),
(4, 'General Physician',  'Primary healthcare and general medicine'),
(5, 'Orthopedic Surgeon', 'Bones, joints and musculoskeletal system'),
(6, 'Neurologist',        'Brain, spinal cord and nervous system'),
(7, 'Gynecologist',       'Female reproductive health');

-- ---------------------------------------------------------------------
-- 3. CLINICS
-- ---------------------------------------------------------------------
INSERT INTO `clinics`
(`id`,`clinic_name`,`address`,`city`,`state`,`postal_code`,`phone`,`email`,`opening_time`,`closing_time`,`status`) VALUES
(1,'MediCare Central Clinic','12 Wellness Avenue','Springfield','Illinois','62701','+1 555 0100','central@medicare.local','08:00:00','20:00:00','active'),
(2,'MediCare City Hospital','48 Riverside Road','Springfield','Illinois','62704','+1 555 0200','city@medicare.local','07:00:00','22:00:00','active'),
(3,'MediCare Family Care Center','7 Green Park Street','Shelbyville','Illinois','62565','+1 555 0300','family@medicare.local','09:00:00','18:00:00','active');

-- ---------------------------------------------------------------------
-- 4. USERS   (BCRYPT hashes - Admin@123 / Doctor@123 / Patient@123)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`,`role_id`,`email`,`password`,`status`) VALUES
(1, 1, 'admin@medicare.local',        '$2y$10$iqqWbVICHxbkRO8Wk8LqE.cgsIvz1uxebbAGKVtKOsA8MsrXX5HaG', 'active'),
(2, 2, 'doctor@medicare.local',       '$2y$10$Yx4jC.aueYJtyJSwMe6LguZkwyIh0.6uBkZ/.Mx1nmgJYg1W/FXsO', 'active'),
(3, 2, 'priya.sharma@medicare.local', '$2y$10$Yx4jC.aueYJtyJSwMe6LguZkwyIh0.6uBkZ/.Mx1nmgJYg1W/FXsO', 'active'),
(4, 2, 'david.chen@medicare.local',   '$2y$10$Yx4jC.aueYJtyJSwMe6LguZkwyIh0.6uBkZ/.Mx1nmgJYg1W/FXsO', 'active'),
(5, 3, 'patient@medicare.local',      '$2y$10$cberGtESKKYUA/8OzYFsFuz/kmHLXHxwGRLtE1lXMkbMm/84EwyOe', 'active'),
(6, 3, 'maria.garcia@medicare.local', '$2y$10$cberGtESKKYUA/8OzYFsFuz/kmHLXHxwGRLtE1lXMkbMm/84EwyOe', 'active'),
(7, 3, 'ahmed.khan@medicare.local',   '$2y$10$cberGtESKKYUA/8OzYFsFuz/kmHLXHxwGRLtE1lXMkbMm/84EwyOe', 'active'),
(8, 3, 'linda.owusu@medicare.local',  '$2y$10$cberGtESKKYUA/8OzYFsFuz/kmHLXHxwGRLtE1lXMkbMm/84EwyOe', 'active'),
(9, 3, 'tom.becker@medicare.local',   '$2y$10$cberGtESKKYUA/8OzYFsFuz/kmHLXHxwGRLtE1lXMkbMm/84EwyOe', 'active');

-- ---------------------------------------------------------------------
-- 5. DOCTORS
-- ---------------------------------------------------------------------
INSERT INTO `doctors`
(`id`,`user_id`,`clinic_id`,`specialization_id`,`first_name`,`last_name`,`gender`,`date_of_birth`,
 `phone`,`qualification`,`license_number`,`experience_years`,`consultation_fee`,`address`,`bio`,`status`) VALUES
(1, 2, 1, 1, 'John',  'Smith',  'Male',   '1980-04-17', '+1 555 1001',
 'MBBS, MD (Cardiology)', 'MED-2024-0001', 14, 75.00, '22 Oakwood Lane, Springfield',
 'Consultant cardiologist with 14 years of experience in preventive cardiology, hypertension management and cardiac rehabilitation.', 'active'),
(2, 3, 1, 2, 'Priya', 'Sharma', 'Female', '1986-09-02', '+1 555 1002',
 'MBBS, MD (Dermatology)', 'MED-2024-0002', 9, 50.00, '5 Lakeview Court, Springfield',
 'Dermatologist specialising in clinical dermatology, allergy related skin disease and paediatric skin care.', 'active'),
(3, 4, 2, 3, 'David', 'Chen',   'Male',   '1978-12-25', '+1 555 1003',
 'MBBS, DCH, MD (Pediatrics)', 'MED-2024-0003', 18, 60.00, '90 Riverside Road, Springfield',
 'Senior paediatrician with a special interest in childhood immunisation, growth monitoring and respiratory illness.', 'active');

-- ---------------------------------------------------------------------
-- 6. PATIENTS
-- ---------------------------------------------------------------------
INSERT INTO `patients`
(`id`,`user_id`,`first_name`,`last_name`,`gender`,`date_of_birth`,`blood_group`,`phone`,`address`,
 `emergency_contact_name`,`emergency_contact_phone`,`registration_date`,`status`) VALUES
(1, 5, 'Robert', 'Johnson', 'Male',   '1990-06-12', 'O+',  '+1 555 2001', '18 Maple Street, Springfield',
   'Emily Johnson', '+1 555 2101', DATE_SUB(CURDATE(), INTERVAL 120 DAY), 'active'),
(2, 6, 'Maria',  'Garcia',  'Female', '1985-02-28', 'A+',  '+1 555 2002', '77 Sunset Boulevard, Springfield',
   'Carlos Garcia', '+1 555 2102', DATE_SUB(CURDATE(), INTERVAL 95 DAY), 'active'),
(3, 7, 'Ahmed',  'Khan',    'Male',   '1972-11-05', 'B+',  '+1 555 2003', '3 Hillcrest Road, Shelbyville',
   'Sara Khan', '+1 555 2103', DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'active'),
(4, 8, 'Linda',  'Owusu',   'Female', '1998-08-19', 'AB-', '+1 555 2004', '210 Elm Court, Springfield',
   'Kwame Owusu', '+1 555 2104', DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'active'),
(5, 9, 'Tom',    'Becker',  'Male',   '2015-03-09', 'O-',  '+1 555 2005', '44 Birch Avenue, Shelbyville',
   'Anna Becker', '+1 555 2105', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'active');

-- ---------------------------------------------------------------------
-- 7. DOCTOR AVAILABILITY   (1 = Monday ... 7 = Sunday)
-- ---------------------------------------------------------------------
INSERT INTO `doctor_availability` (`doctor_id`,`day_of_week`,`start_time`,`end_time`,`slot_duration`,`status`) VALUES
-- Dr. John Smith - weekday mornings + Tuesday/Thursday evening clinic
(1, 1, '09:00:00', '13:00:00', 30, 'active'),
(1, 2, '09:00:00', '13:00:00', 30, 'active'),
(1, 2, '17:00:00', '19:00:00', 30, 'active'),
(1, 3, '09:00:00', '13:00:00', 30, 'active'),
(1, 4, '09:00:00', '13:00:00', 30, 'active'),
(1, 4, '17:00:00', '19:00:00', 30, 'active'),
(1, 5, '09:00:00', '13:00:00', 30, 'active'),
-- Dr. Priya Sharma - afternoon clinics
(2, 1, '14:00:00', '18:00:00', 20, 'active'),
(2, 3, '14:00:00', '18:00:00', 20, 'active'),
(2, 5, '14:00:00', '18:00:00', 20, 'active'),
(2, 6, '10:00:00', '13:00:00', 20, 'active'),
-- Dr. David Chen - paediatrics
(3, 2, '09:00:00', '12:00:00', 30, 'active'),
(3, 4, '09:00:00', '12:00:00', 30, 'active'),
(3, 6, '10:00:00', '14:00:00', 30, 'active'),
(3, 7, '10:00:00', '12:00:00', 30, 'active');

-- ---------------------------------------------------------------------
-- 8. DOCTOR TIME OFF
-- ---------------------------------------------------------------------
INSERT INTO `doctor_time_off` (`doctor_id`,`start_date`,`end_date`,`reason`,`notes`,`status`) VALUES
(1, DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'Vacation',   'Annual family holiday', 'approved'),
(2, DATE_ADD(CURDATE(), INTERVAL  3 DAY), DATE_ADD(CURDATE(), INTERVAL  3 DAY), 'Conference', 'National dermatology conference', 'approved'),
(3, DATE_SUB(CURDATE(), INTERVAL  7 DAY), DATE_SUB(CURDATE(), INTERVAL  5 DAY), 'Personal Leave', 'Completed leave (historic record)', 'approved');

-- ---------------------------------------------------------------------
-- 9. APPOINTMENTS
--    consultation_fee is the fee that applied ON THE BOOKING DAY.
-- ---------------------------------------------------------------------
INSERT INTO `appointments`
(`id`,`patient_id`,`doctor_id`,`clinic_id`,`appointment_date`,`start_time`,`end_time`,
 `status`,`reason`,`notes`,`consultation_fee`,`payment_status`) VALUES
(1, 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 21 DAY), '09:00:00','09:30:00','completed','Chest discomfort and palpitations','ECG normal. Started on antihypertensive therapy.', 75.00,'paid'),
(2, 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL  7 DAY), '09:30:00','10:00:00','completed','Follow-up for blood pressure','BP improving, continue same medication.', 75.00,'paid'),
(3, 2, 2, 1, DATE_SUB(CURDATE(), INTERVAL  5 DAY), '14:00:00','14:20:00','completed','Persistent skin rash','Contact dermatitis. Topical steroid prescribed.', 50.00,'paid'),
(4, 5, 3, 2, DATE_SUB(CURDATE(), INTERVAL  3 DAY), '10:00:00','10:30:00','no_show','Routine child check-up',NULL, 60.00,'pending'),
(5, 3, 1, 1, CURDATE(),                            '11:00:00','11:30:00','confirmed','Annual cardiac review',NULL, 75.00,'pending'),
(6, 4, 2, 1, CURDATE(),                            '15:00:00','15:20:00','pending','Acne treatment consultation',NULL, 50.00,'pending'),
(7, 2, 1, 1, DATE_ADD(CURDATE(), INTERVAL 2 DAY),  '10:00:00','10:30:00','confirmed','Cholesterol review',NULL, 75.00,'pending'),
(8, 1, 3, 2, DATE_ADD(CURDATE(), INTERVAL 4 DAY),  '09:30:00','10:00:00','pending','Consultation for family member',NULL, 60.00,'pending'),
(9, 4, 1, 1, DATE_ADD(CURDATE(), INTERVAL 6 DAY),  '09:00:00','09:30:00','cancelled','Scheduling conflict','Cancelled by the patient.', 75.00,'pending'),
(10,3, 2, 1, DATE_ADD(CURDATE(), INTERVAL 8 DAY),  '16:00:00','16:20:00','confirmed','Mole examination',NULL, 50.00,'pending');

-- ---------------------------------------------------------------------
-- 10. MEDICINES
-- ---------------------------------------------------------------------
INSERT INTO `medicines`
(`id`,`patient_id`,`prescribed_by`,`medicine_name`,`generic_name`,`category`,`dosage`,`dose_unit`,
 `quantity`,`frequency`,`route`,`start_date`,`end_date`,`instructions`,`notes`,`status`) VALUES
(1, 1, 1, 'Amlodipine',  'Amlodipine besylate', 'Antihypertensive', '5',   'mg',      '1 tablet',  'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 40 DAY),
    'Take in the morning after breakfast.', 'Blood pressure control', 'active'),
(2, 1, 1, 'Atorvastatin','Atorvastatin calcium','Cardiac',          '10',  'mg',      '1 tablet',  'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 20 DAY), NULL,
    'Take at night before sleeping.', 'Cholesterol management', 'active'),
(3, 1, NULL,'Vitamin D3', 'Cholecalciferol',    'Vitamin/Supplement','1000','IU',     '1 capsule', 'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 10 DAY), NULL,
    'Take with a meal.', 'Self added supplement', 'active'),
(4, 1, 1, 'Amoxicillin', 'Amoxicillin trihydrate','Antibiotic',     '500', 'mg',      '1 capsule', 'Three Times Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY),
    'Take after food. Complete the full course.', 'Chest infection', 'active'),
(5, 2, 2, 'Cetirizine',  'Cetirizine hydrochloride','Other',        '10',  'mg',      '1 tablet',  'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY),
    'Take at night, may cause drowsiness.', 'Allergic dermatitis', 'active'),
(6, 2, 2, 'Hydrocortisone Cream','Hydrocortisone','Other',          '1',   'other',   'Thin layer','Twice Daily',
    'Topical', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY),
    'Apply a thin layer on the affected area.', 'Contact dermatitis', 'active'),
(7, 3, 1, 'Metformin',   'Metformin hydrochloride','Antidiabetic',  '500', 'mg',      '1 tablet',  'Twice Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 45 DAY), NULL,
    'Take after breakfast and after dinner.', 'Type 2 diabetes', 'active'),
(8, 4, NULL,'Paracetamol','Acetaminophen',     'Analgesic',         '500', 'mg',      '1 tablet',  'As Needed',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY),
    'Take only when you have fever or pain. Maximum 4 tablets per day.', NULL, 'active'),
(9, 5, 3, 'Multivitamin Syrup','Multivitamin','Vitamin/Supplement',  '5',   'ml',      '1 spoon',   'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY),
    'Give with breakfast.', 'Growth support', 'active'),
(10,1, 1, 'Aspirin',     'Acetylsalicylic acid','Cardiac',          '75',  'mg',      '1 tablet',  'Once Daily',
    'Oral', DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY),
    'Discontinued after review.', 'Stopped by the doctor', 'discontinued');

-- ---------------------------------------------------------------------
-- 11. MEDICINE REMINDERS   (1NF: one row per reminder time)
-- ---------------------------------------------------------------------
INSERT INTO `medicine_reminders` (`id`,`medicine_id`,`reminder_time`,`reminder_date`,`message`,`status`) VALUES
(1,  1, '08:00:00', NULL, 'Take Amlodipine 5 mg after breakfast', 'active'),
(2,  2, '21:00:00', NULL, 'Take Atorvastatin 10 mg before sleeping', 'active'),
(3,  3, '13:00:00', NULL, 'Take Vitamin D3 with lunch', 'active'),
(4,  4, '08:00:00', NULL, 'Amoxicillin 500 mg - dose 1 of 3', 'active'),
(5,  4, '14:00:00', NULL, 'Amoxicillin 500 mg - dose 2 of 3', 'active'),
(6,  4, '20:00:00', NULL, 'Amoxicillin 500 mg - dose 3 of 3', 'active'),
(7,  5, '21:30:00', NULL, 'Take Cetirizine 10 mg', 'active'),
(8,  6, '08:30:00', NULL, 'Apply Hydrocortisone cream', 'active'),
(9,  6, '20:30:00', NULL, 'Apply Hydrocortisone cream', 'active'),
(10, 7, '09:00:00', NULL, 'Metformin 500 mg after breakfast', 'active'),
(11, 7, '21:00:00', NULL, 'Metformin 500 mg after dinner', 'active'),
(12, 9, '08:00:00', NULL, 'Multivitamin syrup with breakfast', 'active');

-- A reminder that becomes due 2 MINUTES AFTER THIS FILE IS IMPORTED.
-- Sign in as patient@medicare.local, stay on the dashboard and the
-- reminder pop-up will appear by itself - perfect for Demonstration 4.
INSERT INTO `medicine_reminders` (`id`,`medicine_id`,`reminder_time`,`reminder_date`,`message`,`status`)
VALUES (13, 3, DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 2 MINUTE), '%H:%i:00'), CURDATE(),
        'Demo reminder: Vitamin D3 1000 IU', 'active');

-- ---------------------------------------------------------------------
-- 12. MEDICINE LOGS   (adherence history for the last few days)
-- ---------------------------------------------------------------------
INSERT INTO `medicine_logs` (`reminder_id`,`patient_id`,`scheduled_at`,`action`,`action_time`,`notes`) VALUES
-- Robert Johnson (patient 1)
(1, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '08:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '08:12:00'), NULL),
(2, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '21:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '21:05:00'), NULL),
(1, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:04:00'), NULL),
(2, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '21:00:00'), 'missed',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '22:30:00'), 'Automatically marked as missed'),
(3, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '13:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '13:20:00'), NULL),
(1, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:02:00'), NULL),
(2, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '21:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '21:10:00'), NULL),
(3, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00'), 'skipped', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:30:00'), 'Was travelling'),
(4, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:15:00'), NULL),
(5, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '14:00:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '14:05:00'), NULL),
(6, 1, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20:00:00'), 'missed',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '21:05:00'), 'Automatically marked as missed'),
-- Maria Garcia (patient 2)
(7, 2, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '21:30:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '21:35:00'), NULL),
(8, 2, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:30:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:40:00'), NULL),
(9, 2, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20:30:00'), 'taken',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '20:45:00'), NULL),
-- Ahmed Khan (patient 3)
(10, 3, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '09:00:00'), 'taken',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '09:03:00'), NULL),
(11, 3, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '21:00:00'), 'taken',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '21:15:00'), NULL),
(10, 3, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:00:00'), 'taken',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:01:00'), NULL),
(11, 3, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '21:00:00'), 'missed', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '22:10:00'), 'Automatically marked as missed'),
-- Tom Becker (patient 5)
(12, 5, TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00'), 'taken',  TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:20:00'), NULL);

-- ---------------------------------------------------------------------
-- 13. PRESCRIPTIONS + 14. PRESCRIPTION MEDICINES
-- ---------------------------------------------------------------------
INSERT INTO `prescriptions`
(`id`,`patient_id`,`doctor_id`,`appointment_id`,`prescription_date`,`diagnosis`,`notes`) VALUES
(1, 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'Stage 1 hypertension with dyslipidaemia',
   'Low salt diet, 30 minutes of walking daily, review after three weeks.'),
(2, 2, 2, 3, DATE_SUB(CURDATE(), INTERVAL  5 DAY), 'Allergic contact dermatitis',
   'Avoid the suspected detergent, keep the skin moisturised.'),
(3, 1, 1, 2, DATE_SUB(CURDATE(), INTERVAL  2 DAY), 'Acute lower respiratory tract infection',
   'Complete the full antibiotic course even if symptoms improve.');

INSERT INTO `prescription_medicines` (`prescription_id`,`medicine_id`) VALUES
(1, 1), (1, 2), (2, 5), (2, 6), (3, 4);

-- ---------------------------------------------------------------------
-- 15. NOTIFICATIONS
-- ---------------------------------------------------------------------
INSERT INTO `notifications` (`user_id`,`title`,`message`,`notification_type`,`link`,`is_read`) VALUES
(5, 'New prescription created', 'Dr. John Smith prescribed Amoxicillin 500 mg for you.', 'prescription', 'patient/medicines.php', 0),
(5, 'Appointment confirmed',    'Your appointment with Dr. John Smith has been confirmed.', 'appointment', 'patient/appointments.php', 0),
(5, 'Medicine missed',          'You missed the Amoxicillin dose scheduled at 08:00 PM.', 'medicine', 'patient/history.php', 0),
(2, 'New appointment request',  'Robert Johnson booked an appointment for a cardiac review.', 'appointment', 'doctor/appointments.php', 0),
(2, 'Appointment cancelled',    'Linda Owusu cancelled an upcoming appointment.', 'appointment', 'doctor/appointments.php', 1),
(1, 'New patient registered',   'Tom Becker created a patient account.', 'system', 'admin/patients.php', 0);

-- =====================================================================
--  Seed data complete.
--  Open  http://localhost/medicare/  and sign in with one of the demo
--  accounts documented at the top of this file.
-- =====================================================================
