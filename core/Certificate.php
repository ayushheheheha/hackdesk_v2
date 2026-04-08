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

final class Certificate
{
    public const PAGE_W = 297.0;
    public const PAGE_H = 210.0;

    public const ORG_LOGO_CX = 48.0;
    public const ORG_LOGO_CY = 105.0;
    public const ORG_LOGO_R = 11.0;
    public const ORG_NAME_X = 30.0;
    public const ORG_NAME_Y = 118.5;
    public const ORG_NAME_W = 36.0;
    public const ORG_NAME_H = 8.0;

    public const EYEBROW_X = 78.0;
    public const EYEBROW_Y = 49.5;
    public const EYEBROW_W = 140.0;
    public const EYEBROW_H = 6.0;

    public const POSITION_BADGE_X = 125.0;
    public const POSITION_BADGE_Y = 58.5;
    public const POSITION_BADGE_W = 46.0;
    public const POSITION_BADGE_H = 8.0;

    public const TITLE_X = 78.0;
    public const TITLE_Y = 68.0;
    public const TITLE_W = 140.0;
    public const TITLE_H = 13.0;

    public const RULE_X = 113.5;
    public const RULE_Y = 84.0;
    public const RULE_W = 70.0;

    public const PRESENTED_X = 78.0;
    public const PRESENTED_Y = 89.0;
    public const PRESENTED_W = 140.0;
    public const PRESENTED_H = 7.0;

    public const NAME_X = 78.0;
    public const NAME_Y = 98.0;
    public const NAME_W = 140.0;
    public const NAME_H = 18.0;

    public const NAME_UNDERLINE_X = 113.0;
    public const NAME_UNDERLINE_Y = 118.5;
    public const NAME_UNDERLINE_W = 70.0;

    public const ACHIEVEMENT_X = 78.0;
    public const ACHIEVEMENT_Y = 124.0;
    public const ACHIEVEMENT_W = 140.0;
    public const ACHIEVEMENT_H = 7.0;

    public const HACKATHON_X = 78.0;
    public const HACKATHON_Y = 132.5;
    public const HACKATHON_W = 140.0;
    public const HACKATHON_H = 7.0;

    public const META_X = 78.0;
    public const META_Y = 141.0;
    public const META_W = 140.0;
    public const META_H = 6.0;

    public const SIG1_LINE_X = 87.0;
    public const SIG1_LINE_Y = 171.0;
    public const SIG1_LINE_W = 50.0;
    public const SIG2_LINE_X = 160.0;
    public const SIG2_LINE_Y = 171.0;
    public const SIG2_LINE_W = 50.0;
    public const SIG1_LABEL_X = 82.0;
    public const SIG1_LABEL_Y = 173.0;
    public const SIG1_LABEL_W = 60.0;
    public const SIG2_LABEL_X = 155.0;
    public const SIG2_LABEL_Y = 173.0;
    public const SIG2_LABEL_W = 60.0;

    public const QR_X = 240.0;
    public const QR_Y = 90.0;
    public const QR_SIZE = 30.0;
    public const VERIFY_X = 234.0;
    public const VERIFY_Y = 123.0;
    public const VERIFY_W = 42.0;
    public const VERIFY_H = 5.0;
    public const CERTID_X = 229.0;
    public const CERTID_Y = 129.0;
    public const CERTID_W = 52.0;
    public const CERTID_H = 6.0;

    private const TEMPLATE_PDF = 'templates/certificate-template.pdf';

    public static function generate(int $participantId, int $hackathonId, string $certType, ?int $position = null, ?string $specialTitle = null): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.name,
                p.email,
                p.participant_type,
                p.vit_reg_no,
                p.college,
                h.id AS hackathon_id,
                h.name AS hackathon_name,
                h.venue,
                h.starts_at,
                u.name AS organizer_name
             FROM participants p
             INNER JOIN hackathons h ON h.id = p.hackathon_id
             LEFT JOIN users u ON u.id = h.created_by
             WHERE p.id = ? AND p.hackathon_id = ?
             LIMIT 1'
        );
        $stmt->execute([$participantId, $hackathonId]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($participant)) {
            return null;
        }

        $insert = $pdo->prepare(
            'INSERT INTO certificates (participant_id, hackathon_id, cert_type, position, hmac_token, file_path, is_revoked, revoke_reason, special_title)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$participantId, $hackathonId, $certType, $position, 'pending', null, 0, null, $specialTitle]);
        $certificateId = (int) $pdo->lastInsertId();

        $token = hash_hmac('sha256', $certificateId . '|' . $participantId . '|' . $hackathonId, HMAC_SECRET);
        $updateToken = $pdo->prepare('UPDATE certificates SET hmac_token = ? WHERE id = ?');
        $updateToken->execute([$token, $certificateId]);

        $savePath = self::savePath($hackathonId, $certificateId);
        $templatePath = self::validatedTemplatePath(self::TEMPLATE_PDF);

        if ($templatePath !== null) {
            self::generateFromTemplate($participant, $certificateId, $certType, $token, $savePath, $templatePath, $position, $specialTitle);
        } else {
            self::generatePlain($participant, $certificateId, $certType, $token, $savePath, $position, $specialTitle);
        }

        $updateFile = $pdo->prepare('UPDATE certificates SET file_path = ? WHERE id = ?');
        $updateFile->execute(['uploads/certificates/' . $hackathonId . '/' . $certificateId . '.pdf', $certificateId]);

        return $savePath;
    }

    public static function revoke(int $certId, string $reason): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE certificates SET is_revoked = 1, revoke_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $certId]);
    }

    private static function generateFromTemplate(array $participant, int $certificateId, string $certType, string $token, string $savePath, string $templatePath, ?int $position, ?string $specialTitle): void
    {
        $page = $certType === 'participation' ? 1 : 2;
        $pdf = new Fpdi('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->setSourceFile($templatePath);
        $tpl = $pdf->importPage($page);
        $pdf->useTemplate($tpl, 0, 0, self::PAGE_W, self::PAGE_H);

        $isAchievement = $certType !== 'participation';
        [$accentR, $accentG, $accentB] = $isAchievement ? [180, 83, 9] : [91, 91, 214];
        $eyebrow = $isAchievement ? 'Achievement Record' : 'Participation Record';
        $title = $isAchievement ? 'Certificate of Achievement' : 'Certificate of Participation';
        $positionLabel = self::positionLabel($certType, $specialTitle);
        $achievementLine = self::achievementLine($certType, $specialTitle);
        $hackathonName = self::truncate((string) $participant['hackathon_name'], 46);
        $eventMeta = formatUtcToIst((string) ($participant['starts_at'] ?? null), 'd F Y') . ' | ' . (string) ($participant['venue'] ?? 'Venue TBA');
        $participantName = self::truncate((string) $participant['name'], 34);
        $organizer = self::truncate((string) ($participant['organizer_name'] ?? APP_NAME), 28);

        $logoPath = self::getCirclePng((string) ($participant['organizer_name'] ?? APP_NAME), 200, [$accentR, $accentG, $accentB], self::initials((string) ($participant['organizer_name'] ?? APP_NAME)));
        $diameter = self::ORG_LOGO_R * 2;
        $pdf->Image($logoPath, self::ORG_LOGO_CX - self::ORG_LOGO_R, self::ORG_LOGO_CY - self::ORG_LOGO_R, $diameter, $diameter, 'PNG');
        @unlink($logoPath);

        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY(self::ORG_NAME_X, self::ORG_NAME_Y);
        $pdf->MultiCell(self::ORG_NAME_W, 3.4, $organizer, 0, 'C');

        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetXY(self::EYEBROW_X, self::EYEBROW_Y);
        $pdf->Cell(self::EYEBROW_W, self::EYEBROW_H, strtoupper($eyebrow), 0, 0, 'C');

        if ($isAchievement && $positionLabel !== '') {
            $pdf->SetFillColor($accentR, $accentG, $accentB);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Rect(self::POSITION_BADGE_X, self::POSITION_BADGE_Y, self::POSITION_BADGE_W, self::POSITION_BADGE_H, 'F');
            $pdf->SetXY(self::POSITION_BADGE_X, self::POSITION_BADGE_Y + 0.9);
            $pdf->Cell(self::POSITION_BADGE_W, self::POSITION_BADGE_H - 1.8, $positionLabel, 0, 0, 'C');
        }

        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetXY(self::TITLE_X, self::TITLE_Y);
        $pdf->Cell(self::TITLE_W, self::TITLE_H, $title, 0, 0, 'C');

        $pdf->SetFont('Helvetica', 'I', 11);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(self::PRESENTED_X, self::PRESENTED_Y);
        $pdf->Cell(self::PRESENTED_W, self::PRESENTED_H, 'This is to certify that', 0, 0, 'C');

        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetXY(self::NAME_X, self::NAME_Y);
        $pdf->Cell(self::NAME_W, self::NAME_H, $participantName, 0, 0, 'C');

        $pdf->SetDrawColor($accentR, $accentG, $accentB);
        $pdf->SetLineWidth(0.35);
        $pdf->Line(self::NAME_UNDERLINE_X, self::NAME_UNDERLINE_Y, self::NAME_UNDERLINE_X + self::NAME_UNDERLINE_W, self::NAME_UNDERLINE_Y);
        $pdf->Line(self::RULE_X, self::RULE_Y, self::RULE_X + self::RULE_W, self::RULE_Y);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetXY(self::ACHIEVEMENT_X, self::ACHIEVEMENT_Y);
        $pdf->Cell(self::ACHIEVEMENT_W, self::ACHIEVEMENT_H, $achievementLine, 0, 0, 'C');

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetXY(self::HACKATHON_X, self::HACKATHON_Y);
        $pdf->Cell(self::HACKATHON_W, self::HACKATHON_H, $hackathonName, 0, 0, 'C');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(self::META_X, self::META_Y);
        $pdf->Cell(self::META_W, self::META_H, self::truncate($eventMeta, 54), 0, 0, 'C');

        $pdf->SetDrawColor(110, 110, 110);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(self::SIG1_LINE_X, self::SIG1_LINE_Y, self::SIG1_LINE_X + self::SIG1_LINE_W, self::SIG1_LINE_Y);
        $pdf->Line(self::SIG2_LINE_X, self::SIG2_LINE_Y, self::SIG2_LINE_X + self::SIG2_LINE_W, self::SIG2_LINE_Y);

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(self::SIG1_LABEL_X, self::SIG1_LABEL_Y);
        $pdf->Cell(self::SIG1_LABEL_W, 5, 'Prof. Chandrasegar T. / Faculty Coordinator', 0, 0, 'C');
        $pdf->SetXY(self::SIG2_LABEL_X, self::SIG2_LABEL_Y);
        $pdf->Cell(self::SIG2_LABEL_W, 5, 'Event Organizer / CS Society', 0, 0, 'C');

        $qrPath = self::generateQRPng(rtrim(APP_URL, '/') . '/public/verify-cert.php?token=' . urlencode($token), $certificateId);
        if ($qrPath !== null) {
            $pdf->Image($qrPath, self::QR_X, self::QR_Y, self::QR_SIZE, self::QR_SIZE, 'PNG');
            @unlink($qrPath);
        }

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(self::VERIFY_X, self::VERIFY_Y);
        $pdf->Cell(self::VERIFY_W, self::VERIFY_H, 'Scan to verify', 0, 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetXY(self::CERTID_X, self::CERTID_Y);
        $pdf->Cell(self::CERTID_W, self::CERTID_H, 'ID: ' . substr($token, 0, 16) . '...', 0, 0, 'C');

        $pdf->Output('F', $savePath);
    }

    private static function generatePlain(array $participant, int $certificateId, string $certType, string $token, string $savePath, ?int $position, ?string $specialTitle): void
    {
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $isAchievement = $certType !== 'participation';
        [$accentR, $accentG, $accentB] = $isAchievement ? [180, 83, 9] : [91, 91, 214];

        $pdf->SetFillColor(250, 250, 247);
        $pdf->Rect(0, 0, self::PAGE_W, self::PAGE_H, 'F');
        $pdf->SetFillColor($accentR, $accentG, $accentB);
        $pdf->Rect(0, 0, self::PAGE_W, 10, 'F');
        $pdf->Rect(0, self::PAGE_H - 10, self::PAGE_W, 10, 'F');
        $pdf->SetDrawColor($accentR, $accentG, $accentB);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect(18, 18, 261, 174, 'D');
        $pdf->Rect(24, 24, 249, 162, 'D');

        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetXY(80, 48);
        $pdf->Cell(137, 6, strtoupper($isAchievement ? 'Achievement Record' : 'Participation Record'), 0, 0, 'C');

        if ($isAchievement) {
            $label = self::positionLabel($certType, $specialTitle);
            $pdf->SetFillColor($accentR, $accentG, $accentB);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Rect(122, 57, 52, 8, 'F');
            $pdf->SetXY(122, 58.2);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(52, 5.5, $label, 0, 0, 'C');
        }

        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetXY(74, 68);
        $pdf->Cell(146, 10, $isAchievement ? 'Certificate of Achievement' : 'Certificate of Participation', 0, 0, 'C');
        $pdf->SetDrawColor($accentR, $accentG, $accentB);
        $pdf->Line(113, 84, 183, 84);

        $pdf->SetFont('Helvetica', 'I', 12);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(80, 89);
        $pdf->Cell(140, 6, 'This is to certify that', 0, 0, 'C');

        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetXY(74, 98);
        $pdf->Cell(152, 12, self::truncate((string) $participant['name'], 34), 0, 0, 'C');
        $pdf->Line(113, 118.5, 183, 118.5);

        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(74, 124);
        $pdf->Cell(152, 6, self::achievementLine($certType, $specialTitle), 0, 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetXY(74, 132.5);
        $pdf->Cell(152, 6, self::truncate((string) $participant['hackathon_name'], 46), 0, 0, 'C');
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetXY(74, 141);
        $pdf->Cell(152, 5, self::truncate(formatUtcToIst((string) ($participant['starts_at'] ?? null), 'd F Y') . ' | ' . (string) ($participant['venue'] ?? 'Venue TBA'), 54), 0, 0, 'C');

        $pdf->SetDrawColor(110, 110, 110);
        $pdf->Line(87, 171, 137, 171);
        $pdf->Line(160, 171, 210, 171);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetXY(80, 173);
        $pdf->Cell(64, 5, 'Prof. Chandrasegar T. / Faculty Coordinator', 0, 0, 'C');
        $pdf->SetXY(153, 173);
        $pdf->Cell(64, 5, 'Event Organizer / CS Society', 0, 0, 'C');

        $logoPath = self::getCirclePng((string) ($participant['organizer_name'] ?? APP_NAME), 200, [$accentR, $accentG, $accentB], self::initials((string) ($participant['organizer_name'] ?? APP_NAME)));
        $pdf->Image($logoPath, 37, 94, 22, 22, 'PNG');
        @unlink($logoPath);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->SetXY(29, 119);
        $pdf->MultiCell(38, 3.5, self::truncate((string) ($participant['organizer_name'] ?? APP_NAME), 28), 0, 'C');

        $qrPath = self::generateQRPng(rtrim(APP_URL, '/') . '/public/verify-cert.php?token=' . urlencode($token), $certificateId);
        if ($qrPath !== null) {
            $pdf->Image($qrPath, 240, 90, 30, 30, 'PNG');
            @unlink($qrPath);
        }

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY(234, 123);
        $pdf->Cell(42, 4, 'Scan to verify', 0, 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor($accentR, $accentG, $accentB);
        $pdf->SetXY(229, 129);
        $pdf->Cell(52, 4.5, 'ID: ' . substr($token, 0, 16) . '...', 0, 0, 'C');

        $pdf->Output('F', $savePath);
    }

    private static function savePath(int $hackathonId, int $certificateId): string
    {
        $directory = dirname(__DIR__) . '/uploads/certificates/' . $hackathonId;
        ensureDirectory($directory);

        return $directory . '/' . $certificateId . '.pdf';
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

    private static function generateQRPng(string $content, int $certificateId): ?string
    {
        $path = sys_get_temp_dir() . '/hd_cert_qr_' . $certificateId . '_' . bin2hex(random_bytes(4)) . '.png';
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

    private static function getCirclePng(string $seed, int $size, array $rgb, string $text): string
    {
        $path = sys_get_temp_dir() . '/hd_cert_circle_' . md5($seed . microtime(true)) . '.png';
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('Unable to prepare certificate image.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $fill = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), $size - 4, $size - 4, $fill);

        $fontPath = self::fontPath();
        if ($fontPath !== null) {
            $fontSize = (int) max(18, round($size * 0.2));
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $textWidth = (int) abs($bbox[4] - $bbox[0]);
            $textHeight = (int) abs($bbox[5] - $bbox[1]);
            $x = (int) (($size - $textWidth) / 2);
            $y = (int) (($size + $textHeight) / 2) - 6;
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $text);
        } else {
            $font = 5;
            $textWidth = imagefontwidth($font) * strlen($text);
            $textHeight = imagefontheight($font);
            imagestring($image, $font, (int) (($size - $textWidth) / 2), (int) (($size - $textHeight) / 2), $text, $textColor);
        }

        imagepng($image, $path);
        imagedestroy($image);

        return $path;
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

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice(array_values(array_filter($parts, static fn(string $part): bool => $part !== '')), 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'HD';
    }

    private static function positionLabel(string $certType, ?string $specialTitle): string
    {
        return match ($certType) {
            'winner' => '1st Place',
            'runner_up' => '2nd Place',
            'second_runner_up' => '3rd Place',
            'special' => self::truncate((string) ($specialTitle ?? 'Special Award'), 24),
            default => '',
        };
    }

    private static function achievementLine(string $certType, ?string $specialTitle): string
    {
        return match ($certType) {
            'winner' => 'has secured 1st Place in',
            'runner_up' => 'has secured 2nd Place in',
            'second_runner_up' => 'has secured 3rd Place in',
            'special' => 'has been awarded ' . self::truncate((string) ($specialTitle ?? 'Special Award'), 24) . ' in',
            default => 'has successfully participated in',
        };
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
