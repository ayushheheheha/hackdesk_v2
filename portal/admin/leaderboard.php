<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare('SELECT id, name, leaderboard_visible FROM hackathons ORDER BY created_at DESC, id DESC');
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();
$selectedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: (($hackathons[0]['id'] ?? null) !== null ? (int) $hackathons[0]['id'] : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/leaderboard.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    if ((string) ($_POST['action'] ?? '') === 'toggle_visibility' && $selectedHackathonId) {
        $target = isset($_POST['leaderboard_visible']) && $_POST['leaderboard_visible'] === '1' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE hackathons SET leaderboard_visible = ? WHERE id = ?');
        $stmt->execute([$target, $selectedHackathonId]);
        flash('success', $target === 1 ? 'Leaderboard is now visible to participants.' : 'Leaderboard hidden from participants.');
        redirect('portal/admin/leaderboard.php?hackathon_id=' . $selectedHackathonId);
    }
}

$rows = [];
$leaderboardVisible = 0;
if ($selectedHackathonId) {
    $visibilityStmt = $pdo->prepare('SELECT leaderboard_visible FROM hackathons WHERE id = ? LIMIT 1');
    $visibilityStmt->execute([$selectedHackathonId]);
    $leaderboardVisible = (int) ($visibilityStmt->fetch()['leaderboard_visible'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.name AS team_name,
            ps.title AS problem_statement_title,
            ROUND(AVG(s.total_score), 2) AS average_total_score,
            GROUP_CONCAT(DISTINCT CONCAT(r.round_number, ": ", COALESCE(s.total_score, "NA")) ORDER BY r.round_number SEPARATOR " | ") AS round_scores
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         LEFT JOIN rounds r ON r.hackathon_id = t.hackathon_id
         LEFT JOIN scores s ON s.team_id = t.id AND s.round_id = r.id
         WHERE t.hackathon_id = ?
         GROUP BY t.id, t.name, ps.title
         ORDER BY average_total_score DESC, t.name ASC'
    );
    $stmt->execute([$selectedHackathonId]);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Leaderboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Leaderboard</h1><p class="page-subtitle">Rank teams by average jury score across rounds and control participant visibility.</p></div></section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon before viewing the leaderboard.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/leaderboard.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="post" action="<?= e(appPath('portal/admin/leaderboard.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:12px;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="toggle_visibility">
            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
            <input type="hidden" name="leaderboard_visible" value="<?= $leaderboardVisible === 1 ? '0' : '1' ?>">
            <button type="submit" class="<?= $leaderboardVisible === 1 ? 'btn-ghost' : 'btn-primary' ?>"><?= $leaderboardVisible === 1 ? 'Hide from Participants' : 'Show to Participants' ?></button>
        </form>
    </section>

    <section class="card">
        <?php if ($rows === []): ?>
            <p class="empty-state">No scores are available yet.</p>
        <?php else: ?>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Rank</th><th>Team</th><th>Problem Statement</th><th>Scores by Round</th><th>Total Score</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr style="<?= $index < 3 ? 'background:rgba(91,91,214,0.08);' : '' ?>">
                            <td><?= e((string) ($index + 1)) ?></td>
                            <td><?= e((string) $row['team_name']) ?></td>
                            <td><?= e((string) ($row['problem_statement_title'] ?? 'Not Selected')) ?></td>
                            <td><?= e((string) ($row['round_scores'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['average_total_score'] ?? '0.00')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
