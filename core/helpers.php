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
    return generateUniqueValue('participants', 'barcode_uid', 8);
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
