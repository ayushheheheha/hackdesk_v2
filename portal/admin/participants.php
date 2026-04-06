<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare(
    'SELECT id, name, status, registration_deadline, starts_at
     FROM hackathons
     ORDER BY created_at DESC, id DESC'
);
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();

$selectedHackathonId = filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'hackathon_id', FILTER_VALIDATE_INT)
    ?: (($hackathons[0]['id'] ?? null) !== null ? (int) $hackathons[0]['id'] : null);

$selectedHackathon = null;
if ($selectedHackathonId !== null) {
    $selectedHackathonStmt = $pdo->prepare(
        'SELECT id, name, venue, starts_at, registration_deadline, status
         FROM hackathons
         WHERE id = ?
         LIMIT 1'
    );
    $selectedHackathonStmt->execute([$selectedHackathonId]);
    $selectedHackathon = $selectedHackathonStmt->fetch() ?: null;
}

$bulkSummary = null;
$bulkFlash = getFlash('bulk_summary');
if ($bulkFlash !== null) {
    $bulkSummary = json_decode($bulkFlash, true);
}

function participantExistsForHackathon(PDO $pdo, int $hackathonId, string $email): bool
{
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE hackathon_id = ? AND email = ? LIMIT 1');
    $stmt->execute([$hackathonId, strtolower(trim($email))]);

    return $stmt->fetch() !== false;
}

function validateInternalParticipantRow(array $row): array
{
    $name = trim((string) ($row['name'] ?? ''));
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
    $vitRegNo = trim((string) ($row['vit_reg_no'] ?? ''));
    $department = trim((string) ($row['department'] ?? ''));
    $year = (int) ($row['year'] ?? $row['year_of_study'] ?? 0);

    $errors = [];

    if ($name === '' || $email === '' || $vitRegNo === '' || $department === '' || $year <= 0) {
        $errors[] = 'Missing required fields.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
        $errors[] = 'Phone number must be 10 digits.';
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'vit_reg_no' => $vitRegNo,
            'department' => $department,
            'year_of_study' => $year,
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/admin/participants.php' . ($selectedHackathonId !== null ? '?hackathon_id=' . $selectedHackathonId : ''));
    }

    if ($selectedHackathon === null) {
        flash('error', 'Select a hackathon before managing participants.');
        redirect('portal/admin/participants.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'manual_add') {
        $validation = validateInternalParticipantRow([
            'name' => $_POST['name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'vit_reg_no' => $_POST['vit_reg_no'] ?? '',
            'department' => $_POST['department'] ?? '',
            'year_of_study' => $_POST['year_of_study'] ?? '',
        ]);

        if (!$validation['valid']) {
            flash('error', implode(' ', $validation['errors']));
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $data = $validation['data'];
        if (participantExistsForHackathon($pdo, (int) $selectedHackathonId, $data['email'])) {
            flash('error', 'That email is already registered for this hackathon.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO participants (
                hackathon_id,
                participant_type,
                name,
                email,
                phone,
                vit_reg_no,
                college,
                department,
                year_of_study,
                barcode_uid,
                qr_token,
                id_card_path,
                id_card_sent_at,
                check_in_status,
                checked_in_at,
                checked_in_by,
                registration_confirmed
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            (int) $selectedHackathonId,
            'internal',
            $data['name'],
            $data['email'],
            $data['phone'] !== '' ? $data['phone'] : null,
            $data['vit_reg_no'],
            null,
            $data['department'],
            $data['year_of_study'],
            generateBarcodeUID(),
            generateQRToken(),
            null,
            null,
            'not_checked_in',
            null,
            null,
            1,
        ]);

        $participantId = (int) $pdo->lastInsertId();
        $emailSent = sendParticipantRegistrationEmail($participantId);
        logActivity('internal_participant_added', 'participant', $participantId, ['email_sent' => $emailSent], (int) $selectedHackathonId);

        flash('success', $emailSent ? 'Participant added and registration email sent.' : 'Participant added, but the registration email could not be sent.');
        redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'bulk_upload') {
        $summary = [
            'inserted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'details' => [],
        ];

        if (!isset($_FILES['csv_file']) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid CSV file.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? finfo_file($finfo, $tmpFile) : null;
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $allowedMimeTypes = [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/csv',
        ];

        if ($mimeType !== null && !in_array($mimeType, $allowedMimeTypes, true)) {
            flash('error', 'The uploaded file must be a CSV.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $handle = fopen($tmpFile, 'rb');
        if ($handle === false) {
            flash('error', 'Unable to read the uploaded CSV file.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            flash('error', 'The CSV file is empty.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $normalizedHeader = array_map(static fn($value): string => strtolower(trim((string) $value)), $header);
        $requiredColumns = ['name', 'email', 'phone', 'vit_reg_no', 'department', 'year'];
        foreach ($requiredColumns as $column) {
            if (!in_array($column, $normalizedHeader, true)) {
                fclose($handle);
                flash('error', 'CSV is missing required column: ' . $column);
                redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
            }
        }

        $index = array_flip($normalizedHeader);
        $insertedIds = [];
        $insertStmt = $pdo->prepare(
            'INSERT INTO participants (
                hackathon_id,
                participant_type,
                name,
                email,
                phone,
                vit_reg_no,
                college,
                department,
                year_of_study,
                barcode_uid,
                qr_token,
                id_card_path,
                id_card_sent_at,
                check_in_status,
                checked_in_at,
                checked_in_by,
                registration_confirmed
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $pdo->beginTransaction();

        try {
            $rowNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $mapped = [
                    'name' => $row[$index['name']] ?? '',
                    'email' => $row[$index['email']] ?? '',
                    'phone' => $row[$index['phone']] ?? '',
                    'vit_reg_no' => $row[$index['vit_reg_no']] ?? '',
                    'department' => $row[$index['department']] ?? '',
                    'year' => $row[$index['year']] ?? '',
                ];

                $validation = validateInternalParticipantRow($mapped);
                if (!$validation['valid']) {
                    $summary['errors']++;
                    $summary['details'][] = 'Row ' . $rowNumber . ': ' . implode(' ', $validation['errors']);
                    continue;
                }

                $data = $validation['data'];
                if (participantExistsForHackathon($pdo, (int) $selectedHackathonId, $data['email'])) {
                    $summary['skipped']++;
                    $summary['details'][] = 'Row ' . $rowNumber . ': duplicate email skipped.';
                    continue;
                }

                $insertStmt->execute([
                    (int) $selectedHackathonId,
                    'internal',
                    $data['name'],
                    $data['email'],
                    $data['phone'] !== '' ? $data['phone'] : null,
                    $data['vit_reg_no'],
                    null,
                    $data['department'],
                    $data['year_of_study'],
                    generateBarcodeUID(),
                    generateQRToken(),
                    null,
                    null,
                    'not_checked_in',
                    null,
                    null,
                    1,
                ]);

                $insertedId = (int) $pdo->lastInsertId();
                $insertedIds[] = $insertedId;
                $summary['inserted']++;
            }

            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            fclose($handle);
            flash('error', 'Bulk import failed: ' . $throwable->getMessage());
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        fclose($handle);

        $sendNow = isset($_POST['send_now']) && $_POST['send_now'] === '1';
        if ($sendNow) {
            foreach ($insertedIds as $participantId) {
                if (sendParticipantRegistrationEmail($participantId)) {
                    $summary['email_sent']++;
                } else {
                    $summary['email_failed']++;
                }
            }
        }

        logActivity('internal_bulk_import', 'hackathon', (int) $selectedHackathonId, $summary, (int) $selectedHackathonId);
        flash('success', 'Bulk import complete.');
        flash('bulk_summary', json_encode($summary, JSON_UNESCAPED_SLASHES));
        redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'resend_id_card') {
        $participantId = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
        if ($participantId === false || $participantId === null) {
            flash('error', 'Invalid participant selected.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $checkStmt = $pdo->prepare('SELECT id FROM participants WHERE id = ? AND hackathon_id = ? LIMIT 1');
        $checkStmt->execute([$participantId, (int) $selectedHackathonId]);
        if ($checkStmt->fetch() === false) {
            flash('error', 'Participant not found in this hackathon.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $resent = sendParticipantRegistrationEmail((int) $participantId);
        flash('success', $resent ? 'Registration email sent again.' : 'We could not resend the registration email.');
        redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
    }

    if ($action === 'manual_checkin') {
        $participantId = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
        if ($participantId === false || $participantId === null) {
            flash('error', 'Invalid participant selected.');
            redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
        }

        $stmt = $pdo->prepare(
            'UPDATE participants
             SET check_in_status = ?, checked_in_at = ?, checked_in_by = ?
             WHERE id = ? AND hackathon_id = ?'
        );
        $stmt->execute([
            'checked_in',
            utcNow()->format('Y-m-d H:i:s'),
            (int) ($_SESSION['user']['id'] ?? 0),
            $participantId,
            (int) $selectedHackathonId,
        ]);

        flash('success', 'Participant marked as checked in.');
        redirect('portal/admin/participants.php?hackathon_id=' . $selectedHackathonId);
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'checked_in' && $selectedHackathonId !== null) {
    $stmt = $pdo->prepare(
        'SELECT name, email, participant_type, department, vit_reg_no, checked_in_at
         FROM participants
         WHERE hackathon_id = ? AND check_in_status = ?
         ORDER BY checked_in_at DESC'
    );
    $stmt->execute([$selectedHackathonId, 'checked_in']);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="checked-in-' . $selectedHackathonId . '.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['name', 'email', 'participant_type', 'department', 'vit_reg_no', 'checked_in_at']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['name'],
            $row['email'],
            $row['participant_type'],
            $row['department'],
            $row['vit_reg_no'],
            $row['checked_in_at'] !== null ? formatUtcToIst((string) $row['checked_in_at']) : '',
        ]);
    }
    fclose($output);
    exit;
}

$typeFilter = (string) ($_GET['type'] ?? '');
$checkinFilter = (string) ($_GET['checkin'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$allowedTypes = ['internal', 'external'];
$allowedCheckins = ['not_checked_in', 'checked_in', 'left'];

$where = [];
$params = [];

if ($selectedHackathonId !== null) {
    $where[] = 'p.hackathon_id = ?';
    $params[] = (int) $selectedHackathonId;
}

if (in_array($typeFilter, $allowedTypes, true)) {
    $where[] = 'p.participant_type = ?';
    $params[] = $typeFilter;
}

if (in_array($checkinFilter, $allowedCheckins, true)) {
    $where[] = 'p.check_in_status = ?';
    $params[] = $checkinFilter;
}

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.email LIKE ?)';
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
}

$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(p.id) AS total FROM participants p ' . $whereSql);
$countStmt->execute($params);
$totalParticipants = (int) ($countStmt->fetch()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalParticipants / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = '
    SELECT
        p.id,
        p.name,
        p.email,
        p.participant_type,
        p.department,
        p.vit_reg_no,
        p.college,
        p.check_in_status,
        p.checked_in_at,
        p.registered_at
    FROM participants p
    ' . $whereSql . '
    ORDER BY p.registered_at DESC, p.id DESC
    LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$participants = $listStmt->fetchAll();

$pageTitle = 'Participants';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Participants</h1>
        <p class="page-subtitle">Add internal VIT students, accept CSV imports, and manage registration emails.</p>
    </div>
</section>

<?php if ($hackathons === []): ?>
    <section class="card">
        <p class="empty-state">Create a hackathon first before registering participants.</p>
    </section>
<?php else: ?>
    <?php if (is_array($bulkSummary)): ?>
        <section class="card" style="margin-bottom:24px;">
            <h2>Bulk Upload Summary</h2>
            <p class="page-subtitle" style="margin:10px 0 14px;">
                Inserted: <?= e((string) ($bulkSummary['inserted'] ?? 0)) ?> |
                Skipped: <?= e((string) ($bulkSummary['skipped'] ?? 0)) ?> |
                Errors: <?= e((string) ($bulkSummary['errors'] ?? 0)) ?> |
                Emails Sent: <?= e((string) ($bulkSummary['email_sent'] ?? 0)) ?> |
                Emails Failed: <?= e((string) ($bulkSummary['email_failed'] ?? 0)) ?>
            </p>
            <?php if (!empty($bulkSummary['details'])): ?>
                <div class="table-shell">
                    <table>
                        <thead><tr><th>Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($bulkSummary['details'] as $detail): ?>
                            <tr><td><?= e((string) $detail) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card" style="margin-bottom:24px;">
        <form method="get" action="<?= e(appPath('portal/admin/participants.php')) ?>">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="hackathon_id">Current Hackathon</label>
                    <select id="hackathon_id" name="hackathon_id" onchange="this.form.submit()">
                        <?php foreach ($hackathons as $hackathonOption): ?>
                            <option value="<?= e((string) $hackathonOption['id']) ?>" <?= (int) $hackathonOption['id'] === (int) $selectedHackathonId ? 'selected' : '' ?>>
                                <?= e((string) $hackathonOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </section>

    <section class="grid grid-3" style="margin-bottom:24px;">
        <article class="card">
            <h2>Manual Add</h2>
            <p class="page-subtitle" style="margin:10px 0 18px;">Register one internal VIT participant and email portal access immediately.</p>
            <form method="post" action="<?= e(appPath('portal/admin/participants.php?hackathon_id=' . (int) $selectedHackathonId)) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="manual_add">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <div class="form-group"><label for="name">Name</label><input id="name" name="name" type="text" required></div>
                <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
                <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" pattern="\d{10}"></div>
                <div class="form-group"><label for="vit_reg_no">VIT Reg No</label><input id="vit_reg_no" name="vit_reg_no" type="text" required></div>
                <div class="form-group"><label for="department">Department</label><input id="department" name="department" type="text" required></div>
                <div class="form-group">
                    <label for="year_of_study">Year of Study</label>
                    <select id="year_of_study" name="year_of_study" required>
                        <option value="">Select Year</option>
                        <?php for ($year = 1; $year <= 6; $year++): ?>
                            <option value="<?= $year ?>"><?= e((string) $year) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Add Participant</button>
            </form>
        </article>

        <article class="card" style="grid-column: span 2;">
            <h2>Bulk CSV Upload</h2>
            <p class="page-subtitle" style="margin:10px 0 12px;">Upload a CSV with columns: <code>name,email,phone,vit_reg_no,department,year</code>.</p>
            <form method="post" enctype="multipart/form-data" action="<?= e(appPath('portal/admin/participants.php?hackathon_id=' . (int) $selectedHackathonId)) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="bulk_upload">
                <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                <div class="form-group">
                    <label for="csv_file">CSV File</label>
                    <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
                </div>
                <div class="form-group">
                    <label for="send_now">Email Delivery</label>
                    <select id="send_now" name="send_now">
                        <option value="1">Send registration emails now</option>
                        <option value="0">Send later</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Import CSV</button>
            </form>
        </article>
    </section>

    <section class="card">
        <div class="page-header" style="margin-bottom:18px;">
            <div>
                <h2>All Participants</h2>
                <p class="page-subtitle"><?= e((string) ($selectedHackathon['name'] ?? 'Selected Hackathon')) ?></p>
            </div>
            <a class="btn-ghost" href="<?= e(appPath('portal/admin/participants.php?hackathon_id=' . (int) $selectedHackathonId . '&export=checked_in')) ?>">Export Checked-In CSV</a>
        </div>

        <form method="get" action="<?= e(appPath('portal/admin/participants.php')) ?>" style="margin-bottom:18px;">
            <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="q">Search</label>
                    <input id="q" name="q" type="text" value="<?= e($search) ?>" placeholder="Name or email">
                </div>
                <div class="form-group">
                    <label for="type">Participant Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <option value="internal" <?= $typeFilter === 'internal' ? 'selected' : '' ?>>Internal</option>
                        <option value="external" <?= $typeFilter === 'external' ? 'selected' : '' ?>>External</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="checkin">Check-In Status</label>
                    <select id="checkin" name="checkin">
                        <option value="">All Statuses</option>
                        <option value="not_checked_in" <?= $checkinFilter === 'not_checked_in' ? 'selected' : '' ?>>Not Checked In</option>
                        <option value="checked_in" <?= $checkinFilter === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
                        <option value="left" <?= $checkinFilter === 'left' ? 'selected' : '' ?>>Left</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary">Apply Filters</button>
        </form>

        <div class="table-shell">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Type</th>
                    <th>Department</th>
                    <th>VIT Reg No</th>
                    <th>Check-In Status</th>
                    <th>Checked In At</th>
                    <th>Registered At</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($participants === []): ?>
                    <tr>
                        <td colspan="9">No participants matched the current filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($participants as $participant): ?>
                        <?php
                        $badgeClass = match ($participant['check_in_status']) {
                            'checked_in' => 'badge-success',
                            default => 'badge-muted',
                        };
                        ?>
                        <tr>
                            <td><?= e((string) $participant['name']) ?></td>
                            <td><?= e((string) $participant['email']) ?></td>
                            <td><?= e(ucfirst((string) $participant['participant_type'])) ?></td>
                            <td><?= e((string) ($participant['department'] ?? $participant['college'] ?? '-')) ?></td>
                            <td><?= e((string) ($participant['vit_reg_no'] ?? '-')) ?></td>
                            <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $participant['check_in_status']))) ?></span></td>
                            <td><?= e($participant['checked_in_at'] !== null ? formatUtcToIst((string) $participant['checked_in_at']) : 'Not checked in') ?></td>
                            <td><?= e(formatUtcToIst((string) $participant['registered_at'])) ?></td>
                            <td>
                                <form method="post" action="<?= e(appPath('portal/admin/participants.php?hackathon_id=' . (int) $selectedHackathonId . '&page=' . $page)) ?>">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="resend_id_card">
                                    <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                    <input type="hidden" name="participant_id" value="<?= e((string) $participant['id']) ?>">
                                    <button type="submit" class="btn-ghost">Resend Access Email</button>
                                </form>
                                <?php if ($participant['check_in_status'] !== 'checked_in'): ?>
                                    <form method="post" action="<?= e(appPath('portal/admin/participants.php?hackathon_id=' . (int) $selectedHackathonId . '&page=' . $page)) ?>" style="margin-top:8px;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="manual_checkin">
                                        <input type="hidden" name="hackathon_id" value="<?= e((string) $selectedHackathonId) ?>">
                                        <input type="hidden" name="participant_id" value="<?= e((string) $participant['id']) ?>">
                                        <button type="submit" class="btn-ghost">Mark Checked In</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php
                    $query = http_build_query([
                        'hackathon_id' => $selectedHackathonId,
                        'type' => $typeFilter,
                        'checkin' => $checkinFilter,
                        'q' => $search,
                        'page' => $p,
                    ]);
                    ?>
                    <a class="<?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(appPath('portal/admin/participants.php?' . $query)) ?>"><?= e((string) $p) ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
