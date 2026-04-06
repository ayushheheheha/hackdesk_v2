<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../config/database.php';

Middleware::requireParticipantAuth();
$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT
        ps.title,
        ps.description,
        ps.domain,
        ps.difficulty
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     INNER JOIN problem_statements ps ON ps.id = t.problem_statement_id
     WHERE tm.participant_id = ?
     LIMIT 1'
);
$stmt->execute([(int) ($_SESSION['participant_id'] ?? 0)]);
$problemStatement = $stmt->fetch();
$pageTitle = 'Problem Statement';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header"><div><h1>Problem Statement</h1><p class="page-subtitle">Your currently assigned challenge.</p></div></section>
<section class="card">
    <?php if ($problemStatement !== false): ?>
        <h2><?= e((string) $problemStatement['title']) ?></h2>
        <p class="page-subtitle" style="margin:10px 0 12px;"><?= e((string) ($problemStatement['domain'] ?? 'General')) ?> | <?= e(ucfirst((string) ($problemStatement['difficulty'] ?? 'open'))) ?></p>
        <p><?= e((string) $problemStatement['description']) ?></p>
    <?php else: ?>
        <p class="empty-state">Your team has not selected a problem statement yet.</p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
