<?php
/**
 * CLI probe for idle/absolute session timeout logic: fresh-session grace
 * behavior, idle expiration, absolute expiration, whichever-first precedence,
 * and activity touch resetting the idle clock without resetting login_at.
 *
 * Manipulates $_SESSION directly (no real HTTP session involved) and asserts
 * on session_expiration_reason()/session_touch_activity(). Run:
 *   C:\xampp\php\php.exe tests\session_timeout_probe.php
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

check('default idle timeout is 30 minutes', session_idle_timeout_seconds() === 30 * 60);
check('default absolute timeout is 480 minutes', session_absolute_timeout_seconds() === 480 * 60);

// A session with no tracking keys yet is treated as fresh, not expired.
unset($_SESSION['login_at'], $_SESSION['last_activity_at']);
check('untracked (legacy) session is not expired', session_expiration_reason() === null);

// touch sets both keys on a bare session.
unset($_SESSION['login_at'], $_SESSION['last_activity_at']);
session_touch_activity();
check('touch sets login_at', isset($_SESSION['login_at']));
check('touch sets last_activity_at', isset($_SESSION['last_activity_at']));
check('freshly touched session is not expired', session_expiration_reason() === null);

// Idle expiration: last activity older than the idle window, login recent.
$_SESSION['login_at'] = time() - 60;
$_SESSION['last_activity_at'] = time() - (session_idle_timeout_seconds() + 5);
check('idle-expired session reports "idle"', session_expiration_reason() === 'idle');

// One second inside the idle window is not yet expired.
$_SESSION['login_at'] = time() - 60;
$_SESSION['last_activity_at'] = time() - (session_idle_timeout_seconds() - 1);
check('session just inside the idle window is not expired', session_expiration_reason() === null);

// Absolute expiration: login older than the absolute window, activity recent.
$_SESSION['login_at'] = time() - (session_absolute_timeout_seconds() + 5);
$_SESSION['last_activity_at'] = time();
check('absolute-expired session reports "absolute"', session_expiration_reason() === 'absolute');

// Touching activity does not reset login_at, so absolute expiry still fires
// even for a continuously active session.
$oldLoginAt = time() - (session_absolute_timeout_seconds() + 5);
$_SESSION['login_at'] = $oldLoginAt;
$_SESSION['last_activity_at'] = time() - (session_absolute_timeout_seconds() + 5);
session_touch_activity();
check('touch does not reset an existing login_at', $_SESSION['login_at'] === $oldLoginAt);
check('absolute expiry still fires after touching activity', session_expiration_reason() === 'absolute');

// Both thresholds breached simultaneously: absolute takes precedence (checked first).
$_SESSION['login_at'] = time() - (session_absolute_timeout_seconds() + 100);
$_SESSION['last_activity_at'] = time() - (session_absolute_timeout_seconds() + 100);
check('when both are breached, absolute wins', session_expiration_reason() === 'absolute');

unset($_SESSION['login_at'], $_SESSION['last_activity_at']);

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
