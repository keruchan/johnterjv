<?php
/** CLI helper used by the inspection HTTP validation for the separate status gate. */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit.php';

$applicationId = (int) ($argv[1] ?? 0);
$actorUserId = (int) ($argv[2] ?? 0);

try {
    $result = permit_change_status(
        $pdo,
        $applicationId,
        $actorUserId,
        'application',
        'awaiting_decision',
        'Inspection evidence is complete.'
    );
    echo (string) $result['new_status'];
} catch (Throwable $e) {
    echo $e->getMessage();
}
