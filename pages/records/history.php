<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/record_visibility.php';
require_roles($pdo, ['community', 'rps', 'ems', 'superadmin']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { header('Allow: GET'); throw new RecordVisibilityException('Method not allowed.', 405); }
    $type = $_GET['type'] ?? '';
    $id = $_GET['id'] ?? '';
    if (!is_string($type) || !is_string($id) || !ctype_digit($id)) { throw new RecordVisibilityException('Invalid record.'); }
    if (!record_visibility_ready($pdo)) { throw new RecordVisibilityException('Record activity is temporarily unavailable.', 503); }
    echo json_encode(['events' => record_visibility_history($pdo, (int) $_SESSION['id'], $type, (int) $id)], JSON_THROW_ON_ERROR);
} catch (RecordVisibilityException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[CERTREEFY RECORD HISTORY] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load record activity.']);
}
