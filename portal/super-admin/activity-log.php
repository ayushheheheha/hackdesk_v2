<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('super_admin');

$pdo = Database::getConnection();
$hackathonId = filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT);
$actionFilter = trim((string) ($_GET['action'] ?? ''));

$hackathonsStmt = $pdo->prepare('SELECT id, name FROM hackathons ORDER BY name ASC');
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();

$where = [];
$params = [];

if ($hackathonId !== false && $hackathonId !== null) {
    $where[] = 'al.hackathon_id = ?';
    $params[] = $hackathonId;
}

if ($actionFilter !== '') {
    $where[] = 'al.action LIKE ?';
    $params[] = '%' . $actionFilter . '%';
}

$sql = 'SELECT
            al.id,
            al.action,
            al.entity_type,
            al.entity_id,
            al.metadata,
            al.ip_address,
            al.created_at,
            u.name AS user_name,
            h.name AS hackathon_name
        FROM activity_log al
        LEFT JOIN users u ON u.id = al.user_id
        LEFT JOIN hackathons h ON h.id = al.hackathon_id';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY al.created_at DESC, al.id DESC LIMIT 200';

$activityStmt = $pdo->prepare($sql);
$activityStmt->execute($params);
$activity = $activityStmt->fetchAll();

$pageTitle = 'Activity Log';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Activity Log</h1>
        <p class="page-subtitle">Review the most recent platform actions and trace operational history.</p>
    </div>
</section>

<section class="card" style="margin-bottom:24px;">
    <form method="get" action="<?= e(appPath('portal/super-admin/activity-log.php')) ?>">
        <div class="grid grid-3">
            <div class="form-group">
                <label for="hackathon_id">Hackathon</label>
                <select id="hackathon_id" name="hackathon_id">
                    <option value="">All Hackathons</option>
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $hackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="action">Action Contains</label>
                <input id="action" name="action" type="text" value="<?= e($actionFilter) ?>" placeholder="login, certificate, team">
            </div>
        </div>
        <button type="submit" class="btn-primary">Apply Filters</button>
    </form>
</section>

<section class="card">
    <?php if ($activity === []): ?>
        <p class="empty-state">No activity entries matched the current filters.</p>
    <?php else: ?>
        <div class="table-shell">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Hackathon</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>IP</th>
                    <th>Metadata</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($activity as $row): ?>
                    <tr>
                        <td><?= e(formatUtcToIst((string) $row['created_at'])) ?></td>
                        <td><?= e((string) ($row['user_name'] ?? 'System')) ?></td>
                        <td><?= e((string) ($row['hackathon_name'] ?? 'Platform')) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $row['action']))) ?></td>
                        <td><?= e(((string) ($row['entity_type'] ?? '-')) . ((string) ($row['entity_id'] ?? '') !== '' ? ' #' . $row['entity_id'] : '')) ?></td>
                        <td><?= e((string) ($row['ip_address'] ?? '-')) ?></td>
                        <td><code><?= e((string) ($row['metadata'] ?? '{}')) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
