<?php
/**
 * Query, labeling, and audit helpers for CENRO Superadmin user management.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

function user_management_role_labels(): array
{
    return [
        'superadmin' => 'CENRO Superadmin',
        'rps'        => 'RPS User',
        'community'  => 'Community User',
        'ems'        => 'EMS User',
    ];
}

function user_management_editable_roles(): array
{
    return [
        'community' => 'Community User',
        'rps'       => 'RPS User',
        'ems'       => 'EMS User',
    ];
}

function user_management_status_labels(): array
{
    return [
        'pending'   => 'Pending',
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'disabled'  => 'Deactivated',
    ];
}

function user_management_normalize_filters(array $input): array
{
    $roles = user_management_role_labels();
    $statuses = user_management_status_labels();
    $role = trim((string) ($input['role'] ?? ''));
    $status = trim((string) ($input['status'] ?? ''));
    $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return [
        'q'      => substr(trim((string) ($input['q'] ?? '')), 0, 100),
        'role'   => array_key_exists($role, $roles) ? $role : '',
        'status' => array_key_exists($status, $statuses) ? $status : '',
        'page'   => $page === false ? 1 : (int) $page,
    ];
}

function user_management_query_string(array $filters, array $overrides = []): string
{
    $query = array_merge($filters, $overrides);

    if (($query['q'] ?? '') === '') {
        unset($query['q']);
    }
    if (($query['role'] ?? '') === '') {
        unset($query['role']);
    }
    if (($query['status'] ?? '') === '') {
        unset($query['status']);
    }
    if (($query['page'] ?? 1) <= 1) {
        unset($query['page']);
    }

    return http_build_query($query);
}

function user_management_find_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, fname, mname, lname, email, contact, address,
                username, role, status, created_at, updated_at
         FROM tbl_users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function user_management_can_modify(array $user): bool
{
    return (string) ($user['role'] ?? '') !== 'superadmin';
}

function user_management_list(PDO $pdo, array $filters, int $perPage = 10): array
{
    $where = [];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = "CONCAT_WS(' ', fname, COALESCE(mname, ''), lname, email, username, COALESCE(contact, '')) LIKE :search";
        $params[':search'] = '%' . $filters['q'] . '%';
    }
    if ($filters['role'] !== '') {
        $where[] = 'role = :role';
        $params[':role'] = $filters['role'];
    }
    if ($filters['status'] !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filters['status'];
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_users' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min((int) $filters['page'], $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        'SELECT id, fname, mname, lname, email, contact, username, role, status, created_at
         FROM tbl_users' . $whereSql . '
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $name => $value) {
        $listStmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    return [
        'users'       => $listStmt->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'first'       => $total === 0 ? 0 : $offset + 1,
        'last'        => min($offset + $perPage, $total),
    ];
}

function user_management_status_counts(PDO $pdo): array
{
    $counts = array_fill_keys(array_keys(user_management_status_labels()), 0);
    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM tbl_users GROUP BY status');

    foreach ($stmt->fetchAll() as $row) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['total'];
        }
    }

    return $counts;
}

function user_management_target_status(string $action, string $currentStatus): ?string
{
    $transitions = [
        'activate' => [
            'pending'   => 'active',
            'suspended' => 'active',
            'disabled'  => 'active',
        ],
        'suspend' => [
            'active' => 'suspended',
        ],
        'deactivate' => [
            'pending'   => 'disabled',
            'active'    => 'disabled',
            'suspended' => 'disabled',
        ],
    ];

    return $transitions[$action][$currentStatus] ?? null;
}

function user_management_record_audit(
    PDO $pdo,
    int $actorUserId,
    int $targetUserId,
    string $action,
    array $previous,
    array $next,
    array $changedFields
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_user_management_audit
            (actor_user_id, target_user_id, action, previous_role, new_role,
             previous_status, new_status, changed_fields)
         VALUES
            (:actor_user_id, :target_user_id, :action, :previous_role, :new_role,
             :previous_status, :new_status, :changed_fields)'
    );
    $stmt->execute([
        ':actor_user_id'  => $actorUserId,
        ':target_user_id' => $targetUserId,
        ':action'         => $action,
        ':previous_role'  => $previous['role'] ?? null,
        ':new_role'       => $next['role'] ?? null,
        ':previous_status'=> $previous['status'] ?? null,
        ':new_status'     => $next['status'] ?? null,
        ':changed_fields' => $changedFields === [] ? null : implode(',', $changedFields),
    ]);

    $previousSummary = [
        'role' => $previous['role'] ?? null,
        'status' => $previous['status'] ?? null,
    ];
    $nextSummary = [
        'role' => $next['role'] ?? null,
        'status' => $next['status'] ?? null,
    ];
    $generalAction = $action === 'status_changed' ? 'account_status_changed' : 'user_updated';

    record_audit_event(
        $pdo,
        $actorUserId,
        'user_management',
        $generalAction,
        'user',
        $targetUserId,
        $action === 'status_changed'
            ? 'Changed a user account status.'
            : 'Updated user account information.',
        [
            'changed_fields' => array_values($changedFields),
            'previous' => $previousSummary,
            'next' => $nextSummary,
        ]
    );

    if ($action === 'status_changed') {
        $statusLabels = user_management_status_labels();
        $previousStatus = (string) ($previous['status'] ?? '');
        $newStatus = (string) ($next['status'] ?? '');
        $previousLabel = $statusLabels[$previousStatus] ?? ucfirst($previousStatus);
        $newLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);

        create_notification(
            $pdo,
            $targetUserId,
            $actorUserId,
            'account_status',
            'Account status updated',
            'Your CERTREEFY account status changed from ' . $previousLabel . ' to ' . $newLabel . '.',
            'user',
            $targetUserId
        );
    }
}
