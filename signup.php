<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Sign up';
$errors = [];
$success = false;
$username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $success = true;
            $username = $email = '';
        } catch (PDOException $ex) {
            // 23000 = integrity constraint violation (duplicate username/email)
            $errors[] = $ex->getCode() === '23000'
                ? 'That username or email is already registered.'
                : 'Could not create account: ' . $ex->getMessage();
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
        <input class="form-control" type="password" id="password" name="password" required>
    </div>
    <button class="btn btn-primary" type="submit">Sign up</button>
</form>
<?php require __DIR__ . '/layout_footer.php'; ?>
