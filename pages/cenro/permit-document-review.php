<?php
/** RPS-only online scanned-document review endpoint. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_documents.php';

require_role($pdo, 'rps');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$documentValue = trim((string) ($_POST['document_id'] ?? ''));
if (!ctype_digit($documentValue) || (int) $documentValue < 1) {
    http_response_code(404);
    exit('The permit document was not found.');
}
$documentId = (int) $documentValue;
$document = permit_document_for_actor($pdo, $documentId, (int) $_SESSION['id']);
if ($document === null) {
    http_response_code(404);
    exit('The permit document was not found.');
}
$applicationId = (int) $document['application_id'];

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_document_review_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['permit_document_review_flash'] = [
        'type' => 'danger',
        'message' => 'Security validation failed. Please refresh the page and try again.',
    ];
    header('Location: permit-application.php?id=' . $applicationId . '#documents');
    exit;
}

try {
    review_permit_document(
        $pdo,
        $documentId,
        (int) $_SESSION['id'],
        (string) ($_POST['review_status'] ?? ''),
        (string) ($_POST['review_notes'] ?? '')
    );
    $_SESSION['csrf_permit_document_review_token'] = bin2hex(random_bytes(32));
    $_SESSION['permit_document_review_flash'] = [
        'type' => 'success',
        'message' => 'Online document review saved successfully.',
    ];
} catch (PermitDocumentValidationException | RuntimeException $e) {
    $_SESSION['permit_document_review_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT DOCUMENT REVIEW ERROR] ' . $e->getMessage());
    $_SESSION['permit_document_review_flash'] = [
        'type' => 'danger',
        'message' => 'Unable to save the document review at this time. Please try again later.',
    ];
}

header('Location: permit-application.php?id=' . $applicationId . '#documents');
exit;
