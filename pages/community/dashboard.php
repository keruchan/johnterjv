<?php
/**
 * ============================================================
 * File     : pages/community/dashboard.php
 * Project  : CERTREEFY - CENRO Electronic Registry for Tree
 *            Regulation, Environmental Enforcement, and Facilitation System
 * Purpose  : Starter landing dashboard for Community users.
 *
 * Security notes:
 * - Requires the shared config so hardened session settings are applied.
 * - Allows only authenticated users with role "community".
 * - Redirects users with other valid roles to their own dashboard.
 * - Escapes session-derived output before rendering into HTML.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Community User';
$todayLabel = date('l, F j, Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Community Dashboard</title>

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
        <?php render_certreefy_navigation($currentRole, 'dashboard'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Community Services &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Community Services Dashboard</h1>
                        <p class="meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Track applications, submit reports, and request seedlings from one place.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <section class="row g-3 mb-5" aria-label="Community dashboard metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Active</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Active applications</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-check2-circle"></i></span>
                            <span class="ledger-tag">History</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Completed requests</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-flower1"></i></span>
                            <span class="ledger-tag">Seedlings</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Seedling requests</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <span class="ledger-tag">Reports</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Incident reports</div>
                    </div>
                </div>
            </section>

            <section class="mb-5" aria-label="Community service modules">
                <div class="section-heading">
                    <h2>Community Services</h2>
                    <span class="section-note">4 service areas</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-tree"></i></span>
                            <h3>Tree Cutting Permit</h3>
                            <p>Application details, tree information, location, and documents.</p>
                            <a class="link-open" href="permit-applications.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-flower2"></i></span>
                            <h3>Seedling Request</h3>
                            <p>Seedling quantity, pickup schedule, and claim details.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-shield-exclamation"></i></span>
                            <h3>Illegal Logging Report</h3>
                            <p>Incident location, description, and photo evidence records.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-rust">
                            <span class="registry-icon"><i class="bi bi-megaphone"></i></span>
                            <h3>Advisories</h3>
                            <p>Public environmental posts, notices, and office announcements.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>My Requests</h2>
                            <span class="section-note">Current</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Tree cutting permit</div>
                                <div class="docket-sub">No active application</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Seedling request</div>
                                <div class="docket-sub">No pending pickup schedule</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Incident report</div>
                                <div class="docket-sub">No active investigation record</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>Account Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Account type</span>
                            <span class="status-ready">Community</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Permit services</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Reporting services</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>EMS requests</span>
                            <span class="status-ready">Ready</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
