<?php
/** CENRO Analytics CSV export (RPS/Superadmin). Read-only, GET. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/analytics.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (analytics_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to export analytics.');
}

$range = analytics_normalize_range($_GET);

try {
    $rows = analytics_csv_rows($pdo, $range);
} catch (PDOException $e) {
    error_log('[CERTREEFY ANALYTICS EXPORT ERROR] ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to generate the export at this time.');
}

$suffix = ($range['from'] !== '' || $range['to'] !== '')
    ? ('_' . ($range['from'] !== '' ? $range['from'] : 'start') . '_to_' . ($range['to'] !== '' ? $range['to'] : 'today'))
    : '_all_time';

analytics_send_csv('certreefy_analytics' . $suffix . '.csv', $rows);
