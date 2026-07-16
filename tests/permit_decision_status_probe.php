<?php
/** Confirms the generic status service cannot bypass the decision writer. */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit.php';

$applicationId = isset($argv[1]) ? (int) $argv[1] : 0;
$actorUserId = isset($argv[2]) ? (int) $argv[2] : 0;
if ($applicationId < 1 || $actorUserId < 1) {
    fwrite(STDERR, "Application and actor IDs are required.\n");
    exit(2);
}

try {
    permit_change_status($pdo, $applicationId, $actorUserId, 'decision', 'approved', 'Bypass probe');
    echo "unexpected-success\n";
    exit(1);
} catch (RuntimeException $e) {
    echo $e->getMessage() . "\n";
}
