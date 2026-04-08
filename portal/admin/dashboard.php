<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$selectedHackathonId = resolveSelectedHackathonId($pdo);
$hackathon = null;
if ($selectedHackathonId !== null) {
    $hackathonStmt = $pdo->prepare(
        'SELECT id, name, venue, starts_at, registration_deadline, status
         FROM hackathons
         WHERE id = ?
         LIMIT 1'
    );
    $hackathonStmt->execute([$selectedHackathonId]);
    $hackathon = $hackathonStmt->fetch() ?: null;
}

$stats = ['participants' => 0, 'teams' => 0, 'rounds' => 0];
$recentParticipants = [];
$upcomingRounds = [];

if ($hackathon !== null) {
    $statsStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(id) FROM participants WHERE hackathon_id = ?) AS participants,
            (SELECT COUNT(id) FROM teams WHERE hackathon_id = ?) AS teams,
            (SELECT COUNT(id) FROM rounds WHERE hackathon_id = ?) AS rounds'
    );
    $statsStmt->execute([(int) $hackathon['id'], (int) $hackathon['id'], (int) $hackathon['id']]);
    $stats = $statsStmt->fetch() ?: $stats;

    $recentParticipantsStmt = $pdo->prepare(
        'SELECT name, participant_type, registered_at
         FROM participants
         WHERE hackathon_id = ?
         ORDER BY registered_at DESC
         LIMIT 6'
    );
    $recentParticipantsStmt->execute([(int) $hackathon['id']]);
    $recentParticipants = $recentParticipantsStmt->fetchAll();

    $upcomingRoundsStmt = $pdo->prepare(
        'SELECT name, submission_deadline, status
         FROM rounds
         WHERE hackathon_id = ?
         ORDER BY round_number ASC'
    );
    $upcomingRoundsStmt->execute([(int) $hackathon['id']]);
    $upcomingRounds = $upcomingRoundsStmt->fetchAll();
}

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Event Control Center</h1>
        <p class="page-subtitle"><?= e($hackathon !== null ? (string) $hackathon['name'] : 'Create a hackathon to begin operations.') ?></p>
    </div>
</section>
<?php if ($hackathon === null): ?>
    <section class="card"><p class="empty-state">No hackathons exist yet. Create one to unlock the admin workflow.</p></section>
<?php else: ?>
    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card"><div class="stat-value" data-countup="<?= e((string) $stats['participants']) ?>"><?= e((string) $stats['participants']) ?></div><div class="stat-label">Participants</div></article>
        <article class="card"><div class="stat-value" data-countup="<?= e((string) $stats['teams']) ?>"><?= e((string) $stats['teams']) ?></div><div class="stat-label">Teams</div></article>
        <article class="card"><div class="stat-value" data-countup="<?= e((string) $stats['rounds']) ?>"><?= e((string) $stats['rounds']) ?></div><div class="stat-label">Rounds</div></article>
    </section>

    <section class="grid grid-3">
        <article class="card">
            <h2>Event Snapshot</h2>
            <div style="display:grid;gap:10px;margin-top:14px;">
                <div class="page-subtitle">Venue: <?= e((string) ($hackathon['venue'] ?? 'TBA')) ?></div>
                <div class="page-subtitle">Starts: <?= e(formatUtcToIst((string) $hackathon['starts_at'])) ?></div>
                <div class="page-subtitle">Registration Deadline: <?= e(formatUtcToIst((string) ($hackathon['registration_deadline'] ?? ''))) ?></div>
                <div><span class="badge <?= e(statusBadgeClass((string) $hackathon['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $hackathon['status']))) ?></span></div>
            </div>
        </article>

        <article class="card">
            <h2>Recent Participants</h2>
            <?php if ($recentParticipants === []): ?>
                <p class="empty-state" style="margin-top:12px;">No participants registered yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:10px;margin-top:14px;">
                    <?php foreach ($recentParticipants as $participant): ?>
                        <div class="card" style="padding:14px;">
                            <strong><?= e((string) $participant['name']) ?></strong>
                            <div class="page-subtitle" style="margin-top:6px;"><?= e(ucfirst((string) $participant['participant_type'])) ?> | <?= e(formatUtcToIst((string) $participant['registered_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="card">
            <h2>Rounds</h2>
            <?php if ($upcomingRounds === []): ?>
                <p class="empty-state" style="margin-top:12px;">No rounds created yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:10px;margin-top:14px;">
                    <?php foreach ($upcomingRounds as $round): ?>
                        <div class="card" style="padding:14px;">
                            <strong><?= e((string) $round['name']) ?></strong>
                            <div class="page-subtitle" style="margin-top:6px;"><?= e(formatUtcToIst((string) $round['submission_deadline'])) ?></div>
                            <div style="margin-top:8px;"><span class="badge <?= e(statusBadgeClass((string) $round['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $round['status']))) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
