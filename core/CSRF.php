<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

final class CSRF
{
    public static function generate(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf'];
    }

    public static function validate(?string $token): bool
    {
        if (!isset($_SESSION['csrf']) || !is_string($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf'], $token);
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    private function __construct()
    {
    }
}
