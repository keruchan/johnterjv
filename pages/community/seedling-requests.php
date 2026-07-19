<?php
/** Community seedling-request submission and personal registry. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/planting_sites.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Community User';

if (empty($_SESSION['csrf_seedling_request_submit_token'])) {
    $_SESSION['csrf_seedling_request_submit_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_seedling_request_submit_token'];
$submissionKey = new_seedling_submission_key();

$flash = null;
if (!empty($_SESSION['seedling_request_submit_flash']) && is_array($_SESSION['seedling_request_submit_flash'])) {
    $flash = $_SESSION['seedling_request_submit_flash'];
    unset($_SESSION['seedling_request_submit_flash']);
}

try {
    $availableSpecies = seedling_inventory_list($pdo, true);
    $myRequests = seedling_requests_for_requester($pdo, $userId);
    $plantingRecommendations = planting_site_recommendations($pdo);
    $plantingSiteFeatures = planting_site_map_features($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING REQUEST PAGE ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load the seedling request program at this time. Please try again later.');
}
$cap = seedling_max_per_request();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Seedling Request</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'seedling_request'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Community</div>
                        <h1 class="page-title">Seedling Request</h1>
                        <p class="text-secondary meta-copy mb-0">Request free seedlings for planting. Up to <?php echo $cap; ?> seedlings per request.</p>
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

            <section class="docket-panel mb-4" aria-labelledby="newRequestHeading">
                <div class="section-heading"><h2 id="newRequestHeading">Request Seedlings</h2></div>

                <?php if ($availableSpecies === []): ?>
                    <div class="alert alert-light border mb-0"><i class="bi bi-info-circle me-1"></i>No species available right now — check back later.</div>
                <?php else: ?>
                    <form method="post" action="seedling-request-action.php" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="submission_key" value="<?php echo e($submissionKey); ?>">
                        <input type="hidden" name="action" value="submit">

                        <div class="col-md-6">
                            <label class="form-label" for="plantingPurpose">Planting purpose</label>
                            <input class="form-control" id="plantingPurpose" type="text" name="planting_purpose" maxlength="500" placeholder="e.g. Backyard reforestation" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="plantingLocation">Planting location</label>
                            <input class="form-control" id="plantingLocation" type="text" name="planting_location" maxlength="500" placeholder="e.g. Barangay, municipality" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="preferredPickupDate">Preferred pickup date (optional)</label>
                            <input class="form-control" id="preferredPickupDate" type="date" name="preferred_pickup_date" min="<?php echo e(date('Y-m-d')); ?>">
                        </div>

                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h3 class="h6 mb-0">Species requested</h3>
                                <button type="button" class="btn btn-sm btn-outline-success" id="add-species-row"><i class="bi bi-plus-circle"></i> Add species</button>
                            </div>
                            <div id="species-rows" class="d-grid gap-2">
                                <div class="row g-2 species-row" data-species-row>
                                    <div class="col-md-6">
                                        <select class="form-select" name="inventory_id[]" required>
                                            <option value="">Select species</option>
                                            <?php foreach ($availableSpecies as $species): ?>
                                                <option value="<?php echo (int) $species['id']; ?>"><?php echo e((string) $species['common_name']); ?> (<?php echo (int) $species['available_quantity']; ?> available)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input class="form-control" type="number" name="quantity[]" min="1" max="<?php echo $cap; ?>" placeholder="Quantity" required>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger w-100 remove-species-row" aria-label="Remove species row" disabled><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">Total across all species may not exceed <?php echo $cap; ?> seedlings.</div>
                        </div>

                        <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-send"></i> Submit request</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <?php if ($plantingRecommendations !== []): ?>
                <section class="docket-panel mb-4" aria-labelledby="plantingAdviceHeading">
                    <div class="section-heading">
                        <h2 id="plantingAdviceHeading">Where to Plant — EMS Recommendations</h2>
                        <span class="section-note tabular"><?php echo count($plantingRecommendations); ?> site<?php echo count($plantingRecommendations) === 1 ? '' : 's'; ?></span>
                    </div>
                    <p class="text-secondary small mb-3"><i class="bi bi-info-circle me-1"></i>EMS-recommended planting spots based on soil, moisture, and season. Advisory only — you can plant on your own property.</p>
                    <div id="plantingSitesMap" class="geo-map" role="img" aria-label="Map of recommended planting sites"></div>
                    <div class="row g-3 mt-1">
                        <?php foreach ($plantingRecommendations as $site): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="fw-semibold mb-1"><i class="bi bi-geo-alt text-success me-1"></i><?php echo e((string) $site['site_name']); ?></div>
                                    <?php
                                    $siteLocation = implode(', ', array_filter([$site['barangay'] ?? null, $site['municipality'] ?? null, $site['province'] ?? null]));
                                    ?>
                                    <?php if ($siteLocation !== ''): ?><div class="small text-secondary mb-2"><?php echo e($siteLocation); ?></div><?php endif; ?>
                                    <ul class="list-unstyled small mb-2">
                                        <?php if ($site['soil_type'] !== null || $site['soil_ph'] !== null): ?>
                                            <li><strong>Soil:</strong> <?php echo e(implode(' · ', array_filter([(string) ($site['soil_type'] ?? ''), $site['soil_ph'] !== null ? 'pH ' . (string) $site['soil_ph'] : '']))); ?></li>
                                        <?php endif; ?>
                                        <?php if ($site['moisture_level'] !== null): ?>
                                            <li><strong>Moisture:</strong> <?php echo e(planting_site_moisture_label((string) $site['moisture_level'])); ?></li>
                                        <?php endif; ?>
                                        <?php if ($site['recommended_season'] !== null): ?>
                                            <li><strong>Best season:</strong> <?php echo e((string) $site['recommended_season']); ?></li>
                                        <?php endif; ?>
                                        <?php if ($site['suitable_species'] !== null): ?>
                                            <li><strong>Suitable species:</strong> <?php echo e((string) $site['suitable_species']); ?></li>
                                        <?php endif; ?>
                                    </ul>
                                    <?php if ($site['rationale'] !== null): ?>
                                        <div class="small text-secondary"><i class="bi bi-lightbulb me-1"></i><?php echo e((string) $site['rationale']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="docket-panel" aria-labelledby="myRequestsHeading">
                <div class="section-heading">
                    <h2 id="myRequestsHeading">My Seedling Requests</h2>
                    <span class="section-note tabular"><?php echo count($myRequests); ?> request<?php echo count($myRequests) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search reference">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Requested / Approved</th>
                                <th scope="col" data-tt-filter="Status">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($myRequests === []): ?>
                                <tr><td colspan="5" class="text-center text-secondary py-5">You have not submitted any seedling requests yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($myRequests as $myRequest): ?>
                                <?php $myStatus = (string) $myRequest['current_status']; ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $myRequest['request_reference']); ?></td>
                                    <td class="tabular"><?php echo (int) $myRequest['total_requested']; ?> / <?php echo (int) $myRequest['total_approved']; ?></td>
                                    <td><span class="badge <?php echo e(seedling_request_status_badge($myStatus)); ?>"><?php echo e(seedling_request_status_label($myStatus)); ?></span></td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y', strtotime((string) $myRequest['submitted_at']))); ?></td>
                                    <td class="text-end">
                                        <?php if (in_array($myStatus, ['submitted', 'under_review'], true)): ?>
                                            <form method="post" action="seedling-request-action.php" class="d-inline" onsubmit="return confirm('Withdraw this seedling request?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="request_id" value="<?php echo (int) $myRequest['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Withdraw</button>
                                            </form>
                                        <?php elseif ($myStatus === 'ready_for_pickup'): ?>
                                            <span class="small text-success"><i class="bi bi-check2-circle"></i> Ready at <?php echo e(seedling_claim_location()); ?></span>
                                        <?php else: ?>
                                            <span class="small text-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <?php if ($plantingRecommendations !== []): ?>
        <script type="application/json" id="plantingSiteData"><?php echo json_encode($plantingSiteFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($plantingRecommendations !== []): ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script src="../../js/geo-map.js"></script>
        <script>
            (function () {
                var sites = CertreefyGeo.readJson('plantingSiteData', []);
                CertreefyGeo.display('plantingSitesMap', {
                    zones: sites,
                    points: sites.filter(function (s) { return s.center_lat !== null && s.center_lng !== null; }).map(function (s) {
                        var detail = [];
                        if (s.suitable_species) { detail.push('Species: ' + s.suitable_species); }
                        if (s.recommended_season) { detail.push('Season: ' + s.recommended_season); }
                        return { lat: s.center_lat, lng: s.center_lng, label: s.name + (detail.length ? ' — ' + detail.join(' · ') : '') };
                    })
                });
            })();
        </script>
    <?php endif; ?>
    <script>
        (function () {
            const rows = document.getElementById('species-rows');
            const addButton = document.getElementById('add-species-row');
            if (!rows || !addButton) { return; }

            function refreshRemoveButtons() {
                const rowEls = rows.querySelectorAll('[data-species-row]');
                rowEls.forEach((row) => {
                    const btn = row.querySelector('.remove-species-row');
                    if (btn) { btn.disabled = rowEls.length <= 1; }
                });
            }

            addButton.addEventListener('click', function () {
                const first = rows.querySelector('[data-species-row]');
                const clone = first.cloneNode(true);
                clone.querySelectorAll('select, input').forEach((field) => {
                    if (field.tagName === 'SELECT') { field.selectedIndex = 0; } else { field.value = ''; }
                });
                rows.appendChild(clone);
                refreshRemoveButtons();
            });

            rows.addEventListener('click', function (event) {
                const button = event.target.closest('.remove-species-row');
                if (!button || button.disabled) { return; }
                button.closest('[data-species-row]').remove();
                refreshRemoveButtons();
            });

            refreshRemoveButtons();
        })();
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
