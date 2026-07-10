<?php
/**
 * ============================================================
 * File     : pages/auth/logout.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Securely end the current user session and return
 *            the browser to the login page.
 *
 * Security notes:
 * - Includes config.php so the same hardened session settings are used.
 * - Clears all session variables, destroys server-side session data, and
 *   expires the browser's session cookie.
 * - Redirects after logout so protected pages are not left in a state that
 *   could be accidentally resubmitted or reused.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';

// Remove all application session values from memory first.
$_SESSION = [];

// Expire the session cookie in the browser. session_destroy() deletes the
// server-side session, but the browser cookie must also be cleared so it
// does not keep sending an old session ID on later requests.
if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $cookieParams['path'] ?? '/',
        'domain'   => $cookieParams['domain'] ?? '',
        'secure'   => (bool) ($cookieParams['secure'] ?? false),
        'httponly' => (bool) ($cookieParams['httponly'] ?? true),
        'samesite' => $cookieParams['samesite'] ?? 'Lax',
    ]);
}

// Destroy the server-side session storage after the values and cookie are
// cleared. The active session was started by config.php.
session_destroy();

header('Location: login.php');
exit;
