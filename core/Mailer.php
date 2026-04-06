<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function sendMail(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        array $attachments = []
    ): bool {
        if (!class_exists(PHPMailer::class)) {
            error_log('PHPMailer is not installed. Run composer install.');

            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], PHP_EOL, $htmlBody)));

            foreach ($attachments as $attachment) {
                if (!is_array($attachment) || empty($attachment['path']) || !file_exists($attachment['path'])) {
                    continue;
                }

                $mail->addAttachment($attachment['path'], $attachment['name'] ?? basename($attachment['path']));
            }

            return $mail->send();
        } catch (Exception $exception) {
            error_log('Mail send failed: ' . $exception->getMessage());

            return false;
        }
    }

    private function __construct()
    {
    }
}
