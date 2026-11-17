<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/supabase_auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$_SESSION['pending_email'] = $email;
$lastSent = (int) ($_SESSION['last_verification_resend'] ?? 0);
if (time() - $lastSent < 60) {
    flash('warning', 'Please wait a minute before requesting another verification email.');
    redirect('verify-email.php');
}
$_SESSION['last_verification_resend'] = time();

if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        supabase_auth_resend_signup($email);
    } catch (Throwable $exception) {
        // Keep the response generic to avoid disclosing which addresses have accounts.
        error_log('Synapse Supabase verification resend error: ' . $exception->getMessage());
    }
}
flash('info', 'If the account exists and still needs verification, Supabase Auth will send a new link.');
redirect('verify-email.php');
