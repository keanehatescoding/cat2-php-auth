<?php
$pageTitle = 'Home';
require __DIR__ . '/layout_header.php';
?>
<div class="card card-body shadow-sm">
    <h1 class="h3">CAT 2 &mdash; PHP web app on Linux</h1>
    <p>Served by Apache, PHP (mod_php) and MariaDB in Docker containers.</p>
    <p class="text-success fw-semibold">Update: this line was changed in a second commit and deployed with git pull.</p>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="signup.php">Sign up</a>
        <a class="btn btn-success" href="login.php">Log in</a>
        <a class="btn btn-outline-secondary" href="feedback.php">Feedback form</a>
    </div>
</div>
<?php require __DIR__ . '/layout_footer.php'; ?>
