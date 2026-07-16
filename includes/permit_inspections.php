<?php
/**
 * Append-only site-inspection and tree-verification services for Tree Cutting
 * Permit applications. Inspection results inform, but never make, the permit
 * decision.
 */

require_once __DIR__ . '/permit_documents.php';

class PermitInspectionValidationException extends InvalidArgumentException
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

function permit_inspection_result_statuses(): array
{
    return ['passed', 'failed', 'for_further_evaluation'];
}

function permit_inspection_status_label(string $status): string
{
    return match ($status) {
        'pending_assessment' => 'Pending assessment',
        'not_required' => 'Inspection not required',
        'required' => 'Inspection required',
        'scheduled' => 'Scheduled',
        'rescheduled' => 'Rescheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'passed' => 'Completed - passed',
        'failed' => 'Completed - failed',
        'for_further_evaluation' => 'Completed - for further evaluation',
        'cancelled' => 'Cancelled',
        default => permit_status_label($status),
    };
}

function permit_inspection_status_badge(string $status): string
{
    return match ($status) {
        'passed', 'not_required' => 'text-bg-success',
        'failed', 'cancelled' => 'text-bg-danger',
        'required', 'for_further_evaluation' => 'text-bg-warning',
        'scheduled', 'rescheduled', 'in_progress' => 'text-bg-primary',
        default => 'text-bg-secondary',
    };
}

function permit_inspection_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
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
            certreefy_permission_site_inspection(),
            $forUpdate
        )) {
        return $actor;
    }

    return null;
}

function permit_inspection_personnel(PDO $pdo): array
{
    $permission = certreefy_permission_site_inspection();
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

function permit_inspection_lock_reason(array $application): ?string
{
    if ($application['transaction_id'] === null || (string) $application['application_status'] === 'draft') {
        return 'Unsubmitted applications cannot be inspected.';
    }
    if (in_array((string) $application['decision_status'], ['approved', 'declined'], true)
        || (string) $application['release_status'] === 'released'
        || in_array((string) $application['validity_status'], ['completed', 'expired', 'closed'], true)
        || in_array((string) $application['application_status'], [
            'approved', 'declined', 'awaiting_donation', 'awaiting_final_verification',
            'ready_for_release', 'released', 'completed', 'closed',
        ], true)) {
        return 'This permit transaction is locked for inspection changes.';
    }

    return null;
}

function permit_inspection_application_for_actor(
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
        return permit_inspection_actor($pdo, $actorUserId, $forUpdate) !== null
            && permit_inspection_lock_reason($application) === null
            ? $application
            : null;
    }
    if ($operation !== 'view') {
        return null;
    }
    if ((string) $actor['role'] === 'community') {
        return (int) $application['applicant_user_id'] === $actorUserId ? $application : null;
    }
    if (permit_inspection_actor($pdo, $actorUserId, $forUpdate) !== null
        || ((string) $actor['role'] === 'superadmin'
            && user_has_active_permission(
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

    return null;
}

function permit_latest_inspection(PDO $pdo, int $applicationId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT i.*
         FROM tbl_permit_inspections i
         WHERE i.application_id = :application_id
         ORDER BY i.id DESC
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':application_id' => $applicationId]);
    $inspection = $stmt->fetch();

    return $inspection ?: null;
}

function permit_inspections_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    $application = permit_inspection_application_for_actor(
        $pdo,
        $applicationId,
        $actorUserId,
        'view'
    );
    if ($application === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT i.*,
                CASE WHEN assigned.id IS NULL THEN NULL ELSE CONCAT(assigned.fname, \' \', assigned.lname) END AS inspector_name,
                CONCAT(creator.fname, \' \', creator.lname) AS creator_name,
                CASE WHEN completer.id IS NULL THEN NULL ELSE CONCAT(completer.fname, \' \', completer.lname) END AS completer_name,
                (SELECT COUNT(*) FROM tbl_permit_inspection_tree_verifications tv WHERE tv.inspection_id = i.id) AS verified_tree_record_count,
                (SELECT COUNT(*) FROM tbl_permit_inspection_photos ph WHERE ph.inspection_id = i.id) AS photo_count
         FROM tbl_permit_inspections i
         LEFT JOIN tbl_users assigned ON assigned.id = i.inspector_user_id
         INNER JOIN tbl_users creator ON creator.id = i.created_by_user_id
         LEFT JOIN tbl_users completer ON completer.id = i.completed_by_user_id
         WHERE i.application_id = :application_id
         ORDER BY i.id DESC'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $inspections = $stmt->fetchAll();

    $actor = permit_document_load_actor($pdo, $actorUserId);
    if ($actor !== null && (string) $actor['role'] === 'community') {
        foreach ($inspections as &$inspection) {
            // Exact coordinates and operational photo evidence stay limited to
            // authorized inspection personnel.
            $inspection['latitude'] = null;
            $inspection['longitude'] = null;
            $inspection['photo_count'] = 0;
        }
        unset($inspection);
    }

    return $inspections;
}

function permit_inspection_tree_verifications_for_actor(
    PDO $pdo,
    int $inspectionId,
    int $actorUserId
): ?array {
    $applicationStmt = $pdo->prepare(
        'SELECT application_id FROM tbl_permit_inspections WHERE id = :id LIMIT 1'
    );
    $applicationStmt->execute([':id' => $inspectionId]);
    $applicationId = $applicationStmt->fetchColumn();
    if ($applicationId === false
        || permit_inspection_application_for_actor(
            $pdo,
            (int) $applicationId,
            $actorUserId,
            'view'
        ) === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT tv.*, t.common_name AS applied_common_name,
                t.scientific_name AS applied_scientific_name,
                t.quantity AS applied_quantity, t.diameter_cm AS applied_diameter_cm,
                t.estimated_height_m AS applied_height_m
         FROM tbl_permit_inspection_tree_verifications tv
         INNER JOIN tbl_permit_trees t ON t.id = tv.tree_id
         WHERE tv.inspection_id = :inspection_id
           AND tv.application_id = :application_id
         ORDER BY tv.tree_id'
    );
    $stmt->execute([
        ':inspection_id' => $inspectionId,
        ':application_id' => (int) $applicationId,
    ]);

    return $stmt->fetchAll();
}

function permit_inspection_photos_for_actor(
    PDO $pdo,
    int $inspectionId,
    int $actorUserId
): ?array {
    if (permit_inspection_actor($pdo, $actorUserId) === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT ph.id, ph.application_id, ph.inspection_id, ph.original_filename,
                ph.mime_type, ph.file_size_bytes, ph.uploaded_by_user_id,
                ph.created_at, CONCAT(u.fname, \' \', u.lname) AS uploader_name
         FROM tbl_permit_inspection_photos ph
         INNER JOIN tbl_permit_inspections i ON i.id = ph.inspection_id
              AND i.application_id = ph.application_id
         INNER JOIN tbl_users u ON u.id = ph.uploaded_by_user_id
         WHERE ph.inspection_id = :inspection_id
         ORDER BY ph.id'
    );
    $stmt->execute([':inspection_id' => $inspectionId]);

    return $stmt->fetchAll();
}

function permit_list_applications_for_inspection(PDO $pdo, int $actorUserId): array
{
    if (permit_inspection_actor($pdo, $actorUserId) === null) {
        return [];
    }
    $stmt = $pdo->query(
        'SELECT a.id, a.transaction_id, a.applicant_name, a.property_address,
                a.municipality, a.province, a.application_status,
                a.document_status, a.inspection_status, a.submitted_at,
                (SELECT COUNT(*) FROM tbl_permit_inspections i WHERE i.application_id = a.id) AS inspection_event_count
         FROM tbl_permit_applications a
         WHERE a.transaction_id IS NOT NULL AND a.application_status <> \'draft\'
         ORDER BY a.submitted_at DESC, a.id DESC'
    );

    return $stmt->fetchAll();
}

function permit_inspection_parse_boolean(array $input, string $key, string $label): bool
{
    $value = trim((string) ($input[$key] ?? ''));
    if (!in_array($value, ['0', '1'], true)) {
        throw new PermitInspectionValidationException($label . ' must be answered Yes or No.');
    }

    return $value === '1';
}

function permit_inspection_parse_datetime(string $value, string $label, bool $allowFuture): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\\TH:i') !== $value) {
        throw new PermitInspectionValidationException($label . ' is invalid.');
    }
    if ($allowFuture && $date <= new DateTimeImmutable()) {
        throw new PermitInspectionValidationException($label . ' must be in the future.');
    }
    if (!$allowFuture && $date > new DateTimeImmutable()) {
        throw new PermitInspectionValidationException($label . ' cannot be in the future.');
    }

    return $date->format('Y-m-d H:i:s');
}

function permit_inspection_coordinates(array $input): array
{
    $latitude = trim((string) ($input['latitude'] ?? ''));
    $longitude = trim((string) ($input['longitude'] ?? ''));
    if ($latitude === '' && $longitude === '') {
        return [null, null];
    }
    if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
        throw new PermitInspectionValidationException(
            'Latitude and longitude must both be provided as valid coordinates.'
        );
    }
    $latitudeValue = (float) $latitude;
    $longitudeValue = (float) $longitude;
    if ($latitudeValue < -90 || $latitudeValue > 90
        || $longitudeValue < -180 || $longitudeValue > 180) {
        throw new PermitInspectionValidationException('The inspection coordinates are outside valid ranges.');
    }

    return [number_format($latitudeValue, 7, '.', ''), number_format($longitudeValue, 7, '.', '')];
}

function permit_inspection_storage_root(): string
{
    $configured = defined('PERMIT_INSPECTION_STORAGE_ROOT')
        ? trim((string) PERMIT_INSPECTION_STORAGE_ROOT)
        : '';
    if ($configured === '') {
        throw new RuntimeException('Private inspection photo storage is not configured.');
    }
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Private inspection photo storage is unavailable.');
    }
    $root = realpath($configured);
    if ($root === false || !is_writable($root)) {
        throw new RuntimeException('Private inspection photo storage is unavailable.');
    }
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $publicRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($publicRoot !== false && permit_document_path_is_within($root, $publicRoot)) {
        throw new RuntimeException('Inspection photo storage must be outside the public web root.');
    }

    return $root;
}

function permit_inspection_photo_max_bytes(): int
{
    return defined('PERMIT_INSPECTION_PHOTO_MAX_BYTES')
        ? (int) PERMIT_INSPECTION_PHOTO_MAX_BYTES
        : 10 * 1024 * 1024;
}

function permit_inspection_photo_max_size_label(): string
{
    return number_format(permit_inspection_photo_max_bytes() / 1024 / 1024, 0) . ' MB';
}

function permit_inspection_normalize_photo_files(array $files): array
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

function permit_inspection_validate_photos(array $files): array
{
    $normalized = permit_inspection_normalize_photo_files($files);
    if (count($normalized) > 10) {
        throw new PermitInspectionValidationException('No more than 10 site photographs may be added per inspection event.');
    }
    $validated = [];
    foreach ($normalized as $file) {
        try {
            $photo = permit_document_validate_uploaded_file(
                $file,
                [
                    'jpg' => ['image/jpeg'],
                    'jpeg' => ['image/jpeg'],
                    'png' => ['image/png'],
                ],
                permit_inspection_photo_max_bytes(),
                'JPG, JPEG, and PNG'
            );
        } catch (PermitDocumentValidationException $e) {
            throw new PermitInspectionValidationException($e->getMessage());
        }
        $validated[] = $photo;
    }

    return $validated;
}

function permit_inspection_photo_storage_path(string $transactionId, string $extension): array
{
    if (!preg_match('/^TCP-\d{4}-\d{6}$/', $transactionId)) {
        throw new RuntimeException('The permit transaction ID is invalid for inspection storage.');
    }
    $root = permit_inspection_storage_root();
    $relativeDirectory = date('Y') . '/' . $transactionId;
    $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The inspection photo directory could not be created.');
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

    throw new RuntimeException('A collision-free inspection photo filename could not be generated.');
}

function permit_inspection_validate_tree_records(PDO $pdo, int $applicationId, array $input): array
{
    $stmt = $pdo->prepare(
        'SELECT id, common_name, scientific_name, quantity, diameter_cm, estimated_height_m
         FROM tbl_permit_trees
         WHERE application_id = :application_id
         ORDER BY id
         FOR UPDATE'
    );
    $stmt->execute([':application_id' => $applicationId]);
    $trees = $stmt->fetchAll();
    $submitted = isset($input['trees']) && is_array($input['trees']) ? $input['trees'] : [];
    $expectedIds = array_map(static fn (array $tree): int => (int) $tree['id'], $trees);
    $submittedIds = [];
    $validated = [];
    $allConfirmed = true;

    foreach ($submitted as $key => $row) {
        if (!is_array($row) || !ctype_digit((string) $key)) {
            throw new PermitInspectionValidationException('A tree verification row is invalid.');
        }
        $treeId = (int) $key;
        if (in_array($treeId, $submittedIds, true)) {
            throw new PermitInspectionValidationException('A tree verification row was submitted more than once.');
        }
        $submittedIds[] = $treeId;
    }
    sort($expectedIds);
    sort($submittedIds);
    if ($trees === [] || $expectedIds !== $submittedIds) {
        throw new PermitInspectionValidationException('Every application tree record must be verified exactly once.');
    }

    foreach ($trees as $tree) {
        $treeId = (int) $tree['id'];
        $row = $submitted[(string) $treeId] ?? $submitted[$treeId] ?? [];
        $speciesConfirmed = permit_inspection_parse_boolean($row, 'species_confirmed', 'Species confirmation');
        $quantityConfirmed = permit_inspection_parse_boolean($row, 'quantity_confirmed', 'Tree-count confirmation');
        $commonName = trim((string) ($row['verified_common_name'] ?? ''));
        $scientificName = trim((string) ($row['verified_scientific_name'] ?? ''));
        $quantity = trim((string) ($row['verified_quantity'] ?? ''));
        if ($commonName === '' || strlen($commonName) > 150) {
            throw new PermitInspectionValidationException('Each verified tree species requires a common name of at most 150 characters.');
        }
        if (strlen($scientificName) > 150) {
            throw new PermitInspectionValidationException('Verified scientific names must not exceed 150 characters.');
        }
        if (!ctype_digit($quantity) || (int) $quantity < 1 || (int) $quantity > 65535) {
            throw new PermitInspectionValidationException('Each verified tree count must be between 1 and 65,535.');
        }

        $measurementsRequired = $tree['diameter_cm'] !== null || $tree['estimated_height_m'] !== null;
        $measurementsConfirmed = null;
        if ($measurementsRequired) {
            $measurementsConfirmed = permit_inspection_parse_boolean(
                $row,
                'measurements_confirmed',
                'Tree measurement confirmation'
            );
        }
        $diameter = trim((string) ($row['verified_diameter_cm'] ?? ''));
        $height = trim((string) ($row['verified_height_m'] ?? ''));
        if ($tree['diameter_cm'] !== null && (!is_numeric($diameter) || (float) $diameter <= 0 || (float) $diameter > 999999.99)) {
            throw new PermitInspectionValidationException('A valid positive diameter is required for each measured tree record.');
        }
        if ($tree['estimated_height_m'] !== null && (!is_numeric($height) || (float) $height <= 0 || (float) $height > 999999.99)) {
            throw new PermitInspectionValidationException('A valid positive height is required for each measured tree record.');
        }
        if ($diameter !== '' && (!is_numeric($diameter) || (float) $diameter <= 0 || (float) $diameter > 999999.99)) {
            throw new PermitInspectionValidationException('Verified tree diameters must be valid positive numbers.');
        }
        if ($height !== '' && (!is_numeric($height) || (float) $height <= 0 || (float) $height > 999999.99)) {
            throw new PermitInspectionValidationException('Verified tree heights must be valid positive numbers.');
        }
        $notes = trim((string) ($row['measurement_notes'] ?? ''));
        if (strlen($notes) > 500) {
            throw new PermitInspectionValidationException('Tree verification notes must not exceed 500 characters.');
        }

        $allConfirmed = $allConfirmed
            && $speciesConfirmed
            && $quantityConfirmed
            && (!$measurementsRequired || $measurementsConfirmed === true);
        $validated[] = [
            'tree_id' => $treeId,
            'species_confirmed' => $speciesConfirmed ? 1 : 0,
            'quantity_confirmed' => $quantityConfirmed ? 1 : 0,
            'measurements_confirmed' => $measurementsConfirmed === null ? null : ($measurementsConfirmed ? 1 : 0),
            'verified_common_name' => $commonName,
            'verified_scientific_name' => $scientificName === '' ? null : $scientificName,
            'verified_quantity' => (int) $quantity,
            'verified_diameter_cm' => $diameter === '' ? null : number_format((float) $diameter, 2, '.', ''),
            'verified_height_m' => $height === '' ? null : number_format((float) $height, 2, '.', ''),
            'measurement_notes' => $notes === '' ? null : $notes,
        ];
    }

    return ['records' => $validated, 'all_confirmed' => $allConfirmed];
}

function permit_inspection_copy_snapshot(?array $latest): array
{
    $fields = [
        'inspector_user_id', 'scheduled_at', 'inspection_location', 'latitude', 'longitude',
        'inspected_at', 'property_location_confirmed', 'ownership_authorization_confirmed',
        'findings', 'recommendation', 'follow_up_required', 'inspection_notes',
    ];
    $snapshot = [];
    foreach ($fields as $field) {
        $snapshot[$field] = $latest[$field] ?? null;
    }
    $snapshot['follow_up_required'] = (int) ($snapshot['follow_up_required'] ?? 0);

    return $snapshot;
}

function permit_inspection_insert_snapshot(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $status,
    ?array $latest,
    array $snapshot,
    ?int $followUpOf = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_inspections
            (application_id, previous_inspection_id, follow_up_of_inspection_id,
             inspector_user_id, created_by_user_id, completed_by_user_id,
             inspection_status, scheduled_at, inspection_location, latitude,
             longitude, inspected_at, property_location_confirmed,
             ownership_authorization_confirmed, findings, recommendation,
             follow_up_required, inspection_notes)
         VALUES
            (:application_id, :previous_inspection_id, :follow_up_of_inspection_id,
             :inspector_user_id, :created_by_user_id, :completed_by_user_id,
             :inspection_status, :scheduled_at, :inspection_location, :latitude,
             :longitude, :inspected_at, :property_location_confirmed,
             :ownership_authorization_confirmed, :findings, :recommendation,
             :follow_up_required, :inspection_notes)'
    );
    $stmt->execute([
        ':application_id' => $applicationId,
        ':previous_inspection_id' => $latest === null ? null : (int) $latest['id'],
        ':follow_up_of_inspection_id' => $followUpOf,
        ':inspector_user_id' => $snapshot['inspector_user_id'] ?? null,
        ':created_by_user_id' => $actorUserId,
        ':completed_by_user_id' => $snapshot['completed_by_user_id'] ?? null,
        ':inspection_status' => $status,
        ':scheduled_at' => $snapshot['scheduled_at'] ?? null,
        ':inspection_location' => $snapshot['inspection_location'] ?? null,
        ':latitude' => $snapshot['latitude'] ?? null,
        ':longitude' => $snapshot['longitude'] ?? null,
        ':inspected_at' => $snapshot['inspected_at'] ?? null,
        ':property_location_confirmed' => $snapshot['property_location_confirmed'] ?? null,
        ':ownership_authorization_confirmed' => $snapshot['ownership_authorization_confirmed'] ?? null,
        ':findings' => $snapshot['findings'] ?? null,
        ':recommendation' => $snapshot['recommendation'] ?? null,
        ':follow_up_required' => (int) ($snapshot['follow_up_required'] ?? 0),
        ':inspection_notes' => $snapshot['inspection_notes'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

function record_permit_inspection_action(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $action,
    array $input = [],
    array $photoFiles = []
): array {
    $action = trim($action);
    $supportedActions = [
        'mark_required', 'mark_not_required', 'schedule', 'reschedule',
        'start', 'complete', 'cancel', 'follow_up',
    ];
    if (!in_array($action, $supportedActions, true)) {
        throw new PermitInspectionValidationException('The inspection action is invalid.');
    }
    $validatedPhotos = $action === 'complete'
        ? permit_inspection_validate_photos($photoFiles)
        : [];
    if ($action !== 'complete' && permit_inspection_normalize_photo_files($photoFiles) !== []) {
        throw new PermitInspectionValidationException('Site photographs may be added when completing an inspection.');
    }
    $storedPaths = [];

    try {
        $pdo->beginTransaction();
        $actor = permit_inspection_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('The responsible user is not authorized to manage site inspections.');
        }
        $application = permit_inspection_application_for_actor(
            $pdo,
            $applicationId,
            $actorUserId,
            'manage',
            true
        );
        if ($application === null) {
            throw new RuntimeException('The permit application is unavailable or locked for inspection changes.');
        }
        $latest = permit_latest_inspection($pdo, $applicationId, true);
        $previousStatus = (string) $application['inspection_status'];
        $expectedId = trim((string) ($input['expected_inspection_id'] ?? ''));
        if ($expectedId !== '') {
            if (!ctype_digit($expectedId)
                || (int) $expectedId !== ($latest === null ? 0 : (int) $latest['id'])) {
                throw new RuntimeException('The inspection changed before this action was completed. Reload and try again.');
            }
        }
        if ($latest !== null && (string) $latest['inspection_status'] !== $previousStatus) {
            throw new RuntimeException('The inspection summary and history are inconsistent.');
        }

        $snapshot = permit_inspection_copy_snapshot($latest);
        $newStatus = '';
        $followUpOf = null;
        $treeVerification = null;
        $remarks = trim((string) ($input['inspection_notes'] ?? ''));
        if (strlen($remarks) > 1000) {
            throw new PermitInspectionValidationException('Inspection notes must not exceed 1,000 characters.');
        }

        if ($action === 'mark_required' || $action === 'mark_not_required') {
            $newStatus = $action === 'mark_required' ? 'required' : 'not_required';
            $snapshot = permit_inspection_copy_snapshot(null);
            $snapshot['inspection_notes'] = $remarks === '' ? null : $remarks;
        } elseif (in_array($action, ['schedule', 'reschedule', 'follow_up'], true)) {
            if ((string) $application['document_status'] !== 'verified') {
                throw new RuntimeException(
                    'All mandatory original hardcopy and wet-ink requirements must be verified before scheduling an inspection.'
                );
            }
            $assignedId = trim((string) ($input['inspector_user_id'] ?? ''));
            if (!ctype_digit($assignedId) || permit_inspection_actor($pdo, (int) $assignedId, true) === null) {
                throw new PermitInspectionValidationException('Select an active authorized inspection assignee.');
            }
            $location = trim((string) ($input['inspection_location'] ?? ''));
            if ($location === '' || strlen($location) > 500) {
                throw new PermitInspectionValidationException('Inspection location is required and must not exceed 500 characters.');
            }
            [$latitude, $longitude] = permit_inspection_coordinates($input);
            $snapshot['inspector_user_id'] = (int) $assignedId;
            $snapshot['scheduled_at'] = permit_inspection_parse_datetime(
                (string) ($input['scheduled_at'] ?? ''),
                'Inspection schedule',
                true
            );
            $snapshot['inspection_location'] = $location;
            $snapshot['latitude'] = $latitude;
            $snapshot['longitude'] = $longitude;
            $snapshot['inspected_at'] = null;
            $snapshot['property_location_confirmed'] = null;
            $snapshot['ownership_authorization_confirmed'] = null;
            $snapshot['findings'] = null;
            $snapshot['recommendation'] = null;
            $snapshot['follow_up_required'] = 0;
            $snapshot['inspection_notes'] = $remarks === '' ? null : $remarks;
            $newStatus = $action === 'reschedule' ? 'rescheduled' : 'scheduled';
            if ($action === 'follow_up') {
                if ($latest === null
                    || !in_array($previousStatus, ['completed', 'passed', 'failed', 'for_further_evaluation'], true)) {
                    throw new RuntimeException('A follow-up may be scheduled only after a completed inspection.');
                }
                $followUpOf = (int) $latest['id'];
            }
        } elseif ($action === 'start') {
            $newStatus = 'in_progress';
            $snapshot['inspection_notes'] = $remarks === ''
                ? ($snapshot['inspection_notes'] ?? null)
                : $remarks;
        } elseif ($action === 'cancel') {
            if ($remarks === '') {
                throw new PermitInspectionValidationException('A cancellation reason is required.');
            }
            $newStatus = 'cancelled';
            $snapshot['inspection_notes'] = $remarks;
        } elseif ($action === 'complete') {
            $result = trim((string) ($input['verification_result'] ?? ''));
            if (!in_array($result, permit_inspection_result_statuses(), true)) {
                throw new PermitInspectionValidationException('Select a valid inspection result.');
            }
            $findings = trim((string) ($input['findings'] ?? ''));
            $recommendation = trim((string) ($input['recommendation'] ?? ''));
            if ($findings === '' || strlen($findings) > 10000) {
                throw new PermitInspectionValidationException('Inspection findings are required and must not exceed 10,000 characters.');
            }
            if ($recommendation === '' || strlen($recommendation) > 100) {
                throw new PermitInspectionValidationException('A recommendation is required and must not exceed 100 characters.');
            }
            $propertyConfirmed = permit_inspection_parse_boolean(
                $input,
                'property_location_confirmed',
                'Property or location confirmation'
            );
            $ownershipConfirmed = permit_inspection_parse_boolean(
                $input,
                'ownership_authorization_confirmed',
                'Ownership or authorization confirmation'
            );
            $treeVerification = permit_inspection_validate_tree_records($pdo, $applicationId, $input);
            if ($result === 'passed'
                && (!$propertyConfirmed || !$ownershipConfirmed || !$treeVerification['all_confirmed'])) {
                throw new PermitInspectionValidationException(
                    'A passed result requires confirmed trees, measurements when present, property location, and ownership or authorization.'
                );
            }
            $snapshot['completed_by_user_id'] = $actorUserId;
            $snapshot['inspected_at'] = permit_inspection_parse_datetime(
                (string) ($input['inspected_at'] ?? ''),
                'Actual inspection date and time',
                false
            );
            $snapshot['property_location_confirmed'] = $propertyConfirmed ? 1 : 0;
            $snapshot['ownership_authorization_confirmed'] = $ownershipConfirmed ? 1 : 0;
            $snapshot['findings'] = $findings;
            $snapshot['recommendation'] = $recommendation;
            $snapshot['follow_up_required'] = !empty($input['follow_up_required']) ? 1 : 0;
            $snapshot['inspection_notes'] = $remarks === '' ? null : $remarks;
            $newStatus = $result;
        }

        if (!permit_status_transition_is_allowed('inspection', $previousStatus, $newStatus)) {
            throw new RuntimeException(
                'The requested inspection action is not allowed from ' . permit_inspection_status_label($previousStatus) . '.'
            );
        }
        $inspectionId = permit_inspection_insert_snapshot(
            $pdo,
            $applicationId,
            $actorUserId,
            $newStatus,
            $latest,
            $snapshot,
            $followUpOf
        );

        if ($treeVerification !== null) {
            $treeInsert = $pdo->prepare(
                'INSERT INTO tbl_permit_inspection_tree_verifications
                    (application_id, inspection_id, tree_id, species_confirmed,
                     quantity_confirmed, measurements_confirmed, verified_common_name,
                     verified_scientific_name, verified_quantity, verified_diameter_cm,
                     verified_height_m, measurement_notes)
                 VALUES
                    (:application_id, :inspection_id, :tree_id, :species_confirmed,
                     :quantity_confirmed, :measurements_confirmed, :verified_common_name,
                     :verified_scientific_name, :verified_quantity, :verified_diameter_cm,
                     :verified_height_m, :measurement_notes)'
            );
            foreach ($treeVerification['records'] as $tree) {
                $treeInsert->execute([
                    ':application_id' => $applicationId,
                    ':inspection_id' => $inspectionId,
                    ':tree_id' => $tree['tree_id'],
                    ':species_confirmed' => $tree['species_confirmed'],
                    ':quantity_confirmed' => $tree['quantity_confirmed'],
                    ':measurements_confirmed' => $tree['measurements_confirmed'],
                    ':verified_common_name' => $tree['verified_common_name'],
                    ':verified_scientific_name' => $tree['verified_scientific_name'],
                    ':verified_quantity' => $tree['verified_quantity'],
                    ':verified_diameter_cm' => $tree['verified_diameter_cm'],
                    ':verified_height_m' => $tree['verified_height_m'],
                    ':measurement_notes' => $tree['measurement_notes'],
                ]);
            }
        }

        $photoInsert = $pdo->prepare(
            'INSERT INTO tbl_permit_inspection_photos
                (application_id, inspection_id, storage_path, original_filename,
                 mime_type, file_size_bytes, uploaded_by_user_id)
             VALUES
                (:application_id, :inspection_id, :storage_path, :original_filename,
                 :mime_type, :file_size_bytes, :uploaded_by_user_id)'
        );
        foreach ($validatedPhotos as $photo) {
            $storage = permit_inspection_photo_storage_path(
                (string) $application['transaction_id'],
                (string) $photo['extension']
            );
            if (!move_uploaded_file((string) $photo['tmp_name'], (string) $storage['absolute_path'])) {
                throw new RuntimeException('A site photograph could not be moved into private storage.');
            }
            @chmod((string) $storage['absolute_path'], 0600);
            $storedPaths[] = (string) $storage['absolute_path'];
            $photoInsert->execute([
                ':application_id' => $applicationId,
                ':inspection_id' => $inspectionId,
                ':storage_path' => (string) $storage['relative_path'],
                ':original_filename' => (string) $photo['original_filename'],
                ':mime_type' => (string) $photo['mime_type'],
                ':file_size_bytes' => (int) $photo['file_size_bytes'],
                ':uploaded_by_user_id' => $actorUserId,
            ]);
        }

        if ($previousStatus !== $newStatus) {
            $update = $pdo->prepare(
                'UPDATE tbl_permit_applications
                 SET inspection_status = :new_status
                 WHERE id = :application_id AND inspection_status = :previous_status'
            );
            $update->execute([
                ':new_status' => $newStatus,
                ':application_id' => $applicationId,
                ':previous_status' => $previousStatus,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The inspection status changed before the action completed.');
            }
        }
        permit_record_status_history(
            $pdo,
            $applicationId,
            $actorUserId,
            'inspection',
            $previousStatus,
            $newStatus,
            $remarks === '' ? null : $remarks
        );

        $auditAction = match ($action) {
            'mark_required' => 'site_inspection_required',
            'mark_not_required' => 'site_inspection_not_required',
            'schedule', 'follow_up' => 'site_inspection_scheduled',
            'reschedule' => 'site_inspection_rescheduled',
            'start' => 'site_inspection_started',
            'complete' => 'site_inspection_completed',
            'cancel' => 'site_inspection_cancelled',
        };
        record_audit_event(
            $pdo,
            $actorUserId,
            $action === 'complete' ? 'verification' : 'permit',
            $auditAction,
            'permit_inspection',
            $inspectionId,
            'Recorded a site-inspection workflow action.',
            [
                'application_id' => $applicationId,
                'transaction_id' => (string) $application['transaction_id'],
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'assigned_user_id' => $snapshot['inspector_user_id'] ?? null,
                'completed_by_user_id' => $snapshot['completed_by_user_id'] ?? null,
                'photo_count' => count($validatedPhotos),
            ]
        );

        $message = match ($action) {
            'mark_required' => 'A site inspection is required for application ' . $application['transaction_id'] . '.',
            'mark_not_required' => 'Application ' . $application['transaction_id'] . ' was assessed as not requiring a site inspection.',
            'schedule', 'follow_up' => 'A site inspection for application ' . $application['transaction_id'] . ' is scheduled for ' . date('M j, Y g:i A', strtotime((string) $snapshot['scheduled_at'])) . '.',
            'reschedule' => 'The site inspection for application ' . $application['transaction_id'] . ' was rescheduled to ' . date('M j, Y g:i A', strtotime((string) $snapshot['scheduled_at'])) . '.',
            'start' => 'The site inspection for application ' . $application['transaction_id'] . ' is now in progress.',
            'complete' => 'The site inspection for application ' . $application['transaction_id'] . ' was completed with result: ' . permit_inspection_status_label($newStatus) . '.',
            'cancel' => 'The site inspection for application ' . $application['transaction_id'] . ' was cancelled.',
        };
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            'permit_status',
            'Permit site inspection updated',
            $message,
            'permit_application',
            $applicationId
        );

        $pdo->commit();

        return [
            'application_id' => $applicationId,
            'inspection_id' => $inspectionId,
            'transaction_id' => (string) $application['transaction_id'],
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'photo_count' => count($validatedPhotos),
        ];
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

function permit_inspection_photo_for_actor(PDO $pdo, int $photoId, int $actorUserId): ?array
{
    if (permit_inspection_actor($pdo, $actorUserId) === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT ph.*
         FROM tbl_permit_inspection_photos ph
         INNER JOIN tbl_permit_inspections i ON i.id = ph.inspection_id
              AND i.application_id = ph.application_id
         WHERE ph.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $photoId]);
    $photo = $stmt->fetch();

    return $photo ?: null;
}

function permit_inspection_photo_resolve_path(array $photo): string
{
    $relativePath = (string) ($photo['storage_path'] ?? '');
    if ($relativePath === ''
        || str_contains($relativePath, '\\')
        || str_starts_with($relativePath, '/')
        || preg_match('/(^|\/)\.\.?($|\/)/', $relativePath)
        || preg_match('/[^A-Za-z0-9._\/-]/', $relativePath)) {
        throw new RuntimeException('The stored inspection photo path is invalid.');
    }
    $root = permit_inspection_storage_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved) || !permit_document_path_is_within($resolved, $root)) {
        throw new RuntimeException('The stored inspection photograph is unavailable.');
    }

    return $resolved;
}

function permit_inspection_photo_download_payload(PDO $pdo, int $photoId, int $actorUserId): ?array
{
    $photo = permit_inspection_photo_for_actor($pdo, $photoId, $actorUserId);
    if ($photo === null) {
        return null;
    }
    $photo['absolute_path'] = permit_inspection_photo_resolve_path($photo);

    return $photo;
}

function send_permit_inspection_photo(array $photo): never
{
    $absolutePath = (string) $photo['absolute_path'];
    $originalFilename = permit_document_normalize_original_filename((string) $photo['original_filename']);
    $fallbackFilename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalFilename);
    if ($fallbackFilename === '') {
        $fallbackFilename = 'inspection-photo';
    }
    header('Content-Type: ' . (string) $photo['mime_type']);
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="' . $fallbackFilename
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
