<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
$learner = require_role('user');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$appointmentId) {
    flash('danger', 'Invalid session.');
    redirect('user/dashboard.php');
}
$secretKey = project_env('PAYMONGO_SECRET_KEY');
if (project_env('PAYMONGO_ENABLED') !== 'true' || !str_starts_with($secretKey, 'sk_test_')) {
    flash('warning', 'Online payment is currently disabled. Please choose cash in person.');
    redirect('user/dashboard.php');
}

$connection = db();
try {
    $connection->begin_transaction();
    $stmt = $connection->prepare("SELECT a.id, a.booking_date, a.session_time, a.end_time, a.agreed_rate, a.completion_confirmed_at, a.completion_issue, a.status, c.course_code, c.course_name FROM appointments a JOIN courses c ON c.id = a.course_id WHERE a.id = ? AND a.learner_id = ? FOR UPDATE");
    $stmt->bind_param('ii', $appointmentId, $learner['id']);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appointment || $appointment['status'] !== 'COMPLETED' || !$appointment['completion_confirmed_at'] || $appointment['completion_issue'] !== null) {
        throw new RuntimeException('Confirm the completed session first. A reported issue must be reviewed before payment.');
    }

    $existing = $connection->prepare('SELECT checkout_url, status FROM appointment_payments WHERE appointment_id = ? FOR UPDATE');
    $existing->bind_param('i', $appointmentId);
    $existing->execute();
    $payment = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($payment && $payment['status'] === 'PAID') throw new RuntimeException('This session is already paid.');
    if ($payment && !empty($payment['checkout_url'])) {
        $checkoutUrl = $payment['checkout_url'];
        $connection->commit();
        header('Location: ' . $checkoutUrl, true, 303);
        exit;
    }
    if ($payment) throw new RuntimeException('Payment setup is already in progress. Refresh and try again shortly.');

    $start = strtotime($appointment['booking_date'] . ' ' . $appointment['session_time']);
    $end = strtotime($appointment['booking_date'] . ' ' . $appointment['end_time']);
    $minutes = (int) (($end - $start) / 60);
    $rate = (float) $appointment['agreed_rate'];
    $amountMinor = (int) round($rate * $minutes / 60 * 100);
    if ($minutes < 1 || $rate <= 0 || $amountMinor < 100) throw new RuntimeException('The approved session price is missing or invalid.');
    $reference = 'SYN-' . $appointmentId . '-' . bin2hex(random_bytes(10));
    $insert = $connection->prepare("INSERT INTO appointment_payments (appointment_id, reference_number, amount_minor, currency, status) VALUES (?, ?, ?, 'PHP', 'PENDING')");
    $insert->bind_param('isi', $appointmentId, $reference, $amountMinor);
    $insert->execute();
    $insert->close();
    $connection->commit();

    if (!function_exists('curl_init')) {
        $delete = $connection->pdo()->prepare("DELETE FROM appointment_payments WHERE appointment_id = ? AND status = 'PENDING' AND checkout_session_id IS NULL");
        $delete->execute([$appointmentId]);
        throw new RuntimeException('The server does not have the cURL extension enabled.');
    }
    $payload = [
        'data' => ['attributes' => [
            'line_items' => [[
                'name' => $appointment['course_code'] . ' tutoring session',
                'amount' => $amountMinor,
                'currency' => 'PHP',
                'quantity' => 1,
                'description' => $appointment['course_name'] . ' session with ' . $learner['full_name'],
            ]],
            'payment_method_types' => ['card', 'gcash', 'qrph', 'paymaya'],
            'success_url' => url('user/dashboard.php?payment=return'),
            'cancel_url' => url('user/dashboard.php?payment=cancelled'),
            'reference_number' => $reference,
            'send_email_receipt' => true,
        ]],
    ];
    $handle = curl_init('https://api.paymongo.com/v2/checkout_sessions');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
            'Idempotency-Key: ' . $reference,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
        $delete = $connection->pdo()->prepare("DELETE FROM appointment_payments WHERE appointment_id = ? AND status = 'PENDING' AND checkout_session_id IS NULL");
        $delete->execute([$appointmentId]);
        throw new RuntimeException($curlError ?: 'PayMongo could not create a checkout session. Check the test key and payment methods.');
    }

    $response = json_decode($body, true);
    $session = $response['data'] ?? [];
    $sessionId = (string) ($session['id'] ?? '');
    $checkoutUrl = (string) ($session['attributes']['checkout_url'] ?? '');
    $livemode = (bool) ($session['attributes']['livemode'] ?? true);
    if ($sessionId === '' || !str_starts_with($checkoutUrl, 'https://checkout.paymongo.com/') || $livemode) {
        $delete = $connection->pdo()->prepare("DELETE FROM appointment_payments WHERE appointment_id = ? AND status = 'PENDING' AND checkout_session_id IS NULL");
        $delete->execute([$appointmentId]);
        throw new RuntimeException('PayMongo returned an invalid test checkout session.');
    }
    $update = $connection->prepare("UPDATE appointment_payments SET checkout_session_id = ?, checkout_url = ? WHERE appointment_id = ? AND reference_number = ? AND status = 'PENDING'");
    $update->bind_param('ssis', $sessionId, $checkoutUrl, $appointmentId, $reference);
    $update->execute();
    $update->close();
    header('Location: ' . $checkoutUrl, true, 303);
    exit;
} catch (RuntimeException $exception) {
    try { if ($connection->pdo()->inTransaction()) $connection->rollback(); } catch (Throwable) {}
    flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    try { if ($connection->pdo()->inTransaction()) $connection->rollback(); } catch (Throwable) {}
    error_log('Session checkout failed: ' . $exception->getMessage());
    flash('danger', 'Could not start payment. Please try again.');
}
redirect('user/dashboard.php');
