<?php
/** Authorization-controlled RPS/authorized-Superadmin completion-evidence download. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_release.php';

require_roles($pdo, ['rps', 'superadmin']);

$evidenceValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($evidenceValue) || (int) $evidenceValue < 1) {
    http_response_code(404);
    exit('The completion evidence file was not found.');
}

try {
    $evidence = permit_cutting_completion_evidence_download_payload($pdo, (int) $evidenceValue, (int) $_SESSION['id']);
    if ($evidence === null) {
        http_response_code(404);
        exit('The completion evidence file was not found.');
    }
    send_permit_document_download($evidence);
} catch (RuntimeException $e) {
    error_log('[CERTREEFY COMPLETION EVIDENCE DOWNLOAD ERROR] ' . $e->getMessage());
    http_response_code(404);
    exit('The completion evidence file is unavailable.');
}
