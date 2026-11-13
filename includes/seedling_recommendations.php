<?php
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/seedling.php';

/** Requesters prefer the longest finite stock forecast; unknown estimates are explicit. */
function seedling_request_recommendations(PDO $pdo, array $species): array
{
    $forecast = array_column(inventory_report_depletion_forecast($pdo), null, 'id');
    foreach ($species as &$row) {
        $row['depletion_date'] = $forecast[(int)$row['id']]['depletion_date'] ?? null;
        $row['days_to_depletion'] = $forecast[(int)$row['id']]['days_to_depletion'] ?? null;
    }
    unset($row);
    usort($species, static function ($a,$b) {
        $left = $a['days_to_depletion']; $right = $b['days_to_depletion'];
        if (($left === null) !== ($right === null)) { return $left === null ? 1 : -1; }
        return ($right ?? 0) <=> ($left ?? 0) ?: (int)$b['available_quantity'] <=> (int)$a['available_quantity'] ?: strcmp($a['common_name'],$b['common_name']);
    });
    return $species;
}

/** Donations replenish the greatest stock need, respecting confirmed indigenous classification. */
function seedling_donation_recommendations(PDO $pdo, array $requirement): array
{
    $strict = false;
    if (($requirement['policy_code'] ?? '') === 'tree_origin_tally') {
        $stmt = $pdo->prepare('SELECT natural_count FROM tbl_permit_tallies WHERE application_id = ? AND is_current = 1 LIMIT 1');
        $stmt->execute([(int)$requirement['application_id']]); $strict = (int)$stmt->fetchColumn() > 0;
    }
    $forecast = array_column(inventory_report_depletion_forecast($pdo),null,'id');
    $species = array_values(array_filter(seedling_inventory_list($pdo,true), static fn($row) => !$strict || $row['indigenous_status'] === 'indigenous'));
    foreach ($species as &$row) { $row['days_to_depletion'] = $forecast[(int)$row['id']]['days_to_depletion'] ?? null; }
    unset($row);
    usort($species, static function($a,$b) {
        return (int)($b['indigenous_status']==='indigenous') <=> (int)($a['indigenous_status']==='indigenous')
            ?: (int)$b['is_low_stock'] <=> (int)$a['is_low_stock']
            ?: ($a['days_to_depletion'] ?? PHP_INT_MAX) <=> ($b['days_to_depletion'] ?? PHP_INT_MAX)
            ?: (int)$a['available_quantity'] <=> (int)$b['available_quantity'] ?: strcmp($a['common_name'],$b['common_name']);
    });
    return array_slice($species,0,3);
}
