<?php
declare(strict_types=1);

/**
 * CLI helper: promote or demote a user to/from admin.
 * Usage:
 *   php scripts/promote_admin.php <username-or-email> [--demote]
 *
 * Requires DB env vars (CAT2_DB_HOST/NAME/USER/PASS) to be set in the environment.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';

$identifier = $argv[1] ?? '';
$demote = in_array('--demote', $argv, true);

if ($identifier === '' || $identifier === '--help' || $identifier === '-h') {
    fwrite(STDERR, "Usage: php scripts/promote_admin.php <username-or-email> [--demote]\n");
    exit(1);
}

try {
    $stmt = db()->prepare('SELECT id, username, email, is_admin FROM users WHERE username = :u OR email = :e LIMIT 1');
    $stmt->execute([':u' => $identifier, ':e' => normalize_email($identifier)]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "User not found: {$identifier}\n");
        exit(1);
    }
    $newVal = $demote ? 0 : 1;
    $upd = db()->prepare('UPDATE users SET is_admin = :v WHERE id = :id');
    $upd->execute([':v' => $newVal, ':id' => (int)$user['id']]);
    $action = $newVal ? 'promoted to admin' : 'demoted from admin';
    fwrite(STDOUT, "User {$user['username']} ({$user['email']}) {$action}.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
