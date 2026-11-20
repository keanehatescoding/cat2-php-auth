<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

function ok(string $msg): void { echo "[ok] $msg\n"; }
function fail(string $msg): void { global $failures; $failures++; echo "[fail] $msg\n"; }

$requiredFiles = [
    'config.php','index.php','login.php','signup.php','logout.php','dashboard.php','feedback.php','admin.php',
    'serverless_demo.php','health.php','layout_header.php','layout_footer.php','sql/schema.sql',
    'password-meter.js','style.css','tests/smoke.php','tests/helpers_test.php','tests/integration_auth.sh','docs/rollback.md',
    'scripts/promote_admin.php',
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
foreach (glob($root . '/tests/*.php') ?: [] as $php) {
    if (basename($php) === 'smoke.php') continue;
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($php) . ' 2>&1', $out, $code);
    $code === 0 ? ok('lint: tests/' . basename($php)) : fail('lint: tests/' . basename($php) . ' -> ' . implode(' ', $out));
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
    'function is_current_user_admin(',
    'function require_admin(',
    'function captcha_enabled(',
    'function captcha_enabled_for(',
    'function captcha_verify_request(',
    'function verify_turnstile_response(',
    "script-src {\$scriptSrc}",
    "connect-src {\$connectSrc}",
    "frame-src {\$frameSrc}",
    "style-src 'self' https://cdn.jsdelivr.net;",
    "challenges.cloudflare.com/turnstile/v0/siteverify",
    "CAT2_CAPTCHA_TEST_BYPASS",
];
foreach ($needles as $n) {
    strpos($config, $n) !== false ? ok("config contains: $n") : fail("config missing: $n");
}
if (strpos($config, "script-src 'self' 'unsafe-inline'") === false) {
    ok('CSP script-src has no unsafe-inline');
} else {
    fail('CSP script-src still allows unsafe-inline');
}
if (strpos($config, "style-src 'self' 'unsafe-inline'") === false) {
    ok('CSP style-src has no unsafe-inline');
} else {
    fail('CSP style-src still allows unsafe-inline');
}

$health = file_get_contents($root . '/health.php') ?: '';
strpos($health, 'CAT2_HEALTH_TOKEN') !== false ? ok('health supports CAT2_HEALTH_TOKEN') : fail('health missing CAT2_HEALTH_TOKEN support');
strpos($health, "_GET['token']") === false ? ok('health does not accept query-string token') : fail('health still accepts query-string token');

$login = file_get_contents($root . '/login.php') ?: '';
strpos($login, 'challenge_answer') !== false ? ok('login includes step-up challenge field') : fail('login missing challenge field');
strpos($login, 'name="website"') !== false ? ok('login includes honeypot field') : fail('login missing honeypot field');
strpos($login, "captcha_verify_request('login')") !== false ? ok('login supports captcha verification when enabled') : fail('login missing captcha verification support');
strpos($login, "captcha_widget_html('login')") !== false ? ok('login renders captcha widget placeholder') : fail('login missing captcha widget placeholder');

$signup = file_get_contents($root . '/signup.php') ?: '';
strpos($signup, 'password-meter.js') !== false ? ok('signup loads external password meter script') : fail('signup missing external password meter script');
strpos($signup, '<script>') === false ? ok('signup has no inline script block') : fail('signup still has inline script block');
strpos($signup, 'name="website"') !== false ? ok('signup includes honeypot field') : fail('signup missing honeypot field');
strpos($signup, "captcha_verify_request('signup')") !== false ? ok('signup verifies captcha when enabled') : fail('signup missing captcha verification');
strpos($signup, "captcha_widget_html('signup')") !== false ? ok('signup renders captcha widget placeholder') : fail('signup missing captcha widget placeholder');

$feedback = file_get_contents($root . '/feedback.php') ?: '';
strpos($feedback, 'name="website"') !== false ? ok('feedback includes honeypot field') : fail('feedback missing honeypot field');
strpos($feedback, "captcha_verify_request('feedback')") !== false ? ok('feedback verifies captcha when enabled') : fail('feedback missing captcha verification');
strpos($feedback, "captcha_widget_html('feedback')") !== false ? ok('feedback renders captcha widget placeholder') : fail('feedback missing captcha widget placeholder');
strpos($feedback, '$isLoggedIn') !== false ? ok('feedback restricts feedback list visibility to logged-in users') : fail('feedback does not appear to restrict feedback list visibility');

$admin = file_get_contents($root . '/admin.php') ?: '';
strpos($admin, 'require_admin()') !== false ? ok('admin page requires admin access') : fail('admin page missing require_admin');
strpos($admin, 'delete_feedback') !== false ? ok('admin page supports feedback moderation') : fail('admin page missing feedback moderation action');
strpos($admin, 'toggle_admin') !== false ? ok('admin page supports user role management') : fail('admin page missing user role management');

$schema = file_get_contents($root . '/sql/schema.sql') ?: '';
strpos($schema, 'is_admin') !== false ? ok('schema includes is_admin column') : fail('schema missing is_admin column');

$layout = file_get_contents($root . '/layout_header.php') ?: '';
strpos($layout, 'style="') === false ? ok('layout_header has no inline style attribute') : fail('layout_header still has inline style attribute');
$layoutFooter = file_get_contents($root . '/layout_footer.php') ?: '';
strpos($layoutFooter, 'challenges.cloudflare.com/turnstile/v0/api.js') !== false ? ok('layout_footer can load Turnstile script when enabled') : fail('layout_footer missing Turnstile script include');
$serverlessDemo = file_get_contents($root . '/serverless_demo.php') ?: '';
strpos($serverlessDemo, 'style="') === false ? ok('serverless_demo has no inline style attribute') : fail('serverless_demo still has inline style attribute');
strpos($signup, 'style="') === false ? ok('signup has no inline style attribute') : fail('signup still has inline style attribute');

$style = file_get_contents($root . '/style.css') ?: '';
foreach (['.main-container', '.pw-progress', '.pw-strength-bar', '.pre-wrap'] as $selector) {
    strpos($style, $selector) !== false ? ok("style.css contains $selector") : fail("style.css missing $selector");
}

// Basic helper behavior checks (no DB required)
require_once $root . '/config.php';
is_safe_redirect_target('/dashboard.php') ? ok('safe redirect accepts /dashboard.php') : fail('safe redirect rejected /dashboard.php');
is_safe_redirect_target('/dashboard.php?tab=security') ? ok('safe redirect accepts query string path') : fail('safe redirect rejected query string path');
(!is_safe_redirect_target('https://evil.example') && !is_safe_redirect_target('//evil.example') && !is_safe_redirect_target("/\\evil.example") && !is_safe_redirect_target("/dashboard\n.php")) ? ok('safe redirect rejects external/unsafe targets') : fail('safe redirect accepted unsafe target');
normalize_email('  User@Example.COM  ') === 'user@example.com' ? ok('normalize_email lowercases and trims') : fail('normalize_email behavior incorrect');

if ($failures > 0) {
    fwrite(STDERR, "\nFailures: $failures\n");
    exit(1);
}
echo "\nAll smoke checks passed.\n";
