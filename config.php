<?php
// Database connection settings for the CAT2 database.
// Values come from environment variables (set by docker-compose.yml from .env).
// No password is stored in the code.

declare(strict_types=1);

define('DB_HOST', getenv('CAT2_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('CAT2_DB_NAME') ?: 'CAT2');
define('DB_USER', getenv('CAT2_DB_USER') ?: 'cat2_user');
define('DB_PASS', getenv('CAT2_DB_PASS') ?: '');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        ensure_security_tables($pdo);
    }
    return $pdo;
}

function ensure_security_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Creative/security additions: login throttling + activity audit log.
    // Created lazily so existing deployments can adopt them without a manual migration step.
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username    VARCHAR(100) NOT NULL,
                ip_address  VARCHAR(45)  NOT NULL,
                succeeded   TINYINT(1)   NOT NULL DEFAULT 0,
                created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_attempts_lookup (username, created_at),
                INDEX idx_login_attempts_ip (ip_address, created_at)
            ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_log (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NULL,
                event_type  VARCHAR(50)  NOT NULL,
                details     VARCHAR(255) NULL,
                ip_address  VARCHAR(45)  NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_log_user (user_id, created_at),
                INDEX idx_activity_log_event (event_type, created_at),
                CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB'
        );
    } catch (Throwable $e) {
        // Degrade gracefully if the app DB user cannot create tables.
        error_log('ensure_security_tables failed: ' . $e->getMessage());
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// CSRF protection for state-changing forms. Tokens live in the session, so a
// cross-site POST (including a logged-in victim's browser) is rejected.
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return sprintf('<input type="hidden" name="csrf_token" value="%s">', e(csrf_token()));
}

function csrf_valid($token): bool
{
    start_session();
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token)) {
        return false;
    }
    return $token !== '' && $expected !== '' && hash_equals($expected, $token);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function require_login(): void
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

function log_activity(string $eventType, ?string $details = null, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, event_type, details, ip_address) VALUES (:uid, :evt, :det, :ip)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':evt' => substr($eventType, 0, 50),
            ':det' => $details === null ? null : substr($details, 0, 255),
            ':ip'  => client_ip(),
        ]);
    } catch (Throwable $e) {
        // Logging must not break user flows.
        error_log('activity log failed: ' . $e->getMessage());
    }
}

function record_login_attempt(string $username, bool $succeeded): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (:u, :ip, :ok)'
        );
        $stmt->execute([
            ':u'  => substr($username, 0, 100),
            ':ip' => client_ip(),
            ':ok' => $succeeded ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('login attempt log failed: ' . $e->getMessage());
    }
}

function login_throttled(string $username): array
{
    // Simple brute-force protection: block after 5 failed attempts in 15 minutes
    // for the same username or from the same IP.
    $windowMinutes = 15;
    $maxFailures = 5;
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE succeeded = 0 AND created_at >= (NOW() - INTERVAL :mins MINUTE)
               AND (username = :u OR ip_address = :ip)'
        );
        $stmt->bindValue(':mins', $windowMinutes, PDO::PARAM_INT);
        $stmt->bindValue(':u', substr($username, 0, 100));
        $stmt->bindValue(':ip', client_ip());
        $stmt->execute();
        $n = (int)($stmt->fetch()['n'] ?? 0);
        if ($n >= $maxFailures) {
            return [true, $windowMinutes];
        }
    } catch (Throwable $e) {
        // Fail open for availability, but log for operators.
        error_log('throttle check failed: ' . $e->getMessage());
    }
    return [false, $windowMinutes];
}
