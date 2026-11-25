<?php
/**
 * =====================================================================
 *  MediCare - includes/auth.php
 *  Session handling, authentication, role based access control (RBAC)
 *  and CSRF protection.
 *
 *  EVERY protected page starts with:
 *      require_once __DIR__ . '/../includes/auth.php';
 *      require_role('admin');            // or 'doctor' / 'patient'
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// SESSION START (hardened cookie settings)
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name('MEDICARE_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL,
        'httponly' => true,           // JavaScript cannot read the session cookie
        'samesite' => 'Lax',          // basic CSRF hardening
    ]);
    session_start();
}

/** Idle timeout: 60 minutes. */
define('SESSION_IDLE_LIMIT', 60 * 60);

if (isset($_SESSION['user']) && isset($_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > SESSION_IDLE_LIMIT) {
    logout_user();
    flash_warning('Your session expired because of inactivity. Please sign in again.');
    redirect('login.php');
}
$_SESSION['last_activity'] = time();

// ---------------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------------

/**
 * Verifies credentials and (on success) creates the session.
 *
 * @return array{ok:bool, message:string, role:string}
 */
function attempt_login($email, $password, $expectedRole = '')
{
    $user = db_row(
        'SELECT u.id, u.email, u.password, u.status, r.role_name
           FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE u.email = ?',
        [$email]
    );

    // Same generic message for "no such user" and "wrong password"
    // so the login form cannot be used to discover valid e-mail addresses.
    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'message' => 'Invalid login credentials.', 'role' => ''];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'message' => 'This account has been deactivated. Please contact the administrator.', 'role' => ''];
    }

    if ($expectedRole !== '' && $user['role_name'] !== $expectedRole) {
        return ['ok' => false, 'message' => 'These credentials are not valid for the selected role.', 'role' => ''];
    }

    // The profile row also has to be active (a doctor/patient can be
    // deactivated without deleting the login account).
    $profileId = null;
    $fullName  = 'Administrator';

    if ($user['role_name'] === 'doctor') {
        $d = db_row('SELECT id, first_name, last_name, status FROM doctors WHERE user_id = ?', [(int) $user['id']]);
        if (!$d)                       { return ['ok' => false, 'message' => 'Doctor profile not found. Please contact the administrator.', 'role' => '']; }
        if ($d['status'] !== 'active') { return ['ok' => false, 'message' => 'This doctor profile is inactive.', 'role' => '']; }
        $profileId = (int) $d['id'];
        $fullName  = 'Dr. ' . $d['first_name'] . ' ' . $d['last_name'];
    } elseif ($user['role_name'] === 'patient') {
        $p = db_row('SELECT id, first_name, last_name, status FROM patients WHERE user_id = ?', [(int) $user['id']]);
        if (!$p)                       { return ['ok' => false, 'message' => 'Patient profile not found. Please contact the administrator.', 'role' => '']; }
        if ($p['status'] !== 'active') { return ['ok' => false, 'message' => 'This patient profile is inactive.', 'role' => '']; }
        $profileId = (int) $p['id'];
        $fullName  = $p['first_name'] . ' ' . $p['last_name'];
    }

    // Session fixation protection.
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'         => (int) $user['id'],
        'email'      => $user['email'],
        'role'       => $user['role_name'],
        'name'       => $fullName,
        'profile_id' => $profileId,
    ];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']); // fresh token for the new session

    db_execute('UPDATE users SET last_login = ? WHERE id = ?',
               [date('Y-m-d H:i:s'), (int) $user['id']]);

    return ['ok' => true, 'message' => 'Welcome back, ' . $fullName . '!', 'role' => $user['role_name']];
}

/** Destroys the session completely. */
function logout_user()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------
// CURRENT USER HELPERS
// ---------------------------------------------------------------------

function is_logged_in()   { return isset($_SESSION['user']['id']); }
function current_user()   { return $_SESSION['user'] ?? null; }
function current_user_id(){ return (int) ($_SESSION['user']['id'] ?? 0); }
function current_role()   { return $_SESSION['user']['role'] ?? ''; }
function current_name()   { return $_SESSION['user']['name'] ?? ''; }

/** doctors.id for a logged in doctor (0 otherwise). */
function current_doctor_id()
{
    return current_role() === 'doctor' ? (int) ($_SESSION['user']['profile_id'] ?? 0) : 0;
}

/** patients.id for a logged in patient (0 otherwise). */
function current_patient_id()
{
    return current_role() === 'patient' ? (int) ($_SESSION['user']['profile_id'] ?? 0) : 0;
}

function is_role($role) { return current_role() === $role; }

/** Landing page for each role. */
function dashboard_url($role = null)
{
    switch ($role ?: current_role()) {
        case 'admin':   return 'admin/dashboard.php';
        case 'doctor':  return 'doctor/dashboard.php';
        case 'patient': return 'patient/dashboard.php';
        default:        return 'index.php';
    }
}

// ---------------------------------------------------------------------
// ACCESS CONTROL
// ---------------------------------------------------------------------

/** Blocks guests. */
function require_login()
{
    if (!is_logged_in()) {
        flash_warning('Please sign in to continue.');
        redirect('login.php');
    }
}

/**
 * Blocks anybody whose role is not in the allowed list.
 * A patient typing /admin/dashboard.php is bounced to their OWN dashboard.
 */
function require_role($roles)
{
    require_login();
    $allowed = is_array($roles) ? $roles : [$roles];

    if (!in_array(current_role(), $allowed, true)) {
        flash_error('Access denied: you are not authorised to open that page.');
        redirect(dashboard_url());
    }
}

/** Same rule for AJAX endpoints - answers with JSON instead of redirecting. */
function require_role_api($roles)
{
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!is_logged_in()) {
        json_response(['success' => false, 'message' => 'Not authenticated.'], 401);
    }
    if (!in_array(current_role(), $allowed, true)) {
        json_response(['success' => false, 'message' => 'Access denied.'], 403);
    }
}

// ---------------------------------------------------------------------
// CSRF PROTECTION
// ---------------------------------------------------------------------

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input placed inside EVERY POST form. */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid($token = null)
{
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    return !empty($_SESSION['csrf_token']) && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verifies the CSRF token of a POST request.
 * On failure the user is sent back with an error message.
 */
function verify_csrf($redirectTo = null)
{
    if (!csrf_valid()) {
        flash_error('Security check failed (invalid session token). Please try again.');
        redirect($redirectTo ?: dashboard_url());
    }
}

function verify_csrf_api()
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_valid($token)) {
        json_response(['success' => false, 'message' => 'Invalid security token.'], 419);
    }
}

// ---------------------------------------------------------------------
// PASSWORD HASHING (never store plain text)
// ---------------------------------------------------------------------

function hash_password($plain)
{
    return password_hash($plain, PASSWORD_DEFAULT);
}
