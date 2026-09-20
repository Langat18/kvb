<?php

declare(strict_types=1);

namespace VetBoard;

class Mailer
{
    private static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? '';

        if ($apiKey === '') {
            error_log('Mailer: BREVO_API_KEY is not configured.');
            return false;
        }

        $payload = [
            'sender'      => [
                'name'  => $_ENV['MAIL_FROM_NAME'] ?? 'Kenya Veterinary Board',
                'email' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@kenyavetboard.or.ke',
            ],
            'to'          => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("Mailer: Brevo error [{$httpCode}] to {$toEmail}: {$response} {$curlErr}");
            return false;
        }

        return true;
    }

    public static function sendPasswordReset(string $toEmail, string $toName, string $resetUrl): bool
    {
        $body = self::wrap(
            'Reset your password',
            '<p>A password reset was requested for your account.</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 28px;background:#1a5c2a;color:#fff;text-decoration:none;border-radius:4px;">Reset your password</a></p>'
            . '<p>This link expires in 60 minutes. If you did not request this, you can ignore this email.</p>'
        );

        return self::send($toEmail, $toName, 'Reset your Kenya Veterinary Board password', $body);
    }

    public static function sendAccountCreated(string $toEmail, string $toName, string $activationUrl): bool
    {
        $body = self::wrap(
            'Your account has been created',
            '<p>Dear ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>An account has been created for you on the Kenya Veterinary Board CPD Attendance system.</p>'
            . '<p><a href="' . htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 28px;background:#1a5c2a;color:#fff;text-decoration:none;border-radius:4px;">Set your password &amp; activate account</a></p>'
            . '<p>This link expires in 60 minutes. If it expires before you use it, use "Forgot password" on the login page to request a new one.</p>'
        );

        return self::send($toEmail, $toName, 'Your KVB account has been created', $body);
    }

    private static function wrap(string $heading, string $innerHtml): string
    {
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;line-height:1.6;">
<div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:#1a5c2a;color:#fff;padding:20px;text-align:center;">
        <h1 style="margin:0;font-size:1.2rem;">Kenya Veterinary Board</h1>
        <p style="margin:4px 0 0;">{$heading}</p>
    </div>
    <div style="background:#f9f9f9;padding:24px;">
        {$innerHtml}
    </div>
    <div style="text-align:center;padding:16px;color:#999;font-size:12px;">
        <p>Kenya Veterinary Board &copy; {$year}</p>
    </div>
</div>
</body>
</html>
HTML;
    }
}
