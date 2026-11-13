<?php
/** Rollback-safe personal archive/delete checks against the configured local DB. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/record_visibility.php';

class RecordVisibilityProbeStatement extends PDOStatement
{
    public static bool $failNotifications = false;
    public function execute(?array $params = null): bool
    {
        if (self::$failNotifications && preg_match('/INSERT\s+INTO\s+tbl_notifications/i', $this->queryString)) {
            throw new PDOException('Injected notification failure');
        }
        return parent::execute($params);
    }
}
$checks = 0;
function visibility_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function visibility_rejected(callable $call, int $status, string $label): void
{
    try { $call(); } catch (RecordVisibilityException $e) {
        visibility_check($e->httpStatus === $status, $label);
        return;
    }
    throw new RuntimeException('FAIL: ' . $label);
}
function visibility_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
$originalSession = $_SESSION;
$statementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
$pdo->beginTransaction();
try {
    $users = $pdo->query("SELECT id, role FROM tbl_users WHERE status='active' ORDER BY id")->fetchAll();
    $rps = array_values(array_column(array_filter($users, static fn ($u) => $u['role'] === 'rps'), 'id'));
    $ems = (int) array_values(array_column(array_filter($users, static fn ($u) => $u['role'] === 'ems'), 'id'))[0];
    $admin = (int) array_values(array_column(array_filter($users, static fn ($u) => $u['role'] === 'superadmin'), 'id'))[0];
    if (count($rps) < 2) { throw new RuntimeException('This probe requires two existing active RPS accounts.'); }
    $rps1 = (int) $rps[0]; $rps2 = (int) $rps[1];
    $candidate = $pdo->prepare("SELECT a.* FROM tbl_permit_applications a JOIN tbl_users u ON u.id=a.applicant_user_id AND u.status='active' WHERE a.transaction_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tbl_record_user_visibility v WHERE v.entity_type='permit_application' AND v.entity_id=a.id AND v.user_id IN (?, ?, a.applicant_user_id)) LIMIT 1");
    $candidate->execute([$rps1, $rps2]);
    $application = $candidate->fetch();
    if (!$application) { throw new RuntimeException('No untouched submitted permit fixture is available.'); }
    $id = (int) $application['id']; $owner = (int) $application['applicant_user_id'];
    $strangerStmt = $pdo->prepare("SELECT id FROM tbl_users WHERE role='community' AND status='active' AND id<>? LIMIT 1");
    $strangerStmt->execute([$owner]); $stranger = (int) $strangerStmt->fetchColumn();
    $reason = 'Office filing review: keep my queue tidy. <script>text only</script>';
    visibility_rejected(fn () => record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', '   ', bin2hex(random_bytes(32)), 0), 422, 'Blank reason rejected');
    visibility_rejected(fn () => record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', str_repeat('x', 1001), bin2hex(random_bytes(32)), 0), 422, 'Overlong reason rejected');
    visibility_rejected(fn () => record_visibility_change($pdo, $stranger, 'permit_application', $id, 'delete', $reason, bin2hex(random_bytes(32)), 0), 403, 'Another Community user cannot hide this record');
    visibility_rejected(fn () => record_visibility_change($pdo, $admin, 'permit_application', $id, 'delete', $reason, bin2hex(random_bytes(32)), 0), 403, 'Superadmin cannot hide records from its overview');
    visibility_rejected(fn () => record_visibility_change($pdo, $rps1, 'user', $owner, 'delete', $reason, bin2hex(random_bytes(32)), 0), 404, 'Account deletion is not introduced');
    $key = bin2hex(random_bytes(32));
    $first = record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', $reason, $key, 0);
    $eventsAfter = visibility_count($pdo, 'tbl_record_visibility_actions');
    $notificationsAfter = visibility_count($pdo, 'tbl_notifications');
    $again = record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', $reason, $key, 0);
    visibility_check($again['duplicate'] && $again['event_id'] === $first['event_id'], 'Retry is idempotent');
    visibility_check(visibility_count($pdo, 'tbl_record_visibility_actions') === $eventsAfter && visibility_count($pdo, 'tbl_notifications') === $notificationsAfter, 'Retry creates no duplicate history or notifications');
    visibility_rejected(fn () => record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', 'Changed reason', $key, 0), 409, 'Request keys are bound to their original payload');
    foreach ([[$rps1, 0], [$rps2, 1], [$owner, 1], [$admin, 1]] as [$viewer, $expected]) {
        $_SESSION['id'] = $viewer; $_GET['record_view'] = 'active';
        visibility_check(count(record_visibility_filter($pdo, [$application], 'permit_application')) === $expected, 'Archive visibility is isolated for user #' . $viewer);
    }
    $_SESSION['id'] = $rps1; $_GET['record_view'] = 'archived';
    visibility_check(count(record_visibility_filter($pdo, [$application], 'permit_application')) === 1, 'Actor can retrieve its archived record');
    visibility_rejected(fn () => record_visibility_change($pdo, $rps1, 'permit_application', $id, 'restore', $reason, bin2hex(random_bytes(32)), 0), 409, 'Stale concurrent action rejected');
    record_visibility_change($pdo, $rps1, 'permit_application', $id, 'delete', 'Remove from my personal view.', bin2hex(random_bytes(32)), 1);
    $_GET['record_view'] = 'deleted';
    visibility_check(count(record_visibility_filter($pdo, [$application], 'permit_application')) === 1, 'Deleted record remains recoverable in the actor view');
    $history = record_visibility_history($pdo, $owner, 'permit_application', $id);
    visibility_check($history[0]['action'] === 'delete' && $history[1]['reason'] === $reason && (int) $history[1]['actor_user_id'] === $rps1, 'Affected owner sees actor, action and full reason');
    visibility_rejected(fn () => record_visibility_history($pdo, $stranger, 'permit_application', $id), 403, 'Other Community users cannot read this history');
    $notice = $pdo->prepare("SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=? AND created_by_user_id=? AND entity_type='record_visibility_action' AND entity_id=?");
    $notice->execute([$owner, $rps1, $first['event_id']]);
    visibility_check((int) $notice->fetchColumn() === 1, 'Affected owner is notified once');
    $notice->execute([$stranger, $rps1, $first['event_id']]);
    visibility_check((int) $notice->fetchColumn() === 0, 'Unrelated Community users are not notified');
    $adminActivity = record_visibility_activity($pdo, $admin);
    visibility_check(in_array($first['event_id'], array_map('intval', array_column($adminActivity['events'], 'id')), true), 'Superadmin retains visibility of the action');
    record_visibility_change($pdo, $rps1, 'permit_application', $id, 'restore', 'Return to my active work queue.', bin2hex(random_bytes(32)), 2);
    $_GET['record_view'] = 'active';
    visibility_check(count(record_visibility_filter($pdo, [$application], 'permit_application')) === 1, 'Restore returns the record to active view');
    $beforeFailure = [visibility_count($pdo, 'tbl_record_visibility_actions'), visibility_count($pdo, 'tbl_audit_trail'), visibility_count($pdo, 'tbl_notifications')];
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordVisibilityProbeStatement::class]);
    RecordVisibilityProbeStatement::$failNotifications = true;
    $failed = false;
    try { record_visibility_change($pdo, $rps1, 'permit_application', $id, 'delete', $reason, bin2hex(random_bytes(32)), 3); }
    catch (PDOException $e) { $failed = true; }
    RecordVisibilityProbeStatement::$failNotifications = false;
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
    visibility_check($failed, 'Injected notification failure is reported');
    visibility_check($beforeFailure === [visibility_count($pdo, 'tbl_record_visibility_actions'), visibility_count($pdo, 'tbl_audit_trail'), visibility_count($pdo, 'tbl_notifications')], 'History, audit and notifications roll back together');
    visibility_check(count(record_visibility_filter($pdo, [$application], 'permit_application')) === 1, 'Visibility state also rolls back');
    $reload = $pdo->prepare('SELECT * FROM tbl_permit_applications WHERE id=?'); $reload->execute([$id]);
    visibility_check($reload->fetch() === $application, 'Original application and workflow fields are unchanged');
    $pdo->exec("UPDATE tbl_users SET status='suspended' WHERE id=" . $owner);
    $inactiveNotice = record_visibility_change($pdo, $rps1, 'permit_application', $id, 'archive', 'Retain notice for the affected owner.', bin2hex(random_bytes(32)), 3);
    $notice->execute([$owner, $rps1, $inactiveNotice['event_id']]);
    visibility_check((int) $notice->fetchColumn() === 1, 'Affected owners retain a notice even while their account is suspended');
    $pdo->exec("UPDATE tbl_users SET status='active' WHERE id=" . $owner);
    $unicodeReason = str_repeat('🌱', 1000);
    $unicodeEvent = record_visibility_change($pdo, $rps1, 'permit_application', $id, 'restore', $unicodeReason, bin2hex(random_bytes(32)), 4);
    $unicodeHistory = record_visibility_history($pdo, $owner, 'permit_application', $id);
    visibility_check((int) $unicodeHistory[0]['id'] === $unicodeEvent['event_id'] && $unicodeHistory[0]['reason'] === $unicodeReason, 'Unicode reasons are stored completely while notifications remain bounded');
    foreach (record_visibility_catalog() as $type => $definition) {
        if ($type === 'permit_application') { continue; }
        $actor = in_array($type, ['seedling_request', 'seedling_inventory', 'planting_site'], true) ? $ems : $rps1;
        $find = $pdo->prepare('SELECT r.* FROM ' . $definition['table'] . " r WHERE NOT EXISTS (SELECT 1 FROM tbl_record_user_visibility v WHERE v.entity_type=? AND v.entity_id=r.id AND v.user_id=?) LIMIT 1");
        $find->execute([$type, $actor]); $record = $find->fetch();
        if (!$record) { throw new RuntimeException('No unused fixture for ' . $type); }
        record_visibility_change($pdo, $actor, $type, (int) $record['id'], 'archive', 'Personal filing only.', bin2hex(random_bytes(32)), 0);
        $check = $pdo->prepare('SELECT * FROM ' . $definition['table'] . ' WHERE id=?'); $check->execute([$record['id']]);
        visibility_check($check->fetch() === $record, $type . ' archive never changes the domain record');
    }
    $pdo->exec("UPDATE tbl_users SET status='suspended' WHERE id=" . $rps2);
    visibility_rejected(fn () => record_visibility_change($pdo, $rps2, 'permit_application', $id, 'delete', $reason, bin2hex(random_bytes(32)), 0), 403, 'Active status is rechecked at mutation time');
    echo $checks . ' checks passed; all test writes rolled back.' . PHP_EOL;
} finally {
    RecordVisibilityProbeStatement::$failNotifications = false;
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION = $originalSession;
    unset($_GET['record_view']);
}
