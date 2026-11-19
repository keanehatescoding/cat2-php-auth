<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Login';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $login    = trim($_POST['login'] ?? '');   // username or email
        $password = $_POST['password'] ?? '';

        [$blocked, $windowMinutes] = login_throttled($login !== '' ? $login : client_ip());
        if ($blocked) {
            $error = "Too many failed login attempts. Please try again in about {$windowMinutes} minutes.";
            log_activity('login_blocked', 'throttled login attempt for ' . $login);
        } else {
            $stmt = db()->prepare(
                'SELECT id, username, password_hash FROM users WHERE username = :name OR email = :email LIMIT 1'
            );
            // Native prepares need a distinct placeholder for each occurrence
            $stmt->execute([':name' => $login, ':email' => $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                record_login_attempt($login, true);
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                log_activity('login_success', 'user logged in', (int)$user['id']);
                header('Location: dashboard.php');
                exit;
            }

            record_login_attempt($login, false);
            log_activity('login_failure', 'failed login for ' . $login);
            $error = 'Invalid username/email or password.';
        }
    }
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Log in</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="login">Username or email</label>
        <input class="form-control" id="login" name="login" required autocomplete="username">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button class="btn btn-success" type="submit">Log in</button>
    <p class="text-muted small mt-3 mb-0">Security: repeated failed logins are temporarily throttled and recorded in the activity log.</p>
</form>
<?php require __DIR__ . '/layout_footer.php'; ?>
