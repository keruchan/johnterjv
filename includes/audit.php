<?php
/**
 * Reusable audit trail writer for authentication and domain workflows.
 */

function audit_event_categories(): array
{
    return [
        'authentication',
        'user_management',
        'permit',
        'approval',
        'verification',
    ];
}

function audit_request_ip_address(): ?string
{
    $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false ? $ipAddress : null;
}

function audit_request_user_agent(): ?string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    return $userAgent === '' ? null : substr($userAgent, 0, 255);
}

function record_audit_event(
    PDO $pdo,
    int $actorUserId,
    string $category,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    array $details = []
): int {
    $category = trim($category);
    $action = trim($action);
    $entityType = $entityType === null ? null : trim($entityType);
    $description = $description === null ? null : trim($description);

    if ($actorUserId < 1) {
        throw new InvalidArgumentException('An audit event requires a responsible user.');
    }
    if (!in_array($category, audit_event_categories(), true)) {
        throw new InvalidArgumentException('Unsupported audit event category.');
    }
    if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $action)) {
        throw new InvalidArgumentException('Audit actions must use 2-100 lowercase snake-case characters.');
    }
    if ($entityType !== null && !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $entityType)) {
        throw new InvalidArgumentException('Audit entity types must use lowercase snake case.');
    }
    if ($entityId !== null && ($entityId < 1 || $entityType === null)) {
        throw new InvalidArgumentException('An audit entity ID requires a valid entity type.');
    }
    if ($description === '') {
        $description = null;
    }
    if ($description !== null && strlen($description) > 255) {
        throw new InvalidArgumentException('Audit descriptions must not exceed 255 characters.');
    }

    $detailsJson = null;
    if ($details !== []) {
        $detailsJson = json_encode(
            $details,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (strlen($detailsJson) > 60000) {
            throw new InvalidArgumentException('Audit details are too large.');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_audit_trail
            (actor_user_id, category, action, entity_type, entity_id,
             description, details, ip_address, user_agent)
         VALUES
            (:actor_user_id, :category, :action, :entity_type, :entity_id,
             :description, :details, :ip_address, :user_agent)'
    );
    $stmt->execute([
        ':actor_user_id' => $actorUserId,
        ':category'      => $category,
        ':action'        => $action,
        ':entity_type'   => $entityType,
        ':entity_id'     => $entityId,
        ':description'   => $description,
        ':details'       => $detailsJson,
        ':ip_address'    => audit_request_ip_address(),
        ':user_agent'    => audit_request_user_agent(),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Read-only audit-history registry for the Superadmin viewer: filterable,
 * paginated listing of the reusable general audit trail.
 */

function audit_trail_category_labels(): array
{
    return [
        'authentication' => 'Authentication',
        'user_management' => 'User Management',
        'permit' => 'Permit',
        'approval' => 'Approval',
        'verification' => 'Verification',
    ];
}

function audit_trail_category_badge(string $category): string
{
    return match ($category) {
        'authentication' => 'text-bg-secondary',
        'user_management' => 'text-bg-primary',
        'permit' => 'text-bg-info',
        'approval' => 'text-bg-success',
        'verification' => 'text-bg-warning',
        default => 'text-bg-light border',
    };
}

function audit_trail_normalize_filters(array $input): array
{
    $categories = audit_trail_category_labels();
    $category = trim((string) ($input['audit_category'] ?? ''));
    $page = filter_var($input['audit_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $dateFrom = trim((string) ($input['audit_date_from'] ?? ''));
    $dateTo = trim((string) ($input['audit_date_to'] ?? ''));

    return [
        'q' => substr(trim((string) ($input['audit_q'] ?? '')), 0, 100),
        'category' => array_key_exists($category, $categories) ? $category : '',
        'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '',
        'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '',
        'page' => $page === false ? 1 : (int) $page,
    ];
}

function audit_trail_query_string(array $filters, array $overrides = []): string
{
    $query = [
        'audit_q' => $filters['q'],
        'audit_category' => $filters['category'],
        'audit_date_from' => $filters['date_from'],
        'audit_date_to' => $filters['date_to'],
        'audit_page' => $filters['page'],
    ];
    foreach ($overrides as $key => $value) {
        $query['audit_' . $key] = $value;
    }
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || ($key === 'audit_page' && (int) $value <= 1)) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function audit_trail_list(PDO $pdo, array $filters, int $perPage = 20): array
{
    $where = [];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = "CONCAT_WS(' ', u.fname, u.lname, u.username) LIKE :search";
        $params[':search'] = '%' . $filters['q'] . '%';
    }
    if ($filters['category'] !== '') {
        $where[] = 'a.category = :category';
        $params[':category'] = $filters['category'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'a.created_at >= :date_from';
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'a.created_at <= :date_to';
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_audit_trail a INNER JOIN tbl_users u ON u.id = a.actor_user_id' . $whereSql
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min((int) $filters['page'], $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        'SELECT a.id, a.actor_user_id, a.category, a.action, a.entity_type, a.entity_id,
                a.description, a.details, a.ip_address, a.user_agent, a.created_at,
                CONCAT(u.fname, \' \', u.lname) AS actor_name, u.username AS actor_username
         FROM tbl_audit_trail a
         INNER JOIN tbl_users u ON u.id = a.actor_user_id' . $whereSql . '
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $name => $value) {
        $listStmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    return [
        'entries' => $listStmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'first' => $total === 0 ? 0 : $offset + 1,
        'last' => min($offset + $perPage, $total),
    ];
}
