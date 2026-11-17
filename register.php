<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/supabase_auth.php';
if ($user = current_user()) redirect(dashboard_for($user['role']));

$errors = [];
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Name must be 2 to 100 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $errors[] = 'Enter a valid email address.';
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $errors[] = 'Password must be at least 8 characters and include letters and numbers.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            $authResponse = supabase_auth_signup($email, $password, $name);
            // Supabase's signup endpoint returns the user at the response root
            // when email confirmation is enabled; tolerate a nested user too.
            $authUser = $authResponse['user'] ?? $authResponse;
            $authUserId = (string) ($authUser['id'] ?? '');
            // A previous signup may have created the Auth identity before the
            // profile insert failed. Sign in here to recover that identity ID.
            if ($authUserId === '') {
                $loginResponse = supabase_auth_login($email, $password);
                $authUserId = (string) ($loginResponse['user']['id'] ?? '');
            }
            if ($authUserId === '') throw new RuntimeException('Supabase Auth did not return a user ID.');
            $stmt = db()->prepare('SELECT id, auth_user_id FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $existingProfile = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existingProfile !== false && !empty($existingProfile['auth_user_id'])) {
                throw new RuntimeException('A Synapse profile is already linked to this email.');
            }
            if ($existingProfile !== false) {
                $userId = (int) $existingProfile['id'];
                $stmt = db()->prepare('UPDATE users SET auth_user_id = ?, is_verified = 0, verification_token = NULL, token_expires_at = NULL WHERE id = ? AND auth_user_id IS NULL');
                $stmt->bind_param('si', $authUserId, $userId);
            } else {
                $stmt = db()->prepare("INSERT INTO users (auth_user_id, full_name, email, password_hash, role, is_verified) VALUES (?, ?, ?, NULL, 'user', 0)");
                $stmt->bind_param('sss', $authUserId, $name, $email);
            }
            $stmt->execute();
            if ($existingProfile === false) $userId = (int) db()->insert_id;
            $stmt->close();
            log_activity($userId, 'REGISTER', 'Created a learner account');
            $_SESSION['pending_email'] = $email;
            flash('success', 'Account created. Supabase Auth has sent a verification email. Check your inbox before logging in.');
            redirect('verify-email.php');
        } catch (DomainException $exception) {
            $message = strtolower($exception->getMessage());
            $errors[] = str_contains($message, 'already') || str_contains($message, 'registered')
                ? 'That email address may already be registered.'
                : 'Supabase Auth could not create the account. Check the Auth configuration and try again.';
        } catch (Throwable $exception) {
            error_log('Synapse Supabase registration error: ' . $exception->getMessage());
            $errors[] = 'Account creation failed. Check the Supabase Auth and profile database configuration, then try again.';
        }
    }
}

$pageTitle = 'Create Account';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center py-md-4"><div class="col-md-7 col-lg-5"><div class="card"><div class="card-body p-4 p-lg-5">
    <h1 class="page-heading h3">Create a learner account</h1>
    <p class="text-muted">Supabase Auth will email you a confirmation link. You must confirm your address before logging in.</p>
    <?php if ($errors): ?><div class="alert alert-secondary"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?= e(url('register.php')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="100" required value="<?= e($name) ?>"></div>
        <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" maxlength="150" required value="<?= e($email) ?>"></div>
        <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" minlength="8" required autocomplete="new-password"></div>
        <div class="mb-4"><label class="form-label" for="password_confirm">Confirm password</label><input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password"></div>
        <button class="btn btn-primary w-100" type="submit">Create account</button>
    </form>
    <p class="small text-center mt-3 mb-0">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
</div></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
