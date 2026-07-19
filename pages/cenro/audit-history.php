<?php
/**
 * CENRO Superadmin read-only audit-history and login-attempt viewer.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'superadmin');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Superadmin';
$categoryLabels = audit_trail_category_labels();

$auditFilters = audit_trail_normalize_filters($_GET);
$loginFilters = login_attempts_normalize_filters($_GET);

try {
    $auditList = audit_trail_list($pdo, $auditFilters);
    $loginList = login_attempts_list($pdo, $loginFilters);
} catch (PDOException $e) {
    error_log('[CERTREEFY AUDIT HISTORY LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load audit history at this time. Please try again later.');
}

/** Builds a pagination/filter link preserving the other section's current filters. */
function audit_history_link(array $auditFilters, array $loginFilters, array $auditOverrides = [], array $loginOverrides = []): string
{
    $auditQuery = audit_trail_query_string($auditFilters, $auditOverrides);
    $loginQuery = login_attempts_query_string($loginFilters, $loginOverrides);

    $parsedAudit = [];
    if ($auditQuery !== '') {
        parse_str($auditQuery, $parsedAudit);
    }
    $parsedLogin = [];
    if ($loginQuery !== '') {
        parse_str($loginQuery, $parsedLogin);
    }
    $combined = array_merge($parsedAudit, $parsedLogin);

    return $combined === [] ? 'audit-history.php' : 'audit-history.php?' . http_build_query($combined);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Audit History</title>

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
        <?php render_certreefy_navigation($currentRole, 'audit_history'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Superadmin &middot; Operational Review</div>
                        <h1 class="page-title">Audit History</h1>
                        <p class="text-secondary meta-copy mb-0">A read-only record of what has happened across CERTREEFY — who did what, and when.</p>
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
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <section class="docket-panel mb-4" aria-label="Audit trail filters">
                <form method="get" action="audit-history.php" class="row g-3 align-items-end">
                    <input type="hidden" name="login_q" value="<?php echo e($loginFilters['q']); ?>">
                    <input type="hidden" name="login_status" value="<?php echo e($loginFilters['status']); ?>">
                    <input type="hidden" name="login_date_from" value="<?php echo e($loginFilters['date_from']); ?>">
                    <input type="hidden" name="login_date_to" value="<?php echo e($loginFilters['date_to']); ?>">
                    <div class="col-lg-4">
                        <label for="auditSearch" class="form-label">Search actor</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="auditSearch" name="audit_q" value="<?php echo e($auditFilters['q']); ?>" maxlength="100" placeholder="Name or username">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="auditCategory" class="form-label">Category</label>
                        <select class="form-select" id="auditCategory" name="audit_category">
                            <option value="">All categories</option>
                            <?php foreach ($categoryLabels as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $auditFilters['category'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="auditDateFrom" class="form-label">From</label>
                        <input type="date" class="form-control" id="auditDateFrom" name="audit_date_from" value="<?php echo e($auditFilters['date_from']); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="auditDateTo" class="form-label">To</label>
                        <input type="date" class="form-control" id="auditDateTo" name="audit_date_to" value="<?php echo e($auditFilters['date_to']); ?>">
                    </div>
                    <div class="col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="<?php echo e(audit_history_link(audit_trail_normalize_filters([]), $loginFilters)); ?>" title="Clear audit filters" aria-label="Clear audit filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="auditTrailHeading">
                <div class="section-heading">
                    <h2 id="auditTrailHeading">Activity Log</h2>
                    <span class="section-note tabular">
                        <?php echo e((string) $auditList['first']); ?>-<?php echo e((string) $auditList['last']); ?> of <?php echo e((string) $auditList['total']); ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Who</th>
                                <th scope="col">Category</th>
                                <th scope="col">What happened</th>
                                <th scope="col">Related record</th>
                                <th scope="col">Details</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($auditList['entries'] === []): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-secondary py-5">No activity found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($auditList['entries'] as $entry): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $entry['actor_name']); ?></div>
                                        <div class="small text-secondary">@<?php echo e((string) $entry['actor_username']); ?></div>
                                    </td>
                                    <td><span class="badge <?php echo e(audit_trail_category_badge((string) $entry['category'])); ?>"><?php echo e($categoryLabels[$entry['category']] ?? ucfirst((string) $entry['category'])); ?></span></td>
                                    <td class="text-break"><?php echo e(ucwords(str_replace('_', ' ', (string) $entry['action']))); ?></td>
                                    <td class="small text-secondary"><?php echo $entry['entity_type'] !== null ? e(ucwords(str_replace('_', ' ', (string) $entry['entity_type']))) . ' #' . e((string) $entry['entity_id']) : '-'; ?></td>
                                    <td class="small"><?php echo $entry['description'] !== null ? e((string) $entry['description']) : '<span class="text-secondary">-</span>'; ?></td>
                                    <td class="text-nowrap small"><?php echo e(date('M j, Y g:i A', strtotime((string) $entry['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($auditList['total_pages'] > 1): ?>
                    <nav class="mt-4" aria-label="Audit trail pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php for ($pageNumber = 1; $pageNumber <= $auditList['total_pages']; $pageNumber++): ?>
                                <li class="page-item <?php echo $pageNumber === $auditList['page'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e(audit_history_link($auditFilters, $loginFilters, ['page' => $pageNumber])); ?>"><?php echo e((string) $pageNumber); ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="docket-panel mb-4" aria-label="Login attempt filters">
                <form method="get" action="audit-history.php" class="row g-3 align-items-end">
                    <input type="hidden" name="audit_q" value="<?php echo e($auditFilters['q']); ?>">
                    <input type="hidden" name="audit_category" value="<?php echo e($auditFilters['category']); ?>">
                    <input type="hidden" name="audit_date_from" value="<?php echo e($auditFilters['date_from']); ?>">
                    <input type="hidden" name="audit_date_to" value="<?php echo e($auditFilters['date_to']); ?>">
                    <div class="col-lg-4">
                        <label for="loginSearch" class="form-label">Search username/email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="loginSearch" name="login_q" value="<?php echo e($loginFilters['q']); ?>" maxlength="150" placeholder="Username or email attempted">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="loginStatus" class="form-label">Result</label>
                        <select class="form-select" id="loginStatus" name="login_status">
                            <option value="">All results</option>
                            <option value="success" <?php echo $loginFilters['status'] === 'success' ? 'selected' : ''; ?>>Success</option>
                            <option value="failed" <?php echo $loginFilters['status'] === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="loginDateFrom" class="form-label">From</label>
                        <input type="date" class="form-control" id="loginDateFrom" name="login_date_from" value="<?php echo e($loginFilters['date_from']); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="loginDateTo" class="form-label">To</label>
                        <input type="date" class="form-control" id="loginDateTo" name="login_date_to" value="<?php echo e($loginFilters['date_to']); ?>">
                    </div>
                    <div class="col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="<?php echo e(audit_history_link($auditFilters, login_attempts_normalize_filters([]))); ?>" title="Clear login-attempt filters" aria-label="Clear login-attempt filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="loginAttemptsHeading">
                <div class="section-heading">
                    <h2 id="loginAttemptsHeading">Login Attempts</h2>
                    <span class="section-note tabular">
                        <?php echo e((string) $loginList['first']); ?>-<?php echo e((string) $loginList['last']); ?> of <?php echo e((string) $loginList['total']); ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Username or email entered</th>
                                <th scope="col">Account</th>
                                <th scope="col">Result</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($loginList['attempts'] === []): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-secondary py-5">No login attempts found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($loginList['attempts'] as $attempt): ?>
                                <tr>
                                    <td class="text-break"><?php echo e((string) $attempt['identifier']); ?></td>
                                    <td class="small text-secondary"><?php echo $attempt['matched_user_name'] !== null ? e((string) $attempt['matched_user_name']) : 'Unmatched'; ?></td>
                                    <td><span class="badge <?php echo (int) $attempt['was_successful'] === 1 ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo (int) $attempt['was_successful'] === 1 ? 'Success' : 'Failed'; ?></span></td>
                                    <td class="text-nowrap small"><?php echo e(date('M j, Y g:i A', strtotime((string) $attempt['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($loginList['total_pages'] > 1): ?>
                    <nav class="mt-4" aria-label="Login attempt pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php for ($pageNumber = 1; $pageNumber <= $loginList['total_pages']; $pageNumber++): ?>
                                <li class="page-item <?php echo $pageNumber === $loginList['page'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e(audit_history_link($auditFilters, $loginFilters, [], ['page' => $pageNumber])); ?>"><?php echo e((string) $pageNumber); ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
