<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

/** Send application notifications through the configured SMTP provider. */
function send_html_email(string $email, string $name, string $subjectText, string $html): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('PHPMailer is missing. Run composer install in the Synapse folder.');
        return false;
    }
    require_once $autoload;

    $host = project_env('SMTP_HOST');
    $username = project_env('SMTP_USERNAME');
    $password = project_env('SMTP_PASSWORD');
    $fromEmail = project_env('SMTP_FROM_EMAIL');
    $fromName = project_env('SMTP_FROM_NAME', 'Synapse');
    $port = (int) project_env('SMTP_PORT', '587');
    $encryption = strtolower(project_env('SMTP_ENCRYPTION', 'tls'));
    if ($host === '' || $username === '' || $password === ''
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($encryption, ['tls', 'ssl'], true)) {
        error_log('Synapse SMTP settings or email addresses are missing or invalid.');
        return false;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port;
        $mail->SMTPSecure = $encryption === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $subjectText;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)));
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Synapse SMTP email error: ' . $exception->getMessage());
        return false;
    }
}
