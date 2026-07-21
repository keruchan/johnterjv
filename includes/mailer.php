<?php
/**
 * Thin wrapper around the vendored PHPMailer library for outbound
 * application email (currently: Community account verification).
 */

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../config/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an HTML email via the configured Hostinger SMTP mailbox.
 * Returns true on success; failures are logged server-side only —
 * never leaked to the browser, since this always runs after some other
 * user-facing action has already succeeded (e.g. registration).
 */
function sendAppMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->SMTPDebug = SMTP_DEBUG ? 2 : 0;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);

        $mail->send();

        return true;
    } catch (PHPMailerException $e) {
        error_log('[CERTREEFY MAIL ERROR] ' . $mail->ErrorInfo);

        return false;
    }
}
