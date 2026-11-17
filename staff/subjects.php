<?php
require_once __DIR__ . '/../includes/functions.php';
$tutor = require_role('staff');
$error = '';
$inTransaction = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submitted = $_POST['course_ids'] ?? [];
    if (!is_array($submitted)) $submitted = [];
    $courseIds = array_values(array_unique(array_filter(array_map(
        static fn($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
        $submitted
    ))));
    $rates = $_POST['rates'] ?? [];
    if (!is_array($rates)) $rates = [];
    foreach ($courseIds as $courseId) {
        $rawRate = trim((string) ($rates[$courseId] ?? ''));
        if ($rawRate !== '' && (!is_numeric($rawRate) || (float) $rawRate < 1 || (float) $rawRate > 100000)) {
            $error = 'Enter an hourly rate between PHP 1 and PHP 100,000 for each selected subject.';
            break;
        }
        if ($rawRate === '') {
            $error = 'Enter a proposed hourly rate for each selected subject.';
            break;
        }
    }

    try {
        if ($error !== '') throw new RuntimeException($error);
        if ($courseIds) {
            $check = db()->prepare('SELECT id FROM courses WHERE id = ?');
            foreach ($courseIds as $courseId) {
                $check->bind_param('i', $courseId);
                $check->execute();
                if (!$check->get_result()->fetch_assoc()) throw new RuntimeException('One or more selected subjects are no longer available.');
            }
            $check->close();
        }

        db()->begin_transaction();
        $inTransaction = true;
        $delete = db()->prepare('DELETE FROM tutor_courses WHERE tutor_id = ? AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.tutor_id = tutor_courses.tutor_id AND a.course_id = tutor_courses.course_id)');
        $delete->bind_param('i', $tutor['id']);
        $delete->execute();
        $delete->close();
        if ($courseIds) {
            $insert = db()->prepare("INSERT INTO tutor_courses (tutor_id, course_id, proposed_rate, rate_status) VALUES (?, ?, ?, 'PENDING') ON CONFLICT (tutor_id, course_id) DO UPDATE SET proposed_rate = EXCLUDED.proposed_rate, rate_status = 'PENDING', rate_reviewed_by = NULL, rate_reviewed_at = NULL");
            foreach ($courseIds as $courseId) {
                $rate = number_format((float) $rates[$courseId], 2, '.', '');
                $insert->bind_param('iis', $tutor['id'], $courseId, $rate);
                $insert->execute();
            }
            $insert->close();
        }
        db()->commit();
        $inTransaction = false;
        log_activity((int) $tutor['id'], 'TUTOR_SUBJECTS_UPDATED', 'Updated teaching subjects');
        flash('success', 'Your subjects and rate proposals were saved and sent for administrator approval.');
        redirect('staff/subjects.php');
    } catch (RuntimeException $exception) {
        if ($inTransaction) db()->rollback();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($inTransaction) db()->rollback();
        error_log('Tutor subject update failed: ' . $exception->getMessage());
        $error = 'Your teaching subjects could not be saved. Please try again.';
    }
}

$selected = [];
$stmt = db()->prepare('SELECT course_id, proposed_rate, rate_status FROM tutor_courses WHERE tutor_id = ?');
$stmt->bind_param('i', $tutor['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $selected[(int) $row['course_id']] = $row;
$stmt->close();
$courses = db()->query('SELECT id, course_code, course_name, department FROM courses ORDER BY course_code');
$pageTitle = 'My Teaching Subjects';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><h1 class="page-heading h2 mb-1">My teaching subjects</h1><p class="text-muted mb-0">Choose subjects and propose your hourly rate. An administrator must approve a rate before learners can book it.</p></div><form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary" type="submit">Log out</button></form></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($courses->num_rows === 0): ?><div class="alert alert-info">There are no subjects in the catalog yet. Ask an administrator to add subjects.</div><?php else: ?><form method="post" class="card"><div class="card-body p-4"><input type="hidden" name="rate_form" value="1"><?= csrf_field() ?><div class="row g-3"><?php while ($course = $courses->fetch_assoc()): $courseId=(int)$course['id']; $link=$selected[$courseId]??null; ?><div class="col-md-6"><div class="border rounded p-3 h-100"><label class="form-check d-flex gap-2 align-items-start mb-2"><input class="form-check-input ms-0 mt-1" type="checkbox" name="course_ids[]" value="<?= $courseId ?>" <?= $link ? 'checked' : '' ?>><span><strong><?= e($course['course_code'] . ' · ' . $course['course_name']) ?></strong><span class="d-block small text-muted"><?= e($course['department']) ?></span></span></label><label class="form-label small mb-1" for="rate-<?= $courseId ?>">Proposed rate per hour (PHP)</label><input class="form-control" id="rate-<?= $courseId ?>" type="number" name="rates[<?= $courseId ?>]" min="1" max="100000" step="0.01" value="<?= e($link['proposed_rate'] ?? '') ?>" placeholder="e.g. 250"><div class="small text-muted mt-1">Status: <?= e($link['rate_status'] ?? 'Not submitted') ?></div></div></div><?php endwhile; ?></div><button class="btn btn-primary mt-4" type="submit">Save subjects and rates</button></div></form><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
