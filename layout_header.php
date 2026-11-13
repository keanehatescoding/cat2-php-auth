<?php
// Shared page header. Expects $pageTitle to be set by the including page.
require_once __DIR__ . '/config.php';
start_session();
$loggedIn = !empty($_SESSION['user_id']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'CAT 2') ?> | CAT 2 Auth</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">CAT 2 Server Admin</a>
        <div class="navbar-nav ms-auto">
            <?php if ($loggedIn): ?>
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="feedback.php">Feedback</a>
                <a class="nav-link" href="logout.php">Logout</a>
            <?php else: ?>
                <a class="nav-link" href="login.php">Login</a>
                <a class="nav-link" href="signup.php">Sign up</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container" style="max-width: 720px;">
