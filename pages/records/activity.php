<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_roles($pdo, ['community', 'rps', 'ems', 'superadmin']);
$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$folder = match ($currentRole) { 'superadmin', 'rps' => 'cenro', default => $currentRole };
$events = [];
$next = 0;
$message = '';
try {
    if (!record_visibility_ready($pdo)) { throw new RecordVisibilityException('Record activity is temporarily unavailable.', 503); }
    $requestedEvent = $_GET['event'] ?? '';
    if (!is_string($requestedEvent)) { throw new RecordVisibilityException('Invalid activity identifier.', 404); }
    if ($requestedEvent !== '') {
        if (!ctype_digit($requestedEvent)) { throw new RecordVisibilityException('Activity not found.', 404); }
        $stmt = $pdo->prepare('SELECT entity_type, entity_id FROM tbl_record_visibility_actions WHERE id = ?');
        $stmt->execute([(int) $requestedEvent]);
        $event = $stmt->fetch();
        if (!$event) { throw new RecordVisibilityException('Activity not found.', 404); }
        $events = record_visibility_history($pdo, $userId, $event['entity_type'], (int) $event['entity_id'], $_GET);
    } else {
        $before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
        $result = record_visibility_activity($pdo, $userId, (int) $before, $_GET);
        $events = $result['events'];
        $next = $result['next'];
    }
} catch (RecordVisibilityException $e) {
    http_response_code($e->httpStatus);
    $message = $e->getMessage();
} catch (Throwable $e) {
    error_log('[CERTREEFY RECORD ACTIVITY] ' . $e->getMessage());
    http_response_code(500);
    $message = 'Unable to load record activity.';
}
$events = registry_date_rows($events, 'created_at');
$shownControls = [];
if ($currentRole !== 'superadmin') {
    foreach (array_unique(array_column($events, 'entity_type')) as $type) {
        record_visibility_filter($pdo, [], $type);
    }
}
?>
<!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="../<?php echo e($folder); ?>/">
    <title>CERTREEFY | Record Activity</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../../css/dashboard.css?v=<?php echo e(certreefy_asset_version('css/dashboard.css')); ?>" rel="stylesheet">
</head><body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'record_activity', user_active_permissions($pdo, $userId)); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4"><div class="eyebrow">Record history</div><h1 class="page-title">Record Activity</h1><p class="meta-copy mb-0">Review record actions and their reasons.</p></section>
            <?php render_registry_date_filter('Record action dates', '', '../records/activity.php'); ?>
            <section class="docket-panel">
                <div class="section-heading"><h2>Archive, deletion, and restoration history</h2><a class="btn btn-sm btn-outline-secondary" href="../records/activity.php">Latest activity</a></div>
                <?php if ($message !== ''): ?><div class="alert alert-danger" role="alert"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($events === [] && $message === ''): ?><p class="text-secondary mb-0">No record actions have been recorded.</p><?php endif; ?>
                <?php foreach ($events as $event): ?>
                    <article class="border rounded p-3 mb-3">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-1"><strong class="text-break"><?php echo e($event['record_label']); ?></strong><span class="badge text-bg-light border"><?php echo e(['archive' => 'Archived', 'delete' => 'Deleted', 'restore' => 'Restored'][$event['action']]); ?></span></div>
                        <p class="small text-secondary mb-2"><?php echo e($event['actor_name'] . ' (' . strtoupper($event['actor_role']) . ')'); ?> &middot; <?php echo e($event['created_at']); ?></p>
                        <p class="record-visibility-reason mb-0"><?php echo e($event['reason']); ?></p>
                        <?php
                        $recordKey = $event['entity_type'] . ':' . $event['entity_id'];
                        $personalState = $GLOBALS['record_visibility_context'][$event['entity_type']]['states'][(int) $event['entity_id']] ?? [];
                        if ($currentRole !== 'superadmin' && !isset($shownControls[$recordKey]) && record_visibility_state($personalState) !== 'active'):
                            $shownControls[$recordKey] = true;
                        ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><span class="small text-secondary"><?php echo e(ucfirst(record_visibility_state($personalState))); ?></span><?php render_record_actions($event['entity_type'], (int) $event['entity_id'], $event['record_label']); ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($next > 0): ?><a class="btn btn-outline-secondary" href="<?php echo e('../records/activity.php' . registry_url(['before'=>$next])); ?>">Older activity</a><?php endif; ?>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
