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
$canViewDocuments = $canReviewOriginals || $canDecideApplications;
if (!$canReviewOriginals && !$canManageInspections && !$canDecideApplications) {
    http_response_code(403);
    die('You are not authorized to review permit applications.');
}
$navigationPermissions = user_active_permissions($pdo, $userId);
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Permit Reviewer';
$todayLabel = date('l, F j, Y');
$queueCatalog = permit_review_queue_catalog();
$requestedQueue = trim((string) ($_GET['queue'] ?? 'all'));
$selectedQueue = isset($queueCatalog[$requestedQueue]) ? $requestedQueue : 'all';
$queueCounts = [];

try {
    if ($canDecideApplications) {
        $queueResult = permit_list_applications_for_review($pdo, $userId, $selectedQueue);
        $applications = $queueResult['applications'];
        $queueCounts = $queueResult['counts'];
    } else {
        $applications = $canReviewOriginals
            ? permit_list_applications_for_rps($pdo, $userId)
            : permit_list_applications_for_inspection($pdo, $userId);
    }
} catch (PDOException $e) {
    error_log('[CERTREEFY RPS PERMIT LIST ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load permit applications at this time. Please try again later.');
}
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
    <link rel="stylesheet" href="../../css/dashboard.css">
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
                        <span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span>
                        <form method="post" action="../auth/logout.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right"></i> Logout</button>
                        </form>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/></svg>
            </section>

            <?php if ($canDecideApplications): ?>
                <section class="docket-panel mb-3" aria-labelledby="work-queue-filter-heading">
                    <div class="section-heading"><h2 id="work-queue-filter-heading">RPS Work Queue</h2><span class="section-note">Server-derived workflow views</span></div>
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-lg-6"><label class="form-label" for="queue">Queue view</label><select class="form-select" id="queue" name="queue"><?php foreach ($queueCatalog as $queueKey => $queueLabel): ?><option value="<?php echo e($queueKey); ?>" <?php echo $selectedQueue === $queueKey ? 'selected' : ''; ?>><?php echo e($queueLabel); ?> (<?php echo (int) ($queueCounts[$queueKey] ?? 0); ?>)</option><?php endforeach; ?></select></div>
                        <div class="col-lg-auto"><button class="btn btn-certreefy" type="submit"><i class="bi bi-funnel"></i> Apply filter</button></div>
                        <?php if ($selectedQueue !== 'all'): ?><div class="col-lg-auto"><a class="btn btn-outline-secondary" href="permit-applications.php">Clear</a></div><?php endif; ?>
                    </form>
                    <div class="d-flex flex-wrap gap-2 mt-3"><?php foreach ($queueCatalog as $queueKey => $queueLabel): ?><?php if ($queueKey === 'all') { continue; } ?><a class="badge text-decoration-none <?php echo $selectedQueue === $queueKey ? 'text-bg-primary' : 'text-bg-light border text-dark'; ?>" href="?queue=<?php echo e($queueKey); ?>"><?php echo e($queueLabel); ?>: <?php echo (int) ($queueCounts[$queueKey] ?? 0); ?></a><?php endforeach; ?></div>
                </section>
            <?php endif; ?>

            <section class="docket-panel" aria-labelledby="permit-registry-heading">
                <div class="section-heading">
                    <h2 id="permit-registry-heading"><?php echo $canDecideApplications ? e($queueCatalog[$selectedQueue]) : 'Submitted Applications'; ?></h2>
                    <span class="section-note"><?php echo count($applications); ?> record<?php echo count($applications) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if ($applications === []): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-secondary"></i>
                        <h3 class="h5 mt-3">No submitted applications</h3>
                        <p class="text-secondary mb-0">Community drafts are not visible in the RPS registry.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Transaction</th><th>Applicant</th><th>Location</th><?php if ($canViewDocuments): ?><th>Documents</th><th>Document status</th><?php endif; ?><th>Inspection</th><?php if ($canDecideApplications): ?><th>Decision</th><th>Queue</th><?php endif; ?><th class="text-end">Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($applications as $application): ?>
                                    <?php $location = array_filter([$application['property_address'], $application['municipality'], $application['province']]); ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?php echo e((string) $application['transaction_id']); ?></div><small class="text-secondary"><?php echo e(date('M j, Y', strtotime((string) $application['submitted_at']))); ?></small></td>
                                        <td><?php echo e((string) $application['applicant_name']); ?></td>
                                        <td class="text-break"><?php echo e(implode(', ', array_map('strval', $location))); ?></td>
                                        <?php if ($canViewDocuments): ?><td>
                                            <div><?php echo e((string) $application['current_document_count']); ?> current</div>
                                            <?php if ((int) $application['pending_document_count'] > 0): ?><small class="text-warning-emphasis"><?php echo e((string) $application['pending_document_count']); ?> pending review</small><?php endif; ?>
                                        </td>
                                        <td><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['document_status'])); ?></span></td>
                                        <?php endif; ?>
                                        <td><span class="badge <?php echo e(permit_inspection_status_badge((string) $application['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $application['inspection_status'])); ?></span><div class="small text-secondary mt-1"><?php echo (int) $application['inspection_event_count']; ?> event<?php echo (int) $application['inspection_event_count'] === 1 ? '' : 's'; ?></div></td>
                                        <?php if ($canDecideApplications): ?><td><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['decision_status'])); ?></span></td><td><span class="badge <?php echo (string) $application['queue_key'] === 'ready_for_decision' ? 'text-bg-success' : ((string) $application['queue_key'] === 'declined' ? 'text-bg-danger' : 'text-bg-warning'); ?>"><?php echo e($queueCatalog[(string) $application['queue_key']] ?? permit_status_label((string) $application['queue_key'])); ?></span></td><?php endif; ?>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="permit-application.php?id=<?php echo e((string) $application['id']); ?>"><i class="bi bi-eye"></i> Review</a></td>
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
