<?php
$pageTitle = 'Home';
require __DIR__ . '/layout_header.php';
?>
<div class="card card-body shadow-sm">
    <h1 class="h3">CAT 2 &mdash; Secure PHP Auth Web App</h1>
    <p>Cloud-hosted PHP application served over HTTPS with Apache and MariaDB. Includes account signup/login, CSRF-protected forms, feedback, and security-focused enhancements.</p>
    <ul class="mb-3">
        <li>Secure password hashing and validated form inputs</li>
        <li>Login throttling, activity auditing, and session hardening</li>
        <li>Serverless feedback-handler demo integration</li>
    </ul>
    <div class="d-flex flex-wrap gap-2">
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a class="btn btn-primary" href="dashboard.php">Dashboard</a>
            <a class="btn btn-outline-dark" href="serverless_demo.php">Serverless demo</a>
        <?php else: ?>
            <a class="btn btn-primary" href="signup.php">Sign up</a>
            <a class="btn btn-success" href="login.php">Log in</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="feedback.php">Feedback form</a>
    </div>
</div>
<?php require __DIR__ . '/layout_footer.php'; ?>
