<?php
/**
 * Secure scanned-document services for Tree Cutting Permit applications.
 * Uploaded scans support online review only and never replace original
 * hardcopy or wet-ink verification requirements.
 */

require_once __DIR__ . '/permit.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/permit_matrix.php';

class PermitDocumentValidationException extends InvalidArgumentException
{
}

/**
 * Turns a matrix requirement into the catalog shape the document services and
 * views expect.
 */
function permit_document_definition_from_requirement(array $requirement): array
{
    $note = $requirement['condition_note'] ?? null;

    return [
        'label' => $requirement['label'],
        'description' => trim(($requirement['copies'] ?? '') . ($note !== null ? ' - ' . $note : '')),
        'required' => (bool) ($requirement['is_required'] ?? true),
        'group' => $requirement['group'],
    ];
}

/**
 * The upload checklist an application must satisfy, resolved from the permit
 * matrix against the application's classification and declarations.
 *
 * Passing no application yields the union of every requirement across every
 * category, which is what label lookups for historical rows need.
 */
function permit_document_type_catalog(?array $application = null): array
{
    if ($application === null) {
        $catalog = [];
        foreach (permit_matrix_category_keys() as $categoryKey) {
            foreach (permit_matrix_requirements_for($categoryKey) as $key => $requirement) {
                $catalog[$key] ??= permit_document_definition_from_requirement(
                    $requirement + ['is_required' => false]
                );
            }
        }

        return $catalog;
    }

    $categoryKey = (string) ($application['permit_category'] ?? '');
    if ($categoryKey === '') {
        return [];
    }

    $area = $application['area_hectares'] === null ? null : (float) $application['area_hectares'];
    $resolved = permit_matrix_resolved_requirements(
        $categoryKey,
        permit_matrix_decode_condition_answers($application['condition_answers'] ?? null),
        $area,
        (int) ($application['filed_by_representative'] ?? 0) === 1
    );

    return array_map('permit_document_definition_from_requirement', $resolved);
}

function permit_document_type(string $documentType, ?array $application = null): ?array
{
    return permit_document_type_catalog($application)[$documentType] ?? null;
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
    $uploadTarget = permit_document_application_for_actor($pdo, $applicationId, $uploaderUserId, 'upload');
    if ($uploadTarget === null) {
        throw new RuntimeException('This permit application is not eligible for document uploads.');
    }
    // Only slots the application's own checklist asks for may be uploaded, so a
    // tampered form cannot attach documents from another permit type.
    if (permit_document_type($documentType, $uploadTarget) === null) {
        throw new PermitDocumentValidationException('The selected document type is not required for this application.');
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

    $catalog = permit_document_type_catalog(permit_load_application($pdo, $applicationId));
    foreach ($catalog as $type => $definition) {
        if (!empty($definition['required']) && ($statuses[$type] ?? null) !== 'accepted') {
            return 'under_review';
        }
    }

    $latestOriginals = permit_latest_original_reviews_by_type(
        permit_original_reviews_for_application($pdo, $applicationId)
    );
    $allRequiredOriginalsVerified = true;
    foreach ($catalog as $type => $definition) {
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


/**
 * One-click online review of an application's whole scan set.
 *
 * The reviewer only marks the scans that need replacing; everything else is
 * accepted. The applicant receives a single notification for the batch rather
 * than one per document.
 */
function review_permit_documents_batch(
    PDO $pdo,
    int $applicationId,
    int $reviewerUserId,
    array $replacementDocumentIds,
    ?string $reviewNotes = null
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Batch document review must own its database transaction.');
    }
    $reviewNotes = $reviewNotes === null || trim($reviewNotes) === '' ? null : trim($reviewNotes);
    if ($reviewNotes !== null && strlen($reviewNotes) > 1000) {
        throw new PermitDocumentValidationException('Review notes must not exceed 1000 characters.');
    }
    $replacementIds = [];
    foreach ($replacementDocumentIds as $value) {
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            $replacementIds[(int) $value] = true;
        }
    }
    if ($replacementIds !== [] && $reviewNotes === null) {
        throw new PermitDocumentValidationException(
            'Add a remark explaining what the applicant must correct before requesting a replacement.'
        );
    }

    try {
        $pdo->beginTransaction();
        $application = permit_document_application_for_actor(
            $pdo,
            $applicationId,
            $reviewerUserId,
            'review',
            true
        );
        if ($application === null) {
            throw new RuntimeException('This permit application is not eligible for document review.');
        }

        $documentStmt = $pdo->prepare(
            'SELECT id, document_type, verification_status
             FROM tbl_permit_documents
             WHERE application_id = :application_id AND is_current = 1
             ORDER BY document_type
             FOR UPDATE'
        );
        $documentStmt->execute([':application_id' => $applicationId]);
        $documents = $documentStmt->fetchAll();
        if ($documents === []) {
            throw new PermitDocumentValidationException('There are no submitted scans to review yet.');
        }

        $catalog = permit_document_type_catalog($application);
        $missing = [];
        foreach ($catalog as $type => $definition) {
            if (empty($definition['required'])) {
                continue;
            }
            $hasScan = false;
            foreach ($documents as $document) {
                if ((string) $document['document_type'] === $type) {
                    $hasScan = true;
                    break;
                }
            }
            if (!$hasScan) {
                $missing[] = (string) $definition['label'];
            }
        }
        if ($missing !== [] && $replacementIds === []) {
            throw new PermitDocumentValidationException(
                'These required documents have no scan yet: ' . implode(', ', $missing) . '.'
            );
        }

        $update = $pdo->prepare(
            'UPDATE tbl_permit_documents
             SET verification_status = :verification_status,
                 verified_by_user_id = :verified_by_user_id,
                 verified_at = CURRENT_TIMESTAMP,
                 verification_notes = :verification_notes
             WHERE id = :id AND is_current = 1'
        );
        $insertReview = $pdo->prepare(
            'INSERT INTO tbl_permit_document_reviews
                (application_id, document_id, document_type, review_scope, review_status,
                 reviewed_by_user_id, review_notes)
             VALUES
                (:application_id, :document_id, :document_type, \'online\', :review_status,
                 :reviewed_by_user_id, :review_notes)'
        );

        $replacementLabels = [];
        foreach ($documents as $document) {
            $documentId = (int) $document['id'];
            $needsReplacement = isset($replacementIds[$documentId]);
            $status = $needsReplacement ? 'replacement_required' : 'accepted';

            $update->execute([
                ':verification_status' => $status,
                ':verified_by_user_id' => $reviewerUserId,
                ':verification_notes' => $needsReplacement ? $reviewNotes : null,
                ':id' => $documentId,
            ]);
            $insertReview->execute([
                ':application_id' => $applicationId,
                ':document_id' => $documentId,
                ':document_type' => (string) $document['document_type'],
                ':review_status' => $status,
                ':reviewed_by_user_id' => $reviewerUserId,
                ':review_notes' => $needsReplacement ? $reviewNotes : null,
            ]);

            if ($needsReplacement) {
                $definition = permit_document_type((string) $document['document_type'], $application);
                $replacementLabels[] = (string) ($definition['label'] ?? $document['document_type']);
            }
        }

        $approved = $replacementLabels === [];
        $summaryTarget = permit_document_summary_target($pdo, $applicationId);
        permit_document_transition_summary(
            $pdo,
            $application,
            $reviewerUserId,
            $summaryTarget,
            $approved
                ? 'Initial online documents approved.'
                : 'Replacement requested for one or more online documents.'
        );

        record_audit_event(
            $pdo,
            $reviewerUserId,
            'verification',
            $approved ? 'permit_documents_approved' : 'permit_documents_replacement_required',
            'permit_application',
            $applicationId,
            'Completed a one-click online document review.',
            [
                'transaction_id' => (string) $application['transaction_id'],
                'reviewed_count' => count($documents),
                'replacement_count' => count($replacementLabels),
                'document_status' => $summaryTarget,
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $reviewerUserId,
            'permit_status',
            $approved ? 'Initial documents approved' : 'Initial documents rejected',
            $approved
                ? 'Your initial submitted documents have been approved. Please submit to the office the original documents.'
                : 'Your initial submitted documents have been rejected. Please re-upload: '
                    . implode(', ', $replacementLabels) . '.'
                    . ($reviewNotes !== null ? ' Remarks: ' . $reviewNotes : ''),
            'permit_application',
            $applicationId
        );

        $pdo->commit();

        return [
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'approved' => $approved,
            'reviewed_count' => count($documents),
            'replacement_count' => count($replacementLabels),
            'document_status' => $summaryTarget,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * One-click confirmation that the applicant presented their original hardcopies
 * and settled the assessed fees at the office.
 */
function verify_permit_originals_and_fees(
    PDO $pdo,
    int $applicationId,
    int $verifierUserId,
    ?string $remarks = null
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Original and fee verification must own its database transaction.');
    }
    $remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);
    if ($remarks !== null && strlen($remarks) > 1000) {
        throw new PermitDocumentValidationException('Verification remarks must not exceed 1000 characters.');
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

        $documentStmt = $pdo->prepare(
            'SELECT id, document_type, verification_status
             FROM tbl_permit_documents
             WHERE application_id = :application_id AND is_current = 1
             ORDER BY document_type
             FOR UPDATE'
        );
        $documentStmt->execute([':application_id' => $applicationId]);
        $documentsByType = [];
        foreach ($documentStmt->fetchAll() as $document) {
            $documentsByType[(string) $document['document_type']] = $document;
        }

        $catalog = permit_document_type_catalog($application);
        $blocking = [];
        foreach ($catalog as $type => $definition) {
            if (empty($definition['required'])) {
                continue;
            }
            $document = $documentsByType[$type] ?? null;
            if ($document === null || (string) $document['verification_status'] !== 'accepted') {
                $blocking[] = (string) $definition['label'];
            }
        }
        if ($blocking !== []) {
            throw new PermitDocumentValidationException(
                'Approve the online scans first. Still pending: ' . implode(', ', $blocking) . '.'
            );
        }

        $today = date('Y-m-d');
        $insert = $pdo->prepare(
            'INSERT INTO tbl_permit_document_reviews
                (application_id, document_id, document_type, review_scope,
                 review_status, previous_review_id, original_received,
                 original_received_on, received_by_user_id, wet_ink_required,
                 wet_ink_verified, scan_compared_with_original,
                 reviewed_by_user_id, review_notes)
             VALUES
                (:application_id, :document_id, :document_type, \'original\',
                 \'verified\', :previous_review_id, 1,
                 :original_received_on, :received_by_user_id, 0,
                 0, 1,
                 :reviewed_by_user_id, :review_notes)'
        );

        $verifiedCount = 0;
        foreach ($catalog as $type => $definition) {
            if (empty($definition['required'])) {
                continue;
            }
            $previousReview = permit_latest_original_review($pdo, $applicationId, $type, true);
            $insert->execute([
                ':application_id' => $applicationId,
                ':document_id' => (int) $documentsByType[$type]['id'],
                ':document_type' => $type,
                ':previous_review_id' => $previousReview ? (int) $previousReview['id'] : null,
                ':original_received_on' => $today,
                ':received_by_user_id' => $verifierUserId,
                ':reviewed_by_user_id' => $verifierUserId,
                ':review_notes' => $remarks,
            ]);
            $verifiedCount++;
        }

        $feeUpdate = $pdo->prepare(
            'UPDATE tbl_permit_applications
             SET fees_status = \'paid\',
                 fees_confirmed_at = CURRENT_TIMESTAMP,
                 fees_confirmed_by_user_id = :verifier
             WHERE id = :application_id'
        );
        $feeUpdate->execute([
            ':verifier' => $verifierUserId,
            ':application_id' => $applicationId,
        ]);

        $summaryTarget = permit_document_summary_target($pdo, $applicationId);
        permit_document_transition_summary(
            $pdo,
            $application,
            $verifierUserId,
            $summaryTarget,
            'Original documents received and assessed fees settled.'
        );

        $totalFee = (float) ($application['total_fee'] ?? 0);
        record_audit_event(
            $pdo,
            $verifierUserId,
            'verification',
            'permit_originals_and_fees_verified',
            'permit_application',
            $applicationId,
            'Confirmed original documents and fee payment in one action.',
            [
                'transaction_id' => (string) $application['transaction_id'],
                'verified_document_count' => $verifiedCount,
                'total_fee' => $totalFee,
                'document_status' => $summaryTarget,
            ]
        );
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $verifierUserId,
            'permit_status',
            'Original documents and fees verified',
            'Your original documents have been verified and your fees of '
                . permit_matrix_format_peso($totalFee)
                . ' have been received. Your application now proceeds to site inspection.'
                . ($remarks !== null ? ' Remarks: ' . $remarks : ''),
            'permit_application',
            $applicationId
        );

        $pdo->commit();

        return [
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'verified_document_count' => $verifiedCount,
            'total_fee' => $totalFee,
            'document_status' => $summaryTarget,
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
