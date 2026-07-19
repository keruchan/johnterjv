<?php
/** EMS seedling inventory: species records, stock levels, and stock movements. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

if (empty($_SESSION['csrf_seedling_inventory_token'])) {
    $_SESSION['csrf_seedling_inventory_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_seedling_inventory_token'];

$flash = null;
if (!empty($_SESSION['seedling_inventory_flash']) && is_array($_SESSION['seedling_inventory_flash'])) {
    $flash = $_SESSION['seedling_inventory_flash'];
    unset($_SESSION['seedling_inventory_flash']);
}

try {
    $inventory = seedling_inventory_list($pdo);
    $summary = seedling_inventory_summary($pdo);
    $movements = seedling_stock_movements($pdo, 15);
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING INVENTORY LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load seedling inventory at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Seed Inventory</title>

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
        <?php render_certreefy_navigation($currentRole, 'seed_inventory'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Seed Inventory</h1>
                        <p class="text-secondary meta-copy mb-0">Species records, available stock, and stock movements.</p>
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

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?php echo e((string) $flash['type']); ?> alert-dismissible fade show" role="alert">
                    <?php echo e((string) $flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <section class="row g-3 mb-4" aria-label="Inventory summary">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-box-seam"></i></span><span class="ledger-tag">Inventory</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['total_available']; ?></div>
                        <div class="ledger-caption">Available seedlings</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-diagram-3"></i></span><span class="ledger-tag">Species</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['species_count']; ?></div>
                        <div class="ledger-caption">Active species</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="ledger-tag">Alerts</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['low_stock_species']; ?></div>
                        <div class="ledger-caption">Low stock items</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-4">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span><span class="ledger-tag">Requests</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['pending_requests']; ?></div>
                        <div class="ledger-caption">Pending seedling requests</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="addSpeciesHeading">
                <div class="section-heading">
                    <h2 id="addSpeciesHeading">Add Species</h2>
                    <span class="section-note">Opening stock is recorded as an incoming movement</span>
                </div>
                <form method="post" action="seedling-inventory-action.php" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_species">
                    <div class="col-md-3"><label class="form-label" for="commonName">Common name</label><input class="form-control" id="commonName" type="text" name="common_name" maxlength="150" required></div>
                    <div class="col-md-3"><label class="form-label" for="scientificName">Scientific name (optional)</label><input class="form-control" id="scientificName" type="text" name="scientific_name" maxlength="150"></div>
                    <div class="col-md-2"><label class="form-label" for="openingQuantity">Opening stock</label><input class="form-control" id="openingQuantity" type="number" name="available_quantity" min="0" value="0" required></div>
                    <div class="col-md-2"><label class="form-label" for="lowStockThreshold">Low-stock threshold</label><input class="form-control" id="lowStockThreshold" type="number" name="low_stock_threshold" min="0" value="0" required></div>
                    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-plus-circle"></i> Add species</button></div>
                    <div class="col-12"><label class="form-label" for="speciesNotes">Notes (optional)</label><input class="form-control" id="speciesNotes" type="text" name="notes" maxlength="500"></div>
                </form>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="inventoryTableHeading">
                <div class="section-heading">
                    <h2 id="inventoryTableHeading">Species Registry</h2>
                    <span class="section-note tabular"><?php echo count($inventory); ?> species</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search species">
                        <thead>
                            <tr>
                                <th scope="col">Species</th>
                                <th scope="col">Available</th>
                                <th scope="col">Low-stock threshold</th>
                                <th scope="col" data-tt-filter="Status">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inventory === []): ?>
                                <tr><td colspan="5" class="text-center text-secondary py-5">No seedling species recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($inventory as $species): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $species['common_name']); ?></div>
                                        <?php if (!empty($species['scientific_name'])): ?><div class="small text-secondary fst-italic"><?php echo e((string) $species['scientific_name']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="fs-5 fw-semibold tabular"><?php echo (int) $species['available_quantity']; ?></td>
                                    <td class="tabular"><?php echo (int) $species['low_stock_threshold']; ?></td>
                                    <td>
                                        <?php if ((int) $species['is_active'] !== 1): ?>
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        <?php elseif ((int) $species['is_low_stock'] === 1): ?>
                                            <span class="badge text-bg-danger">Low stock</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">In stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary adjust-stock-action" type="button" data-bs-toggle="modal" data-bs-target="#adjustStockModal" data-inventory-id="<?php echo (int) $species['id']; ?>" data-common-name="<?php echo e((string) $species['common_name']); ?>"><i class="bi bi-arrow-left-right"></i> Adjust stock</button>
                                        <button class="btn btn-sm btn-outline-primary edit-species-action" type="button" data-bs-toggle="modal" data-bs-target="#editSpeciesModal" data-inventory-id="<?php echo (int) $species['id']; ?>" data-common-name="<?php echo e((string) $species['common_name']); ?>" data-scientific-name="<?php echo e((string) ($species['scientific_name'] ?? '')); ?>" data-threshold="<?php echo (int) $species['low_stock_threshold']; ?>" data-notes="<?php echo e((string) ($species['notes'] ?? '')); ?>" data-is-active="<?php echo (int) $species['is_active']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="docket-panel" aria-labelledby="movementsHeading">
                <div class="section-heading">
                    <h2 id="movementsHeading">Recent Stock Movements</h2>
                    <span class="section-note">Last 15 entries</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-page-size="15" data-tt-search-placeholder="Search species, reason, or reference">
                        <thead>
                            <tr>
                                <th scope="col">Species</th>
                                <th scope="col" data-tt-filter="Type">Type</th>
                                <th scope="col">Change</th>
                                <th scope="col">After</th>
                                <th scope="col">Reason / request</th>
                                <th scope="col">Recorded by</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($movements === []): ?>
                                <tr><td colspan="7" class="text-center text-secondary py-5">No stock movements recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td><?php echo e((string) $movement['common_name']); ?></td>
                                    <td>
                                        <?php $movementBadge = match ((string) $movement['movement_type']) {
                                            'incoming' => 'text-bg-success',
                                            'released' => 'text-bg-info',
                                            default => 'text-bg-secondary',
                                        }; ?>
                                        <span class="badge <?php echo e($movementBadge); ?>"><?php echo e(ucfirst((string) $movement['movement_type'])); ?></span>
                                    </td>
                                    <td class="tabular <?php echo (int) $movement['quantity_delta'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo (int) $movement['quantity_delta'] > 0 ? '+' : ''; ?><?php echo (int) $movement['quantity_delta']; ?></td>
                                    <td class="tabular"><?php echo (int) $movement['quantity_after']; ?></td>
                                    <td class="small text-secondary text-break"><?php echo $movement['request_reference'] !== null ? e((string) $movement['request_reference']) : e((string) ($movement['reason'] ?? '-')); ?></td>
                                    <td class="small"><?php echo e((string) $movement['recorded_by_name']); ?></td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string) $movement['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="modal fade" id="adjustStockModal" tabindex="-1" aria-labelledby="adjustStockModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="seedling-inventory-action.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="adjustStockModalLabel">Adjust Stock</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">Adjusting stock for <strong id="adjustStockSpeciesName"></strong></p>
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="adjust_stock">
                        <input type="hidden" name="inventory_id" id="adjustStockInventoryId" value="">
                        <div class="mb-3">
                            <label class="form-label" for="movementType">Movement type</label>
                            <select class="form-select" id="movementType" name="movement_type" required>
                                <option value="incoming">Incoming stock (delivery)</option>
                                <option value="adjustment">Correction (e.g. damaged, miscount)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="movementQuantity">Quantity</label>
                            <input class="form-control" id="movementQuantity" type="number" name="quantity" required>
                            <div class="form-text">For a correction, use a negative number to reduce stock.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="movementReason">Reason</label>
                            <input class="form-control" id="movementReason" type="text" name="reason" maxlength="500">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editSpeciesModal" tabindex="-1" aria-labelledby="editSpeciesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="seedling-inventory-action.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="editSpeciesModalLabel">Edit Species</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">Editing <strong id="editSpeciesName"></strong> <span class="text-secondary">(common name and stock are not editable here)</span></p>
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_species">
                        <input type="hidden" name="inventory_id" id="editSpeciesInventoryId" value="">
                        <div class="mb-3"><label class="form-label" for="editScientificName">Scientific name</label><input class="form-control" id="editScientificName" type="text" name="scientific_name" maxlength="150"></div>
                        <div class="mb-3"><label class="form-label" for="editThreshold">Low-stock threshold</label><input class="form-control" id="editThreshold" type="number" name="low_stock_threshold" min="0" required></div>
                        <div class="mb-3"><label class="form-label" for="editNotes">Notes</label><input class="form-control" id="editNotes" type="text" name="notes" maxlength="500"></div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="editIsActive" name="is_active" value="1">
                            <label class="form-check-label" for="editIsActive">Active (visible to Community for requests)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.adjust-stock-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('adjustStockInventoryId').value = button.dataset.inventoryId;
                document.getElementById('adjustStockSpeciesName').textContent = button.dataset.commonName;
            });
        });
        document.querySelectorAll('.edit-species-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('editSpeciesInventoryId').value = button.dataset.inventoryId;
                document.getElementById('editSpeciesName').textContent = button.dataset.commonName;
                document.getElementById('editScientificName').value = button.dataset.scientificName || '';
                document.getElementById('editThreshold').value = button.dataset.threshold;
                document.getElementById('editNotes').value = button.dataset.notes || '';
                document.getElementById('editIsActive').checked = button.dataset.isActive === '1';
            });
        });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
