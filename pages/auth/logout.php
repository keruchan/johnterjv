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
 * - Is a CSRF-protected POST action (not a plain GET link), so a cross-site
 *   request cannot force a visitor's session to end.
 * - Clears all session variables, destroys server-side session data, and
 *   expires the browser's session cookie.
 * - Redirects after logout so protected pages are not left in a state that
 *   could be accidentally resubmitted or reused.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_logout_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    exit('Security validation failed. Please return to the page you were on and try again.');
}

$actorUserId = filter_var($_SESSION['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($actorUserId !== false && $actorUserId !== null) {
    try {
        record_audit_event(
            $pdo,
            (int) $actorUserId,
            'authentication',
            'logout',
            'user',
            (int) $actorUserId,
            'Authenticated user logout.',
            ['role' => (string) ($_SESSION['role'] ?? '')]
        );
    } catch (Throwable $e) {
        // A logging failure must never prevent the browser session from ending.
        error_log('[CERTREEFY LOGOUT AUDIT ERROR] ' . $e->getMessage());
    }
}

destroy_authentication_session();

header('Location: login.php');
exit;
