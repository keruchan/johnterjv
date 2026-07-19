<?php
/** CENRO Area Management: reference registry of allowed/restricted/protected zones. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/area_management.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Officer';

if (area_management_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to view area management.');
}

if (empty($_SESSION['csrf_area_management_token'])) {
    $_SESSION['csrf_area_management_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_area_management_token'];

$flash = null;
if (!empty($_SESSION['area_management_flash']) && is_array($_SESSION['area_management_flash'])) {
    $flash = $_SESSION['area_management_flash'];
    unset($_SESSION['area_management_flash']);
}

$classifications = area_zone_classifications();
$filters = [
    'classification' => trim((string) ($_GET['classification'] ?? '')),
    'q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

try {
    $zones = area_zone_list($pdo, $filters);
    $summary = area_zone_summary($pdo);
    $zoneMapFeatures = area_zone_map_features($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY AREA MANAGEMENT LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load area management at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Area Management</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.css">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'area_management'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Operations</div>
                        <h1 class="page-title">Area Management</h1>
                        <p class="text-secondary meta-copy mb-0">Reference registry of allowed, restricted, and protected environmental zones.</p>
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

            <div class="alert alert-light border mb-4"><i class="bi bi-info-circle me-1"></i>Informational only — not linked to permit review or approval.</div>

            <section class="row g-3 mb-4" aria-label="Zone summary">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-geo-alt"></i></span><span class="ledger-tag">Zones</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['total']; ?></div>
                        <div class="ledger-caption">Total zones</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-check-circle"></i></span><span class="ledger-tag">Allowed</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['allowed_count']; ?></div>
                        <div class="ledger-caption">Allowed for cutting</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="ledger-tag">Restricted</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['restricted_count']; ?></div>
                        <div class="ledger-caption">Restricted zones</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-shield-exclamation"></i></span><span class="ledger-tag">Protected</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['protected_count']; ?></div>
                        <div class="ledger-caption">Protected zones</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="zoneMapHeading">
                <div class="section-heading">
                    <h2 id="zoneMapHeading">Zone Map</h2>
                    <span class="section-note tabular"><?php echo count($zoneMapFeatures); ?> mapped</span>
                </div>
                <?php if ($zoneMapFeatures === []): ?>
                    <p class="text-secondary small mb-2">No zone has a drawn boundary yet — draw one below or edit an existing zone.</p>
                <?php endif; ?>
                <div id="zonesOverviewMap" class="geo-map geo-map-tall" role="img" aria-label="Map of managed environmental zones"></div>
                <div class="geo-legend">
                    <span><span class="legend-swatch" style="background:#4a7c59"></span>Allowed for Cutting</span>
                    <span><span class="legend-swatch" style="background:#d9a441"></span>Restricted</span>
                    <span><span class="legend-swatch" style="background:#b4552d"></span>Protected</span>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="addZoneHeading">
                <div class="section-heading"><h2 id="addZoneHeading">Add Zone</h2></div>
                <form method="post" action="area-management-action.php" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_zone">
                    <div class="col-md-4"><label class="form-label" for="zoneName">Zone name</label><input class="form-control" id="zoneName" type="text" name="zone_name" maxlength="150" required></div>
                    <div class="col-md-4">
                        <label class="form-label" for="zoneClassification">Classification</label>
                        <select class="form-select" id="zoneClassification" name="classification" required>
                            <?php foreach ($classifications as $value => $label): ?>
                                <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label" for="zoneCoverage">Coverage description (optional)</label><input class="form-control" id="zoneCoverage" type="text" name="coverage_description" maxlength="1000"></div>
                    <div class="col-12"><label class="form-label" for="zoneNotes">Notes (optional)</label><textarea class="form-control" id="zoneNotes" name="notes" rows="2" maxlength="1000"></textarea></div>
                    <div class="col-12">
                        <label class="form-label mb-1">Zone boundary <span class="text-danger">*</span></label>
                        <p class="text-secondary small mb-2">Draw the zone's coverage with the polygon or rectangle tool. Province, municipality, barangay, and district are detected automatically from the boundary center.</p>
                        <input type="hidden" name="boundary_geojson" id="zoneBoundaryInput">
                        <div id="zoneBoundaryEditor" class="geo-map"></div>
                    </div>
                    <div class="col-md-3"><label class="form-label" for="zoneProvince">Province</label><input class="form-control" id="zoneProvince" type="text" name="province" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-md-3"><label class="form-label" for="zoneMunicipality">Municipality</label><input class="form-control" id="zoneMunicipality" type="text" name="municipality" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-md-3"><label class="form-label" for="zoneBarangay">Barangay</label><input class="form-control" id="zoneBarangay" type="text" name="barangay" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-md-3"><label class="form-label" for="zoneDistrict">District</label><input class="form-control" id="zoneDistrict" type="text" name="district" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-12"><span class="small text-secondary" id="zoneLocationStatus" role="status"></span></div>
                    <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-plus-circle me-1"></i>Add zone</button></div>
                </form>
            </section>

            <section class="docket-panel mb-4" aria-label="Zone filters">
                <form method="get" action="area-management.php" class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label for="zoneSearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="zoneSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Zone name, municipality, barangay, or district">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="classificationFilter" class="form-label">Classification</label>
                        <select class="form-select" id="classificationFilter" name="classification">
                            <option value="">All classifications</option>
                            <?php foreach ($classifications as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filters['classification'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="area-management.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="zoneRegistryHeading">
                <div class="section-heading">
                    <h2 id="zoneRegistryHeading">Zone Registry</h2>
                    <span class="section-note tabular"><?php echo count($zones); ?> zone<?php echo count($zones) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Zone</th>
                                <th scope="col">Classification</th>
                                <th scope="col">Coverage</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($zones === []): ?>
                                <tr><td colspan="5" class="text-center text-secondary py-5">No area zones recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($zones as $zone): ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $zone['zone_name']); ?></td>
                                    <td><span class="badge <?php echo e(area_zone_classification_badge((string) $zone['classification'])); ?>"><?php echo e(area_zone_classification_label((string) $zone['classification'])); ?></span></td>
                                    <td class="small text-secondary">
                                        <?php
                                        $coverageParts = array_filter([$zone['barangay'] ?? null, $zone['municipality'] ?? null, $zone['district'] ?? null, $zone['province'] ?? null]);
                                        echo $coverageParts !== [] ? e(implode(', ', $coverageParts)) : '-';
                                        ?>
                                    </td>
                                    <td><?php echo (int) $zone['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>'; ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary edit-zone-action" type="button" data-bs-toggle="modal" data-bs-target="#editZoneModal"
                                            data-zone-id="<?php echo (int) $zone['id']; ?>"
                                            data-zone-name="<?php echo e((string) $zone['zone_name']); ?>"
                                            data-classification="<?php echo e((string) $zone['classification']); ?>"
                                            data-province="<?php echo e((string) ($zone['province'] ?? '')); ?>"
                                            data-municipality="<?php echo e((string) ($zone['municipality'] ?? '')); ?>"
                                            data-barangay="<?php echo e((string) ($zone['barangay'] ?? '')); ?>"
                                            data-district="<?php echo e((string) ($zone['district'] ?? '')); ?>"
                                            data-coverage="<?php echo e((string) ($zone['coverage_description'] ?? '')); ?>"
                                            data-notes="<?php echo e((string) ($zone['notes'] ?? '')); ?>"
                                            data-is-active="<?php echo (int) $zone['is_active']; ?>"
                                        ><i class="bi bi-pencil"></i> Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="modal fade" id="editZoneModal" tabindex="-1" aria-labelledby="editZoneModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="area-management-action.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="editZoneModalLabel">Edit Zone</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_zone">
                        <input type="hidden" name="zone_id" id="editZoneId" value="">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="editZoneName">Zone name</label><input class="form-control" id="editZoneName" type="text" name="zone_name" maxlength="150" required></div>
                            <div class="col-md-6">
                                <label class="form-label" for="editClassification">Classification</label>
                                <select class="form-select" id="editClassification" name="classification" required>
                                    <?php foreach ($classifications as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="editCoverage">Coverage description</label><input class="form-control" id="editCoverage" type="text" name="coverage_description" maxlength="1000"></div>
                            <div class="col-md-6"><label class="form-label" for="editNotes">Notes</label><textarea class="form-control" id="editNotes" name="notes" rows="1" maxlength="1000"></textarea></div>
                            <div class="col-12">
                                <label class="form-label mb-1">Zone boundary <span class="text-danger">*</span></label>
                                <p class="text-secondary small mb-2">Draw or adjust the boundary — location is re-detected when you redraw it.</p>
                                <input type="hidden" name="boundary_geojson" id="editBoundaryInput">
                                <div id="editBoundaryEditor" class="geo-map geo-map-compact"></div>
                            </div>
                            <div class="col-md-3"><label class="form-label" for="editProvince">Province</label><input class="form-control" id="editProvince" type="text" name="province" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-md-3"><label class="form-label" for="editMunicipality">Municipality</label><input class="form-control" id="editMunicipality" type="text" name="municipality" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-md-3"><label class="form-label" for="editBarangay">Barangay</label><input class="form-control" id="editBarangay" type="text" name="barangay" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-md-3"><label class="form-label" for="editDistrict">District</label><input class="form-control" id="editDistrict" type="text" name="district" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-12"><span class="small text-secondary" id="editLocationStatus" role="status"></span></div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="editIsActive" name="is_active" value="1">
                                    <label class="form-check-label" for="editIsActive">Active</label>
                                </div>
                            </div>
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

    <script type="application/json" id="zoneMapData"><?php echo json_encode($zoneMapFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script type="application/json" id="zoneBoundaryData"><?php
        $zoneBoundaryById = [];
        foreach ($zones as $zone) {
            $zoneBoundaryById[(string) $zone['id']] = (string) ($zone['boundary_geojson'] ?? '');
        }
        echo json_encode($zoneBoundaryById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.min.js"></script>
    <script src="../../js/geo-map.js"></script>
    <script>
        var zoneFeatures = CertreefyGeo.readJson('zoneMapData', []);
        var zoneBoundaries = CertreefyGeo.readJson('zoneBoundaryData', {});

        // Legislative district lookup by municipality (Laguna composition is
        // stable public information; OSM has no legislative-district layer).
        var LAGUNA_DISTRICTS = {
            'san pedro': '1st District', 'binan': '1st District', 'santa rosa': '1st District',
            'bay': '2nd District', 'cabuyao': '2nd District', 'calamba': '2nd District', 'los banos': '2nd District',
            'alaminos': '3rd District', 'calauan': '3rd District', 'liliw': '3rd District',
            'nagcarlan': '3rd District', 'rizal': '3rd District', 'san pablo': '3rd District', 'victoria': '3rd District',
            'cavinti': '4th District', 'famy': '4th District', 'kalayaan': '4th District', 'luisiana': '4th District',
            'lumban': '4th District', 'mabitac': '4th District', 'magdalena': '4th District', 'majayjay': '4th District',
            'paete': '4th District', 'pagsanjan': '4th District', 'pakil': '4th District', 'pangil': '4th District',
            'pila': '4th District', 'santa cruz': '4th District', 'santa maria': '4th District', 'siniloan': '4th District'
        };
        function lagunaDistrict(province, municipality) {
            if (!/laguna/i.test(province || '') || !municipality) { return ''; }
            var key = municipality.toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/\b(city of|city)\b/g, '').replace(/\s+/g, ' ').trim();
            return LAGUNA_DISTRICTS[key] || '';
        }

        function fillZoneLocation(prefix, center) {
            var status = document.getElementById(prefix + 'LocationStatus');
            var province = document.getElementById(prefix + 'Province');
            var municipality = document.getElementById(prefix + 'Municipality');
            var barangay = document.getElementById(prefix + 'Barangay');
            var district = document.getElementById(prefix + 'District');
            if (center === null) {
                province.value = ''; municipality.value = ''; barangay.value = ''; district.value = '';
                if (status) { status.textContent = ''; }
                return;
            }
            if (status) { status.textContent = 'Detecting location from the boundary center…'; }
            CertreefyGeo.reverseGeocode(center[0], center[1], function (place) {
                if (!place) {
                    if (status) { status.textContent = 'Could not detect the location automatically. The boundary is still saved.'; }
                    return;
                }
                province.value = place.province || '';
                municipality.value = place.municipality || '';
                barangay.value = place.barangay || '';
                district.value = lagunaDistrict(place.province, place.municipality);
                if (status) { status.textContent = 'Location detected from the boundary center (OpenStreetMap / Nominatim).'; }
            });
        }
        function debounce(fn, ms) {
            var timer = null;
            return function (arg) { clearTimeout(timer); timer = setTimeout(function () { fn(arg); }, ms); };
        }

        CertreefyGeo.display('zonesOverviewMap', { zones: zoneFeatures });
        CertreefyGeo.zoneEditor('zoneBoundaryEditor', {
            geojsonInput: 'zoneBoundaryInput',
            classification: document.getElementById('zoneClassification').value,
            zones: zoneFeatures,
            onChange: debounce(function (center) { fillZoneLocation('zone', center); }, 600)
        });

        // Add-zone form: block submission until a boundary is drawn.
        document.querySelector('form[action="area-management-action.php"]').addEventListener('submit', function (event) {
            if (!document.getElementById('zoneBoundaryInput').value) {
                event.preventDefault();
                var status = document.getElementById('zoneLocationStatus');
                if (status) { status.textContent = 'Please draw the zone boundary on the map before saving.'; }
                document.getElementById('zoneBoundaryEditor').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // The edit-modal map is rebuilt per open so it always reflects the
        // selected zone (Leaflet cannot re-initialize an existing container).
        var editBoundaryMap = null;
        var editModal = document.getElementById('editZoneModal');
        editModal.addEventListener('shown.bs.modal', function () {
            if (editBoundaryMap !== null) {
                editBoundaryMap.remove();
                editBoundaryMap = null;
            }
            editBoundaryMap = CertreefyGeo.zoneEditor('editBoundaryEditor', {
                geojsonInput: 'editBoundaryInput',
                classification: document.getElementById('editClassification').value,
                onChange: debounce(function (center) { fillZoneLocation('edit', center); }, 600)
            });
        });

        document.querySelectorAll('.edit-zone-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('editZoneId').value = button.dataset.zoneId;
                document.getElementById('editZoneName').value = button.dataset.zoneName;
                document.getElementById('editClassification').value = button.dataset.classification;
                document.getElementById('editProvince').value = button.dataset.province || '';
                document.getElementById('editMunicipality').value = button.dataset.municipality || '';
                document.getElementById('editBarangay').value = button.dataset.barangay || '';
                document.getElementById('editDistrict').value = button.dataset.district || '';
                document.getElementById('editCoverage').value = button.dataset.coverage || '';
                document.getElementById('editNotes').value = button.dataset.notes || '';
                document.getElementById('editIsActive').checked = button.dataset.isActive === '1';
                document.getElementById('editBoundaryInput').value = zoneBoundaries[button.dataset.zoneId] || '';
                document.getElementById('editLocationStatus').textContent = '';
            });
        });

        // Edit modal: block save when the boundary was deleted and not redrawn.
        document.querySelector('#editZoneModal form').addEventListener('submit', function (event) {
            if (!document.getElementById('editBoundaryInput').value) {
                event.preventDefault();
                document.getElementById('editLocationStatus').textContent = 'Please draw the zone boundary on the map before saving.';
            }
        });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
