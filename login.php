<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Login';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');   // username or email
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare(
        'SELECT id, username, password_hash FROM users WHERE username = :name OR email = :email LIMIT 1'
    );
    // Native prepares need a distinct placeholder for each occurrence
    $stmt->execute([':name' => $login, ':email' => $login]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid username/email or password.';
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Log in</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm">
    <div class="mb-3">
        <label class="form-label" for="login">Username or email</label>
        <input class="form-control" id="login" name="login" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required>
    </div>
    <button class="btn btn-success" type="submit">Log in</button>
</form>
<?php require __DIR__ . '/layout_footer.php'; ?>
