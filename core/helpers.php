<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function sanitize(?string $input): string
{
    return htmlspecialchars(trim((string) $input), ENT_QUOTES, 'UTF-8');
}

function e(?string $input): string
{
    return htmlspecialchars((string) $input, ENT_QUOTES, 'UTF-8');
}

function appPath(string $path = ''): string
{
    $normalized = ltrim($path, '/');

    return rtrim(APP_URL, '/') . ($normalized !== '' ? '/' . $normalized : '');
}

function redirect(string $path): never
{
    $target = preg_match('#^https?://#i', $path) ? $path : appPath($path);
    header('Location: ' . $target);
    exit;
}

function flash(string $key, string $msg): void
{
    $_SESSION['flash'][$key] = $msg;
}

function getFlash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

function generateUniqueValue(string $table, string $column, int $bytes): string
{
    $pdo = Database::getConnection();

    do {
        $value = bin2hex(random_bytes($bytes));
        $stmt = $pdo->prepare(sprintf('SELECT %s FROM %s WHERE %s = ? LIMIT 1', $column, $table, $column));
        $stmt->execute([$value]);
    } while ($stmt->fetch() !== false);

    return $value;
}

function generateJoinCode(): string
{
    $pdo = Database::getConnection();
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $stmt = $pdo->prepare('SELECT id FROM teams WHERE join_code = ? LIMIT 1');
        $stmt->execute([$code]);
    } while ($stmt->fetch() !== false);

    return $code;
}

function generateBarcodeUID(): string
{
    $pdo = Database::getConnection();
    $yearPrefix = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('y');
    $prefix = $yearPrefix . 'HAK';
    $lockName = 'hackdesk_barcode_uid_' . $yearPrefix;

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$lockName]);
    $lockAcquired = (int) $lockStmt->fetchColumn() === 1;

    if (!$lockAcquired) {
        throw new RuntimeException('Unable to generate registration number right now.');
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT barcode_uid
             FROM participants
             WHERE barcode_uid LIKE ?
             ORDER BY barcode_uid DESC
             LIMIT 1'
        );
        $stmt->execute([$prefix . '%']);
        $lastValue = $stmt->fetchColumn();

        $nextNumber = 1;
        if (is_string($lastValue) && preg_match('/^\d{2}HAK(\d{4})$/', $lastValue, $matches) === 1) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        if ($nextNumber > 9999) {
            throw new RuntimeException('Yearly registration number limit reached.');
        }

        return sprintf('%s%04d', $prefix, $nextNumber);
    } finally {
        $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $unlockStmt->execute([$lockName]);
    }
}

function generateQRToken(): string
{
    return generateUniqueValue('participants', 'qr_token', 32);
}

function normalizeParticipantType(?string $type): ?string
{
    $type = strtolower(trim((string) $type));

    return in_array($type, ['internal', 'external'], true) ? $type : null;
}

function normalizeVitRegNo(?string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
}

function getClientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }

        $ip = trim(explode(',', $candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '0.0.0.0';
}

function logActivity(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $metadata = null,
    ?int $hackathonId = null
): void {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO activity_log (user_id, hackathon_id, action, entity_type, entity_id, metadata, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $_SESSION['user']['id'] ?? null,
        $hackathonId,
        $action,
        $entityType,
        $entityId,
        $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        getClientIp(),
    ]);
}

function isDeadlinePassed(?string $datetimeStr): bool
{
    if ($datetimeStr === null || trim($datetimeStr) === '') {
        return false;
    }

    $deadline = new DateTimeImmutable($datetimeStr, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $deadline <= $now;
}

function currentUserName(): string
{
    return e($_SESSION['user']['name'] ?? 'User');
}

function currentUserRole(): ?string
{
    $role = $_SESSION['user']['role'] ?? null;

    return is_string($role) && $role !== '' ? $role : null;
}

function currentAssignedHackathonId(): ?int
{
    $value = $_SESSION['user']['assigned_hackathon_id'] ?? null;

    return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
}

function getAccessibleHackathons(PDO $pdo): array
{
    $role = currentUserRole();
    $assignedHackathonId = currentAssignedHackathonId();

    if ($role === 'super_admin') {
        $stmt = $pdo->prepare('SELECT id, name FROM hackathons ORDER BY created_at DESC, id DESC');
        $stmt->execute();

        return $stmt->fetchAll();
    }

    if (in_array($role, ['admin', 'jury', 'staff'], true) && $assignedHackathonId !== null) {
        $stmt = $pdo->prepare('SELECT id, name FROM hackathons WHERE id = ? LIMIT 1');
        $stmt->execute([$assignedHackathonId]);

        return $stmt->fetchAll();
    }

    return [];
}

function resolveSelectedHackathonId(PDO $pdo, ?int $requestedHackathonId = null): ?int
{
    $accessibleHackathons = getAccessibleHackathons($pdo);
    if ($accessibleHackathons === []) {
        return null;
    }

    $accessibleIds = array_map(static fn(array $hackathon): int => (int) $hackathon['id'], $accessibleHackathons);

    if ($requestedHackathonId !== null) {
        if (in_array($requestedHackathonId, $accessibleIds, true)) {
            return $requestedHackathonId;
        }

        // Audit cross-hackathon access attempts for scoped roles.
        if (isset($_SESSION['user']['id'])) {
            logActivity(
                'hackathon_scope_violation',
                'hackathon',
                $requestedHackathonId,
                [
                    'requested_hackathon_id' => $requestedHackathonId,
                    'allowed_hackathon_ids' => $accessibleIds,
                    'role' => currentUserRole(),
                ],
                $accessibleIds[0] ?? null
            );
        }
    }

    return $accessibleIds[0];
}

function utcNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function formatUtcToIst(?string $datetimeStr, string $format = 'd M Y, h:i A'): string
{
    if ($datetimeStr === null || trim($datetimeStr) === '') {
        return 'TBA';
    }

    $dateTime = new DateTimeImmutable($datetimeStr, new DateTimeZone('UTC'));

    return $dateTime->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($format);
}

function istInputToUtc(?string $datetimeStr): ?string
{
    $value = trim((string) $datetimeStr);
    if ($value === '') {
        return null;
    }

    $timezone = new DateTimeZone('Asia/Kolkata');
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function utcToInput(?string $datetimeStr): string
{
    if ($datetimeStr === null || trim($datetimeStr) === '') {
        return '';
    }

    $dateTime = new DateTimeImmutable($datetimeStr, new DateTimeZone('UTC'));

    return $dateTime->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d\TH:i');
}

function generateParticipantSessionToken(): string
{
    return generateUniqueValue('participant_sessions', 'token', 24);
}

function generateParticipantOtpCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function createParticipantSession(int $participantId): string
{
    $pdo = Database::getConnection();
    $token = generateParticipantSessionToken();
    $expiresAt = utcNow()->modify('+7 days')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO participant_sessions (participant_id, token, expires_at, used_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$participantId, $token, $expiresAt, null]);

    return $token;
}

function createParticipantOtp(int $participantId, string $email): string
{
    $pdo = Database::getConnection();
    $code = generateParticipantOtpCode();
    $expiresAt = utcNow()->modify('+10 minutes')->format('Y-m-d H:i:s');

    $cleanupStmt = $pdo->prepare(
        'UPDATE participant_otps
         SET consumed_at = COALESCE(consumed_at, ?)
         WHERE participant_id = ? AND consumed_at IS NULL AND verified_at IS NULL'
    );
    $cleanupStmt->execute([utcNow()->format('Y-m-d H:i:s'), $participantId]);

    $stmt = $pdo->prepare(
        'INSERT INTO participant_otps (participant_id, email, code_hash, expires_at, verified_at, consumed_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $participantId,
        strtolower(trim($email)),
        password_hash($code, PASSWORD_ARGON2ID),
        $expiresAt,
        null,
        null,
    ]);

    return $code;
}

function buildParticipantMagicLink(string $token, ?int $hackathonId = null, ?string $participantType = null): string
{
    $params = ['token' => $token];

    if ($hackathonId !== null) {
        $params['h'] = (string) $hackathonId;
    }

    $normalizedType = normalizeParticipantType($participantType);
    if ($normalizedType !== null) {
        $params['type'] = $normalizedType;
    }

    return appPath('public/participant-login.php?' . http_build_query($params));
}

function sendParticipantOtpEmail(array $participant, string $otpCode): bool
{
    require_once __DIR__ . '/Mailer.php';

    $subject = 'Your HackDesk login code';
    $hackathonName = (string) ($participant['hackathon_name'] ?? 'HackDesk');
    $expiresAt = utcNow()->modify('+10 minutes')->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i A');
    $body = '
        <html lang="en">
        <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
                <h1 style="margin:0 0 14px;font-size:22px;">Your HackDesk login code</h1>
                <p style="margin:0 0 10px;color:#D4D4D8;">Hi ' . e((string) $participant['name']) . ',</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Use this one-time password to sign in to your participant portal for ' . e($hackathonName) . ':</p>
                <div style="margin:18px 0;padding:16px;background:#0C0C0E;border:1px solid #27272A;border-radius:10px;text-align:center;font-size:28px;font-weight:700;letter-spacing:0.18em;">' . e($otpCode) . '</div>
                <p style="margin:0 0 10px;color:#D4D4D8;">This code expires at ' . e($expiresAt) . ' and can be used once.</p>
                <p style="margin:0;color:#71717A;font-size:13px;">If you did not request this, you can ignore this email.</p>
            </div>
        </body>
        </html>
    ';

    return Mailer::sendMail(
        (string) $participant['email'],
        (string) $participant['name'],
        $subject,
        $body
    );
}

function buildParticipantRegistrationEmail(array $participant, array $hackathon, string $magicLink): array
{
    $isInternal = ($participant['participant_type'] ?? '') === 'internal';
    $subject = $isInternal
        ? "You're registered — {$hackathon['name']}"
        : "Your HackDesk ID Card — {$hackathon['name']}";

    $dateLine = formatUtcToIst($hackathon['starts_at'] ?? null, 'd M Y');
    $venue = $hackathon['venue'] ?? 'Venue details will be shared soon';
    $studentLine = $isInternal
        ? 'VIT Registration Number: ' . ($participant['vit_reg_no'] ?? 'N/A')
        : 'College: ' . ($participant['college'] ?? 'External Participant');

    $intro = $isInternal
        ? "You're registered for {$hackathon['name']}."
        : "Welcome, {$participant['name']}! You're registered for {$hackathon['name']}.";

    $html = '
        <html lang="en">
        <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
                <h1 style="margin:0 0 14px;font-size:22px;">' . e(APP_NAME) . '</h1>
                <p style="margin:0 0 12px;color:#FAFAFA;">' . e($intro) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Event: ' . e($hackathon['name']) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Venue: ' . e((string) $venue) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Date: ' . e($dateLine) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Registration Number: ' . e((string) $participant['barcode_uid']) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">' . e($studentLine) . '</p>
                <p style="margin:18px 0 10px;color:#FAFAFA;">Your ID card is attached. Bring it printed or on your phone and show the QR code or barcode at entry for check-in.</p>
                <p style="margin:0 0 18px;color:#FAFAFA;">Participant portal access: <a href="' . e($magicLink) . '" style="color:#A5B4FC;">Open your magic login link</a></p>
                <p style="margin:0;color:#71717A;font-size:13px;">This link expires in 7 days for security.</p>
            </div>
        </body>
        </html>
    ';

    return [
        'subject' => $subject,
        'html' => $html,
    ];
}

function buildParticipantRegistrationEmailV2(array $participant, array $hackathon, string $magicLink): array
{
    $isInternal = ($participant['participant_type'] ?? '') === 'internal';
    $subject = $isInternal
        ? "You're registered - {$hackathon['name']}"
        : "Your HackDesk ID Card - {$hackathon['name']}";

    $dateLine = formatUtcToIst($hackathon['starts_at'] ?? null, 'd M Y');
    $venue = $hackathon['venue'] ?? 'Venue details will be shared soon';
    $studentLine = $isInternal
        ? 'VIT Registration Number: ' . ($participant['vit_reg_no'] ?? 'N/A')
        : 'College: ' . ($participant['college'] ?? 'External Participant');
    $intro = $isInternal
        ? "You're registered for {$hackathon['name']}."
        : "Welcome, {$participant['name']}! You're registered for {$hackathon['name']}.";
    $accessCopy = $isInternal
        ? 'Bring your VIT ID card to the venue. Staff will check you in using your VIT registration number or campus barcode.'
        : 'Your ID card is attached. Bring it printed or on your phone and show the QR code or barcode at entry for check-in.';

    $html = '
        <html lang="en">
        <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
                <h1 style="margin:0 0 14px;font-size:22px;">' . e(APP_NAME) . '</h1>
                <p style="margin:0 0 12px;color:#FAFAFA;">' . e($intro) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Event: ' . e((string) $hackathon['name']) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Venue: ' . e((string) $venue) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Date: ' . e($dateLine) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Registration Number: ' . e((string) $participant['barcode_uid']) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">' . e($studentLine) . '</p>
                <p style="margin:18px 0 10px;color:#FAFAFA;">' . e($accessCopy) . '</p>
                <p style="margin:0 0 18px;color:#FAFAFA;">Participant portal access: <a href="' . e($magicLink) . '" style="color:#A5B4FC;">Open your magic login link</a></p>
                <p style="margin:0;color:#71717A;font-size:13px;">This link expires in 7 days for security.</p>
            </div>
        </body>
        </html>
    ';

    return [
        'subject' => $subject,
        'html' => $html,
    ];
}

function sendParticipantRegistrationEmail(int $participantId): bool
{
    require_once __DIR__ . '/Mailer.php';

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT
            p.id,
            p.hackathon_id,
            p.participant_type,
            p.name,
            p.email,
            p.phone,
            p.vit_reg_no,
            p.college,
            p.department,
            p.year_of_study,
            p.barcode_uid,
            p.qr_token,
            p.id_card_path,
            h.name AS hackathon_name,
            h.venue,
            h.starts_at
         FROM participants p
         INNER JOIN hackathons h ON h.id = p.hackathon_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $stmt->execute([$participantId]);
    $participant = $stmt->fetch();

    if ($participant === false) {
        return false;
    }

    $isInternal = ($participant['participant_type'] ?? '') === 'internal';
    $cardPath = null;

    if (!$isInternal) {
        require_once __DIR__ . '/IDCard.php';
        $cardPath = IDCard::generate($participantId);
    }

    $magicToken = createParticipantSession($participantId);
    $magicLink = buildParticipantMagicLink(
        $magicToken,
        (int) $participant['hackathon_id'],
        (string) $participant['participant_type']
    );
    $emailContent = buildParticipantRegistrationEmailV2($participant, [
        'name' => $participant['hackathon_name'],
        'venue' => $participant['venue'],
        'starts_at' => $participant['starts_at'],
    ], $magicLink);

    $attachments = [];
    if ($cardPath !== null) {
        $attachments[] = [
            'path' => $cardPath,
            'name' => basename($cardPath),
        ];
    }

    $sent = Mailer::sendMail(
        (string) $participant['email'],
        (string) $participant['name'],
        $emailContent['subject'],
        $emailContent['html'],
        $attachments
    );

    if ($sent) {
        $updateStmt = $pdo->prepare('UPDATE participants SET id_card_sent_at = ? WHERE id = ?');
        $updateStmt->execute([utcNow()->format('Y-m-d H:i:s'), $participantId]);
    }

    return $sent;
}

function parseJudgingCriteria(array $names, array $maxScores): array
{
    $criteria = [];

    foreach ($names as $index => $name) {
        $criterionName = trim((string) $name);
        $maxScore = isset($maxScores[$index]) ? (float) $maxScores[$index] : 0.0;

        if ($criterionName === '' || $maxScore <= 0) {
            continue;
        }

        $criteria[] = [
            'name' => $criterionName,
            'max' => $maxScore,
        ];
    }

    return $criteria;
}

function decodeCriteria(?string $criteriaJson): array
{
    if ($criteriaJson === null || trim($criteriaJson) === '') {
        return [];
    }

    $decoded = json_decode($criteriaJson, true);

    return is_array($decoded) ? $decoded : [];
}

function validateGithubUrl(?string $url): bool
{
    $url = trim((string) $url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);

    return is_string($host) && str_contains(strtolower($host), 'github.com');
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function storeSubmissionFile(array $file, int $hackathonId, int $roundId, int $teamId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Presentation upload failed.');
    }

    $allowedMimeTypes = [
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/pdf' => 'pdf',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo !== false) {
        finfo_close($finfo);
    }

    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Only PPT, PPTX, and PDF files are allowed.');
    }

    if (($file['size'] ?? 0) > 25 * 1024 * 1024) {
        throw new RuntimeException('Presentation file must be 25MB or smaller.');
    }

    $directory = dirname(__DIR__) . '/uploads/submissions/' . $hackathonId . '/' . $roundId;
    ensureDirectory($directory);

    $extension = $allowedMimeTypes[$mimeType];
    $filename = $teamId . '_' . time() . '.' . $extension;
    $targetPath = $directory . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to store the uploaded presentation.');
    }

    return [
        'relative_path' => 'uploads/submissions/' . $hackathonId . '/' . $roundId . '/' . $filename,
        'original_name' => basename((string) ($file['name'] ?? 'presentation.' . $extension)),
        'mime_type' => $mimeType,
    ];
}

function generateTemporaryPassword(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function validatePasswordStrength(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function sendUserAccountEmail(string $email, string $name, string $temporaryPassword): bool
{
    require_once __DIR__ . '/Mailer.php';

    $subject = 'Your HackDesk account';
    $body = '
        <html lang="en">
        <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
                <h1 style="margin:0 0 14px;font-size:22px;">Your HackDesk account</h1>
                <p style="margin:0 0 10px;color:#D4D4D8;">Your account has been created.</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Email: ' . e($email) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Temporary password: ' . e($temporaryPassword) . '</p>
                <p style="margin:0 0 10px;color:#D4D4D8;">Login at: <a href="' . e(appPath('public/login.php')) . '" style="color:#A5B4FC;">' . e(appPath('public/login.php')) . '</a></p>
                <p style="margin:0;color:#D4D4D8;">Change your password immediately after login.</p>
            </div>
        </body>
        </html>
    ';

    return Mailer::sendMail($email, $name, $subject, $body);
}

function downloadCsv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'registration_open', 'open', 'ongoing', 'completed', 'checked_in', 'judging_done' => 'badge-success',
        'cancelled', 'disqualified', 'late', 'revoked' => 'badge-danger',
        'draft', 'upcoming', 'closed', 'judging', 'forming', 'not_checked_in' => 'badge-warning',
        default => 'badge-muted',
    };
}

function ensureOperationalTables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            hackathon_id INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            target_scope ENUM("all","participant","jury","staff","admin","team","round") NOT NULL DEFAULT "all",
            target_team_id INT UNSIGNED NULL,
            target_round_id INT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            send_email TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            published_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reminder_dispatch (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            hackathon_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            offset_hours INT NOT NULL,
            recipient_email VARCHAR(180) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reminder_dispatch (event_type, event_id, offset_hours, recipient_email)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS round_advancements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            hackathon_id INT UNSIGNED NOT NULL,
            round_id INT UNSIGNED NOT NULL,
            team_id INT UNSIGNED NOT NULL,
            is_selected TINYINT(1) NOT NULL DEFAULT 0,
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_round_advancement (round_id, team_id),
            FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
            FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );
}

function teamEligibleForRound(PDO $pdo, int $teamId, int $roundId): bool
{
    ensureOperationalTables($pdo);

    $roundStmt = $pdo->prepare('SELECT id, hackathon_id, round_number FROM rounds WHERE id = ? LIMIT 1');
    $roundStmt->execute([$roundId]);
    $round = $roundStmt->fetch();
    if ($round === false) {
        return false;
    }

    if ((int) $round['round_number'] <= 1) {
        return true;
    }

    $prevStmt = $pdo->prepare(
        'SELECT ra.id
         FROM round_advancements ra
         INNER JOIN rounds r ON r.id = ra.round_id
         WHERE ra.team_id = ? AND r.hackathon_id = ? AND r.round_number = ? AND ra.is_selected = 1
         LIMIT 1'
    );
    $prevStmt->execute([$teamId, (int) $round['hackathon_id'], (int) $round['round_number'] - 1]);

    return $prevStmt->fetch() !== false;
}

function fetchAnnouncements(PDO $pdo, int $hackathonId, string $role, ?int $teamId = null, ?int $roundId = null): array
{
    ensureOperationalTables($pdo);

    $baseScopes = ['all', $role];
    $where = ['a.hackathon_id = ?', 'a.is_active = 1'];
    $params = [$hackathonId];

    $scopeConditions = [];
    foreach ($baseScopes as $scope) {
        $scopeConditions[] = 'a.target_scope = ?';
        $params[] = $scope;
    }
    if ($teamId !== null) {
        $scopeConditions[] = '(a.target_scope = "team" AND a.target_team_id = ?)';
        $params[] = $teamId;
    }
    if ($roundId !== null) {
        $scopeConditions[] = '(a.target_scope = "round" AND a.target_round_id = ?)';
        $params[] = $roundId;
    }

    $where[] = '(' . implode(' OR ', $scopeConditions) . ')';

    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.body, a.target_scope, a.published_at, u.name AS author_name
         FROM announcements a
         INNER JOIN users u ON u.id = a.created_by
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.published_at DESC, a.id DESC
         LIMIT 8'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function runDeadlineReminders(PDO $pdo, int $hackathonId): array
{
    require_once __DIR__ . '/Mailer.php';

    ensureOperationalTables($pdo);

    $offsets = [48, 12, 1];
    $now = utcNow();
    $sent = 0;
    $skipped = 0;

    $sendIfDue = static function (
        PDO $pdo,
        string $eventType,
        int $eventId,
        int $hackathonId,
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $body,
        array $offsets,
        DateTimeImmutable $deadline,
        DateTimeImmutable $now
    ) use (&$sent, &$skipped): void {
        $secondsRemaining = $deadline->getTimestamp() - $now->getTimestamp();
        if ($secondsRemaining <= 0) {
            return;
        }

        rsort($offsets);
        $targetOffset = null;
        $offsetCount = count($offsets);
        for ($i = 0; $i < $offsetCount; $i++) {
            $current = (int) $offsets[$i];
            $next = $i + 1 < $offsetCount ? (int) $offsets[$i + 1] : 0;
            if ($secondsRemaining <= ($current * 3600) && $secondsRemaining > ($next * 3600)) {
                $targetOffset = $current;
                break;
            }
        }

        if ($targetOffset === null) {
            return;
        }

        $checkStmt = $pdo->prepare(
            'SELECT id FROM reminder_dispatch
             WHERE event_type = ? AND event_id = ? AND offset_hours = ? AND recipient_email = ?
             LIMIT 1'
        );
        $checkStmt->execute([$eventType, $eventId, $targetOffset, strtolower($recipientEmail)]);
        if ($checkStmt->fetch() !== false) {
            $skipped++;
            return;
        }

        $ok = Mailer::sendMail($recipientEmail, $recipientName, $subject, $body);
        $insertStmt = $pdo->prepare(
            'INSERT INTO reminder_dispatch (hackathon_id, event_type, event_id, offset_hours, recipient_email)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([$hackathonId, $eventType, $eventId, $targetOffset, strtolower($recipientEmail)]);
        if ($ok) {
            $sent++;
        } else {
            $skipped++;
        }

    };

    $psStmt = $pdo->prepare('SELECT id, name, ps_selection_deadline FROM hackathons WHERE id = ? AND ps_selection_deadline IS NOT NULL LIMIT 1');
    $psStmt->execute([$hackathonId]);
    $hackathon = $psStmt->fetch();
    if ($hackathon !== false && !empty($hackathon['ps_selection_deadline'])) {
        $deadline = new DateTimeImmutable((string) $hackathon['ps_selection_deadline'], new DateTimeZone('UTC'));
        $leadersStmt = $pdo->prepare(
            'SELECT DISTINCT p.email, p.name
             FROM teams t
             INNER JOIN participants p ON p.id = t.leader_participant_id
             WHERE t.hackathon_id = ?'
        );
        $leadersStmt->execute([$hackathonId]);
        foreach ($leadersStmt->fetchAll() as $leader) {
            $subject = 'Reminder: Problem statement deadline for ' . (string) $hackathon['name'];
            $body = '<p>Your problem statement selection deadline is approaching at <strong>' . e(formatUtcToIst((string) $hackathon['ps_selection_deadline'])) . '</strong>.</p>';
            $sendIfDue(
                $pdo,
                'ps_selection',
                (int) $hackathon['id'],
                $hackathonId,
                (string) $leader['email'],
                (string) $leader['name'],
                $subject,
                $body,
                $offsets,
                $deadline,
                $now
            );
        }
    }

    $roundStmt = $pdo->prepare(
        'SELECT id, name, submission_deadline, judging_deadline
         FROM rounds
         WHERE hackathon_id = ?'
    );
    $roundStmt->execute([$hackathonId]);
    $rounds = $roundStmt->fetchAll();

    foreach ($rounds as $round) {
        if (!empty($round['submission_deadline'])) {
            $deadline = new DateTimeImmutable((string) $round['submission_deadline'], new DateTimeZone('UTC'));
            $leadersStmt = $pdo->prepare(
                'SELECT DISTINCT p.email, p.name
                 FROM teams t
                 INNER JOIN participants p ON p.id = t.leader_participant_id
                 WHERE t.hackathon_id = ?'
            );
            $leadersStmt->execute([$hackathonId]);
            foreach ($leadersStmt->fetchAll() as $leader) {
                $subject = 'Reminder: Submission deadline for ' . (string) $round['name'];
                $body = '<p>Round submission deadline is <strong>' . e(formatUtcToIst((string) $round['submission_deadline'])) . '</strong>.</p>';
                $sendIfDue(
                    $pdo,
                    'round_submission',
                    (int) $round['id'],
                    $hackathonId,
                    (string) $leader['email'],
                    (string) $leader['name'],
                    $subject,
                    $body,
                    $offsets,
                    $deadline,
                    $now
                );
            }
        }

        if (!empty($round['judging_deadline'])) {
            $deadline = new DateTimeImmutable((string) $round['judging_deadline'], new DateTimeZone('UTC'));
            $juryStmt = $pdo->prepare(
                'SELECT DISTINCT u.email, u.name
                 FROM jury_assignments ja
                 INNER JOIN users u ON u.id = ja.jury_user_id
                 WHERE ja.round_id = ?'
            );
            $juryStmt->execute([(int) $round['id']]);
            foreach ($juryStmt->fetchAll() as $jury) {
                $subject = 'Reminder: Judging deadline for ' . (string) $round['name'];
                $body = '<p>Your judging deadline is <strong>' . e(formatUtcToIst((string) $round['judging_deadline'])) . '</strong>.</p>';
                $sendIfDue(
                    $pdo,
                    'round_judging',
                    (int) $round['id'],
                    $hackathonId,
                    (string) $jury['email'],
                    (string) $jury['name'],
                    $subject,
                    $body,
                    $offsets,
                    $deadline,
                    $now
                );
            }
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped];
}
