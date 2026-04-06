<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('super_admin');

$pdo = Database::getConnection();
$hackathonsStmt = $pdo->prepare('SELECT id, name, status FROM hackathons ORDER BY starts_at DESC, id DESC');
$hackathonsStmt->execute();
$hackathons = $hackathonsStmt->fetchAll();
$assignedHackathonColumnStmt = $pdo->prepare(
    "SELECT COUNT(*) AS c
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'assigned_hackathon_id'"
);
$assignedHackathonColumnStmt->execute();
$hasAssignedHackathonColumn = ((int) (($assignedHackathonColumnStmt->fetch()['c'] ?? 0))) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/super-admin/users.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = trim((string) ($_POST['role'] ?? ''));
        $assignedHackathonId = filter_input(INPUT_POST, 'assigned_hackathon_id', FILTER_VALIDATE_INT);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'jury', 'staff'], true)) {
            flash('error', 'Enter a valid name, email, and role.');
            redirect('portal/super-admin/users.php');
        }

        if ($assignedHackathonId === false || $assignedHackathonId === null) {
            flash('error', 'Select the hackathon this user belongs to.');
            redirect('portal/super-admin/users.php');
        }

        $hackathonCheckStmt = $pdo->prepare('SELECT id FROM hackathons WHERE id = ? LIMIT 1');
        $hackathonCheckStmt->execute([$assignedHackathonId]);
        if ($hackathonCheckStmt->fetch() === false) {
            flash('error', 'Select a valid hackathon for this user.');
            redirect('portal/super-admin/users.php');
        }

        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch() !== false) {
            flash('error', 'A user with that email already exists.');
            redirect('portal/super-admin/users.php');
        }

        $temporaryPassword = generateTemporaryPassword();
        if ($hasAssignedHackathonColumn) {
            $insertStmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, assigned_hackathon_id, is_active, login_attempts, locked_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $name,
                $email,
                password_hash($temporaryPassword, PASSWORD_ARGON2ID),
                $role,
                $assignedHackathonId,
                1,
                0,
                null,
            ]);
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_active, login_attempts, locked_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $name,
                $email,
                password_hash($temporaryPassword, PASSWORD_ARGON2ID),
                $role,
                1,
                0,
                null,
            ]);
        }

        $userId = (int) $pdo->lastInsertId();
        $sent = sendUserAccountEmail($email, $name, $temporaryPassword);
        logActivity('user_created', 'user', $userId, [
            'role' => $role,
            'email_sent' => $sent,
            'assigned_hackathon_id' => $assignedHackathonId,
        ], $assignedHackathonId);

        if ($sent) {
            flash('success', 'User created and login email sent.');
        } else {
            flash('warning', 'User created, but the email failed. Temporary password: ' . $temporaryPassword);
        }

        redirect('portal/super-admin/users.php');
    }

    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null) {
        flash('error', 'Invalid user selected.');
        redirect('portal/super-admin/users.php');
    }

    if ($action === 'reset_password') {
        $tempPassword = generateTemporaryPassword();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL WHERE id = ?');
        $stmt->execute([password_hash($tempPassword, PASSWORD_ARGON2ID), $userId]);

        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        $sent = $user !== false ? sendUserAccountEmail((string) $user['email'], (string) $user['name'], $tempPassword) : false;
        logActivity('user_password_reset', 'user', $userId, ['email_sent' => $sent], null);

        if ($sent) {
            flash('success', 'Temporary password reset and emailed.');
        } else {
            flash('warning', 'Password reset, but email failed. Temporary password: ' . $tempPassword);
        }

        redirect('portal/super-admin/users.php');
    }

    if ($action === 'toggle_active') {
        $targetState = isset($_POST['target_state']) && $_POST['target_state'] === '1' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$targetState, $userId]);
        logActivity($targetState === 1 ? 'user_activated' : 'user_deactivated', 'user', $userId, null, null);
        flash('success', $targetState === 1 ? 'User account activated.' : 'User account deactivated.');
        redirect('portal/super-admin/users.php');
    }
}

$usersSql = $hasAssignedHackathonColumn
    ? 'SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.assigned_hackathon_id,
            u.is_active,
            u.created_at,
            h.name AS assigned_hackathon_name
         FROM users u
         LEFT JOIN hackathons h ON h.id = u.assigned_hackathon_id
         WHERE u.role IN (?, ?, ?)
         ORDER BY FIELD(u.role, "admin", "jury", "staff"), u.name ASC'
    : 'SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            NULL AS assigned_hackathon_id,
            u.is_active,
            u.created_at,
            "Hackathon 1 (pending migration)" AS assigned_hackathon_name
         FROM users u
         WHERE u.role IN (?, ?, ?)
         ORDER BY FIELD(u.role, "admin", "jury", "staff"), u.name ASC';
$usersStmt = $pdo->prepare($usersSql);
$usersStmt->execute(['admin', 'jury', 'staff']);
$users = $usersStmt->fetchAll();

$pageTitle = 'Users';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Users</h1>
        <p class="page-subtitle">Create and manage admin, jury, and staff accounts platform-wide.</p>
    </div>
</section>

<section class="grid grid-3">
    <article class="card">
        <h2>Create User</h2>
        <p class="page-subtitle" style="margin:10px 0 16px;">A temporary password is generated automatically, and you now assign the user to a specific hackathon here.</p>
        <form method="post" action="<?= e(appPath('portal/super-admin/users.php')) ?>">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="form-group">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required>
            </div>
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="admin">Admin</option>
                    <option value="jury">Jury</option>
                    <option value="staff">Staff</option>
                </select>
            </div>
            <div class="form-group">
                <label for="assigned_hackathon_id">Assigned Hackathon</label>
                <select id="assigned_hackathon_id" name="assigned_hackathon_id" required>
                    <option value="">Select Hackathon</option>
                    <?php foreach ($hackathons as $hackathon): ?>
                        <option value="<?= e((string) $hackathon['id']) ?>">
                            <?= e((string) $hackathon['name']) ?> (<?= e(ucwords(str_replace('_', ' ', (string) $hackathon['status']))) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary">Create User</button>
        </form>
    </article>

    <article class="card" style="grid-column: span 2;">
        <h2>All User Accounts</h2>
        <div class="table-shell" style="margin-top:16px;">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Assigned Hackathon</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="6">No admin, jury, or staff accounts exist yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e((string) $user['name']) ?><br><span class="page-subtitle"><?= e(formatUtcToIst((string) $user['created_at'])) ?></span></td>
                            <td><?= e((string) $user['email']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) $user['role']))) ?></td>
                            <td><span class="badge <?= (int) $user['is_active'] === 1 ? 'badge-success' : 'badge-danger' ?>"><?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= e((string) ($user['assigned_hackathon_name'] ?? 'Not assigned yet')) ?></td>
                            <td>
                                <form method="post" action="<?= e(appPath('portal/super-admin/users.php')) ?>">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>">
                                    <button type="submit" class="btn-ghost">Reset Password</button>
                                </form>
                                <form method="post" action="<?= e(appPath('portal/super-admin/users.php')) ?>" style="margin-top:8px;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>">
                                    <input type="hidden" name="target_state" value="<?= (int) $user['is_active'] === 1 ? '0' : '1' ?>">
                                    <button type="submit" class="btn-ghost"><?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
