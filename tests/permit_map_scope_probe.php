<?php
/** Read-only geographic checks. No database connection or fixtures required. */
require_once __DIR__ . '/../includes/permit.php';
require_once __DIR__ . '/../includes/permit_inspections.php';

$checks = 0;
function scope_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}

foreach ([
    'Santa Cruz' => [14.2814, 121.4161],
    'Calamba' => [14.2117, 121.1653],
    'San Pablo' => [14.069, 121.325],
    'Siniloan' => [14.421, 121.447],
] as $name => [$lat, $lng]) {
    scope_check(geo_point_in_laguna($lat, $lng), $name . ' is selectable');
    $data = permit_normalize_application_data(['latitude' => (string) $lat, 'longitude' => (string) $lng]);
    scope_check(permit_validate_application_data($data, false) === [], $name . ' draft coordinates accepted');
    scope_check(count(permit_inspection_coordinates($data)) === 2, $name . ' inspection coordinates accepted');
}
foreach ([
    'Manila' => [14.5995, 120.9842],
    'Tanauan, Batangas (inside province bounding rectangle)' => [14.086, 121.15],
    'Tiaong, Quezon' => [13.958, 121.322],
    'Invalid latitude' => [91, 121.4],
] as $name => [$lat, $lng]) {
    scope_check(!geo_point_in_laguna($lat, $lng), $name . ' is outside Laguna');
    $data = permit_normalize_application_data(['latitude' => (string) $lat, 'longitude' => (string) $lng]);
    scope_check(permit_validate_application_data($data, false) !== [], $name . ' draft rejected');
    $rejected = false;
    try {
        permit_inspection_coordinates($data);
    } catch (PermitInspectionValidationException $e) {
        $rejected = true;
    }
    scope_check($rejected, $name . ' inspection rejected');
}
scope_check(permit_validate_application_data(permit_normalize_application_data([]), false) === [], 'Blank optional coordinates remain accepted');
scope_check(permit_validate_application_data(permit_normalize_application_data(['latitude' => '14.28']), false) !== [], 'Incomplete coordinate pair rejected');
scope_check(permit_inspection_coordinates([]) === [null, null], 'Blank inspection coordinates remain accepted');
scope_check(!geo_point_in_laguna(INF, 121.4), 'Nonfinite coordinates rejected');
echo $checks . ' checks passed.' . PHP_EOL;
