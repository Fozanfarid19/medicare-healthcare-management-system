<?php
/**
 * MediCare - includes/navbar.php
 * Top bar: brand, sidebar toggle (mobile), notification bell, user menu.
 * Included by header.php for the 'app' and 'public' layouts.
 */
if (!defined('BASE_URL')) { exit('Direct access is not allowed.'); }

$layout = $layout ?? 'app';
$unread = $unread ?? (is_logged_in() ? unread_notification_count(current_user_id()) : 0);
$notes  = is_logged_in() ? recent_notifications(current_user_id(), 6) : [];
?>
<nav class="mc-navbar">
    <?php if ($layout === 'app'): ?>
        <button class="mc-iconbtn mc-menu-toggle" id="sidebarToggle" type="button" aria-label="Toggle menu">
            <?= icon('menu') ?>
        </button>
    <?php endif; ?>

    <a class="mc-brand" href="<?= e(url(is_logged_in() ? dashboard_url() : 'index.php')) ?>">
        <span class="mc-logo">M+</span>
        <span><?= e(APP_NAME) ?><small>Healthcare System</small></span>
    </a>

    <div class="mc-navbar-right">
        <?php if (is_logged_in()): ?>
            <span class="mc-role-chip"><?= e(current_role()) ?></span>

            <!-- Notification centre -->
            <div class="dropdown">
                <button class="mc-iconbtn" type="button" data-bs-toggle="dropdown" aria-label="Notifications">
                    <?= icon('bell') ?>
                    <?php if ($unread > 0): ?>
                        <span class="mc-dot" id="notifCount"><?= $unread > 99 ? '99+' : (int) $unread ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu">
                    <div class="dropdown-header d-flex justify-content-between align-items-center">
                        <span>Notifications</span>
                        <?php if ($unread > 0): ?>
                            <form method="post" action="<?= e(url('api/notifications.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="read_all">
                                <input type="hidden" name="redirect" value="1">
                                <button class="btn btn-link btn-sm p-0" type="submit">Mark all read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$notes): ?>
                        <div class="dropdown-item text-muted">No notifications yet.</div>
                    <?php else: ?>
                        <?php foreach ($notes as $n): ?>
                            <a class="dropdown-item <?= $n['is_read'] ? '' : 'fw-semibold' ?>"
                               href="<?= e(url('api/notifications.php?action=open&id=' . (int) $n['id'])) ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <span><?= e($n['title']) ?></span>
                                    <?php if (!$n['is_read']): ?><span class="badge bg-primary">new</span><?php endif; ?>
                                </div>
                                <div class="small text-muted"><?= e(str_limit($n['message'], 70)) ?></div>
                                <div class="small text-muted"><?= e(format_datetime($n['created_at'])) ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- User menu -->
            <div class="dropdown">
                <div class="mc-user" data-bs-toggle="dropdown" role="button" tabindex="0">
                    <span class="mc-avatar"><?= e(strtoupper(substr(preg_replace('/^Dr\.\s*/', '', current_name()), 0, 1))) ?></span>
                    <span class="mc-username"><?= e(current_name()) ?></span>
                </div>
                <div class="dropdown-menu">
                    <div class="dropdown-header"><?= e(current_user()['email']) ?></div>
                    <?php if (is_role('doctor')): ?>
                        <a class="dropdown-item" href="<?= e(url('doctor/profile.php')) ?>"><?= icon('user') ?> My Profile</a>
                    <?php elseif (is_role('patient')): ?>
                        <a class="dropdown-item" href="<?= e(url('patient/profile.php')) ?>"><?= icon('user') ?> My Profile</a>
                    <?php else: ?>
                        <a class="dropdown-item" href="<?= e(url('admin/profile.php')) ?>"><?= icon('user') ?> My Profile</a>
                    <?php endif; ?>
                    <a class="dropdown-item" href="<?= e(url(dashboard_url())) ?>"><?= icon('dashboard') ?> Dashboard</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a class="nav-link d-none d-md-inline-flex" href="<?= e(url('index.php#features')) ?>">Features</a>
            <a class="nav-link d-none d-md-inline-flex" href="<?= e(url('index.php#contact')) ?>">Contact</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?> Login</a>
            <a class="btn btn-primary btn-sm" href="<?= e(url('register.php')) ?>"><?= icon('plus') ?> Register</a>
        <?php endif; ?>
    </div>
</nav>
