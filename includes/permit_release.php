<?php
/**
 * Post-approval Tree Cutting Permit lifecycle services:
 * final RPS donation confirmation, signed-permit preparation and release,
 * validity calculation, expiration processing, and cutting completion.
 *
 * Every mutation is transactional and reuses the shared status-history,
 * audit, and notification writers. Donation, decision, and inspection state
 * remain owned by their existing workflow services; this module only advances
 * the release, validity, and application domains plus the terminal donation
 * confirmation that the RPS performs.
 */

require_once __DIR__ . '/permit.php';
require_once __DIR__ . '/permit_decisions.php';
require_once __DIR__ . '/permit_donation_receipts.php';
require_once __DIR__ . '/permit_documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../config/permit_policy.php';

class PermitReleaseValidationException extends RuntimeException
{
}

/** Release/final-verification/completion authority equals RPS decision authority. */
function permit_release_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    return permit_decision_actor($pdo, $actorUserId, $forUpdate);
}

function permit_validity_duration_bounds(): array
{
    return [
        'min' => (int) PERMIT_VALIDITY_MIN_DAYS,
        'max' => (int) PERMIT_VALIDITY_MAX_DAYS,
        'default' => (int) PERMIT_VALIDITY_DEFAULT_DAYS,
    ];
}

function permit_validity_start_basis(): string
{
    return (string) PERMIT_VALIDITY_START_BASIS;
}

/**
 * Where the official wet-ink-signed permit is physically claimed. The system
 * does not generate or deliver the permit document digitally; a released permit
 * is collected in person at this office.
 */
function permit_claim_location(): string
{
    return defined('CERTREEFY_PERMIT_CLAIM_LOCATION')
        ? (string) CERTREEFY_PERMIT_CLAIM_LOCATION
        : 'the CENRO office';
}

/** Read-only permit view for any actor authorized to see the application. */
function permit_release_record_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    if (permit_decision_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT p.*, CONCAT(pre.fname, \' \', pre.lname) AS prepared_by_name,
                CASE WHEN rel.id IS NULL THEN NULL ELSE CONCAT(rel.fname, \' \', rel.lname) END AS released_by_name,
                CASE WHEN up.id IS NULL THEN NULL ELSE CONCAT(up.fname, \' \', up.lname) END AS permit_file_uploaded_by_name
         FROM tbl_permits p
         INNER JOIN tbl_users pre ON pre.id = p.prepared_by_user_id
         LEFT JOIN tbl_users rel ON rel.id = p.released_by_user_id
         LEFT JOIN tbl_users up ON up.id = p.permit_file_uploaded_by_user_id
         WHERE p.application_id = :application_id
         LIMIT 1'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $permit = $stmt->fetch();

    return $permit ?: null;
}

/** Computed validity view: derives an effective expiry state from valid_until. */
function permit_validity_snapshot(?array $permit, ?string $validityStatus = null): array
{
    if ($permit === null) {
        return [
            'issued' => false,
            'status' => $validityStatus ?? 'not_issued',
            'valid_from' => null,
            'valid_until' => null,
            'days_remaining' => null,
            'is_expired' => false,
            'is_expiring_soon' => false,
        ];
    }
    $status = $validityStatus ?? (string) ($permit['validity_status'] ?? 'not_issued');
    $validUntil = $permit['valid_until'] ?? null;
    $daysRemaining = null;
    $isExpired = false;
    $isExpiringSoon = false;
    if ($validUntil !== null) {
        $today = new DateTimeImmutable('today');
        $until = new DateTimeImmutable((string) $validUntil . ' 00:00:00');
        $daysRemaining = (int) $today->diff($until)->format('%r%a');
        // A permit is effectively expired once the day after valid_until begins.
        $isExpired = $status === 'active' && $daysRemaining < 0;
        $isExpiringSoon = $status === 'active' && !$isExpired
            && $daysRemaining <= (int) PERMIT_VALIDITY_EXPIRY_WARNING_DAYS;
    }

    return [
        'issued' => true,
        'status' => $status,
        'valid_from' => $permit['valid_from'] ?? null,
        'valid_until' => $validUntil,
        'days_remaining' => $daysRemaining,
        'is_expired' => $isExpired,
        'is_expiring_soon' => $isExpiringSoon,
    ];
}

/** Active RPS + authorized-superadmin personnel who may verify a completion. */
function permit_completion_personnel(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, CONCAT(fname, \' \', lname) AS full_name, role
         FROM tbl_users
         WHERE status = \'active\' AND role IN (\'rps\', \'superadmin\')
         ORDER BY full_name'
    );

    return $stmt->fetchAll();
}

/**
 * Final RPS donation confirmation.
 *
 * Moves an EMS-verified donation to rps_verified, advances the application to
 * ready_for_release, and opens release preparation. It never releases the
 * permit; a separate release action does that.
 */
function permit_confirm_final_donation_verification(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $remarks
): array {
    $remarks = trim($remarks);
    if ($remarks !== '' && strlen($remarks) > 500) {
        throw new PermitReleaseValidationException('Confirmation remarks must not exceed 500 characters.');
    }
    if ($applicationId < 1) {
        throw new PermitReleaseValidationException('The permit application is invalid.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $actor = permit_release_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to confirm donation compliance.');
        }
        $application = permit_load_application($pdo, $applicationId, true);
        if ($application === null) {
            throw new RuntimeException('The permit application does not exist.');
        }
        if ((string) $application['application_status'] !== 'awaiting_final_verification'
            || (string) $application['donation_status'] !== 'ems_verified') {
            throw new RuntimeException('This application is not awaiting final RPS donation verification.');
        }

        $requirementStmt = $pdo->prepare(
            'SELECT id, required_seedling_count, received_seedling_count, current_status
             FROM tbl_permit_donation_requirements
             WHERE application_id = :application_id
             LIMIT 1
             FOR UPDATE'
        );
        $requirementStmt->execute([':application_id' => $applicationId]);
        $requirement = $requirementStmt->fetch();
        if (!$requirement || (string) $requirement['current_status'] !== 'ems_verified') {
            throw new RuntimeException('The seedling donation has not been verified by EMS.');
        }
        if ((int) $requirement['received_seedling_count'] < (int) $requirement['required_seedling_count']) {
            throw new RuntimeException('The recorded donation total does not meet the required seedling count.');
        }

        // Donation: ems_verified -> rps_verified.
        $donationUpdate = $pdo->prepare(
            'UPDATE tbl_permit_donation_requirements
             SET current_status = \'rps_verified\'
             WHERE id = :id AND current_status = \'ems_verified\''
        );
        $donationUpdate->execute([':id' => (int) $requirement['id']]);
        if ($donationUpdate->rowCount() !== 1) {
            throw new RuntimeException('The donation status changed before final verification completed.');
        }
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'donation',
            'ems_verified',
            'rps_verified',
            $remarks !== '' ? $remarks : 'RPS confirmed EMS-verified seedling donation compliance.'
        );
        // Application: awaiting_final_verification -> ready_for_release.
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'application',
            'awaiting_final_verification',
            'ready_for_release',
            'Donation compliance confirmed; the permit may be prepared and released.'
        );
        // Release: not_ready -> preparing.
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'release',
            'not_ready',
            'preparing',
            'Signed permit preparation opened after donation confirmation.'
        );

        record_audit_event(
            $pdo,
            $actorUserId,
            'verification',
            'donation_rps_verified',
            'permit_application',
            $applicationId,
            'RPS confirmed final seedling donation compliance.',
            [
                'transaction_id' => (string) $application['transaction_id'],
                'required_total' => (int) $requirement['required_seedling_count'],
                'received_total' => (int) $requirement['received_seedling_count'],
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'donation_verification',
            'Seedling donation compliance confirmed',
            'RPS confirmed your seedling donation for permit ' . (string) $application['transaction_id']
                . '. Your application is now ready for permit preparation and release.',
            'permit_application',
            $applicationId
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'donation_status' => 'rps_verified',
            'application_status' => 'ready_for_release',
            'release_status' => 'preparing',
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Internal: validate and apply a single release/validity/application transition. */
function permit_release_apply_status(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $domain,
    string $previousStatus,
    string $newStatus,
    string $remarks
): void {
    if (!permit_status_transition_is_allowed($domain, $previousStatus, $newStatus)) {
        throw new RuntimeException('An invalid permit ' . $domain . ' transition was blocked.');
    }
    $column = permit_status_columns()[$domain];
    $update = $pdo->prepare(
        'UPDATE tbl_permit_applications
         SET ' . $column . ' = :new_status
         WHERE id = :application_id AND ' . $column . ' = :previous_status'
    );
    $update->execute([
        ':new_status' => $newStatus,
        ':application_id' => $applicationId,
        ':previous_status' => $previousStatus,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The permit ' . $domain . ' status changed before the update completed.');
    }
    $historyRemarks = strlen($remarks) > 500 ? substr($remarks, 0, 497) . '...' : $remarks;
    permit_record_status_history(
        $pdo,
        $applicationId,
        $actorUserId,
        $domain,
        $previousStatus,
        $newStatus,
        $historyRemarks
    );
}

/**
 * Prepare and release a signed permit in one transaction: creates the permit
 * record, stores the exact approved duration, derives the expiration date, and
 * activates validity. No extension or reactivation path exists.
 */
function permit_prepare_and_release_permit(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    array $input
): array {
    if ($applicationId < 1) {
        throw new PermitReleaseValidationException('The permit application is invalid.');
    }
    $bounds = permit_validity_duration_bounds();
    $durationValue = trim((string) ($input['approved_duration_days'] ?? ''));
    if (!ctype_digit($durationValue)) {
        throw new PermitReleaseValidationException('Enter the approved cutting duration in whole days.');
    }
    $durationDays = (int) $durationValue;
    if ($durationDays < $bounds['min'] || $durationDays > $bounds['max']) {
        throw new PermitReleaseValidationException(
            'The approved cutting duration must be between ' . $bounds['min']
            . ' and ' . $bounds['max'] . ' days.'
        );
    }
    $permitNumberInput = trim((string) ($input['permit_number'] ?? ''));
    if ($permitNumberInput !== '' && !preg_match('/^[A-Za-z0-9\/-]{3,50}$/', $permitNumberInput)) {
        throw new PermitReleaseValidationException('The permit number may use 3-50 letters, numbers, hyphens, or slashes.');
    }
    $releaseNotes = trim((string) ($input['release_notes'] ?? ''));
    if (strlen($releaseNotes) > 1000) {
        throw new PermitReleaseValidationException('Release notes must not exceed 1000 characters.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $actor = permit_release_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to prepare and release permits.');
        }
        $application = permit_load_application($pdo, $applicationId, true);
        if ($application === null) {
            throw new RuntimeException('The permit application does not exist.');
        }
        if ((string) $application['application_status'] !== 'ready_for_release'
            || (string) $application['release_status'] !== 'preparing') {
            throw new RuntimeException('This application is not ready for permit preparation and release.');
        }
        if (!in_array((string) $application['donation_status'], ['rps_verified', 'waived'], true)) {
            throw new RuntimeException('Donation compliance must be RPS-verified before release.');
        }
        if ((string) $application['document_status'] !== 'verified') {
            throw new RuntimeException('All original documents must be verified before release.');
        }
        if (!in_array((string) $application['inspection_status'], ['passed', 'not_required'], true)) {
            throw new RuntimeException('A passed or not-required inspection is required before release.');
        }
        if ((string) $application['validity_status'] !== 'not_issued') {
            throw new RuntimeException('This permit has already been issued.');
        }

        $existingPermitStmt = $pdo->prepare(
            'SELECT id FROM tbl_permits WHERE application_id = :application_id LIMIT 1 FOR UPDATE'
        );
        $existingPermitStmt->execute([':application_id' => $applicationId]);
        if ($existingPermitStmt->fetchColumn() !== false) {
            throw new RuntimeException('A permit record already exists for this application.');
        }

        $decisionStmt = $pdo->prepare(
            'SELECT id FROM tbl_permit_decisions
             WHERE application_id = :application_id AND decision = \'approved\'
             ORDER BY id DESC LIMIT 1'
        );
        $decisionStmt->execute([':application_id' => $applicationId]);
        $approvalDecisionId = $decisionStmt->fetchColumn();
        if ($approvalDecisionId === false) {
            throw new RuntimeException('No approval decision was found for this application.');
        }

        $validFrom = new DateTimeImmutable('today');
        $validUntil = $validFrom->add(new DateInterval('P' . $durationDays . 'D'));
        $permitNumber = $permitNumberInput !== '' ? $permitNumberInput : (string) $application['transaction_id'];

        $insert = $pdo->prepare(
            'INSERT INTO tbl_permits
                (application_id, decision_id, permit_number, prepared_by_user_id,
                 released_by_user_id, released_at, valid_from, valid_until,
                 approved_duration_days, validity_start_basis, release_notes)
             VALUES
                (:application_id, :decision_id, :permit_number, :prepared_by,
                 :released_by, NOW(), :valid_from, :valid_until,
                 :approved_duration_days, :validity_start_basis, :release_notes)'
        );
        try {
            $insert->execute([
                ':application_id' => $applicationId,
                ':decision_id' => (int) $approvalDecisionId,
                ':permit_number' => $permitNumber,
                ':prepared_by' => $actorUserId,
                ':released_by' => $actorUserId,
                ':valid_from' => $validFrom->format('Y-m-d'),
                ':valid_until' => $validUntil->format('Y-m-d'),
                ':approved_duration_days' => $durationDays,
                ':validity_start_basis' => permit_validity_start_basis(),
                ':release_notes' => $releaseNotes !== '' ? $releaseNotes : null,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new PermitReleaseValidationException('That permit number is already in use. Enter a unique permit number.');
            }
            throw $e;
        }
        $permitId = (int) $pdo->lastInsertId();

        // Release: preparing -> ready -> released. Two events preserve the
        // separate preparation and release milestones.
        permit_release_apply_status($pdo, $applicationId, $actorUserId, 'release', 'preparing', 'ready', 'Signed permit prepared for release.');
        permit_release_apply_status($pdo, $applicationId, $actorUserId, 'release', 'ready', 'released', 'Permit released to the applicant.');
        // Application: ready_for_release -> released.
        permit_release_apply_status($pdo, $applicationId, $actorUserId, 'application', 'ready_for_release', 'released', 'Signed permit released.');
        // Validity: not_issued -> active.
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'validity',
            'not_issued',
            'active',
            'Permit valid from ' . $validFrom->format('Y-m-d') . ' to ' . $validUntil->format('Y-m-d')
                . ' (' . $durationDays . ' days).'
        );

        record_audit_event(
            $pdo,
            $actorUserId,
            'permit',
            'permit_released',
            'permit',
            $permitId,
            'Prepared and released a signed Tree Cutting Permit.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'permit_number' => $permitNumber,
                'approved_duration_days' => $durationDays,
                'valid_from' => $validFrom->format('Y-m-d'),
                'valid_until' => $validUntil->format('Y-m-d'),
                'validity_start_basis' => permit_validity_start_basis(),
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'permit_status',
            'Tree Cutting Permit released',
            'Permit ' . $permitNumber . ' for transaction ' . (string) $application['transaction_id']
                . ' has been released. It is valid from ' . $validFrom->format('M j, Y')
                . ' to ' . $validUntil->format('M j, Y') . ' (' . $durationDays
                . ' days). Please claim your official signed permit at ' . permit_claim_location()
                . ' during office hours; bring a valid ID and your transaction ID. No extension is'
                . ' allowed; cutting must be completed within this period.',
            'permit_application',
            $applicationId
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'permit_id' => $permitId,
            'permit_number' => $permitNumber,
            'transaction_id' => (string) $application['transaction_id'],
            'valid_from' => $validFrom->format('Y-m-d'),
            'valid_until' => $validUntil->format('Y-m-d'),
            'approved_duration_days' => $durationDays,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Attaches (or replaces) the scanned copy of a physically signed permit and,
 * optionally, the signing/recipient record for an already-released permit.
 *
 * This is a post-release document action: it never changes the release,
 * validity, or application status. The permit is released by
 * permit_prepare_and_release_permit(); uploading a scan alone does not release
 * a permit. The scan is stored in the same private root as permit documents and
 * is only reachable through the authorization-checked download endpoints.
 */
function permit_attach_signed_permit_scan(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    array $input,
    array $file
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Signed-permit upload must own its database transaction.');
    }
    if ($applicationId < 1) {
        throw new PermitReleaseValidationException('The permit application is invalid.');
    }

    // Optional signing/recipient metadata.
    $signedOn = trim((string) ($input['signed_on'] ?? ''));
    if ($signedOn !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $signedOn);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $signedOn) {
            throw new PermitReleaseValidationException('Enter a valid signing date.');
        }
        if ($date > new DateTimeImmutable('today')) {
            throw new PermitReleaseValidationException('The signing date cannot be in the future.');
        }
    }
    $signedByName = trim((string) ($input['signed_by_name'] ?? ''));
    if (strlen($signedByName) > 150) {
        throw new PermitReleaseValidationException('The signing personnel name must not exceed 150 characters.');
    }
    $recipient = trim((string) ($input['released_to_recipient'] ?? ''));
    if (strlen($recipient) > 150) {
        throw new PermitReleaseValidationException('The recipient or claimant name must not exceed 150 characters.');
    }

    // Reuse the vetted permit-document validator (PDF/JPEG/PNG, real content).
    $validatedFile = permit_document_validate_uploaded_file($file);
    $storedPath = null;

    try {
        $pdo->beginTransaction();
        $actor = permit_release_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to record the signed permit.');
        }
        $application = permit_load_application($pdo, $applicationId, true);
        if ($application === null) {
            throw new RuntimeException('The permit application does not exist.');
        }
        if ((string) $application['release_status'] !== 'released') {
            throw new RuntimeException('A signed permit scan can be recorded only after the permit is released.');
        }

        $permitStmt = $pdo->prepare(
            'SELECT id, permit_number, permit_file_path
             FROM tbl_permits
             WHERE application_id = :application_id
             LIMIT 1
             FOR UPDATE'
        );
        $permitStmt->execute([':application_id' => $applicationId]);
        $permit = $permitStmt->fetch();
        if (!$permit) {
            throw new RuntimeException('No released permit record was found for this application.');
        }
        $previousPath = (string) ($permit['permit_file_path'] ?? '');

        $storage = permit_document_relative_storage_path(
            (string) $application['transaction_id'],
            (string) $validatedFile['extension']
        );
        $storedPath = (string) $storage['absolute_path'];
        if (!move_uploaded_file((string) $validatedFile['tmp_name'], $storedPath)) {
            throw new RuntimeException('The signed permit scan could not be moved into private storage.');
        }
        @chmod($storedPath, 0600);

        $update = $pdo->prepare(
            'UPDATE tbl_permits
             SET permit_file_path = :permit_file_path,
                 permit_file_original_name = :permit_file_original_name,
                 permit_file_mime_type = :permit_file_mime_type,
                 permit_file_size_bytes = :permit_file_size_bytes,
                 permit_file_uploaded_by_user_id = :uploaded_by,
                 permit_file_uploaded_at = NOW(),
                 signed_on = :signed_on,
                 signed_by_name = :signed_by_name,
                 released_to_recipient = :released_to_recipient
             WHERE id = :id'
        );
        $update->execute([
            ':permit_file_path' => (string) $storage['relative_path'],
            ':permit_file_original_name' => (string) $validatedFile['original_filename'],
            ':permit_file_mime_type' => (string) $validatedFile['mime_type'],
            ':permit_file_size_bytes' => (int) $validatedFile['file_size_bytes'],
            ':uploaded_by' => $actorUserId,
            ':signed_on' => $signedOn !== '' ? $signedOn : null,
            ':signed_by_name' => $signedByName !== '' ? $signedByName : null,
            ':released_to_recipient' => $recipient !== '' ? $recipient : null,
            ':id' => (int) $permit['id'],
        ]);

        record_audit_event(
            $pdo,
            $actorUserId,
            'permit',
            $previousPath !== '' ? 'permit_signed_scan_replaced' : 'permit_signed_scan_uploaded',
            'permit',
            (int) $permit['id'],
            $previousPath !== ''
                ? 'Replaced the scanned copy of the signed Tree Cutting Permit.'
                : 'Uploaded the scanned copy of the signed Tree Cutting Permit.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'permit_number' => (string) $permit['permit_number'],
                'signed_on' => $signedOn !== '' ? $signedOn : null,
                'replaced_previous_scan' => $previousPath !== '',
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'permit_status',
            'Signed permit available',
            'The signed copy of permit ' . (string) $permit['permit_number'] . ' for transaction '
                . (string) $application['transaction_id']
                . ' is now available to view and download from your application.',
            'permit_application',
            $applicationId
        );

        $pdo->commit();

        // Remove the superseded scan only after the row change is committed.
        if ($previousPath !== '' && $previousPath !== (string) $storage['relative_path']) {
            permit_signed_scan_unlink_relative($previousPath);
        }

        return [
            'application_id' => $applicationId,
            'permit_id' => (int) $permit['id'],
            'permit_number' => (string) $permit['permit_number'],
            'transaction_id' => (string) $application['transaction_id'],
            'replaced_previous_scan' => $previousPath !== '',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($storedPath !== null && is_file($storedPath)) {
            @unlink($storedPath);
        }
        throw $e;
    }
}

/** Best-effort removal of a superseded signed-permit scan by relative path. */
function permit_signed_scan_unlink_relative(string $relativePath): void
{
    try {
        $resolved = permit_document_resolve_path(['storage_path' => $relativePath]);
        @unlink($resolved);
    } catch (Throwable $e) {
        error_log('[CERTREEFY PERMIT SIGNED SCAN CLEANUP] ' . $e->getMessage());
    }
}

/**
 * Resolves the signed-permit scan download payload for any actor authorized to
 * view the application (RPS/permitted-Superadmin or the Community owner). Returns
 * null when the actor may not view the permit or no scan has been uploaded.
 */
function permit_signed_scan_download_payload(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    $permit = permit_release_record_for_actor($pdo, $applicationId, $actorUserId);
    if ($permit === null || (string) ($permit['permit_file_path'] ?? '') === '') {
        return null;
    }
    $document = [
        'storage_path' => (string) $permit['permit_file_path'],
        'original_filename' => (string) ($permit['permit_file_original_name'] ?? 'signed-permit'),
        'mime_type' => (string) ($permit['permit_file_mime_type'] ?? 'application/octet-stream'),
    ];
    $document['absolute_path'] = permit_document_resolve_path($document);

    return $document;
}

/** Resolves the responsible user for automated validity maintenance. */
function permit_release_system_actor(array $permit): int
{
    $releaser = (int) ($permit['released_by_user_id'] ?? 0);
    if ($releaser > 0) {
        return $releaser;
    }

    return (int) $permit['prepared_by_user_id'];
}

/**
 * Marks every active permit whose validity has lapsed as expired, records
 * history and audit, and notifies the applicant and RPS once. Idempotent and
 * safe to call from a scheduled task or opportunistically on page load.
 */
function permit_expire_due_permits(PDO $pdo): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Expiration processing must run outside an open transaction.');
    }
    $due = $pdo->query(
        'SELECT p.id AS permit_id, p.application_id, p.permit_number,
                p.valid_until, p.prepared_by_user_id, p.released_by_user_id,
                a.transaction_id, a.applicant_user_id
         FROM tbl_permits p
         INNER JOIN tbl_permit_applications a ON a.id = p.application_id
         WHERE a.validity_status = \'active\'
           AND p.valid_until IS NOT NULL
           AND p.valid_until < CURDATE()'
    )->fetchAll();

    $expiredIds = [];
    $rpsRecipients = $due === [] ? [] : permit_donation_rps_notification_recipients($pdo);
    foreach ($due as $permit) {
        $applicationId = (int) $permit['application_id'];
        $actorUserId = permit_release_system_actor($permit);
        try {
            $pdo->beginTransaction();
            $application = permit_load_application($pdo, $applicationId, true);
            if ($application === null || (string) $application['validity_status'] !== 'active') {
                $pdo->rollBack();
                continue;
            }
            permit_release_apply_status(
                $pdo,
                $applicationId,
                $actorUserId,
                'validity',
                'active',
                'expired',
                'Permit expired on ' . (string) $permit['valid_until']
                    . '. No extension or reactivation is permitted.'
            );
            $pdo->prepare(
                'UPDATE tbl_permits SET expired_notified_at = NOW() WHERE id = :id'
            )->execute([':id' => (int) $permit['permit_id']]);
            record_audit_event(
                $pdo,
                $actorUserId,
                'permit',
                'permit_expired',
                'permit',
                (int) $permit['permit_id'],
                'Permit reached its validity end date and expired.',
                [
                    'application_id' => $applicationId,
                    'transaction_id' => (string) $permit['transaction_id'],
                    'permit_number' => (string) $permit['permit_number'],
                    'valid_until' => (string) $permit['valid_until'],
                ]
            );
            $message = 'Permit ' . (string) $permit['permit_number'] . ' for transaction '
                . (string) $permit['transaction_id'] . ' expired on '
                . date('M j, Y', strtotime((string) $permit['valid_until']))
                . '. It cannot be extended or reactivated. If cutting was not completed, a new '
                . 'application and transaction ID are required.';
            create_notification(
                $pdo,
                (int) $permit['applicant_user_id'],
                $actorUserId,
                'permit_status',
                'Tree Cutting Permit expired',
                $message,
                'permit_application',
                $applicationId
            );
            if ($rpsRecipients !== []) {
                create_notifications_for_users(
                    $pdo,
                    $rpsRecipients,
                    $actorUserId,
                    'permit_status',
                    'Tree Cutting Permit expired',
                    $message,
                    'permit_application',
                    $applicationId
                );
            }
            $pdo->commit();
            $expiredIds[] = $applicationId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CERTREEFY PERMIT EXPIRATION ERROR] application ' . $applicationId . ': ' . $e->getMessage());
        }
    }

    return ['expired' => count($expiredIds), 'application_ids' => $expiredIds];
}

/**
 * Sends a single approaching-expiration reminder for each active permit within
 * the warning window. The expiry_warning_notified_at marker prevents duplicate
 * reminders.
 */
function permit_notify_expiring_permits(PDO $pdo): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Expiration reminders must run outside an open transaction.');
    }
    $warningDays = (int) PERMIT_VALIDITY_EXPIRY_WARNING_DAYS;
    $stmt = $pdo->prepare(
        'SELECT p.id AS permit_id, p.application_id, p.permit_number, p.valid_until,
                p.prepared_by_user_id, p.released_by_user_id,
                a.transaction_id, a.applicant_user_id
         FROM tbl_permits p
         INNER JOIN tbl_permit_applications a ON a.id = p.application_id
         WHERE a.validity_status = \'active\'
           AND p.expiry_warning_notified_at IS NULL
           AND p.valid_until IS NOT NULL
           AND p.valid_until >= CURDATE()
           AND p.valid_until <= DATE_ADD(CURDATE(), INTERVAL :warning_days DAY)'
    );
    $stmt->execute([':warning_days' => $warningDays]);
    $upcoming = $stmt->fetchAll();

    $notifiedIds = [];
    foreach ($upcoming as $permit) {
        $applicationId = (int) $permit['application_id'];
        $actorUserId = permit_release_system_actor($permit);
        try {
            $pdo->beginTransaction();
            $marker = $pdo->prepare(
                'UPDATE tbl_permits
                 SET expiry_warning_notified_at = NOW()
                 WHERE id = :id AND expiry_warning_notified_at IS NULL'
            );
            $marker->execute([':id' => (int) $permit['permit_id']]);
            if ($marker->rowCount() !== 1) {
                $pdo->rollBack();
                continue;
            }
            $daysLeft = max(0, (int) (new DateTimeImmutable('today'))
                ->diff(new DateTimeImmutable((string) $permit['valid_until']))->format('%a'));
            create_notification(
                $pdo,
                (int) $permit['applicant_user_id'],
                $actorUserId,
                'permit_status',
                'Tree Cutting Permit expiring soon',
                'Permit ' . (string) $permit['permit_number'] . ' for transaction '
                    . (string) $permit['transaction_id'] . ' expires on '
                    . date('M j, Y', strtotime((string) $permit['valid_until'])) . ' (' . $daysLeft
                    . ' day(s) left). No extension is possible; complete cutting before it lapses.',
                'permit_application',
                $applicationId
            );
            $pdo->commit();
            $notifiedIds[] = $applicationId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CERTREEFY PERMIT EXPIRY WARNING ERROR] application ' . $applicationId . ': ' . $e->getMessage());
        }
    }

    return ['notified' => count($notifiedIds), 'application_ids' => $notifiedIds];
}

/**
 * Runs both validity sweeps. Intended for a scheduled task and for a cheap,
 * throttled opportunistic call on privileged page loads so a permit becomes
 * recognized as expired without anyone manually opening its page.
 */
function permit_run_validity_maintenance(PDO $pdo): array
{
    $expired = permit_expire_due_permits($pdo);
    $warned = permit_notify_expiring_permits($pdo);

    return ['expired' => $expired, 'warned' => $warned];
}

/**
 * Records cutting completion for an active permit: stores the completion date,
 * verifying personnel, trees actually cut, status, and remarks, then closes the
 * validity as completed.
 */
/** Normalizes a possibly-multi-file $_FILES sub-array into a flat list, dropping empty slots. */
function permit_completion_normalize_evidence_files(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            ? []
            : [$files];
    }
    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $file = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
        if ((int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $normalized[] = $file;
        }
    }

    return array_values(array_filter($normalized, static fn ($file): bool => is_array($file)));
}

/** Validates optional completion-evidence photographs (JPEG/PNG, up to 10). */
function permit_completion_validate_evidence(array $files): array
{
    $normalized = permit_completion_normalize_evidence_files($files);
    if (count($normalized) > 10) {
        throw new PermitReleaseValidationException('No more than 10 evidence photographs may be attached per completion.');
    }
    $validated = [];
    foreach ($normalized as $file) {
        try {
            $validated[] = permit_document_validate_uploaded_file(
                $file,
                ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']],
                permit_document_max_bytes(),
                'JPG, JPEG, and PNG'
            );
        } catch (PermitDocumentValidationException $e) {
            throw new PermitReleaseValidationException($e->getMessage());
        }
    }

    return $validated;
}

function permit_record_cutting_completion(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    array $input,
    array $evidenceFiles = []
): array {
    if ($applicationId < 1) {
        throw new PermitReleaseValidationException('The permit application is invalid.');
    }
    $validatedEvidence = permit_completion_validate_evidence($evidenceFiles);
    $completionStatus = trim((string) ($input['completion_status'] ?? ''));
    if (!in_array($completionStatus, ['completed', 'partially_completed'], true)) {
        throw new PermitReleaseValidationException('Select whether cutting was completed or partially completed.');
    }
    $treesValue = trim((string) ($input['trees_cut_count'] ?? ''));
    if (!ctype_digit($treesValue)) {
        throw new PermitReleaseValidationException('Enter the number of trees actually cut.');
    }
    $treesCut = (int) $treesValue;
    $completedOn = trim((string) ($input['completed_on'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn) || strtotime($completedOn) === false) {
        throw new PermitReleaseValidationException('Enter a valid completion date.');
    }
    if (strtotime($completedOn) > strtotime('today')) {
        throw new PermitReleaseValidationException('The completion date cannot be in the future.');
    }
    $remarks = trim((string) ($input['remarks'] ?? ''));
    if (strlen($remarks) > 1000) {
        throw new PermitReleaseValidationException('Completion remarks must not exceed 1000 characters.');
    }
    if ($completionStatus === 'completed' && $treesCut < 1) {
        throw new PermitReleaseValidationException('A completed cutting must record at least one tree cut.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    $storedEvidencePaths = [];
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $actor = permit_release_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to record cutting completion.');
        }
        $application = permit_load_application($pdo, $applicationId, true);
        if ($application === null) {
            throw new RuntimeException('The permit application does not exist.');
        }
        if ((string) $application['validity_status'] !== 'active'
            || (string) $application['application_status'] !== 'released') {
            throw new RuntimeException('Cutting completion can be recorded only for an active released permit.');
        }
        $permitStmt = $pdo->prepare(
            'SELECT id, valid_from FROM tbl_permits WHERE application_id = :application_id LIMIT 1 FOR UPDATE'
        );
        $permitStmt->execute([':application_id' => $applicationId]);
        $permit = $permitStmt->fetch();
        if (!$permit) {
            throw new RuntimeException('No released permit record was found for this application.');
        }
        if ($permit['valid_from'] !== null && strtotime($completedOn) < strtotime((string) $permit['valid_from'])) {
            throw new PermitReleaseValidationException('The completion date cannot precede the permit effectivity date.');
        }

        // Verifying personnel: default to the actor; otherwise an active RPS/superadmin.
        $verifierValue = trim((string) ($input['verified_by_user_id'] ?? ''));
        $verifierId = $actorUserId;
        if ($verifierValue !== '') {
            if (!ctype_digit($verifierValue)) {
                throw new PermitReleaseValidationException('Select valid verifying personnel.');
            }
            $verifierId = (int) $verifierValue;
            $verifierStmt = $pdo->prepare(
                'SELECT 1 FROM tbl_users
                 WHERE id = :id AND status = \'active\' AND role IN (\'rps\', \'superadmin\') LIMIT 1'
            );
            $verifierStmt->execute([':id' => $verifierId]);
            if ($verifierStmt->fetchColumn() === false) {
                throw new PermitReleaseValidationException('The selected verifying personnel is not authorized.');
            }
        }

        // Bound trees cut by the approved tree count when available.
        $approvedStmt = $pdo->prepare(
            'SELECT approved_tree_count FROM tbl_permit_decisions
             WHERE application_id = :application_id AND decision = \'approved\'
             ORDER BY id DESC LIMIT 1'
        );
        $approvedStmt->execute([':application_id' => $applicationId]);
        $approvedTreeCount = $approvedStmt->fetchColumn();
        if ($approvedTreeCount !== false && $approvedTreeCount !== null
            && $treesCut > (int) $approvedTreeCount) {
            throw new PermitReleaseValidationException(
                'Trees cut cannot exceed the approved count of ' . (int) $approvedTreeCount . '.'
            );
        }

        $existing = $pdo->prepare(
            'SELECT id FROM tbl_permit_cutting_completions
             WHERE application_id = :application_id LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':application_id' => $applicationId]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException('Cutting completion has already been recorded for this permit.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_permit_cutting_completions
                (application_id, permit_id, completion_status, trees_cut_count,
                 completed_on, verified_by_user_id, recorded_by_user_id, remarks)
             VALUES
                (:application_id, :permit_id, :completion_status, :trees_cut_count,
                 :completed_on, :verified_by, :recorded_by, :remarks)'
        );
        $insert->execute([
            ':application_id' => $applicationId,
            ':permit_id' => (int) $permit['id'],
            ':completion_status' => $completionStatus,
            ':trees_cut_count' => $treesCut,
            ':completed_on' => $completedOn,
            ':verified_by' => $verifierId,
            ':recorded_by' => $actorUserId,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);
        $completionId = (int) $pdo->lastInsertId();

        $evidenceInsert = $pdo->prepare(
            'INSERT INTO tbl_permit_cutting_completion_evidence
                (application_id, completion_id, storage_path, original_filename,
                 mime_type, file_size_bytes, uploaded_by_user_id)
             VALUES
                (:application_id, :completion_id, :storage_path, :original_filename,
                 :mime_type, :file_size_bytes, :uploaded_by_user_id)'
        );
        foreach ($validatedEvidence as $evidence) {
            $storage = permit_document_relative_storage_path(
                (string) $application['transaction_id'],
                (string) $evidence['extension']
            );
            if (!move_uploaded_file((string) $evidence['tmp_name'], (string) $storage['absolute_path'])) {
                throw new RuntimeException('A completion evidence photograph could not be moved into private storage.');
            }
            @chmod((string) $storage['absolute_path'], 0600);
            $storedEvidencePaths[] = (string) $storage['absolute_path'];
            $evidenceInsert->execute([
                ':application_id' => $applicationId,
                ':completion_id' => $completionId,
                ':storage_path' => (string) $storage['relative_path'],
                ':original_filename' => (string) $evidence['original_filename'],
                ':mime_type' => (string) $evidence['mime_type'],
                ':file_size_bytes' => (int) $evidence['file_size_bytes'],
                ':uploaded_by_user_id' => $actorUserId,
            ]);
        }

        $pdo->prepare('UPDATE tbl_permits SET completed_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $permit['id']]);

        // Validity: active -> completed; Application: released -> completed.
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'validity',
            'active',
            'completed',
            'Cutting recorded as ' . $completionStatus . ' on ' . $completedOn
                . ' (' . $treesCut . ' tree(s) cut).'
        );
        permit_release_apply_status(
            $pdo,
            $applicationId,
            $actorUserId,
            'application',
            'released',
            'completed',
            'Tree cutting completed under the released permit.'
        );

        record_audit_event(
            $pdo,
            $actorUserId,
            'permit',
            'permit_cutting_completed',
            'permit',
            (int) $permit['id'],
            'Recorded Tree Cutting Permit cutting completion.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'completion_status' => $completionStatus,
                'trees_cut_count' => $treesCut,
                'completed_on' => $completedOn,
                'verified_by_user_id' => $verifierId,
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'permit_status',
            'Tree cutting completion recorded',
            'Cutting under permit for transaction ' . (string) $application['transaction_id']
                . ' was recorded as ' . str_replace('_', ' ', $completionStatus) . ' on '
                . date('M j, Y', strtotime($completedOn)) . ' (' . $treesCut
                . ' tree(s) cut). The permit transaction is now completed.',
            'permit_application',
            $applicationId
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'completion_id' => $completionId,
            'completion_status' => $completionStatus,
            'trees_cut_count' => $treesCut,
            'completed_on' => $completedOn,
            'evidence_count' => count($validatedEvidence),
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($storedEvidencePaths as $storedPath) {
            if (is_file($storedPath)) {
                @unlink($storedPath);
            }
        }
        throw $e;
    }
}

/** Read-only completion record for any actor authorized to view the application. */
function permit_cutting_completion_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    if (permit_decision_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.*, CONCAT(v.fname, \' \', v.lname) AS verified_by_name,
                CONCAT(r.fname, \' \', r.lname) AS recorded_by_name
         FROM tbl_permit_cutting_completions c
         INNER JOIN tbl_users v ON v.id = c.verified_by_user_id
         INNER JOIN tbl_users r ON r.id = c.recorded_by_user_id
         WHERE c.application_id = :application_id
         LIMIT 1'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $completion = $stmt->fetch();

    return $completion ?: null;
}

/** Read-only completion-evidence list for any actor authorized to view the application. */
function permit_cutting_completion_evidence_for_actor(PDO $pdo, int $applicationId, int $actorUserId): array
{
    if (permit_decision_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT e.id, e.application_id, e.completion_id, e.original_filename,
                e.mime_type, e.file_size_bytes, e.created_at,
                CONCAT(u.fname, \' \', u.lname) AS uploader_name
         FROM tbl_permit_cutting_completion_evidence e
         INNER JOIN tbl_users u ON u.id = e.uploaded_by_user_id
         WHERE e.application_id = :application_id
         ORDER BY e.id'
    );
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

/** Resolves a single completion-evidence photo for download by an authorized actor. */
function permit_cutting_completion_evidence_download_payload(PDO $pdo, int $evidenceId, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, application_id, storage_path, original_filename, mime_type
         FROM tbl_permit_cutting_completion_evidence
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $evidenceId]);
    $evidence = $stmt->fetch();
    if (!$evidence) {
        return null;
    }
    if (permit_decision_application_for_actor($pdo, (int) $evidence['application_id'], $actorUserId, 'view') === null) {
        return null;
    }
    $evidence['absolute_path'] = permit_document_resolve_path($evidence);

    return $evidence;
}
