<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Login';
$error = '';
$rawNext = $_GET['next'] ?? ($_POST['next'] ?? '');
$next = is_string($rawNext) ? $rawNext : '';

if (!empty($_SESSION['user_id'])) {
    $destination = is_safe_redirect_target($next) ? $next : 'dashboard.php';
    header('Location: ' . $destination);
    exit;
}

$initialLogin = isset($_POST['login']) && is_string($_POST['login']) ? trim($_POST['login']) : '';
$challengeRequired = login_challenge_required($initialLogin !== '' ? $initialLogin : client_ip());
$challengePair = $challengeRequired ? ensure_login_challenge() : [null, null];
$loadTurnstileScript = captcha_enabled_for('login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawLogin = $_POST['login'] ?? '';
    $login    = is_string($rawLogin) ? trim($rawLogin) : '';   // username or email
    $password = $_POST['password'] ?? '';
    if (!is_string($password)) {
        $password = '';
    }
    $postedNext = $_POST['next'] ?? $next;
    $next = is_string($postedNext) ? $postedNext : '';
    $throttleKey = $login !== '' ? $login : client_ip();

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $error = 'Invalid username/email or password.';
        log_activity('login_honeypot', 'honeypot triggered for ' . $login);
    } else {
        [$captchaOk, $captchaReason] = captcha_verify_request('login');
        if (!$captchaOk) {
            $error = 'Please complete the anti-abuse verification and try again.';
            log_activity('login_captcha_failed', 'captcha verification failed: ' . $captchaReason);
            // Skip throttling/admission when captcha fails.
        } else {
        $reservation = begin_login_attempt($throttleKey);
        [$allowed, $attemptId, $windowMinutes, $locks, $pdo, $failureCountBeforeReservation] = array_pad($reservation, 6, null);

        if (!$allowed) {
            complete_login_attempt($reservation, false);
            $error = "Too many failed login attempts. Please try again in about {$windowMinutes} minutes.";
            $challengeRequired = true;
            $challengePair = rotate_login_challenge();
            log_activity('login_blocked', 'throttled login attempt for ' . $login);
        } else {
            $challengeRequired = ($failureCountBeforeReservation !== null)
                ? ($failureCountBeforeReservation >= 2)
                : login_challenge_required($throttleKey);
            if ($challengeRequired && ($challengePair[0] === null || $challengePair[1] === null)) {
                $challengePair = ensure_login_challenge();
            }

            if ($challengeRequired && !login_challenge_valid($_POST['challenge_answer'] ?? '')) {
                complete_login_attempt($reservation, false);
                $error = 'Please solve the security check and try again.';
                $challengePair = rotate_login_challenge();
                log_activity('login_challenge_failed', 'challenge failed for ' . $login);
            } else {
                $stmt = db()->prepare(
                    'SELECT id, username, password_hash, is_admin FROM users
                     WHERE username = :name OR email = :email
                     LIMIT 1'
                );
                // Native prepares need a distinct placeholder for each occurrence
                $stmt->execute([':name' => $login, ':email' => $login]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    complete_login_attempt($reservation, true);
                    $previousLogin = null;
                    $previousLoginKnown = false;
                    $isAdmin = false;
                    try {
                        $prevStmt = db()->prepare(
                            'SELECT ip_address, created_at
                             FROM activity_log
                             WHERE user_id = :uid AND event_type = :evt
                             ORDER BY id DESC
                             LIMIT 1'
                        );
                        $prevStmt->execute([':uid' => (int)$user['id'], ':evt' => 'login_success']);
                        $previousLogin = $prevStmt->fetch() ?: null;
                        $previousLoginKnown = true;
                    } catch (Throwable $e) {
                        error_log('previous login lookup failed: ' . $e->getMessage());
                    }
                    try {
                        $adminStmt = db()->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
                        $adminStmt->execute([':id' => (int)$user['id']]);
                        $adminRow = $adminStmt->fetch();
                        $isAdmin = !empty($adminRow['is_admin']);
                    } catch (Throwable $e) {
                        error_log('admin flag lookup failed: ' . $e->getMessage());
                    }

                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['user_id']  = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['previous_login'] = $previousLogin;
                    $_SESSION['previous_login_known'] = $previousLoginKnown;
                    log_activity('login_success', 'user logged in', (int)$user['id']);
                    $destination = is_safe_redirect_target($next) ? $next : 'dashboard.php';
                    header('Location: ' . $destination);
                    exit;
                }

                complete_login_attempt($reservation, false);
                $challengePair = rotate_login_challenge();
                log_activity('login_failure', 'failed login for ' . $login);
                $error = 'Invalid username/email or password.';
            }
        }
        }
    }
}

$challengeKey = isset($login) && $login !== '' ? $login : $initialLogin;
$challengeKey = $challengeKey !== '' ? $challengeKey : client_ip();
$challengeRequired = login_challenge_required($challengeKey) || $challengeRequired;
if ($challengeRequired && ($challengePair[0] === null || $challengePair[1] === null)) {
    $challengePair = ensure_login_challenge();
}

$loginValue = isset($login) ? $login : $initialLogin;

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Log in</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <?php if (is_safe_redirect_target($next)): ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php endif; ?>
    <div class="d-none" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>
    <div class="mb-3">
        <label class="form-label" for="login">Username or email</label>
        <input class="form-control" id="login" name="login" value="<?= e($loginValue) ?>" required autocomplete="username">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <?php if ($challengeRequired): ?>
    <div class="mb-3">
        <label class="form-label" for="challenge_answer">Security check: what is <?= (int)$challengePair[0] ?> + <?= (int)$challengePair[1] ?>?</label>
        <input class="form-control" id="challenge_answer" name="challenge_answer" inputmode="numeric" autocomplete="off" required>
        <div class="form-text">This extra step appears after repeated failed sign-in attempts to reduce automated abuse.</div>
    </div>
    <?php endif; ?>
    <?= captcha_widget_html('login') ?>
    <button class="btn btn-success" type="submit">Log in</button>
    <p class="text-muted small mt-3 mb-0">Security: repeated failed logins trigger an extra challenge, are throttled, and are recorded in the activity log.</p>
</form>
<?php require __DIR__ . '/layout_footer.php'; ?>
