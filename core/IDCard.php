<?php

declare(strict_types=1);

/*
 * TEMPLATE SETUP REQUIRED
 * Before this class works, convert the HTML templates to PDF:
 *
 * ID Card:
 *   Open templates/id-card-template.html in Chrome
 *   Print -> Save as PDF -> Paper: 105x148mm, Margins: None
 *   Save to: templates/id-card-template.pdf
 *
 * Certificate:
 *   Open templates/certificate-template.html in Chrome
 *   Print -> Save as PDF -> Paper: A4 Landscape, Margins: None
 *   Save to: templates/certificate-template.pdf
 *   (2-page PDF: page 1 = participation, page 2 = achievement)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorPNG;
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
    public const CARD_W = 105.0;
    public const CARD_H = 148.0;

    public const NAME_X = 20.0;
    public const NAME_Y = 83.0;
    public const NAME_W = 65.0;
    public const NAME_H = 8.0;

    public const TYPE_BADGE_X = 28.0;
    public const TYPE_BADGE_Y = 93.2;
    public const TYPE_BADGE_W = 49.0;
    public const TYPE_BADGE_H = 6.8;

    public const QR_X = 30.0;
    public const QR_Y = 36.5;
    public const QR_SIZE = 45.0;

    public const ID_VALUE_X = 33.0;
    public const ID_VALUE_Y = 106.8-1;
    public const ID_VALUE_W = 46.0;
    public const ID_VALUE_H = 4.8;

    public const EMAIL_VALUE_X = 33.0;
    public const EMAIL_VALUE_Y = 112.2-1;
    public const EMAIL_VALUE_W = 46.0;
    public const EMAIL_VALUE_H = 4.8;

    public const PHONE_VALUE_X = 33.0;
    public const PHONE_VALUE_Y = 117.6-1;
    public const PHONE_VALUE_W = 46.0;
    public const PHONE_VALUE_H = 4.8;

    public const BARCODE_X = 30.0;
    public const BARCODE_Y = 125.8;
    public const BARCODE_W = 45.0;
    public const BARCODE_H = 9.6;

    private const TEMPLATE_PDF = 'templates/id-card-template.pdf';

    public static function generate(int $participantId): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.hackathon_id,
                p.participant_type,
                p.name,
                p.email,
                p.phone,
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
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($participant)) {
            return null;
        }

        $templatePath = self::validatedTemplatePath(self::TEMPLATE_PDF);

        return $templatePath !== null
            ? self::generateFromTemplate($participant, $templatePath)
            : self::generatePlain($participant);
    }

    public static function generateFromTemplate(array $participant, string $templatePath): string
    {
        $savePath = self::savePath($participant);
        $pdf = new Fpdi('P', 'mm', [self::CARD_W, self::CARD_H]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->setSourceFile($templatePath);
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, self::CARD_W, self::CARD_H);

        $name = strtoupper(self::truncate((string) $participant['name'], 32));
        $idLine = ($participant['participant_type'] ?? '') === 'internal'
            ? self::truncate((string) ($participant['vit_reg_no'] ?? 'N/A'), 22)
            : self::truncate((string) ($participant['barcode_uid'] ?? 'N/A'), 22);
        $emailLine = trim((string) ($participant['email'] ?? 'N/A'));
        $phoneLine = self::truncate((string) ($participant['phone'] ?? 'N/A'), 18);

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetTextColor(31, 46, 77);
        $pdf->SetXY(self::NAME_X, self::NAME_Y);
        $pdf->Cell(self::NAME_W, self::NAME_H, $name, 0, 0, 'C');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(35, 49, 76);
        $pdf->SetXY(self::ID_VALUE_X, self::ID_VALUE_Y);
        $pdf->Cell(self::ID_VALUE_W, self::ID_VALUE_H, $idLine, 0, 0, 'L');
        $pdf->SetXY(self::EMAIL_VALUE_X, self::EMAIL_VALUE_Y);
        $pdf->SetFont('Helvetica', '', 6.2);
        $pdf->Cell(self::EMAIL_VALUE_W, self::EMAIL_VALUE_H, $emailLine, 0, 0, 'L');
        $pdf->SetXY(self::PHONE_VALUE_X, self::PHONE_VALUE_Y);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(self::PHONE_VALUE_W, self::PHONE_VALUE_H, $phoneLine, 0, 0, 'L');

        $qrPath = self::generateQRPng(
            rtrim(APP_URL, '/') . '/public/verify-cert.php?type=checkin&token=' . urlencode((string) $participant['qr_token']),
            (int) $participant['id']
        );
        if ($qrPath !== null) {
            $pdf->Image($qrPath, self::QR_X, self::QR_Y, self::QR_SIZE, self::QR_SIZE, 'PNG');
            @unlink($qrPath);
        }

        $barcodePath = self::generateBarcodePng((string) $participant['barcode_uid'], (int) $participant['id']);
        if ($barcodePath !== null) {
            $pdf->Image($barcodePath, self::BARCODE_X, self::BARCODE_Y, self::BARCODE_W, self::BARCODE_H, 'PNG');
            @unlink($barcodePath);
        }

        $pdf->Output('F', $savePath);
        self::updateIdCardPath((int) $participant['id'], (int) $participant['hackathon_id']);

        return $savePath;
    }

    public static function generatePlain(array $participant): string
    {
        $savePath = self::savePath($participant);
        $pdf = new FPDF('P', 'mm', [self::CARD_W, self::CARD_H]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, self::CARD_W, self::CARD_H, 'F');
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Rect(6, 6, 93, 136, 'D');
        $pdf->SetFillColor(17, 17, 17);
        $pdf->Rect(6, 6, 93, 20, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(12, 13);
        $pdf->Cell(81, 5, 'HackDesk', 0, 0, 'C');

        $pdf->SetDrawColor(140, 140, 140);
        $pdf->Rect(self::QR_X, self::QR_Y, self::QR_SIZE, self::QR_SIZE, 'D');

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetTextColor(31, 46, 77);
        $pdf->SetXY(self::NAME_X, self::NAME_Y);
        $pdf->Cell(self::NAME_W, self::NAME_H, strtoupper(self::truncate((string) $participant['name'], 32)), 0, 0, 'C');

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetXY(12, 108);
        $pdf->Cell(20, 5, 'ID No', 0, 0, 'L');
        $pdf->SetXY(12, 114);
        $pdf->Cell(20, 5, 'Email', 0, 0, 'L');
        $pdf->SetXY(12, 120);
        $pdf->Cell(20, 5, 'Phone', 0, 0, 'L');
        $pdf->SetXY(28, 108);
        $pdf->Cell(5, 5, ':', 0, 0, 'L');
        $pdf->SetXY(28, 114);
        $pdf->Cell(5, 5, ':', 0, 0, 'L');
        $pdf->SetXY(28, 120);
        $pdf->Cell(5, 5, ':', 0, 0, 'L');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(35, 49, 76);
        $plainId = ($participant['participant_type'] ?? '') === 'internal'
            ? self::truncate((string) ($participant['vit_reg_no'] ?? 'N/A'), 22)
            : self::truncate((string) ($participant['barcode_uid'] ?? 'N/A'), 22);
        $pdf->SetXY(self::ID_VALUE_X, self::ID_VALUE_Y);
        $pdf->Cell(self::ID_VALUE_W, self::ID_VALUE_H, $plainId, 0, 0, 'L');
        $pdf->SetXY(self::EMAIL_VALUE_X, self::EMAIL_VALUE_Y);
        $pdf->SetFont('Helvetica', '', 6.2);
        $pdf->Cell(self::EMAIL_VALUE_W, self::EMAIL_VALUE_H, trim((string) ($participant['email'] ?? 'N/A')), 0, 0, 'L');
        $pdf->SetXY(self::PHONE_VALUE_X, self::PHONE_VALUE_Y);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(self::PHONE_VALUE_W, self::PHONE_VALUE_H, self::truncate((string) ($participant['phone'] ?? 'N/A'), 18), 0, 0, 'L');

        $qrPath = self::generateQRPng(
            rtrim(APP_URL, '/') . '/public/verify-cert.php?type=checkin&token=' . urlencode((string) $participant['qr_token']),
            (int) $participant['id']
        );
        if ($qrPath !== null) {
            $pdf->Image($qrPath, self::QR_X, self::QR_Y, self::QR_SIZE, self::QR_SIZE, 'PNG');
            @unlink($qrPath);
        }

        $barcodePath = self::generateBarcodePng((string) $participant['barcode_uid'], (int) $participant['id']);
        if ($barcodePath !== null) {
            $pdf->Rect(self::BARCODE_X, self::BARCODE_Y, self::BARCODE_W, self::BARCODE_H, 'D');
            $pdf->Image($barcodePath, self::BARCODE_X, self::BARCODE_Y, self::BARCODE_W, self::BARCODE_H, 'PNG');
            @unlink($barcodePath);
        }

        $pdf->Output('F', $savePath);
        self::updateIdCardPath((int) $participant['id'], (int) $participant['hackathon_id']);

        return $savePath;
    }

    private static function savePath(array $participant): string
    {
        $directory = dirname(__DIR__) . '/uploads/id-cards/' . (int) $participant['hackathon_id'];
        ensureDirectory($directory);

        return $directory . '/' . (int) $participant['id'] . '.pdf';
    }

    private static function updateIdCardPath(int $participantId, int $hackathonId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE participants SET id_card_path = ? WHERE id = ?');
        $stmt->execute(['uploads/id-cards/' . $hackathonId . '/' . $participantId . '.pdf', $participantId]);
    }

    private static function validatedTemplatePath(string $relativePath): ?string
    {
        $templateRoot = realpath(dirname(__DIR__) . '/templates');
        if ($templateRoot === false) {
            return null;
        }

        $candidate = realpath(dirname(__DIR__) . '/' . ltrim($relativePath, '/'));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        $normalizedRoot = rtrim($templateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($candidate, $normalizedRoot) ? $candidate : null;
    }

    private static function getInitialsCirclePng(string $name, int $size): string
    {
        $path = sys_get_temp_dir() . '/hd_initials_' . md5($name . microtime(true)) . '.png';
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('Unable to prepare ID card avatar image.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        [$r, $g, $b] = self::paletteColor($name);
        $circleColor = imagecolorallocate($image, $r, $g, $b);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), $size - 4, $size - 4, $circleColor);

        $initials = self::initials($name);
        $fontPath = self::fontPath();
        if ($fontPath !== null) {
            $fontSize = (int) max(18, round($size * 0.24));
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $initials) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $textWidth = (int) abs($bbox[4] - $bbox[0]);
            $textHeight = (int) abs($bbox[5] - $bbox[1]);
            $x = (int) (($size - $textWidth) / 2);
            $y = (int) (($size + $textHeight) / 2) - 6;
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $initials);
        } else {
            $font = 5;
            $textWidth = imagefontwidth($font) * strlen($initials);
            $textHeight = imagefontheight($font);
            imagestring($image, $font, (int) (($size - $textWidth) / 2), (int) (($size - $textHeight) / 2), $initials, $textColor);
        }

        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private static function generateQRPng(string $content, int $participantId): ?string
    {
        $path = sys_get_temp_dir() . '/hd_qr_' . $participantId . '_' . bin2hex(random_bytes(4)) . '.png';
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => 6,
        ]);
        $data = (new QRCode($options))->render($content);

        if (str_starts_with($data, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
            if ($decoded === false) {
                return null;
            }
            $data = $decoded;
        }

        return file_put_contents($path, $data) === false ? null : $path;
    }

    private static function generateBarcodePng(string $uid, int $participantId): ?string
    {
        $value = trim($uid);
        if ($value === '') {
            return null;
        }

        $generator = new BarcodeGeneratorPNG();
        $path = sys_get_temp_dir() . '/hd_barcode_' . $participantId . '_' . bin2hex(random_bytes(4)) . '.png';
        $png = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

        return file_put_contents($path, $png) === false ? null : $path;
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice(array_values(array_filter($parts, static fn(string $part): bool => $part !== '')), 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'HD';
    }

    private static function paletteColor(string $seed): array
    {
        $palette = [
            [91, 91, 214],
            [37, 99, 235],
            [8, 145, 178],
            [124, 58, 237],
            [22, 163, 74],
        ];

        return $palette[abs(crc32($seed)) % count($palette)];
    }

    private static function fontPath(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/public/assets/fonts/Inter-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function truncate(string $text, int $maxLength): string
    {
        $value = trim($text);

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength - 3) . '...' : $value;
    }

    private function __construct()
    {
    }
}
