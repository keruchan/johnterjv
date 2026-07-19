<?php
/** CENRO illegal-logging report detail: full record plus state-appropriate processing action. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';
require_once __DIR__ . '/../../includes/area_management.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Officer';

$reportValue = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($reportValue) || (int) $reportValue < 1) {
    http_response_code(404);
    die('The illegal-logging report was not found.');
}
$reportId = (int) $reportValue;

try {
    $report = illegal_logging_report_for_actor($pdo, $reportId, $userId);
    if ($report === null) {
        http_response_code(404);
        die('The illegal-logging report was not found.');
    }
    $photos = illegal_logging_report_photos($pdo, $reportId);
    $history = illegal_logging_report_history($pdo, $reportId);
    $personnel = illegal_logging_processing_personnel($pdo);
    $zoneMapFeatures = area_zone_map_features($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING DETAIL ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load this report at this time. Please try again later.');
}

if (empty($_SESSION['csrf_illegal_logging_process_token'])) {
    $_SESSION['csrf_illegal_logging_process_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_illegal_logging_process_token'];

$flash = null;
if (!empty($_SESSION['illegal_logging_process_flash']) && is_array($_SESSION['illegal_logging_process_flash'])) {
    $flash = $_SESSION['illegal_logging_process_flash'];
    unset($_SESSION['illegal_logging_process_flash']);
}

$status = (string) $report['current_status'];
$outcomes = illegal_logging_resolution_outcomes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Report <?php echo e((string) $report['report_reference']); ?></title>

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
        <?php render_certreefy_navigation($currentRole, 'logging_reports'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Illegal Logging Report</div>
                        <h1 class="page-title"><?php echo e((string) $report['report_reference']); ?></h1>
                        <p class="text-secondary meta-copy mb-0"><a href="illegal-logging-reports.php"><i class="bi bi-arrow-left"></i> Back to queue</a></p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?>
                        <span class="badge fs-6 <?php echo e(illegal_logging_report_status_badge($status)); ?>"><?php echo e(illegal_logging_report_status_label($status)); ?></span>
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

            <section class="docket-panel mb-4" aria-labelledby="reportSummaryHeading">
                <div class="section-heading"><h2 id="reportSummaryHeading">Report Summary</h2></div>
                <div class="row g-3">
                    <div class="col-md-4"><div class="small text-secondary">Reporter</div><div class="fw-semibold"><?php echo e((string) $report['reporter_full_name']); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Contact</div><div class="fw-semibold"><?php echo e((string) ($report['reporter_contact'] ?? '-')); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Submitted</div><div class="fw-semibold"><?php echo e(date('M j, Y g:i A', strtotime((string) $report['submitted_at']))); ?></div></div>
                    <div class="col-md-8"><div class="small text-secondary">Incident location</div><div class="fw-semibold text-break"><?php echo e((string) $report['incident_location']); ?></div></div>
                    <div class="col-md-4"><div class="small text-secondary">Date observed</div><div class="fw-semibold"><?php echo $report['observed_on'] !== null ? e(date('M j, Y', strtotime((string) $report['observed_on']))) : '-'; ?></div></div>
                    <?php if ($report['latitude'] !== null && $report['longitude'] !== null): ?>
                        <div class="col-12"><div class="small text-secondary">Coordinates</div><div class="fw-semibold"><?php echo e((string) $report['latitude']); ?>, <?php echo e((string) $report['longitude']); ?></div></div>
                    <?php endif; ?>
                    <div class="col-12"><div class="small text-secondary">Description</div><div class="text-break"><?php echo nl2br(e((string) $report['incident_description'])); ?></div></div>
                    <?php if (!empty($report['assigned_to_name'])): ?>
                        <div class="col-md-6"><div class="small text-secondary">Assigned to</div><div class="fw-semibold"><?php echo e((string) $report['assigned_to_name']); ?></div></div>
                    <?php endif; ?>
                    <?php if ($report['latitude'] !== null && $report['longitude'] !== null): ?>
                        <div class="col-12">
                            <div class="small text-secondary mb-1">Incident site map <span class="text-muted">(managed zones overlaid for reference)</span></div>
                            <div id="incidentSiteMap" class="geo-map" role="img" aria-label="Map of the reported incident site"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($photos !== []): ?>
                <section class="docket-panel mb-4" aria-labelledby="reportPhotosHeading">
                    <div class="section-heading"><h2 id="reportPhotosHeading">Evidence Photos</h2></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($photos as $photo): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="illegal-logging-photo-download.php?id=<?php echo (int) $photo['id']; ?>"><i class="bi bi-image"></i> <?php echo e((string) $photo['original_filename']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="docket-panel mb-4" aria-labelledby="reportActionHeading">
                <div class="section-heading"><h2 id="reportActionHeading">Process Report</h2></div>

                <?php if ($status === 'submitted' || $status === 'under_review'): ?>
                    <?php if ($status === 'submitted'): ?>
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6"><i class="bi bi-play-circle me-1"></i>Begin review</h3>
                            <form method="post" action="illegal-logging-report-action.php" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                <input type="hidden" name="action" value="begin_review">
                                <div class="col-md-8">
                                    <label class="form-label" for="assignedTo">Assign to</label>
                                    <select class="form-select" id="assignedTo" name="assigned_to_user_id">
                                        <?php foreach ($personnel as $person): ?>
                                            <option value="<?php echo (int) $person['id']; ?>" <?php echo (int) $person['id'] === $userId ? 'selected' : ''; ?>><?php echo e(trim((string) $person['fname'] . ' ' . (string) $person['lname'])); ?> (<?php echo e(strtoupper((string) $person['role'])); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-play-circle"></i> Begin review</button></div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6"><i class="bi bi-truck me-1"></i>Dispatch field verification</h3>
                            <form method="post" action="illegal-logging-report-action.php" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                <input type="hidden" name="action" value="dispatch">
                                <div class="col-md-9"><label class="form-label" for="dispatchRemarks">Remarks (optional)</label><input class="form-control" id="dispatchRemarks" type="text" name="remarks" maxlength="500"></div>
                                <div class="col-md-3"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-truck"></i> Dispatch</button></div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (in_array($status, ['submitted', 'under_review', 'field_verification'], true)): ?>
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6"><i class="bi bi-check2-square me-1"></i>Resolve report</h3>
                        <form method="post" action="illegal-logging-report-action.php" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                            <input type="hidden" name="action" value="resolve">
                            <div class="col-md-4">
                                <label class="form-label" for="resolutionOutcome">Outcome</label>
                                <select class="form-select" id="resolutionOutcome" name="resolution_outcome" required>
                                    <option value="">Select outcome</option>
                                    <?php foreach ($outcomes as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="fieldFindings">Field findings (optional)</label>
                                <input class="form-control" id="fieldFindings" type="text" name="field_findings" maxlength="2000">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="resolutionNotes">Resolution notes (required)</label>
                                <textarea class="form-control" id="resolutionNotes" name="resolution_notes" rows="3" maxlength="2000" required></textarea>
                            </div>
                            <div class="col-12"><button class="btn btn-outline-danger" type="submit"><i class="bi bi-check2-square"></i> Resolve report</button></div>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="text-secondary mb-0">
                        Resolved
                        <?php if (!empty($report['resolution_outcome'])): ?>
                            (<span class="badge <?php echo e(illegal_logging_resolution_outcome_badge((string) $report['resolution_outcome'])); ?>"><?php echo e(illegal_logging_resolution_outcome_label((string) $report['resolution_outcome'])); ?></span>)
                        <?php endif; ?>
                        — no further action needed.
                    </p>
                    <?php if (!empty($report['resolution_notes'])): ?><p class="small text-secondary mb-0 mt-2"><strong>Resolution notes:</strong> <?php echo e((string) $report['resolution_notes']); ?></p><?php endif; ?>
                    <?php if (!empty($report['field_findings'])): ?><p class="small text-secondary mb-0 mt-1"><strong>Field findings:</strong> <?php echo e((string) $report['field_findings']); ?></p><?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="docket-panel" aria-labelledby="reportHistoryHeading">
                <div class="section-heading"><h2 id="reportHistoryHeading">Status History</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Status</th><th>Remarks</th><th>By</th><th>When</th></tr></thead>
                        <tbody>
                            <?php foreach ($history as $event): ?>
                                <tr>
                                    <td><span class="badge <?php echo e(illegal_logging_report_status_badge((string) $event['new_status'])); ?>"><?php echo e(illegal_logging_report_status_label((string) $event['new_status'])); ?></span></td>
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
    <?php if ($report['latitude'] !== null && $report['longitude'] !== null): ?>
        <script type="application/json" id="zoneMapData"><?php echo json_encode($zoneMapFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script src="../../js/geo-map.js"></script>
        <script>
            CertreefyGeo.display('incidentSiteMap', {
                zones: CertreefyGeo.readJson('zoneMapData', []),
                points: [{
                    lat: <?php echo json_encode((string) $report['latitude']); ?>,
                    lng: <?php echo json_encode((string) $report['longitude']); ?>,
                    label: <?php echo json_encode('Reported incident site — ' . (string) $report['report_reference']); ?>
                }]
            });
        </script>
    <?php endif; ?>
</body>
</html>
