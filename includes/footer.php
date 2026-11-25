<?php
/**
 * MediCare - includes/footer.php  (closes every page)
 */
if (!defined('BASE_URL')) { exit('Direct access is not allowed.'); }

$layout       = $layout       ?? 'app';
$needs_charts = $needs_charts ?? false;

$officialJs = APP_ROOT . '/assets/vendor/bootstrap/bootstrap.bundle.min.js';
$jsSrc      = is_file($officialJs)
            ? url('assets/vendor/bootstrap/bootstrap.bundle.min.js')
            : url('assets/vendor/bootstrap-lite/bootstrap-lite.js');
?>

<?php if ($layout === 'app'): ?>
            </div><!-- /.app-content -->
        </main>
    </div><!-- /.app-shell -->
<?php elseif ($layout === 'public'): ?>
    </main>
    <footer class="public-footer" id="contact">
        <div class="container">
            <div class="row">
                <div class="col-md-5 mb-3">
                    <h5 class="text-white"><?= e(APP_NAME) ?></h5>
                    <p class="mb-1"><?= e(APP_TAGLINE) ?></p>
                    <p class="mb-0">An offline academic project built with PHP, MySQL,
                       HTML5, CSS3, JavaScript and Bootstrap 5 conventions.</p>
                </div>
                <div class="col-md-3 mb-3">
                    <h6 class="text-white">Quick Links</h6>
                    <div><a href="<?= e(url('index.php')) ?>">Home</a></div>
                    <div><a href="<?= e(url('index.php#features')) ?>">Features</a></div>
                    <div><a href="<?= e(url('login.php')) ?>">Login</a></div>
                    <div><a href="<?= e(url('register.php')) ?>">Register as Patient</a></div>
                </div>
                <div class="col-md-4 mb-3">
                    <h6 class="text-white">Contact</h6>
                    <div>MediCare Central Clinic</div>
                    <div>12 Wellness Avenue, Springfield</div>
                    <div>Phone: +1 555 0100</div>
                    <div>Email: support@medicare.local</div>
                </div>
            </div>
            <hr style="border-color:rgba(255,255,255,.15)">
            <div class="text-center small">
                &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Academic project - runs completely offline on XAMPP.
            </div>
        </div>
    </footer>
<?php else: ?>
    </main>
<?php endif; ?>

<script src="<?= e($jsSrc) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php if ($needs_charts): ?>
    <script src="<?= e(url('assets/js/charts.js')) ?>"></script>
<?php endif; ?>
<?php if (is_logged_in() && is_role('patient')): ?>
    <script src="<?= e(url('assets/js/reminders.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
