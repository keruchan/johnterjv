<?php
/** EMS seedling donation receipt and verification workflow for a single permit application. */

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

$applicationValue = permit_donation_scalar_value($_GET['application_id'] ?? '');
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$editValue = permit_donation_scalar_value($_GET['edit_receipt'] ?? '');
$editReceiptId = ctype_digit($editValue) ? (int) $editValue : 0;
$selectedRequirement = null;
$receipts = [];
$editReceipt = null;
$emsUsers = [];
$speciesList = [];
$loadError = null;

try {
    $emsUsers = permit_donation_active_ems_users($pdo, $userId);
    $speciesList = seedling_inventory_list($pdo, true);
    if ($applicationId > 0) {
        $selectedRequirement = permit_donation_requirement_for_actor($pdo, $applicationId, $userId);
        if ($selectedRequirement === null) {
            http_response_code(404);
            $loadError = 'The selected donation requirement was not found.';
        } else {
            $receipts = permit_list_donation_receipts_for_ems($pdo, $applicationId, $userId);
            if ($editReceiptId > 0) {
                $editReceipt = permit_donation_receipt_for_edit($pdo, $applicationId, $editReceiptId, $userId);
                if ($editReceipt === null) {
                    $loadError = 'Only a current unfinalized receipt may be corrected.';
                }
            }
        }
    } else {
        http_response_code(404);
        $loadError = 'No donation requirement was specified.';
    }
} catch (PDOException $e) {
    error_log('[CERTREEFY EMS DONATION RECEIPT ERROR] ' . $e->getMessage());
    http_response_code(500);
    $loadError = 'Unable to load this seedling donation requirement at this time.';
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

$receiptValues = [
    'received_on' => date('Y-m-d'),
    'received_by_user_id' => (string) $userId,
    'verification_notes' => '',
    'inventory_id' => [''],
    'other_species_name' => [''],
    'quantity_received' => [''],
    'expected_verification_id' => '',
];
if ($editReceipt !== null) {
    $receiptValues = [
        'received_on' => date('Y-m-d', strtotime((string) $editReceipt['received_at'])),
        'received_by_user_id' => (string) $editReceipt['received_by_user_id'],
        'verification_notes' => (string) ($editReceipt['verification_notes'] ?? ''),
        // A legacy item recorded before species were linked to inventory has
        // no inventory_id; fall back to "Other" pre-filled with its name.
        'inventory_id' => array_map(
            static fn (array $item): string => $item['inventory_id'] !== null ? (string) $item['inventory_id'] : 'other',
            $editReceipt['items']
        ),
        'other_species_name' => array_map(
            static fn (array $item): string => $item['inventory_id'] !== null ? '' : (string) $item['seedling_type'],
            $editReceipt['items']
        ),
        'quantity_received' => array_map('strval', array_column($editReceipt['items'], 'quantity_received')),
        'expected_verification_id' => (string) $editReceipt['id'],
    ];
}
if ($oldInput !== []
    && permit_donation_scalar_value($oldInput['action'] ?? '') !== 'flag_invalid') {
    foreach (array_keys($receiptValues) as $field) {
        if (array_key_exists($field, $oldInput)) {
            $receiptValues[$field] = in_array($field, ['inventory_id', 'other_species_name', 'quantity_received'], true)
                ? $oldInput[$field]
                : permit_donation_scalar_value($oldInput[$field]);
        }
    }
}
if (!is_array($receiptValues['inventory_id'])
    || !is_array($receiptValues['other_species_name'])
    || !is_array($receiptValues['quantity_received'])
    || count($receiptValues['inventory_id']) !== count($receiptValues['quantity_received'])
    || $receiptValues['inventory_id'] === []) {
    $receiptValues['inventory_id'] = [''];
    $receiptValues['other_species_name'] = [''];
    $receiptValues['quantity_received'] = [''];
}
$receiptValues['inventory_id'] = array_map('permit_donation_scalar_value', $receiptValues['inventory_id']);
$receiptValues['other_species_name'] = array_values(array_map(
    'permit_donation_scalar_value',
    $receiptValues['other_species_name'] !== [] ? $receiptValues['other_species_name'] : array_fill(0, count($receiptValues['inventory_id']), '')
));
$receiptValues['quantity_received'] = array_map('permit_donation_scalar_value', $receiptValues['quantity_received']);
$flagRemarks = $oldInput !== []
    && permit_donation_scalar_value($oldInput['action'] ?? '') === 'flag_invalid'
    ? permit_donation_scalar_value($oldInput['verification_notes'] ?? '')
    : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Donation Receipt</title>
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
                    <div><div class="eyebrow">Enforcement &amp; Monitoring Section</div><h1 class="page-title">Donation Receipt</h1><p class="text-secondary meta-copy mb-0">Physical receipt and EMS verification for a single transaction.</p></div>
                    <div class="d-flex align-items-center gap-2"><?php render_certreefy_notification_bell('header'); ?><span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>"><button type="submit" class="btn-logout-outline"><i class="bi bi-box-arrow-right me-1"></i> Logout</button></form></div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <?php if (is_array($flash)): ?><div class="alert alert-<?php echo e((string) ($flash['type'] ?? 'info')); ?> alert-dismissible fade show" role="alert"><?php echo e((string) ($flash['message'] ?? '')); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

            <section class="docket-panel" aria-labelledby="receipt-workflow-heading">
                <div class="section-heading"><div><h2 id="receipt-workflow-heading">Donation Receipt</h2><span class="section-note">Physical receipt and EMS verification</span></div><a class="btn btn-outline-secondary btn-sm" href="donation-registry.php"><i class="bi bi-arrow-left"></i> Back to registry</a></div>

                <?php if ($loadError !== null): ?>
                    <div class="alert alert-danger" role="alert"><?php echo e($loadError); ?></div>
                <?php else: ?>
                    <?php render_permit_donation_requirement($selectedRequirement, true); ?>

                    <div class="alert alert-light border mt-3 mb-0" role="note"><i class="bi bi-box-seam me-1"></i>No reference number needed — the transaction ID above covers it. Finalizing credits each species to inventory automatically.</div>

                    <div class="row g-4 mt-1">
                        <div class="col-xl-7">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-3"><div><h3 class="h5 mb-1"><?php echo $editReceipt !== null ? 'Correct unfinalized receipt' : 'Record receipt'; ?></h3><p class="small text-secondary mb-0">Finalized receipt batches cannot be edited. Corrections create a preserved prior version.</p></div><?php if ($editReceipt !== null): ?><a class="btn btn-sm btn-outline-secondary" href="donation-receipt.php?application_id=<?php echo $applicationId; ?>">Cancel correction</a><?php endif; ?></div>
                                <form method="post" action="donation-receipt-action.php" id="donation-receipt-form" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="action_key" value="<?php echo e($receiptActionKey); ?>">
                                    <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                                    <input type="hidden" name="expected_verification_id" value="<?php echo e((string) $receiptValues['expected_verification_id']); ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6"><label class="form-label" for="received_on">Date received</label><input class="form-control" id="received_on" name="received_on" type="date" max="<?php echo e(date('Y-m-d')); ?>" required value="<?php echo e((string) $receiptValues['received_on']); ?>"></div>
                                        <div class="col-md-6"><label class="form-label" for="received_by_user_id">Receiving personnel</label><select class="form-select" id="received_by_user_id" name="received_by_user_id" required><option value="">Select active EMS personnel</option><?php foreach ($emsUsers as $emsUser): ?><option value="<?php echo (int) $emsUser['id']; ?>" <?php echo (int) $receiptValues['received_by_user_id'] === (int) $emsUser['id'] ? 'selected' : ''; ?>><?php echo e((string) $emsUser['display_name']); ?></option><?php endforeach; ?></select></div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-4 mb-2"><div><h4 class="h6 mb-0">Seedling species received</h4><div class="small text-secondary">Pick from inventory, or choose "Other" to add a species not yet encoded.</div></div><button class="btn btn-sm btn-outline-success" type="button" id="add-seedling-item"><i class="bi bi-plus-lg"></i> Add item</button></div>
                                    <div id="seedling-items" class="d-grid gap-2">
                                        <?php foreach ($receiptValues['inventory_id'] as $index => $selectedInventoryId): ?>
                                            <?php $isOtherRow = $selectedInventoryId === 'other'; ?>
                                            <div class="row g-2 align-items-end seedling-item-row">
                                                <div class="col-sm-5">
                                                    <label class="form-label small">Species</label>
                                                    <select class="form-select seedling-species-select" name="inventory_id[]" required>
                                                        <option value="">Select species</option>
                                                        <?php foreach ($speciesList as $species): ?>
                                                            <option value="<?php echo (int) $species['id']; ?>" <?php echo $selectedInventoryId === (string) $species['id'] ? 'selected' : ''; ?>><?php echo e((string) $species['common_name']); ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="other" <?php echo $isOtherRow ? 'selected' : ''; ?>>Other (not yet encoded)</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-4 seedling-other-name-wrap" <?php echo $isOtherRow ? '' : 'hidden'; ?>>
                                                    <label class="form-label small">New species name</label>
                                                    <input class="form-control seedling-other-name" name="other_species_name[]" maxlength="150" value="<?php echo e((string) ($receiptValues['other_species_name'][$index] ?? '')); ?>" <?php echo $isOtherRow ? 'required' : ''; ?>>
                                                </div>
                                                <div class="col-sm-2"><label class="form-label small">Quantity</label><input class="form-control seedling-quantity" name="quantity_received[]" type="number" min="1" max="1000000" step="1" required value="<?php echo e((string) ($receiptValues['quantity_received'][$index] ?? '')); ?>"></div>
                                                <div class="col-sm-1"><button class="btn btn-outline-danger w-100 remove-seedling-item" type="button" aria-label="Remove seedling item"><i class="bi bi-trash"></i></button></div>
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
                            <div class="border rounded p-3 h-100"><h3 class="h5">Receipt rules</h3><ul class="small text-secondary mb-0"><li class="mb-2">Saving keeps a receipt unfinalized and editable.</li><li class="mb-2">Finalizing confirms receipt, counts the batch, and credits inventory.</li><li class="mb-2">A partial total doesn't make the permit release-eligible.</li><li>A complete total advances only to final RPS verification.</li></ul></div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h5 mb-0">Receipt and verification history</h3><span class="small text-secondary"><?php echo count($receipts); ?> record<?php echo count($receipts) === 1 ? '' : 's'; ?></span></div>
                        <?php if ($receipts === []): ?><div class="border rounded p-4 text-center text-secondary">No EMS receipt has been recorded.</div><?php else: ?>
                            <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Status / version</th><th>Received</th><th>Seedling items</th><th>Personnel</th><th>Remarks</th><th></th></tr></thead><tbody>
                                <?php foreach ($receipts as $receipt): ?><tr>
                                    <td><span class="badge <?php echo e(permit_donation_receipt_status_badge((string) $receipt['verification_status'])); ?>"><?php echo e(permit_status_label((string) $receipt['verification_status'])); ?></span><div class="small text-secondary mt-1">Version <?php echo (int) $receipt['version_number']; ?><?php if ((int) $receipt['is_current'] !== 1): ?> &middot; superseded<?php elseif ((int) $receipt['is_finalized'] === 1): ?> &middot; finalized<?php else: ?> &middot; current<?php endif; ?></div></td>
                                    <td class="small"><div><?php echo e(date('M j, Y', strtotime((string) $receipt['received_at']))); ?></div><div class="text-secondary">Batch total: <?php echo (int) $receipt['seedlings_received']; ?></div></td>
                                    <td class="small"><?php if ($receipt['items'] === []): ?><span class="text-secondary">No receipt items</span><?php else: ?><?php foreach ($receipt['items'] as $item): ?><div><?php echo e((string) $item['seedling_type']); ?> &middot; <?php echo (int) $item['quantity_received']; ?></div><?php endforeach; ?><?php endif; ?></td>
                                    <td class="small"><div><strong>Received by:</strong> <?php echo e((string) $receipt['received_by_name']); ?></div><div><strong>Recorded by:</strong> <?php echo e((string) $receipt['verified_by_name']); ?></div><div class="text-secondary"><?php echo e(date('M j, Y g:i A', strtotime((string) $receipt['verified_at']))); ?></div></td>
                                    <td class="small text-break"><?php echo $receipt['verification_notes'] === null ? '<span class="text-secondary">None</span>' : e((string) $receipt['verification_notes']); ?></td>
                                    <td><?php if ((int) $receipt['is_current'] === 1 && (int) $receipt['is_finalized'] === 0): ?><a class="btn btn-sm btn-outline-primary" href="donation-receipt.php?application_id=<?php echo $applicationId; ?>&amp;edit_receipt=<?php echo (int) $receipt['id']; ?>">Correct</a><?php endif; ?></td>
                                </tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <?php if ($loadError === null): ?>
        <div class="modal fade" id="flagDonationModal" tabindex="-1" aria-labelledby="flagDonationModalLabel" aria-hidden="true">
            <div class="modal-dialog"><div class="modal-content"><form method="post" action="donation-receipt-action.php"><div class="modal-header"><h2 class="modal-title fs-5" id="flagDonationModalLabel">Flag invalid donation transaction</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="text-secondary">This blocks donation completion until EMS records a later valid receipt. It does not decline the permit.</p><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="action_key" value="<?php echo e($flagActionKey); ?>"><input type="hidden" name="application_id" value="<?php echo $applicationId; ?>"><input type="hidden" name="action" value="flag_invalid"><label class="form-label" for="flag_verification_notes">Verification remarks</label><textarea class="form-control" id="flag_verification_notes" name="verification_notes" rows="4" maxlength="1000" required><?php echo e($flagRemarks); ?></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Flag transaction</button></div></form></div></div>
        </div>
        <template id="seedling-item-template">
            <div class="row g-2 align-items-end seedling-item-row">
                <div class="col-sm-5">
                    <label class="form-label small">Species</label>
                    <select class="form-select seedling-species-select" name="inventory_id[]" required>
                        <option value="">Select species</option>
                        <?php foreach ($speciesList as $species): ?><option value="<?php echo (int) $species['id']; ?>"><?php echo e((string) $species['common_name']); ?></option><?php endforeach; ?>
                        <option value="other">Other (not yet encoded)</option>
                    </select>
                </div>
                <div class="col-sm-4 seedling-other-name-wrap" hidden>
                    <label class="form-label small">New species name</label>
                    <input class="form-control seedling-other-name" name="other_species_name[]" maxlength="150">
                </div>
                <div class="col-sm-2"><label class="form-label small">Quantity</label><input class="form-control seedling-quantity" name="quantity_received[]" type="number" min="1" max="1000000" step="1" required></div>
                <div class="col-sm-1"><button class="btn btn-outline-danger w-100 remove-seedling-item" type="button" aria-label="Remove seedling item"><i class="bi bi-trash"></i></button></div>
            </div>
        </template>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($loadError === null): ?>
        <script>
        (() => {
            const container = document.getElementById('seedling-items');
            const template = document.getElementById('seedling-item-template');
            const addButton = document.getElementById('add-seedling-item');
            const total = document.getElementById('receipt-batch-total');
            if (!container || !template || !addButton || !total) return;

            const syncOtherVisibility = (row) => {
                const select = row.querySelector('.seedling-species-select');
                const wrap = row.querySelector('.seedling-other-name-wrap');
                const input = row.querySelector('.seedling-other-name');
                if (!select || !wrap || !input) return;
                const isOther = select.value === 'other';
                wrap.hidden = !isOther;
                input.required = isOther;
                if (!isOther) input.value = '';
            };

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
                const fragment = template.content.cloneNode(true);
                container.appendChild(fragment);
                refresh();
            });
            container.addEventListener('click', (event) => {
                const button = event.target.closest('.remove-seedling-item');
                if (!button || container.querySelectorAll('.seedling-item-row').length <= 1) return;
                button.closest('.seedling-item-row').remove();
                refresh();
            });
            container.addEventListener('change', (event) => {
                if (event.target.classList.contains('seedling-species-select')) {
                    syncOtherVisibility(event.target.closest('.seedling-item-row'));
                }
            });
            container.addEventListener('input', refresh);
            container.querySelectorAll('.seedling-item-row').forEach(syncOtherVisibility);
            refresh();
        })();
        </script>
    <?php endif; ?>
</body>
</html>
