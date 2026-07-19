<?php
/**
 * Illegal logging incident reports.
 *
 * Independent of the Tree Cutting Permit and seedling-request workflows: a
 * logged-in Community user reports a suspected incident, CENRO enforcement
 * (active RPS or a specifically permitted Superadmin) dispatches a field
 * verification, and records a resolution outcome. Every mutation is
 * transactional and reuses the shared audit/notification writers.
 *
 * Lifecycle:
 *   submitted -> under_review -> field_verification -> resolved
 *             \-> resolved (direct, e.g. duplicate/invalid, no field visit)
 *   under_review -> resolved (direct)
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/permit_documents.php';

class IllegalLoggingValidationException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------

function illegal_logging_report_statuses(): array
{
    return [
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'field_verification' => 'Field verification',
        'resolved' => 'Resolved',
    ];
}

function illegal_logging_report_status_label(string $status): string
{
    return illegal_logging_report_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function illegal_logging_report_status_badge(string $status): string
{
    return match ($status) {
        'submitted' => 'text-bg-secondary',
        'under_review' => 'text-bg-info',
        'field_verification' => 'text-bg-warning',
        'resolved' => 'text-bg-success',
        default => 'text-bg-light border',
    };
}

function illegal_logging_resolution_outcomes(): array
{
    return [
        'confirmed' => 'Violation confirmed',
        'unfounded' => 'No violation found',
        'referred' => 'Referred to another agency',
        'invalid' => 'Invalid or duplicate report',
    ];
}

function illegal_logging_resolution_outcome_label(string $outcome): string
{
    return illegal_logging_resolution_outcomes()[$outcome] ?? ucwords(str_replace('_', ' ', $outcome));
}

function illegal_logging_resolution_outcome_badge(string $outcome): string
{
    return match ($outcome) {
        'confirmed' => 'text-bg-danger',
        'unfounded' => 'text-bg-success',
        'referred' => 'text-bg-info',
        'invalid' => 'text-bg-secondary',
        default => 'text-bg-light border',
    };
}

function illegal_logging_report_transition_is_allowed(string $from, string $to): bool
{
    $allowed = [
        'submitted' => ['under_review', 'resolved'],
        'under_review' => ['field_verification', 'resolved'],
        'field_verification' => ['resolved'],
        'resolved' => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
}

// ---------------------------------------------------------------------------
// Actors
// ---------------------------------------------------------------------------

function illegal_logging_load_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, fname, lname, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' ? $actor : null;
}

/** Reports may only be created by an active Community user. */
function illegal_logging_reporter_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $actor = illegal_logging_load_actor($pdo, $actorUserId, $forUpdate);

    return $actor && (string) $actor['role'] === 'community' ? $actor : null;
}

/** Processing (review/dispatch/resolve) is active RPS or a specifically permitted Superadmin. */
function illegal_logging_processor_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $actor = illegal_logging_load_actor($pdo, $actorUserId, $forUpdate);
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
            certreefy_permission_illegal_logging_processing(),
            $forUpdate
        )) {
        return $actor;
    }

    return null;
}

/** Active RPS + permitted Superadmins, for assignment dropdowns and notification fan-out. */
function illegal_logging_processing_personnel(PDO $pdo): array
{
    $permission = certreefy_permission_illegal_logging_processing();
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

// ---------------------------------------------------------------------------
// Internal writers
// ---------------------------------------------------------------------------

function illegal_logging_record_status_history(
    PDO $pdo,
    int $reportId,
    int $actorUserId,
    ?string $previousStatus,
    string $newStatus,
    string $remarks = ''
): void {
    $remarks = trim($remarks);
    if (strlen($remarks) > 500) {
        $remarks = substr($remarks, 0, 497) . '...';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_illegal_logging_report_status_history
            (report_id, previous_status, new_status, changed_by_user_id, remarks)
         VALUES (:report_id, :previous_status, :new_status, :actor, :remarks)'
    );
    $stmt->execute([
        ':report_id' => $reportId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':actor' => $actorUserId,
        ':remarks' => $remarks !== '' ? $remarks : null,
    ]);
}

/** Reserves the next IL-YYYY-###### reference under an annual row lock. */
function illegal_logging_reserve_report_reference(PDO $pdo, ?int $year = null): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('An illegal-logging report reference must be reserved inside a transaction.');
    }
    $year = $year ?? (int) date('Y');

    $pdo->prepare(
        'INSERT IGNORE INTO tbl_illegal_logging_report_sequences (sequence_year, last_number) VALUES (:year, 0)'
    )->execute([':year' => $year]);

    $select = $pdo->prepare(
        'SELECT last_number FROM tbl_illegal_logging_report_sequences WHERE sequence_year = :year FOR UPDATE'
    );
    $select->execute([':year' => $year]);
    $lastNumber = $select->fetchColumn();
    if ($lastNumber === false) {
        throw new RuntimeException('Unable to reserve an illegal-logging report sequence.');
    }
    $nextNumber = (int) $lastNumber + 1;

    $pdo->prepare(
        'UPDATE tbl_illegal_logging_report_sequences SET last_number = :next WHERE sequence_year = :year'
    )->execute([':next' => $nextNumber, ':year' => $year]);

    return sprintf('IL-%04d-%06d', $year, $nextNumber);
}

// ---------------------------------------------------------------------------
// Evidence photos (private storage, mirrors permit inspection photos)
// ---------------------------------------------------------------------------

function illegal_logging_photo_max_bytes(): int
{
    return defined('ILLEGAL_LOGGING_PHOTO_MAX_BYTES') ? (int) ILLEGAL_LOGGING_PHOTO_MAX_BYTES : 10 * 1024 * 1024;
}

function illegal_logging_photo_max_size_label(): string
{
    return number_format(illegal_logging_photo_max_bytes() / 1024 / 1024, 0) . ' MB';
}

function illegal_logging_storage_root(): string
{
    $configured = defined('ILLEGAL_LOGGING_STORAGE_ROOT') ? trim((string) ILLEGAL_LOGGING_STORAGE_ROOT) : '';
    if ($configured === '') {
        throw new RuntimeException('Private illegal-logging evidence storage is not configured.');
    }
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Private illegal-logging evidence storage is unavailable.');
    }
    $root = realpath($configured);
    if ($root === false || !is_writable($root)) {
        throw new RuntimeException('Private illegal-logging evidence storage is unavailable.');
    }
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $publicRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($publicRoot !== false && permit_document_path_is_within($root, $publicRoot)) {
        throw new RuntimeException('Illegal-logging evidence storage must be outside the public web root.');
    }

    return $root;
}

/** Normalizes a possibly-multi-file $_FILES sub-array into a flat list, dropping empty slots. */
function illegal_logging_normalize_photo_files(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
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

/** Validates optional evidence photographs (JPEG/PNG, up to 10). */
function illegal_logging_validate_photos(array $files): array
{
    $normalized = illegal_logging_normalize_photo_files($files);
    if (count($normalized) > 10) {
        throw new IllegalLoggingValidationException('No more than 10 evidence photographs may be attached per report.');
    }
    $validated = [];
    foreach ($normalized as $file) {
        try {
            $validated[] = permit_document_validate_uploaded_file(
                $file,
                ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']],
                illegal_logging_photo_max_bytes(),
                'JPG, JPEG, and PNG'
            );
        } catch (PermitDocumentValidationException $e) {
            throw new IllegalLoggingValidationException($e->getMessage());
        }
    }

    return $validated;
}

function illegal_logging_photo_storage_path(string $reportReference, string $extension): array
{
    if (!preg_match('/^IL-\d{4}-\d{6}$/', $reportReference)) {
        throw new RuntimeException('The report reference is invalid for evidence storage.');
    }
    $root = illegal_logging_storage_root();
    $relativeDirectory = date('Y') . '/' . $reportReference;
    $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The evidence directory could not be created.');
    }
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $filename = bin2hex(random_bytes(32)) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($absolutePath)) {
            return ['relative_path' => $relativeDirectory . '/' . $filename, 'absolute_path' => $absolutePath];
        }
    }

    throw new RuntimeException('A collision-free evidence filename could not be generated.');
}

function illegal_logging_photo_resolve_path(array $photo): string
{
    $relativePath = (string) ($photo['storage_path'] ?? '');
    if ($relativePath === ''
        || str_contains($relativePath, '\\')
        || str_starts_with($relativePath, '/')
        || preg_match('/(^|\/)\.\.?($|\/)/', $relativePath)
        || preg_match('/[^A-Za-z0-9._\/-]/', $relativePath)) {
        throw new RuntimeException('The stored evidence path is invalid.');
    }
    $root = illegal_logging_storage_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved) || !permit_document_path_is_within($resolved, $root)) {
        throw new RuntimeException('The stored evidence file is unavailable.');
    }

    return $resolved;
}

// ---------------------------------------------------------------------------
// Report submission (Community)
// ---------------------------------------------------------------------------

function new_illegal_logging_submission_key(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Submits an illegal-logging report, with optional evidence photos. Idempotent
 * per reporter via submission_key, so a double-posted form cannot create two
 * reports.
 */
function illegal_logging_submit_report(PDO $pdo, int $reporterUserId, array $input, array $files = []): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Report submission must own its database transaction.');
    }
    $location = trim((string) ($input['incident_location'] ?? ''));
    $description = trim((string) ($input['incident_description'] ?? ''));
    if ($location === '' || strlen($location) > 500) {
        throw new IllegalLoggingValidationException('Describe the incident location in up to 500 characters.');
    }
    if ($description === '' || strlen($description) > 2000) {
        throw new IllegalLoggingValidationException('Describe the incident in up to 2000 characters.');
    }

    $latitude = trim((string) ($input['latitude'] ?? ''));
    $longitude = trim((string) ($input['longitude'] ?? ''));
    if (($latitude === '') !== ($longitude === '')) {
        throw new IllegalLoggingValidationException('Provide both latitude and longitude, or leave both blank.');
    }
    $hasCoordinates = $latitude !== '' && $longitude !== '';
    if ($hasCoordinates) {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new IllegalLoggingValidationException('Coordinates must be valid numbers.');
        }
        $latitudeValue = (float) $latitude;
        $longitudeValue = (float) $longitude;
        if ($latitudeValue < -90 || $latitudeValue > 90 || $longitudeValue < -180 || $longitudeValue > 180) {
            throw new IllegalLoggingValidationException('The coordinates are outside valid ranges.');
        }
    }

    $observedOn = trim((string) ($input['observed_on'] ?? ''));
    if ($observedOn !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $observedOn);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $observedOn) {
            throw new IllegalLoggingValidationException('Enter a valid observed date.');
        }
        if ($date > new DateTimeImmutable('today')) {
            throw new IllegalLoggingValidationException('The observed date cannot be in the future.');
        }
    }

    $submissionKey = trim((string) ($input['submission_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new IllegalLoggingValidationException('The submission is invalid. Refresh the page and try again.');
    }

    // Evidence photos are validated before the transaction opens (matches the
    // permit-document/inspection-photo pattern).
    $validatedPhotos = illegal_logging_validate_photos($files);
    $storedPaths = [];

    try {
        $pdo->beginTransaction();
        $reporter = illegal_logging_reporter_actor($pdo, $reporterUserId, true);
        if ($reporter === null) {
            throw new RuntimeException('Only an active Community account may submit an illegal-logging report.');
        }

        // Idempotency: an identical resubmission returns the existing report.
        $existing = $pdo->prepare(
            'SELECT id, report_reference FROM tbl_illegal_logging_reports
             WHERE reporter_user_id = :reporter AND submission_key = :key
             LIMIT 1'
        );
        $existing->execute([':reporter' => $reporterUserId, ':key' => $submissionKey]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            $pdo->commit();

            return [
                'report_id' => (int) $existingRow['id'],
                'report_reference' => (string) $existingRow['report_reference'],
                'duplicate' => true,
            ];
        }

        $reference = illegal_logging_reserve_report_reference($pdo);
        $reporterName = trim((string) $reporter['fname'] . ' ' . (string) $reporter['lname']);
        $reporterContactStmt = $pdo->prepare('SELECT contact FROM tbl_users WHERE id = :id LIMIT 1');
        $reporterContactStmt->execute([':id' => $reporterUserId]);
        $reporterContact = $reporterContactStmt->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO tbl_illegal_logging_reports
                (report_reference, submission_key, reporter_user_id, reporter_name, reporter_contact,
                 incident_location, latitude, longitude, incident_description, observed_on, current_status)
             VALUES
                (:reference, :key, :reporter, :name, :contact,
                 :location, :latitude, :longitude, :description, :observed_on, \'submitted\')'
        );
        $insert->execute([
            ':reference' => $reference,
            ':key' => $submissionKey,
            ':reporter' => $reporterUserId,
            ':name' => $reporterName,
            ':contact' => $reporterContact !== false ? (string) $reporterContact : null,
            ':location' => $location,
            ':latitude' => $hasCoordinates ? number_format((float) $latitude, 7, '.', '') : null,
            ':longitude' => $hasCoordinates ? number_format((float) $longitude, 7, '.', '') : null,
            ':description' => $description,
            ':observed_on' => $observedOn !== '' ? $observedOn : null,
        ]);
        $reportId = (int) $pdo->lastInsertId();

        $photoInsert = $pdo->prepare(
            'INSERT INTO tbl_illegal_logging_report_photos
                (report_id, storage_path, original_filename, mime_type, file_size_bytes, uploaded_by_user_id)
             VALUES (:report_id, :storage_path, :original_filename, :mime_type, :file_size_bytes, :actor)'
        );
        foreach ($validatedPhotos as $photo) {
            $storage = illegal_logging_photo_storage_path($reference, (string) $photo['extension']);
            if (!move_uploaded_file((string) $photo['tmp_name'], (string) $storage['absolute_path'])) {
                throw new RuntimeException('An evidence photograph could not be moved into private storage.');
            }
            @chmod((string) $storage['absolute_path'], 0600);
            $storedPaths[] = (string) $storage['absolute_path'];
            $photoInsert->execute([
                ':report_id' => $reportId,
                ':storage_path' => (string) $storage['relative_path'],
                ':original_filename' => (string) $photo['original_filename'],
                ':mime_type' => (string) $photo['mime_type'],
                ':file_size_bytes' => (int) $photo['file_size_bytes'],
                ':actor' => $reporterUserId,
            ]);
        }

        illegal_logging_record_status_history($pdo, $reportId, $reporterUserId, null, 'submitted', 'Report submitted.');
        record_audit_event(
            $pdo,
            $reporterUserId,
            'illegal_logging',
            'illegal_logging_report_submitted',
            'illegal_logging_report',
            $reportId,
            'Submitted an illegal-logging report.',
            ['report_reference' => $reference, 'photo_count' => count($validatedPhotos)]
        );

        $processors = illegal_logging_processing_personnel($pdo);
        if ($processors !== []) {
            create_notifications_for_users(
                $pdo,
                array_map(static fn (array $p): int => (int) $p['id'], $processors),
                $reporterUserId,
                'illegal_logging_report',
                'New illegal logging report',
                'Report ' . $reference . ' from ' . $reporterName . ' requires review: ' . $location . '.',
                'illegal_logging_report',
                $reportId
            );
        }

        $pdo->commit();

        return ['report_id' => $reportId, 'report_reference' => $reference, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($storedPaths as $storedPath) {
            if (is_file($storedPath)) {
                @unlink($storedPath);
            }
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/** Owner-scoped for Community; processors see everything. Returns null when unauthorized. */
function illegal_logging_report_for_actor(PDO $pdo, int $reportId, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, CONCAT(u.fname, \' \', u.lname) AS reporter_full_name,
                CASE WHEN a.id IS NULL THEN NULL ELSE CONCAT(a.fname, \' \', a.lname) END AS assigned_to_name,
                CASE WHEN res.id IS NULL THEN NULL ELSE CONCAT(res.fname, \' \', res.lname) END AS resolved_by_name
         FROM tbl_illegal_logging_reports r
         INNER JOIN tbl_users u ON u.id = r.reporter_user_id
         LEFT JOIN tbl_users a ON a.id = r.assigned_to_user_id
         LEFT JOIN tbl_users res ON res.id = r.resolved_by_user_id
         WHERE r.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $reportId]);
    $report = $stmt->fetch();
    if (!$report) {
        return null;
    }

    $actor = illegal_logging_load_actor($pdo, $actorUserId);
    if ($actor === null) {
        return null;
    }
    $role = (string) $actor['role'];
    if ($role === 'rps'
        || ($role === 'superadmin' && user_has_active_permission($pdo, $actorUserId, certreefy_permission_illegal_logging_processing()))) {
        return $report;
    }
    if ($role === 'community' && (int) $report['reporter_user_id'] === $actorUserId) {
        return $report;
    }

    return null;
}

function illegal_logging_report_photos(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, CONCAT(u.fname, \' \', u.lname) AS uploader_name
         FROM tbl_illegal_logging_report_photos p
         INNER JOIN tbl_users u ON u.id = p.uploaded_by_user_id
         WHERE p.report_id = :id
         ORDER BY p.id'
    );
    $stmt->execute([':id' => $reportId]);

    return $stmt->fetchAll();
}

function illegal_logging_photo_for_actor(PDO $pdo, int $photoId, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, report_id, storage_path, original_filename, mime_type
         FROM tbl_illegal_logging_report_photos WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $photoId]);
    $photo = $stmt->fetch();
    if (!$photo) {
        return null;
    }

    return illegal_logging_report_for_actor($pdo, (int) $photo['report_id'], $actorUserId) !== null ? $photo : null;
}

function illegal_logging_photo_download_payload(PDO $pdo, int $photoId, int $actorUserId): ?array
{
    $photo = illegal_logging_photo_for_actor($pdo, $photoId, $actorUserId);
    if ($photo === null) {
        return null;
    }
    $photo['absolute_path'] = illegal_logging_photo_resolve_path($photo);

    return $photo;
}

function illegal_logging_report_history(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, CONCAT(u.fname, \' \', u.lname) AS changed_by_name
         FROM tbl_illegal_logging_report_status_history h
         INNER JOIN tbl_users u ON u.id = h.changed_by_user_id
         WHERE h.report_id = :id
         ORDER BY h.id'
    );
    $stmt->execute([':id' => $reportId]);

    return $stmt->fetchAll();
}

/** Owner-scoped list for the Community registry. */
function illegal_logging_reports_for_reporter(PDO $pdo, int $reporterUserId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tbl_illegal_logging_reports
         WHERE reporter_user_id = :reporter
         ORDER BY submitted_at DESC, id DESC'
    );
    $stmt->execute([':reporter' => $reporterUserId]);

    return $stmt->fetchAll();
}

/** Filterable CENRO enforcement work queue. */
function illegal_logging_reports_for_processors(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && array_key_exists($status, illegal_logging_report_statuses())) {
        $where[] = 'r.current_status = :status';
        $params[':status'] = $status;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(r.report_reference LIKE :search1 OR r.reporter_name LIKE :search2 OR r.incident_location LIKE :search3)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        'SELECT r.*,
                (SELECT COUNT(*) FROM tbl_illegal_logging_report_photos p WHERE p.report_id = r.id) AS photo_count
         FROM tbl_illegal_logging_reports r' . $whereSql . '
         ORDER BY FIELD(r.current_status, \'submitted\', \'under_review\', \'field_verification\', \'resolved\'),
                  r.submitted_at DESC, r.id DESC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Processing (CENRO enforcement)
// ---------------------------------------------------------------------------

function illegal_logging_lock_report_for_transition(PDO $pdo, int $reportId, string $newStatus): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_illegal_logging_reports WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $reportId]);
    $report = $stmt->fetch();
    if (!$report) {
        throw new IllegalLoggingValidationException('The illegal-logging report does not exist.');
    }
    $current = (string) $report['current_status'];
    if (!illegal_logging_report_transition_is_allowed($current, $newStatus)) {
        throw new IllegalLoggingValidationException(
            'A ' . illegal_logging_report_status_label($current) . ' report cannot become '
            . illegal_logging_report_status_label($newStatus) . '.'
        );
    }

    return $report;
}

function illegal_logging_set_report_status(
    PDO $pdo,
    array $report,
    int $actorUserId,
    string $newStatus,
    string $remarks,
    array $extraColumns = []
): void {
    $columns = ['current_status = :new_status'];
    $params = [
        ':new_status' => $newStatus,
        ':id' => (int) $report['id'],
        ':expected' => (string) $report['current_status'],
    ];
    foreach ($extraColumns as $column => $value) {
        $columns[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }
    $update = $pdo->prepare(
        'UPDATE tbl_illegal_logging_reports SET ' . implode(', ', $columns)
        . ' WHERE id = :id AND current_status = :expected'
    );
    $update->execute($params);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The report changed before the update completed.');
    }
    illegal_logging_record_status_history(
        $pdo,
        (int) $report['id'],
        $actorUserId,
        (string) $report['current_status'],
        $newStatus,
        $remarks
    );
}

/**
 * CENRO processing action: begin_review (assigns a processor), dispatch
 * (field verification), or resolve (with an outcome). Resolve may be called
 * from submitted, under_review, or field_verification.
 */
function illegal_logging_process_report(PDO $pdo, int $reportId, int $actorUserId, string $action, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Report processing must own its database transaction.');
    }
    if (!in_array($action, ['begin_review', 'dispatch', 'resolve'], true)) {
        throw new IllegalLoggingValidationException('Unsupported report action.');
    }
    $remarks = trim((string) ($input['remarks'] ?? ''));
    if (strlen($remarks) > 500) {
        throw new IllegalLoggingValidationException('Remarks must not exceed 500 characters.');
    }

    try {
        $pdo->beginTransaction();
        $actor = illegal_logging_processor_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to process illegal-logging reports.');
        }

        if ($action === 'begin_review') {
            $report = illegal_logging_lock_report_for_transition($pdo, $reportId, 'under_review');
            $assignedToValue = trim((string) ($input['assigned_to_user_id'] ?? ''));
            $assignedTo = $actorUserId;
            if ($assignedToValue !== '') {
                if (!ctype_digit($assignedToValue)) {
                    throw new IllegalLoggingValidationException('Select valid assigned personnel.');
                }
                $assignedTo = (int) $assignedToValue;
                if (illegal_logging_processor_actor($pdo, $assignedTo) === null) {
                    throw new IllegalLoggingValidationException('The selected personnel is not authorized to process reports.');
                }
            }
            illegal_logging_set_report_status(
                $pdo,
                $report,
                $actorUserId,
                'under_review',
                $remarks !== '' ? $remarks : 'Report is now under review.',
                ['assigned_to_user_id' => $assignedTo, 'assigned_by_user_id' => $actorUserId, 'assigned_at' => date('Y-m-d H:i:s')]
            );
            $result = ['report_id' => $reportId, 'status' => 'under_review'];
        } elseif ($action === 'dispatch') {
            $report = illegal_logging_lock_report_for_transition($pdo, $reportId, 'field_verification');
            illegal_logging_set_report_status(
                $pdo,
                $report,
                $actorUserId,
                'field_verification',
                $remarks !== '' ? $remarks : 'A field team has been dispatched to verify the site.'
            );
            $result = ['report_id' => $reportId, 'status' => 'field_verification'];
        } else {
            $outcome = trim((string) ($input['resolution_outcome'] ?? ''));
            if (!array_key_exists($outcome, illegal_logging_resolution_outcomes())) {
                throw new IllegalLoggingValidationException('Select a valid resolution outcome.');
            }
            $findings = trim((string) ($input['field_findings'] ?? ''));
            if (strlen($findings) > 2000) {
                throw new IllegalLoggingValidationException('Field findings must not exceed 2000 characters.');
            }
            $resolutionNotes = trim((string) ($input['resolution_notes'] ?? ''));
            if ($resolutionNotes === '') {
                throw new IllegalLoggingValidationException('Resolution notes are required.');
            }
            if (strlen($resolutionNotes) > 2000) {
                throw new IllegalLoggingValidationException('Resolution notes must not exceed 2000 characters.');
            }

            $report = illegal_logging_lock_report_for_transition($pdo, $reportId, 'resolved');
            $wasFieldVerified = (string) $report['current_status'] === 'field_verification';
            illegal_logging_set_report_status(
                $pdo,
                $report,
                $actorUserId,
                'resolved',
                $remarks !== '' ? $remarks : 'Report resolved: ' . illegal_logging_resolution_outcome_label($outcome) . '.',
                array_filter([
                    'resolution_outcome' => $outcome,
                    'resolution_notes' => $resolutionNotes,
                    'resolved_by_user_id' => $actorUserId,
                    'resolved_at' => date('Y-m-d H:i:s'),
                    'field_findings' => $findings !== '' ? $findings : null,
                    'field_verified_at' => $wasFieldVerified ? date('Y-m-d H:i:s') : ($report['field_verified_at'] ?? null),
                ], static fn ($value): bool => $value !== null)
            );
            $result = ['report_id' => $reportId, 'status' => 'resolved', 'resolution_outcome' => $outcome];
        }

        $reference = (string) $report['report_reference'];
        $reporterUserId = (int) $report['reporter_user_id'];
        $result['report_reference'] = $reference;

        record_audit_event(
            $pdo,
            $actorUserId,
            'illegal_logging',
            'illegal_logging_report_' . $result['status'],
            'illegal_logging_report',
            $reportId,
            'Illegal-logging report moved to ' . illegal_logging_report_status_label($result['status']) . '.',
            array_merge(
                ['report_reference' => $reference, 'previous_status' => (string) $report['current_status']],
                isset($result['resolution_outcome']) ? ['resolution_outcome' => $result['resolution_outcome']] : []
            )
        );

        $messages = [
            'under_review' => 'Your illegal-logging report ' . $reference . ' is now under review by CENRO.',
            'field_verification' => 'A CENRO field team has been dispatched to verify your reported incident (' . $reference . ').',
            'resolved' => 'Your illegal-logging report ' . $reference . ' has been resolved: '
                . illegal_logging_resolution_outcome_label((string) ($result['resolution_outcome'] ?? '')) . '.',
        ];
        create_notification(
            $pdo,
            $reporterUserId,
            $actorUserId,
            'illegal_logging_report',
            'Illegal logging report ' . illegal_logging_report_status_label($result['status']),
            $messages[$result['status']] ?? ('Your report ' . $reference . ' is now ' . illegal_logging_report_status_label($result['status']) . '.'),
            'illegal_logging_report',
            $reportId
        );

        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
