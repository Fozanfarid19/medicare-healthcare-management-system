<?php
/**
 * MediCare - index.php
 * Public landing page (hero, about, features, doctors, contact).
 * The counters and the doctor list are read from MySQL, so this page
 * already proves that the backend is working.
 */
require_once __DIR__ . '/includes/auth.php';

$stats = [
    'doctors'   => (int) db_scalar("SELECT COUNT(*) FROM doctors  WHERE status = 'active'"),
    'patients'  => (int) db_scalar("SELECT COUNT(*) FROM patients WHERE status = 'active'"),
    'clinics'   => (int) db_scalar("SELECT COUNT(*) FROM clinics  WHERE status = 'active'"),
    'reminders' => (int) db_scalar("SELECT COUNT(*) FROM medicine_reminders WHERE status = 'active'"),
];

$featuredDoctors = db_all(
    "SELECT d.id, d.first_name, d.last_name, d.qualification, d.experience_years,
            d.consultation_fee, s.specialization_name, c.clinic_name, c.city
       FROM doctors d
       LEFT JOIN specializations s ON s.id = d.specialization_id
       LEFT JOIN clinics c         ON c.id = d.clinic_id
      WHERE d.status = 'active'
      ORDER BY d.experience_years DESC
      LIMIT 3"
);

$page_title = 'Home';
$layout     = 'public';
include __DIR__ . '/includes/header.php';
?>

<!-- ============================== HERO ============================== -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-7 mb-4">
                <span class="badge bg-success mb-3">100% Offline &middot; XAMPP &middot; PHP &amp; MySQL</span>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="mb-4"><?= e(APP_TAGLINE) ?> - never miss a dose again, book appointments
                   with the right specialist and keep your complete medical record in one place.</p>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-light btn-lg" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?> Login</a>
                    <a class="btn btn-success btn-lg" href="<?= e(url('register.php')) ?>"><?= icon('plus') ?> Register as Patient</a>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                <div class="hero-card">
                    <h5 class="text-white mb-3">Today at <?= e(APP_NAME) ?></h5>
                    <div class="hc-row"><?= icon('doctor') ?> <span><?= (int) $stats['doctors'] ?> registered doctors</span></div>
                    <div class="hc-row"><?= icon('patient') ?> <span><?= (int) $stats['patients'] ?> active patients</span></div>
                    <div class="hc-row"><?= icon('clinic') ?> <span><?= (int) $stats['clinics'] ?> partner clinics</span></div>
                    <div class="hc-row"><?= icon('bell') ?> <span><?= (int) $stats['reminders'] ?> active medicine reminders</span></div>
                    <p class="small mb-0 mt-3 opacity-75">These numbers are read live from the
                       <code>medicare_db</code> MySQL database.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================== ABOUT ============================= -->
<section class="section bg-white" id="about">
    <div class="container">
        <div class="section-title">
            <h2>About the System</h2>
            <p><?= e(APP_NAME) ?> is a complete healthcare management platform that connects
               administrators, doctors and patients. It combines a medicine reminder engine with
               appointment scheduling, prescriptions, clinic management and adherence reporting -
               all running locally on Apache, PHP and MySQL without any internet connection.</p>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('users') ?></div>
                    <h3>Three secure roles</h3>
                    <p>Administrator, Doctor and Patient each get their own dashboard, menu and
                       permissions, enforced by PHP sessions and role based access control.</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('lock') ?></div>
                    <h3>Security first</h3>
                    <p>Passwords are hashed with <code>password_hash()</code>, every query uses
                       MySQLi prepared statements and every form carries a CSRF token.</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('chart') ?></div>
                    <h3>Normalised database</h3>
                    <p>Fifteen related tables in third normal form with foreign keys, unique
                       constraints and indexes - no duplicated doctor or patient data anywhere.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================ FEATURES =========================== -->
<section class="section" id="features">
    <div class="container">
        <div class="section-title">
            <h2>Key Features</h2>
            <p>Everything an academic healthcare management project needs - and every button
               is connected to a real database operation.</p>
        </div>
        <div class="row">
            <div class="col-md-6 col-lg-3 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('bell') ?></div>
                    <h3>Medicine Reminders</h3>
                    <p>Multiple reminder times per medicine, a live pop-up when a dose is due and
                       Taken / Snooze / Skip actions saved into <code>medicine_logs</code>.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('calendar') ?></div>
                    <h3>Appointments</h3>
                    <p>Slots generated from each doctor's weekly availability, blocked by time off
                       and by existing bookings, with the consultation fee captured at booking.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('prescription') ?></div>
                    <h3>Prescriptions</h3>
                    <p>Doctors prescribe medicines with dosage, frequency, route and reminder
                       times; the patient sees them instantly on their dashboard.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3 mb-3">
                <div class="feature">
                    <div class="feature-ic"><?= icon('report') ?></div>
                    <h3>Reports</h3>
                    <p>Registration, appointment, medicine and adherence reports with date
                       filtering and a print friendly layout.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================= DOCTORS =========================== -->
<?php if ($featuredDoctors): ?>
<section class="section bg-white" id="doctors">
    <div class="container">
        <div class="section-title">
            <h2>Our Doctors</h2>
            <p>Register or sign in to view every specialist, their clinic, their availability
               and their consultation fee.</p>
        </div>
        <div class="row">
            <?php foreach ($featuredDoctors as $doc): ?>
                <div class="col-md-4 mb-3">
                    <div class="doc-card">
                        <div class="doc-top">
                            <div class="doc-av"><?= e(strtoupper(substr($doc['first_name'], 0, 1) . substr($doc['last_name'], 0, 1))) ?></div>
                            <div>
                                <div class="fw-bold">Dr. <?= e($doc['first_name'] . ' ' . $doc['last_name']) ?></div>
                                <div class="small text-muted"><?= e($doc['specialization_name'] ?: 'General Physician') ?></div>
                            </div>
                        </div>
                        <div class="kv"><span class="k">Qualification</span><span class="v"><?= e($doc['qualification'] ?: '-') ?></span></div>
                        <div class="kv"><span class="k">Experience</span><span class="v"><?= (int) $doc['experience_years'] ?> years</span></div>
                        <div class="kv"><span class="k">Clinic</span><span class="v"><?= e($doc['clinic_name'] ?: 'Not assigned') ?></span></div>
                        <div class="kv"><span class="k">Consultation Fee</span><span class="v doc-fee"><?= e(money($doc['consultation_fee'])) ?></span></div>
                        <a class="btn btn-outline-primary w-100 mt-3" href="<?= e(url('login.php')) ?>">Book an appointment</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========================== CALL TO ACTION ======================== -->
<section class="section" style="background:linear-gradient(135deg,#0f2745,#1266d4);color:#fff">
    <div class="container text-center">
        <h2 class="text-white">Ready to take control of your medication?</h2>
        <p class="mb-4" style="opacity:.9">Create a free patient account and start receiving
           reminders for every dose.</p>
        <a class="btn btn-light btn-lg me-2" href="<?= e(url('register.php')) ?>">Register as Patient</a>
        <a class="btn btn-outline-light btn-lg" href="<?= e(url('login.php')) ?>">Login</a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
