<?php
require_once __DIR__ . '/config.php';
start_session();

// Logout is state-changing, so it must be a CSRF-protected POST. A GET link
// could be triggered cross-site (e.g. an  tag) and log a user out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf_token'] ?? '')) {
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    log_activity('logout', 'user logged out', $uid);
    $_SESSION = [];
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
}

header('Location: login.php');
exit;
