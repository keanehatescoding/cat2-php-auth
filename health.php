<?php
require_once __DIR__ . '/config.php';
send_security_headers();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$rawToken = getenv('CAT2_HEALTH_TOKEN');
$token = $rawToken === false ? '' : (string)$rawToken;
$authorized = true;
if ($token !== '') {
    $provided = '';
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($auth) || $auth === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : (function_exists('apache_request_headers') ? apache_request_headers() : []);
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $auth = $value;
                    break;
                }
            }
        }
    }
    if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        $provided = $m[1];
    }
    $authorized = ($provided !== '' && hash_equals($token, $provided));
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode([
        'status' => 'forbidden',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$status = 'ok';
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $status = 'degraded';
    error_log('health check db failed: ' . $e->getMessage());
}

http_response_code($status === 'ok' ? 200 : 503);
echo json_encode([
    'status' => $status,
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
