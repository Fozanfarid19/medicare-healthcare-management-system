-- =====================================================================
--  MediCare - Medicine Reminder & Healthcare Management System
--  File    : database/schema.sql
--  Engine  : InnoDB (required for FOREIGN KEY support)
--  Charset : utf8mb4
--
--  Import order:  1) schema.sql      2) seed.sql
--
--  DESIGN NOTE (Normalisation - 3NF):
--    * Every table has a single-column surrogate PRIMARY KEY (id).
--    * All attribute values are atomic  ................ 1NF
--      (e.g. reminder times live in medicine_reminders, one row per time,
--       never as a comma separated list inside medicines).
--    * No partial dependencies - no composite primary keys are used, so
--      every non-key column depends on the WHOLE key ... 2NF
--    * No transitive dependencies - descriptive data such as the doctor's
--      name, phone, clinic name or specialisation name is NEVER copied
--      into appointments/medicines; only the foreign key is stored and
--      the value is fetched with a JOIN ................ 3NF
--
--    Deliberate exception (documented in README):
--      appointments.consultation_fee stores the fee AT THE TIME OF BOOKING.
--      This is not a normalisation error - it is a temporal/historic fact
--      about the appointment, not a copy of the doctor's current fee.
-- =====================================================================

DROP DATABASE IF EXISTS `medicare_db`;
CREATE DATABASE `medicare_db`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `medicare_db`;

SET FOREIGN_KEY_CHECKS = 1;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. roles  (lookup table - removes role-name duplication from users)
-- ---------------------------------------------------------------------
CREATE TABLE `roles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_name`   VARCHAR(20)  NOT NULL,
    `description` VARCHAR(150) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. users  (authentication only - profile data lives in doctors/patients)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id`    INT UNSIGNED NOT NULL,
    `email`      VARCHAR(120) NOT NULL,
    `password`   VARCHAR(255) NOT NULL COMMENT 'BCRYPT hash produced by password_hash()',
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role_id`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. specializations (lookup - keeps doctors table in 3NF)
-- ---------------------------------------------------------------------
CREATE TABLE `specializations` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `specialization_name` VARCHAR(80) NOT NULL,
    `description`         VARCHAR(200) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_spec_name` (`specialization_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. clinics
-- ---------------------------------------------------------------------
CREATE TABLE `clinics` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clinic_name`  VARCHAR(120) NOT NULL,
    `address`      VARCHAR(200) NOT NULL,
    `city`         VARCHAR(60)  NOT NULL,
    `state`        VARCHAR(60)  DEFAULT NULL,
    `postal_code`  VARCHAR(15)  DEFAULT NULL,
    `phone`        VARCHAR(20)  DEFAULT NULL,
    `email`        VARCHAR(120) DEFAULT NULL,
    `opening_time` TIME NOT NULL DEFAULT '09:00:00',
    `closing_time` TIME NOT NULL DEFAULT '18:00:00',
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clinic_name` (`clinic_name`),
    KEY `idx_clinic_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. doctors  (1:1 with users, N:1 with clinics and specializations)
-- ---------------------------------------------------------------------
CREATE TABLE `doctors` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `clinic_id`         INT UNSIGNED DEFAULT NULL,
    `specialization_id` INT UNSIGNED DEFAULT NULL,
    `first_name`        VARCHAR(60) NOT NULL,
    `last_name`         VARCHAR(60) NOT NULL,
    `gender`            ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
    `date_of_birth`     DATE DEFAULT NULL,
    `phone`             VARCHAR(20) NOT NULL,
    `qualification`     VARCHAR(120) DEFAULT NULL,
    `license_number`    VARCHAR(50)  NOT NULL,
    `experience_years`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `consultation_fee`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `address`           VARCHAR(200) DEFAULT NULL,
    `bio`               TEXT DEFAULT NULL,
    `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doctor_user`    (`user_id`),
    UNIQUE KEY `uq_doctor_license` (`license_number`),
    KEY `idx_doctor_clinic` (`clinic_id`),
    KEY `idx_doctor_spec`   (`specialization_id`),
    KEY `idx_doctor_status` (`status`),
    CONSTRAINT `fk_doctors_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doctors_clinic`
        FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doctors_spec`
        FOREIGN KEY (`specialization_id`) REFERENCES `specializations` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. patients (1:1 with users)
-- ---------------------------------------------------------------------
CREATE TABLE `patients` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`                 INT UNSIGNED NOT NULL,
    `first_name`              VARCHAR(60) NOT NULL,
    `last_name`               VARCHAR(60) NOT NULL,
    `gender`                  ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
    `date_of_birth`           DATE DEFAULT NULL,
    `blood_group`             ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown')
                              NOT NULL DEFAULT 'Unknown',
    `phone`                   VARCHAR(20) NOT NULL,
    `address`                 VARCHAR(200) DEFAULT NULL,
    `emergency_contact_name`  VARCHAR(80)  DEFAULT NULL,
    `emergency_contact_phone` VARCHAR(20)  DEFAULT NULL,
    `registration_date`       DATE NOT NULL,
    `status`                  ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_patient_user` (`user_id`),
    KEY `idx_patient_status` (`status`),
    KEY `idx_patient_name` (`last_name`,`first_name`),
    CONSTRAINT `fk_patients_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. doctor_availability  (weekly recurring working hours)
--    day_of_week follows ISO-8601 : 1 = Monday ... 7 = Sunday
-- ---------------------------------------------------------------------
CREATE TABLE `doctor_availability` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doctor_id`     INT UNSIGNED NOT NULL,
    `day_of_week`   TINYINT UNSIGNED NOT NULL COMMENT '1=Mon ... 7=Sun',
    `start_time`    TIME NOT NULL,
    `end_time`      TIME NOT NULL,
    `slot_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'minutes',
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_avail_slot` (`doctor_id`,`day_of_week`,`start_time`),
    KEY `idx_avail_doctor_day` (`doctor_id`,`day_of_week`),
    CONSTRAINT `fk_avail_doctor`
        FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. doctor_time_off  (leave / holidays - blocks booking)
-- ---------------------------------------------------------------------
CREATE TABLE `doctor_time_off` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doctor_id`  INT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date`   DATE NOT NULL,
    `reason`     ENUM('Vacation','Personal Leave','Conference','Holiday','Emergency','Other')
                 NOT NULL DEFAULT 'Personal Leave',
    `notes`      VARCHAR(200) DEFAULT NULL,
    `status`     ENUM('approved','cancelled') NOT NULL DEFAULT 'approved',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_timeoff_doctor_range` (`doctor_id`,`start_date`,`end_date`),
    CONSTRAINT `fk_timeoff_doctor`
        FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. appointments
--    ON DELETE RESTRICT on patient/doctor => historical records can never
--    be silently destroyed by deleting a person.
-- ---------------------------------------------------------------------
CREATE TABLE `appointments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`       INT UNSIGNED NOT NULL,
    `doctor_id`        INT UNSIGNED NOT NULL,
    `clinic_id`        INT UNSIGNED DEFAULT NULL,
    `appointment_date` DATE NOT NULL,
    `start_time`       TIME NOT NULL,
    `end_time`         TIME NOT NULL,
    `status`           ENUM('pending','confirmed','completed','cancelled','no_show')
                       NOT NULL DEFAULT 'pending',
    `reason`           VARCHAR(200) DEFAULT NULL,
    `notes`            TEXT DEFAULT NULL,
    `consultation_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00
                       COMMENT 'Fee captured at booking time (historic value)',
    `payment_status`   ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_appt_doctor_slot`  (`doctor_id`,`appointment_date`,`start_time`),
    KEY `idx_appt_patient_date` (`patient_id`,`appointment_date`),
    KEY `idx_appt_status`       (`status`),
    KEY `idx_appt_clinic`       (`clinic_id`),
    CONSTRAINT `fk_appt_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_appt_doctor`
        FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_appt_clinic`
        FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. medicines  (a medicine belongs to ONE patient; may be self-added
--     by the patient (prescribed_by = NULL) or prescribed by a doctor)
-- ---------------------------------------------------------------------
CREATE TABLE `medicines` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`    INT UNSIGNED NOT NULL,
    `prescribed_by` INT UNSIGNED DEFAULT NULL COMMENT 'doctors.id, NULL = self added',
    `medicine_name` VARCHAR(120) NOT NULL,
    `generic_name`  VARCHAR(120) DEFAULT NULL,
    `category`      VARCHAR(60)  DEFAULT NULL,
    `dosage`        VARCHAR(30)  NOT NULL COMMENT 'numeric/text part e.g. 500',
    `dose_unit`     ENUM('mg','g','ml','tablet','capsule','drop','puff','unit','IU','other')
                    NOT NULL DEFAULT 'mg',
    `quantity`      VARCHAR(30) DEFAULT NULL COMMENT 'e.g. 1 tablet per dose',
    `frequency`     ENUM('Once Daily','Twice Daily','Three Times Daily','Four Times Daily',
                         'Every 4 Hours','Every 6 Hours','Every 8 Hours','Every 12 Hours','As Needed')
                    NOT NULL DEFAULT 'Once Daily',
    `route`         ENUM('Oral','Injection','Topical','Inhalation','Sublingual','Other')
                    NOT NULL DEFAULT 'Oral',
    `start_date`    DATE NOT NULL,
    `end_date`      DATE DEFAULT NULL COMMENT 'NULL = ongoing',
    `instructions`  VARCHAR(200) DEFAULT NULL,
    `notes`         TEXT DEFAULT NULL,
    `status`        ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_med_patient_status` (`patient_id`,`status`),
    KEY `idx_med_doctor` (`prescribed_by`),
    KEY `idx_med_dates` (`start_date`,`end_date`),
    CONSTRAINT `fk_med_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_med_doctor`
        FOREIGN KEY (`prescribed_by`) REFERENCES `doctors` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. medicine_reminders
--     1NF: one row per reminder time (never "08:00,14:00,20:00" in a column)
--     reminder_date NULL  = repeats every day inside the medicine period
--     reminder_date SET   = one-off reminder for that single date
-- ---------------------------------------------------------------------
CREATE TABLE `medicine_reminders` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `medicine_id`   INT UNSIGNED NOT NULL,
    `reminder_time` TIME NOT NULL,
    `reminder_date` DATE DEFAULT NULL COMMENT 'NULL = daily recurring',
    `message`       VARCHAR(200) DEFAULT NULL,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rem_medicine` (`medicine_id`,`status`),
    KEY `idx_rem_time` (`reminder_time`),
    CONSTRAINT `fk_rem_medicine`
        FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. medicine_logs  (adherence history - one row per reminder OCCURRENCE)
--     UNIQUE(reminder_id, scheduled_at) guarantees a single log per dose.
--     A "pending" dose simply has NO row yet.
-- ---------------------------------------------------------------------
CREATE TABLE `medicine_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reminder_id`   INT UNSIGNED NOT NULL,
    `patient_id`    INT UNSIGNED NOT NULL,
    `scheduled_at`  DATETIME NOT NULL COMMENT 'date + reminder_time of this dose',
    `action`        ENUM('taken','missed','skipped','snoozed') NOT NULL,
    `action_time`   DATETIME NOT NULL,
    `snooze_until`  DATETIME DEFAULT NULL,
    `notes`         VARCHAR(200) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_log_occurrence` (`reminder_id`,`scheduled_at`),
    KEY `idx_log_patient_time` (`patient_id`,`scheduled_at`),
    KEY `idx_log_action` (`action`),
    CONSTRAINT `fk_log_reminder`
        FOREIGN KEY (`reminder_id`) REFERENCES `medicine_reminders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_log_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. prescriptions  (header) + 14. prescription_medicines (detail)
--     Classic 1:N header/detail split - resolves the M:N between a
--     prescription and the medicines it contains.
-- ---------------------------------------------------------------------
CREATE TABLE `prescriptions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`        INT UNSIGNED NOT NULL,
    `doctor_id`         INT UNSIGNED NOT NULL,
    `appointment_id`    INT UNSIGNED DEFAULT NULL,
    `prescription_date` DATE NOT NULL,
    `diagnosis`         VARCHAR(200) DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_presc_patient` (`patient_id`),
    KEY `idx_presc_doctor`  (`doctor_id`),
    KEY `idx_presc_appt`    (`appointment_id`),
    CONSTRAINT `fk_presc_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_presc_doctor`
        FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_presc_appt`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prescription_medicines` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prescription_id` INT UNSIGNED NOT NULL,
    `medicine_id`     INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_presc_med` (`prescription_id`,`medicine_id`),
    KEY `idx_pm_medicine` (`medicine_id`),
    CONSTRAINT `fk_pm_prescription`
        FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pm_medicine`
        FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. notifications (in-app notification centre)
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `title`             VARCHAR(120) NOT NULL,
    `message`           VARCHAR(255) NOT NULL,
    `notification_type` ENUM('appointment','medicine','prescription','system')
                        NOT NULL DEFAULT 'system',
    `link`              VARCHAR(150) DEFAULT NULL,
    `is_read`           TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_user_read` (`user_id`,`is_read`),
    CONSTRAINT `fk_notif_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Reporting view (demonstrates JOIN based retrieval instead of
-- duplicating doctor / patient / clinic columns inside appointments)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_appointment_details` AS
SELECT  a.id,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.status,
        a.consultation_fee,
        a.payment_status,
        CONCAT(p.first_name,' ',p.last_name) AS patient_name,
        p.phone                              AS patient_phone,
        CONCAT('Dr. ',d.first_name,' ',d.last_name) AS doctor_name,
        s.specialization_name,
        c.clinic_name
FROM appointments a
JOIN patients p            ON p.id = a.patient_id
JOIN doctors  d            ON d.id = a.doctor_id
LEFT JOIN specializations s ON s.id = d.specialization_id
LEFT JOIN clinics c        ON c.id = a.clinic_id;

-- =====================================================================
-- End of schema.  Next: import database/seed.sql
-- =====================================================================
