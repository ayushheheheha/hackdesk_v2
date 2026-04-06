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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/rounds.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    $action = (string) ($_POST['action'] ?? '');
    $criteria = parseJudgingCriteria($_POST['criteria_name'] ?? [], $_POST['criteria_max'] ?? []);
    $criteriaJson = $criteria !== [] ? json_encode($criteria, JSON_UNESCAPED_SLASHES) : null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $roundNumber = filter_input(INPUT_POST, 'round_number', FILTER_VALIDATE_INT);
    $submissionOpensAt = trim((string) ($_POST['submission_opens_at'] ?? ''));
    $submissionDeadline = trim((string) ($_POST['submission_deadline'] ?? ''));
    $judgingDeadline = trim((string) ($_POST['judging_deadline'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'upcoming'));
    $pptRequired = isset($_POST['ppt_required']) ? 1 : 0;
    $githubRequired = isset($_POST['github_required']) ? 1 : 0;
    $customLinkAllowed = isset($_POST['custom_link_allowed']) ? 1 : 0;
    $abstractRequired = isset($_POST['abstract_required']) ? 1 : 0;

    if ($action === 'create' || $action === 'update') {
        if ($name === '' || $roundNumber === false || $roundNumber === null || $submissionDeadline === '') {
            flash('error', 'Name, round number, and submission deadline are required.');
            redirect('portal/admin/rounds.php?hackathon_id=' . $selectedHackathonId);
        }
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO rounds (
                hackathon_id, name, round_number, description, submission_opens_at, submission_deadline,
                judging_deadline, ppt_required, github_required, custom_link_allowed, abstract_required,
                judging_criteria, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $selectedHackathonId,
            $name,
            $roundNumber,
            null,
            $submissionOpensAt !== '' ? $submissionOpensAt : null,
            $submissionDeadline,
            $judgingDeadline !== '' ? $judgingDeadline : null,
            $pptRequired,
            $githubRequired,
            $customLinkAllowed,
            $abstractRequired,
            $criteriaJson,
            $status,
        ]);
        flash('success', 'Round created successfully.');
        redirect('portal/admin/rounds.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'update') {
        $roundId = filter_input(INPUT_POST, 'round_id', FILTER_VALIDATE_INT);
        if ($roundId !== false && $roundId !== null) {
            $stmt = $pdo->prepare(
                'UPDATE rounds
                 SET name = ?, round_number = ?, submission_opens_at = ?, submission_deadline = ?, judging_deadline = ?,
                     ppt_required = ?, github_required = ?, custom_link_allowed = ?, abstract_required = ?,
                     judging_criteria = ?, status = ?
                 WHERE id = ? AND hackathon_id = ?'
            );
            $stmt->execute([
                $name,
                $roundNumber,
                $submissionOpensAt !== '' ? $submissionOpensAt : null,
                $submissionDeadline,
                $judgingDeadline !== '' ? $judgingDeadline : null,
                $pptRequired,
                $githubRequired,
                $customLinkAllowed,
                $abstractRequired,
                $criteriaJson,
                $status,
                $roundId,
                $selectedHackathonId,
            ]);
            flash('success', 'Round updated.');
        }
        redirect('portal/admin/rounds.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'delete') {
        $roundId = filter_input(INPUT_POST, 'round_id', FILTER_VALIDATE_INT);
        if ($roundId !== false && $roundId !== null) {
            $checkStmt = $pdo->prepare('SELECT COUNT(id) AS submission_count FROM submissions WHERE round_id = ?');
            $checkStmt->execute([$roundId]);
            $submissionCount = (int) ($checkStmt->fetch()['submission_count'] ?? 0);
            if ($submissionCount > 0) {
                flash('error', 'This round cannot be deleted because submissions already exist.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM rounds WHERE id = ? AND hackathon_id = ?');
                $stmt->execute([$roundId, $selectedHackathonId]);
                flash('success', 'Round deleted.');
            }
        }
        redirect('portal/admin/rounds.php?hackathon_id=' . $selectedHackathonId);
    }
}

$nextRoundNumber = 1;
$rounds = [];
if ($selectedHackathonId !== null) {
    $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(round_number), 0) AS max_round FROM rounds WHERE hackathon_id = ?');
    $maxStmt->execute([$selectedHackathonId]);
    $nextRoundNumber = ((int) ($maxStmt->fetch()['max_round'] ?? 0)) + 1;

    $stmt = $pdo->prepare(
        'SELECT
            r.*,
            COUNT(s.id) AS submission_count
         FROM rounds r
         LEFT JOIN submissions s ON s.round_id = r.id
         WHERE r.hackathon_id = ?
         GROUP BY r.id
         ORDER BY r.round_number ASC'
    );
    $stmt->execute([$selectedHackathonId]);
    $rounds = $stmt->fetchAll();
}

$pageTitle = 'Rounds';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Rounds</h1>
        <p class="page-subtitle">Configure submission windows, judging deadlines, and score criteria.</p>
    </div>
</section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon before adding rounds.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/rounds.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Current Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Create Round</h2>
            <form method="post" action="<?= e(appPath('portal/admin/rounds.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:18px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <div class="form-group"><label for="name">Name</label><input id="name" name="name" type="text" required></div>
                <div class="form-group"><label for="round_number">Round Number</label><input id="round_number" name="round_number" type="number" min="1" value="<?= e((string) $nextRoundNumber) ?>" required></div>
                <div class="form-group"><label for="submission_opens_at">Submission Opens At</label><input id="submission_opens_at" name="submission_opens_at" type="datetime-local"></div>
                <div class="form-group"><label for="submission_deadline">Submission Deadline</label><input id="submission_deadline" name="submission_deadline" type="datetime-local" required></div>
                <div class="form-group"><label for="judging_deadline">Judging Deadline</label><input id="judging_deadline" name="judging_deadline" type="datetime-local"></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><option value="upcoming">Upcoming</option><option value="open">Open</option><option value="closed">Closed</option><option value="judging_done">Judging Done</option></select></div>
                <div class="form-group"><label><input type="checkbox" name="ppt_required" value="1" checked style="width:auto;margin-right:8px;">PPT Required</label></div>
                <div class="form-group"><label><input type="checkbox" name="github_required" value="1" checked style="width:auto;margin-right:8px;">GitHub Required</label></div>
                <div class="form-group"><label><input type="checkbox" name="custom_link_allowed" value="1" style="width:auto;margin-right:8px;">Custom Link Allowed</label></div>
                <div class="form-group"><label><input type="checkbox" name="abstract_required" value="1" style="width:auto;margin-right:8px;">Abstract Required</label></div>
                <div id="criteria-builder">
                    <label>Judging Criteria</label>
                    <div class="grid grid-3" style="margin-bottom:8px;">
                        <input name="criteria_name[]" type="text" placeholder="Innovation">
                        <input name="criteria_max[]" type="number" min="0.5" step="0.5" placeholder="10">
                    </div>
                </div>
                <button type="button" class="btn-ghost" onclick="addCriteriaRow()">Add Criterion</button>
                <button type="submit" class="btn-primary" style="margin-top:14px;">Create Round</button>
            </form>
        </article>

        <article class="card" style="grid-column: span 2;">
            <h2>Rounds List</h2>
            <?php if ($rounds === []): ?>
                <p class="empty-state" style="margin-top:12px;">No rounds configured for this hackathon yet.</p>
            <?php else: ?>
                <?php foreach ($rounds as $round): ?>
                    <?php $criteria = decodeCriteria($round['judging_criteria']); ?>
                    <article class="card" style="margin-top:16px;">
                        <div class="page-header" style="margin-bottom:12px;">
                            <div>
                                <h3>Round <?= e((string) $round['round_number']) ?>: <?= e((string) $round['name']) ?></h3>
                                <p class="page-subtitle">Status: <?= e((string) $round['status']) ?> | Submissions: <?= e((string) $round['submission_count']) ?></p>
                            </div>
                            <span class="badge <?= $round['status'] === 'judging_done' ? 'badge-success' : 'badge-muted' ?>"><?= e((string) $round['status']) ?></span>
                        </div>
                        <p class="page-subtitle">Deadline: <?= e(formatUtcToIst((string) $round['submission_deadline'])) ?></p>
                        <?php if ($criteria !== []): ?>
                            <p class="page-subtitle" style="margin-top:8px;">Criteria: <?= e(implode(', ', array_map(static fn($criterion): string => $criterion['name'] . ' (' . $criterion['max'] . ')', $criteria))) ?></p>
                        <?php endif; ?>
                        <details style="margin-top:12px;">
                            <summary class="btn-ghost" style="display:inline-flex;">Edit Round</summary>
                            <form method="post" action="<?= e(appPath('portal/admin/rounds.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:12px;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="round_id" value="<?= e((string) $round['id']) ?>">
                                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                <div class="form-group"><label>Name</label><input name="name" type="text" value="<?= e((string) $round['name']) ?>" required></div>
                                <div class="form-group"><label>Round Number</label><input name="round_number" type="number" min="1" value="<?= e((string) $round['round_number']) ?>" required></div>
                                <div class="form-group"><label>Submission Opens At</label><input name="submission_opens_at" type="datetime-local" value="<?= e($round['submission_opens_at'] !== null ? str_replace(' ', 'T', substr((string) $round['submission_opens_at'], 0, 16)) : '') ?>"></div>
                                <div class="form-group"><label>Submission Deadline</label><input name="submission_deadline" type="datetime-local" value="<?= e(str_replace(' ', 'T', substr((string) $round['submission_deadline'], 0, 16))) ?>" required></div>
                                <div class="form-group"><label>Judging Deadline</label><input name="judging_deadline" type="datetime-local" value="<?= e($round['judging_deadline'] !== null ? str_replace(' ', 'T', substr((string) $round['judging_deadline'], 0, 16)) : '') ?>"></div>
                                <div class="form-group"><label>Status</label><select name="status"><option value="upcoming" <?= $round['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option><option value="open" <?= $round['status'] === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= $round['status'] === 'closed' ? 'selected' : '' ?>>Closed</option><option value="judging_done" <?= $round['status'] === 'judging_done' ? 'selected' : '' ?>>Judging Done</option></select></div>
                                <div class="form-group"><label><input type="checkbox" name="ppt_required" value="1" <?= (int) $round['ppt_required'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">PPT Required</label></div>
                                <div class="form-group"><label><input type="checkbox" name="github_required" value="1" <?= (int) $round['github_required'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">GitHub Required</label></div>
                                <div class="form-group"><label><input type="checkbox" name="custom_link_allowed" value="1" <?= (int) $round['custom_link_allowed'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Custom Link Allowed</label></div>
                                <div class="form-group"><label><input type="checkbox" name="abstract_required" value="1" <?= (int) $round['abstract_required'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Abstract Required</label></div>
                                <?php foreach ($criteria as $criterion): ?>
                                    <div class="grid grid-3" style="margin-bottom:8px;">
                                        <input name="criteria_name[]" type="text" value="<?= e((string) $criterion['name']) ?>">
                                        <input name="criteria_max[]" type="number" min="0.5" step="0.5" value="<?= e((string) $criterion['max']) ?>">
                                    </div>
                                <?php endforeach; ?>
                                <button type="submit" class="btn-primary">Save Changes</button>
                            </form>
                            <form method="post" action="<?= e(appPath('portal/admin/rounds.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:8px;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="round_id" value="<?= e((string) $round['id']) ?>">
                                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                <button type="submit" class="btn-ghost">Delete Round</button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </article>
    </section>

    <script>
    function addCriteriaRow() {
        const wrapper = document.getElementById('criteria-builder');
        const row = document.createElement('div');
        row.className = 'grid grid-3';
        row.style.marginBottom = '8px';
        row.innerHTML = '<input name="criteria_name[]" type="text" placeholder="Criterion name"><input name="criteria_max[]" type="number" min="0.5" step="0.5" placeholder="10">';
        wrapper.appendChild(row);
    }
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
