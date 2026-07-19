<?php
/** Authorization-controlled RPS/permitted-Superadmin evidence-photo download. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';

require_roles($pdo, ['rps', 'superadmin']);

$photoValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($photoValue) || (int) $photoValue < 1) {
    http_response_code(404);
    exit('The evidence photo was not found.');
}

try {
    $photo = illegal_logging_photo_download_payload($pdo, (int) $photoValue, (int) $_SESSION['id']);
    if ($photo === null) {
        http_response_code(404);
        exit('The evidence photo was not found.');
    }
    send_permit_document_download($photo);
} catch (RuntimeException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING PHOTO DOWNLOAD ERROR] ' . $e->getMessage());
    http_response_code(404);
    exit('The evidence photo is unavailable.');
}
