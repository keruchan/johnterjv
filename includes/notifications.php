<?php
/**
 * Reusable notification creation helpers for current and planned workflows.
 */

function notification_types(): array
{
    return [
        'permit_status',
        'donation_verification',
        'account_status',
        'system_announcement',
        'seedling_request',
        'illegal_logging_report',
    ];
}

function create_notification(
    PDO $pdo,
    int $recipientUserId,
    int $createdByUserId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): int {
    $type = trim($type);
    $title = trim($title);
    $message = trim($message);
    $entityType = $entityType === null ? null : trim($entityType);

    if ($recipientUserId < 1 || $createdByUserId < 1) {
        throw new InvalidArgumentException('A notification requires valid recipient and responsible users.');
    }
    if (!in_array($type, notification_types(), true)) {
        throw new InvalidArgumentException('Unsupported notification type.');
    }
    if ($title === '' || strlen($title) > 150) {
        throw new InvalidArgumentException('Notification titles must contain 1-150 characters.');
    }
    if ($message === '' || strlen($message) > 1000) {
        throw new InvalidArgumentException('Notification messages must contain 1-1000 characters.');
    }
    if ($entityType !== null && !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $entityType)) {
        throw new InvalidArgumentException('Notification entity types must use lowercase snake case.');
    }
    if ($entityId !== null && ($entityId < 1 || $entityType === null)) {
        throw new InvalidArgumentException('A notification entity ID requires a valid entity type.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_notifications
            (recipient_user_id, created_by_user_id, notification_type,
             title, message, entity_type, entity_id)
         VALUES
            (:recipient_user_id, :created_by_user_id, :notification_type,
             :title, :message, :entity_type, :entity_id)'
    );
    $stmt->execute([
        ':recipient_user_id' => $recipientUserId,
        ':created_by_user_id'=> $createdByUserId,
        ':notification_type' => $type,
        ':title'             => $title,
        ':message'           => $message,
        ':entity_type'       => $entityType,
        ':entity_id'         => $entityId,
    ]);

    return (int) $pdo->lastInsertId();
}

function create_notifications_for_users(
    PDO $pdo,
    array $recipientUserIds,
    int $createdByUserId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null
): array {
    $recipientUserIds = array_values(array_unique(array_map('intval', $recipientUserIds)));

    if ($recipientUserIds === [] || in_array(0, $recipientUserIds, true)) {
        throw new InvalidArgumentException('At least one valid notification recipient is required.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    $notificationIds = [];

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($recipientUserIds as $recipientUserId) {
            $notificationIds[] = create_notification(
                $pdo,
                $recipientUserId,
                $createdByUserId,
                $type,
                $title,
                $message,
                $entityType,
                $entityId
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $notificationIds;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

// ===========================================================================
// Notification centre (read side): powers the shared bell + dropdown panel on
// every protected page. All reads are owner-scoped by recipient_user_id.
// ===========================================================================

/** Unread notification count for a user. */
function notification_unread_count(PDO $pdo, int $userId): int
{
    if ($userId < 1) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id = :uid AND read_at IS NULL'
    );
    $stmt->execute([':uid' => $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Owner-scoped, cursor-paginated notification list (newest first). Pass the
 * smallest id already loaded as $beforeId to fetch the next page. Fetches one
 * extra row to report whether more remain.
 */
function notification_list_for_user(PDO $pdo, int $userId, int $beforeId = 0, int $limit = 12): array
{
    if ($userId < 1) {
        return ['items' => [], 'has_more' => false];
    }
    $limit = max(1, min($limit, 50));

    $where = 'recipient_user_id = :uid';
    $params = [':uid' => $userId];
    if ($beforeId > 0) {
        $where .= ' AND id < :before';
        $params[':before'] = $beforeId;
    }

    $stmt = $pdo->prepare(
        'SELECT id, notification_type, title, message, entity_type, entity_id, read_at, created_at
         FROM tbl_notifications
         WHERE ' . $where . '
         ORDER BY id DESC
         LIMIT :limit'
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    return ['items' => $rows, 'has_more' => $hasMore];
}

/** Marks a single notification read (only if it belongs to the user). Returns true if a row changed. */
function notification_mark_read(PDO $pdo, int $userId, int $notificationId): bool
{
    if ($userId < 1 || $notificationId < 1) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE tbl_notifications SET read_at = NOW()
         WHERE id = :id AND recipient_user_id = :uid AND read_at IS NULL'
    );
    $stmt->execute([':id' => $notificationId, ':uid' => $userId]);

    return $stmt->rowCount() > 0;
}

/** Marks all of a user's unread notifications read. Returns the number changed. */
function notification_mark_all_read(PDO $pdo, int $userId): int
{
    if ($userId < 1) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'UPDATE tbl_notifications SET read_at = NOW()
         WHERE recipient_user_id = :uid AND read_at IS NULL'
    );
    $stmt->execute([':uid' => $userId]);

    return $stmt->rowCount();
}

/** Marks a single notification unread (only if it belongs to the user). Returns true if a row changed. */
function notification_mark_unread(PDO $pdo, int $userId, int $notificationId): bool
{
    if ($userId < 1 || $notificationId < 1) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE tbl_notifications SET read_at = NULL
         WHERE id = :id AND recipient_user_id = :uid AND read_at IS NOT NULL'
    );
    $stmt->execute([':id' => $notificationId, ':uid' => $userId]);

    return $stmt->rowCount() > 0;
}

/** Deletes a single notification (only if it belongs to the user). Returns true if a row was removed. */
function notification_delete(PDO $pdo, int $userId, int $notificationId): bool
{
    if ($userId < 1 || $notificationId < 1) {
        return false;
    }
    $stmt = $pdo->prepare(
        'DELETE FROM tbl_notifications WHERE id = :id AND recipient_user_id = :uid'
    );
    $stmt->execute([':id' => $notificationId, ':uid' => $userId]);

    return $stmt->rowCount() > 0;
}

/**
 * Maps a notification's entity to a destination relative to the viewer's
 * dashboard directory (pages/{role}/). Returns null when there is no sensible
 * per-role destination (the notification is then read-only on click).
 */
function notification_route(string $role, ?string $entityType, ?int $entityId): ?string
{
    if ($entityType === null || $entityId === null || $entityId < 1) {
        return null;
    }
    $isCenro = in_array($role, ['rps', 'superadmin'], true);

    switch ($entityType) {
        case 'permit_application':
            if ($role === 'ems') {
                return 'donation-receipt.php?application_id=' . $entityId;
            }
            if ($role === 'community' || $isCenro) {
                return 'permit-application.php?id=' . $entityId;
            }
            return null;
        case 'seedling_request':
            if ($role === 'ems') {
                return 'seedling-request-detail.php?id=' . $entityId;
            }
            if ($role === 'community') {
                return 'seedling-requests.php';
            }
            return null;
        case 'illegal_logging_report':
            if ($isCenro) {
                return 'illegal-logging-report-detail.php?id=' . $entityId;
            }
            if ($role === 'community') {
                return 'illegal-logging-reports.php';
            }
            return null;
        case 'advisory':
            return 'advisories.php';
        case 'user':
            return $role === 'community' ? 'profile.php' : null;
        default:
            return null;
    }
}

/** A Bootstrap icon name for a notification type. */
function notification_icon(string $type): string
{
    return match ($type) {
        'permit_status' => 'bi-file-earmark-check',
        'donation_verification' => 'bi-tree',
        'account_status' => 'bi-person-badge',
        'system_announcement' => 'bi-megaphone',
        'seedling_request' => 'bi-flower1',
        'illegal_logging_report' => 'bi-shield-exclamation',
        default => 'bi-bell',
    };
}

/** A palette accent class for a notification type's icon chip. */
function notification_accent(string $type): string
{
    return match ($type) {
        'permit_status' => 'accent-fern',
        'donation_verification' => 'accent-fern',
        'account_status' => 'accent-teal',
        'system_announcement' => 'accent-amber',
        'seedling_request' => 'accent-teal',
        'illegal_logging_report' => 'accent-rust',
        default => 'accent-fern',
    };
}

/** Facebook-style compact relative time ("just now", "5m", "3h", "2d", or a date). */
function notification_relative_time(string $timestamp): string
{
    $then = strtotime($timestamp);
    if ($then === false) {
        return '';
    }
    $diff = time() - $then;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . 'd';
    }
    if (date('Y', $then) === date('Y')) {
        return date('M j', $then);
    }

    return date('M j, Y', $then);
}
