<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/UserSettings.php';

handlePasswordChange('super_admin', 'portal/super-admin/settings.php');
$pageTitle = 'Settings';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Settings</h1><p class="page-subtitle">Update your super admin password.</p></div></section>
<section class="card" style="max-width:560px;">
    <form method="post" action="<?= e(appPath('portal/super-admin/settings.php')) ?>">
        <?= CSRF::field() ?>
        <div class="form-group"><label for="current_password">Current Password</label><input id="current_password" name="current_password" type="password" required></div>
        <div class="form-group"><label for="new_password">New Password</label><input id="new_password" name="new_password" type="password" required></div>
        <div class="form-group"><label for="confirm_password">Confirm New Password</label><input id="confirm_password" name="confirm_password" type="password" required></div>
        <button type="submit" class="btn-primary">Change Password</button>
    </form>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
