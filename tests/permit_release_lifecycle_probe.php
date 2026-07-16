<?php
/**
 * CLI end-to-end probe for the post-approval permit lifecycle:
 * final RPS donation confirmation -> prepare & release -> validity ->
 * cutting completion, plus the expiration sweep and duration-bound guards.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\permit_release_lifecycle_probe.php
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
$rpsUserId = 0;
$communityUserId = 0;

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

/** Seeds an application sitting at awaiting_final_verification with all release gates satisfied. */
function seed_ready_application(PDO $pdo, int $applicantId, int $rpsId, string $suffix, string $tag): int
{
    $txn = 'TCP-2026-' . substr(str_pad($tag . $suffix, 6, '0'), 0, 6);
    // Ensure a unique transaction id per seed.
    $txn = 'TCPX' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_applications
            (transaction_id, submission_key, applicant_user_id, applicant_name,
             property_classification, property_owner_name, cutting_purpose,
             application_status, document_status, inspection_status, decision_status,
             donation_status, release_status, validity_status, submitted_at)
         VALUES
            (:txn, :skey, :applicant, :aname, \'private_property\', :owner, \'Probe cutting\',
             \'awaiting_final_verification\', \'verified\', \'passed\', \'approved\',
             \'ems_verified\', \'not_ready\', \'not_issued\', NOW())'
    );
    $stmt->execute([
        ':txn' => $txn,
        ':skey' => hash('sha256', $tag . $suffix . microtime(true)),
        ':applicant' => $applicantId,
        ':aname' => 'Probe Applicant',
        ':owner' => 'Probe Owner',
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

    $requirement = $pdo->prepare(
        'INSERT INTO tbl_permit_donation_requirements
            (application_id, approval_decision_id, property_classification, policy_code,
             policy_version, required_seedling_count, received_seedling_count,
             requirement_basis, applicant_instructions, imposed_by_user_id, current_status)
         VALUES (:app, :decision, \'private_property\', \'property_private_property\',
             \'1\', 50, 50, \'Probe basis\', \'Probe instructions\', :actor, \'ems_verified\')'
    );
    $requirement->execute([':app' => $applicationId, ':decision' => $decisionId, ':actor' => $rpsId]);

    return $applicationId;
}

function cleanup_application(PDO $pdo, int $applicationId): void
{
    $pdo->prepare('DELETE FROM tbl_notifications WHERE entity_type = \'permit_application\' AND entity_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_cutting_completions WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permits WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_donation_requirements WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_status_history WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_decisions WHERE application_id = :id')->execute([':id' => $applicationId]);
    $pdo->prepare('DELETE FROM tbl_permit_applications WHERE id = :id')->execute([':id' => $applicationId]);
}

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $communityUserId = seed_user($pdo, 'community', $suffix, 'community');

    echo 'Config: validity ' . PERMIT_VALIDITY_MIN_DAYS . '-' . PERMIT_VALIDITY_MAX_DAYS
        . ' days, default ' . PERMIT_VALIDITY_DEFAULT_DAYS . ', basis ' . PERMIT_VALIDITY_START_BASIS
        . ', warn ' . PERMIT_VALIDITY_EXPIRY_WARNING_DAYS . ' days.' . PHP_EOL;
    check('validity bounds are 50-90', PERMIT_VALIDITY_MIN_DAYS === 50 && PERMIT_VALIDITY_MAX_DAYS === 90);

    // ---- Path 1: confirm -> release -> completion ----
    echo 'Path 1: final confirmation -> release -> cutting completion' . PHP_EOL;
    $app1 = seed_ready_application($pdo, $communityUserId, $rpsUserId, $suffix, 'a');
    $createdApplicationIds[] = $app1;

    $confirm = permit_confirm_final_donation_verification($pdo, $app1, $rpsUserId, 'Probe confirm');
    check('donation now rps_verified', $confirm['donation_status'] === 'rps_verified');
    check('application now ready_for_release', $confirm['application_status'] === 'ready_for_release');
    check('release now preparing', $confirm['release_status'] === 'preparing');

    // Duration guard rejections.
    $lowRejected = false;
    try { permit_prepare_and_release_permit($pdo, $app1, $rpsUserId, ['approved_duration_days' => '49']); }
    catch (PermitReleaseValidationException $e) { $lowRejected = true; }
    check('duration 49 rejected', $lowRejected);
    $highRejected = false;
    try { permit_prepare_and_release_permit($pdo, $app1, $rpsUserId, ['approved_duration_days' => '91']); }
    catch (PermitReleaseValidationException $e) { $highRejected = true; }
    check('duration 91 rejected', $highRejected);

    $release = permit_prepare_and_release_permit($pdo, $app1, $rpsUserId, [
        'approved_duration_days' => '60',
        'release_notes' => 'Probe release',
    ]);
    $expectedUntil = (new DateTimeImmutable('today'))->add(new DateInterval('P60D'))->format('Y-m-d');
    check('valid_from is today', $release['valid_from'] === (new DateTimeImmutable('today'))->format('Y-m-d'));
    check('valid_until is today+60', $release['valid_until'] === $expectedUntil);
    check('approved duration stored as 60', (int) $release['approved_duration_days'] === 60);

    $appRow = $pdo->query('SELECT application_status, release_status, validity_status FROM tbl_permit_applications WHERE id = ' . $app1)->fetch();
    check('application released', $appRow['application_status'] === 'released');
    check('release released', $appRow['release_status'] === 'released');
    check('validity active', $appRow['validity_status'] === 'active');

    // Double-release rejected.
    $doubleReleaseRejected = false;
    try { permit_prepare_and_release_permit($pdo, $app1, $rpsUserId, ['approved_duration_days' => '60']); }
    catch (Throwable $e) { $doubleReleaseRejected = true; }
    check('second release rejected', $doubleReleaseRejected);

    // Cutting completion.
    $completion = permit_record_cutting_completion($pdo, $app1, $rpsUserId, [
        'completion_status' => 'completed',
        'trees_cut_count' => '4',
        'completed_on' => date('Y-m-d'),
        'remarks' => 'Probe completion',
    ]);
    check('completion recorded (4 trees)', (int) $completion['trees_cut_count'] === 4);
    $appRow = $pdo->query('SELECT application_status, validity_status FROM tbl_permit_applications WHERE id = ' . $app1)->fetch();
    check('application completed', $appRow['application_status'] === 'completed');
    check('validity completed', $appRow['validity_status'] === 'completed');

    // Trees-cut over approved count rejected on a fresh completion attempt (duplicate + bound).
    $dupRejected = false;
    try {
        permit_record_cutting_completion($pdo, $app1, $rpsUserId, [
            'completion_status' => 'completed', 'trees_cut_count' => '1', 'completed_on' => date('Y-m-d'),
        ]);
    } catch (Throwable $e) { $dupRejected = true; }
    check('duplicate completion rejected', $dupRejected);

    // ---- Path 2: confirm -> release -> expiration sweep ----
    echo 'Path 2: final confirmation -> release -> expiration' . PHP_EOL;
    $app2 = seed_ready_application($pdo, $communityUserId, $rpsUserId, $suffix, 'b');
    $createdApplicationIds[] = $app2;
    permit_confirm_final_donation_verification($pdo, $app2, $rpsUserId, 'Probe confirm 2');
    permit_prepare_and_release_permit($pdo, $app2, $rpsUserId, ['approved_duration_days' => '55']);

    // Over-approved-count completion guard while active.
    $overRejected = false;
    try {
        permit_record_cutting_completion($pdo, $app2, $rpsUserId, [
            'completion_status' => 'completed', 'trees_cut_count' => '99', 'completed_on' => date('Y-m-d'),
        ]);
    } catch (PermitReleaseValidationException $e) { $overRejected = true; }
    check('trees cut over approved count rejected', $overRejected);

    // Back-date the permit so the sweep lapses it.
    $pdo->prepare('UPDATE tbl_permits SET valid_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE application_id = ' . $app2)->execute();
    $sweep = permit_expire_due_permits($pdo);
    check('sweep expired at least one permit', (int) $sweep['expired'] >= 1 && in_array($app2, $sweep['application_ids'], true));
    $appRow = $pdo->query('SELECT validity_status FROM tbl_permit_applications WHERE id = ' . $app2)->fetch();
    check('validity expired', $appRow['validity_status'] === 'expired');

    // Re-running the sweep is idempotent (no longer active).
    $sweep2 = permit_expire_due_permits($pdo);
    check('sweep idempotent', !in_array($app2, $sweep2['application_ids'], true));

    // Completion blocked on an expired permit.
    $expiredCompletionRejected = false;
    try {
        permit_record_cutting_completion($pdo, $app2, $rpsUserId, [
            'completion_status' => 'completed', 'trees_cut_count' => '1', 'completed_on' => date('Y-m-d'),
        ]);
    } catch (Throwable $e) { $expiredCompletionRejected = true; }
    check('completion blocked on expired permit', $expiredCompletionRejected);

    // Expiration notification exists for the applicant.
    $notifCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_notifications WHERE entity_id = ' . $app2
        . ' AND recipient_user_id = ' . $communityUserId . ' AND title = \'Tree Cutting Permit expired\''
    )->fetchColumn();
    check('applicant expiration notification created', $notifCount >= 1);

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    foreach ($createdApplicationIds as $id) {
        try { cleanup_application($pdo, $id); } catch (Throwable $e) { echo 'cleanup app ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$rpsUserId, $communityUserId] as $uid) {
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
