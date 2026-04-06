<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

final class ParticipantAuth
{
    public static function loginParticipant(int $participantId, int $hackathonId): void
    {
        session_regenerate_id(true);
        $_SESSION['participant_id'] = $participantId;
        $_SESSION['participant_hackathon_id'] = $hackathonId;
    }

    public static function loginWithToken(string $token): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                ps.id,
                ps.participant_id,
                ps.expires_at,
                ps.used_at,
                p.hackathon_id
             FROM participant_sessions ps
             INNER JOIN participants p ON p.id = ps.participant_id
             WHERE ps.token = ?
             LIMIT 1'
        );
        $stmt->execute([trim($token)]);
        $session = $stmt->fetch();

        if ($session === false || $session['used_at'] !== null) {
            return false;
        }

        $expiresAt = new DateTimeImmutable((string) $session['expires_at'], new DateTimeZone('UTC'));
        if ($expiresAt <= utcNow()) {
            return false;
        }

        $updateStmt = $pdo->prepare('UPDATE participant_sessions SET used_at = ? WHERE id = ?');
        $updateStmt->execute([utcNow()->format('Y-m-d H:i:s'), (int) $session['id']]);

        self::loginParticipant((int) $session['participant_id'], (int) $session['hackathon_id']);

        return true;
    }

    public static function sendOtpForEmail(string $email, ?int $hackathonId = null, ?string $participantType = null): bool
    {
        require_once __DIR__ . '/Mailer.php';

        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        $pdo = Database::getConnection();
        $where = ['p.email = ?'];
        $params = [$normalizedEmail];

        $normalizedType = normalizeParticipantType($participantType);
        if ($hackathonId !== null) {
            $where[] = 'p.hackathon_id = ?';
            $params[] = $hackathonId;
        }

        if ($normalizedType !== null) {
            $where[] = 'p.participant_type = ?';
            $params[] = $normalizedType;
        }

        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.name,
                p.email,
                p.hackathon_id,
                p.participant_type,
                h.name AS hackathon_name
             FROM participants p
             INNER JOIN hackathons h ON h.id = p.hackathon_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.registered_at DESC, p.id DESC
             LIMIT 1'
        );
        $stmt->execute($params);
        $participant = $stmt->fetch();

        if ($participant === false) {
            return false;
        }

        $otpCode = createParticipantOtp((int) $participant['id'], (string) $participant['email']);

        return sendParticipantOtpEmail($participant, $otpCode);
    }

    public static function loginWithOtp(string $email, string $otpCode, ?int $hackathonId = null, ?string $participantType = null): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $otpCode = trim($otpCode);

        if ($normalizedEmail === '' || $otpCode === '' || !preg_match('/^\d{6}$/', $otpCode)) {
            return false;
        }

        $pdo = Database::getConnection();
        $where = ['po.email = ?'];
        $params = [$normalizedEmail];
        $normalizedType = normalizeParticipantType($participantType);

        if ($hackathonId !== null) {
            $where[] = 'p.hackathon_id = ?';
            $params[] = $hackathonId;
        }

        if ($normalizedType !== null) {
            $where[] = 'p.participant_type = ?';
            $params[] = $normalizedType;
        }

        $stmt = $pdo->prepare(
            'SELECT
                po.id,
                po.participant_id,
                po.code_hash,
                po.expires_at,
                po.verified_at,
                po.consumed_at,
                p.hackathon_id
             FROM participant_otps po
             INNER JOIN participants p ON p.id = po.participant_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY po.created_at DESC, po.id DESC
             LIMIT 1'
        );
        $stmt->execute($params);
        $otp = $stmt->fetch();

        if ($otp === false || $otp['verified_at'] !== null || $otp['consumed_at'] !== null) {
            return false;
        }

        $expiresAt = new DateTimeImmutable((string) $otp['expires_at'], new DateTimeZone('UTC'));
        if ($expiresAt <= utcNow()) {
            $expireStmt = $pdo->prepare('UPDATE participant_otps SET consumed_at = ? WHERE id = ?');
            $expireStmt->execute([utcNow()->format('Y-m-d H:i:s'), (int) $otp['id']]);
            return false;
        }

        if (!password_verify($otpCode, (string) $otp['code_hash'])) {
            return false;
        }

        $updateStmt = $pdo->prepare('UPDATE participant_otps SET verified_at = ?, consumed_at = ? WHERE id = ?');
        $timestamp = utcNow()->format('Y-m-d H:i:s');
        $updateStmt->execute([$timestamp, $timestamp, (int) $otp['id']]);

        self::loginParticipant((int) $otp['participant_id'], (int) $otp['hackathon_id']);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['participant_id'], $_SESSION['participant_hackathon_id']);
    }

    public static function participant(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.hackathon_id,
                p.name,
                p.email,
                p.participant_type,
                p.department,
                p.college,
                p.vit_reg_no,
                p.year_of_study,
                p.check_in_status,
                h.name AS hackathon_name,
                h.venue,
                h.starts_at
             FROM participants p
             INNER JOIN hackathons h ON h.id = p.hackathon_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([(int) $_SESSION['participant_id']]);

        $participant = $stmt->fetch();

        return $participant !== false ? $participant : null;
    }

    public static function logout(): void
    {
        unset($_SESSION['participant_id'], $_SESSION['participant_hackathon_id']);
    }

    private function __construct()
    {
    }
}
