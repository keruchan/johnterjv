<?php
/** PRG endpoint for Community seedling-request submission and cancellation. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/seedling.php';

require_role($pdo, 'community');

$userId = (int) $_SESSION['id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_seedling_request_submit_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new SeedlingValidationException('Security validation failed. Refresh the page and try again.');
    }

    if ($action === 'submit') {
        $result = seedling_submit_request($pdo, $userId, $_POST);
        $_SESSION['seedling_request_submit_flash'] = [
            'type' => 'success',
            'message' => $result['duplicate']
                ? 'This request was already submitted (reference ' . $result['request_reference'] . ').'
                : 'Seedling request ' . $result['request_reference'] . ' submitted (' . $result['total_requested'] . ' seedling(s)).',
        ];
        $_SESSION['csrf_seedling_request_submit_token'] = bin2hex(random_bytes(32));
    } elseif ($action === 'cancel') {
        $requestValue = trim((string) ($_POST['request_id'] ?? ''));
        $requestId = ctype_digit($requestValue) ? (int) $requestValue : 0;
        if ($requestId < 1) {
            throw new SeedlingValidationException('The seedling request is invalid.');
        }
        seedling_cancel_request($pdo, $requestId, $userId, (string) ($_POST['remarks'] ?? ''));
        $_SESSION['seedling_request_submit_flash'] = ['type' => 'success', 'message' => 'Seedling request withdrawn.'];
    } else {
        throw new SeedlingValidationException('The requested action is not supported.');
    }
} catch (SeedlingValidationException $e) {
    $_SESSION['seedling_request_submit_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING REQUEST SUBMIT ERROR] ' . $e->getMessage());
    $_SESSION['seedling_request_submit_flash'] = [
        'type' => 'danger',
        'message' => 'The seedling request could not be processed at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['seedling_request_submit_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: seedling-requests.php', true, 303);
exit;
