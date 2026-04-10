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
$participantId = (int) ($_SESSION['participant_id'] ?? 0);
$hackathonId = (int) ($_SESSION['participant_hackathon_id'] ?? 0);

$teamStmt = $pdo->prepare(
    'SELECT t.id, t.name, t.leader_participant_id
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     WHERE tm.participant_id = ?
     LIMIT 1'
);
$teamStmt->execute([$participantId]);
$team = $teamStmt->fetch() ?: null;
$isLeader = $team !== null && (int) $team['leader_participant_id'] === $participantId;

if ($team !== null) {
    $lateStmt = $pdo->prepare(
        'SELECT r.id
         FROM rounds r
         LEFT JOIN submissions s ON s.round_id = r.id AND s.team_id = ?
         WHERE r.hackathon_id = ? AND r.submission_deadline < ? AND s.id IS NULL'
    );
    $lateStmt->execute([(int) $team['id'], $hackathonId, utcNow()->format('Y-m-d H:i:s')]);
    $lateRounds = $lateStmt->fetchAll();

    foreach ($lateRounds as $lateRound) {
        $insertLateStmt = $pdo->prepare(
            'INSERT INTO submissions (team_id, round_id, ppt_file_path, ppt_original_name, github_url, custom_link, abstract, submitted_at, last_updated_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertLateStmt->execute([(int) $team['id'], (int) $lateRound['id'], null, null, null, null, null, null, null, 'late']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/participant/submissions.php');
    }

    if ($team === null || !$isLeader) {
        flash('error', 'Only the team leader can manage submissions.');
        redirect('portal/participant/submissions.php');
    }

    $roundId = filter_input(INPUT_POST, 'round_id', FILTER_VALIDATE_INT);
    $intent = (string) ($_POST['intent'] ?? 'draft');

    $roundStmt = $pdo->prepare(
        'SELECT *
         FROM rounds
         WHERE id = ? AND hackathon_id = ?
         LIMIT 1'
    );
    $roundStmt->execute([$roundId, $hackathonId]);
    $round = $roundStmt->fetch();

    if ($round === false) {
        flash('error', 'Selected round was not found.');
        redirect('portal/participant/submissions.php');
    }

    $deadlinePassed = isDeadlinePassed((string) $round['submission_deadline']) || $round['status'] === 'closed' || $round['status'] === 'judging_done';
    if ($deadlinePassed) {
        flash('error', 'Submissions are locked for this round.');
        redirect('portal/participant/submissions.php');
    }

    $githubUrl = trim((string) ($_POST['github_url'] ?? ''));
    $customLink = trim((string) ($_POST['custom_link'] ?? ''));
    $abstract = trim((string) ($_POST['abstract'] ?? ''));

    if ((int) $round['github_required'] === 1 && !validateGithubUrl($githubUrl)) {
        flash('error', 'A valid GitHub URL is required.');
        redirect('portal/participant/submissions.php');
    }

    if ((int) $round['custom_link_allowed'] === 1 && $customLink !== '' && filter_var($customLink, FILTER_VALIDATE_URL) === false) {
        flash('error', 'Custom link must be a valid URL.');
        redirect('portal/participant/submissions.php');
    }

    if ((int) $round['abstract_required'] === 1 && $abstract === '') {
        flash('error', 'Abstract is required for this round.');
        redirect('portal/participant/submissions.php');
    }

    if (mb_strlen($abstract) > 500) {
        flash('error', 'Abstract must be 500 characters or fewer.');
        redirect('portal/participant/submissions.php');
    }

    $submissionStmt = $pdo->prepare('SELECT * FROM submissions WHERE team_id = ? AND round_id = ? LIMIT 1');
    $submissionStmt->execute([(int) $team['id'], $roundId]);
    $existingSubmission = $submissionStmt->fetch() ?: null;

    $pptFilePath = $existingSubmission['ppt_file_path'] ?? null;
    $pptOriginalName = $existingSubmission['ppt_original_name'] ?? null;

    if ((int) $round['ppt_required'] === 1 && isset($_FILES['ppt_file']) && (int) ($_FILES['ppt_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $storedFile = storeSubmissionFile($_FILES['ppt_file'], $hackathonId, (int) $roundId, (int) $team['id']);
            $pptFilePath = $storedFile['relative_path'];
            $pptOriginalName = $storedFile['original_name'];
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
            redirect('portal/participant/submissions.php');
        }
    } elseif ((int) $round['ppt_required'] === 1 && empty($pptFilePath) && $intent === 'submitted') {
        flash('error', 'A PPT, PPTX, or PDF file is required to submit this round.');
        redirect('portal/participant/submissions.php');
    }

    $status = $intent === 'submitted' ? 'submitted' : 'draft';
    $submittedAt = $intent === 'submitted' ? utcNow()->format('Y-m-d H:i:s') : null;
    $lastUpdatedAt = utcNow()->format('Y-m-d H:i:s');

    if ($existingSubmission === null) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO submissions (team_id, round_id, ppt_file_path, ppt_original_name, github_url, custom_link, abstract, submitted_at, last_updated_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            (int) $team['id'],
            (int) $roundId,
            $pptFilePath,
            $pptOriginalName,
            $githubUrl !== '' ? $githubUrl : null,
            $customLink !== '' ? $customLink : null,
            $abstract !== '' ? $abstract : null,
            $submittedAt,
            $lastUpdatedAt,
            $status,
        ]);
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE submissions
             SET ppt_file_path = ?, ppt_original_name = ?, github_url = ?, custom_link = ?, abstract = ?, submitted_at = ?, last_updated_at = ?, status = ?
             WHERE id = ?'
        );
        $updateStmt->execute([
            $pptFilePath,
            $pptOriginalName,
            $githubUrl !== '' ? $githubUrl : null,
            $customLink !== '' ? $customLink : null,
            $abstract !== '' ? $abstract : null,
            $submittedAt ?? $existingSubmission['submitted_at'],
            $lastUpdatedAt,
            $status,
            (int) $existingSubmission['id'],
        ]);
    }

    flash('success', $status === 'submitted' ? 'Submission saved and marked as submitted.' : 'Draft saved successfully.');
    redirect('portal/participant/submissions.php');
}

$rounds = [];
if ($team !== null) {
    $stmt = $pdo->prepare(
        'SELECT
            r.*,
            s.id AS submission_id,
            s.ppt_file_path,
            s.ppt_original_name,
            s.github_url,
            s.custom_link,
            s.abstract,
            s.submitted_at,
            s.last_updated_at,
            s.status AS submission_status
         FROM rounds r
         LEFT JOIN submissions s ON s.round_id = r.id AND s.team_id = ?
         WHERE r.hackathon_id = ?
         ORDER BY r.round_number ASC'
    );
    $stmt->execute([(int) $team['id'], $hackathonId]);
    $rounds = $stmt->fetchAll();
}

$pageTitle = 'My Submissions';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>My Submissions</h1>
        <p class="page-subtitle">Round-wise submissions for <?= e((string) ($team['name'] ?? 'your team')) ?>.</p>
    </div>
</section>
<?php if ($team === null): ?>
    <section class="card"><p class="empty-state">Join or create a team before submitting work.</p></section>
<?php else: ?>
    <?php foreach ($rounds as $round): ?>
        <?php
        $isUpcoming = $round['status'] === 'upcoming';
        $locked = isDeadlinePassed((string) $round['submission_deadline']) || in_array($round['status'], ['closed', 'judging_done'], true);
        $submissionStatus = $round['submission_status'] ?? null;
        ?>
        <section class="card" style="margin-bottom:24px;">
            <div class="page-header">
                <div>
                    <h2><?= e((string) $round['name']) ?></h2>
                    <p class="page-subtitle">Deadline: <?= e(formatUtcToIst((string) $round['submission_deadline'])) ?> | Status: <?= e((string) $round['status']) ?></p>
                </div>
                <span class="badge <?= $submissionStatus === 'submitted' ? 'badge-success' : 'badge-muted' ?>"><?= e((string) ($submissionStatus ?? 'not started')) ?></span>
            </div>

            <?php if ($isUpcoming): ?>
                <p class="empty-state">Submissions are not open yet.</p>
            <?php elseif ($locked): ?>
                <?php if ($submissionStatus !== null): ?>
                    <p><strong>Status:</strong> <?= e((string) $submissionStatus) ?></p>
                    <p style="margin-top:10px;"><strong>PPT:</strong>
                        <?= $round['ppt_file_path'] !== null ? '<a href="' . e(appPath('public/submission-file.php?submission_id=' . (int) $round['submission_id'])) . '">Download</a>' : 'Not uploaded' ?>
                    </p>
                    <p style="margin-top:10px;"><strong>GitHub:</strong> <?= e((string) ($round['github_url'] ?? '-')) ?></p>
                    <p style="margin-top:10px;"><strong>Custom Link:</strong> <?= e((string) ($round['custom_link'] ?? '-')) ?></p>
                    <p style="margin-top:10px;"><strong>Abstract:</strong> <?= e((string) ($round['abstract'] ?? '-')) ?></p>
                <?php else: ?>
                    <p class="empty-state">Not submitted.</p>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!$isLeader): ?>
                    <p class="empty-state">Only the team leader can submit for this round. You can view the current submission below.</p>
                <?php endif; ?>
                <?php
                $requiredChecks = [];
                if ((int) $round['ppt_required'] === 1) {
                    $requiredChecks[] = ['label' => 'PPT/PDF uploaded', 'ok' => !empty($round['ppt_file_path'])];
                }
                if ((int) $round['github_required'] === 1) {
                    $requiredChecks[] = ['label' => 'GitHub URL', 'ok' => !empty($round['github_url'])];
                }
                if ((int) $round['abstract_required'] === 1) {
                    $requiredChecks[] = ['label' => 'Abstract', 'ok' => !empty($round['abstract'])];
                }
                ?>
                <?php if ($requiredChecks !== []): ?>
                    <div class="card" style="margin-top:14px;background:var(--bg-hover);">
                        <h3>Required Checklist</h3>
                        <ul style="margin:10px 0 0 18px;padding:0;">
                            <?php foreach ($requiredChecks as $check): ?>
                                <li style="margin:4px 0;color:<?= $check['ok'] ? 'var(--success)' : 'var(--text-muted)' ?>;">
                                    <?= $check['ok'] ? 'Done' : 'Pending' ?> - <?= e((string) $check['label']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="<?= e(appPath('portal/participant/submissions.php')) ?>" style="margin-top:14px;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="round_id" value="<?= e((string) $round['id']) ?>">
                    <?php if ((int) $round['ppt_required'] === 1): ?>
                        <div class="form-group">
                            <label>PPT Upload</label>
                            <input name="ppt_file" type="file" accept=".ppt,.pptx,.pdf" <?= !$isLeader ? 'disabled' : '' ?> data-required-field="<?= (int) $round['ppt_required'] === 1 ? '1' : '0' ?>">
                            <?php if ($round['ppt_file_path'] !== null): ?>
                                <p class="page-subtitle" style="margin-top:8px;">Current: <a href="<?= e(appPath('public/submission-file.php?submission_id=' . (int) $round['submission_id'])) ?>"><?= e((string) $round['ppt_original_name']) ?></a></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ((int) $round['github_required'] === 1): ?>
                        <div class="form-group">
                            <label>GitHub URL</label>
                            <input name="github_url" type="url" value="<?= e((string) ($round['github_url'] ?? '')) ?>" <?= !$isLeader ? 'disabled' : '' ?> data-required-field="<?= (int) $round['github_required'] === 1 ? '1' : '0' ?>" data-required-label="GitHub URL">
                        </div>
                    <?php endif; ?>
                    <?php if ((int) $round['custom_link_allowed'] === 1): ?>
                        <div class="form-group">
                            <label>Custom Link</label>
                            <input name="custom_link" type="url" value="<?= e((string) ($round['custom_link'] ?? '')) ?>" <?= !$isLeader ? 'disabled' : '' ?>>
                        </div>
                    <?php endif; ?>
                    <?php if ((int) $round['abstract_required'] === 1): ?>
                        <div class="form-group">
                            <label>Abstract</label>
                            <textarea name="abstract" rows="4" maxlength="500" <?= !$isLeader ? 'disabled' : '' ?> data-required-field="<?= (int) $round['abstract_required'] === 1 ? '1' : '0' ?>" data-required-label="Abstract"><?= e((string) ($round['abstract'] ?? '')) ?></textarea>
                        </div>
                    <?php endif; ?>
                    <?php if ($isLeader): ?>
                        <button type="submit" name="intent" value="draft" class="btn-ghost">Save Draft</button>
                        <button type="submit" name="intent" value="submitted" class="btn-primary">Submit</button>
                        <span class="page-subtitle local-autosave" data-round-id="<?= e((string) $round['id']) ?>" style="display:inline-block;margin-left:12px;">No local autosave yet.</span>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<script>
document.querySelectorAll('form[action$="portal/participant/submissions.php"]').forEach((form) => {
    const roundIdInput = form.querySelector('input[name="round_id"]');
    const autosaveLabel = form.querySelector('.local-autosave');
    const storageKey = roundIdInput ? `hackdesk_submission_local_${roundIdInput.value}` : null;

    if (storageKey && autosaveLabel) {
        const lastSaved = localStorage.getItem(storageKey);
        if (lastSaved) {
            autosaveLabel.textContent = `Local draft updated at ${new Date(Number(lastSaved)).toLocaleString()}`;
        }

        form.querySelectorAll('input, textarea, select').forEach((field) => {
            field.addEventListener('input', () => {
                localStorage.setItem(storageKey, String(Date.now()));
                autosaveLabel.textContent = `Local draft updated at ${new Date().toLocaleString()}`;
            });
        });
    }

    form.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (!submitter || submitter.name !== 'intent' || submitter.value !== 'submitted') {
            return;
        }

        const missing = [];
        form.querySelectorAll('[data-required-field="1"]').forEach((field) => {
            if ((field.value || '').trim() === '') {
                missing.push(field.getAttribute('data-required-label') || 'Required field');
            }
        });

        const fileInput = form.querySelector('input[name="ppt_file"][data-required-field="1"]');
        if (fileInput && !(fileInput.files && fileInput.files.length > 0)) {
            const hasCurrent = form.querySelector('a[href*="submission-file.php"]');
            if (!hasCurrent) {
                missing.push('PPT/PDF upload');
            }
        }

        if (missing.length > 0) {
            event.preventDefault();
            alert(`Please complete required fields before submission:\n- ${missing.join('\n- ')}`);
        }
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
