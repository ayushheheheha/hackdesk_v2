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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/problem-statements.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    if ($selectedHackathonId === null) {
        flash('error', 'Create a hackathon before managing problem statements.');
        redirect('portal/admin/problem-statements.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $domain = trim((string) ($_POST['domain'] ?? ''));
    $difficulty = trim((string) ($_POST['difficulty'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($action === 'create' || $action === 'update') {
        if ($title === '' || $description === '') {
            flash('error', 'Title and description are required.');
            redirect('portal/admin/problem-statements.php?hackathon_id=' . $selectedHackathonId);
        }
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO problem_statements (hackathon_id, title, description, domain, difficulty, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$selectedHackathonId, $title, $description, $domain !== '' ? $domain : null, $difficulty !== '' ? $difficulty : null, $isActive]);
        flash('success', 'Problem statement added successfully.');
        redirect('portal/admin/problem-statements.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'update') {
        $problemStatementId = filter_input(INPUT_POST, 'problem_statement_id', FILTER_VALIDATE_INT);
        if ($problemStatementId !== false && $problemStatementId !== null) {
            $stmt = $pdo->prepare(
                'UPDATE problem_statements
                 SET title = ?, description = ?, domain = ?, difficulty = ?, is_active = ?
                 WHERE id = ? AND hackathon_id = ?'
            );
            $stmt->execute([
                $title,
                $description,
                $domain !== '' ? $domain : null,
                $difficulty !== '' ? $difficulty : null,
                $isActive,
                $problemStatementId,
                $selectedHackathonId,
            ]);
            flash('success', 'Problem statement updated.');
        }
        redirect('portal/admin/problem-statements.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'toggle_active' || $action === 'soft_delete') {
        $problemStatementId = filter_input(INPUT_POST, 'problem_statement_id', FILTER_VALIDATE_INT);
        if ($problemStatementId !== false && $problemStatementId !== null) {
            $newState = $action === 'soft_delete' ? 0 : (isset($_POST['target_state']) && $_POST['target_state'] === '1' ? 1 : 0);
            $stmt = $pdo->prepare('UPDATE problem_statements SET is_active = ? WHERE id = ? AND hackathon_id = ?');
            $stmt->execute([$newState, $problemStatementId, $selectedHackathonId]);
            flash('success', $newState === 1 ? 'Problem statement activated.' : 'Problem statement deactivated.');
        }
        redirect('portal/admin/problem-statements.php?hackathon_id=' . $selectedHackathonId);
    }
}

$problemStatements = [];
if ($selectedHackathonId !== null) {
    $stmt = $pdo->prepare(
        'SELECT
            ps.id,
            ps.title,
            ps.description,
            ps.domain,
            ps.difficulty,
            ps.is_active,
            COUNT(t.id) AS team_count
         FROM problem_statements ps
         LEFT JOIN teams t ON t.problem_statement_id = ps.id
         WHERE ps.hackathon_id = ?
         GROUP BY ps.id, ps.title, ps.description, ps.domain, ps.difficulty, ps.is_active
         ORDER BY ps.created_at DESC, ps.id DESC'
    );
    $stmt->execute([$selectedHackathonId]);
    $problemStatements = $stmt->fetchAll();
}

$pageTitle = 'Problem Statements';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Problem Statements</h1>
        <p class="page-subtitle">Manage challenge tracks, keep history soft-deleted, and monitor adoption by teams.</p>
    </div>
</section>

<?php if ($hackathons === []): ?>
    <section class="card">
        <p class="empty-state">Create a hackathon first before adding problem statements.</p>
    </section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/problem-statements.php')) ?>">
            <div class="form-group" style="max-width:360px;">
                <label for="hackathon_id">Current Hackathon</label>
                <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>>
                            <?= e((string) $hackathon['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Add Problem Statement</h2>
            <form method="post" action="<?= e(appPath('portal/admin/problem-statements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:18px;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <div class="form-group"><label for="title">Title</label><input id="title" name="title" type="text" required></div>
                <div class="form-group"><label for="description">Description</label><textarea id="description" name="description" rows="5" required></textarea></div>
                <div class="form-group"><label for="domain">Domain</label><input id="domain" name="domain" type="text"></div>
                <div class="form-group">
                    <label for="difficulty">Difficulty</label>
                    <select id="difficulty" name="difficulty">
                        <option value="">Not Set</option>
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="is_active" value="1" checked style="width:auto;margin-right:8px;">Active</label>
                </div>
                <button type="submit" class="btn-primary">Add Problem Statement</button>
            </form>
        </article>

        <article class="card" style="grid-column: span 2;">
            <h2>Current Problem Statements</h2>
            <?php if ($problemStatements === []): ?>
                <p class="empty-state" style="margin-top:12px;">No problem statements have been added for this hackathon yet.</p>
            <?php else: ?>
                <div class="table-shell" style="margin-top:18px;">
                    <table>
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Domain</th>
                            <th>Difficulty</th>
                            <th>Teams</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($problemStatements as $problemStatement): ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $problemStatement['title']) ?></strong>
                                    <div class="page-subtitle" style="margin-top:6px;"><?= e((string) $problemStatement['description']) ?></div>
                                </td>
                                <td><?= e((string) ($problemStatement['domain'] ?? '-')) ?></td>
                                <td><?= e((string) ($problemStatement['difficulty'] ?? '-')) ?></td>
                                <td><?= e((string) $problemStatement['team_count']) ?></td>
                                <td><span class="badge <?= (int) $problemStatement['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $problemStatement['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <details>
                                        <summary class="btn-ghost" style="display:inline-flex;">Edit</summary>
                                        <form method="post" action="<?= e(appPath('portal/admin/problem-statements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:12px;min-width:280px;">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                            <input type="hidden" name="problem_statement_id" value="<?= e((string) $problemStatement['id']) ?>">
                                            <div class="form-group"><label>Title</label><input name="title" type="text" value="<?= e((string) $problemStatement['title']) ?>" required></div>
                                            <div class="form-group"><label>Description</label><textarea name="description" rows="4" required><?= e((string) $problemStatement['description']) ?></textarea></div>
                                            <div class="form-group"><label>Domain</label><input name="domain" type="text" value="<?= e((string) ($problemStatement['domain'] ?? '')) ?>"></div>
                                            <div class="form-group">
                                                <label>Difficulty</label>
                                                <select name="difficulty">
                                                    <option value="" <?= $problemStatement['difficulty'] === null ? 'selected' : '' ?>>Not Set</option>
                                                    <option value="beginner" <?= $problemStatement['difficulty'] === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                                                    <option value="intermediate" <?= $problemStatement['difficulty'] === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                                                    <option value="advanced" <?= $problemStatement['difficulty'] === 'advanced' ? 'selected' : '' ?>>Advanced</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><input type="checkbox" name="is_active" value="1" <?= (int) $problemStatement['is_active'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Active</label>
                                            </div>
                                            <button type="submit" class="btn-primary">Save</button>
                                        </form>
                                        <form method="post" action="<?= e(appPath('portal/admin/problem-statements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:8px;">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                            <input type="hidden" name="problem_statement_id" value="<?= e((string) $problemStatement['id']) ?>">
                                            <input type="hidden" name="target_state" value="<?= (int) $problemStatement['is_active'] === 1 ? '0' : '1' ?>">
                                            <button type="submit" class="btn-ghost"><?= (int) $problemStatement['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                        </form>
                                        <form method="post" action="<?= e(appPath('portal/admin/problem-statements.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="margin-top:8px;">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="soft_delete">
                                            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                            <input type="hidden" name="problem_statement_id" value="<?= e((string) $problemStatement['id']) ?>">
                                            <button type="submit" class="btn-ghost">Soft Delete</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
