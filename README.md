# MediCare — Medicine Reminder & Healthcare Management System

A complete, **fully offline** healthcare management web application built with
**PHP 8 (MySQLi) · MySQL/MariaDB · HTML5 · CSS3 · JavaScript · Bootstrap 5 conventions**,
designed to run on **XAMPP** with no internet connection, no CDN, no Composer and no
external service of any kind.

Three roles (Administrator, Doctor, Patient), a live medicine-reminder engine, an
appointment system with real slot validation, prescriptions, clinics, reports and a
normalised 15-table relational database.

---

## Table of contents

1. [Requirements](#1-requirements)
2. [Installation (XAMPP)](#2-installation-xampp)
3. [Demo accounts](#3-demo-accounts)
4. [Feature list](#4-feature-list)
5. [System architecture](#5-system-architecture)
6. [Database design & relationships](#6-database-design--relationships)
7. [Normalisation explained (1NF → 3NF)](#7-normalisation-explained-1nf--3nf)
8. [How the medicine reminder engine works](#8-how-the-medicine-reminder-engine-works)
9. [How appointment slots are validated](#9-how-appointment-slots-are-validated)
10. [Security features](#10-security-features)
11. [Project structure](#11-project-structure)
12. [Testing instructions](#12-testing-instructions)
13. [Demonstration script for the invigilator](#13-demonstration-script-for-the-invigilator)
14. [Offline operation & optional Bootstrap files](#14-offline-operation--optional-bootstrap-files)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Requirements

| Component | Version |
|---|---|
| XAMPP | 8.x (any recent build) |
| Apache | 2.4 |
| PHP | 8.0 or newer (7.4 also works) |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| Browser | Any modern browser (Chrome, Edge, Firefox) |

Required PHP extensions: `mysqli`, `session`, `mbstring`, `json` — **all enabled by default in XAMPP.**

---

## 2. Installation (XAMPP)

1. **Install XAMPP** and open the XAMPP Control Panel.
2. **Start Apache** and **Start MySQL**.
3. **Copy the project folder** `medicare` into the XAMPP web root:
   * Windows: `C:\xampp\htdocs\medicare`
   * macOS: `/Applications/XAMPP/htdocs/medicare`
   * Linux: `/opt/lampp/htdocs/medicare`
4. Open **phpMyAdmin**: <http://localhost/phpmyadmin>
5. Click the **Import** tab → **Choose File** → select `database/schema.sql` → **Go**.
   *(This file creates the database `medicare_db`, all 15 tables, the foreign keys,
   the indexes and one reporting view. You do not need to create the database first.)*
6. Import the demo data: **Import** → select `database/seed.sql` → **Go**.
   Make sure `medicare_db` is selected in the left panel first, or simply import it —
   the file starts with `USE medicare_db;`.
7. **Check the database settings** in `config/database.php`. The XAMPP defaults are
   already set:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');          // XAMPP's MySQL root password is empty by default
   define('DB_NAME', 'medicare_db');
   define('APP_TIMEZONE', 'America/New_York');   // <- change to your own timezone
   ```

8. Open the application: **<http://localhost/medicare/>**

> **Tip:** if you put the folder somewhere else inside `htdocs` (for example
> `htdocs/projects/medicare`), nothing breaks — `BASE_URL` is calculated automatically
> in `config/database.php`.

### Command-line alternative to steps 4–6

```bash
cd C:\xampp\mysql\bin
mysql -u root < C:\xampp\htdocs\medicare\database\schema.sql
mysql -u root < C:\xampp\htdocs\medicare\database\seed.sql
```

---

## 3. Demo accounts

| Role | Email | Password |
|---|---|---|
| **Administrator** | `admin@medicare.local` | `Admin@123` |
| **Doctor** | `doctor@medicare.local` | `Doctor@123` |
| **Patient** | `patient@medicare.local` | `Patient@123` |

Additional seeded accounts (same passwords as their role above):

| Role | Email |
|---|---|
| Doctor | `priya.sharma@medicare.local` (Dermatologist) |
| Doctor | `david.chen@medicare.local` (Pediatrician) |
| Patient | `maria.garcia@medicare.local` |
| Patient | `ahmed.khan@medicare.local` |
| Patient | `linda.owusu@medicare.local` |
| Patient | `tom.becker@medicare.local` |

**Passwords are never stored as plain text.** `seed.sql` contains BCRYPT hashes
generated with PHP's `password_hash()`; login uses `password_verify()`.
You can confirm this in phpMyAdmin — the `users.password` column shows `$2y$10$...`.

The **role selector on the login page** is enforced server-side: signing in as a
patient while "Administrator" is selected is rejected.

---

## 4. Feature list

### Administrator
- Dashboard with 11 live statistics and 3 charts (appointments per month, appointment
  status doughnut, doctors per specialization)
- **User management** — create administrators, activate/deactivate accounts, reset any
  password, delete (blocked with a friendly message when historical records exist),
  search, filter by role and status
- **Doctor management** — register a doctor (creates the login account *and* the doctor
  profile in one transaction), edit, deactivate, search, filter by specialization/clinic,
  full doctor profile page with availability, time off, appointments, patients and a
  "change consultation fee" form
- **Patient management** — register, edit, deactivate, search, filter by blood group and
  gender, full patient record with appointments, medicines, reminder times, adherence
  and the dose log
- **Clinic management** — add/edit/deactivate clinics, assign doctors to clinics
- **Specializations** — full CRUD with a doctor-count column
- **Appointments** — filter by status/doctor/clinic/date range, change status, mark the
  consultation fee as paid, and book an appointment on behalf of a patient
- **Medicines** — every medicine in the system with its reminder times and dose log
- **Reports** — patient registrations, doctors, appointments, medicines, medicine
  adherence and appointment status; date filtering, charts and a print-friendly layout
- **Notifications** — inbox plus a broadcast tool (all patients / all doctors / one user)

### Doctor
- Dashboard: patient count, today's schedule, pending requests, charts
- Profile editing and password change
- **My patients** — only patients linked to this doctor through an appointment or a
  prescription (enforced in SQL)
- **Patient record** — history, current and previous medicines, prescriptions,
  adherence statistics and the dose log
- **Appointments** — Today / Upcoming / Past views, confirm, complete, cancel, no-show,
  mark paid, save consultation notes
- **Availability** — weekly working blocks with slot duration, overlap detection and a
  live "preview the generated slots for a date" panel
- **Time off** — leave records that immediately block patient bookings; creating leave
  is refused if appointments already exist in that range (they are listed)
- **Prescriptions** — multi-medicine prescriptions with dosage, unit, quantity,
  frequency, route, dates, instructions **and reminder times** (auto-suggested from the
  chosen frequency); the patient sees them instantly
- **Medicines** — edit, discontinue or reactivate anything this doctor prescribed
- **Reports** — appointments by status, patients seen, prescriptions, medicines by
  category, revenue split by paid/pending

### Patient
- Dashboard: live clock, dose counters, **Today's Medicine Schedule** table, next
  reminder, upcoming and previous appointments, assigned doctors
- **Medicine reminders** — pop-up alert when a dose is due with **Taken / Snooze / Skip**;
  every action is written to `medicine_logs`
- **My medicines** — add personal medicines with reminder times; for doctor-prescribed
  medicines only the reminder times can be changed (enforced server-side)
- **Reminders page** — due now, today's schedule, and per-medicine reminder-time editing
- **Medicine history** — filters (today, yesterday, this week, this month, custom range),
  status and medicine filters, adherence percentage, per-medicine breakdown, print view
- **Find a doctor** — search by name, specialization, clinic and city; see qualification,
  experience, clinic and **consultation fee**
- **Book an appointment** — pick a doctor and a date, real available slots are loaded
  from the server, book, then cancel if needed
- Profile, e-mail change and password change

### Public
- Homepage with hero, about, features, live database counters, featured doctors,
  contact section, and Login / Register calls to action
- Patient self-registration with full validation and duplicate e-mail prevention
- Login with role selection · secure logout

---

## 5. System architecture

```
                 ┌──────────────────────────────────────────┐
   BROWSER       │  HTML5 + CSS3 + JavaScript (Bootstrap 5) │
                 │  app.js · reminders.js · charts.js       │
                 └───────────────┬──────────────────────────┘
                                 │ HTTP (localhost) + AJAX (fetch)
                 ┌───────────────▼──────────────────────────┐
   APACHE / PHP  │  Presentation : *.php pages per role      │
                 │  ─────────────────────────────────────    │
                 │  Security     : includes/auth.php         │
                 │                 (sessions, RBAC, CSRF)    │
                 │  Domain logic : includes/medicine.php     │
                 │                 includes/appointments.php │
                 │  Helpers      : includes/functions.php    │
                 │  Data access  : config/database.php       │
                 │                 (MySQLi prepared stmts)   │
                 └───────────────┬──────────────────────────┘
                                 │ MySQLi
                 ┌───────────────▼──────────────────────────┐
   MySQL         │  medicare_db — 15 normalised tables      │
                 │  foreign keys · indexes · constraints    │
                 └──────────────────────────────────────────┘
```

**Layered design.** No page ever talks to MySQL directly. Every page includes
`includes/auth.php`, which pulls in `includes/functions.php` → `config/database.php`.
All database access goes through six helper functions — `db_all()`, `db_row()`,
`db_scalar()`, `db_execute()`, `db_insert()`, `db_exists()` — each of which builds a
**prepared statement**, binds the parameters with the correct types and returns plain
PHP arrays. This means:

* SQL injection is structurally impossible — user input is never concatenated into SQL;
* the database credentials exist in exactly one file;
* every page follows the same **Post/Redirect/Get** pattern: POST → validate → write →
  `flash_success()` → `redirect()`, so refreshing a page never repeats an action.

**Request flow of a typical action** (patient books an appointment):

```
patient/appointments.php  (POST action=book, CSRF token)
   → verify_csrf()                     includes/auth.php
   → require_role('patient')           includes/auth.php
   → book_appointment(...)             includes/appointments.php
        ├── validates doctor, clinic, date, availability, time off
        ├── db_begin()  … SELECT … FOR UPDATE … db_insert() … db_commit()
        └── notify() the doctor and the patient
   → flash message → redirect (PRG)
```

---

## 6. Database design & relationships

Database name: **`medicare_db`** · Engine: **InnoDB** · Charset: **utf8mb4**

```
        roles ──1:N──► users ──1:1──► doctors ──N:1──► clinics
                         │              │   │           ▲
                         │              │   └─N:1─► specializations
                         │              │
                         │              ├─1:N──► doctor_availability
                         │              ├─1:N──► doctor_time_off
                         │              ├─1:N──► appointments ◄─N:1─┐
                         │              ├─1:N──► prescriptions ◄────┤
                         │              └─1:N──► medicines ◄────────┤
                         │                                          │
                         └──1:1──► patients ────────────────────────┘
                                       │
   medicines ─1:N─► medicine_reminders ─1:N─► medicine_logs
   prescriptions ─1:N─► prescription_medicines ─N:1─► medicines
   users ─1:N─► notifications
```

| Child table | Foreign key | Parent | ON DELETE | Why |
|---|---|---|---|---|
| users | role_id | roles | RESTRICT | a role in use cannot be removed |
| doctors | user_id | users | CASCADE | the profile belongs to the login account |
| doctors | clinic_id | clinics | SET NULL | deleting a clinic must not delete doctors |
| doctors | specialization_id | specializations | SET NULL | same reasoning |
| patients | user_id | users | CASCADE | profile belongs to the account |
| doctor_availability | doctor_id | doctors | CASCADE | owned sub-record |
| doctor_time_off | doctor_id | doctors | CASCADE | owned sub-record |
| appointments | patient_id / doctor_id | patients / doctors | **RESTRICT** | **medical history must never be destroyed silently** |
| appointments | clinic_id | clinics | SET NULL | keep the appointment, drop the clinic link |
| medicines | patient_id | patients | RESTRICT | protects the medication record |
| medicines | prescribed_by | doctors | SET NULL | the medicine survives the doctor leaving |
| medicine_reminders | medicine_id | medicines | CASCADE | a reminder has no meaning alone |
| medicine_logs | reminder_id | medicine_reminders | CASCADE | dose history follows its reminder |
| medicine_logs | patient_id | patients | RESTRICT | protects adherence history |
| prescriptions | patient_id / doctor_id | patients / doctors | RESTRICT | legal record |
| prescriptions | appointment_id | appointments | SET NULL | optional link |
| prescription_medicines | prescription_id / medicine_id | prescriptions / medicines | CASCADE | pure link table |
| notifications | user_id | users | CASCADE | personal data |

Because deletion is deliberately restricted on historical tables, the application
**deactivates** doctors, patients and medicines (`status = 'inactive'`) instead of
deleting them. This is the behaviour a real clinical system needs, and it is a good
point to explain during the demonstration.

**Other constraints**

* `UNIQUE` — `users.email`, `roles.role_name`, `doctors.license_number`,
  `doctors.user_id`, `patients.user_id`, `clinics.clinic_name`,
  `specializations.specialization_name`,
  `doctor_availability (doctor_id, day_of_week, start_time)`,
  `medicine_logs (reminder_id, scheduled_at)` ← guarantees one log row per dose,
  `prescription_medicines (prescription_id, medicine_id)`
* `NOT NULL` on every mandatory column, sensible `DEFAULT`s, `ENUM`s for fixed value sets
* Indexes on every foreign key plus the columns used for filtering
  (`appointments (doctor_id, appointment_date, start_time)`, `medicines (patient_id, status)`,
  `notifications (user_id, is_read)` …)
* A view, `vw_appointment_details`, demonstrates retrieving doctor/patient/clinic names
  through JOINs instead of duplicating them.

---

## 7. Normalisation explained (1NF → 3NF)

**First Normal Form — atomic values, no repeating groups.**
The obvious temptation is to store a medicine's reminder times as
`"08:00,14:00,20:00"` in one column. This project does not: each reminder time is a
row in `medicine_reminders`. That is why a medicine can have any number of reminders,
why each one can be switched off individually, and why each dose can be logged
separately.

**Second Normal Form — no partial dependency.**
Every table uses a single-column surrogate primary key (`id`), so no non-key column can
depend on part of a composite key. Link tables (`prescription_medicines`) hold only the
two foreign keys, not copies of the medicine's data.

**Third Normal Form — no transitive dependency.**
`appointments` stores `doctor_id`, `patient_id` and `clinic_id` — **never**
`doctor_name`, `doctor_phone`, `clinic_name` or `specialization_name`. Those values are
retrieved with JOINs (see `appointment_select_sql()` in `includes/appointments.php`).
Similarly `doctors` stores `specialization_id`, not the specialization text, so renaming
"Cardiologist" to "Cardiology" is a single-row update.

**One deliberate, defensible exception.**
`appointments.consultation_fee` stores the fee **at the moment of booking**. This is not
a redundant copy of `doctors.consultation_fee` — it is a historical fact about that
appointment. If the doctor raises the fee tomorrow, past invoices must not change.
(The automated test suite verifies exactly this: changing a doctor's fee leaves existing
appointment records untouched.) The same reasoning applies to `medicine_logs.scheduled_at`.

---

## 8. How the medicine reminder engine works

```
Browser (patient logged in)
   │  every 30 seconds  (assets/js/reminders.js)
   ▼
GET api/reminder_check.php?action=due
   │
   ├─ mark_missed_doses()   →  writes 'missed' rows for doses whose 60-minute
   │                            grace period expired (no cron job needed)
   ├─ due_reminders()       →  medicines × medicine_reminders × medicine_logs
   ▼
JSON  { due: [ {medicine, dose, time, instructions, scheduled_at} ] }
   │
   ▼
In-page modal:  "Medicine Reminder — time to take Amoxicillin 500 mg"
                [ Taken ]  [ Snooze ]  [ Skip ]
   │
   ▼
POST api/reminder_check.php   (action + reminder_id + scheduled_at + CSRF token)
   │
   ▼
medicine_logs  INSERT … ON DUPLICATE KEY UPDATE      →  MySQL row written
   │
   ▼
JSON response updates the dashboard counters and the schedule row without a reload
```

**Key design decisions worth explaining to an invigilator**

* A *dose occurrence* is the pair (reminder, date) → `scheduled_at`. The
  `UNIQUE (reminder_id, scheduled_at)` constraint guarantees exactly one log row per
  dose, so pressing "Taken" twice cannot create duplicates, and a snoozed dose that is
  later taken **updates** the same row.
* **Pending is the absence of a row.** No log row for a scheduled dose means it is still
  pending — a clean, normalised representation.
* **Missed is derived, then persisted.** When the grace period (`REMINDER_GRACE_MINUTES`,
  default 60) passes, the dashboard/history pages call `mark_missed_doses()` which writes
  real `missed` rows. This keeps the database truthful without requiring a scheduler.
* The alert is a real in-application dialog, so it works even if the browser blocks
  desktop notifications. The forms behind the buttons also work with JavaScript disabled.

**To demonstrate it live:** the seed file already inserts a reminder due **2 minutes
after the import**. You can also add one from *My Medicines* with a reminder time 1–2
minutes in the future, then stay on the dashboard and wait.

---

## 9. How appointment slots are validated

`doctor_available_slots()` and `book_appointment()` in `includes/appointments.php`
run this chain before anything is written:

1. the doctor exists and `status = 'active'`
2. the doctor's clinic is active
3. the date is valid and not in the past
4. the doctor has an active `doctor_availability` block for that weekday, and the slots
   are generated from `start_time`, `end_time` and `slot_duration`
5. the doctor has no approved `doctor_time_off` covering that date
6. no other live appointment (`pending`/`confirmed`/`completed`) occupies the slot
7. the patient has no clashing appointment of their own
8. slots earlier than "now" are removed when the date is today
9. the write happens inside a transaction with `SELECT … FOR UPDATE`, so two people
   clicking at the same instant cannot take the same slot
10. the **consultation fee is copied from the doctor** into the appointment row
11. both the doctor and the patient receive an in-app notification

Every failure produces a meaningful message, for example
*"This appointment slot is already booked. Please select another time."* or
*"The doctor is unavailable on the selected date (Vacation)."*

---

## 10. Security features

| Requirement | Implementation |
|---|---|
| Password storage | `password_hash()` (BCRYPT) — `includes/auth.php`, `hash_password()` |
| Login verification | `password_verify()`; identical error message for unknown e-mail and wrong password |
| SQL injection | 100 % MySQLi **prepared statements** via `config/database.php` |
| XSS | every echoed value passes through `e()` (`htmlspecialchars`, ENT_QUOTES) |
| CSRF | random 32-byte token in the session, `csrf_field()` in every POST form, `verify_csrf()` / `verify_csrf_api()` on every write |
| Session fixation | `session_regenerate_id(true)` on successful login |
| Session hardening | `HttpOnly` + `SameSite=Lax` cookie, 60-minute idle timeout |
| Authorisation | `require_role('admin'\|'doctor'\|'patient')` at the top of every page; API endpoints use `require_role_api()` |
| Horizontal access control | every doctor query is scoped by `current_doctor_id()`, every patient query by `current_patient_id()` — taken from the session, never from the request |
| Error handling | raw SQL errors are logged with `error_log()`, users see friendly messages (`APP_DEBUG = false`) |
| Secure logout | session data cleared, cookie expired, `session_destroy()` |

Typing `http://localhost/medicare/admin/dashboard.php` while logged in as a patient
redirects to the patient dashboard with *"Access denied: you are not authorised to open
that page."*

---

## 11. Project structure

```
medicare/
├── index.php                  Public homepage (live DB counters)
├── login.php                  Role-based login
├── register.php               Patient self-registration
├── logout.php                 Secure logout
├── README.md
│
├── config/
│   └── database.php           Credentials + MySQLi prepared-statement helpers
│
├── includes/
│   ├── auth.php               Sessions, login, RBAC, CSRF
│   ├── functions.php          Escaping, flash, validation, formatting, pagination, icons
│   ├── medicine.php           Reminder schedule, dose logging, adherence
│   ├── appointments.php       Slot generation, booking validation, status changes
│   ├── header.php             Page opening + asset loading
│   ├── navbar.php             Top bar, notification centre, user menu
│   ├── sidebar.php            Role-aware navigation
│   └── footer.php             Page closing + scripts
│
├── assets/
│   ├── css/style.css          Healthcare theme
│   ├── js/app.js              Sidebar, modals, confirmations, filters
│   ├── js/reminders.js        The reminder polling engine
│   ├── js/charts.js           Local canvas charts (bar / line / doughnut)
│   ├── images/
│   └── vendor/
│       ├── bootstrap-lite/    Offline Bootstrap-5-compatible CSS + JS (bundled)
│       └── bootstrap/         Drop the official Bootstrap 5 dist files here (optional)
│
├── admin/     dashboard, users, doctors, doctor_view, patients, patient_view,
│              clinics, specializations, appointments, medicines, reports,
│              notifications, profile
│
├── doctor/    dashboard, profile, patients, patient_record, appointments,
│              availability, time_off, prescriptions, medicines, reports
│
├── patient/   dashboard, profile, doctors, doctor_view, appointments,
│              medicines, reminders, history
│
├── api/
│   ├── reminder_check.php     Due reminders + Taken/Snooze/Skip (AJAX)
│   ├── appointment_slots.php  Available slots for a doctor and date (AJAX)
│   └── notifications.php      Notification list / mark read
│
└── database/
    ├── schema.sql             Database, 15 tables, FKs, indexes, view
    └── seed.sql               Demo data with BCRYPT passwords
```

---

## 12. Testing instructions

### 12.1 Quick checklist (all verified)

| # | Test | Expected result |
|---|---|---|
| 1 | Admin / Doctor / Patient login | lands on the matching dashboard |
| 2 | Wrong password | "Invalid login credentials." |
| 3 | Correct password, wrong role selected | "These credentials are not valid for the selected role." |
| 4 | Patient opens `/admin/dashboard.php` | redirected with "Access denied" |
| 5 | Patient registration | new rows in `users` + `patients`, password is a `$2y$` hash |
| 6 | Duplicate registration e-mail | "This email address is already registered." |
| 7 | Admin adds a doctor | new rows in `users` **and** `doctors` (one transaction) |
| 8 | Admin adds a duplicate licence/e-mail | rejected with a friendly message |
| 9 | Admin adds a clinic / specialization / patient | row inserted, list refreshes |
| 10 | Admin changes a consultation fee | `doctors.consultation_fee` changes, past appointments keep their old fee |
| 11 | Doctor adds an availability block | row in `doctor_availability`; overlapping block rejected |
| 12 | Doctor adds time off | row in `doctor_time_off`; patients can no longer book those dates |
| 13 | Doctor creates a prescription | rows in `prescriptions`, `medicines`, `medicine_reminders`, `prescription_medicines` + notification to the patient |
| 14 | Doctor confirms / completes an appointment | `appointments.status` changes, patient notified |
| 15 | Doctor marks a fee as paid | `payment_status = 'paid'` |
| 16 | Doctor tries another doctor's appointment | ignored (ownership checked in SQL) |
| 17 | Patient books an appointment | row inserted with the doctor's current fee, status `pending` |
| 18 | Patient books the same slot twice | "This appointment slot is already booked." |
| 19 | Patient books during time off / in the past | rejected with an explanatory message |
| 20 | Patient cancels an appointment | status `cancelled`, doctor notified |
| 21 | Patient adds a medicine with reminder times | rows in `medicines` + `medicine_reminders` |
| 22 | Reminder becomes due | pop-up appears within 30 seconds |
| 23 | Taken / Snooze / Skip | row written to `medicine_logs`; snooze re-opens later; taking a snoozed dose updates the same row |
| 24 | Dose left for over an hour | automatically logged as `missed` |
| 25 | Medicine history filters | Today / Yesterday / This Week / This Month / Custom all filter correctly |
| 26 | Search & filters on every list | server-side filtering with prepared statements |
| 27 | POST without a CSRF token | rejected with "Security check failed" |
| 28 | Logout | session destroyed, protected pages redirect to login |

### 12.2 Verifying the database from phpMyAdmin

After performing an action in the UI, open phpMyAdmin and run, for example:

```sql
SELECT * FROM medicine_logs ORDER BY id DESC LIMIT 10;
SELECT * FROM appointments ORDER BY id DESC LIMIT 10;
SELECT id, email, LEFT(password, 7) AS hash_prefix, status FROM users;
```

Useful JOIN query to show that nothing is duplicated between tables:

```sql
SELECT a.id, a.appointment_date, a.start_time, a.status, a.consultation_fee,
       CONCAT(p.first_name,' ',p.last_name)      AS patient,
       CONCAT('Dr. ',d.first_name,' ',d.last_name) AS doctor,
       s.specialization_name, c.clinic_name
FROM appointments a
JOIN patients p            ON p.id = a.patient_id
JOIN doctors  d            ON d.id = a.doctor_id
LEFT JOIN specializations s ON s.id = d.specialization_id
LEFT JOIN clinics c        ON c.id = a.clinic_id
ORDER BY a.appointment_date DESC;
```

---

## 13. Demonstration script for the invigilator

**Demonstration 1 — Administrator**
1. Sign in as `admin@medicare.local`.
2. Show the dashboard counters and charts, and explain that each number comes from a
   `COUNT()` query (open `admin/dashboard.php` if asked).
3. *Doctors → Add Doctor* — create one; show the new rows in `users` and `doctors`.
4. *Patients → Add Patient* — create one.
5. *Clinics → Add Clinic*, then assign the new doctor to it.
6. Open the doctor's profile and change the **consultation fee**.
7. *Appointments* — show the records, filters and the "Mark as Paid" action.

**Demonstration 2 — Doctor**
1. Logout, sign in as `doctor@medicare.local`.
2. Dashboard → today's schedule and pending requests.
3. *My Patients* → open a patient record.
4. *Availability* → add a block, then use the preview panel to show the generated slots.
5. *Time Off* → add leave for a future date.
6. *Prescriptions → New prescription* → choose the patient, add a medicine with dosage,
   frequency, instructions **and reminder times** → save.
7. *Appointments* → confirm a pending appointment.

**Demonstration 3 — Patient**
1. Logout, sign in as `patient@medicare.local`.
2. Dashboard → the prescription created in Demonstration 2 is already visible in
   *Today's Medicine Schedule* with its dose and reminder time.
3. *Find a Doctor* → show specialization, clinic and consultation fee.
4. *Appointments* → pick a doctor and a date → load the available slots → book →
   show the fee and the payment status.
5. Try to book the same slot again → the system refuses.

**Demonstration 4 — Medicine reminder (the highlight)**
1. As the patient, open *My Medicines → Add Medicine* and set a reminder time
   1–2 minutes ahead (or use the reminder seeded to fire 2 minutes after import).
2. Go back to the dashboard and wait — the pop-up appears by itself.
3. Press **Taken**.
4. Open *Medicine History* → the dose is listed with its scheduled and actual time.
5. In phpMyAdmin run `SELECT * FROM medicine_logs ORDER BY id DESC LIMIT 5;` — the row
   is there.

**Demonstration 5 — Database**
1. Open phpMyAdmin → `medicare_db` → show the 15 tables.
2. Open `appointments` → *Structure* → *Relation view* to show the foreign keys.
3. Explain the normalisation points from section 7: reminder times in their own table
   (1NF), surrogate keys (2NF), no doctor/clinic names inside `appointments` (3NF), and
   the deliberate historical fee exception.
4. Run the JOIN query from section 12.2 to show how the names are retrieved.

---

## 14. Offline operation & optional Bootstrap files

The application makes **no network requests at all**. There is no Firebase, no cloud
database, no external API, no payment gateway and no CDN link. Everything —
stylesheet, JavaScript, icons and charts — is served from the `assets/` folder:

* `assets/vendor/bootstrap-lite/` — a self-contained stylesheet and script that implement
  the Bootstrap 5 class names and behaviours used by this project (grid, cards, tables,
  forms, buttons, badges, alerts, modals, dropdowns, navbar, tabs, pagination).
* `assets/js/charts.js` — a small canvas chart library written for this project
  (bar, line and doughnut), so the dashboards need no charting CDN.
* Icons are inline SVG (`icon()` in `includes/functions.php`) — no icon font to download.

**Optional: use the official Bootstrap 5 build.** If you prefer to ship the genuine
library, download Bootstrap 5 once and copy two files:

```
assets/vendor/bootstrap/bootstrap.min.css          (from bootstrap/dist/css/)
assets/vendor/bootstrap/bootstrap.bundle.min.js    (from bootstrap/dist/js/)
```

`includes/header.php` detects those files with `is_file()` and loads them **instead of**
the bundled ones — no code change is required, and the application stays completely
offline because the files are local.

Payment is **simulated** on purpose (a "Mark as Paid" action that sets
`appointments.payment_status`), because no online payment gateway may be used.

---

## 15. Troubleshooting

| Problem | Cause / fix |
|---|---|
| "Database error" page | MySQL is not running in XAMPP, or `schema.sql` was not imported. Start MySQL and import both SQL files. |
| `Access denied for user 'root'@'localhost'` | You set a MySQL password — put it in `DB_PASS` in `config/database.php`. |
| Page not found at `http://localhost/medicare/` | The folder is not inside `htdocs`, or Apache is not started. |
| Port 80 already in use | Change Apache's port in XAMPP (e.g. 8080) and use `http://localhost:8080/medicare/`. |
| Reminder pop-up does not appear | The reminder time must be in the past or now, the medicine must be `active`, the dose must not already be logged, and it must be within the 60-minute grace window (`REMINDER_GRACE_MINUTES`). Check that the clock and `APP_TIMEZONE` in `config/database.php` match your machine. |
| Times look shifted | Set `APP_TIMEZONE` in `config/database.php` to your timezone (the app also aligns MySQL's session timezone with PHP's automatically). |
| Need technical error messages while developing | Set `define('APP_DEBUG', true);` in `config/database.php`. Set it back to `false` before the demonstration. |
| Want to reset the demo data | Re-import `database/seed.sql` (it clears the tables first). |

---

**MediCare** — academic project. PHP · MySQL · HTML5 · CSS3 · JavaScript · Bootstrap 5 conventions.
Runs completely offline on XAMPP.
