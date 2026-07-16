<?php
/** Confirms donation requirements cannot be created outside an approval. */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit_donations.php';

$applicationId = isset($argv[1]) ? (int) $argv[1] : 0;
$actorUserId = isset($argv[2]) ? (int) $argv[2] : 0;
if ($applicationId < 1 || $actorUserId < 1) {
    fwrite(STDERR, "Application and actor IDs are required.\n");
    exit(2);
}

try {
    $pdo->beginTransaction();
    $application = permit_load_application($pdo, $applicationId, true);
    if ($application === null) {
        throw new RuntimeException('Application not found.');
    }
    $policy = permit_donation_policy_for_classification((string) $application['property_classification']);
    create_permit_donation_requirement($pdo, $application, 0, $actorUserId, $policy);
    $pdo->rollBack();
    echo "unexpected-success\n";
    exit(1);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo $e->getMessage() . "\n";
}
