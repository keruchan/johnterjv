<?php
/** CENRO illegal-logging report work queue: filterable registry for enforcement. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Officer';

if (illegal_logging_processor_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to view illegal-logging reports.');
}

$statusLabels = illegal_logging_report_statuses();
$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

try {
    $reports = illegal_logging_reports_for_processors($pdo, $filters);
} catch (PDOException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING QUEUE ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load illegal-logging reports at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Illegal Logging Reports</title>

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
        <?php render_certreefy_navigation($currentRole, 'logging_reports'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Enforcement</div>
                        <h1 class="page-title">Illegal Logging Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Review, dispatch field verification, and resolve Community-reported incidents.</p>
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

            <section class="docket-panel mb-4" aria-label="Report filters">
                <form method="get" action="illegal-logging-reports.php" class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label for="reportSearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="reportSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Reference, reporter, or location">
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
                        <a class="btn btn-outline-secondary" href="illegal-logging-reports.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="reportQueueHeading">
                <div class="section-heading">
                    <h2 id="reportQueueHeading">Report Queue</h2>
                    <span class="section-note tabular"><?php echo count($reports); ?> report<?php echo count($reports) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Reporter</th>
                                <th scope="col">Location</th>
                                <th scope="col">Evidence</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($reports === []): ?>
                                <tr><td colspan="7" class="text-center text-secondary py-5">No illegal-logging reports found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $report['report_reference']); ?></td>
                                    <td><?php echo e((string) $report['reporter_name']); ?></td>
                                    <td class="text-break"><?php echo e((string) $report['incident_location']); ?></td>
                                    <td class="tabular"><?php echo (int) $report['photo_count']; ?> photo(s)</td>
                                    <td><span class="badge <?php echo e(illegal_logging_report_status_badge((string) $report['current_status'])); ?>"><?php echo e(illegal_logging_report_status_label((string) $report['current_status'])); ?></span></td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y', strtotime((string) $report['submitted_at']))); ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="illegal-logging-report-detail.php?id=<?php echo (int) $report['id']; ?>"><i class="bi bi-eye"></i> Open</a></td>
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
