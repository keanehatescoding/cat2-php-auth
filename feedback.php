<?php
require_once __DIR__ . '/config.php';
start_session();

$pageTitle = 'Feedback';
$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Name is required (max 100 characters).';
    }
    if ($message === '') {
        $errors[] = 'Message is required.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO feedback (user_id, name, message) VALUES (:uid, :n, :m)'
        );
        $stmt->execute([
            ':uid' => $_SESSION['user_id'] ?? null,
            ':n'   => $name,
            ':m'   => $message,
        ]);
        // Post/Redirect/Get avoids duplicate inserts on refresh
        header('Location: feedback.php?saved=1');
        exit;
    }
}

$saved = isset($_GET['saved']);
$rows  = db()->query(
    'SELECT id, name, message, created_at FROM feedback ORDER BY id DESC LIMIT 50'
)->fetchAll();

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Feedback</h1>

<?php if ($saved): ?>
    <div class="alert alert-success">Feedback saved.</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card card-body shadow-sm mb-4">
    <div class="mb-3">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" maxlength="100" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="message">Message</label>
        <textarea class="form-control" id="message" name="message" rows="3" required></textarea>
    </div>
    <button class="btn btn-primary" type="submit">Submit</button>
</form>

<h2 class="h5">Saved feedback (latest 50)</h2>
<table class="table table-sm table-striped bg-white">
    <thead><tr><th>#</th><th>Name</th><th>Message</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['message']) ?></td>
            <td><?= e($r['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="4" class="text-muted">No feedback yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/layout_footer.php'; ?>
