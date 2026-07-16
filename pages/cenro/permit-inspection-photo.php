<?php
/** Authorization-controlled delivery of private site-inspection photographs. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_inspections.php';

require_roles($pdo, ['rps', 'superadmin']);

$photoValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($photoValue) || (int) $photoValue < 1) {
    http_response_code(404);
    exit('The inspection photograph was not found.');
}

try {
    $photo = permit_inspection_photo_download_payload($pdo, (int) $photoValue, (int) $_SESSION['id']);
    if ($photo === null) {
        http_response_code(404);
        exit('The inspection photograph was not found.');
    }
    send_permit_inspection_photo($photo);
} catch (RuntimeException $e) {
    error_log('[CERTREEFY INSPECTION PHOTO ERROR] ' . $e->getMessage());
    http_response_code(404);
    exit('The inspection photograph is unavailable.');
}
