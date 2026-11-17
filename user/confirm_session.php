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
$action = (string) ($_POST['action'] ?? '');
$issue = trim((string) ($_POST['completion_issue'] ?? ''));
if (!$appointmentId || !in_array($action, ['confirm', 'report'], true)) {
    flash('danger', 'Invalid session confirmation.');
    redirect('user/dashboard.php');
}
if ($action === 'report' && (mb_strlen($issue) < 10 || mb_strlen($issue) > 500)) {
    flash('warning', 'Describe the issue in 10 to 500 characters.');
    redirect('user/dashboard.php');
}

if ($action === 'confirm') {
    $stmt = db()->prepare("UPDATE appointments SET completion_confirmed_at = NOW() WHERE id = ? AND learner_id = ? AND status = 'COMPLETED' AND completion_confirmed_at IS NULL AND completion_issue IS NULL");
    $stmt->bind_param('ii', $appointmentId, $learner['id']);
} else {
    $stmt = db()->prepare("UPDATE appointments SET completion_issue = ? WHERE id = ? AND learner_id = ? AND status = 'COMPLETED' AND completion_confirmed_at IS NULL AND completion_issue IS NULL");
    $stmt->bind_param('sii', $issue, $appointmentId, $learner['id']);
}
$stmt->execute();
$changed = $stmt->affected_rows === 1;
$stmt->close();
if ($changed) {
    log_activity((int) $learner['id'], $action === 'confirm' ? 'SESSION_COMPLETION_CONFIRMED' : 'SESSION_COMPLETION_REPORTED', 'Appointment #' . $appointmentId);
    flash('success', $action === 'confirm'
        ? 'Session confirmed. You can now pay the agreed amount.'
        : 'Your report was sent. Payment is paused while an administrator reviews the issue.');
} else {
    flash('warning', 'This session is no longer waiting for your confirmation.');
}
redirect('user/dashboard.php');
