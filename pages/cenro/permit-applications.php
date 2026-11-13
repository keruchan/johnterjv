<?php
/** Authorized permit work queue for review, documents, inspection, and decision. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit_documents.php';
require_once __DIR__ . '/../../includes/permit_inspections.php';
require_once __DIR__ . '/../../includes/permit_decisions.php';
require_once __DIR__ . '/../../includes/permit_release.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

// Opportunistic, access-independent backstop to the scheduled maintenance task:
// lapse expired permits and send expiry reminders on RPS work-queue loads.
// Failures never block the queue; the scheduled task remains authoritative.
try {
    permit_run_validity_maintenance($pdo);
} catch (Throwable $e) {
    error_log('[CERTREEFY PERMIT VALIDITY SWEEP ERROR] ' . $e->getMessage());
}

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$canReviewOriginals = permit_original_verification_actor($pdo, $userId) !== null;
$canManageInspections = permit_inspection_actor($pdo, $userId) !== null;
$canDecideApplications = permit_decision_actor($pdo, $userId) !== null;
if (!$canReviewOriginals && !$canManageInspections && !$canDecideApplications) {
    http_response_code(403);
    die('You are not authorized to review permit applications.');
}
$navigationPermissions = user_active_permissions($pdo, $userId);
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Permit Reviewer';
$todayLabel = date('l, F j, Y');
$dateFrom = is_string($_GET['date_from'] ?? '') ? trim($_GET['date_from'] ?? '') : '';
$dateTo = is_string($_GET['date_to'] ?? '') ? trim($_GET['date_to'] ?? '') : '';
$dateError = '';
foreach (['From' => $dateFrom, 'To' => $dateTo] as $label => $value) {
    $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if ($value !== '' && (!$parsed || $parsed->format('Y-m-d') !== $value)) {
        $dateError = 'Enter a valid ' . $label . ' date.';
        break;
    }
}
if ($dateError === '' && $dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateError = 'From date must be on or before To date.';
}
$selectedSummary = is_string($_GET['summary'] ?? '') ? ($_GET['summary'] ?? 'all') : 'all';
if (!in_array($selectedSummary, ['all', 'review', 'inspection', 'donation', 'pending', 'approved'], true)) {
    $selectedSummary = 'all';
}

try {
    if ($canDecideApplications) {
        $queueResult = permit_list_applications_for_review($pdo, $userId, 'all');
        $applications = record_visibility_filter($pdo, $queueResult['applications'], 'permit_application');
    } else {
        $applications = $canReviewOriginals
            ? permit_list_applications_for_rps($pdo, $userId)
            : permit_list_applications_for_inspection($pdo, $userId);
        $applications = record_visibility_filter($pdo, $applications, 'permit_application');
    }
} catch (PDOException $e) {
    error_log('[CERTREEFY RPS PERMIT LIST ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load permit applications at this time. Please try again later.');
}
$applications = array_values(array_filter($applications, static function (array $row) use ($dateFrom, $dateTo, $dateError): bool {
    $submittedDate = substr((string) $row['submitted_at'], 0, 10);
    return $dateError === '' && ($dateFrom === '' || $submittedDate >= $dateFrom)
        && ($dateTo === '' || $submittedDate <= $dateTo);
}));
$summaryCounts = ['all' => count($applications), 'review' => 0, 'inspection' => 0, 'donation' => 0];
foreach ($applications as $application) {
    $category = permit_summary_category($application);
    if ($category !== 'all') { $summaryCounts[$category]++; }
}
if ($selectedSummary !== 'all') {
    $applications = array_values(array_filter($applications, static fn (array $row): bool => match ($selectedSummary) {
        'approved' => $row['decision_status'] === 'approved',
        'pending' => !in_array($row['application_status'], ['draft','completed','declined','closed','released'], true) && $row['decision_status'] !== 'approved',
        default => permit_summary_category($row) === $selectedSummary,
    }));
}
$summaryCards = [
    ['key' => 'all', 'label' => 'Total applications', 'icon' => 'bi-files', 'accent' => ''],
    ['key' => 'review', 'label' => 'For review', 'icon' => 'bi-file-earmark-check', 'accent' => 'accent-teal'],
    ['key' => 'inspection', 'label' => 'For site inspection', 'icon' => 'bi-geo-alt', 'accent' => 'accent-amber'],
    ['key' => 'donation', 'label' => 'Awaiting donation', 'icon' => 'bi-tree', 'accent' => ''],
];
$summaryLabels = array_column($summaryCards, 'label', 'key') + ['pending'=>'Pending permit requests', 'approved'=>'Approved permits'];
$recordView = $GLOBALS['record_visibility_context']['permit_application']['view'] ?? 'active';
$filterQuery = array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo, 'record_view' => $recordView]);
$today = new DateTimeImmutable('today');
$presets = [
    'This week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
    'This month' => [$today->format('Y-m-01'), $today->format('Y-m-d')],
    'This year' => [$today->format('Y-01-01'), $today->format('Y-m-d')],
    'Last 12 months' => [$today->modify('first day of this month')->modify('-11 months')->format('Y-m-d'), $today->format('Y-m-d')],
    'All dates' => ['', ''],
];
$coverageLabel = $dateFrom === '' && $dateTo === '' ? 'All dates'
    : ($dateFrom !== '' ? date('M j, Y', strtotime($dateFrom)) : 'Beginning') . ' – '
        . ($dateTo !== '' ? date('M j, Y', strtotime($dateTo)) : 'Present');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Permit Applications</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=<?php echo e(certreefy_asset_version('css/dashboard.css')); ?>">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'permit_applications', $navigationPermissions); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Regulation and Permitting Section &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Permit Applications</h1>
                        <p class="meta-copy mb-0">Review submitted applications, requirements, inspections, decisions, and donation readiness.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span>
                        <form method="post" action="../auth/logout.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right"></i> Logout</button>
                        </form>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/></svg>
            </section>

            <section class="docket-panel mb-3" aria-label="Filter by submission date">
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="record_view" value="<?php echo e($recordView); ?>">
                    <input type="hidden" name="summary" value="<?php echo e($selectedSummary); ?>">
                    <div class="col-md-5"><label class="form-label" for="submittedFrom">From</label><input class="form-control" type="date" id="submittedFrom" name="date_from" value="<?php echo e($dateFrom); ?>"></div>
                    <div class="col-md-5"><label class="form-label" for="submittedTo">To</label><input class="form-control" type="date" id="submittedTo" name="date_to" value="<?php echo e($dateTo); ?>"></div>
                    <div class="col-md-2"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button></div>
                </form>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php foreach ($presets as $label => [$from, $to]): ?>
                        <?php $presetSelected = $dateError === '' && $dateFrom === $from && $dateTo === $to; ?>
                        <a class="btn btn-sm <?php echo $presetSelected ? 'btn-certreefy' : 'btn-outline-secondary'; ?>" href="?<?php echo e(http_build_query(['date_from' => $from, 'date_to' => $to, 'summary' => $selectedSummary, 'record_view' => $recordView])); ?>"<?php echo $presetSelected ? ' aria-current="true"' : ''; ?>><?php echo e($label); ?></a>
                    <?php endforeach; ?>
                    <a class="btn btn-sm btn-outline-secondary ms-auto" href="?<?php echo e(http_build_query(['record_view' => $recordView])); ?>">Reset</a>
                </div>
                <?php if ($dateError !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?php echo e($dateError); ?></div>
                <?php else: ?>
                    <p class="small text-secondary mt-3 mb-0">Submission dates: <?php echo e($coverageLabel); ?> &middot; <?php echo (int) $summaryCounts['all']; ?> applications</p>
                <?php endif; ?>
            </section>

            <section class="row g-3 mb-4" aria-label="Application summary">
                <?php foreach ($summaryCards as $card): ?>
                    <div class="col-sm-6 col-xl-3">
                        <a class="ledger-card summary-card <?php echo e($card['accent']); ?><?php echo $selectedSummary === $card['key'] ? ' is-selected' : ''; ?>" href="?<?php echo e(http_build_query(array_merge($filterQuery, ['summary' => $card['key']]))); ?>"<?php echo $selectedSummary === $card['key'] ? ' aria-current="page"' : ''; ?>>
                            <span class="ledger-icon" aria-hidden="true"><i class="bi <?php echo e($card['icon']); ?>"></i></span>
                            <div class="ledger-value tabular"><?php echo (int) $summaryCounts[$card['key']]; ?></div>
                            <div class="ledger-caption"><?php echo e($card['label']); ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="docket-panel" aria-labelledby="permit-registry-heading">
                <div class="section-heading">
                    <h2 id="permit-registry-heading"><?php echo $selectedSummary === 'all' ? 'Submitted Applications' : e($summaryLabels[$selectedSummary]); ?></h2>
                    <span class="section-note"><?php echo count($applications); ?> record<?php echo count($applications) === 1 ? '' : 's'; ?></span>
                </div>
                <?php render_record_visibility_tabs('permit_application'); ?>
                <?php if ($applications === []): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-secondary"></i>
                        <h3 class="h5 mt-3">No applications in this view</h3>
                        <p class="text-secondary mb-0"><?php echo $dateError !== '' ? 'Check the date range above.' : 'Try another date range or application category.'; ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search transaction, applicant, or municipality">
                            <thead><tr><th scope="col">Transaction</th><th scope="col">Applicant</th><th scope="col">Municipality</th><th scope="col" data-tt-stage-status>Stage / status</th><th scope="col" class="text-end">Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($applications as $application): ?>
                                    <?php
                                    $stageStatus = permit_stage_status($application);
                                    ?>
                                    <tr>
                                        <td><div class="fw-semibold"><a class="record-link" data-row-link href="permit-application.php?id=<?php echo e((string) $application['id']); ?>"><?php echo e((string) $application['transaction_id']); ?></a></div><small class="text-secondary"><?php echo e(date('M j, Y', strtotime((string) $application['submitted_at']))); ?></small></td>
                                        <td><?php echo e((string) $application['applicant_name']); ?></td>
                                        <td><?php echo trim((string) ($application['municipality'] ?? '')) !== '' ? e((string) $application['municipality']) : '<span class="text-secondary">Not provided</span>'; ?></td>
                                        <td data-tt-stage="<?php echo e($stageStatus['stage']); ?>" data-tt-status="<?php echo e($stageStatus['status']); ?>">
                                            <div><span class="badge rounded-pill <?php echo e($stageStatus['badge_class']); ?>"><?php echo e($stageStatus['stage']); ?></span></div>
                                            <small class="d-block <?php echo e($stageStatus['status_class']); ?>"><?php echo e($stageStatus['status']); ?></small>
                                        </td>
                                        <td class="text-end" data-record-actions><?php render_record_actions('permit_application', (int) $application['id'], (string) $application['transaction_id']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
