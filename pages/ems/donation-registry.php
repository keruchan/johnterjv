<?php
/** EMS registry: locate approved permit applications with a seedling donation requirement. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit_donation_receipts.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';
$filters = [
    'transaction' => permit_donation_scalar_value($_GET['transaction'] ?? ''),
    'applicant' => permit_donation_scalar_value($_GET['applicant'] ?? ''),
    'donation_status' => permit_donation_scalar_value($_GET['donation_status'] ?? ''),
];
$requirements = [];
$loadError = null;

try {
    $requirements = permit_list_donation_requirements_for_ems($pdo, $userId, $filters);
} catch (PDOException $e) {
    error_log('[CERTREEFY EMS DONATION REGISTRY ERROR] ' . $e->getMessage());
    http_response_code(500);
    $loadError = 'Unable to load seedling donation requirements at this time.';
}

$hasFilters = implode('', $filters) !== '';
// Only statuses EMS can still act on ever appear here — once a requirement
// is EMS/RPS-verified or waived it drops out of this registry entirely.
$donationStatuses = ['required', 'pending', 'partially_received', 'flagged'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Donation Registry</title>
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
        <?php render_certreefy_navigation($currentRole, 'permit_donations'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div><div class="eyebrow">Enforcement &amp; Monitoring Section</div><h1 class="page-title">Donation Registry</h1><p class="text-secondary meta-copy mb-0">Locate approved transactions and open a donation receipt.</p></div>
                    <div class="d-flex align-items-center gap-2"><?php render_certreefy_notification_bell('header'); ?><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>"><button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right me-1"></i> Logout</button></form></div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <div class="alert alert-light border" role="note"><i class="bi bi-info-circle me-1"></i>Approved applications appear here automatically. Search by transaction ID or applicant name.</div>
            <?php if ($loadError !== null): ?><div class="alert alert-danger" role="alert"><?php echo e($loadError); ?></div><?php endif; ?>

            <section class="docket-panel" aria-labelledby="donation-registry-heading">
                <div class="section-heading"><h2 id="donation-registry-heading">Donation Registry</h2><span class="section-note"><?php echo count($requirements); ?> record<?php echo count($requirements) === 1 ? '' : 's'; ?></span></div>
                <form method="get" class="row g-3 align-items-end mb-4">
                    <div class="col-lg-5"><label class="form-label" for="transaction">Transaction ID <span class="text-secondary">(primary reference)</span></label><input class="form-control" id="transaction" name="transaction" maxlength="50" autofocus value="<?php echo e($filters['transaction']); ?>" placeholder="TCP-YYYY-######"></div>
                    <div class="col-lg-5"><label class="form-label" for="applicant">Applicant name</label><input class="form-control" id="applicant" name="applicant" maxlength="150" value="<?php echo e($filters['applicant']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label" for="donation_status">Donation status</label><select class="form-select" id="donation_status" name="donation_status"><option value="">All statuses</option><?php foreach ($donationStatuses as $status): ?><option value="<?php echo e($status); ?>" <?php echo $filters['donation_status'] === $status ? 'selected' : ''; ?>><?php echo e(permit_status_label($status)); ?></option><?php endforeach; ?></select></div>
                    <div class="col-auto"><button class="btn btn-certreefy" type="submit"><i class="bi bi-search"></i> Search transactions</button></div>
                    <?php if ($hasFilters): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="donation-registry.php">Clear</a></div><?php endif; ?>
                </form>

                <?php if ($requirements === []): ?>
                    <div class="text-center py-5"><i class="bi bi-inbox fs-1 text-secondary"></i><h3 class="h5 mt-3">No matching donation requirements</h3><p class="text-secondary mb-0">Only approved applications with a donation requirement appear here.</p></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Transaction / application</th><th>Applicant / property</th><th>Required</th><th>Received</th><th>Remaining</th><th>Status</th><th>Policy</th><th></th></tr></thead><tbody>
                        <?php foreach ($requirements as $requirement): ?><tr><td><div class="fw-semibold text-break"><?php echo e((string) $requirement['transaction_id']); ?></div><div class="small text-secondary">Application #<?php echo (int) $requirement['application_id']; ?><br>Created <?php echo e(date('M j, Y g:i A', strtotime((string) $requirement['imposed_at']))); ?></div></td><td><div class="fw-semibold"><?php echo e((string) $requirement['applicant_name']); ?></div><div class="small text-secondary"><?php echo e(permit_status_label((string) $requirement['property_classification'])); ?></div></td><td class="fs-5 fw-semibold"><?php echo (int) $requirement['required_seedling_count']; ?></td><td><?php echo (int) $requirement['received_seedling_count']; ?></td><td><?php echo (int) $requirement['remaining_seedling_count']; ?></td><td><span class="badge <?php echo e(permit_donation_receipt_status_badge((string) $requirement['current_status'])); ?>"><?php echo e(permit_status_label((string) $requirement['current_status'])); ?></span></td><td class="small text-break"><div><strong><?php echo e((string) $requirement['policy_code']); ?></strong></div><div class="text-secondary">Version <?php echo e((string) $requirement['policy_version']); ?></div></td><td><a class="btn btn-sm btn-outline-primary" href="donation-receipt.php?application_id=<?php echo (int) $requirement['application_id']; ?>">Open</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
