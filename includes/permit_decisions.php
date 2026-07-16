<?php
/**
 * Transactional RPS review and decision services. Decisions remain distinct
 * from permit preparation and final release.
 */

require_once __DIR__ . '/permit_inspections.php';
require_once __DIR__ . '/permit_donations.php';

class PermitDecisionValidationException extends InvalidArgumentException
{
    private array $validationErrors;

    public function __construct(array|string $errors)
    {
        $this->validationErrors = array_values((array) $errors);
        parent::__construct(implode(' ', $this->validationErrors));
    }

    public function errors(): array
    {
        return $this->validationErrors;
    }
}

function permit_decision_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $actor = permit_document_load_actor($pdo, $actorUserId, $forUpdate);
    if ($actor === null) {
        return null;
    }
    if ((string) $actor['role'] === 'rps') {
        return $actor;
    }
    if ((string) $actor['role'] === 'superadmin'
        && user_has_active_permission(
            $pdo,
            $actorUserId,
            certreefy_permission_permit_decision(),
            $forUpdate
        )) {
        return $actor;
    }

    return null;
}

function permit_decision_event_label(string $decision): string
{
    return match ($decision) {
        'review_started' => 'Review started',
        'review_resumed' => 'Review resumed',
        'returned_for_correction' => 'Returned for correction',
        'additional_requirements_requested' => 'Additional requirements requested',
        'approved' => 'Approved',
        'declined' => 'Declined',
        default => permit_status_label($decision),
    };
}

function permit_decision_event_badge(string $decision): string
{
    return match ($decision) {
        'approved' => 'text-bg-success',
        'declined' => 'text-bg-danger',
        'returned_for_correction', 'additional_requirements_requested' => 'text-bg-warning',
        'review_started', 'review_resumed' => 'text-bg-primary',
        default => 'text-bg-secondary',
    };
}

function permit_decision_lock_reason(array $application): ?string
{
    if ($application['transaction_id'] === null || (string) $application['application_status'] === 'draft') {
        return 'Unsubmitted applications cannot enter RPS review.';
    }
    if (in_array((string) $application['decision_status'], ['approved', 'declined'], true)
        || in_array((string) $application['application_status'], [
            'approved', 'declined', 'awaiting_donation', 'awaiting_final_verification',
            'ready_for_release', 'released', 'completed', 'closed',
        ], true)
        || (string) $application['release_status'] === 'released'
        || in_array((string) $application['validity_status'], ['completed', 'expired', 'closed'], true)) {
        return 'This application already has a terminal decision or later processing state.';
    }

    return null;
}

function permit_decision_application_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $operation = 'view',
    bool $forUpdate = false
): ?array {
    $actor = permit_document_load_actor($pdo, $actorUserId, $forUpdate);
    if ($actor === null) {
        return null;
    }
    $application = permit_load_application($pdo, $applicationId, $forUpdate);
    if ($application === null) {
        return null;
    }
    if ($operation === 'manage') {
        return permit_decision_actor($pdo, $actorUserId, $forUpdate) !== null
            && permit_decision_lock_reason($application) === null
            ? $application
            : null;
    }
    if ($operation !== 'view') {
        return null;
    }
    if ((string) $actor['role'] === 'community') {
        return (int) $application['applicant_user_id'] === $actorUserId ? $application : null;
    }
    if (permit_decision_actor($pdo, $actorUserId, $forUpdate) !== null) {
        return $application['transaction_id'] !== null
            && (string) $application['application_status'] !== 'draft'
            ? $application
            : null;
    }

    return null;
}

function permit_latest_decision_event(PDO $pdo, int $applicationId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT d.*
         FROM tbl_permit_decisions d
         WHERE d.application_id = :application_id
         ORDER BY d.id DESC
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':application_id' => $applicationId]);
    $decision = $stmt->fetch();

    return $decision ?: null;
}

function permit_decision_events_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    if (permit_decision_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT d.*, CONCAT(u.fname, \' \', u.lname) AS decision_maker_name
         FROM tbl_permit_decisions d
         INNER JOIN tbl_users u ON u.id = d.decided_by_user_id
         WHERE d.application_id = :application_id
         ORDER BY d.id DESC'
    );
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

function permit_status_history_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    if (permit_decision_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT h.*, CONCAT(u.fname, \' \', u.lname) AS actor_name
         FROM tbl_permit_status_history h
         INNER JOIN tbl_users u ON u.id = h.changed_by_user_id
         WHERE h.application_id = :application_id
         ORDER BY h.id DESC'
    );
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

function permit_decision_readiness(
    PDO $pdo,
    int $applicationId,
    bool $forUpdate = false,
    ?array $application = null
): array {
    $application = $application ?? permit_load_application($pdo, $applicationId, $forUpdate);
    if ($application === null) {
        throw new RuntimeException('The permit application does not exist.');
    }

    $requiredFields = [
        'transaction_id', 'applicant_name', 'applicant_contact', 'applicant_address',
        'applicant_type', 'property_relationship', 'property_classification',
        'property_owner_name', 'property_address', 'district', 'barangay',
        'municipality', 'province', 'cutting_purpose',
    ];
    $applicationComplete = $application['submitted_at'] !== null
        && $application['declaration_confirmed_at'] !== null;
    foreach ($requiredFields as $field) {
        $applicationComplete = $applicationComplete
            && trim((string) ($application[$field] ?? '')) !== '';
    }
    $applicationComplete = $applicationComplete
        && isset(permit_donation_policy_catalog()[(string) ($application['property_classification'] ?? '')]);
    if ((string) ($application['applicant_type'] ?? '') === 'organization') {
        $applicationComplete = $applicationComplete
            && trim((string) ($application['organization_name'] ?? '')) !== '';
    }
    if ((string) ($application['property_relationship'] ?? '') === 'authorized_representative') {
        $applicationComplete = $applicationComplete
            && trim((string) ($application['authorization_details'] ?? '')) !== '';
    }

    $treeSql =
        'SELECT id, quantity, diameter_cm, estimated_height_m
         FROM tbl_permit_trees
         WHERE application_id = :application_id
         ORDER BY id';
    if ($forUpdate) {
        $treeSql .= ' FOR UPDATE';
    }
    $treeStmt = $pdo->prepare($treeSql);
    $treeStmt->execute([':application_id' => $applicationId]);
    $trees = $treeStmt->fetchAll();
    $treeTotal = array_sum(array_map(static fn (array $tree): int => (int) $tree['quantity'], $trees));
    $treesComplete = $trees !== [] && $treeTotal > 0;

    $documentSql =
        'SELECT id, document_type, is_current, verification_status
         FROM tbl_permit_documents
         WHERE application_id = :application_id AND is_current = 1
         ORDER BY document_type, id DESC';
    if ($forUpdate) {
        $documentSql .= ' FOR UPDATE';
    }
    $documentStmt = $pdo->prepare($documentSql);
    $documentStmt->execute([':application_id' => $applicationId]);
    $currentDocuments = permit_current_documents_by_type($documentStmt->fetchAll());
    $documentsComplete = (string) $application['document_status'] === 'verified';
    foreach (permit_document_type_catalog() as $documentType => $definition) {
        if (empty($definition['required'])) {
            continue;
        }
        $document = $currentDocuments[$documentType] ?? null;
        $review = permit_latest_original_review($pdo, $applicationId, $documentType, $forUpdate);
        $documentsComplete = $documentsComplete
            && $document !== null
            && (string) $document['verification_status'] === 'accepted'
            && permit_original_review_matches_document($review, $document)
            && (string) ($review['review_status'] ?? '') === 'verified'
            && (int) ($review['original_received'] ?? 0) === 1
            && !empty($review['original_received_on'])
            && !empty($review['received_by_user_id'])
            && (int) ($review['scan_compared_with_original'] ?? 0) === 1
            && ((int) ($review['wet_ink_required'] ?? 0) === 0
                || (int) ($review['wet_ink_verified'] ?? 0) === 1);
    }
    $inspectionComplete = false;
    $inspectionDetail = 'Inspection has not been completed.';
    $approvedTreeLimit = $treeTotal;
    if ((string) $application['inspection_status'] === 'not_required') {
        $inspectionComplete = true;
        $inspectionDetail = 'Inspection was assessed as not required.';
    } elseif ((string) $application['inspection_status'] === 'passed') {
        $inspection = permit_latest_inspection($pdo, $applicationId, $forUpdate);
        if ($inspection !== null && (string) $inspection['inspection_status'] === 'passed') {
            $verificationStmt = $pdo->prepare(
                'SELECT tv.tree_id, tv.species_confirmed, tv.quantity_confirmed,
                        tv.measurements_confirmed, tv.verified_common_name,
                        tv.verified_quantity, tv.verified_diameter_cm,
                        tv.verified_height_m,
                        t.diameter_cm, t.estimated_height_m
                 FROM tbl_permit_inspection_tree_verifications tv
                 INNER JOIN tbl_permit_trees t
                         ON t.id = tv.tree_id AND t.application_id = tv.application_id
                 WHERE tv.application_id = :application_id
                   AND tv.inspection_id = :inspection_id
                 ORDER BY tv.tree_id'
            );
            $verificationStmt->execute([
                ':application_id' => $applicationId,
                ':inspection_id' => (int) $inspection['id'],
            ]);
            $verifications = $verificationStmt->fetchAll();
            $treeVerificationComplete = count($verifications) === count($trees);
            $verifiedTreeTotal = 0;
            foreach ($verifications as $verification) {
                $measurementRequired = $verification['diameter_cm'] !== null
                    || $verification['estimated_height_m'] !== null;
                $treeVerificationComplete = $treeVerificationComplete
                    && (int) $verification['species_confirmed'] === 1
                    && (int) $verification['quantity_confirmed'] === 1
                    && trim((string) $verification['verified_common_name']) !== ''
                    && (int) $verification['verified_quantity'] > 0
                    && (!$measurementRequired || (int) $verification['measurements_confirmed'] === 1)
                    && ($verification['diameter_cm'] === null
                        || ($verification['verified_diameter_cm'] !== null
                            && (float) $verification['verified_diameter_cm'] > 0))
                    && ($verification['estimated_height_m'] === null
                        || ($verification['verified_height_m'] !== null
                            && (float) $verification['verified_height_m'] > 0));
                $verifiedTreeTotal += (int) $verification['verified_quantity'];
            }
            $inspectionComplete = $treeVerificationComplete
                && (int) $inspection['property_location_confirmed'] === 1
                && (int) $inspection['ownership_authorization_confirmed'] === 1
                && $inspection['completed_by_user_id'] !== null
                && $inspection['inspected_at'] !== null
                && trim((string) ($inspection['findings'] ?? '')) !== ''
                && trim((string) ($inspection['recommendation'] ?? '')) !== '';
            if ($inspectionComplete) {
                $inspectionDetail = 'Passed inspection findings and every tree verification are complete.';
                $approvedTreeLimit = $verifiedTreeTotal;
            } else {
                $inspectionDetail = 'The passed inspection record is incomplete or inconsistent.';
            }
        }
    }

    $reviewActive = (string) $application['decision_status'] === 'under_review';
    $noBlockingRequirement = !in_array(
        (string) $application['application_status'],
        ['awaiting_documents', 'awaiting_inspection'],
        true
    ) && (string) $application['decision_status'] !== 'returned';
    $reviewStateAllowed = in_array(
        (string) $application['application_status'],
        ['under_review', 'awaiting_decision'],
        true
    );

    $checks = [
        'application' => [
            'label' => 'Application information and declaration are complete',
            'passed' => $applicationComplete,
        ],
        'trees' => [
            'label' => 'At least one valid tree record is available',
            'passed' => $treesComplete,
        ],
        'documents' => [
            'label' => 'Mandatory scans, originals, and wet-ink requirements are verified',
            'passed' => $documentsComplete,
        ],
        'inspection' => [
            'label' => 'Site inspection is passed or formally not required',
            'passed' => $inspectionComplete,
            'detail' => $inspectionDetail,
        ],
        'review' => [
            'label' => 'RPS review is active',
            'passed' => $reviewActive,
        ],
        'blocking' => [
            'label' => 'No returned or unresolved blocking requirement remains',
            'passed' => $noBlockingRequirement && $reviewStateAllowed,
        ],
    ];
    $ready = !in_array(false, array_map(
        static fn (array $check): bool => (bool) $check['passed'],
        $checks
    ), true);

    return [
        'ready' => $ready,
        'checks' => $checks,
        'approved_tree_limit' => $approvedTreeLimit,
        'application_tree_total' => $treeTotal,
    ];
}

function permit_review_queue_catalog(): array
{
    return [
        'all' => 'All submitted applications',
        'newly_submitted' => 'Newly submitted',
        'under_review' => 'Under review',
        'originals_pending' => 'Original documents pending',
        'requirements_requested' => 'Additional requirements requested',
        'inspection_pending' => 'Site inspections pending',
        'ready_for_decision' => 'Ready for decision',
        'approved_awaiting_donation' => 'Approved - awaiting donation',
        'declined' => 'Declined',
    ];
}

function permit_review_queue_key(array $application): string
{
    if ((string) $application['decision_status'] === 'approved'
        || in_array((string) $application['application_status'], ['approved', 'awaiting_donation'], true)) {
        return 'approved_awaiting_donation';
    }
    if ((string) $application['decision_status'] === 'declined'
        || (string) $application['application_status'] === 'declined') {
        return 'declined';
    }
    if ((string) $application['decision_status'] === 'pending'
        && (string) $application['application_status'] === 'submitted') {
        return 'newly_submitted';
    }
    if ((string) $application['decision_status'] === 'returned'
        || (string) $application['application_status'] === 'awaiting_documents') {
        return 'requirements_requested';
    }
    if (array_key_exists('documents_ready', $application)
        ? !$application['documents_ready']
        : (string) $application['document_status'] !== 'verified') {
        return 'originals_pending';
    }
    if (array_key_exists('inspection_ready', $application)
        ? !$application['inspection_ready']
        : !in_array((string) $application['inspection_status'], ['passed', 'not_required'], true)) {
        return 'inspection_pending';
    }
    if (!empty($application['decision_ready'])) {
        return 'ready_for_decision';
    }

    return 'under_review';
}

function permit_list_applications_for_review(
    PDO $pdo,
    int $actorUserId,
    string $queue = 'all'
): array {
    if (permit_decision_actor($pdo, $actorUserId) === null) {
        return ['applications' => [], 'counts' => []];
    }
    $catalog = permit_review_queue_catalog();
    if (!isset($catalog[$queue])) {
        $queue = 'all';
    }
    $stmt = $pdo->query(
        'SELECT a.id, a.transaction_id, a.applicant_name, a.property_address,
                a.municipality, a.province, a.application_status,
                a.document_status, a.inspection_status, a.decision_status,
                a.donation_status, a.submitted_at,
                (SELECT COUNT(*) FROM tbl_permit_documents d WHERE d.application_id = a.id AND d.is_current = 1) AS current_document_count,
                (SELECT COUNT(*) FROM tbl_permit_documents d WHERE d.application_id = a.id AND d.is_current = 1 AND d.verification_status = \'pending\') AS pending_document_count,
                (SELECT COUNT(*) FROM tbl_permit_inspections i WHERE i.application_id = a.id) AS inspection_event_count,
                (SELECT d.decision FROM tbl_permit_decisions d WHERE d.application_id = a.id ORDER BY d.id DESC LIMIT 1) AS latest_decision_event,
                COALESCE((SELECT SUM(t.quantity) FROM tbl_permit_trees t WHERE t.application_id = a.id), 0) AS total_tree_quantity
         FROM tbl_permit_applications a
         WHERE a.transaction_id IS NOT NULL AND a.application_status <> \'draft\'
         ORDER BY a.submitted_at DESC, a.id DESC'
    );
    $all = $stmt->fetchAll();
    $counts = array_fill_keys(array_keys($catalog), 0);
    $counts['all'] = count($all);
    foreach ($all as &$application) {
        $readiness = permit_decision_readiness($pdo, (int) $application['id'], false);
        $application['decision_ready'] = $readiness['ready'];
        $application['documents_ready'] = (bool) $readiness['checks']['documents']['passed'];
        $application['inspection_ready'] = (bool) $readiness['checks']['inspection']['passed'];
        $application['queue_key'] = permit_review_queue_key($application);
        $counts[$application['queue_key']]++;
    }
    unset($application);
    $filtered = $queue === 'all'
        ? $all
        : array_values(array_filter(
            $all,
            static fn (array $application): bool => (string) $application['queue_key'] === $queue
        ));

    return ['applications' => $filtered, 'counts' => $counts, 'queue' => $queue];
}

function permit_decision_transition_summary(
    PDO $pdo,
    array &$application,
    int $actorUserId,
    string $domain,
    string $newStatus,
    ?string $remarks
): void {
    $columns = permit_status_columns();
    $column = $columns[$domain] ?? null;
    if ($column === null) {
        throw new RuntimeException('The review workflow status domain is invalid.');
    }
    $previousStatus = (string) $application[$column];
    if ($previousStatus === $newStatus) {
        return;
    }
    if (!permit_status_transition_is_allowed($domain, $previousStatus, $newStatus)) {
        throw new RuntimeException(
            'The ' . permit_status_label($domain) . ' status cannot change from '
                . permit_status_label($previousStatus) . ' to ' . permit_status_label($newStatus) . '.'
        );
    }
    $stmt = $pdo->prepare(
        'UPDATE tbl_permit_applications
         SET ' . $column . ' = :new_status
         WHERE id = :application_id AND ' . $column . ' = :previous_status'
    );
    $stmt->execute([
        ':new_status' => $newStatus,
        ':application_id' => (int) $application['id'],
        ':previous_status' => $previousStatus,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The permit status changed before the review action completed.');
    }
    $historyRemarks = $remarks;
    if ($historyRemarks !== null && strlen($historyRemarks) > 500) {
        $historyRemarks = substr($historyRemarks, 0, 497) . '...';
    }
    permit_record_status_history(
        $pdo,
        (int) $application['id'],
        $actorUserId,
        $domain,
        $previousStatus,
        $newStatus,
        $historyRemarks
    );
    $application[$column] = $newStatus;
}

function permit_insert_decision_event(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $decision,
    ?array $previous,
    ?string $notes,
    ?string $conditions = null,
    ?int $approvedTreeCount = null,
    ?string $propertyClassification = null,
    ?int $donationSeedlingCount = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_decisions
            (application_id, previous_decision_id, decided_by_user_id, decision,
             decision_notes, decision_conditions, approved_tree_count,
             property_classification, donation_seedling_count)
         VALUES
            (:application_id, :previous_decision_id, :decided_by_user_id, :decision,
             :decision_notes, :decision_conditions, :approved_tree_count,
             :property_classification, :donation_seedling_count)'
    );
    $stmt->execute([
        ':application_id' => $applicationId,
        ':previous_decision_id' => $previous === null ? null : (int) $previous['id'],
        ':decided_by_user_id' => $actorUserId,
        ':decision' => $decision,
        ':decision_notes' => $notes,
        ':decision_conditions' => $conditions,
        ':approved_tree_count' => $approvedTreeCount,
        ':property_classification' => $propertyClassification,
        ':donation_seedling_count' => $donationSeedlingCount,
    ]);

    return (int) $pdo->lastInsertId();
}

function record_permit_review_action(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $action,
    array $input = []
): array {
    $action = trim($action);
    if (!in_array($action, [
        'begin_review', 'return_for_correction', 'request_requirements', 'approve', 'decline',
    ], true)) {
        throw new PermitDecisionValidationException('The review action is invalid.');
    }
    $notes = trim((string) ($input['decision_notes'] ?? ''));
    $conditions = trim((string) ($input['decision_conditions'] ?? ''));
    if (strlen($notes) > 1000) {
        throw new PermitDecisionValidationException('Decision remarks must not exceed 1,000 characters.');
    }
    if (strlen($conditions) > 2000) {
        throw new PermitDecisionValidationException('Approval conditions must not exceed 2,000 characters.');
    }
    if (in_array($action, ['return_for_correction', 'request_requirements', 'approve', 'decline'], true)
        && $notes === '') {
        throw new PermitDecisionValidationException('Decision remarks are required for this action.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $actor = permit_decision_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('The responsible user is not authorized to review or decide permit applications.');
        }
        $application = permit_decision_application_for_actor(
            $pdo,
            $applicationId,
            $actorUserId,
            'manage',
            true
        );
        if ($application === null) {
            throw new RuntimeException('The permit application is unavailable or locked for review decisions.');
        }
        $latest = permit_latest_decision_event($pdo, $applicationId, true);
        $expectedId = trim((string) ($input['expected_decision_id'] ?? ''));
        if ($expectedId !== ''
            && (!ctype_digit($expectedId)
                || (int) $expectedId !== ($latest === null ? 0 : (int) $latest['id']))) {
            throw new RuntimeException('The review changed before this action completed. Reload and try again.');
        }

        $currentDecisionStatus = (string) $application['decision_status'];
        $event = '';
        $approvedTreeCount = null;
        $donationRequirement = null;
        if ($action === 'begin_review') {
            if (!in_array($currentDecisionStatus, ['pending', 'returned'], true)) {
                throw new RuntimeException('Review can begin only for a pending or returned application.');
            }
            $event = $currentDecisionStatus === 'pending' ? 'review_started' : 'review_resumed';
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'decision',
                'under_review',
                $notes === '' ? null : $notes
            );
            if ((string) $application['application_status'] !== 'under_review') {
                permit_decision_transition_summary(
                    $pdo,
                    $application,
                    $actorUserId,
                    'application',
                    'under_review',
                    $notes === '' ? 'RPS review started.' : $notes
                );
            }
        } elseif (in_array($action, ['return_for_correction', 'request_requirements'], true)) {
            if ($currentDecisionStatus !== 'under_review') {
                throw new RuntimeException('Only an application under active review may be returned.');
            }
            $event = $action === 'return_for_correction'
                ? 'returned_for_correction'
                : 'additional_requirements_requested';
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'decision',
                'returned',
                $notes
            );
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'application',
                'awaiting_documents',
                $notes
            );
        } elseif ($action === 'approve') {
            if ($currentDecisionStatus !== 'under_review') {
                throw new RuntimeException('Only an application under active review may be approved.');
            }
            $readiness = permit_decision_readiness($pdo, $applicationId, true, $application);
            if (!$readiness['ready']) {
                $missing = array_values(array_map(
                    static fn (array $check): string => (string) $check['label'],
                    array_filter(
                        $readiness['checks'],
                        static fn (array $check): bool => !$check['passed']
                    )
                ));
                throw new RuntimeException(
                    'The application is not ready for approval: ' . implode('; ', $missing) . '.'
                );
            }
            $approvedValue = trim((string) ($input['approved_tree_count'] ?? ''));
            if (!ctype_digit($approvedValue)
                || (int) $approvedValue < 1
                || (int) $approvedValue > (int) $readiness['approved_tree_limit']) {
                throw new PermitDecisionValidationException(
                    'Approved tree count must be between 1 and '
                        . (int) $readiness['approved_tree_limit'] . '.'
                );
            }
            $approvedTreeCount = (int) $approvedValue;
            $donationRequirement = permit_donation_policy_for_classification(
                (string) $application['property_classification']
            );
            $event = 'approved';
        } elseif ($action === 'decline') {
            if ($currentDecisionStatus !== 'under_review') {
                throw new RuntimeException('Only an application under active review may be declined.');
            }
            $event = 'declined';
        }

        $decisionId = permit_insert_decision_event(
            $pdo,
            $applicationId,
            $actorUserId,
            $event,
            $latest,
            $notes === '' ? null : $notes,
            $conditions === '' ? null : $conditions,
            $approvedTreeCount,
            $action === 'approve' ? (string) $application['property_classification'] : null,
            $donationRequirement === null ? null : (int) $donationRequirement['count']
        );

        if ($action === 'approve') {
            if ((string) $application['application_status'] === 'under_review') {
                permit_decision_transition_summary(
                    $pdo,
                    $application,
                    $actorUserId,
                    'application',
                    'awaiting_decision',
                    'All configured review requirements were rechecked before approval.'
                );
            }
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'decision',
                'approved',
                $notes
            );
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'application',
                'approved',
                $notes
            );
            create_permit_donation_requirement(
                $pdo,
                $application,
                $decisionId,
                $actorUserId,
                $donationRequirement
            );
            $basis = permit_donation_policy_basis($donationRequirement);
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'donation',
                'required',
                $basis
            );
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'application',
                'awaiting_donation',
                'Approved application is awaiting the configured seedling donation requirement.'
            );
        } elseif ($action === 'decline') {
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'decision',
                'declined',
                $notes
            );
            permit_decision_transition_summary(
                $pdo,
                $application,
                $actorUserId,
                'application',
                'declined',
                $notes
            );
        }

        $auditAction = match ($action) {
            'begin_review' => 'permit_review_started',
            'return_for_correction' => 'permit_returned_for_correction',
            'request_requirements' => 'permit_additional_requirements_requested',
            'approve' => 'permit_approved',
            'decline' => 'permit_declined',
        };
        record_audit_event(
            $pdo,
            $actorUserId,
            in_array($action, ['approve', 'decline'], true) ? 'approval' : 'permit',
            $auditAction,
            'permit_decision',
            $decisionId,
            'Recorded an RPS permit review or decision action.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'event' => $event,
                'approved_tree_count' => $approvedTreeCount,
                'property_classification' => $action === 'approve'
                    ? (string) $application['property_classification']
                    : null,
                'donation_seedling_count' => $donationRequirement === null
                    ? null
                    : (int) $donationRequirement['count'],
                'donation_policy_code' => $donationRequirement === null
                    ? null
                    : (string) $donationRequirement['code'],
                'donation_policy_version' => $donationRequirement === null
                    ? null
                    : (string) $donationRequirement['version'],
            ]
        );
        $notificationMessage = match ($action) {
            'begin_review' => 'RPS began reviewing application ' . $application['transaction_id'] . '.',
            'return_for_correction' => 'Application ' . $application['transaction_id'] . ' was returned for correction. Remarks: ' . $notes,
            'request_requirements' => 'Additional requirements were requested for application ' . $application['transaction_id'] . '. Remarks: ' . $notes,
            'approve' => 'Application ' . $application['transaction_id'] . ' was approved for ' . $approvedTreeCount . ' tree(s). A seedling donation requirement of ' . (int) $donationRequirement['count'] . ' seedlings was created under policy ' . $donationRequirement['code'] . ' version ' . $donationRequirement['version'] . '. Coordinate with EMS using the transaction ID. This is not a permit release.',
            'decline' => 'Application ' . $application['transaction_id'] . ' was declined. Reason: ' . $notes,
        };
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'permit_status',
            $action === 'approve' ? 'Seedling donation requirement created' : 'Permit review updated',
            $notificationMessage,
            'permit_application',
            $applicationId
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'decision_id' => $decisionId,
            'event' => $event,
            'application_status' => (string) $application['application_status'],
            'decision_status' => (string) $application['decision_status'],
            'donation_status' => (string) $application['donation_status'],
            'donation_seedling_count' => $donationRequirement === null
                ? null
                : (int) $donationRequirement['count'],
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
