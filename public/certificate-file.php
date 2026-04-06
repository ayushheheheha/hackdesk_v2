<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';

$certificateId = filter_input(INPUT_GET, 'certificate_id', FILTER_VALIDATE_INT);

if ($certificateId === false || $certificateId === null) {
    http_response_code(404);
    exit('Certificate not found.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT id, participant_id, file_path, hmac_token
     FROM certificates
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$certificateId]);
$certificate = $stmt->fetch();

if ($certificate === false || empty($certificate['file_path'])) {
    http_response_code(404);
    exit('Certificate not found.');
}

$allowed = false;
if (Auth::check()) {
    $role = Auth::user()['role'] ?? null;
    if (in_array($role, ['admin', 'super_admin'], true)) {
        $allowed = true;
    }
}

if (!$allowed && ParticipantAuth::check() && (int) ($_SESSION['participant_id'] ?? 0) === (int) $certificate['participant_id']) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$absolutePath = dirname(__DIR__) . '/' . ltrim((string) $certificate['file_path'], '/');
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('Certificate file not found.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: attachment; filename="certificate-' . (int) $certificate['id'] . '.pdf"');
readfile($absolutePath);
exit;
