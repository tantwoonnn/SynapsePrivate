<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/functions.php';

use Google\Service\Gmail;
use Google\Service\Gmail\Message;

function send_gmail_html_email(string $email, string $name, string $subjectText, string $html): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Google API PHP client is missing. Run composer install in the Synapse folder.');
        return false;
    }
    require_once $autoload;

    $clientId = project_env('GOOGLE_OAUTH_CLIENT_ID');
    $clientSecret = project_env('GOOGLE_OAUTH_CLIENT_SECRET');
    $refreshToken = project_env('GOOGLE_OAUTH_REFRESH_TOKEN');
    $senderEmail = project_env('GMAIL_SENDER_EMAIL');
    $senderName = project_env('GMAIL_SENDER_NAME', 'Synapse');
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('Synapse Gmail API OAuth settings or GMAIL_SENDER_EMAIL are missing or invalid.');
        return false;
    }

    try {
        $client = new Google\Client();
        $client->setApplicationName('Synapse email verification');
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setScopes([Gmail::GMAIL_SEND]);
        $client->setAccessType('offline');
        $tokenResponse = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($tokenResponse['error'])) {
            throw new RuntimeException('Google OAuth token refresh failed: ' . ($tokenResponse['error_description'] ?? $tokenResponse['error']));
        }

        $cleanName = trim(str_replace(["\r", "\n"], '', $name));
        $cleanSenderName = trim(str_replace(["\r", "\n"], '', $senderName));
        $toName = $cleanName === '' ? '' : mb_encode_mimeheader($cleanName, 'UTF-8', 'B');
        $fromName = $cleanSenderName === '' ? '' : mb_encode_mimeheader($cleanSenderName, 'UTF-8', 'B');
        $toHeader = $toName === '' ? $email : $toName . ' <' . $email . '>';
        $fromHeader = $fromName === '' ? $senderEmail : $fromName . ' <' . $senderEmail . '>';
        $subject = mb_encode_mimeheader(str_replace(["\r", "\n"], '', $subjectText), 'UTF-8', 'B');
        $raw = "To: {$toHeader}\r\n"
            . "From: {$fromHeader}\r\n"
            . "Subject: {$subject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html));

        $message = new Message();
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));
        $gmail = new Gmail($client);
        $gmail->users_messages->send('me', $message);
        return true;
    } catch (Throwable $exception) {
        error_log('Synapse Gmail API error: ' . $exception->getMessage());
        return false;
    }
}

function send_verification_email(string $email, string $name, string $token): bool
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $link = url('verify.php?token=' . urlencode($token));
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto">'
        . '<h2>Welcome to Synapse, ' . $safeName . '!</h2>'
        . '<p>Confirm your email address to activate your account.</p>'
        . '<p><a href="' . $safeLink . '">Verify my email</a></p>'
        . '<p>This link expires in 24 hours and can only be used once.</p>'
        . '<p>If you did not create this account, you can ignore this message.</p></div>';
    return send_gmail_html_email($email, $name, 'Verify your Synapse account', $html);
}
