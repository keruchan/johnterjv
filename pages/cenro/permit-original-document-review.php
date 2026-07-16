<?php
/** Authorized append-only original hardcopy and wet-ink verification endpoint. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_documents.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (permit_original_verification_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to verify original permit documents.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

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
$sessionToken = (string) ($_SESSION['csrf_permit_original_review_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['permit_original_review_flash'] = [
        'type' => 'danger',
        'message' => 'Security validation failed. Please refresh the page and try again.',
    ];
    header('Location: permit-application.php?id=' . $applicationId . '#documents');
    exit;
}

try {
    record_original_document_verification(
        $pdo,
        $applicationId,
        $userId,
        (string) ($_POST['document_type'] ?? ''),
        $_POST
    );
    $_SESSION['csrf_permit_original_review_token'] = bin2hex(random_bytes(32));
    $_SESSION['permit_original_review_flash'] = [
        'type' => 'success',
        'message' => 'Original hardcopy and wet-ink verification recorded successfully.',
    ];
} catch (PermitDocumentValidationException | RuntimeException $e) {
    $_SESSION['permit_original_review_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
} catch (PDOException $e) {
    error_log('[CERTREEFY ORIGINAL DOCUMENT REVIEW ERROR] ' . $e->getMessage());
    $_SESSION['permit_original_review_flash'] = [
        'type' => 'danger',
        'message' => 'Unable to save the original-document verification at this time.',
    ];
}

header('Location: permit-application.php?id=' . $applicationId . '#documents');
exit;
