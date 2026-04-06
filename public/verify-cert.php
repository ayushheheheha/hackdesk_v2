<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/helpers.php';

$pdo = Database::getConnection();
$type = trim((string) ($_GET['type'] ?? 'certificate'));
$token = trim((string) ($_GET['token'] ?? ''));
$result = null;
$verificationState = 'not_found';

if ($type === 'checkin' && $token !== '') {
    $stmt = $pdo->prepare(
        'SELECT
            p.name,
            p.participant_type,
            p.barcode_uid,
            p.vit_reg_no,
            p.college,
            p.department,
            p.check_in_status,
            h.name AS hackathon_name,
            h.venue,
            h.starts_at
         FROM participants p
         INNER JOIN hackathons h ON h.id = p.hackathon_id
         WHERE p.qr_token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $result = $stmt->fetch() ?: null;
    $verificationState = $result !== null ? 'checkin_found' : 'checkin_missing';
} elseif ($token !== '') {
    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.participant_id,
            c.hackathon_id,
            c.cert_type,
            c.position,
            c.hmac_token,
            c.is_revoked,
            c.revoke_reason,
            c.issued_at,
            c.special_title,
            p.name AS participant_name,
            h.name AS hackathon_name,
            h.venue,
            h.starts_at
         FROM certificates c
         INNER JOIN participants p ON p.id = c.participant_id
         INNER JOIN hackathons h ON h.id = c.hackathon_id
         WHERE c.hmac_token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $result = $stmt->fetch() ?: null;

    if ($result !== null) {
        if ((int) $result['is_revoked'] === 1) {
            $verificationState = 'revoked';
        } else {
            $expected = hash_hmac('sha256', $result['id'] . '|' . $result['participant_id'] . '|' . $result['hackathon_id'], HMAC_SECRET);
            $verificationState = hash_equals($expected, $token) ? 'authentic' : 'invalid';
        }
    }
}

$pageTitle = 'Verification';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1><?= e($type === 'checkin' ? 'Check-In Verification' : 'Certificate Verification') ?></h1>
        <p class="page-subtitle">Public verification for HackDesk participant identity and event access.</p>
    </div>
</section>
<section class="card" style="max-width:760px;">
    <?php if ($type === 'checkin' && is_array($result)): ?>
        <?php $badgeClass = $result['check_in_status'] === 'checked_in' ? 'badge-success' : 'badge-muted'; ?>
        <h2><?= e((string) $result['name']) ?></h2>
        <p class="page-subtitle" style="margin:10px 0 16px;"><?= e((string) $result['hackathon_name']) ?> | <?= e(formatUtcToIst((string) $result['starts_at'], 'd M Y')) ?></p>
        <p><strong>Status:</strong> <span class="badge <?= e($badgeClass) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $result['check_in_status']))) ?></span></p>
        <p style="margin-top:10px;"><strong>Participant Type:</strong> <?= e(ucfirst((string) $result['participant_type'])) ?></p>
        <p style="margin-top:10px;"><strong>Department:</strong> <?= e((string) ($result['department'] ?? '-')) ?></p>
        <p style="margin-top:10px;"><strong>Registration Number:</strong> <?= e((string) $result['barcode_uid']) ?></p>
        <p style="margin-top:10px;"><strong>Identity:</strong> <?= e((string) (($result['participant_type'] === 'internal') ? ($result['vit_reg_no'] ?? 'VIT Student') : ($result['college'] ?? 'External Participant'))) ?></p>
        <p style="margin-top:16px;" class="empty-state">Venue: <?= e((string) ($result['venue'] ?? 'TBA')) ?></p>
    <?php elseif ($type === 'checkin'): ?>
        <p class="empty-state">This check-in QR code is invalid or no longer available.</p>
    <?php elseif ($verificationState === 'authentic' && is_array($result)): ?>
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:18px;">
            <div style="width:54px;height:54px;border-radius:999px;background:rgba(74,222,128,0.12);color:#4ADE80;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;">&#10003;</div>
            <div>
                <h2 style="margin:0;">This certificate is AUTHENTIC</h2>
                <p class="page-subtitle" style="margin-top:8px;">The certificate details below match our signed record.</p>
            </div>
        </div>
        <p><strong>Participant:</strong> <?= e((string) $result['participant_name']) ?></p>
        <p style="margin-top:10px;"><strong>Certificate Type:</strong> <?= e(ucwords(str_replace('_', ' ', (string) $result['cert_type']))) ?><?= ($result['cert_type'] === 'special' && !empty($result['special_title'])) ? ' - ' . e((string) $result['special_title']) : '' ?></p>
        <p style="margin-top:10px;"><strong>Hackathon:</strong> <?= e((string) $result['hackathon_name']) ?></p>
        <p style="margin-top:10px;"><strong>Date:</strong> <?= e(formatUtcToIst((string) $result['starts_at'], 'd M Y')) ?></p>
        <p style="margin-top:10px;"><strong>Venue:</strong> <?= e((string) ($result['venue'] ?? 'TBA')) ?></p>
        <p style="margin-top:10px;"><strong>Issued At:</strong> <?= e(formatUtcToIst((string) $result['issued_at'])) ?></p>
        <p style="margin-top:10px;"><strong>Certificate ID:</strong> <?= e(substr((string) $result['hmac_token'], 0, 16)) ?>...</p>
    <?php elseif ($verificationState === 'revoked' && is_array($result)): ?>
        <h2>This certificate has been revoked</h2>
        <p class="page-subtitle" style="margin-top:10px;">Participant: <?= e((string) $result['participant_name']) ?></p>
        <p style="margin-top:12px;"><strong>Reason:</strong> <?= e((string) ($result['revoke_reason'] ?? 'No reason provided')) ?></p>
    <?php elseif ($token !== ''): ?>
        <h2><?= e($verificationState === 'invalid' ? 'Invalid certificate' : 'Certificate not found') ?></h2>
        <p class="empty-state" style="margin-top:12px;"><?= e($verificationState === 'invalid' ? 'The token does not match our signed certificate record.' : 'We could not find a certificate for this verification token.') ?></p>
    <?php else: ?>
        <h2>Verify A Certificate</h2>
        <p class="page-subtitle" style="margin-top:10px;">Paste a HackDesk certificate token below to check whether it is authentic.</p>
        <form method="get" action="<?= e(appPath('public/verify-cert.php')) ?>" style="margin-top:20px;">
            <input type="hidden" name="type" value="certificate">
            <div class="form-group">
                <label for="token">Certificate Token</label>
                <input id="token" name="token" type="text" required placeholder="Paste the verification token from the certificate QR link">
            </div>
            <button type="submit" class="btn-primary">Verify Certificate</button>
        </form>
        <p class="empty-state" style="margin-top:16px;">If you scanned a QR from a HackDesk certificate, this page will usually open automatically with the token already included.</p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
