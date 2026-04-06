<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare(
    'SELECT
        h.id,
        h.name,
        h.tagline,
        h.description,
        h.venue,
        h.starts_at,
        h.ends_at,
        h.registration_deadline,
        h.ps_selection_deadline,
        h.min_team_size,
        h.max_team_size,
        h.leaderboard_visible,
        h.status,
        u.name AS organizer_name
     FROM hackathons h
     INNER JOIN users u ON u.id = h.created_by
     ORDER BY h.created_at DESC, h.id DESC'
);
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();

$pageTitle = 'Event Setup';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Event Details</h1><p class="page-subtitle">Review the live event configuration, deadlines, and team rules.</p></div></section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">No hackathons have been created yet.</p></section>
<?php else: ?>
    <section style="display:grid;gap:16px;">
        <?php foreach ($hackathons as $hackathon): ?>
            <article class="card">
                <div class="page-header" style="margin-bottom:16px;">
                    <div>
                        <h2><?= e((string) $hackathon['name']) ?></h2>
                        <p class="page-subtitle"><?= e((string) ($hackathon['tagline'] ?? '')) ?></p>
                    </div>
                    <span class="badge <?= e(statusBadgeClass((string) $hackathon['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $hackathon['status']))) ?></span>
                </div>
                <div class="grid grid-3">
                    <div>
                        <div class="stat-label">Organizer</div>
                        <p><?= e((string) $hackathon['organizer_name']) ?></p>
                    </div>
                    <div>
                        <div class="stat-label">Venue</div>
                        <p><?= e((string) ($hackathon['venue'] ?? 'TBA')) ?></p>
                    </div>
                    <div>
                        <div class="stat-label">Event Window</div>
                        <p><?= e(formatUtcToIst((string) $hackathon['starts_at'])) ?><br><?= e(formatUtcToIst((string) $hackathon['ends_at'])) ?></p>
                    </div>
                    <div>
                        <div class="stat-label">Registration Deadline</div>
                        <p><?= e(formatUtcToIst((string) ($hackathon['registration_deadline'] ?? ''))) ?></p>
                    </div>
                    <div>
                        <div class="stat-label">PS Selection Deadline</div>
                        <p><?= e(formatUtcToIst((string) ($hackathon['ps_selection_deadline'] ?? ''))) ?></p>
                    </div>
                    <div>
                        <div class="stat-label">Team Size</div>
                        <p><?= e((string) $hackathon['min_team_size']) ?> to <?= e((string) $hackathon['max_team_size']) ?></p>
                    </div>
                </div>
                <p style="margin-top:16px;"><?= e((string) ($hackathon['description'] ?? 'No event description provided yet.')) ?></p>
                <p class="page-subtitle" style="margin-top:12px;">Participant leaderboard visibility: <?= e((int) $hackathon['leaderboard_visible'] === 1 ? 'Visible to participants' : 'Hidden from participants') ?></p>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
