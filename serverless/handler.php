<?php
// Feedback handler for AWS Lambda. Runs once per request; there is no server to manage.
// Receives the API event from a Lambda function URL and returns an HTTP-style response.

function handle(array $event): array
{
    $json = ['content-type' => 'application/json'];

    $body = (string) ($event['body'] ?? '');
    if (!empty($event['isBase64Encoded'])) {
        $body = base64_decode($body);
    }

    // The feedback form posts application/x-www-form-urlencoded data.
    parse_str($body, $form);
    $name = trim((string) ($form['name'] ?? ''));
    $message = trim((string) ($form['message'] ?? ''));

    if ($name === '' || $message === '') {
        return [
            'statusCode' => 400,
            'headers' => $json,
            'body' => json_encode(['error' => 'name and message are required']),
        ];
    }

    $id = bin2hex(random_bytes(8));
    // Logs go to CloudWatch. The message text is not logged.
    error_log(sprintf('feedback id=%s name_length=%d message_length=%d', $id, mb_strlen($name), mb_strlen($message)));

    return [
        'statusCode' => 201,
        'headers' => $json,
        'body' => json_encode([
            'id' => $id,
            'name' => $name,
            'message' => $message,
            'received_at' => gmdate('c'),
            'runtime' => 'PHP ' . PHP_VERSION . ' on AWS Lambda',
            'function' => getenv('AWS_LAMBDA_FUNCTION_NAME') ?: 'local',
        ]),
    ];
}
