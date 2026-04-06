<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php-error.log');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hackdesk_session');
    session_start();
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'hackdesk');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'HackDesk');
define('APP_URL', 'http://localhost/hackdesk/hackdesk_v2');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@example.com');
define('SMTP_PASS', 'replace-with-app-password');
define('SMTP_FROM', 'your-email@example.com');
define('SMTP_FROM_NAME', 'HackDesk');

define('HMAC_SECRET', 'replace-with-a-long-random-secret');
