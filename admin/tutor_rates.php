<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = require_role('admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tutorId = filter_var($_POST['tutor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $decision = (string) ($_POST['decision'] ?? '');
    if (!$tutorId || !$courseId || !in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Choose a valid rate request.';
    } else {
        $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
        $stmt = db()->prepare("UPDATE tutor_courses SET rate_status = ?, rate_reviewed_by = ?, rate_reviewed_at = NOW() WHERE tutor_id = ? AND course_id = ? AND proposed_rate IS NOT NULL");
        $stmt->bind_param('siii', $status, $admin['id'], $tutorId, $courseId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if ($changed) {
            log_activity((int) $admin['id'], 'TUTOR_RATE_' . $status, 'Reviewed tutor #' . $tutorId . ' rate for subject #' . $courseId);
            flash('success', 'Rate request ' . strtolower($status) . '.');
            redirect('admin/tutor_rates.php');
        }
        $error = 'That rate request was not found or has already been reviewed.';
    }
}

$rates = db()->query("SELECT tc.tutor_id, tc.course_id, tc.proposed_rate, tc.rate_status, tc.created_at, u.full_name, u.email, c.course_code, c.course_name FROM tutor_courses tc JOIN users u ON u.id = tc.tutor_id JOIN courses c ON c.id = tc.course_id WHERE tc.rate_status = 'PENDING' AND tc.proposed_rate IS NOT NULL ORDER BY tc.created_at, u.full_name");
$pageTitle = 'Tutor Rate Requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><h1 class="page-heading h2">Tutor rate requests</h1><p class="text-muted mb-0">Review proposed hourly rates before they appear to learners.</p></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Tutor</th><th>Subject</th><th>Proposed hourly rate</th><th>Decision</th></tr></thead><tbody>
<?php if ($rates->num_rows === 0): ?><tr><td colspan="4" class="text-muted">No pending rate requests.</td></tr><?php endif; ?>
<?php while ($rate = $rates->fetch_assoc()): ?><tr><td><?= e($rate['full_name']) ?><br><span class="small text-muted"><?= e($rate['email']) ?></span></td><td><?= e($rate['course_code'] . ' · ' . $rate['course_name']) ?></td><td>PHP <?= e(number_format((float) $rate['proposed_rate'], 2)) ?> / hour</td><td><div class="d-flex gap-2"><form method="post"><?= csrf_field() ?><input type="hidden" name="tutor_id" value="<?= (int) $rate['tutor_id'] ?>"><input type="hidden" name="course_id" value="<?= (int) $rate['course_id'] ?>"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="tutor_id" value="<?= (int) $rate['tutor_id'] ?>"><input type="hidden" name="course_id" value="<?= (int) $rate['course_id'] ?>"><button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button></form></div></td></tr><?php endwhile; ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
