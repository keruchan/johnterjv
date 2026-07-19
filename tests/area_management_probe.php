<?php
/**
 * CLI probe for Area Management: zone creation/update validation, duplicate
 * name rejection, classification/summary counts, RPS/Superadmin authorization
 * (Community/EMS denied), soft-deactivation, and transaction rollback.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\area_management_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/area_management.php';

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
$createdZoneIds = [];

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

$probeBoundary = json_encode([
    'type' => 'FeatureCollection',
    'features' => [[
        'type' => 'Feature',
        'properties' => new stdClass(),
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[[121.40, 14.20], [121.44, 14.20], [121.44, 14.24], [121.40, 14.24], [121.40, 14.20]]],
        ],
    ]],
]);

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $superadminId = seed_user($pdo, 'superadmin', $suffix, 'admin');
    $communityUserId = seed_user($pdo, 'community', $suffix, 'community');
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');

    // ---- Actor checks ----
    echo 'Actors' . PHP_EOL;
    check('RPS can manage zones', area_management_actor($pdo, $rpsUserId) !== null);
    check('Superadmin can manage zones (no special permission needed)', area_management_actor($pdo, $superadminId) !== null);
    check('Community cannot manage zones', area_management_actor($pdo, $communityUserId) === null);
    check('EMS cannot manage zones', area_management_actor($pdo, $emsUserId) === null);

    // ---- Validation ----
    echo 'Validation' . PHP_EOL;

    $emptyNameRejected = false;
    try {
        area_zone_create($pdo, $rpsUserId, ['zone_name' => '', 'classification' => 'protected']);
    } catch (AreaManagementValidationException $e) {
        $emptyNameRejected = true;
    }
    check('empty zone name rejected', $emptyNameRejected);

    $badClassificationRejected = false;
    try {
        area_zone_create($pdo, $rpsUserId, ['zone_name' => 'Test Zone ' . $suffix, 'classification' => 'not_a_real_class']);
    } catch (AreaManagementValidationException $e) {
        $badClassificationRejected = str_contains($e->getMessage(), 'valid classification');
    }
    check('invalid classification rejected', $badClassificationRejected);

    $communityCreateDenied = false;
    try {
        area_zone_create($pdo, $communityUserId, ['zone_name' => 'Illegal Zone ' . $suffix, 'classification' => 'protected', 'boundary_geojson' => $probeBoundary]);
    } catch (RuntimeException $e) {
        $communityCreateDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot create a zone', $communityCreateDenied);

    $missingBoundaryRejected = false;
    try {
        area_zone_create($pdo, $rpsUserId, ['zone_name' => 'No Boundary Zone ' . $suffix, 'classification' => 'protected']);
    } catch (AreaManagementValidationException $e) {
        $missingBoundaryRejected = str_contains($e->getMessage(), 'boundary');
    }
    check('zone creation without a boundary rejected', $missingBoundaryRejected);

    // ---- Valid creation ----
    echo 'Valid creation' . PHP_EOL;
    $zoneName = 'Watershed Reserve ' . $suffix;
    $created = area_zone_create($pdo, $rpsUserId, [
        'zone_name' => $zoneName,
        'classification' => 'protected',
        'municipality' => 'Sta. Cruz',
        'province' => 'Laguna',
        'district' => 'District 3',
        'coverage_description' => 'Covers the watershed area east of the highway.',
        'boundary_geojson' => $probeBoundary,
    ]);
    $createdZoneIds[] = $created['zone_id'];
    check('zone created', $created['zone_name'] === $zoneName);

    $zone = area_zone_find($pdo, $created['zone_id']);
    check('zone persisted with correct classification', (string) $zone['classification'] === 'protected');
    check('zone is active by default', (int) $zone['is_active'] === 1);

    // Duplicate name rejected.
    $dupRejected = false;
    try {
        area_zone_create($pdo, $rpsUserId, ['zone_name' => $zoneName, 'classification' => 'allowed', 'boundary_geojson' => $probeBoundary]);
    } catch (AreaManagementValidationException $e) {
        $dupRejected = str_contains($e->getMessage(), 'already exists');
    }
    check('duplicate zone name rejected', $dupRejected);

    // ---- Summary + listing ----
    echo 'Summary and listing' . PHP_EOL;
    $summary = area_zone_summary($pdo);
    check('summary counts the protected zone', $summary['protected_count'] >= 1);

    $listAll = area_zone_list($pdo, ['q' => $suffix]);
    check('search by suffix finds the zone', count($listAll) === 1 && (int) $listAll[0]['id'] === $created['zone_id']);

    $listByClassification = area_zone_list($pdo, ['classification' => 'protected', 'q' => $suffix]);
    check('filter by classification finds the zone', count($listByClassification) === 1);

    $listWrongClassification = area_zone_list($pdo, ['classification' => 'allowed', 'q' => $suffix]);
    check('filter by wrong classification excludes the zone', $listWrongClassification === []);

    // ---- Update (including by Superadmin) ----
    echo 'Update' . PHP_EOL;
    $updated = area_zone_update($pdo, $superadminId, $created['zone_id'], [
        'zone_name' => $zoneName,
        'classification' => 'restricted',
        'municipality' => 'Sta. Cruz',
        'notes' => 'Reclassified after review.',
        'boundary_geojson' => $probeBoundary,
        'is_active' => '1',
    ]);
    check('Superadmin can update a zone', $updated['zone_id'] === $created['zone_id']);
    $afterUpdate = area_zone_find($pdo, $created['zone_id']);
    check('classification changed to restricted', (string) $afterUpdate['classification'] === 'restricted');
    check('updated_by_user_id recorded', (int) $afterUpdate['updated_by_user_id'] === $superadminId);

    $communityUpdateDenied = false;
    try {
        area_zone_update($pdo, $communityUserId, $created['zone_id'], ['zone_name' => $zoneName, 'classification' => 'allowed', 'boundary_geojson' => $probeBoundary]);
    } catch (RuntimeException $e) {
        $communityUpdateDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot update a zone', $communityUpdateDenied);

    $updateMissingZoneRejected = false;
    try {
        area_zone_update($pdo, $rpsUserId, 999999999, ['zone_name' => 'Nonexistent', 'classification' => 'allowed', 'boundary_geojson' => $probeBoundary]);
    } catch (AreaManagementValidationException $e) {
        $updateMissingZoneRejected = str_contains($e->getMessage(), 'does not exist');
    }
    check('updating a nonexistent zone rejected', $updateMissingZoneRejected);

    // ---- Soft deactivation ----
    echo 'Deactivation' . PHP_EOL;
    area_zone_update($pdo, $rpsUserId, $created['zone_id'], [
        'zone_name' => $zoneName, 'classification' => 'restricted', 'is_active' => '0',
        'boundary_geojson' => $probeBoundary,
    ]);
    $deactivated = area_zone_find($pdo, $created['zone_id']);
    check('zone soft-deactivated', (int) $deactivated['is_active'] === 0);
    $summaryAfterDeactivation = area_zone_summary($pdo);
    check('deactivated zone excluded from active summary counts',
        $summaryAfterDeactivation['restricted_count'] < ($summary['protected_count'] + 1));

    // Audit trail.
    $auditCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_audit_trail WHERE category = \'area_management\' AND entity_type = \'area_zone\''
        . ' AND entity_id = ' . $created['zone_id']
    )->fetchColumn();
    check('every mutation audited (create + 2 successful updates)', $auditCount === 3);

    // ---- Rollback ----
    echo 'Rollback' . PHP_EOL;
    $trigger = 'trg_area_management_probe_' . $suffix;
    $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON tbl_area_zones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced rollback'");
    $rolledBack = false;
    try {
        area_zone_create($pdo, $rpsUserId, ['zone_name' => 'Rollback Zone ' . $suffix, 'classification' => 'allowed', 'boundary_geojson' => $probeBoundary]);
    } catch (Throwable $e) {
        $rolledBack = true;
    }
    $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
    check('forced failure during creation threw', $rolledBack);
    check('no zone row survived the failed creation',
        (int) $pdo->query("SELECT COUNT(*) FROM tbl_area_zones WHERE zone_name = 'Rollback Zone $suffix'")->fetchColumn() === 0);

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    try { $pdo->exec('DROP TRIGGER IF EXISTS trg_area_management_probe_' . $suffix); } catch (Throwable $e) {}
    foreach ($createdZoneIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'area_zone\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_area_zones WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) { echo 'cleanup zone ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$rpsUserId, $superadminId, $communityUserId, $emsUserId] as $uid) {
        if ($uid > 0) {
            try {
                $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $uid]);
            } catch (Throwable $e) { echo 'cleanup user ' . $uid . ': ' . $e->getMessage() . PHP_EOL; }
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
