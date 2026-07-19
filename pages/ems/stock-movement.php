<?php
/** EMS Stock Movement: filterable read-only view of the seedling stock ledger. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

$filters = [
    'inventory_id' => trim((string) ($_GET['inventory_id'] ?? '')),
    'movement_type' => trim((string) ($_GET['movement_type'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

try {
    $species = seedling_inventory_list($pdo);
    $movements = seedling_stock_movement_ledger($pdo, $filters);
} catch (PDOException $e) {
    error_log('[CERTREEFY STOCK MOVEMENT LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load stock movement at this time. Please try again later.');
}

$totalIn = 0;
$totalOut = 0;
foreach ($movements as $m) {
    $delta = (int) $m['quantity_delta'];
    if ($delta >= 0) {
        $totalIn += $delta;
    } else {
        $totalOut += -$delta;
    }
}
$movementTypes = seedling_movement_types();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Stock Movement</title>

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
        <?php render_certreefy_navigation($currentRole, 'stock_movement'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Stock Movement</h1>
                        <p class="text-secondary meta-copy mb-0">Complete ledger of seedling stock: incoming, releases, and corrections.</p>
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

            <section class="row g-3 mb-4" aria-label="Movement summary">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-list-ul"></i></span><span class="ledger-tag">Entries</span></div>
                        <div class="ledger-value tabular"><?php echo count($movements); ?></div>
                        <div class="ledger-caption">Movements shown</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-arrow-down-circle"></i></span><span class="ledger-tag">In</span></div>
                        <div class="ledger-value tabular">+<?php echo (int) $totalIn; ?></div>
                        <div class="ledger-caption">Seedlings added</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-arrow-up-circle"></i></span><span class="ledger-tag">Out</span></div>
                        <div class="ledger-value tabular">-<?php echo (int) $totalOut; ?></div>
                        <div class="ledger-caption">Seedlings released</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-4">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-arrow-left-right"></i></span><span class="ledger-tag">Net</span></div>
                        <div class="ledger-value tabular"><?php echo (int) ($totalIn - $totalOut); ?></div>
                        <div class="ledger-caption">Net change (shown)</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-label="Movement filters">
                <form method="get" action="stock-movement.php" class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-3">
                        <label for="speciesFilter" class="form-label">Species</label>
                        <select class="form-select" id="speciesFilter" name="inventory_id">
                            <option value="">All species</option>
                            <?php foreach ($species as $sp): ?>
                                <option value="<?php echo (int) $sp['id']; ?>" <?php echo $filters['inventory_id'] === (string) $sp['id'] ? 'selected' : ''; ?>><?php echo e((string) $sp['common_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="typeFilter" class="form-label">Type</label>
                        <select class="form-select" id="typeFilter" name="movement_type">
                            <option value="">All types</option>
                            <?php foreach ($movementTypes as $type): ?>
                                <option value="<?php echo e($type); ?>" <?php echo $filters['movement_type'] === $type ? 'selected' : ''; ?>><?php echo e(seedling_movement_type_label($type)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="dateFrom" class="form-label">From</label>
                        <input type="date" class="form-control" id="dateFrom" name="date_from" value="<?php echo e($filters['date_from']); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="dateTo" class="form-label">To</label>
                        <input type="date" class="form-control" id="dateTo" name="date_to" value="<?php echo e($filters['date_to']); ?>">
                    </div>
                    <div class="col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="stock-movement.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="ledgerHeading">
                <div class="section-heading">
                    <h2 id="ledgerHeading">Movement Ledger</h2>
                    <span class="section-note tabular"><?php echo count($movements); ?> entr<?php echo count($movements) === 1 ? 'y' : 'ies'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search species, reference, or reason">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Species</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-end">Change</th>
                                <th scope="col" class="text-end">Balance</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($movements === []): ?>
                                <tr><td colspan="8" class="text-center text-secondary py-5">No stock movements match these filters.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($movements as $m): ?>
                                <?php $delta = (int) $m['quantity_delta']; ?>
                                <tr>
                                    <td class="small text-secondary text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string) $m['created_at']))); ?></td>
                                    <td class="fw-semibold text-break"><?php echo e((string) $m['common_name']); ?></td>
                                    <td>
                                        <?php
                                        $badge = match ((string) $m['movement_type']) {
                                            'incoming' => 'text-bg-success',
                                            'released' => 'text-bg-danger',
                                            'adjustment' => 'text-bg-warning',
                                            default => 'text-bg-secondary',
                                        };
                                        ?>
                                        <span class="badge <?php echo e($badge); ?>"><?php echo e(seedling_movement_type_label((string) $m['movement_type'])); ?></span>
                                    </td>
                                    <td class="text-end tabular fw-semibold <?php echo $delta >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($delta >= 0 ? '+' : '') . $delta; ?></td>
                                    <td class="text-end tabular"><?php echo (int) $m['quantity_after']; ?></td>
                                    <td class="small"><?php echo $m['request_reference'] !== null ? e((string) $m['request_reference']) : '<span class="text-secondary">&mdash;</span>'; ?></td>
                                    <td class="small text-secondary"><?php echo $m['reason'] !== null && $m['reason'] !== '' ? e((string) $m['reason']) : '<span class="text-secondary">&mdash;</span>'; ?></td>
                                    <td class="small text-secondary"><?php echo e((string) $m['recorded_by_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($movements) >= 500): ?>
                    <p class="small text-secondary mt-3 mb-0">Showing the most recent 500 — narrow filters to see older entries.</p>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
