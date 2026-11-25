<?php
/**
 * =====================================================================
 *  MediCare - includes/functions.php
 *  Generic, reusable helper functions (output escaping, flash messages,
 *  formatting, pagination, notifications, drop-down option lists).
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------
// OUTPUT ESCAPING  (protection against XSS - used on EVERY echoed value)
// ---------------------------------------------------------------------

/** Escape a value for safe HTML output. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL from a path relative to the project root. */
function url($path = '')
{
    return BASE_URL . ltrim($path, '/');
}

/** Redirect to a project-relative path and stop execution. */
function redirect($path)
{
    if (!headers_sent()) {
        header('Location: ' . url($path));
    } else {
        echo '<script>window.location.href=' . json_encode(url($path)) . ';</script>';
    }
    exit;
}

/** Send a JSON response (used by the /api endpoints). */
function json_response(array $data, $httpCode = 200)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ---------------------------------------------------------------------
// REQUEST HELPERS
// ---------------------------------------------------------------------

function is_post()                { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function post($key, $default = '') { return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default; }
function post_int($key, $default = 0) { return isset($_POST[$key]) && $_POST[$key] !== '' ? (int) $_POST[$key] : $default; }
function post_raw($key, $default = '') { return isset($_POST[$key]) ? (string) $_POST[$key] : $default; }
function get_param($key, $default = '') { return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default; }
function get_int($key, $default = 0)  { return isset($_GET[$key]) && $_GET[$key] !== '' ? (int) $_GET[$key] : $default; }

/** Returns NULL for empty strings so optional columns stay NULL in MySQL. */
function null_if_blank($value)
{
    $value = is_string($value) ? trim($value) : $value;
    return ($value === '' || $value === null) ? null : $value;
}

// ---------------------------------------------------------------------
// FLASH MESSAGES  (survive one redirect, then disappear)
// ---------------------------------------------------------------------

/** @param string $type success|danger|warning|info */
function set_flash($type, $message)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_success($m) { set_flash('success', $m); }
function flash_error($m)   { set_flash('danger',  $m); }
function flash_warning($m) { set_flash('warning', $m); }
function flash_info($m)    { set_flash('info',    $m); }

/** Renders and clears every queued flash message. */
function render_flash()
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $f) {
        $icon = ($f['type'] === 'success') ? 'check-circle'
              : (($f['type'] === 'danger') ? 'alert-circle' : 'info');
        echo '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
           . icon($icon) . ' <span>' . $f['message'] . '</span>'
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
           . '</div>';
    }
    unset($_SESSION['flash']);
}

/** Remember submitted form values so a failed form can be re-filled. */
function keep_old(array $data)
{
    $_SESSION['old'] = $data;
}

function old($key, $default = '')
{
    return isset($_SESSION['old'][$key]) ? (string) $_SESSION['old'][$key] : $default;
}

function clear_old()
{
    unset($_SESSION['old']);
}

// ---------------------------------------------------------------------
// VALIDATION HELPERS
// ---------------------------------------------------------------------

function is_valid_email($email)
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_valid_phone($phone)
{
    return (bool) preg_match('/^[0-9+\-\s()]{7,20}$/', $phone);
}

function is_valid_date($date)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { return false; }
    [$y, $m, $d] = explode('-', $date);
    return checkdate((int) $m, (int) $d, (int) $y);
}

function is_valid_time($time)
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $time);
}

/**
 * Password policy: at least 6 characters, one letter and one digit.
 * @return string|null error message or null when valid
 */
function password_problem($password)
{
    if (strlen($password) < 6)            { return 'Password must be at least 6 characters long.'; }
    if (!preg_match('/[A-Za-z]/', $password)) { return 'Password must contain at least one letter.'; }
    if (!preg_match('/\d/', $password))       { return 'Password must contain at least one number.'; }
    return null;
}

// ---------------------------------------------------------------------
// FORMATTING
// ---------------------------------------------------------------------

function format_date($date, $format = 'd M Y')
{
    if (empty($date) || $date === '0000-00-00') { return '-'; }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

function format_time($time)
{
    if (empty($time)) { return '-'; }
    $ts = strtotime($time);
    return $ts ? date('h:i A', $ts) : '-';
}

function format_datetime($dt)
{
    if (empty($dt)) { return '-'; }
    $ts = strtotime($dt);
    return $ts ? date('d M Y, h:i A', $ts) : '-';
}

function money($amount)
{
    return APP_CURRENCY . number_format((float) $amount, 2);
}

function calculate_age($dob)
{
    if (empty($dob) || $dob === '0000-00-00') { return '-'; }
    try {
        $birth = new DateTime($dob);
        return (new DateTime('today'))->diff($birth)->y;
    } catch (Throwable $e) {
        return '-';
    }
}

/** Shorten long text for table cells. */
function str_limit($text, $limit = 60)
{
    $text = (string) $text;
    return (mb_strlen($text) > $limit) ? mb_substr($text, 0, $limit) . '...' : $text;
}

/** Human readable label for an ENUM style value. */
function nice_label($value)
{
    return ucwords(str_replace('_', ' ', (string) $value));
}

// ---------------------------------------------------------------------
// STATUS BADGES
// ---------------------------------------------------------------------

function badge_class($status)
{
    switch (strtolower((string) $status)) {
        case 'active':
        case 'confirmed':
        case 'paid':
        case 'taken':
        case 'approved':   return 'success';
        case 'pending':
        case 'snoozed':    return 'warning';
        case 'completed':  return 'primary';
        case 'cancelled':
        case 'missed':
        case 'inactive':
        case 'discontinued': return 'danger';
        case 'no_show':
        case 'skipped':    return 'secondary';
        default:           return 'secondary';
    }
}

function status_badge($status)
{
    return '<span class="badge bg-' . badge_class($status) . '">' . e(nice_label($status)) . '</span>';
}

// ---------------------------------------------------------------------
// ICONS  (inline SVG - no icon font, therefore 100% offline)
// ---------------------------------------------------------------------

function icon($name, $class = 'ic')
{
    $paths = [
        'dashboard'  => '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>',
        'users'      => '<path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
        'doctor'     => '<path d="M12 2a5 5 0 0 0-5 5v2a5 5 0 0 0 10 0V7a5 5 0 0 0-5-5zm-7 15.5C5 15 9 14 12 14s7 1 7 3.5V22H5v-4.5z"/>',
        'patient'    => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4 0-8 2-8 4.5V22h16v-3.5c0-2.5-4-4.5-8-4.5z"/>',
        'pill'       => '<path d="M4.2 12.9 12 5.1a4.5 4.5 0 0 1 6.4 6.4l-7.8 7.8a4.5 4.5 0 0 1-6.4-6.4zm2.1 2.1 4.6-4.6 3.7 3.7-4.6 4.6a2.6 2.6 0 0 1-3.7-3.7z"/>',
        'calendar'   => '<path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm12 8v10H5V10h14z"/>',
        'clock'      => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 10.6V6h-2v7.4l5.2 3.1 1-1.7-4.2-2.2z"/>',
        'clinic'     => '<path d="M12 2 2 8v14h7v-6h6v6h7V8L12 2zm-1 6h2v2h2v2h-2v2h-2v-2H9v-2h2V8z"/>',
        'report'     => '<path d="M6 2h9l5 5v15H6V2zm8 1.5V8h4.5L14 3.5zM8 12h8v2H8v-2zm0 4h8v2H8v-2z"/>',
        'bell'       => '<path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22zm7-6v-5a7 7 0 0 0-5.5-6.8V3.5a1.5 1.5 0 1 0-3 0v.7A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2z"/>',
        'logout'     => '<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v-2H5V6h5V4zm5.6 3.6L14.2 9l2 2H8v2h8.2l-2 2 1.4 1.4L20 12l-4.4-4.4z"/>',
        'plus'       => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5z"/>',
        'edit'       => '<path d="M3 17.2V21h3.8L17.8 10 14 6.2 3 17.2zM20.7 7.3a1 1 0 0 0 0-1.4l-2.6-2.6a1 1 0 0 0-1.4 0L15 5l3.8 3.8 1.9-1.5z"/>',
        'trash'      => '<path d="M6 7h12v14H6V7zm3-4h6l1 2h4v2H4V5h4l1-2z"/>',
        'eye'        => '<path d="M12 5C6.5 5 2.7 9.1 1.5 12c1.2 2.9 5 7 10.5 7s9.3-4.1 10.5-7C21.3 9.1 17.5 5 12 5zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/>',
        'search'     => '<path d="M15.5 14h-.8l-.3-.3a6.5 6.5 0 1 0-.7.7l.3.3v.8l5 5 1.5-1.5-5-5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/>',
        'check'      => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/>',
        'check-circle' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 15-5-5 1.4-1.4L11 13.2l5.6-5.6L18 9l-7 8z"/>',
        'alert-circle' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>',
        'info'       => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>',
        'close'      => '<path d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4z"/>',
        'user'       => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4 0-8 2-8 4.5V22h16v-3.5c0-2.5-4-4.5-8-4.5z"/>',
        'lock'       => '<path d="M18 8h-1V6a5 5 0 0 0-10 0v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9V6zm3 12a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/>',
        'prescription' => '<path d="M6 2h6a4 4 0 0 1 2.4 7.2L17 12l1.6-1.6L20 11.8 18.4 13.4 20 15l-1.4 1.4L17 14.8 15.4 16.4 14 15l1.6-1.6-3.3-3.3H8V20H6V2zm2 2v4.5h4a2.25 2.25 0 0 0 0-4.5H8z"/>',
        'history'    => '<path d="M13 3a9 9 0 0 0-9 9H1l4 4 4-4H6a7 7 0 1 1 2 4.9l-1.4 1.5A9 9 0 1 0 13 3zm-1 5v5l4 2 .7-1.3-3.2-1.9V8H12z"/>',
        'chart'      => '<path d="M4 20h16v2H2V2h2v18zm3-2V10h3v8H7zm5 0V6h3v12h-3zm5 0v-5h3v5h-3z"/>',
        'menu'       => '<path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>',
        'money'      => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm.9 15.4v1.3h-1.6v-1.3c-1.6-.2-2.7-1.1-2.9-2.6h1.7c.1.7.7 1.2 1.9 1.2 1.1 0 1.7-.5 1.7-1.1 0-.6-.4-.9-1.9-1.3-2-.4-3.1-1.1-3.1-2.6 0-1.3 1-2.2 2.6-2.4V7.3h1.6v1.3c1.5.3 2.4 1.2 2.5 2.5h-1.7c-.1-.7-.6-1.2-1.6-1.2s-1.6.4-1.6 1c0 .6.5.9 1.9 1.2 2 .5 3.1 1.1 3.1 2.7 0 1.4-1 2.3-2.6 2.6z"/>',
        'calendar-check' => '<path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm12 8v10H5V10h14zm-8.2 8L7 14.2l1.4-1.4 2.4 2.4 4.8-4.8 1.4 1.4-6.2 6.2z"/>',
        'print'      => '<path d="M19 8H5a3 3 0 0 0-3 3v6h4v4h12v-4h4v-6a3 3 0 0 0-3-3zM8 19v-5h8v5H8zm8-11V3H8v5h8z"/>',
        'stethoscope' => '<path d="M6 3v6a4 4 0 0 0 3 3.9V15a4 4 0 0 0 8 0v-1.1a3 3 0 1 0-2-.1V15a2 2 0 0 1-4 0v-2.1A4 4 0 0 0 14 9V3h-2v6a2 2 0 0 1-4 0V3H6zm12 9.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>',
    ];

    $d = $paths[$name] ?? $paths['info'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="18" height="18" '
         . 'fill="currentColor" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

// ---------------------------------------------------------------------
// PAGINATION
// ---------------------------------------------------------------------

/**
 * @return array{page:int, per_page:int, offset:int, total:int, pages:int}
 */
function paginate($totalRows, $page = null, $perPage = PAGE_SIZE)
{
    $page  = $page ?: max(1, get_int('page', 1));
    $pages = max(1, (int) ceil($totalRows / $perPage));
    $page  = min($page, $pages);

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'total'    => (int) $totalRows,
        'pages'    => $pages,
    ];
}

/** Renders Bootstrap pagination preserving the current query string. */
function render_pagination(array $p, array $query = [])
{
    if ($p['pages'] <= 1) { return; }

    $build = function ($page) use ($query) {
        $query['page'] = $page;
        return '?' . http_build_query($query);
    };

    echo '<nav class="mt-3"><ul class="pagination">';
    echo '<li class="page-item' . ($p['page'] <= 1 ? ' disabled' : '') . '">'
       . '<a class="page-link" href="' . e($build($p['page'] - 1)) . '">Previous</a></li>';

    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $start + 4);
    $start = max(1, $end - 4);

    for ($i = $start; $i <= $end; $i++) {
        echo '<li class="page-item' . ($i === $p['page'] ? ' active' : '') . '">'
           . '<a class="page-link" href="' . e($build($i)) . '">' . $i . '</a></li>';
    }

    echo '<li class="page-item' . ($p['page'] >= $p['pages'] ? ' disabled' : '') . '">'
       . '<a class="page-link" href="' . e($build($p['page'] + 1)) . '">Next</a></li>';
    echo '</ul></nav>';
}

// ---------------------------------------------------------------------
// NOTIFICATIONS
// ---------------------------------------------------------------------

function notify($userId, $title, $message, $type = 'system', $link = null)
{
    if (!$userId) { return 0; }
    return db_insert(
        'INSERT INTO notifications (user_id, title, message, notification_type, link)
         VALUES (?, ?, ?, ?, ?)',
        [(int) $userId, $title, $message, $type, $link]
    );
}

function unread_notification_count($userId)
{
    return (int) db_scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
        [(int) $userId]
    );
}

function recent_notifications($userId, $limit = 8)
{
    return db_all(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
        [(int) $userId, (int) $limit]
    );
}

// ---------------------------------------------------------------------
// SHARED OPTION LISTS  (single source of truth for every drop-down)
// ---------------------------------------------------------------------

function genders()      { return ['Male', 'Female', 'Other']; }
function blood_groups() { return ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown']; }

function frequencies()
{
    return ['Once Daily','Twice Daily','Three Times Daily','Four Times Daily',
            'Every 4 Hours','Every 6 Hours','Every 8 Hours','Every 12 Hours','As Needed'];
}

function routes()      { return ['Oral','Injection','Topical','Inhalation','Sublingual','Other']; }
function dose_units()  { return ['mg','g','ml','tablet','capsule','drop','puff','unit','IU','other']; }

function medicine_categories()
{
    return ['Antibiotic','Analgesic','Antipyretic','Vitamin/Supplement','Antihypertensive',
            'Antidiabetic','Cardiac','Respiratory','Gastrointestinal','Other'];
}

function appointment_statuses() { return ['pending','confirmed','completed','cancelled','no_show']; }
function time_off_reasons()     { return ['Vacation','Personal Leave','Conference','Holiday','Emergency','Other']; }

function week_days()
{
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}

function day_name($dayNumber)
{
    $days = week_days();
    return $days[(int) $dayNumber] ?? '-';
}

/**
 * Suggested reminder clock times for a frequency - used to pre-fill the
 * prescription form (the doctor can always override them).
 */
function suggested_times($frequency)
{
    switch ($frequency) {
        case 'Twice Daily':        return ['08:00', '20:00'];
        case 'Three Times Daily':  return ['08:00', '14:00', '20:00'];
        case 'Four Times Daily':   return ['06:00', '12:00', '18:00', '22:00'];
        case 'Every 4 Hours':      return ['06:00', '10:00', '14:00', '18:00', '22:00'];
        case 'Every 6 Hours':      return ['06:00', '12:00', '18:00', '00:00'];
        case 'Every 8 Hours':      return ['06:00', '14:00', '22:00'];
        case 'Every 12 Hours':     return ['08:00', '20:00'];
        case 'As Needed':          return [];
        case 'Once Daily':
        default:                   return ['08:00'];
    }
}

/** Renders <option> tags for a simple list. */
function options_from(array $values, $selected = '')
{
    $html = '';
    foreach ($values as $key => $label) {
        $value = is_int($key) ? $label : $key;
        $html .= '<option value="' . e($value) . '"'
               . ((string) $value === (string) $selected ? ' selected' : '') . '>'
               . e($label) . '</option>';
    }
    return $html;
}

/** Renders <option> tags from a query result. */
function options_from_rows(array $rows, $valueKey, $labelKey, $selected = '')
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<option value="' . e($row[$valueKey]) . '"'
               . ((string) $row[$valueKey] === (string) $selected ? ' selected' : '') . '>'
               . e($row[$labelKey]) . '</option>';
    }
    return $html;
}

// ---------------------------------------------------------------------
// SMALL SHARED QUERIES
// ---------------------------------------------------------------------

function all_clinics($activeOnly = true)
{
    $sql = 'SELECT id, clinic_name, city FROM clinics';
    if ($activeOnly) { $sql .= " WHERE status = 'active'"; }
    return db_all($sql . ' ORDER BY clinic_name');
}

function all_specializations()
{
    return db_all('SELECT id, specialization_name FROM specializations ORDER BY specialization_name');
}

function doctor_display_name(array $doctor)
{
    return 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
}
