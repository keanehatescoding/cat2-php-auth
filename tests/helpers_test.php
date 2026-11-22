<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
function ok(string $msg): void { echo "[ok] $msg\n"; }
function fail(string $msg): void { global $failures; $failures++; echo "[fail] $msg\n"; }

// Prevent session/header side effects in CLI.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTPS'] = 'on';

require_once $root . '/config.php';

// Redirect safety
$accept = ['/cat2/', '/cat2/login.php?next=%2Fcat2%2Fdashboard.php', '/index.php#top'];
foreach ($accept as $t) {
    is_safe_redirect_target($t) ? ok("accept redirect: $t") : fail("reject redirect (unexpected): $t");
}
$reject = ['http://evil.test', 'https://evil.test', '//evil.test', '/\\evil.test', "\\\\evil.test", "/a\nb", "/a\rb", "", null];
foreach ($reject as $t) {
    !is_safe_redirect_target($t) ? ok('reject redirect unsafe value') : fail('accepted unsafe redirect: ' . var_export($t, true));
}

// Email normalization
normalize_email(' TEST@EXAMPLE.com ') === 'test@example.com' ? ok('normalize_email trims/lowercases') : fail('normalize_email failed');

// CSRF token behavior
$_SESSION = [];
$t1 = csrf_token();
$t2 = csrf_token();
($t1 !== '' && hash_equals($t1, $t2)) ? ok('csrf_token stable within session') : fail('csrf_token not stable');
csrf_valid($t1) ? ok('csrf_valid accepts current token') : fail('csrf_valid rejected current token');
!csrf_valid('wrong') ? ok('csrf_valid rejects wrong token') : fail('csrf_valid accepted wrong token');

// Login challenge helpers (no DB)
$_SESSION = [];
[$a,$b] = ensure_login_challenge();
(is_int($a) && is_int($b)) ? ok('ensure_login_challenge returns ints') : fail('ensure_login_challenge invalid');
!login_challenge_valid('not-a-number') ? ok('login_challenge_valid rejects non-numeric') : fail('login_challenge_valid accepted non-numeric');
$expected = (string)($a + $b);
login_challenge_valid($expected) ? ok('login_challenge_valid accepts correct answer') : fail('login_challenge_valid rejected correct answer');
!isset($_SESSION['login_challenge_a'], $_SESSION['login_challenge_b']) ? ok('challenge cleared on success') : fail('challenge not cleared on success');

if ($failures > 0) {
    fwrite(STDERR, "\nFailures: $failures\n");
    exit(1);
}
echo "\nAll helper checks passed.\n";
