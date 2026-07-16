<?php
/** Community-only scanned permit document upload endpoint. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_documents.php';

require_role($pdo, 'community');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['id'];
$applicationValue = trim((string) ($_POST['application_id'] ?? ''));
if (!ctype_digit($applicationValue) || (int) $applicationValue < 1) {
    http_response_code(404);
    exit('The permit application was not found.');
}
$applicationId = (int) $applicationValue;

if (permit_document_application_for_actor($pdo, $applicationId, $userId, 'view') === null) {
    http_response_code(404);
    exit('The permit application was not found.');
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_document_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['permit_document_flash'] = [
        'type' => 'danger',
        'message' => 'Security validation failed. Please refresh the page and try again.',
    ];
    header('Location: permit-application.php?id=' . $applicationId . '#documents');
    exit;
}

try {
    $result = upload_permit_document(
        $pdo,
        $applicationId,
        $userId,
        (string) ($_POST['document_type'] ?? ''),
        is_array($_FILES['document_file'] ?? null) ? $_FILES['document_file'] : []
    );
    $_SESSION['csrf_permit_document_token'] = bin2hex(random_bytes(32));
    $_SESSION['permit_document_flash'] = [
        'type' => 'success',
        'message' => !empty($result['replaced_document_id'])
            ? 'Replacement scan uploaded successfully and is pending RPS review.'
            : 'Document scan uploaded successfully and is pending RPS review.',
    ];
} catch (PermitDocumentValidationException | RuntimeException $e) {
    $_SESSION['permit_document_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT DOCUMENT UPLOAD ERROR] ' . $e->getMessage());
    $_SESSION['permit_document_flash'] = [
        'type' => 'danger',
        'message' => 'Unable to upload the document at this time. Please try again later.',
    ];
}

header('Location: permit-application.php?id=' . $applicationId . '#documents');
exit;
