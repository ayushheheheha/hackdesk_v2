<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('super_admin');

$pdo = Database::getConnection();

$statsStmt = $pdo->prepare(
    'SELECT
        (SELECT COUNT(h.id) FROM hackathons h) AS total_hackathons,
        (SELECT COUNT(p.id) FROM participants p) AS total_participants,
        (SELECT COUNT(h.id) FROM hackathons h WHERE h.status IN (?, ?, ?)) AS active_hackathons'
);
$statsStmt->execute(['registration_open', 'ongoing', 'judging']);
$stats = $statsStmt->fetch() ?: [
    'total_hackathons' => 0,
    'total_participants' => 0,
    'active_hackathons' => 0,
];

$recentHackathonsStmt = $pdo->prepare(
    'SELECT
        h.id,
        h.name,
        h.status,
        h.starts_at,
        h.ends_at,
        h.venue,
        u.name AS organizer_name
     FROM hackathons h
     INNER JOIN users u ON u.id = h.created_by
     ORDER BY h.created_at DESC, h.id DESC
     LIMIT 8'
);
$recentHackathonsStmt->execute();
$recentHackathons = $recentHackathonsStmt->fetchAll();

$activityStmt = $pdo->prepare(
    'SELECT
        al.id,
        al.action,
        al.entity_type,
        al.entity_id,
        al.created_at,
        u.name AS user_name,
        h.name AS hackathon_name
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     LEFT JOIN hackathons h ON h.id = al.hackathon_id
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 50'
);
$activityStmt->execute();
$recentActivity = $activityStmt->fetchAll();

$pageTitle = 'Super Admin Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Platform Overview</h1>
        <p class="page-subtitle">Monitor the entire HackDesk platform from one place.</p>
    </div>
</section>

<section class="grid grid-3" style="margin-bottom:24px;">
    <article class="card">
        <div class="stat-value" data-countup="<?= e((string) $stats['total_hackathons']) ?>"><?= e((string) $stats['total_hackathons']) ?></div>
        <div class="stat-label">Total Hackathons</div>
    </article>
    <article class="card">
        <div class="stat-value" data-countup="<?= e((string) $stats['total_participants']) ?>"><?= e((string) $stats['total_participants']) ?></div>
        <div class="stat-label">Total Participants Ever</div>
    </article>
    <article class="card">
        <div class="stat-value" data-countup="<?= e((string) $stats['active_hackathons']) ?>"><?= e((string) $stats['active_hackathons']) ?></div>
        <div class="stat-label">Active Hackathons</div>
    </article>
</section>

<section class="grid grid-3">
    <article class="card">
        <div class="page-header" style="margin-bottom:16px;">
            <div>
                <h2>Recent Hackathons</h2>
                <p class="page-subtitle">Latest events across the platform.</p>
            </div>
        </div>
        <?php if ($recentHackathons === []): ?>
            <p class="empty-state">No hackathons have been created yet.</p>
        <?php else: ?>
            <div style="display:grid;gap:12px;">
                <?php foreach ($recentHackathons as $hackathon): ?>
                    <a class="card" href="<?= e(appPath('portal/super-admin/hackathons.php?view=' . (int) $hackathon['id'])) ?>" style="padding:16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <strong><?= e((string) $hackathon['name']) ?></strong>
                            <span class="badge <?= e(statusBadgeClass((string) $hackathon['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $hackathon['status']))) ?></span>
                        </div>
                        <p class="page-subtitle" style="margin-top:8px;"><?= e((string) $hackathon['organizer_name']) ?> | <?= e((string) ($hackathon['venue'] ?? 'TBA')) ?></p>
                        <p class="page-subtitle" style="margin-top:6px;"><?= e(formatUtcToIst((string) $hackathon['starts_at'], 'd M Y')) ?> to <?= e(formatUtcToIst((string) $hackathon['ends_at'], 'd M Y')) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="card" style="grid-column: span 2;">
        <div class="page-header" style="margin-bottom:16px;">
            <div>
                <h2>Recent Activity</h2>
                <p class="page-subtitle">Last 50 platform actions from staff and admins.</p>
            </div>
            <a class="btn-ghost" href="<?= e(appPath('portal/super-admin/activity-log.php')) ?>">Open Full Log</a>
        </div>
        <?php if ($recentActivity === []): ?>
            <p class="empty-state">No activity has been logged yet.</p>
        <?php else: ?>
            <div class="table-shell">
                <table>
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Hackathon</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentActivity as $activity): ?>
                        <tr>
                            <td><?= e(formatUtcToIst((string) $activity['created_at'])) ?></td>
                            <td><?= e((string) ($activity['user_name'] ?? 'System')) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) $activity['action']))) ?></td>
                            <td><?= e(((string) ($activity['entity_type'] ?? '-')) . ((string) ($activity['entity_id'] ?? '') !== '' ? ' #' . $activity['entity_id'] : '')) ?></td>
                            <td><?= e((string) ($activity['hackathon_name'] ?? 'Platform')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
