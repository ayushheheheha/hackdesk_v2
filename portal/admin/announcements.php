<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/Mailer.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
ensureOperationalTables($pdo);

$requestedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT);
$hackathons = getAccessibleHackathons($pdo);
$selectedHackathonId = resolveSelectedHackathonId($pdo, $requestedHackathonId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/announcements.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'publish' && $selectedHackathonId !== null) {
        $scope = (string) ($_POST['target_scope'] ?? 'all');
        $allowedScopes = ['all', 'participant', 'jury', 'staff', 'admin', 'team', 'round'];
        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'all';
        }

        $teamId = filter_input(INPUT_POST, 'target_team_id', FILTER_VALIDATE_INT);
        $roundId = filter_input(INPUT_POST, 'target_round_id', FILTER_VALIDATE_INT);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $sendEmail = isset($_POST['send_email']) && $_POST['send_email'] === '1';

        if ($title === '' || $body === '') {
            flash('error', 'Announcement title and message are required.');
            redirect('portal/admin/announcements.php?hackathon_id=' . $selectedHackathonId);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO announcements (
                hackathon_id, created_by, target_scope, target_team_id, target_round_id, title, body, send_email, is_active, published_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            $selectedHackathonId,
            (int) ($_SESSION['user']['id'] ?? 0),
            $scope,
            $teamId !== false ? $teamId : null,
            $roundId !== false ? $roundId : null,
            $title,
            $body,
            $sendEmail ? 1 : 0,
            utcNow()->format('Y-m-d H:i:s'),
        ]);

        if ($sendEmail) {
            $recipientQuery = 'SELECT name, email FROM participants WHERE hackathon_id = ?';
            $params = [$selectedHackathonId];

            if ($scope === 'team' && $teamId !== false && $teamId !== null) {
                $recipientQuery = 'SELECT p.name, p.email FROM team_members tm INNER JOIN participants p ON p.id = tm.participant_id WHERE tm.team_id = ?';
                $params = [$teamId];
            } elseif ($scope === 'participant') {
                $recipientQuery = 'SELECT name, email FROM participants WHERE hackathon_id = ?';
            } elseif (in_array($scope, ['jury', 'staff', 'admin'], true)) {
                $recipientQuery = 'SELECT name, email FROM users WHERE assigned_hackathon_id = ? AND role = ?';
                $params = [$selectedHackathonId, $scope];
            }

            $recipientsStmt = $pdo->prepare($recipientQuery);
            $recipientsStmt->execute($params);
            foreach ($recipientsStmt->fetchAll() as $recipient) {
                Mailer::sendMail(
                    (string) $recipient['email'],
                    (string) $recipient['name'],
                    'HackDesk Announcement: ' . $title,
                    '<p>' . nl2br(e($body)) . '</p>'
                );
            }
        }

        flash('success', 'Announcement published successfully.');
        redirect('portal/admin/announcements.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'run_reminders' && $selectedHackathonId !== null) {
        $result = runDeadlineReminders($pdo, $selectedHackathonId);
        flash('success', 'Reminder run complete. Sent: ' . $result['sent'] . '. Skipped: ' . $result['skipped'] . '.');
        redirect('portal/admin/announcements.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'deactivate') {
        $announcementId = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
        if ($announcementId !== false && $announcementId !== null) {
            $stmt = $pdo->prepare('UPDATE announcements SET is_active = 0 WHERE id = ? AND hackathon_id = ?');
            $stmt->execute([$announcementId, $selectedHackathonId]);
            flash('success', 'Announcement archived.');
        }
        redirect('portal/admin/announcements.php?hackathon_id=' . $selectedHackathonId);
    }
}

$teams = [];
$rounds = [];
$announcements = [];
if ($selectedHackathonId !== null) {
    $teamsStmt = $pdo->prepare('SELECT id, name FROM teams WHERE hackathon_id = ? ORDER BY name ASC');
    $teamsStmt->execute([$selectedHackathonId]);
    $teams = $teamsStmt->fetchAll();

    $roundsStmt = $pdo->prepare('SELECT id, name, round_number FROM rounds WHERE hackathon_id = ? ORDER BY round_number ASC');
    $roundsStmt->execute([$selectedHackathonId]);
    $rounds = $roundsStmt->fetchAll();

    $annStmt = $pdo->prepare(
        'SELECT a.id, a.title, a.body, a.target_scope, a.send_email, a.is_active, a.published_at, u.name AS author_name
         FROM announcements a
         INNER JOIN users u ON u.id = a.created_by
         WHERE a.hackathon_id = ?
         ORDER BY a.published_at DESC, a.id DESC'
    );
    $annStmt->execute([$selectedHackathonId]);
    $announcements = $annStmt->fetchAll();
}

$pageTitle = 'Announcements';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Announcement Center</h1>
        <p class="page-subtitle">Publish updates, target audiences, and run deadline reminders.</p>
    </div>
</section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon first.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/announcements.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="post" action="<?= e(appPath('portal/admin/announcements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:10px;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="run_reminders">
            <button type="submit" class="btn-ghost">Run Deadline Reminders Now</button>
        </form>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <h2>Publish Announcement</h2>
        <form method="post" action="<?= e(appPath('portal/admin/announcements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:14px;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="publish">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input id="title" name="title" type="text" required maxlength="180">
                </div>
                <div class="form-group">
                    <label for="target_scope">Target</label>
                    <select id="target_scope" name="target_scope">
                        <option value="all">All</option>
                        <option value="participant">Participants</option>
                        <option value="jury">Jury</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admins</option>
                        <option value="team">Specific Team</option>
                        <option value="round">Round Participants</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="send_email" value="1"> Also send email</label>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label for="target_team_id">Team (optional)</label>
                    <select id="target_team_id" name="target_team_id">
                        <option value="">None</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="target_round_id">Round (optional)</label>
                    <select id="target_round_id" name="target_round_id">
                        <option value="">None</option>
                        <?php foreach ($rounds as $round): ?>
                            <option value="<?= e((string) $round['id']) ?>">Round <?= e((string) $round['round_number']) ?> - <?= e((string) $round['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="body">Message</label>
                <textarea id="body" name="body" rows="5" required></textarea>
            </div>
            <button type="submit" class="btn-primary">Publish</button>
        </form>
    </section>

    <section class="card">
        <h2>Recent Announcements</h2>
        <?php if ($announcements === []): ?>
            <p class="empty-state" style="margin-top:12px;">No announcements published yet.</p>
        <?php else: ?>
            <div class="table-shell" style="margin-top:12px;">
                <table>
                    <thead><tr><th>Title</th><th>Target</th><th>Published</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($announcements as $announcement): ?>
                        <tr>
                            <td><?= e((string) $announcement['title']) ?><br><span class="page-subtitle"><?= e((string) $announcement['body']) ?></span></td>
                            <td><?= e(ucfirst((string) $announcement['target_scope'])) ?></td>
                            <td><?= e(formatUtcToIst((string) $announcement['published_at'])) ?><br><span class="page-subtitle"><?= e((string) $announcement['author_name']) ?></span></td>
                            <td><?= (int) $announcement['is_active'] === 1 ? 'Active' : 'Archived' ?></td>
                            <td>
                                <?php if ((int) $announcement['is_active'] === 1): ?>
                                    <form method="post" action="<?= e(appPath('portal/admin/announcements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="announcement_id" value="<?= e((string) $announcement['id']) ?>">
                                        <button type="submit" class="btn-ghost">Archive</button>
                                    </form>
                                <?php else: ?>
                                    <span class="page-subtitle">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

