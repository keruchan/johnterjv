<?php
/**
 * CLI end-to-end probe for the seedling inventory and public request program:
 * species creation, stock movements, request submission (cap + validation +
 * idempotency), the approve -> fulfil -> claim lifecycle, stock deduction at
 * fulfilment, authorization boundaries, and transaction rollback.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\seedling_lifecycle_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/seedling.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$emsUserId = 0;
$ownerUserId = 0;
$otherCommunityId = 0;
$rpsUserId = 0;
$createdInventoryIds = [];
$createdRequestIds = [];

function seed_user(PDO $pdo, string $role, string $suffix, string $tag): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_users (fname, lname, email, username, password, contact, role, status)
         VALUES (:fname, :lname, :email, :username, :password, \'09170000000\', :role, \'active\')'
    );
    $stmt->execute([
        ':fname' => ucfirst($tag),
        ':lname' => 'Probe',
        ':email' => $tag . '_' . $suffix . '@certreefy.test',
        ':username' => $tag . '_' . $suffix,
        ':password' => password_hash('probe-' . $suffix, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);

    return (int) $pdo->lastInsertId();
}

function new_submission_key(): string
{
    return hash('sha256', bin2hex(random_bytes(16)) . microtime(true));
}

try {
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');
    $ownerUserId = seed_user($pdo, 'community', $suffix, 'owner');
    $otherCommunityId = seed_user($pdo, 'community', $suffix, 'other');
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');

    echo 'Config: max ' . seedling_max_per_request() . ' seedlings per request.' . PHP_EOL;
    check('default per-request cap is 50', seedling_max_per_request() === 50);

    // ---- Inventory: species creation + opening stock ----
    echo 'Inventory' . PHP_EOL;
    $narra = seedling_create_species($pdo, $emsUserId, [
        'common_name' => 'Narra Probe ' . $suffix,
        'scientific_name' => 'Pterocarpus indicus',
        'available_quantity' => '100',
        'low_stock_threshold' => '10',
    ]);
    $createdInventoryIds[] = $narra['inventory_id'];
    check('species created with opening stock 100', $narra['available_quantity'] === 100);

    $molave = seedling_create_species($pdo, $emsUserId, [
        'common_name' => 'Molave Probe ' . $suffix,
        'available_quantity' => '20',
        'low_stock_threshold' => '25',
    ]);
    $createdInventoryIds[] = $molave['inventory_id'];

    $openingMovement = seedling_stock_movements($pdo, 5, $narra['inventory_id']);
    check('opening stock recorded as an incoming movement',
        count($openingMovement) === 1
        && (string) $openingMovement[0]['movement_type'] === 'incoming'
        && (int) $openingMovement[0]['quantity_delta'] === 100
        && (int) $openingMovement[0]['quantity_after'] === 100);

    // Duplicate species rejected.
    $dupRejected = false;
    try {
        seedling_create_species($pdo, $emsUserId, ['common_name' => 'Narra Probe ' . $suffix, 'available_quantity' => '5']);
    } catch (SeedlingValidationException $e) {
        $dupRejected = str_contains($e->getMessage(), 'already in the inventory');
    }
    check('duplicate species rejected', $dupRejected);

    // Non-EMS cannot manage inventory.
    $communityInventoryDenied = false;
    try {
        seedling_create_species($pdo, $ownerUserId, ['common_name' => 'Illegal ' . $suffix, 'available_quantity' => '1']);
    } catch (RuntimeException $e) {
        $communityInventoryDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot manage inventory', $communityInventoryDenied);

    // Incoming stock + correction.
    $received = seedling_adjust_stock($pdo, $emsUserId, [
        'inventory_id' => (string) $narra['inventory_id'],
        'movement_type' => 'incoming',
        'quantity' => '50',
        'reason' => 'Delivery',
    ]);
    check('incoming stock raises available to 150', $received['quantity_after'] === 150);

    $corrected = seedling_adjust_stock($pdo, $emsUserId, [
        'inventory_id' => (string) $narra['inventory_id'],
        'movement_type' => 'adjustment',
        'quantity' => '-20',
        'reason' => 'Damaged in storage',
    ]);
    check('negative correction lowers available to 130', $corrected['quantity_after'] === 130);

    // A correction cannot drive stock negative.
    $negativeRejected = false;
    try {
        seedling_adjust_stock($pdo, $emsUserId, [
            'inventory_id' => (string) $narra['inventory_id'],
            'movement_type' => 'adjustment',
            'quantity' => '-9999',
            'reason' => 'Over-correction',
        ]);
    } catch (SeedlingValidationException $e) {
        $negativeRejected = str_contains($e->getMessage(), 'Insufficient stock');
    }
    check('stock cannot be driven negative', $negativeRejected);
    check('failed correction left stock at 130',
        (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'] === 130);

    // A correction requires a reason.
    $reasonRequired = false;
    try {
        seedling_adjust_stock($pdo, $emsUserId, [
            'inventory_id' => (string) $narra['inventory_id'],
            'movement_type' => 'adjustment',
            'quantity' => '-1',
            'reason' => '',
        ]);
    } catch (SeedlingValidationException $e) {
        $reasonRequired = str_contains($e->getMessage(), 'requires a reason');
    }
    check('stock correction requires a reason', $reasonRequired);

    // Low-stock detection.
    $summary = seedling_inventory_summary($pdo);
    check('summary counts low-stock species (Molave 20 <= 25)', $summary['low_stock_species'] >= 1);
    check('summary totals available stock', $summary['total_available'] >= 150);

    // ---- Request submission ----
    echo 'Request submission' . PHP_EOL;

    // Cap enforced.
    $capRejected = false;
    try {
        seedling_submit_request($pdo, $ownerUserId, [
            'planting_purpose' => 'Reforestation', 'planting_location' => 'Barangay probe',
            'submission_key' => new_submission_key(),
            'inventory_id' => [(string) $narra['inventory_id']],
            'quantity' => ['51'],
        ]);
    } catch (SeedlingValidationException $e) {
        $capRejected = str_contains($e->getMessage(), 'may not exceed 50');
    }
    check('per-request cap of 50 enforced', $capRejected);

    // Zero/blank quantity rejected.
    $zeroRejected = false;
    try {
        seedling_submit_request($pdo, $ownerUserId, [
            'planting_purpose' => 'Reforestation', 'planting_location' => 'Barangay probe',
            'submission_key' => new_submission_key(),
            'inventory_id' => [(string) $narra['inventory_id']],
            'quantity' => ['0'],
        ]);
    } catch (SeedlingValidationException $e) {
        $zeroRejected = true;
    }
    check('zero quantity rejected', $zeroRejected);

    // Empty request rejected.
    $emptyRejected = false;
    try {
        seedling_submit_request($pdo, $ownerUserId, [
            'planting_purpose' => 'Reforestation', 'planting_location' => 'Barangay probe',
            'submission_key' => new_submission_key(),
            'inventory_id' => [], 'quantity' => [],
        ]);
    } catch (SeedlingValidationException $e) {
        $emptyRejected = str_contains($e->getMessage(), 'at least one seedling species');
    }
    check('empty request rejected', $emptyRejected);

    // Non-community cannot submit.
    $emsSubmitDenied = false;
    try {
        seedling_submit_request($pdo, $emsUserId, [
            'planting_purpose' => 'x', 'planting_location' => 'y',
            'submission_key' => new_submission_key(),
            'inventory_id' => [(string) $narra['inventory_id']], 'quantity' => ['1'],
        ]);
    } catch (RuntimeException $e) {
        $emsSubmitDenied = str_contains($e->getMessage(), 'active Community account');
    }
    check('EMS cannot submit a Community request', $emsSubmitDenied);

    // Valid multi-species request.
    $key = new_submission_key();
    $request = seedling_submit_request($pdo, $ownerUserId, [
        'planting_purpose' => 'Backyard reforestation',
        'planting_location' => 'Barangay Probe, Sta. Cruz',
        'preferred_pickup_date' => date('Y-m-d', strtotime('+7 days')),
        'submission_key' => $key,
        'inventory_id' => [(string) $narra['inventory_id'], (string) $molave['inventory_id']],
        'quantity' => ['30', '10'],
    ]);
    $createdRequestIds[] = $request['request_id'];
    check('multi-species request submitted (40 total)', $request['total_requested'] === 40);
    check('request reference uses SR-YYYY-###### format',
        (bool) preg_match('/^SR-\d{4}-\d{6}$/', $request['request_reference']));
    check('new request starts as submitted',
        (string) seedling_request_for_actor($pdo, $request['request_id'], $emsUserId)['current_status'] === 'submitted');

    // Idempotency: same submission key returns the same request.
    $replay = seedling_submit_request($pdo, $ownerUserId, [
        'planting_purpose' => 'Backyard reforestation',
        'planting_location' => 'Barangay Probe, Sta. Cruz',
        'submission_key' => $key,
        'inventory_id' => [(string) $narra['inventory_id']],
        'quantity' => ['5'],
    ]);
    check('duplicate submission_key is idempotent',
        $replay['duplicate'] === true && $replay['request_id'] === $request['request_id']);
    check('idempotent replay created no second request',
        count(seedling_requests_for_requester($pdo, $ownerUserId)) === 1);

    // EMS was notified of the new request.
    $emsNotified = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_notifications WHERE entity_type = \'seedling_request\''
        . ' AND entity_id = ' . $request['request_id'] . ' AND recipient_user_id = ' . $emsUserId
    )->fetchColumn();
    check('EMS notified of the new request', $emsNotified === 1);

    // Stock is untouched by submission.
    check('submission does not deduct stock',
        (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'] === 130);

    // ---- Authorization on reads ----
    check('owner can read own request', seedling_request_for_actor($pdo, $request['request_id'], $ownerUserId) !== null);
    check('EMS can read any request', seedling_request_for_actor($pdo, $request['request_id'], $emsUserId) !== null);
    check('non-owner Community denied', seedling_request_for_actor($pdo, $request['request_id'], $otherCommunityId) === null);
    check('RPS denied (module is EMS/Community only)', seedling_request_for_actor($pdo, $request['request_id'], $rpsUserId) === null);

    // ---- Lifecycle ----
    echo 'Lifecycle' . PHP_EOL;

    // Cannot fulfil before approval.
    $prematureFulfil = false;
    try {
        seedling_process_request($pdo, $request['request_id'], $emsUserId, 'fulfil', []);
    } catch (SeedlingValidationException $e) {
        $prematureFulfil = str_contains($e->getMessage(), 'cannot become');
    }
    check('cannot fulfil a submitted request', $prematureFulfil);

    seedling_process_request($pdo, $request['request_id'], $emsUserId, 'begin_review', []);
    check('begin_review -> under_review',
        (string) seedling_request_for_actor($pdo, $request['request_id'], $emsUserId)['current_status'] === 'under_review');

    // Approve, reducing Narra 30 -> 25.
    $items = seedling_request_items($pdo, $request['request_id']);
    $approvedInput = [];
    foreach ($items as $item) {
        $approvedInput[(int) $item['id']] = str_contains((string) $item['common_name'], 'Narra') ? '25' : (string) $item['quantity_requested'];
    }
    $approve = seedling_process_request($pdo, $request['request_id'], $emsUserId, 'approve', [
        'quantity_approved' => $approvedInput,
        'remarks' => 'Approved with reduced Narra.',
    ]);
    check('approve records reduced total (25+10=35)', $approve['total_approved'] === 35);
    check('approval does not deduct stock yet',
        (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'] === 130);

    // Approved quantity may not exceed requested.
    // (fresh request to test the guard without disturbing the main one)
    $key2 = new_submission_key();
    $req2 = seedling_submit_request($pdo, $ownerUserId, [
        'planting_purpose' => 'Guard test', 'planting_location' => 'Barangay Probe',
        'submission_key' => $key2,
        'inventory_id' => [(string) $molave['inventory_id']], 'quantity' => ['5'],
    ]);
    $createdRequestIds[] = $req2['request_id'];
    $req2Items = seedling_request_items($pdo, $req2['request_id']);
    $overApprove = false;
    try {
        seedling_process_request($pdo, $req2['request_id'], $emsUserId, 'approve', [
            'quantity_approved' => [(int) $req2Items[0]['id'] => '99'],
        ]);
    } catch (SeedlingValidationException $e) {
        $overApprove = str_contains($e->getMessage(), 'cannot exceed the requested amount');
    }
    check('approved quantity cannot exceed requested', $overApprove);

    // Fulfil the main request: deducts 25 Narra + 10 Molave.
    $fulfil = seedling_process_request($pdo, $request['request_id'], $emsUserId, 'fulfil', []);
    check('fulfil deducts the approved total (35)', $fulfil['total_deducted'] === 35);
    check('Narra stock 130 -> 105 after fulfilment',
        (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'] === 105);
    check('Molave stock 20 -> 10 after fulfilment',
        (int) seedling_inventory_find($pdo, $molave['inventory_id'])['available_quantity'] === 10);
    check('request now ready_for_pickup',
        (string) seedling_request_for_actor($pdo, $request['request_id'], $emsUserId)['current_status'] === 'ready_for_pickup');

    // Released movements are linked to the request.
    $released = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_seedling_stock_movements WHERE request_id = ' . $request['request_id']
        . ' AND movement_type = \'released\''
    )->fetchColumn();
    check('two released movements linked to the request', $released === 2);

    // Claim.
    $claim = seedling_process_request($pdo, $request['request_id'], $emsUserId, 'claim', [
        'claimed_by_name' => 'Juan Probe',
        'claimed_on' => date('Y-m-d'),
    ]);
    check('claim records the claimant', $claim['claimed_by_name'] === 'Juan Probe');
    $claimed = seedling_request_for_actor($pdo, $request['request_id'], $emsUserId);
    check('request now claimed (terminal)', (string) $claimed['current_status'] === 'claimed');
    check('claim date stored', (string) $claimed['claimed_on'] === date('Y-m-d'));

    // Future claim date rejected (on a fresh ready request).
    $futureClaimRejected = false;
    try {
        seedling_process_request($pdo, $request['request_id'], $emsUserId, 'claim', [
            'claimed_by_name' => 'X', 'claimed_on' => date('Y-m-d', strtotime('+2 days')),
        ]);
    } catch (SeedlingValidationException $e) {
        $futureClaimRejected = true; // also blocked by terminal-state guard
    }
    check('a claimed request cannot be claimed again', $futureClaimRejected);

    // Status history captured every transition.
    $history = seedling_request_history($pdo, $request['request_id']);
    $statuses = array_column($history, 'new_status');
    check('status history records the full path',
        $statuses === ['submitted', 'under_review', 'approved', 'ready_for_pickup', 'claimed']);

    // Requester notified at each meaningful step (approved, ready, claimed = 3).
    $requesterNotes = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_notifications WHERE entity_type = \'seedling_request\''
        . ' AND entity_id = ' . $request['request_id'] . ' AND recipient_user_id = ' . $ownerUserId
    )->fetchColumn();
    check('requester notified on approve/ready/claim (3)', $requesterNotes === 3);

    // Audit entries under the seedling category.
    $auditCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_audit_trail WHERE category = \'seedling\' AND entity_type = \'seedling_request\''
        . ' AND entity_id = ' . $request['request_id']
    )->fetchColumn();
    check('every request transition audited', $auditCount >= 4);

    // ---- Decline + cancel ----
    echo 'Decline and cancel' . PHP_EOL;
    $declineReasonRequired = false;
    try {
        seedling_process_request($pdo, $req2['request_id'], $emsUserId, 'decline', ['remarks' => '']);
    } catch (SeedlingValidationException $e) {
        $declineReasonRequired = str_contains($e->getMessage(), 'requires a reason');
    }
    check('decline requires a reason', $declineReasonRequired);

    seedling_process_request($pdo, $req2['request_id'], $emsUserId, 'decline', ['remarks' => 'Out of season.']);
    check('declined is terminal',
        (string) seedling_request_for_actor($pdo, $req2['request_id'], $emsUserId)['current_status'] === 'declined');
    check('decline did not deduct stock',
        (int) seedling_inventory_find($pdo, $molave['inventory_id'])['available_quantity'] === 10);

    $key3 = new_submission_key();
    $req3 = seedling_submit_request($pdo, $ownerUserId, [
        'planting_purpose' => 'Cancel test', 'planting_location' => 'Barangay Probe',
        'submission_key' => $key3,
        'inventory_id' => [(string) $narra['inventory_id']], 'quantity' => ['3'],
    ]);
    $createdRequestIds[] = $req3['request_id'];

    // A different Community user cannot cancel someone else's request.
    $foreignCancelDenied = false;
    try {
        seedling_cancel_request($pdo, $req3['request_id'], $otherCommunityId, 'nope');
    } catch (RuntimeException $e) {
        $foreignCancelDenied = str_contains($e->getMessage(), 'your own');
    }
    check('non-owner cannot cancel another user request', $foreignCancelDenied);

    seedling_cancel_request($pdo, $req3['request_id'], $ownerUserId, 'Changed my mind.');
    check('owner can cancel before approval',
        (string) seedling_request_for_actor($pdo, $req3['request_id'], $ownerUserId)['current_status'] === 'cancelled');

    // ---- Rollback: a forced failure during fulfilment restores stock ----
    echo 'Rollback' . PHP_EOL;
    $key4 = new_submission_key();
    $req4 = seedling_submit_request($pdo, $ownerUserId, [
        'planting_purpose' => 'Rollback test', 'planting_location' => 'Barangay Probe',
        'submission_key' => $key4,
        'inventory_id' => [(string) $narra['inventory_id']], 'quantity' => ['5'],
    ]);
    $createdRequestIds[] = $req4['request_id'];
    $req4Items = seedling_request_items($pdo, $req4['request_id']);
    seedling_process_request($pdo, $req4['request_id'], $emsUserId, 'approve', [
        'quantity_approved' => [(int) $req4Items[0]['id'] => '5'],
    ]);
    $stockBefore = (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'];

    $trigger = 'trg_seedling_probe_' . $suffix;
    $pdo->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON tbl_seedling_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced seedling rollback'");
    $rolledBack = false;
    try {
        seedling_process_request($pdo, $req4['request_id'], $emsUserId, 'fulfil', []);
    } catch (Throwable $e) {
        $rolledBack = true;
    }
    $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
    check('forced failure during fulfilment threw', $rolledBack);
    check('stock restored by rollback',
        (int) seedling_inventory_find($pdo, $narra['inventory_id'])['available_quantity'] === $stockBefore);
    check('no released movement survived the rollback',
        (int) $pdo->query('SELECT COUNT(*) FROM tbl_seedling_stock_movements WHERE request_id = ' . $req4['request_id'])->fetchColumn() === 0);
    check('request still approved after rollback',
        (string) seedling_request_for_actor($pdo, $req4['request_id'], $emsUserId)['current_status'] === 'approved');

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    try { $pdo->exec('DROP TRIGGER IF EXISTS trg_seedling_probe_' . $suffix); } catch (Throwable $e) {}
    foreach ($createdRequestIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_notifications WHERE entity_type = \'seedling_request\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_stock_movements WHERE request_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_request_status_history WHERE request_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_request_items WHERE request_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_requests WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) { echo 'cleanup request ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ($createdInventoryIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_seedling_stock_movements WHERE inventory_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_inventory WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) { echo 'cleanup inventory ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$emsUserId, $ownerUserId, $otherCommunityId, $rpsUserId] as $uid) {
        if ($uid > 0) {
            try {
                $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_notifications WHERE recipient_user_id = :rid OR created_by_user_id = :cid')->execute([':rid' => $uid, ':cid' => $uid]);
                $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $uid]);
            } catch (Throwable $e) { echo 'cleanup user ' . $uid . ': ' . $e->getMessage() . PHP_EOL; }
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
