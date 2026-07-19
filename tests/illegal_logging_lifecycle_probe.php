<?php
/**
 * CLI end-to-end probe for illegal-logging incident reports: submission
 * validation, idempotency, coordinate/photo-count guards, authorization
 * (reporter-only submit, RPS/permitted-Superadmin-only processing, owner-scoped
 * reads), the full submitted -> under_review -> field_verification -> resolved
 * lifecycle plus the direct-resolve shortcut, status history, notifications,
 * audit, and transaction rollback.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\illegal_logging_lifecycle_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/illegal_logging.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$rpsUserId = 0;
$permittedSuperadminId = 0;
$unpermittedSuperadminId = 0;
$reporterUserId = 0;
$otherCommunityId = 0;
$emsUserId = 0;
$createdReportIds = [];

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

function no_files(): array
{
    return ['error' => UPLOAD_ERR_NO_FILE];
}

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $permittedSuperadminId = seed_user($pdo, 'superadmin', $suffix, 'permitted');
    $unpermittedSuperadminId = seed_user($pdo, 'superadmin', $suffix, 'unpermitted');
    $reporterUserId = seed_user($pdo, 'community', $suffix, 'reporter');
    $otherCommunityId = seed_user($pdo, 'community', $suffix, 'other');
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');

    $pdo->prepare(
        'INSERT INTO tbl_user_permissions (user_id, permission_key, is_active) VALUES (:uid, :perm, 1)'
    )->execute([':uid' => $permittedSuperadminId, ':perm' => certreefy_permission_illegal_logging_processing()]);

    // ---- Actor checks ----
    echo 'Actors' . PHP_EOL;
    check('RPS is a valid processor', illegal_logging_processor_actor($pdo, $rpsUserId) !== null);
    check('permitted Superadmin is a valid processor', illegal_logging_processor_actor($pdo, $permittedSuperadminId) !== null);
    check('unpermitted Superadmin is not a processor', illegal_logging_processor_actor($pdo, $unpermittedSuperadminId) === null);
    check('EMS is not a processor', illegal_logging_processor_actor($pdo, $emsUserId) === null);
    check('Community is not a processor', illegal_logging_processor_actor($pdo, $reporterUserId) === null);
    check('Community is a valid reporter', illegal_logging_reporter_actor($pdo, $reporterUserId) !== null);
    check('RPS is not a valid reporter', illegal_logging_reporter_actor($pdo, $rpsUserId) === null);

    // ---- Submission validation ----
    echo 'Submission validation' . PHP_EOL;

    $emptyLocationRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => '', 'incident_description' => 'desc', 'submission_key' => new_submission_key(),
        ], no_files());
    } catch (IllegalLoggingValidationException $e) {
        $emptyLocationRejected = true;
    }
    check('empty location rejected', $emptyLocationRejected);

    $emptyDescriptionRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => '', 'submission_key' => new_submission_key(),
        ], no_files());
    } catch (IllegalLoggingValidationException $e) {
        $emptyDescriptionRejected = true;
    }
    check('empty description rejected', $emptyDescriptionRejected);

    $partialCoordsRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => 'desc',
            'submission_key' => new_submission_key(), 'latitude' => '14.5',
        ], no_files());
    } catch (IllegalLoggingValidationException $e) {
        $partialCoordsRejected = str_contains($e->getMessage(), 'both latitude and longitude');
    }
    check('partial coordinates rejected', $partialCoordsRejected);

    $badCoordsRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => 'desc',
            'submission_key' => new_submission_key(), 'latitude' => '999', 'longitude' => '999',
        ], no_files());
    } catch (IllegalLoggingValidationException $e) {
        $badCoordsRejected = str_contains($e->getMessage(), 'outside valid ranges');
    }
    check('out-of-range coordinates rejected', $badCoordsRejected);

    $futureDateRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => 'desc',
            'submission_key' => new_submission_key(), 'observed_on' => date('Y-m-d', strtotime('+1 day')),
        ], no_files());
    } catch (IllegalLoggingValidationException $e) {
        $futureDateRejected = true;
    }
    check('future observed date rejected', $futureDateRejected);

    $rpsSubmitDenied = false;
    try {
        illegal_logging_submit_report($pdo, $rpsUserId, [
            'incident_location' => 'x', 'incident_description' => 'y', 'submission_key' => new_submission_key(),
        ], no_files());
    } catch (RuntimeException $e) {
        $rpsSubmitDenied = str_contains($e->getMessage(), 'active Community account');
    }
    check('RPS cannot submit a report', $rpsSubmitDenied);

    // A non-genuine upload reference (not from a real HTTP request) is rejected
    // by the reused validator before any DB write.
    $badFileRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => 'desc', 'submission_key' => new_submission_key(),
        ], ['name' => ['fake.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => [__FILE__], 'error' => [UPLOAD_ERR_OK], 'size' => [100]]);
    } catch (IllegalLoggingValidationException $e) {
        $badFileRejected = true;
    }
    check('non-genuine upload reference rejected', $badFileRejected);

    $tooManyFiles = [
        'name' => array_fill(0, 11, 'x.jpg'), 'type' => array_fill(0, 11, 'image/jpeg'),
        'tmp_name' => array_fill(0, 11, __FILE__), 'error' => array_fill(0, 11, UPLOAD_ERR_OK), 'size' => array_fill(0, 11, 100),
    ];
    $tooManyRejected = false;
    try {
        illegal_logging_submit_report($pdo, $reporterUserId, [
            'incident_location' => 'Barangay X', 'incident_description' => 'desc', 'submission_key' => new_submission_key(),
        ], $tooManyFiles);
    } catch (IllegalLoggingValidationException $e) {
        $tooManyRejected = str_contains($e->getMessage(), '10 evidence');
    }
    check('more than 10 evidence photos rejected', $tooManyRejected);

    // ---- Valid submission + idempotency ----
    echo 'Valid submission' . PHP_EOL;
    $key = new_submission_key();
    $submission = illegal_logging_submit_report($pdo, $reporterUserId, [
        'incident_location' => 'Barangay Probe, Sta. Cruz',
        'incident_description' => 'Chainsaw sounds and felled trees observed near the creek.',
        'observed_on' => date('Y-m-d', strtotime('-1 day')),
        'latitude' => '14.2781', 'longitude' => '121.4153',
        'submission_key' => $key,
    ], no_files());
    $createdReportIds[] = $submission['report_id'];
    check('report reference uses IL-YYYY-###### format', (bool) preg_match('/^IL-\d{4}-\d{6}$/', $submission['report_reference']));
    check('new report starts as submitted',
        (string) illegal_logging_report_for_actor($pdo, $submission['report_id'], $rpsUserId)['current_status'] === 'submitted');

    $replay = illegal_logging_submit_report($pdo, $reporterUserId, [
        'incident_location' => 'Different', 'incident_description' => 'different', 'submission_key' => $key,
    ], no_files());
    check('duplicate submission_key is idempotent',
        $replay['duplicate'] === true && $replay['report_id'] === $submission['report_id']);
    check('idempotent replay created no second report',
        count(illegal_logging_reports_for_reporter($pdo, $reporterUserId)) === 1);

    $processorsNotified = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_notifications WHERE entity_type = \'illegal_logging_report\''
        . ' AND entity_id = ' . $submission['report_id'] . ' AND recipient_user_id IN (' . $rpsUserId . ',' . $permittedSuperadminId . ')'
    )->fetchColumn();
    check('both processors notified of the new report', $processorsNotified === 2);

    // ---- Authorization on reads ----
    echo 'Read authorization' . PHP_EOL;
    check('reporter can read own report', illegal_logging_report_for_actor($pdo, $submission['report_id'], $reporterUserId) !== null);
    check('RPS can read any report', illegal_logging_report_for_actor($pdo, $submission['report_id'], $rpsUserId) !== null);
    check('permitted Superadmin can read', illegal_logging_report_for_actor($pdo, $submission['report_id'], $permittedSuperadminId) !== null);
    check('unpermitted Superadmin denied', illegal_logging_report_for_actor($pdo, $submission['report_id'], $unpermittedSuperadminId) === null);
    check('non-reporter Community denied', illegal_logging_report_for_actor($pdo, $submission['report_id'], $otherCommunityId) === null);
    check('EMS denied (module is Community/RPS/permitted-Superadmin only)', illegal_logging_report_for_actor($pdo, $submission['report_id'], $emsUserId) === null);

    // ---- Lifecycle ----
    echo 'Lifecycle' . PHP_EOL;

    $prematureDispatch = false;
    try {
        illegal_logging_process_report($pdo, $submission['report_id'], $rpsUserId, 'dispatch', []);
    } catch (IllegalLoggingValidationException $e) {
        $prematureDispatch = str_contains($e->getMessage(), 'cannot become');
    }
    check('cannot dispatch a submitted report', $prematureDispatch);

    $unauthorizedProcessDenied = false;
    try {
        illegal_logging_process_report($pdo, $submission['report_id'], $unpermittedSuperadminId, 'begin_review', []);
    } catch (RuntimeException $e) {
        $unauthorizedProcessDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('unpermitted Superadmin cannot process', $unauthorizedProcessDenied);

    illegal_logging_process_report($pdo, $submission['report_id'], $rpsUserId, 'begin_review', [
        'assigned_to_user_id' => (string) $permittedSuperadminId,
    ]);
    $underReview = illegal_logging_report_for_actor($pdo, $submission['report_id'], $rpsUserId);
    check('begin_review -> under_review', (string) $underReview['current_status'] === 'under_review');
    check('assignment recorded', (int) $underReview['assigned_to_user_id'] === $permittedSuperadminId);

    illegal_logging_process_report($pdo, $submission['report_id'], $permittedSuperadminId, 'dispatch', [
        'remarks' => 'Field team dispatched.',
    ]);
    check('dispatch -> field_verification',
        (string) illegal_logging_report_for_actor($pdo, $submission['report_id'], $rpsUserId)['current_status'] === 'field_verification');

    $missingNotesRejected = false;
    try {
        illegal_logging_process_report($pdo, $submission['report_id'], $permittedSuperadminId, 'resolve', [
            'resolution_outcome' => 'confirmed', 'resolution_notes' => '',
        ]);
    } catch (IllegalLoggingValidationException $e) {
        $missingNotesRejected = str_contains($e->getMessage(), 'Resolution notes are required');
    }
    check('resolution requires notes', $missingNotesRejected);

    $badOutcomeRejected = false;
    try {
        illegal_logging_process_report($pdo, $submission['report_id'], $permittedSuperadminId, 'resolve', [
            'resolution_outcome' => 'not_a_real_outcome', 'resolution_notes' => 'x',
        ]);
    } catch (IllegalLoggingValidationException $e) {
        $badOutcomeRejected = str_contains($e->getMessage(), 'valid resolution outcome');
    }
    check('invalid resolution outcome rejected', $badOutcomeRejected);

    illegal_logging_process_report($pdo, $submission['report_id'], $permittedSuperadminId, 'resolve', [
        'resolution_outcome' => 'confirmed',
        'resolution_notes' => 'Confirmed illegal cutting; referred for citation.',
        'field_findings' => 'Five felled narra trees found at the reported coordinates.',
    ]);
    $resolved = illegal_logging_report_for_actor($pdo, $submission['report_id'], $rpsUserId);
    check('resolve -> resolved (terminal)', (string) $resolved['current_status'] === 'resolved');
    check('resolution outcome stored', (string) $resolved['resolution_outcome'] === 'confirmed');
    check('field verified timestamp stored', $resolved['field_verified_at'] !== null);

    $reResolveRejected = false;
    try {
        illegal_logging_process_report($pdo, $submission['report_id'], $rpsUserId, 'resolve', [
            'resolution_outcome' => 'unfounded', 'resolution_notes' => 'x',
        ]);
    } catch (IllegalLoggingValidationException $e) {
        $reResolveRejected = true;
    }
    check('a resolved report cannot be resolved again', $reResolveRejected);

    $history = illegal_logging_report_history($pdo, $submission['report_id']);
    $statuses = array_column($history, 'new_status');
    check('status history records the full path',
        $statuses === ['submitted', 'under_review', 'field_verification', 'resolved']);

    $reporterNotes = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_notifications WHERE entity_type = \'illegal_logging_report\''
        . ' AND entity_id = ' . $submission['report_id'] . ' AND recipient_user_id = ' . $reporterUserId
    )->fetchColumn();
    check('reporter notified at each transition (3)', $reporterNotes === 3);

    $auditCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_audit_trail WHERE category = \'illegal_logging\' AND entity_type = \'illegal_logging_report\''
        . ' AND entity_id = ' . $submission['report_id']
    )->fetchColumn();
    check('every transition audited', $auditCount >= 4);

    // ---- Direct-resolve shortcut ----
    echo 'Direct resolve shortcut' . PHP_EOL;
    $key2 = new_submission_key();
    $report2 = illegal_logging_submit_report($pdo, $reporterUserId, [
        'incident_location' => 'Duplicate test', 'incident_description' => 'Same as an earlier report.',
        'submission_key' => $key2,
    ], no_files());
    $createdReportIds[] = $report2['report_id'];
    illegal_logging_process_report($pdo, $report2['report_id'], $rpsUserId, 'resolve', [
        'resolution_outcome' => 'invalid', 'resolution_notes' => 'Duplicate of ' . $submission['report_reference'] . '.',
    ]);
    check('direct resolve from submitted (no field visit) works',
        (string) illegal_logging_report_for_actor($pdo, $report2['report_id'], $rpsUserId)['current_status'] === 'resolved');
    check('direct-resolved report has no field_verified_at',
        illegal_logging_report_for_actor($pdo, $report2['report_id'], $rpsUserId)['field_verified_at'] === null);

    // ---- Rollback ----
    echo 'Rollback' . PHP_EOL;
    $key3 = new_submission_key();
    $report3 = illegal_logging_submit_report($pdo, $reporterUserId, [
        'incident_location' => 'Rollback test', 'incident_description' => 'Testing rollback.',
        'submission_key' => $key3,
    ], no_files());
    $createdReportIds[] = $report3['report_id'];

    $trigger = 'trg_illegal_logging_probe_' . $suffix;
    $pdo->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON tbl_illegal_logging_reports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced rollback'");
    $rolledBack = false;
    try {
        illegal_logging_process_report($pdo, $report3['report_id'], $rpsUserId, 'begin_review', []);
    } catch (Throwable $e) {
        $rolledBack = true;
    }
    $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
    check('forced failure during begin_review threw', $rolledBack);
    check('report still submitted after rollback',
        (string) illegal_logging_report_for_actor($pdo, $report3['report_id'], $rpsUserId)['current_status'] === 'submitted');
    check('no extra status-history row survived the rollback',
        count(illegal_logging_report_history($pdo, $report3['report_id'])) === 1);

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    try { $pdo->exec('DROP TRIGGER IF EXISTS trg_illegal_logging_probe_' . $suffix); } catch (Throwable $e) {}
    foreach ($createdReportIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_notifications WHERE entity_type = \'illegal_logging_report\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_illegal_logging_report_photos WHERE report_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_illegal_logging_report_status_history WHERE report_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_illegal_logging_reports WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) { echo 'cleanup report ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$rpsUserId, $permittedSuperadminId, $unpermittedSuperadminId, $reporterUserId, $otherCommunityId, $emsUserId] as $uid) {
        if ($uid > 0) {
            try {
                $pdo->prepare('DELETE FROM tbl_user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_notifications WHERE recipient_user_id = :rid OR created_by_user_id = :cid')->execute([':rid' => $uid, ':cid' => $uid]);
                $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $uid]);
            } catch (Throwable $e) { echo 'cleanup user ' . $uid . ': ' . $e->getMessage() . PHP_EOL; }
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
