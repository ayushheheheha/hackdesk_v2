<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';

$pageTitle = $pageTitle ?? APP_NAME;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isPortalPage = str_contains($requestPath, '/portal/');
$isParticipantPortal = str_contains($requestPath, '/portal/participant/');

$currentUser = null;

if ($isParticipantPortal && ParticipantAuth::check()) {
    $participant = ParticipantAuth::participant();
    $currentUser = [
        'id' => (int) ($_SESSION['participant_id'] ?? 0),
        'name' => (string) ($participant['name'] ?? 'Participant'),
        'email' => (string) ($participant['email'] ?? ''),
        'role' => 'participant',
    ];
} elseif ($isPortalPage) {
    $currentUser = Auth::user();
}

$successFlash = getFlash('success');
$errorFlash = getFlash('error');
$warningFlash = getFlash('warning');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(appPath('public/assets/css/style.css')) ?>">
</head>
<body>
<?php if ($currentUser !== null): ?>
    <?php require __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<main class="<?= $currentUser !== null ? 'app-main' : 'public-main' ?>">
    <?php if ($successFlash !== null): ?>
        <script>document.addEventListener('DOMContentLoaded',()=>showToast('<?= addslashes($successFlash) ?>','success'));</script>
    <?php endif; ?>
    <?php if ($errorFlash !== null): ?>
        <script>document.addEventListener('DOMContentLoaded',()=>showToast('<?= addslashes($errorFlash) ?>','error'));</script>
    <?php endif; ?>
    <?php if ($warningFlash !== null): ?>
        <script>document.addEventListener('DOMContentLoaded',()=>showToast('<?= addslashes($warningFlash) ?>','warning'));</script>
    <?php endif; ?>
