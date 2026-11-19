<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

function ok(string $msg): void { echo "[ok] $msg\n"; }
function fail(string $msg): void { global $failures; $failures++; echo "[fail] $msg\n"; }

$requiredFiles = [
    'config.php','index.php','login.php','signup.php','logout.php','dashboard.php','feedback.php',
    'serverless_demo.php','health.php','layout_header.php','layout_footer.php','sql/schema.sql',
];
foreach ($requiredFiles as $f) {
    file_exists($root . '/' . $f) ? ok("file exists: $f") : fail("missing file: $f");
}

foreach (glob($root . '/*.php') ?: [] as $php) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($php) . ' 2>&1', $out, $code);
    $code === 0 ? ok('lint: ' . basename($php)) : fail('lint: ' . basename($php) . ' -> ' . implode(' ', $out));
}

$config = file_get_contents($root . '/config.php') ?: '';
$needles = [
    'function db(',
    'function start_session(',
    'function csrf_token(',
    'function require_login(',
    'function is_safe_redirect_target(',
    'function begin_login_attempt(',
    'function log_activity(',
    'function login_challenge_required(',
];
foreach ($needles as $n) {
    strpos($config, $n) !== false ? ok("config contains: $n") : fail("config missing: $n");
}

$health = file_get_contents($root . '/health.php') ?: '';
strpos($health, 'CAT2_HEALTH_TOKEN') !== false ? ok('health supports CAT2_HEALTH_TOKEN') : fail('health missing CAT2_HEALTH_TOKEN support');

$login = file_get_contents($root . '/login.php') ?: '';
strpos($login, 'challenge_answer') !== false ? ok('login includes step-up challenge field') : fail('login missing challenge field');

if ($failures > 0) {
    fwrite(STDERR, "\nFailures: $failures\n");
    exit(1);
}
echo "\nAll smoke checks passed.\n";
