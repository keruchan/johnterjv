<?php
/** PRG endpoint for recording Tree Cutting Permit cutting completion. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_release.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (permit_release_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to record cutting completion.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$applicationValue = trim((string) ($_POST['application_id'] ?? ''));
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$redirect = $applicationId > 0
    ? 'permit-application.php?id=' . $applicationId . '#release-workflow'
    : 'permit-applications.php';
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_release_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new PermitReleaseValidationException('Security validation failed. Refresh the page and try again.');
    }
    $evidenceFiles = $_FILES['completion_evidence'] ?? [];
    $result = permit_record_cutting_completion($pdo, $applicationId, $userId, $_POST, $evidenceFiles);
    $_SESSION['permit_release_flash'] = [
        'type' => 'success',
        'message' => 'Cutting completion recorded (' . str_replace('_', ' ', $result['completion_status'])
            . ', ' . $result['trees_cut_count'] . ' tree(s) cut'
            . ($result['evidence_count'] > 0 ? ', ' . $result['evidence_count'] . ' evidence photo(s) attached' : '')
            . '). The permit transaction is completed.',
    ];
    $_SESSION['csrf_permit_release_token'] = bin2hex(random_bytes(32));
} catch (PermitReleaseValidationException $e) {
    $_SESSION['permit_release_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT COMPLETION ERROR] ' . $e->getMessage());
    $_SESSION['permit_release_flash'] = [
        'type' => 'danger',
        'message' => 'The cutting completion could not be recorded at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['permit_release_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: ' . $redirect, true, 303);
exit;
