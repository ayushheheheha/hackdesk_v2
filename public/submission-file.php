<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';
require_once __DIR__ . '/../core/helpers.php';

$submissionId = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT);

if ($submissionId === false || $submissionId === null) {
    http_response_code(404);
    exit('File not found.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'SELECT
        s.id,
        s.team_id,
        s.ppt_file_path,
        s.ppt_original_name,
        s.round_id
     FROM submissions s
     WHERE s.id = ?
     LIMIT 1'
);
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();

if ($submission === false || empty($submission['ppt_file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$allowed = false;

if (Auth::check()) {
    $role = Auth::user()['role'] ?? null;
    if (in_array($role, ['admin', 'jury', 'super_admin'], true)) {
        $allowed = true;
    }
}

if (!$allowed && ParticipantAuth::check()) {
    $checkStmt = $pdo->prepare(
        'SELECT tm.id
         FROM team_members tm
         WHERE tm.team_id = ? AND tm.participant_id = ?
         LIMIT 1'
    );
    $checkStmt->execute([(int) $submission['team_id'], (int) ($_SESSION['participant_id'] ?? 0)]);
    $allowed = $checkStmt->fetch() !== false;
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$absolutePath = dirname(__DIR__) . '/' . ltrim((string) $submission['ppt_file_path'], '/');
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
$disposition = (isset($_GET['inline']) && $_GET['inline'] === '1') ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode((string) $submission['ppt_original_name']) . '"');
readfile($absolutePath);
exit;
