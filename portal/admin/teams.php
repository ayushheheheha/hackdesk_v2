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

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selectedHackathonId !== null) {
    $stmt = $pdo->prepare(
        'SELECT
            t.name AS team_name,
            ps.title AS problem_statement_title,
            GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ", ") AS member_names,
            GROUP_CONCAT(p.email ORDER BY p.name SEPARATOR ", ") AS email_list
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         LEFT JOIN team_members tm ON tm.team_id = t.id
         LEFT JOIN participants p ON p.id = tm.participant_id
         WHERE t.hackathon_id = ?
         GROUP BY t.id, t.name, ps.title
         ORDER BY t.name ASC'
    );
    $stmt->execute([$selectedHackathonId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="teams-' . $selectedHackathonId . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['team_name', 'members', 'problem_statement', 'email_list']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['team_name'],
            $row['member_names'],
            $row['problem_statement_title'],
            $row['email_list'],
        ]);
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/teams.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    $action = (string) ($_POST['action'] ?? '');
    $teamId = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);

    if ($teamId === false || $teamId === null) {
        flash('error', 'Invalid team selected.');
        redirect('portal/admin/teams.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'disqualify') {
        $stmt = $pdo->prepare('UPDATE teams SET status = ? WHERE id = ? AND hackathon_id = ?');
        $stmt->execute(['disqualified', $teamId, $selectedHackathonId]);
        flash('success', 'Team disqualified.');
    }

    if ($action === 'toggle_ps_lock') {
        $targetLock = isset($_POST['target_lock']) && $_POST['target_lock'] === '1' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE teams SET ps_locked = ?, ps_locked_at = ? WHERE id = ? AND hackathon_id = ?');
        $stmt->execute([$targetLock, $targetLock === 1 ? utcNow()->format('Y-m-d H:i:s') : null, $teamId, $selectedHackathonId]);
        flash('success', $targetLock === 1 ? 'Problem statement locked.' : 'Problem statement unlocked.');
    }

    if ($action === 'change_leader') {
        $leaderParticipantId = filter_input(INPUT_POST, 'leader_participant_id', FILTER_VALIDATE_INT);
        if ($leaderParticipantId !== false && $leaderParticipantId !== null) {
            $checkStmt = $pdo->prepare('SELECT id FROM team_members WHERE team_id = ? AND participant_id = ? LIMIT 1');
            $checkStmt->execute([$teamId, $leaderParticipantId]);
            if ($checkStmt->fetch() !== false) {
                $stmt = $pdo->prepare('UPDATE teams SET leader_participant_id = ? WHERE id = ? AND hackathon_id = ?');
                $stmt->execute([$leaderParticipantId, $teamId, $selectedHackathonId]);
                flash('success', 'Team leader updated.');
            }
        }
    }

    redirect('portal/admin/teams.php?hackathon_id=' . $selectedHackathonId . '&team_id=' . $teamId);
}

$statusFilter = (string) ($_GET['status'] ?? '');
$psFilter = (string) ($_GET['has_ps'] ?? '');
$submittedRound1 = (string) ($_GET['submitted_round_1'] ?? '');
$selectedTeamId = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);

$where = ['t.hackathon_id = ?'];
$params = [$selectedHackathonId];

if (in_array($statusFilter, ['forming', 'complete', 'disqualified'], true)) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}

if ($psFilter === 'yes') {
    $where[] = 't.problem_statement_id IS NOT NULL';
}
if ($psFilter === 'no') {
    $where[] = 't.problem_statement_id IS NULL';
}
if ($submittedRound1 === 'yes') {
    $where[] = 'EXISTS (SELECT 1 FROM submissions s INNER JOIN rounds r ON r.id = s.round_id WHERE s.team_id = t.id AND r.round_number = 1 AND s.status IN ("submitted","late"))';
}

$teamsStmt = $pdo->prepare(
    'SELECT
        t.id,
        t.name,
        t.status,
        t.leader_participant_id,
        t.ps_locked,
        leader.name AS leader_name,
        ps.title AS problem_statement_title,
        COUNT(tm.id) AS member_count
     FROM teams t
     INNER JOIN participants leader ON leader.id = t.leader_participant_id
     LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
     LEFT JOIN team_members tm ON tm.team_id = t.id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY t.id, t.name, t.status, t.leader_participant_id, t.ps_locked, leader.name, ps.title
     ORDER BY t.created_at DESC, t.id DESC'
);
$teamsStmt->execute($params);
$teams = $teamsStmt->fetchAll();

$teamDetails = null;
$teamMembers = [];
$teamRounds = [];
if ($selectedTeamId !== false && $selectedTeamId !== null && $selectedHackathonId !== null) {
    $detailStmt = $pdo->prepare(
        'SELECT
            t.id,
            t.name,
            t.status,
            t.leader_participant_id,
            t.join_code,
            t.ps_locked,
            ps.title AS problem_statement_title
         FROM teams t
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         WHERE t.id = ? AND t.hackathon_id = ?
         LIMIT 1'
    );
    $detailStmt->execute([$selectedTeamId, $selectedHackathonId]);
    $teamDetails = $detailStmt->fetch() ?: null;

    if ($teamDetails !== null) {
        $membersStmt = $pdo->prepare(
            'SELECT p.id, p.name, p.email, p.check_in_status
             FROM team_members tm
             INNER JOIN participants p ON p.id = tm.participant_id
             WHERE tm.team_id = ?
             ORDER BY p.name ASC'
        );
        $membersStmt->execute([$selectedTeamId]);
        $teamMembers = $membersStmt->fetchAll();

        $roundsStmt = $pdo->prepare(
            'SELECT
                r.name AS round_name,
                s.status,
                s.submitted_at
             FROM rounds r
             LEFT JOIN submissions s ON s.team_id = ? AND s.round_id = r.id
             WHERE r.hackathon_id = ?
             ORDER BY r.round_number ASC'
        );
        $roundsStmt->execute([$selectedTeamId, $selectedHackathonId]);
        $teamRounds = $roundsStmt->fetchAll();
    }
}

$pageTitle = 'Teams';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Teams</h1>
        <p class="page-subtitle">Manage team composition, PS locks, leader assignments, and CSV exports.</p>
    </div>
</section>

<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon before managing teams.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/teams.php')) ?>">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="hackathon_id">Current Hackathon</label>
                    <select id="hackathon_id" name="hackathon_id">
                        <?php foreach ($hackathons as $hackathon): ?>
                            <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="forming" <?= $statusFilter === 'forming' ? 'selected' : '' ?>>Forming</option>
                        <option value="complete" <?= $statusFilter === 'complete' ? 'selected' : '' ?>>Complete</option>
                        <option value="disqualified" <?= $statusFilter === 'disqualified' ? 'selected' : '' ?>>Disqualified</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="has_ps">Has Problem Statement</label>
                    <select id="has_ps" name="has_ps">
                        <option value="">All</option>
                        <option value="yes" <?= $psFilter === 'yes' ? 'selected' : '' ?>>Yes</option>
                        <option value="no" <?= $psFilter === 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="submitted_round_1">Submitted Round 1</label>
                    <select id="submitted_round_1" name="submitted_round_1">
                        <option value="">All</option>
                        <option value="yes" <?= $submittedRound1 === 'yes' ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary">Apply Filters</button>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/teams.php?hackathon_id=' . (int) $selectedHackathonId . '&export=csv')) ?>">Export Teams CSV</a>
        </form>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <div class="table-shell">
            <table>
                <thead>
                <tr>
                    <th>Team Name</th>
                    <th>Leader</th>
                    <th>Member Count</th>
                    <th>Problem Statement</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($teams === []): ?>
                    <tr><td colspan="5">No teams matched the current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($teams as $team): ?>
                        <tr onclick="window.location='<?= e(appPath('portal/admin/teams.php?hackathon_id=' . (int) $selectedHackathonId . '&team_id=' . (int) $team['id'])) ?>'" style="cursor:pointer;">
                            <td><?= e((string) $team['name']) ?></td>
                            <td><?= e((string) $team['leader_name']) ?></td>
                            <td><?= e((string) $team['member_count']) ?></td>
                            <td><?= e((string) ($team['problem_statement_title'] ?? 'Not Selected')) ?></td>
                            <td><?= e(ucfirst((string) $team['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($teamDetails !== null): ?>
        <section class="grid grid-3">
            <article class="card" style="grid-column: span 2;">
                <h2><?= e((string) $teamDetails['name']) ?></h2>
                <p class="page-subtitle" style="margin:8px 0 18px;">Join code: <?= e((string) $teamDetails['join_code']) ?> | PS: <?= e((string) ($teamDetails['problem_statement_title'] ?? 'Not Selected')) ?></p>
                <div class="table-shell">
                    <table>
                        <thead><tr><th>Member</th><th>Email</th><th>Check-In</th><th>Role</th></tr></thead>
                        <tbody>
                        <?php foreach ($teamMembers as $member): ?>
                            <tr>
                                <td><?= e((string) $member['name']) ?></td>
                                <td><?= e((string) $member['email']) ?></td>
                                <td><?= e(ucwords(str_replace('_', ' ', (string) $member['check_in_status']))) ?></td>
                                <td><?= (int) $member['id'] === (int) $teamDetails['leader_participant_id'] ? 'Leader' : 'Member' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3 style="margin-top:20px;">Submission Status</h3>
                <div class="table-shell" style="margin-top:10px;">
                    <table>
                        <thead><tr><th>Round</th><th>Status</th><th>Submitted At</th></tr></thead>
                        <tbody>
                        <?php foreach ($teamRounds as $round): ?>
                            <tr>
                                <td><?= e((string) $round['round_name']) ?></td>
                                <td><?= e(ucfirst((string) ($round['status'] ?? 'not started'))) ?></td>
                                <td><?= e($round['submitted_at'] !== null ? formatUtcToIst((string) $round['submitted_at']) : 'Not submitted') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <h2>Admin Actions</h2>
                <form method="post" action="<?= e(appPath('portal/admin/teams.php?hackathon_id=' . (int) $selectedHackathonId . '&team_id=' . (int) $teamDetails['id'])) ?>" style="margin-top:18px;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="team_id" value="<?= e((string) $teamDetails['id']) ?>">
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                    <input type="hidden" name="action" value="disqualify">
                    <button type="submit" class="btn-ghost">Disqualify Team</button>
                </form>

                <form method="post" action="<?= e(appPath('portal/admin/teams.php?hackathon_id=' . (int) $selectedHackathonId . '&team_id=' . (int) $teamDetails['id'])) ?>" style="margin-top:12px;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="team_id" value="<?= e((string) $teamDetails['id']) ?>">
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                    <input type="hidden" name="action" value="toggle_ps_lock">
                    <input type="hidden" name="target_lock" value="<?= (int) $teamDetails['ps_locked'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn-ghost"><?= (int) $teamDetails['ps_locked'] === 1 ? 'Unlock PS' : 'Lock PS' ?></button>
                </form>

                <form method="post" action="<?= e(appPath('portal/admin/teams.php?hackathon_id=' . (int) $selectedHackathonId . '&team_id=' . (int) $teamDetails['id'])) ?>" style="margin-top:12px;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="team_id" value="<?= e((string) $teamDetails['id']) ?>">
                    <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                    <input type="hidden" name="action" value="change_leader">
                    <div class="form-group">
                        <label for="leader_participant_id">Change Leader</label>
                        <select id="leader_participant_id" name="leader_participant_id">
                            <?php foreach ($teamMembers as $member): ?>
                                <option value="<?= e((string) $member['id']) ?>" <?= (int) $member['id'] === (int) $teamDetails['leader_participant_id'] ? 'selected' : '' ?>><?= e((string) $member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Update Leader</button>
                </form>
            </article>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
