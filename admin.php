<?php
require_once __DIR__ . '/config.php';
require_admin();

$pageTitle = 'Admin';
$errors = [];
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $rawAction = $_POST['action'] ?? '';
        $action = is_scalar($rawAction) ? (string)$rawAction : '';
        try {
            if ($action === 'delete_feedback') {
                $rawFeedbackId = $_POST['feedback_id'] ?? null;
                $id = (is_int($rawFeedbackId) || is_string($rawFeedbackId)) ? (int)$rawFeedbackId : 0;
                if ($id <= 0) {
                    $errors[] = 'Invalid feedback item.';
                } else {
                    $stmt = db()->prepare('DELETE FROM feedback WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    if ($stmt->rowCount() > 0) {
                        log_activity('admin_feedback_deleted', 'deleted feedback id ' . $id, (int)$_SESSION['user_id']);
                        $notices[] = 'Feedback item deleted.';
                    } else {
                        $errors[] = 'Feedback item not found.';
                    }
                }
            } elseif ($action === 'toggle_admin') {
                $rawUserId = $_POST['user_id'] ?? null;
                $uid = (is_int($rawUserId) || is_string($rawUserId)) ? (int)$rawUserId : 0;
                if ($uid <= 0) {
                    $errors[] = 'Invalid user.';
                } elseif ($uid === (int)$_SESSION['user_id']) {
                    $errors[] = 'You cannot change your own admin status from this page.';
                } else {
                    $stmt = db()->prepare('SELECT id, username, is_admin FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $uid]);
                    $user = $stmt->fetch();
                    if (!$user) {
                        $errors[] = 'User not found.';
                    } else {
                        $newVal = empty($user['is_admin']) ? 1 : 0;
                        $upd = db()->prepare('UPDATE users SET is_admin = :v WHERE id = :id');
                        $upd->execute([':v' => $newVal, ':id' => $uid]);
                        log_activity('admin_user_role_changed', 'set is_admin=' . $newVal . ' for user ' . $user['username'] . ' (id ' . $uid . ')', (int)$_SESSION['user_id']);
                        $notices[] = 'User admin status updated for ' . $user['username'] . '.';
                    }
                }
            } else {
                $errors[] = 'Unknown admin action.';
            }
        } catch (Throwable $e) {
            error_log('admin action failed: ' . $e->getMessage());
            $errors[] = 'Could not complete admin action. Please try again later.';
        }
    }
}

$users = [];
$feedbackRows = [];
$activityRows = [];
$loginRows = [];
try {
    $users = db()->query('SELECT id, username, email, is_admin, created_at FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
} catch (Throwable $e) {
    error_log('admin users query failed: ' . $e->getMessage());
    $errors[] = 'Could not load users.';
}
try {
    $feedbackRows = db()->query('SELECT id, user_id, name, message, created_at FROM feedback ORDER BY id DESC LIMIT 100')->fetchAll();
} catch (Throwable $e) {
    error_log('admin feedback query failed: ' . $e->getMessage());
    $errors[] = 'Could not load feedback.';
}
try {
    $activityRows = db()->query('SELECT id, user_id, event_type, details, ip_address, created_at FROM activity_log ORDER BY id DESC LIMIT 100')->fetchAll();
} catch (Throwable $e) {
    error_log('admin activity query failed: ' . $e->getMessage());
    $errors[] = 'Could not load activity log.';
}
try {
    $loginRows = db()->query('SELECT id, username, ip_address, succeeded, created_at FROM login_attempts ORDER BY id DESC LIMIT 100')->fetchAll();
} catch (Throwable $e) {
    error_log('admin login attempts query failed: ' . $e->getMessage());
    $errors[] = 'Could not load login attempts.';
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Admin</h1>
<p class="text-muted">Moderation and security overview for administrators.</p>

<?php foreach ($notices as $msg): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $msg): ?>
    <div class="alert alert-danger"><?= e($msg) ?></div>
<?php endforeach; ?>

<div class="card card-body shadow-sm mb-4">
    <h2 class="h5">Users (latest 100)</h2>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Admin</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= !empty($u['is_admin']) ? 'Yes' : 'No' ?></td>
                    <td><?= e($u['created_at']) ?></td>
                    <td>
                        <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                            <span class="text-muted small">Current user</span>
                        <?php else: ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_admin">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= !empty($u['is_admin']) ? 'Revoke admin' : 'Make admin' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr><td colspan="6" class="text-muted">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-body shadow-sm mb-4">
    <h2 class="h5">Feedback moderation (latest 100)</h2>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>ID</th><th>User ID</th><th>Name</th><th>Message</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($feedbackRows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= $r['user_id'] === null ? '' : (int)$r['user_id'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['message']) ?></td>
                    <td><?= e($r['created_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_feedback">
                            <input type="hidden" name="feedback_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$feedbackRows): ?>
                <tr><td colspan="6" class="text-muted">No feedback entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card card-body shadow-sm h-100">
            <h2 class="h5">Recent activity log (latest 100)</h2>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID</th><th>User</th><th>Event</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($activityRows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= $r['user_id'] === null ? '' : (int)$r['user_id'] ?></td>
                            <td><code><?= e($r['event_type']) ?></code></td>
                            <td><?= e($r['details'] ?? '') ?></td>
                            <td><?= e($r['ip_address'] ?? '') ?></td>
                            <td><?= e($r['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$activityRows): ?>
                        <tr><td colspan="6" class="text-muted">No activity recorded.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card card-body shadow-sm h-100">
            <h2 class="h5">Recent login attempts (latest 100)</h2>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID</th><th>User/ID</th><th>IP</th><th>OK</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($loginRows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= e($r['username']) ?></td>
                            <td><?= e($r['ip_address']) ?></td>
                            <td><?= !empty($r['succeeded']) ? 'Yes' : 'No' ?></td>
                            <td><?= e($r['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$loginRows): ?>
                        <tr><td colspan="5" class="text-muted">No login attempts recorded.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout_footer.php'; ?>
