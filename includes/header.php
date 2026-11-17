<?php
$loggedInUser = current_user();
$pageTitle = $pageTitle ?? 'Synapse';
$flashMessage = get_flash();
$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$nav = [];
if ($loggedInUser) {
    if ($loggedInUser['role'] === 'admin') {
        $nav = ['admin/dashboard.php' => 'Dashboard', 'admin/users.php' => 'Users', 'admin/tutor_applications.php' => 'Tutor Applications', 'admin/tutor_rates.php' => 'Tutor Rates', 'admin/courses.php' => 'Courses', 'admin/appointments.php' => 'Appointments', 'admin/activity_logs.php' => 'Activity Logs', 'admin/reports.php' => 'Reports'];
    } elseif ($loggedInUser['role'] === 'staff') {
        $nav = ['staff/dashboard.php' => 'Approval Queue', 'staff/subjects.php' => 'My Subjects', 'staff/schedule.php' => 'My Availability'];
    } else {
        $nav = ['user/dashboard.php' => 'My Sessions', 'user/tutors.php' => 'Find a Tutor', 'user/book_appointment.php' => 'Book Session', 'user/tutor_application.php' => 'Apply as Tutor'];
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · Synapse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
    <?php if (!empty($includeLeafletAssets)): ?><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous"><?php endif; ?>
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-light no-print">
    <div class="container">
        <a class="navbar-brand fw-bold text-dark" href="<?= e(url($loggedInUser ? dashboard_for($loggedInUser['role']) : 'index.php')) ?>"><span class="brand-mark"><i class="bi bi-diagram-3-fill"></i></span> Synapse</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($nav as $path => $label): ?><li class="nav-item"><a class="nav-link <?= str_ends_with($currentPath, $path) ? 'active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center gap-lg-2">
                <?php if ($loggedInUser): ?>
                    <li class="nav-item"><span class="user-chip"><span class="avatar"><?= e(strtoupper(mb_substr($loggedInUser['full_name'], 0, 1))) ?></span><?= e($loggedInUser['full_name']) ?> <span class="role-pill"><?= e($loggedInUser['role'] === 'staff' ? 'Tutor' : ($loggedInUser['role'] === 'admin' ? 'Admin' : 'Learner')) ?></span></span></li>
                    <li class="nav-item"><form method="post" action="<?= e(url('logout.php')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm mt-2 mt-lg-0" type="submit">Log out</button></form></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link <?= str_ends_with($currentPath, 'login.php') ? 'active' : '' ?>" href="<?= e(url('login.php')) ?>">Log in</a></li>
                    <li class="nav-item"><a class="nav-link <?= str_ends_with($currentPath, 'register.php') ? 'active' : '' ?>" href="<?= e(url('register.php')) ?>">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container py-4 flex-grow-1">
    <?php if ($flashMessage): ?><div class="alert alert-<?= e(in_array($flashMessage['type'], ['success','warning','danger','info'], true) ? $flashMessage['type'] : 'info') ?> alert-dismissible fade show no-print" role="alert"><?= e($flashMessage['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
