<?php
/**
 * CLI probe for the signed-permit scan record: metadata guards, download-payload
 * authorization (RPS, owner Community, non-owner Community), and cleanup.
 *
 * The full upload path relies on move_uploaded_file()/is_uploaded_file() and is
 * exercised over HTTP; this probe covers everything reachable from the CLI by
 * physically placing a scan in the private root and wiring tbl_permits directly.
 * Seeds throwaway rows, asserts state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\permit_signed_permit_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit_release.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$createdApplicationIds = [];
$placedFiles = [];
$rpsUserId = 0;
$ownerUserId = 0;
$otherCommunityUserId = 0;

function seed_user(PDO $pdo, string $role, string $suffix, string $tag): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_users (fname, lname, email, username, password, role, status)
         VALUES (:fname, :lname, :email, :username, :password, :role, \'active\')'
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

/** Seeds an already-released application + permit row for signed-scan tests. */
function seed_released_application(PDO $pdo, int $applicantId, int $rpsId): array
{
    $txn = 'TCP-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_applications
            (transaction_id, submission_key, applicant_user_id, applicant_name,
             property_classification, property_owner_name, cutting_purpose,
             application_status, document_status, inspection_status, decision_status,
             donation_status, release_status, validity_status, submitted_at)
         VALUES
            (:txn, :skey, :applicant, \'Probe Applicant\', \'private_property\', \'Probe Owner\',
             \'Probe cutting\', \'released\', \'verified\', \'passed\', \'approved\',
             \'rps_verified\', \'released\', \'active\', NOW())'
    );
    $stmt->execute([
        ':txn' => $txn,
        ':skey' => hash('sha256', $txn . microtime(true)),
        ':applicant' => $applicantId,
    ]);
    $applicationId = (int) $pdo->lastInsertId();

    $decision = $pdo->prepare(
        'INSERT INTO tbl_permit_decisions
            (application_id, decided_by_user_id, decision, approved_tree_count,
             property_classification, donation_seedling_count)
         VALUES (:app, :actor, \'approved\', 5, \'private_property\', 50)'
    );
    $decision->execute([':app' => $applicationId, ':actor' => $rpsId]);
    $decisionId = (int) $pdo->lastInsertId();

    $permit = $pdo->prepare(
        'INSERT INTO tbl_permits
            (application_id, decision_id, permit_number, prepared_by_user_id, released_by_user_id,
             released_at, valid_from, valid_until, approved_duration_days, validity_start_basis)
         VALUES (:app, :decision, :num, :preparer, :releaser, NOW(), CURDATE(),
             DATE_ADD(CURDATE(), INTERVAL 60 DAY), 60, \'release_date\')'
    );
    $permit->execute([
        ':app' => $applicationId,
        ':decision' => $decisionId,
        ':num' => $txn,
        ':preparer' => $rpsId,
        ':releaser' => $rpsId,
    ]);

    return ['application_id' => $applicationId, 'transaction_id' => $txn];
}

function cleanup_application(PDO $pdo, int $applicationId): void
{
    $pdo->prepare('DELETE FROM tbl_notifications WHERE entity_type = \'permit_application\' AND entity_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permits WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_status_history WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_decisions WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_applications WHERE id = :id')->execute([':id' => $applicationId]);
}

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $ownerUserId = seed_user($pdo, 'community', $suffix, 'owner');
    $otherCommunityUserId = seed_user($pdo, 'community', $suffix, 'other');

    $seed = seed_released_application($pdo, $ownerUserId, $rpsUserId);
    $appId = $seed['application_id'];
    $createdApplicationIds[] = $appId;

    // ---- Metadata guards (run before the file upload path) ----
    $dummyFile = ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'name' => 'x.pdf'];

    $futureRejected = false;
    try {
        permit_attach_signed_permit_scan($pdo, $appId, $rpsUserId, [
            'signed_on' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        ], $dummyFile);
    } catch (PermitReleaseValidationException $e) {
        $futureRejected = str_contains($e->getMessage(), 'future');
    }
    check('future signing date rejected', $futureRejected);

    $badDateRejected = false;
    try {
        permit_attach_signed_permit_scan($pdo, $appId, $rpsUserId, ['signed_on' => '2026-13-40'], $dummyFile);
    } catch (PermitReleaseValidationException $e) {
        $badDateRejected = true;
    }
    check('invalid signing date rejected', $badDateRejected);

    $longNameRejected = false;
    try {
        permit_attach_signed_permit_scan($pdo, $appId, $rpsUserId, [
            'signed_by_name' => str_repeat('a', 151),
        ], $dummyFile);
    } catch (PermitReleaseValidationException $e) {
        $longNameRejected = true;
    }
    check('over-length signing personnel rejected', $longNameRejected);

    $longRecipientRejected = false;
    try {
        permit_attach_signed_permit_scan($pdo, $appId, $rpsUserId, [
            'released_to_recipient' => str_repeat('b', 151),
        ], $dummyFile);
    } catch (PermitReleaseValidationException $e) {
        $longRecipientRejected = true;
    }
    check('over-length recipient rejected', $longRecipientRejected);

    // Missing file (valid metadata) surfaces the upload validation error.
    $noFileRejected = false;
    try {
        permit_attach_signed_permit_scan($pdo, $appId, $rpsUserId, [], ['error' => UPLOAD_ERR_NO_FILE]);
    } catch (PermitDocumentValidationException $e) {
        $noFileRejected = true;
    }
    check('missing file rejected', $noFileRejected);

    // ---- Download payload: no scan yet ----
    check('no scan -> null payload for RPS', permit_signed_scan_download_payload($pdo, $appId, $rpsUserId) === null);

    // ---- Physically place a scan and wire the permit row (bypasses HTTP upload) ----
    $storage = permit_document_relative_storage_path($seed['transaction_id'], 'pdf');
    $pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    file_put_contents((string) $storage['absolute_path'], $pdfBytes);
    $placedFiles[] = (string) $storage['absolute_path'];
    $pdo->prepare(
        'UPDATE tbl_permits
         SET permit_file_path = :p, permit_file_original_name = \'signed.pdf\',
             permit_file_mime_type = \'application/pdf\', permit_file_size_bytes = :sz,
             permit_file_uploaded_by_user_id = :uid, permit_file_uploaded_at = NOW(),
             signed_on = CURDATE(), signed_by_name = \'CENRO Officer\', released_to_recipient = \'Probe Claimant\'
         WHERE application_id = :app'
    )->execute([
        ':p' => (string) $storage['relative_path'],
        ':sz' => strlen($pdfBytes),
        ':uid' => $rpsUserId,
        ':app' => $appId,
    ]);

    // ---- Download payload authorization ----
    $rpsPayload = permit_signed_scan_download_payload($pdo, $appId, $rpsUserId);
    check('RPS gets download payload', $rpsPayload !== null && is_file((string) $rpsPayload['absolute_path']));
    check('payload carries mime + filename', $rpsPayload !== null
        && $rpsPayload['mime_type'] === 'application/pdf'
        && $rpsPayload['original_filename'] === 'signed.pdf');

    $ownerPayload = permit_signed_scan_download_payload($pdo, $appId, $ownerUserId);
    check('owner Community gets download payload', $ownerPayload !== null && is_file((string) $ownerPayload['absolute_path']));

    $otherPayload = permit_signed_scan_download_payload($pdo, $appId, $otherCommunityUserId);
    check('non-owner Community denied (null payload)', $otherPayload === null);

    // ---- Record reflects signing/recipient metadata for display ----
    $record = permit_release_record_for_actor($pdo, $appId, $rpsUserId);
    check('record exposes signing personnel', $record !== null && $record['signed_by_name'] === 'CENRO Officer');
    check('record exposes recipient', $record !== null && $record['released_to_recipient'] === 'Probe Claimant');
    check('record exposes uploader name', $record !== null && !empty($record['permit_file_uploaded_by_name']));

    // ---- Cleanup helper removes a superseded scan ----
    permit_signed_scan_unlink_relative((string) $storage['relative_path']);
    check('cleanup helper removed the scan file', !is_file((string) $storage['absolute_path']));

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    foreach ($placedFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach ($createdApplicationIds as $id) {
        try { cleanup_application($pdo, $id); } catch (Throwable $e) { echo 'cleanup app ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$rpsUserId, $ownerUserId, $otherCommunityUserId] as $uid) {
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
