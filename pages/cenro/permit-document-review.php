<?php
/** RPS-only one-click online scanned-document review endpoint. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_documents.php';

require_role($pdo, 'rps');

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
if (permit_document_application_for_actor($pdo, $applicationId, (int) $_SESSION['id'], 'view') === null) {
    http_response_code(404);
    exit('The permit application was not found.');
}

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
    $result = review_permit_documents_batch(
        $pdo,
        $applicationId,
        (int) $_SESSION['id'],
        is_array($_POST['replace'] ?? null) ? $_POST['replace'] : [],
        (string) ($_POST['review_notes'] ?? '')
    );
    $_SESSION['csrf_permit_document_review_token'] = bin2hex(random_bytes(32));
    $_SESSION['permit_document_review_flash'] = [
        'type' => $result['approved'] ? 'success' : 'warning',
        'message' => $result['approved']
            ? 'All ' . (int) $result['reviewed_count'] . ' submitted scan(s) approved. The applicant was asked to bring the original documents.'
            : 'Replacement requested for ' . (int) $result['replacement_count'] . ' document(s). The applicant was notified.',
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
