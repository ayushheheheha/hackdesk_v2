<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use setasign\Fpdi\Fpdi;

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

final class IDCard
{
    public static function generate(int $participantId): string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.hackathon_id,
                p.participant_type,
                p.name,
                p.email,
                p.vit_reg_no,
                p.college,
                p.department,
                p.year_of_study,
                p.barcode_uid,
                p.qr_token,
                h.name AS hackathon_name,
                h.venue,
                h.starts_at
             FROM participants p
             INNER JOIN hackathons h ON h.id = p.hackathon_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$participantId]);
        $participant = $stmt->fetch();

        if ($participant === false) {
            throw new RuntimeException('Participant not found for ID card generation.');
        }

        $directory = dirname(__DIR__) . '/uploads/id-cards/' . (int) $participant['hackathon_id'];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ID card directory.');
        }

        $pdfPath = $directory . '/' . (int) $participant['id'] . '.pdf';
        $qrPath = $directory . '/qr-' . (int) $participant['id'] . '.png';
        self::generateQrImage((string) $participant['qr_token'], $qrPath);

        $pdf = new HackDeskPDF('P', 'mm', [105, 148]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->SetFillColor(91, 91, 214);
        $pdf->Rect(0, 0, 105, 28, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetXY(10, 8);
        $pdf->Cell(85, 7, self::truncateAscii((string) $participant['hackathon_name'], 34), 0, 2);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(85, 5, formatUtcToIst((string) $participant['starts_at'], 'd M Y'), 0, 0);

        $pdf->SetDrawColor(39, 39, 42);
        $pdf->SetFillColor(19, 19, 22);
        $pdf->Rect(8, 34, 89, 105, 'D');

        $pdf->SetFillColor(28, 28, 32);
        $pdf->Rect(14, 40, 26, 26, 'F');
        $pdf->SetFillColor(91, 91, 214);
        $pdf->Circle(27, 53, 9, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetXY(18, 48.5);
        $pdf->Cell(18, 9, self::initials((string) $participant['name']), 0, 0, 'C');

        $pdf->SetTextColor(12, 12, 14);
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetXY(44, 40);
        $pdf->MultiCell(46, 7, self::truncateAscii((string) $participant['name'], 36), 0, 'L');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(63, 63, 70);
        $pdf->SetXY(44, 55);
        $pdf->MultiCell(46, 5, self::truncateAscii(self::participantSubtitle($participant), 56), 0, 'L');

        $pdf->Image($qrPath, 27.5, 74, 50, 50, 'PNG');
        $pdf->Code128(22, 127, (string) $participant['barcode_uid'], 60, 10);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(63, 63, 70);
        $pdf->SetXY(15, 139);
        $pdf->Cell(75, 4, 'Scan QR at venue for check-in', 0, 0, 'C');

        $pdf->Output('F', $pdfPath);

        $updateStmt = $pdo->prepare('UPDATE participants SET id_card_path = ? WHERE id = ?');
        $updateStmt->execute([self::relativeUploadPath($participant['hackathon_id'], $participant['id']), $participantId]);

        if (file_exists($qrPath)) {
            unlink($qrPath);
        }

        return $pdfPath;
    }

    private static function generateQrImage(string $token, string $outputPath): void
    {
        $data = APP_URL . '/public/verify-cert.php?type=checkin&token=' . urlencode($token);
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => 6,
        ]);

        $pngData = (new QRCode($options))->render($data);
        if (str_starts_with($pngData, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($pngData, strlen('data:image/png;base64,')), true);
            if ($decoded === false) {
                throw new RuntimeException('Unable to decode QR image data.');
            }
            $pngData = $decoded;
        }

        if (file_put_contents($outputPath, $pngData) === false) {
            throw new RuntimeException('Unable to write QR image for ID card.');
        }
    }

    private static function participantSubtitle(array $participant): string
    {
        if (($participant['participant_type'] ?? '') === 'internal') {
            return trim(($participant['department'] ?? 'VIT Student') . ' | ' . ($participant['vit_reg_no'] ?? 'VIT Student'));
        }

        return trim(($participant['college'] ?? 'External Participant') . ' | ' . ($participant['department'] ?? 'Department'));
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'HD';
    }

    private static function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1) . '…' : $text;
    }

    private static function truncateAscii(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 3) . '...' : $text;
    }

    private static function relativeUploadPath(int|string $hackathonId, int|string $participantId): string
    {
        return 'uploads/id-cards/' . (int) $hackathonId . '/' . (int) $participantId . '.pdf';
    }

    private function __construct()
    {
    }
}

class HackDeskPDF extends FPDF
{
    private const T128 = [];

    public function Circle(float $x, float $y, float $r, string $style = 'D'): void
    {
        $this->Ellipse($x, $y, $r, $r, $style);
    }

    public function Ellipse(float $x, float $y, float $rx, float $ry, string $style = 'D'): void
    {
        $op = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };

        $lx = 4 / 3 * (sqrt(2) - 1) * $rx;
        $ly = 4 / 3 * (sqrt(2) - 1) * $ry;
        $k = $this->k;
        $h = $this->h;

        $this->_out(sprintf('%.2F %.2F m', ($x + $rx) * $k, ($h - $y) * $k));
        $this->_Arc($x + $rx, $y - $ly, $x + $lx, $y - $ry, $x, $y - $ry);
        $this->_Arc($x - $lx, $y - $ry, $x - $rx, $y - $ly, $x - $rx, $y);
        $this->_Arc($x - $rx, $y + $ly, $x - $lx, $y + $ry, $x, $y + $ry);
        $this->_Arc($x + $lx, $y + $ry, $x + $rx, $y + $ly, $x + $rx, $y);
        $this->_out($op);
    }

    public function Code128(float $x, float $y, string $code, float $w, float $h): void
    {
        $codes = self::patterns();
        $start = 104;
        $checksum = $start;
        $encoded = chr($start);

        for ($i = 0; $i < strlen($code); $i++) {
            $value = ord($code[$i]) - 32;
            if ($value < 0 || $value > 94) {
                $value = 0;
            }

            $checksum += $value * ($i + 1);
            $encoded .= chr($value);
        }

        $encoded .= chr($checksum % 103) . chr(106) . chr(107);

        $modules = '';
        for ($i = 0; $i < strlen($encoded); $i++) {
            $modules .= $codes[ord($encoded[$i])];
        }

        $moduleWidth = $w / strlen($modules);
        for ($i = 0; $i < strlen($modules); $i++) {
            if ($modules[$i] === '1') {
                $this->Rect($x + $i * $moduleWidth, $y, $moduleWidth, $h, 'F');
            }
        }

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(12, 12, 14);
        $this->SetXY($x, $y + $h + 1);
        $this->Cell($w, 4, $code, 0, 0, 'C');
    }

    private function _Arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($h - $y1) * $this->k,
            $x2 * $this->k,
            ($h - $y2) * $this->k,
            $x3 * $this->k,
            ($h - $y3) * $this->k
        ));
    }

    private static function patterns(): array
    {
        return [
            0 => '11011001100', 1 => '11001101100', 2 => '11001100110', 3 => '10010011000', 4 => '10010001100',
            5 => '10001001100', 6 => '10011001000', 7 => '10011000100', 8 => '10001100100', 9 => '11001001000',
            10 => '11001000100', 11 => '11000100100', 12 => '10110011100', 13 => '10011011100', 14 => '10011001110',
            15 => '10111001100', 16 => '10011101100', 17 => '10011100110', 18 => '11001110010', 19 => '11001011100',
            20 => '11001001110', 21 => '11011100100', 22 => '11001110100', 23 => '11101101110', 24 => '11101001100',
            25 => '11100101100', 26 => '11100100110', 27 => '11101100100', 28 => '11100110100', 29 => '11100110010',
            30 => '11011011000', 31 => '11011000110', 32 => '11000110110', 33 => '10100011000', 34 => '10001011000',
            35 => '10001000110', 36 => '10110001000', 37 => '10001101000', 38 => '10001100010', 39 => '11010001000',
            40 => '11000101000', 41 => '11000100010', 42 => '10110111000', 43 => '10110001110', 44 => '10001101110',
            45 => '10111011000', 46 => '10111000110', 47 => '10001110110', 48 => '11101110110', 49 => '11010001110',
            50 => '11000101110', 51 => '11011101000', 52 => '11011100010', 53 => '11011101110', 54 => '11101011000',
            55 => '11101000110', 56 => '11100010110', 57 => '11101101000', 58 => '11101100010', 59 => '11100011010',
            60 => '11101111010', 61 => '11001000010', 62 => '11110001010', 63 => '10100110000', 64 => '10100001100',
            65 => '10010110000', 66 => '10010000110', 67 => '10000101100', 68 => '10000100110', 69 => '10110010000',
            70 => '10110000100', 71 => '10011010000', 72 => '10011000010', 73 => '10000110100', 74 => '10000110010',
            75 => '11000010010', 76 => '11001010000', 77 => '11110111010', 78 => '11000010100', 79 => '10001111010',
            80 => '10100111100', 81 => '10010111100', 82 => '10010011110', 83 => '10111100100', 84 => '10011110100',
            85 => '10011110010', 86 => '11110100100', 87 => '11110010100', 88 => '11110010010', 89 => '11011011110',
            90 => '11011110110', 91 => '11110110110', 92 => '10101111000', 93 => '10100011110', 94 => '10001011110',
            95 => '10111101000', 96 => '10111100010', 97 => '11110101000', 98 => '11110100010', 99 => '10111011110',
            100 => '10111101110', 101 => '11101011110', 102 => '11110101110', 103 => '11010000100',
            104 => '11010010000', 105 => '11010011100', 106 => '11000111010', 107 => '11',
        ];
    }
}
