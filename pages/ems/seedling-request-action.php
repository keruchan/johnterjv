<?php
/** PRG endpoint for EMS seedling-request processing actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/seedling.php';

require_role($pdo, 'ems');

$userId = (int) $_SESSION['id'];
if (seedling_ems_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to process seedling requests.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$requestValue = trim((string) ($_POST['request_id'] ?? ''));
$requestId = ctype_digit($requestValue) ? (int) $requestValue : 0;
$redirect = $requestId > 0
    ? 'seedling-request-detail.php?id=' . $requestId
    : 'seedling-requests.php';
$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_seedling_request_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new SeedlingValidationException('Security validation failed. Refresh the page and try again.');
    }
    if ($requestId < 1) {
        throw new SeedlingValidationException('The seedling request is invalid.');
    }

    $result = seedling_process_request($pdo, $requestId, $userId, $action, $_POST);
    $messages = [
        'under_review' => 'Request marked as under review.',
        'approved' => 'Request approved (' . ($result['total_approved'] ?? 0) . ' seedling(s)).',
        'declined' => 'Request declined.',
        'ready_for_pickup' => 'Stock reserved (' . ($result['total_deducted'] ?? 0) . ' seedling(s)); ready for pickup.',
        'claimed' => 'Pickup recorded for ' . ($result['claimed_by_name'] ?? 'the claimant') . '.',
    ];
    $_SESSION['seedling_request_flash'] = [
        'type' => 'success',
        'message' => $messages[$result['status']] ?? 'Request updated.',
    ];
    $_SESSION['csrf_seedling_request_token'] = bin2hex(random_bytes(32));
} catch (SeedlingValidationException $e) {
    $_SESSION['seedling_request_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING REQUEST ACTION ERROR] ' . $e->getMessage());
    $_SESSION['seedling_request_flash'] = [
        'type' => 'danger',
        'message' => 'The request could not be updated at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['seedling_request_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: ' . $redirect, true, 303);
exit;
