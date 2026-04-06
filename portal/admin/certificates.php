<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/Certificate.php';
require_once __DIR__ . '/../../core/Mailer.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare('SELECT id, name FROM hackathons ORDER BY created_at DESC, id DESC');
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();
$selectedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: (($hackathons[0]['id'] ?? null) !== null ? (int) $hackathons[0]['id'] : null);

$sendCertificateEmail = static function (PDO $pdo, int $certificateId): bool {
    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.hmac_token,
            c.file_path,
            c.cert_type,
            c.special_title,
            p.name,
            p.email,
            h.name AS hackathon_name
         FROM certificates c
         INNER JOIN participants p ON p.id = c.participant_id
         INNER JOIN hackathons h ON h.id = c.hackathon_id
         WHERE c.id = ?
         LIMIT 1'
    );
    $stmt->execute([$certificateId]);
    $certificate = $stmt->fetch();

    if ($certificate === false || empty($certificate['file_path'])) {
        return false;
    }

    $absolutePath = dirname(__DIR__, 2) . '/' . ltrim((string) $certificate['file_path'], '/');
    if (!is_file($absolutePath)) {
        return false;
    }

    $verifyUrl = APP_URL . '/public/verify-cert.php?token=' . urlencode((string) $certificate['hmac_token']);
    $subject = 'Your Certificate — ' . $certificate['hackathon_name'];
    $body = '
        <html lang="en">
        <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
                <h1 style="margin:0 0 14px;font-size:22px;">Congratulations ' . e((string) $certificate['name']) . '!</h1>
                <p style="margin:0 0 10px;color:#D4D4D8;">Your certificate for ' . e((string) $certificate['hackathon_name']) . ' is attached.</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Verify authenticity at: <a href="' . e($verifyUrl) . '" style="color:#A5B4FC;">' . e($verifyUrl) . '</a></p>
                <p style="margin:0;color:#D4D4D8;">Certificate ID: ' . e(substr((string) $certificate['hmac_token'], 0, 16)) . '...</p>
            </div>
        </body>
        </html>
    ';

    return Mailer::sendMail(
        (string) $certificate['email'],
        (string) $certificate['name'],
        $subject,
        $body,
        [['path' => $absolutePath, 'name' => 'certificate-' . $certificate['id'] . '.pdf']]
    );
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/certificates.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_participation' && $selectedHackathonId) {
        $participantsStmt = $pdo->prepare(
            'SELECT p.id
             FROM participants p
             WHERE p.hackathon_id = ? AND p.check_in_status = ?'
        );
        $participantsStmt->execute([$selectedHackathonId, 'checked_in']);
        $participantIds = array_map(static fn(array $row): int => (int) $row['id'], $participantsStmt->fetchAll());

        $generated = 0;
        $skipped = 0;
        foreach ($participantIds as $participantId) {
            $checkStmt = $pdo->prepare('SELECT id FROM certificates WHERE participant_id = ? AND hackathon_id = ? AND cert_type = ? LIMIT 1');
            $checkStmt->execute([$participantId, $selectedHackathonId, 'participation']);
            if ($checkStmt->fetch() !== false) {
                $skipped++;
                continue;
            }
            Certificate::generate($participantId, $selectedHackathonId, 'participation');
            $generated++;
        }
        flash('success', 'Participation certificates generated: ' . $generated . '. Already existing: ' . $skipped . '.');
        redirect('portal/admin/certificates.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'generate_selected' && $selectedHackathonId) {
        $generated = 0;
        $teamAwardMap = [
            'winner_team_id' => ['type' => 'winner', 'position' => 1],
            'runner_up_team_id' => ['type' => 'runner_up', 'position' => 2],
            'second_runner_up_team_id' => ['type' => 'second_runner_up', 'position' => 3],
        ];

        foreach ($teamAwardMap as $field => $meta) {
            $teamId = filter_input(INPUT_POST, $field, FILTER_VALIDATE_INT);
            if ($teamId !== false && $teamId !== null) {
                $membersStmt = $pdo->prepare('SELECT participant_id FROM team_members WHERE team_id = ?');
                $membersStmt->execute([$teamId]);
                foreach ($membersStmt->fetchAll() as $member) {
                    $checkStmt = $pdo->prepare('SELECT id FROM certificates WHERE participant_id = ? AND hackathon_id = ? AND cert_type = ? LIMIT 1');
                    $checkStmt->execute([(int) $member['participant_id'], $selectedHackathonId, $meta['type']]);
                    if ($checkStmt->fetch() === false) {
                        Certificate::generate((int) $member['participant_id'], $selectedHackathonId, $meta['type'], $meta['position']);
                        $generated++;
                    }
                }
            }
        }

        $specialParticipantId = filter_input(INPUT_POST, 'special_participant_id', FILTER_VALIDATE_INT);
        $specialTeamId = filter_input(INPUT_POST, 'special_team_id', FILTER_VALIDATE_INT);
        $specialTitle = trim((string) ($_POST['special_title'] ?? ''));
        if ($specialTitle !== '') {
            if ($specialParticipantId !== false && $specialParticipantId !== null) {
                Certificate::generate($specialParticipantId, $selectedHackathonId, 'special', null, $specialTitle);
                $generated++;
            } elseif ($specialTeamId !== false && $specialTeamId !== null) {
                $membersStmt = $pdo->prepare('SELECT participant_id FROM team_members WHERE team_id = ?');
                $membersStmt->execute([$specialTeamId]);
                foreach ($membersStmt->fetchAll() as $member) {
                    Certificate::generate((int) $member['participant_id'], $selectedHackathonId, 'special', null, $specialTitle);
                    $generated++;
                }
            }
        }

        flash('success', 'Certificates generated: ' . $generated . '.');
        redirect('portal/admin/certificates.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'revoke_certificate') {
        $certificateId = filter_input(INPUT_POST, 'certificate_id', FILTER_VALIDATE_INT);
        $reason = trim((string) ($_POST['revoke_reason'] ?? 'Revoked by admin'));
        if ($certificateId !== false && $certificateId !== null) {
            Certificate::revoke($certificateId, $reason);
            flash('success', 'Certificate revoked.');
        }
        redirect('portal/admin/certificates.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'email_all' && $selectedHackathonId) {
        $certStmt = $pdo->prepare('SELECT id FROM certificates WHERE hackathon_id = ? AND is_revoked = 0');
        $certStmt->execute([$selectedHackathonId]);
        $sent = 0;
        $failed = 0;
        foreach ($certStmt->fetchAll() as $row) {
            if ($sendCertificateEmail($pdo, (int) $row['id'])) {
                $sent++;
            } else {
                $failed++;
            }
        }
        flash('success', 'Certificate emails sent: ' . $sent . '. Failed: ' . $failed . '.');
        redirect('portal/admin/certificates.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'retry_email') {
        $certificateId = filter_input(INPUT_POST, 'certificate_id', FILTER_VALIDATE_INT);
        if ($certificateId !== false && $certificateId !== null) {
            $ok = $sendCertificateEmail($pdo, $certificateId);
            flash('success', $ok ? 'Certificate email sent.' : 'Certificate email failed.');
        }
        redirect('portal/admin/certificates.php?hackathon_id=' . $selectedHackathonId);
    }
}

$participants = [];
$teams = [];
$certificates = [];
if ($selectedHackathonId) {
    $participantsStmt = $pdo->prepare(
        'SELECT p.id, p.name, p.participant_type, p.email
         FROM participants p
         WHERE p.hackathon_id = ?
         ORDER BY p.name ASC'
    );
    $participantsStmt->execute([$selectedHackathonId]);
    $participants = $participantsStmt->fetchAll();

    $teamsStmt = $pdo->prepare('SELECT id, name FROM teams WHERE hackathon_id = ? ORDER BY name ASC');
    $teamsStmt->execute([$selectedHackathonId]);
    $teams = $teamsStmt->fetchAll();

    $certStmt = $pdo->prepare(
        'SELECT
            c.id,
            c.participant_id,
            c.cert_type,
            c.hmac_token,
            c.file_path,
            c.is_revoked,
            c.issued_at,
            c.special_title,
            p.name AS participant_name,
            p.participant_type
         FROM certificates c
         INNER JOIN participants p ON p.id = c.participant_id
         WHERE c.hackathon_id = ?
         ORDER BY c.issued_at DESC'
    );
    $certStmt->execute([$selectedHackathonId]);
    $certificates = $certStmt->fetchAll();
}

$pageTitle = 'Certificates';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Certificates</h1><p class="page-subtitle">Generate, revoke, email, and verify certificates for participants and winners.</p></div></section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon before generating certificates.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/certificates.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Participation Certificates</h2>
            <p class="page-subtitle" style="margin:10px 0 18px;">Generate certificates for all checked-in participants who do not already have one.</p>
            <form method="post" action="<?= e(appPath('portal/admin/certificates.php?hackathon_id=' . (int) $selectedHackathonId)) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="generate_participation">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <button type="submit" class="btn-primary">Generate for All Checked-In Participants</button>
            </form>
        </article>

        <article class="card" style="grid-column: span 2;">
            <h2>Winner and Special Certificates</h2>
            <form method="post" action="<?= e(appPath('portal/admin/certificates.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:18px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="generate_selected">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="winner_team_id">1st Place Team</label>
                        <select id="winner_team_id" name="winner_team_id">
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $team): ?><option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="runner_up_team_id">2nd Place Team</label>
                        <select id="runner_up_team_id" name="runner_up_team_id">
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $team): ?><option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="second_runner_up_team_id">3rd Place Team</label>
                        <select id="second_runner_up_team_id" name="second_runner_up_team_id">
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $team): ?><option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="special_title">Special Award Name</label>
                        <input id="special_title" name="special_title" type="text" placeholder="Best UI/UX">
                    </div>
                    <div class="form-group">
                        <label for="special_team_id">Special Award Team</label>
                        <select id="special_team_id" name="special_team_id">
                            <option value="">No Team</option>
                            <?php foreach ($teams as $team): ?><option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="special_participant_id">Special Award Participant</label>
                        <select id="special_participant_id" name="special_participant_id">
                            <option value="">No Individual</option>
                            <?php foreach ($participants as $participant): ?><option value="<?= e((string) $participant['id']) ?>"><?= e((string) $participant['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-primary">Generate Selected Certificates</button>
            </form>
        </article>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <h2>Bulk Email Certificates</h2>
        <form method="post" action="<?= e(appPath('portal/admin/certificates.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:14px;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="email_all">
            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
            <button type="submit" class="btn-primary">Email All Certificates</button>
        </form>
    </section>

    <section class="card">
        <h2>Issued Certificates</h2>
        <?php if ($certificates === []): ?>
            <p class="empty-state" style="margin-top:12px;">No certificates have been generated yet.</p>
        <?php else: ?>
            <div class="table-shell" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Participant</th><th>Type</th><th>Status</th><th>Issued</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($certificates as $certificate): ?>
                        <tr>
                            <td><?= e((string) $certificate['participant_name']) ?><br><span class="page-subtitle"><?= e((string) $certificate['participant_type']) ?></span></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) $certificate['cert_type']))) ?><?= ($certificate['cert_type'] === 'special' && !empty($certificate['special_title'])) ? '<br><span class="page-subtitle">' . e((string) $certificate['special_title']) . '</span>' : '' ?></td>
                            <td><span class="badge <?= (int) $certificate['is_revoked'] === 1 ? 'badge-muted' : 'badge-success' ?>"><?= (int) $certificate['is_revoked'] === 1 ? 'Revoked' : 'Active' ?></span></td>
                            <td><?= e(formatUtcToIst((string) $certificate['issued_at'])) ?></td>
                            <td>
                                <a class="btn-ghost" href="<?= e(appPath('public/certificate-file.php?certificate_id=' . (int) $certificate['id'])) ?>">Download</a>
                                <a class="btn-ghost" href="<?= e(appPath('public/verify-cert.php?token=' . urlencode((string) $certificate['hmac_token']))) ?>" target="_blank" rel="noopener">Verify</a>
                                <form method="post" action="<?= e(appPath('portal/admin/certificates.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:8px;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="retry_email">
                                    <input type="hidden" name="certificate_id" value="<?= e((string) $certificate['id']) ?>">
                                    <button type="submit" class="btn-ghost">Email</button>
                                </form>
                                <?php if ((int) $certificate['is_revoked'] === 0): ?>
                                    <form method="post" action="<?= e(appPath('portal/admin/certificates.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:8px;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="revoke_certificate">
                                        <input type="hidden" name="certificate_id" value="<?= e((string) $certificate['id']) ?>">
                                        <input type="hidden" name="revoke_reason" value="Revoked by admin">
                                        <button type="submit" class="btn-ghost">Revoke</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
