<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/ParticipantAuth.php';
require_once __DIR__ . '/../../config/database.php';

Middleware::requireParticipantAuth();
$participant = ParticipantAuth::participant();
$pdo = Database::getConnection();

$teamStmt = $pdo->prepare(
    'SELECT
        t.id,
        t.name,
        t.status,
        ps.title AS problem_statement_title
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
     WHERE tm.participant_id = ?
     LIMIT 1'
);
$teamStmt->execute([(int) ($_SESSION['participant_id'] ?? 0)]);
$team = $teamStmt->fetch();

$certificatesStmt = $pdo->prepare(
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
$certificatesStmt->execute([(int) ($_SESSION['participant_id'] ?? 0)]);
$certificates = $certificatesStmt->fetchAll();

$checkInClass = match ($participant['check_in_status'] ?? 'not_checked_in') {
    'checked_in' => 'badge-success',
    default => 'badge-muted',
};

$pageTitle = 'Participant Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1><?= e((string) ($participant['name'] ?? 'Participant')) ?></h1>
        <p class="page-subtitle"><?= e((string) ($participant['hackathon_name'] ?? 'HackDesk Event')) ?></p>
    </div>
    <span class="badge <?= e($checkInClass) ?>"><?= e(ucwords(str_replace('_', ' ', (string) ($participant['check_in_status'] ?? 'not_checked_in')))) ?></span>
</section>

<section class="grid grid-3">
    <article class="card">
        <div class="stat-label">Team Status</div>
        <div class="stat-value" style="font-size:20px;"><?= e($team !== false ? 'In a Team' : 'Not Yet') ?></div>
        <p class="page-subtitle" style="margin-top:8px;"><?= e($team !== false ? (string) $team['name'] : 'Create or join a team to continue.') ?></p>
    </article>
    <article class="card">
        <div class="stat-label">Problem Statement</div>
        <div class="stat-value" style="font-size:20px;"><?= e($team !== false && !empty($team['problem_statement_title']) ? 'Selected' : 'Pending') ?></div>
        <p class="page-subtitle" style="margin-top:8px;"><?= e($team !== false && !empty($team['problem_statement_title']) ? (string) $team['problem_statement_title'] : 'Choose your challenge after joining a team.') ?></p>
    </article>
    <article class="card">
        <div class="stat-label">Participant Type</div>
        <div class="stat-value" style="font-size:20px;"><?= e(ucfirst((string) ($participant['participant_type'] ?? 'participant'))) ?></div>
        <p class="page-subtitle" style="margin-top:8px;"><?= e((string) (($participant['participant_type'] ?? '') === 'internal' ? ($participant['vit_reg_no'] ?? 'VIT Student') : ($participant['college'] ?? 'External Participant'))) ?></p>
    </article>
</section>

<section class="grid grid-3" style="margin-top:24px;">
    <a class="card" href="<?= e(appPath('portal/participant/team.php')) ?>">
        <h2>Join or Create Team</h2>
        <p class="page-subtitle" style="margin-top:10px;">Manage your team membership and invite flow.</p>
    </a>
    <a class="card" href="<?= e(appPath('portal/participant/problem-statement.php')) ?>">
        <h2>Change Problem Statement</h2>
        <p class="page-subtitle" style="margin-top:10px;">View or update your team challenge selection.</p>
    </a>
    <a class="card" href="<?= e(appPath('portal/participant/submissions.php')) ?>">
        <h2>Submit Round Work</h2>
        <p class="page-subtitle" style="margin-top:10px;">Open your submission area for active rounds.</p>
    </a>
</section>

<section class="card" style="margin-top:24px;">
    <div class="page-header">
        <div>
            <h2>Your Certificates</h2>
            <p class="page-subtitle">Issued certificates appear here after the event.</p>
        </div>
    </div>
    <?php if ($certificates === []): ?>
        <p class="empty-state">Certificates will appear here after the event.</p>
    <?php else: ?>
        <div class="table-shell">
            <table>
                <thead><tr><th>Type</th><th>Hackathon</th><th>Issued Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($certificates as $certificate): ?>
                    <tr>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $certificate['cert_type']))) ?><?= ($certificate['cert_type'] === 'special' && !empty($certificate['special_title'])) ? '<br><span class="page-subtitle">' . e((string) $certificate['special_title']) . '</span>' : '' ?></td>
                        <td><?= e((string) $certificate['hackathon_name']) ?></td>
                        <td><?= e(formatUtcToIst((string) $certificate['issued_at'])) ?></td>
                        <td>
                            <a class="btn-ghost" href="<?= e(appPath('public/certificate-file.php?certificate_id=' . (int) $certificate['id'])) ?>">Download PDF</a>
                            <a class="btn-ghost" href="<?= e(appPath('public/verify-cert.php?token=' . urlencode((string) $certificate['hmac_token']))) ?>" target="_blank" rel="noopener">View Verification</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
