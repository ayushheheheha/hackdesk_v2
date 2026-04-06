<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';

Middleware::requireParticipantAuth();

$pdo = Database::getConnection();
$hackathonId = (int) ($_SESSION['participant_hackathon_id'] ?? 0);

$hackathonStmt = $pdo->prepare('SELECT name, leaderboard_visible FROM hackathons WHERE id = ? LIMIT 1');
$hackathonStmt->execute([$hackathonId]);
$hackathon = $hackathonStmt->fetch() ?: null;

$rows = [];
if ($hackathon !== null && (int) $hackathon['leaderboard_visible'] === 1) {
    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.name AS team_name,
            ps.title AS problem_statement_title,
            ROUND(AVG(s2.total_score), 2) AS average_total_score
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         LEFT JOIN scores s2 ON s2.team_id = t.id
         WHERE t.hackathon_id = ?
         GROUP BY t.id, t.name, ps.title
         ORDER BY average_total_score DESC, t.name ASC'
    );
    $stmt->execute([$hackathonId]);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Leaderboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Leaderboard</h1>
        <p class="page-subtitle"><?= e((string) ($hackathon['name'] ?? 'Hackathon')) ?></p>
    </div>
</section>
<section class="card">
    <?php if ($hackathon === null || (int) $hackathon['leaderboard_visible'] !== 1): ?>
        <p class="empty-state">The leaderboard is currently hidden by the organizers.</p>
    <?php elseif ($rows === []): ?>
        <p class="empty-state">Scores have not been published yet.</p>
    <?php else: ?>
        <div class="table-shell">
            <table>
                <thead><tr><th>Rank</th><th>Team</th><th>Problem Statement</th><th>Total Score</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><?= e((string) ($index + 1)) ?></td>
                        <td><?= e((string) $row['team_name']) ?></td>
                        <td><?= e((string) ($row['problem_statement_title'] ?? 'Not Selected')) ?></td>
                        <td><?= e((string) ($row['average_total_score'] ?? '0.00')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
