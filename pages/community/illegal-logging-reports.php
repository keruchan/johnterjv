<?php
/** Community illegal-logging report submission and personal registry. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Community User';

if (empty($_SESSION['csrf_illegal_logging_submit_token'])) {
    $_SESSION['csrf_illegal_logging_submit_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_illegal_logging_submit_token'];
$submissionKey = new_illegal_logging_submission_key();

$flash = null;
if (!empty($_SESSION['illegal_logging_submit_flash']) && is_array($_SESSION['illegal_logging_submit_flash'])) {
    $flash = $_SESSION['illegal_logging_submit_flash'];
    unset($_SESSION['illegal_logging_submit_flash']);
}

try {
    $myReports = illegal_logging_reports_for_reporter($pdo, $userId);
} catch (PDOException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING PAGE ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load the illegal-logging report program at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Illegal Logging Report</title>

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
        <?php render_certreefy_navigation($currentRole, 'report_logging'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Community</div>
                        <h1 class="page-title">Illegal Logging Report</h1>
                        <p class="text-secondary meta-copy mb-0">Report suspected illegal cutting activity to CENRO enforcement.</p>
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

            <section class="docket-panel mb-4" aria-labelledby="newReportHeading">
                <div class="section-heading"><h2 id="newReportHeading">Report an Incident</h2></div>
                <div class="alert alert-light border mb-3"><i class="bi bi-info-circle me-1"></i>Routed directly to CENRO enforcement. A field team may verify the site — track status here.</div>

                <form method="post" action="illegal-logging-report-action.php" class="row g-3" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="submission_key" value="<?php echo e($submissionKey); ?>">

                    <div class="col-md-8">
                        <label class="form-label" for="incidentLocation">Incident location</label>
                        <input class="form-control" id="incidentLocation" type="text" name="incident_location" maxlength="500" placeholder="e.g. Barangay, municipality, landmark" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="observedOn">Date observed (optional)</label>
                        <input class="form-control" id="observedOn" type="date" name="observed_on" max="<?php echo e(date('Y-m-d')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="latitude">Latitude (optional)</label>
                        <input class="form-control" id="latitude" type="text" name="latitude" placeholder="e.g. 14.2781" inputmode="decimal">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="longitude">Longitude (optional)</label>
                        <input class="form-control" id="longitude" type="text" name="longitude" placeholder="e.g. 121.4153" inputmode="decimal">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Pin the incident on the map (optional)</label>
                        <p class="text-secondary small mb-2">Click the map (or use &#9737; for your current location) to set coordinates. Drag the pin to adjust.</p>
                        <div id="incidentMap" class="geo-map geo-map-compact"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="incidentDescription">Description</label>
                        <textarea class="form-control" id="incidentDescription" name="incident_description" rows="4" maxlength="2000" placeholder="What did you see or hear? Include any details that may help enforcement." required></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="evidencePhotos">Evidence photos (optional)</label>
                        <input class="form-control" id="evidencePhotos" type="file" name="evidence_photos[]" accept=".jpg,.jpeg,.png" multiple>
                        <div class="form-text">JPG or PNG, up to 10 photos, 10 MB each. Stored privately and shared only with CENRO enforcement.</div>
                    </div>
                    <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-send"></i> Submit report</button></div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="myReportsHeading">
                <div class="section-heading">
                    <h2 id="myReportsHeading">My Reports</h2>
                    <span class="section-note tabular"><?php echo count($myReports); ?> report<?php echo count($myReports) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search-placeholder="Search reference or location">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Location</th>
                                <th scope="col" data-tt-filter="Status">Status</th>
                                <th scope="col" data-tt-filter="Outcome">Outcome</th>
                                <th scope="col">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($myReports === []): ?>
                                <tr><td colspan="5" class="text-center text-secondary py-5">You have not submitted any illegal-logging reports yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($myReports as $myReport): ?>
                                <?php $myStatus = (string) $myReport['current_status']; ?>
                                <tr>
                                    <td class="fw-semibold text-break"><?php echo e((string) $myReport['report_reference']); ?></td>
                                    <td class="text-break"><?php echo e((string) $myReport['incident_location']); ?></td>
                                    <td><span class="badge <?php echo e(illegal_logging_report_status_badge($myStatus)); ?>"><?php echo e(illegal_logging_report_status_label($myStatus)); ?></span></td>
                                    <td>
                                        <?php if (!empty($myReport['resolution_outcome'])): ?>
                                            <span class="badge <?php echo e(illegal_logging_resolution_outcome_badge((string) $myReport['resolution_outcome'])); ?>"><?php echo e(illegal_logging_resolution_outcome_label((string) $myReport['resolution_outcome'])); ?></span>
                                        <?php else: ?>
                                            <span class="small text-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-nowrap"><?php echo e(date('M j, Y', strtotime((string) $myReport['submitted_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/geo-map.js"></script>
    <script>
        CertreefyGeo.picker('incidentMap', { latInput: 'latitude', lngInput: 'longitude' });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
