<?php
/** EMS registry plus seedling donation receipt and verification workflow. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit_donation_receipts.php';
require_once __DIR__ . '/../../includes/permit_donation_view.php';
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
    'application_reference' => permit_donation_scalar_value($_GET['application_reference'] ?? ''),
    'donation_status' => permit_donation_scalar_value($_GET['donation_status'] ?? ''),
    'date_from' => permit_donation_scalar_value($_GET['date_from'] ?? ''),
    'date_to' => permit_donation_scalar_value($_GET['date_to'] ?? ''),
];
$applicationValue = permit_donation_scalar_value($_GET['application_id'] ?? '');
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$editValue = permit_donation_scalar_value($_GET['edit_receipt'] ?? '');
$editReceiptId = ctype_digit($editValue) ? (int) $editValue : 0;
$requirements = [];
$selectedRequirement = null;
$receipts = [];
$editReceipt = null;
$emsUsers = [];
$loadError = null;

try {
    $requirements = permit_list_donation_requirements_for_ems($pdo, $userId, $filters);
    $emsUsers = permit_donation_active_ems_users($pdo, $userId);
    if ($applicationId > 0) {
        $selectedRequirement = permit_donation_requirement_for_actor(
            $pdo,
            $applicationId,
            $userId
        );
        if ($selectedRequirement === null) {
            http_response_code(404);
            $loadError = 'The selected donation requirement was not found.';
        } else {
            $receipts = permit_list_donation_receipts_for_ems($pdo, $applicationId, $userId);
            if ($editReceiptId > 0) {
                $editReceipt = permit_donation_receipt_for_edit(
                    $pdo,
                    $applicationId,
                    $editReceiptId,
                    $userId
                );
                if ($editReceipt === null) {
                    $loadError = 'Only a current unfinalized receipt may be corrected.';
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log('[CERTREEFY EMS DONATION REQUIREMENTS ERROR] ' . $e->getMessage());
    http_response_code(500);
    $loadError = 'Unable to load seedling donation requirements at this time.';
}

$flash = $_SESSION['permit_donation_receipt_flash'] ?? null;
unset($_SESSION['permit_donation_receipt_flash']);
$oldInput = $_SESSION['permit_donation_receipt_old_input'] ?? [];
unset($_SESSION['permit_donation_receipt_old_input']);
if (!is_array($oldInput)
    || (int) ($oldInput['application_id'] ?? 0) !== $applicationId) {
    $oldInput = [];
}
if (empty($_SESSION['csrf_permit_donation_receipt_token'])) {
    $_SESSION['csrf_permit_donation_receipt_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['permit_donation_receipt_action_key'])) {
    $_SESSION['permit_donation_receipt_action_key'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['permit_donation_flag_action_key'])) {
    $_SESSION['permit_donation_flag_action_key'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_permit_donation_receipt_token'];
$receiptActionKey = (string) $_SESSION['permit_donation_receipt_action_key'];
$flagActionKey = (string) $_SESSION['permit_donation_flag_action_key'];
$hasFilters = implode('', $filters) !== '';
$donationStatuses = array_values(array_filter(
    permit_workflow_statuses()['donation'],
    static fn (string $status): bool => $status !== 'not_required'
));

$receiptValues = [
    'receipt_reference' => '',
    'received_on' => date('Y-m-d'),
    'received_by_user_id' => (string) $userId,
    'verification_notes' => '',
    'seedling_type' => [''],
    'quantity_received' => [''],
    'expected_verification_id' => '',
];
if ($editReceipt !== null) {
    $receiptValues = [
        'receipt_reference' => (string) $editReceipt['receipt_reference'],
        'received_on' => date('Y-m-d', strtotime((string) $editReceipt['received_at'])),
        'received_by_user_id' => (string) $editReceipt['received_by_user_id'],
        'verification_notes' => (string) ($editReceipt['verification_notes'] ?? ''),
        'seedling_type' => array_column($editReceipt['items'], 'seedling_type'),
        'quantity_received' => array_map('strval', array_column($editReceipt['items'], 'quantity_received')),
        'expected_verification_id' => (string) $editReceipt['id'],
    ];
}
if ($oldInput !== []
    && permit_donation_scalar_value($oldInput['action'] ?? '') !== 'flag_invalid') {
    foreach (array_keys($receiptValues) as $field) {
        if (array_key_exists($field, $oldInput)) {
            $receiptValues[$field] = in_array($field, ['seedling_type', 'quantity_received'], true)
                ? $oldInput[$field]
                : permit_donation_scalar_value($oldInput[$field]);
        }
    }
}
if (!is_array($receiptValues['seedling_type'])
    || !is_array($receiptValues['quantity_received'])
    || count($receiptValues['seedling_type']) !== count($receiptValues['quantity_received'])
    || $receiptValues['seedling_type'] === []) {
    $receiptValues['seedling_type'] = [''];
    $receiptValues['quantity_received'] = [''];
}
$receiptValues['seedling_type'] = array_map(
    'permit_donation_scalar_value',
    $receiptValues['seedling_type']
);
$receiptValues['quantity_received'] = array_map(
    'permit_donation_scalar_value',
    $receiptValues['quantity_received']
);
$flagRemarks = $oldInput !== []
    && permit_donation_scalar_value($oldInput['action'] ?? '') === 'flag_invalid'
    ? permit_donation_scalar_value($oldInput['verification_notes'] ?? '')
    : '';
$receiptWorkflowOpen = $selectedRequirement !== null
    && (string) $selectedRequirement['decision_status'] === 'approved'
    && (string) $selectedRequirement['application_status'] === 'awaiting_donation'
    && !in_array((string) $selectedRequirement['current_status'], [
        'ems_verified', 'rps_verified', 'waived', 'not_required',
    ], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Permit Donations</title>
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
        <?php render_certreefy_navigation($currentRole, 'permit_donations'); ?>
        <main class="main" id="main-content">
            <section class="hero-band mb-4">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div><div class="eyebrow mb-1">Enforcement &amp; Monitoring Section</div><h1 class="page-title">Permit Donation Requirements</h1><p class="mb-0 opacity-75">Locate approved transactions and record physical seedling donation receipts.</p></div>
                    <div class="d-flex align-items-center gap-2"><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>"><button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right me-1"></i> Logout</button></form></div>
                </div>
            </section>

            <div class="alert alert-light border" role="note"><i class="bi bi-info-circle me-1"></i>Transaction ID is the primary EMS reference. A partial or verified donation does not release the permit; final RPS verification remains separate.</div>
            <?php if (is_array($flash)): ?><div class="alert alert-<?php echo e((string) ($flash['type'] ?? 'info')); ?> alert-dismissible fade show" role="alert"><?php echo e((string) ($flash['message'] ?? '')); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
            <?php if ($loadError !== null): ?><div class="alert alert-danger" role="alert"><?php echo e($loadError); ?></div><?php endif; ?>

            <?php if ($selectedRequirement !== null): ?>
                <section class="docket-panel mb-4" id="receipt-workflow" aria-labelledby="receipt-workflow-heading">
                    <div class="section-heading"><div><h2 id="receipt-workflow-heading">Donation Receipt</h2><span class="section-note">Physical receipt and EMS verification</span></div><a class="btn btn-outline-secondary btn-sm" href="donation-requirements.php"><i class="bi bi-arrow-left"></i> Back to registry</a></div>
                    <?php render_permit_donation_requirement($selectedRequirement, true); ?>

                    <?php if ($receiptWorkflowOpen): ?>
                        <div class="row g-4 mt-1">
                            <div class="col-xl-7">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3"><div><h3 class="h5 mb-1"><?php echo $editReceipt !== null ? 'Correct unfinalized receipt' : 'Record receipt'; ?></h3><p class="small text-secondary mb-0">Finalized receipt batches cannot be edited. Corrections create a preserved prior version.</p></div><?php if ($editReceipt !== null): ?><a class="btn btn-sm btn-outline-secondary" href="donation-requirements.php?application_id=<?php echo $applicationId; ?>#receipt-workflow">Cancel correction</a><?php endif; ?></div>
                                    <form method="post" action="donation-receipt-action.php" id="donation-receipt-form" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="action_key" value="<?php echo e($receiptActionKey); ?>">
                                        <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                        <input type="hidden" name="expected_verification_id" value="<?php echo e((string) $receiptValues['expected_verification_id']); ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="form-label" for="receipt_reference">Receipt or acknowledgment reference</label><input class="form-control" id="receipt_reference" name="receipt_reference" maxlength="100" required value="<?php echo e((string) $receiptValues['receipt_reference']); ?>"></div>
                                            <div class="col-md-6"><label class="form-label" for="received_on">Date received</label><input class="form-control" id="received_on" name="received_on" type="date" max="<?php echo e(date('Y-m-d')); ?>" required value="<?php echo e((string) $receiptValues['received_on']); ?>"></div>
                                            <div class="col-12"><label class="form-label" for="received_by_user_id">Receiving personnel</label><select class="form-select" id="received_by_user_id" name="received_by_user_id" required><option value="">Select active EMS personnel</option><?php foreach ($emsUsers as $emsUser): ?><option value="<?php echo (int) $emsUser['id']; ?>" <?php echo (int) $receiptValues['received_by_user_id'] === (int) $emsUser['id'] ? 'selected' : ''; ?>><?php echo e((string) $emsUser['display_name']); ?></option><?php endforeach; ?></select></div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-4 mb-2"><div><h4 class="h6 mb-0">Seedling species or types</h4><div class="small text-secondary">Each row must have a positive whole-number quantity.</div></div><button class="btn btn-sm btn-outline-success" type="button" id="add-seedling-item"><i class="bi bi-plus-lg"></i> Add item</button></div>
                                        <div id="seedling-items" class="d-grid gap-2">
                                            <?php foreach ($receiptValues['seedling_type'] as $index => $seedlingType): ?>
                                                <div class="row g-2 align-items-end seedling-item-row">
                                                    <div class="col-sm-7"><label class="form-label small">Species or type</label><input class="form-control" name="seedling_type[]" maxlength="150" required value="<?php echo e((string) $seedlingType); ?>"></div>
                                                    <div class="col-sm-3"><label class="form-label small">Quantity</label><input class="form-control seedling-quantity" name="quantity_received[]" type="number" min="1" max="1000000" step="1" required value="<?php echo e((string) ($receiptValues['quantity_received'][$index] ?? '')); ?>"></div>
                                                    <div class="col-sm-2"><button class="btn btn-outline-danger w-100 remove-seedling-item" type="button" aria-label="Remove seedling item"><i class="bi bi-trash"></i></button></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="d-flex justify-content-end mt-2"><span class="badge text-bg-light border fs-6">Batch total: <span id="receipt-batch-total">0</span></span></div>
                                        <div class="mt-3"><label class="form-label" for="verification_notes">Verification remarks</label><textarea class="form-control" id="verification_notes" name="verification_notes" rows="3" maxlength="1000"><?php echo e((string) $receiptValues['verification_notes']); ?></textarea></div>
                                        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" value="1" id="confirm_physical_receipt" name="confirm_physical_receipt" <?php echo !empty($oldInput['confirm_physical_receipt']) ? 'checked' : ''; ?>><label class="form-check-label" for="confirm_physical_receipt">I confirm EMS physically received the recorded seedlings.</label></div>
                                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" value="1" id="confirm_overage" name="confirm_overage" <?php echo !empty($oldInput['confirm_overage']) ? 'checked' : ''; ?>><label class="form-check-label" for="confirm_overage">I confirm an over-receipt if this batch makes the cumulative total exceed the requirement.</label></div>
                                        <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-outline-secondary" type="submit" name="action" value="save_draft"><i class="bi bi-floppy"></i> Save unfinalized</button><button class="btn btn-certreefy" type="submit" name="action" value="finalize"><i class="bi bi-check2-circle"></i> Finalize receipt</button><button class="btn btn-outline-danger ms-lg-auto" type="button" data-bs-toggle="modal" data-bs-target="#flagDonationModal"><i class="bi bi-flag"></i> Flag invalid transaction</button></div>
                                    </form>
                                </div>
                            </div>
                            <div class="col-xl-5">
                                <div class="border rounded p-3 h-100"><h3 class="h5">Receipt rules</h3><ul class="small text-secondary mb-0"><li class="mb-2">Saving keeps a receipt unfinalized and editable through append-only correction versions.</li><li class="mb-2">Finalizing confirms physical receipt and permanently counts the batch toward the requirement.</li><li class="mb-2">A partial total remains incomplete and does not make the permit eligible for release.</li><li>A complete EMS total advances only to final RPS verification.</li></ul></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary mt-3 mb-0" role="note"><i class="bi bi-lock me-1"></i>Receipt entry is locked because this transaction is no longer an approved application awaiting EMS donation processing.</div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h5 mb-0">Receipt and verification history</h3><span class="small text-secondary"><?php echo count($receipts); ?> record<?php echo count($receipts) === 1 ? '' : 's'; ?></span></div>
                        <?php if ($receipts === []): ?><div class="border rounded p-4 text-center text-secondary">No EMS receipt has been recorded.</div><?php else: ?>
                            <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Status / version</th><th>Receipt details</th><th>Seedling items</th><th>Personnel</th><th>Remarks</th><th></th></tr></thead><tbody>
                                <?php foreach ($receipts as $receipt): ?><tr>
                                    <td><span class="badge <?php echo e(permit_donation_receipt_status_badge((string) $receipt['verification_status'])); ?>"><?php echo e(permit_status_label((string) $receipt['verification_status'])); ?></span><div class="small text-secondary mt-1">Version <?php echo (int) $receipt['version_number']; ?><?php if ((int) $receipt['is_current'] !== 1): ?> &middot; superseded<?php elseif ((int) $receipt['is_finalized'] === 1): ?> &middot; finalized<?php else: ?> &middot; current<?php endif; ?></div></td>
                                    <td class="small"><div><strong><?php echo $receipt['receipt_reference'] === null ? 'No reference' : e((string) $receipt['receipt_reference']); ?></strong></div><div><?php echo e(date('M j, Y', strtotime((string) $receipt['received_at']))); ?></div><div class="text-secondary">Batch total: <?php echo (int) $receipt['seedlings_received']; ?></div></td>
                                    <td class="small"><?php if ($receipt['items'] === []): ?><span class="text-secondary">No receipt items</span><?php else: ?><?php foreach ($receipt['items'] as $item): ?><div><?php echo e((string) $item['seedling_type']); ?> &middot; <?php echo (int) $item['quantity_received']; ?></div><?php endforeach; ?><?php endif; ?></td>
                                    <td class="small"><div><strong>Received by:</strong> <?php echo e((string) $receipt['received_by_name']); ?></div><div><strong>Recorded by:</strong> <?php echo e((string) $receipt['verified_by_name']); ?></div><div class="text-secondary"><?php echo e(date('M j, Y g:i A', strtotime((string) $receipt['verified_at']))); ?></div></td>
                                    <td class="small text-break"><?php echo $receipt['verification_notes'] === null ? '<span class="text-secondary">None</span>' : e((string) $receipt['verification_notes']); ?></td>
                                    <td><?php if ((int) $receipt['is_current'] === 1 && (int) $receipt['is_finalized'] === 0): ?><a class="btn btn-sm btn-outline-primary" href="donation-requirements.php?application_id=<?php echo $applicationId; ?>&amp;edit_receipt=<?php echo (int) $receipt['id']; ?>#receipt-workflow">Correct</a><?php endif; ?></td>
                                </tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="docket-panel" aria-labelledby="donation-registry-heading">
                <div class="section-heading"><h2 id="donation-registry-heading">Donation Registry</h2><span class="section-note"><?php echo count($requirements); ?> record<?php echo count($requirements) === 1 ? '' : 's'; ?></span></div>
                <form method="get" class="row g-3 align-items-end mb-4">
                    <div class="col-lg-4"><label class="form-label" for="transaction">Transaction ID <span class="text-secondary">(primary reference)</span></label><input class="form-control" id="transaction" name="transaction" maxlength="50" autofocus value="<?php echo e($filters['transaction']); ?>" placeholder="TCP-YYYY-######"></div>
                    <div class="col-lg-4"><label class="form-label" for="applicant">Applicant name</label><input class="form-control" id="applicant" name="applicant" maxlength="150" value="<?php echo e($filters['applicant']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label" for="application_reference">Application reference</label><input class="form-control" id="application_reference" name="application_reference" inputmode="numeric" value="<?php echo e($filters['application_reference']); ?>" placeholder="#"></div>
                    <div class="col-lg-2"><label class="form-label" for="donation_status">Donation status</label><select class="form-select" id="donation_status" name="donation_status"><option value="">All statuses</option><?php foreach ($donationStatuses as $status): ?><option value="<?php echo e($status); ?>" <?php echo $filters['donation_status'] === $status ? 'selected' : ''; ?>><?php echo e(permit_status_label($status)); ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6 col-lg-3"><label class="form-label" for="date_from">Requirement date from</label><input class="form-control" id="date_from" name="date_from" type="date" value="<?php echo e($filters['date_from']); ?>"></div>
                    <div class="col-sm-6 col-lg-3"><label class="form-label" for="date_to">Requirement date to</label><input class="form-control" id="date_to" name="date_to" type="date" value="<?php echo e($filters['date_to']); ?>"></div>
                    <div class="col-auto"><button class="btn btn-certreefy" type="submit"><i class="bi bi-search"></i> Search transactions</button></div>
                    <?php if ($hasFilters): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="donation-requirements.php">Clear</a></div><?php endif; ?>
                </form>

                <?php if ($requirements === []): ?>
                    <div class="text-center py-5"><i class="bi bi-inbox fs-1 text-secondary"></i><h3 class="h5 mt-3">No matching donation requirements</h3><p class="text-secondary mb-0">Only approved permit applications with a generated donation requirement appear here.</p></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Transaction / application</th><th>Applicant / property</th><th>Required</th><th>Received</th><th>Remaining</th><th>Status</th><th>Policy</th><th></th></tr></thead><tbody>
                        <?php foreach ($requirements as $requirement): ?><tr><td><div class="fw-semibold text-break"><?php echo e((string) $requirement['transaction_id']); ?></div><div class="small text-secondary">Application #<?php echo (int) $requirement['application_id']; ?><br>Created <?php echo e(date('M j, Y g:i A', strtotime((string) $requirement['imposed_at']))); ?></div></td><td><div class="fw-semibold"><?php echo e((string) $requirement['applicant_name']); ?></div><div class="small text-secondary"><?php echo e(permit_status_label((string) $requirement['property_classification'])); ?></div></td><td class="fs-5 fw-semibold"><?php echo (int) $requirement['required_seedling_count']; ?></td><td><?php echo (int) $requirement['received_seedling_count']; ?></td><td><?php echo (int) $requirement['remaining_seedling_count']; ?></td><td><span class="badge <?php echo e(permit_donation_receipt_status_badge((string) $requirement['current_status'])); ?>"><?php echo e(permit_status_label((string) $requirement['current_status'])); ?></span></td><td class="small text-break"><div><strong><?php echo e((string) $requirement['policy_code']); ?></strong></div><div class="text-secondary">Version <?php echo e((string) $requirement['policy_version']); ?></div></td><td><a class="btn btn-sm btn-outline-primary" href="donation-requirements.php?application_id=<?php echo (int) $requirement['application_id']; ?>#receipt-workflow">Open</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <?php if ($selectedRequirement !== null && $receiptWorkflowOpen): ?>
        <div class="modal fade" id="flagDonationModal" tabindex="-1" aria-labelledby="flagDonationModalLabel" aria-hidden="true">
            <div class="modal-dialog"><div class="modal-content"><form method="post" action="donation-receipt-action.php"><div class="modal-header"><h2 class="modal-title fs-5" id="flagDonationModalLabel">Flag invalid donation transaction</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="text-secondary">This blocks donation completion until EMS records a later valid receipt. It does not decline the permit.</p><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="action_key" value="<?php echo e($flagActionKey); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="action" value="flag_invalid"><label class="form-label" for="flag_verification_notes">Verification remarks</label><textarea class="form-control" id="flag_verification_notes" name="verification_notes" rows="4" maxlength="1000" required><?php echo e($flagRemarks); ?></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Flag transaction</button></div></form></div></div>
        </div>
        <template id="seedling-item-template"><div class="row g-2 align-items-end seedling-item-row"><div class="col-sm-7"><label class="form-label small">Species or type</label><input class="form-control" name="seedling_type[]" maxlength="150" required></div><div class="col-sm-3"><label class="form-label small">Quantity</label><input class="form-control seedling-quantity" name="quantity_received[]" type="number" min="1" max="1000000" step="1" required></div><div class="col-sm-2"><button class="btn btn-outline-danger w-100 remove-seedling-item" type="button" aria-label="Remove seedling item"><i class="bi bi-trash"></i></button></div></div></template>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($selectedRequirement !== null && $receiptWorkflowOpen): ?>
        <script>
        (() => {
            const container = document.getElementById('seedling-items');
            const template = document.getElementById('seedling-item-template');
            const addButton = document.getElementById('add-seedling-item');
            const total = document.getElementById('receipt-batch-total');
            if (!container || !template || !addButton || !total) return;
            const refresh = () => {
                const rows = [...container.querySelectorAll('.seedling-item-row')];
                rows.forEach((row) => {
                    const remove = row.querySelector('.remove-seedling-item');
                    if (remove) remove.disabled = rows.length === 1;
                });
                total.textContent = [...container.querySelectorAll('.seedling-quantity')]
                    .reduce((sum, input) => sum + Math.max(0, Number.parseInt(input.value || '0', 10) || 0), 0)
                    .toString();
            };
            addButton.addEventListener('click', () => {
                if (container.querySelectorAll('.seedling-item-row').length >= 50) return;
                container.appendChild(template.content.cloneNode(true));
                refresh();
            });
            container.addEventListener('click', (event) => {
                const button = event.target.closest('.remove-seedling-item');
                if (!button || container.querySelectorAll('.seedling-item-row').length <= 1) return;
                button.closest('.seedling-item-row').remove();
                refresh();
            });
            container.addEventListener('input', refresh);
            refresh();
        })();
        </script>
    <?php endif; ?>
</body>
</html>
