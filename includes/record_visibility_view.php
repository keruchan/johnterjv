<?php
require_once __DIR__ . '/record_visibility.php';
require_once __DIR__ . '/view.php';

function render_record_actions(string $type, int $id, string $label = '', bool $showHistory = true): void
{
    $context = $GLOBALS['record_visibility_context'][$type] ?? null;
    $state = $context['states'][$id] ?? [];
    $stateName = record_visibility_state($state);
    $enabled = !empty($context['ready']) && ($context['actor']['role'] ?? '') !== 'superadmin';
    echo '<span class="d-inline-flex gap-1">';
    $actions = $stateName === 'active' ? ['archive' => 'Archive', 'delete' => 'Delete'] : ['restore' => 'Restore'];
    if ($stateName === 'archived') { $actions['delete'] = 'Delete'; }
    foreach ($actions + ($showHistory ? ['history' => 'View record activity'] : []) as $action => $title) {
        $icon = ['archive' => 'archive', 'delete' => 'trash', 'restore' => 'arrow-counterclockwise', 'history' => 'eye'][$action];
        $disabled = ($action === 'history' ? empty($context['ready']) : !$enabled) ? ' disabled' : '';
        $color = $action === 'delete' ? 'danger' : 'secondary';
        echo '<button type="button" class="btn btn-sm btn-outline-' . $color . '" data-record-visibility-action="' . $action
            . '" data-record-type="' . e($type) . '" data-record-id="' . $id . '" data-record-version="' . (int) ($state['version'] ?? 0)
            . '" data-record-label="' . e($label !== '' ? $label : ucwords(str_replace('_', ' ', $type)) . ' #' . $id)
            . '" title="' . e($title) . '" aria-label="' . e($title) . '"' . $disabled . '><i class="bi bi-' . $icon . '" aria-hidden="true"></i></button>';
    }
    echo '</span>';
}

function render_record_visibility_tabs(string $type): void
{
    $context = $GLOBALS['record_visibility_context'][$type] ?? null;
    if (!$context || $context['actor']['role'] === 'superadmin') { return; }
    if (!$context['ready']) {
        echo '<p class="small text-secondary">Archiving and deletion are temporarily unavailable.</p>';
        return;
    }
    echo '<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Record filters">';
    foreach (['active' => 'Active', 'archived' => 'Archived'] as $view => $label) {
        $query = $_GET;
        unset($query['page']);
        $query['record_view'] = $view;
        $class = $context['view'] === $view ? 'btn-certreefy' : 'btn-outline-secondary';
        echo '<a class="btn btn-sm ' . $class . '" href="?' . e(http_build_query($query)) . '"' . ($context['view'] === $view ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    echo '<a class="btn btn-sm btn-outline-secondary ms-auto" href="../records/activity.php"><i class="bi bi-clock-history"></i> Record activity</a></nav>';
}

function render_record_visibility_ui(): void
{
    if (empty($_SESSION['csrf_record_visibility_token'])) { $_SESSION['csrf_record_visibility_token'] = bin2hex(random_bytes(32)); }
    ?>
    <div class="record-visibility-ui" data-record-visibility data-csrf="<?php echo e((string) $_SESSION['csrf_record_visibility_token']); ?>">
        <div class="modal fade" id="recordVisibilityModal" tabindex="-1" aria-labelledby="recordVisibilityTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <form id="recordVisibilityForm">
                    <div class="modal-header"><h2 class="modal-title fs-5" id="recordVisibilityTitle">Record action</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <p class="fw-semibold text-break" id="recordVisibilityLabel"></p>
                        <p class="small text-secondary">Enter a reason to confirm this action.</p>
                        <div class="alert alert-danger" id="recordVisibilityError" role="alert" hidden></div>
                        <label class="form-label" for="recordVisibilityReason">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="recordVisibilityReason" name="reason" rows="4" maxlength="1000" required></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-certreefy" id="recordVisibilityConfirm">Confirm</button></div>
                </form>
            </div></div>
        </div>
        <div class="modal fade" id="recordVisibilityHistory" tabindex="-1" aria-labelledby="recordVisibilityHistoryTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="recordVisibilityHistoryTitle">Record activity</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" id="recordVisibilityHistoryBody"></div>
            </div></div>
        </div>
    </div>
    <script src="../../js/record-visibility.js?v=<?php echo e(certreefy_asset_version('js/record-visibility.js')); ?>" defer></script>
    <?php
}
