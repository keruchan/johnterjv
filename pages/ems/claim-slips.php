<?php
/** EMS Claim Slips: registry of ready-for-pickup and claimed seedling requests. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

try {
    $requests = seedling_claim_slip_requests($pdo, $filters);
    $all = seedling_claim_slip_requests($pdo, []);
} catch (PDOException $e) {
    error_log('[CERTREEFY CLAIM SLIPS LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load claim slips at this time. Please try again later.');
}

$readyCount = 0;
$claimedCount = 0;
foreach ($all as $r) {
    if ((string) $r['current_status'] === 'ready_for_pickup') {
        $readyCount++;
    } elseif ((string) $r['current_status'] === 'claimed') {
        $claimedCount++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Claim Slips</title>

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
        <?php render_certreefy_navigation($currentRole, 'claim_slips'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Claim Slips</h1>
                        <p class="text-secondary meta-copy mb-0">Pickup records for approved seedling requests. Print a slip for the claimant to sign.</p>
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

            <section class="row g-3 mb-4" aria-label="Claim summary">
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span><span class="ledger-tag">Awaiting</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $readyCount; ?></div>
                        <div class="ledger-caption">Ready for pickup</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-check2-circle"></i></span><span class="ledger-tag">Claimed</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $claimedCount; ?></div>
                        <div class="ledger-caption">Already picked up</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card accent-amber stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-clipboard-check"></i></span><span class="ledger-tag">Total</span></div>
                        <div class="ledger-value tabular"><?php echo (int) ($readyCount + $claimedCount); ?></div>
                        <div class="ledger-caption">Claim records</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-label="Claim filters">
                <form method="get" action="claim-slips.php" class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label for="claimSearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="claimSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Reference, requester, or claimant">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">Ready &amp; claimed</option>
                            <option value="ready_for_pickup" <?php echo $filters['status'] === 'ready_for_pickup' ? 'selected' : ''; ?>>Ready for pickup</option>
                            <option value="claimed" <?php echo $filters['status'] === 'claimed' ? 'selected' : ''; ?>>Claimed</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="claim-slips.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="claimRegistryHeading">
                <div class="section-heading">
                    <h2 id="claimRegistryHeading">Claim Registry</h2>
                    <span class="section-note tabular"><?php echo count($requests); ?> record<?php echo count($requests) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Requester</th>
                                <th scope="col" class="text-end">Seedlings</th>
                                <th scope="col">Status</th>
                                <th scope="col">Ready since</th>
                                <th scope="col">Claimed by</th>
                                <th scope="col" class="text-end">Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($requests === []): ?>
                                <tr><td colspan="7" class="text-center text-secondary py-5">No claim records match these filters.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) ($r['request_reference'] ?? ('#' . $r['id']))); ?></td>
                                    <td><?php echo e((string) $r['requester_name']); ?></td>
                                    <td class="text-end tabular"><?php echo (int) $r['total_approved']; ?></td>
                                    <td><span class="badge <?php echo e(seedling_request_status_badge((string) $r['current_status'])); ?>"><?php echo e(seedling_request_status_label((string) $r['current_status'])); ?></span></td>
                                    <td class="small text-secondary"><?php echo $r['fulfilled_at'] !== null ? e(date('M j, Y', strtotime((string) $r['fulfilled_at']))) : '&mdash;'; ?></td>
                                    <td class="small text-secondary">
                                        <?php if ((string) $r['current_status'] === 'claimed'): ?>
                                            <?php echo e((string) ($r['claimed_by_name'] ?? '&mdash;')); ?>
                                            <?php if ($r['claimed_on'] !== null): ?><div class="text-secondary"><?php echo e(date('M j, Y', strtotime((string) $r['claimed_on']))); ?></div><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-secondary">Not yet claimed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="claim-slip.php?request=<?php echo (int) $r['id']; ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-printer"></i> Print
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
