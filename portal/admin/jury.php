<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$requestedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT);
$hackathons = getAccessibleHackathons($pdo);
$selectedHackathonId = resolveSelectedHackathonId($pdo, $requestedHackathonId);
$selectedRoundId = filter_input(INPUT_POST, 'round_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'round_id', FILTER_VALIDATE_INT);

$rounds = [];
if ($selectedHackathonId !== null) {
    $roundsStmt = $pdo->prepare('SELECT id, name, round_number, status FROM rounds WHERE hackathon_id = ? ORDER BY round_number ASC');
    $roundsStmt->execute([$selectedHackathonId]);
    $rounds = $roundsStmt->fetchAll();
    if ($selectedRoundId === false || $selectedRoundId === null) {
        $selectedRoundId = (int) ($rounds[0]['id'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/jury.php?hackathon_id=' . $selectedHackathonId . '&round_id=' . $selectedRoundId);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle_assignment') {
        $juryUserId = filter_input(INPUT_POST, 'jury_user_id', FILTER_VALIDATE_INT);
        $teamId = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
        $checked = isset($_POST['checked']) && $_POST['checked'] === '1';

        if ($juryUserId && $teamId && $selectedHackathonId && $selectedRoundId) {
            if ($checked) {
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO jury_assignments (jury_user_id, hackathon_id, round_id, team_id, assigned_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$juryUserId, $selectedHackathonId, $selectedRoundId, $teamId, (int) ($_SESSION['user']['id'] ?? 0)]);
            } else {
                $checkStmt = $pdo->prepare(
                    'SELECT s.id
                     FROM scores s
                     INNER JOIN jury_assignments ja ON ja.id = s.jury_assignment_id
                     WHERE ja.jury_user_id = ? AND ja.round_id = ? AND ja.team_id = ?
                     LIMIT 1'
                );
                $checkStmt->execute([$juryUserId, $selectedRoundId, $teamId]);
                if ($checkStmt->fetch() === false) {
                    $stmt = $pdo->prepare(
                        'DELETE FROM jury_assignments
                         WHERE jury_user_id = ? AND round_id = ? AND team_id = ? AND hackathon_id = ?'
                    );
                    $stmt->execute([$juryUserId, $selectedRoundId, $teamId, $selectedHackathonId]);
                } else {
                    flash('error', 'Assignments with recorded scores cannot be removed.');
                }
            }
        }

        redirect('portal/admin/jury.php?hackathon_id=' . $selectedHackathonId . '&round_id=' . $selectedRoundId);
    }

    if ($action === 'auto_assign' && $selectedHackathonId && $selectedRoundId) {
        $juryStmt = $pdo->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1 ORDER BY id ASC');
        $juryStmt->execute(['jury']);
        $juryIds = array_map(static fn(array $row): int => (int) $row['id'], $juryStmt->fetchAll());

        $teamStmt = $pdo->prepare('SELECT id FROM teams WHERE hackathon_id = ? ORDER BY RAND()');
        $teamStmt->execute([$selectedHackathonId]);
        $teamIds = array_map(static fn(array $row): int => (int) $row['id'], $teamStmt->fetchAll());

        if ($juryIds !== [] && $teamIds !== []) {
            foreach ($teamIds as $index => $teamId) {
                $juryUserId = $juryIds[$index % count($juryIds)];
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO jury_assignments (jury_user_id, hackathon_id, round_id, team_id, assigned_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$juryUserId, $selectedHackathonId, $selectedRoundId, $teamId, (int) ($_SESSION['user']['id'] ?? 0)]);
            }
            flash('success', 'Teams auto-assigned evenly across jury members.');
        } else {
            flash('error', 'You need at least one jury member and one team for auto-assignment.');
        }

        redirect('portal/admin/jury.php?hackathon_id=' . $selectedHackathonId . '&round_id=' . $selectedRoundId);
    }
}

$juryMembers = [];
$teams = [];
$assignmentMap = [];
$assignmentCounts = [];
$unassignedTeams = [];

if ($selectedHackathonId !== null && $selectedRoundId) {
    $juryStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE role = ? AND is_active = 1 ORDER BY name ASC');
    $juryStmt->execute(['jury']);
    $juryMembers = $juryStmt->fetchAll();

    $teamsStmt = $pdo->prepare(
        'SELECT t.id, t.name, ps.title AS problem_statement_title
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         WHERE t.hackathon_id = ?
         ORDER BY t.name ASC'
    );
    $teamsStmt->execute([$selectedHackathonId]);
    $teams = $teamsStmt->fetchAll();

    $assignmentsStmt = $pdo->prepare('SELECT jury_user_id, team_id FROM jury_assignments WHERE hackathon_id = ? AND round_id = ?');
    $assignmentsStmt->execute([$selectedHackathonId, $selectedRoundId]);
    foreach ($assignmentsStmt->fetchAll() as $assignment) {
        $assignmentMap[(int) $assignment['team_id']][(int) $assignment['jury_user_id']] = true;
        $assignmentCounts[(int) $assignment['jury_user_id']] = ($assignmentCounts[(int) $assignment['jury_user_id']] ?? 0) + 1;
    }

    foreach ($teams as $team) {
        if (empty($assignmentMap[(int) $team['id']])) {
            $unassignedTeams[] = $team['name'];
        }
    }
}

$pageTitle = 'Jury';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Jury Assignment</h1>
        <p class="page-subtitle">Assign jury members to teams round by round and balance review load.</p>
    </div>
</section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon and rounds before assigning jury.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/jury.php')) ?>">
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
                            <option value="<?= e((string) $round['id']) ?>" <?= (int) $round['id'] === (int) $selectedRoundId ? 'selected' : '' ?>><?= e((string) $round['round_number']) ?> - <?= e((string) $round['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        <?php if ($selectedRoundId): ?>
            <form method="post" action="<?= e(appPath('portal/admin/jury.php?hackathon_id=' . (int) $selectedHackathonId . '&round_id=' . (int) $selectedRoundId)) ?>" style="margin-top:12px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="auto_assign">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <input type="hidden" name="round_id" value="<?= e((string) $selectedRoundId) ?>">
                <button type="submit" class="btn-primary">Auto-Assign</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($selectedRoundId && $juryMembers !== [] && $teams !== []): ?>
        <?php if ($unassignedTeams !== []): ?>
            <div class="flash flash-error">Warning: <?= e(implode(', ', $unassignedTeams)) ?> currently have no jury assigned for this round.</div>
        <?php endif; ?>
        <section class="card">
            <div class="table-shell">
                <table>
                    <thead>
                    <tr>
                        <th>Team</th>
                        <?php foreach ($juryMembers as $juryMember): ?>
                            <th><?= e((string) $juryMember['name']) ?><br><span class="page-subtitle"><?= e((string) ($assignmentCounts[(int) $juryMember['id']] ?? 0)) ?> assigned</span></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teams as $team): ?>
                        <tr>
                            <td><?= e((string) $team['name']) ?><br><span class="page-subtitle"><?= e((string) ($team['problem_statement_title'] ?? 'No PS')) ?></span></td>
                            <?php foreach ($juryMembers as $juryMember): ?>
                                <?php $checked = isset($assignmentMap[(int) $team['id']][(int) $juryMember['id']]); ?>
                                <td>
                                    <form method="post" action="<?= e(appPath('portal/admin/jury.php?hackathon_id=' . (int) $selectedHackathonId . '&round_id=' . (int) $selectedRoundId)) ?>">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_assignment">
                                        <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                        <input type="hidden" name="round_id" value="<?= e((string) $selectedRoundId) ?>">
                                        <input type="hidden" name="team_id" value="<?= e((string) $team['id']) ?>">
                                        <input type="hidden" name="jury_user_id" value="<?= e((string) $juryMember['id']) ?>">
                                        <input type="hidden" name="checked" value="<?= $checked ? '0' : '1' ?>">
                                        <button type="submit" class="<?= $checked ? 'btn-primary' : 'btn-ghost' ?>"><?= $checked ? 'Assigned' : 'Assign' ?></button>
                                    </form>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="card"><p class="empty-state">Add at least one round, jury user, and team to manage assignments.</p></section>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
