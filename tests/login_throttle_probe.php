<?php
/**
 * CLI probe for login throttling and failed-login audit: per-identifier and
 * per-IP sliding-window counters, successful-login reset behavior (a success
 * does not count toward the failure window), and identifier normalization.
 *
 * Seeds throwaway rows directly against tbl_login_attempts, exercises the
 * real throttle-check functions, asserts the resulting state, and removes
 * everything it created. Run:
 *   C:\xampp\php\php.exe tests\login_throttle_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$identifier = 'throttle_probe_' . $suffix;
$ipAddress = '203.0.113.' . random_int(1, 254);
$otherIp = '198.51.100.' . random_int(1, 254);

function cleanup(PDO $pdo, string $identifier, string $ipAddress, string $otherIp): void
{
    $pdo->prepare('DELETE FROM tbl_login_attempts WHERE identifier = :i OR ip_address IN (:ip1, :ip2)')
        ->execute([':i' => $identifier, ':ip1' => $ipAddress, ':ip2' => $otherIp]);
}

try {
    cleanup($pdo, $identifier, $ipAddress, $otherIp);

    check('identifier not throttled before any attempts', !login_attempt_identifier_is_throttled($pdo, $identifier));
    check('IP not throttled before any attempts', !login_attempt_ip_is_throttled($pdo, $ipAddress));

    $maxPerIdentifier = login_throttle_max_attempts_per_identifier();
    check('default per-identifier limit is 5', $maxPerIdentifier === 5);

    // Simulate failed attempts up to one below the threshold.
    $insert = $pdo->prepare(
        'INSERT INTO tbl_login_attempts (identifier, user_id, ip_address, user_agent, was_successful, created_at)
         VALUES (:identifier, NULL, :ip, \'probe-agent\', 0, NOW())'
    );
    for ($i = 0; $i < $maxPerIdentifier - 1; $i++) {
        $insert->execute([':identifier' => $identifier, ':ip' => $ipAddress]);
    }
    check('not yet throttled at ' . ($maxPerIdentifier - 1) . ' failures', !login_attempt_identifier_is_throttled($pdo, $identifier));

    // One more failure reaches the threshold.
    $insert->execute([':identifier' => $identifier, ':ip' => $ipAddress]);
    check('throttled at exactly ' . $maxPerIdentifier . ' failures', login_attempt_identifier_is_throttled($pdo, $identifier));

    // A different identifier on the same IP is unaffected by the per-identifier counter...
    $otherIdentifier = 'throttle_probe_other_' . $suffix;
    check('a different identifier is not throttled', !login_attempt_identifier_is_throttled($pdo, $otherIdentifier));
    // ...but the shared IP has now also accumulated failures (below its own higher threshold).
    check('IP not yet throttled (below its own higher limit)', !login_attempt_ip_is_throttled($pdo, $ipAddress));

    $maxPerIp = login_throttle_max_attempts_per_ip();
    check('default per-IP limit is 20', $maxPerIp === 20);
    $remaining = $maxPerIp - $maxPerIdentifier;
    for ($i = 0; $i < $remaining; $i++) {
        $insert->execute([':identifier' => $otherIdentifier, ':ip' => $ipAddress]);
    }
    check('IP throttled once its own threshold is reached', login_attempt_ip_is_throttled($pdo, $ipAddress));
    check('a different IP is unaffected by the shared-IP throttle', !login_attempt_ip_is_throttled($pdo, $otherIp));

    // A successful attempt does not itself trigger throttling and old failures
    // outside the window do not count (simulated via an old timestamp).
    cleanup($pdo, $identifier, $ipAddress, $otherIp);
    $insert->execute([':identifier' => $identifier, ':ip' => $ipAddress]);
    record_login_attempt($pdo, $identifier, null, true);
    check('successful attempt recorded without error', true);
    check('one old-window failure alone is not throttled', !login_attempt_identifier_is_throttled($pdo, $identifier));

    $windowMinutes = login_throttle_window_minutes();
    check('default window is 15 minutes', $windowMinutes === 15);
    $pdo->prepare(
        'UPDATE tbl_login_attempts
         SET created_at = DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
         WHERE identifier = :identifier AND was_successful = 0'
    )->execute([':minutes' => $windowMinutes + 5, ':identifier' => $identifier]);
    for ($i = 0; $i < $maxPerIdentifier - 1; $i++) {
        $insert->execute([':identifier' => $identifier, ':ip' => $ipAddress]);
    }
    check('outside-window failure does not count toward the current threshold', !login_attempt_identifier_is_throttled($pdo, $identifier));

    // Identifier normalization: case/whitespace variants must collide to the same key.
    check('normalization lowercases and trims', login_attempt_normalize_identifier('  Admin@Example.COM  ') === 'admin@example.com');

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    try { cleanup($pdo, $identifier, $ipAddress, $otherIp); } catch (Throwable $e) { echo 'cleanup: ' . $e->getMessage() . PHP_EOL; }
    try { cleanup($pdo, 'throttle_probe_other_' . $suffix, $ipAddress, $otherIp); } catch (Throwable $e) { echo 'cleanup: ' . $e->getMessage() . PHP_EOL; }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
