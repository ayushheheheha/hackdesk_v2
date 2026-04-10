<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Middleware.php';

Middleware::redirectIfAuthenticated();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!CSRF::validate(is_string($token) ? $token : null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('public/login.php');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (Auth::login($email, $password)) {
        redirect(Auth::dashboardPathForRole(Auth::user()['role'] ?? null));
    }

    flash('error', 'Invalid credentials or account temporarily locked.');
    redirect('public/login.php');
}

$error = getFlash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> Login</title>
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('hackdesk_theme');
                if (savedTheme === 'dark' || savedTheme === 'light') {
                    document.documentElement.setAttribute('data-theme', savedTheme);
                }
            } catch (error) {
                // Ignore theme storage errors.
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(appPath('public/assets/css/style.css')) ?>">
</head>
<body class="login-body">
<button id="theme-toggle" class="theme-toggle" type="button" aria-label="Toggle color theme"></button>
<main class="auth-shell">
    <div class="auth-wordmark"><?= e(APP_NAME) ?></div>
    <section class="card auth-card">
        <h1>Sign in</h1>
        <p class="auth-copy">Use your staff or organizer credentials to access the platform.</p>
        <?php if ($error !== null): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(appPath('public/login.php')) ?>" novalidate>
            <?= CSRF::field() ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-primary btn-block">Login</button>
        </form>
    </section>
</main>
<script src="<?= e(appPath('public/assets/js/main.js')) ?>" defer></script>
</body>
</html>
