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

$connection = db();
try {
    $connection->begin_transaction();
    $stmt = $connection->prepare("SELECT id, booking_date, session_time, end_time, agreed_rate, status, completion_confirmed_at, completion_issue FROM appointments WHERE id = ? AND learner_id = ? FOR UPDATE");
    $stmt->bind_param('ii', $appointmentId, $learner['id']);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appointment || $appointment['status'] !== 'COMPLETED' || !$appointment['completion_confirmed_at'] || $appointment['completion_issue'] !== null) {
        throw new RuntimeException('Confirm the completed session first. A reported issue must be reviewed before payment.');
    }

    $existing = $connection->prepare('SELECT payment_method, status FROM appointment_payments WHERE appointment_id = ? FOR UPDATE');
    $existing->bind_param('i', $appointmentId);
    $existing->execute();
    $payment = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($payment) {
        if ($payment['status'] === 'PAID') throw new RuntimeException('This session is already paid.');
        if ($payment['payment_method'] === 'CASH') throw new RuntimeException('Cash payment is already selected. Ask the tutor to confirm it after receiving the cash.');
        throw new RuntimeException('An online checkout has already been started for this session. Continue with that payment method.');
    }

    $minutes = (int) ((strtotime($appointment['booking_date'] . ' ' . $appointment['end_time']) - strtotime($appointment['booking_date'] . ' ' . $appointment['session_time'])) / 60);
    $rate = (float) $appointment['agreed_rate'];
    $amountMinor = (int) round($rate * $minutes / 60 * 100);
    if ($minutes < 1 || $rate <= 0 || $amountMinor < 100) throw new RuntimeException('The approved session price is missing or invalid.');

    $reference = 'SYN-CASH-' . $appointmentId . '-' . bin2hex(random_bytes(8));
    $insert = $connection->prepare("INSERT INTO appointment_payments (appointment_id, reference_number, amount_minor, currency, payment_method, status) VALUES (?, ?, ?, 'PHP', 'CASH', 'PENDING')");
    $insert->bind_param('isi', $appointmentId, $reference, $amountMinor);
    $insert->execute();
    $insert->close();
    log_activity((int) $learner['id'], 'CASH_PAYMENT_SELECTED', 'Selected in-person cash for appointment #' . $appointmentId);
    $connection->commit();
    flash('success', 'Cash selected. Pay the tutor in person, then ask them to confirm receipt in Synapse.');
} catch (RuntimeException $exception) {
    if ($connection->pdo()->inTransaction()) $connection->rollback();
    flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    if ($connection->pdo()->inTransaction()) $connection->rollback();
    error_log('Cash payment selection failed: ' . $exception->getMessage());
    flash('danger', 'Could not select cash payment. Please try again.');
}
redirect('user/dashboard.php');
