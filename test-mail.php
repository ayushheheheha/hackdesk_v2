<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Mailer.php';

$recipientEmail = 'ayushyadav102030tt@gmail.com';
$recipientName = 'Ayush Yadav';
$subject = 'HackDesk SMTP Test';
$htmlBody = '
    <html lang="en">
    <body style="margin:0;padding:24px;background:#0C0C0E;color:#FAFAFA;font-family:Inter,Arial,sans-serif;">
        <div style="max-width:560px;margin:0 auto;padding:24px;background:#131316;border:1px solid #27272A;border-radius:12px;">
            <h1 style="margin:0 0 12px;font-size:22px;">HackDesk SMTP Test</h1>
            <p style="margin:0 0 10px;color:#D4D4D8;">This email confirms that your Uno Send SMTP credentials are being used by HackDesk successfully.</p>
            <p style="margin:0 0 10px;color:#A1A1AA;">Sent at: ' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . ' IST</p>
            <p style="margin:0;color:#A1A1AA;">If you received this, your Phase 1 mail configuration is working.</p>
        </div>
    </body>
    </html>
';

$sent = Mailer::sendMail($recipientEmail, $recipientName, $subject, $htmlBody);

if ($sent) {
    echo 'Test email sent successfully to ' . $recipientEmail . PHP_EOL;
    exit(0);
}

echo 'Test email failed to send. Check SMTP_FROM, SMTP credentials, and logs/php-error.log.' . PHP_EOL;
exit(1);
