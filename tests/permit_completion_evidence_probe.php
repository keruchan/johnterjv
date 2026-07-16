<?php
/**
 * CLI probe for cutting-completion evidence attachment: file-count/type guards,
 * completion+evidence recorded together, download-payload authorization (RPS,
 * owner Community, non-owner Community), and rollback cleanup on failure.
 *
 * The full upload path relies on move_uploaded_file()/is_uploaded_file() and is
 * exercised over HTTP; this probe covers everything reachable from the CLI by
 * physically placing evidence files and wiring the tables directly where the
 * real move would occur. Seeds throwaway rows, asserts state, and removes
 * everything it created. Run:
 *   C:\xampp\php\php.exe tests\permit_completion_evidence_probe.php
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

/** Seeds an active released application + permit ready for cutting completion. */
function seed_active_application(PDO $pdo, int $applicantId, int $rpsId): array
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
    $pdo->prepare('DELETE FROM tbl_permit_cutting_completion_evidence WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_cutting_completions WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permits WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_status_history WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_decisions WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_applications WHERE id = :id')->execute([':id' => $applicationId]);
}

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $ownerUserId = seed_user($pdo, 'community', $suffix, 'owner');
    $otherCommunityUserId = seed_user($pdo, 'community', $suffix, 'other');

    // ---- File-count guard: 11 files rejected without touching the database ----
    $seed1 = seed_active_application($pdo, $ownerUserId, $rpsUserId);
    $app1 = $seed1['application_id'];
    $createdApplicationIds[] = $app1;

    $manyFiles = [
        'name' => array_fill(0, 11, 'x.jpg'),
        'type' => array_fill(0, 11, 'image/jpeg'),
        'tmp_name' => array_fill(0, 11, __FILE__),
        'error' => array_fill(0, 11, UPLOAD_ERR_OK),
        'size' => array_fill(0, 11, 100),
    ];
    $tooManyRejected = false;
    try {
        permit_record_cutting_completion($pdo, $app1, $rpsUserId, [
            'completion_status' => 'completed', 'trees_cut_count' => '3', 'completed_on' => date('Y-m-d'),
        ], $manyFiles);
    } catch (PermitReleaseValidationException $e) {
        $tooManyRejected = str_contains($e->getMessage(), '10');
    }
    check('more than 10 evidence files rejected', $tooManyRejected);
    $noCompletionYet = (int) $pdo->query('SELECT COUNT(*) FROM tbl_permit_cutting_completions WHERE application_id = ' . $app1)->fetchColumn();
    check('rejected batch wrote no completion row', $noCompletionYet === 0);

    // ---- A non-genuine upload reference (not from a real HTTP request) is rejected ----
    // is_uploaded_file() fails for any path not produced by an actual upload, so this also
    // proves evidence validation runs, and rejects, before the transaction opens or any
    // completion/evidence row is written. The real multi-file move + content validation and
    // the moved-file rollback-cleanup path require a genuine HTTP upload; see the paired
    // HTTP validation pass for those.
    $notAnUploadRejected = false;
    try {
        permit_record_cutting_completion($pdo, $app1, $rpsUserId, [
            'completion_status' => 'completed', 'trees_cut_count' => '3', 'completed_on' => date('Y-m-d'),
        ], ['name' => ['fake.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => [__FILE__], 'error' => [UPLOAD_ERR_OK], 'size' => [100]]);
    } catch (PermitReleaseValidationException $e) {
        $notAnUploadRejected = true;
    }
    check('a non-genuine upload reference is rejected before any write', $notAnUploadRejected);
    $stillNoCompletion = (int) $pdo->query('SELECT COUNT(*) FROM tbl_permit_cutting_completions WHERE application_id = ' . $app1)->fetchColumn();
    check('rejected reference wrote no completion row', $stillNoCompletion === 0);

    // ---- Completion with no evidence still works (evidence is optional) ----
    $completion = permit_record_cutting_completion($pdo, $app1, $rpsUserId, [
        'completion_status' => 'completed', 'trees_cut_count' => '3', 'completed_on' => date('Y-m-d'),
    ]);
    check('completion recorded with zero evidence files', $completion['evidence_count'] === 0);
    check('no evidence rows for this completion', permit_cutting_completion_evidence_for_actor($pdo, $app1, $rpsUserId) === []);

    // ---- Physically place evidence and wire the table directly (bypasses HTTP upload) ----
    $storage = permit_document_relative_storage_path($seed1['transaction_id'], 'jpg');
    $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==');
    file_put_contents((string) $storage['absolute_path'], $jpegBytes);
    $placedFiles[] = (string) $storage['absolute_path'];
    $evidenceInsertId = null;
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_cutting_completion_evidence
            (application_id, completion_id, storage_path, original_filename, mime_type, file_size_bytes, uploaded_by_user_id)
         VALUES (:app, :completion, :path, \'evidence.jpg\', \'image/jpeg\', :size, :uid)'
    );
    $stmt->execute([
        ':app' => $app1,
        ':completion' => $completion['completion_id'],
        ':path' => (string) $storage['relative_path'],
        ':size' => strlen($jpegBytes),
        ':uid' => $rpsUserId,
    ]);
    $evidenceId = (int) $pdo->lastInsertId();

    // ---- Listing + download authorization ----
    $listForRps = permit_cutting_completion_evidence_for_actor($pdo, $app1, $rpsUserId);
    check('RPS sees the evidence in the list', count($listForRps) === 1 && (int) $listForRps[0]['id'] === $evidenceId);

    $rpsPayload = permit_cutting_completion_evidence_download_payload($pdo, $evidenceId, $rpsUserId);
    check('RPS gets download payload', $rpsPayload !== null && is_file((string) $rpsPayload['absolute_path']));

    $ownerPayload = permit_cutting_completion_evidence_download_payload($pdo, $evidenceId, $ownerUserId);
    check('owner Community gets download payload', $ownerPayload !== null && is_file((string) $ownerPayload['absolute_path']));

    $otherPayload = permit_cutting_completion_evidence_download_payload($pdo, $evidenceId, $otherCommunityUserId);
    check('non-owner Community denied (null payload)', $otherPayload === null);

    $listForOther = permit_cutting_completion_evidence_for_actor($pdo, $app1, $otherCommunityUserId);
    check('non-owner Community sees empty list', $listForOther === []);

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
