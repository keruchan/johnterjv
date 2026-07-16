<?php
/**
 * ============================================================
 * File     : config/config.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Central configuration file.
 *            - Starts the PHP session (required by all modules
 *              for authentication state: pages/cenro/,
 *              pages/community/, pages/ems/).
 *            - Opens a single shared PDO connection to MySQL.
 *            - Enforces UTF-8 (utf8mb4) encoding.
 *            - Enables PDO Exception mode for secure, consistent
 *              error handling (no silent failures).
 *
 * Include this file at the TOP of every page that needs
 * database access and/or session data, e.g.:
 *      require_once __DIR__ . '/../../config/config.php';
 * ============================================================
 */

// ------------------------------------------------------------
// 1. SESSION HANDLING
// ------------------------------------------------------------
// Only start a session if one is not already active. This guard
// prevents "session already started" notices when config.php is
// included multiple times (e.g., via nested requires).
if (session_status() === PHP_SESSION_NONE) {

    // Harden session cookie parameters before starting the session.
    // - httponly: JS (document.cookie) cannot read the session cookie -> mitigates XSS session theft.
    // - samesite: Lax by default is a reasonable balance for a gov portal with normal navigation/login.
    // - secure: set to true automatically when the request is served over HTTPS.
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,        // Expires when the browser closes.
        'path'     => '/',
        'domain'   => '',       // Current domain only.
        'secure'   => $isHttps, // Only sent over HTTPS when available.
        'httponly' => true,     // Not accessible via client-side JS.
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ------------------------------------------------------------
// 2. ERROR REPORTING (Development vs Production)
// ------------------------------------------------------------
// For a live government system, raw PHP errors should NEVER be
// displayed to end users (information disclosure risk).
// Toggle DISPLAY_ERRORS to true only in a local dev environment.
define('DISPLAY_ERRORS', false);

if (DISPLAY_ERRORS) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL); // still log everything, just don't display
}

// ------------------------------------------------------------
// 3. DATABASE CONFIGURATION
// ------------------------------------------------------------
// Adjust these constants to match your local/production
// MySQL environment. Credentials should ideally be pulled from
// environment variables outside of source control in production.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'certreefy_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Permit scans are private records. The default storage directory sits outside
// XAMPP's public htdocs tree and may be overridden per environment.
$permitStorageOverride = trim((string) getenv('CERTREEFY_PERMIT_STORAGE'));
define(
    'PERMIT_DOCUMENT_STORAGE_ROOT',
    $permitStorageOverride !== ''
        ? $permitStorageOverride
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'certreefy' . DIRECTORY_SEPARATOR . 'permit_documents'
);
$permitMaxBytes = filter_var(getenv('CERTREEFY_PERMIT_MAX_BYTES'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1024, 'max_range' => 50 * 1024 * 1024],
]);
define('PERMIT_DOCUMENT_MAX_BYTES', $permitMaxBytes === false ? 10 * 1024 * 1024 : $permitMaxBytes);

// Site-inspection photographs are operational evidence and are kept in a
// separate private directory from applicant document scans.
$inspectionStorageOverride = trim((string) getenv('CERTREEFY_INSPECTION_STORAGE'));
define(
    'PERMIT_INSPECTION_STORAGE_ROOT',
    $inspectionStorageOverride !== ''
        ? $inspectionStorageOverride
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'certreefy' . DIRECTORY_SEPARATOR . 'permit_inspections'
);
$inspectionPhotoMaxBytes = filter_var(getenv('CERTREEFY_INSPECTION_PHOTO_MAX_BYTES'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1024, 'max_range' => 50 * 1024 * 1024],
]);
define(
    'PERMIT_INSPECTION_PHOTO_MAX_BYTES',
    $inspectionPhotoMaxBytes === false ? 10 * 1024 * 1024 : $inspectionPhotoMaxBytes
);

// Login throttling slows down credential-guessing. Two independent windows are
// enforced: a tight per-identifier limit (protects one account from repeated
// guesses) and a looser per-IP limit (slows a single actor spraying many
// usernames). Both use the same sliding window and are overridable per
// environment.
$loginMaxAttemptsPerIdentifier = filter_var(
    getenv('CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IDENTIFIER'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000]]
);
define(
    'CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IDENTIFIER',
    $loginMaxAttemptsPerIdentifier === false ? 5 : $loginMaxAttemptsPerIdentifier
);
$loginMaxAttemptsPerIp = filter_var(
    getenv('CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IP'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000]]
);
define(
    'CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IP',
    $loginMaxAttemptsPerIp === false ? 20 : $loginMaxAttemptsPerIp
);
$loginLockoutMinutes = filter_var(
    getenv('CERTREEFY_LOGIN_LOCKOUT_MINUTES'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1440]]
);
define(
    'CERTREEFY_LOGIN_LOCKOUT_MINUTES',
    $loginLockoutMinutes === false ? 15 : $loginLockoutMinutes
);

// Authenticated sessions expire after a period of inactivity (idle timeout) or
// a fixed maximum duration since login (absolute timeout), whichever comes
// first. Both are overridable per environment.
$sessionIdleTimeoutMinutes = filter_var(
    getenv('CERTREEFY_SESSION_IDLE_TIMEOUT_MINUTES'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 10080]]
);
define(
    'CERTREEFY_SESSION_IDLE_TIMEOUT_MINUTES',
    $sessionIdleTimeoutMinutes === false ? 30 : $sessionIdleTimeoutMinutes
);
$sessionAbsoluteTimeoutMinutes = filter_var(
    getenv('CERTREEFY_SESSION_ABSOLUTE_TIMEOUT_MINUTES'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 10080]]
);
define(
    'CERTREEFY_SESSION_ABSOLUTE_TIMEOUT_MINUTES',
    $sessionAbsoluteTimeoutMinutes === false ? 480 : $sessionAbsoluteTimeoutMinutes
);

// The official Tree Cutting Permit is a physical, wet-ink-signed document
// claimed in person; the system does not generate or deliver it digitally. This
// is the office where a released permit is collected, shown to the applicant and
// included in the release notification. Overridable per environment.
$permitClaimLocation = trim((string) getenv('CERTREEFY_PERMIT_CLAIM_LOCATION'));
define(
    'CERTREEFY_PERMIT_CLAIM_LOCATION',
    $permitClaimLocation !== '' ? $permitClaimLocation : 'the CENRO Sta. Cruz, Laguna office'
);

// Runtime and one-time setup share one authoritative donation policy source.
require_once __DIR__ . '/permit_policy.php';

// ------------------------------------------------------------
// 4. PDO CONNECTION
// ------------------------------------------------------------
// Data Source Name (DSN) string, forcing UTF-8 (utf8mb4) so that
// names, addresses, etc. support full Unicode (emojis, special
// characters, Filipino diacritics, etc.) without corruption.
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

// PDO options:
// - ERRMODE_EXCEPTION : every DB error throws a PDOException instead
//                       of failing silently or via legacy warnings.
//                       This is the "secure coding" default because
//                       calling code is FORCED to handle failures
//                       (try/catch) rather than assuming success.
// - FETCH_ASSOC       : default fetch mode returns associative arrays.
// - EMULATE_PREPARES  : false -> use REAL prepared statements sent to
//                       MySQL itself, which is the strongest defense
//                       against SQL injection (vs. client-side emulation).
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // $pdo is the single shared connection object used across
    // register.php, login.php, and every dashboard/module file.
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Never leak raw DB credentials/error strings to the browser.
    // Log the real error server-side, show a generic message to users.
    error_log('[CERTREEFY DB CONNECTION ERROR] ' . $e->getMessage());
    die('Unable to connect to the system at this time. Please try again later.');
}

// ------------------------------------------------------------
// 5. TIMEZONE (optional but recommended for consistent timestamps
//    on permit applications, reports, and audit trails)
// ------------------------------------------------------------
date_default_timezone_set('Asia/Manila');
