<?php
/**
 * Shared role-aware navigation for protected CERTREEFY pages.
 *
 * Page guards remain responsible for authorization. This component mirrors the
 * permitted module links for the database-verified session role and renders the
 * existing desktop sidebar and mobile offcanvas markup from one source.
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';

/** Renders a notification bell button. Both bells (sidebar + mobile) share one panel/badge via JS. */
function render_certreefy_notification_bell(string $placement): void
{
    echo '<button type="button" class="notif-bell notif-bell-' . htmlspecialchars($placement, ENT_QUOTES, 'UTF-8')
        . '" data-notif-toggle aria-label="Notifications" aria-haspopup="dialog" aria-expanded="false">'
        . '<i class="bi bi-bell"></i>'
        . '<span class="notif-badge" data-notif-badge hidden>0</span>'
        . '</button>';
}

/** Renders the shared, fixed-position notification panel + backdrop once per page (outside the sidebar's overflow). */
function render_certreefy_notification_panel(): void
{
    if (empty($_SESSION['csrf_notif_token'])) {
        $_SESSION['csrf_notif_token'] = bin2hex(random_bytes(32));
    }
    $csrf = htmlspecialchars((string) $_SESSION['csrf_notif_token'], ENT_QUOTES, 'UTF-8');
    ?>
    <div class="notif-backdrop" data-notif-backdrop hidden></div>
    <aside class="notif-panel" id="notifPanel" data-notif-csrf="<?php echo $csrf; ?>" role="dialog" aria-label="Notifications" aria-modal="false" hidden>
        <div class="notif-panel-head">
            <span class="notif-panel-title">Notifications</span>
            <button type="button" class="notif-markall" data-notif-markall><i class="bi bi-check2-all me-1"></i>Mark all read</button>
        </div>
        <div class="notif-list" data-notif-list>
            <div class="notif-state">Loading&hellip;</div>
        </div>
        <div class="notif-panel-foot">
            <button type="button" class="btn btn-sm btn-outline-secondary w-100" data-notif-more hidden>Load more</button>
        </div>
    </aside>
    <?php
}

function certreefy_navigation_items_for_role(string $role, array $permissions = []): array
{
    $items = [
        // Escape hatch out of the dashboard shell back to the public landing
        // page/announcements, shared by every role. Placed first so it always
        // leads its role's "Overview" section, ahead of "Dashboard".
        [
            'key' => 'home',
            'roles' => ['community', 'superadmin', 'rps', 'ems'],
            'section' => 'Overview',
            'href' => '../index.php',
            'icon' => 'bi-house-door',
            'label' => 'Home',
        ],

        // --- Community ---
        [
            'key' => 'dashboard',
            'roles' => ['community'],
            'section' => 'Overview',
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'tree_permit',
            'roles' => ['community'],
            'section' => 'Permits',
            'href' => 'permit-applications.php',
            'icon' => 'bi-file-earmark-plus',
            'label' => 'Tree Permit',
        ],
        [
            'key' => 'application_status',
            'roles' => ['community'],
            'section' => 'Permits',
            'href' => 'permit-applications.php#applications',
            'icon' => 'bi-clock-history',
            'label' => 'Application Status',
        ],
        [
            'key' => 'seedling_request',
            'roles' => ['community'],
            'section' => 'Programs',
            'href' => 'seedling-requests.php',
            'icon' => 'bi-flower1',
            'label' => 'Seedling Request',
        ],
        [
            'key' => 'report_logging',
            'roles' => ['community'],
            'section' => 'Programs',
            'href' => 'illegal-logging-reports.php',
            'icon' => 'bi-shield-exclamation',
            'label' => 'Report Logging',
        ],
        [
            'key' => 'advisories',
            'roles' => ['community'],
            'section' => 'Programs',
            'href' => 'advisories.php',
            'icon' => 'bi-megaphone',
            'label' => 'Advisories',
        ],
        [
            'key' => 'profile',
            'roles' => ['community'],
            'section' => 'Account',
            'href' => 'profile.php',
            'icon' => 'bi-person-circle',
            'label' => 'Profile',
        ],

        // --- CENRO (Superadmin / RPS) ---
        [
            'key' => 'dashboard',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Overview',
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'permit_applications',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Permitting',
            'permission_by_role' => [
                'superadmin' => [
                    certreefy_permission_original_document_verification(),
                    certreefy_permission_site_inspection(),
                    certreefy_permission_permit_decision(),
                ],
            ],
            'href' => 'permit-applications.php',
            'icon' => 'bi-file-earmark-check',
            'label' => 'Permit Applications',
        ],
        [
            'key' => 'logging_reports',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Enforcement',
            'permission_by_role' => [
                'superadmin' => certreefy_permission_illegal_logging_processing(),
            ],
            'href' => 'illegal-logging-reports.php',
            'icon' => 'bi-shield-exclamation',
            'label' => 'Logging Reports',
        ],
        [
            'key' => 'area_management',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Registry',
            'href' => 'area-management.php',
            'icon' => 'bi-map',
            'label' => 'Area Management',
        ],
        [
            'key' => 'announcements',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Registry',
            'href' => 'advisories.php',
            'icon' => 'bi-megaphone',
            'label' => 'Announcements',
        ],
        [
            'key' => 'analytics',
            'roles' => ['superadmin', 'rps'],
            'section' => 'Insights',
            'href' => 'analytics.php',
            'icon' => 'bi-bar-chart-line',
            'label' => 'Analytics',
        ],
        [
            'key' => 'user_management',
            'roles' => ['superadmin'],
            'section' => 'Administration',
            'href' => 'user-management.php',
            'icon' => 'bi-people',
            'label' => 'User Management',
        ],
        [
            'key' => 'audit_history',
            'roles' => ['superadmin'],
            'section' => 'Administration',
            'href' => 'audit-history.php',
            'icon' => 'bi-journal-text',
            'label' => 'Audit History',
        ],

        // --- EMS ---
        [
            'key' => 'dashboard',
            'roles' => ['ems'],
            'section' => 'Overview',
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'permit_donations',
            'roles' => ['ems'],
            'section' => 'Donations',
            'href' => 'donation-registry.php',
            'icon' => 'bi-tree',
            'label' => 'Permit Donations',
        ],
        [
            'key' => 'seed_inventory',
            'roles' => ['ems'],
            'section' => 'Seedlings',
            'href' => 'seedling-inventory.php',
            'icon' => 'bi-box-seam',
            'label' => 'Seed Inventory',
        ],
        [
            'key' => 'seedling_requests',
            'roles' => ['ems'],
            'section' => 'Seedlings',
            'href' => 'seedling-requests.php',
            'icon' => 'bi-flower1',
            'label' => 'Seedling Requests',
        ],
        [
            'key' => 'claim_slips',
            'roles' => ['ems'],
            'section' => 'Seedlings',
            'href' => 'claim-slips.php',
            'icon' => 'bi-clipboard-check',
            'label' => 'Claim Slips',
        ],
        [
            'key' => 'planting_sites',
            'roles' => ['ems'],
            'section' => 'Seedlings',
            'href' => 'planting-sites.php',
            'icon' => 'bi-geo-alt',
            'label' => 'Planting Sites',
        ],
        [
            'key' => 'stock_movement',
            'roles' => ['ems'],
            'section' => 'Seedlings',
            'href' => 'stock-movement.php',
            'icon' => 'bi-arrow-left-right',
            'label' => 'Stock Movement',
        ],
        [
            'key' => 'inventory_reports',
            'roles' => ['ems'],
            'section' => 'Reports',
            'href' => 'inventory-reports.php',
            'icon' => 'bi-file-earmark-bar-graph',
            'label' => 'Inventory Reports',
        ],
    ];

    return array_values(array_filter(
        $items,
        static function (array $item) use ($role, $permissions): bool {
            if (!in_array($role, $item['roles'], true)) {
                return false;
            }
            $requiredPermission = $item['permission_by_role'][$role] ?? null;
            if ($requiredPermission === null) {
                return true;
            }
            $requiredPermissions = is_array($requiredPermission)
                ? $requiredPermission
                : [$requiredPermission];

            return array_intersect($requiredPermissions, $permissions) !== [];
        }
    ));
}

function certreefy_navigation_shell_for_role(string $role): ?array
{
    if ($role === 'community') {
        return [
            'aria_label' => 'Community dashboard navigation',
            'mobile_aria_label' => 'Community mobile navigation',
            'brand_subtitle' => 'Community Portal',
            'brand_mark' => 'tree',
        ];
    }

    if ($role === 'ems') {
        return [
            'aria_label' => 'EMS dashboard navigation',
            'mobile_aria_label' => 'EMS mobile navigation',
            'brand_subtitle' => 'EMS Unit',
            'brand_mark' => 'initials',
        ];
    }

    if (in_array($role, ['superadmin', 'rps'], true)) {
        return [
            'aria_label' => 'CENRO dashboard navigation',
            'mobile_aria_label' => 'CENRO mobile navigation',
            'brand_subtitle' => 'CENRO Registry',
            'brand_mark' => 'tree',
        ];
    }

    return null;
}

function render_certreefy_navigation_items(array $items, string $activePage): void
{
    $currentSection = null;
    foreach ($items as $item) {
        $section = $item['section'] ?? null;
        if ($section !== null && $section !== $currentSection) {
            echo '<div class="nav-section-label">'
                . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '</div>';
            $currentSection = $section;
        }

        $isActive = $item['key'] === $activePage;
        $classAttribute = $isActive ? ' class="active"' : '';
        $currentAttribute = $isActive ? ' aria-current="page"' : '';

        echo '<a' . $classAttribute . ' href="'
            . htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') . '"'
            . $currentAttribute . '><i class="bi '
            . htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') . '"></i><span>'
            . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
}

function certreefy_role_display_label(string $role): string
{
    return match ($role) {
        'superadmin' => 'Superadmin',
        'rps' => 'RPS Officer',
        'ems' => 'EMS Unit',
        'community' => 'Community',
        default => ucfirst($role),
    };
}

function render_certreefy_sidebar_identity(string $role): void
{
    $name = trim((string) ($_SESSION['name'] ?? ''));
    if ($name === '') {
        $name = certreefy_role_display_label($role);
    }
    $initial = strtoupper(substr($name, 0, 1));

    echo '<div class="sidebar-identity">'
        . '<span class="id-avatar">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<div class="id-meta">'
        . '<div class="id-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="id-role">' . htmlspecialchars(certreefy_role_display_label($role), ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div></div>';
}

function render_certreefy_brand_mark(string $brandMark): void
{
    if ($brandMark === 'initials') {
        echo '<span class="brand-mark" aria-hidden="true">CT</span>';
        return;
    }

    echo '<span class="registry-seal" aria-hidden="true"><i class="bi bi-tree-fill"></i></span>';
}

function render_certreefy_navigation(string $role, string $activePage, array $permissions = []): void
{
    $shell = certreefy_navigation_shell_for_role($role);

    if ($shell === null) {
        throw new InvalidArgumentException('Unsupported CERTREEFY navigation role.');
    }

    $items = certreefy_navigation_items_for_role($role, $permissions);
    ?>
    <aside class="sidebar" aria-label="<?php echo htmlspecialchars($shell['aria_label'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="sidebar-head">
            <a class="brand-block" href="../index.php" title="Go to the CERTREEFY home page">
                <?php render_certreefy_brand_mark($shell['brand_mark']); ?>
                <div>
                    <div class="brand-word">CERTREEFY</div>
                    <div class="brand-sub"><?php echo htmlspecialchars($shell['brand_subtitle'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </a>
        </div>

        <nav class="nav-panel" aria-label="<?php echo htmlspecialchars($shell['aria_label'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php render_certreefy_navigation_items($items, $activePage); ?>
        </nav>

        <div class="nav-divider"></div>
        <div class="sidebar-footer">
            <?php render_certreefy_sidebar_identity($role); ?>
            <form method="post" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_logout_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
        </div>
    </aside>

    <div class="mobile-topbar">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="../index.php" title="Go to the CERTREEFY home page">
            <?php render_certreefy_brand_mark($shell['brand_mark']); ?>
            <span class="brand-word">CERTREEFY</span>
        </a>
        <div class="d-flex align-items-center gap-1">
            <button class="btn-menu-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open navigation menu">
                <i class="bi bi-list"></i>
            </button>
        </div>
    </div>

    <div class="offcanvas offcanvas-start offcanvas-registry" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
        <div class="offcanvas-header">
            <h2 id="mobileNavLabel" class="brand-word h6 mb-0">Navigation</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <nav class="nav-panel" aria-label="<?php echo htmlspecialchars($shell['mobile_aria_label'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php render_certreefy_navigation_items($items, $activePage); ?>
            </nav>
            <div class="nav-divider"></div>
            <div class="sidebar-footer">
                <?php render_certreefy_sidebar_identity($role); ?>
                <form method="post" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_logout_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
            </div>
        </div>
    </div>

    <?php render_certreefy_notification_panel(); ?>
    <script src="../../js/notifications.js?v=2" defer></script>
    <?php
}
