<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('jury');

$pdo = Database::getConnection();
$juryUserId = (int) ($_SESSION['user']['id'] ?? 0);
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);

$stmt = $pdo->prepare(
    'SELECT
        ja.id,
        ja.team_id,
        ja.round_id,
        ja.jury_user_id,
        t.name AS team_name,
        ps.title AS problem_statement_title,
        r.name AS round_name,
        r.judging_deadline,
        r.judging_criteria,
        s.id AS submission_id,
        s.ppt_file_path,
        s.ppt_original_name,
        s.github_url,
        s.custom_link,
        s.abstract,
        sc.criteria_scores,
        sc.total_score,
        sc.remarks,
        sc.updated_at
     FROM jury_assignments ja
     INNER JOIN teams t ON t.id = ja.team_id
     INNER JOIN rounds r ON r.id = ja.round_id
     LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
     LEFT JOIN submissions s ON s.team_id = ja.team_id AND s.round_id = ja.round_id
     LEFT JOIN scores sc ON sc.jury_assignment_id = ja.id
     WHERE ja.id = ? AND ja.jury_user_id = ?
     LIMIT 1'
);
$stmt->execute([$assignmentId, $juryUserId]);
$assignment = $stmt->fetch() ?: null;

if ($assignment === null) {
    flash('error', 'That assignment was not found for your account.');
    redirect('portal/jury/dashboard.php');
}

$criteria = decodeCriteria($assignment['judging_criteria'] ?? null);
$savedScores = $assignment['criteria_scores'] !== null ? (json_decode((string) $assignment['criteria_scores'], true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/jury/score.php?assignment_id=' . $assignmentId);
    }

    if ($assignment['judging_deadline'] !== null && isDeadlinePassed((string) $assignment['judging_deadline'])) {
        flash('error', 'The judging deadline has passed for this round.');
        redirect('portal/jury/score.php?assignment_id=' . $assignmentId);
    }

    $criteriaScores = [];
    $totalScore = 0.0;
    foreach ($criteria as $criterion) {
        $key = (string) $criterion['name'];
        $max = (float) $criterion['max'];
        $value = isset($_POST['criteria'][$key]) ? (float) $_POST['criteria'][$key] : 0.0;
        if ($value < 0 || $value > $max) {
            flash('error', 'Scores must stay within the criterion maximum.');
            redirect('portal/jury/score.php?assignment_id=' . $assignmentId);
        }
        $criteriaScores[$key] = $value;
        $totalScore += $value;
    }

    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $checkStmt = $pdo->prepare('SELECT id FROM scores WHERE jury_assignment_id = ? LIMIT 1');
    $checkStmt->execute([$assignmentId]);
    $existingScore = $checkStmt->fetch();

    if ($existingScore === false) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO scores (jury_assignment_id, team_id, round_id, criteria_scores, total_score, remarks)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            $assignmentId,
            (int) $assignment['team_id'],
            (int) $assignment['round_id'],
            json_encode($criteriaScores, JSON_UNESCAPED_SLASHES),
            $totalScore,
            $remarks !== '' ? $remarks : null,
        ]);
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE scores
             SET criteria_scores = ?, total_score = ?, remarks = ?
             WHERE jury_assignment_id = ?'
        );
        $updateStmt->execute([
            json_encode($criteriaScores, JSON_UNESCAPED_SLASHES),
            $totalScore,
            $remarks !== '' ? $remarks : null,
            $assignmentId,
        ]);
    }

    flash('success', 'Scores saved successfully.');
    redirect('portal/jury/score.php?assignment_id=' . $assignmentId);
}

$membersStmt = $pdo->prepare(
    'SELECT p.name, p.email
     FROM team_members tm
     INNER JOIN participants p ON p.id = tm.participant_id
     WHERE tm.team_id = ?
     ORDER BY p.name ASC'
);
$membersStmt->execute([(int) $assignment['team_id']]);
$members = $membersStmt->fetchAll();

$pageTitle = 'Score Teams';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div><h1>Score Team</h1><p class="page-subtitle"><?= e((string) $assignment['team_name']) ?> | <?= e((string) $assignment['round_name']) ?></p></div>
</section>
<section class="grid grid-3">
    <article class="card" style="grid-column: span 2;">
        <h2>Submission Details</h2>
        <p class="page-subtitle" style="margin:8px 0 16px;">Problem Statement: <?= e((string) ($assignment['problem_statement_title'] ?? 'Not Selected')) ?></p>
        <p><strong>Members:</strong> <?= e(implode(', ', array_map(static fn(array $member): string => $member['name'], $members))) ?></p>
        <p style="margin-top:10px;"><strong>PPT:</strong>
            <?php if ($assignment['ppt_file_path'] !== null): ?>
                <a href="<?= e(appPath('public/submission-file.php?submission_id=' . (int) $assignment['submission_id'])) ?>">Download</a>
                <?php if (strtolower(pathinfo((string) $assignment['ppt_original_name'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                    | <a href="<?= e(appPath('public/submission-file.php?submission_id=' . (int) $assignment['submission_id'] . '&inline=1')) ?>" target="_blank" rel="noopener">Preview PDF</a>
                <?php endif; ?>
            <?php else: ?>
                Not uploaded
            <?php endif; ?>
        </p>
        <?php if ($assignment['ppt_file_path'] !== null && strtolower(pathinfo((string) $assignment['ppt_original_name'], PATHINFO_EXTENSION)) === 'pdf'): ?>
            <iframe src="<?= e(appPath('public/submission-file.php?submission_id=' . (int) $assignment['submission_id'] . '&inline=1')) ?>" style="width:100%;height:480px;border:1px solid var(--border);border-radius:8px;margin-top:16px;"></iframe>
        <?php endif; ?>
        <p style="margin-top:10px;"><strong>GitHub:</strong> <?= e((string) ($assignment['github_url'] ?? '-')) ?></p>
        <p style="margin-top:10px;"><strong>Custom Link:</strong> <?= e((string) ($assignment['custom_link'] ?? '-')) ?></p>
        <p style="margin-top:10px;"><strong>Abstract:</strong> <?= e((string) ($assignment['abstract'] ?? '-')) ?></p>
    </article>
    <article class="card">
        <h2>Scoring</h2>
        <?php if ($criteria === []): ?>
            <div class="empty-state" style="margin-top:14px;">
                This round has no judging criteria configured yet. Ask the event admin to update the round before scoring submissions.
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(appPath('portal/jury/score.php?assignment_id=' . (int) $assignmentId)) ?>" style="margin-top:14px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                <?php foreach ($criteria as $criterion): ?>
                    <?php $criterionName = (string) $criterion['name']; $max = (float) $criterion['max']; ?>
                    <div class="form-group">
                        <label><?= e($criterionName) ?> - max <?= e((string) $max) ?> pts</label>
                        <input class="criterion-input" name="criteria[<?= e($criterionName) ?>]" type="number" min="0" max="<?= e((string) $max) ?>" step="0.5" value="<?= e((string) ($savedScores[$criterionName] ?? '0')) ?>">
                    </div>
                <?php endforeach; ?>
                <div class="form-group">
                    <label>Total Score</label>
                    <input id="total-score" type="number" value="<?= e((string) ($assignment['total_score'] ?? '0')) ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="4"><?= e((string) ($assignment['remarks'] ?? '')) ?></textarea>
                </div>
                <button type="submit" class="btn-primary">Save Score</button>
            </form>
        <?php endif; ?>
        <?php if ($assignment['updated_at'] !== null): ?>
            <p class="page-subtitle" style="margin-top:12px;">Last saved: <?= e(formatUtcToIst((string) $assignment['updated_at'])) ?></p>
        <?php endif; ?>
    </article>
</section>
<script>
const totalScoreInput = document.getElementById('total-score');
function updateTotalScore() {
    if (!totalScoreInput) {
        return;
    }
    let total = 0;
    document.querySelectorAll('.criterion-input').forEach((input) => {
        total += Number(input.value || 0);
    });
    totalScoreInput.value = total.toFixed(1);
}
document.querySelectorAll('.criterion-input').forEach((input) => input.addEventListener('input', updateTotalScore));
updateTotalScore();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
