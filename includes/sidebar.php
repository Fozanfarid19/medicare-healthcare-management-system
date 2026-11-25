<?php
/**
 * MediCare - includes/sidebar.php
 * Role aware navigation.  $active holds the key of the current page.
 */
if (!defined('BASE_URL')) { exit('Direct access is not allowed.'); }

$active = $active ?? '';

/** Renders one sidebar link. */
function sb_link($key, $href, $label, $iconName, $badge = null)
{
    global $active;
    $cls = 'sb-link' . ($active === $key ? ' active' : '');
    echo '<a class="' . $cls . '" href="' . e(url($href)) . '">' . icon($iconName)
       . '<span>' . e($label) . '</span>'
       . ($badge ? '<span class="sb-badge">' . e($badge) . '</span>' : '')
       . '</a>';
}

$role = current_role();

// --- small live counters shown as badges ------------------------------
$badgePending = 0;
if ($role === 'admin') {
    $badgePending = (int) db_scalar("SELECT COUNT(*) FROM appointments WHERE status = 'pending'");
} elseif ($role === 'doctor') {
    $badgePending = (int) db_scalar(
        "SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'pending'",
        [current_doctor_id()]
    );
} elseif ($role === 'patient') {
    $badgePending = (int) db_scalar(
        "SELECT COUNT(*) FROM appointments
          WHERE patient_id = ? AND status IN ('pending','confirmed') AND appointment_date >= ?",
        [current_patient_id(), date('Y-m-d')]
    );
}
?>
<aside class="app-sidebar" id="appSidebar">

<?php if ($role === 'admin'): ?>
    <div class="sb-title">Overview</div>
    <?php sb_link('dashboard', 'admin/dashboard.php', 'Dashboard', 'dashboard'); ?>

    <div class="sb-title">User Management</div>
    <?php
        sb_link('users',    'admin/users.php',    'All Users', 'users');
        sb_link('doctors',  'admin/doctors.php',  'Doctors',   'doctor');
        sb_link('patients', 'admin/patients.php', 'Patients',  'patient');
    ?>

    <div class="sb-title">Healthcare</div>
    <?php
        sb_link('clinics',      'admin/clinics.php',         'Clinics',         'clinic');
        sb_link('specializations', 'admin/specializations.php', 'Specializations', 'stethoscope');
        sb_link('appointments', 'admin/appointments.php',    'Appointments',    'calendar', $badgePending ?: null);
        sb_link('medicines',    'admin/medicines.php',       'Medicines',       'pill');
    ?>

    <div class="sb-title">Analysis</div>
    <?php
        sb_link('reports',       'admin/reports.php',       'Reports',       'report');
        sb_link('notifications', 'admin/notifications.php', 'Notifications', 'bell');
    ?>

<?php elseif ($role === 'doctor'): ?>
    <div class="sb-title">Overview</div>
    <?php sb_link('dashboard', 'doctor/dashboard.php', 'Dashboard', 'dashboard'); ?>

    <div class="sb-title">Practice</div>
    <?php
        sb_link('appointments',  'doctor/appointments.php',  'Appointments',  'calendar', $badgePending ?: null);
        sb_link('patients',      'doctor/patients.php',      'My Patients',   'patient');
        sb_link('prescriptions', 'doctor/prescriptions.php', 'Prescriptions', 'prescription');
        sb_link('medicines',     'doctor/medicines.php',     'Medicines',     'pill');
    ?>

    <div class="sb-title">Schedule</div>
    <?php
        sb_link('availability', 'doctor/availability.php', 'Availability', 'clock');
        sb_link('time_off',     'doctor/time_off.php',     'Time Off',     'calendar-check');
    ?>

    <div class="sb-title">Account</div>
    <?php
        sb_link('reports', 'doctor/reports.php', 'Reports',    'report');
        sb_link('profile', 'doctor/profile.php', 'My Profile', 'user');
    ?>

<?php elseif ($role === 'patient'): ?>
    <div class="sb-title">Overview</div>
    <?php sb_link('dashboard', 'patient/dashboard.php', 'Dashboard', 'dashboard'); ?>

    <div class="sb-title">My Medication</div>
    <?php
        sb_link('medicines', 'patient/medicines.php', 'My Medicines',    'pill');
        sb_link('reminders', 'patient/reminders.php', 'Reminders',       'bell');
        sb_link('history',   'patient/history.php',   'Medicine History','history');
    ?>

    <div class="sb-title">Care</div>
    <?php
        sb_link('doctors',      'patient/doctors.php',      'Find a Doctor', 'doctor');
        sb_link('appointments', 'patient/appointments.php', 'Appointments',  'calendar', $badgePending ?: null);
    ?>

    <div class="sb-title">Account</div>
    <?php sb_link('profile', 'patient/profile.php', 'My Profile', 'user'); ?>
<?php endif; ?>

    <div class="sb-title">&nbsp;</div>
    <?php sb_link('logout', 'logout.php', 'Logout', 'logout'); ?>

    <div class="sb-foot">
        <?= e(APP_NAME) ?> v1.0<br>
        Offline XAMPP build<br>
        <?= e(date('d M Y')) ?>
    </div>
</aside>
