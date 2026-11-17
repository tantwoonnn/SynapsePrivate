<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/supabase_auth.php';
$admin = require_role('admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? 'user');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['user', 'staff', 'admin'], true)) {
                throw new RuntimeException('Enter a name, valid email, role, and password of at least 8 characters.');
            }
            $authUser = supabase_auth_admin_create_user($email, $password, $name);
            $authUserId = (string) ($authUser['id'] ?? '');
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
                $stmt = db()->prepare('UPDATE users SET auth_user_id = ?, role = ?, is_verified = 1, verification_token = NULL, token_expires_at = NULL WHERE id = ? AND auth_user_id IS NULL');
                $stmt->bind_param('ssi', $authUserId, $role, $userId);
            } else {
                $stmt = db()->prepare('INSERT INTO users (auth_user_id, full_name, email, password_hash, role, is_verified) VALUES (?, ?, ?, NULL, ?, 1)');
                $stmt->bind_param('ssss', $authUserId, $name, $email, $role);
            }
            $stmt->execute();
            $stmt->close();
            log_activity((int) $admin['id'], 'USER_CREATED', 'Created ' . $email . ' with role ' . $role);
            flash('success', 'Account created and confirmed by the administrator. Share the temporary password with the user securely.');
            redirect('admin/users.php');
        }
        if ($action === 'role') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $role = (string) ($_POST['role'] ?? '');
            if (!$id || !in_array($role, ['user', 'staff', 'admin'], true)) throw new RuntimeException('Choose a valid account and role.');
            if ((int) $id === (int) $admin['id']) throw new RuntimeException('You cannot change your own role.');
            $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->bind_param('si', $role, $id);
            $stmt->execute();
            $stmt->close();
            log_activity((int) $admin['id'], 'USER_ROLE_CHANGED', 'Changed account #' . $id . ' to ' . $role);
            flash('success', 'Account role updated.');
            redirect('admin/users.php');
        }
        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || (int) $id === (int) $admin['id']) throw new RuntimeException('You cannot delete your own account.');
            $lookup = db()->prepare('SELECT auth_user_id FROM users WHERE id = ?');
            $lookup->bind_param('i', $id);
            $lookup->execute();
            $profile = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if ($profile === false) throw new RuntimeException('Account not found.');
            if (!empty($profile['auth_user_id'])) supabase_auth_admin_delete_user((string) $profile['auth_user_id']);
            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            log_activity((int) $admin['id'], 'USER_DELETED', 'Deleted account #' . $id);
            flash('success', 'Account deleted. Related bookings for this account are removed by the database.');
            redirect('admin/users.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'Could not save the account. Check whether the email is already in use.';
    } catch (Throwable $exception) {
        error_log('Synapse admin account creation error: ' . $exception->getMessage());
        $error = 'Could not complete the account action. Check Supabase Auth configuration and try again.';
    }
}

$users = db()->query('SELECT id, full_name, email, role, is_verified, created_at FROM users ORDER BY id DESC');
$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><h1 class="page-heading h2">User management</h1><p class="text-muted mb-0">Create accounts, change roles, or remove accounts.</p></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Add an account</h2><form method="post" class="row g-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="col-md-3"><label class="form-label">Full name</label><input class="form-control" name="full_name" maxlength="100" required></div>
    <div class="col-md-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" maxlength="150" required></div>
    <div class="col-md-3"><label class="form-label">Temporary password</label><input class="form-control" type="password" name="password" minlength="8" required></div>
    <div class="col-md-2"><label class="form-label">Role</label><select class="form-select" name="role"><option value="user">Learner</option><option value="staff">Tutor / staff</option><option value="admin">Admin</option></select></div>
    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Add</button></div>
</form></div></div>
<div class="card"><div class="card-body"><h2 class="h5">Accounts</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Email status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
<?php while ($row = $users->fetch_assoc()): ?><tr><td><?= e($row['full_name']) ?></td><td><?= e($row['email']) ?></td><td>
    <?php if ((int) $row['id'] === (int) $admin['id']): ?><?= e(ucfirst($row['role'])) ?> <span class="text-muted small">(you)</span>
    <?php else: ?><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><select class="form-select form-select-sm" name="role"><option value="user" <?= $row['role'] === 'user' ? 'selected' : '' ?>>Learner</option><option value="staff" <?= $row['role'] === 'staff' ? 'selected' : '' ?>>Tutor / staff</option><option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select><button class="btn btn-sm btn-outline-primary" name="action_button" value="save">Save</button></form><?php endif; ?>
    </td><td><?= (int) $row['is_verified'] === 1 ? 'Verified' : 'Pending' ?></td><td><?= e(format_date(substr($row['created_at'], 0, 10))) ?></td><td><?php if ((int) $row['id'] !== (int) $admin['id']): ?><form method="post" data-confirm="Delete this account and its linked bookings?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form><?php else: ?><span class="text-muted small">Protected</span><?php endif; ?></td></tr><?php endwhile; ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
