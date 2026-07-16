<?php
/** PRG endpoint for authorized RPS permit review and decision actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_decisions.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (permit_decision_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to review or decide permit applications.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$applicationValue = trim((string) ($_POST['application_id'] ?? ''));
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$redirect = $applicationId > 0
    ? 'permit-application.php?id=' . $applicationId . '#decision-review'
    : 'permit-applications.php';
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_decision_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new PermitDecisionValidationException('Security validation failed. Refresh the page and try again.');
    }
    if ($applicationId < 1) {
        throw new PermitDecisionValidationException('The permit application is invalid.');
    }
    $result = record_permit_review_action(
        $pdo,
        $applicationId,
        $userId,
        (string) ($_POST['action'] ?? ''),
        $_POST
    );
    $_SESSION['permit_decision_flash'] = [
        'type' => 'success',
        'message' => permit_decision_event_label((string) $result['event'])
            . ' was recorded successfully.',
    ];
    $_SESSION['csrf_permit_decision_token'] = bin2hex(random_bytes(32));
} catch (PermitDecisionValidationException $e) {
    $_SESSION['permit_decision_flash'] = [
        'type' => 'danger',
        'message' => implode(' ', $e->errors()),
    ];
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT DECISION ERROR] ' . $e->getMessage());
    $_SESSION['permit_decision_flash'] = [
        'type' => 'danger',
        'message' => 'The review or decision action could not be recorded at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['permit_decision_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
}

header('Location: ' . $redirect, true, 303);
exit;
