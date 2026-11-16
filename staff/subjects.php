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

    try {
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
        $delete = db()->prepare('DELETE FROM tutor_courses WHERE tutor_id = ?');
        $delete->bind_param('i', $tutor['id']);
        $delete->execute();
        $delete->close();
        if ($courseIds) {
            $insert = db()->prepare('INSERT INTO tutor_courses (tutor_id, course_id) VALUES (?, ?)');
            foreach ($courseIds as $courseId) {
                $insert->bind_param('ii', $tutor['id'], $courseId);
                $insert->execute();
            }
            $insert->close();
        }
        db()->commit();
        $inTransaction = false;
        log_activity((int) $tutor['id'], 'TUTOR_SUBJECTS_UPDATED', 'Updated teaching subjects');
        flash('success', 'Your teaching subjects were updated.');
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
$stmt = db()->prepare('SELECT course_id FROM tutor_courses WHERE tutor_id = ?');
$stmt->bind_param('i', $tutor['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $selected[] = (int) $row['course_id'];
$stmt->close();
$courses = db()->query('SELECT id, course_code, course_name, department FROM courses ORDER BY course_code');
$pageTitle = 'My Teaching Subjects';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><h1 class="page-heading h2 mb-1">My teaching subjects</h1><p class="text-muted mb-0">Choose the catalog subjects you can tutor. Learners will see these on your tutor profile.</p></div><form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary" type="submit">Log out</button></form></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($courses->num_rows === 0): ?><div class="alert alert-info">There are no subjects in the catalog yet. Ask an administrator to add subjects.</div><?php else: ?><form method="post" class="card"><div class="card-body p-4"><?= csrf_field() ?><div class="row g-2"><?php while ($course = $courses->fetch_assoc()): ?><div class="col-md-6"><label class="form-check border rounded p-3 d-flex gap-2 align-items-start"><input class="form-check-input ms-0 mt-1" type="checkbox" name="course_ids[]" value="<?= (int) $course['id'] ?>" <?= in_array((int) $course['id'], $selected, true) ? 'checked' : '' ?>><span><strong><?= e($course['course_code'] . ' · ' . $course['course_name']) ?></strong><span class="d-block small text-muted"><?= e($course['department']) ?></span></span></label></div><?php endwhile; ?></div><button class="btn btn-primary mt-4" type="submit">Save subjects</button></div></form><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
