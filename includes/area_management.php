<?php
/**
 * Area Management: a CENRO-internal reference registry of named geographic
 * zones classified as allowed, restricted, or protected.
 *
 * Purely informational (project-owner decision): it has no lifecycle, no
 * Community-facing side, and no link to the Tree Cutting Permit workflow.
 * Any active RPS or Superadmin may create/edit zone records; no new
 * exceptional permission was introduced for it. Every mutation is
 * transactional and reuses the shared audit writer.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/geo.php';

class AreaManagementValidationException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------

function area_zone_classifications(): array
{
    return [
        'allowed' => 'Allowed for Cutting',
        'restricted' => 'Restricted',
        'protected' => 'Protected',
    ];
}

function area_zone_classification_label(string $classification): string
{
    return area_zone_classifications()[$classification] ?? ucwords(str_replace('_', ' ', $classification));
}

function area_zone_classification_badge(string $classification): string
{
    return match ($classification) {
        'allowed' => 'text-bg-success',
        'restricted' => 'text-bg-warning',
        'protected' => 'text-bg-danger',
        default => 'text-bg-light border',
    };
}

// ---------------------------------------------------------------------------
// Actor
// ---------------------------------------------------------------------------

/** Zone management is any active RPS or Superadmin; no granular permission gates it. */
function area_management_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' && in_array((string) $actor['role'], ['rps', 'superadmin'], true)
        ? $actor
        : null;
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function area_zone_list(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    $classification = trim((string) ($filters['classification'] ?? ''));
    if ($classification !== '' && array_key_exists($classification, area_zone_classifications())) {
        $where[] = 'z.classification = :classification';
        $params[':classification'] = $classification;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(z.zone_name LIKE :search1 OR z.municipality LIKE :search2 OR z.barangay LIKE :search3 OR z.district LIKE :search4)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
        $params[':search4'] = $searchTerm;
    }
    $activeOnly = !empty($filters['active_only']);
    if ($activeOnly) {
        $where[] = 'z.is_active = 1';
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        'SELECT z.*, CONCAT(u.fname, \' \', u.lname) AS created_by_name,
                CASE WHEN uu.id IS NULL THEN NULL ELSE CONCAT(uu.fname, \' \', uu.lname) END AS updated_by_name
         FROM tbl_area_zones z
         INNER JOIN tbl_users u ON u.id = z.created_by_user_id
         LEFT JOIN tbl_users uu ON uu.id = z.updated_by_user_id' . $whereSql . '
         ORDER BY z.zone_name'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function area_zone_find(PDO $pdo, int $zoneId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_area_zones WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $zoneId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Active zones that have a drawn boundary, shaped for map overlays
 * (name, classification + label, GeoJSON text). Used by every page that
 * renders the zone layer, including read-only permit/report maps.
 */
function area_zone_map_features(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT zone_name, classification, boundary_geojson
         FROM tbl_area_zones
         WHERE is_active = 1 AND boundary_geojson IS NOT NULL
         ORDER BY zone_name"
    )->fetchAll();

    return array_map(
        static fn (array $row): array => [
            'name' => (string) $row['zone_name'],
            'classification' => (string) $row['classification'],
            'classification_label' => area_zone_classification_label((string) $row['classification']),
            'geojson' => (string) $row['boundary_geojson'],
        ],
        $rows
    );
}

function area_zone_summary(PDO $pdo): array
{
    $row = $pdo->query(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN classification = \'allowed\' AND is_active = 1 THEN 1 ELSE 0 END) AS allowed_count,
                SUM(CASE WHEN classification = \'restricted\' AND is_active = 1 THEN 1 ELSE 0 END) AS restricted_count,
                SUM(CASE WHEN classification = \'protected\' AND is_active = 1 THEN 1 ELSE 0 END) AS protected_count
         FROM tbl_area_zones
         WHERE is_active = 1'
    )->fetch();

    return [
        'total' => (int) ($row['total'] ?? 0),
        'allowed_count' => (int) ($row['allowed_count'] ?? 0),
        'restricted_count' => (int) ($row['restricted_count'] ?? 0),
        'protected_count' => (int) ($row['protected_count'] ?? 0),
    ];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function area_zone_validate_common(array $input): array
{
    $zoneName = trim((string) ($input['zone_name'] ?? ''));
    if ($zoneName === '' || strlen($zoneName) > 150) {
        throw new AreaManagementValidationException('Enter a zone name of up to 150 characters.');
    }
    $classification = trim((string) ($input['classification'] ?? ''));
    if (!array_key_exists($classification, area_zone_classifications())) {
        throw new AreaManagementValidationException('Select a valid classification.');
    }
    $province = trim((string) ($input['province'] ?? ''));
    $municipality = trim((string) ($input['municipality'] ?? ''));
    $barangay = trim((string) ($input['barangay'] ?? ''));
    $district = trim((string) ($input['district'] ?? ''));
    foreach (['province' => $province, 'municipality' => $municipality, 'barangay' => $barangay, 'district' => $district] as $label => $value) {
        if (strlen($value) > 100) {
            throw new AreaManagementValidationException(ucfirst($label) . ' must not exceed 100 characters.');
        }
    }
    $coverageDescription = trim((string) ($input['coverage_description'] ?? ''));
    if (strlen($coverageDescription) > 1000) {
        throw new AreaManagementValidationException('The coverage description must not exceed 1000 characters.');
    }
    $notes = trim((string) ($input['notes'] ?? ''));
    if (strlen($notes) > 1000) {
        throw new AreaManagementValidationException('Notes must not exceed 1000 characters.');
    }

    // The boundary is required; province/municipality/barangay/district are
    // filled from the boundary center via reverse geocoding on the client, so
    // the center is always derived here from the drawn shape.
    try {
        $boundaryGeoJson = geo_validate_boundary_geojson($input['boundary_geojson'] ?? null);
    } catch (GeoValidationException $e) {
        throw new AreaManagementValidationException($e->getMessage());
    }
    if ($boundaryGeoJson === null) {
        throw new AreaManagementValidationException('Draw the zone boundary on the map before saving.');
    }
    $center = geo_boundary_center($boundaryGeoJson);

    return [
        'zone_name' => $zoneName,
        'classification' => $classification,
        'province' => $province !== '' ? $province : null,
        'municipality' => $municipality !== '' ? $municipality : null,
        'barangay' => $barangay !== '' ? $barangay : null,
        'district' => $district !== '' ? $district : null,
        'coverage_description' => $coverageDescription !== '' ? $coverageDescription : null,
        'boundary_geojson' => $boundaryGeoJson,
        'center_lat' => $center !== null ? number_format($center[0], 7, '.', '') : null,
        'center_lng' => $center !== null ? number_format($center[1], 7, '.', '') : null,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function area_zone_create(PDO $pdo, int $actorUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Zone creation must own its database transaction.');
    }
    $data = area_zone_validate_common($input);

    try {
        $pdo->beginTransaction();
        if (area_management_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage area zones.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_area_zones
                (zone_name, classification, province, municipality, barangay, district,
                 coverage_description, boundary_geojson, center_lat, center_lng, notes, created_by_user_id)
             VALUES
                (:zone_name, :classification, :province, :municipality, :barangay, :district,
                 :coverage_description, :boundary_geojson, :center_lat, :center_lng, :notes, :actor)'
        );
        try {
            $insert->execute([
                ':zone_name' => $data['zone_name'],
                ':classification' => $data['classification'],
                ':province' => $data['province'],
                ':municipality' => $data['municipality'],
                ':barangay' => $data['barangay'],
                ':district' => $data['district'],
                ':coverage_description' => $data['coverage_description'],
                ':boundary_geojson' => $data['boundary_geojson'],
                ':center_lat' => $data['center_lat'],
                ':center_lng' => $data['center_lng'],
                ':notes' => $data['notes'],
                ':actor' => $actorUserId,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new AreaManagementValidationException('A zone with that name already exists.');
            }
            throw $e;
        }
        $zoneId = (int) $pdo->lastInsertId();

        record_audit_event(
            $pdo,
            $actorUserId,
            'area_management',
            'area_zone_created',
            'area_zone',
            $zoneId,
            'Added an area management zone record.',
            ['zone_name' => $data['zone_name'], 'classification' => $data['classification']]
        );

        $pdo->commit();

        return ['zone_id' => $zoneId, 'zone_name' => $data['zone_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function area_zone_update(PDO $pdo, int $actorUserId, int $zoneId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Zone update must own its database transaction.');
    }
    $data = area_zone_validate_common($input);
    $isActive = trim((string) ($input['is_active'] ?? '1')) === '1' ? 1 : 0;

    try {
        $pdo->beginTransaction();
        if (area_management_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage area zones.');
        }
        $existing = $pdo->prepare('SELECT id FROM tbl_area_zones WHERE id = :id LIMIT 1 FOR UPDATE');
        $existing->execute([':id' => $zoneId]);
        if ($existing->fetchColumn() === false) {
            throw new AreaManagementValidationException('The selected zone does not exist.');
        }

        $update = $pdo->prepare(
            'UPDATE tbl_area_zones
             SET zone_name = :zone_name,
                 classification = :classification,
                 province = :province,
                 municipality = :municipality,
                 barangay = :barangay,
                 district = :district,
                 coverage_description = :coverage_description,
                 boundary_geojson = :boundary_geojson,
                 center_lat = :center_lat,
                 center_lng = :center_lng,
                 notes = :notes,
                 is_active = :is_active,
                 updated_by_user_id = :actor
             WHERE id = :id'
        );
        try {
            $update->execute([
                ':zone_name' => $data['zone_name'],
                ':classification' => $data['classification'],
                ':province' => $data['province'],
                ':municipality' => $data['municipality'],
                ':barangay' => $data['barangay'],
                ':district' => $data['district'],
                ':coverage_description' => $data['coverage_description'],
                ':boundary_geojson' => $data['boundary_geojson'],
                ':center_lat' => $data['center_lat'],
                ':center_lng' => $data['center_lng'],
                ':notes' => $data['notes'],
                ':is_active' => $isActive,
                ':actor' => $actorUserId,
                ':id' => $zoneId,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new AreaManagementValidationException('A zone with that name already exists.');
            }
            throw $e;
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'area_management',
            'area_zone_updated',
            'area_zone',
            $zoneId,
            'Updated an area management zone record.',
            ['zone_name' => $data['zone_name'], 'classification' => $data['classification'], 'is_active' => $isActive === 1]
        );

        $pdo->commit();

        return ['zone_id' => $zoneId, 'zone_name' => $data['zone_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
