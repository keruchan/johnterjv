<?php
/**
 * Shared role-aware navigation for protected CERTREEFY pages.
 *
 * Page guards remain responsible for authorization. This component mirrors the
 * permitted module links for the database-verified session role and renders the
 * existing desktop sidebar and mobile offcanvas markup from one source.
 */

require_once __DIR__ . '/permissions.php';

function certreefy_navigation_items_for_role(string $role, array $permissions = []): array
{
    $items = [
        [
            'key' => 'dashboard',
            'roles' => ['community'],
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'tree_permit',
            'roles' => ['community'],
            'href' => 'permit-applications.php',
            'icon' => 'bi-file-earmark-plus',
            'label' => 'Tree Permit',
        ],
        [
            'key' => 'application_status',
            'roles' => ['community'],
            'href' => 'permit-applications.php#applications',
            'icon' => 'bi-clock-history',
            'label' => 'Application Status',
        ],
        [
            'key' => 'seedling_request',
            'roles' => ['community'],
            'href' => '#',
            'icon' => 'bi-flower1',
            'label' => 'Seedling Request',
        ],
        [
            'key' => 'report_logging',
            'roles' => ['community'],
            'href' => '#',
            'icon' => 'bi-shield-exclamation',
            'label' => 'Report Logging',
        ],
        [
            'key' => 'advisories',
            'roles' => ['community'],
            'href' => '#',
            'icon' => 'bi-megaphone',
            'label' => 'Advisories',
        ],
        [
            'key' => 'profile',
            'roles' => ['community'],
            'href' => 'profile.php',
            'icon' => 'bi-person-circle',
            'label' => 'Profile',
        ],
        [
            'key' => 'dashboard',
            'roles' => ['superadmin', 'rps'],
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'permit_applications',
            'roles' => ['superadmin', 'rps'],
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
            'href' => '#',
            'icon' => 'bi-shield-exclamation',
            'label' => 'Logging Reports',
        ],
        [
            'key' => 'area_management',
            'roles' => ['superadmin', 'rps'],
            'href' => '#',
            'icon' => 'bi-map',
            'label' => 'Area Management',
        ],
        [
            'key' => 'announcements',
            'roles' => ['superadmin', 'rps'],
            'href' => '#',
            'icon' => 'bi-megaphone',
            'label' => 'Announcements',
        ],
        [
            'key' => 'user_management',
            'roles' => ['superadmin'],
            'href' => 'user-management.php',
            'icon' => 'bi-people',
            'label' => 'User Management',
        ],
        [
            'key' => 'audit_history',
            'roles' => ['superadmin'],
            'href' => 'audit-history.php',
            'icon' => 'bi-journal-text',
            'label' => 'Audit History',
        ],
        [
            'key' => 'documents',
            'roles' => ['superadmin', 'rps'],
            'permission_by_role' => [
                'superadmin' => certreefy_permission_original_document_verification(),
            ],
            'href' => 'permit-applications.php',
            'icon' => 'bi-folder2-open',
            'label' => 'Documents',
        ],
        [
            'key' => 'analytics',
            'roles' => ['superadmin', 'rps'],
            'href' => '#',
            'icon' => 'bi-bar-chart-line',
            'label' => 'Analytics',
        ],
        [
            'key' => 'dashboard',
            'roles' => ['ems'],
            'href' => 'dashboard.php',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'key' => 'permit_donations',
            'roles' => ['ems'],
            'href' => 'donation-requirements.php',
            'icon' => 'bi-tree',
            'label' => 'Permit Donations',
        ],
        [
            'key' => 'seed_inventory',
            'roles' => ['ems'],
            'href' => '#',
            'icon' => 'bi-box-seam',
            'label' => 'Seed Inventory',
        ],
        [
            'key' => 'seedling_requests',
            'roles' => ['ems'],
            'href' => '#',
            'icon' => 'bi-flower1',
            'label' => 'Seedling Requests',
        ],
        [
            'key' => 'claim_slips',
            'roles' => ['ems'],
            'href' => '#',
            'icon' => 'bi-clipboard-check',
            'label' => 'Claim Slips',
        ],
        [
            'key' => 'stock_movement',
            'roles' => ['ems'],
            'href' => '#',
            'icon' => 'bi-arrow-left-right',
            'label' => 'Stock Movement',
        ],
        [
            'key' => 'inventory_reports',
            'roles' => ['ems'],
            'href' => '#',
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
    foreach ($items as $item) {
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
        <div class="brand-block">
            <?php render_certreefy_brand_mark($shell['brand_mark']); ?>
            <div>
                <div class="brand-word">CERTREEFY</div>
                <div class="brand-sub"><?php echo htmlspecialchars($shell['brand_subtitle'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <nav class="nav-panel" aria-label="<?php echo htmlspecialchars($shell['aria_label'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php render_certreefy_navigation_items($items, $activePage); ?>
        </nav>

        <div class="nav-divider"></div>
        <div class="sidebar-footer">
            <form method="post" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_logout_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
        </div>
    </aside>

    <div class="mobile-topbar">
        <div class="d-flex align-items-center gap-2">
            <?php render_certreefy_brand_mark($shell['brand_mark']); ?>
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
            <nav class="nav-panel" aria-label="<?php echo htmlspecialchars($shell['mobile_aria_label'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php render_certreefy_navigation_items($items, $activePage); ?>
            </nav>
            <div class="nav-divider"></div>
            <div class="sidebar-footer">
                <form method="post" action="../auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_logout_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
            </div>
        </div>
    </div>
    <?php
}
