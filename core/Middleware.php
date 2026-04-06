<?php

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

final class Middleware
{
    public static function requireAuth(): void
    {
        if (!Auth::check()) {
            flash('error', 'Please sign in to continue.');
            redirect('public/login.php');
        }
    }

    public static function requireRole(string|array $role): void
    {
        Auth::requireRole($role);
    }

    public static function requireParticipantAuth(): void
    {
        if (!isset($_SESSION['participant_id'], $_SESSION['participant_hackathon_id'])) {
            flash('error', 'Please sign in with your participant email or login link to continue.');
            redirect('public/participant-login.php');
        }
    }

    public static function redirectIfAuthenticated(): void
    {
        if (Auth::check()) {
            redirect(Auth::dashboardPathForRole(Auth::user()['role'] ?? null));
        }
    }

    private function __construct()
    {
    }
}
