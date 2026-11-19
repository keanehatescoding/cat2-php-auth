<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Sign up';
$errors = [];
$success = false;
$username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

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
        <div class="progress mt-2" style="height: 8px;" aria-hidden="true">
            <div id="pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
        </div>
        <div id="pw-strength-text" class="small mt-1 text-muted">Password strength: &mdash;</div>
    </div>
    <button class="btn btn-primary" type="submit">Sign up</button>
</form>
<script>
(function () {
    var input = document.getElementById('password');
    var bar = document.getElementById('pw-strength-bar');
    var text = document.getElementById('pw-strength-text');
    if (!input || !bar || !text) return;

    function score(pw) {
        var s = 0;
        if (!pw) return 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
        if (/\d/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return Math.min(s, 5);
    }

    function render() {
        var s = score(input.value);
        var labels = ['—', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
        var classes = ['bg-secondary', 'bg-danger', 'bg-warning', 'bg-info', 'bg-primary', 'bg-success'];
        var widths = ['0%', '20%', '40%', '60%', '80%', '100%'];
        bar.style.width = widths[s];
        bar.className = 'progress-bar ' + classes[s];
        text.textContent = 'Password strength: ' + labels[s];
        text.className = 'small mt-1 ' + (s <= 1 ? 'text-danger' : s <= 3 ? 'text-muted' : 'text-success');
    }

    input.addEventListener('input', render);
    render();
})();
</script>
<?php require __DIR__ . '/layout_footer.php'; ?>
