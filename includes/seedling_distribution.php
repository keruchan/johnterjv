<?php
/** Read-only distribution statistics use actual release movements, never request estimates. */
require_once __DIR__ . '/registry_filters.php';
require_once __DIR__ . '/geo.php';

function seedling_distribution_year(array $input = []): int
{
    $value = $input['distribution_year'] ?? date('Y');
    $year = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    return $year !== false && $year >= 2000 && $year <= 9998 ? $year : (int) date('Y');
}

function seedling_annual_distribution(PDO $pdo, int $year): array
{
    if ($year < 2000 || $year > 9998) { throw new InvalidArgumentException('Invalid distribution year.'); }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(-quantity_delta), 0) FROM tbl_seedling_stock_movements
        WHERE movement_type = 'released' AND quantity_delta < 0 AND created_at >= ? AND created_at < ?");
    $stmt->execute([$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']);
    $total = (int) $stmt->fetchColumn();
    $target = (int) CERTREEFY_SEEDLING_ANNUAL_TARGET;
    return ['year'=>$year, 'total'=>$total, 'target'=>$target, 'remaining'=>max(0, $target-$total),
        'above'=>max(0, $total-$target), 'percent'=>min(100, $target > 0 ? round($total/$target*100, 1) : 0)];
}

function render_seedling_annual_target(PDO $pdo, ?int $year = null): void
{
    $annual = seedling_annual_distribution($pdo, $year ?? seedling_distribution_year($_GET));
    ?>
    <section class="docket-panel mb-3" aria-label="Annual seedling distribution target">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <strong><?php echo $annual['year']; ?> Seedling Distribution</strong>
            <span class="tabular"><?php echo number_format($annual['total']); ?> / <?php echo number_format($annual['target']); ?> seedlings</span>
        </div>
        <div class="progress" role="progressbar" aria-label="Annual distribution progress" aria-valuenow="<?php echo $annual['percent']; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?php echo $annual['percent']; ?>%"></div></div>
        <p class="small text-secondary mt-2 mb-0">Annual target · January 1–December 31 · Stock released for pickup<?php if ($annual['above'] > 0): ?> · <?php echo number_format($annual['above']); ?> above target<?php else: ?> · <?php echo number_format($annual['remaining']); ?> remaining to target<?php endif; ?></p>
    </section>
    <?php
}

function seedling_distribution_data(PDO $pdo, array $input): array
{
    $year = seedling_distribution_year($input);
    $where = ["m.movement_type = 'released'", 'm.quantity_delta < 0', 'm.created_at >= :year_from', 'm.created_at < :year_to'];
    $params = [':year_from'=>$year.'-01-01 00:00:00', ':year_to'=>($year+1).'-01-01 00:00:00'];
    registry_sql_dates($where, $params, 'm.created_at', $input);
    $stmt = $pdo->prepare('SELECT m.inventory_id, i.common_name, r.planting_barangay, r.planting_municipality,
        r.planting_latitude, r.planting_longitude, -m.quantity_delta AS seedlings
        FROM tbl_seedling_stock_movements m
        INNER JOIN tbl_seedling_inventory i ON i.id = m.inventory_id
        LEFT JOIN tbl_seedling_requests r ON r.id = m.request_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id');
    $stmt->execute($params);
    $municipality = is_string($input['distribution_municipality'] ?? '') ? trim($input['distribution_municipality'] ?? '') : '';
    $barangay = is_string($input['distribution_barangay'] ?? '') ? trim($input['distribution_barangay'] ?? '') : '';
    $species = is_scalar($input['distribution_species'] ?? '') ? (int) ($input['distribution_species'] ?? 0) : 0;
    $groups = $municipalities = $barangays = $speciesOptions = [];
    $total = $unassigned = 0;
    foreach ($stmt->fetchAll() as $row) {
        $muni = trim((string) ($row['planting_municipality'] ?? ''));
        $bgy = trim((string) ($row['planting_barangay'] ?? ''));
        if ($muni !== '') { $municipalities[$muni] = $muni; }
        if ($bgy !== '' && ($municipality === '' || $muni === $municipality)) { $barangays[$bgy] = $bgy; }
        $speciesOptions[(int) $row['inventory_id']] = $row['common_name'];
        if (($municipality !== '' && $municipality !== $muni) || ($barangay !== '' && $barangay !== $bgy)
            || ($species > 0 && $species !== (int) $row['inventory_id'])) { continue; }
        $count = (int) $row['seedlings'];
        $total += $count;
        if ($muni === '' || $bgy === '') { $unassigned += $count; continue; }
        $key = mb_strtolower($muni . "\n" . $bgy, 'UTF-8');
        if (!isset($groups[$key])) { $groups[$key] = ['municipality'=>$muni, 'barangay'=>$bgy, 'total'=>0, 'lat'=>null, 'lng'=>null]; }
        $groups[$key]['total'] += $count;
        if ($groups[$key]['lat'] === null && $row['planting_latitude'] !== null && $row['planting_longitude'] !== null
            && geo_point_in_laguna((float) $row['planting_latitude'], (float) $row['planting_longitude'])) {
            $groups[$key]['lat'] = (float) $row['planting_latitude'];
            $groups[$key]['lng'] = (float) $row['planting_longitude'];
        }
    }
    usort($groups, static fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['barangay'], $b['barangay']));
    sort($municipalities); sort($barangays); asort($speciesOptions);
    return compact('year','total','unassigned','groups','municipalities','barangays','speciesOptions','municipality','barangay','species');
}

function render_seedling_distribution(PDO $pdo, string $assetBase = '../../', bool $showInsights = false): void
{
    $data = seedling_distribution_data($pdo, $_GET);
    render_seedling_annual_target($pdo, $data['year']);
    ?>
    <section class="docket-panel mb-4" aria-labelledby="seedlingDistributionHeading">
        <div class="section-heading"><h2 id="seedlingDistributionHeading">Seedlings Released per Barangay</h2><span class="section-note"><?php echo number_format($data['total']); ?> seedlings</span></div>
        <?php if ($showInsights): ?><div class="alert alert-light border"><strong>Distribution Insights</strong><ul class="small mb-0"><?php if ($data['groups']): $top=$data['groups'][0]; ?><li>Highest recorded barangay total: <?php echo e($top['barangay'].', '.$top['municipality']); ?> — <?php echo number_format($top['total']); ?> seedlings.</li><?php endif; ?><li><?php echo number_format($data['unassigned']); ?> released seedlings have no assigned barangay in this selection.</li><li>Stock-release movements determine these totals; subsequent pickup does not increase them.</li></ul></div><?php endif; ?>
        <form method="get" class="row g-3 align-items-end mb-3">
            <?php foreach ($_GET as $key=>$value): if (!is_scalar($value) || str_starts_with((string) $key, 'distribution_') || in_array($key, ['page','before','edit','view'], true)) { continue; } ?>
                <input type="hidden" name="<?php echo e((string) $key); ?>" value="<?php echo e((string) $value); ?>">
            <?php endforeach; ?>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="distributionYear">Year</label><input class="form-control" type="number" min="2000" max="9998" id="distributionYear" name="distribution_year" value="<?php echo $data['year']; ?>" required></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="distributionMunicipality">Municipality</label><select class="form-select" id="distributionMunicipality" name="distribution_municipality"><option value="">All municipalities</option><?php foreach ($data['municipalities'] as $value): ?><option<?php echo $value === $data['municipality'] ? ' selected' : ''; ?>><?php echo e($value); ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="distributionBarangay">Barangay</label><select class="form-select" id="distributionBarangay" name="distribution_barangay"><option value="">All barangays</option><?php foreach ($data['barangays'] as $value): ?><option<?php echo $value === $data['barangay'] ? ' selected' : ''; ?>><?php echo e($value); ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="distributionSpecies">Species</label><select class="form-select" id="distributionSpecies" name="distribution_species"><option value="">All species</option><?php foreach ($data['speciesOptions'] as $id=>$name): ?><option value="<?php echo (int) $id; ?>"<?php echo $id === $data['species'] ? ' selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-1"><button class="btn btn-certreefy w-100" type="submit">Filter</button></div>
        </form>
        <p class="small text-secondary">Release dates follow the date range above. Map markers use recorded planting-site locations.</p>
        <div class="map-container mb-2" id="seedlingDistributionMap" style="height:360px" aria-label="Barangay seedling distribution map"></div>
        <div class="d-flex flex-wrap gap-3 small mb-3" aria-label="Distribution map legend"><span><i class="bi bi-circle-fill" style="color:#a9c4ac" aria-hidden="true"></i> 1–99</span><span><i class="bi bi-circle-fill" style="color:#4a7c59" aria-hidden="true"></i> 100–499</span><span><i class="bi bi-circle-fill" style="color:#1f4937" aria-hidden="true"></i> 500+ seedlings</span></div>
        <?php if ($data['groups'] === []): ?><p class="text-secondary">No barangay totals are available for this selection.</p><?php else: ?>
            <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Municipality</th><th>Barangay</th><th>Released seedlings</th><th>Map location</th></tr></thead><tbody><?php foreach ($data['groups'] as $group): ?><tr><td><?php echo e($group['municipality']); ?></td><td><?php echo e($group['barangay']); ?></td><td><?php echo number_format($group['total']); ?></td><td><?php echo $group['lat'] === null ? 'Not recorded' : 'Recorded'; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if ($data['unassigned'] > 0): ?><p class="small text-secondary mb-0">Unassigned barangay: <?php echo number_format($data['unassigned']); ?> seedlings.</p><?php endif; ?>
        <script type="application/json" id="seedlingDistributionData"><?php echo json_encode($data['groups'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
        <script src="<?php echo e($assetBase); ?>js/seedling-distribution.js?v=<?php echo e(certreefy_asset_version('js/seedling-distribution.js')); ?>"></script>
    </section>
    <?php
}
