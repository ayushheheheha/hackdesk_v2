<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$requestedHackathonId = filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT);
$hackathons = getAccessibleHackathons($pdo);
$selectedHackathonId = resolveSelectedHackathonId($pdo, $requestedHackathonId);

$submissions = [];
if ($selectedHackathonId !== null) {
    $submissionsStmt = $pdo->prepare(
        'SELECT
            s.id,
            t.name AS team_name,
            r.name AS round_name,
            r.round_number,
            s.status,
            s.ppt_original_name,
            s.github_url,
            s.custom_link,
            s.submitted_at,
            s.last_updated_at
         FROM submissions s
         INNER JOIN teams t ON t.id = s.team_id
         INNER JOIN rounds r ON r.id = s.round_id
         WHERE r.hackathon_id = ?
         ORDER BY r.round_number ASC, s.submitted_at DESC, s.id DESC'
    );
    $submissionsStmt->execute([$selectedHackathonId]);
    $submissions = $submissionsStmt->fetchAll();
}

$pageTitle = 'Submissions';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Submissions</h1><p class="page-subtitle">Review uploaded decks, links, and submission status across all rounds.</p></div></section>
<?php if ($hackathons === []): ?>
    <section class="card"><p class="empty-state">Create a hackathon before reviewing submissions.</p></section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/submissions.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <section class="card">
        <?php if ($submissions === []): ?>
            <p class="empty-state">No submissions found for this hackathon yet.</p>
        <?php else: ?>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Round</th><th>Team</th><th>Status</th><th>PPT</th><th>GitHub</th><th>Custom Link</th><th>Submitted</th></tr></thead>
                    <tbody>
                    <?php foreach ($submissions as $submission): ?>
                        <tr>
                            <td><?= e((string) $submission['round_number']) ?> - <?= e((string) $submission['round_name']) ?></td>
                            <td><?= e((string) $submission['team_name']) ?></td>
                            <td><span class="badge <?= e(statusBadgeClass((string) $submission['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $submission['status']))) ?></span></td>
                            <td>
                                <?php if (!empty($submission['ppt_original_name'])): ?>
                                    <a class="btn-ghost" href="<?= e(appPath('public/submission-file.php?submission_id=' . (int) $submission['id'])) ?>">Download</a>
                                <?php else: ?>
                                    <span class="page-subtitle">None</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($submission['github_url']) ? '<a href="' . e((string) $submission['github_url']) . '" target="_blank" rel="noopener">Open</a>' : '<span class="page-subtitle">None</span>' ?></td>
                            <td><?= !empty($submission['custom_link']) ? '<a href="' . e((string) $submission['custom_link']) . '" target="_blank" rel="noopener">Open</a>' : '<span class="page-subtitle">None</span>' ?></td>
                            <td><?= e($submission['submitted_at'] !== null ? formatUtcToIst((string) $submission['submitted_at']) : 'Draft only') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
