<?php
/** PRG endpoint for CENRO illegal-logging report processing actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (illegal_logging_processor_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to process illegal-logging reports.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$reportValue = trim((string) ($_POST['report_id'] ?? ''));
$reportId = ctype_digit($reportValue) ? (int) $reportValue : 0;
$redirect = $reportId > 0
    ? 'illegal-logging-report-detail.php?id=' . $reportId
    : 'illegal-logging-reports.php';
$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_illegal_logging_process_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new IllegalLoggingValidationException('Security validation failed. Refresh the page and try again.');
    }
    if ($reportId < 1) {
        throw new IllegalLoggingValidationException('The report is invalid.');
    }

    $result = illegal_logging_process_report($pdo, $reportId, $userId, $action, $_POST);
    $messages = [
        'under_review' => 'Report marked as under review.',
        'field_verification' => 'Field team dispatched.',
        'resolved' => 'Report resolved: ' . illegal_logging_resolution_outcome_label((string) ($result['resolution_outcome'] ?? '')) . '.',
    ];
    $_SESSION['illegal_logging_process_flash'] = [
        'type' => 'success',
        'message' => $messages[$result['status']] ?? 'Report updated.',
    ];
    $_SESSION['csrf_illegal_logging_process_token'] = bin2hex(random_bytes(32));
} catch (IllegalLoggingValidationException $e) {
    $_SESSION['illegal_logging_process_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING PROCESS ERROR] ' . $e->getMessage());
    $_SESSION['illegal_logging_process_flash'] = [
        'type' => 'danger',
        'message' => 'The report could not be updated at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['illegal_logging_process_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: ' . $redirect, true, 303);
exit;
