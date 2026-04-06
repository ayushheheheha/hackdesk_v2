<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare('SELECT id, name FROM hackathons ORDER BY created_at DESC, id DESC');
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();
$selectedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: (($hackathons[0]['id'] ?? null) !== null ? (int) $hackathons[0]['id'] : null);
$selectedRoundId = filter_input(INPUT_POST, 'round_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'round_id', FILTER_VALIDATE_INT);

$rounds = [];
if ($selectedHackathonId !== null) {
    $roundsStmt = $pdo->prepare('SELECT id, name, round_number FROM rounds WHERE hackathon_id = ? ORDER BY round_number ASC');
    $roundsStmt->execute([$selectedHackathonId]);
    $rounds = $roundsStmt->fetchAll();
    if (($selectedRoundId === false || $selectedRoundId === null) && $rounds !== []) {
        $selectedRoundId = (int) $rounds[0]['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/judging.php?hackathon_id=' . $selectedHackathonId . '&round_id=' . $selectedRoundId);
    }

    if ((string) ($_POST['action'] ?? '') === 'finalize_round' && $selectedRoundId) {
        $stmt = $pdo->prepare('UPDATE rounds SET status = ? WHERE id = ? AND hackathon_id = ?');
        $stmt->execute(['judging_done', $selectedRoundId, $selectedHackathonId]);
        flash('success', 'Round finalized successfully.');
        redirect('portal/admin/judging.php?hackathon_id=' . $selectedHackathonId . '&round_id=' . $selectedRoundId);
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selectedRoundId) {
    $stmt = $pdo->prepare(
        'SELECT
            t.name AS team_name,
            ps.title AS problem_statement_title,
            u.name AS jury_name,
            s.criteria_scores,
            s.total_score,
            s.remarks
         FROM scores s
         INNER JOIN teams t ON t.id = s.team_id
         INNER JOIN jury_assignments ja ON ja.id = s.jury_assignment_id
         INNER JOIN users u ON u.id = ja.jury_user_id
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         WHERE s.round_id = ?
         ORDER BY t.name ASC, u.name ASC'
    );
    $stmt->execute([$selectedRoundId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="judging-round-' . $selectedRoundId . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['team', 'problem_statement', 'jury_member', 'criteria_scores', 'total', 'remarks']);
    foreach ($rows as $row) {
        fputcsv($output, [$row['team_name'], $row['problem_statement_title'], $row['jury_name'], $row['criteria_scores'], $row['total_score'], $row['remarks']]);
    }
    fclose($output);
    exit;
}

$overviewRows = [];
if ($selectedRoundId) {
    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.name AS team_name,
            ps.title AS problem_statement_title,
            GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ", ") AS members,
            COUNT(DISTINCT ja.id) AS assigned_jury_count,
            COUNT(DISTINCT s.id) AS scores_given_count,
            ROUND(AVG(s.total_score), 2) AS average_score,
            GROUP_CONCAT(DISTINCT CONCAT(u.name, ": ", COALESCE(s.total_score, "NA")) ORDER BY u.name SEPARATOR " | ") AS individual_scores
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         LEFT JOIN team_members tm ON tm.team_id = t.id
         LEFT JOIN participants p ON p.id = tm.participant_id
         LEFT JOIN jury_assignments ja ON ja.team_id = t.id AND ja.round_id = ?
         LEFT JOIN users u ON u.id = ja.jury_user_id
         LEFT JOIN scores s ON s.jury_assignment_id = ja.id
         WHERE t.hackathon_id = ?
         GROUP BY t.id, t.name, ps.title
         ORDER BY t.name ASC'
    );
    $stmt->execute([$selectedRoundId, $selectedHackathonId]);
    $overviewRows = $stmt->fetchAll();
}

$pageTitle = 'Judging';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div><h1>Judging</h1><p class="page-subtitle">Monitor jury completion, averages, and round finalization.</p></div>
</section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon and round before reviewing judging.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/judging.php')) ?>">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="hackathon_id">Hackathon</label>
                    <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                        <?php foreach ($hackathons as $hackathon): ?>
                            <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="round_id">Round</label>
                    <select id="round_id" name="round_id" onchange="this.form.submit()">
                        <?php foreach ($rounds as $round): ?>
                            <option value="<?= e((string) $round['id']) ?>" <?= (int) $round['id'] === (int) $selectedRoundId ? 'selected' : '' ?>>Round <?= e((string) $round['round_number']) ?> - <?= e((string) $round['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        <?php if ($selectedRoundId): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" action="<?= e(appPath('portal/admin/judging.php?hackathon_id=' . (int) $selectedHackathonId . '&round_id=' . (int) $selectedRoundId)) ?>">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="finalize_round">
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                    <input type="hidden" name="round_id" value="<?= e((string) $selectedRoundId) ?>">
                    <button type="submit" class="btn-primary">Finalize Round</button>
                </form>
                <a class="btn-ghost" href="<?= e(appPath('portal/admin/judging.php?hackathon_id=' . (int) $selectedHackathonId . '&round_id=' . (int) $selectedRoundId . '&export=csv')) ?>">Export Scores CSV</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <?php if ($overviewRows === []): ?>
            <p class="empty-state">No teams or assignments found for this round.</p>
        <?php else: ?>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Team</th><th>PS</th><th>Members</th><th>Assigned Jury</th><th>Scores Given</th><th>Average</th><th>Individual Jury Scores</th></tr></thead>
                    <tbody>
                    <?php foreach ($overviewRows as $row): ?>
                        <?php
                        $statusColor = ((int) $row['assigned_jury_count'] > 0 && (int) $row['scores_given_count'] === (int) $row['assigned_jury_count']) ? 'badge-success' : (((int) $row['scores_given_count'] > 0) ? 'badge-muted' : 'badge-muted');
                        ?>
                        <tr>
                            <td><?= e((string) $row['team_name']) ?></td>
                            <td><?= e((string) ($row['problem_statement_title'] ?? 'Not Selected')) ?></td>
                            <td><?= e((string) ($row['members'] ?? '-')) ?></td>
                            <td><?= e((string) $row['assigned_jury_count']) ?></td>
                            <td><span class="badge <?= e($statusColor) ?>"><?= e((string) $row['scores_given_count']) ?></span></td>
                            <td><?= e((string) ($row['average_score'] ?? '0.00')) ?></td>
                            <td><?= e((string) ($row['individual_scores'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
