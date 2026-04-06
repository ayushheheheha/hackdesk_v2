<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireParticipantAuth();
$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT
        c.id,
        c.cert_type,
        c.special_title,
        c.hmac_token,
        c.issued_at,
        h.name AS hackathon_name
     FROM certificates c
     INNER JOIN hackathons h ON h.id = c.hackathon_id
     WHERE c.participant_id = ? AND c.is_revoked = 0
     ORDER BY c.issued_at DESC'
);
$stmt->execute([(int) ($_SESSION['participant_id'] ?? 0)]);
$certificates = $stmt->fetchAll();
$pageTitle = 'Certificates';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Certificates</h1><p class="page-subtitle">Download and verify your issued certificates.</p></div></section>
<section class="card">
    <?php if ($certificates === []): ?>
        <p class="empty-state">Certificates will appear here after the event.</p>
    <?php else: ?>
        <div class="table-shell">
            <table>
                <thead><tr><th>Type</th><th>Hackathon</th><th>Issued</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($certificates as $certificate): ?>
                    <tr>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $certificate['cert_type']))) ?><?= ($certificate['cert_type'] === 'special' && !empty($certificate['special_title'])) ? '<br><span class="page-subtitle">' . e((string) $certificate['special_title']) . '</span>' : '' ?></td>
                        <td><?= e((string) $certificate['hackathon_name']) ?></td>
                        <td><?= e(formatUtcToIst((string) $certificate['issued_at'])) ?></td>
                        <td><a class="btn-ghost" href="<?= e(appPath('public/certificate-file.php?certificate_id=' . (int) $certificate['id'])) ?>">Download PDF</a> <a class="btn-ghost" href="<?= e(appPath('public/verify-cert.php?token=' . urlencode((string) $certificate['hmac_token']))) ?>" target="_blank" rel="noopener">Verify</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
