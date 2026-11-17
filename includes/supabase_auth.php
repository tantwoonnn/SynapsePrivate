<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

/** Call the Supabase Auth HTTP API without exposing credentials to the browser. */
function supabase_auth_request(string $path, array $payload): array
{
    $baseUrl = rtrim(project_env('SUPABASE_URL'), '/');
    $apiKey = project_env('SUPABASE_PUBLISHABLE_KEY', project_env('SUPABASE_ANON_KEY'));
    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Supabase Auth is not configured. Set SUPABASE_URL and SUPABASE_PUBLISHABLE_KEY.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Supabase Auth.');
    }

    $curl = curl_init($baseUrl . '/auth/v1/' . ltrim($path, '/'));
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($body)) {
        throw new RuntimeException('Supabase Auth request failed: ' . $curlError);
    }
    $response = json_decode($body, true);
    if (!is_array($response)) $response = [];
    if ($status < 200 || $status >= 300) {
        $message = (string) ($response['msg'] ?? $response['message'] ?? $response['error_description'] ?? $response['error'] ?? 'Supabase Auth request failed.');
        throw new DomainException($message, $status);
    }
    return $response;
}

function supabase_auth_signup(string $email, string $password, string $fullName): array
{
    return supabase_auth_request('signup?' . http_build_query(['redirect_to' => url('login.php?verified=1')]), [
        'email' => $email,
        'password' => $password,
        'data' => ['full_name' => $fullName],
    ]);
}

function supabase_auth_login(string $email, string $password): array
{
    return supabase_auth_request('token?grant_type=password', ['email' => $email, 'password' => $password]);
}

function supabase_auth_resend_signup(string $email): array
{
    return supabase_auth_request('resend?' . http_build_query(['redirect_to' => url('login.php?verified=1')]), [
        'type' => 'signup',
        'email' => $email,
    ]);
}

/** Create an account from the admin screen using the server-only Supabase key. */
function supabase_auth_admin_create_user(string $email, string $password, string $fullName): array
{
    $baseUrl = rtrim(project_env('SUPABASE_URL'), '/');
    $secretKey = project_env('SUPABASE_SECRET_KEY');
    if ($baseUrl === '' || $secretKey === '') {
        throw new RuntimeException('Set SUPABASE_URL and the server-only SUPABASE_SECRET_KEY to create users from the admin screen.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for Supabase Auth.');

    $curl = curl_init($baseUrl . '/auth/v1/admin/users');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'user_metadata' => ['full_name' => $fullName],
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $secretKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) throw new RuntimeException('Supabase Auth user creation failed: ' . $curlError);
    $response = json_decode($body, true);
    if (!is_array($response)) $response = [];
    if ($status < 200 || $status >= 300) {
        $message = (string) ($response['msg'] ?? $response['message'] ?? 'Supabase Auth user creation failed.');
        throw new DomainException($message, $status);
    }
    return $response;
}

function supabase_auth_admin_delete_user(string $authUserId): void
{
    $baseUrl = rtrim(project_env('SUPABASE_URL'), '/');
    $secretKey = project_env('SUPABASE_SECRET_KEY');
    if ($baseUrl === '' || $secretKey === '') {
        throw new RuntimeException('Set SUPABASE_URL and the server-only SUPABASE_SECRET_KEY to delete users from the admin screen.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for Supabase Auth.');

    $curl = curl_init($baseUrl . '/auth/v1/admin/users/' . rawurlencode($authUserId));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['apikey: ' . $secretKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) throw new RuntimeException('Supabase Auth user deletion failed: ' . $curlError);
    if ($status < 200 || $status >= 300) {
        $response = json_decode($body, true);
        $message = is_array($response) ? (string) ($response['msg'] ?? $response['message'] ?? 'Supabase Auth user deletion failed.') : 'Supabase Auth user deletion failed.';
        throw new DomainException($message, $status);
    }
}
