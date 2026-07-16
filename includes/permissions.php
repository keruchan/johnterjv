<?php
/**
 * Reusable database-backed action permissions for exceptions that are narrower
 * than a user's primary role.
 */

function certreefy_permission_original_document_verification(): string
{
    return 'permit_original_document_verification';
}

function certreefy_permission_site_inspection(): string
{
    return 'permit_site_inspection';
}

function certreefy_permission_permit_decision(): string
{
    return 'permit_decision';
}

function certreefy_supported_permissions(): array
{
    return [
        certreefy_permission_original_document_verification(),
        certreefy_permission_site_inspection(),
        certreefy_permission_permit_decision(),
    ];
}

function user_active_permissions(PDO $pdo, int $userId, bool $forUpdate = false): array
{
    if ($userId < 1) {
        return [];
    }

    $sql =
        'SELECT p.permission_key
         FROM tbl_user_permissions p
         INNER JOIN tbl_users u ON u.id = p.user_id
         WHERE p.user_id = :user_id
           AND p.is_active = 1
           AND p.revoked_at IS NULL
           AND u.status = \'active\'';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $supported = certreefy_supported_permissions();

    return array_values(array_unique(array_filter(
        array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        static fn (string $permission): bool => in_array($permission, $supported, true)
    )));
}

function user_has_active_permission(
    PDO $pdo,
    int $userId,
    string $permission,
    bool $forUpdate = false
): bool {
    return in_array($permission, user_active_permissions($pdo, $userId, $forUpdate), true);
}
