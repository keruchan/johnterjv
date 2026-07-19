<?php
/** EMS seedling-request detail: full record plus the state-appropriate action. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

$requestValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($requestValue) || (int) $requestValue < 1) {
    http_response_code(404);
    die('The seedling request was not found.');
}
$requestId = (int) $requestValue;

try {
    $request = seedling_request_for_actor($pdo, $requestId, $userId);
    if ($request === null) {
        http_response_code(404);
        die('The seedling request was not found.');
    }
    $items = seedling_request_items($pdo, $requestId);
    $history = seedling_request_history($pdo, $requestId);
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING REQUEST DETAIL ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load this seedling request at this time. Please try again later.');
}

if (empty($_SESSION['csrf_seedling_request_token'])) {
    $_SESSION['csrf_seedling_request_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_seedling_request_token'];

$flash = null;
if (!empty($_SESSION['seedling_request_flash']) && is_array($_SESSION['seedling_request_flash'])) {
    $flash = $_SESSION['seedling_request_flash'];
    unset($_SESSION['seedling_request_flash']);
}

$status = (string) $request['current_status'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Seedling Request <?php echo e((string) $request['request_reference']); ?></title>

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
                        <div class="eyebrow">Seedling Request</div>
                        <h1 class="page-title"><?php echo e((string) $request['request_reference']); ?></h1>
                        <p class="text-secondary meta-copy mb-0"><a href="seedling-requests.php"><i class="bi bi-arrow-left"></i> Back to queue</a></p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?>
                        <span class="badge fs-6 <?php echo e(seedling_request_status_badge($status)); ?>"><?php echo e(seedling_request_status_label($status)); ?></span>
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

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?php echo e((string) $flash['type']); ?> alert-dismissible fade show" role="alert">
                    <?php echo e((string) $flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <section class="docket-panel mb-4" aria-labelledby="requestSummaryHeading">
                <div class="section-heading"><h2 id="requestSummaryHeading">Request Summary</h2></div>
                <div class="row g-3">
                    <div class="col-md-4"><div class="small text-secondary">Requester</div><div class="fw-semibold"><?php echo e((string) $request['requester_full_name']); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Contact</div><div class="fw-semibold"><?php echo e((string) ($request['requester_contact'] ?? '-')); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Submitted</div><div class="fw-semibold"><?php echo e(date('M j, Y g:i A', strtotime((string) $request['submitted_at']))); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Preferred pickup date</div><div class="fw-semibold"><?php echo $request['preferred_pickup_date'] !== null ? e(date('M j, Y', strtotime((string) $request['preferred_pickup_date']))) : '-'; ?></div></div>
                    <div class="col-md-8"><div class="small text-secondary">Planting location</div><div class="fw-semibold text-break"><?php echo e((string) $request['planting_location']); ?></div></div>
                    <div class="col-12"><div class="small text-secondary">Planting purpose</div><div class="text-break"><?php echo e((string) $request['planting_purpose']); ?></div></div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="requestItemsHeading">
                <div class="section-heading"><h2 id="requestItemsHeading">Requested Species</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Species</th><th>Requested</th><th>Approved</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo e((string) $item['common_name']); ?></td>
                                    <td class="tabular"><?php echo (int) $item['quantity_requested']; ?></td>
                                    <td class="tabular"><?php echo $item['quantity_approved'] !== null ? (int) $item['quantity_approved'] : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="requestActionHeading">
                <div class="section-heading"><h2 id="requestActionHeading">Process Request</h2></div>

                <?php if ($status === 'submitted'): ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <form method="post" action="seedling-request-action.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                            <input type="hidden" name="action" value="begin_review">
                            <button type="submit" class="btn btn-certreefy"><i class="bi bi-play-circle"></i> Begin review</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'submitted' || $status === 'under_review'): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6"><i class="bi bi-check2-circle me-1"></i>Approve request</h3>
                        <p class="small text-secondary">Adjust each species down if you can't grant the full amount. Stock isn't deducted until fulfilment.</p>
                        <form method="post" action="seedling-request-action.php" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                            <input type="hidden" name="action" value="approve">
                            <?php foreach ($items as $item): ?>
                                <div class="col-md-4">
                                    <label class="form-label" for="approved_<?php echo (int) $item['id']; ?>"><?php echo e((string) $item['common_name']); ?> (requested <?php echo (int) $item['quantity_requested']; ?>)</label>
                                    <input class="form-control" type="number" id="approved_<?php echo (int) $item['id']; ?>" name="quantity_approved[<?php echo (int) $item['id']; ?>]" min="0" max="<?php echo (int) $item['quantity_requested']; ?>" value="<?php echo (int) $item['quantity_requested']; ?>" required>
                                </div>
                            <?php endforeach; ?>
                            <div class="col-12"><label class="form-label" for="approveRemarks">Remarks (optional)</label><textarea class="form-control" id="approveRemarks" name="remarks" rows="2" maxlength="1000"></textarea></div>
                            <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-check2-circle"></i> Approve</button></div>
                        </form>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6"><i class="bi bi-x-circle me-1"></i>Decline request</h3>
                        <form method="post" action="seedling-request-action.php" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                            <input type="hidden" name="action" value="decline">
                            <div class="col-md-9"><label class="form-label" for="declineRemarks">Reason (required)</label><input class="form-control" id="declineRemarks" type="text" name="remarks" maxlength="1000" required></div>
                            <div class="col-md-3"><button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-circle"></i> Decline</button></div>
                        </form>
                    </div>
                <?php elseif ($status === 'approved'): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6"><i class="bi bi-box-arrow-up me-1"></i>Fulfil request</h3>
                        <p class="small text-secondary">Deducts the approved quantities from inventory and marks it ready for pickup.</p>
                        <form method="post" action="seedling-request-action.php" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                            <input type="hidden" name="action" value="fulfil">
                            <div class="col-md-9"><label class="form-label" for="fulfilRemarks">Remarks (optional)</label><input class="form-control" id="fulfilRemarks" type="text" name="remarks" maxlength="1000"></div>
                            <div class="col-md-3"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-box-arrow-up"></i> Fulfil</button></div>
                        </form>
                    </div>
                <?php elseif ($status === 'ready_for_pickup'): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6"><i class="bi bi-check2-all me-1"></i>Record pickup</h3>
                        <form method="post" action="seedling-request-action.php" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                            <input type="hidden" name="action" value="claim">
                            <div class="col-md-6"><label class="form-label" for="claimedByName">Collected by</label><input class="form-control" id="claimedByName" type="text" name="claimed_by_name" maxlength="150" value="<?php echo e((string) $request['requester_full_name']); ?>" required></div>
                            <div class="col-md-6"><label class="form-label" for="claimedOn">Claim date</label><input class="form-control" id="claimedOn" type="date" name="claimed_on" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e(date('Y-m-d')); ?>" required></div>
                            <div class="col-12"><label class="form-label" for="claimRemarks">Remarks (optional)</label><textarea class="form-control" id="claimRemarks" name="remarks" rows="2" maxlength="1000"></textarea></div>
                            <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-check2-all"></i> Record pickup</button></div>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="text-secondary mb-0"><?php echo e(seedling_request_status_label($status)); ?> — no further action needed.</p>
                <?php endif; ?>
            </section>

            <section class="docket-panel" aria-labelledby="requestHistoryHeading">
                <div class="section-heading"><h2 id="requestHistoryHeading">Status History</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Status</th><th>Remarks</th><th>By</th><th>When</th></tr></thead>
                        <tbody>
                            <?php foreach ($history as $event): ?>
                                <tr>
                                    <td><span class="badge <?php echo e(seedling_request_status_badge((string) $event['new_status'])); ?>"><?php echo e(seedling_request_status_label((string) $event['new_status'])); ?></span></td>
                                    <td class="small text-break"><?php echo e((string) ($event['remarks'] ?? '-')); ?></td>
                                    <td class="small"><?php echo e((string) $event['changed_by_name']); ?></td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string) $event['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
