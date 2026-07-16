<?php
/** Authorization-controlled Community owner signed-permit download. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_release.php';

require_role($pdo, 'community');

$applicationValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($applicationValue) || (int) $applicationValue < 1) {
    http_response_code(404);
    exit('The signed permit was not found.');
}

try {
    $document = permit_signed_scan_download_payload($pdo, (int) $applicationValue, (int) $_SESSION['id']);
    if ($document === null) {
        http_response_code(404);
        exit('The signed permit was not found.');
    }
    send_permit_document_download($document);
} catch (RuntimeException $e) {
    error_log('[CERTREEFY PERMIT SIGNED DOWNLOAD ERROR] ' . $e->getMessage());
    http_response_code(404);
    exit('The signed permit is unavailable.');
}
