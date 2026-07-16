<?php
/** PRG endpoint for authorized site-inspection workflow actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_inspections.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (permit_inspection_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to manage permit site inspections.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$applicationValue = trim((string) ($_POST['application_id'] ?? ''));
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$redirect = $applicationId > 0
    ? 'permit-application.php?id=' . $applicationId . '#inspection'
    : 'permit-applications.php';
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_inspection_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new PermitInspectionValidationException('Security validation failed. Refresh the page and try again.');
    }
    if ($applicationId < 1) {
        throw new PermitInspectionValidationException('The permit application is invalid.');
    }
    $result = record_permit_inspection_action(
        $pdo,
        $applicationId,
        $userId,
        (string) ($_POST['action'] ?? ''),
        $_POST,
        is_array($_FILES['site_photos'] ?? null) ? $_FILES['site_photos'] : []
    );
    $_SESSION['permit_inspection_flash'] = [
        'type' => 'success',
        'message' => 'Inspection action recorded. Current status: '
            . permit_inspection_status_label((string) $result['new_status']) . '.',
    ];
    $_SESSION['csrf_permit_inspection_token'] = bin2hex(random_bytes(32));
} catch (PermitInspectionValidationException $e) {
    $_SESSION['permit_inspection_flash'] = [
        'type' => 'danger',
        'message' => implode(' ', $e->errors()),
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['permit_inspection_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
} catch (PDOException $e) {
    error_log('[CERTREEFY INSPECTION ACTION ERROR] ' . $e->getMessage());
    $_SESSION['permit_inspection_flash'] = [
        'type' => 'danger',
        'message' => 'The inspection action could not be recorded at this time.',
    ];
}

header('Location: ' . $redirect, true, 303);
exit;
