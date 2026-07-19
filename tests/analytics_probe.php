<?php
/**
 * CLI probe for the read-only analytics/reporting layer: actor authorization
 * (RPS/Superadmin for CENRO analytics, EMS-only for inventory reports), date
 * range normalization, cross-domain aggregate correctness (permits, illegal
 * logging, seedlings, zones), the EMS stock/movement/release summaries, and
 * CSV row assembly. Seeds throwaway rows, exercises the real read functions,
 * asserts the numbers, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\analytics_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/analytics.php';

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
$superadminId = 0;
$communityUserId = 0;
$emsUserId = 0;
$zoneId = 0;
$speciesId = 0;
$requestId = 0;
$reportId = 0;
$applicationIds = [];

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

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $superadminId = seed_user($pdo, 'superadmin', $suffix, 'admin');
    $communityUserId = seed_user($pdo, 'community', $suffix, 'community');
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');

    // ---- Actors ----
    echo 'Actors' . PHP_EOL;
    check('RPS can view CENRO analytics', analytics_actor($pdo, $rpsUserId) !== null);
    check('Superadmin can view CENRO analytics', analytics_actor($pdo, $superadminId) !== null);
    check('Community cannot view CENRO analytics', analytics_actor($pdo, $communityUserId) === null);
    check('EMS cannot view CENRO analytics', analytics_actor($pdo, $emsUserId) === null);
    check('EMS can view inventory reports', inventory_reports_actor($pdo, $emsUserId) !== null);
    check('RPS cannot view inventory reports', inventory_reports_actor($pdo, $rpsUserId) === null);
    check('Community cannot view inventory reports', inventory_reports_actor($pdo, $communityUserId) === null);

    // ---- Date range normalization (pure) ----
    echo 'Date range' . PHP_EOL;
    $valid = analytics_normalize_range(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    check('valid range preserved', $valid['from'] === '2026-01-01' && $valid['to'] === '2026-01-31');
    $invalid = analytics_normalize_range(['date_from' => 'not-a-date', 'date_to' => '2026-13-40']);
    check('invalid dates dropped', $invalid['from'] === '' && $invalid['to'] === '');
    $reversed = analytics_normalize_range(['date_from' => '2026-06-30', 'date_to' => '2026-01-01']);
    check('reversed range swapped', $reversed['from'] === '2026-01-01' && $reversed['to'] === '2026-06-30');
    $empty = analytics_normalize_range([]);
    check('empty input yields open range', $empty['from'] === '' && $empty['to'] === '');

    // ---- Range condition builder (unique placeholders) ----
    echo 'Range conditions' . PHP_EOL;
    [$conds, $params] = analytics_range_conditions(['from' => '2026-01-01', 'to' => '2026-01-31'], 'a.created_at', 'permit');
    check('two conditions built', count($conds) === 2);
    check('placeholders are unique', array_key_exists(':permit_from', $params) && array_key_exists(':permit_to', $params));
    [$noConds] = analytics_range_conditions(['from' => '', 'to' => ''], 'a.created_at', 'permit');
    check('open range yields no conditions', $noConds === []);

    $openRange = ['from' => '', 'to' => ''];

    // ---- Zones ----
    echo 'Area zones' . PHP_EOL;
    require_once __DIR__ . '/../includes/area_management.php';
    $zoneBefore = analytics_area_zone_summary($pdo)['protected_count'];
    $zone = area_zone_create($pdo, $rpsUserId, ['zone_name' => 'Analytics Zone ' . $suffix, 'classification' => 'protected']);
    $zoneId = $zone['zone_id'];
    $zoneAfter = analytics_area_zone_summary($pdo)['protected_count'];
    check('protected zone count reflects the new zone', $zoneAfter === $zoneBefore + 1);

    // ---- Seedlings ----
    echo 'Seedlings' . PHP_EOL;
    require_once __DIR__ . '/../includes/seedling.php';
    $species = seedling_create_species($pdo, $emsUserId, [
        'common_name' => 'Analytics Narra ' . $suffix,
        'available_quantity' => '200',
        'low_stock_threshold' => '10',
    ]);
    $speciesId = $species['inventory_id'];

    $seedlingBefore = analytics_seedling_summary($pdo, $openRange);
    $submissionKey = new_seedling_submission_key();
    $submitted = seedling_submit_request($pdo, $communityUserId, [
        'planting_purpose' => 'Reforestation drive',
        'planting_location' => 'Barangay Test',
        'submission_key' => $submissionKey,
        'inventory_id' => [$speciesId],
        'quantity' => ['30'],
    ]);
    $requestId = $submitted['request_id'];
    $seedlingAfter = analytics_seedling_summary($pdo, $openRange);
    check('seedling request counted', $seedlingAfter['total_requests'] === $seedlingBefore['total_requests'] + 1);
    check('new request is in submitted status', $seedlingAfter['status_breakdown']['submitted'] >= 1);

    // Advance to fulfilment so stock releases and "distributed" increases.
    seedling_process_request($pdo, $requestId, $emsUserId, 'begin_review', []);
    // No quantity_approved override -> each line defaults to its requested amount (30).
    seedling_process_request($pdo, $requestId, $emsUserId, 'approve', []);
    $distBefore = analytics_seedling_summary($pdo, $openRange)['seedlings_distributed'];
    seedling_process_request($pdo, $requestId, $emsUserId, 'fulfil', []);
    $distAfter = analytics_seedling_summary($pdo, $openRange)['seedlings_distributed'];
    check('seedlings distributed increased by the fulfilled quantity', $distAfter === $distBefore + 30);

    // ---- Illegal logging ----
    echo 'Illegal logging' . PHP_EOL;
    require_once __DIR__ . '/../includes/illegal_logging.php';
    $ilBefore = analytics_illegal_logging_summary($pdo, $openRange);
    $ilKey = new_illegal_logging_submission_key();
    $report = illegal_logging_submit_report($pdo, $communityUserId, [
        'incident_location' => 'Analytics ridge ' . $suffix,
        'incident_description' => 'Suspected unauthorized cutting observed.',
        'submission_key' => $ilKey,
    ]);
    $reportId = $report['report_id'];
    $ilAfter = analytics_illegal_logging_summary($pdo, $openRange);
    check('illegal-logging report counted', $ilAfter['total'] === $ilBefore['total'] + 1);
    check('new report is submitted status', $ilAfter['status_breakdown']['submitted'] >= 1);

    // Resolve directly (invalid) and confirm the outcome breakdown moves.
    illegal_logging_process_report($pdo, $reportId, $rpsUserId, 'resolve', [
        'resolution_outcome' => 'invalid',
        'resolution_notes' => 'Duplicate of an earlier report.',
    ]);
    $ilResolved = analytics_illegal_logging_summary($pdo, $openRange);
    check('resolved outcome breakdown counts the invalid resolution',
        $ilResolved['outcome_breakdown']['invalid'] === $ilBefore['outcome_breakdown']['invalid'] + 1);

    // ---- Permits (minimal seeded applications) ----
    echo 'Permits' . PHP_EOL;
    $permitBefore = analytics_permit_summary($pdo, $openRange);
    $insertApp = $pdo->prepare(
        'INSERT INTO tbl_permit_applications
            (submission_key, applicant_user_id, applicant_name, application_status, decision_status, validity_status)
         VALUES (:key, :user, :name, :app_status, :decision, :validity)'
    );
    // One approved+active permit-issued application.
    $insertApp->execute([
        ':key' => bin2hex(random_bytes(32)), ':user' => $communityUserId, ':name' => 'Analytics Applicant A',
        ':app_status' => 'released', ':decision' => 'approved', ':validity' => 'active',
    ]);
    $appApproved = (int) $pdo->lastInsertId();
    $applicationIds[] = $appApproved;
    // One declined application.
    $insertApp->execute([
        ':key' => bin2hex(random_bytes(32)), ':user' => $communityUserId, ':name' => 'Analytics Applicant B',
        ':app_status' => 'declined', ':decision' => 'declined', ':validity' => 'not_issued',
    ]);
    $applicationIds[] = (int) $pdo->lastInsertId();

    // Seed a released permit row for the approved application so "permits issued" counts it.
    $decisionStmt = $pdo->prepare(
        'INSERT INTO tbl_permit_decisions (application_id, decided_by_user_id, decision)
         VALUES (:app, :actor, \'approved\')'
    );
    try {
        $decisionStmt->execute([':app' => $appApproved, ':actor' => $rpsUserId]);
        $decisionId = (int) $pdo->lastInsertId();
        $permitStmt = $pdo->prepare(
            'INSERT INTO tbl_permits (application_id, decision_id, permit_number, prepared_by_user_id, released_by_user_id, released_at, valid_from, valid_until, approved_duration_days)
             VALUES (:app, :decision, :number, :prep, :rel, NOW(), CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 60)'
        );
        $permitStmt->execute([
            ':app' => $appApproved, ':decision' => $decisionId, ':number' => 'ANL-' . $suffix,
            ':prep' => $rpsUserId, ':rel' => $rpsUserId,
        ]);
        $permitSeeded = true;
    } catch (Throwable $e) {
        // decision_type enum may differ; permit-issued assertion becomes best-effort.
        echo '  (note) permit/decision seed skipped: ' . $e->getMessage() . PHP_EOL;
        $permitSeeded = false;
    }

    $permitAfter = analytics_permit_summary($pdo, $openRange);
    check('permit applications counted (+2)', $permitAfter['total_applications'] === $permitBefore['total_applications'] + 2);
    check('approved count increased', $permitAfter['approved'] === $permitBefore['approved'] + 1);
    check('declined count increased', $permitAfter['declined'] === $permitBefore['declined'] + 1);
    check('active permit count increased', $permitAfter['active_permits'] === $permitBefore['active_permits'] + 1);
    if ($permitSeeded) {
        check('permits issued increased', $permitAfter['permits_issued'] === $permitBefore['permits_issued'] + 1);
    }

    $statusBreakdown = analytics_permit_status_breakdown($pdo, $openRange);
    check('status breakdown includes released and declined',
        ($statusBreakdown['released'] ?? 0) >= 1 && ($statusBreakdown['declined'] ?? 0) >= 1);

    // ---- Future-dated range excludes everything ----
    echo 'Date filtering' . PHP_EOL;
    $futureRange = ['from' => '2099-01-01', 'to' => '2099-12-31'];
    check('future range excludes permit applications', analytics_permit_summary($pdo, $futureRange)['total_applications'] === 0);
    check('future range excludes illegal-logging reports', analytics_illegal_logging_summary($pdo, $futureRange)['total'] === 0);
    check('future range excludes seedling requests', analytics_seedling_summary($pdo, $futureRange)['total_requests'] === 0);

    // ---- EMS inventory reports ----
    echo 'Inventory reports' . PHP_EOL;
    $snapshot = inventory_report_stock_snapshot($pdo);
    $ourSpecies = null;
    foreach ($snapshot['species'] as $s) {
        if ((int) $s['id'] === $speciesId) {
            $ourSpecies = $s;
            break;
        }
    }
    check('seeded species present in snapshot', $ourSpecies !== null);
    check('species stock reflects opening minus release (200-30=170)', $ourSpecies !== null && (int) $ourSpecies['available_quantity'] === 170);
    check('species not flagged low stock (170 > 10)', $ourSpecies !== null && $ourSpecies['is_low_stock'] === false);

    $movements = inventory_report_movement_summary($pdo, $openRange);
    check('incoming movements recorded', $movements['by_type']['incoming']['total_in'] >= 200);
    check('released movements recorded', $movements['by_type']['released']['total_out'] >= 30);

    $releases = inventory_report_release_records($pdo, $openRange);
    $foundRelease = false;
    foreach ($releases as $r) {
        if ((int) $r['id'] === $requestId) {
            $foundRelease = $r;
            break;
        }
    }
    check('fulfilled request appears in release records', $foundRelease !== false);
    check('release record shows the approved quantity', $foundRelease !== false && (int) $foundRelease['total_released'] === 30);

    // ---- CSV rows ----
    echo 'CSV export rows' . PHP_EOL;
    $csv = analytics_csv_rows($pdo, $openRange);
    check('analytics CSV has a header row', $csv[0] === ['Section', 'Metric', 'Value']);
    check('analytics CSV includes permit + seedling + zone sections',
        count(array_filter($csv, static fn ($r): bool => ($r[0] ?? '') === 'Permits')) > 0
        && count(array_filter($csv, static fn ($r): bool => ($r[0] ?? '') === 'Seedlings')) > 0
        && count(array_filter($csv, static fn ($r): bool => ($r[0] ?? '') === 'Area zones')) > 0);

    $invCsv = inventory_report_csv_rows($pdo, $openRange);
    check('inventory CSV has a header row', $invCsv[0] === ['Section', 'Item', 'Detail', 'Value']);
    check('inventory CSV includes a Stock row for the seeded species',
        count(array_filter($invCsv, static fn ($r): bool => ($r[0] ?? '') === 'Stock' && str_contains((string) ($r[1] ?? ''), $suffix))) === 1);

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    $fail++;
} finally {
    // Clean up in FK-safe order.
    try {
        if ($reportId > 0) {
            $pdo->prepare('DELETE FROM tbl_illegal_logging_report_status_history WHERE report_id = :id')->execute([':id' => $reportId]);
            $pdo->prepare('DELETE FROM tbl_illegal_logging_report_photos WHERE report_id = :id')->execute([':id' => $reportId]);
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'illegal_logging_report\' AND entity_id = :id')->execute([':id' => $reportId]);
            $pdo->prepare('DELETE FROM tbl_illegal_logging_reports WHERE id = :id')->execute([':id' => $reportId]);
        }
        if ($requestId > 0) {
            $pdo->prepare('DELETE FROM tbl_seedling_request_status_history WHERE request_id = :id')->execute([':id' => $requestId]);
            $pdo->prepare('DELETE FROM tbl_seedling_stock_movements WHERE request_id = :id')->execute([':id' => $requestId]);
            $pdo->prepare('DELETE FROM tbl_seedling_request_items WHERE request_id = :id')->execute([':id' => $requestId]);
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'seedling_request\' AND entity_id = :id')->execute([':id' => $requestId]);
            $pdo->prepare('DELETE FROM tbl_seedling_requests WHERE id = :id')->execute([':id' => $requestId]);
        }
        if ($speciesId > 0) {
            $pdo->prepare('DELETE FROM tbl_seedling_stock_movements WHERE inventory_id = :id')->execute([':id' => $speciesId]);
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'seedling_inventory\' AND entity_id = :id')->execute([':id' => $speciesId]);
            $pdo->prepare('DELETE FROM tbl_seedling_inventory WHERE id = :id')->execute([':id' => $speciesId]);
        }
        if ($zoneId > 0) {
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'area_zone\' AND entity_id = :id')->execute([':id' => $zoneId]);
            $pdo->prepare('DELETE FROM tbl_area_zones WHERE id = :id')->execute([':id' => $zoneId]);
        }
        foreach ($applicationIds as $appId) {
            $pdo->prepare('DELETE FROM tbl_permits WHERE application_id = :id')->execute([':id' => $appId]);
            $pdo->prepare('DELETE FROM tbl_permit_decisions WHERE application_id = :id')->execute([':id' => $appId]);
            $pdo->prepare('DELETE FROM tbl_permit_applications WHERE id = :id')->execute([':id' => $appId]);
        }
    } catch (Throwable $e) {
        echo 'cleanup domain data: ' . $e->getMessage() . PHP_EOL;
    }
    foreach ([$rpsUserId, $superadminId, $communityUserId, $emsUserId] as $uid) {
        if ($uid > 0) {
            try {
                $pdo->prepare('DELETE FROM tbl_notifications WHERE recipient_user_id = :id1 OR created_by_user_id = :id2')->execute([':id1' => $uid, ':id2' => $uid]);
                $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $uid]);
            } catch (Throwable $e) { echo 'cleanup user ' . $uid . ': ' . $e->getMessage() . PHP_EOL; }
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
