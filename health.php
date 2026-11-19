<?php
require_once __DIR__ . '/config.php';
send_security_headers();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$status = 'ok';
$dbOk = false;

try {
    db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $status = 'degraded';
    error_log('health check db failed: ' . $e->getMessage());
}

http_response_code($dbOk ? 200 : 503);
echo json_encode([
    'status' => $status,
    'db' => $dbOk ? 'ok' : 'error',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
