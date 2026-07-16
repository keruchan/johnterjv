<?php
/**
 * Seedling donation policy, requirement reads, and EMS registry services.
 * Receipt mutations live in permit_donation_receipts.php.
 */

require_once __DIR__ . '/permit.php';
require_once __DIR__ . '/permissions.php';

function permit_donation_policy_catalog(): array
{
    return [
        'public_domain' => [
            'code' => 'property_public_domain',
            'version' => (string) PERMIT_DONATION_POLICY_VERSION,
            'count' => (int) PERMIT_PUBLIC_DOMAIN_DONATION_COUNT,
            'label' => 'Public-domain or public-property seedling donation policy',
            'instructions' => (string) PERMIT_DONATION_INSTRUCTIONS,
        ],
        'private_property' => [
            'code' => 'property_private_property',
            'version' => (string) PERMIT_DONATION_POLICY_VERSION,
            'count' => (int) PERMIT_PRIVATE_PROPERTY_DONATION_COUNT,
            'label' => 'Private or privately owned property seedling donation policy',
            'instructions' => (string) PERMIT_DONATION_INSTRUCTIONS,
        ],
    ];
}

function permit_donation_policy_for_classification(string $propertyClassification): array
{
    $policy = permit_donation_policy_catalog();
    if (!isset($policy[$propertyClassification]) || (int) $policy[$propertyClassification]['count'] < 1) {
        throw new RuntimeException('No valid server-side donation policy is configured for this property classification.');
    }

    return $policy[$propertyClassification];
}

function permit_donation_policy_basis(array $policy): string
{
    return (string) $policy['label'] . ' version ' . (string) $policy['version']
        . ' applied at approval (' . (int) $policy['count'] . ' seedlings).';
}

function permit_donation_actor(PDO $pdo, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, role, status
         FROM tbl_users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' ? $actor : null;
}

function permit_donation_application_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId
): ?array {
    $actor = permit_donation_actor($pdo, $actorUserId);
    if ($actor === null) {
        return null;
    }
    $application = permit_load_application($pdo, $applicationId);
    if ($application === null || $application['transaction_id'] === null) {
        return null;
    }
    $role = (string) $actor['role'];
    if ($role === 'community') {
        return (int) $application['applicant_user_id'] === $actorUserId ? $application : null;
    }
    if ($role === 'rps') {
        return $application;
    }
    if ($role === 'superadmin') {
        return user_has_active_permission(
            $pdo,
            $actorUserId,
            certreefy_permission_permit_decision()
        ) ? $application : null;
    }
    if ($role === 'ems') {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM tbl_permit_donation_requirements
             WHERE application_id = :application_id
             LIMIT 1'
        );
        $stmt->execute([':application_id' => $applicationId]);

        return $stmt->fetchColumn() !== false ? $application : null;
    }

    return null;
}

function permit_donation_requirement_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    if (permit_donation_application_for_actor($pdo, $applicationId, $actorUserId) === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT r.*, a.transaction_id, a.applicant_name,
                a.application_status, a.decision_status, a.donation_status,
                CONCAT(u.fname, \' \', u.lname) AS imposed_by_name,
                d.decision_notes, d.decision_conditions, d.decided_at,
                GREATEST(
                    CAST(r.required_seedling_count AS SIGNED)
                        - CAST(r.received_seedling_count AS SIGNED),
                    0
                ) AS remaining_seedling_count
         FROM tbl_permit_donation_requirements r
         INNER JOIN tbl_permit_applications a ON a.id = r.application_id
         INNER JOIN tbl_users u ON u.id = r.imposed_by_user_id
         LEFT JOIN tbl_permit_decisions d
                ON d.application_id = r.application_id
               AND d.id = r.approval_decision_id
               AND d.decision = \'approved\'
         WHERE r.application_id = :application_id
         LIMIT 1'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $requirement = $stmt->fetch();

    return $requirement ?: null;
}

function permit_list_donation_requirements_for_ems(
    PDO $pdo,
    int $actorUserId,
    array|string $filters = []
): array {
    $actor = permit_donation_actor($pdo, $actorUserId);
    if ($actor === null || (string) $actor['role'] !== 'ems') {
        return [];
    }
    if (is_string($filters)) {
        $filters = ['transaction' => $filters];
    }
    $transactionFilter = trim((string) ($filters['transaction'] ?? ''));
    if (strlen($transactionFilter) > 50) {
        $transactionFilter = substr($transactionFilter, 0, 50);
    }
    $applicantFilter = trim((string) ($filters['applicant'] ?? ''));
    if (strlen($applicantFilter) > 150) {
        $applicantFilter = substr($applicantFilter, 0, 150);
    }
    $applicationReference = trim((string) ($filters['application_reference'] ?? ''));
    if ($applicationReference !== '' && !ctype_digit($applicationReference)) {
        $applicationReference = '';
    }
    $donationStatus = trim((string) ($filters['donation_status'] ?? ''));
    if ($donationStatus !== ''
        && !in_array($donationStatus, permit_workflow_statuses()['donation'], true)) {
        $donationStatus = '';
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }
    $sql =
        'SELECT r.*, a.transaction_id, a.applicant_name,
                a.application_status, a.decision_status, a.donation_status,
                CONCAT(u.fname, \' \', u.lname) AS imposed_by_name,
                GREATEST(
                    CAST(r.required_seedling_count AS SIGNED)
                        - CAST(r.received_seedling_count AS SIGNED),
                    0
                ) AS remaining_seedling_count
         FROM tbl_permit_donation_requirements r
         INNER JOIN tbl_permit_applications a ON a.id = r.application_id
         INNER JOIN tbl_users u ON u.id = r.imposed_by_user_id
         WHERE a.decision_status = \'approved\'
           AND a.application_status NOT IN (\'declined\', \'closed\')';
    $params = [];
    if ($transactionFilter !== '') {
        $sql .= ' AND a.transaction_id LIKE :transaction_filter';
        $params[':transaction_filter'] = '%' . $transactionFilter . '%';
    }
    if ($applicantFilter !== '') {
        $sql .= ' AND a.applicant_name LIKE :applicant_filter';
        $params[':applicant_filter'] = '%' . $applicantFilter . '%';
    }
    if ($applicationReference !== '') {
        $sql .= ' AND a.id = :application_reference';
        $params[':application_reference'] = (int) $applicationReference;
    }
    if ($donationStatus !== '') {
        $sql .= ' AND r.current_status = :donation_status';
        $params[':donation_status'] = $donationStatus;
    }
    if ($dateFrom !== '') {
        $sql .= ' AND DATE(r.imposed_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sql .= ' AND DATE(r.imposed_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    $sql .= ' ORDER BY r.imposed_at DESC, r.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function create_permit_donation_requirement(
    PDO $pdo,
    array $application,
    int $approvalDecisionId,
    int $actorUserId,
    array $policy
): int {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Donation requirement creation must be part of the approval transaction.');
    }
    if ((string) ($application['decision_status'] ?? '') !== 'approved'
        || (string) ($application['application_status'] ?? '') !== 'approved') {
        throw new RuntimeException('A donation requirement may be created only for an approved application.');
    }
    $classification = (string) ($application['property_classification'] ?? '');
    $configuredPolicy = permit_donation_policy_for_classification($classification);
    foreach (['code', 'version', 'count', 'label', 'instructions'] as $field) {
        if (!array_key_exists($field, $policy)
            || (string) $policy[$field] !== (string) $configuredPolicy[$field]) {
            throw new RuntimeException('The applied donation policy changed before approval completed.');
        }
    }
    $decisionStmt = $pdo->prepare(
        'SELECT id, property_classification, donation_seedling_count
         FROM tbl_permit_decisions
         WHERE id = :decision_id
           AND application_id = :application_id
           AND decided_by_user_id = :actor_user_id
           AND decision = \'approved\'
         LIMIT 1
         FOR UPDATE'
    );
    $decisionStmt->execute([
        ':decision_id' => $approvalDecisionId,
        ':application_id' => (int) $application['id'],
        ':actor_user_id' => $actorUserId,
    ]);
    $decision = $decisionStmt->fetch();
    if (!$decision
        || (string) $decision['property_classification'] !== $classification
        || (int) $decision['donation_seedling_count'] !== (int) $policy['count']) {
        throw new RuntimeException('The approval decision does not match the donation requirement policy.');
    }
    $existingStmt = $pdo->prepare(
        'SELECT id
         FROM tbl_permit_donation_requirements
         WHERE application_id = :application_id
         LIMIT 1
         FOR UPDATE'
    );
    $existingStmt->execute([':application_id' => (int) $application['id']]);
    if ($existingStmt->fetchColumn() !== false) {
        throw new RuntimeException('This approval already has a seedling donation requirement.');
    }

    $basis = permit_donation_policy_basis($policy);
    $insert = $pdo->prepare(
        'INSERT INTO tbl_permit_donation_requirements
            (application_id, approval_decision_id, property_classification,
             policy_code, policy_version, required_seedling_count,
             received_seedling_count, requirement_basis, applicant_instructions,
             imposed_by_user_id, current_status)
         VALUES
            (:application_id, :approval_decision_id, :property_classification,
             :policy_code, :policy_version, :required_seedling_count,
             0, :requirement_basis, :applicant_instructions,
             :imposed_by_user_id, \'required\')'
    );
    $insert->execute([
        ':application_id' => (int) $application['id'],
        ':approval_decision_id' => $approvalDecisionId,
        ':property_classification' => $classification,
        ':policy_code' => (string) $policy['code'],
        ':policy_version' => (string) $policy['version'],
        ':required_seedling_count' => (int) $policy['count'],
        ':requirement_basis' => $basis,
        ':applicant_instructions' => (string) $policy['instructions'],
        ':imposed_by_user_id' => $actorUserId,
    ]);

    return (int) $pdo->lastInsertId();
}
