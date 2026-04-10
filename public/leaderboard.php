<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/helpers.php';

$pdo = Database::getConnection();
$hackathonId = filter_input(INPUT_GET, 'h', FILTER_VALIDATE_INT);

if ($hackathonId === false || $hackathonId === null) {
    $hackathonStmt = $pdo->prepare(
        'SELECT id
         FROM hackathons
         WHERE leaderboard_visible = 1
         ORDER BY starts_at DESC, id DESC
         LIMIT 1'
    );
    $hackathonStmt->execute();
    $hackathonId = (int) ($hackathonStmt->fetchColumn() ?: 0);
}

$fetchLeaderboard = static function (PDO $pdo, int $hackathonId): array {
    $hackathonStmt = $pdo->prepare(
        'SELECT id, name, starts_at, venue, leaderboard_visible
         FROM hackathons
         WHERE id = ?
         LIMIT 1'
    );
    $hackathonStmt->execute([$hackathonId]);
    $hackathon = $hackathonStmt->fetch();
    if ($hackathon === false || (int) $hackathon['leaderboard_visible'] !== 1) {
        return ['hackathon' => null, 'rows' => []];
    }

    $rowsStmt = $pdo->prepare(
        'SELECT
            t.id,
            t.name AS team_name,
            COALESCE(ps.title, "Not Selected") AS problem_statement_title,
            ROUND(AVG(s.total_score), 2) AS average_total_score
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         LEFT JOIN rounds r ON r.hackathon_id = t.hackathon_id
         LEFT JOIN scores s ON s.team_id = t.id AND s.round_id = r.id
         WHERE t.hackathon_id = ?
         GROUP BY t.id, t.name, ps.title
         ORDER BY average_total_score DESC, t.name ASC'
    );
    $rowsStmt->execute([$hackathonId]);

    return ['hackathon' => $hackathon, 'rows' => $rowsStmt->fetchAll()];
};

$payload = $hackathonId > 0 ? $fetchLeaderboard($pdo, $hackathonId) : ['hackathon' => null, 'rows' => []];

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$pageTitle = 'Live Leaderboard';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Live Leaderboard</h1>
        <p class="page-subtitle" id="leaderboard-subtitle">
            <?php if ($payload['hackathon'] !== null): ?>
                <?= e((string) $payload['hackathon']['name']) ?> | <?= e(formatUtcToIst((string) $payload['hackathon']['starts_at'], 'd M Y')) ?> | <?= e((string) ($payload['hackathon']['venue'] ?? 'TBA')) ?>
            <?php else: ?>
                No public leaderboard is currently available.
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="card">
    <div class="table-shell">
        <table id="leaderboard-table">
            <thead><tr><th>Rank</th><th>Team</th><th>Problem Statement</th><th>Total Score</th></tr></thead>
            <tbody>
            <?php if ($payload['rows'] === []): ?>
                <tr><td colspan="4">No ranked teams yet.</td></tr>
            <?php else: ?>
                <?php foreach ($payload['rows'] as $index => $row): ?>
                    <tr<?= $index < 3 ? ' style="background:rgba(31,42,68,0.08);"' : '' ?>>
                        <td><?= e((string) ($index + 1)) ?></td>
                        <td><?= e((string) $row['team_name']) ?></td>
                        <td><?= e((string) $row['problem_statement_title']) ?></td>
                        <td><?= e((string) ($row['average_total_score'] ?? '0.00')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
const tableBody = document.querySelector('#leaderboard-table tbody');
const subtitle = document.getElementById('leaderboard-subtitle');
const hackathonId = <?= json_encode((int) $hackathonId) ?>;
const endpoint = <?= json_encode(appPath('public/leaderboard.php?h=' . (int) $hackathonId . '&ajax=1')) ?>;

async function refreshLeaderboard() {
    try {
        const response = await fetch(endpoint, { cache: 'no-store' });
        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        if (!payload.hackathon || !Array.isArray(payload.rows)) {
            return;
        }

        subtitle.textContent = `${payload.hackathon.name} | live auto-refresh every 15s`;
        if (payload.rows.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="4">No ranked teams yet.</td></tr>';
            return;
        }

        tableBody.innerHTML = payload.rows.map((row, index) => `
            <tr ${index < 3 ? 'style="background:rgba(31,42,68,0.08);"' : ''}>
                <td>${index + 1}</td>
                <td>${row.team_name ?? ''}</td>
                <td>${row.problem_statement_title ?? ''}</td>
                <td>${row.average_total_score ?? '0.00'}</td>
            </tr>
        `).join('');
    } catch (error) {
        // Keep current view when fetch fails.
    }
}

setInterval(refreshLeaderboard, 15000);
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

