<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

final class Auth
{
    public static function login(string $email, string $password): bool
    {
        $pdo = Database::getConnection();
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '' || $password === '') {
            self::logLoginAttempt(null, $normalizedEmail, false);
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active, login_attempts, locked_until
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$normalizedEmail]);
        $user = $stmt->fetch();

        if ($user === false) {
            self::logLoginAttempt(null, $normalizedEmail, false);
            return false;
        }

        if ((int) $user['is_active'] !== 1) {
            self::logLoginAttempt((int) $user['id'], $normalizedEmail, false);
            return false;
        }

        if (self::isLocked($user['locked_until'] ?? null)) {
            self::logLoginAttempt((int) $user['id'], $normalizedEmail, false);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::incrementFailedAttempts((int) $user['id'], (int) $user['login_attempts']);
            self::logLoginAttempt((int) $user['id'], $normalizedEmail, false);
            return false;
        }

        self::clearFailedAttempts((int) $user['id']);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
        ];

        self::logLoginAttempt((int) $user['id'], $normalizedEmail, true);
        logActivity('login', 'user', (int) $user['id'], ['role' => $user['role']], null);

        return true;
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user']['id'])) {
            logActivity('logout', 'user', (int) $_SESSION['user']['id'], null, null);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'], $_SESSION['user']['role']);
    }

    public static function user(): ?array
    {
        return self::check() ? $_SESSION['user'] : null;
    }

    public static function hasRole(string|array $role): bool
    {
        if (!self::check()) {
            return false;
        }

        $roles = is_array($role) ? $role : [$role];

        return in_array($_SESSION['user']['role'], $roles, true);
    }

    public static function requireRole(string|array $role): void
    {
        if (!self::hasRole($role)) {
            flash('error', 'Please sign in with the correct account to continue.');
            redirect('public/login.php');
        }
    }

    public static function dashboardPathForRole(?string $role): string
    {
        return match ($role) {
            'super_admin' => 'portal/super-admin/dashboard.php',
            'admin' => 'portal/admin/dashboard.php',
            'jury' => 'portal/jury/dashboard.php',
            'staff' => 'portal/staff/checkin.php',
            'participant' => 'portal/participant/dashboard.php',
            default => 'public/login.php',
        };
    }

    private static function isLocked(?string $lockedUntil): bool
    {
        if ($lockedUntil === null) {
            return false;
        }

        $lockTime = new DateTimeImmutable($lockedUntil, new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $lockTime > $now;
    }

    private static function incrementFailedAttempts(int $userId, int $currentAttempts): void
    {
        $pdo = Database::getConnection();
        $attempts = $currentAttempts + 1;
        $lockedUntil = $attempts >= 5
            ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->format('Y-m-d H:i:s')
            : null;

        $stmt = $pdo->prepare('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?');
        $stmt->execute([$attempts >= 5 ? 5 : $attempts, $lockedUntil, $userId]);
    }

    private static function clearFailedAttempts(int $userId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?');
        $stmt->execute([$userId]);
    }

    private static function logLoginAttempt(?int $userId, string $emailAttempted, bool $success): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO login_audit (user_id, email_attempted, ip_address, user_agent, success)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $emailAttempted,
            getClientIp(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 300),
            $success ? 1 : 0,
        ]);
    }

    private function __construct()
    {
    }
}
