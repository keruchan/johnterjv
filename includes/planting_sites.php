<?php
/**
 * Planting sites: an EMS-maintained advisory registry of recommended seedling
 * planting locations for the public seedling-request program.
 *
 * Each record carries editable environmental attributes — soil type/pH,
 * moisture, recommended planting season, suitable species, and a plain-language
 * rationale (the "why"). Initial values may be seeded from free public
 * datasets (ISRIC SoilGrids, Open-Meteo, PAGASA climatological normals; the
 * data_source field records provenance) and are always overridable by EMS
 * field knowledge. Community users read active recommendations on the
 * seedling-request page. Advisory only: no link to the permit workflow and no
 * effect on request processing. Every mutation is transactional and reuses
 * the shared audit writer under the seedling category.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/geo.php';

class PlantingSiteValidationException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------

function planting_site_moisture_levels(): array
{
    return [
        'dry' => 'Dry',
        'moderate' => 'Moderate',
        'moist' => 'Moist',
        'wet' => 'Wet / waterlogged',
    ];
}

function planting_site_moisture_label(string $level): string
{
    return planting_site_moisture_levels()[$level] ?? ucwords(str_replace('_', ' ', $level));
}

/** Ordered month vocabulary for the recommended-planting-season ranges. */
function planting_site_months(): array
{
    return [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];
}

/**
 * Builds a canonical "January - March, October - November" season string from
 * aligned start/end month arrays. Blank pairs are skipped; every non-blank
 * pair must name two valid months.
 */
function planting_site_build_season(array $fromMonths, array $toMonths): string
{
    $months = planting_site_months();
    $ranges = [];
    $count = max(count($fromMonths), count($toMonths));
    for ($i = 0; $i < $count; $i++) {
        $from = trim((string) ($fromMonths[$i] ?? ''));
        $to = trim((string) ($toMonths[$i] ?? ''));
        if ($from === '' && $to === '') {
            continue;
        }
        if (!in_array($from, $months, true) || !in_array($to, $months, true)) {
            throw new PlantingSiteValidationException('Choose both a start and end month for each planting-season range.');
        }
        $ranges[] = $from . ' - ' . $to;
    }

    return implode(', ', $ranges);
}

/**
 * Resolves aligned species dropdown/"Others" arrays into a de-duplicated
 * comma-separated string. A row whose selection is the "Others" sentinel takes
 * its value from the matching free-text entry.
 */
function planting_site_build_species(array $selected, array $others): string
{
    $names = [];
    foreach ($selected as $i => $rawValue) {
        $value = trim((string) $rawValue);
        if ($value === '') {
            continue;
        }
        if ($value === '__other__') {
            $value = trim((string) ($others[$i] ?? ''));
            if ($value === '') {
                continue;
            }
        }
        if (strlen($value) > 150) {
            throw new PlantingSiteValidationException('Each species name must not exceed 150 characters.');
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        if (!isset($names[$key])) {
            $names[$key] = $value;
        }
    }

    return implode(', ', array_values($names));
}

// ---------------------------------------------------------------------------
// Actor
// ---------------------------------------------------------------------------

/** Planting-site management mirrors the seedling program: exact active EMS role. */
function planting_site_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' && (string) $actor['role'] === 'ems'
        ? $actor
        : null;
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function planting_site_list(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(s.site_name LIKE :search1 OR s.municipality LIKE :search2 OR s.barangay LIKE :search3 OR s.suitable_species LIKE :search4)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
        $params[':search4'] = $searchTerm;
    }
    if (!empty($filters['active_only'])) {
        $where[] = 's.is_active = 1';
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        'SELECT s.*, CONCAT(u.fname, \' \', u.lname) AS created_by_name,
                CASE WHEN uu.id IS NULL THEN NULL ELSE CONCAT(uu.fname, \' \', uu.lname) END AS updated_by_name
         FROM tbl_planting_sites s
         INNER JOIN tbl_users u ON u.id = s.created_by_user_id
         LEFT JOIN tbl_users uu ON uu.id = s.updated_by_user_id' . $whereSql . '
         ORDER BY s.site_name'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function planting_site_find(PDO $pdo, int $siteId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_planting_sites WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $siteId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Active recommendations for the Community seedling-request page: location,
 * environmental attributes, and the rationale, newest first. Read-only.
 */
function planting_site_recommendations(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, site_name, province, municipality, barangay,
                boundary_geojson, center_lat, center_lng,
                soil_type, soil_ph, moisture_level, recommended_season,
                suitable_species, rationale
         FROM tbl_planting_sites
         WHERE is_active = 1
         ORDER BY created_at DESC, site_name'
    )->fetchAll();
}

/** Active sites shaped for map overlays (planting classification color). */
function planting_site_map_features(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT site_name, boundary_geojson, center_lat, center_lng,
                soil_type, moisture_level, recommended_season, suitable_species
         FROM tbl_planting_sites
         WHERE is_active = 1
         ORDER BY site_name"
    )->fetchAll();

    $features = [];
    foreach ($rows as $row) {
        $features[] = [
            'name' => (string) $row['site_name'],
            'classification' => 'planting',
            'geojson' => (string) ($row['boundary_geojson'] ?? ''),
            'center_lat' => $row['center_lat'] !== null ? (float) $row['center_lat'] : null,
            'center_lng' => $row['center_lng'] !== null ? (float) $row['center_lng'] : null,
            'soil_type' => (string) ($row['soil_type'] ?? ''),
            'moisture_level' => $row['moisture_level'] !== null ? planting_site_moisture_label((string) $row['moisture_level']) : '',
            'recommended_season' => (string) ($row['recommended_season'] ?? ''),
            'suitable_species' => (string) ($row['suitable_species'] ?? ''),
        ];
    }

    return $features;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function planting_site_validate_common(array $input): array
{
    $siteName = trim((string) ($input['site_name'] ?? ''));
    if ($siteName === '' || strlen($siteName) > 150) {
        throw new PlantingSiteValidationException('Enter a site name of up to 150 characters.');
    }
    $province = trim((string) ($input['province'] ?? ''));
    $municipality = trim((string) ($input['municipality'] ?? ''));
    $barangay = trim((string) ($input['barangay'] ?? ''));
    foreach (['province' => $province, 'municipality' => $municipality, 'barangay' => $barangay] as $label => $value) {
        if (strlen($value) > 100) {
            throw new PlantingSiteValidationException(ucfirst($label) . ' must not exceed 100 characters.');
        }
    }
    $soilType = trim((string) ($input['soil_type'] ?? ''));
    if (strlen($soilType) > 100) {
        throw new PlantingSiteValidationException('Soil type must not exceed 100 characters.');
    }
    $soilPh = trim((string) ($input['soil_ph'] ?? ''));
    if (strlen($soilPh) > 50) {
        throw new PlantingSiteValidationException('Soil pH must not exceed 50 characters.');
    }
    $moistureLevel = trim((string) ($input['moisture_level'] ?? ''));
    if ($moistureLevel !== '' && !array_key_exists($moistureLevel, planting_site_moisture_levels())) {
        throw new PlantingSiteValidationException('Select a valid moisture level.');
    }

    // Recommended season: month-range arrays are preferred; a plain string is
    // still accepted for backward compatibility / API callers.
    if (isset($input['season_from']) || isset($input['season_to'])) {
        $recommendedSeason = planting_site_build_season(
            (array) ($input['season_from'] ?? []),
            (array) ($input['season_to'] ?? [])
        );
    } else {
        $recommendedSeason = trim((string) ($input['recommended_season'] ?? ''));
    }
    if (strlen($recommendedSeason) > 150) {
        throw new PlantingSiteValidationException('The recommended season must not exceed 150 characters.');
    }

    // Suitable species: dropdown/"Others" arrays are preferred; a plain string
    // is still accepted for backward compatibility / API callers.
    if (isset($input['species']) && is_array($input['species'])) {
        $suitableSpecies = planting_site_build_species(
            (array) $input['species'],
            (array) ($input['species_other'] ?? [])
        );
    } else {
        $suitableSpecies = trim((string) ($input['suitable_species'] ?? ''));
    }
    if (strlen($suitableSpecies) > 500) {
        throw new PlantingSiteValidationException('Suitable species must not exceed 500 characters.');
    }

    $rationale = trim((string) ($input['rationale'] ?? ''));
    if (strlen($rationale) > 1000) {
        throw new PlantingSiteValidationException('The rationale must not exceed 1000 characters.');
    }
    $dataSource = trim((string) ($input['data_source'] ?? ''));
    if (strlen($dataSource) > 255) {
        throw new PlantingSiteValidationException('The data source must not exceed 255 characters.');
    }

    // The boundary is required; province/municipality/barangay are read from
    // the boundary center via reverse geocoding on the client, so the center is
    // always derived here from the drawn shape (never manually supplied).
    try {
        $boundaryGeoJson = geo_validate_boundary_geojson($input['boundary_geojson'] ?? null);
    } catch (GeoValidationException $e) {
        throw new PlantingSiteValidationException($e->getMessage());
    }
    if ($boundaryGeoJson === null) {
        throw new PlantingSiteValidationException('Draw the site boundary on the map before saving.');
    }
    $center = geo_boundary_center($boundaryGeoJson);
    $point = $center !== null
        ? ['lat' => number_format($center[0], 7, '.', ''), 'lng' => number_format($center[1], 7, '.', '')]
        : null;

    return [
        'site_name' => $siteName,
        'province' => $province !== '' ? $province : null,
        'municipality' => $municipality !== '' ? $municipality : null,
        'barangay' => $barangay !== '' ? $barangay : null,
        'boundary_geojson' => $boundaryGeoJson,
        'center_lat' => $point['lat'] ?? null,
        'center_lng' => $point['lng'] ?? null,
        'soil_type' => $soilType !== '' ? $soilType : null,
        'soil_ph' => $soilPh !== '' ? $soilPh : null,
        'moisture_level' => $moistureLevel !== '' ? $moistureLevel : null,
        'recommended_season' => $recommendedSeason !== '' ? $recommendedSeason : null,
        'suitable_species' => $suitableSpecies !== '' ? $suitableSpecies : null,
        'rationale' => $rationale !== '' ? $rationale : null,
        'data_source' => $dataSource !== '' ? $dataSource : null,
    ];
}

function planting_site_create(PDO $pdo, int $actorUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Planting-site creation must own its database transaction.');
    }
    $data = planting_site_validate_common($input);

    try {
        $pdo->beginTransaction();
        if (planting_site_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage planting sites.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_planting_sites
                (site_name, province, municipality, barangay, boundary_geojson, center_lat, center_lng,
                 soil_type, soil_ph, moisture_level, recommended_season, suitable_species,
                 rationale, data_source, created_by_user_id)
             VALUES
                (:site_name, :province, :municipality, :barangay, :boundary_geojson, :center_lat, :center_lng,
                 :soil_type, :soil_ph, :moisture_level, :recommended_season, :suitable_species,
                 :rationale, :data_source, :actor)'
        );
        try {
            $insert->execute([
                ':site_name' => $data['site_name'],
                ':province' => $data['province'],
                ':municipality' => $data['municipality'],
                ':barangay' => $data['barangay'],
                ':boundary_geojson' => $data['boundary_geojson'],
                ':center_lat' => $data['center_lat'],
                ':center_lng' => $data['center_lng'],
                ':soil_type' => $data['soil_type'],
                ':soil_ph' => $data['soil_ph'],
                ':moisture_level' => $data['moisture_level'],
                ':recommended_season' => $data['recommended_season'],
                ':suitable_species' => $data['suitable_species'],
                ':rationale' => $data['rationale'],
                ':data_source' => $data['data_source'],
                ':actor' => $actorUserId,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new PlantingSiteValidationException('A planting site with that name already exists.');
            }
            throw $e;
        }
        $siteId = (int) $pdo->lastInsertId();

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'planting_site_created',
            'planting_site',
            $siteId,
            'Added a recommended planting site record.',
            ['site_name' => $data['site_name']]
        );

        $pdo->commit();

        return ['site_id' => $siteId, 'site_name' => $data['site_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function planting_site_update(PDO $pdo, int $actorUserId, int $siteId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Planting-site update must own its database transaction.');
    }
    $data = planting_site_validate_common($input);
    $isActive = trim((string) ($input['is_active'] ?? '1')) === '1' ? 1 : 0;

    try {
        $pdo->beginTransaction();
        if (planting_site_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage planting sites.');
        }
        $existing = $pdo->prepare('SELECT id FROM tbl_planting_sites WHERE id = :id LIMIT 1 FOR UPDATE');
        $existing->execute([':id' => $siteId]);
        if ($existing->fetchColumn() === false) {
            throw new PlantingSiteValidationException('The selected planting site does not exist.');
        }

        $update = $pdo->prepare(
            'UPDATE tbl_planting_sites
             SET site_name = :site_name,
                 province = :province,
                 municipality = :municipality,
                 barangay = :barangay,
                 boundary_geojson = :boundary_geojson,
                 center_lat = :center_lat,
                 center_lng = :center_lng,
                 soil_type = :soil_type,
                 soil_ph = :soil_ph,
                 moisture_level = :moisture_level,
                 recommended_season = :recommended_season,
                 suitable_species = :suitable_species,
                 rationale = :rationale,
                 data_source = :data_source,
                 is_active = :is_active,
                 updated_by_user_id = :actor
             WHERE id = :id'
        );
        try {
            $update->execute([
                ':site_name' => $data['site_name'],
                ':province' => $data['province'],
                ':municipality' => $data['municipality'],
                ':barangay' => $data['barangay'],
                ':boundary_geojson' => $data['boundary_geojson'],
                ':center_lat' => $data['center_lat'],
                ':center_lng' => $data['center_lng'],
                ':soil_type' => $data['soil_type'],
                ':soil_ph' => $data['soil_ph'],
                ':moisture_level' => $data['moisture_level'],
                ':recommended_season' => $data['recommended_season'],
                ':suitable_species' => $data['suitable_species'],
                ':rationale' => $data['rationale'],
                ':data_source' => $data['data_source'],
                ':is_active' => $isActive,
                ':actor' => $actorUserId,
                ':id' => $siteId,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new PlantingSiteValidationException('A planting site with that name already exists.');
            }
            throw $e;
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'planting_site_updated',
            'planting_site',
            $siteId,
            'Updated a recommended planting site record.',
            ['site_name' => $data['site_name'], 'is_active' => $isActive === 1]
        );

        $pdo->commit();

        return ['site_id' => $siteId, 'site_name' => $data['site_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
