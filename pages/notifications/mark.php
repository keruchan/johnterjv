<?php
/**
 * Marks/removes notifications for the current user. POST + CSRF.
 * Body: action=all               -> mark every unread read
 *       action=unread, id=<n>    -> mark one unread
 *       action=delete, id=<n>    -> delete one
 *       id=<n> (action omitted)  -> mark one read (must belong to the user)
 * All single-notification actions require the notification to belong to
 * the current user (recipient_user_id), enforced in includes/notifications.php.
 * Returns: { ok, unread_count }.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_roles($pdo, ['community', 'rps', 'superadmin', 'ems']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_notif_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Security validation failed.']);
    exit;
}

$userId = (int) $_SESSION['id'];
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'all') {
        notification_mark_all_read($pdo, $userId);
    } else {
        $id = (int) filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid notification is required.']);
            exit;
        }
        if ($action === 'unread') {
            notification_mark_unread($pdo, $userId, $id);
        } elseif ($action === 'delete') {
            notification_delete($pdo, $userId, $id);
        } else {
            notification_mark_read($pdo, $userId, $id);
        }
    }
    $unread = notification_unread_count($pdo, $userId);
} catch (PDOException $e) {
    error_log('[CERTREEFY NOTIFICATION MARK ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to update notifications.']);
    exit;
}

echo json_encode(['ok' => true, 'unread_count' => $unread]);
