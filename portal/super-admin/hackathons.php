<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('super_admin');

$pdo = Database::getConnection();
$selectedHackathonId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT);
$statusFilter = trim((string) ($_GET['status'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/super-admin/hackathons.php' . ($selectedHackathonId !== null ? '?view=' . $selectedHackathonId : ''));
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    $hackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT);

    if ($hackathonId === false || $hackathonId === null) {
        flash('error', 'Invalid hackathon selected.');
        redirect('portal/super-admin/hackathons.php');
    }

    if ($action === 'change_status') {
        $allowedStatuses = ['draft', 'registration_open', 'ongoing', 'judging', 'completed', 'cancelled'];
        $newStatus = trim((string) ($_POST['status'] ?? ''));

        if (!in_array($newStatus, $allowedStatuses, true)) {
            flash('error', 'Invalid hackathon status selected.');
            redirect('portal/super-admin/hackathons.php?view=' . $hackathonId);
        }

        $stmt = $pdo->prepare('UPDATE hackathons SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $hackathonId]);
        logActivity('hackathon_status_changed', 'hackathon', $hackathonId, ['status' => $newStatus], $hackathonId);
        flash('success', 'Hackathon status updated.');
        redirect('portal/super-admin/hackathons.php?view=' . $hackathonId);
    }

    if ($action === 'cancel') {
        $stmt = $pdo->prepare('UPDATE hackathons SET status = ? WHERE id = ?');
        $stmt->execute(['cancelled', $hackathonId]);
        logActivity('hackathon_cancelled', 'hackathon', $hackathonId, null, $hackathonId);
        flash('success', 'Hackathon cancelled.');
        redirect('portal/super-admin/hackathons.php?view=' . $hackathonId);
    }
}

$where = '';
$params = [];
if ($statusFilter !== '' && in_array($statusFilter, ['draft', 'registration_open', 'ongoing', 'judging', 'completed', 'cancelled'], true)) {
    $where = 'WHERE h.status = ?';
    $params[] = $statusFilter;
}

$hackathonsStmt = $pdo->prepare(
    'SELECT
        h.id,
        h.name,
        h.status,
        h.starts_at,
        h.ends_at,
        h.registration_deadline,
        h.venue,
        u.name AS organizer_name,
        COUNT(DISTINCT p.id) AS participant_count
     FROM hackathons h
     INNER JOIN users u ON u.id = h.created_by
     LEFT JOIN participants p ON p.hackathon_id = h.id
     ' . $where . '
     GROUP BY h.id, h.name, h.status, h.starts_at, h.ends_at, h.registration_deadline, h.venue, u.name
     ORDER BY h.created_at DESC, h.id DESC'
);
$hackathonsStmt->execute($params);
$hackathons = $hackathonsStmt->fetchAll();

$hackathonDetails = null;
$rounds = [];
$recentActivity = [];
if ($selectedHackathonId !== false && $selectedHackathonId !== null) {
    $detailStmt = $pdo->prepare(
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
            h.status,
            h.created_at,
            u.name AS organizer_name,
            u.email AS organizer_email,
            COUNT(DISTINCT p.id) AS participants_count,
            SUM(CASE WHEN p.check_in_status = "checked_in" THEN 1 ELSE 0 END) AS checked_in_count,
            COUNT(DISTINCT t.id) AS teams_count,
            COUNT(DISTINCT r.id) AS rounds_count,
            COUNT(DISTINCT c.id) AS certificates_count
         FROM hackathons h
         INNER JOIN users u ON u.id = h.created_by
         LEFT JOIN participants p ON p.hackathon_id = h.id
         LEFT JOIN teams t ON t.hackathon_id = h.id
         LEFT JOIN rounds r ON r.hackathon_id = h.id
         LEFT JOIN certificates c ON c.hackathon_id = h.id
         WHERE h.id = ?
         GROUP BY h.id, h.name, h.tagline, h.description, h.venue, h.starts_at, h.ends_at, h.registration_deadline, h.ps_selection_deadline, h.min_team_size, h.max_team_size, h.status, h.created_at, u.name, u.email
         LIMIT 1'
    );
    $detailStmt->execute([$selectedHackathonId]);
    $hackathonDetails = $detailStmt->fetch() ?: null;

    if ($hackathonDetails !== null) {
        $roundsStmt = $pdo->prepare(
            'SELECT id, name, round_number, submission_deadline, status
             FROM rounds
             WHERE hackathon_id = ?
             ORDER BY round_number ASC'
        );
        $roundsStmt->execute([$selectedHackathonId]);
        $rounds = $roundsStmt->fetchAll();

        $activityStmt = $pdo->prepare(
            'SELECT
                al.action,
                al.entity_type,
                al.entity_id,
                al.created_at,
                u.name AS user_name
             FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.hackathon_id = ?
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 12'
        );
        $activityStmt->execute([$selectedHackathonId]);
        $recentActivity = $activityStmt->fetchAll();
    }
}

$pageTitle = 'Hackathons';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Hackathons</h1>
        <p class="page-subtitle">Monitor every event on the platform and override statuses when needed.</p>
    </div>
</section>

<section class="grid grid-3" style="margin-bottom:24px;">
    <article class="card" style="grid-column: span 2;">
        <h2>Filter Hackathons</h2>
        <form method="get" action="<?= e(appPath('portal/super-admin/hackathons.php')) ?>" style="margin-top:16px;">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="status_filter">Status Filter</label>
                    <select id="status_filter" name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="registration_open" <?= $statusFilter === 'registration_open' ? 'selected' : '' ?>>Registration Open</option>
                        <option value="ongoing" <?= $statusFilter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                        <option value="judging" <?= $statusFilter === 'judging' ? 'selected' : '' ?>>Judging</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary">Apply Filter</button>
        </form>
    </article>
    <article class="card">
        <h2>Create Hackathon</h2>
        <p class="page-subtitle" style="margin:10px 0 16px;">Open the dedicated creation page to enter all event details cleanly.</p>
        <a class="btn-primary" href="<?= e(appPath('portal/super-admin/create-hackathon.php')) ?>">Open Create Form</a>
    </article>
</section>

<section class="grid grid-3">
    <article class="card" style="grid-column: span 2;">
        <div class="table-shell">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Organizer</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Participants</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($hackathons === []): ?>
                    <tr><td colspan="6">No hackathons matched the current filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($hackathons as $hackathon): ?>
                        <tr>
                            <td><?= e((string) $hackathon['name']) ?></td>
                            <td><?= e((string) $hackathon['organizer_name']) ?></td>
                            <td><?= e(formatUtcToIst((string) $hackathon['starts_at'], 'd M Y')) ?><br><span class="page-subtitle"><?= e(formatUtcToIst((string) $hackathon['ends_at'], 'd M Y')) ?></span></td>
                            <td><span class="badge <?= e(statusBadgeClass((string) $hackathon['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $hackathon['status']))) ?></span></td>
                            <td><?= e((string) $hackathon['participant_count']) ?></td>
                            <td><a class="btn-ghost" href="<?= e(appPath('portal/super-admin/hackathons.php?view=' . (int) $hackathon['id'])) ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <h2>Hackathon Detail</h2>
        <?php if ($hackathonDetails === null): ?>
            <p class="empty-state" style="margin-top:12px;">Select a hackathon to inspect full details.</p>
        <?php else: ?>
            <div style="display:grid;gap:10px;margin-top:14px;">
                <div><strong><?= e((string) $hackathonDetails['name']) ?></strong></div>
                <div class="page-subtitle"><?= e((string) ($hackathonDetails['tagline'] ?? '')) ?></div>
                <div><?= e((string) ($hackathonDetails['description'] ?? 'No description provided.')) ?></div>
                <div class="page-subtitle">Organizer: <?= e((string) $hackathonDetails['organizer_name']) ?> (<?= e((string) $hackathonDetails['organizer_email']) ?>)</div>
                <div class="page-subtitle">Venue: <?= e((string) ($hackathonDetails['venue'] ?? 'TBA')) ?></div>
                <div class="page-subtitle">Event Window: <?= e(formatUtcToIst((string) $hackathonDetails['starts_at'])) ?> to <?= e(formatUtcToIst((string) $hackathonDetails['ends_at'])) ?></div>
                <div class="page-subtitle">Registration Deadline: <?= e(formatUtcToIst((string) ($hackathonDetails['registration_deadline'] ?? ''))) ?></div>
            </div>

            <div class="grid grid-3" style="margin-top:18px;">
                <div class="card" style="padding:16px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) $hackathonDetails['participants_count']) ?></div>
                    <div class="stat-label">Participants</div>
                </div>
                <div class="card" style="padding:16px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) ($hackathonDetails['teams_count'] ?? 0)) ?></div>
                    <div class="stat-label">Teams</div>
                </div>
                <div class="card" style="padding:16px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) ($hackathonDetails['rounds_count'] ?? 0)) ?></div>
                    <div class="stat-label">Rounds</div>
                </div>
            </div>

            <form method="post" action="<?= e(appPath('portal/super-admin/hackathons.php?view=' . (int) $hackathonDetails['id'])) ?>" style="margin-top:18px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $hackathonDetails['id']) ?>">
                <div class="form-group">
                    <label for="detail_status">Change Status</label>
                    <select id="detail_status" name="status">
                        <option value="draft" <?= $hackathonDetails['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="registration_open" <?= $hackathonDetails['status'] === 'registration_open' ? 'selected' : '' ?>>Registration Open</option>
                        <option value="ongoing" <?= $hackathonDetails['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                        <option value="judging" <?= $hackathonDetails['status'] === 'judging' ? 'selected' : '' ?>>Judging</option>
                        <option value="completed" <?= $hackathonDetails['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $hackathonDetails['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Update Status</button>
            </form>

            <form method="post" action="<?= e(appPath('portal/super-admin/hackathons.php?view=' . (int) $hackathonDetails['id'])) ?>" style="margin-top:10px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $hackathonDetails['id']) ?>">
                <button type="submit" class="btn-ghost">Soft Delete (Cancel)</button>
            </form>

            <h3 style="margin-top:24px;">Rounds</h3>
            <?php if ($rounds === []): ?>
                <p class="empty-state" style="margin-top:10px;">No rounds created yet.</p>
            <?php else: ?>
                <div class="table-shell" style="margin-top:10px;">
                    <table>
                        <thead><tr><th>#</th><th>Name</th><th>Deadline</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($rounds as $round): ?>
                            <tr>
                                <td><?= e((string) $round['round_number']) ?></td>
                                <td><?= e((string) $round['name']) ?></td>
                                <td><?= e(formatUtcToIst((string) $round['submission_deadline'])) ?></td>
                                <td><span class="badge <?= e(statusBadgeClass((string) $round['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $round['status']))) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3 style="margin-top:24px;">Recent Activity</h3>
            <?php if ($recentActivity === []): ?>
                <p class="empty-state" style="margin-top:10px;">No activity logged for this hackathon yet.</p>
            <?php else: ?>
                <div style="display:grid;gap:10px;margin-top:10px;">
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="card" style="padding:14px;">
                            <strong><?= e(ucwords(str_replace('_', ' ', (string) $activity['action']))) ?></strong>
                            <div class="page-subtitle" style="margin-top:6px;"><?= e((string) ($activity['user_name'] ?? 'System')) ?> | <?= e(formatUtcToIst((string) $activity['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </article>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
