<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();

$requestedHackathonId = filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT);
$hackathons = getAccessibleHackathons($pdo);
$selectedHackathonId = resolveSelectedHackathonId($pdo, $requestedHackathonId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedHackathonId !== null) {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/reports.php?hackathon_id=' . $selectedHackathonId);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_archived') {
        $stmt = $pdo->prepare('UPDATE hackathons SET status = ? WHERE id = ?');
        $stmt->execute(['completed', $selectedHackathonId]);
        flash('success', 'Hackathon marked completed for archive workflow.');
        redirect('portal/admin/reports.php?hackathon_id=' . $selectedHackathonId);
    }
}

if (isset($_GET['export']) && $selectedHackathonId !== null) {
    $export = trim((string) $_GET['export']);

    if ($export === 'participants') {
        $stmt = $pdo->prepare(
            'SELECT
                p.name,
                p.email,
                p.participant_type,
                COALESCE(p.college, "") AS college,
                COALESCE(p.department, "") AS department,
                p.check_in_status,
                COALESCE(t.name, "") AS team_name
             FROM participants p
             LEFT JOIN team_members tm ON tm.participant_id = p.id
             LEFT JOIN teams t ON t.id = tm.team_id
             WHERE p.hackathon_id = ?
             ORDER BY p.name ASC'
        );
        $stmt->execute([$selectedHackathonId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['name'],
                $row['email'],
                $row['participant_type'],
                $row['college'],
                $row['department'],
                $row['check_in_status'],
                $row['team_name'],
            ];
        }

        downloadCsv('participants-' . $selectedHackathonId . '.csv', ['name', 'email', 'type', 'college', 'department', 'check_in_status', 'team'], $rows);
    }

    if ($export === 'teams') {
        $stmt = $pdo->prepare(
            'SELECT
                t.id,
                t.name AS team_name,
                leader.name AS leader_name,
                COALESCE(ps.title, "") AS problem_statement,
                GROUP_CONCAT(DISTINCT member.name ORDER BY member.name SEPARATOR ", ") AS members,
                GROUP_CONCAT(DISTINCT CONCAT(r.name, ": ", COALESCE(s.status, "not_submitted")) ORDER BY r.round_number SEPARATOR " | ") AS submission_status,
                ROUND(AVG(sc.total_score), 2) AS average_score
             FROM teams t
             INNER JOIN participants leader ON leader.id = t.leader_participant_id
             LEFT JOIN team_members tm ON tm.team_id = t.id
             LEFT JOIN participants member ON member.id = tm.participant_id
             LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
             LEFT JOIN rounds r ON r.hackathon_id = t.hackathon_id
             LEFT JOIN submissions s ON s.team_id = t.id AND s.round_id = r.id
             LEFT JOIN scores sc ON sc.team_id = t.id
             WHERE t.hackathon_id = ?
             GROUP BY t.id, t.name, leader.name, ps.title
             ORDER BY t.name ASC'
        );
        $stmt->execute([$selectedHackathonId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['team_name'],
                $row['leader_name'],
                $row['members'],
                $row['problem_statement'],
                $row['submission_status'],
                (string) ($row['average_score'] ?? '0.00'),
            ];
        }

        downloadCsv('teams-' . $selectedHackathonId . '.csv', ['name', 'leader', 'members', 'problem_statement', 'submission_status', 'score'], $rows);
    }

    if ($export === 'scores') {
        $stmt = $pdo->prepare(
            'SELECT
                t.name AS team_name,
                u.name AS jury_name,
                r.name AS round_name,
                sc.criteria_scores,
                sc.total_score
             FROM scores sc
             INNER JOIN teams t ON t.id = sc.team_id
             INNER JOIN rounds r ON r.id = sc.round_id
             INNER JOIN jury_assignments ja ON ja.id = sc.jury_assignment_id
             INNER JOIN users u ON u.id = ja.jury_user_id
             WHERE r.hackathon_id = ?
             ORDER BY r.round_number ASC, t.name ASC, u.name ASC'
        );
        $stmt->execute([$selectedHackathonId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['team_name'],
                $row['jury_name'],
                $row['round_name'],
                $row['criteria_scores'],
                (string) $row['total_score'],
            ];
        }

        downloadCsv('scores-' . $selectedHackathonId . '.csv', ['team', 'jury', 'round', 'criteria_breakdown', 'total'], $rows);
    }

    if ($export === 'certificates') {
        $stmt = $pdo->prepare(
            'SELECT
                p.name AS participant_name,
                c.cert_type,
                c.special_title,
                c.issued_at,
                c.is_revoked
             FROM certificates c
             INNER JOIN participants p ON p.id = c.participant_id
             WHERE c.hackathon_id = ?
             ORDER BY c.issued_at DESC'
        );
        $stmt->execute([$selectedHackathonId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['participant_name'],
                $row['cert_type'] === 'special' && $row['special_title'] !== null ? 'special: ' . $row['special_title'] : $row['cert_type'],
                formatUtcToIst((string) $row['issued_at']),
                (int) $row['is_revoked'] === 1 ? 'yes' : 'no',
            ];
        }

        downloadCsv('certificate-log-' . $selectedHackathonId . '.csv', ['participant', 'type', 'issued_at', 'revoked'], $rows);
    }

    if ($export === 'orphan_files') {
        $base = dirname(__DIR__, 2) . '/uploads';
        $referenced = [];

        $pathQueries = [
            'SELECT id_card_path AS path FROM participants WHERE hackathon_id = ? AND id_card_path IS NOT NULL',
            'SELECT ppt_file_path AS path FROM submissions s INNER JOIN rounds r ON r.id = s.round_id WHERE r.hackathon_id = ? AND s.ppt_file_path IS NOT NULL',
            'SELECT file_path AS path FROM certificates WHERE hackathon_id = ? AND file_path IS NOT NULL',
        ];

        foreach ($pathQueries as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$selectedHackathonId]);
            foreach ($stmt->fetchAll() as $row) {
                $referenced[str_replace('\\', '/', ltrim((string) $row['path'], '/'))] = true;
            }
        }

        $scanDirs = [
            $base . '/id-cards/' . (int) $selectedHackathonId,
            $base . '/submissions/' . (int) $selectedHackathonId,
            $base . '/certificates/' . (int) $selectedHackathonId,
        ];

        $orphans = [];
        foreach ($scanDirs as $scanDir) {
            if (!is_dir($scanDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
                $relativePath = ltrim(str_replace(str_replace('\\', '/', dirname(__DIR__, 2)) . '/', '', $absolutePath), '/');
                if (!isset($referenced[$relativePath])) {
                    $orphans[] = [$relativePath, $fileInfo->getSize(), date('Y-m-d H:i:s', $fileInfo->getMTime())];
                }
            }
        }

        downloadCsv('orphan-files-' . $selectedHackathonId . '.csv', ['path', 'size_bytes', 'last_modified'], $orphans);
    }

    if ($export === 'archive_uploads') {
        $archiveRoot = dirname(__DIR__, 2) . '/uploads/archives';
        if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0775, true) && !is_dir($archiveRoot)) {
            flash('error', 'Could not create archive folder.');
            redirect('portal/admin/reports.php?hackathon_id=' . $selectedHackathonId);
        }

        $archiveName = 'hackathon-' . $selectedHackathonId . '-uploads-' . date('Ymd-His') . '.zip';
        $archivePath = $archiveRoot . '/' . $archiveName;

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            flash('error', 'Could not generate archive ZIP.');
            redirect('portal/admin/reports.php?hackathon_id=' . $selectedHackathonId);
        }

        $folders = [
            'id-cards/' . (int) $selectedHackathonId,
            'submissions/' . (int) $selectedHackathonId,
            'certificates/' . (int) $selectedHackathonId,
        ];
        foreach ($folders as $folder) {
            $fullDir = dirname(__DIR__, 2) . '/uploads/' . $folder;
            if (!is_dir($fullDir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $localName = 'uploads/' . $folder . '/' . ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($fullDir))), '/');
                $zip->addFile($fileInfo->getPathname(), $localName);
            }
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($archivePath) . '"');
        header('Content-Length: ' . filesize($archivePath));
        readfile($archivePath);
        exit;
    }
}

$selectedHackathon = null;
if ($selectedHackathonId !== null) {
    $hackathonStmt = $pdo->prepare('SELECT id, name, starts_at, venue FROM hackathons WHERE id = ? LIMIT 1');
    $hackathonStmt->execute([$selectedHackathonId]);
    $selectedHackathon = $hackathonStmt->fetch() ?: null;
}

$registrationTotals = ['total_registered' => 0, 'internal_count' => 0, 'external_count' => 0];
$registrationTrend = [];
$topInstitutions = [];
$checkinTotals = ['checked_in_count' => 0, 'not_checked_in_count' => 0];
$checkinByDepartment = [];
$teamStats = ['total_teams' => 0, 'avg_team_size' => 0, 'with_ps' => 0, 'without_ps' => 0];
$popularProblemStatements = [];
$submissionRate = [];
$submissionTimeliness = ['on_time' => 0, 'late' => 0];
$scoreDistribution = [];
$averageScoreByPs = [];

if ($selectedHackathonId !== null && $selectedHackathon !== null) {
    $registrationStmt = $pdo->prepare(
        'SELECT
            COUNT(id) AS total_registered,
            SUM(CASE WHEN participant_type = "internal" THEN 1 ELSE 0 END) AS internal_count,
            SUM(CASE WHEN participant_type = "external" THEN 1 ELSE 0 END) AS external_count
         FROM participants
         WHERE hackathon_id = ?'
    );
    $registrationStmt->execute([$selectedHackathonId]);
    $registrationTotals = $registrationStmt->fetch() ?: $registrationTotals;

    $registrationTrendStmt = $pdo->prepare(
        'SELECT DATE(registered_at) AS label, COUNT(id) AS total
         FROM participants
         WHERE hackathon_id = ?
         GROUP BY DATE(registered_at)
         ORDER BY DATE(registered_at) ASC'
    );
    $registrationTrendStmt->execute([$selectedHackathonId]);
    $registrationTrend = $registrationTrendStmt->fetchAll();

    $topInstitutionsStmt = $pdo->prepare(
        'SELECT
            CASE
                WHEN participant_type = "external" THEN COALESCE(college, "Unknown College")
                ELSE COALESCE(department, "Unknown Department")
            END AS label,
            COUNT(id) AS total
         FROM participants
         WHERE hackathon_id = ?
         GROUP BY label
         ORDER BY total DESC, label ASC
         LIMIT 8'
    );
    $topInstitutionsStmt->execute([$selectedHackathonId]);
    $topInstitutions = $topInstitutionsStmt->fetchAll();

    $checkinStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN check_in_status = "checked_in" THEN 1 ELSE 0 END) AS checked_in_count,
            SUM(CASE WHEN check_in_status <> "checked_in" THEN 1 ELSE 0 END) AS not_checked_in_count
         FROM participants
         WHERE hackathon_id = ?'
    );
    $checkinStmt->execute([$selectedHackathonId]);
    $checkinTotals = $checkinStmt->fetch() ?: $checkinTotals;

    $checkinByDepartmentStmt = $pdo->prepare(
        'SELECT
            COALESCE(department, college, "Unknown") AS label,
            ROUND(100 * SUM(CASE WHEN check_in_status = "checked_in" THEN 1 ELSE 0 END) / COUNT(id), 1) AS rate
         FROM participants
         WHERE hackathon_id = ?
         GROUP BY label
         ORDER BY rate DESC, label ASC
         LIMIT 8'
    );
    $checkinByDepartmentStmt->execute([$selectedHackathonId]);
    $checkinByDepartment = $checkinByDepartmentStmt->fetchAll();

    $teamStatsStmt = $pdo->prepare(
        'SELECT
            COUNT(t.id) AS total_teams,
            ROUND(AVG(team_counts.member_count), 2) AS avg_team_size,
            SUM(CASE WHEN t.problem_statement_id IS NOT NULL THEN 1 ELSE 0 END) AS with_ps,
            SUM(CASE WHEN t.problem_statement_id IS NULL THEN 1 ELSE 0 END) AS without_ps
         FROM teams t
         LEFT JOIN (
            SELECT team_id, COUNT(id) AS member_count
            FROM team_members
            GROUP BY team_id
         ) AS team_counts ON team_counts.team_id = t.id
         WHERE t.hackathon_id = ?'
    );
    $teamStatsStmt->execute([$selectedHackathonId]);
    $teamStats = $teamStatsStmt->fetch() ?: $teamStats;

    $popularPsStmt = $pdo->prepare(
        'SELECT ps.title AS label, COUNT(t.id) AS total
         FROM problem_statements ps
         LEFT JOIN teams t ON t.problem_statement_id = ps.id
         WHERE ps.hackathon_id = ?
         GROUP BY ps.id, ps.title
         ORDER BY total DESC, ps.title ASC
         LIMIT 8'
    );
    $popularPsStmt->execute([$selectedHackathonId]);
    $popularProblemStatements = $popularPsStmt->fetchAll();

    $submissionRateStmt = $pdo->prepare(
        'SELECT
            r.name AS label,
            COUNT(DISTINCT t.id) AS total_teams,
            COUNT(DISTINCT CASE WHEN s.status IN ("submitted", "late") THEN s.team_id END) AS submitted_teams
         FROM rounds r
         LEFT JOIN teams t ON t.hackathon_id = r.hackathon_id
         LEFT JOIN submissions s ON s.round_id = r.id AND s.team_id = t.id
         WHERE r.hackathon_id = ?
         GROUP BY r.id, r.name, r.round_number
         ORDER BY r.round_number ASC'
    );
    $submissionRateStmt->execute([$selectedHackathonId]);
    $submissionRate = $submissionRateStmt->fetchAll();

    $submissionTimelinessStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN s.status = "submitted" THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE WHEN s.status = "late" THEN 1 ELSE 0 END) AS late
         FROM submissions s
         INNER JOIN rounds r ON r.id = s.round_id
         WHERE r.hackathon_id = ?'
    );
    $submissionTimelinessStmt->execute([$selectedHackathonId]);
    $submissionTimeliness = $submissionTimelinessStmt->fetch() ?: $submissionTimeliness;

    $scoreDistributionStmt = $pdo->prepare(
        'SELECT CONCAT(FLOOR(total_score / 10) * 10, "-", FLOOR(total_score / 10) * 10 + 9.9) AS label, COUNT(id) AS total
         FROM scores
         WHERE round_id IN (SELECT id FROM rounds WHERE hackathon_id = ?)
         GROUP BY FLOOR(total_score / 10)
         ORDER BY FLOOR(total_score / 10) ASC'
    );
    $scoreDistributionStmt->execute([$selectedHackathonId]);
    $scoreDistribution = $scoreDistributionStmt->fetchAll();

    $avgScoreByPsStmt = $pdo->prepare(
        'SELECT
            COALESCE(ps.title, "No Problem Statement") AS label,
            ROUND(AVG(sc.total_score), 2) AS average_score
         FROM scores sc
         INNER JOIN teams t ON t.id = sc.team_id
         LEFT JOIN problem_statements ps ON ps.id = t.problem_statement_id
         WHERE t.hackathon_id = ?
         GROUP BY label
         ORDER BY average_score DESC, label ASC
         LIMIT 8'
    );
    $avgScoreByPsStmt->execute([$selectedHackathonId]);
    $averageScoreByPs = $avgScoreByPsStmt->fetchAll();
}

$checkInPercentage = ((int) ($checkinTotals['checked_in_count'] ?? 0) > 0 || (int) ($checkinTotals['not_checked_in_count'] ?? 0) > 0)
    ? round(
        ((int) ($checkinTotals['checked_in_count'] ?? 0) / max(1, ((int) ($checkinTotals['checked_in_count'] ?? 0) + (int) ($checkinTotals['not_checked_in_count'] ?? 0)))) * 100,
        1
    )
    : 0.0;

$pageTitle = 'Reports';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Reports & Export</h1>
        <p class="page-subtitle">Analytics and operational exports for the current hackathon.</p>
    </div>
</section>

<?php if ($hackathons === []): ?>
    <section class="card">
        <p class="empty-state">Create a hackathon before viewing analytics and exports.</p>
    </section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/reports.php')) ?>">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="hackathon_id">Hackathon</label>
                    <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                        <?php foreach ($hackathons as $hackathon): ?>
                            <option value="<?= e((string) $hackathon['id']) ?>" <?= (int) $hackathon['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>><?= e((string) $hackathon['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <div class="stat-value" data-countup="<?= e((string) ($registrationTotals['total_registered'] ?? 0)) ?>"><?= e((string) ($registrationTotals['total_registered'] ?? 0)) ?></div>
            <div class="stat-label">Registered Participants</div>
        </article>
        <article class="card">
            <div class="stat-value" data-countup="<?= e((string) ($teamStats['total_teams'] ?? 0)) ?>"><?= e((string) ($teamStats['total_teams'] ?? 0)) ?></div>
            <div class="stat-label">Teams Formed</div>
        </article>
        <article class="card">
            <div class="stat-value"><?= e((string) $checkInPercentage) ?>%</div>
            <div class="stat-label">Check-In Rate</div>
        </article>
    </section>

    <section class="card" style="margin-bottom:24px;">
        <div class="page-header" style="margin-bottom:16px;">
            <div>
                <h2>Exports</h2>
                <p class="page-subtitle">CSV downloads generated directly from the current hackathon data.</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=participants')) ?>">All Participants CSV</a>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=teams')) ?>">All Teams CSV</a>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=scores')) ?>">Scores CSV</a>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=certificates')) ?>">Certificate Log CSV</a>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=orphan_files')) ?>">Orphan Files CSV</a>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId . '&export=archive_uploads')) ?>">Archive Uploads ZIP</a>
            <form method="post" action="<?= e(appPath('portal/admin/reports.php?hackathon_id=' . (int) $selectedHackathonId)) ?>" style="display:inline-flex;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="mark_archived">
                <button type="submit" class="btn-ghost">Mark Event Completed</button>
            </form>
        </div>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Registration Stats</h2>
            <p class="page-subtitle" style="margin-top:8px;">Internal vs external participation.</p>
            <canvas id="registrationBreakdownChart" height="220" style="margin-top:16px;"></canvas>
        </article>
        <article class="card" style="grid-column: span 2;">
            <h2>Registrations Over Time</h2>
            <p class="page-subtitle" style="margin-top:8px;">Daily registrations for this hackathon.</p>
            <canvas id="registrationTrendChart" height="110" style="margin-top:16px;"></canvas>
        </article>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card" style="grid-column: span 2;">
            <h2>Top Colleges & Departments</h2>
            <canvas id="institutionChart" height="120" style="margin-top:16px;"></canvas>
        </article>
        <article class="card">
            <h2>Check-In Stats</h2>
            <div style="margin-top:16px;padding:14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span>Checked In</span>
                    <strong><?= e((string) ($checkinTotals['checked_in_count'] ?? 0)) ?> / <?= e((string) (((int) ($checkinTotals['checked_in_count'] ?? 0)) + ((int) ($checkinTotals['not_checked_in_count'] ?? 0)))) ?></strong>
                </div>
                <div style="height:12px;border-radius:999px;background:var(--bg-hover);overflow:hidden;">
                    <div style="width:<?= e((string) $checkInPercentage) ?>%;height:100%;background:var(--success);"></div>
                </div>
                <p class="page-subtitle" style="margin-top:10px;"><?= e((string) $checkInPercentage) ?>% checked in</p>
            </div>
            <canvas id="checkinDepartmentChart" height="220" style="margin-top:16px;"></canvas>
        </article>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Team Stats</h2>
            <div style="display:grid;gap:12px;margin-top:16px;">
                <div class="card" style="padding:14px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) ($teamStats['total_teams'] ?? 0)) ?></div>
                    <div class="stat-label">Teams Formed</div>
                </div>
                <div class="card" style="padding:14px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) ($teamStats['avg_team_size'] ?? 0)) ?></div>
                    <div class="stat-label">Average Team Size</div>
                </div>
                <div class="card" style="padding:14px;">
                    <div class="stat-value" style="font-size:22px;"><?= e((string) ($teamStats['with_ps'] ?? 0)) ?> / <?= e((string) ($teamStats['without_ps'] ?? 0)) ?></div>
                    <div class="stat-label">With PS / Without PS</div>
                </div>
            </div>
        </article>
        <article class="card" style="grid-column: span 2;">
            <h2>Most Popular Problem Statements</h2>
            <canvas id="popularPsChart" height="120" style="margin-top:16px;"></canvas>
        </article>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card" style="grid-column: span 2;">
            <h2>Submission Rate Per Round</h2>
            <canvas id="submissionRateChart" height="120" style="margin-top:16px;"></canvas>
        </article>
        <article class="card">
            <h2>Late vs On-Time</h2>
            <canvas id="submissionTimingChart" height="220" style="margin-top:16px;"></canvas>
        </article>
    </section>

    <section class="grid grid-3">
        <article class="card">
            <h2>Score Distribution</h2>
            <canvas id="scoreDistributionChart" height="220" style="margin-top:16px;"></canvas>
        </article>
        <article class="card" style="grid-column: span 2;">
            <h2>Average Score Per Problem Statement</h2>
            <canvas id="avgScoreByPsChart" height="120" style="margin-top:16px;"></canvas>
        </article>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const reportData = {
        registrationBreakdown: <?= json_encode([
            'labels' => ['Internal', 'External'],
            'values' => [(int) ($registrationTotals['internal_count'] ?? 0), (int) ($registrationTotals['external_count'] ?? 0)],
        ], JSON_UNESCAPED_SLASHES) ?>,
        registrationTrend: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $registrationTrend),
            'values' => array_map(static fn(array $row): int => (int) $row['total'], $registrationTrend),
        ], JSON_UNESCAPED_SLASHES) ?>,
        topInstitutions: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $topInstitutions),
            'values' => array_map(static fn(array $row): int => (int) $row['total'], $topInstitutions),
        ], JSON_UNESCAPED_SLASHES) ?>,
        checkinByDepartment: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $checkinByDepartment),
            'values' => array_map(static fn(array $row): float => (float) $row['rate'], $checkinByDepartment),
        ], JSON_UNESCAPED_SLASHES) ?>,
        popularPs: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $popularProblemStatements),
            'values' => array_map(static fn(array $row): int => (int) $row['total'], $popularProblemStatements),
        ], JSON_UNESCAPED_SLASHES) ?>,
        submissionRate: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $submissionRate),
            'values' => array_map(static fn(array $row): int => (int) $row['submitted_teams'], $submissionRate),
            'totals' => array_map(static fn(array $row): int => (int) $row['total_teams'], $submissionRate),
        ], JSON_UNESCAPED_SLASHES) ?>,
        submissionTimeliness: <?= json_encode([
            'labels' => ['On Time', 'Late'],
            'values' => [(int) ($submissionTimeliness['on_time'] ?? 0), (int) ($submissionTimeliness['late'] ?? 0)],
        ], JSON_UNESCAPED_SLASHES) ?>,
        scoreDistribution: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $scoreDistribution),
            'values' => array_map(static fn(array $row): int => (int) $row['total'], $scoreDistribution),
        ], JSON_UNESCAPED_SLASHES) ?>,
        avgScoreByPs: <?= json_encode([
            'labels' => array_map(static fn(array $row): string => (string) $row['label'], $averageScoreByPs),
            'values' => array_map(static fn(array $row): float => (float) $row['average_score'], $averageScoreByPs),
        ], JSON_UNESCAPED_SLASHES) ?>,
    };

    Chart.defaults.color = '#FAFAFA';
    Chart.defaults.borderColor = '#27272A';
    Chart.defaults.font.family = 'Inter';

    new Chart(document.getElementById('registrationBreakdownChart'), {
        type: 'doughnut',
        data: {
            labels: reportData.registrationBreakdown.labels,
            datasets: [{ data: reportData.registrationBreakdown.values, backgroundColor: ['rgba(91,91,214,0.85)', 'rgba(74,222,128,0.75)'] }]
        }
    });

    new Chart(document.getElementById('registrationTrendChart'), {
        type: 'line',
        data: {
            labels: reportData.registrationTrend.labels,
            datasets: [{ label: 'Registrations', data: reportData.registrationTrend.values, borderColor: '#5B5BD6', backgroundColor: 'rgba(91,91,214,0.18)', fill: true, tension: 0.3 }]
        }
    });

    new Chart(document.getElementById('institutionChart'), {
        type: 'bar',
        data: {
            labels: reportData.topInstitutions.labels,
            datasets: [{ label: 'Participants', data: reportData.topInstitutions.values, backgroundColor: 'rgba(91,91,214,0.75)' }]
        },
        options: { indexAxis: 'y' }
    });

    new Chart(document.getElementById('checkinDepartmentChart'), {
        type: 'bar',
        data: {
            labels: reportData.checkinByDepartment.labels,
            datasets: [{ label: 'Check-In Rate %', data: reportData.checkinByDepartment.values, backgroundColor: 'rgba(74,222,128,0.75)' }]
        },
        options: { scales: { y: { beginAtZero: true, max: 100 } } }
    });

    new Chart(document.getElementById('popularPsChart'), {
        type: 'bar',
        data: {
            labels: reportData.popularPs.labels,
            datasets: [{ label: 'Teams', data: reportData.popularPs.values, backgroundColor: 'rgba(251,191,36,0.75)' }]
        }
    });

    new Chart(document.getElementById('submissionRateChart'), {
        type: 'bar',
        data: {
            labels: reportData.submissionRate.labels,
            datasets: [
                { label: 'Submitted', data: reportData.submissionRate.values, backgroundColor: 'rgba(91,91,214,0.75)' },
                { label: 'Total Teams', data: reportData.submissionRate.totals, backgroundColor: 'rgba(113,113,122,0.45)' }
            ]
        }
    });

    new Chart(document.getElementById('submissionTimingChart'), {
        type: 'doughnut',
        data: {
            labels: reportData.submissionTimeliness.labels,
            datasets: [{ data: reportData.submissionTimeliness.values, backgroundColor: ['rgba(74,222,128,0.75)', 'rgba(248,113,113,0.75)'] }]
        }
    });

    new Chart(document.getElementById('scoreDistributionChart'), {
        type: 'bar',
        data: {
            labels: reportData.scoreDistribution.labels,
            datasets: [{ label: 'Score Count', data: reportData.scoreDistribution.values, backgroundColor: 'rgba(91,91,214,0.75)' }]
        }
    });

    new Chart(document.getElementById('avgScoreByPsChart'), {
        type: 'bar',
        data: {
            labels: reportData.avgScoreByPs.labels,
            datasets: [{ label: 'Average Score', data: reportData.avgScoreByPs.values, backgroundColor: 'rgba(74,222,128,0.75)' }]
        }
    });
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
