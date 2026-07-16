<?php
/**
 * Secure scanned-document services for Tree Cutting Permit applications.
 * Uploaded scans support online review only and never replace original
 * hardcopy or wet-ink verification requirements.
 */

require_once __DIR__ . '/permit.php';
require_once __DIR__ . '/permissions.php';

class PermitDocumentValidationException extends InvalidArgumentException
{
}

function permit_document_type_catalog(): array
{
    // This is a configurable digital-intake baseline. The official hardcopy
    // checklist remains subject to confirmation by CENRO/RPS.
    return [
        'application_request' => [
            'label' => 'Application or request document',
            'description' => 'Signed request or application scan for online intake.',
            'required' => true,
        ],
        'applicant_identification' => [
            'label' => 'Applicant identification',
            'description' => 'Identification scan for the applicant or authorized representative.',
            'required' => true,
        ],
        'ownership_authorization' => [
            'label' => 'Property ownership or authorization',
            'description' => 'Ownership evidence or the property owner authorization scan.',
            'required' => true,
        ],
        'tree_location_photos' => [
            'label' => 'Tree and property location photographs',
            'description' => 'Photographs showing the subject trees and their property location.',
            'required' => true,
        ],
        'supporting_document' => [
            'label' => 'Additional supporting document',
            'description' => 'Optional supporting scan requested for the application.',
            'required' => false,
        ],
    ];
}

function permit_document_type(string $documentType): ?array
{
    $catalog = permit_document_type_catalog();

    return $catalog[$documentType] ?? null;
}

function permit_document_allowed_file_types(): array
{
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
}

function permit_document_accept_attribute(): string
{
    return '.pdf,.jpg,.jpeg,.png';
}

function permit_document_max_bytes(): int
{
    return defined('PERMIT_DOCUMENT_MAX_BYTES')
        ? (int) PERMIT_DOCUMENT_MAX_BYTES
        : 10 * 1024 * 1024;
}

function permit_document_max_size_label(): string
{
    return number_format(permit_document_max_bytes() / 1024 / 1024, 0) . ' MB';
}

function permit_document_review_statuses(): array
{
    return ['accepted', 'rejected', 'replacement_required'];
}

function permit_original_review_statuses(): array
{
    return ['pending', 'verified', 'rejected', 'replacement_required'];
}

function permit_original_review_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending original verification',
        'verified' => 'Original verified',
        'rejected' => 'Original rejected',
        'replacement_required' => 'Replacement required',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function permit_original_review_status_badge(string $status): string
{
    return match ($status) {
        'verified' => 'text-bg-success',
        'rejected', 'replacement_required' => 'text-bg-danger',
        'pending' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
}

function permit_document_status_label(string $status, bool $isCurrent = true): string
{
    if (!$isCurrent) {
        return 'Archived - ' . ucwords(str_replace('_', ' ', $status));
    }

    return match ($status) {
        'pending' => 'Pending review',
        'accepted' => 'Accepted online scan',
        'rejected' => 'Rejected',
        'replacement_required' => 'Replacement required',
        'archived' => 'Archived',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function permit_document_status_badge(string $status, bool $isCurrent = true): string
{
    if (!$isCurrent) {
        return 'text-bg-secondary';
    }

    return match ($status) {
        'accepted' => 'text-bg-success',
        'rejected', 'replacement_required' => 'text-bg-danger',
        'pending' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
}

function permit_document_storage_root(): string
{
    $configured = defined('PERMIT_DOCUMENT_STORAGE_ROOT')
        ? trim((string) PERMIT_DOCUMENT_STORAGE_ROOT)
        : '';
    if ($configured === '') {
        throw new RuntimeException('Private permit document storage is not configured.');
    }

    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Private permit document storage is unavailable.');
    }
    $root = realpath($configured);
    if ($root === false || !is_writable($root)) {
        throw new RuntimeException('Private permit document storage is unavailable.');
    }

    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $publicRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($publicRoot !== false && permit_document_path_is_within($root, $publicRoot)) {
        throw new RuntimeException('Permit document storage must be outside the public web root.');
    }

    return $root;
}

function permit_document_path_is_within(string $path, string $root): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    $candidate = $path . DIRECTORY_SEPARATOR;
    $prefix = $root . DIRECTORY_SEPARATOR;

    return DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($candidate, $prefix, strlen($prefix)) === 0
        : strncmp($candidate, $prefix, strlen($prefix)) === 0;
}

function permit_document_normalize_original_filename(string $filename): string
{
    $filename = str_replace('\\', '/', $filename);
    $filename = basename($filename);
    $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename));

    if ($filename === '' || strlen($filename) > 255) {
        throw new PermitDocumentValidationException('The original filename is invalid or too long.');
    }

    return $filename;
}

function permit_document_validate_uploaded_file(
    array $file,
    ?array $allowedTypes = null,
    ?int $maximumBytes = null,
    string $allowedTypeLabel = 'PDF, JPG, JPEG, and PNG'
): array
{
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Select a file to upload.',
            default => 'The file could not be uploaded.',
        };
        throw new PermitDocumentValidationException($message);
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new PermitDocumentValidationException('The uploaded file could not be verified.');
    }

    $originalFilename = permit_document_normalize_original_filename((string) ($file['name'] ?? ''));
    $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
    $allowedTypes = $allowedTypes ?? permit_document_allowed_file_types();
    if (!isset($allowedTypes[$extension])) {
        throw new PermitDocumentValidationException('Only ' . $allowedTypeLabel . ' files are allowed.');
    }

    $actualSize = filesize($temporaryPath);
    if ($actualSize === false || $actualSize < 1) {
        throw new PermitDocumentValidationException('The selected file is empty or unreadable.');
    }
    $maximumBytes = $maximumBytes ?? permit_document_max_bytes();
    if ($actualSize > $maximumBytes) {
        throw new PermitDocumentValidationException(
            'The selected file exceeds the '
                . number_format($maximumBytes / 1024 / 1024, 0) . ' MB size limit.'
        );
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('Server-side MIME validation is unavailable.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($temporaryPath);
    if (!in_array($mimeType, $allowedTypes[$extension], true)) {
        throw new PermitDocumentValidationException('The file content does not match its extension.');
    }

    if ($mimeType === 'application/pdf') {
        $contents = file_get_contents($temporaryPath);
        if ($contents === false
            || !str_starts_with($contents, '%PDF-')
            || !str_contains(substr($contents, -2048), '%%EOF')) {
            throw new PermitDocumentValidationException('The PDF content is invalid.');
        }
        if (preg_match('/\/(?:JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b/i', $contents)) {
            throw new PermitDocumentValidationException('PDFs with active or embedded content are not allowed.');
        }
    } else {
        $imageInfo = @getimagesize($temporaryPath);
        $expectedImageType = $mimeType === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if ($imageInfo === false || (int) ($imageInfo[2] ?? 0) !== $expectedImageType) {
            throw new PermitDocumentValidationException('The image content is invalid.');
        }
    }

    return [
        'tmp_name' => $temporaryPath,
        'original_filename' => $originalFilename,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'file_size_bytes' => (int) $actualSize,
    ];
}

function permit_document_load_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT id, role, status
         FROM tbl_users
         WHERE id = :id
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' ? $actor : null;
}

function permit_original_verification_actor(
    PDO $pdo,
    int $actorUserId,
    bool $forUpdate = false
): ?array {
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
            certreefy_permission_original_document_verification(),
            $forUpdate
        )) {
        return $actor;
    }

    return null;
}

function permit_original_receiving_personnel(PDO $pdo): array
{
    $permission = certreefy_permission_original_document_verification();
    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.id, u.fname, u.lname, u.role
         FROM tbl_users u
         LEFT JOIN tbl_user_permissions p
                ON p.user_id = u.id
               AND p.permission_key = :permission_key
               AND p.is_active = 1
               AND p.revoked_at IS NULL
         WHERE u.status = \'active\'
           AND (u.role = \'rps\' OR (u.role = \'superadmin\' AND p.id IS NOT NULL))
         ORDER BY u.lname, u.fname, u.id'
    );
    $stmt->execute([':permission_key' => $permission]);

    return $stmt->fetchAll();
}

function permit_document_upload_lock_reason(array $application): ?string
{
    if ($application['transaction_id'] === null || (string) $application['application_status'] === 'draft') {
        return 'Documents may be uploaded only after final application submission.';
    }
    if (!in_array((string) $application['application_status'], ['submitted', 'under_review', 'awaiting_documents'], true)) {
        return 'This permit transaction is locked for document uploads.';
    }
    if (in_array((string) $application['decision_status'], ['approved', 'declined'], true)
        || (string) $application['release_status'] === 'released'
        || in_array((string) $application['validity_status'], ['completed', 'expired', 'closed'], true)) {
        return 'This permit transaction is locked for document uploads.';
    }

    return null;
}

function permit_document_review_lock_reason(array $application): ?string
{
    if ($application['transaction_id'] === null || (string) $application['application_status'] === 'draft') {
        return 'Unsubmitted applications cannot be reviewed.';
    }
    if (!in_array(
        (string) $application['application_status'],
        ['submitted', 'under_review', 'awaiting_documents', 'awaiting_inspection', 'awaiting_decision'],
        true
    )) {
        return 'This permit transaction is locked for document review.';
    }
    if (in_array((string) $application['decision_status'], ['approved', 'declined'], true)
        || (string) $application['release_status'] === 'released'
        || in_array((string) $application['validity_status'], ['completed', 'expired', 'closed'], true)) {
        return 'This permit transaction is locked for document review.';
    }

    return null;
}

function permit_document_application_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $operation,
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

    $role = (string) $actor['role'];
    if ($operation === 'upload') {
        return $role === 'community'
            && (int) $application['applicant_user_id'] === $actorUserId
            && permit_document_upload_lock_reason($application) === null
            ? $application
            : null;
    }
    if ($operation === 'review') {
        return $role === 'rps' && permit_document_review_lock_reason($application) === null
            ? $application
            : null;
    }
    if ($operation === 'original_verify') {
        return permit_original_verification_actor($pdo, $actorUserId, $forUpdate) !== null
            && permit_document_review_lock_reason($application) === null
            ? $application
            : null;
    }
    if ($operation === 'view') {
        if ($role === 'community') {
            return (int) $application['applicant_user_id'] === $actorUserId ? $application : null;
        }
        if ($role === 'rps') {
            return $application['transaction_id'] !== null
                && (string) $application['application_status'] !== 'draft'
                ? $application
                : null;
        }
        if ($role === 'superadmin'
            && (permit_original_verification_actor($pdo, $actorUserId, $forUpdate) !== null
                || user_has_active_permission(
                    $pdo,
                    $actorUserId,
                    certreefy_permission_permit_decision(),
                    $forUpdate
                ))) {
            return $application['transaction_id'] !== null
                && (string) $application['application_status'] !== 'draft'
                ? $application
                : null;
        }
    }

    return null;
}

function permit_documents_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    bool $includeHistory = true
): ?array {
    if (permit_document_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }

    $sql =
        'SELECT d.id, d.application_id, d.document_type, d.storage_path,
                d.original_filename, d.mime_type, d.file_size_bytes,
                d.uploaded_by_user_id, d.replaces_document_id, d.is_current,
                d.verification_status, d.verified_by_user_id, d.verified_at,
                d.verification_notes, d.archived_at, d.created_at,
                CONCAT(u.fname, \' \', u.lname) AS uploader_name,
                CASE WHEN v.id IS NULL THEN NULL ELSE CONCAT(v.fname, \' \', v.lname) END AS reviewer_name
         FROM tbl_permit_documents d
         INNER JOIN tbl_users u ON u.id = d.uploaded_by_user_id
         LEFT JOIN tbl_users v ON v.id = d.verified_by_user_id
         WHERE d.application_id = :application_id';
    if (!$includeHistory) {
        $sql .= ' AND d.is_current = 1';
    }
    $sql .= ' ORDER BY d.document_type, d.is_current DESC, d.created_at DESC, d.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

function permit_original_reviews_for_application(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.application_id, r.document_id, r.document_type,
                r.review_status, r.previous_review_id, r.original_received,
                r.original_received_on, r.received_by_user_id,
                r.wet_ink_required, r.wet_ink_verified,
                r.scan_compared_with_original, r.reviewed_by_user_id,
                r.review_notes, r.reviewed_at,
                d.original_filename AS compared_scan_filename,
                d.is_current AS compared_scan_is_current,
                CONCAT(v.fname, \' \', v.lname) AS verifier_name,
                CASE WHEN receiver.id IS NULL THEN NULL
                     ELSE CONCAT(receiver.fname, \' \', receiver.lname) END AS receiver_name
         FROM tbl_permit_document_reviews r
         INNER JOIN tbl_users v ON v.id = r.reviewed_by_user_id
         LEFT JOIN tbl_users receiver ON receiver.id = r.received_by_user_id
         LEFT JOIN tbl_permit_documents d
                ON d.application_id = r.application_id AND d.id = r.document_id
         WHERE r.application_id = :application_id
           AND r.review_scope = \'original\'
         ORDER BY r.document_type, r.reviewed_at DESC, r.id DESC'
    );
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

function permit_original_reviews_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId
): ?array {
    if (permit_document_application_for_actor($pdo, $applicationId, $actorUserId, 'view') === null) {
        return null;
    }

    return permit_original_reviews_for_application($pdo, $applicationId);
}

function permit_latest_original_reviews_by_type(array $reviews): array
{
    $latest = [];
    foreach ($reviews as $review) {
        $type = (string) ($review['document_type'] ?? '');
        if ($type !== '' && !isset($latest[$type])) {
            $latest[$type] = $review;
        }
    }

    return $latest;
}

function permit_latest_original_review(
    PDO $pdo,
    int $applicationId,
    string $documentType,
    bool $forUpdate = false
): ?array {
    $sql =
        'SELECT id, application_id, document_id, document_type, review_status,
                previous_review_id, original_received, original_received_on,
                received_by_user_id, wet_ink_required, wet_ink_verified,
                scan_compared_with_original, reviewed_by_user_id, review_notes,
                reviewed_at
         FROM tbl_permit_document_reviews
         WHERE application_id = :application_id
           AND review_scope = \'original\'
           AND document_type = :document_type
         ORDER BY reviewed_at DESC, id DESC
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':application_id' => $applicationId,
        ':document_type' => $documentType,
    ]);
    $review = $stmt->fetch();

    return $review ?: null;
}

function permit_original_review_matches_document(?array $review, ?array $document): bool
{
    return $review !== null
        && $document !== null
        && (int) ($review['document_id'] ?? 0) === (int) ($document['id'] ?? 0);
}

function permit_original_scan_replacement_requested(?array $review, ?array $document): bool
{
    return permit_original_review_matches_document($review, $document)
        && (string) ($review['review_status'] ?? '') === 'replacement_required';
}

function permit_original_required_progress(
    array $catalog,
    array $currentDocuments,
    array $latestOriginalReviews
): array {
    $required = 0;
    $verified = 0;
    foreach ($catalog as $type => $definition) {
        if (empty($definition['required'])) {
            continue;
        }
        $required++;
        $document = $currentDocuments[$type] ?? null;
        $review = $latestOriginalReviews[$type] ?? null;
        if (permit_original_review_matches_document($review, $document)
            && (string) ($review['review_status'] ?? '') === 'verified') {
            $verified++;
        }
    }

    return [
        'required' => $required,
        'verified' => $verified,
        'percent' => $required > 0 ? (int) round(($verified / $required) * 100) : 0,
        'complete' => $required > 0 && $verified === $required,
    ];
}

function permit_current_documents_by_type(array $documents): array
{
    $current = [];
    foreach ($documents as $document) {
        if ((int) $document['is_current'] === 1) {
            $current[(string) $document['document_type']] = $document;
        }
    }

    return $current;
}

function permit_document_for_actor(PDO $pdo, int $documentId, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT d.id, d.application_id, d.document_type, d.storage_path,
                d.original_filename, d.mime_type, d.file_size_bytes,
                d.uploaded_by_user_id, d.replaces_document_id, d.is_current,
                d.verification_status, d.created_at, a.transaction_id,
                a.applicant_user_id, a.application_status
         FROM tbl_permit_documents d
         INNER JOIN tbl_permit_applications a ON a.id = d.application_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch();
    if (!$document) {
        return null;
    }

    return permit_document_application_for_actor(
        $pdo,
        (int) $document['application_id'],
        $actorUserId,
        'view'
    ) !== null ? $document : null;
}

function permit_document_relative_storage_path(string $transactionId, string $extension): array
{
    if (!preg_match('/^TCP-\d{4}-\d{6}$/', $transactionId)) {
        throw new RuntimeException('The permit transaction ID is invalid for document storage.');
    }
    $root = permit_document_storage_root();
    $relativeDirectory = date('Y') . '/' . $transactionId;
    $directory = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The permit document directory could not be created.');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $filename = bin2hex(random_bytes(32)) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($absolutePath)) {
            return [
                'relative_path' => $relativeDirectory . '/' . $filename,
                'absolute_path' => $absolutePath,
            ];
        }
    }

    throw new RuntimeException('A collision-free permit document filename could not be generated.');
}

function permit_document_transition_summary(
    PDO $pdo,
    array &$application,
    int $actorUserId,
    string $targetStatus,
    string $remarks
): void {
    $currentStatus = (string) $application['document_status'];
    if ($currentStatus === $targetStatus) {
        return;
    }

    $paths = [
        'pending:under_review' => ['under_review'],
        'pending:incomplete' => ['under_review', 'incomplete'],
        'pending:online_verified' => ['under_review', 'online_verified'],
        'pending:verified' => ['under_review', 'online_verified', 'originals_verified', 'verified'],
        'under_review:incomplete' => ['incomplete'],
        'under_review:online_verified' => ['online_verified'],
        'under_review:verified' => ['online_verified', 'originals_verified', 'verified'],
        'incomplete:under_review' => ['under_review'],
        'incomplete:online_verified' => ['under_review', 'online_verified'],
        'incomplete:verified' => ['under_review', 'online_verified', 'originals_verified', 'verified'],
        'online_verified:incomplete' => ['incomplete'],
        'online_verified:verified' => ['originals_verified', 'verified'],
        'originals_verified:incomplete' => ['incomplete'],
        'originals_verified:online_verified' => ['online_verified'],
        'originals_verified:verified' => ['verified'],
        'verified:under_review' => ['under_review'],
        'verified:online_verified' => ['under_review', 'online_verified'],
        'verified:incomplete' => ['under_review', 'incomplete'],
    ];
    $steps = $paths[$currentStatus . ':' . $targetStatus] ?? null;
    if ($steps === null) {
        throw new RuntimeException('The document summary status cannot move to the requested state.');
    }

    foreach ($steps as $nextStatus) {
        if (!permit_status_transition_is_allowed('document', $currentStatus, $nextStatus)) {
            throw new RuntimeException('The document summary status transition is not allowed.');
        }
        $update = $pdo->prepare(
            'UPDATE tbl_permit_applications
             SET document_status = :new_status
             WHERE id = :application_id AND document_status = :previous_status'
        );
        $update->execute([
            ':new_status' => $nextStatus,
            ':application_id' => (int) $application['id'],
            ':previous_status' => $currentStatus,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The document summary changed before the update completed.');
        }
        permit_record_status_history(
            $pdo,
            (int) $application['id'],
            $actorUserId,
            'document',
            $currentStatus,
            $nextStatus,
            $remarks
        );
        $currentStatus = $nextStatus;
    }
    $application['document_status'] = $targetStatus;
}

function upload_permit_document(
    PDO $pdo,
    int $applicationId,
    int $uploaderUserId,
    string $documentType,
    array $file
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Permit document uploading must own its database transaction.');
    }
    $documentType = trim($documentType);
    if (permit_document_type($documentType) === null) {
        throw new PermitDocumentValidationException('The selected document type is invalid.');
    }
    if (permit_document_application_for_actor($pdo, $applicationId, $uploaderUserId, 'upload') === null) {
        throw new RuntimeException('This permit application is not eligible for document uploads.');
    }
    $validatedFile = permit_document_validate_uploaded_file($file);
    $storedPath = null;

    try {
        $pdo->beginTransaction();
        $application = permit_document_application_for_actor(
            $pdo,
            $applicationId,
            $uploaderUserId,
            'upload',
            true
        );
        if ($application === null) {
            throw new RuntimeException('This permit application is not eligible for document uploads.');
        }

        $currentStmt = $pdo->prepare(
            'SELECT id, verification_status
             FROM tbl_permit_documents
             WHERE application_id = :application_id
               AND document_type = :document_type
               AND is_current = 1
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $currentStmt->execute([
            ':application_id' => $applicationId,
            ':document_type' => $documentType,
        ]);
        $currentDocument = $currentStmt->fetch();
        if ($currentDocument && (string) $currentDocument['verification_status'] === 'accepted') {
            $latestOriginalReview = permit_latest_original_review(
                $pdo,
                $applicationId,
                $documentType,
                true
            );
            $originalReplacementRequested = permit_original_scan_replacement_requested(
                $latestOriginalReview,
                $currentDocument
            );
            if (!$originalReplacementRequested) {
                throw new RuntimeException('An accepted online scan cannot be replaced from the Community portal.');
            }
        }

        $storage = permit_document_relative_storage_path(
            (string) $application['transaction_id'],
            (string) $validatedFile['extension']
        );
        $storedPath = (string) $storage['absolute_path'];
        if (!move_uploaded_file((string) $validatedFile['tmp_name'], $storedPath)) {
            throw new RuntimeException('The uploaded file could not be moved into private storage.');
        }
        @chmod($storedPath, 0600);

        if ($currentDocument) {
            $archive = $pdo->prepare(
                'UPDATE tbl_permit_documents
                 SET is_current = 0, archived_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND application_id = :application_id AND is_current = 1'
            );
            $archive->execute([
                ':id' => (int) $currentDocument['id'],
                ':application_id' => $applicationId,
            ]);
            if ($archive->rowCount() !== 1) {
                throw new RuntimeException('The existing document changed before replacement completed.');
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_permit_documents
                (application_id, document_type, storage_path, original_filename,
                 mime_type, file_size_bytes, uploaded_by_user_id,
                 replaces_document_id, is_current, verification_status)
             VALUES
                (:application_id, :document_type, :storage_path, :original_filename,
                 :mime_type, :file_size_bytes, :uploaded_by_user_id,
                 :replaces_document_id, 1, \'pending\')'
        );
        $insert->execute([
            ':application_id' => $applicationId,
            ':document_type' => $documentType,
            ':storage_path' => (string) $storage['relative_path'],
            ':original_filename' => (string) $validatedFile['original_filename'],
            ':mime_type' => (string) $validatedFile['mime_type'],
            ':file_size_bytes' => (int) $validatedFile['file_size_bytes'],
            ':uploaded_by_user_id' => $uploaderUserId,
            ':replaces_document_id' => $currentDocument ? (int) $currentDocument['id'] : null,
        ]);
        $documentId = (int) $pdo->lastInsertId();

        if ((string) $application['document_status'] === 'incomplete') {
            permit_document_transition_summary(
                $pdo,
                $application,
                $uploaderUserId,
                'under_review',
                'A requested replacement scan was uploaded.'
            );
        }

        record_audit_event(
            $pdo,
            $uploaderUserId,
            'permit',
            $currentDocument ? 'document_replaced' : 'document_uploaded',
            'permit_document',
            $documentId,
            $currentDocument ? 'Replaced a permit document scan.' : 'Uploaded a permit document scan.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'document_type' => $documentType,
                'replaces_document_id' => $currentDocument ? (int) $currentDocument['id'] : null,
            ]
        );

        $pdo->commit();

        return [
            'document_id' => $documentId,
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'replaced_document_id' => $currentDocument ? (int) $currentDocument['id'] : null,
            'status' => 'pending',
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

function permit_document_summary_target(PDO $pdo, int $applicationId): string
{
    $stmt = $pdo->prepare(
        'SELECT id, document_type, verification_status
         FROM tbl_permit_documents
         WHERE application_id = :application_id AND is_current = 1'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $statuses = [];
    $documentIds = [];
    foreach ($stmt->fetchAll() as $document) {
        $statuses[(string) $document['document_type']] = (string) $document['verification_status'];
        $documentIds[(string) $document['document_type']] = (int) $document['id'];
    }

    if (array_intersect($statuses, ['rejected', 'replacement_required']) !== []) {
        return 'incomplete';
    }

    foreach (permit_document_type_catalog() as $type => $definition) {
        if (!empty($definition['required']) && ($statuses[$type] ?? null) !== 'accepted') {
            return 'under_review';
        }
    }

    $latestOriginals = permit_latest_original_reviews_by_type(
        permit_original_reviews_for_application($pdo, $applicationId)
    );
    $allRequiredOriginalsVerified = true;
    foreach (permit_document_type_catalog() as $type => $definition) {
        if (empty($definition['required'])) {
            continue;
        }
        $original = $latestOriginals[$type] ?? null;
        $matchesCurrentScan = $original !== null
            && (int) ($original['document_id'] ?? 0) === ($documentIds[$type] ?? 0);
        if ($matchesCurrentScan
            && in_array((string) $original['review_status'], ['rejected', 'replacement_required'], true)) {
            return 'incomplete';
        }
        if (!$matchesCurrentScan || (string) $original['review_status'] !== 'verified') {
            $allRequiredOriginalsVerified = false;
        }
    }

    return $allRequiredOriginalsVerified ? 'verified' : 'online_verified';
}

function review_permit_document(
    PDO $pdo,
    int $documentId,
    int $reviewerUserId,
    string $reviewStatus,
    ?string $reviewNotes = null
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Permit document review must own its database transaction.');
    }
    $reviewStatus = trim($reviewStatus);
    $reviewNotes = $reviewNotes === null ? null : trim($reviewNotes);
    if (!in_array($reviewStatus, permit_document_review_statuses(), true)) {
        throw new PermitDocumentValidationException('The selected document review status is invalid.');
    }
    if ($reviewNotes === '') {
        $reviewNotes = null;
    }
    if ($reviewNotes !== null && strlen($reviewNotes) > 1000) {
        throw new PermitDocumentValidationException('Review notes must not exceed 1000 characters.');
    }
    if (in_array($reviewStatus, ['rejected', 'replacement_required'], true) && $reviewNotes === null) {
        throw new PermitDocumentValidationException('Review notes are required when rejecting or requesting replacement.');
    }

    try {
        $pdo->beginTransaction();
        $documentStmt = $pdo->prepare(
            'SELECT id, application_id, document_type, original_filename,
                    verification_status, is_current
             FROM tbl_permit_documents
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $documentStmt->execute([':id' => $documentId]);
        $document = $documentStmt->fetch();
        if (!$document || (int) $document['is_current'] !== 1) {
            throw new RuntimeException('Only a current permit document may be reviewed.');
        }

        $application = permit_document_application_for_actor(
            $pdo,
            (int) $document['application_id'],
            $reviewerUserId,
            'review',
            true
        );
        if ($application === null) {
            throw new RuntimeException('This permit document is not eligible for review.');
        }
        $previousStatus = (string) $document['verification_status'];
        if ($previousStatus === $reviewStatus) {
            throw new PermitDocumentValidationException('The document already has the selected review status.');
        }

        $update = $pdo->prepare(
            'UPDATE tbl_permit_documents
             SET verification_status = :verification_status,
                 verified_by_user_id = :verified_by_user_id,
                 verified_at = CURRENT_TIMESTAMP,
                 verification_notes = :verification_notes
             WHERE id = :id AND is_current = 1'
        );
        $update->execute([
            ':verification_status' => $reviewStatus,
            ':verified_by_user_id' => $reviewerUserId,
            ':verification_notes' => $reviewNotes,
            ':id' => $documentId,
        ]);

        $insertReview = $pdo->prepare(
            'INSERT INTO tbl_permit_document_reviews
                (application_id, document_id, document_type, review_scope, review_status,
                 reviewed_by_user_id, review_notes)
             VALUES
                (:application_id, :document_id, :document_type, \'online\', :review_status,
                 :reviewed_by_user_id, :review_notes)'
        );
        $insertReview->execute([
            ':application_id' => (int) $document['application_id'],
            ':document_id' => $documentId,
            ':document_type' => (string) $document['document_type'],
            ':review_status' => $reviewStatus,
            ':reviewed_by_user_id' => $reviewerUserId,
            ':review_notes' => $reviewNotes,
        ]);

        $summaryTarget = permit_document_summary_target($pdo, (int) $document['application_id']);
        permit_document_transition_summary(
            $pdo,
            $application,
            $reviewerUserId,
            $summaryTarget,
            'Online scanned-document review updated.'
        );

        record_audit_event(
            $pdo,
            $reviewerUserId,
            'verification',
            'permit_document_' . $reviewStatus,
            'permit_document',
            $documentId,
            'Reviewed a scanned permit document.',
            [
                'application_id' => (int) $document['application_id'],
                'transaction_id' => (string) $application['transaction_id'],
                'document_type' => (string) $document['document_type'],
                'previous_status' => $previousStatus,
                'new_status' => $reviewStatus,
                'review_scope' => 'online',
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $reviewerUserId,
            'permit_status',
            'Permit document review updated',
            'The online scan for ' . (permit_document_type((string) $document['document_type'])['label'] ?? 'a permit document')
                . ' in application ' . $application['transaction_id'] . ' is now '
                . strtolower(permit_document_status_label($reviewStatus)) . '.',
            'permit_application',
            (int) $document['application_id']
        );

        $pdo->commit();

        return [
            'document_id' => $documentId,
            'application_id' => (int) $document['application_id'],
            'transaction_id' => (string) $application['transaction_id'],
            'previous_status' => $previousStatus,
            'review_status' => $reviewStatus,
            'document_status' => $summaryTarget,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function permit_original_boolean_input(array $data, string $key, string $label): bool
{
    $value = strtolower(trim((string) ($data[$key] ?? '')));
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new PermitDocumentValidationException('Select yes or no for ' . $label . '.');
}

function record_original_document_verification(
    PDO $pdo,
    int $applicationId,
    int $verifierUserId,
    string $documentType,
    array $data
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Original-document verification must own its database transaction.');
    }
    $documentType = trim($documentType);
    $definition = permit_document_type($documentType);
    if ($definition === null) {
        throw new PermitDocumentValidationException('The selected document type is invalid.');
    }

    $originalReceived = permit_original_boolean_input($data, 'original_received', 'original hardcopy received');
    $wetInkRequired = permit_original_boolean_input($data, 'wet_ink_required', 'wet-ink signature required');
    $wetInkVerified = permit_original_boolean_input($data, 'wet_ink_verified', 'wet-ink signature verified');
    $scanCompared = permit_original_boolean_input($data, 'scan_compared_with_original', 'scan comparison');
    $reviewStatus = trim((string) ($data['review_status'] ?? ''));
    $reviewNotes = trim((string) ($data['review_notes'] ?? ''));
    $receivedOn = trim((string) ($data['original_received_on'] ?? ''));
    $receiverValue = trim((string) ($data['received_by_user_id'] ?? ''));
    $expectedDocumentValue = trim((string) ($data['expected_document_id'] ?? ''));
    if ($expectedDocumentValue !== '' && (!ctype_digit($expectedDocumentValue) || (int) $expectedDocumentValue < 1)) {
        throw new PermitDocumentValidationException('The expected current scan reference is invalid.');
    }
    $expectedDocumentId = $expectedDocumentValue === '' ? null : (int) $expectedDocumentValue;

    if (!in_array($reviewStatus, permit_original_review_statuses(), true)) {
        throw new PermitDocumentValidationException('The selected original verification result is invalid.');
    }
    if (strlen($reviewNotes) > 1000) {
        throw new PermitDocumentValidationException('Verification remarks must not exceed 1000 characters.');
    }
    if ($scanCompared && !$originalReceived) {
        throw new PermitDocumentValidationException('A scan cannot be compared before the original hardcopy is received.');
    }
    if ($wetInkVerified && (!$wetInkRequired || !$originalReceived)) {
        throw new PermitDocumentValidationException('Wet-ink verification requires a received original and a required wet-ink signature.');
    }
    if (!$wetInkRequired) {
        $wetInkVerified = false;
    }

    $receivedByUserId = null;
    if ($originalReceived) {
        if ($receivedOn === '') {
            throw new PermitDocumentValidationException('The original hardcopy receipt date is required.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $receivedOn);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $receivedOn) {
            throw new PermitDocumentValidationException('The original hardcopy receipt date is invalid.');
        }
        if ($date > new DateTimeImmutable('today')) {
            throw new PermitDocumentValidationException('The original hardcopy receipt date cannot be in the future.');
        }
        if (!ctype_digit($receiverValue) || (int) $receiverValue < 1) {
            throw new PermitDocumentValidationException('Select the personnel who received the original hardcopy.');
        }
        $receivedByUserId = (int) $receiverValue;
    } else {
        if ($receivedOn !== '' || $receiverValue !== '') {
            throw new PermitDocumentValidationException('Receipt date and receiving personnel must be blank when no original was received.');
        }
        $receivedOn = null;
        $wetInkVerified = false;
        $scanCompared = false;
    }

    if ($reviewStatus === 'verified'
        && (!$originalReceived || ($wetInkRequired && !$wetInkVerified) || !$scanCompared)) {
        throw new PermitDocumentValidationException(
            'A verified result requires the original hardcopy, any required wet-ink signature, and scan comparison.'
        );
    }
    if ($reviewStatus === 'rejected' && !$originalReceived) {
        throw new PermitDocumentValidationException('An original hardcopy must be received before it can be rejected.');
    }
    if ((in_array($reviewStatus, ['rejected', 'replacement_required'], true)
            || !$originalReceived
            || ($wetInkRequired && !$wetInkVerified))
        && $reviewNotes === '') {
        throw new PermitDocumentValidationException('Verification remarks are required when applicant action is needed.');
    }
    if ($reviewNotes === '') {
        $reviewNotes = null;
    }

    try {
        $pdo->beginTransaction();
        $application = permit_document_application_for_actor(
            $pdo,
            $applicationId,
            $verifierUserId,
            'original_verify',
            true
        );
        if ($application === null) {
            throw new RuntimeException('This permit application is not eligible for original-document verification.');
        }
        if ($receivedByUserId !== null
            && permit_original_verification_actor($pdo, $receivedByUserId) === null) {
            throw new PermitDocumentValidationException('The selected receiving personnel is not authorized or active.');
        }

        $documentStmt = $pdo->prepare(
            'SELECT id, verification_status
             FROM tbl_permit_documents
             WHERE application_id = :application_id
               AND document_type = :document_type
               AND is_current = 1
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $documentStmt->execute([
            ':application_id' => $applicationId,
            ':document_type' => $documentType,
        ]);
        $currentDocument = $documentStmt->fetch();
        $currentDocumentId = $currentDocument ? (int) $currentDocument['id'] : null;
        if ($currentDocumentId !== $expectedDocumentId) {
            throw new PermitDocumentValidationException(
                'The current scan changed after this page was loaded. Review the latest scan and try again.'
            );
        }
        if ($scanCompared && !$currentDocument) {
            throw new PermitDocumentValidationException('Upload a current scan before recording scan comparison.');
        }
        if ($reviewStatus === 'verified'
            && (!$currentDocument || (string) $currentDocument['verification_status'] !== 'accepted')) {
            throw new PermitDocumentValidationException(
                'The current scan must pass online review before its original can be marked verified.'
            );
        }

        $previousReview = permit_latest_original_review($pdo, $applicationId, $documentType, true);
        $documentId = $currentDocumentId;
        if ($previousReview !== null
            && (int) ($previousReview['document_id'] ?? 0) === (int) ($documentId ?? 0)
            && (string) $previousReview['review_status'] === $reviewStatus
            && (int) $previousReview['original_received'] === (int) $originalReceived
            && (string) ($previousReview['original_received_on'] ?? '') === (string) ($receivedOn ?? '')
            && (int) ($previousReview['received_by_user_id'] ?? 0) === (int) ($receivedByUserId ?? 0)
            && (int) $previousReview['wet_ink_required'] === (int) $wetInkRequired
            && (int) $previousReview['wet_ink_verified'] === (int) $wetInkVerified
            && (int) $previousReview['scan_compared_with_original'] === (int) $scanCompared
            && (string) ($previousReview['review_notes'] ?? '') === (string) ($reviewNotes ?? '')) {
            throw new PermitDocumentValidationException('This original-document verification decision is already recorded.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_permit_document_reviews
                (application_id, document_id, document_type, review_scope,
                 review_status, previous_review_id, original_received,
                 original_received_on, received_by_user_id, wet_ink_required,
                 wet_ink_verified, scan_compared_with_original,
                 reviewed_by_user_id, review_notes)
             VALUES
                (:application_id, :document_id, :document_type, \'original\',
                 :review_status, :previous_review_id, :original_received,
                 :original_received_on, :received_by_user_id, :wet_ink_required,
                 :wet_ink_verified, :scan_compared_with_original,
                 :reviewed_by_user_id, :review_notes)'
        );
        $insert->execute([
            ':application_id' => $applicationId,
            ':document_id' => $documentId,
            ':document_type' => $documentType,
            ':review_status' => $reviewStatus,
            ':previous_review_id' => $previousReview ? (int) $previousReview['id'] : null,
            ':original_received' => (int) $originalReceived,
            ':original_received_on' => $receivedOn,
            ':received_by_user_id' => $receivedByUserId,
            ':wet_ink_required' => (int) $wetInkRequired,
            ':wet_ink_verified' => (int) $wetInkVerified,
            ':scan_compared_with_original' => (int) $scanCompared,
            ':reviewed_by_user_id' => $verifierUserId,
            ':review_notes' => $reviewNotes,
        ]);
        $reviewId = (int) $pdo->lastInsertId();

        $summaryTarget = permit_document_summary_target($pdo, $applicationId);
        permit_document_transition_summary(
            $pdo,
            $application,
            $verifierUserId,
            $summaryTarget,
            'Original hardcopy and wet-ink verification updated.'
        );

        record_audit_event(
            $pdo,
            $verifierUserId,
            'verification',
            'permit_original_document_' . $reviewStatus,
            'permit_document_review',
            $reviewId,
            'Recorded an original permit document verification decision.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'document_type' => $documentType,
                'document_id' => $documentId,
                'previous_review_id' => $previousReview ? (int) $previousReview['id'] : null,
                'original_received' => $originalReceived,
                'original_received_on' => $receivedOn,
                'received_by_user_id' => $receivedByUserId,
                'wet_ink_required' => $wetInkRequired,
                'wet_ink_verified' => $wetInkVerified,
                'scan_compared_with_original' => $scanCompared,
                'review_status' => $reviewStatus,
            ]
        );

        $actionRequired = !$originalReceived
            || in_array($reviewStatus, ['rejected', 'replacement_required'], true)
            || ($wetInkRequired && !$wetInkVerified);
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $verifierUserId,
            'permit_status',
            $actionRequired ? 'Original document action required' : 'Original document verification updated',
            'Application ' . $application['transaction_id'] . ': '
                . (string) $definition['label'] . ' is now '
                . strtolower(permit_original_review_status_label($reviewStatus)) . '.'
                . ($actionRequired ? ' Review the recorded remarks and provide the requested action.' : ''),
            'permit_application',
            $applicationId
        );

        $pdo->commit();

        return [
            'review_id' => $reviewId,
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'document_type' => $documentType,
            'review_status' => $reviewStatus,
            'document_status' => $summaryTarget,
            'previous_review_id' => $previousReview ? (int) $previousReview['id'] : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function permit_list_applications_for_rps(PDO $pdo, int $rpsUserId): array
{
    if (permit_original_verification_actor($pdo, $rpsUserId) === null) {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT a.id, a.transaction_id, a.applicant_name, a.property_address,
                a.municipality, a.province, a.application_status, a.document_status,
                a.inspection_status, a.submitted_at,
                SUM(CASE WHEN d.is_current = 1 THEN 1 ELSE 0 END) AS current_document_count,
                SUM(CASE WHEN d.is_current = 1 AND d.verification_status = 'pending' THEN 1 ELSE 0 END) AS pending_document_count,
                (SELECT COUNT(*) FROM tbl_permit_inspections i WHERE i.application_id = a.id) AS inspection_event_count
         FROM tbl_permit_applications a
         LEFT JOIN tbl_permit_documents d ON d.application_id = a.id
         WHERE a.transaction_id IS NOT NULL AND a.application_status <> 'draft'
         GROUP BY a.id, a.transaction_id, a.applicant_name, a.property_address,
                  a.municipality, a.province, a.application_status, a.document_status,
                  a.inspection_status, a.submitted_at
         ORDER BY a.submitted_at DESC, a.id DESC"
    );

    return $stmt->fetchAll();
}

function permit_document_resolve_path(array $document): string
{
    $relativePath = (string) ($document['storage_path'] ?? '');
    if ($relativePath === ''
        || str_contains($relativePath, '\\')
        || str_starts_with($relativePath, '/')
        || preg_match('/(^|\/)\.\.?($|\/)/', $relativePath)
        || preg_match('/[^A-Za-z0-9._\/-]/', $relativePath)) {
        throw new RuntimeException('The stored document path is invalid.');
    }

    $root = permit_document_storage_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved) || !permit_document_path_is_within($resolved, $root)) {
        throw new RuntimeException('The stored document file is unavailable.');
    }

    return $resolved;
}

function permit_document_download_payload(PDO $pdo, int $documentId, int $actorUserId): ?array
{
    $document = permit_document_for_actor($pdo, $documentId, $actorUserId);
    if ($document === null) {
        return null;
    }
    $document['absolute_path'] = permit_document_resolve_path($document);

    return $document;
}

function send_permit_document_download(array $document): never
{
    $absolutePath = (string) $document['absolute_path'];
    $originalFilename = permit_document_normalize_original_filename((string) $document['original_filename']);
    $fallbackFilename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalFilename);
    if ($fallbackFilename === '') {
        $fallbackFilename = 'permit-document';
    }

    header('Content-Type: ' . (string) $document['mime_type']);
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: attachment; filename="' . $fallbackFilename
        . '"; filename*=UTF-8\'\'' . rawurlencode($originalFilename));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($absolutePath);
    }
    exit;
}
