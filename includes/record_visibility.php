<?php
/** Personal record visibility; never changes the underlying domain workflow. */
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/permissions.php';

class RecordVisibilityException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function record_visibility_catalog(): array
{
    return [
        'permit_application' => ['table' => 'tbl_permit_applications', 'label' => 'transaction_id', 'owner' => 'applicant_user_id', 'category' => 'permit'],
        'seedling_request' => ['table' => 'tbl_seedling_requests', 'label' => 'request_reference', 'owner' => 'requester_user_id', 'category' => 'seedling'],
        'illegal_logging_report' => ['table' => 'tbl_illegal_logging_reports', 'label' => 'report_reference', 'owner' => 'reporter_user_id', 'category' => 'illegal_logging'],
        'seedling_inventory' => ['table' => 'tbl_seedling_inventory', 'label' => 'common_name', 'owner' => 'created_by_user_id', 'category' => 'seedling'],
        'planting_site' => ['table' => 'tbl_planting_sites', 'label' => 'site_name', 'owner' => 'created_by_user_id', 'category' => 'area_management'],
        'area_zone' => ['table' => 'tbl_area_zones', 'label' => 'zone_name', 'owner' => 'created_by_user_id', 'category' => 'area_management'],
        'advisory' => ['table' => 'tbl_advisories', 'label' => 'title', 'owner' => 'created_by_user_id', 'category' => 'advisory'],
    ];
}

function record_visibility_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tbl_record_user_visibility', 'tbl_record_visibility_actions')")->fetchColumn() === 2;
    }
    return $ready;
}

function record_visibility_actor(PDO $pdo, int $userId, bool $lock = false): array
{
    $stmt = $pdo->prepare('SELECT id, fname, lname, role, status FROM tbl_users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $actor = $stmt->fetch();
    if (!$actor || $actor['status'] !== 'active' || !in_array($actor['role'], ['community', 'rps', 'ems', 'superadmin'], true)) {
        throw new RecordVisibilityException('An active account is required.', 403);
    }
    return $actor;
}

/** Authorize against current role, ownership and existing exceptional permissions. */
function record_visibility_record(PDO $pdo, string $type, int $id, array $actor, bool $lock = false): array
{
    $catalog = record_visibility_catalog();
    if (!isset($catalog[$type]) || $id < 1) {
        throw new RecordVisibilityException('Unsupported record.', 404);
    }
    $stmt = $pdo->prepare('SELECT * FROM ' . $catalog[$type]['table'] . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) { throw new RecordVisibilityException('Record not found.', 404); }
    $role = (string) $actor['role'];
    $owner = (int) ($record[$catalog[$type]['owner']] ?? 0) === (int) $actor['id'];
    // Superadmin retains visibility of the record activity for every module.
    $allowed = $role === 'superadmin';
    if (!$allowed) {
        $allowed = match ($type) {
            'permit_application' => ($role === 'community' && $owner)
                || ($role === 'rps' && $record['transaction_id'] !== null),
            'seedling_request', 'illegal_logging_report' => ($role === 'community' && $owner)
                || ($type === 'seedling_request' && $role === 'ems')
                || ($type === 'illegal_logging_report' && $role === 'rps'),
            'seedling_inventory', 'planting_site' => $role === 'ems',
            'area_zone', 'advisory' => $role === 'rps',
            default => false,
        };
        if ($type === 'permit_application' && $role === 'ems') {
            $requirement = $pdo->prepare('SELECT id FROM tbl_permit_donation_requirements WHERE application_id = ?');
            $requirement->execute([$id]);
            $allowed = (bool) $requirement->fetchColumn();
        }
    }
    if (!$allowed) { throw new RecordVisibilityException('You cannot access this record.', 403); }
    return $record;
}

function record_visibility_view(): string
{
    $view = $_GET['record_view'] ?? 'active';
    return is_string($view) && in_array($view, ['active', 'archived', 'deleted'], true) ? $view : 'active';
}

function record_visibility_state(array $state): string
{
    return !empty($state['is_deleted']) ? 'deleted' : (!empty($state['is_archived']) ? 'archived' : 'active');
}

/** Filter on the server; the same row remains visible to everyone else. */
function record_visibility_filter(PDO $pdo, array $rows, string $type, string $idKey = 'id'): array
{
    $userId = (int) ($_SESSION['id'] ?? 0);
    $actor = record_visibility_actor($pdo, $userId);
    $states = [];
    $ready = record_visibility_ready($pdo);
    if ($ready) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_record_user_visibility WHERE user_id = ? AND entity_type = ?');
        $stmt->execute([$userId, $type]);
        foreach ($stmt->fetchAll() as $state) { $states[(int) $state['entity_id']] = $state; }
    }
    $view = $actor['role'] === 'superadmin' ? 'all' : record_visibility_view();
    $GLOBALS['record_visibility_context'][$type] = compact('states', 'actor', 'ready', 'view');
    if (!$ready || $view === 'all') { return $rows; }
    return array_values(array_filter($rows, static fn (array $row): bool => record_visibility_state($states[(int) $row[$idKey]] ?? []) === $view));
}

function record_visibility_cut(string $text, int $bytes): string
{
    $text = substr($text, 0, $bytes);
    while ($text !== '' && !preg_match('//u', $text)) { $text = substr($text, 0, -1); }
    return $text;
}

/** Transactionally persist only the actor's flags, immutable history and notices. */
function record_visibility_change(PDO $pdo, int $userId, string $type, int $id, string $action, string $reason, string $requestKey, int $expectedVersion): array
{
    if (!record_visibility_ready($pdo)) { throw new RecordVisibilityException('Archiving and deletion are temporarily unavailable.', 503); }
    $reason = trim($reason);
    if (!in_array($action, ['archive', 'delete', 'restore'], true) || $reason === '' || !preg_match('//u', $reason) || preg_match_all('/./us', $reason) > 1000
        || !preg_match('/^[a-f0-9]{64}$/', $requestKey) || $expectedVersion < 0) {
        throw new RecordVisibilityException('Choose a valid action and provide a reason of up to 1000 characters.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) { $pdo->beginTransaction(); } else { $pdo->exec('SAVEPOINT record_visibility_action'); }
    try {
        $actor = record_visibility_actor($pdo, $userId, true);
        if ($actor['role'] === 'superadmin') {
            throw new RecordVisibilityException('This action is not available for your account.', 403);
        }
        $record = record_visibility_record($pdo, $type, $id, $actor, true);
        $existing = $pdo->prepare('SELECT * FROM tbl_record_visibility_actions WHERE request_key = ?');
        $existing->execute([$requestKey]);
        $duplicate = $existing->fetch();
        if ($duplicate) {
            if ((int) $duplicate['actor_user_id'] !== $userId || $duplicate['entity_type'] !== $type || (int) $duplicate['entity_id'] !== $id || $duplicate['action'] !== $action || $duplicate['reason'] !== $reason) {
                throw new RecordVisibilityException('This action key has already been used.', 409);
            }
            if ($ownsTransaction) { $pdo->commit(); } else { $pdo->exec('RELEASE SAVEPOINT record_visibility_action'); }
            return ['event_id' => (int) $duplicate['id'], 'state' => $duplicate['new_state'], 'duplicate' => true];
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO tbl_record_user_visibility (user_id, entity_type, entity_id) VALUES (?, ?, ?)');
        $insert->execute([$userId, $type, $id]);
        $stmt = $pdo->prepare('SELECT * FROM tbl_record_user_visibility WHERE user_id = ? AND entity_type = ? AND entity_id = ? FOR UPDATE');
        $stmt->execute([$userId, $type, $id]);
        $state = $stmt->fetch();
        $previous = record_visibility_state($state);
        $next = ['archive' => 'archived', 'delete' => 'deleted', 'restore' => 'active'][$action];
        if ((int) $state['version'] !== $expectedVersion || $previous === $next || ($previous === 'deleted' && $action === 'archive')) {
            throw new RecordVisibilityException('This record has changed. Refresh the page before trying again.', 409);
        }
        $update = $pdo->prepare('UPDATE tbl_record_user_visibility SET is_deleted = ?, is_archived = ?, reason = ?, version = version + 1 WHERE id = ?');
        $update->execute([(int) ($next === 'deleted'), (int) ($next === 'archived'), $reason, $state['id']]);
        $catalog = record_visibility_catalog()[$type];
        $label = record_visibility_cut((string) ($record[$catalog['label']] ?: ucwords(str_replace('_', ' ', $type)) . ' #' . $id), 255);
        $name = record_visibility_cut(trim($actor['fname'] . ' ' . $actor['lname']), 255);
        $event = $pdo->prepare('INSERT INTO tbl_record_visibility_actions (actor_user_id, actor_name, actor_role, entity_type, entity_id, record_label, action, previous_state, new_state, reason, request_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $event->execute([$userId, $name, $actor['role'], $type, $id, $label, $action, $previous, $next, $reason, $requestKey]);
        $eventId = (int) $pdo->lastInsertId();
        $verb = ['archive' => 'archived', 'delete' => 'hidden', 'restore' => 'restored'][$action];
        $pastVerb = $action === 'delete' ? 'deleted' : $verb;
        $description = record_visibility_cut($name . ' ' . $pastVerb . ' ' . $label . '. Reason: ' . $reason, 255);
        record_audit_event($pdo, $userId, 'record_visibility', 'record_' . $verb, $type, $id, $description,
            ['reason' => $reason, 'previous_state' => $previous, 'new_state' => $next, 'visibility_user_id' => $userId, 'visibility_event_id' => $eventId]);
        $owner = (int) ($record[$catalog['owner']] ?? 0);
        $candidates = $pdo->prepare("SELECT id, fname, lname, role, status FROM tbl_users WHERE (status = 'active' AND role IN ('rps', 'ems', 'superadmin')) OR id = ?");
        $candidates->execute([$owner]);
        foreach ($candidates->fetchAll() as $recipient) {
            if ((int) $recipient['id'] === $userId) { continue; }
            try { record_visibility_record($pdo, $type, $id, $recipient); } catch (RecordVisibilityException $e) { continue; }
            $message = strtoupper((string) $actor['role']) . ' ' . $name . ' ' . $pastVerb . ' ' . $label . '. Reason: ' . $reason;
            $message = record_visibility_cut($message, 940) . ' Open Record Activity for the full reason.';
            create_notification($pdo, (int) $recipient['id'], $userId, 'record_visibility', 'Record ' . $pastVerb, $message, 'record_visibility_action', $eventId);
        }
        if ($ownsTransaction) { $pdo->commit(); } else { $pdo->exec('RELEASE SAVEPOINT record_visibility_action'); }
        unset($GLOBALS['record_visibility_context'][$type]);
        return ['event_id' => $eventId, 'state' => $next, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        elseif (!$ownsTransaction && $pdo->inTransaction()) { $pdo->exec('ROLLBACK TO SAVEPOINT record_visibility_action'); $pdo->exec('RELEASE SAVEPOINT record_visibility_action'); }
        throw $e;
    }
}

function record_visibility_history(PDO $pdo, int $userId, string $type, int $id, array $filters = []): array
{
    $actor = record_visibility_actor($pdo, $userId);
    record_visibility_record($pdo, $type, $id, $actor);
    $where = 'entity_type = ? AND entity_id = ?';
    $params = [$type, $id];
    $range = registry_range($filters);
    if ($range['error'] !== '') { $where .= ' AND 1 = 0'; }
    else {
        if ($range['from'] !== '') { $where .= ' AND created_at >= ?'; $params[] = $range['from'] . ' 00:00:00'; }
        if ($range['to'] !== '') { $where .= ' AND created_at <= ?'; $params[] = $range['to'] . ' 23:59:59'; }
    }
    $stmt = $pdo->prepare('SELECT * FROM tbl_record_visibility_actions WHERE ' . $where . ' ORDER BY id DESC LIMIT 100');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function record_visibility_activity(PDO $pdo, int $userId, int $before = 0, array $filters = []): array
{
    $actor = record_visibility_actor($pdo, $userId);
    $where = '1 = 1';
    $params = [];
    if ($actor['role'] === 'community') {
        $where = "((e.entity_type = 'permit_application' AND EXISTS (SELECT 1 FROM tbl_permit_applications p WHERE p.id = e.entity_id AND p.applicant_user_id = ?))
            OR (e.entity_type = 'seedling_request' AND EXISTS (SELECT 1 FROM tbl_seedling_requests s WHERE s.id = e.entity_id AND s.requester_user_id = ?))
            OR (e.entity_type = 'illegal_logging_report' AND EXISTS (SELECT 1 FROM tbl_illegal_logging_reports r WHERE r.id = e.entity_id AND r.reporter_user_id = ?)))";
        $params = [$userId, $userId, $userId];
    } elseif ($actor['role'] === 'rps') {
        $where = "e.entity_type IN ('permit_application', 'illegal_logging_report', 'area_zone', 'advisory')";
    } elseif ($actor['role'] === 'ems') {
        $where = "e.entity_type IN ('permit_application', 'seedling_request', 'seedling_inventory', 'planting_site')";
    }
    $range = registry_range($filters);
    if ($range['error'] !== '') { $where .= ' AND 1 = 0'; }
    else {
        if ($range['from'] !== '') { $where .= ' AND e.created_at >= ?'; $params[] = $range['from'] . ' 00:00:00'; }
        if ($range['to'] !== '') { $where .= ' AND e.created_at <= ?'; $params[] = $range['to'] . ' 23:59:59'; }
    }
    if ($before > 0) { $where .= ' AND e.id < ?'; $params[] = $before; }
    $stmt = $pdo->prepare('SELECT e.* FROM tbl_record_visibility_actions e WHERE ' . $where . ' ORDER BY e.id DESC LIMIT 51');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > 50;
    if ($hasMore) { array_pop($rows); }
    $cursor = $rows !== [] ? (int) $rows[count($rows) - 1]['id'] : 0;
    $rows = array_values(array_filter($rows, static function (array $row) use ($pdo, $actor): bool {
        try { record_visibility_record($pdo, $row['entity_type'], (int) $row['entity_id'], $actor); return true; }
        catch (RecordVisibilityException $e) { return false; }
    }));
    return ['events' => $rows, 'next' => $hasMore ? $cursor : 0];
}
