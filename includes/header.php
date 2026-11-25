<?php
/**
 * =====================================================================
 *  MediCare - includes/header.php
 *  Opens every page.  Set these variables BEFORE including it:
 *      $page_title    (string)  browser + page title
 *      $active        (string)  sidebar key: dashboard, users, doctors...
 *      $layout        (string)  'app' (default) | 'public' | 'auth'
 *      $needs_charts  (bool)    also load assets/js/charts.js
 * =====================================================================
 */

require_once __DIR__ . '/auth.php';

$page_title   = $page_title   ?? APP_NAME;
$active       = $active       ?? '';
$layout       = $layout       ?? 'app';
$needs_charts = $needs_charts ?? false;

/* --- Offline asset detection -----------------------------------------
   If the official Bootstrap 5 dist files have been copied into
   assets/vendor/bootstrap/ they are used; otherwise the bundled
   bootstrap-lite files are loaded.  Either way NOTHING is fetched
   from the internet.                                                  */
$officialCss = APP_ROOT . '/assets/vendor/bootstrap/bootstrap.min.css';
$officialJs  = APP_ROOT . '/assets/vendor/bootstrap/bootstrap.bundle.min.js';

$cssHref = is_file($officialCss)
         ? url('assets/vendor/bootstrap/bootstrap.min.css')
         : url('assets/vendor/bootstrap-lite/bootstrap-lite.css');
$jsSrc   = is_file($officialJs)
         ? url('assets/vendor/bootstrap/bootstrap.bundle.min.js')
         : url('assets/vendor/bootstrap-lite/bootstrap-lite.js');

$unread = is_logged_in() ? unread_notification_count(current_user_id()) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(APP_NAME . ' - ' . APP_TAGLINE) ?>">
    <title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231266d4'/><path d='M13 7h6v6h6v6h-6v6h-6v-6H7v-6h6z' fill='white'/></svg>">
    <link rel="stylesheet" href="<?= e($cssHref) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <script>
        // Shared configuration for the JavaScript layer
        window.MEDICARE = {
            baseUrl: <?= json_encode(BASE_URL) ?>,
            csrf: <?= json_encode(is_logged_in() ? csrf_token() : '') ?>,
            role: <?= json_encode(current_role()) ?>,
            snoozeMinutes: <?= (int) REMINDER_SNOOZE_MINUTES ?>
        };
    </script>
</head>
<body class="layout-<?= e($layout) ?>">

<?php if ($layout === 'app'): ?>
    <?php include __DIR__ . '/navbar.php'; ?>
    <div class="app-shell">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <main class="app-main">
            <div class="app-content">
                <?php render_flash(); ?>
<?php elseif ($layout === 'public'): ?>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main>
<?php else: /* auth */ ?>
    <main class="auth-wrap">
<?php endif; ?>
