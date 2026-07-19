<?php
/**
 * JSON feed for the shared notification bell. Owner-scoped, cursor-paginated.
 * GET params: before (id cursor, optional), limit (optional).
 * Returns: { unread_count, items: [...], has_more }.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_roles($pdo, ['community', 'rps', 'superadmin', 'ems']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$userId = (int) $_SESSION['id'];
$role = (string) $_SESSION['role'];
$before = (int) filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
$limit = (int) filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 12, 'min_range' => 1, 'max_range' => 30]]);

try {
    $list = notification_list_for_user($pdo, $userId, $before, $limit);
    $unread = notification_unread_count($pdo, $userId);
} catch (PDOException $e) {
    error_log('[CERTREEFY NOTIFICATION FEED ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load notifications.']);
    exit;
}

$items = array_map(static function (array $n) use ($role): array {
    $entityId = $n['entity_id'] !== null ? (int) $n['entity_id'] : null;

    return [
        'id' => (int) $n['id'],
        'title' => (string) $n['title'],
        'message' => (string) $n['message'],
        'type' => (string) $n['notification_type'],
        'icon' => notification_icon((string) $n['notification_type']),
        'accent' => notification_accent((string) $n['notification_type']),
        'unread' => $n['read_at'] === null,
        'time' => notification_relative_time((string) $n['created_at']),
        'full_time' => date('M j, Y g:i A', strtotime((string) $n['created_at'])),
        'route' => notification_route($role, $n['entity_type'], $entityId),
    ];
}, $list['items']);

echo json_encode([
    'unread_count' => $unread,
    'items' => $items,
    'has_more' => $list['has_more'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
