<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/record_visibility.php';
require_roles($pdo, ['community', 'rps', 'ems', 'superadmin']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new RecordVisibilityException('Method not allowed.', 405); }
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_record_visibility_token'] ?? '';
    if (!is_string($token) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        throw new RecordVisibilityException('Security validation failed. Refresh the page and try again.', 403);
    }
    foreach (['entity_type', 'entity_id', 'action', 'reason', 'request_key', 'version'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { throw new RecordVisibilityException('Invalid record action.'); }
    }
    if (!ctype_digit($_POST['entity_id']) || !ctype_digit($_POST['version'])) { throw new RecordVisibilityException('Invalid record identifier or version.'); }
    $result = record_visibility_change($pdo, (int) $_SESSION['id'], $_POST['entity_type'], (int) $_POST['entity_id'], $_POST['action'], $_POST['reason'], $_POST['request_key'], (int) $_POST['version']);
    echo json_encode(['ok' => true] + $result);
} catch (RecordVisibilityException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[CERTREEFY RECORD VISIBILITY] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The action could not be completed. Please try again.']);
}
