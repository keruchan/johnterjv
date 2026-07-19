<?php
/**
 * CLI probe for the geomapping layer: GeoJSON boundary validation, derived
 * map centers, area-zone boundary storage/overlay reads, planting-site
 * EMS-only authorization, creation/update/recommendation reads, duplicate and
 * vocabulary rejection, and analytics geographic dataset shape.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\geomapping_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/area_management.php';
require_once __DIR__ . '/../includes/planting_sites.php';
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
$communityUserId = 0;
$emsUserId = 0;
$createdZoneIds = [];
$createdSiteIds = [];

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

$squareBoundary = json_encode([
    'type' => 'FeatureCollection',
    'features' => [[
        'type' => 'Feature',
        'properties' => ['client_junk' => 'to_be_dropped'],
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[[121.40, 14.20], [121.44, 14.20], [121.44, 14.24], [121.40, 14.24], [121.40, 14.20]]],
        ],
    ]],
]);

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $communityUserId = seed_user($pdo, 'community', $suffix, 'community');
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');

    // ---- GeoJSON validation ----
    echo 'GeoJSON validation' . PHP_EOL;
    check('blank boundary returns null', geo_validate_boundary_geojson('') === null);
    check('empty feature collection returns null', geo_validate_boundary_geojson('{"type":"FeatureCollection","features":[]}') === null);

    $normalized = geo_validate_boundary_geojson($squareBoundary);
    check('valid polygon collection accepted', $normalized !== null);
    check('client-supplied properties are dropped', $normalized !== null && !str_contains($normalized, 'client_junk'));

    $invalidJsonRejected = false;
    try {
        geo_validate_boundary_geojson('{not json');
    } catch (GeoValidationException $e) {
        $invalidJsonRejected = true;
    }
    check('malformed JSON rejected', $invalidJsonRejected);

    $pointRejected = false;
    try {
        geo_validate_boundary_geojson('{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[121.4,14.2]}}]}');
    } catch (GeoValidationException $e) {
        $pointRejected = str_contains($e->getMessage(), 'polygon');
    }
    check('non-polygon geometry rejected', $pointRejected);

    $rangeRejected = false;
    try {
        geo_validate_boundary_geojson('{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[200,14],[201,14],[201,15],[200,14]]]}}]}');
    } catch (GeoValidationException $e) {
        $rangeRejected = true;
    }
    check('out-of-range coordinates rejected', $rangeRejected);

    $center = geo_boundary_center($normalized);
    check(
        'boundary center is the bounding-box middle',
        $center !== null && abs($center[0] - 14.22) < 0.0001 && abs($center[1] - 121.42) < 0.0001
    );

    $bothBlank = geo_validate_optional_point('', '');
    check('blank point pair returns null', $bothBlank === null);
    $halfRejected = false;
    try {
        geo_validate_optional_point('14.2', '');
    } catch (GeoValidationException $e) {
        $halfRejected = true;
    }
    check('one-sided point pair rejected', $halfRejected);
    $point = geo_validate_optional_point('14.2', '121.4');
    check('valid point normalized to 7 decimals', $point !== null && $point['lat'] === '14.2000000' && $point['lng'] === '121.4000000');

    // ---- Area zone boundary storage ----
    echo 'Area zone boundaries' . PHP_EOL;
    $zone = area_zone_create($pdo, $rpsUserId, [
        'zone_name' => 'Geo Probe Zone ' . $suffix,
        'classification' => 'restricted',
        'municipality' => 'Santa Cruz',
        'boundary_geojson' => $squareBoundary,
    ]);
    $createdZoneIds[] = (int) $zone['zone_id'];
    $storedZone = area_zone_find($pdo, (int) $zone['zone_id']);
    check('zone boundary stored', $storedZone !== null && $storedZone['boundary_geojson'] !== null);
    check(
        'zone center derived from boundary',
        $storedZone !== null && abs((float) $storedZone['center_lat'] - 14.22) < 0.0001
            && abs((float) $storedZone['center_lng'] - 121.42) < 0.0001
    );

    $features = area_zone_map_features($pdo);
    $zoneInOverlay = false;
    foreach ($features as $feature) {
        if ($feature['name'] === 'Geo Probe Zone ' . $suffix && $feature['classification'] === 'restricted') {
            $zoneInOverlay = true;
        }
    }
    check('zone appears in map overlay features', $zoneInOverlay);

    $badBoundaryRejected = false;
    try {
        area_zone_create($pdo, $rpsUserId, [
            'zone_name' => 'Bad Boundary Zone ' . $suffix,
            'classification' => 'allowed',
            'boundary_geojson' => '{bad json',
        ]);
    } catch (AreaManagementValidationException $e) {
        $badBoundaryRejected = true;
    }
    check('zone creation with invalid boundary rejected', $badBoundaryRejected);

    $zoneBoundaryClearRejected = false;
    try {
        area_zone_update($pdo, $rpsUserId, (int) $zone['zone_id'], [
            'zone_name' => 'Geo Probe Zone ' . $suffix,
            'classification' => 'restricted',
            'boundary_geojson' => '',
            'is_active' => '1',
        ]);
    } catch (AreaManagementValidationException $e) {
        $zoneBoundaryClearRejected = str_contains($e->getMessage(), 'boundary');
    }
    check('zone boundary is required (clearing on update rejected)', $zoneBoundaryClearRejected);

    // ---- Planting site authorization ----
    echo 'Planting site actors' . PHP_EOL;
    check('EMS can manage planting sites', planting_site_actor($pdo, $emsUserId) !== null);
    check('RPS cannot manage planting sites', planting_site_actor($pdo, $rpsUserId) === null);
    check('Community cannot manage planting sites', planting_site_actor($pdo, $communityUserId) === null);

    $rpsCreateDenied = false;
    try {
        planting_site_create($pdo, $rpsUserId, ['site_name' => 'Denied Site ' . $suffix, 'boundary_geojson' => $squareBoundary]);
    } catch (RuntimeException $e) {
        $rpsCreateDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('RPS planting-site creation denied', $rpsCreateDenied);

    // ---- Planting site lifecycle ----
    echo 'Planting site lifecycle' . PHP_EOL;
    $site = planting_site_create($pdo, $emsUserId, [
        'site_name' => 'Geo Probe Site ' . $suffix,
        'municipality' => 'Pagsanjan',
        'province' => 'Laguna',
        'soil_type' => 'Clay loam',
        'soil_ph' => '5.5-6.5',
        'moisture_level' => 'moist',
        'recommended_season' => 'June to October',
        'suitable_species' => 'Narra, Molave',
        'rationale' => 'Riverbank soil retains moisture through the dry months.',
        'data_source' => 'SoilGrids seed / probe',
        'boundary_geojson' => $squareBoundary,
    ]);
    $createdSiteIds[] = (int) $site['site_id'];
    $storedSite = planting_site_find($pdo, (int) $site['site_id']);
    check('site stored with environmental attributes', $storedSite !== null && $storedSite['soil_type'] === 'Clay loam' && $storedSite['moisture_level'] === 'moist');
    check('site center derived from boundary', $storedSite !== null && abs((float) $storedSite['center_lat'] - 14.22) < 0.0001);

    $recommendations = planting_site_recommendations($pdo);
    $siteRecommended = false;
    foreach ($recommendations as $recommendation) {
        if ($recommendation['site_name'] === 'Geo Probe Site ' . $suffix) {
            $siteRecommended = $recommendation['rationale'] !== null;
        }
    }
    check('active site appears in community recommendations with rationale', $siteRecommended);

    $siteFeatures = planting_site_map_features($pdo);
    $siteMapped = false;
    foreach ($siteFeatures as $feature) {
        if ($feature['name'] === 'Geo Probe Site ' . $suffix && $feature['classification'] === 'planting') {
            $siteMapped = true;
        }
    }
    check('site appears in map overlay features', $siteMapped);

    $duplicateRejected = false;
    try {
        planting_site_create($pdo, $emsUserId, ['site_name' => 'Geo Probe Site ' . $suffix, 'boundary_geojson' => $squareBoundary]);
    } catch (PlantingSiteValidationException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'already exists');
    }
    check('duplicate site name rejected', $duplicateRejected);

    $badMoistureRejected = false;
    try {
        planting_site_create($pdo, $emsUserId, ['site_name' => 'Bad Moisture ' . $suffix, 'moisture_level' => 'soggy', 'boundary_geojson' => $squareBoundary]);
    } catch (PlantingSiteValidationException $e) {
        $badMoistureRejected = str_contains($e->getMessage(), 'moisture');
    }
    check('invalid moisture level rejected', $badMoistureRejected);

    // ---- Required boundary + month-range season + species dropdowns ----
    echo 'Planting site fields' . PHP_EOL;

    $missingBoundaryRejected = false;
    try {
        planting_site_create($pdo, $emsUserId, ['site_name' => 'No Boundary ' . $suffix]);
    } catch (PlantingSiteValidationException $e) {
        $missingBoundaryRejected = str_contains($e->getMessage(), 'boundary');
    }
    check('site creation without a boundary rejected', $missingBoundaryRejected);

    $seasonSite = planting_site_create($pdo, $emsUserId, [
        'site_name' => 'Season Site ' . $suffix,
        'boundary_geojson' => $squareBoundary,
        'season_from' => ['January', 'October'],
        'season_to' => ['March', 'November'],
        'species' => ['Narra', '__other__', 'Narra'],
        'species_other' => ['', 'Bagras', ''],
    ]);
    $createdSiteIds[] = (int) $seasonSite['site_id'];
    $seasonStored = planting_site_find($pdo, (int) $seasonSite['site_id']);
    check('month ranges compiled into canonical season string', $seasonStored !== null && $seasonStored['recommended_season'] === 'January - March, October - November');
    check('species dropdown + Others compiled and de-duplicated', $seasonStored !== null && $seasonStored['suitable_species'] === 'Narra, Bagras');

    $badMonthRejected = false;
    try {
        planting_site_create($pdo, $emsUserId, [
            'site_name' => 'Bad Month ' . $suffix,
            'boundary_geojson' => $squareBoundary,
            'season_from' => ['Janury'],
            'season_to' => ['March'],
        ]);
    } catch (PlantingSiteValidationException $e) {
        $badMonthRejected = str_contains($e->getMessage(), 'month');
    }
    check('invalid month name rejected', $badMonthRejected);

    planting_site_update($pdo, $emsUserId, (int) $site['site_id'], [
        'site_name' => 'Geo Probe Site ' . $suffix,
        'boundary_geojson' => $squareBoundary,
        'is_active' => '0',
    ]);
    $recommendationsAfter = planting_site_recommendations($pdo);
    $stillRecommended = false;
    foreach ($recommendationsAfter as $recommendation) {
        if ($recommendation['site_name'] === 'Geo Probe Site ' . $suffix) {
            $stillRecommended = true;
        }
    }
    check('deactivated site leaves community recommendations', !$stillRecommended);

    // ---- Analytics geographic dataset ----
    echo 'Analytics geographic dataset' . PHP_EOL;
    $geographic = analytics_geographic_data($pdo);
    check(
        'geographic dataset exposes the expected keys',
        isset($geographic['heat_points'], $geographic['permit_markers'], $geographic['il_markers'], $geographic['zones'])
    );
    check('geographic counts match marker arrays', $geographic['permit_located_count'] === count($geographic['permit_markers']) && $geographic['il_located_count'] === count($geographic['il_markers']));
} catch (Throwable $e) {
    echo 'UNEXPECTED FAILURE: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    // ---- Cleanup ----
    try {
        foreach ($createdSiteIds as $siteId) {
            $pdo->prepare('DELETE FROM tbl_planting_sites WHERE id = :id')->execute([':id' => $siteId]);
        }
        foreach ($createdZoneIds as $zoneId) {
            $pdo->prepare('DELETE FROM tbl_area_zones WHERE id = :id')->execute([':id' => $zoneId]);
        }
        $userIds = array_filter([$rpsUserId, $communityUserId, $emsUserId]);
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $pdo->prepare("DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($placeholders)")->execute(array_values($userIds));
            $pdo->prepare("DELETE FROM tbl_users WHERE id IN ($placeholders)")->execute(array_values($userIds));
        }
    } catch (Throwable $cleanupError) {
        echo 'CLEANUP WARNING: ' . $cleanupError->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
