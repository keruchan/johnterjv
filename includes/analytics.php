<?php
/**
 * Read-only reporting/analytics aggregates.
 *
 * Two consumers share this layer:
 *   - CENRO Analytics (`pages/cenro/analytics.php`): a cross-domain operational
 *     overview for any active RPS or Superadmin (no permission gate, matching
 *     Area Management and Advisories).
 *   - EMS Inventory Reports (`pages/ems/inventory-reports.php`): stock, movement,
 *     and release summaries for the seedling program, EMS-only.
 *
 * Every function here is a pure read: it opens no transaction, writes nothing,
 * and never mutates domain state. Date ranges filter each domain on its own
 * natural creation timestamp (permit applications by intake `created_at`,
 * illegal-logging reports and seedling requests by `submitted_at`, stock
 * movements by `created_at`); snapshot aggregates (current inventory, current
 * zone classification counts) are point-in-time and ignore the range.
 */

require_once __DIR__ . '/seedling.php';
require_once __DIR__ . '/illegal_logging.php';
require_once __DIR__ . '/area_management.php';

// ---------------------------------------------------------------------------
// Actors (no new exceptional permission; role membership is the gate)
// ---------------------------------------------------------------------------

/** CENRO cross-domain analytics: any active RPS or Superadmin. */
function analytics_actor(PDO $pdo, int $actorUserId): ?array
{
    $stmt = $pdo->prepare('SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();
    if (!$actor || (string) $actor['status'] !== 'active') {
        return null;
    }

    return in_array((string) $actor['role'], ['rps', 'superadmin'], true) ? $actor : null;
}

/** EMS inventory reports: active EMS only. */
function inventory_reports_actor(PDO $pdo, int $actorUserId): ?array
{
    $stmt = $pdo->prepare('SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();
    if (!$actor || (string) $actor['status'] !== 'active') {
        return null;
    }

    return (string) $actor['role'] === 'ems' ? $actor : null;
}

// ---------------------------------------------------------------------------
// Date range
// ---------------------------------------------------------------------------

/**
 * Normalizes an optional inclusive date range. Invalid dates are dropped; a
 * reversed range (from > to) is swapped so callers always get a sane window.
 */
function analytics_normalize_range(array $input): array
{
    $from = analytics_valid_date((string) ($input['date_from'] ?? ''));
    $to = analytics_valid_date((string) ($input['date_to'] ?? ''));

    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    return ['from' => $from, 'to' => $to];
}

/** Returns the input if it is a real Y-m-d calendar date, otherwise ''. */
function analytics_valid_date(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

/**
 * Builds a reusable WHERE fragment for a range against the given column.
 * Returns [sqlFragment, params]; each bound parameter name is unique per call
 * via the supplied prefix (native prepares forbid reusing a placeholder name).
 */
function analytics_range_conditions(array $range, string $column, string $prefix): array
{
    $conditions = [];
    $params = [];
    if ($range['from'] !== '') {
        $conditions[] = $column . ' >= :' . $prefix . '_from';
        $params[':' . $prefix . '_from'] = $range['from'] . ' 00:00:00';
    }
    if ($range['to'] !== '') {
        $conditions[] = $column . ' <= :' . $prefix . '_to';
        $params[':' . $prefix . '_to'] = $range['to'] . ' 23:59:59';
    }

    return [$conditions, $params];
}

// ---------------------------------------------------------------------------
// Permit domain (submitted-application cohort by submission date)
//
// Drafts are excluded entirely: they are private to the applicant and hidden
// from the processing roles who view analytics, so counting them would be both
// misleading and inconsistent with what RPS/Superadmin can otherwise see. The
// date range filters on `submitted_at` (an application's entry into the
// pipeline); `tbl_permit_applications` has no draft-creation timestamp column.
// ---------------------------------------------------------------------------

function analytics_permit_base_where(array $range, array &$params): string
{
    [$conditions, $params] = analytics_range_conditions($range, 'a.submitted_at', 'permit');
    $conditions[] = "a.application_status <> 'draft'";

    return ' WHERE ' . implode(' AND ', $conditions);
}

function analytics_permit_summary(PDO $pdo, array $range): array
{
    $params = [];
    $whereSql = analytics_permit_base_where($range, $params);

    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_applications,
            SUM(a.decision_status = \'approved\') AS approved,
            SUM(a.decision_status = \'declined\') AS declined,
            SUM(a.validity_status = \'active\') AS active_permits,
            SUM(a.validity_status = \'completed\') AS completed,
            SUM(a.validity_status = \'expired\') AS expired
         FROM tbl_permit_applications a' . $whereSql
    );
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    // Permits physically issued: a submitted application with a released permit.
    $issuedStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tbl_permit_applications a
         INNER JOIN tbl_permits p ON p.application_id = a.id AND p.released_at IS NOT NULL'
        . $whereSql
    );
    $issuedStmt->execute($params);

    return [
        'total_applications' => (int) ($row['total_applications'] ?? 0),
        'approved' => (int) ($row['approved'] ?? 0),
        'declined' => (int) ($row['declined'] ?? 0),
        'permits_issued' => (int) $issuedStmt->fetchColumn(),
        'active_permits' => (int) ($row['active_permits'] ?? 0),
        'completed' => (int) ($row['completed'] ?? 0),
        'expired' => (int) ($row['expired'] ?? 0),
    ];
}

function analytics_permit_status_breakdown(PDO $pdo, array $range): array
{
    $params = [];
    $whereSql = analytics_permit_base_where($range, $params);
    $stmt = $pdo->prepare(
        'SELECT a.application_status AS status, COUNT(*) AS total
         FROM tbl_permit_applications a' . $whereSql . '
         GROUP BY a.application_status'
    );
    $stmt->execute($params);
    $counts = [];
    foreach ($stmt->fetchAll() as $r) {
        $counts[(string) $r['status']] = (int) $r['total'];
    }

    return $counts;
}

// ---------------------------------------------------------------------------
// Illegal logging domain
// ---------------------------------------------------------------------------

function analytics_illegal_logging_summary(PDO $pdo, array $range): array
{
    [$conditions, $params] = analytics_range_conditions($range, 'r.submitted_at', 'il');
    $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    $statusStmt = $pdo->prepare(
        'SELECT r.current_status AS status, COUNT(*) AS total
         FROM tbl_illegal_logging_reports r' . $whereSql . '
         GROUP BY r.current_status'
    );
    $statusStmt->execute($params);
    $statusCounts = [];
    foreach (array_keys(illegal_logging_report_statuses()) as $status) {
        $statusCounts[$status] = 0;
    }
    $total = 0;
    foreach ($statusStmt->fetchAll() as $r) {
        $statusCounts[(string) $r['status']] = (int) $r['total'];
        $total += (int) $r['total'];
    }

    $outcomeStmt = $pdo->prepare(
        'SELECT r.resolution_outcome AS outcome, COUNT(*) AS total
         FROM tbl_illegal_logging_reports r
         WHERE r.current_status = \'resolved\' AND r.resolution_outcome IS NOT NULL'
        . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions)) . '
         GROUP BY r.resolution_outcome'
    );
    $outcomeStmt->execute($params);
    $outcomeCounts = [];
    foreach (array_keys(illegal_logging_resolution_outcomes()) as $outcome) {
        $outcomeCounts[$outcome] = 0;
    }
    foreach ($outcomeStmt->fetchAll() as $r) {
        $outcomeCounts[(string) $r['outcome']] = (int) $r['total'];
    }

    return [
        'total' => $total,
        'status_breakdown' => $statusCounts,
        'resolved' => $statusCounts['resolved'] ?? 0,
        'outcome_breakdown' => $outcomeCounts,
    ];
}

// ---------------------------------------------------------------------------
// Seedling domain
// ---------------------------------------------------------------------------

function analytics_seedling_summary(PDO $pdo, array $range): array
{
    [$conditions, $params] = analytics_range_conditions($range, 'r.submitted_at', 'sr');
    $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    $statusStmt = $pdo->prepare(
        'SELECT r.current_status AS status, COUNT(*) AS total
         FROM tbl_seedling_requests r' . $whereSql . '
         GROUP BY r.current_status'
    );
    $statusStmt->execute($params);
    $statusCounts = [];
    foreach (array_keys(seedling_request_statuses()) as $status) {
        $statusCounts[$status] = 0;
    }
    $total = 0;
    foreach ($statusStmt->fetchAll() as $r) {
        $statusCounts[(string) $r['status']] = (int) $r['total'];
        $total += (int) $r['total'];
    }

    // Seedlings distributed = total released stock (movement deltas are negative
    // for releases, so negate the sum). Filtered on the movement date.
    [$mConditions, $mParams] = analytics_range_conditions($range, 'm.created_at', 'srm');
    $mWhere = array_merge(["m.movement_type = 'released'"], $mConditions);
    $distStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(-m.quantity_delta), 0)
         FROM tbl_seedling_stock_movements m
         WHERE ' . implode(' AND ', $mWhere)
    );
    $distStmt->execute($mParams);

    return [
        'total_requests' => $total,
        'status_breakdown' => $statusCounts,
        'seedlings_distributed' => (int) $distStmt->fetchColumn(),
    ];
}

// ---------------------------------------------------------------------------
// Area zone domain (snapshot; range ignored)
// ---------------------------------------------------------------------------

function analytics_area_zone_summary(PDO $pdo): array
{
    return area_zone_summary($pdo);
}

// ---------------------------------------------------------------------------
// Geographic layer
// ---------------------------------------------------------------------------

/**
 * Map-ready geographic datasets for the Geographic analytics tab:
 * cutting-activity heat points (submitted applications weighted by tree
 * count, with recorded completions using the actual trees-cut figure),
 * illegal-logging incident markers, and drawn zone boundaries. Read-only,
 * system-wide (drafts excluded — they are private to their applicant).
 */
function analytics_geographic_data(PDO $pdo): array
{
    $permitRows = $pdo->query(
        "SELECT a.transaction_id, a.latitude, a.longitude, a.application_status,
                COALESCE(SUM(t.quantity), 0) AS tree_total,
                c.trees_cut_count
         FROM tbl_permit_applications a
         LEFT JOIN tbl_permit_trees t ON t.application_id = a.id
         LEFT JOIN tbl_permit_cutting_completions c ON c.application_id = a.id
         WHERE a.application_status <> 'draft'
           AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
         GROUP BY a.id"
    )->fetchAll();

    $heatPoints = [];
    $permitMarkers = [];
    foreach ($permitRows as $row) {
        $weight = $row['trees_cut_count'] !== null
            ? (int) $row['trees_cut_count']
            : (int) $row['tree_total'];
        $heatPoints[] = [(float) $row['latitude'], (float) $row['longitude'], max(1, $weight)];
        $permitMarkers[] = [
            'lat' => (float) $row['latitude'],
            'lng' => (float) $row['longitude'],
            'label' => sprintf(
                '%s — %s, %d tree(s)%s',
                (string) ($row['transaction_id'] ?? 'Application'),
                ucwords(str_replace('_', ' ', (string) $row['application_status'])),
                (int) $row['tree_total'],
                $row['trees_cut_count'] !== null ? ', ' . (int) $row['trees_cut_count'] . ' cut' : ''
            ),
            'color' => $row['trees_cut_count'] !== null ? '#1b4332' : '#2d6a4f',
        ];
    }

    $ilRows = $pdo->query(
        "SELECT report_reference, latitude, longitude, current_status
         FROM tbl_illegal_logging_reports
         WHERE latitude IS NOT NULL AND longitude IS NOT NULL"
    )->fetchAll();

    $ilMarkers = [];
    foreach ($ilRows as $row) {
        $ilMarkers[] = [
            'lat' => (float) $row['latitude'],
            'lng' => (float) $row['longitude'],
            'label' => sprintf(
                '%s — %s',
                (string) $row['report_reference'],
                ucwords(str_replace('_', ' ', (string) $row['current_status']))
            ),
            'color' => (string) $row['current_status'] === 'resolved' ? '#7c877e' : '#a5402a',
        ];
    }

    return [
        'heat_points' => $heatPoints,
        'permit_markers' => $permitMarkers,
        'il_markers' => $ilMarkers,
        'zones' => area_zone_map_features($pdo),
        'permit_located_count' => count($permitMarkers),
        'il_located_count' => count($ilMarkers),
    ];
}

// ---------------------------------------------------------------------------
// Assembled overview + CSV
// ---------------------------------------------------------------------------

function analytics_overview(PDO $pdo, array $range): array
{
    return [
        'permits' => analytics_permit_summary($pdo, $range),
        'permit_status_breakdown' => analytics_permit_status_breakdown($pdo, $range),
        'illegal_logging' => analytics_illegal_logging_summary($pdo, $range),
        'seedlings' => analytics_seedling_summary($pdo, $range),
        'zones' => analytics_area_zone_summary($pdo),
    ];
}

/** Flat [section, metric, value] rows for the CENRO analytics CSV export. */
function analytics_csv_rows(PDO $pdo, array $range): array
{
    $overview = analytics_overview($pdo, $range);
    $rows = [['Section', 'Metric', 'Value']];

    $permits = $overview['permits'];
    $rows[] = ['Permits', 'Applications submitted', $permits['total_applications']];
    $rows[] = ['Permits', 'Approved', $permits['approved']];
    $rows[] = ['Permits', 'Declined', $permits['declined']];
    $rows[] = ['Permits', 'Permits issued', $permits['permits_issued']];
    $rows[] = ['Permits', 'Active permits', $permits['active_permits']];
    $rows[] = ['Permits', 'Completed', $permits['completed']];
    $rows[] = ['Permits', 'Expired', $permits['expired']];

    $il = $overview['illegal_logging'];
    $rows[] = ['Illegal logging', 'Total reports', $il['total']];
    foreach ($il['status_breakdown'] as $status => $count) {
        $rows[] = ['Illegal logging', 'Status: ' . illegal_logging_report_status_label($status), $count];
    }
    foreach ($il['outcome_breakdown'] as $outcome => $count) {
        $rows[] = ['Illegal logging', 'Resolved outcome: ' . illegal_logging_resolution_outcome_label($outcome), $count];
    }

    $seedlings = $overview['seedlings'];
    $rows[] = ['Seedlings', 'Total requests', $seedlings['total_requests']];
    foreach ($seedlings['status_breakdown'] as $status => $count) {
        $rows[] = ['Seedlings', 'Status: ' . seedling_request_status_label($status), $count];
    }
    $rows[] = ['Seedlings', 'Seedlings distributed', $seedlings['seedlings_distributed']];

    $zones = $overview['zones'];
    $rows[] = ['Area zones', 'Total active zones', $zones['total']];
    $rows[] = ['Area zones', 'Allowed', $zones['allowed_count']];
    $rows[] = ['Area zones', 'Restricted', $zones['restricted_count']];
    $rows[] = ['Area zones', 'Protected', $zones['protected_count']];

    return $rows;
}

// ---------------------------------------------------------------------------
// EMS inventory reports
// ---------------------------------------------------------------------------

/** Per-species current stock with low-stock flags, plus totals. */
function inventory_report_stock_snapshot(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, common_name, scientific_name, available_quantity, low_stock_threshold, is_active
         FROM tbl_seedling_inventory
         ORDER BY common_name'
    );
    $species = $stmt->fetchAll();
    $totalStock = 0;
    $lowStock = 0;
    $activeSpecies = 0;
    foreach ($species as &$row) {
        $available = (int) $row['available_quantity'];
        $threshold = (int) $row['low_stock_threshold'];
        $isActive = (int) $row['is_active'] === 1;
        $row['is_low_stock'] = $isActive && $threshold > 0 && $available <= $threshold;
        $totalStock += $available;
        if ($isActive) {
            $activeSpecies++;
        }
        if ($row['is_low_stock']) {
            $lowStock++;
        }
    }
    unset($row);

    return [
        'species' => $species,
        'total_species' => count($species),
        'active_species' => $activeSpecies,
        'total_stock' => $totalStock,
        'low_stock_count' => $lowStock,
    ];
}

/** Movement totals by type over the range, plus the net change. */
function inventory_report_movement_summary(PDO $pdo, array $range): array
{
    [$conditions, $params] = analytics_range_conditions($range, 'm.created_at', 'mv');
    $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare(
        'SELECT m.movement_type AS type,
                COUNT(*) AS entries,
                COALESCE(SUM(m.quantity_delta), 0) AS net_delta,
                COALESCE(SUM(GREATEST(m.quantity_delta, 0)), 0) AS total_in,
                COALESCE(SUM(-LEAST(m.quantity_delta, 0)), 0) AS total_out
         FROM tbl_seedling_stock_movements m' . $whereSql . '
         GROUP BY m.movement_type'
    );
    $stmt->execute($params);
    $byType = [];
    foreach (seedling_movement_types() as $type) {
        $byType[$type] = ['entries' => 0, 'net_delta' => 0, 'total_in' => 0, 'total_out' => 0];
    }
    $netDelta = 0;
    $entries = 0;
    foreach ($stmt->fetchAll() as $r) {
        $type = (string) $r['type'];
        $byType[$type] = [
            'entries' => (int) $r['entries'],
            'net_delta' => (int) $r['net_delta'],
            'total_in' => (int) $r['total_in'],
            'total_out' => (int) $r['total_out'],
        ];
        $netDelta += (int) $r['net_delta'];
        $entries += (int) $r['entries'];
    }

    return ['by_type' => $byType, 'net_delta' => $netDelta, 'total_entries' => $entries];
}

/** Release records: fulfilled/claimed seedling requests within the range. */
function inventory_report_release_records(PDO $pdo, array $range, int $limit = 100): array
{
    [$conditions, $params] = analytics_range_conditions($range, 'r.fulfilled_at', 'rel');
    $where = array_merge(['r.fulfilled_at IS NOT NULL'], $conditions);
    $stmt = $pdo->prepare(
        'SELECT r.id, r.request_reference, r.requester_name, r.current_status,
                r.fulfilled_at, r.claimed_on, r.claimed_by_name,
                COALESCE(SUM(i.quantity_approved), 0) AS total_released
         FROM tbl_seedling_requests r
         LEFT JOIN tbl_seedling_request_items i ON i.request_id = r.id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY r.id, r.request_reference, r.requester_name, r.current_status,
                  r.fulfilled_at, r.claimed_on, r.claimed_by_name
         ORDER BY r.fulfilled_at DESC, r.id DESC
         LIMIT :limit'
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/** Flat CSV rows for the EMS inventory report export (movement + release detail). */
function inventory_report_csv_rows(PDO $pdo, array $range): array
{
    $snapshot = inventory_report_stock_snapshot($pdo);
    $movements = inventory_report_movement_summary($pdo, $range);
    $releases = inventory_report_release_records($pdo, $range);

    $rows = [['Section', 'Item', 'Detail', 'Value']];

    $rows[] = ['Summary', 'Total species', '', $snapshot['total_species']];
    $rows[] = ['Summary', 'Active species', '', $snapshot['active_species']];
    $rows[] = ['Summary', 'Total stock on hand', '', $snapshot['total_stock']];
    $rows[] = ['Summary', 'Low-stock species', '', $snapshot['low_stock_count']];

    foreach ($snapshot['species'] as $s) {
        $rows[] = [
            'Stock',
            (string) $s['common_name'],
            ((int) $s['is_active'] === 1 ? 'Active' : 'Inactive') . ($s['is_low_stock'] ? ', LOW STOCK' : ''),
            (int) $s['available_quantity'],
        ];
    }

    foreach ($movements['by_type'] as $type => $data) {
        $rows[] = ['Movement', ucfirst($type), 'In / Out / Net', $data['total_in'] . ' / ' . $data['total_out'] . ' / ' . $data['net_delta']];
    }
    $rows[] = ['Movement', 'Net change', '', $movements['net_delta']];

    foreach ($releases as $r) {
        $rows[] = [
            'Release',
            (string) ($r['request_reference'] ?? ('#' . $r['id'])),
            (string) $r['requester_name'] . ' (' . seedling_request_status_label((string) $r['current_status']) . ')',
            (int) $r['total_released'],
        ];
    }

    return $rows;
}

// ===========================================================================
// DEEP ANALYTICS: descriptive time series, predictive forecasting, and
// prescriptive recommendations. All still pure reads (no writes, no
// transactions). Forecasting uses ordinary least-squares linear regression
// over a trailing monthly window — a transparent, explainable method suited
// to an operational government dashboard (no opaque ML, results are auditable).
// ===========================================================================

/**
 * Returns a trailing list of month buckets ending with the current month.
 * Each entry: ['key' => 'YYYY-MM', 'label' => 'Mon YYYY'].
 */
function analytics_trend_months(int $count = 12): array
{
    $count = max(2, min($count, 36));
    $months = [];
    $cursor = new DateTimeImmutable('first day of this month 00:00:00');
    $cursor = $cursor->modify('-' . ($count - 1) . ' months');
    for ($i = 0; $i < $count; $i++) {
        $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
        $cursor = $cursor->modify('+1 month');
    }

    return $months;
}

/** Aligns a ['YYYY-MM' => number] map onto the given month axis, filling gaps with 0. */
function analytics_align_to_months(array $months, array $countsByMonth): array
{
    $aligned = [];
    foreach ($months as $month) {
        $aligned[] = (int) ($countsByMonth[$month['key']] ?? 0);
    }

    return $aligned;
}

/**
 * Ordinary least-squares linear forecast over an evenly-spaced numeric series.
 * Returns the next $periods projected values (clamped to >= 0), the slope
 * (units/month), a coarse trend direction, and R^2 goodness-of-fit.
 */
function analytics_linear_forecast(array $values, int $periods = 3): array
{
    $values = array_values(array_map('floatval', $values));
    $n = count($values);
    $periods = max(1, min($periods, 12));

    if ($n < 2) {
        $flat = $n === 1 ? max(0, (int) round($values[0])) : 0;

        return [
            'forecast' => array_fill(0, $periods, $flat),
            'slope' => 0.0,
            'trend' => 'flat',
            'r2' => 0.0,
            'next' => $flat,
        ];
    }

    $sumX = 0.0; $sumY = 0.0; $sumXY = 0.0; $sumX2 = 0.0;
    foreach ($values as $i => $v) {
        $sumX += $i;
        $sumY += $v;
        $sumXY += $i * $v;
        $sumX2 += $i * $i;
    }
    $denominator = ($n * $sumX2) - ($sumX * $sumX);
    $slope = $denominator != 0.0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denominator : 0.0;
    $intercept = ($sumY - ($slope * $sumX)) / $n;

    $meanY = $sumY / $n;
    $ssTotal = 0.0; $ssResidual = 0.0;
    foreach ($values as $i => $v) {
        $predicted = $intercept + ($slope * $i);
        $ssResidual += ($v - $predicted) ** 2;
        $ssTotal += ($v - $meanY) ** 2;
    }
    $r2 = $ssTotal > 0 ? max(0.0, 1 - ($ssResidual / $ssTotal)) : 0.0;

    $forecast = [];
    for ($k = 1; $k <= $periods; $k++) {
        $forecast[] = max(0, (int) round($intercept + ($slope * ($n - 1 + $k))));
    }

    // A slope small relative to the series average is treated as flat so tiny
    // sampling noise is not reported as a "trend".
    $magnitude = abs($slope);
    $reference = max(1.0, abs($meanY) * 0.08);
    $trend = $magnitude < $reference ? 'flat' : ($slope > 0 ? 'rising' : 'falling');

    return [
        'forecast' => $forecast,
        'slope' => round($slope, 2),
        'trend' => $trend,
        'r2' => round($r2, 2),
        'next' => $forecast[0],
    ];
}

/** Percent change of the most recent month vs the mean of the prior months (descriptive momentum). */
function analytics_momentum(array $values): ?float
{
    $values = array_values($values);
    $n = count($values);
    if ($n < 2) {
        return null;
    }
    $recent = (float) $values[$n - 1];
    $priorSlice = array_slice($values, 0, $n - 1);
    $priorMean = array_sum($priorSlice) / max(1, count($priorSlice));
    if ($priorMean <= 0) {
        return $recent > 0 ? 100.0 : 0.0;
    }

    return round((($recent - $priorMean) / $priorMean) * 100, 1);
}

/** Buckets a single COUNT-per-month query onto the trailing month axis. */
function analytics_monthly_counts(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['bucket']] = (int) $row['total'];
    }

    return $map;
}

/**
 * Cross-domain monthly trend series (trailing window) for the CENRO analytics
 * charts. Deliberately independent of the page date filter: forecasting needs a
 * consistent history, so trends always use the last N months.
 */
function analytics_trend_series(PDO $pdo, int $months = 12): array
{
    $axis = analytics_trend_months($months);
    $since = $axis[0]['key'] . '-01 00:00:00';

    $permitSubs = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS bucket, COUNT(*) AS total
         FROM tbl_permit_applications
         WHERE submitted_at IS NOT NULL AND application_status <> 'draft'
           AND submitted_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $permitIssued = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(released_at, '%Y-%m') AS bucket, COUNT(*) AS total
         FROM tbl_permits
         WHERE released_at IS NOT NULL AND released_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $ilReceived = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS bucket, COUNT(*) AS total
         FROM tbl_illegal_logging_reports
         WHERE submitted_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $ilResolved = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(resolved_at, '%Y-%m') AS bucket, COUNT(*) AS total
         FROM tbl_illegal_logging_reports
         WHERE resolved_at IS NOT NULL AND resolved_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $seedRequests = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS bucket, COUNT(*) AS total
         FROM tbl_seedling_requests
         WHERE submitted_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $seedDistributed = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(-quantity_delta), 0) AS total
         FROM tbl_seedling_stock_movements
         WHERE movement_type = 'released' AND created_at >= '" . $since . "'
         GROUP BY bucket"
    );

    return [
        'labels' => array_map(static fn (array $m): string => $m['label'], $axis),
        'permit_submissions' => analytics_align_to_months($axis, $permitSubs),
        'permit_issued' => analytics_align_to_months($axis, $permitIssued),
        'il_received' => analytics_align_to_months($axis, $ilReceived),
        'il_resolved' => analytics_align_to_months($axis, $ilResolved),
        'seedling_requests' => analytics_align_to_months($axis, $seedRequests),
        'seedlings_distributed' => analytics_align_to_months($axis, $seedDistributed),
    ];
}

/** Month labels continuing $count months past the last label in $labels ("Aug 2026"...). */
function analytics_forecast_labels(array $labels, int $count = 3): array
{
    $last = end($labels);
    $cursor = $last !== false ? DateTimeImmutable::createFromFormat('!M Y', (string) $last) : false;
    if ($cursor === false) {
        $cursor = new DateTimeImmutable('first day of this month');
    }
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $cursor = $cursor->modify('+1 month');
        $out[] = $cursor->format('M Y');
    }

    return $out;
}

/**
 * Assembles the CENRO deep-analytics bundle: descriptive trend series plus a
 * 3-month forecast per key series, ready to hand to Chart.js. History and
 * forecast arrays are padded so a dashed forecast line visually continues the
 * solid history line (the bridge point is the last historical value).
 */
function analytics_forecast_bundle(PDO $pdo, int $months = 12, int $periods = 3): array
{
    $series = analytics_trend_series($pdo, $months);
    $histLen = count($series['labels']);
    $forecastLabels = analytics_forecast_labels($series['labels'], $periods);
    $allLabels = array_merge($series['labels'], $forecastLabels);

    $build = static function (array $history) use ($histLen, $periods): array {
        $meta = analytics_linear_forecast($history, $periods);
        $historyPadded = array_merge($history, array_fill(0, $periods, null));
        // Forecast dataset: nulls across history except the last point (bridge),
        // then the projected values.
        $forecastPadded = array_fill(0, $histLen, null);
        $forecastPadded[$histLen - 1] = $history[$histLen - 1] ?? null;
        foreach ($meta['forecast'] as $value) {
            $forecastPadded[] = $value;
        }

        return [
            'history' => $historyPadded,
            'forecast' => $forecastPadded,
            'meta' => $meta,
            'momentum' => analytics_momentum($history),
        ];
    };

    return [
        'labels' => $allLabels,
        'history_length' => $histLen,
        'forecast_length' => $periods,
        'permit_submissions' => $build($series['permit_submissions']),
        'permit_issued' => $build($series['permit_issued']),
        'il_received' => $build($series['il_received']),
        'il_resolved' => $build($series['il_resolved']),
        'seedling_requests' => $build($series['seedling_requests']),
        'seedlings_distributed' => $build($series['seedlings_distributed']),
    ];
}

/** Count of applications currently sitting in an active processing stage (backlog). */
function analytics_permit_backlog(PDO $pdo): array
{
    $stages = ['submitted', 'under_review', 'awaiting_documents', 'awaiting_inspection', 'awaiting_decision'];
    $placeholders = implode(',', array_map(static fn (string $s): string => "'" . $s . "'", $stages));
    $total = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_permit_applications WHERE application_status IN (' . $placeholders . ')'
    )->fetchColumn();
    // Applications submitted more than 14 days ago and still in a processing stage.
    $aging = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_permit_applications
         WHERE application_status IN (' . $placeholders . ')
           AND submitted_at IS NOT NULL AND submitted_at < (NOW() - INTERVAL 14 DAY)'
    )->fetchColumn();

    return ['pending' => $total, 'aging' => $aging];
}

/**
 * Prescriptive recommendations for CENRO: rule-based, derived from the
 * descriptive summary, the forecast bundle, and live backlog. Each item:
 * ['level','icon','title','detail'] where level is one of
 * critical|warning|info|positive. Ordered most-urgent first.
 */
function analytics_cenro_recommendations(PDO $pdo, array $overview, array $bundle): array
{
    $recs = [];
    $backlog = analytics_permit_backlog($pdo);

    // 1. Permit backlog / aging applications.
    if ($backlog['aging'] > 0) {
        $recs[] = [
            'level' => $backlog['aging'] >= 5 ? 'critical' : 'warning',
            'icon' => 'bi-hourglass-bottom',
            'title' => $backlog['aging'] . ' application' . ($backlog['aging'] === 1 ? '' : 's') . ' pending over 14 days',
            'detail' => 'Prioritize review to keep processing within service standards. ' . $backlog['pending'] . ' total application(s) are in an active stage.',
        ];
    } elseif ($backlog['pending'] >= 8) {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-inboxes',
            'title' => 'Growing review queue (' . $backlog['pending'] . ' in progress)',
            'detail' => 'Consider assigning additional reviewers or scheduling inspections to prevent a backlog from forming.',
        ];
    }

    // 2. Illegal logging pressure (forecast rising + unresolved backlog).
    $ilForecast = $bundle['il_received']['meta'];
    $ilUnresolved = ($overview['illegal_logging']['total'] ?? 0) - ($overview['illegal_logging']['resolved'] ?? 0);
    if ($ilForecast['trend'] === 'rising') {
        $recs[] = [
            'level' => 'critical',
            'icon' => 'bi-graph-up-arrow',
            'title' => 'Illegal-logging reports are trending upward',
            'detail' => 'Projected ~' . $ilForecast['next'] . ' report(s) next month. Increase field-verification capacity and coordinate patrols in recently reported areas.',
        ];
    } elseif ($ilUnresolved >= 3) {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-shield-exclamation',
            'title' => $ilUnresolved . ' illegal-logging report(s) still unresolved',
            'detail' => 'Dispatch field verification or record a resolution outcome so enforcement records stay current.',
        ];
    }

    // 3. Seedling demand outlook (coordinate supply with EMS).
    $seedForecast = $bundle['seedling_requests']['meta'];
    if ($seedForecast['trend'] === 'rising') {
        $recs[] = [
            'level' => 'info',
            'icon' => 'bi-flower1',
            'title' => 'Seedling demand is projected to rise',
            'detail' => 'Around ' . $seedForecast['next'] . ' request(s) expected next month. Coordinate with the EMS nursery to confirm stock ahead of demand.',
        ];
    }

    // 4. Permit approval outlook.
    $permitForecast = $bundle['permit_submissions']['meta'];
    if ($permitForecast['trend'] === 'rising') {
        $recs[] = [
            'level' => 'info',
            'icon' => 'bi-file-earmark-plus',
            'title' => 'Permit applications are increasing',
            'detail' => 'Projected ~' . $permitForecast['next'] . ' new application(s) next month. Ensure inspection scheduling and document review keep pace.',
        ];
    }

    // 5. Expiring/expired permits needing follow-up.
    $expired = (int) ($overview['permits']['expired'] ?? 0);
    if ($expired > 0) {
        $recs[] = [
            'level' => 'info',
            'icon' => 'bi-calendar-x',
            'title' => $expired . ' permit(s) have expired',
            'detail' => 'Confirm whether cutting was completed or follow up on unused permits for the record.',
        ];
    }

    if ($recs === []) {
        $recs[] = [
            'level' => 'positive',
            'icon' => 'bi-check-circle',
            'title' => 'Operations are within normal ranges',
            'detail' => 'No backlog, rising-risk trend, or supply concern was detected for the current period. Keep monitoring.',
        ];
    }

    return $recs;
}

// ---------------------------------------------------------------------------
// EMS predictive/prescriptive: stock-depletion forecasting + reorder advice
// ---------------------------------------------------------------------------

/**
 * Per-species stock-depletion forecast from the recent average daily release
 * rate. Returns each active species with its average daily release over the
 * lookback window, projected days-to-depletion, and a projected depletion date
 * (null when there has been no recent release, i.e. no depletion trajectory).
 */
function inventory_report_depletion_forecast(PDO $pdo, int $lookbackDays = 90): array
{
    $lookbackDays = max(14, min($lookbackDays, 365));
    $stmt = $pdo->prepare(
        'SELECT i.id, i.common_name, i.available_quantity, i.low_stock_threshold,
                COALESCE(SUM(-m.quantity_delta), 0) AS released_window
         FROM tbl_seedling_inventory i
         LEFT JOIN tbl_seedling_stock_movements m
                ON m.inventory_id = i.id
               AND m.movement_type = \'released\'
               AND m.created_at >= (NOW() - INTERVAL :days DAY)
         WHERE i.is_active = 1
         GROUP BY i.id, i.common_name, i.available_quantity, i.low_stock_threshold
         ORDER BY i.common_name'
    );
    $stmt->bindValue(':days', $lookbackDays, PDO::PARAM_INT);
    $stmt->execute();

    $species = [];
    foreach ($stmt->fetchAll() as $row) {
        $available = (int) $row['available_quantity'];
        $released = (int) $row['released_window'];
        $avgDaily = $released / $lookbackDays;
        $daysToDepletion = $avgDaily > 0 ? (int) floor($available / $avgDaily) : null;
        $depletionDate = null;
        if ($daysToDepletion !== null) {
            $depletionDate = (new DateTimeImmutable('today'))
                ->modify('+' . $daysToDepletion . ' days')
                ->format('Y-m-d');
        }
        $species[] = [
            'id' => (int) $row['id'],
            'common_name' => (string) $row['common_name'],
            'available' => $available,
            'threshold' => (int) $row['low_stock_threshold'],
            'released_window' => $released,
            'avg_daily' => round($avgDaily, 2),
            'days_to_depletion' => $daysToDepletion,
            'depletion_date' => $depletionDate,
            'lookback_days' => $lookbackDays,
        ];
    }

    return $species;
}

/**
 * Prescriptive reorder recommendations for EMS derived from live stock, the
 * low-stock threshold, and the depletion forecast. Most-urgent first.
 */
function inventory_report_recommendations(array $snapshot, array $depletion): array
{
    $recs = [];
    $byId = [];
    foreach ($depletion as $d) {
        $byId[$d['id']] = $d;
    }

    foreach ($snapshot['species'] as $s) {
        if ((int) $s['is_active'] !== 1) {
            continue;
        }
        $id = (int) $s['id'];
        $forecast = $byId[$id] ?? null;
        $available = (int) $s['available_quantity'];
        $name = (string) $s['common_name'];
        $days = $forecast['days_to_depletion'] ?? null;

        if ((int) $available === 0) {
            $recs[] = [
                'level' => 'critical',
                'icon' => 'bi-x-octagon',
                'title' => $name . ' is out of stock',
                'detail' => 'Restock before accepting new requests for this species.',
                'sort' => 0,
            ];
        } elseif ($days !== null && $days <= 30) {
            $recs[] = [
                'level' => 'critical',
                'icon' => 'bi-hourglass-bottom',
                'title' => $name . ' may run out in ~' . $days . ' day' . ($days === 1 ? '' : 's'),
                'detail' => 'At the recent release rate (' . $forecast['avg_daily'] . '/day) stock of ' . $available . ' depletes around ' . date('M j, Y', strtotime((string) $forecast['depletion_date'])) . '. Schedule restocking now.',
                'sort' => $days,
            ];
        } elseif (!empty($s['is_low_stock'])) {
            $recs[] = [
                'level' => 'warning',
                'icon' => 'bi-exclamation-triangle',
                'title' => $name . ' is at/below its low-stock threshold',
                'detail' => 'Available ' . $available . ' vs threshold ' . (int) $s['low_stock_threshold'] . '. Plan a replenishment batch.',
                'sort' => 100 + $available,
            ];
        } elseif ($days !== null && $days <= 60) {
            $recs[] = [
                'level' => 'info',
                'icon' => 'bi-calendar-event',
                'title' => $name . ' projected to deplete in ~' . $days . ' days',
                'detail' => 'Not urgent yet, but monitor — consider adding to the next restocking cycle.',
                'sort' => 200 + $days,
            ];
        }
    }

    usort($recs, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    $recs = array_map(static function (array $r): array {
        unset($r['sort']);
        return $r;
    }, $recs);

    if ($recs === []) {
        $recs[] = [
            'level' => 'positive',
            'icon' => 'bi-check-circle',
            'title' => 'Seedling stock levels are healthy',
            'detail' => 'No species is out of stock, below threshold, or projected to deplete within 60 days.',
        ];
    }

    return $recs;
}

/** Monthly seedling stock movement series (in vs out) for the EMS trend chart. */
function inventory_report_movement_trend(PDO $pdo, int $months = 12): array
{
    $axis = analytics_trend_months($months);
    $since = $axis[0]['key'] . '-01 00:00:00';

    $incoming = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(GREATEST(quantity_delta,0)),0) AS total
         FROM tbl_seedling_stock_movements
         WHERE created_at >= '" . $since . "'
         GROUP BY bucket"
    );
    $released = analytics_monthly_counts(
        $pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(-LEAST(quantity_delta,0)),0) AS total
         FROM tbl_seedling_stock_movements
         WHERE created_at >= '" . $since . "'
         GROUP BY bucket"
    );

    $releasedSeries = analytics_align_to_months($axis, $released);
    $forecast = analytics_linear_forecast($releasedSeries, 3);

    return [
        'labels' => array_map(static fn (array $m): string => $m['label'], $axis),
        'incoming' => analytics_align_to_months($axis, $incoming),
        'released' => $releasedSeries,
        'released_forecast' => $forecast,
    ];
}

// ---------------------------------------------------------------------------
// CSV rendering
// ---------------------------------------------------------------------------

/** Streams rows as a CSV download. Callers must not have sent output yet. */
function analytics_send_csv(string $filename, array $rows): void
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        $filename = 'export.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens accented species names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}
