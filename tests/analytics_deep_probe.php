<?php
/**
 * CLI probe for the deep-analytics layer: least-squares forecasting math,
 * momentum, month-axis generation/alignment, permit backlog, stock-depletion
 * forecasting, and the CENRO/EMS recommendation engines. The pure math is
 * checked against hand-computed expectations; the DB-backed pieces are checked
 * against throwaway seeded rows and cleaned up afterward. Run:
 *   C:\xampp\php\php.exe tests\analytics_deep_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/analytics.php';
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
$speciesIds = [];

try {
    // ---- Forecast math (deterministic) ----
    echo 'Linear forecast' . PHP_EOL;

    // Perfectly linear series 2,4,6,8,10 -> slope 2, next 12,14,16, R^2 = 1.
    $f = analytics_linear_forecast([2, 4, 6, 8, 10], 3);
    check('perfect line slope = 2', abs($f['slope'] - 2.0) < 0.001);
    check('perfect line forecast = [12,14,16]', $f['forecast'] === [12, 14, 16]);
    check('perfect line R^2 = 1', abs($f['r2'] - 1.0) < 0.001);
    check('perfect line trend rising', $f['trend'] === 'rising');

    // Falling line 10,8,6,4,2 -> next projects downward, clamped >= 0.
    $fd = analytics_linear_forecast([10, 8, 6, 4, 2], 3);
    check('falling line trend falling', $fd['trend'] === 'falling');
    check('falling forecast clamped >= 0', min($fd['forecast']) >= 0);
    check('falling next = 0 (2 - 2)', $fd['forecast'][0] === 0);

    // Flat/noisy small series -> trend flat.
    $flat = analytics_linear_forecast([5, 5, 5, 5, 5], 3);
    check('flat line trend flat', $flat['trend'] === 'flat');
    check('flat forecast = [5,5,5]', $flat['forecast'] === [5, 5, 5]);

    // Degenerate inputs.
    check('empty series -> zeros', analytics_linear_forecast([], 2)['forecast'] === [0, 0]);
    check('single value -> repeated', analytics_linear_forecast([7], 2)['forecast'] === [7, 7]);

    // ---- Momentum ----
    echo 'Momentum' . PHP_EOL;
    check('momentum null for <2', analytics_momentum([3]) === null);
    // last=10 vs prior mean of [4,6]=5 -> +100%
    check('momentum +100%', abs((float) analytics_momentum([4, 6, 10]) - 100.0) < 0.001);
    // last=0 vs prior mean 5 -> -100%
    check('momentum -100%', abs((float) analytics_momentum([6, 4, 0]) + 100.0) < 0.001);

    // ---- Month axis + alignment ----
    echo 'Month axis' . PHP_EOL;
    $months = analytics_trend_months(12);
    check('12 month buckets', count($months) === 12);
    check('last bucket is current month', $months[11]['key'] === date('Y-m'));
    check('buckets are chronological', $months[0]['key'] < $months[11]['key']);
    $aligned = analytics_align_to_months($months, [$months[11]['key'] => 9]);
    check('alignment maps current month to 9', $aligned[11] === 9 && $aligned[0] === 0);
    check('minimum window clamped to 2', count(analytics_trend_months(1)) === 2);

    // ---- Forecast labels continue the axis ----
    echo 'Forecast labels' . PHP_EOL;
    $flabels = analytics_forecast_labels(['Jan 2026', 'Feb 2026'], 2);
    check('forecast labels continue months', $flabels === ['Mar 2026', 'Apr 2026']);

    // ---- DB-backed: trend series + bundle run without error ----
    echo 'Trend bundle (live DB)' . PHP_EOL;
    $bundle = analytics_forecast_bundle($pdo, 12, 3);
    check('bundle labels = 12 history + 3 forecast', count($bundle['labels']) === 15);
    check('permit history padded to 15', count($bundle['permit_submissions']['history']) === 15);
    check('forecast bridge point set at last history index',
        $bundle['permit_submissions']['forecast'][11] === $bundle['permit_submissions']['history'][11]);
    check('il_received meta has a trend', in_array($bundle['il_received']['meta']['trend'], ['rising', 'falling', 'flat'], true));

    $backlog = analytics_permit_backlog($pdo);
    check('backlog returns pending + aging ints', is_int($backlog['pending']) && is_int($backlog['aging']));

    // ---- CENRO recommendations shape ----
    echo 'CENRO recommendations' . PHP_EOL;
    $overview = analytics_overview($pdo, ['from' => '', 'to' => '']);
    $recs = analytics_cenro_recommendations($pdo, $overview, $bundle);
    check('always returns at least one recommendation', count($recs) >= 1);
    check('each rec has level/icon/title/detail', array_reduce($recs, static fn ($c, $r): bool =>
        $c && isset($r['level'], $r['icon'], $r['title'], $r['detail'])
        && in_array($r['level'], ['critical', 'warning', 'info', 'positive'], true), true));

    // ---- EMS depletion forecast with seeded species ----
    echo 'EMS depletion forecast' . PHP_EOL;
    $emsStmt = $pdo->prepare(
        'INSERT INTO tbl_users (fname, lname, email, username, password, contact, role, status)
         VALUES (\'Ems\', \'Probe\', :email, :username, :password, \'09170000000\', \'ems\', \'active\')'
    );
    $emsStmt->execute([
        ':email' => 'ems_deep_' . $suffix . '@certreefy.test',
        ':username' => 'ems_deep_' . $suffix,
        ':password' => password_hash('probe-' . $suffix, PASSWORD_DEFAULT),
    ]);
    $emsUserId = (int) $pdo->lastInsertId();

    // Species A: 300 in stock, will receive a 60-unit release dated 30 days ago
    // -> avg 60/90 = 0.667/day -> ~450 days to depletion (healthy).
    $speciesA = seedling_create_species($pdo, $emsUserId, [
        'common_name' => 'Deep Narra A ' . $suffix, 'available_quantity' => '300', 'low_stock_threshold' => '10',
    ]);
    $speciesIds[] = $speciesA['inventory_id'];
    // Species B: tiny stock so it flags depletion soon.
    $speciesB = seedling_create_species($pdo, $emsUserId, [
        'common_name' => 'Deep Narra B ' . $suffix, 'available_quantity' => '20', 'low_stock_threshold' => '5',
    ]);
    $speciesIds[] = $speciesB['inventory_id'];

    // Record a released movement for species B 30 days ago: 90 released over the
    // window -> 1/day -> 20 days to depletion (should trigger a critical rec).
    $mv = $pdo->prepare(
        'INSERT INTO tbl_seedling_stock_movements
            (inventory_id, movement_type, quantity_delta, quantity_after, reason, recorded_by_user_id, created_at)
         VALUES (:inv, \'released\', -90, 0, \'probe\', :actor, (NOW() - INTERVAL 30 DAY))'
    );
    $mv->execute([':inv' => $speciesB['inventory_id'], ':actor' => $emsUserId]);

    $depletion = inventory_report_depletion_forecast($pdo, 90);
    $byName = [];
    foreach ($depletion as $d) {
        $byName[$d['common_name']] = $d;
    }
    $b = $byName['Deep Narra B ' . $suffix] ?? null;
    check('species B appears in forecast', $b !== null);
    check('species B avg daily = 1.0 (90/90)', $b !== null && abs($b['avg_daily'] - 1.0) < 0.001);
    check('species B days-to-depletion = 20', $b !== null && $b['days_to_depletion'] === 20);
    check('species B has a projected depletion date', $b !== null && $b['depletion_date'] !== null);

    $a = $byName['Deep Narra A ' . $suffix] ?? null;
    check('species A (no releases) has null days-to-depletion', $a !== null && $a['days_to_depletion'] === null);

    // ---- EMS recommendations ----
    echo 'EMS recommendations' . PHP_EOL;
    $snapshot = inventory_report_stock_snapshot($pdo);
    $emsRecs = inventory_report_recommendations($snapshot, $depletion);
    check('EMS recs returned', count($emsRecs) >= 1);
    $hasBWarning = false;
    foreach ($emsRecs as $r) {
        if (str_contains($r['title'], 'Deep Narra B ' . $suffix)) {
            $hasBWarning = ($r['level'] === 'critical');
        }
    }
    check('species B flagged critical (depletes <= 30 days)', $hasBWarning);

    // ---- Movement trend ----
    echo 'Movement trend' . PHP_EOL;
    $trend = inventory_report_movement_trend($pdo, 12);
    check('movement trend has 12 labels', count($trend['labels']) === 12);
    check('released series length 12', count($trend['released']) === 12);
    check('released_forecast has a trend', in_array($trend['released_forecast']['trend'], ['rising', 'falling', 'flat'], true));

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    $fail++;
} finally {
    foreach ($speciesIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_seedling_stock_movements WHERE inventory_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'seedling_inventory\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_seedling_inventory WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) {
            echo 'cleanup species ' . $id . ': ' . $e->getMessage() . PHP_EOL;
        }
    }
    if ($emsUserId > 0) {
        try {
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $emsUserId]);
            $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $emsUserId]);
        } catch (Throwable $e) {
            echo 'cleanup user: ' . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
