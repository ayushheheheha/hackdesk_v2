<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('jury');

$pdo = Database::getConnection();
$juryUserId = (int) ($_SESSION['user']['id'] ?? 0);
$roundFilter = filter_input(INPUT_GET, 'round_id', FILTER_VALIDATE_INT);

$roundsStmt = $pdo->prepare(
    'SELECT DISTINCT r.id, r.name, r.round_number
     FROM jury_assignments ja
     INNER JOIN rounds r ON r.id = ja.round_id
     WHERE ja.jury_user_id = ?
     ORDER BY r.round_number ASC'
);
$roundsStmt->execute([$juryUserId]);
$rounds = $roundsStmt->fetchAll();

$params = [$juryUserId];
$where = ['ja.jury_user_id = ?'];
if ($roundFilter !== false && $roundFilter !== null) {
    $where[] = 'ja.round_id = ?';
    $params[] = $roundFilter;
}

$stmt = $pdo->prepare(
    'SELECT
        ja.id AS assignment_id,
        h.name AS hackathon_name,
        t.name AS team_name,
        ps.title AS problem_statement_title,
        r.name AS round_name,
        r.round_number,
        s.status AS submission_status,
        sc.total_score
     FROM jury_assignments ja
     INNER JOIN hackathons h ON h.id = ja.hackathon_id
     INNER JOIN teams t ON t.id = ja.team_id
     INNER JOIN rounds r ON r.id = ja.round_id
     LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
     LEFT JOIN submissions s ON s.team_id = ja.team_id AND s.round_id = ja.round_id
     LEFT JOIN scores sc ON sc.jury_assignment_id = ja.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY h.name ASC, r.round_number ASC, t.name ASC'
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$pageTitle = 'Jury Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div><h1>Jury Dashboard</h1><p class="page-subtitle">Your assigned teams and rounds for judging.</p></div>
</section>
<section class="card" style="margin-bottom:24px;">
    <form method="get" action="<?= e(appPath('portal/jury/dashboard.php')) ?>">
        <div class="form-group" style="max-width:320px;">
            <label for="round_id">Filter by Round</label>
            <select id="round_id" name="round_id" onchange="this.form.submit()">
                <option value="">All Assigned Rounds</option>
                <?php foreach ($rounds as $round): ?>
                    <option value="<?= e((string) $round['id']) ?>" <?= (int) $round['id'] === (int) $roundFilter ? 'selected' : '' ?>>Round <?= e((string) $round['round_number']) ?> - <?= e((string) $round['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</section>
<section class="card">
    <?php if ($assignments === []): ?>
        <p class="empty-state">No assignments found for this jury account.</p>
    <?php else: ?>
        <div class="table-shell">
            <table>
                <thead><tr><th>Team</th><th>Problem Statement</th><th>Round</th><th>Submission Status</th><th>Score Given</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($assignments as $assignment): ?>
                    <?php
                    $submissionStatus = $assignment['submission_status'] ?? 'not submitted';
                    $badgeClass = $assignment['total_score'] !== null ? 'badge-success' : ($submissionStatus === 'submitted' ? 'badge-muted' : 'badge-muted');
                    ?>
                    <tr>
                        <td><?= e((string) $assignment['team_name']) ?><br><span class="page-subtitle"><?= e((string) $assignment['hackathon_name']) ?></span></td>
                        <td><?= e((string) ($assignment['problem_statement_title'] ?? 'Not Selected')) ?></td>
                        <td><?= e((string) $assignment['round_name']) ?></td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst((string) $submissionStatus)) ?></span></td>
                        <td><?= e($assignment['total_score'] !== null ? (string) $assignment['total_score'] : 'Not scored') ?></td>
                        <td><a class="btn-ghost" href="<?= e(appPath('portal/jury/score.php?assignment_id=' . (int) $assignment['assignment_id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
