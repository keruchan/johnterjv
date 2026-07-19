<?php
/**
 * ============================================================
 * File     : pages/cenro/dashboard.php
 * Project  : CERTREEFY - CENRO Electronic Registry for Tree
 *            Regulation, Environmental Enforcement, and Facilitation System
 * Purpose  : Starter landing dashboard for CENRO Superadmin and RPS users.
 *
 * Security notes:
 * - Requires the shared config so hardened session settings are applied.
 * - Allows only authenticated users with role "superadmin" or "rps".
 * - Redirects users with other valid roles to their own dashboard.
 * - Escapes session-derived output before rendering into HTML.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['superadmin', 'rps']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name'])
    ? (string) $_SESSION['name']
    : ($currentRole === 'rps' ? 'RPS User' : 'CENRO Superadmin');
$operationsLabel = $currentRole === 'rps'
    ? 'Regulation and Permitting Section'
    : 'CENRO Operations';

$pendingIncidentReports = 0;
try {
    if (illegal_logging_processor_actor($pdo, $userId) !== null) {
        $pendingIncidentReports = (int) $pdo->query(
            "SELECT COUNT(*) FROM tbl_illegal_logging_reports WHERE current_status <> 'resolved'"
        )->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('[CERTREEFY CENRO DASHBOARD ERROR] ' . $e->getMessage());
}
$navigationPermissions = user_active_permissions($pdo, (int) $_SESSION['id']);
$canReviewPermitDocuments = $currentRole === 'rps'
    || array_intersect(
        [
            certreefy_permission_original_document_verification(),
            certreefy_permission_site_inspection(),
            certreefy_permission_permit_decision(),
        ],
        $navigationPermissions
    ) !== [];
$canProcessLoggingReports = $currentRole === 'rps'
    || in_array(
        certreefy_permission_illegal_logging_processing(),
        $navigationPermissions,
        true
    );

// Today's date, formatted for the ledger-style header (display only).
$todayLabel = date('l, F j, Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | CENRO Dashboard</title>

    <!-- Type system: Fraunces (display, gives the page an official/document character)
         paired with Inter (body/data face, tuned for small UI text and tabular figures). -->
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

        <?php render_certreefy_navigation($currentRole, 'dashboard', $navigationPermissions); ?>

        <!-- ================= MAIN CONTENT ================= -->
        <main class="main" id="main-content">

            <!-- Header: ledger-style record header for today's session -->
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow"><?php echo e($operationsLabel); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Environmental Command Dashboard</h1>
                        <p class="text-secondary meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Here's today's registry at a glance.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Ridge-line divider: a quiet nod to the forest-hill motif used
                     across CERTREEFY's public site, rendered as a thin structural rule. -->
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <!-- Metrics: ledger entry cards -->
            <section class="row g-3 mb-5" aria-label="CENRO dashboard metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Queue</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Pending permit requests</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <span class="ledger-tag">Records</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Approved permits</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <span class="ledger-tag">Reports</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $pendingIncidentReports; ?></div>
                        <div class="ledger-caption">Illegal logging reports</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-person-check"></i></span>
                            <span class="ledger-tag">Users</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Accounts awaiting action</div>
                    </div>
                </div>
            </section>

            <!-- Primary modules, framed as registry entries -->
            <?php
            $cenroModuleCount = 3; // Area Management, Public Advisories, Analytics are always visible.
            if ($canReviewPermitDocuments) {
                $cenroModuleCount++;
            }
            if ($canProcessLoggingReports) {
                $cenroModuleCount++;
            }
            if ($currentRole === 'superadmin') {
                $cenroModuleCount += 2; // User Management, Audit History.
            }
            ?>
            <section class="mb-5" aria-label="CENRO primary modules">
                <div class="section-heading">
                    <h2>Registry Modules</h2>
                    <span class="section-note"><?php echo (int) $cenroModuleCount; ?> modules available</span>
                </div>
                <div class="row g-3">
                    <?php if ($canReviewPermitDocuments): ?>
                        <div class="col-md-6 col-xl-3">
                            <div class="registry-card">
                                <span class="registry-icon"><i class="bi bi-tree"></i></span>
                                <h3>Permit Review</h3>
                                <p>Tree cutting requests, documentary checks, and approval records.</p>
                                <a class="link-open" href="permit-applications.php">Open module <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canProcessLoggingReports): ?>
                        <div class="col-md-6 col-xl-3">
                            <div class="registry-card tone-rust">
                                <span class="registry-icon"><i class="bi bi-shield-exclamation"></i></span>
                                <h3>Logging Reports</h3>
                                <p>Illegal-logging incident intake, dispatch, and resolution.</p>
                                <a class="link-open" href="illegal-logging-reports.php">Open module <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-geo-alt"></i></span>
                            <h3>Area Management</h3>
                            <p>Allowed, restricted, and protected environmental zones.</p>
                            <a class="link-open" href="area-management.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-megaphone"></i></span>
                            <h3>Public Advisories</h3>
                            <p>Announcements, notices, and environmental information posts.</p>
                            <a class="link-open" href="advisories.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-bar-chart-line"></i></span>
                            <h3>Analytics</h3>
                            <p>Descriptive, predictive, and prescriptive cross-domain reporting.</p>
                            <a class="link-open" href="analytics.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <?php if ($currentRole === 'superadmin'): ?>
                        <div class="col-md-6 col-xl-3">
                            <div class="registry-card tone-rust">
                                <span class="registry-icon"><i class="bi bi-people"></i></span>
                                <h3>User Management</h3>
                                <p>Community accounts, role assignment, and account status control.</p>
                                <a class="link-open" href="user-management.php">Open module <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <div class="registry-card tone-teal">
                                <span class="registry-icon"><i class="bi bi-journal-text"></i></span>
                                <h3>Audit History</h3>
                                <p>Activity log and login attempts, in plain-language form.</p>
                                <a class="link-open" href="audit-history.php">Open module <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Docket (operational queues) + system snapshot -->
            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Operational Docket</h2>
                            <span class="section-note">Today</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Tree cutting permit applications</div>
                                <div class="docket-sub">Applications ready for screening</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Illegal logging incident reports</div>
                                <div class="docket-sub">Reports requiring assessment</div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $pendingIncidentReports; ?> pending</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Community account approvals</div>
                                <div class="docket-sub">New registrations awaiting activation</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>System Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Permit monitoring</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Community reporting</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>EMS oversight</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Document records</span>
                            <span class="status-ready">Ready</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Bootstrap bundle JS: powers the mobile off-canvas navigation only
         (no AJAX, no additional framework). -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
