<?php
/** CENRO Public Advisories: author, publish, and archive Community-facing notices. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/advisory.php';
require_once __DIR__ . '/../../includes/view.php';

require_roles($pdo, ['rps', 'superadmin']);

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Officer';

if (advisory_actor($pdo, $userId) === null) {
    http_response_code(403);
    die('You are not authorized to view advisories.');
}

if (empty($_SESSION['csrf_advisory_token'])) {
    $_SESSION['csrf_advisory_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_advisory_token'];

$flash = null;
if (!empty($_SESSION['advisory_flash']) && is_array($_SESSION['advisory_flash'])) {
    $flash = $_SESSION['advisory_flash'];
    unset($_SESSION['advisory_flash']);
}

$statuses = advisory_statuses();
$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'q' => substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

try {
    $advisories = advisory_list($pdo, $filters);
    $summary = advisory_summary($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY ADVISORIES LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load advisories at this time. Please try again later.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Public Advisories</title>

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
        <?php render_certreefy_navigation($currentRole, 'announcements'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Operations</div>
                        <h1 class="page-title">Public Advisories</h1>
                        <p class="text-secondary meta-copy mb-0">Announcements, notices, and environmental information posts for Community users.</p>
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

            <div class="alert alert-light border mb-4"><i class="bi bi-info-circle me-1"></i><strong>Published</strong> posts show to Community users; marking <strong>Show on public homepage</strong> also adds them to the landing-page carousel. Image optional. Drafts and archived posts stay private.</div>

            <section class="row g-3 mb-4" aria-label="Advisory summary">
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-file-earmark-text"></i></span><span class="ledger-tag">Draft</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['draft']; ?></div>
                        <div class="ledger-caption">Awaiting publication</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card accent-teal stagger-2">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-megaphone"></i></span><span class="ledger-tag">Published</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['published']; ?></div>
                        <div class="ledger-caption">Visible to Community</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-4">
                    <div class="ledger-card accent-amber stagger-3">
                        <div class="d-flex justify-content-between align-items-start"><span class="ledger-icon"><i class="bi bi-archive"></i></span><span class="ledger-tag">Archived</span></div>
                        <div class="ledger-value tabular"><?php echo (int) $summary['archived']; ?></div>
                        <div class="ledger-caption">Retired posts</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel mb-4" aria-labelledby="addAdvisoryHeading">
                <div class="section-heading"><h2 id="addAdvisoryHeading">New Announcement</h2></div>
                <form method="post" action="advisory-action.php" class="row g-3" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_advisory">
                    <div class="col-md-8"><label class="form-label" for="advisoryTitle">Title</label><input class="form-control" id="advisoryTitle" type="text" name="title" maxlength="200" required></div>
                    <div class="col-md-4"><label class="form-label" for="advisoryEvent">Cutting schedule <span class="text-secondary">(optional)</span></label><input class="form-control" id="advisoryEvent" type="datetime-local" name="event_at"></div>
                    <div class="col-12"><label class="form-label" for="advisoryBody">Content</label><textarea class="form-control" id="advisoryBody" name="body" rows="3" maxlength="5000" required placeholder="e.g. Please be advised of scheduled tree-cutting along the highway. We apologize for the inconvenience."></textarea></div>
                    <div class="col-md-8"><label class="form-label" for="advisoryImage">Announcement image <span class="text-secondary">(optional &middot; JPG, PNG, or WebP, up to <?php echo e(advisory_image_max_size_label()); ?>)</span></label><input class="form-control" id="advisoryImage" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check mt-4 mt-md-0">
                            <input type="hidden" name="is_public" value="0">
                            <input class="form-check-input" type="checkbox" id="advisoryPublic" name="is_public" value="1" checked>
                            <label class="form-check-label" for="advisoryPublic">Show on public homepage</label>
                        </div>
                    </div>
                    <div class="col-12"><button class="btn btn-certreefy" type="submit"><i class="bi bi-plus-circle me-1"></i>Save as draft</button></div>
                </form>
            </section>

            <section class="docket-panel mb-4" aria-label="Advisory filters">
                <form method="get" action="advisories.php" class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label for="advisorySearch" class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="advisorySearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Title or content">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="advisories.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel" aria-labelledby="advisoryRegistryHeading">
                <div class="section-heading">
                    <h2 id="advisoryRegistryHeading">Advisory Registry</h2>
                    <span class="section-note tabular"><?php echo count($advisories); ?> advisor<?php echo count($advisories) === 1 ? 'y' : 'ies'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-table-tools data-tt-search="false">
                        <thead>
                            <tr>
                                <th scope="col">Announcement</th>
                                <th scope="col">Status</th>
                                <th scope="col">Visibility</th>
                                <th scope="col">Schedule</th>
                                <th scope="col">Published</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($advisories === []): ?>
                                <tr><td colspan="6" class="text-center text-secondary py-5">No announcements recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($advisories as $advisory): ?>
                                <?php $imageUrl = advisory_image_url($advisory); ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($imageUrl !== null): ?>
                                                <img src="../../<?php echo e($imageUrl); ?>" alt="" width="46" height="46" style="object-fit:cover;border-radius:6px;flex:0 0 46px;">
                                            <?php else: ?>
                                                <span class="d-inline-flex align-items-center justify-content-center text-secondary" style="width:46px;height:46px;border-radius:6px;background:var(--sprout-100,#eef4ef);flex:0 0 46px;"><i class="bi bi-megaphone"></i></span>
                                            <?php endif; ?>
                                            <span class="fw-semibold text-break"><?php echo e((string) $advisory['title']); ?></span>
                                        </div>
                                    </td>
                                    <td><span class="badge <?php echo e(advisory_status_badge((string) $advisory['current_status'])); ?>"><?php echo e(advisory_status_label((string) $advisory['current_status'])); ?></span></td>
                                    <td>
                                        <?php if ((int) $advisory['is_public'] === 1): ?>
                                            <span class="badge text-bg-success">Public</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Internal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo $advisory['event_at'] !== null ? e(date('M j, Y g:i A', strtotime((string) $advisory['event_at']))) : '&mdash;'; ?></td>
                                    <td class="small text-secondary"><?php echo $advisory['published_at'] !== null ? e(date('M j, Y g:i A', strtotime((string) $advisory['published_at']))) : '&mdash;'; ?></td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-primary edit-advisory-action" type="button" data-bs-toggle="modal" data-bs-target="#editAdvisoryModal"
                                                data-advisory-id="<?php echo (int) $advisory['id']; ?>"
                                                data-title="<?php echo e((string) $advisory['title']); ?>"
                                                data-body="<?php echo e((string) $advisory['body']); ?>"
                                                data-is-public="<?php echo (int) $advisory['is_public']; ?>"
                                                data-event-at="<?php echo $advisory['event_at'] !== null ? e(date('Y-m-d\TH:i', strtotime((string) $advisory['event_at']))) : ''; ?>"
                                                data-image-url="<?php echo $imageUrl !== null ? e('../../' . $imageUrl) : ''; ?>"
                                            ><i class="bi bi-pencil"></i></button>
                                            <?php if ((string) $advisory['current_status'] === 'draft'): ?>
                                                <form method="post" action="advisory-action.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="publish_advisory">
                                                    <input type="hidden" name="advisory_id" value="<?php echo (int) $advisory['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-megaphone"></i> Publish</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array((string) $advisory['current_status'], ['draft', 'published'], true)): ?>
                                                <form method="post" action="advisory-action.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="archive_advisory">
                                                    <input type="hidden" name="advisory_id" value="<?php echo (int) $advisory['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-warning" type="submit"><i class="bi bi-archive"></i> Archive</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="modal fade" id="editAdvisoryModal" tabindex="-1" aria-labelledby="editAdvisoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="advisory-action.php" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="editAdvisoryModalLabel">Edit Announcement</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_advisory">
                        <input type="hidden" name="advisory_id" id="editAdvisoryId" value="">
                        <div class="row g-3">
                            <div class="col-md-8"><label class="form-label" for="editTitle">Title</label><input class="form-control" id="editTitle" type="text" name="title" maxlength="200" required></div>
                            <div class="col-md-4"><label class="form-label" for="editEvent">Cutting schedule <span class="text-secondary">(optional)</span></label><input class="form-control" id="editEvent" type="datetime-local" name="event_at"></div>
                            <div class="col-12"><label class="form-label" for="editBody">Content</label><textarea class="form-control" id="editBody" name="body" rows="5" maxlength="5000" required></textarea></div>
                            <div class="col-12">
                                <label class="form-label">Announcement image</label>
                                <div id="editImagePreviewWrap" class="mb-2 d-none">
                                    <img id="editImagePreview" src="" alt="Current announcement image" style="max-height:120px;border-radius:8px;border:1px solid var(--line,#e2ddca);">
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="editRemoveImage" name="remove_image" value="1">
                                        <label class="form-check-label small" for="editRemoveImage">Remove current image</label>
                                    </div>
                                </div>
                                <input class="form-control" id="editImage" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">Uploading a new image replaces the current one. JPG, PNG, or WebP up to <?php echo e(advisory_image_max_size_label()); ?>.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_public" value="0">
                                    <input class="form-check-input" type="checkbox" id="editIsPublic" name="is_public" value="1">
                                    <label class="form-check-label" for="editIsPublic">Show on public homepage</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.edit-advisory-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('editAdvisoryId').value = button.dataset.advisoryId;
                document.getElementById('editTitle').value = button.dataset.title;
                document.getElementById('editBody').value = button.dataset.body;
                document.getElementById('editEvent').value = button.dataset.eventAt || '';
                document.getElementById('editIsPublic').checked = button.dataset.isPublic === '1';

                var removeBox = document.getElementById('editRemoveImage');
                var fileInput = document.getElementById('editImage');
                removeBox.checked = false;
                fileInput.value = '';

                var wrap = document.getElementById('editImagePreviewWrap');
                var preview = document.getElementById('editImagePreview');
                if (button.dataset.imageUrl) {
                    preview.src = button.dataset.imageUrl;
                    wrap.classList.remove('d-none');
                } else {
                    preview.src = '';
                    wrap.classList.add('d-none');
                }
            });
        });
    </script>
    <script src="../../js/table-tools.js"></script>
</body>
</html>
