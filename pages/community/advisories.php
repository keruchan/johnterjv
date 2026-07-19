<?php
/** Community read-only view of published public advisories. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/advisory.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Community User';

$filters = ['q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100)];

try {
    $advisories = advisory_published_list($pdo, $filters);
} catch (PDOException $e) {
    error_log('[CERTREEFY COMMUNITY ADVISORIES ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load advisories at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Advisories</title>

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
        <?php render_certreefy_navigation($currentRole, 'advisories'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Community</div>
                        <h1 class="page-title">Advisories</h1>
                        <p class="text-secondary meta-copy mb-0">Public environmental posts, notices, and office announcements from CENRO.</p>
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

            <section class="docket-panel mb-4" aria-label="Advisory search">
                <form method="get" action="advisories.php" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="advisorySearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="advisorySearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Title or content">
                        </div>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Search</button>
                        <a class="btn btn-outline-secondary" href="advisories.php" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="advisoryListHeading">
                <div class="section-heading">
                    <h2 id="advisoryListHeading">Advisories</h2>
                    <span class="section-note tabular"><?php echo count($advisories); ?> post<?php echo count($advisories) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if ($advisories === []): ?>
                    <p class="text-center text-secondary py-5 mb-0">No advisories have been published yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($advisories as $advisory): ?>
                            <?php $imageUrl = advisory_image_url($advisory); ?>
                            <div class="border rounded-3 p-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                    <h3 class="h6 mb-1"><?php echo e((string) $advisory['title']); ?></h3>
                                    <span class="text-secondary small tabular"><?php echo e(date('M j, Y g:i A', strtotime((string) $advisory['published_at']))); ?></span>
                                </div>
                                <?php if ($advisory['event_at'] !== null): ?>
                                    <p class="small mb-2"><span class="badge text-bg-warning"><i class="bi bi-calendar-event me-1"></i>Schedule: <?php echo e(date('M j, Y g:i A', strtotime((string) $advisory['event_at']))); ?></span></p>
                                <?php endif; ?>
                                <?php if ($imageUrl !== null): ?>
                                    <img src="../../<?php echo e($imageUrl); ?>" alt="<?php echo e((string) ($advisory['image_original_name'] ?? $advisory['title'])); ?>" class="img-fluid rounded-3 mb-2" style="max-height:320px;">
                                <?php endif; ?>
                                <p class="mb-0 text-secondary" style="white-space: pre-line;"><?php echo e((string) $advisory['body']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
