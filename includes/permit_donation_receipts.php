<?php
/**
 * Transactional EMS receipt and physical-verification services for permit
 * seedling donation requirements. Finalized receipts automatically restock
 * the seedling inventory (tbl_seedling_inventory / tbl_seedling_stock_movements)
 * so donated seedlings become available for the seedling-request program.
 */

require_once __DIR__ . '/permit_donations.php';
require_once __DIR__ . '/seedling.php';

class PermitDonationReceiptValidationException extends InvalidArgumentException
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

function permit_donation_scalar_value(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function permit_donation_receipt_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT id, fname, lname, role, status
         FROM tbl_users
         WHERE id = :id
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor
        && (string) $actor['role'] === 'ems'
        && (string) $actor['status'] === 'active'
        ? $actor
        : null;
}

function permit_donation_active_ems_users(PDO $pdo, int $actorUserId): array
{
    if (permit_donation_receipt_actor($pdo, $actorUserId) === null) {
        return [];
    }
    $stmt = $pdo->query(
        'SELECT id, CONCAT(fname, \' \', lname) AS display_name
         FROM tbl_users
         WHERE role = \'ems\' AND status = \'active\'
         ORDER BY fname, lname, id'
    );

    return $stmt->fetchAll();
}

function permit_donation_receipt_status_badge(string $status): string
{
    return match ($status) {
        'ems_verified' => 'text-bg-success',
        'partially_received' => 'text-bg-warning',
        'flagged' => 'text-bg-danger',
        'draft' => 'text-bg-secondary',
        default => 'text-bg-light border',
    };
}

function permit_donation_receipt_validate_action_key(string $actionKey): string
{
    $actionKey = trim($actionKey);
    if (!preg_match('/^[a-f0-9]{64}$/', $actionKey)) {
        throw new PermitDonationReceiptValidationException(
            'The receipt action key is invalid. Refresh the page and try again.'
        );
    }

    return $actionKey;
}

/**
 * Format-only validation (no database access). Each item row resolves to
 * either an existing inventory species (inventory_choice = its numeric id)
 * or a new one entered by hand (inventory_choice = 'other', other_name set).
 * permit_donation_resolve_receipt_items() performs the actual inventory
 * lookup/creation once inside the caller's transaction.
 */
function permit_donation_normalize_receipt_input(array $input): array
{
    $errors = [];
    $receivedOn = permit_donation_scalar_value($input['received_on'] ?? '');
    $remarks = permit_donation_scalar_value($input['verification_notes'] ?? '');
    $receiverValue = permit_donation_scalar_value($input['received_by_user_id'] ?? '');

    $receivedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $receivedOn);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$receivedDate
        || ($dateErrors !== false
            && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))
        || $receivedDate->format('Y-m-d') !== $receivedOn) {
        $errors[] = 'A valid date received is required.';
    } elseif ($receivedDate > new DateTimeImmutable('today')) {
        $errors[] = 'The date received cannot be in the future.';
    }
    if (!ctype_digit($receiverValue) || (int) $receiverValue < 1) {
        $errors[] = 'Select valid receiving personnel.';
    }
    if (strlen($remarks) > 1000) {
        $errors[] = 'Verification remarks must not exceed 1000 characters.';
    }

    $choices = $input['inventory_id'] ?? [];
    $otherNames = $input['other_species_name'] ?? [];
    $quantities = $input['quantity_received'] ?? [];
    if (!is_array($choices) || !is_array($quantities)
        || count($choices) !== count($quantities)
        || count($choices) < 1
        || count($choices) > 50) {
        $errors[] = 'Provide between 1 and 50 complete seedling item rows.';
        $choices = [];
        $quantities = [];
    }

    $items = [];
    $seenSpecies = [];
    $total = 0;
    foreach ($choices as $index => $choiceValue) {
        $quantityInput = $quantities[$index] ?? null;
        if (!is_scalar($choiceValue) || !is_scalar($quantityInput)) {
            $errors[] = 'Seedling item ' . ($index + 1) . ' contains invalid values.';
            continue;
        }
        $choice = permit_donation_scalar_value($choiceValue);
        $quantityValue = permit_donation_scalar_value($quantityInput);
        $rowNumber = $index + 1;

        $isOther = $choice === 'other';
        $otherName = $isOther ? permit_donation_scalar_value($otherNames[$index] ?? '') : '';
        if ($isOther) {
            if ($otherName === '' || strlen($otherName) > 150) {
                $errors[] = 'Seedling item ' . $rowNumber . ' needs a species name of 1-150 characters.';
            }
        } elseif (!ctype_digit($choice) || (int) $choice < 1) {
            $errors[] = 'Seedling item ' . $rowNumber . ' requires a selected species.';
        }
        if (!ctype_digit($quantityValue)
            || (int) $quantityValue < 1
            || (int) $quantityValue > 1000000) {
            $errors[] = 'Seedling item ' . $rowNumber . ' quantity must be a whole number greater than zero.';
        }

        $dedupeKey = $isOther ? 'other:' . strtolower($otherName) : 'id:' . $choice;
        if (($isOther ? $otherName !== '' : $choice !== '') && isset($seenSpecies[$dedupeKey])) {
            $errors[] = 'Combine duplicate seedling species into one item row.';
        }
        $seenSpecies[$dedupeKey] = true;

        $rowValid = $isOther ? $otherName !== '' : (ctype_digit($choice) && (int) $choice > 0);
        if ($rowValid && ctype_digit($quantityValue) && (int) $quantityValue > 0) {
            $quantity = (int) $quantityValue;
            $total += $quantity;
            $items[] = [
                'inventory_choice' => $isOther ? 'other' : $choice,
                'other_name' => $isOther ? $otherName : null,
                'quantity_received' => $quantity,
            ];
        }
    }
    if ($total < 1 || $total > 1000000) {
        $errors[] = 'The receipt total must be between 1 and 1,000,000 seedlings.';
    }

    if ($errors !== []) {
        throw new PermitDonationReceiptValidationException($errors);
    }

    return [
        'received_at' => $receivedDate->format('Y-m-d 00:00:00'),
        'received_on' => $receivedDate->format('Y-m-d'),
        'received_by_user_id' => (int) $receiverValue,
        'verification_notes' => $remarks === '' ? null : $remarks,
        'items' => $items,
        'seedlings_received' => $total,
    ];
}

/** Finds an active species by name, or creates one (auto-encoding it into the inventory). */
function permit_donation_find_or_create_species(PDO $pdo, int $actorUserId, string $name): array
{
    $find = $pdo->prepare('SELECT id, common_name FROM tbl_seedling_inventory WHERE common_name = :name LIMIT 1');
    $find->execute([':name' => $name]);
    $existing = $find->fetch();
    if ($existing) {
        return [(int) $existing['id'], (string) $existing['common_name']];
    }

    $insert = $pdo->prepare(
        'INSERT INTO tbl_seedling_inventory (common_name, available_quantity, low_stock_threshold, created_by_user_id)
         VALUES (:name, 0, 0, :actor)'
    );
    try {
        $insert->execute([':name' => $name, ':actor' => $actorUserId]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            $find->execute([':name' => $name]);
            $existing = $find->fetch();
            if ($existing) {
                return [(int) $existing['id'], (string) $existing['common_name']];
            }
        }
        throw $e;
    }
    $inventoryId = (int) $pdo->lastInsertId();
    record_audit_event(
        $pdo,
        $actorUserId,
        'seedling',
        'seedling_species_added',
        'seedling_inventory',
        $inventoryId,
        'Added a seedling species to the inventory (auto-encoded from a permit donation receipt).',
        ['common_name' => $name]
    );

    return [$inventoryId, $name];
}

/** Resolves each normalized item to a real inventory species, creating new ones as needed. */
function permit_donation_resolve_receipt_items(PDO $pdo, int $actorUserId, array $rawItems): array
{
    $resolved = [];
    $seenInventoryIds = [];
    foreach ($rawItems as $rawItem) {
        if ($rawItem['inventory_choice'] === 'other') {
            [$inventoryId, $speciesName] = permit_donation_find_or_create_species(
                $pdo,
                $actorUserId,
                (string) $rawItem['other_name']
            );
        } else {
            $inventoryId = (int) $rawItem['inventory_choice'];
            $stmt = $pdo->prepare(
                'SELECT id, common_name FROM tbl_seedling_inventory WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([':id' => $inventoryId]);
            $species = $stmt->fetch();
            if (!$species) {
                throw new PermitDonationReceiptValidationException(
                    'One of the selected seedling species is unavailable. Refresh the page and try again.'
                );
            }
            $speciesName = (string) $species['common_name'];
        }
        if (isset($seenInventoryIds[$inventoryId])) {
            throw new PermitDonationReceiptValidationException(
                'Combine duplicate seedling species into one item row.'
            );
        }
        $seenInventoryIds[$inventoryId] = true;
        $resolved[] = [
            'inventory_id' => $inventoryId,
            'seedling_type' => $speciesName,
            'quantity_received' => (int) $rawItem['quantity_received'],
        ];
    }

    return $resolved;
}

function permit_donation_requirement_for_ems_update(
    PDO $pdo,
    int $applicationId,
    int $actorUserId
): array {
    if (permit_donation_receipt_actor($pdo, $actorUserId, true) === null) {
        throw new RuntimeException('Only an active EMS User may record donation receipts.');
    }
    $stmt = $pdo->prepare(
        'SELECT r.*, a.transaction_id, a.applicant_user_id, a.applicant_name,
                a.application_status, a.decision_status, a.donation_status,
                a.release_status, a.validity_status
         FROM tbl_permit_donation_requirements r
         INNER JOIN tbl_permit_applications a ON a.id = r.application_id
         WHERE r.application_id = :application_id
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $requirement = $stmt->fetch();
    if (!$requirement) {
        throw new RuntimeException('The donation requirement does not exist.');
    }
    if ((string) $requirement['decision_status'] !== 'approved'
        || (string) $requirement['application_status'] !== 'awaiting_donation'
        || in_array((string) $requirement['current_status'], [
            'ems_verified', 'rps_verified', 'waived', 'not_required',
        ], true)
        || (string) $requirement['release_status'] === 'released'
        || in_array((string) $requirement['validity_status'], [
            'completed', 'expired', 'closed',
        ], true)) {
        throw new RuntimeException(
            'Only an approved application awaiting seedling donation may receive an EMS receipt.'
        );
    }
    if ((string) $requirement['donation_status'] !== (string) $requirement['current_status']) {
        throw new RuntimeException('The donation requirement and permit status are inconsistent.');
    }

    return $requirement;
}

function permit_donation_validate_receiver(PDO $pdo, int $receiverUserId): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tbl_users
         WHERE id = :id AND role = \'ems\' AND status = \'active\''
    );
    $stmt->execute([':id' => $receiverUserId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new PermitDonationReceiptValidationException(
            'Receiving personnel must be an active EMS User.'
        );
    }
}

function permit_donation_transition(
    PDO $pdo,
    array &$requirement,
    int $actorUserId,
    string $newStatus,
    ?string $remarks
): void {
    $previousStatus = (string) $requirement['current_status'];
    if ($previousStatus === $newStatus) {
        return;
    }
    if (!permit_status_transition_is_allowed('donation', $previousStatus, $newStatus)) {
        throw new RuntimeException(
            'Donation status cannot change from ' . permit_status_label($previousStatus)
                . ' to ' . permit_status_label($newStatus) . '.'
        );
    }
    $requirementUpdate = $pdo->prepare(
        'UPDATE tbl_permit_donation_requirements
         SET current_status = :new_status
         WHERE id = :requirement_id AND current_status = :previous_status'
    );
    $requirementUpdate->execute([
        ':new_status' => $newStatus,
        ':requirement_id' => (int) $requirement['id'],
        ':previous_status' => $previousStatus,
    ]);
    $applicationUpdate = $pdo->prepare(
        'UPDATE tbl_permit_applications
         SET donation_status = :new_status
         WHERE id = :application_id AND donation_status = :previous_status'
    );
    $applicationUpdate->execute([
        ':new_status' => $newStatus,
        ':application_id' => (int) $requirement['application_id'],
        ':previous_status' => $previousStatus,
    ]);
    if ($requirementUpdate->rowCount() !== 1 || $applicationUpdate->rowCount() !== 1) {
        throw new RuntimeException('The donation status changed before the EMS action completed.');
    }
    $historyRemarks = $remarks;
    if ($historyRemarks !== null && strlen($historyRemarks) > 500) {
        $historyRemarks = substr($historyRemarks, 0, 497) . '...';
    }
    permit_record_status_history(
        $pdo,
        (int) $requirement['application_id'],
        $actorUserId,
        'donation',
        $previousStatus,
        $newStatus,
        $historyRemarks
    );
    $requirement['current_status'] = $newStatus;
    $requirement['donation_status'] = $newStatus;
}

function permit_donation_transition_application_for_final_verification(
    PDO $pdo,
    array &$requirement,
    int $actorUserId,
    string $remarks
): void {
    $previousStatus = (string) $requirement['application_status'];
    $newStatus = 'awaiting_final_verification';
    if (!permit_status_transition_is_allowed('application', $previousStatus, $newStatus)) {
        throw new RuntimeException('The application is not eligible for final RPS verification.');
    }
    $stmt = $pdo->prepare(
        'UPDATE tbl_permit_applications
         SET application_status = :new_status
         WHERE id = :application_id AND application_status = :previous_status'
    );
    $stmt->execute([
        ':new_status' => $newStatus,
        ':application_id' => (int) $requirement['application_id'],
        ':previous_status' => $previousStatus,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The application changed before EMS verification completed.');
    }
    permit_record_status_history(
        $pdo,
        (int) $requirement['application_id'],
        $actorUserId,
        'application',
        $previousStatus,
        $newStatus,
        $remarks
    );
    $requirement['application_status'] = $newStatus;
}

function permit_donation_existing_action_result(
    PDO $pdo,
    string $actionKey,
    int $applicationId,
    int $actorUserId
): ?array {
    $stmt = $pdo->prepare(
        'SELECT v.id, v.verification_status, v.is_finalized, v.seedlings_received,
                r.application_id, r.required_seedling_count, r.received_seedling_count,
                r.current_status
         FROM tbl_permit_donation_verifications v
         INNER JOIN tbl_permit_donation_requirements r ON r.id = v.donation_requirement_id
         WHERE v.action_key = :action_key
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([':action_key' => $actionKey]);
    $existing = $stmt->fetch();
    if (!$existing) {
        return null;
    }
    $ownerStmt = $pdo->prepare(
        'SELECT verified_by_user_id
         FROM tbl_permit_donation_verifications
         WHERE id = :id'
    );
    $ownerStmt->execute([':id' => (int) $existing['id']]);
    if ((int) $existing['application_id'] !== $applicationId
        || (int) $ownerStmt->fetchColumn() !== $actorUserId) {
        throw new RuntimeException('The receipt action key was already used for another action.');
    }

    return [
        'application_id' => $applicationId,
        'verification_id' => (int) $existing['id'],
        'verification_status' => (string) $existing['verification_status'],
        'is_finalized' => (bool) $existing['is_finalized'],
        'batch_total' => (int) $existing['seedlings_received'],
        'received_total' => (int) $existing['received_seedling_count'],
        'required_total' => (int) $existing['required_seedling_count'],
        'remaining_total' => max(
            (int) $existing['required_seedling_count'] - (int) $existing['received_seedling_count'],
            0
        ),
        'donation_status' => (string) $existing['current_status'],
        'duplicate' => true,
    ];
}

function permit_donation_insert_receipt_version(
    PDO $pdo,
    array $requirement,
    int $actorUserId,
    string $actionKey,
    array $receipt,
    int $expectedVerificationId
): int {
    $previousId = null;
    $receiptGroupKey = bin2hex(random_bytes(32));
    $versionNumber = 1;
    if ($expectedVerificationId > 0) {
        $previousStmt = $pdo->prepare(
            'SELECT id, donation_requirement_id, receipt_group_key, version_number,
                    is_current, is_finalized, verification_status
             FROM tbl_permit_donation_verifications
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $previousStmt->execute([':id' => $expectedVerificationId]);
        $previous = $previousStmt->fetch();
        if (!$previous
            || (int) $previous['donation_requirement_id'] !== (int) $requirement['id']
            || (int) $previous['is_current'] !== 1
            || (int) $previous['is_finalized'] !== 0
            || (string) $previous['verification_status'] !== 'draft') {
            throw new RuntimeException(
                'The unfinalized receipt changed before this action completed. Reload and try again.'
            );
        }
        $previousId = (int) $previous['id'];
        $receiptGroupKey = (string) $previous['receipt_group_key'];
        $versionNumber = (int) $previous['version_number'] + 1;
        $archive = $pdo->prepare(
            'UPDATE tbl_permit_donation_verifications
             SET is_current = 0
             WHERE id = :id AND is_current = 1 AND is_finalized = 0'
        );
        $archive->execute([':id' => $previousId]);
        if ($archive->rowCount() !== 1) {
            throw new RuntimeException('The receipt correction could not preserve the prior version.');
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO tbl_permit_donation_verifications
            (donation_requirement_id, previous_verification_id, receipt_group_key,
             action_key, version_number, received_by_user_id, verified_by_user_id,
             verification_status, is_current, is_finalized, seedlings_received,
             receipt_reference, received_at, verification_notes)
         VALUES
            (:donation_requirement_id, :previous_verification_id, :receipt_group_key,
             :action_key, :version_number, :received_by_user_id, :verified_by_user_id,
             \'draft\', 1, 0, :seedlings_received,
             NULL, :received_at, :verification_notes)'
    );
    $insert->execute([
        ':donation_requirement_id' => (int) $requirement['id'],
        ':previous_verification_id' => $previousId,
        ':receipt_group_key' => $receiptGroupKey,
        ':action_key' => $actionKey,
        ':version_number' => $versionNumber,
        ':received_by_user_id' => (int) $receipt['received_by_user_id'],
        ':verified_by_user_id' => $actorUserId,
        ':seedlings_received' => (int) $receipt['seedlings_received'],
        ':received_at' => (string) $receipt['received_at'],
        ':verification_notes' => $receipt['verification_notes'],
    ]);
    $verificationId = (int) $pdo->lastInsertId();
    $itemInsert = $pdo->prepare(
        'INSERT INTO tbl_permit_donation_verification_items
            (donation_verification_id, seedling_type, inventory_id, quantity_received)
         VALUES
            (:donation_verification_id, :seedling_type, :inventory_id, :quantity_received)'
    );
    foreach ($receipt['items'] as $item) {
        $itemInsert->execute([
            ':donation_verification_id' => $verificationId,
            ':seedling_type' => (string) $item['seedling_type'],
            ':inventory_id' => (int) $item['inventory_id'],
            ':quantity_received' => (int) $item['quantity_received'],
        ]);
    }

    return $verificationId;
}

function permit_donation_total_finalized_receipts(PDO $pdo, int $requirementId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(seedlings_received), 0)
         FROM tbl_permit_donation_verifications
         WHERE donation_requirement_id = :requirement_id
           AND is_current = 1
           AND is_finalized = 1
           AND verification_status IN (\'partially_received\', \'ems_verified\', \'verified\')'
    );
    $stmt->execute([':requirement_id' => $requirementId]);

    return (int) $stmt->fetchColumn();
}

function permit_donation_rps_notification_recipients(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id FROM tbl_users WHERE role = \'rps\' AND status = \'active\' ORDER BY id'
    );

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function permit_donation_notify_receipt_result(
    PDO $pdo,
    array $requirement,
    int $actorUserId,
    string $title,
    string $message,
    bool $notifyRps
): void {
    create_notification(
        $pdo,
        (int) $requirement['applicant_user_id'],
        $actorUserId,
        'donation_verification',
        $title,
        $message,
        'permit_application',
        (int) $requirement['application_id']
    );
    if ($notifyRps) {
        $rpsRecipients = permit_donation_rps_notification_recipients($pdo);
        if ($rpsRecipients !== []) {
            create_notifications_for_users(
                $pdo,
                $rpsRecipients,
                $actorUserId,
                'donation_verification',
                $title,
                $message,
                'permit_application',
                (int) $requirement['application_id']
            );
        }
    }
}

function record_permit_donation_receipt(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $action,
    array $input
): array {
    $action = trim($action);
    if (!in_array($action, ['save_draft', 'finalize', 'flag_invalid'], true)) {
        throw new PermitDonationReceiptValidationException('Select a valid EMS receipt action.');
    }
    if ($applicationId < 1) {
        throw new PermitDonationReceiptValidationException('The permit application is invalid.');
    }
    $actionKey = permit_donation_receipt_validate_action_key(
        permit_donation_scalar_value($input['action_key'] ?? '')
    );
    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        if (permit_donation_receipt_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('Only an active EMS User may record donation receipts.');
        }
        $duplicate = permit_donation_existing_action_result(
            $pdo,
            $actionKey,
            $applicationId,
            $actorUserId
        );
        if ($duplicate !== null) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $duplicate;
        }
        $requirement = permit_donation_requirement_for_ems_update(
            $pdo,
            $applicationId,
            $actorUserId
        );

        if ($action === 'flag_invalid') {
            $remarks = permit_donation_scalar_value($input['verification_notes'] ?? '');
            if ($remarks === '' || strlen($remarks) > 1000) {
                throw new PermitDonationReceiptValidationException(
                    'Flagging a transaction requires verification remarks of 1-1000 characters.'
                );
            }
            if ((string) $requirement['current_status'] === 'flagged') {
                throw new RuntimeException('This donation transaction is already flagged.');
            }
            if ((string) $requirement['current_status'] === 'required') {
                permit_donation_transition(
                    $pdo,
                    $requirement,
                    $actorUserId,
                    'pending',
                    'EMS began donation transaction verification.'
                );
            }
            $insert = $pdo->prepare(
                'INSERT INTO tbl_permit_donation_verifications
                    (donation_requirement_id, receipt_group_key, action_key, version_number,
                     received_by_user_id, verified_by_user_id, verification_status,
                     is_current, is_finalized, seedlings_received, receipt_reference,
                     received_at, verification_notes, finalized_at)
                 VALUES
                    (:requirement_id, :receipt_group_key, :action_key, 1,
                     :received_by_user_id, :verified_by_user_id, \'flagged\',
                     1, 1, 0, NULL, CURRENT_TIMESTAMP, :verification_notes, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                ':requirement_id' => (int) $requirement['id'],
                ':receipt_group_key' => bin2hex(random_bytes(32)),
                ':action_key' => $actionKey,
                ':received_by_user_id' => $actorUserId,
                ':verified_by_user_id' => $actorUserId,
                ':verification_notes' => $remarks,
            ]);
            $verificationId = (int) $pdo->lastInsertId();
            permit_donation_transition(
                $pdo,
                $requirement,
                $actorUserId,
                'flagged',
                $remarks
            );
            record_audit_event(
                $pdo,
                $actorUserId,
                'verification',
                'donation_transaction_flagged',
                'permit_donation_verification',
                $verificationId,
                'Flagged an invalid seedling donation transaction.',
                [
                    'application_id' => $applicationId,
                    'transaction_id' => (string) $requirement['transaction_id'],
                    'remarks' => $remarks,
                ]
            );
            permit_donation_notify_receipt_result(
                $pdo,
                $requirement,
                $actorUserId,
                'Seedling donation transaction flagged',
                'EMS flagged donation transaction ' . $requirement['transaction_id']
                    . '. Remarks: ' . $remarks
                    . ' The permit is not eligible for release.',
                true
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'application_id' => $applicationId,
                'verification_id' => $verificationId,
                'verification_status' => 'flagged',
                'is_finalized' => true,
                'batch_total' => 0,
                'received_total' => (int) $requirement['received_seedling_count'],
                'required_total' => (int) $requirement['required_seedling_count'],
                'remaining_total' => max(
                    (int) $requirement['required_seedling_count']
                        - (int) $requirement['received_seedling_count'],
                    0
                ),
                'donation_status' => 'flagged',
                'duplicate' => false,
            ];
        }

        $receipt = permit_donation_normalize_receipt_input($input);
        $receipt['items'] = permit_donation_resolve_receipt_items($pdo, $actorUserId, $receipt['items']);
        permit_donation_validate_receiver($pdo, (int) $receipt['received_by_user_id']);
        if ($action === 'finalize'
            && permit_donation_scalar_value($input['confirm_physical_receipt'] ?? '') !== '1') {
            throw new PermitDonationReceiptValidationException(
                'Confirm that EMS physically received the recorded seedlings before finalizing.'
            );
        }
        $expectedValue = permit_donation_scalar_value($input['expected_verification_id'] ?? '');
        $expectedVerificationId = $expectedValue === '' ? 0 : (ctype_digit($expectedValue)
            ? (int) $expectedValue
            : -1);
        if ($expectedVerificationId < 0) {
            throw new PermitDonationReceiptValidationException('The receipt version is invalid.');
        }
        $verificationId = permit_donation_insert_receipt_version(
            $pdo,
            $requirement,
            $actorUserId,
            $actionKey,
            $receipt,
            $expectedVerificationId
        );
        if ((string) $requirement['current_status'] === 'required') {
            permit_donation_transition(
                $pdo,
                $requirement,
                $actorUserId,
                'pending',
                'EMS started a seedling donation receipt record.'
            );
        }

        if ($action === 'save_draft') {
            record_audit_event(
                $pdo,
                $actorUserId,
                'verification',
                $expectedVerificationId > 0
                    ? 'donation_receipt_draft_corrected'
                    : 'donation_receipt_draft_saved',
                'permit_donation_verification',
                $verificationId,
                'Saved an unfinalized EMS seedling donation receipt.',
                [
                    'application_id' => $applicationId,
                    'transaction_id' => (string) $requirement['transaction_id'],
                    'previous_verification_id' => $expectedVerificationId ?: null,
                    'batch_total' => (int) $receipt['seedlings_received'],
                ]
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'application_id' => $applicationId,
                'verification_id' => $verificationId,
                'verification_status' => 'draft',
                'is_finalized' => false,
                'batch_total' => (int) $receipt['seedlings_received'],
                'received_total' => (int) $requirement['received_seedling_count'],
                'required_total' => (int) $requirement['required_seedling_count'],
                'remaining_total' => max(
                    (int) $requirement['required_seedling_count']
                        - (int) $requirement['received_seedling_count'],
                    0
                ),
                'donation_status' => (string) $requirement['current_status'],
                'duplicate' => false,
            ];
        }

        $finalizedBefore = permit_donation_total_finalized_receipts(
            $pdo,
            (int) $requirement['id']
        );
        $cumulativeTotal = $finalizedBefore + (int) $receipt['seedlings_received'];
        $requiredTotal = (int) $requirement['required_seedling_count'];
        if ($cumulativeTotal > $requiredTotal
            && permit_donation_scalar_value($input['confirm_overage'] ?? '') !== '1') {
            throw new PermitDonationReceiptValidationException(
                'The cumulative receipt exceeds the requirement. Confirm the over-receipt before finalizing.'
            );
        }
        $verificationStatus = $cumulativeTotal >= $requiredTotal
            ? 'ems_verified'
            : 'partially_received';
        $finalize = $pdo->prepare(
            'UPDATE tbl_permit_donation_verifications
             SET verification_status = :verification_status,
                 is_finalized = 1,
                 finalized_at = CURRENT_TIMESTAMP
             WHERE id = :id AND is_current = 1 AND is_finalized = 0'
        );
        $finalize->execute([
            ':verification_status' => $verificationStatus,
            ':id' => $verificationId,
        ]);
        if ($finalize->rowCount() !== 1) {
            throw new RuntimeException('The receipt was finalized by another request.');
        }
        $requirementTotalUpdate = $pdo->prepare(
            'UPDATE tbl_permit_donation_requirements
             SET received_seedling_count = :received_total
             WHERE id = :requirement_id
               AND received_seedling_count = :previous_received_total'
        );
        $requirementTotalUpdate->execute([
            ':received_total' => $cumulativeTotal,
            ':requirement_id' => (int) $requirement['id'],
            ':previous_received_total' => (int) $requirement['received_seedling_count'],
        ]);
        if ($requirementTotalUpdate->rowCount() !== 1) {
            throw new RuntimeException('The donation total changed before finalization completed.');
        }
        $requirement['received_seedling_count'] = $cumulativeTotal;

        // Physical seedlings received restock the seedling-request inventory
        // automatically, whether this batch completes the requirement or not.
        foreach ($receipt['items'] as $item) {
            seedling_apply_stock_movement(
                $pdo,
                (int) $item['inventory_id'],
                $actorUserId,
                'incoming',
                (int) $item['quantity_received'],
                null,
                'Seedling donation receipt for permit ' . (string) $requirement['transaction_id']
                    . ' (application #' . (int) $requirement['application_id'] . ').'
            );
        }

        $statusRemarks = $verificationStatus === 'ems_verified'
            ? 'EMS verified a cumulative physical receipt of ' . $cumulativeTotal
                . ' seedlings against the requirement of ' . $requiredTotal . '.'
            : 'EMS finalized a partial physical receipt of ' . $receipt['seedlings_received']
                . ' seedlings; ' . ($requiredTotal - $cumulativeTotal) . ' remain.';
        permit_donation_transition(
            $pdo,
            $requirement,
            $actorUserId,
            $verificationStatus,
            $statusRemarks
        );
        if ($verificationStatus === 'ems_verified') {
            permit_donation_transition_application_for_final_verification(
                $pdo,
                $requirement,
                $actorUserId,
                'EMS donation verification completed. Final RPS verification is still required.'
            );
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'verification',
            $verificationStatus === 'ems_verified'
                ? 'donation_receipt_ems_verified'
                : 'donation_receipt_partial_recorded',
            'permit_donation_verification',
            $verificationId,
            'Finalized an EMS seedling donation receipt.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $requirement['transaction_id'],
                'receiving_personnel_user_id' => (int) $receipt['received_by_user_id'],
                'batch_total' => (int) $receipt['seedlings_received'],
                'cumulative_total' => $cumulativeTotal,
                'required_total' => $requiredTotal,
                'overage_confirmed' => $cumulativeTotal > $requiredTotal,
                'verification_status' => $verificationStatus,
            ]
        );
        $remaining = max($requiredTotal - $cumulativeTotal, 0);
        if ($verificationStatus === 'ems_verified') {
            $message = 'EMS verified physical receipt for donation transaction '
                . $requirement['transaction_id'] . ' (' . $cumulativeTotal . ' of '
                . $requiredTotal . ' seedlings). The application is eligible for final RPS verification, '
                . 'but the permit has not been released.';
            permit_donation_notify_receipt_result(
                $pdo,
                $requirement,
                $actorUserId,
                'Seedling donation verified by EMS',
                $message,
                true
            );
        } else {
            permit_donation_notify_receipt_result(
                $pdo,
                $requirement,
                $actorUserId,
                'Partial seedling donation recorded',
                'EMS recorded ' . $cumulativeTotal . ' of ' . $requiredTotal
                    . ' required seedlings for transaction ' . $requirement['transaction_id']
                    . '. ' . $remaining . ' remain; the permit is not eligible for release.',
                true
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'verification_id' => $verificationId,
            'verification_status' => $verificationStatus,
            'is_finalized' => true,
            'batch_total' => (int) $receipt['seedlings_received'],
            'received_total' => $cumulativeTotal,
            'required_total' => $requiredTotal,
            'remaining_total' => $remaining,
            'donation_status' => $verificationStatus,
            'duplicate' => false,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function permit_list_donation_receipts_for_ems(
    PDO $pdo,
    int $applicationId,
    int $actorUserId
): array {
    if (permit_donation_receipt_actor($pdo, $actorUserId) === null
        || permit_donation_application_for_actor($pdo, $applicationId, $actorUserId) === null) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT v.*, CONCAT(receiver.fname, \' \', receiver.lname) AS received_by_name,
                CONCAT(actor.fname, \' \', actor.lname) AS verified_by_name
         FROM tbl_permit_donation_verifications v
         INNER JOIN tbl_permit_donation_requirements r ON r.id = v.donation_requirement_id
         INNER JOIN tbl_users receiver ON receiver.id = v.received_by_user_id
         INNER JOIN tbl_users actor ON actor.id = v.verified_by_user_id
         WHERE r.application_id = :application_id
         ORDER BY v.verified_at DESC, v.id DESC'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $receipts = $stmt->fetchAll();
    if ($receipts === []) {
        return [];
    }
    $receiptIds = array_map(static fn (array $row): int => (int) $row['id'], $receipts);
    $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));
    $itemsStmt = $pdo->prepare(
        'SELECT donation_verification_id, seedling_type, inventory_id, quantity_received
         FROM tbl_permit_donation_verification_items
         WHERE donation_verification_id IN (' . $placeholders . ')
         ORDER BY id'
    );
    $itemsStmt->execute($receiptIds);
    $itemsByReceipt = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByReceipt[(int) $item['donation_verification_id']][] = $item;
    }
    foreach ($receipts as &$receipt) {
        $receipt['items'] = $itemsByReceipt[(int) $receipt['id']] ?? [];
    }
    unset($receipt);

    return $receipts;
}

function permit_donation_receipt_for_edit(
    PDO $pdo,
    int $applicationId,
    int $verificationId,
    int $actorUserId
): ?array {
    foreach (permit_list_donation_receipts_for_ems($pdo, $applicationId, $actorUserId) as $receipt) {
        if ((int) $receipt['id'] === $verificationId
            && (int) $receipt['is_current'] === 1
            && (int) $receipt['is_finalized'] === 0
            && (string) $receipt['verification_status'] === 'draft') {
            return $receipt;
        }
    }

    return null;
}
