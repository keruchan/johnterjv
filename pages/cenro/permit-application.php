<?php
/** Authorized permit detail, document review, and site inspection. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit_documents.php';
require_once __DIR__ . '/../../includes/permit_inspections.php';
require_once __DIR__ . '/../../includes/permit_decisions.php';
require_once __DIR__ . '/../../includes/permit_donation_view.php';
require_once __DIR__ . '/../../includes/permit_release.php';
require_once __DIR__ . '/../../includes/area_management.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

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
$applicationValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($applicationValue) || (int) $applicationValue < 1) {
    http_response_code(404);
    die('The permit application was not found.');
}
$applicationId = (int) $applicationValue;

try {
    $application = $canDecideApplications
        ? permit_decision_application_for_actor($pdo, $applicationId, $userId, 'view')
        : ($canManageInspections
            ? permit_inspection_application_for_actor($pdo, $applicationId, $userId, 'view')
            : permit_document_application_for_actor($pdo, $applicationId, $userId, 'view'));
    if ($application === null) {
        http_response_code(404);
        die('The permit application was not found.');
    }
    $trees = permit_tree_records_for_actor($pdo, $applicationId, $userId) ?? [];
    $documents = $canViewDocuments
        ? (permit_documents_for_actor($pdo, $applicationId, $userId, true) ?? [])
        : [];
    $originalReviews = $canViewDocuments
        ? (permit_original_reviews_for_actor($pdo, $applicationId, $userId) ?? [])
        : [];
    $receivingPersonnel = $canReviewOriginals ? permit_original_receiving_personnel($pdo) : [];
    $inspections = permit_inspections_for_actor($pdo, $applicationId, $userId) ?? [];
    $inspectionPersonnel = $canManageInspections ? permit_inspection_personnel($pdo) : [];
    $decisionEvents = $canDecideApplications
        ? (permit_decision_events_for_actor($pdo, $applicationId, $userId) ?? [])
        : [];
    $statusHistory = $canDecideApplications
        ? (permit_status_history_for_actor($pdo, $applicationId, $userId) ?? [])
        : [];
    $donationRequirement = $canDecideApplications
        ? permit_donation_requirement_for_actor($pdo, $applicationId, $userId)
        : null;
    $decisionReadiness = $canDecideApplications
        ? permit_decision_readiness($pdo, $applicationId, false, $application)
        : null;
    $releaseRecord = permit_release_record_for_actor($pdo, $applicationId, $userId);
    $validitySnapshot = permit_validity_snapshot($releaseRecord, (string) $application['validity_status']);
    $cuttingCompletion = permit_cutting_completion_for_actor($pdo, $applicationId, $userId);
    $completionEvidence = permit_cutting_completion_evidence_for_actor($pdo, $applicationId, $userId);
    $completionPersonnel = $canDecideApplications ? permit_completion_personnel($pdo) : [];
    $durationBounds = permit_validity_duration_bounds();
} catch (PDOException $e) {
    error_log('[CERTREEFY RPS PERMIT DETAIL ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load the permit application at this time. Please try again later.');
}

if (empty($_SESSION['csrf_permit_document_review_token'])) {
    $_SESSION['csrf_permit_document_review_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_permit_original_review_token'])) {
    $_SESSION['csrf_permit_original_review_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_permit_inspection_token'])) {
    $_SESSION['csrf_permit_inspection_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_permit_decision_token'])) {
    $_SESSION['csrf_permit_decision_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_permit_release_token'])) {
    $_SESSION['csrf_permit_release_token'] = bin2hex(random_bytes(32));
}
$releaseFlash = null;
if (!empty($_SESSION['permit_release_flash']) && is_array($_SESSION['permit_release_flash'])) {
    $releaseFlash = $_SESSION['permit_release_flash'];
    unset($_SESSION['permit_release_flash']);
}
$reviewFlash = null;
if (!empty($_SESSION['permit_document_review_flash']) && is_array($_SESSION['permit_document_review_flash'])) {
    $reviewFlash = $_SESSION['permit_document_review_flash'];
    unset($_SESSION['permit_document_review_flash']);
}
$originalReviewFlash = null;
if (!empty($_SESSION['permit_original_review_flash']) && is_array($_SESSION['permit_original_review_flash'])) {
    $originalReviewFlash = $_SESSION['permit_original_review_flash'];
    unset($_SESSION['permit_original_review_flash']);
}
$inspectionFlash = null;
if (!empty($_SESSION['permit_inspection_flash']) && is_array($_SESSION['permit_inspection_flash'])) {
    $inspectionFlash = $_SESSION['permit_inspection_flash'];
    unset($_SESSION['permit_inspection_flash']);
}
$decisionFlash = null;
if (!empty($_SESSION['permit_decision_flash']) && is_array($_SESSION['permit_decision_flash'])) {
    $decisionFlash = $_SESSION['permit_decision_flash'];
    unset($_SESSION['permit_decision_flash']);
}

$catalog = permit_document_type_catalog();
$currentDocuments = permit_current_documents_by_type($documents);
$latestOriginalReviews = permit_latest_original_reviews_by_type($originalReviews);
$originalProgress = permit_original_required_progress($catalog, $currentDocuments, $latestOriginalReviews);
$reviewLockReason = permit_document_review_lock_reason($application);
$requiredCount = count(array_filter($catalog, static fn (array $definition): bool => !empty($definition['required'])));
$acceptedCount = 0;
foreach ($catalog as $type => $definition) {
    if (!empty($definition['required'])
        && isset($currentDocuments[$type])
        && (string) $currentDocuments[$type]['verification_status'] === 'accepted') {
        $acceptedCount++;
    }
}
$progress = $requiredCount > 0 ? (int) round(($acceptedCount / $requiredCount) * 100) : 0;
$latestInspection = $inspections[0] ?? null;
$latestDecision = $decisionEvents[0] ?? null;
$decisionLockReason = permit_decision_lock_reason($application);
$donationPolicy = null;
if ($canDecideApplications) {
    try {
        $donationPolicy = permit_donation_policy_for_classification((string) $application['property_classification']);
    } catch (RuntimeException $e) {
        $donationPolicy = null;
    }
}
$inspectionLockReason = permit_inspection_lock_reason($application);
$inspectionTreeVerifications = [];
$inspectionPhotos = [];
foreach ($inspections as $inspection) {
    $inspectionId = (int) $inspection['id'];
    $inspectionTreeVerifications[$inspectionId] = permit_inspection_tree_verifications_for_actor(
        $pdo,
        $inspectionId,
        $userId
    ) ?? [];
    if ($canManageInspections) {
        $inspectionPhotos[$inspectionId] = permit_inspection_photos_for_actor(
            $pdo,
            $inspectionId,
            $userId
        ) ?? [];
    }
}
$defaultInspectionLocation = implode(', ', array_filter([
    (string) $application['property_address'],
    (string) $application['barangay'],
    (string) $application['municipality'],
    (string) $application['province'],
]));
try {
    $zoneMapFeatures = area_zone_map_features($pdo);
} catch (PDOException $e) {
    $zoneMapFeatures = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Permit <?php echo e((string) $application['transaction_id']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body data-bs-spy="scroll" data-bs-target="#pageSubNav" data-bs-offset="130" tabindex="0">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'permit_applications', $navigationPermissions); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div><div class="eyebrow">RPS Permit Review &middot; <?php echo e($todayLabel); ?></div><h1 class="page-title"><?php echo e((string) $application['transaction_id']); ?></h1><p class="meta-copy mb-0">Application information, requirements, inspection, review, and decision history.</p></div>
                    <div class="d-flex flex-wrap align-items-center gap-2"><?php render_certreefy_notification_bell('header'); ?><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>"><button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right"></i> Logout</button></form></div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/></svg>
            </section>

            <nav id="pageSubNav" class="page-subnav" aria-label="Jump to a section on this page">
                <a class="page-subnav-link" href="#overview"><i class="bi bi-speedometer2"></i> Overview</a>
                <?php if ($canDecideApplications && is_array($decisionReadiness)): ?><a class="page-subnav-link" href="#decision-review"><i class="bi bi-clipboard-check"></i> Decision</a><?php endif; ?>
                <a class="page-subnav-link" href="#release-workflow"><i class="bi bi-tree"></i> Donation &amp; Release</a>
                <a class="page-subnav-link" href="#trees"><i class="bi bi-list-check"></i> Tree Records</a>
                <a class="page-subnav-link" href="#inspection"><i class="bi bi-geo-alt"></i> Inspection</a>
                <?php if ($canViewDocuments): ?><a class="page-subnav-link" href="#documents"><i class="bi bi-folder2-open"></i> Documents</a><?php endif; ?>
            </nav>

            <?php if (is_array($reviewFlash)): ?><div class="alert alert-<?php echo ($reviewFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert"><?php echo e((string) ($reviewFlash['message'] ?? 'The review could not be completed.')); ?></div><?php endif; ?>
            <?php if (is_array($originalReviewFlash)): ?><div class="alert alert-<?php echo ($originalReviewFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert"><?php echo e((string) ($originalReviewFlash['message'] ?? 'The original verification could not be completed.')); ?></div><?php endif; ?>
            <?php if (is_array($inspectionFlash)): ?><div class="alert alert-<?php echo ($inspectionFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert"><?php echo e((string) ($inspectionFlash['message'] ?? 'The inspection action could not be completed.')); ?></div><?php endif; ?>
            <?php if (is_array($decisionFlash)): ?><div class="alert alert-<?php echo ($decisionFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert"><?php echo e((string) ($decisionFlash['message'] ?? 'The review action could not be completed.')); ?></div><?php endif; ?>
            <?php if (is_array($releaseFlash)): ?><div class="alert alert-<?php echo ($releaseFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert"><?php echo e((string) ($releaseFlash['message'] ?? 'The release action could not be completed.')); ?></div><?php endif; ?>

            <section class="row g-3 mb-3 page-anchor-section" id="overview">
                <div class="col-xl-8">
                    <div class="docket-panel h-100">
                        <div class="section-heading"><h2>Application Summary</h2><span class="section-note">Read-only</span></div>
                        <div class="row g-3">
                            <div class="col-md-6"><div class="small text-secondary">Applicant</div><div class="fw-semibold"><?php echo e((string) $application['applicant_name']); ?></div></div>
                            <div class="col-md-6"><div class="small text-secondary">Contact</div><div class="fw-semibold"><?php echo e((string) ($application['applicant_contact'] ?? 'Not provided')); ?></div></div>
                            <div class="col-md-6"><div class="small text-secondary">Property owner</div><div class="fw-semibold"><?php echo e((string) $application['property_owner_name']); ?></div></div>
                            <div class="col-md-6"><div class="small text-secondary">Classification</div><div class="fw-semibold"><?php echo e(permit_status_label((string) $application['property_classification'])); ?></div></div>
                            <div class="col-12"><div class="small text-secondary">Property location</div><div class="fw-semibold"><?php echo e(implode(', ', array_filter([(string) $application['property_address'], (string) $application['barangay'], (string) $application['municipality'], (string) $application['province']]))); ?></div></div>
                            <div class="col-12"><div class="small text-secondary">Purpose of cutting</div><div><?php echo e((string) $application['cutting_purpose']); ?></div></div>
                            <?php if ($application['latitude'] !== null && $application['longitude'] !== null): ?>
                                <div class="col-12">
                                    <div class="small text-secondary mb-1">Site map for area visitation <span class="text-muted">(managed zones overlaid for reference)</span></div>
                                    <div id="propertySiteMap" class="geo-map" role="img" aria-label="Map of the application property location"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="snapshot-panel h-100">
                        <div class="section-heading"><h2>Workflow Snapshot</h2></div>
                        <div class="snapshot-row"><span class="text-secondary">Application</span><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['application_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Document workflow</span><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['document_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Site inspection</span><span class="badge <?php echo e(permit_inspection_status_badge((string) $application['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $application['inspection_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">RPS decision</span><span class="badge <?php echo e(permit_decision_event_badge((string) $application['decision_status'])); ?>"><?php echo e(permit_status_label((string) $application['decision_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Donation</span><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['donation_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Release</span><span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $application['release_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Validity</span><span class="badge <?php echo $validitySnapshot['is_expired'] || (string) $application['validity_status'] === 'expired' ? 'text-bg-danger' : ((string) $application['validity_status'] === 'active' ? 'text-bg-success' : 'text-bg-light border'); ?>"><?php echo e(permit_status_label((string) $application['validity_status'])); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Submitted</span><span class="fw-semibold"><?php echo e(date('M j, Y', strtotime((string) $application['submitted_at']))); ?></span></div>
                    </div>
                </div>
            </section>

            <?php if ($canDecideApplications && is_array($decisionReadiness)): ?>
            <section class="docket-panel mb-3 page-anchor-section" id="decision-review" aria-labelledby="decision-review-heading">
                <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div><h2 id="decision-review-heading">RPS Review &amp; Decision</h2><span class="section-note">RPS decision authority only</span></div>
                    <span class="badge <?php echo e(permit_decision_event_badge((string) $application['decision_status'])); ?>"><?php echo e(permit_status_label((string) $application['decision_status'])); ?></span>
                </div>
                <div class="alert alert-light border" role="note"><i class="bi bi-shield-check me-1"></i>Approval logs the decision and sets the donation requirement — it doesn't release the permit.</div>
                <?php if ($decisionLockReason !== null): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i><?php echo e($decisionLockReason); ?> Records stay read-only.</div><?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-lg-7">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><h3 class="h6 mb-0">Ready-for-decision checks</h3><span class="badge <?php echo $decisionReadiness['ready'] ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo $decisionReadiness['ready'] ? 'Ready' : 'Requirements pending'; ?></span></div>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($decisionReadiness['checks'] as $check): ?>
                                    <li class="list-group-item px-0 d-flex align-items-start gap-2"><i class="bi <?php echo !empty($check['passed']) ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?>"></i><div><div><?php echo e((string) $check['label']); ?></div><?php if (!empty($check['detail'])): ?><small class="text-secondary"><?php echo e((string) $check['detail']); ?></small><?php endif; ?></div></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6">Decision controls</h3>
                            <div class="small text-secondary mb-3">Approved tree limit: <?php echo (int) $decisionReadiness['approved_tree_limit']; ?> of <?php echo (int) $decisionReadiness['application_tree_total']; ?> application tree(s).</div>
                            <?php if ($decisionLockReason === null): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if (in_array((string) $application['decision_status'], ['pending', 'returned'], true)): ?>
                                        <form method="post" action="permit-decision-action.php">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_decision_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_decision_id" value="<?php echo (int) ($latestDecision['id'] ?? 0); ?>"><input type="hidden" name="action" value="begin_review">
                                            <button class="btn btn-certreefy" type="submit"><i class="bi bi-play-circle"></i> <?php echo (string) $application['decision_status'] === 'returned' ? 'Resume review' : 'Begin review'; ?></button>
                                        </form>
                                    <?php elseif ((string) $application['decision_status'] === 'under_review'): ?>
                                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#returnCorrectionModal"><i class="bi bi-arrow-return-left"></i> Return for correction</button>
                                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#requirementsRequestModal"><i class="bi bi-file-earmark-plus"></i> Request requirements</button>
                                        <a class="btn btn-outline-secondary" href="#inspection"><i class="bi bi-geo-alt"></i> Inspection controls</a>
                                        <button class="btn btn-certreefy" type="button" data-bs-toggle="modal" data-bs-target="#approvePermitModal" <?php echo $decisionReadiness['ready'] ? '' : 'disabled'; ?>><i class="bi bi-check2-circle"></i> Approve</button>
                                        <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#declinePermitModal"><i class="bi bi-x-octagon"></i> Decline</button>
                                    <?php endif; ?>
                                </div>
                                <?php if ((string) $application['decision_status'] === 'under_review' && !$decisionReadiness['ready']): ?><div class="form-text mt-2">Approval is disabled until every configured readiness check passes. The server rechecks these requirements when the decision is submitted.</div><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($donationRequirement !== null): ?>
                                <hr><?php render_permit_donation_requirement($donationRequirement, true); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <h3 class="h5">Decision History</h3>
                <?php if ($decisionEvents === []): ?>
                    <p class="text-secondary">No RPS review action has been recorded.</p>
                <?php else: ?>
                    <div class="table-responsive mb-4"><table class="table align-middle mb-0"><thead><tr><th>Action</th><th>Responsible personnel</th><th>Remarks / conditions</th><th>Decision details</th></tr></thead><tbody>
                        <?php foreach ($decisionEvents as $decisionEvent): ?><tr><td><span class="badge <?php echo e(permit_decision_event_badge((string) $decisionEvent['decision'])); ?>"><?php echo e(permit_decision_event_label((string) $decisionEvent['decision'])); ?></span><div class="small text-secondary mt-1"><?php echo e(date('M j, Y g:i A', strtotime((string) $decisionEvent['decided_at']))); ?></div></td><td><?php echo e((string) $decisionEvent['decision_maker_name']); ?></td><td class="text-break"><?php echo e((string) ($decisionEvent['decision_notes'] ?? '-')); ?><?php if (!empty($decisionEvent['decision_conditions'])): ?><div class="small mt-1"><strong>Conditions:</strong> <?php echo e((string) $decisionEvent['decision_conditions']); ?></div><?php endif; ?></td><td class="small"><?php if ($decisionEvent['approved_tree_count'] !== null): ?><div>Approved trees: <strong><?php echo (int) $decisionEvent['approved_tree_count']; ?></strong></div><div>Property: <?php echo e(permit_status_label((string) $decisionEvent['property_classification'])); ?></div><div>Donation: <?php echo (int) $decisionEvent['donation_seedling_count']; ?> seedling(s)</div><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>

                <details>
                    <summary class="fw-semibold">Application activity and status history (<?php echo count($statusHistory); ?>)</summary>
                    <div class="table-responsive mt-3"><table class="table align-middle mb-0"><thead><tr><th>Workflow</th><th>Change</th><th>Responsible user</th><th>Time / remarks</th></tr></thead><tbody>
                        <?php foreach ($statusHistory as $historyEntry): ?><tr><td><?php echo e(permit_status_label((string) $historyEntry['status_domain'])); ?></td><td><?php echo e(permit_status_label((string) ($historyEntry['previous_status'] ?? 'Not set'))); ?> <i class="bi bi-arrow-right"></i> <strong><?php echo e(permit_status_label((string) $historyEntry['new_status'])); ?></strong></td><td><?php echo e((string) $historyEntry['actor_name']); ?></td><td><div><?php echo e(date('M j, Y g:i A', strtotime((string) $historyEntry['created_at']))); ?></div><div class="small text-secondary text-break"><?php echo e((string) ($historyEntry['remarks'] ?? '-')); ?></div></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </details>
            </section>
            <?php endif; ?>

            <section class="docket-panel mb-3 page-anchor-section" id="release-workflow" aria-labelledby="release-workflow-heading">
                <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div><h2 id="release-workflow-heading">Donation Confirmation, Release &amp; Validity</h2><span class="section-note">Final RPS confirmation, signed-permit release, and cutting completion</span></div>
                    <span class="badge <?php echo $validitySnapshot['is_expired'] || (string) $application['validity_status'] === 'expired' ? 'text-bg-danger' : ((string) $application['validity_status'] === 'active' ? 'text-bg-success' : 'text-bg-light border'); ?>"><?php echo e(permit_status_label((string) $application['validity_status'])); ?></span>
                </div>
                <div class="alert alert-light border" role="note"><i class="bi bi-shield-check me-1"></i>Fixed <?php echo (int) $durationBounds['min']; ?>&ndash;<?php echo (int) $durationBounds['max']; ?>-day term — no extension or reactivation. If it expires before cutting is done, a new application is needed.</div>

                <?php if ($releaseRecord !== null): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><div class="small text-secondary">Permit number</div><div class="fw-semibold"><?php echo e((string) $releaseRecord['permit_number']); ?></div></div>
                        <div class="col-md-3"><div class="small text-secondary">Approved duration</div><div class="fw-semibold"><?php echo (int) $releaseRecord['approved_duration_days']; ?> days</div></div>
                        <div class="col-md-3"><div class="small text-secondary">Valid from</div><div class="fw-semibold"><?php echo $releaseRecord['valid_from'] !== null ? e(date('M j, Y', strtotime((string) $releaseRecord['valid_from']))) : '-'; ?></div></div>
                        <div class="col-md-3"><div class="small text-secondary">Valid until</div><div class="fw-semibold"><?php echo $releaseRecord['valid_until'] !== null ? e(date('M j, Y', strtotime((string) $releaseRecord['valid_until']))) : '-'; ?></div></div>
                        <div class="col-md-3"><div class="small text-secondary">Released by</div><div class="fw-semibold"><?php echo e((string) ($releaseRecord['released_by_name'] ?? $releaseRecord['prepared_by_name'])); ?></div></div>
                        <div class="col-md-3"><div class="small text-secondary">Released at</div><div class="fw-semibold"><?php echo $releaseRecord['released_at'] !== null ? e(date('M j, Y g:i A', strtotime((string) $releaseRecord['released_at']))) : '-'; ?></div></div>
                        <div class="col-md-6"><div class="small text-secondary">Validity start basis</div><div class="fw-semibold"><?php echo e(permit_status_label((string) ($releaseRecord['validity_start_basis'] ?? permit_validity_start_basis()))); ?> <span class="text-secondary">(office rule unverified; using physical release date)</span></div></div>
                        <?php if (!empty($releaseRecord['release_notes'])): ?><div class="col-12"><div class="small text-secondary">Release notes</div><div class="text-break"><?php echo e((string) $releaseRecord['release_notes']); ?></div></div><?php endif; ?>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6 mb-2"><i class="bi bi-file-earmark-pdf me-1"></i>Signed permit (physical &amp; scanned copy)</h3>
                        <?php if (!empty($releaseRecord['permit_file_path'])): ?>
                            <div class="row g-3 mb-2">
                                <div class="col-md-3"><div class="small text-secondary">Signed on</div><div class="fw-semibold"><?php echo $releaseRecord['signed_on'] !== null ? e(date('M j, Y', strtotime((string) $releaseRecord['signed_on']))) : '-'; ?></div></div>
                                <div class="col-md-3"><div class="small text-secondary">Signing personnel</div><div class="fw-semibold"><?php echo $releaseRecord['signed_by_name'] !== null ? e((string) $releaseRecord['signed_by_name']) : '-'; ?></div></div>
                                <div class="col-md-3"><div class="small text-secondary">Released to (claimant)</div><div class="fw-semibold"><?php echo $releaseRecord['released_to_recipient'] !== null ? e((string) $releaseRecord['released_to_recipient']) : '-'; ?></div></div>
                                <div class="col-md-3"><div class="small text-secondary">Scan uploaded by</div><div class="fw-semibold"><?php echo e((string) ($releaseRecord['permit_file_uploaded_by_name'] ?? '-')); ?><?php echo $releaseRecord['permit_file_uploaded_at'] !== null ? '<br><span class="small text-secondary">' . e(date('M j, Y g:i A', strtotime((string) $releaseRecord['permit_file_uploaded_at']))) . '</span>' : ''; ?></div></div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="permit-signed-download.php?id=<?php echo $applicationId; ?>"><i class="bi bi-download"></i> Download signed permit<?php echo !empty($releaseRecord['permit_file_original_name']) ? ' (' . e((string) $releaseRecord['permit_file_original_name']) . ')' : ''; ?></a>
                        <?php else: ?>
                            <p class="small text-secondary mb-2"><i class="bi bi-info-circle me-1"></i>No signed scan yet. Uploading one is just a record — it doesn't release the permit.</p>
                        <?php endif; ?>
                        <?php if ($canDecideApplications): ?>
                            <form method="post" action="permit-signed-upload-action.php" class="row g-3 mt-1" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_release_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                <div class="col-md-6"><label class="form-label" for="signedPermitFile"><?php echo !empty($releaseRecord['permit_file_path']) ? 'Replace signed permit scan' : 'Upload signed permit scan'; ?></label><input class="form-control" id="signedPermitFile" type="file" name="signed_permit_file" accept="<?php echo e(permit_document_accept_attribute()); ?>" required><div class="form-text">PDF, JPG, or PNG up to <?php echo e(permit_document_max_size_label()); ?>. Stored privately.</div></div>
                                <div class="col-md-3"><label class="form-label" for="signedOn">Signing date (optional)</label><input class="form-control" id="signedOn" type="date" name="signed_on" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo $releaseRecord['signed_on'] !== null ? e((string) $releaseRecord['signed_on']) : ''; ?>"></div>
                                <div class="col-md-3"><label class="form-label" for="signedByName">Signing personnel (optional)</label><input class="form-control" id="signedByName" type="text" name="signed_by_name" maxlength="150" value="<?php echo $releaseRecord['signed_by_name'] !== null ? e((string) $releaseRecord['signed_by_name']) : ''; ?>"></div>
                                <div class="col-md-6"><label class="form-label" for="releasedToRecipient">Released to / claimant (optional)</label><input class="form-control" id="releasedToRecipient" type="text" name="released_to_recipient" maxlength="150" value="<?php echo $releaseRecord['released_to_recipient'] !== null ? e((string) $releaseRecord['released_to_recipient']) : ''; ?>"></div>
                                <div class="col-md-6 d-flex align-items-end"><button class="btn btn-certreefy" type="submit"><i class="bi bi-cloud-arrow-up"></i> <?php echo !empty($releaseRecord['permit_file_path']) ? 'Replace signed permit' : 'Record signed permit'; ?></button></div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ((string) $application['validity_status'] === 'active' && $validitySnapshot['is_expired']): ?>
                        <div class="alert alert-danger"><i class="bi bi-hourglass-bottom me-1"></i>This permit has passed its validity end date and is pending the expiration sweep. It will be marked expired automatically.</div>
                    <?php elseif ((string) $application['validity_status'] === 'active' && !empty($validitySnapshot['is_expiring_soon'])): ?>
                        <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>This permit expires in <?php echo (int) $validitySnapshot['days_remaining']; ?> day(s). No extension is possible.</div>
                    <?php elseif ((string) $application['validity_status'] === 'expired'): ?>
                        <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>This permit has expired and cannot be extended or reactivated.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($cuttingCompletion !== null): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6 mb-2"><i class="bi bi-check2-all me-1"></i>Cutting completion recorded</h3>
                        <div class="row g-3">
                            <div class="col-md-3"><div class="small text-secondary">Status</div><div class="fw-semibold"><?php echo e(permit_status_label((string) $cuttingCompletion['completion_status'])); ?></div></div>
                            <div class="col-md-3"><div class="small text-secondary">Trees cut</div><div class="fw-semibold"><?php echo (int) $cuttingCompletion['trees_cut_count']; ?></div></div>
                            <div class="col-md-3"><div class="small text-secondary">Completed on</div><div class="fw-semibold"><?php echo e(date('M j, Y', strtotime((string) $cuttingCompletion['completed_on']))); ?></div></div>
                            <div class="col-md-3"><div class="small text-secondary">Verified by</div><div class="fw-semibold"><?php echo e((string) $cuttingCompletion['verified_by_name']); ?></div></div>
                            <?php if (!empty($cuttingCompletion['remarks'])): ?><div class="col-12"><div class="small text-secondary">Remarks</div><div class="text-break"><?php echo e((string) $cuttingCompletion['remarks']); ?></div></div><?php endif; ?>
                            <div class="col-12"><div class="small text-secondary">Recorded by <?php echo e((string) $cuttingCompletion['recorded_by_name']); ?> &middot; <?php echo e(date('M j, Y g:i A', strtotime((string) $cuttingCompletion['created_at']))); ?></div></div>
                        </div>
                        <?php if ($completionEvidence !== []): ?>
                            <div class="mt-3">
                                <div class="small text-secondary mb-1">Evidence (<?php echo count($completionEvidence); ?>)</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($completionEvidence as $evidence): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="permit-completion-evidence-download.php?id=<?php echo (int) $evidence['id']; ?>"><i class="bi bi-image"></i> <?php echo e((string) $evidence['original_filename']); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canDecideApplications): ?>
                    <?php if ((string) $application['application_status'] === 'awaiting_final_verification' && (string) $application['donation_status'] === 'ems_verified'): ?>
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6"><i class="bi bi-patch-check me-1"></i>Confirm seedling donation compliance</h3>
                            <p class="small text-secondary">EMS verified the physical receipt. Confirm to proceed to permit preparation and release.</p>
                            <form method="post" action="permit-final-verification-action.php" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_release_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                <div class="col-md-8"><label class="form-label" for="finalVerifyRemarks">Confirmation remarks (optional)</label><input class="form-control" id="finalVerifyRemarks" type="text" name="remarks" maxlength="500"></div>
                                <div class="col-md-4"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-check2-circle"></i> Confirm compliance</button></div>
                            </form>
                        </div>
                    <?php elseif ((string) $application['application_status'] === 'ready_for_release' && (string) $application['release_status'] === 'preparing'): ?>
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6"><i class="bi bi-file-earmark-medical me-1"></i>Prepare &amp; release signed permit</h3>
                            <p class="small text-secondary">Enter the approved cutting duration. Expiration is set from the release date and can't be extended.</p>
                            <form method="post" action="permit-release-action.php" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_release_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                <div class="col-md-4"><label class="form-label" for="approvedDurationDays">Approved duration (days)</label><input class="form-control" id="approvedDurationDays" type="number" name="approved_duration_days" min="<?php echo (int) $durationBounds['min']; ?>" max="<?php echo (int) $durationBounds['max']; ?>" value="<?php echo (int) $durationBounds['default']; ?>" required><div class="form-text"><?php echo (int) $durationBounds['min']; ?>&ndash;<?php echo (int) $durationBounds['max']; ?> days; enforced on the server.</div></div>
                                <div class="col-md-4"><label class="form-label" for="permitNumber">Permit number</label><input class="form-control" id="permitNumber" type="text" name="permit_number" maxlength="50" value="<?php echo e((string) $application['transaction_id']); ?>"><div class="form-text">Defaults to the transaction ID; must be unique.</div></div>
                                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-send-check"></i> Release permit</button></div>
                                <div class="col-12"><label class="form-label" for="releaseNotes">Release notes (optional)</label><textarea class="form-control" id="releaseNotes" name="release_notes" rows="2" maxlength="1000"></textarea></div>
                            </form>
                        </div>
                    <?php elseif ((string) $application['validity_status'] === 'active' && (string) $application['application_status'] === 'released' && $cuttingCompletion === null): ?>
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6"><i class="bi bi-tree me-1"></i>Record cutting completion</h3>
                            <p class="small text-secondary">Record the cutting outcome while the permit is active — this closes the transaction.</p>
                            <form method="post" action="permit-completion-action.php" class="row g-3" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_release_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                <div class="col-md-3"><label class="form-label" for="completionStatus">Completion status</label><select class="form-select" id="completionStatus" name="completion_status" required><option value="completed">Completed</option><option value="partially_completed">Partially completed</option></select></div>
                                <div class="col-md-3"><label class="form-label" for="treesCutCount">Trees actually cut</label><input class="form-control" id="treesCutCount" type="number" name="trees_cut_count" min="0" value="0" required></div>
                                <div class="col-md-3"><label class="form-label" for="completedOn">Completion date</label><input class="form-control" id="completedOn" type="date" name="completed_on" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e(date('Y-m-d')); ?>" required></div>
                                <div class="col-md-3"><label class="form-label" for="verifiedByUserId">Verifying personnel</label><select class="form-select" id="verifiedByUserId" name="verified_by_user_id"><?php foreach ($completionPersonnel as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo (int) $person['id'] === $userId ? 'selected' : ''; ?>><?php echo e((string) $person['full_name']); ?> (<?php echo e(strtoupper((string) $person['role'])); ?>)</option><?php endforeach; ?></select></div>
                                <div class="col-md-6"><label class="form-label" for="completionEvidence">Evidence photos (optional)</label><input class="form-control" id="completionEvidence" type="file" name="completion_evidence[]" accept=".jpg,.jpeg,.png" multiple><div class="form-text">JPG or PNG, up to 10 photos, <?php echo e(permit_document_max_size_label()); ?> each. Stored privately.</div></div>
                                <div class="col-md-6"><label class="form-label" for="completionRemarks">Remarks (optional)</label><textarea class="form-control" id="completionRemarks" name="remarks" rows="2" maxlength="1000"></textarea></div>
                                <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-check2-square"></i> Record completion</button></div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($releaseRecord === null && (string) $application['validity_status'] === 'not_issued'
                    && !in_array((string) $application['application_status'], ['awaiting_final_verification', 'ready_for_release'], true)): ?>
                    <p class="text-secondary mb-0">No permit released yet — available after EMS donation verification and RPS compliance confirmation.</p>
                <?php endif; ?>
            </section>

            <section class="docket-panel mb-3 page-anchor-section" id="trees" aria-labelledby="trees-heading">
                <div class="section-heading"><h2 id="trees-heading">Tree Records</h2><span class="section-note"><?php echo count($trees); ?> record<?php echo count($trees) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Common name</th><th>Scientific name</th><th>Quantity</th><th>Diameter</th><th>Height</th></tr></thead><tbody>
                    <?php foreach ($trees as $tree): ?><tr><td><?php echo e((string) $tree['common_name']); ?></td><td><?php echo e((string) ($tree['scientific_name'] ?? '-')); ?></td><td><?php echo e((string) $tree['quantity']); ?></td><td><?php echo $tree['diameter_cm'] !== null ? e((string) $tree['diameter_cm']) . ' cm' : '-'; ?></td><td><?php echo $tree['estimated_height_m'] !== null ? e((string) $tree['estimated_height_m']) . ' m' : '-'; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section>

            <section class="docket-panel mb-3 page-anchor-section" id="inspection" aria-labelledby="inspection-heading">
                <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div><h2 id="inspection-heading">Site Inspection &amp; Tree Verification</h2><span class="section-note">Append-only scheduling and findings history</span></div>
                    <span class="badge <?php echo e(permit_inspection_status_badge((string) $application['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $application['inspection_status'])); ?></span>
                </div>
                <div class="alert alert-light border" role="note"><i class="bi bi-info-circle me-1"></i>Feeds the RPS decision — passing inspection doesn't approve the permit.</div>
                <?php if ($inspectionLockReason !== null): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i><?php echo e($inspectionLockReason); ?> History stays available.</div><?php endif; ?>

                <?php if ($canManageInspections && $inspectionLockReason === null): ?>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <?php if ((string) $application['inspection_status'] === 'pending_assessment'): ?>
                            <form method="post" action="permit-inspection-action.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" value="mark_required">
                                <button class="btn btn-certreefy" type="submit"><i class="bi bi-clipboard-check"></i> Mark inspection required</button>
                            </form>
                            <form method="post" action="permit-inspection-action.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" value="mark_not_required">
                                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-dash-circle"></i> Mark not required</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array((string) $application['inspection_status'], ['required', 'cancelled'], true)): ?>
                            <button class="btn btn-certreefy inspection-schedule-button" type="button" data-bs-toggle="modal" data-bs-target="#inspectionScheduleModal" data-action="schedule" <?php echo (string) $application['document_status'] !== 'verified' ? 'disabled' : ''; ?>><i class="bi bi-calendar-event"></i> Schedule inspection</button>
                        <?php endif; ?>
                        <?php if (in_array((string) $application['inspection_status'], ['scheduled', 'rescheduled'], true)): ?>
                            <button class="btn btn-outline-secondary inspection-schedule-button" type="button" data-bs-toggle="modal" data-bs-target="#inspectionScheduleModal" data-action="reschedule"><i class="bi bi-calendar2-week"></i> Reschedule</button>
                            <form method="post" action="permit-inspection-action.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" value="start">
                                <button class="btn btn-certreefy" type="submit"><i class="bi bi-play-circle"></i> Start inspection</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((string) $application['inspection_status'] === 'in_progress'): ?>
                            <button class="btn btn-certreefy" type="button" data-bs-toggle="modal" data-bs-target="#inspectionCompleteModal"><i class="bi bi-check2-square"></i> Record findings</button>
                        <?php endif; ?>
                        <?php if (in_array((string) $application['inspection_status'], ['required', 'scheduled', 'rescheduled', 'in_progress'], true)): ?>
                            <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#inspectionCancelModal"><i class="bi bi-x-circle"></i> Cancel</button>
                        <?php endif; ?>
                        <?php if (in_array((string) $application['inspection_status'], ['completed', 'passed', 'failed', 'for_further_evaluation'], true)): ?>
                            <button class="btn btn-outline-secondary inspection-schedule-button" type="button" data-bs-toggle="modal" data-bs-target="#inspectionScheduleModal" data-action="follow_up"><i class="bi bi-arrow-repeat"></i> Schedule follow-up</button>
                        <?php endif; ?>
                    </div>
                    <?php if (in_array((string) $application['inspection_status'], ['required', 'cancelled'], true) && (string) $application['document_status'] !== 'verified'): ?><div class="alert alert-warning"><i class="bi bi-file-earmark-lock me-1"></i>Scheduling remains locked until all mandatory original hardcopy and wet-ink requirements are verified.</div><?php endif; ?>
                <?php endif; ?>

                <?php if ($inspections === []): ?>
                    <div class="text-center py-4"><i class="bi bi-geo-alt fs-2 text-secondary"></i><p class="text-secondary mt-2 mb-0">No inspection assessment has been recorded.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Status / schedule</th><th>Assignment &amp; location</th><th>Findings</th><th>Personnel</th><th>Evidence</th></tr></thead>
                            <tbody>
                                <?php foreach ($inspections as $inspection): ?>
                                    <?php $inspectionId = (int) $inspection['id']; $verifiedTrees = $inspectionTreeVerifications[$inspectionId] ?? []; $photos = $inspectionPhotos[$inspectionId] ?? []; ?>
                                    <tr>
                                        <td><span class="badge <?php echo e(permit_inspection_status_badge((string) $inspection['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $inspection['inspection_status'])); ?></span><?php if ($inspection['scheduled_at'] !== null): ?><div class="small mt-2"><strong>Scheduled:</strong><br><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['scheduled_at']))); ?></div><?php endif; ?><?php if ($inspection['inspected_at'] !== null): ?><div class="small"><strong>Inspected:</strong><br><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['inspected_at']))); ?></div><?php endif; ?><?php if ($inspection['follow_up_of_inspection_id'] !== null): ?><div class="small text-secondary mt-1">Follow-up to event #<?php echo (int) $inspection['follow_up_of_inspection_id']; ?></div><?php endif; ?></td>
                                        <td class="small"><div><strong>Assigned:</strong> <?php echo e((string) ($inspection['inspector_name'] ?? 'Not assigned')); ?></div><?php if ($inspection['inspection_location'] !== null): ?><div class="text-break"><strong>Location:</strong> <?php echo e((string) $inspection['inspection_location']); ?></div><?php endif; ?><?php if ($inspection['latitude'] !== null && $inspection['longitude'] !== null): ?><div><strong>Coordinates:</strong> <?php echo e((string) $inspection['latitude']); ?>, <?php echo e((string) $inspection['longitude']); ?></div><?php endif; ?></td>
                                        <td class="small text-break"><?php if ($inspection['findings'] !== null): ?><div><strong>Findings:</strong> <?php echo e((string) $inspection['findings']); ?></div><div><strong>Recommendation:</strong> <?php echo e((string) $inspection['recommendation']); ?></div><div>Property/location: <?php echo (int) $inspection['property_location_confirmed'] === 1 ? 'Confirmed' : 'Not confirmed'; ?> &middot; Ownership/authorization: <?php echo (int) $inspection['ownership_authorization_confirmed'] === 1 ? 'Confirmed' : 'Not confirmed'; ?></div><?php else: ?>-<?php endif; ?><?php if ($inspection['inspection_notes'] !== null): ?><div class="mt-1"><strong>Notes:</strong> <?php echo e((string) $inspection['inspection_notes']); ?></div><?php endif; ?></td>
                                        <td class="small"><div>Recorded by <?php echo e((string) $inspection['creator_name']); ?></div><?php if ($inspection['completer_name'] !== null): ?><div>Completed by <?php echo e((string) $inspection['completer_name']); ?></div><?php endif; ?><div class="text-secondary"><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['created_at']))); ?></div></td>
                                        <td class="small"><?php if ($verifiedTrees !== []): ?><details><summary><?php echo count($verifiedTrees); ?> verified tree record<?php echo count($verifiedTrees) === 1 ? '' : 's'; ?></summary><ul class="ps-3 mt-2 mb-0"><?php foreach ($verifiedTrees as $verifiedTree): ?><li><?php echo e((string) $verifiedTree['verified_common_name']); ?> &times; <?php echo (int) $verifiedTree['verified_quantity']; ?> &mdash; species <?php echo (int) $verifiedTree['species_confirmed'] === 1 ? 'confirmed' : 'not confirmed'; ?>, count <?php echo (int) $verifiedTree['quantity_confirmed'] === 1 ? 'confirmed' : 'not confirmed'; ?></li><?php endforeach; ?></ul></details><?php endif; ?><?php if ($photos !== []): ?><div class="d-flex flex-wrap gap-1 mt-2"><?php foreach ($photos as $photo): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="permit-inspection-photo.php?id=<?php echo (int) $photo['id']; ?>"><i class="bi bi-image"></i> <?php echo e((string) $photo['original_filename']); ?></a><?php endforeach; ?></div><?php elseif ((int) $inspection['photo_count'] > 0): ?><div><?php echo (int) $inspection['photo_count']; ?> protected photo<?php echo (int) $inspection['photo_count'] === 1 ? '' : 's'; ?></div><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($canViewDocuments): ?>
            <section class="docket-panel page-anchor-section" id="documents" aria-labelledby="documents-heading">
                <div class="section-heading"><h2 id="documents-heading">Permit Document Review</h2><span class="section-note">Scans and originals remain separate</span></div>
                <div class="alert alert-warning" role="note"><i class="bi bi-exclamation-triangle me-1"></i>Accepting the scan doesn't verify the original hardcopy or wet-ink signature. Original-document decisions below are separate and never approve the application.</div>
                <?php if ($reviewLockReason !== null): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i><?php echo e($reviewLockReason); ?> Downloads and history stay available.</div><?php endif; ?>
                <div class="row g-3 mb-4">
                    <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required digital scans accepted</span><span class="fw-semibold"><?php echo $acceptedCount; ?> of <?php echo $requiredCount; ?></span></div><div class="progress" role="progressbar" aria-label="Required online scans" aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo $progress; ?>%"><?php echo $progress; ?>%</div></div></div></div>
                    <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required originals verified</span><span class="fw-semibold"><?php echo (int) $originalProgress['verified']; ?> of <?php echo (int) $originalProgress['required']; ?></span></div><div class="progress" role="progressbar" aria-label="Required original documents" aria-valuenow="<?php echo (int) $originalProgress['percent']; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo (int) $originalProgress['percent']; ?>%"><?php echo (int) $originalProgress['percent']; ?>%</div></div></div></div>
                    <div class="col-12"><div class="form-text">The document checklist remains provisional pending confirmation of official RPS requirements.</div></div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Document type</th><th>Current online scan</th><th>Original hardcopy verification</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($catalog as $type => $definition): ?>
                                <?php
                                $document = $currentDocuments[$type] ?? null;
                                $original = $latestOriginalReviews[$type] ?? null;
                                $originalMatchesCurrent = permit_original_review_matches_document($original, $document);
                                ?>
                                <tr>
                                    <td><div class="fw-semibold"><?php echo e((string) $definition['label']); ?></div><small class="text-secondary"><?php echo !empty($definition['required']) ? 'Required' : 'Optional'; ?></small></td>
                                    <td>
                                        <?php if ($document === null): ?>
                                            <span class="text-secondary">No current scan uploaded.</span>
                                        <?php else: ?>
                                            <div class="text-break fw-semibold"><?php echo e((string) $document['original_filename']); ?></div>
                                            <small class="text-secondary"><?php echo e(number_format((int) $document['file_size_bytes'] / 1024, 1)); ?> KB &middot; <?php echo e((string) $document['uploader_name']); ?></small>
                                            <div class="mt-1"><span class="badge <?php echo e(permit_document_status_badge((string) $document['verification_status'])); ?>"><?php echo e(permit_document_status_label((string) $document['verification_status'])); ?></span></div>
                                            <?php if (!empty($document['verification_notes'])): ?><div class="small mt-1 text-break"><?php echo e((string) $document['verification_notes']); ?></div><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($original === null): ?>
                                            <span class="text-secondary">No original verification recorded.</span>
                                        <?php else: ?>
                                            <span class="badge <?php echo e(permit_original_review_status_badge((string) $original['review_status'])); ?>"><?php echo e(permit_original_review_status_label((string) $original['review_status'])); ?></span>
                                            <?php if (!$originalMatchesCurrent && $document !== null): ?><div class="small text-warning-emphasis mt-1"><i class="bi bi-arrow-repeat"></i> Not recorded against the current scan; re-verification required.</div><?php endif; ?>
                                            <div class="small mt-2">Received: <strong><?php echo (int) $original['original_received'] === 1 ? 'Yes' : 'No'; ?></strong><?php if ((int) $original['original_received'] === 1): ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $original['original_received_on']))); ?> by <?php echo e((string) $original['receiver_name']); ?><?php endif; ?></div>
                                            <div class="small">Wet-ink: <?php echo (int) $original['wet_ink_required'] === 1 ? ((int) $original['wet_ink_verified'] === 1 ? 'Required and verified' : 'Required, not verified') : 'Not required'; ?></div>
                                            <div class="small">Scan compared: <?php echo (int) $original['scan_compared_with_original'] === 1 ? 'Yes' : 'No'; ?></div>
                                            <div class="small text-secondary">Verified by <?php echo e((string) $original['verifier_name']); ?> &middot; <?php echo e(date('M j, Y g:i A', strtotime((string) $original['reviewed_at']))); ?></div>
                                            <?php if (!empty($original['review_notes'])): ?><div class="small mt-1 text-break"><strong>Remarks:</strong> <?php echo e((string) $original['review_notes']); ?></div><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex flex-wrap justify-content-end gap-1">
                                            <?php if ($document !== null): ?><a class="btn btn-sm btn-outline-secondary" href="permit-document-download.php?id=<?php echo e((string) $document['id']); ?>"><i class="bi bi-download"></i> Download</a><?php endif; ?>
                                            <?php if ($document !== null && $reviewLockReason === null && $currentRole === 'rps'): ?><button type="button" class="btn btn-sm btn-outline-primary review-document" data-bs-toggle="modal" data-bs-target="#reviewDocumentModal" data-document-id="<?php echo e((string) $document['id']); ?>" data-document-name="<?php echo e((string) $document['original_filename']); ?>" data-current-status="<?php echo e((string) $document['verification_status']); ?>"><i class="bi bi-clipboard-check"></i> Online review</button><?php endif; ?>
                                            <?php if ($canReviewOriginals && $reviewLockReason === null): ?><button type="button" class="btn btn-sm btn-certreefy verify-original" data-bs-toggle="modal" data-bs-target="#verifyOriginalModal" data-document-type="<?php echo e($type); ?>" data-document-id="<?php echo e((string) ($document['id'] ?? '')); ?>" data-document-label="<?php echo e((string) $definition['label']); ?>" data-original-received="<?php echo $original !== null ? (int) $original['original_received'] : 0; ?>" data-received-on="<?php echo e((string) ($original['original_received_on'] ?? '')); ?>" data-received-by="<?php echo e((string) ($original['received_by_user_id'] ?? $userId)); ?>" data-wet-ink-required="<?php echo $original !== null ? (int) $original['wet_ink_required'] : 1; ?>" data-wet-ink-verified="<?php echo $original !== null ? (int) $original['wet_ink_verified'] : 0; ?>" data-scan-compared="<?php echo $original !== null ? (int) $original['scan_compared_with_original'] : 0; ?>" data-review-status="<?php echo e((string) ($original['review_status'] ?? 'pending')); ?>" data-review-notes="<?php echo e((string) ($original['review_notes'] ?? '')); ?>"><i class="bi bi-file-earmark-check"></i> Verify original</button><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $history = array_values(array_filter($documents, static fn (array $document): bool => (int) $document['is_current'] !== 1)); ?>
                <?php if ($history !== []): ?><h3 class="h5 mt-4">Scan Replacement History</h3><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Type</th><th>Filename</th><th>Archived status</th><th>Uploaded</th><th class="text-end">File</th></tr></thead><tbody><?php foreach ($history as $document): ?><?php $definition = permit_document_type((string) $document['document_type']); ?><tr><td><?php echo e((string) ($definition['label'] ?? $document['document_type'])); ?></td><td class="text-break"><?php echo e((string) $document['original_filename']); ?></td><td><span class="badge text-bg-secondary"><?php echo e(permit_document_status_label((string) $document['verification_status'], false)); ?></span></td><td><?php echo e(date('M j, Y g:i A', strtotime((string) $document['created_at']))); ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="permit-document-download.php?id=<?php echo e((string) $document['id']); ?>"><i class="bi bi-download"></i> Download</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

                <?php if ($originalReviews !== []): ?>
                    <h3 class="h5 mt-4">Original Verification History</h3>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Document</th><th>Result</th><th>Receipt / wet-ink / comparison</th><th>Personnel</th><th>Verification date</th><th>Remarks</th></tr></thead><tbody>
                        <?php foreach ($originalReviews as $review): ?><?php $historyDefinition = permit_document_type((string) $review['document_type']); ?><tr><td><?php echo e((string) ($historyDefinition['label'] ?? $review['document_type'])); ?><?php if (!empty($review['compared_scan_filename'])): ?><div class="small text-secondary text-break">Scan: <?php echo e((string) $review['compared_scan_filename']); ?></div><?php endif; ?></td><td><span class="badge <?php echo e(permit_original_review_status_badge((string) $review['review_status'])); ?>"><?php echo e(permit_original_review_status_label((string) $review['review_status'])); ?></span></td><td class="small">Received: <?php echo (int) $review['original_received'] === 1 ? 'Yes &middot; ' . e(date('M j, Y', strtotime((string) $review['original_received_on']))) : 'No'; ?><br>Wet-ink: <?php echo (int) $review['wet_ink_required'] === 1 ? ((int) $review['wet_ink_verified'] === 1 ? 'Verified' : 'Not verified') : 'Not required'; ?><br>Compared: <?php echo (int) $review['scan_compared_with_original'] === 1 ? 'Yes' : 'No'; ?></td><td class="small">Receiver: <?php echo e((string) ($review['receiver_name'] ?? '-')); ?><br>Verifier: <?php echo e((string) $review['verifier_name']); ?></td><td><?php echo e(date('M j, Y g:i A', strtotime((string) $review['reviewed_at']))); ?></td><td class="text-break"><?php echo e((string) ($review['review_notes'] ?? '-')); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
                <a href="permit-applications.php" class="btn btn-outline-secondary mt-4"><i class="bi bi-arrow-left"></i> Back to applications</a>
            </section>
            <?php endif; ?>
            <?php if (!$canViewDocuments): ?><a href="permit-applications.php" class="btn btn-outline-secondary mt-4"><i class="bi bi-arrow-left"></i> Back to applications</a><?php endif; ?>
        </main>
    </div>

    <?php if ($canDecideApplications && $decisionLockReason === null && (string) $application['decision_status'] === 'under_review'): ?>
    <div class="modal fade" id="returnCorrectionModal" tabindex="-1" aria-labelledby="returnCorrectionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-decision-action.php">
            <div class="modal-header"><div><div class="eyebrow">RPS review</div><h2 class="modal-title fs-5" id="returnCorrectionModalLabel">Return for correction</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_decision_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_decision_id" value="<?php echo (int) ($latestDecision['id'] ?? 0); ?>"><input type="hidden" name="action" value="return_for_correction"><label class="form-label" for="correctionRemarks">Required corrections and remarks</label><textarea class="form-control" id="correctionRemarks" name="decision_notes" rows="4" maxlength="1000" required></textarea><div class="form-text">The applicant will be notified and the application will move to additional requirements requested.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Return application</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="requirementsRequestModal" tabindex="-1" aria-labelledby="requirementsRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-decision-action.php">
            <div class="modal-header"><div><div class="eyebrow">RPS review</div><h2 class="modal-title fs-5" id="requirementsRequestModalLabel">Request additional requirements</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_decision_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_decision_id" value="<?php echo (int) ($latestDecision['id'] ?? 0); ?>"><input type="hidden" name="action" value="request_requirements"><label class="form-label" for="requirementRemarks">Requirements and remarks</label><textarea class="form-control" id="requirementRemarks" name="decision_notes" rows="4" maxlength="1000" required></textarea><div class="form-text">List the exact requirement the applicant must address. The applicant will be notified.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Send requirement request</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="approvePermitModal" tabindex="-1" aria-labelledby="approvePermitModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-decision-action.php">
            <div class="modal-header"><div><div class="eyebrow">RPS decision</div><h2 class="modal-title fs-5" id="approvePermitModalLabel">Approve application</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_decision_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_decision_id" value="<?php echo (int) ($latestDecision['id'] ?? 0); ?>"><input type="hidden" name="action" value="approve">
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Confirm the evidence and exercise authorized RPS judgment before approval. This action creates a donation requirement but does not release a permit.</div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label" for="approvedTreeCount">Approved number of trees</label><input class="form-control" id="approvedTreeCount" type="number" name="approved_tree_count" min="1" max="<?php echo (int) $decisionReadiness['approved_tree_limit']; ?>" value="<?php echo (int) $decisionReadiness['approved_tree_limit']; ?>" required><div class="form-text">Server limit: <?php echo (int) $decisionReadiness['approved_tree_limit']; ?></div></div>
                    <div class="col-md-4"><label class="form-label">Property classification</label><input class="form-control" value="<?php echo e(permit_status_label((string) $application['property_classification'])); ?>" readonly></div>
                    <div class="col-md-4"><label class="form-label">Configured donation requirement</label><input class="form-control" value="<?php echo $donationPolicy !== null ? (int) $donationPolicy['count'] . ' seedlings' : 'Not configured'; ?>" readonly><div class="form-text">Calculated on the server; office policy remains configurable.</div></div>
                    <div class="col-12"><label class="form-label" for="approvalRemarks">Decision remarks</label><textarea class="form-control" id="approvalRemarks" name="decision_notes" rows="3" maxlength="1000" required></textarea></div>
                    <div class="col-12"><label class="form-label" for="approvalConditions">Approval conditions</label><textarea class="form-control" id="approvalConditions" name="decision_conditions" rows="3" maxlength="2000"></textarea><div class="form-text">Optional conditions recorded with this approval decision.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy" <?php echo $decisionReadiness['ready'] ? '' : 'disabled'; ?>>Record approval</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="declinePermitModal" tabindex="-1" aria-labelledby="declinePermitModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-decision-action.php">
            <div class="modal-header"><div><div class="eyebrow">RPS decision</div><h2 class="modal-title fs-5" id="declinePermitModalLabel">Decline application</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_decision_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_decision_id" value="<?php echo (int) ($latestDecision['id'] ?? 0); ?>"><input type="hidden" name="action" value="decline"><div class="alert alert-danger"><i class="bi bi-lock me-1"></i>A declined application is locked from inappropriate further processing. All records and history are preserved.</div><label class="form-label" for="declineRemarks">Decline reason</label><textarea class="form-control" id="declineRemarks" name="decision_notes" rows="4" maxlength="1000" required></textarea></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Record decline</button></div>
        </form></div></div>
    </div>
    <?php endif; ?>

    <?php if ($canManageInspections): ?>
    <div class="modal fade" id="inspectionScheduleModal" tabindex="-1" aria-labelledby="inspectionScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-inspection-action.php">
            <div class="modal-header"><div><div class="eyebrow">Site inspection</div><h2 class="modal-title fs-5" id="inspectionScheduleModalLabel">Schedule inspection</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" id="inspectionScheduleAction" value="schedule">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="inspectionScheduledAt">Inspection date and time</label><input class="form-control" id="inspectionScheduledAt" type="datetime-local" name="scheduled_at" min="<?php echo e(date('Y-m-d\\TH:i')); ?>" value="<?php echo $latestInspection !== null && $latestInspection['scheduled_at'] !== null ? e(date('Y-m-d\\TH:i', strtotime((string) $latestInspection['scheduled_at']))) : ''; ?>" required></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionAssignee">Assigned RPS or authorized personnel</label><select class="form-select" id="inspectionAssignee" name="inspector_user_id" required><option value="">Select assignee</option><?php foreach ($inspectionPersonnel as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo (int) ($latestInspection['inspector_user_id'] ?? $userId) === (int) $person['id'] ? 'selected' : ''; ?>><?php echo e(trim((string) $person['fname'] . ' ' . (string) $person['lname'])); ?> (<?php echo e(strtoupper((string) $person['role'])); ?>)</option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label" for="inspectionLocation">Inspection location</label><textarea class="form-control" id="inspectionLocation" name="inspection_location" rows="2" maxlength="500" required><?php echo e((string) ($latestInspection['inspection_location'] ?? $defaultInspectionLocation)); ?></textarea></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionLatitude">Latitude</label><input class="form-control" id="inspectionLatitude" type="number" name="latitude" min="-90" max="90" step="0.0000001" value="<?php echo e((string) ($latestInspection['latitude'] ?? $application['latitude'] ?? '')); ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionLongitude">Longitude</label><input class="form-control" id="inspectionLongitude" type="number" name="longitude" min="-180" max="180" step="0.0000001" value="<?php echo e((string) ($latestInspection['longitude'] ?? $application['longitude'] ?? '')); ?>"></div>
                    <div class="col-12"><div class="small text-secondary mb-1">Pin the inspection meeting point (optional) — click the map or drag the pin.</div><div id="inspectionPickerMap" class="geo-map geo-map-compact"></div></div>
                    <div class="col-12"><label class="form-label" for="inspectionScheduleNotes">Schedule notes</label><textarea class="form-control" id="inspectionScheduleNotes" name="inspection_notes" rows="3" maxlength="1000"></textarea><div class="form-text">The applicant is notified after the schedule is recorded.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Save schedule</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="inspectionCancelModal" tabindex="-1" aria-labelledby="inspectionCancelModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-inspection-action.php">
            <div class="modal-header"><div><div class="eyebrow">Site inspection</div><h2 class="modal-title fs-5" id="inspectionCancelModalLabel">Cancel inspection</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" value="cancel"><label class="form-label" for="inspectionCancellationReason">Cancellation reason</label><textarea class="form-control" id="inspectionCancellationReason" name="inspection_notes" rows="4" maxlength="1000" required></textarea></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep inspection</button><button type="submit" class="btn btn-outline-danger">Record cancellation</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="inspectionCompleteModal" tabindex="-1" aria-labelledby="inspectionCompleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form method="post" action="permit-inspection-action.php" enctype="multipart/form-data">
            <div class="modal-header"><div><div class="eyebrow">Site and tree verification</div><h2 class="modal-title fs-5" id="inspectionCompleteModalLabel">Record inspection findings</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="expected_inspection_id" value="<?php echo (int) ($latestInspection['id'] ?? 0); ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="MAX_FILE_SIZE" value="<?php echo permit_inspection_photo_max_bytes(); ?>">
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Record the observed result. This action does not approve or decline the permit.</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label" for="inspectionActualAt">Actual inspection date and time</label><input class="form-control" id="inspectionActualAt" type="datetime-local" name="inspected_at" max="<?php echo e(date('Y-m-d\\TH:i')); ?>" value="<?php echo e(date('Y-m-d\\TH:i')); ?>" required></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionResult">Verification result</label><select class="form-select" id="inspectionResult" name="verification_result" required><option value="passed">Passed</option><option value="failed">Failed</option><option value="for_further_evaluation">For further evaluation</option></select></div>
                    <div class="col-md-6"><label class="form-label">Property or location confirmed</label><select class="form-select" name="property_location_confirmed" required><option value="1">Yes</option><option value="0">No</option></select></div>
                    <div class="col-md-6"><label class="form-label">Ownership or authorization confirmed</label><select class="form-select" name="ownership_authorization_confirmed" required><option value="1">Yes</option><option value="0">No</option></select></div>
                </div>
                <h3 class="h5">Tree Verification</h3>
                <div class="vstack gap-3 mb-4">
                    <?php foreach ($trees as $tree): ?>
                        <?php $treeId = (int) $tree['id']; $measurementsRequired = $tree['diameter_cm'] !== null || $tree['estimated_height_m'] !== null; ?>
                        <article class="card border-0 bg-light"><div class="card-body"><div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3"><div><h4 class="h6 mb-1"><?php echo e((string) $tree['common_name']); ?></h4><div class="small text-secondary">Applied count: <?php echo (int) $tree['quantity']; ?><?php if ($tree['diameter_cm'] !== null): ?> &middot; Diameter: <?php echo e((string) $tree['diameter_cm']); ?> cm<?php endif; ?><?php if ($tree['estimated_height_m'] !== null): ?> &middot; Height: <?php echo e((string) $tree['estimated_height_m']); ?> m<?php endif; ?></div></div><span class="badge text-bg-light border">Tree record #<?php echo $treeId; ?></span></div><div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Species confirmed</label><select class="form-select" name="trees[<?php echo $treeId; ?>][species_confirmed]" required><option value="1">Yes</option><option value="0">No</option></select></div>
                            <div class="col-md-6"><label class="form-label">Tree count confirmed</label><select class="form-select" name="trees[<?php echo $treeId; ?>][quantity_confirmed]" required><option value="1">Yes</option><option value="0">No</option></select></div>
                            <div class="col-md-6"><label class="form-label">Verified common name</label><input class="form-control" name="trees[<?php echo $treeId; ?>][verified_common_name]" maxlength="150" value="<?php echo e((string) $tree['common_name']); ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Verified scientific name</label><input class="form-control" name="trees[<?php echo $treeId; ?>][verified_scientific_name]" maxlength="150" value="<?php echo e((string) ($tree['scientific_name'] ?? '')); ?>"></div>
                            <div class="col-md-4"><label class="form-label">Verified count</label><input class="form-control" type="number" name="trees[<?php echo $treeId; ?>][verified_quantity]" min="1" max="65535" step="1" value="<?php echo (int) $tree['quantity']; ?>" required></div>
                            <div class="col-md-4"><label class="form-label">Verified diameter (cm)</label><input class="form-control" type="number" name="trees[<?php echo $treeId; ?>][verified_diameter_cm]" min="0.01" max="999999.99" step="0.01" value="<?php echo e((string) ($tree['diameter_cm'] ?? '')); ?>" <?php echo $tree['diameter_cm'] !== null ? 'required' : ''; ?>></div>
                            <div class="col-md-4"><label class="form-label">Verified height (m)</label><input class="form-control" type="number" name="trees[<?php echo $treeId; ?>][verified_height_m]" min="0.01" max="999999.99" step="0.01" value="<?php echo e((string) ($tree['estimated_height_m'] ?? '')); ?>" <?php echo $tree['estimated_height_m'] !== null ? 'required' : ''; ?>></div>
                            <?php if ($measurementsRequired): ?><div class="col-md-6"><label class="form-label">Required measurements confirmed</label><select class="form-select" name="trees[<?php echo $treeId; ?>][measurements_confirmed]" required><option value="1">Yes</option><option value="0">No</option></select></div><?php endif; ?>
                            <div class="<?php echo $measurementsRequired ? 'col-md-6' : 'col-12'; ?>"><label class="form-label">Tree verification notes</label><input class="form-control" name="trees[<?php echo $treeId; ?>][measurement_notes]" maxlength="500"></div>
                        </div></div></article>
                    <?php endforeach; ?>
                </div>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label" for="inspectionFindings">Inspection findings</label><textarea class="form-control" id="inspectionFindings" name="findings" rows="5" maxlength="10000" required></textarea></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionRecommendation">Recommendation</label><input class="form-control" id="inspectionRecommendation" name="recommendation" maxlength="100" required><div class="form-text">Use the applicable RPS office wording; the recommendation remains separate from the decision.</div></div>
                    <div class="col-md-6"><label class="form-label" for="inspectionPhotos">Site photographs</label><input class="form-control" id="inspectionPhotos" type="file" name="site_photos[]" accept=".jpg,.jpeg,.png" multiple><div class="form-text">JPG, JPEG, or PNG; up to 10 files, <?php echo e(permit_inspection_photo_max_size_label()); ?> each. Photos are kept in protected private storage.</div></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="inspectionFollowUpRequired" name="follow_up_required" value="1"><label class="form-check-label" for="inspectionFollowUpRequired">A follow-up inspection is required</label></div></div>
                    <div class="col-12"><label class="form-label" for="inspectionCompletionNotes">Additional inspection notes</label><textarea class="form-control" id="inspectionCompletionNotes" name="inspection_notes" rows="3" maxlength="1000"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Complete inspection</button></div>
        </form></div></div>
    </div>
    <?php endif; ?>

    <?php if ($canReviewOriginals): ?>
    <div class="modal fade" id="reviewDocumentModal" tabindex="-1" aria-labelledby="reviewDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-document-review.php">
            <div class="modal-header"><div><div class="eyebrow">Online scan review</div><h2 class="modal-title fs-5" id="reviewDocumentModalLabel">Review document</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_document_review_token']); ?>"><input type="hidden" name="document_id" id="reviewDocumentId">
                <p class="small text-secondary text-break" id="reviewDocumentName"></p>
                <div class="mb-3"><label for="reviewStatus" class="form-label">Online review result</label><select class="form-select" id="reviewStatus" name="review_status" required><option value="accepted">Accepted online scan</option><option value="replacement_required">Replacement required</option><option value="rejected">Rejected</option></select></div>
                <div><label for="reviewNotes" class="form-label">Review notes</label><textarea class="form-control" id="reviewNotes" name="review_notes" rows="4" maxlength="1000"></textarea><div class="form-text">Notes are required for rejection or replacement requests.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Save online review</button></div>
        </form></div></div>
    </div>

    <div class="modal fade" id="verifyOriginalModal" tabindex="-1" aria-labelledby="verifyOriginalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form method="post" action="permit-original-document-review.php">
            <div class="modal-header"><div><div class="eyebrow">Original hardcopy verification</div><h2 class="modal-title fs-5" id="verifyOriginalModalLabel">Verify original document</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_original_review_token']); ?>">
                <input type="hidden" name="application_id" value="<?php echo e((string) $applicationId); ?>">
                <input type="hidden" name="document_type" id="originalDocumentType">
                <input type="hidden" name="expected_document_id" id="originalExpectedDocumentId">
                <p class="fw-semibold" id="originalDocumentLabel"></p>
                <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>Records a separate original-document decision; time and verifier are logged automatically.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label for="originalReceived" class="form-label">Original hardcopy received</label><select class="form-select" id="originalReceived" name="original_received" required><option value="1">Yes</option><option value="0">No</option></select></div>
                    <div class="col-md-6"><label for="originalReceivedOn" class="form-label">Date received</label><input class="form-control" type="date" id="originalReceivedOn" name="original_received_on" max="<?php echo e(date('Y-m-d')); ?>"></div>
                    <div class="col-12"><label for="originalReceivedBy" class="form-label">Receiving personnel</label><select class="form-select" id="originalReceivedBy" name="received_by_user_id"><option value="">Select personnel</option><?php foreach ($receivingPersonnel as $person): ?><option value="<?php echo e((string) $person['id']); ?>"><?php echo e(trim((string) $person['fname'] . ' ' . (string) $person['lname'])); ?> (<?php echo e(strtoupper((string) $person['role'])); ?>)</option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label for="wetInkRequired" class="form-label">Wet-ink signature required</label><select class="form-select" id="wetInkRequired" name="wet_ink_required" required><option value="1">Yes</option><option value="0">No</option></select></div>
                    <div class="col-md-6"><label for="wetInkVerified" class="form-label">Wet-ink signature verified</label><select class="form-select" id="wetInkVerified" name="wet_ink_verified" required><option value="1">Yes</option><option value="0">No</option></select></div>
                    <div class="col-md-6"><label for="scanCompared" class="form-label">Scan compared with original</label><select class="form-select" id="scanCompared" name="scan_compared_with_original" required><option value="1">Yes</option><option value="0">No</option></select></div>
                    <div class="col-md-6"><label for="originalReviewStatus" class="form-label">Verification result</label><select class="form-select" id="originalReviewStatus" name="review_status" required><option value="pending">Pending verification</option><option value="verified">Verified original</option><option value="replacement_required">Replacement required</option><option value="rejected">Rejected</option></select></div>
                    <div class="col-12"><label for="originalReviewNotes" class="form-label">Remarks</label><textarea class="form-control" id="originalReviewNotes" name="review_notes" rows="4" maxlength="1000"></textarea><div class="form-text">Remarks are required when the original is missing, rejected, requires replacement, or a required wet-ink signature is not verified.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy">Record original verification</button></div>
        </form></div></div>
    </div>
    <?php endif; ?>

    <script type="application/json" id="zoneMapData"><?php echo json_encode($zoneMapFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/geo-map.js"></script>
    <script>
        (() => {
            const zones = CertreefyGeo.readJson('zoneMapData', []);
            <?php if ($application['latitude'] !== null && $application['longitude'] !== null): ?>
            CertreefyGeo.display('propertySiteMap', {
                zones: zones,
                points: [{
                    lat: <?php echo json_encode((string) $application['latitude']); ?>,
                    lng: <?php echo json_encode((string) $application['longitude']); ?>,
                    label: <?php echo json_encode('Application property — ' . (string) $application['transaction_id']); ?>
                }]
            });
            <?php endif; ?>

            // The schedule-modal picker is created on first open so Leaflet
            // sizes itself against a visible container.
            const scheduleModal = document.getElementById('inspectionScheduleModal');
            let inspectionPickerMap = null;
            if (scheduleModal) {
                scheduleModal.addEventListener('shown.bs.modal', () => {
                    if (inspectionPickerMap !== null) {
                        inspectionPickerMap.invalidateSize();
                        return;
                    }
                    inspectionPickerMap = CertreefyGeo.picker('inspectionPickerMap', {
                        latInput: 'inspectionLatitude',
                        lngInput: 'inspectionLongitude',
                        zones: zones
                    });
                });
            }
        })();
    </script>
    <?php if ($canReviewOriginals): ?>
    <script>
        document.querySelectorAll('.review-document').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('reviewDocumentId').value = button.dataset.documentId;
                document.getElementById('reviewDocumentName').textContent = button.dataset.documentName;
                const status = button.dataset.currentStatus;
                document.getElementById('reviewStatus').value = status === 'rejected' ? 'rejected' : (status === 'replacement_required' ? 'replacement_required' : 'accepted');
                document.getElementById('reviewNotes').value = '';
            });
        });

        const originalReceived = document.getElementById('originalReceived');
        const originalReceivedOn = document.getElementById('originalReceivedOn');
        const originalReceivedBy = document.getElementById('originalReceivedBy');
        const wetInkRequired = document.getElementById('wetInkRequired');
        const wetInkVerified = document.getElementById('wetInkVerified');
        const scanCompared = document.getElementById('scanCompared');

        const syncOriginalFields = () => {
            const received = originalReceived.value === '1';
            originalReceivedOn.disabled = !received;
            originalReceivedOn.required = received;
            originalReceivedBy.disabled = !received;
            originalReceivedBy.required = received;
            if (!received) {
                originalReceivedOn.value = '';
                originalReceivedBy.value = '';
                wetInkVerified.value = '0';
                scanCompared.value = '0';
            }
            if (wetInkRequired.value === '0') {
                wetInkVerified.value = '0';
            }
        };
        originalReceived.addEventListener('change', syncOriginalFields);
        wetInkRequired.addEventListener('change', syncOriginalFields);

        document.querySelectorAll('.verify-original').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('originalDocumentType').value = button.dataset.documentType;
                document.getElementById('originalExpectedDocumentId').value = button.dataset.documentId;
                document.getElementById('originalDocumentLabel').textContent = button.dataset.documentLabel;
                originalReceived.value = button.dataset.originalReceived;
                originalReceivedOn.disabled = false;
                originalReceivedOn.value = button.dataset.receivedOn;
                originalReceivedBy.disabled = false;
                originalReceivedBy.value = button.dataset.receivedBy;
                wetInkRequired.value = button.dataset.wetInkRequired;
                wetInkVerified.value = button.dataset.wetInkVerified;
                scanCompared.value = button.dataset.scanCompared;
                document.getElementById('originalReviewStatus').value = button.dataset.reviewStatus;
                document.getElementById('originalReviewNotes').value = button.dataset.reviewNotes;
                syncOriginalFields();
            });
        });
    </script>
    <?php endif; ?>
    <?php if ($canManageInspections): ?>
    <script>
        document.querySelectorAll('.inspection-schedule-button').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.action || 'schedule';
                document.getElementById('inspectionScheduleAction').value = action;
                document.getElementById('inspectionScheduleModalLabel').textContent = action === 'reschedule'
                    ? 'Reschedule inspection'
                    : (action === 'follow_up' ? 'Schedule follow-up inspection' : 'Schedule inspection');
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
