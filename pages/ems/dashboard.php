<?php
/**
 * ============================================================
 * File     : pages/ems/dashboard.php
 * Project  : CERTREEFY - CENRO Electronic Registry for Tree
 *            Regulation, Environmental Enforcement, and Facilitation System
 * Purpose  : Starter landing dashboard for EMS users.
 *
 * Security notes:
 * - Requires the shared config so hardened session settings are applied.
 * - Allows only authenticated users with role "ems".
 * - Redirects users with other valid roles to their own dashboard.
 * - Escapes session-derived output before rendering into HTML.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'EMS User';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | EMS Dashboard</title>

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
            <section class="hero-band mb-4">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <div class="eyebrow mb-1">Enforcement &amp; Monitoring Section</div>
                        <h1 class="page-title">Seedling Inventory Dashboard</h1>
                        <p class="mb-0 opacity-75">Welcome, <?php echo e($displayName); ?>.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="officer-chip">
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
            </section>

            <section class="row g-3 mb-5" aria-label="EMS dashboard metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-box-seam"></i></span>
                            <span class="ledger-tag">Inventory</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Available seedlings</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Requests</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Pending seedling requests</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-clipboard-check"></i></span>
                            <span class="ledger-tag">Release</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Released this month</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <span class="ledger-tag">Alerts</span>
                        </div>
                        <div class="ledger-value tabular">0</div>
                        <div class="ledger-caption">Low stock items</div>
                    </div>
                </div>
            </section>

            <section class="mb-5" aria-label="EMS service modules">
                <div class="section-heading">
                    <h2>EMS Modules</h2>
                    <span class="section-note">4 active areas</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-box-seam"></i></span>
                            <h3>Seed Inventory</h3>
                            <p>Species records, available quantity, stock level, and status.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-flower1"></i></span>
                            <h3>Seedling Requests</h3>
                            <p>Approved requests, verification, pickup schedule, and release.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-clipboard-check"></i></span>
                            <h3>Claim Slips</h3>
                            <p>Claim records, recipient details, and release confirmation.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-rust">
                            <span class="registry-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                            <h3>Inventory Reports</h3>
                            <p>Stock movement, release records, and report summaries.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Inventory Movement</h2>
                            <span class="section-note">Today</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Incoming stock</div>
                                <div class="docket-sub">Seedlings added to inventory</div>
                            </div>
                            <span class="count-badge tabular">0 entries</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Released seedlings</div>
                                <div class="docket-sub">Claim slips completed</div>
                            </div>
                            <span class="count-badge tabular">0 releases</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Pending verification</div>
                                <div class="docket-sub">Requests awaiting EMS action</div>
                            </div>
                            <span class="count-badge tabular">0 pending</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>EMS Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Inventory records</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Request verification</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Claim slip records</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Inventory reports</span>
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

