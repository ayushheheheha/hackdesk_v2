<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';
require_once __DIR__ . '/../core/CSRF.php';

$alreadyAuthenticated = ParticipantAuth::check();
if ($alreadyAuthenticated) {
    redirect('portal/participant/dashboard.php');
}

$pdo = Database::getConnection();
$hackathonId = filter_input(INPUT_GET, 'h', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT);
$selectedType = normalizeParticipantType((string) ($_GET['type'] ?? $_POST['participant_type'] ?? ''));
$hackathon = null;

if ($hackathonId !== false && $hackathonId !== null) {
    $hackathonStmt = $pdo->prepare(
        'SELECT id, name, venue, starts_at
         FROM hackathons
         WHERE id = ?
         LIMIT 1'
    );
    $hackathonStmt->execute([$hackathonId]);
    $hackathon = $hackathonStmt->fetch() ?: null;
}

$token = trim((string) ($_GET['token'] ?? ''));
$success = false;
$otpEmail = trim((string) ($_POST['email'] ?? $_GET['email'] ?? ''));
$otpRequested = false;
$otpVerified = false;
$otpError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        $otpError = 'Your session token is invalid. Please refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'request_otp') {
            if ($otpEmail === '' || !filter_var($otpEmail, FILTER_VALIDATE_EMAIL)) {
                $otpError = 'Enter a valid participant email address.';
            } else {
                $otpRequested = ParticipantAuth::sendOtpForEmail($otpEmail, $hackathonId ?: null, $selectedType);
                if (!$otpRequested) {
                    $otpError = 'We could not send a login code for that participant email. Check the student type, event link, and email address.';
                }
            }
        }

        if ($action === 'verify_otp') {
            $otpCode = trim((string) ($_POST['otp_code'] ?? ''));
            $otpVerified = ParticipantAuth::loginWithOtp($otpEmail, $otpCode, $hackathonId ?: null, $selectedType);
            if (!$otpVerified) {
                $otpError = 'The OTP is invalid, expired, or has already been used.';
            }
        }
    }
}

if ($token !== '') {
    $success = ParticipantAuth::loginWithToken($token);
}

$pageTitle = 'Participant Login';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Participant Login</h1>
        <p class="page-subtitle">Choose your student type, then sign in with a one-time email code or a participant magic link.</p>
    </div>
</section>

<?php if ($hackathon !== null): ?>
    <section class="card" style="margin-bottom:24px;">
        <h2><?= e((string) $hackathon['name']) ?></h2>
        <p class="page-subtitle" style="margin-top:10px;">Venue: <?= e((string) ($hackathon['venue'] ?? 'TBA')) ?> | Date: <?= e(formatUtcToIst((string) $hackathon['starts_at'], 'd M Y')) ?></p>
    </section>
<?php endif; ?>

<section class="card" style="margin-bottom:24px;">
    <h2>Who Are You?</h2>
    <p class="page-subtitle" style="margin-top:10px;">Internal students use their VIT registration details. External participants use the HackDesk ID card and registration email they received.</p>
    <div class="audience-toggle" style="margin-top:20px;">
        <?php
        $baseParams = [];
        if ($hackathonId) {
            $baseParams['h'] = (string) $hackathonId;
        }
        ?>
        <a class="<?= $selectedType === 'internal' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(appPath('public/participant-login.php?' . http_build_query($baseParams + ['type' => 'internal']))) ?>">Internal Student</a>
        <a class="<?= $selectedType === 'external' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(appPath('public/participant-login.php?' . http_build_query($baseParams + ['type' => 'external']))) ?>">External Participant</a>
    </div>
</section>

<section class="grid grid-3">
    <article class="card" style="grid-column: span 2; max-width:760px;">
        <h2>Email OTP Login</h2>
        <p class="page-subtitle" style="margin-top:10px;">
            <?= e($selectedType === 'internal'
                ? 'Enter the email you used for VIT student registration to receive a 6-digit login code.'
                : ($selectedType === 'external'
                    ? 'Enter the email you used for external registration to receive a 6-digit login code.'
                    : 'Enter your participant email to receive a 6-digit login code.')) ?>
        </p>

        <?php if ($otpError !== null): ?>
            <div class="flash flash-error" style="margin-top:18px;"><?= e($otpError) ?></div>
        <?php endif; ?>

        <?php if ($otpRequested && !$otpVerified): ?>
            <div class="flash flash-success" style="margin-top:18px;">A login code has been sent to your email.</div>
        <?php endif; ?>

        <?php if ($otpVerified): ?>
            <div class="flash flash-success" style="margin-top:18px;">OTP verified. Continue into your participant dashboard.</div>
            <div style="margin-top:18px;">
                <a class="btn-primary" href="<?= e(appPath('portal/participant/dashboard.php')) ?>">Continue To Participant Dashboard</a>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(appPath('public/participant-login.php' . (($hackathonId || $selectedType !== null) ? '?' . http_build_query(array_filter([
                'h' => $hackathonId ?: null,
                'type' => $selectedType,
            ])) : ''))) ?>" style="margin-top:20px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="request_otp">
                <?php if ($hackathonId): ?>
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $hackathonId) ?>">
                <?php endif; ?>
                <?php if ($selectedType !== null): ?>
                    <input type="hidden" name="participant_type" value="<?= e($selectedType) ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="email">Participant Email</label>
                    <input id="email" name="email" type="email" required value="<?= e($otpEmail) ?>">
                </div>
                <button type="submit" class="btn-primary">Send OTP</button>
            </form>

            <form method="post" action="<?= e(appPath('public/participant-login.php' . (($hackathonId || $selectedType !== null) ? '?' . http_build_query(array_filter([
                'h' => $hackathonId ?: null,
                'type' => $selectedType,
            ])) : ''))) ?>" style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border);">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="verify_otp">
                <?php if ($hackathonId): ?>
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $hackathonId) ?>">
                <?php endif; ?>
                <?php if ($selectedType !== null): ?>
                    <input type="hidden" name="participant_type" value="<?= e($selectedType) ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="otp_email">Participant Email</label>
                    <input id="otp_email" name="email" type="email" required value="<?= e($otpEmail) ?>">
                </div>
                <div class="form-group">
                    <label for="otp_code">6-Digit OTP</label>
                    <input id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
                </div>
                <button type="submit" class="btn-primary">Verify OTP</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="card">
        <h2>Magic Link</h2>
        <?php if ($success): ?>
            <p class="page-subtitle" style="margin-top:12px;">Your magic link is valid and your participant session is ready.</p>
            <div style="margin-top:20px;">
                <a class="btn-primary" href="<?= e(appPath('portal/participant/dashboard.php')) ?>">Continue To Participant Dashboard</a>
            </div>
        <?php elseif ($token !== ''): ?>
            <p class="empty-state" style="margin-top:12px;">This participant login link is invalid, expired, or has already been used.</p>
        <?php else: ?>
            <p class="empty-state" style="margin-top:12px;">You can still use a fresh magic link from your registration email if you prefer.</p>
            <?php if ($hackathonId): ?>
                <div style="margin-top:18px;">
                    <a class="btn-ghost" href="<?= e(appPath('public/register.php?h=' . (int) $hackathonId . ($selectedType !== null ? '&type=' . $selectedType : ''))) ?>">Need to register first?</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </article>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
