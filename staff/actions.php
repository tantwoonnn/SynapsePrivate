<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$tutor = require_role('staff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');
if (!$id || !in_array($action, ['approve', 'reject', 'complete'], true)) {
    flash('danger', 'Invalid session action.');
    redirect('staff/dashboard.php');
}
$rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
if ($action === 'reject' && (mb_strlen($rejectionReason) < 10 || mb_strlen($rejectionReason) > 500)) {
    flash('warning', 'Enter a rejection reason between 10 and 500 characters.');
    redirect('staff/dashboard.php');
}

$pdo = db();
$pdo->begin_transaction();
try {
    $stmt = $pdo->prepare('SELECT a.booking_date, a.session_time, a.end_time, a.status, l.full_name AS learner_name, l.email AS learner_email, c.course_code, c.course_name FROM appointments a JOIN users l ON l.id = a.learner_id JOIN courses c ON c.id = a.course_id WHERE a.id = ? AND a.tutor_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $id, $tutor['id']);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appointment) throw new RuntimeException('Session request was not found.');

    if ($action === 'approve' && $appointment['status'] === 'PENDING') {
        $check = $pdo->prepare("SELECT id FROM appointments WHERE tutor_id = ? AND booking_date = ? AND status = 'CONFIRMED' AND id <> ? AND session_time < ? AND end_time > ?");
        $check->bind_param('isiss', $tutor['id'], $appointment['booking_date'], $id, $appointment['end_time'], $appointment['session_time']);
        $check->execute();
        $busy = $check->get_result();
        if ($busy->num_rows > 0) throw new RuntimeException('This tutor already has a confirmed session that overlaps this time.');
        $check->close();
        $newStatus = 'CONFIRMED';
    } elseif ($action === 'reject' && $appointment['status'] === 'PENDING') {
        $newStatus = 'CANCELLED';
    } elseif ($action === 'complete' && $appointment['status'] === 'CONFIRMED') {
        $newStatus = 'COMPLETED';
    } else {
        throw new RuntimeException('This session is no longer in a state that can use that action.');
    }

    $reasonToSave = $action === 'reject' ? $rejectionReason : null;
    $update = $pdo->prepare('UPDATE appointments SET status = ?, rejection_reason = ? WHERE id = ? AND tutor_id = ?');
    $update->bind_param('ssii', $newStatus, $reasonToSave, $id, $tutor['id']);
    $update->execute();
    $update->close();
    log_activity((int) $tutor['id'], 'SESSION_' . $newStatus, 'Updated appointment #' . $id);
    $pdo->commit();

    if (in_array($action, ['approve', 'reject'], true)) {
        $approved = $action === 'approve';
        $safeLearnerName = e($appointment['learner_name']);
        $safeTutorName = e($tutor['full_name']);
        $safeSubject = e($appointment['course_code'] . ' · ' . $appointment['course_name']);
        $safeWhen = e(format_date($appointment['booking_date']) . ' · ' . format_time($appointment['session_time']) . '–' . format_time($appointment['end_time']));
        $reasonHtml = $approved ? '' : '<p><strong>Tutor’s reason:</strong><br>' . nl2br(e($rejectionReason)) . '</p>';
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto">'
            . '<h2>Hello ' . $safeLearnerName . ',</h2>'
            . ($approved
                ? '<p>' . $safeTutorName . ' accepted your tutoring session request.</p>'
                : '<p>' . $safeTutorName . ' declined your tutoring session request.</p>')
            . '<p><strong>Subject:</strong> ' . $safeSubject . '<br><strong>When:</strong> ' . $safeWhen . '</p>'
            . $reasonHtml
            . '<p><a href="' . e(url('user/dashboard.php')) . '">Open your Synapse dashboard</a></p></div>';
        $emailSent = send_gmail_html_email(
            $appointment['learner_email'],
            $appointment['learner_name'],
            $approved ? 'Your Synapse session was accepted' : 'Your Synapse session was declined',
            $html
        );
        flash($emailSent ? 'success' : 'warning', $emailSent
            ? 'Session updated and the learner was emailed.'
            : 'Session updated, but the learner notification email could not be sent.');
    } else {
        flash('success', 'Session updated to ' . strtolower($newStatus) . '.');
    }
} catch (RuntimeException $exception) {
    $pdo->rollback();
    flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    $pdo->rollback();
    error_log('Tutor appointment action failed: ' . $exception->getMessage());
    flash('danger', 'Could not update the session. Please try again.');
}
redirect('staff/dashboard.php');
