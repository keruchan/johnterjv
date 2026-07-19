<?php
/**
 * Community Tree Cutting Permit registry. The service query is scoped to the
 * authenticated owner; request data cannot select another applicant.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Community User';
$todayLabel = date('l, F j, Y');
$success = null;

if (!empty($_SESSION['permit_success']) && is_array($_SESSION['permit_success'])) {
    $success = $_SESSION['permit_success'];
    unset($_SESSION['permit_success']);
}

try {
    $applications = permit_list_applications_for_owner($pdo, $userId);
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT LIST ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load your permit applications at this time. Please try again later.');
}

function community_permit_status_badge(string $status): string
{
    return match ($status) {
        'draft' => 'text-bg-secondary',
        'submitted', 'under_review', 'awaiting_documents', 'awaiting_inspection',
        'awaiting_decision', 'awaiting_donation', 'awaiting_final_verification' => 'text-bg-warning',
        'approved', 'ready_for_release', 'released', 'completed' => 'text-bg-success',
        'declined', 'closed' => 'text-bg-danger',
        default => 'text-bg-light',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Tree Cutting Permit Applications</title>

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
        <?php render_certreefy_navigation($currentRole, 'tree_permit'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Community Services &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Tree Cutting Permit</h1>
                        <p class="meta-copy mb-0">Prepare an application and track its current processing status.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <?php if (is_array($success)): ?>
                <div class="alert alert-success" role="alert">
                    <div class="fw-semibold"><?php echo e((string) ($success['message'] ?? 'Permit application updated successfully.')); ?></div>
                    <?php if (!empty($success['transaction_id'])): ?>
                        <div class="mt-1">Transaction ID: <strong><?php echo e((string) $success['transaction_id']); ?></strong></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <section class="docket-panel" id="applications" aria-labelledby="applications-heading">
                <div class="section-heading d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <div>
                        <h2 id="applications-heading">My Applications</h2>
                        <span class="section-note">Only applications owned by this account are shown.</span>
                    </div>
                    <a href="permit-application.php" class="btn btn-certreefy flex-shrink-0">
                        <i class="bi bi-file-earmark-plus"></i> New application
                    </a>
                </div>

                <?php if ($applications === []): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-file-earmark-text fs-1 text-secondary" aria-hidden="true"></i>
                        <h3 class="h5 mt-3">No permit applications yet</h3>
                        <p class="text-secondary mb-3">Start a draft when you're ready with the property and tree details.</p>
                        <a href="permit-application.php" class="btn btn-certreefy">Start application</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search transaction or location">
                            <thead>
                                <tr>
                                    <th scope="col">Transaction</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">Trees</th>
                                    <th scope="col" data-tt-filter="Status">Status</th>
                                    <th scope="col">Last activity</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $application): ?>
                                    <?php
                                    $status = (string) $application['application_status'];
                                    $activityAt = $application['submitted_at'] ?: $application['updated_at'];
                                    $locationParts = array_filter([
                                        $application['property_address'],
                                        $application['municipality'],
                                        $application['province'],
                                    ], static fn ($value): bool => trim((string) $value) !== '');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">
                                                <?php echo $application['transaction_id'] !== null
                                                    ? e((string) $application['transaction_id'])
                                                    : 'Draft #' . e((string) $application['id']); ?>
                                            </div>
                                            <small class="text-secondary"><?php echo $status === 'draft' ? 'Not yet submitted' : 'Submitted application'; ?></small>
                                        </td>
                                        <td class="text-break">
                                            <?php echo $locationParts !== [] ? e(implode(', ', $locationParts)) : '<span class="text-secondary">Not yet provided</span>'; ?>
                                        </td>
                                        <td><?php echo e((string) $application['total_tree_quantity']); ?></td>
                                        <td><span class="badge <?php echo e(community_permit_status_badge($status)); ?>"><?php echo e(permit_status_label($status)); ?></span></td>
                                        <td>
                                            <?php echo $activityAt
                                                ? e(date('M j, Y g:i A', strtotime((string) $activityAt)))
                                                : '<span class="text-secondary">Just created</span>'; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="permit-application.php?id=<?php echo e((string) $application['id']); ?>" class="btn btn-sm <?php echo $status === 'draft' ? 'btn-outline-primary' : 'btn-outline-secondary'; ?>">
                                                <i class="bi <?php echo $status === 'draft' ? 'bi-pencil-square' : 'bi-eye'; ?>"></i>
                                                <?php echo $status === 'draft' ? 'Edit' : 'View'; ?>
                                            </a>
                                        </td>
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
    <script src="../../js/table-tools.js"></script>
</body>
</html>
