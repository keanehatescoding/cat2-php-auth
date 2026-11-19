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

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function session_cookie_options(): array
{
    return [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function send_security_headers(): void
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Keep CSP compatible with existing Bootstrap CDN assets and inline UI scripts.
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self'");
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(session_cookie_options());
        session_start();
    }
    send_security_headers();
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

function maybe_prune_security_records(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    // Opportunistic cleanup to keep security tables bounded.
    if (random_int(1, 100) > 2) {
        return;
    }
    try {
        $pdo->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY)");
        $pdo->exec("DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 90 DAY)");
    } catch (Throwable $e) {
        error_log('security record prune failed: ' . $e->getMessage());
    }
}

function log_activity(string $eventType, ?string $details = null, ?int $userId = null): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, event_type, details, ip_address) VALUES (:uid, :evt, :det, :ip)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':evt' => substr($eventType, 0, 50),
            ':det' => $details === null ? null : substr($details, 0, 255),
            ':ip'  => client_ip(),
        ]);
        maybe_prune_security_records($pdo);
    } catch (Throwable $e) {
        // Logging must not break user flows.
        error_log('activity log failed: ' . $e->getMessage());
    }
}

function recent_event_count(string $eventType, int $minutes, ?int $userId = null, bool $ipOnly = false): int
{
    $minutes = max(0, (int)$minutes);
    try {
        if ($ipOnly || $userId === null) {
            $stmt = db()->prepare(
                'SELECT COUNT(*) AS n FROM activity_log
                 WHERE event_type = :evt AND ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL ' . $minutes . ' MINUTE)'
            );
            $stmt->bindValue(':evt', $eventType);
            $stmt->bindValue(':ip', client_ip());
        } else {
            $stmt = db()->prepare(
                'SELECT COUNT(*) AS n FROM activity_log
                 WHERE event_type = :evt AND user_id = :uid
                   AND created_at >= (NOW() - INTERVAL ' . $minutes . ' MINUTE)'
            );
            $stmt->bindValue(':evt', $eventType);
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int)($stmt->fetch()['n'] ?? 0);
    } catch (Throwable $e) {
        error_log('recent_event_count failed: ' . $e->getMessage());
        return 0;
    }
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function acquire_login_locks(PDO $pdo, string $username, string $ip): array
{
    $names = [
        'login_u_' . hash('sha256', $username),
        'login_i_' . hash('sha256', $ip),
    ];
    sort($names, SORT_STRING);
    $names = array_values(array_unique($names));
    $acquired = [];
    foreach ($names as $name) {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:name, 10) AS got');
        $stmt->execute([':name' => $name]);
        $got = $stmt->fetch()['got'] ?? 0;
        if ((int)$got !== 1) {
            foreach (array_reverse($acquired) as $held) {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $release->execute([':name' => $held]);
            }
            throw new RuntimeException('Could not acquire login admission lock');
        }
        $acquired[] = $name;
    }
    return $acquired;
}

function release_login_locks(PDO $pdo, array $names): void
{
    foreach (array_reverse($names) as $name) {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute([':name' => $name]);
        } catch (Throwable $e) {
            error_log('release login lock failed: ' . $e->getMessage());
        }
    }
}

function begin_login_attempt(string $username): array
{
    // Reserve admission before password verification so concurrent attempts for the
    // same username or IP cannot all pass the throttle check in parallel.
    $windowMinutes = 15;
    $maxFailures = 5;
    $username = substr($username, 0, 100);
    $ip = client_ip();
    $pdo = db();
    $locks = [];
    try {
        $locks = acquire_login_locks($pdo, $username, $ip);

        $windowMinutes = (int)$windowMinutes;
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE succeeded = 0 AND created_at >= (NOW() - INTERVAL ' . $windowMinutes . ' MINUTE)
               AND (username = :u OR ip_address = :ip)'
        );
        $countStmt->bindValue(':u', $username);
        $countStmt->bindValue(':ip', $ip);
        $countStmt->execute();
        $n = (int)($countStmt->fetch()['n'] ?? 0);
        if ($n >= $maxFailures) {
            return [false, null, $windowMinutes, $locks, $pdo];
        }

        $insert = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (:u, :ip, 0)'
        );
        $insert->execute([':u' => $username, ':ip' => $ip]);
        $attemptId = (int)$pdo->lastInsertId();

        // Re-check including the reserved in-progress attempt for atomic admission.
        $countStmt->execute();
        $nWithReservation = (int)($countStmt->fetch()['n'] ?? 0);
        if ($nWithReservation > $maxFailures) {
            $delete = $pdo->prepare('DELETE FROM login_attempts WHERE id = :id');
            $delete->execute([':id' => $attemptId]);
            return [false, null, $windowMinutes, $locks, $pdo];
        }

        return [true, $attemptId, $windowMinutes, $locks, $pdo];
    } catch (Throwable $e) {
        // Fail open for availability, but log for operators.
        error_log('begin_login_attempt failed: ' . $e->getMessage());
        return [true, null, $windowMinutes, $locks, $pdo];
    }
}

function complete_login_attempt($reservation, bool $succeeded): void
{
    [, $attemptId, , $locks, $pdo] = $reservation + [null, null, null, [], null];
    try {
        if ($attemptId !== null && $pdo instanceof PDO) {
            if ($succeeded) {
                $stmt = $pdo->prepare('UPDATE login_attempts SET succeeded = 1 WHERE id = :id');
                $stmt->execute([':id' => (int)$attemptId]);
            }
            // Failed attempts remain recorded as succeeded = 0.
        }
    } catch (Throwable $e) {
        error_log('complete_login_attempt failed: ' . $e->getMessage());
    } finally {
        if ($pdo instanceof PDO && is_array($locks) && $locks) {
            release_login_locks($pdo, $locks);
        }
    }
}
