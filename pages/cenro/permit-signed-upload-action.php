<?php
/** PRG endpoint for uploading the scanned copy of a signed permit. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_release.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (permit_release_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to record signed permits.');
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
    $file = $_FILES['signed_permit_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $result = permit_attach_signed_permit_scan($pdo, $applicationId, $userId, $_POST, $file);
    $_SESSION['permit_release_flash'] = [
        'type' => 'success',
        'message' => ($result['replaced_previous_scan'] ? 'Signed permit scan replaced for ' : 'Signed permit scan uploaded for ')
            . $result['permit_number'] . '.',
    ];
    $_SESSION['csrf_permit_release_token'] = bin2hex(random_bytes(32));
} catch (PermitDocumentValidationException | PermitReleaseValidationException $e) {
    $_SESSION['permit_release_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT SIGNED UPLOAD ERROR] ' . $e->getMessage());
    $_SESSION['permit_release_flash'] = [
        'type' => 'danger',
        'message' => 'The signed permit could not be recorded at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['permit_release_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: ' . $redirect, true, 303);
exit;
