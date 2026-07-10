<?php
/**
 * ============================================================
 * File     : cenro/dashboard.php
 * Project  : CERTREEFY - CENRO Electronic Registry for Tree
 *            Regulation, Environmental Enforcement, and Facilitation System
 * Purpose  : Starter landing dashboard for CENRO Superadmin.
 *
 * Security notes:
 * - Requires the shared config so hardened session settings are applied.
 * - Allows only authenticated users with role "superadmin".
 * - Redirects users with other valid roles to their own dashboard.
 * - Escapes session-derived output before rendering into HTML.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dashboard_path_for_role(string $role): string
{
    $routes = [
        'superadmin' => '../cenro/dashboard.php',
        'community'  => '../community/dashboard.php',
        'greenhouse' => '../greenhouse/dashboard.php',
    ];

    return $routes[$role] ?? '';
}

function require_role(string $requiredRole): void
{
    if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    if ($_SESSION['role'] !== $requiredRole) {
        $redirectPath = dashboard_path_for_role((string) $_SESSION['role']);
        header('Location: ' . ($redirectPath !== '' ? $redirectPath : '../auth/logout.php'));
        exit;
    }
}

require_role('superadmin');

$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Superadmin';

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
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">

        <!-- ================= SIDEBAR (desktop) ================= -->
        <aside class="sidebar" aria-label="CENRO dashboard navigation">
            <div class="brand-block">
                <span class="registry-seal" aria-hidden="true"><i class="bi bi-tree-fill"></i></span>
                <div>
                    <div class="brand-word">CERTREEFY</div>
                    <div class="brand-sub">CENRO Registry</div>
                </div>
            </div>

            <nav class="nav-panel">
                <a class="active" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
                <a href="#"><i class="bi bi-file-earmark-check"></i><span>Permit Applications</span></a>
                <a href="#"><i class="bi bi-shield-exclamation"></i><span>Logging Reports</span></a>
                <a href="#"><i class="bi bi-map"></i><span>Area Management</span></a>
                <a href="#"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
                <a href="#"><i class="bi bi-people"></i><span>User Management</span></a>
                <a href="#"><i class="bi bi-folder2-open"></i><span>Documents</span></a>
                <a href="#"><i class="bi bi-bar-chart-line"></i><span>Analytics</span></a>
            </nav>

            <div class="nav-divider"></div>
            <div class="sidebar-footer">
                <a href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
            </div>
        </aside>

        <!-- ================= MOBILE TOP BAR + OFFCANVAS ================= -->
        <div class="mobile-topbar">
            <div class="d-flex align-items-center gap-2">
                <span class="registry-seal" aria-hidden="true"><i class="bi bi-tree-fill"></i></span>
                <span class="brand-word">CERTREEFY</span>
            </div>
            <button class="btn-menu-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open navigation menu">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="offcanvas offcanvas-start offcanvas-registry" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
            <div class="offcanvas-header">
                <h2 id="mobileNavLabel" class="brand-word h6 mb-0">Navigation</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <nav class="nav-panel">
                    <a class="active" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
                    <a href="#"><i class="bi bi-file-earmark-check"></i><span>Permit Applications</span></a>
                    <a href="#"><i class="bi bi-shield-exclamation"></i><span>Logging Reports</span></a>
                    <a href="#"><i class="bi bi-map"></i><span>Area Management</span></a>
                    <a href="#"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
                    <a href="#"><i class="bi bi-people"></i><span>User Management</span></a>
                    <a href="#"><i class="bi bi-folder2-open"></i><span>Documents</span></a>
                    <a href="#"><i class="bi bi-bar-chart-line"></i><span>Analytics</span></a>
                </nav>
                <div class="nav-divider"></div>
                <div class="sidebar-footer">
                    <a href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
                </div>
            </div>
        </div>

        <!-- ================= MAIN CONTENT ================= -->
        <main class="main" id="main-content">

            <!-- Header: ledger-style record header for today's session -->
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Operations &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Environmental Command Dashboard</h1>
                        <p class="text-secondary meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Here's today's registry at a glance.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <a href="../auth/logout.php" class="btn-logout-outline">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </a>
                    </div>
                </div>

                <!-- Ridge-line divider: a quiet nod to the forest-hill motif used
                     across CETREEFY's public site, rendered as a thin structural rule. -->
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
                        <div class="ledger-value tabular">0</div>
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
            <section class="mb-5" aria-label="CENRO primary modules">
                <div class="section-heading">
                    <h2>Registry Modules</h2>
                    <span class="section-note">8 modules available</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-tree"></i></span>
                            <h3>Permit Review</h3>
                            <p>Tree cutting requests, documentary checks, and approval records.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-geo-alt"></i></span>
                            <h3>Area Management</h3>
                            <p>Allowed, restricted, and protected environmental zones.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-megaphone"></i></span>
                            <h3>Public Advisories</h3>
                            <p>Announcements, notices, and environmental information posts.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-rust">
                            <span class="registry-icon"><i class="bi bi-people"></i></span>
                            <h3>User Management</h3>
                            <p>Community accounts, role assignment, and account status control.</p>
                            <a class="link-open" href="#">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
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
                            <span class="count-badge tabular">0 pending</span>
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
                            <span class="text-secondary"><span class="status-dot"></span>Greenhouse oversight</span>
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
