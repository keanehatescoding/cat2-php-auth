<?php
// Minimal custom Lambda runtime for PHP. It polls the Lambda runtime API for events,
// calls handle() from handler.php, and posts the result back.

require __DIR__ . '/handler.php';

$api = getenv('AWS_LAMBDA_RUNTIME_API');
if ($api === false || $api === '') {
    fwrite(STDERR, "AWS_LAMBDA_RUNTIME_API is not set\n");
    exit(1);
}
$base = "http://$api/2018-06-01/runtime/invocation";

function http_call(string $method, string $url, ?string $body = null): array
{
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 900]];
    if ($body !== null) {
        $opts['http']['header'] = "Content-Type: application/json\r\n";
        $opts['http']['content'] = $body;
    }
    $data = file_get_contents($url, false, stream_context_create($opts));
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$data === false ? '' : $data, $headers];
}

while (true) {
    [$payload, $headers] = http_call('GET', "$base/next");
    $requestId = $headers['lambda-runtime-aws-request-id'] ?? '';
    if ($requestId === '') {
        continue;
    }

    try {
        $event = json_decode($payload, true) ?? [];
        $result = handle($event);
        http_call('POST', "$base/$requestId/response", json_encode($result));
    } catch (Throwable $e) {
        $error = json_encode([
            'errorMessage' => $e->getMessage(),
            'errorType' => get_class($e),
        ]);
        http_call('POST', "$base/$requestId/error", $error);
    }
}
