<?php
require_once __DIR__ . '/config.php';
require_login();

$pageTitle = 'Dashboard';
$userId = (int)$_SESSION['user_id'];

$recentActivity = [];
$recentFailures = 0;
$lastLogin = $_SESSION['previous_login'] ?? null;
$lastLoginKnown = array_key_exists('previous_login_known', $_SESSION) ? (bool)$_SESSION['previous_login_known'] : ($lastLogin !== null);
try {
    $stmt = db()->prepare(
        'SELECT event_type, details, ip_address, created_at
         FROM activity_log
         WHERE user_id = :uid
         ORDER BY id DESC
         LIMIT 10'
    );
    $stmt->execute([':uid' => $userId]);
    $recentActivity = $stmt->fetchAll();

    $userStmt = db()->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $userId]);
    $account = $userStmt->fetch() ?: [];
    $identifiers = array_values(array_unique(array_filter([
        (string)($_SESSION['username'] ?? ''),
        (string)($account['username'] ?? ''),
        (string)($account['email'] ?? ''),
    ], static fn($v) => $v !== '')));

    if ($identifiers) {
        $placeholders = [];
        $params = [];
        foreach ($identifiers as $i => $identifier) {
            $key = ':u' . $i;
            $placeholders[] = $key;
            $params[$key] = $identifier;
        }
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE succeeded = 0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)
               AND username IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $recentFailures = (int)($stmt->fetch()['n'] ?? 0);
    }
} catch (Throwable $e) {
    // Dashboard should still render if new tables are unavailable.
    error_log('dashboard activity query failed: ' . $e->getMessage());
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3">Welcome, <?= e($_SESSION['username']) ?></h1>
<p class="text-muted mb-1">You are logged in. Server time: <?= e(date('Y-m-d H:i:s')) ?>, host: <?= e(gethostname() ?: 'unknown') ?>.</p>
<p class="text-muted">
    Last successful login:
    <?php if (is_array($lastLogin) && !empty($lastLogin['created_at'])): ?>
        <strong><?= e((string)$lastLogin['created_at']) ?></strong> from <code><?= e((string)($lastLogin['ip_address'] ?? 'unknown')) ?></code>
    <?php elseif ($lastLoginKnown): ?>
        <span>This is your first recorded successful login.</span>
    <?php else: ?>
        <span class="text-muted">Previous login information is unavailable.</span>
    <?php endif; ?>
</p>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card card-body shadow-sm h-100">
            <h2 class="h5">Security highlights</h2>
            <ul class="mb-0">
                <li>Passwords stored as secure hashes (<code>PASSWORD_DEFAULT</code>).</li>
                <li>CSRF protection on state-changing forms and POST-only logout.</li>
                <li>Login throttling enabled (failed attempts in last 15 min for your account: <strong><?= $recentFailures ?></strong>).</li>
                <li>Session cookies use HttpOnly and SameSite protections.</li>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-body shadow-sm h-100">
            <h2 class="h5">Quick actions</h2>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="feedback.php">Feedback form</a>
                <a class="btn btn-outline-dark" href="serverless_demo.php">Serverless demo</a>
            </div>
            <p class="text-muted small mt-3 mb-0">The serverless demo showcases the project's simulated AWS Lambda feedback handler.</p>
        </div>
    </div>
</div>

<div class="card card-body shadow-sm">
    <h2 class="h5">Recent account & security activity</h2>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Time</th><th>Event</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><code><?= e($row['event_type']) ?></code></td>
                    <td><?= e($row['details'] ?? '') ?></td>
                    <td><?= e($row['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentActivity): ?>
                <tr><td colspan="4" class="text-muted">No activity recorded yet. New events will appear here after login, logout, signup, or feedback actions.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/layout_footer.php'; ?>
