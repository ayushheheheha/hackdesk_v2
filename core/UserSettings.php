<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CSRF.php';

function handlePasswordChange(string $requiredRole, string $redirectPath): string
{
    Auth::requireRole($requiredRole);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
            flash('error', 'Your session token is invalid. Please try again.');
            redirect($redirectPath);
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $userId = (int) ($_SESSION['user']['id'] ?? 0);

        if ($newPassword !== $confirmPassword) {
            flash('error', 'New password and confirmation do not match.');
            redirect($redirectPath);
        }

        if (!validatePasswordStrength($newPassword)) {
            flash('error', 'Password must be at least 8 characters and include uppercase, number, and special character.');
            redirect($redirectPath);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($currentPassword, (string) $user['password_hash'])) {
            flash('error', 'Current password is incorrect.');
            redirect($redirectPath);
        }

        $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $updateStmt->execute([password_hash($newPassword, PASSWORD_ARGON2ID), $userId]);
        flash('success', 'Password updated. Please sign in again.');
        Auth::logout();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        flash('success', 'Password updated successfully. Please sign in again.');
        redirect('public/login.php');
    }

    return $requiredRole;
}
