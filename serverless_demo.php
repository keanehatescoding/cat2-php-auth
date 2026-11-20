<?php
require_once __DIR__ . '/config.php';
require_login();

$pageTitle = 'Serverless Demo';
$result = null;
$error = '';
$name = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');

        // Reuse the project's simulated AWS Lambda handler (serverless/handler.php)
        // by invoking it in-process as a function event. This demonstrates a
        // serverless-style request/response flow integrated into the web app.
        require_once __DIR__ . '/serverless/handler.php';
        $event = [
            'version' => '2.0',
            'requestContext' => ['http' => ['method' => 'POST', 'path' => '/feedback']],
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query(['name' => $name, 'message' => $message]),
            'isBase64Encoded' => false,
        ];

        try {
            $response = handle($event);
            $status = (int)($response['statusCode'] ?? 500);
            $body = (string)($response['body'] ?? '');
            $decoded = json_decode($body, true);
            $result = [
                'status' => $status,
                'body' => $decoded !== null ? $decoded : $body,
            ];
            log_activity('serverless_demo', 'invoked simulated lambda handler (status ' . $status . ')', (int)$_SESSION['user_id']);
            if ($status >= 400) {
                $error = is_array($decoded) && isset($decoded['error']) ? (string)$decoded['error'] : 'Handler returned an error.';
            }
        } catch (Throwable $e) {
            $error = 'Could not invoke the serverless handler.';
            error_log('serverless demo failed: ' . $e->getMessage());
        }
    }
}

require __DIR__ . '/layout_header.php';
?>
<h1 class="h3 mb-3">Serverless Feedback Demo</h1>
<p class="text-muted">This page integrates the project's <code>serverless/handler.php</code> (a simulated AWS Lambda feedback handler with a custom PHP runtime) by invoking it as an event-driven function from the app. In a full deployment this same handler can run behind a Lambda function URL/container runtime; here it runs in-process to demonstrate the serverless request/response model.</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="card card-body shadow-sm mb-4">
        <h2 class="h5">Function response</h2>
        <p class="mb-2"><strong>Status:</strong> <?= (int)$result['status'] ?></p>
        <pre class="bg-light border rounded p-3 mb-0 pre-wrap"><?= e(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm mb-4">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" maxlength="100" value="<?= e($name) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="message">Message</label>
        <textarea class="form-control" id="message" name="message" rows="3" required><?= e($message) ?></textarea>
    </div>
    <button class="btn btn-dark" type="submit">Invoke serverless handler</button>
</form>

<div class="card card-body shadow-sm">
    <h2 class="h5">What this demonstrates</h2>
    <ul class="mb-0">
        <li>Event-driven function input/output (Lambda-style event and HTTP response object).</li>
        <li>Reusable handler logic shared between serverless packaging and the main application.</li>
        <li>Validation and safe handling inside the function boundary.</li>
        <li>Local simulation path already included in <code>serverless/</code> (Dockerfile, custom runtime/bootstrap, frontend emulator client).</li>
    </ul>
</div>
<?php require __DIR__ . '/layout_footer.php'; ?>
