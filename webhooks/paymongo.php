<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$webhookSecret = project_env('PAYMONGO_WEBHOOK_SECRET');
$apiKey = project_env('PAYMONGO_SECRET_KEY');
$rawBody = file_get_contents('php://input');
$signatureHeader = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
if ($webhookSecret === '' || $apiKey === '' || $rawBody === '' || strlen($rawBody) > 1048576) {
    http_response_code(400);
    exit;
}

$parts = [];
foreach (explode(',', $signatureHeader) as $part) {
    $pair = explode('=', trim($part), 2);
    if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
}
$testMode = str_starts_with($apiKey, 'sk_test_');
$provided = $parts[$testMode ? 'te' : 'li'] ?? '';
$timestamp = $parts['t'] ?? '';
if ($provided === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(400);
    exit;
}
$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
if (!hash_equals($expected, $provided)) {
    http_response_code(400);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    exit;
}
$eventData = $event['data'] ?? [];
$eventAttributes = $eventData['attributes'] ?? [];
$eventType = $eventAttributes['type'] ?? $eventData['type'] ?? '';
if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    exit('Ignored');
}

$checkout = $eventAttributes['data'] ?? $eventData['data'] ?? [];
$attributes = $checkout['attributes'] ?? [];
$reference = (string) ($attributes['reference_number'] ?? '');
$checkoutId = (string) ($checkout['id'] ?? '');
$livemode = (bool) ($eventAttributes['livemode'] ?? $eventData['livemode'] ?? false);
$payments = $attributes['payments'] ?? [];
$paidPayment = null;
foreach ($payments as $payment) {
    if (($payment['attributes']['status'] ?? '') === 'paid') {
        $paidPayment = $payment;
        break;
    }
}
if ($reference === '' || $checkoutId === '' || !$paidPayment || $livemode === $testMode) {
    http_response_code(400);
    exit;
}
$paymentAttributes = $paidPayment['attributes'] ?? [];
$paymentId = (string) ($paidPayment['id'] ?? '');
$amount = (int) ($paymentAttributes['amount'] ?? 0);
$currency = strtoupper((string) ($paymentAttributes['currency'] ?? ''));
if ($paymentId === '' || $amount < 1 || $currency !== 'PHP') {
    http_response_code(400);
    exit;
}

try {
    $stmt = db()->prepare("UPDATE appointment_payments SET status = 'PAID', provider_payment_id = ?, paid_at = NOW() WHERE reference_number = ? AND checkout_session_id = ? AND amount_minor = ? AND payment_method = 'PAYMONGO' AND currency = 'PHP' AND status = 'PENDING'");
    $stmt->bind_param('sssi', $paymentId, $reference, $checkoutId, $amount);
    $stmt->execute();
    $stmt->close();
    http_response_code(200);
    echo 'OK';
} catch (Throwable $exception) {
    error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
    http_response_code(500);
}
