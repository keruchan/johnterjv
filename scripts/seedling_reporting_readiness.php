<?php
/** Read-only, CLI-only evidence for planning barangay distribution and stock insights. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/analytics.php';

try {
    $columns = [];
    foreach (['tbl_seedling_requests', 'tbl_seedling_request_items', 'tbl_seedling_stock_movements', 'tbl_planting_sites'] as $table) {
        $columns[$table] = array_column($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Field');
    }
    $requests = $pdo->query("SELECT current_status, COUNT(*) AS requests FROM tbl_seedling_requests GROUP BY current_status ORDER BY current_status")->fetchAll();
    $releases = $pdo->query("SELECT COALESCE(r.current_status, 'Unlinked movement') AS request_status,
        COUNT(*) AS movement_rows, COUNT(DISTINCT m.request_id) AS requests,
        COALESCE(SUM(-m.quantity_delta), 0) AS seedlings_deducted
        FROM tbl_seedling_stock_movements m LEFT JOIN tbl_seedling_requests r ON r.id = m.request_id
        WHERE m.movement_type = 'released' AND m.quantity_delta < 0
        GROUP BY COALESCE(r.current_status, 'Unlinked movement') ORDER BY request_status")->fetchAll();
    $locationCoverage = $pdo->query("SELECT COUNT(*) AS requests,
        SUM(CASE WHEN TRIM(COALESCE(planting_location, '')) <> '' THEN 1 ELSE 0 END) AS with_location_text
        FROM tbl_seedling_requests")->fetch();
    $structuredLocation = array_values(array_intersect(['planting_barangay', 'planting_municipality', 'planting_site_id', 'planting_latitude', 'planting_longitude'], $columns['tbl_seedling_requests']));
    $depletion = inventory_report_depletion_forecast($pdo);
    $result = [
        'generated_at' => date(DATE_ATOM),
        'read_only' => true,
        'request_columns' => $columns['tbl_seedling_requests'],
        'request_item_columns' => $columns['tbl_seedling_request_items'],
        'planting_site_columns' => $columns['tbl_planting_sites'],
        'structured_request_location_columns' => $structuredLocation,
        'request_counts_by_status' => $requests,
        'release_movements_by_request_status' => $releases,
        'location_coverage' => $locationCoverage,
        'depletion_forecast_coverage' => [
            'active_species' => count($depletion),
            'species_with_release_history' => count(array_filter($depletion, static fn ($s) => $s['days_to_depletion'] !== null)),
            'species_without_release_history' => count(array_filter($depletion, static fn ($s) => $s['days_to_depletion'] === null)),
        ],
        'interpretation' => [
            'Stock is deducted at fulfilment (ready_for_pickup); collection is recorded separately at claimed.',
            'Do not equate requested or approved quantities with actual movement quantities.',
            'Free-text planting locations do not establish verified barangay boundaries or map coordinates.',
            'Unlinked movements must remain separate from barangay distribution totals.',
            'Existing depletion forecasts use recent release rates; no release history means no finite estimate.',
        ],
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Reporting readiness check failed. Verify database availability and module schema.' . PHP_EOL);
    exit(1);
}
