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
