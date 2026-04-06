<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/ParticipantAuth.php';

Middleware::requireParticipantAuth();
$pdo = Database::getConnection();
$participant = ParticipantAuth::participant();
$participantId = (int) ($_SESSION['participant_id'] ?? 0);
$hackathonId = (int) ($_SESSION['participant_hackathon_id'] ?? 0);

$hackathonStmt = $pdo->prepare('SELECT id, name, min_team_size, max_team_size, ps_selection_deadline FROM hackathons WHERE id = ? LIMIT 1');
$hackathonStmt->execute([$hackathonId]);
$hackathon = $hackathonStmt->fetch() ?: null;

$fetchTeam = static function (PDO $pdo, int $participantId): array|false {
    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.hackathon_id,
            t.leader_participant_id,
            t.problem_statement_id,
            t.name,
            t.join_code,
            t.status,
            t.ps_locked,
            t.ps_locked_at,
            h.max_team_size,
            h.min_team_size,
            h.ps_selection_deadline,
            ps.title AS problem_statement_title
         FROM team_members tm
         INNER JOIN teams t ON t.id = tm.team_id
         INNER JOIN hackathons h ON h.id = t.hackathon_id
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         WHERE tm.participant_id = ?
         LIMIT 1'
    );
    $stmt->execute([$participantId]);

    return $stmt->fetch();
};

$team = $fetchTeam($pdo, $participantId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/participant/team.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_team' && $team === false && $hackathon !== null) {
        $teamName = trim((string) ($_POST['team_name'] ?? ''));
        if (mb_strlen($teamName) < 3 || mb_strlen($teamName) > 50) {
            flash('error', 'Team name must be between 3 and 50 characters.');
            redirect('portal/participant/team.php');
        }

        $pdo->beginTransaction();
        try {
            $insertTeamStmt = $pdo->prepare(
                'INSERT INTO teams (hackathon_id, leader_participant_id, problem_statement_id, name, join_code, status, ps_locked, ps_locked_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $joinCode = generateJoinCode();
            $insertTeamStmt->execute([$hackathonId, $participantId, null, $teamName, $joinCode, 'forming', 0, null]);
            $teamId = (int) $pdo->lastInsertId();

            $insertMemberStmt = $pdo->prepare('INSERT INTO team_members (team_id, participant_id) VALUES (?, ?)');
            $insertMemberStmt->execute([$teamId, $participantId]);
            $pdo->commit();
            flash('success', 'Team created successfully.');
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            flash('error', 'Could not create the team: ' . $throwable->getMessage());
        }

        redirect('portal/participant/team.php');
    }

    if ($action === 'join_team' && $team === false && $hackathon !== null) {
        $joinCode = strtoupper(trim((string) ($_POST['join_code'] ?? '')));
        $teamStmt = $pdo->prepare(
            'SELECT t.id, t.status, t.name, h.max_team_size
             FROM teams t
             INNER JOIN hackathons h ON h.id = t.hackathon_id
             WHERE t.join_code = ? AND t.hackathon_id = ?
             LIMIT 1'
        );
        $teamStmt->execute([$joinCode, $hackathonId]);
        $targetTeam = $teamStmt->fetch();

        if ($targetTeam === false) {
            flash('error', 'Team code not found for this hackathon.');
            redirect('portal/participant/team.php');
        }

        if ($targetTeam['status'] !== 'forming') {
            flash('error', 'This team is no longer accepting new members.');
            redirect('portal/participant/team.php');
        }

        $countStmt = $pdo->prepare('SELECT COUNT(id) AS member_count FROM team_members WHERE team_id = ?');
        $countStmt->execute([(int) $targetTeam['id']]);
        $memberCount = (int) ($countStmt->fetch()['member_count'] ?? 0);

        if ($memberCount >= (int) $targetTeam['max_team_size']) {
            flash('error', 'This team is already full.');
            redirect('portal/participant/team.php');
        }

        $pdo->beginTransaction();
        try {
            $insertMemberStmt = $pdo->prepare('INSERT INTO team_members (team_id, participant_id) VALUES (?, ?)');
            $insertMemberStmt->execute([(int) $targetTeam['id'], $participantId]);
            $memberCount++;
            $status = $memberCount >= (int) $targetTeam['max_team_size'] ? 'complete' : 'forming';
            $updateTeamStmt = $pdo->prepare('UPDATE teams SET status = ? WHERE id = ?');
            $updateTeamStmt->execute([$status, (int) $targetTeam['id']]);
            $pdo->commit();
            flash('success', 'You joined ' . $targetTeam['name'] . ' successfully.');
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            flash('error', 'Could not join the team: ' . $throwable->getMessage());
        }

        redirect('portal/participant/team.php');
    }

    if ($team !== false) {
        $isLeader = (int) $team['leader_participant_id'] === $participantId;

        if ($action === 'remove_member' && $isLeader) {
            $memberParticipantId = filter_input(INPUT_POST, 'member_participant_id', FILTER_VALIDATE_INT);
            if ($memberParticipantId === false || $memberParticipantId === null || $memberParticipantId === $participantId) {
                flash('error', 'You can only remove other team members.');
                redirect('portal/participant/team.php');
            }

            $deleteStmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ? AND participant_id = ?');
            $deleteStmt->execute([(int) $team['id'], $memberParticipantId]);
            $updateStmt = $pdo->prepare('UPDATE teams SET status = ? WHERE id = ?');
            $updateStmt->execute(['forming', (int) $team['id']]);
            flash('success', 'Team member removed.');
            redirect('portal/participant/team.php');
        }

        if ($action === 'disband_team' && $isLeader) {
            $countStmt = $pdo->prepare('SELECT COUNT(id) AS member_count FROM team_members WHERE team_id = ?');
            $countStmt->execute([(int) $team['id']]);
            $memberCount = (int) ($countStmt->fetch()['member_count'] ?? 0);

            if ($memberCount !== 1) {
                flash('error', 'You can only disband a team when you are the only member.');
                redirect('portal/participant/team.php');
            }

            $pdo->beginTransaction();
            try {
                $deleteMembersStmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ?');
                $deleteMembersStmt->execute([(int) $team['id']]);
                $deleteTeamStmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
                $deleteTeamStmt->execute([(int) $team['id']]);
                $pdo->commit();
                flash('success', 'Team disbanded.');
            } catch (Throwable $throwable) {
                $pdo->rollBack();
                flash('error', 'Could not disband the team: ' . $throwable->getMessage());
            }

            redirect('portal/participant/team.php');
        }

        if ($action === 'select_problem_statement' && $isLeader) {
            $problemStatementId = filter_input(INPUT_POST, 'problem_statement_id', FILTER_VALIDATE_INT);
            $deadlinePassed = isDeadlinePassed($team['ps_selection_deadline'] ?? null);
            if ($problemStatementId === false || $problemStatementId === null) {
                flash('error', 'Invalid problem statement selected.');
                redirect('portal/participant/team.php');
            }

            if ($deadlinePassed || (int) $team['ps_locked'] === 1) {
                flash('error', 'Problem statement selection is locked for this team.');
                redirect('portal/participant/team.php');
            }

            $checkStmt = $pdo->prepare(
                'SELECT id FROM problem_statements WHERE id = ? AND hackathon_id = ? AND is_active = 1 LIMIT 1'
            );
            $checkStmt->execute([$problemStatementId, $hackathonId]);
            if ($checkStmt->fetch() === false) {
                flash('error', 'That problem statement is not available.');
                redirect('portal/participant/team.php');
            }

            $updateStmt = $pdo->prepare('UPDATE teams SET problem_statement_id = ?, ps_locked = 0 WHERE id = ?');
            $updateStmt->execute([$problemStatementId, (int) $team['id']]);
            flash('success', 'Problem statement updated.');
            redirect('portal/participant/team.php');
        }
    }
}

$team = $fetchTeam($pdo, $participantId);
$teamMembers = [];
$availableProblemStatements = [];
$teamSubmissions = [];
$psDeadlinePassed = false;
$psLockedMessage = null;

if ($team !== false) {
    $membersStmt = $pdo->prepare(
        'SELECT p.id, p.name, p.email, p.check_in_status
         FROM team_members tm
         INNER JOIN participants p ON p.id = tm.participant_id
         WHERE tm.team_id = ?
         ORDER BY p.name ASC'
    );
    $membersStmt->execute([(int) $team['id']]);
    $teamMembers = $membersStmt->fetchAll();

    $problemStatementsStmt = $pdo->prepare(
        'SELECT
            ps.id,
            ps.title,
            ps.description,
            ps.domain,
            ps.difficulty,
            COUNT(t.id) AS team_count
         FROM problem_statements ps
         LEFT JOIN teams t ON t.problem_statement_id = ps.id
         WHERE ps.hackathon_id = ? AND ps.is_active = 1
         GROUP BY ps.id, ps.title, ps.description, ps.domain, ps.difficulty
         ORDER BY ps.title ASC'
    );
    $problemStatementsStmt->execute([$hackathonId]);
    $availableProblemStatements = $problemStatementsStmt->fetchAll();

    $submissionsStmt = $pdo->prepare(
        'SELECT r.name AS round_name, s.status, s.submitted_at
         FROM rounds r
         LEFT JOIN submissions s ON s.round_id = r.id AND s.team_id = ?
         WHERE r.hackathon_id = ?
         ORDER BY r.round_number ASC'
    );
    $submissionsStmt->execute([(int) $team['id'], $hackathonId]);
    $teamSubmissions = $submissionsStmt->fetchAll();

    $psDeadlinePassed = isDeadlinePassed($team['ps_selection_deadline'] ?? null);
    if ($psDeadlinePassed) {
        $psLockedMessage = 'Problem statement locked as of ' . formatUtcToIst((string) $team['ps_selection_deadline']);
    } elseif ((int) $team['ps_locked'] === 1) {
        $psLockedMessage = 'Problem statement changes are locked by the organizers.';
    }
}

$pageTitle = 'My Team';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>My Team</h1>
        <p class="page-subtitle"><?= e((string) ($hackathon['name'] ?? 'Hackathon')) ?> team formation and problem statement selection.</p>
    </div>
</section>

<?php if ($team === false): ?>
    <section class="grid grid-3">
        <article class="card">
            <h2>Create a Team</h2>
            <p class="page-subtitle" style="margin:10px 0 18px;">Create a new team and become its leader.</p>
            <form method="post" action="<?= e(appPath('portal/participant/team.php')) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_team">
                <div class="form-group">
                    <label for="team_name">Team Name</label>
                    <input id="team_name" name="team_name" type="text" minlength="3" maxlength="50" required>
                </div>
                <button type="submit" class="btn-primary">Create Team</button>
            </form>
        </article>

        <article class="card" style="grid-column: span 2;">
            <h2>Join a Team</h2>
            <p class="page-subtitle" style="margin:10px 0 18px;">Enter an 8-character team code shared by your team leader.</p>
            <form method="post" action="<?= e(appPath('portal/participant/team.php')) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="join_team">
                <div class="form-group" style="max-width:240px;">
                    <label for="join_code">Team Code</label>
                    <input id="join_code" name="join_code" type="text" maxlength="8" minlength="8" style="text-transform:uppercase;" required>
                </div>
                <p class="page-subtitle" style="margin-bottom:18px;">Team size allowed for this hackathon: <?= e((string) ($hackathon['min_team_size'] ?? 2)) ?> to <?= e((string) ($hackathon['max_team_size'] ?? 4)) ?> members.</p>
                <button type="submit" class="btn-primary">Join Team</button>
            </form>
        </article>
    </section>
<?php else: ?>
    <?php $isLeader = (int) $team['leader_participant_id'] === $participantId; ?>
    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <div class="stat-label">Team Name</div>
            <div class="stat-value" style="font-size:22px;"><?= e((string) $team['name']) ?></div>
            <p class="page-subtitle" style="margin-top:10px;">Status: <?= e(ucfirst((string) $team['status'])) ?></p>
        </article>
        <article class="card">
            <div class="stat-label">Team Size</div>
            <div class="stat-value" style="font-size:22px;"><?= e((string) count($teamMembers)) ?> / <?= e((string) $team['max_team_size']) ?></div>
            <p class="page-subtitle" style="margin-top:10px;">Minimum size: <?= e((string) $team['min_team_size']) ?></p>
        </article>
        <article class="card">
            <div class="stat-label">Join Code</div>
            <div class="stat-value" style="font-size:22px;"><?= $isLeader ? e((string) $team['join_code']) : 'Hidden' ?></div>
            <p class="page-subtitle" style="margin-top:10px;"><?= $isLeader ? 'Share this code with your teammates.' : 'Only the leader can view the join code.' ?></p>
        </article>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <div class="page-header" style="margin-bottom:18px;">
            <div>
                <h2>Team Members</h2>
                <p class="page-subtitle">Leader controls team membership.</p>
            </div>
        </div>
        <div class="table-shell">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Check-In</th>
                    <th>Role</th>
                    <?php if ($isLeader): ?><th>Action</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($teamMembers as $member): ?>
                    <?php $memberIsLeader = (int) $member['id'] === (int) $team['leader_participant_id']; ?>
                    <tr>
                        <td><?= e((string) $member['name']) ?></td>
                        <td><?= e((string) $member['email']) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $member['check_in_status']))) ?></td>
                        <td><?= $memberIsLeader ? '<span class="badge badge-success">Leader</span>' : '<span class="badge badge-muted">Member</span>' ?></td>
                        <?php if ($isLeader): ?>
                            <td>
                                <?php if (!$memberIsLeader): ?>
                                    <form method="post" action="<?= e(appPath('portal/participant/team.php')) ?>">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="remove_member">
                                        <input type="hidden" name="member_participant_id" value="<?= e((string) $member['id']) ?>">
                                        <button type="submit" class="btn-ghost">Remove</button>
                                    </form>
                                <?php else: ?>
                                    <span class="page-subtitle">You</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($isLeader && count($teamMembers) === 1): ?>
            <form method="post" action="<?= e(appPath('portal/participant/team.php')) ?>" style="margin-top:18px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="disband_team">
                <button type="submit" class="btn-ghost">Disband Team</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <div class="page-header" style="margin-bottom:18px;">
            <div>
                <h2>Problem Statement Selection</h2>
                <p class="page-subtitle"><?= $isLeader ? 'Select or update your team challenge while selection is open.' : 'Only the team leader can change the problem statement.' ?></p>
            </div>
        </div>
        <?php if ($psLockedMessage !== null): ?>
            <div class="flash flash-error"><?= e($psLockedMessage) ?></div>
        <?php endif; ?>
        <?php if ($availableProblemStatements === []): ?>
            <p class="empty-state">No active problem statements are available for this hackathon yet.</p>
        <?php else: ?>
            <?php foreach ($availableProblemStatements as $problemStatement): ?>
                <?php $isCurrent = (int) $problemStatement['id'] === (int) ($team['problem_statement_id'] ?? 0); ?>
                <article class="card" style="margin-bottom:16px;border-color:<?= $isCurrent ? '#5B5BD6' : '#27272A' ?>;">
                    <div class="page-header" style="margin-bottom:14px;">
                        <div>
                            <h3><?= e((string) $problemStatement['title']) ?></h3>
                            <p class="page-subtitle"><?= e((string) ($problemStatement['domain'] ?? 'General')) ?> | <?= e((string) ($problemStatement['difficulty'] ?? 'Open')) ?> | <?= e((string) $problemStatement['team_count']) ?> teams selected</p>
                        </div>
                        <?php if ($isCurrent): ?><span class="badge badge-success">Current</span><?php endif; ?>
                    </div>
                    <p><?= e((string) $problemStatement['description']) ?></p>
                    <?php if ($isLeader): ?>
                        <form method="post" action="<?= e(appPath('portal/participant/team.php')) ?>" style="margin-top:14px;">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="select_problem_statement">
                            <input type="hidden" name="problem_statement_id" value="<?= e((string) $problemStatement['id']) ?>">
                            <button type="submit" class="<?= $isCurrent ? 'btn-ghost' : 'btn-primary' ?>" <?= ($psDeadlinePassed || (int) $team['ps_locked'] === 1) ? 'disabled' : '' ?>>
                                <?= $isCurrent ? 'Selected' : 'Select' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Submission Status by Round</h2>
        <?php if ($teamSubmissions === []): ?>
            <p class="empty-state" style="margin-top:12px;">No rounds are configured for this hackathon yet.</p>
        <?php else: ?>
            <div class="table-shell" style="margin-top:14px;">
                <table>
                    <thead>
                    <tr>
                        <th>Round</th>
                        <th>Status</th>
                        <th>Submitted At</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teamSubmissions as $submission): ?>
                        <tr>
                            <td><?= e((string) $submission['round_name']) ?></td>
                            <td><?= e(ucfirst((string) ($submission['status'] ?? 'not started'))) ?></td>
                            <td><?= e($submission['submitted_at'] !== null ? formatUtcToIst((string) $submission['submitted_at']) : 'Not submitted') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
