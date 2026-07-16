<?php
/** CLI probe confirming generic status changes cannot bypass receipt integrity. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit.php';

$applicationId = isset($argv[1]) ? (int) $argv[1] : 0;
$actorUserId = isset($argv[2]) ? (int) $argv[2] : 0;

try {
    permit_change_status(
        $pdo,
        $applicationId,
        $actorUserId,
        'donation',
        'ems_verified',
        'Generic bypass probe.'
    );
    echo 'unexpected';
} catch (Throwable $e) {
    echo $e->getMessage();
}
