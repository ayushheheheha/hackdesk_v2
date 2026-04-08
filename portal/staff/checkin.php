<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('staff');

$pdo = Database::getConnection();
$staffUserId = (int) ($_SESSION['user']['id'] ?? 0);
$requestedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT);
$hackathons = getAccessibleHackathons($pdo);
$selectedHackathonId = resolveSelectedHackathonId($pdo, $requestedHackathonId);
$pageFeedback = null;

$fetchStats = static function (PDO $pdo, int $hackathonId): array {
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(id) AS total_registered,
            SUM(CASE WHEN check_in_status = "checked_in" THEN 1 ELSE 0 END) AS total_checked_in
         FROM participants
         WHERE hackathon_id = ?'
    );
    $stmt->execute([$hackathonId]);
    $row = $stmt->fetch() ?: ['total_registered' => 0, 'total_checked_in' => 0];
    $total = (int) ($row['total_registered'] ?? 0);
    $checkedIn = (int) ($row['total_checked_in'] ?? 0);

    return [
        'total_registered' => $total,
        'total_checked_in' => $checkedIn,
        'percentage' => $total > 0 ? round(($checkedIn / $total) * 100, 1) : 0,
    ];
};

$findParticipantByCode = static function (PDO $pdo, int $hackathonId, string $code): array|false {
    $stmt = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.check_in_status,
            p.checked_in_at,
            t.name AS team_name
         FROM participants p
         LEFT JOIN team_members tm ON tm.participant_id = p.id
         LEFT JOIN teams t ON t.id = tm.team_id
         WHERE p.hackathon_id = ? AND (p.barcode_uid = ? OR p.qr_token = ? OR p.vit_reg_no = ?)
         LIMIT 1'
    );
    $stmt->execute([$hackathonId, $code, $code, normalizeVitRegNo($code)]);

    return $stmt->fetch();
};

$buildScanResult = static function (PDO $pdo, array|false $participant, int $hackathonId, int $staffUserId) use ($fetchStats): array {
    if ($participant === false) {
        return ['ok' => false, 'type' => 'error', 'message' => 'ID not found'];
    }

    if (($participant['check_in_status'] ?? '') === 'checked_in') {
        return [
            'ok' => true,
            'type' => 'warning',
            'message' => 'Already checked in at ' . formatUtcToIst((string) $participant['checked_in_at']),
            'participant' => [
                'name' => $participant['name'],
                'team_name' => $participant['team_name'] ?? 'No team',
            ],
            'stats' => $fetchStats($pdo, $hackathonId),
        ];
    }

    $updateStmt = $pdo->prepare(
        'UPDATE participants
         SET check_in_status = ?, checked_in_at = ?, checked_in_by = ?
         WHERE id = ?'
    );
    $updateStmt->execute(['checked_in', utcNow()->format('Y-m-d H:i:s'), $staffUserId, (int) $participant['id']]);

    return [
        'ok' => true,
        'type' => 'success',
        'message' => 'Checked in successfully',
        'participant' => [
            'name' => $participant['name'],
            'team_name' => $participant['team_name'] ?? 'No team',
        ],
        'stats' => $fetchStats($pdo, $hackathonId),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    if ($isAjax) {
        header('Content-Type: application/json');
    }

    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        $result = ['ok' => false, 'type' => 'error', 'message' => 'Session token expired. Refresh the page and try again.'];
        if ($isAjax) {
            echo json_encode($result);
            exit;
        }
        $pageFeedback = $result;
    } elseif ($selectedHackathonId === null) {
        $result = ['ok' => false, 'type' => 'error', 'message' => 'No hackathon selected.'];
        if ($isAjax) {
            echo json_encode($result);
            exit;
        }
        $pageFeedback = $result;
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'scan') {
            $code = trim((string) ($_POST['code'] ?? ''));
            $result = $buildScanResult($pdo, $findParticipantByCode($pdo, $selectedHackathonId, $code), $selectedHackathonId, $staffUserId);
            if ($isAjax) {
                echo json_encode($result);
                exit;
            }
            $pageFeedback = $result;
            $stats = $fetchStats($pdo, $selectedHackathonId);
        }

        if ($action === 'manual_checkin') {
            $participantId = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
            if ($participantId === false || $participantId === null) {
                $result = ['ok' => false, 'type' => 'error', 'message' => 'Invalid participant selected.'];
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE participants
                     SET check_in_status = ?, checked_in_at = ?, checked_in_by = ?
                     WHERE id = ? AND hackathon_id = ?'
                );
                $updateStmt->execute(['checked_in', utcNow()->format('Y-m-d H:i:s'), $staffUserId, $participantId, $selectedHackathonId]);
                $result = [
                    'ok' => true,
                    'type' => 'success',
                    'message' => 'Participant checked in.',
                    'stats' => $fetchStats($pdo, $selectedHackathonId),
                ];
            }

            if ($isAjax) {
                echo json_encode($result);
                exit;
            }
            $pageFeedback = $result;
            $stats = $fetchStats($pdo, $selectedHackathonId);
        }
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats' && $selectedHackathonId !== null) {
    header('Content-Type: application/json');
    echo json_encode($fetchStats($pdo, $selectedHackathonId));
    exit;
}

$manualSearch = trim((string) ($_GET['search'] ?? ''));
$searchResults = [];
if ($manualSearch !== '' && $selectedHackathonId !== null) {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, check_in_status
         FROM participants
         WHERE hackathon_id = ? AND (name LIKE ? OR email LIKE ?)
         ORDER BY name ASC
         LIMIT 25'
    );
    $like = '%' . $manualSearch . '%';
    $stmt->execute([$selectedHackathonId, $like, $like]);
    $searchResults = $stmt->fetchAll();
}

$stats = $selectedHackathonId !== null ? $fetchStats($pdo, $selectedHackathonId) : ['total_registered' => 0, 'total_checked_in' => 0, 'percentage' => 0];

$pageTitle = 'Check-In Scanner';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Check-In Scanner</h1>
        <p class="page-subtitle">Fast venue check-in for QR codes, barcodes, and manual fallback.</p>
    </div>
</section>

<section class="grid grid-3">
    <article class="card" style="grid-column: span 2;">
        <form id="scan-form" method="post" action="<?= e(appPath('portal/staff/checkin.php')) ?>">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="action" value="scan">
            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(CSRF::generate()) ?>">
            <div class="form-group">
                <label for="scan_code">Scan QR / Barcode</label>
                <input id="scan_code" name="code" type="text" autocomplete="off" autofocus placeholder="Scan QR, barcode, or VIT registration number and press Enter">
            </div>
            <button type="submit" class="btn-primary">Check In Participant</button>
        </form>
        <div id="scan-feedback" class="card" style="margin-top:18px;background:#131316;">
            <?php if ($pageFeedback !== null): ?>
                <div style="display:flex;gap:16px;align-items:center;">
                    <div style="width:56px;height:56px;border-radius:999px;background:#1C1C20;display:flex;align-items:center;justify-content:center;font-weight:700;"><?= e(isset($pageFeedback['participant']['name']) ? strtoupper(substr((string) $pageFeedback['participant']['name'], 0, 1)) : 'HD') ?></div>
                    <div>
                        <div style="font-size:20px;font-weight:700;"><?= e((string) $pageFeedback['message']) ?></div>
                        <?php if (isset($pageFeedback['participant']['name'])): ?>
                            <div class="page-subtitle" style="margin-top:8px;"><?= e((string) $pageFeedback['participant']['name']) ?> | <?= e((string) ($pageFeedback['participant']['team_name'] ?? 'No team')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="empty-state">Scan a participant QR code or barcode to begin check-in.</p>
            <?php endif; ?>
        </div>

        <form method="get" action="<?= e(appPath('portal/staff/checkin.php')) ?>" style="margin-top:24px;">
            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
            <div class="form-group">
                <label for="search">Manual Search</label>
                <input id="search" name="search" type="text" value="<?= e($manualSearch) ?>" placeholder="Search by name or email">
            </div>
            <button type="submit" class="btn-primary">Search</button>
        </form>

        <?php if ($searchResults !== []): ?>
            <div class="table-shell" style="margin-top:18px;">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($searchResults as $result): ?>
                        <tr>
                            <td><?= e((string) $result['name']) ?></td>
                            <td><?= e((string) $result['email']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) $result['check_in_status']))) ?></td>
                            <td><button type="button" class="btn-ghost manual-checkin" data-id="<?= e((string) $result['id']) ?>">Check In</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <aside class="card">
        <h2>Live Stats</h2>
        <div class="stat-value" id="stat-total" data-countup="<?= e((string) $stats['total_registered']) ?>"><?= e((string) $stats['total_registered']) ?></div>
        <p class="page-subtitle">Total Registered</p>
        <div class="stat-value" id="stat-checked" style="margin-top:18px;" data-countup="<?= e((string) $stats['total_checked_in']) ?>"><?= e((string) $stats['total_checked_in']) ?></div>
        <p class="page-subtitle">Checked In</p>
        <div class="stat-value" id="stat-percent" style="margin-top:18px;"><?= e((string) $stats['percentage']) ?>%</div>
        <p class="page-subtitle">Venue Attendance</p>
    </aside>
</section>

<script>
const scanForm = document.getElementById('scan-form');
const scanInput = document.getElementById('scan_code');
const feedback = document.getElementById('scan-feedback');
const statTotal = document.getElementById('stat-total');
const statChecked = document.getElementById('stat-checked');
const statPercent = document.getElementById('stat-percent');
const csrfToken = <?= json_encode(CSRF::generate()) ?>;
const hackathonId = <?= json_encode((int) $selectedHackathonId) ?>;
const requestPath = window.location.pathname.replace(/^\/+/, '/');
const ajaxField = scanForm.querySelector('input[name="ajax"]');

function renderFeedback(type, message, participant = null) {
    const colors = {
        success: ['rgba(74,222,128,0.12)', '#4ADE80'],
        warning: ['rgba(251,191,36,0.12)', '#FBBF24'],
        error: ['rgba(248,113,113,0.12)', '#F87171']
    };
    const selected = colors[type] || colors.error;
    feedback.style.background = selected[0];
    feedback.style.borderColor = selected[1];
    feedback.innerHTML = `
        <div style="display:flex;gap:16px;align-items:center;">
            <div style="width:56px;height:56px;border-radius:999px;background:#1C1C20;display:flex;align-items:center;justify-content:center;font-weight:700;">${participant ? participant.name.split(' ').slice(0, 2).map(p => p[0]).join('') : 'HD'}</div>
            <div>
                <div style="font-size:20px;font-weight:700;">${message}</div>
                ${participant ? `<div class="page-subtitle" style="margin-top:8px;">${participant.name} | ${participant.team_name || 'No team'}</div>` : ''}
            </div>
        </div>
    `;
}

function updateStats(stats) {
    if (!stats) return;
    statTotal.textContent = stats.total_registered;
    statChecked.textContent = stats.total_checked_in;
    statPercent.textContent = `${stats.percentage}%`;
}

scanForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const formData = new FormData(scanForm);
        const response = await fetch(requestPath + '?hackathon_id=' + hackathonId, { method: 'POST', body: formData });
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        const result = await response.json();
        renderFeedback(result.type, result.message, result.participant || null);
        updateStats(result.stats || null);
    } catch (error) {
        ajaxField.value = '0';
        scanForm.submit();
        return;
    } finally {
        setTimeout(() => {
            scanInput.value = '';
            scanInput.focus();
        }, 3000);
    }
});

document.querySelectorAll('.manual-checkin').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'manual_checkin');
            formData.append('hackathon_id', String(hackathonId));
            formData.append('participant_id', button.dataset.id);
            formData.append('csrf_token', csrfToken);
            const response = await fetch(requestPath + '?hackathon_id=' + hackathonId, { method: 'POST', body: formData });
            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }
            const result = await response.json();
            renderFeedback(result.type, result.message);
            updateStats(result.stats || null);
        } catch (error) {
            const fallbackForm = document.createElement('form');
            fallbackForm.method = 'post';
            fallbackForm.action = requestPath;
            fallbackForm.innerHTML = `
                <input type="hidden" name="ajax" value="0">
                <input type="hidden" name="action" value="manual_checkin">
                <input type="hidden" name="hackathon_id" value="${hackathonId}">
                <input type="hidden" name="participant_id" value="${button.dataset.id}">
                <input type="hidden" name="csrf_token" value="${csrfToken}">
            `;
            document.body.appendChild(fallbackForm);
            fallbackForm.submit();
        }
    });
});

setInterval(async () => {
    const response = await fetch(requestPath + '?hackathon_id=' + hackathonId + '&ajax=stats');
    const stats = await response.json();
    updateStats(stats);
}, 10000);
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
