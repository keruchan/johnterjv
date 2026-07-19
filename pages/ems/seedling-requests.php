<?php
/** EMS seedling-request work queue: filterable registry of Community requests. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';
$statusLabels = seedling_request_statuses();

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

try {
    $requests = seedling_requests_for_ems($pdo, $filters);
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING REQUEST QUEUE ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load seedling requests at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Seedling Requests</title>

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
        <?php render_certreefy_navigation($currentRole, 'seedling_requests'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Seedling Requests</h1>
                        <p class="text-secondary meta-copy mb-0">Review, approve, fulfil, and record pickup for Community seedling requests.</p>
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

            <section class="docket-panel mb-4" aria-label="Request filters">
                <form method="get" action="seedling-requests.php" class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label for="requestSearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="requestSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Reference or requester name">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="seedling-requests.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="requestQueueHeading">
                <div class="section-heading">
                    <h2 id="requestQueueHeading">Request Queue</h2>
                    <span class="section-note tabular"><?php echo count($requests); ?> request<?php echo count($requests) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Requester</th>
                                <th scope="col">Requested / Approved</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($requests === []): ?>
                                <tr><td colspan="6" class="text-center text-secondary py-5">No seedling requests found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $request['request_reference']); ?></td>
                                    <td>
                                        <div><?php echo e((string) $request['requester_name']); ?></div>
                                        <?php if (!empty($request['requester_contact'])): ?><div class="small text-secondary"><?php echo e((string) $request['requester_contact']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="tabular"><?php echo (int) $request['total_requested']; ?> / <?php echo (int) $request['total_approved']; ?></td>
                                    <td><span class="badge <?php echo e(seedling_request_status_badge((string) $request['current_status'])); ?>"><?php echo e(seedling_request_status_label((string) $request['current_status'])); ?></span></td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y', strtotime((string) $request['submitted_at']))); ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="seedling-request-detail.php?id=<?php echo (int) $request['id']; ?>"><i class="bi bi-eye"></i> Open</a></td>
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
