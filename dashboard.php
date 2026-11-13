<?php
require_once __DIR__ . '/config.php';
require_login();

$pageTitle = 'Dashboard';
require __DIR__ . '/layout_header.php';
?>
<h1 class="h3">Welcome, <?= e($_SESSION['username']) ?></h1>
<p class="text-muted">You are logged in. Server time: <?= e(date('Y-m-d H:i:s')) ?>, host: <?= e(gethostname()) ?>.</p>
<a class="btn btn-outline-primary" href="feedback.php">Go to feedback form</a>
<?php require __DIR__ . '/layout_footer.php'; ?>
