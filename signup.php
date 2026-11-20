<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Sign up';
$errors = [];
$success = false;
$username = $email = '';
$loadTurnstileScript = captcha_enabled_for('signup');

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = 'Invalid form submission. Please try again.';
        log_activity('signup_honeypot', 'honeypot triggered');
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = normalize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        [$captchaOk, $captchaReason] = captcha_verify_request('signup');
        if (!$captchaOk) {
            $errors[] = 'Please complete the anti-abuse verification and try again.';
            log_activity('signup_captcha_failed', 'captcha verification failed: ' . $captchaReason);
        }

        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters: letters, numbers or underscore.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must include at least one letter and one number.';
        }

        $pdo = db();
        $formLock = acquire_form_ip_lock($pdo);
        try {
            if (recent_event_count('signup_attempt', 60, null, true) >= 5 || recent_event_count('signup_throttled', 60, null, true) >= 10) {
                $errors[] = 'Too many signup attempts from this network. Please try again later.';
                log_activity('signup_throttled', 'signup throttled');
            } elseif (!$errors) {
                // Count admitted attempts before hashing/insertion so duplicates also consume quota.
                log_activity('signup_attempt', 'signup attempt for ' . $username);
            }
        } finally {
            release_form_ip_lock($pdo, $formLock);
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :h)'
                );
                $stmt->execute([
                    ':u' => $username,
                    ':e' => $email,
                    ':h' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $newId = (int)db()->lastInsertId();
                log_activity('signup', 'new account created: ' . $username, $newId);
                $success = true;
                $username = $email = '';
            } catch (PDOException $ex) {
                // 23000 = integrity constraint violation (duplicate username/email)
                if ($ex->getCode() !== '23000') {
                    error_log('signup insert failed: ' . $ex->getMessage());
                }
                $errors[] = $ex->getCode() === '23000'
                    ? 'That username or email is already registered.'
                    : 'Could not create account. Please try again later.';
            }
        }
    }
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Create an account</h1>

<?php if ($success): ?>
    <div class="alert alert-success">Account created. <a href="login.php">Log in now</a>.</div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div class="d-none" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>
    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password (min 8 characters)</label>
        <input class="form-control" type="password" id="password" name="password" required aria-describedby="pw-help pw-strength-text">
        <div id="pw-help" class="form-text">Use at least 8 characters including a letter and a number.</div>
        <div class="progress mt-2 pw-progress" aria-hidden="true">
            <div id="pw-strength-bar" class="progress-bar pw-strength-bar" role="progressbar"></div>
        </div>
        <div id="pw-strength-text" class="small mt-1 text-muted">Password strength: &mdash;</div>
    </div>
    <?= captcha_widget_html('signup') ?>
    <button class="btn btn-primary" type="submit">Sign up</button>
</form>
<script src="password-meter.js" defer></script>
<?php require __DIR__ . '/layout_footer.php'; ?>
