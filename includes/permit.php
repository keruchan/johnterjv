<?php
/**
 * Reusable Tree Cutting Permit data access and transaction services.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/permit_workflow.php';

class PermitValidationException extends InvalidArgumentException
{
    private array $validationErrors;

    public function __construct(array $errors)
    {
        $this->validationErrors = array_values($errors);
        parent::__construct(implode(' ', $this->validationErrors));
    }

    public function errors(): array
    {
        return $this->validationErrors;
    }
}

function new_permit_submission_key(): string
{
    return bin2hex(random_bytes(32));
}

function permit_normalize_application_data(array $input): array
{
    $fields = [
        'applicant_type',
        'organization_name',
        'property_relationship',
        'authorization_details',
        'property_classification',
        'property_owner_name',
        'property_address',
        'lot_number',
        'district',
        'barangay',
        'municipality',
        'province',
        'latitude',
        'longitude',
        'cutting_purpose',
        'application_notes',
    ];
    $data = [];

    foreach ($fields as $field) {
        $data[$field] = trim((string) ($input[$field] ?? ''));
    }

    foreach ($fields as $field) {
        $data[$field] = $data[$field] === '' ? null : $data[$field];
    }
    if ($data['applicant_type'] !== 'organization') {
        $data['organization_name'] = null;
    }
    if ($data['property_relationship'] !== 'authorized_representative') {
        $data['authorization_details'] = null;
    }
    $data['declaration_confirmed'] = in_array(
        strtolower(trim((string) ($input['declaration_confirmed'] ?? ''))),
        ['1', 'true', 'yes', 'on'],
        true
    );

    return $data;
}

function permit_validate_application_data(array $data, bool $forSubmission = true): array
{
    $errors = [];
    $fieldLengths = [
        'applicant_type' => ['Applicant type', 50],
        'organization_name' => ['Organization name', 255],
        'property_relationship' => ['Property relationship', 50],
        'authorization_details' => ['Authorization details', 1000],
        'property_classification' => ['Property classification', 100],
        'property_owner_name' => ['Property owner name', 255],
        'property_address' => ['Property address', 500],
        'lot_number' => ['Lot number', 100],
        'district' => ['District', 100],
        'barangay' => ['Barangay', 100],
        'municipality' => ['Municipality', 100],
        'province' => ['Province', 100],
        'cutting_purpose' => ['Cutting purpose', 500],
        'application_notes' => ['Application notes', 5000],
    ];

    foreach ($fieldLengths as $field => [$label, $maximum]) {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value !== '' && strlen($value) > $maximum) {
            $errors[] = $label . ' must not exceed ' . $maximum . ' characters.';
        }
    }

    if (($data['applicant_type'] ?? null) !== null
        && !in_array($data['applicant_type'], ['individual', 'organization'], true)) {
        $errors[] = 'Applicant type is invalid.';
    }
    if (($data['property_relationship'] ?? null) !== null
        && !in_array($data['property_relationship'], ['owner', 'authorized_representative'], true)) {
        $errors[] = 'Property relationship is invalid.';
    }
    if (($data['property_classification'] ?? null) !== null
        && !in_array($data['property_classification'], ['public_domain', 'private_property'], true)) {
        $errors[] = 'Property classification is invalid.';
    }

    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => [$minimum, $maximum]) {
        if (($data[$field] ?? null) === null) {
            continue;
        }

        $coordinate = filter_var($data[$field], FILTER_VALIDATE_FLOAT);
        if ($coordinate === false || $coordinate < $minimum || $coordinate > $maximum) {
            $errors[] = ucfirst($field) . ' must be a valid coordinate.';
        }
    }

    if ($forSubmission) {
        $requiredFields = [
            'applicant_type' => 'Applicant type',
            'property_relationship' => 'Property relationship',
            'property_classification' => 'Property classification',
            'property_owner_name' => 'Property owner name',
            'property_address' => 'Detailed property location',
            'district' => 'District',
            'barangay' => 'Barangay',
            'municipality' => 'Municipality or city',
            'province' => 'Province',
            'cutting_purpose' => 'Purpose of cutting',
        ];
        foreach ($requiredFields as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[] = $label . ' is required.';
            }
        }
        if (($data['applicant_type'] ?? null) === 'organization'
            && trim((string) ($data['organization_name'] ?? '')) === '') {
            $errors[] = 'Organization name is required for an organization applicant.';
        }
        if (($data['property_relationship'] ?? null) === 'authorized_representative'
            && trim((string) ($data['authorization_details'] ?? '')) === '') {
            $errors[] = 'Authorization details are required for an authorized representative.';
        }
        if (empty($data['declaration_confirmed'])) {
            $errors[] = 'The applicant declaration must be confirmed before submission.';
        }
    }

    return $errors;
}

function permit_normalize_tree_records(array $trees): array
{
    $normalized = [];

    foreach ($trees as $tree) {
        $tree = is_array($tree) ? $tree : [];
        $scientificName = trim((string) ($tree['scientific_name'] ?? ''));
        $diameter = trim((string) ($tree['diameter_cm'] ?? ''));
        $estimatedHeight = trim((string) ($tree['estimated_height_m'] ?? ''));
        $conditionNotes = trim((string) ($tree['condition_notes'] ?? ''));
        $record = [
            'common_name' => trim((string) ($tree['common_name'] ?? '')),
            'scientific_name' => $scientificName === '' ? null : $scientificName,
            'quantity' => trim((string) ($tree['quantity'] ?? '')),
            'diameter_cm' => $diameter === '' ? null : $diameter,
            'estimated_height_m' => $estimatedHeight === '' ? null : $estimatedHeight,
            'condition_notes' => $conditionNotes === '' ? null : $conditionNotes,
        ];

        if ($record['common_name'] === ''
            && $record['scientific_name'] === null
            && $record['quantity'] === ''
            && $record['diameter_cm'] === null
            && $record['estimated_height_m'] === null
            && $record['condition_notes'] === null) {
            continue;
        }

        $normalized[] = $record;
    }

    return $normalized;
}

function permit_validate_tree_records(array $trees, bool $required = true): array
{
    if ($required && $trees === []) {
        return ['At least one tree record is required.'];
    }

    $errors = [];
    foreach ($trees as $index => $tree) {
        $position = $index + 1;
        $commonName = (string) ($tree['common_name'] ?? '');
        $quantity = filter_var($tree['quantity'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($commonName === '') {
            $errors[] = 'Tree ' . $position . ' common name is required.';
        } elseif (strlen($commonName) > 150) {
            $errors[] = 'Tree ' . $position . ' common name must not exceed 150 characters.';
        }
        if (($tree['scientific_name'] ?? null) !== null && strlen((string) $tree['scientific_name']) > 150) {
            $errors[] = 'Tree ' . $position . ' scientific name must not exceed 150 characters.';
        }
        if ($quantity === false) {
            $errors[] = 'Tree ' . $position . ' quantity must be between 1 and 65535.';
        }

        foreach (['diameter_cm' => 'diameter', 'estimated_height_m' => 'estimated height'] as $field => $label) {
            if (($tree[$field] ?? null) === null) {
                continue;
            }
            $measurement = filter_var($tree[$field], FILTER_VALIDATE_FLOAT);
            if ($measurement === false || $measurement <= 0 || $measurement > 999999.99) {
                $errors[] = 'Tree ' . $position . ' ' . $label . ' must be a positive number.';
            }
        }

        if (($tree['condition_notes'] ?? null) !== null && strlen((string) $tree['condition_notes']) > 500) {
            $errors[] = 'Tree ' . $position . ' condition notes must not exceed 500 characters.';
        }
    }

    return $errors;
}

function permit_reserve_transaction_id(PDO $pdo, ?int $year = null): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('A permit transaction ID must be reserved inside a database transaction.');
    }

    $year = $year ?? (int) date('Y');
    if ($year < 2000 || $year > 9999) {
        throw new InvalidArgumentException('Permit transaction year is outside the supported range.');
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO tbl_permit_transaction_sequences (sequence_year, last_number)
         VALUES (:sequence_year, 0)'
    );
    $insert->execute([':sequence_year' => $year]);

    $select = $pdo->prepare(
        'SELECT last_number
         FROM tbl_permit_transaction_sequences
         WHERE sequence_year = :sequence_year
         FOR UPDATE'
    );
    $select->execute([':sequence_year' => $year]);
    $lastNumber = $select->fetchColumn();

    if ($lastNumber === false) {
        throw new RuntimeException('Unable to reserve a permit transaction sequence.');
    }

    $nextNumber = (int) $lastNumber + 1;
    if ($nextNumber > 999999) {
        throw new RuntimeException('The annual permit transaction sequence is exhausted.');
    }

    $update = $pdo->prepare(
        'UPDATE tbl_permit_transaction_sequences
         SET last_number = :last_number
         WHERE sequence_year = :sequence_year'
    );
    $update->execute([
        ':last_number' => $nextNumber,
        ':sequence_year' => $year,
    ]);

    return sprintf('TCP-%04d-%06d', $year, $nextNumber);
}

function permit_record_status_history(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $domain,
    ?string $previousStatus,
    string $newStatus,
    ?string $remarks = null
): int {
    $domain = trim($domain);
    $newStatus = trim($newStatus);
    $remarks = $remarks === null ? null : trim($remarks);

    if ($applicationId < 1 || $actorUserId < 1) {
        throw new InvalidArgumentException('Status history requires valid application and responsible users.');
    }
    if (!permit_status_is_supported($domain, $newStatus)) {
        throw new InvalidArgumentException('Unsupported permit workflow status.');
    }
    if ($previousStatus !== null && !permit_status_is_supported($domain, $previousStatus)) {
        throw new InvalidArgumentException('Unsupported previous permit workflow status.');
    }
    if ($remarks === '') {
        $remarks = null;
    }
    if ($remarks !== null && strlen($remarks) > 500) {
        throw new InvalidArgumentException('Status remarks must not exceed 500 characters.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_status_history
            (application_id, status_domain, previous_status, new_status,
             changed_by_user_id, remarks)
         VALUES
            (:application_id, :status_domain, :previous_status, :new_status,
             :changed_by_user_id, :remarks)'
    );
    $stmt->execute([
        ':application_id' => $applicationId,
        ':status_domain' => $domain,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':changed_by_user_id' => $actorUserId,
        ':remarks' => $remarks,
    ]);

    return (int) $pdo->lastInsertId();
}

function permit_validate_submission_key(string $submissionKey): string
{
    $submissionKey = trim($submissionKey);
    if (!preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new InvalidArgumentException('A valid permit submission key is required.');
    }

    return $submissionKey;
}

function permit_load_community_applicant(PDO $pdo, int $userId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT id, fname, mname, lname, email, contact, address, username, role, status
         FROM tbl_users
         WHERE id = :id
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $userId]);
    $applicant = $stmt->fetch();

    if (!$applicant
        || (string) $applicant['role'] !== 'community'
        || (string) $applicant['status'] !== 'active') {
        return null;
    }

    return $applicant;
}

function permit_applicant_name(array $applicant): string
{
    return trim(
        (string) $applicant['fname'] . ' '
        . ((string) ($applicant['mname'] ?? '') !== '' ? (string) $applicant['mname'] . ' ' : '')
        . (string) $applicant['lname']
    );
}

function permit_application_parameters(
    int $applicantUserId,
    string $submissionKey,
    array $applicant,
    array $application,
    array $statuses,
    ?string $transactionId,
    bool $submitted
): array {
    $submittedAt = $submitted ? date('Y-m-d H:i:s') : null;

    return [
        ':transaction_id' => $transactionId,
        ':submission_key' => $submissionKey,
        ':applicant_user_id' => $applicantUserId,
        ':applicant_name' => permit_applicant_name($applicant),
        ':applicant_contact' => $applicant['contact'] ?? null,
        ':applicant_address' => $applicant['address'] ?? null,
        ':applicant_type' => $application['applicant_type'],
        ':organization_name' => $application['organization_name'],
        ':property_relationship' => $application['property_relationship'],
        ':authorization_details' => $application['authorization_details'],
        ':property_classification' => $application['property_classification'],
        ':property_owner_name' => $application['property_owner_name'],
        ':property_address' => $application['property_address'],
        ':lot_number' => $application['lot_number'],
        ':district' => $application['district'],
        ':barangay' => $application['barangay'],
        ':municipality' => $application['municipality'],
        ':province' => $application['province'],
        ':latitude' => $application['latitude'],
        ':longitude' => $application['longitude'],
        ':cutting_purpose' => $application['cutting_purpose'],
        ':application_notes' => $application['application_notes'],
        ':application_status' => $statuses['application'],
        ':document_status' => $statuses['document'],
        ':inspection_status' => $statuses['inspection'],
        ':decision_status' => $statuses['decision'],
        ':donation_status' => $statuses['donation'],
        ':release_status' => $statuses['release'],
        ':validity_status' => $statuses['validity'],
        ':declaration_confirmed_at' => $submittedAt,
        ':submitted_at' => $submittedAt,
    ];
}

function permit_insert_application_record(PDO $pdo, array $parameters): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permit_applications
            (transaction_id, submission_key, applicant_user_id, applicant_name,
             applicant_contact, applicant_address, applicant_type, organization_name,
             property_relationship, authorization_details, property_classification,
             property_owner_name, property_address, lot_number, district, barangay,
             municipality, province, latitude, longitude, cutting_purpose,
             application_notes, application_status, document_status,
             inspection_status, decision_status, donation_status, release_status,
             validity_status, declaration_confirmed_at, submitted_at)
         VALUES
            (:transaction_id, :submission_key, :applicant_user_id, :applicant_name,
             :applicant_contact, :applicant_address, :applicant_type, :organization_name,
             :property_relationship, :authorization_details, :property_classification,
             :property_owner_name, :property_address, :lot_number, :district, :barangay,
             :municipality, :province, :latitude, :longitude, :cutting_purpose,
             :application_notes, :application_status, :document_status,
             :inspection_status, :decision_status, :donation_status, :release_status,
             :validity_status, :declaration_confirmed_at, :submitted_at)'
    );
    $stmt->execute($parameters);

    return (int) $pdo->lastInsertId();
}

function permit_update_application_record(
    PDO $pdo,
    int $applicationId,
    array $parameters,
    bool $submitting
): void {
    unset($parameters[':submission_key'], $parameters[':applicant_user_id']);
    $parameters[':application_id'] = $applicationId;

    $statusSql = '';
    if ($submitting) {
        $statusSql =
            ', application_status = :application_status,
               document_status = :document_status,
               inspection_status = :inspection_status,
               decision_status = :decision_status,
               donation_status = :donation_status,
               release_status = :release_status,
               validity_status = :validity_status,
               declaration_confirmed_at = :declaration_confirmed_at,
               submitted_at = :submitted_at';
    } else {
        foreach ([
            ':transaction_id', ':application_status', ':document_status',
            ':inspection_status', ':decision_status', ':donation_status',
            ':release_status', ':validity_status', ':declaration_confirmed_at',
            ':submitted_at',
        ] as $unusedParameter) {
            unset($parameters[$unusedParameter]);
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE tbl_permit_applications
         SET transaction_id = :transaction_id,
             applicant_name = :applicant_name,
             applicant_contact = :applicant_contact,
             applicant_address = :applicant_address,
             applicant_type = :applicant_type,
             organization_name = :organization_name,
             property_relationship = :property_relationship,
             authorization_details = :authorization_details,
             property_classification = :property_classification,
             property_owner_name = :property_owner_name,
             property_address = :property_address,
             lot_number = :lot_number,
             district = :district,
             barangay = :barangay,
             municipality = :municipality,
             province = :province,
             latitude = :latitude,
             longitude = :longitude,
             cutting_purpose = :cutting_purpose,
             application_notes = :application_notes'
        . $statusSql .
        ' WHERE id = :application_id
           AND application_status = \'draft\''
    );

    if (!$submitting) {
        $parameters[':transaction_id'] = null;
    }
    $stmt->execute($parameters);
}

function permit_replace_tree_records(PDO $pdo, int $applicationId, array $trees): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Tree records must be replaced inside a database transaction.');
    }

    $delete = $pdo->prepare('DELETE FROM tbl_permit_trees WHERE application_id = :application_id');
    $delete->execute([':application_id' => $applicationId]);

    $insert = $pdo->prepare(
        'INSERT INTO tbl_permit_trees
            (application_id, common_name, scientific_name, quantity,
             diameter_cm, estimated_height_m, condition_notes)
         VALUES
            (:application_id, :common_name, :scientific_name, :quantity,
             :diameter_cm, :estimated_height_m, :condition_notes)'
    );
    foreach ($trees as $tree) {
        $insert->execute([
            ':application_id' => $applicationId,
            ':common_name' => $tree['common_name'],
            ':scientific_name' => $tree['scientific_name'],
            ':quantity' => (int) $tree['quantity'],
            ':diameter_cm' => $tree['diameter_cm'],
            ':estimated_height_m' => $tree['estimated_height_m'],
            ':condition_notes' => $tree['condition_notes'],
        ]);
    }
}

function permit_record_submission_events(
    PDO $pdo,
    int $applicationId,
    int $applicantUserId,
    string $transactionId,
    ?string $previousApplicationStatus
): void {
    foreach (permit_initial_statuses() as $domain => $status) {
        permit_record_status_history(
            $pdo,
            $applicationId,
            $applicantUserId,
            $domain,
            $domain === 'application' ? $previousApplicationStatus : null,
            $status,
            'Initial status recorded at application submission.'
        );
    }

    record_audit_event(
        $pdo,
        $applicantUserId,
        'permit',
        'application_submitted',
        'permit_application',
        $applicationId,
        'Submitted a Tree Cutting Permit application.',
        ['transaction_id' => $transactionId]
    );
    create_notification(
        $pdo,
        $applicantUserId,
        $applicantUserId,
        'permit_status',
        'Permit application submitted',
        'Your Tree Cutting Permit application ' . $transactionId . ' was submitted successfully.',
        'permit_application',
        $applicationId
    );
}

function save_permit_draft(
    PDO $pdo,
    int $applicantUserId,
    string $submissionKey,
    array $applicationInput,
    array $treeInput,
    ?int $applicationId = null
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Permit draft saving must own its database transaction.');
    }
    $submissionKey = permit_validate_submission_key($submissionKey);
    $application = permit_normalize_application_data($applicationInput);
    $trees = permit_normalize_tree_records($treeInput);
    $errors = array_merge(
        permit_validate_application_data($application, false),
        permit_validate_tree_records($trees, false)
    );
    if ($errors !== []) {
        throw new PermitValidationException($errors);
    }

    try {
        $pdo->beginTransaction();
        $applicant = permit_load_community_applicant($pdo, $applicantUserId, true);
        if ($applicant === null) {
            throw new RuntimeException('Only an active Community user may own a permit application.');
        }

        $draft = $applicationId === null
            ? permit_find_application_by_submission_key($pdo, $applicantUserId, $submissionKey, true)
            : permit_load_application($pdo, $applicationId, true);
        $created = $draft === null;

        if ($draft !== null) {
            if ((int) $draft['applicant_user_id'] !== $applicantUserId) {
                throw new RuntimeException('The permit application is not available.');
            }
            if (!hash_equals((string) $draft['submission_key'], $submissionKey)) {
                throw new RuntimeException('The permit application submission key does not match.');
            }
            if ((string) $draft['application_status'] !== 'draft') {
                throw new RuntimeException('Submitted permit applications cannot be edited.');
            }
            $applicationId = (int) $draft['id'];
        }

        $statuses = permit_initial_statuses();
        $statuses['application'] = 'draft';
        $parameters = permit_application_parameters(
            $applicantUserId,
            $submissionKey,
            $applicant,
            $application,
            $statuses,
            null,
            false
        );

        if ($created) {
            $applicationId = permit_insert_application_record($pdo, $parameters);
            permit_record_status_history(
                $pdo,
                $applicationId,
                $applicantUserId,
                'application',
                null,
                'draft',
                'Draft application created.'
            );
        } else {
            permit_update_application_record($pdo, $applicationId, $parameters, false);
        }
        permit_replace_tree_records($pdo, $applicationId, $trees);

        record_audit_event(
            $pdo,
            $applicantUserId,
            'permit',
            $created ? 'application_draft_created' : 'application_draft_updated',
            'permit_application',
            $applicationId,
            $created ? 'Created a Tree Cutting Permit draft.' : 'Updated a Tree Cutting Permit draft.'
        );

        $pdo->commit();

        return [
            'application_id' => $applicationId,
            'transaction_id' => null,
            'created' => $created,
            'application_status' => 'draft',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function submit_permit_application(
    PDO $pdo,
    int $applicantUserId,
    string $submissionKey,
    array $applicationInput,
    array $treeInput,
    ?int $applicationId = null,
    ?int $sequenceYear = null
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Permit application submission must own its database transaction.');
    }
    $submissionKey = permit_validate_submission_key($submissionKey);
    $application = permit_normalize_application_data($applicationInput);
    $trees = permit_normalize_tree_records($treeInput);
    $errors = array_merge(
        permit_validate_application_data($application, true),
        permit_validate_tree_records($trees, true)
    );
    if ($errors !== []) {
        throw new PermitValidationException($errors);
    }

    try {
        $pdo->beginTransaction();
        $applicant = permit_load_community_applicant($pdo, $applicantUserId, true);
        if ($applicant === null) {
            throw new RuntimeException('Only an active Community user may own a permit application.');
        }
        $profileErrors = [];
        if (trim((string) ($applicant['contact'] ?? '')) === '') {
            $profileErrors[] = 'Add a contact number to your Community profile before submission.';
        }
        if (trim((string) ($applicant['address'] ?? '')) === '') {
            $profileErrors[] = 'Add an applicant address to your Community profile before submission.';
        }
        if ($profileErrors !== []) {
            throw new PermitValidationException($profileErrors);
        }

        $existing = $applicationId === null
            ? permit_find_application_by_submission_key($pdo, $applicantUserId, $submissionKey, true)
            : permit_load_application($pdo, $applicationId, true);

        if ($existing !== null) {
            if ((int) $existing['applicant_user_id'] !== $applicantUserId
                || !hash_equals((string) $existing['submission_key'], $submissionKey)) {
                throw new RuntimeException('The permit application is not available.');
            }
            if ((string) $existing['application_status'] !== 'draft') {
                if ($existing['transaction_id'] !== null) {
                    $pdo->commit();
                    return [
                        'application_id' => (int) $existing['id'],
                        'transaction_id' => (string) $existing['transaction_id'],
                        'created' => false,
                        'duplicate' => true,
                        'application_status' => (string) $existing['application_status'],
                    ];
                }
                throw new RuntimeException('This permit application cannot be submitted from its current status.');
            }
            $applicationId = (int) $existing['id'];
        }

        $transactionId = permit_reserve_transaction_id($pdo, $sequenceYear);
        $statuses = permit_initial_statuses();
        $parameters = permit_application_parameters(
            $applicantUserId,
            $submissionKey,
            $applicant,
            $application,
            $statuses,
            $transactionId,
            true
        );
        $created = $existing === null;

        if ($created) {
            $applicationId = permit_insert_application_record($pdo, $parameters);
        } else {
            permit_update_application_record($pdo, $applicationId, $parameters, true);
        }
        permit_replace_tree_records($pdo, $applicationId, $trees);
        permit_record_submission_events(
            $pdo,
            $applicationId,
            $applicantUserId,
            $transactionId,
            $created ? null : 'draft'
        );

        $pdo->commit();

        return [
            'application_id' => $applicationId,
            'transaction_id' => $transactionId,
            'created' => $created,
            'duplicate' => false,
            'application_status' => 'submitted',
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $existing = permit_find_application_by_submission_key($pdo, $applicantUserId, $submissionKey);
            if ($existing !== null && $existing['transaction_id'] !== null) {
                return [
                    'application_id' => (int) $existing['id'],
                    'transaction_id' => (string) $existing['transaction_id'],
                    'created' => false,
                    'duplicate' => true,
                    'application_status' => (string) $existing['application_status'],
                ];
            }
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function create_permit_application(
    PDO $pdo,
    int $applicantUserId,
    string $submissionKey,
    array $applicationInput,
    array $treeInput,
    ?int $sequenceYear = null
): array {
    return submit_permit_application(
        $pdo,
        $applicantUserId,
        $submissionKey,
        $applicationInput,
        $treeInput,
        null,
        $sequenceYear
    );
}

function permit_find_application_by_submission_key(
    PDO $pdo,
    int $applicantUserId,
    string $submissionKey,
    bool $forUpdate = false
): ?array
{
    $sql =
        'SELECT id, transaction_id, submission_key, applicant_user_id, application_status
         FROM tbl_permit_applications
         WHERE applicant_user_id = :applicant_user_id
           AND submission_key = :submission_key
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':applicant_user_id' => $applicantUserId,
        ':submission_key' => $submissionKey,
    ]);
    $application = $stmt->fetch();

    return $application ?: null;
}

function permit_load_application(PDO $pdo, int $applicationId, bool $forUpdate = false): ?array
{
    $sql =
        'SELECT id, transaction_id, submission_key, applicant_user_id, applicant_name,
                applicant_contact, applicant_address, property_classification,
                applicant_type, organization_name, property_relationship,
                authorization_details, property_owner_name, property_address,
                lot_number, district, barangay, municipality, province, latitude,
                longitude, cutting_purpose, application_notes, application_status, document_status,
                inspection_status, decision_status, donation_status,
                release_status, validity_status, declaration_confirmed_at,
                submitted_at, updated_at
         FROM tbl_permit_applications
         WHERE id = :id
         LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $applicationId]);
    $application = $stmt->fetch();

    return $application ?: null;
}

function permit_find_application_for_actor(PDO $pdo, int $applicationId, int $actorUserId): ?array
{
    $actorStmt = $pdo->prepare(
        'SELECT id, role, status
         FROM tbl_users
         WHERE id = :id
         LIMIT 1'
    );
    $actorStmt->execute([':id' => $actorUserId]);
    $actor = $actorStmt->fetch();

    if (!$actor || (string) $actor['status'] !== 'active') {
        return null;
    }

    $application = permit_load_application($pdo, $applicationId);
    if ($application === null) {
        return null;
    }

    $role = (string) $actor['role'];
    if ($role === 'community') {
        return (int) $application['applicant_user_id'] === $actorUserId ? $application : null;
    }
    if ((string) $application['application_status'] === 'draft') {
        return null;
    }
    if (in_array($role, ['rps', 'superadmin'], true)) {
        return $application;
    }
    if ($role === 'ems') {
        $donationStmt = $pdo->prepare(
            'SELECT 1
             FROM tbl_permit_donation_requirements
             WHERE application_id = :application_id
             LIMIT 1'
        );
        $donationStmt->execute([':application_id' => $applicationId]);

        return $donationStmt->fetchColumn() !== false ? $application : null;
    }

    return null;
}

function permit_list_applications_for_owner(PDO $pdo, int $applicantUserId): array
{
    if (permit_load_community_applicant($pdo, $applicantUserId) === null) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.transaction_id, a.application_status, a.document_status,
                a.decision_status, a.release_status, a.submitted_at, a.updated_at,
                a.property_address, a.municipality, a.province,
                COUNT(t.id) AS tree_record_count,
                COALESCE(SUM(t.quantity), 0) AS total_tree_quantity
         FROM tbl_permit_applications a
         LEFT JOIN tbl_permit_trees t ON t.application_id = a.id
         WHERE a.applicant_user_id = :applicant_user_id
         GROUP BY a.id, a.transaction_id, a.application_status, a.document_status,
                  a.decision_status, a.release_status, a.submitted_at, a.updated_at,
                  a.property_address, a.municipality, a.province
         ORDER BY COALESCE(a.submitted_at, a.updated_at) DESC, a.id DESC'
    );
    $stmt->execute([':applicant_user_id' => $applicantUserId]);

    return $stmt->fetchAll();
}

function permit_find_application_by_transaction_for_actor(
    PDO $pdo,
    string $transactionId,
    int $actorUserId
): ?array {
    $stmt = $pdo->prepare(
        'SELECT id
         FROM tbl_permit_applications
         WHERE transaction_id = :transaction_id
         LIMIT 1'
    );
    $stmt->execute([':transaction_id' => trim($transactionId)]);
    $applicationId = $stmt->fetchColumn();

    return $applicationId === false
        ? null
        : permit_find_application_for_actor($pdo, (int) $applicationId, $actorUserId);
}

function permit_tree_records_for_actor(
    PDO $pdo,
    int $applicationId,
    int $actorUserId
): ?array
{
    if (permit_find_application_for_actor($pdo, $applicationId, $actorUserId) === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, application_id, common_name, scientific_name, quantity,
                diameter_cm, estimated_height_m, condition_notes, created_at
         FROM tbl_permit_trees
         WHERE application_id = :application_id
         ORDER BY id'
    );
    $stmt->execute([':application_id' => $applicationId]);

    return $stmt->fetchAll();
}

function permit_status_audit_event(string $domain, string $newStatus): array
{
    if ($domain === 'decision') {
        return ['approval', 'permit_' . $newStatus];
    }
    if ($domain === 'donation' && in_array($newStatus, ['ems_verified', 'rps_verified'], true)) {
        return ['verification', 'donation_' . $newStatus];
    }
    if ($domain === 'inspection' && $newStatus === 'completed') {
        return ['verification', 'site_inspection_completed'];
    }
    if ($domain === 'release' && $newStatus === 'released') {
        return ['permit', 'permit_released'];
    }

    return ['permit', 'permit_' . $domain . '_status_changed'];
}

function permit_change_status(
    PDO $pdo,
    int $applicationId,
    int $actorUserId,
    string $domain,
    string $newStatus,
    ?string $remarks = null
): array {
    $domain = trim($domain);
    $newStatus = trim($newStatus);
    $remarks = $remarks === null ? null : trim($remarks);
    $columns = permit_status_columns();

    if (!isset($columns[$domain]) || !permit_status_is_supported($domain, $newStatus)) {
        throw new InvalidArgumentException('Unsupported permit status change.');
    }
    if ($domain === 'document' && in_array($newStatus, ['originals_verified', 'verified'], true)) {
        throw new RuntimeException(
            'Original-document completion must be derived by the original verification workflow.'
        );
    }
    if ($domain === 'inspection') {
        throw new RuntimeException(
            'Inspection status changes must be recorded by the site-inspection workflow.'
        );
    }
    if ($domain === 'decision') {
        throw new RuntimeException(
            'Review and decision status changes must be recorded by the permit-decision workflow.'
        );
    }
    if ($domain === 'donation') {
        throw new RuntimeException(
            'Donation receipt and verification changes must be recorded by the donation-receipt workflow.'
        );
    }
    if ($remarks !== null && strlen($remarks) > 500) {
        throw new InvalidArgumentException('Status remarks must not exceed 500 characters.');
    }

    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $actorStmt = $pdo->prepare(
            'SELECT id, role, status
             FROM tbl_users
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $actorStmt->execute([':id' => $actorUserId]);
        $actor = $actorStmt->fetch();

        if (!$actor || (string) $actor['status'] !== 'active') {
            throw new RuntimeException('A permit status change requires an active responsible user.');
        }
        if (!in_array((string) $actor['role'], permit_roles_for_status_change($domain, $newStatus), true)) {
            throw new RuntimeException('The responsible user is not authorized for this permit status change.');
        }

        $application = permit_load_application($pdo, $applicationId, true);
        if ($application === null) {
            throw new RuntimeException('The permit application does not exist.');
        }
        if ((string) $application['application_status'] === 'draft') {
            throw new RuntimeException('Draft applications must be submitted by their Community owner.');
        }

        $column = $columns[$domain];
        $previousStatus = (string) $application[$column];
        if ($previousStatus === $newStatus) {
            throw new InvalidArgumentException('The permit status is already set to the requested value.');
        }
        if (!permit_status_transition_is_allowed($domain, $previousStatus, $newStatus)) {
            throw new RuntimeException('The requested permit status transition is not allowed.');
        }
        if (permit_status_requires_verified_original_documents($domain, $newStatus)
            && (string) $application['document_status'] !== 'verified') {
            throw new RuntimeException(
                'All mandatory original hardcopy and wet-ink requirements must be verified before this workflow step.'
            );
        }
        if (permit_status_requires_passed_inspection($domain, $newStatus)
            && !in_array((string) $application['inspection_status'], ['not_required', 'passed'], true)) {
            throw new RuntimeException(
                'A passed site inspection or an authorized not-required assessment is required before this workflow step.'
            );
        }

        $update = $pdo->prepare(
            'UPDATE tbl_permit_applications
             SET ' . $column . ' = :new_status
             WHERE id = :application_id
               AND ' . $column . ' = :previous_status'
        );
        $update->execute([
            ':new_status' => $newStatus,
            ':application_id' => $applicationId,
            ':previous_status' => $previousStatus,
        ]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The permit status changed before the update completed.');
        }

        permit_record_status_history(
            $pdo,
            $applicationId,
            $actorUserId,
            $domain,
            $previousStatus,
            $newStatus,
            $remarks
        );

        [$auditCategory, $auditAction] = permit_status_audit_event($domain, $newStatus);
        record_audit_event(
            $pdo,
            $actorUserId,
            $auditCategory,
            $auditAction,
            'permit_application',
            $applicationId,
            'Changed a permit workflow status.',
            [
                'transaction_id' => (string) $application['transaction_id'],
                'status_domain' => $domain,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
            ]
        );

        $notificationType = $domain === 'donation'
            && in_array($newStatus, ['ems_verified', 'rps_verified'], true)
            ? 'donation_verification'
            : 'permit_status';
        create_notification(
            $pdo,
            (int) $application['applicant_user_id'],
            $actorUserId,
            $notificationType,
            'Permit status updated',
            'Application ' . $application['transaction_id'] . ' ' . permit_status_label($domain)
                . ' status changed from ' . permit_status_label($previousStatus)
                . ' to ' . permit_status_label($newStatus) . '.',
            'permit_application',
            $applicationId
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'application_id' => $applicationId,
            'transaction_id' => (string) $application['transaction_id'],
            'status_domain' => $domain,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
