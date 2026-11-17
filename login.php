<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/supabase_auth.php';
if ($user = current_user()) redirect(dashboard_for($user['role']));

$error = '';
if (isset($_GET['verified']) && $_GET['verified'] === '1') {
    flash('success', 'Email verified. You can now log in.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    try {
        $authResponse = supabase_auth_login($email, $password);
        $authUser = $authResponse['user'] ?? [];
        $authUserId = (string) ($authUser['id'] ?? '');
        $confirmedAt = $authUser['email_confirmed_at'] ?? $authUser['confirmed_at'] ?? null;
        if ($authUserId === '' || !$confirmedAt) {
            $_SESSION['pending_email'] = $email;
            flash('warning', 'Verify your email before logging in.');
            redirect('verify-email.php');
        }
        $stmt = db()->prepare('SELECT id, full_name, email, role FROM users WHERE auth_user_id = ? LIMIT 1');
        $stmt->bind_param('s', $authUserId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($account === false) throw new RuntimeException('No Synapse profile is linked to this Supabase Auth account.');

        $id = (int) $account['id'];
        $role = (string) $account['role'];
        $stmt = db()->prepare('UPDATE users SET is_verified = 1 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $id, 'full_name' => $account['full_name'], 'email' => $account['email'], 'role' => $role];
        log_activity($id, 'LOGIN', 'User logged in');
        redirect(dashboard_for($role));
    } catch (DomainException $exception) {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'not confirmed') || str_contains($message, 'not verified')) {
            $_SESSION['pending_email'] = $email;
            flash('warning', 'Verify your email before logging in.');
            redirect('verify-email.php');
        }
        $error = 'Invalid email or password.';
    } catch (Throwable $exception) {
        error_log('Synapse Supabase login error: ' . $exception->getMessage());
        $error = 'Login is temporarily unavailable. Please try again.';
    }
}

$pageTitle = 'Log In';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center py-md-4"><div class="col-md-7 col-lg-5">
    <div class="text-center mb-4"><p class="text-uppercase text-synapse fw-semibold small mb-1">Welcome back</p><h1 class="page-heading">Log in to Synapse</h1><p class="text-muted">Continue to your learner, tutor, or coordinator workspace.</p></div>
    <div class="card"><div class="card-body p-4 p-lg-5">
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('login.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control form-control-lg" id="email" type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control form-control-lg" id="password" type="password" name="password" required autocomplete="current-password"></div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Log in</button>
        </form>
    </div></div>
    <p class="text-center small mt-3">New to Synapse? <a href="<?= e(url('register.php')) ?>">Create a learner account</a></p>
    <p class="text-center small mt-2"><a href="<?= e(url('index.php')) ?>">Back to Synapse</a></p>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
