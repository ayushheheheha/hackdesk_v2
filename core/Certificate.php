<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (!function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime(): int
    {
        return 0;
    }
}

if (!function_exists('set_magic_quotes_runtime')) {
    function set_magic_quotes_runtime(int $newSetting): bool
    {
        return false;
    }
}

if (!class_exists('FPDF')) {
    require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
}

final class Certificate
{
    public static function generate(int $participantId, int $hackathonId, string $certType, ?int $position = null, ?string $specialTitle = null): string
    {
        $pdo = Database::getConnection();
        $participantStmt = $pdo->prepare(
            'SELECT
                p.id,
                p.name,
                p.email,
                p.participant_type,
                p.vit_reg_no,
                p.college,
                h.name AS hackathon_name,
                h.venue,
                h.starts_at
             FROM participants p
             INNER JOIN hackathons h ON h.id = p.hackathon_id
             WHERE p.id = ? AND p.hackathon_id = ?
             LIMIT 1'
        );
        $participantStmt->execute([$participantId, $hackathonId]);
        $participant = $participantStmt->fetch();

        if ($participant === false) {
            throw new RuntimeException('Participant not found for certificate generation.');
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO certificates (participant_id, hackathon_id, cert_type, position, hmac_token, file_path, is_revoked, revoke_reason, special_title)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([$participantId, $hackathonId, $certType, $position, 'pending', null, 0, null, $specialTitle]);
        $certId = (int) $pdo->lastInsertId();

        $token = hash_hmac('sha256', $certId . '|' . $participantId . '|' . $hackathonId, HMAC_SECRET);
        $updateTokenStmt = $pdo->prepare('UPDATE certificates SET hmac_token = ? WHERE id = ?');
        $updateTokenStmt->execute([$token, $certId]);

        $directory = dirname(__DIR__) . '/uploads/certificates/' . $hackathonId;
        ensureDirectory($directory);
        $qrPath = $directory . '/qr-' . $certId . '.png';
        $pdfPath = $directory . '/' . $certId . '.pdf';

        self::generateQr($token, $qrPath);
        self::generatePdf($participant, $certId, $certType, $token, $pdfPath, $qrPath, $position, $specialTitle);

        $relativePath = 'uploads/certificates/' . $hackathonId . '/' . $certId . '.pdf';
        $updateFileStmt = $pdo->prepare('UPDATE certificates SET file_path = ? WHERE id = ?');
        $updateFileStmt->execute([$relativePath, $certId]);

        if (is_file($qrPath)) {
            unlink($qrPath);
        }

        return $token;
    }

    public static function revoke(int $certId, string $reason): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE certificates SET is_revoked = 1, revoke_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $certId]);
    }

    private static function generateQr(string $token, string $qrPath): void
    {
        $url = APP_URL . '/public/verify-cert.php?token=' . urlencode($token);
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => 6,
        ]);
        $png = (new QRCode($options))->render($url);
        if (str_starts_with($png, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($png, strlen('data:image/png;base64,')), true);
            if ($decoded === false) {
                throw new RuntimeException('Unable to decode certificate QR image data.');
            }
            $png = $decoded;
        }

        if (file_put_contents($qrPath, $png) === false) {
            throw new RuntimeException('Unable to write certificate QR image.');
        }
    }

    private static function generatePdf(array $participant, int $certId, string $certType, string $token, string $pdfPath, string $qrPath, ?int $position, ?string $specialTitle): void
    {
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        $pdf->SetDrawColor(40, 40, 40);
        $pdf->Rect(8, 8, 281, 194);
        $pdf->Rect(12, 12, 273, 186);

        $pdf->SetFont('Arial', 'B', 26);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetXY(20, 24);
        $pdf->Cell(257, 12, self::truncate((string) $participant['hackathon_name'], 48), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 18);
        $pdf->Cell(257, 10, self::certificateHeading($certType, $specialTitle), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 14);
        $pdf->SetXY(20, 72);
        $pdf->Cell(257, 8, 'This is to certify that', 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 28);
        $pdf->SetXY(20, 86);
        $pdf->Cell(257, 12, self::truncate((string) $participant['name'], 40), 0, 1, 'C');
        $pdf->Line(78, 102, 220, 102);

        $pdf->SetFont('Arial', '', 16);
        $pdf->SetXY(34, 112);
        $pdf->MultiCell(225, 9, self::certificateBody($participant, $certType, $position, $specialTitle), 0, 'C');

        $pdf->SetFont('Arial', '', 12);
        $pdf->SetXY(36, 160);
        $pdf->Cell(70, 6, '__________________________', 0, 0, 'C');
        $pdf->Cell(110, 6, '', 0, 0, 'C');
        $pdf->Cell(70, 6, '__________________________', 0, 1, 'C');
        $pdf->SetXY(36, 167);
        $pdf->Cell(70, 6, 'Organizer', 0, 0, 'C');
        $pdf->Cell(110, 6, '', 0, 0, 'C');
        $pdf->Cell(70, 6, 'Faculty Coordinator', 0, 1, 'C');

        $pdf->Image($qrPath, 244, 146, 30, 30, 'PNG');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetXY(214, 178);
        $pdf->Cell(70, 5, 'Verify at: ' . APP_URL . '/public/verify-cert.php', 0, 1, 'R');
        $pdf->SetXY(214, 184);
        $pdf->Cell(70, 5, 'ID: ' . substr($token, 0, 16) . '...', 0, 1, 'R');

        $pdf->Output('F', $pdfPath);
    }

    private static function certificateHeading(string $certType, ?string $specialTitle): string
    {
        return match ($certType) {
            'winner', 'runner_up', 'second_runner_up' => 'Certificate of Achievement',
            'special' => 'Certificate of ' . ($specialTitle !== null && trim($specialTitle) !== '' ? self::truncate($specialTitle, 24) : 'Achievement'),
            default => 'Certificate of Participation',
        };
    }

    private static function certificateBody(array $participant, string $certType, ?int $position, ?string $specialTitle): string
    {
        $event = (string) $participant['hackathon_name'];
        $date = formatUtcToIst((string) $participant['starts_at'], 'd M Y');
        $venue = (string) ($participant['venue'] ?? 'the venue');

        return match ($certType) {
            'winner' => 'has secured 1st position in ' . $event . ' held on ' . $date . ' at ' . $venue . '.',
            'runner_up' => 'has secured 2nd position in ' . $event . ' held on ' . $date . ' at ' . $venue . '.',
            'second_runner_up' => 'has secured 3rd position in ' . $event . ' held on ' . $date . ' at ' . $venue . '.',
            'special' => 'has been recognized with the ' . ($specialTitle !== null && trim($specialTitle) !== '' ? '"' . self::truncate($specialTitle, 40) . '"' : 'special award') . ' at ' . $event . ' held on ' . $date . ' at ' . $venue . '.',
            default => 'has successfully participated in ' . $event . ' held on ' . $date . ' at ' . $venue . '.',
        };
    }

    private static function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 3) . '...' : $text;
    }

    private function __construct()
    {
    }
}
