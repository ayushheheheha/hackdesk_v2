<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';
require_once __DIR__ . '/../core/helpers.php';

Auth::logout();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
ParticipantAuth::logout();
flash('success', 'You have been logged out.');
redirect('public/login.php');
