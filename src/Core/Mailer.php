<?php
declare(strict_types=1);

namespace src\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    public static function send(
        string $toEmail,
        string $subject,
        string $body
    ): bool {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            
            // Your Gmail settings
            $mail->Host     = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->Username = getenv('SMTP_USER') ?: 'shammpams@gmail.com';
            $mail->Password = getenv('SMTP_PASS') ?: 'ihvs qjcd tdua khey';
            $mail->Port     = (int) (getenv('SMTP_PORT') ?: 587);

            $mail->setFrom(
                getenv('SMTP_FROM_EMAIL') ?: 'shammpams@gmail.com',
                getenv('SMTP_FROM_NAME') ?: 'IndianaDesi'
            );

            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;

            // TEMP DEBUG - Remove after testing
            $mail->SMTPDebug = 0;

            $mail->send();
            error_log("✅ Email sent to {$toEmail}");
            return true;
            
        } catch (Exception $e) {
            error_log("💥 Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
