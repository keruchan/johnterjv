<?php
/**
 * EMS Inventory Reports: deep seedling analytics (EMS-only, read-only).
 *   - Descriptive  -> stock composition, movement trend, on-hand/release tables
 *   - Predictive   -> stock-depletion forecast (avg daily release -> days left)
 *   - Prescriptive -> reorder / restock recommendations
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

if (inventory_reports_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to view inventory reports.');
}

$range = analytics_normalize_range($_GET);
$rangeQuery = array_filter(['date_from' => $range['from'], 'date_to' => $range['to']]);

try {
    $snapshot = inventory_report_stock_snapshot($pdo);
    $movements = inventory_report_movement_summary($pdo, $range);
    $releases = inventory_report_release_records($pdo, $range);
    $depletion = inventory_report_depletion_forecast($pdo, 90);
    $recommendations = inventory_report_recommendations($snapshot, $depletion);
    $movementTrend = inventory_report_movement_trend($pdo, 12);
} catch (PDOException $e) {
    error_log('[CERTREEFY INVENTORY REPORTS LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load inventory reports at this time. Please try again later.');
}

$palette = ['#2d6a4f', '#1f6a68', '#a8721f', '#a5402a', '#74a57f', '#1b4332', '#7c877e', '#3a7d5d'];

// Stock composition (active species with stock on hand).
$composition = ['labels' => [], 'data' => [], 'colors' => []];
$i = 0;
foreach ($snapshot['species'] as $s) {
    if ((int) $s['is_active'] !== 1 || (int) $s['available_quantity'] <= 0) { continue; }
    $composition['labels'][] = (string) $s['common_name'];
    $composition['data'][] = (int) $s['available_quantity'];
    $composition['colors'][] = $palette[$i++ % count($palette)];
}

// Depletion bar (species with a finite projected days-to-depletion, soonest first).
$depletionSorted = array_values(array_filter($depletion, static fn (array $d): bool => $d['days_to_depletion'] !== null));
usort($depletionSorted, static fn (array $a, array $b): int => $a['days_to_depletion'] <=> $b['days_to_depletion']);
$depletionChart = ['labels' => [], 'data' => [], 'colors' => []];
foreach (array_slice($depletionSorted, 0, 12) as $d) {
    $days = (int) $d['days_to_depletion'];
    $depletionChart['labels'][] = $d['common_name'];
    $depletionChart['data'][] = $days;
    $depletionChart['colors'][] = $days <= 30 ? '#a5402a' : ($days <= 60 ? '#a8721f' : '#2d6a4f');
}

$emsChartData = [
    'composition' => $composition,
    'movementTrend' => [
        'labels' => $movementTrend['labels'],
        'incoming' => $movementTrend['incoming'],
        'released' => $movementTrend['released'],
    ],
    'depletion' => $depletionChart,
];

$releasedForecast = $movementTrend['released_forecast'];

/** Depletion status badge for a species forecast row. */
function inventory_depletion_status(array $species, array $forecast): array
{
    $available = (int) $species['available'];
    $days = $forecast['days_to_depletion'] ?? null;
    if ($available === 0) {
        return ['label' => 'Out of stock', 'class' => 'text-bg-danger'];
    }
    if ($days !== null && $days <= 30) {
        return ['label' => 'Reorder soon', 'class' => 'text-bg-danger'];
    }
    if ($available <= (int) $species['threshold'] && (int) $species['threshold'] > 0) {
        return ['label' => 'Low stock', 'class' => 'text-bg-warning'];
    }
    if ($days !== null && $days <= 60) {
        return ['label' => 'Watch', 'class' => 'text-bg-warning'];
    }
    if ($days === null) {
        return ['label' => 'No recent releases', 'class' => 'text-bg-secondary'];
    }

    return ['label' => 'Healthy', 'class' => 'text-bg-success'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Inventory Reports</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'inventory_reports'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Inventory Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Stock, movement, and release analytics with depletion forecasts and reorder guidance.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right me-1"></i> Logout</button>
                        </form>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <section class="row g-3 mb-4" aria-label="Inventory summary">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-box-seam"></i></span><span class="ledger-tag">Stock</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $snapshot['total_stock']; ?></div>
                        <div class="ledger-caption">Seedlings on hand</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-diagram-3"></i></span><span class="ledger-tag">Species</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $snapshot['active_species']; ?></div>
                        <div class="ledger-caption"><?php echo (int) $snapshot['total_species']; ?> total species</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="ledger-tag">Low stock</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $snapshot['low_stock_count']; ?></div>
                        <div class="ledger-caption">Species at/below threshold</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-arrow-left-right"></i></span><span class="ledger-tag">Net</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $movements['net_delta']; ?></div>
                        <div class="ledger-caption">Net change in period</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-label="Report period">
                <form method="get" action="inventory-reports.php" class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-3">
                        <label for="dateFrom" class="form-label">Movements from</label>
                        <input type="date" class="form-control" id="dateFrom" name="date_from" value="<?php echo e($range['from']); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="dateTo" class="form-label">Movements to</label>
                        <input type="date" class="form-control" id="dateTo" name="date_to" value="<?php echo e($range['to']); ?>">
                    </div>
                    <div class="col-lg-6 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy"><i class="bi bi-funnel me-1"></i>Apply period</button>
                        <a class="btn btn-outline-secondary" href="inventory-reports.php"><i class="bi bi-x-lg me-1"></i>Clear</a>
                        <a class="btn btn-outline-success ms-auto" href="inventory-reports-export.php<?php echo $rangeQuery === [] ? '' : ('?' . e(http_build_query($rangeQuery))); ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
                    </div>
                </form>
                <p class="small text-secondary mt-2 mb-0">Tables follow the selected period. Forecasts use the last 90 days; the trend uses the trailing 12 months.</p>
            </section>

            <ul class="nav analytics-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-e-desc" data-bs-toggle="tab" data-bs-target="#pane-e-desc" data-tab="e-desc" type="button" role="tab"><i class="bi bi-bar-chart-line me-1"></i>Overview</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-e-fore" data-bs-toggle="tab" data-bs-target="#pane-e-fore" data-tab="e-fore" type="button" role="tab"><i class="bi bi-graph-up-arrow me-1"></i>Forecast</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-e-reco" data-bs-toggle="tab" data-bs-target="#pane-e-reco" data-tab="e-reco" type="button" role="tab"><i class="bi bi-lightbulb me-1"></i>Recommendations<span class="badge text-bg-secondary ms-1"><?php echo count($recommendations); ?></span></button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- ============ OVERVIEW (descriptive) ============ -->
                <div class="tab-pane fade show active" id="pane-e-desc" role="tabpanel" aria-labelledby="tab-e-desc">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-7">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Stock Movement &mdash; monthly (last 12 months)</h2></div>
                                <div class="chart-frame"><canvas id="e_movement"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-5">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Stock Composition</h2></div>
                                <div class="chart-frame"><canvas id="e_composition"></canvas></div>
                            </section>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-5">
                            <section class="docket-panel h-100" aria-labelledby="movementHeading">
                                <div class="section-heading"><h2 id="movementHeading" class="h6 mb-0">Movement (selected period)</h2></div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead><tr><th scope="col">Type</th><th scope="col" class="text-end">In</th><th scope="col" class="text-end">Out</th><th scope="col" class="text-end">Net</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($movements['by_type'] as $type => $data): ?>
                                                <tr>
                                                    <td class="text-capitalize"><?php echo e($type); ?></td>
                                                    <td class="text-end tabular text-success">+<?php echo (int) $data['total_in']; ?></td>
                                                    <td class="text-end tabular text-danger">-<?php echo (int) $data['total_out']; ?></td>
                                                    <td class="text-end tabular fw-semibold"><?php echo (int) $data['net_delta']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-top">
                                                <th scope="row">Net change</th>
                                                <td colspan="2"></td>
                                                <td class="text-end tabular fw-bold"><?php echo (int) $movements['net_delta']; ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </section>
                        </div>
                        <div class="col-lg-7">
                            <section class="docket-panel h-100" aria-labelledby="stockHeading">
                                <div class="section-heading">
                                    <h2 id="stockHeading" class="h6 mb-0">Stock on Hand</h2>
                                    <span class="section-note tabular"><?php echo count($snapshot['species']); ?> species</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search species">
                                        <thead><tr><th scope="col">Species</th><th scope="col" class="text-end">Available</th><th scope="col" data-tt-filter="Status">Status</th></tr></thead>
                                        <tbody>
                                            <?php if ($snapshot['species'] === []): ?>
                                                <tr><td colspan="3" class="text-center text-secondary py-4">No species recorded yet.</td></tr>
                                            <?php endif; ?>
                                            <?php foreach ($snapshot['species'] as $s): ?>
                                                <tr>
                                                    <td class="fw-semibold text-break"><?php echo e((string) $s['common_name']); ?>
                                                        <?php if (!empty($s['scientific_name'])): ?><div class="small text-secondary fst-italic"><?php echo e((string) $s['scientific_name']); ?></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-end tabular"><?php echo (int) $s['available_quantity']; ?></td>
                                                    <td>
                                                        <?php if ((int) $s['is_active'] !== 1): ?>
                                                            <span class="badge text-bg-secondary">Inactive</span>
                                                        <?php elseif ($s['is_low_stock']): ?>
                                                            <span class="badge text-bg-warning">Low stock</span>
                                                        <?php else: ?>
                                                            <span class="badge text-bg-success">OK</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>

                        <div class="col-12">
                            <section class="docket-panel" aria-labelledby="releaseHeading">
                                <div class="section-heading">
                                    <h2 id="releaseHeading" class="h6 mb-0">Release Records</h2>
                                    <span class="section-note tabular"><?php echo count($releases); ?> fulfilled request<?php echo count($releases) === 1 ? '' : 's'; ?></span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search reference or requester">
                                        <thead><tr><th scope="col">Reference</th><th scope="col">Requester</th><th scope="col" data-tt-filter="Status">Status</th><th scope="col">Fulfilled</th><th scope="col">Claimed by</th><th scope="col" class="text-end">Seedlings</th></tr></thead>
                                        <tbody>
                                            <?php if ($releases === []): ?>
                                                <tr><td colspan="6" class="text-center text-secondary py-4">No seedling releases in this period.</td></tr>
                                            <?php endif; ?>
                                            <?php foreach ($releases as $r): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo e((string) ($r['request_reference'] ?? ('#' . $r['id']))); ?></td>
                                                    <td><?php echo e((string) $r['requester_name']); ?></td>
                                                    <td><span class="badge <?php echo e(seedling_request_status_badge((string) $r['current_status'])); ?>"><?php echo e(seedling_request_status_label((string) $r['current_status'])); ?></span></td>
                                                    <td class="small text-secondary"><?php echo $r['fulfilled_at'] !== null ? e(date('M j, Y', strtotime((string) $r['fulfilled_at']))) : '-'; ?></td>
                                                    <td class="small text-secondary"><?php echo e((string) ($r['claimed_by_name'] ?? '-')); ?></td>
                                                    <td class="text-end tabular"><?php echo (int) $r['total_released']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <!-- ============ FORECAST (predictive) ============ -->
                <div class="tab-pane fade" id="pane-e-fore" role="tabpanel" aria-labelledby="tab-e-fore">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>What's likely next.</strong> Estimates use each species' average daily release over 90 days, projected 3 months ahead.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Estimated days until depletion</h2></div>
                                <?php if ($depletionChart['labels'] === []): ?>
                                    <p class="text-secondary small mb-0 py-4 text-center">No recent releases yet — nothing to project.</p>
                                <?php else: ?>
                                    <div class="chart-frame"><canvas id="e_depletion"></canvas></div>
                                    <div class="analytics-legend">
                                        <span class="lg-key"><span class="lg-dot" style="background:#a5402a"></span>&le; 30 days</span>
                                        <span class="lg-key"><span class="lg-dot" style="background:#a8721f"></span>31&ndash;60 days</span>
                                        <span class="lg-key"><span class="lg-dot" style="background:#2d6a4f"></span>&gt; 60 days</span>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Release demand outlook</h2><span class="metric-good"><?php
                                    $rt = $releasedForecast['trend'];
                                    $ic = $rt === 'rising' ? 'bi-arrow-up-right' : ($rt === 'falling' ? 'bi-arrow-down-right' : 'bi-arrow-right');
                                    echo '<span class="forecast-chip ' . e($rt) . '"><i class="bi ' . $ic . '"></i>' . e(ucfirst($rt)) . '</span>';
                                ?></span></div>
                                <p class="mb-2">Projected seedlings released next month:
                                    <span class="h4 fw-semibold tabular d-block mt-1"><?php echo (int) $releasedForecast['next']; ?></span>
                                </p>
                                <p class="small text-secondary mb-0">Based on the trailing 12-month trend (fit R&sup2; <?php echo e(number_format((float) $releasedForecast['r2'], 2)); ?>). Rising means demand is growing &mdash; plan intake accordingly.</p>
                            </section>
                        </div>
                    </div>

                    <section class="docket-panel">
                        <div class="section-heading"><h2 class="h6 mb-0">Per-species depletion forecast</h2><span class="section-note">90-day release rate</span></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search species">
                                <thead><tr><th scope="col">Species</th><th scope="col" class="text-end">In stock</th><th scope="col" class="text-end">Avg released/day</th><th scope="col" class="text-end">Est. days left</th><th scope="col">Projected depletion</th><th scope="col" data-tt-filter="Status">Status</th></tr></thead>
                                <tbody>
                                    <?php if ($depletion === []): ?>
                                        <tr><td colspan="6" class="text-center text-secondary py-4">No active species to forecast.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($depletion as $d): ?>
                                        <?php $status = inventory_depletion_status($d, $d); ?>
                                        <tr>
                                            <td class="fw-semibold text-break"><?php echo e((string) $d['common_name']); ?></td>
                                            <td class="text-end tabular"><?php echo (int) $d['available']; ?></td>
                                            <td class="text-end tabular"><?php echo e(number_format((float) $d['avg_daily'], 2)); ?></td>
                                            <td class="text-end tabular fw-semibold"><?php echo $d['days_to_depletion'] !== null ? (int) $d['days_to_depletion'] : '&mdash;'; ?></td>
                                            <td class="small text-secondary"><?php echo $d['depletion_date'] !== null ? e(date('M j, Y', strtotime((string) $d['depletion_date']))) : 'No recent releases'; ?></td>
                                            <td><span class="badge <?php echo e($status['class']); ?>"><?php echo e($status['label']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- ============ RECOMMENDATIONS (prescriptive) ============ -->
                <div class="tab-pane fade" id="pane-e-reco" role="tabpanel" aria-labelledby="tab-e-reco">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>What to do.</strong> Reorder guidance from live stock, thresholds, and the forecast — most urgent first.</p>
                    <div class="row">
                        <div class="col-lg-9">
                            <section class="docket-panel">
                                <div class="section-heading"><h2 class="h6 mb-0">Recommended actions</h2><span class="section-note"><?php echo count($recommendations); ?> item<?php echo count($recommendations) === 1 ? '' : 's'; ?></span></div>
                                <?php foreach ($recommendations as $rec): ?>
                                    <div class="reco-item level-<?php echo e((string) $rec['level']); ?>">
                                        <span class="reco-icon"><i class="bi <?php echo e((string) $rec['icon']); ?>"></i></span>
                                        <div>
                                            <div class="reco-title"><?php echo e((string) $rec['title']); ?></div>
                                            <div class="reco-detail"><?php echo e((string) $rec['detail']); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <p class="small text-secondary mt-3 mb-0">Advisory, based on recent release rates — actual demand may vary.</p>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        const EMS = <?php echo json_encode($emsChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const P = { fern:'#2d6a4f', teal:'#1f6a68', amber:'#a8721f', rust:'#a5402a', canopy:'#1b4332' };

        if (window.Chart) {
            Chart.defaults.font.family = "Inter, system-ui, sans-serif";
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#566058';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.boxWidth = 8;
            Chart.defaults.plugins.legend.labels.padding = 14;
        }

        const charts = {};
        const initialized = {};

        function emptyCanvas(el, msg) {
            const ctx = el.getContext('2d');
            ctx.font = '13px Inter, sans-serif'; ctx.fillStyle = '#7c877e'; ctx.textAlign = 'center';
            ctx.fillText(msg, el.width / 2, el.height / 2);
        }

        function initTab(tab) {
            if (initialized[tab]) return;
            initialized[tab] = true;

            if (tab === 'e-desc') {
                const mv = document.getElementById('e_movement');
                if (mv && !charts.e_movement) {
                    charts.e_movement = new Chart(mv, {
                        data: { labels: EMS.movementTrend.labels, datasets: [
                            { type:'bar', label:'Incoming', data: EMS.movementTrend.incoming, backgroundColor:'rgba(45,106,79,.55)', borderRadius:3 },
                            { type:'bar', label:'Released', data: EMS.movementTrend.released, backgroundColor:'rgba(165,64,42,.55)', borderRadius:3 }
                        ]},
                        options: { responsive:true, maintainAspectRatio:false,
                            scales:{ y:{ beginAtZero:true, ticks:{precision:0}, grid:{color:'rgba(32,43,34,.06)'} }, x:{ grid:{display:false} } },
                            plugins:{ legend:{ position:'bottom' } } }
                    });
                }
                const comp = document.getElementById('e_composition');
                if (comp && !charts.e_composition) {
                    if ((EMS.composition.data || []).some(v => v > 0)) {
                        charts.e_composition = new Chart(comp, {
                            type:'doughnut',
                            data:{ labels: EMS.composition.labels, datasets:[{ data: EMS.composition.data, backgroundColor: EMS.composition.colors, borderWidth:2, borderColor:'#fff' }] },
                            options:{ responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{ position:'right' } } }
                        });
                    } else { emptyCanvas(comp, 'No stock on hand'); charts.e_composition = true; }
                }
            }

            if (tab === 'e-fore') {
                const dep = document.getElementById('e_depletion');
                if (dep && !charts.e_depletion && (EMS.depletion.labels || []).length) {
                    charts.e_depletion = new Chart(dep, {
                        type:'bar',
                        data:{ labels: EMS.depletion.labels, datasets:[{ label:'Days until depletion', data: EMS.depletion.data, backgroundColor: EMS.depletion.colors, borderRadius:3 }] },
                        options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
                            scales:{ x:{ beginAtZero:true, ticks:{precision:0}, grid:{color:'rgba(32,43,34,.06)'}, title:{display:true,text:'Days'} }, y:{ grid:{display:false} } },
                            plugins:{ legend:{ display:false } } }
                    });
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            initTab('e-desc');
            document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (t) {
                t.addEventListener('shown.bs.tab', function (e) {
                    initTab(e.target.getAttribute('data-tab'));
                    Object.values(charts).forEach(function (c) { if (c && c.resize) c.resize(); });
                });
            });
        });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
