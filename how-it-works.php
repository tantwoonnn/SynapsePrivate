<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'How It Works';
require __DIR__ . '/includes/header.php';
?>
<section class="hero rounded-4 p-4 p-md-5 mb-5 text-white">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <p class="text-uppercase fw-semibold small opacity-75 mb-2">A simple way to learn together</p>
            <h1 class="display-5 fw-bold">How Synapse works</h1>
            <p class="lead mb-0">Find a peer tutor for the subject you need, agree on a time, and learn together on campus.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <a href="<?= e(url('register.php')) ?>" class="btn btn-light btn-lg">Get started <i class="bi bi-arrow-right ms-1"></i></a>
        </div>
    </div>
</section>

<section class="mb-5" aria-labelledby="learn-heading">
    <div class="mb-4">
        <p class="text-uppercase text-primary fw-semibold small mb-1">For learners</p>
        <h2 class="page-heading h3" id="learn-heading">From finding a tutor to sharing feedback</h2>
        <p class="text-muted mb-0">Your session request and its progress are available in your learner dashboard.</p>
    </div>
    <div class="row g-3">
        <?php foreach ([
            ['bi-search', 'Browse tutors', 'Explore peer tutors and the subjects they teach. Choose the tutor who fits what you want to learn.'],
            ['bi-calendar-plus', 'Request a subject and time', 'Choose one of that tutor’s approved subjects, select a date and a start and end time within their availability, and add a meeting place and any helpful notes.'],
            ['bi-hourglass-split', 'Wait for tutor approval', 'Your request is sent to the tutor for review. You can follow its status in My Sessions while the tutor approves or declines it.'],
            ['bi-people', 'Meet for your session', 'Once approved, meet at the agreed place and time for your tutoring session.'],
            ['bi-check2-circle', 'Confirm completion', 'After the tutor marks the session complete, confirm it in My Sessions. If something is wrong, you can report a problem for review.'],
            ['bi-star', 'Leave a review', 'Share a rating and review after the session so other learners can learn from your experience.'],
        ] as $index => [$icon, $title, $description]): ?>
            <div class="col-md-6 col-lg-4">
                <article class="card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="brand-mark"><i class="bi <?= e($icon) ?>"></i></span>
                            <span class="small text-muted fw-semibold">STEP <?= (int) $index + 1 ?></span>
                        </div>
                        <h3 class="h5"><?= e($title) ?></h3>
                        <p class="text-muted mb-0"><?= e($description) ?></p>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-5" aria-labelledby="tutor-heading">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-4">
            <div class="h-100 p-4 p-lg-5 rounded-4 text-white" style="background:linear-gradient(135deg,#3730a3,#7c3aed)">
                <p class="text-uppercase fw-semibold small opacity-75 mb-2">For future peer tutors</p>
                <h2 class="h3 fw-bold" id="tutor-heading">Share what you know</h2>
                <p class="mb-0">Help fellow learners build confidence in subjects you have studied and understand well.</p>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body p-4 p-lg-5">
                    <ol class="list-unstyled mb-0">
                        <li class="d-flex gap-3 mb-4">
                            <span class="brand-mark flex-shrink-0"><i class="bi bi-file-earmark-person"></i></span>
                            <div><h3 class="h5">Apply as a tutor</h3><p class="text-muted mb-0">From your learner account, submit your academic program, the subjects you can tutor, relevant experience, and why you want to tutor. You may also include a supporting file.</p></div>
                        </li>
                        <li class="d-flex gap-3 mb-4">
                            <span class="brand-mark flex-shrink-0"><i class="bi bi-person-check"></i></span>
                            <div><h3 class="h5">Wait for administrator review</h3><p class="text-muted mb-0">An administrator reviews your application and decides whether to approve it. You’ll see the application status in your dashboard.</p></div>
                        </li>
                        <li class="d-flex gap-3 mb-4">
                            <span class="brand-mark flex-shrink-0"><i class="bi bi-calendar-week"></i></span>
                            <div><h3 class="h5">Set your availability</h3><p class="text-muted mb-0">After approval, add the recurring days and times when learners can request sessions.</p></div>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="brand-mark flex-shrink-0"><i class="bi bi-journal-check"></i></span>
                            <div><h3 class="h5">Submit subjects and rates</h3><p class="text-muted mb-0">Choose the subjects you can teach and propose an hourly rate for each. An administrator must approve subjects and rates before learners can book them.</p></div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="card border-0 mb-3">
    <div class="card-body p-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div><h2 class="h5 mb-1">Ready to get started?</h2><p class="text-muted mb-0">Create an account to find a tutor or apply to become one.</p></div>
        <a href="<?= e(url('register.php')) ?>" class="btn btn-primary">Create an account</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
