<?php
/**
 * EMS Planting Sites: advisory registry of recommended seedling planting
 * locations with editable soil/moisture/season attributes and drawn map
 * boundaries. Recommendations surface read-only on the Community
 * seedling-request page.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/planting_sites.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';

if (planting_site_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to manage planting sites.');
}

if (empty($_SESSION['csrf_planting_site_token'])) {
    $_SESSION['csrf_planting_site_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_planting_site_token'];

$flash = null;
if (!empty($_SESSION['planting_site_flash']) && is_array($_SESSION['planting_site_flash'])) {
    $flash = $_SESSION['planting_site_flash'];
    unset($_SESSION['planting_site_flash']);
}

$moistureLevels = planting_site_moisture_levels();
$months = planting_site_months();
$filters = ['q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100)];

try {
    $sites = planting_site_list($pdo, $filters);
    $siteMapFeatures = planting_site_map_features($pdo);
    $activeCount = count(array_filter($sites, static fn (array $s): bool => (int) $s['is_active'] === 1));
    $inventorySpeciesNames = array_values(array_map(
        static fn (array $row): string => (string) $row['common_name'],
        seedling_inventory_list($pdo, true)
    ));
} catch (PDOException $e) {
    error_log('[CERTREEFY PLANTING SITES LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load planting sites at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Planting Sites</title>

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
        <?php render_certreefy_navigation($currentRole, 'planting_sites'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Planting Sites</h1>
                        <p class="text-secondary meta-copy mb-0">Recommended seedling planting locations — where to plant and why (soil, moisture, season).</p>
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

            <div class="alert alert-light border mb-4"><i class="bi bi-info-circle me-1"></i>Shown to Community users on the Seedling Request page. Soil/moisture/season may start from public datasets — refine with field knowledge; every field is editable.</div>

            <section class="docket-panel mb-4" aria-labelledby="siteMapHeading">
                <div class="section-heading">
                    <h2 id="siteMapHeading">Recommended Sites Map</h2>
                    <span class="section-note tabular"><?php echo (int) $activeCount; ?> active site<?php echo $activeCount === 1 ? '' : 's'; ?></span>
                </div>
                <div id="sitesOverviewMap" class="geo-map geo-map-tall" role="img" aria-label="Map of recommended planting sites"></div>
                <div class="geo-legend"><span><span class="legend-swatch" style="background:#2c6e8f"></span>Recommended planting site</span></div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="addSiteHeading">
                <div class="section-heading"><h2 id="addSiteHeading">Add Planting Site</h2></div>
                <form method="post" action="planting-site-action.php" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_site">
                    <div class="col-md-6"><label class="form-label" for="siteName">Site name</label><input class="form-control" id="siteName" type="text" name="site_name" maxlength="150" required></div>
                    <div class="col-md-2"><label class="form-label" for="siteSoilType">Soil type</label><input class="form-control" id="siteSoilType" type="text" name="soil_type" maxlength="100" placeholder="e.g. Clay loam"></div>
                    <div class="col-md-2"><label class="form-label" for="siteSoilPh">Soil pH</label><input class="form-control" id="siteSoilPh" type="text" name="soil_ph" maxlength="50" placeholder="e.g. 5.5–6.5"></div>
                    <div class="col-md-2">
                        <label class="form-label" for="siteMoisture">Moisture level</label>
                        <select class="form-select" id="siteMoisture" name="moisture_level">
                            <option value="">Not specified</option>
                            <?php foreach ($moistureLevels as $value => $label): ?>
                                <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label mb-1">Recommended planting season</label>
                        <p class="text-secondary small mb-2">Add one or more month ranges (e.g. January – March, October – November).</p>
                        <div id="siteSeasonRows" class="d-grid gap-2"></div>
                        <button type="button" class="btn btn-sm btn-outline-success mt-2" data-add-season="siteSeasonRows"><i class="bi bi-plus-circle me-1"></i>Add month range</button>
                    </div>

                    <div class="col-12">
                        <label class="form-label mb-1">Suitable species</label>
                        <p class="text-secondary small mb-2">Choose from the seed inventory, or pick “Others…” to name a species not yet in inventory.</p>
                        <div id="siteSpeciesRows" class="d-grid gap-2"></div>
                        <button type="button" class="btn btn-sm btn-outline-success mt-2" data-add-species="siteSpeciesRows"><i class="bi bi-plus-circle me-1"></i>Add species</button>
                    </div>

                    <div class="col-md-6"><label class="form-label" for="siteDataSource">Data source (optional)</label><input class="form-control" id="siteDataSource" type="text" name="data_source" maxlength="255" placeholder="e.g. SoilGrids 2020 + EMS field visit"></div>
                    <div class="col-12"><label class="form-label" for="siteRationale">Why plant here? (shown to the public)</label><textarea class="form-control" id="siteRationale" name="rationale" rows="2" maxlength="1000" placeholder="e.g. Moist clay-loam riverbank soil suits Narra; plant at the onset of the rainy season for best survival."></textarea></div>

                    <div class="col-12">
                        <label class="form-label mb-1">Site boundary <span class="text-danger">*</span></label>
                        <p class="text-secondary small mb-2">Draw the planting area with the polygon or rectangle tool. Location is detected automatically from the boundary center.</p>
                        <input type="hidden" name="boundary_geojson" id="siteBoundaryInput">
                        <div id="siteBoundaryEditor" class="geo-map"></div>
                    </div>
                    <div class="col-md-4"><label class="form-label" for="siteProvince">Province</label><input class="form-control" id="siteProvince" type="text" name="province" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-md-4"><label class="form-label" for="siteMunicipality">Municipality</label><input class="form-control" id="siteMunicipality" type="text" name="municipality" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-md-4"><label class="form-label" for="siteBarangay">Barangay</label><input class="form-control" id="siteBarangay" type="text" name="barangay" maxlength="100" readonly placeholder="Detected from boundary"></div>
                    <div class="col-12"><span class="small text-secondary" id="siteLocationStatus" role="status"></span></div>

                    <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-plus-circle me-1"></i>Add planting site</button></div>
                </form>
            </section>

            <section class="docket-panel mb-4" aria-label="Planting site search">
                <form method="get" action="planting-sites.php" class="row g-3 align-items-end">
                    <div class="col-lg-9">
                        <label for="siteSearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="siteSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Site name, municipality, barangay, or species">
                        </div>
                    </div>
                    <div class="col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Search</button>
                        <a class="btn btn-outline-secondary" href="planting-sites.php" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="siteRegistryHeading">
                <div class="section-heading">
                    <h2 id="siteRegistryHeading">Planting Site Registry</h2>
                    <span class="section-note tabular"><?php echo count($sites); ?> site<?php echo count($sites) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Site</th>
                                <th scope="col">Location</th>
                                <th scope="col">Soil / Moisture</th>
                                <th scope="col">Season</th>
                                <th scope="col">Suitable species</th>
                                <th scope="col" data-tt-filter="Status">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sites === []): ?>
                                <tr><td colspan="7" class="text-center text-secondary py-5">No planting sites recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($sites as $site): ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $site['site_name']); ?></td>
                                    <td class="small text-secondary">
                                        <?php
                                        $locationParts = array_filter([$site['barangay'] ?? null, $site['municipality'] ?? null, $site['province'] ?? null]);
                                        echo $locationParts !== [] ? e(implode(', ', $locationParts)) : '-';
                                        ?>
                                    </td>
                                    <td class="small text-secondary">
                                        <?php
                                        $soilParts = array_filter([
                                            $site['soil_type'] !== null ? (string) $site['soil_type'] : null,
                                            $site['soil_ph'] !== null ? 'pH ' . (string) $site['soil_ph'] : null,
                                            $site['moisture_level'] !== null ? planting_site_moisture_label((string) $site['moisture_level']) : null,
                                        ]);
                                        echo $soilParts !== [] ? e(implode(' · ', $soilParts)) : '-';
                                        ?>
                                    </td>
                                    <td class="small text-secondary text-break"><?php echo $site['recommended_season'] !== null ? e((string) $site['recommended_season']) : '-'; ?></td>
                                    <td class="small text-secondary text-break"><?php echo $site['suitable_species'] !== null ? e((string) $site['suitable_species']) : '-'; ?></td>
                                    <td><?php echo (int) $site['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>'; ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary edit-site-action" type="button" data-bs-toggle="modal" data-bs-target="#editSiteModal"
                                            data-site-id="<?php echo (int) $site['id']; ?>"
                                            data-site-name="<?php echo e((string) $site['site_name']); ?>"
                                            data-province="<?php echo e((string) ($site['province'] ?? '')); ?>"
                                            data-municipality="<?php echo e((string) ($site['municipality'] ?? '')); ?>"
                                            data-barangay="<?php echo e((string) ($site['barangay'] ?? '')); ?>"
                                            data-soil-type="<?php echo e((string) ($site['soil_type'] ?? '')); ?>"
                                            data-soil-ph="<?php echo e((string) ($site['soil_ph'] ?? '')); ?>"
                                            data-moisture="<?php echo e((string) ($site['moisture_level'] ?? '')); ?>"
                                            data-season="<?php echo e((string) ($site['recommended_season'] ?? '')); ?>"
                                            data-species="<?php echo e((string) ($site['suitable_species'] ?? '')); ?>"
                                            data-rationale="<?php echo e((string) ($site['rationale'] ?? '')); ?>"
                                            data-source="<?php echo e((string) ($site['data_source'] ?? '')); ?>"
                                            data-is-active="<?php echo (int) $site['is_active']; ?>"
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

    <div class="modal fade" id="editSiteModal" tabindex="-1" aria-labelledby="editSiteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="planting-site-action.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="editSiteModalLabel">Edit Planting Site</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_site">
                        <input type="hidden" name="site_id" id="editSiteId" value="">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="editSiteName">Site name</label><input class="form-control" id="editSiteName" type="text" name="site_name" maxlength="150" required></div>
                            <div class="col-md-2"><label class="form-label" for="editSiteSoilType">Soil type</label><input class="form-control" id="editSiteSoilType" type="text" name="soil_type" maxlength="100"></div>
                            <div class="col-md-2"><label class="form-label" for="editSiteSoilPh">Soil pH</label><input class="form-control" id="editSiteSoilPh" type="text" name="soil_ph" maxlength="50"></div>
                            <div class="col-md-2">
                                <label class="form-label" for="editSiteMoisture">Moisture level</label>
                                <select class="form-select" id="editSiteMoisture" name="moisture_level">
                                    <option value="">Not specified</option>
                                    <?php foreach ($moistureLevels as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-1">Recommended planting season</label>
                                <div id="editSiteSeasonRows" class="d-grid gap-2"></div>
                                <button type="button" class="btn btn-sm btn-outline-success mt-2" data-add-season="editSiteSeasonRows"><i class="bi bi-plus-circle me-1"></i>Add month range</button>
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-1">Suitable species</label>
                                <div id="editSiteSpeciesRows" class="d-grid gap-2"></div>
                                <button type="button" class="btn btn-sm btn-outline-success mt-2" data-add-species="editSiteSpeciesRows"><i class="bi bi-plus-circle me-1"></i>Add species</button>
                            </div>
                            <div class="col-12"><label class="form-label" for="editSiteRationale">Why plant here? (shown to the public)</label><textarea class="form-control" id="editSiteRationale" name="rationale" rows="2" maxlength="1000"></textarea></div>
                            <div class="col-12"><label class="form-label" for="editSiteDataSource">Data source</label><input class="form-control" id="editSiteDataSource" type="text" name="data_source" maxlength="255"></div>
                            <div class="col-12">
                                <label class="form-label mb-1">Site boundary <span class="text-danger">*</span></label>
                                <p class="text-secondary small mb-2">Draw, adjust, or delete the boundary — location re-detects when you redraw it.</p>
                                <input type="hidden" name="boundary_geojson" id="editSiteBoundaryInput">
                                <div id="editSiteBoundaryEditor" class="geo-map geo-map-compact"></div>
                            </div>
                            <div class="col-md-4"><label class="form-label" for="editSiteProvince">Province</label><input class="form-control" id="editSiteProvince" type="text" name="province" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-md-4"><label class="form-label" for="editSiteMunicipality">Municipality</label><input class="form-control" id="editSiteMunicipality" type="text" name="municipality" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-md-4"><label class="form-label" for="editSiteBarangay">Barangay</label><input class="form-control" id="editSiteBarangay" type="text" name="barangay" maxlength="100" readonly placeholder="Detected from boundary"></div>
                            <div class="col-12"><span class="small text-secondary" id="editSiteLocationStatus" role="status"></span></div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="editSiteIsActive" name="is_active" value="1">
                                    <label class="form-check-label" for="editSiteIsActive">Active (visible to Community users)</label>
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

    <script type="application/json" id="siteMapData"><?php echo json_encode($siteMapFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script type="application/json" id="monthsData"><?php echo json_encode($months, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script type="application/json" id="speciesData"><?php echo json_encode($inventorySpeciesNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script type="application/json" id="siteBoundaryData"><?php
        $siteBoundaryById = [];
        foreach ($sites as $site) {
            $siteBoundaryById[(string) $site['id']] = (string) ($site['boundary_geojson'] ?? '');
        }
        echo json_encode($siteBoundaryById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.min.js"></script>
    <script src="../../js/geo-map.js"></script>
    <script>
        var siteFeatures = CertreefyGeo.readJson('siteMapData', []);
        var siteBoundaries = CertreefyGeo.readJson('siteBoundaryData', {});
        var MONTHS = CertreefyGeo.readJson('monthsData', []);
        var SPECIES = CertreefyGeo.readJson('speciesData', []);
        var OTHER = '__other__';

        function escapeAttr(value) {
            return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ---- Month-range (recommended season) rows ----
        function monthOptions(selected) {
            var html = '<option value="">Month</option>';
            MONTHS.forEach(function (m) {
                html += '<option value="' + escapeAttr(m) + '"' + (m === selected ? ' selected' : '') + '>' + escapeAttr(m) + '</option>';
            });
            return html;
        }
        function addSeasonRow(container, from, to) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center';
            row.setAttribute('data-season-row', '');
            row.innerHTML =
                '<div class="col"><select class="form-select" name="season_from[]" aria-label="Start month">' + monthOptions(from || '') + '</select></div>'
                + '<div class="col-auto text-secondary small">to</div>'
                + '<div class="col"><select class="form-select" name="season_to[]" aria-label="End month">' + monthOptions(to || '') + '</select></div>'
                + '<div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove month range"><i class="bi bi-x-lg"></i></button></div>';
            container.appendChild(row);
        }
        function initSeasonRows(container, seasonString) {
            container.innerHTML = '';
            var added = 0;
            String(seasonString || '').split(',').forEach(function (part) {
                var bits = part.split(/\s*[-–]\s*/);
                var from = (bits[0] || '').trim();
                var to = (bits[1] || '').trim();
                if (from || to) { addSeasonRow(container, from, to); added++; }
            });
            if (added === 0) { addSeasonRow(container, '', ''); }
        }

        // ---- Suitable-species rows ----
        function speciesOptions(selectedName) {
            var inList = selectedName && SPECIES.some(function (s) { return s === selectedName; });
            var html = '<option value="">Select species</option>';
            SPECIES.forEach(function (s) {
                html += '<option value="' + escapeAttr(s) + '"' + (s === selectedName ? ' selected' : '') + '>' + escapeAttr(s) + '</option>';
            });
            html += '<option value="' + OTHER + '"' + (selectedName && !inList ? ' selected' : '') + '>Others…</option>';
            return html;
        }
        function addSpeciesRow(container, name) {
            var inList = name && SPECIES.some(function (s) { return s === name; });
            var isOther = name && !inList;
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center';
            row.setAttribute('data-species-row', '');
            row.innerHTML =
                '<div class="col"><select class="form-select" name="species[]" data-species-select aria-label="Species">' + speciesOptions(name || '') + '</select></div>'
                + '<div class="col' + (isOther ? '' : ' d-none') + '" data-species-other-wrap>'
                + '<input class="form-control" type="text" name="species_other[]" maxlength="150" placeholder="Other species name" value="' + escapeAttr(isOther ? name : '') + '"></div>'
                + '<div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove species"><i class="bi bi-x-lg"></i></button></div>';
            container.appendChild(row);
        }
        function initSpeciesRows(container, speciesString) {
            container.innerHTML = '';
            var added = 0;
            String(speciesString || '').split(',').forEach(function (part) {
                var name = part.trim();
                if (name) { addSpeciesRow(container, name); added++; }
            });
            if (added === 0) { addSpeciesRow(container, ''); }
        }

        // ---- Delegated controls: add / remove / "Others" toggle ----
        document.addEventListener('click', function (event) {
            var addSeason = event.target.closest('[data-add-season]');
            if (addSeason) { addSeasonRow(document.getElementById(addSeason.dataset.addSeason), '', ''); return; }
            var addSpecies = event.target.closest('[data-add-species]');
            if (addSpecies) { addSpeciesRow(document.getElementById(addSpecies.dataset.addSpecies), ''); return; }
            var remove = event.target.closest('[data-remove-row]');
            if (remove) {
                var row = remove.closest('[data-season-row], [data-species-row]');
                var host = row.parentElement;
                row.remove();
                if (host.querySelectorAll('[data-season-row], [data-species-row]').length === 0) {
                    if (host.id.indexOf('Season') !== -1) { addSeasonRow(host, '', ''); } else { addSpeciesRow(host, ''); }
                }
            }
        });
        document.addEventListener('change', function (event) {
            var select = event.target.closest('[data-species-select]');
            if (!select) { return; }
            var wrap = select.closest('[data-species-row]').querySelector('[data-species-other-wrap]');
            var isOther = select.value === OTHER;
            wrap.classList.toggle('d-none', !isOther);
            if (!isOther) { wrap.querySelector('input').value = ''; }
        });

        // ---- Reverse geocoding of the boundary center into the location fields ----
        function fillLocation(prefix, center) {
            var status = document.getElementById(prefix + 'LocationStatus');
            var province = document.getElementById(prefix + 'Province');
            var municipality = document.getElementById(prefix + 'Municipality');
            var barangay = document.getElementById(prefix + 'Barangay');
            if (center === null) {
                province.value = ''; municipality.value = ''; barangay.value = '';
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
                if (status) {
                    status.textContent = 'Location detected from the boundary center (OpenStreetMap / Nominatim).';
                }
            });
        }
        function debounce(fn, ms) {
            var timer = null;
            return function (arg) { clearTimeout(timer); timer = setTimeout(function () { fn(arg); }, ms); };
        }

        // Overview: boundaries plus center markers with the "why" in popups.
        CertreefyGeo.display('sitesOverviewMap', {
            zones: siteFeatures,
            points: siteFeatures.filter(function (s) { return s.center_lat !== null && s.center_lng !== null; }).map(function (s) {
                var detail = [];
                if (s.soil_type) { detail.push('Soil: ' + s.soil_type); }
                if (s.moisture_level) { detail.push('Moisture: ' + s.moisture_level); }
                if (s.recommended_season) { detail.push('Season: ' + s.recommended_season); }
                if (s.suitable_species) { detail.push('Species: ' + s.suitable_species); }
                return { lat: s.center_lat, lng: s.center_lng, label: s.name + (detail.length ? ' — ' + detail.join(' · ') : '') };
            })
        });

        // Add-form: seed one empty row for season and species, and the boundary editor.
        initSeasonRows(document.getElementById('siteSeasonRows'), '');
        initSpeciesRows(document.getElementById('siteSpeciesRows'), '');
        CertreefyGeo.zoneEditor('siteBoundaryEditor', {
            geojsonInput: 'siteBoundaryInput',
            classification: 'planting',
            zones: siteFeatures,
            onChange: debounce(function (center) { fillLocation('site', center); }, 600)
        });

        // Add-form: block submission until a boundary is drawn.
        document.querySelector('form[action="planting-site-action.php"]').addEventListener('submit', function (event) {
            if (!document.getElementById('siteBoundaryInput').value) {
                event.preventDefault();
                var status = document.getElementById('siteLocationStatus');
                if (status) { status.textContent = 'Please draw the site boundary on the map before saving.'; }
                document.getElementById('siteBoundaryEditor').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Edit modal: rebuild the boundary editor per open so Leaflet sizes against a visible container.
        var editSiteBoundaryMap = null;
        document.getElementById('editSiteModal').addEventListener('shown.bs.modal', function () {
            if (editSiteBoundaryMap !== null) {
                editSiteBoundaryMap.remove();
                editSiteBoundaryMap = null;
            }
            editSiteBoundaryMap = CertreefyGeo.zoneEditor('editSiteBoundaryEditor', {
                geojsonInput: 'editSiteBoundaryInput',
                classification: 'planting',
                onChange: debounce(function (center) { fillLocation('editSite', center); }, 600)
            });
        });

        document.querySelectorAll('.edit-site-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('editSiteId').value = button.dataset.siteId;
                document.getElementById('editSiteName').value = button.dataset.siteName;
                document.getElementById('editSiteProvince').value = button.dataset.province || '';
                document.getElementById('editSiteMunicipality').value = button.dataset.municipality || '';
                document.getElementById('editSiteBarangay').value = button.dataset.barangay || '';
                document.getElementById('editSiteSoilType').value = button.dataset.soilType || '';
                document.getElementById('editSiteSoilPh').value = button.dataset.soilPh || '';
                document.getElementById('editSiteMoisture').value = button.dataset.moisture || '';
                initSeasonRows(document.getElementById('editSiteSeasonRows'), button.dataset.season || '');
                initSpeciesRows(document.getElementById('editSiteSpeciesRows'), button.dataset.species || '');
                document.getElementById('editSiteRationale').value = button.dataset.rationale || '';
                document.getElementById('editSiteDataSource').value = button.dataset.source || '';
                document.getElementById('editSiteIsActive').checked = button.dataset.isActive === '1';
                document.getElementById('editSiteBoundaryInput').value = siteBoundaries[button.dataset.siteId] || '';
                document.getElementById('editSiteLocationStatus').textContent = '';
            });
        });

        // Edit modal: block save when the boundary was deleted and not redrawn.
        document.querySelector('#editSiteModal form').addEventListener('submit', function (event) {
            if (!document.getElementById('editSiteBoundaryInput').value) {
                event.preventDefault();
                document.getElementById('editSiteLocationStatus').textContent = 'Please draw the site boundary on the map before saving.';
            }
        });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
