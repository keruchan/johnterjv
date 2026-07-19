<?php
/**
 * CENRO Analytics: deep cross-domain analytics (RPS/Superadmin).
 *
 * Three layers, matching the classic analytics maturity model:
 *   - Descriptive  ("what happened")  -> trend lines + distribution charts
 *   - Predictive   ("what's next")    -> least-squares 3-month forecasts
 *   - Prescriptive ("what to do")     -> rule-based recommendations
 * All figures are read-only; forecasting is transparent linear regression.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Officer';

if (analytics_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to view analytics.');
}

$range = analytics_normalize_range($_GET);
$rangeQuery = array_filter(['date_from' => $range['from'], 'date_to' => $range['to']]);

try {
    $overview = analytics_overview($pdo, $range);
    $bundle = analytics_forecast_bundle($pdo, 12, 3);
    $recommendations = analytics_cenro_recommendations($pdo, $overview, $bundle);
    $geographic = analytics_geographic_data($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY ANALYTICS LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load analytics at this time. Please try again later.');
}

$permits = $overview['permits'];
$il = $overview['illegal_logging'];
$seedlings = $overview['seedlings'];
$zones = $overview['zones'];

$permitLabel = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));

// ---- Distribution datasets for the doughnut charts ----
$palette = ['#2d6a4f', '#1f6a68', '#a8721f', '#a5402a', '#74a57f', '#1b4332', '#7c877e', '#3a7d5d'];

$permitDist = ['labels' => [], 'data' => [], 'colors' => []];
$i = 0;
foreach ($overview['permit_status_breakdown'] as $status => $count) {
    if ((int) $count === 0) { continue; }
    $permitDist['labels'][] = $permitLabel($status);
    $permitDist['data'][] = (int) $count;
    $permitDist['colors'][] = $palette[$i++ % count($palette)];
}

$ilStatusColors = ['submitted' => '#7c877e', 'under_review' => '#1f6a68', 'field_verification' => '#a8721f', 'resolved' => '#2d6a4f'];
$ilStatusDist = ['labels' => [], 'data' => [], 'colors' => []];
foreach ($il['status_breakdown'] as $status => $count) {
    $ilStatusDist['labels'][] = illegal_logging_report_status_label($status);
    $ilStatusDist['data'][] = (int) $count;
    $ilStatusDist['colors'][] = $ilStatusColors[$status] ?? '#7c877e';
}

$ilOutcomeColors = ['confirmed' => '#a5402a', 'unfounded' => '#2d6a4f', 'referred' => '#1f6a68', 'invalid' => '#7c877e'];
$ilOutcomeDist = ['labels' => [], 'data' => [], 'colors' => []];
foreach ($il['outcome_breakdown'] as $outcome => $count) {
    $ilOutcomeDist['labels'][] = illegal_logging_resolution_outcome_label($outcome);
    $ilOutcomeDist['data'][] = (int) $count;
    $ilOutcomeDist['colors'][] = $ilOutcomeColors[$outcome] ?? '#7c877e';
}

$seedlingDist = ['labels' => [], 'data' => [], 'colors' => []];
$i = 0;
foreach ($seedlings['status_breakdown'] as $status => $count) {
    $seedlingDist['labels'][] = seedling_request_status_label($status);
    $seedlingDist['data'][] = (int) $count;
    $seedlingDist['colors'][] = $palette[$i++ % count($palette)];
}

$zoneDist = [
    'labels' => ['Allowed', 'Restricted', 'Protected'],
    'data' => [(int) $zones['allowed_count'], (int) $zones['restricted_count'], (int) $zones['protected_count']],
    'colors' => ['#2d6a4f', '#a8721f', '#a5402a'],
];

// ---- Chart payload handed to the client ----
$chartData = [
    'labels' => $bundle['labels'],
    'histLen' => $bundle['history_length'],
    'series' => [
        'permit_submissions' => $bundle['permit_submissions'],
        'permit_issued' => $bundle['permit_issued'],
        'il_received' => $bundle['il_received'],
        'il_resolved' => $bundle['il_resolved'],
        'seedling_requests' => $bundle['seedling_requests'],
        'seedlings_distributed' => $bundle['seedlings_distributed'],
    ],
    'dist' => [
        'permit' => $permitDist,
        'il_status' => $ilStatusDist,
        'il_outcome' => $ilOutcomeDist,
        'seedling' => $seedlingDist,
        'zone' => $zoneDist,
    ],
];

// ---- Forecast summary rows for the predictive table ----
// invert_good = a rising trend is a GOOD thing for this metric.
$forecastRows = [
    ['label' => 'Permit applications', 'series' => 'permit_submissions', 'invert_good' => false],
    ['label' => 'Permits issued', 'series' => 'permit_issued', 'invert_good' => true],
    ['label' => 'Illegal-logging reports', 'series' => 'il_received', 'invert_good' => false],
    ['label' => 'Reports resolved', 'series' => 'il_resolved', 'invert_good' => true],
    ['label' => 'Seedling requests', 'series' => 'seedling_requests', 'invert_good' => true],
    ['label' => 'Seedlings distributed', 'series' => 'seedlings_distributed', 'invert_good' => true],
];

/** Renders a small trend chip (rising/falling/flat) with an arrow. */
function analytics_trend_chip(array $meta): string
{
    $trend = (string) $meta['trend'];
    $icon = $trend === 'rising' ? 'bi-arrow-up-right' : ($trend === 'falling' ? 'bi-arrow-down-right' : 'bi-arrow-right');
    $word = ucfirst($trend);

    return '<span class="forecast-chip ' . e($trend) . '"><i class="bi ' . $icon . '"></i>' . e($word) . '</span>';
}

/** Latest historical value of a padded history array (last non-null). */
function analytics_latest_value(array $history): int
{
    for ($k = count($history) - 1; $k >= 0; $k--) {
        if ($history[$k] !== null) {
            return (int) $history[$k];
        }
    }

    return 0;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Analytics</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'analytics'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Operations</div>
                        <h1 class="page-title">Analytics</h1>
                        <p class="text-secondary meta-copy mb-0">Descriptive, predictive, and prescriptive insight across permits, illegal logging, seedlings, and area zones.</p>
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

            <!-- Headline metrics (respect the date filter) -->
            <section class="row g-3 mb-4" aria-label="Headline metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-file-earmark-check"></i></span><span class="ledger-tag">Permits</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $permits['permits_issued']; ?></div>
                        <div class="ledger-caption">Permits issued (<?php echo (int) $permits['total_applications']; ?> submitted)</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-shield-exclamation"></i></span><span class="ledger-tag">Reports</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $il['total']; ?></div>
                        <div class="ledger-caption"><?php echo (int) $il['resolved']; ?> resolved</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-flower1"></i></span><span class="ledger-tag">Seedlings</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $seedlings['seedlings_distributed']; ?></div>
                        <div class="ledger-caption"><?php echo (int) $seedlings['total_requests']; ?> request(s)</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-4">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-geo-alt"></i></span><span class="ledger-tag">Zones</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $zones['total']; ?></div>
                        <div class="ledger-caption"><?php echo (int) $zones['protected_count']; ?> protected</div>
                    </div>
                </div>
            </section>

            <!-- Filter + export (applies to the descriptive summary figures) -->
            <section class="docket-panel mb-4" aria-label="Report period">
                <form method="get" action="analytics.php" class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-3">
                        <label for="dateFrom" class="form-label">From</label>
                        <input type="date" class="form-control" id="dateFrom" name="date_from" value="<?php echo e($range['from']); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="dateTo" class="form-label">To</label>
                        <input type="date" class="form-control" id="dateTo" name="date_to" value="<?php echo e($range['to']); ?>">
                    </div>
                    <div class="col-lg-6 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy"><i class="bi bi-funnel me-1"></i>Apply period</button>
                        <a class="btn btn-outline-secondary" href="analytics.php"><i class="bi bi-x-lg me-1"></i>Clear</a>
                        <a class="btn btn-outline-success ms-auto" href="analytics-export.php<?php echo $rangeQuery === [] ? '' : ('?' . e(http_build_query($rangeQuery))); ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
                    </div>
                </form>
                <p class="small text-secondary mt-2 mb-0">
                    Headline figures and distribution charts use
                    <?php if ($range['from'] === '' && $range['to'] === ''): ?>all-time data<?php else: ?>the selected period<?php endif; ?>
                    (drafts excluded). Trend/forecast charts always use the trailing 12 months.
                </p>
            </section>

            <!-- Analytics layers -->
            <ul class="nav analytics-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-descriptive" data-bs-toggle="tab" data-bs-target="#pane-descriptive" data-tab="descriptive" type="button" role="tab"><i class="bi bi-bar-chart-line me-1"></i>Descriptive</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-predictive" data-bs-toggle="tab" data-bs-target="#pane-predictive" data-tab="predictive" type="button" role="tab"><i class="bi bi-graph-up-arrow me-1"></i>Predictive</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-prescriptive" data-bs-toggle="tab" data-bs-target="#pane-prescriptive" data-tab="prescriptive" type="button" role="tab"><i class="bi bi-lightbulb me-1"></i>Prescriptive<span class="badge text-bg-secondary ms-1"><?php echo count($recommendations); ?></span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-geographic" data-bs-toggle="tab" data-bs-target="#pane-geographic" data-tab="geographic" type="button" role="tab"><i class="bi bi-geo-alt me-1"></i>Geographic</button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- ============ DESCRIPTIVE ============ -->
                <div class="tab-pane fade show active" id="pane-descriptive" role="tabpanel" aria-labelledby="tab-descriptive">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>What's been happening.</strong> Monthly activity over 12 months and how current work is distributed.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Tree Cutting Permits &mdash; monthly</h2></div>
                                <div class="chart-frame"><canvas id="d_permit"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Illegal Logging &mdash; monthly</h2></div>
                                <div class="chart-frame"><canvas id="d_il"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Seedling Program &mdash; monthly</h2></div>
                                <div class="chart-frame"><canvas id="d_seed"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Area Zone Classification</h2></div>
                                <div class="chart-frame"><canvas id="d_zone"></canvas></div>
                            </section>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Permit status mix</h2></div>
                                <div class="chart-frame chart-frame-sm"><canvas id="d_permit_dist"></canvas></div>
                            </section>
                        </div>
                        <div class="col-md-4">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Report status mix</h2></div>
                                <div class="chart-frame chart-frame-sm"><canvas id="d_il_dist"></canvas></div>
                            </section>
                        </div>
                        <div class="col-md-4">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Seedling request mix</h2></div>
                                <div class="chart-frame chart-frame-sm"><canvas id="d_seed_dist"></canvas></div>
                            </section>
                        </div>
                    </div>
                </div>

                <!-- ============ PREDICTIVE ============ -->
                <div class="tab-pane fade" id="pane-predictive" role="tabpanel" aria-labelledby="tab-predictive">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>What's likely next.</strong> Solid lines are actuals; dashed lines project 3 months ahead from the trailing 12 months. Guidance, not certainty.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Permit applications forecast</h2></div>
                                <div class="chart-frame"><canvas id="p_permit"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Illegal-logging reports forecast</h2></div>
                                <div class="chart-frame"><canvas id="p_il"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Seedlings distributed forecast</h2></div>
                                <div class="chart-frame"><canvas id="p_seed"></canvas></div>
                            </section>
                        </div>
                        <div class="col-lg-6">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Forecast summary (next month)</h2></div>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead><tr><th scope="col">Indicator</th><th scope="col" class="text-end">Latest</th><th scope="col" class="text-end">Projected</th><th scope="col">Trend</th><th scope="col" class="text-end">Fit (R&sup2;)</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($forecastRows as $row): ?>
                                                <?php
                                                $s = $bundle[$row['series']];
                                                $meta = $s['meta'];
                                                $latest = analytics_latest_value($s['history']);
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo e($row['label']); ?></td>
                                                    <td class="text-end tabular"><?php echo (int) $latest; ?></td>
                                                    <td class="text-end tabular fw-semibold"><?php echo (int) $meta['next']; ?></td>
                                                    <td><span class="<?php echo $row['invert_good'] ? 'metric-good' : ''; ?>"><?php echo analytics_trend_chip($meta); ?></span></td>
                                                    <td class="text-end tabular small text-secondary"><?php echo e(number_format((float) $meta['r2'], 2)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="small text-secondary mt-2 mb-0">R&sup2; near 1.00 = steady trend, more reliable; near 0 = irregular activity.</p>
                            </section>
                        </div>
                    </div>
                </div>

                <!-- ============ PRESCRIPTIVE ============ -->
                <div class="tab-pane fade" id="pane-prescriptive" role="tabpanel" aria-labelledby="tab-prescriptive">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>What to do about it.</strong> Recommended actions from current workload and the forecasts above, most urgent first.</p>
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
                                <p class="small text-secondary mt-3 mb-0">Generated from the Descriptive and Predictive tabs — advisory only, not a replacement for CENRO judgement.</p>
                            </section>
                        </div>
                    </div>
                </div>

                <!-- ============ GEOGRAPHIC ============ -->
                <div class="tab-pane fade" id="pane-geographic" role="tabpanel" aria-labelledby="tab-geographic">
                    <p class="small text-secondary mb-3"><i class="bi bi-info-circle me-1"></i><strong>Where it's happening.</strong> Cutting density by tree count, illegal-logging sites, and managed zone boundaries from Area Management.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-9">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Cutting activity &amp; incident map</h2><span class="section-note tabular"><?php echo (int) $geographic['permit_located_count']; ?> permit site(s) &middot; <?php echo (int) $geographic['il_located_count']; ?> incident(s)</span></div>
                                <div id="geoAnalyticsMap" class="geo-map geo-map-tall" role="img" aria-label="Map of cutting activity and incident locations"></div>
                                <div class="geo-legend">
                                    <span><span class="legend-swatch" style="background:#2d6a4f"></span>Permit application site</span>
                                    <span><span class="legend-swatch" style="background:#1b4332"></span>Completed cutting</span>
                                    <span><span class="legend-swatch" style="background:#a5402a"></span>Open illegal-logging report</span>
                                    <span><span class="legend-swatch" style="background:#7c877e"></span>Resolved report</span>
                                    <span><span class="legend-swatch" style="background:#4a7c59"></span>Allowed zone</span>
                                    <span><span class="legend-swatch" style="background:#d9a441"></span>Restricted zone</span>
                                    <span><span class="legend-swatch" style="background:#b4552d"></span>Protected zone</span>
                                </div>
                            </section>
                        </div>
                        <div class="col-lg-3">
                            <section class="docket-panel h-100">
                                <div class="section-heading"><h2 class="h6 mb-0">Reading this map</h2></div>
                                <p class="small text-secondary mb-2">Hotter areas have more trees approved or cut. Click any dot for the record.</p>
                                <p class="small text-secondary mb-2">Zone boundaries come from <a href="area-management.php">Area Management</a>.</p>
                                <p class="small text-secondary mb-0">Only records with pinned coordinates appear here.</p>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script type="application/json" id="geoAnalyticsData"><?php echo json_encode($geographic, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <script src="../../js/geo-map.js"></script>
    <script>
        const CT = <?php echo json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const PALETTE = { fern:'#2d6a4f', teal:'#1f6a68', amber:'#a8721f', rust:'#a5402a', moss:'#74a57f', canopy:'#1b4332', ink:'#7c877e' };

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

        function lineTrend(id, labels, datasets) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            charts[id] = new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(32,43,34,.06)' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { position: 'bottom' }, tooltip: { boxPadding: 6 } }
                }
            });
        }

        function comboSeed(id, forecast) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            const labels = forecast ? CT.labels : CT.labels.slice(0, CT.histLen);
            const reqHist = CT.series.seedling_requests.history.slice(0, forecast ? undefined : CT.histLen);
            const distHist = CT.series.seedlings_distributed.history.slice(0, forecast ? undefined : CT.histLen);
            const ds = [
                { type:'bar', label:'Seedlings distributed', data: distHist, backgroundColor:'rgba(31,106,104,.35)', borderColor:PALETTE.teal, yAxisID:'yQty', order:2 },
                { type:'line', label:'Requests received', data: reqHist, borderColor:PALETTE.amber, backgroundColor:PALETTE.amber, tension:.3, yAxisID:'yCount', order:1, pointRadius:2 }
            ];
            if (forecast) {
                ds.push({ type:'line', label:'Distributed (forecast)', data: CT.series.seedlings_distributed.forecast, borderColor:PALETTE.teal, borderDash:[6,5], tension:.2, yAxisID:'yQty', pointRadius:0, order:0 });
            }
            charts[id] = new Chart(el, {
                data: { labels: labels, datasets: ds },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    interaction:{ mode:'index', intersect:false },
                    scales: {
                        yCount: { type:'linear', position:'left', beginAtZero:true, ticks:{precision:0}, grid:{color:'rgba(32,43,34,.06)'}, title:{display:true,text:'Requests'} },
                        yQty: { type:'linear', position:'right', beginAtZero:true, ticks:{precision:0}, grid:{display:false}, title:{display:true,text:'Seedlings'} },
                        x: { grid:{display:false} }
                    },
                    plugins: { legend:{ position:'bottom' } }
                }
            });
        }

        function doughnut(id, dist) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            const hasData = (dist.data || []).some(v => v > 0);
            if (!hasData) {
                const ctx = el.getContext('2d');
                ctx.font = '13px Inter, sans-serif'; ctx.fillStyle = '#7c877e'; ctx.textAlign = 'center';
                ctx.fillText('No data for this period', el.width / 2, el.height / 2);
                charts[id] = true;
                return;
            }
            charts[id] = new Chart(el, {
                type: 'doughnut',
                data: { labels: dist.labels, datasets: [{ data: dist.data, backgroundColor: dist.colors, borderWidth: 2, borderColor: '#fff' }] },
                options: { responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{ position:'right' } } }
            });
        }

        // History-only slice helper for descriptive line charts.
        function hist(series) { return CT.series[series].history.slice(0, CT.histLen); }
        function histLabels() { return CT.labels.slice(0, CT.histLen); }

        function initTab(tab) {
            if (initialized[tab]) return;
            initialized[tab] = true;

            if (tab === 'descriptive') {
                lineTrend('d_permit', histLabels(), [
                    { label:'Applications', data: hist('permit_submissions'), borderColor:PALETTE.fern, backgroundColor:'rgba(45,106,79,.12)', fill:true, tension:.3, pointRadius:2 },
                    { label:'Permits issued', data: hist('permit_issued'), borderColor:PALETTE.canopy, backgroundColor:PALETTE.canopy, tension:.3, pointRadius:2 }
                ]);
                lineTrend('d_il', histLabels(), [
                    { label:'Reports received', data: hist('il_received'), borderColor:PALETTE.rust, backgroundColor:'rgba(165,64,42,.12)', fill:true, tension:.3, pointRadius:2 },
                    { label:'Resolved', data: hist('il_resolved'), borderColor:PALETTE.fern, backgroundColor:PALETTE.fern, tension:.3, pointRadius:2 }
                ]);
                comboSeed('d_seed', false);
                doughnut('d_zone', CT.dist.zone);
                doughnut('d_permit_dist', CT.dist.permit);
                doughnut('d_il_dist', CT.dist.il_status);
                doughnut('d_seed_dist', CT.dist.seedling);
            }

            if (tab === 'geographic' && window.CertreefyGeo) {
                const geo = CertreefyGeo.readJson('geoAnalyticsData', {});
                CertreefyGeo.heatmap('geoAnalyticsMap', {
                    points: geo.heat_points || [],
                    markers: (geo.permit_markers || []).concat(geo.il_markers || []),
                    zones: geo.zones || []
                });
            }

            if (tab === 'predictive') {
                lineTrend('p_permit', CT.labels, [
                    { label:'Applications (actual)', data: CT.series.permit_submissions.history, borderColor:PALETTE.fern, backgroundColor:'rgba(45,106,79,.10)', fill:true, tension:.3, pointRadius:2 },
                    { label:'Applications (forecast)', data: CT.series.permit_submissions.forecast, borderColor:PALETTE.fern, borderDash:[6,5], tension:.2, pointRadius:0 }
                ]);
                lineTrend('p_il', CT.labels, [
                    { label:'Reports (actual)', data: CT.series.il_received.history, borderColor:PALETTE.rust, backgroundColor:'rgba(165,64,42,.10)', fill:true, tension:.3, pointRadius:2 },
                    { label:'Reports (forecast)', data: CT.series.il_received.forecast, borderColor:PALETTE.rust, borderDash:[6,5], tension:.2, pointRadius:0 }
                ]);
                comboSeed('p_seed', true);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            initTab('descriptive');
            document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (t) {
                t.addEventListener('shown.bs.tab', function (e) {
                    initTab(e.target.getAttribute('data-tab'));
                    Object.values(charts).forEach(function (c) { if (c && c.resize) c.resize(); });
                });
            });
        });
    </script>
</body>
</html>
