<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$suffix = bin2hex(random_bytes(6));
$userIds = [];
$applicationIds = [];
$year = (int) date('Y');
$sequenceStmt = $pdo->prepare('SELECT last_number FROM tbl_permit_transaction_sequences WHERE sequence_year = :year');
$sequenceStmt->execute([':year' => $year]);
$previousSequence = $sequenceStmt->fetchColumn();

try {
    $insertUser = $pdo->prepare(
        'INSERT INTO tbl_users
            (fname, lname, email, contact, address, username, password, role, status)
         VALUES
            (:fname, :lname, :email, :contact, :address, :username, :password, :role, :status)'
    );
    foreach ([
        ['Owner', 'community'],
        ['Other', 'community'],
        ['Rps', 'rps'],
    ] as [$name, $role]) {
        $username = strtolower($name) . '_' . $suffix;
        $insertUser->execute([
            ':fname' => $name,
            ':lname' => 'Validation',
            ':email' => $username . '@example.test',
            ':contact' => '09171234567',
            ':address' => 'Validation Address',
            ':username' => $username,
            ':password' => password_hash('Validation123!', PASSWORD_DEFAULT),
            ':role' => $role,
            ':status' => 'active',
        ]);
        $userIds[$role === 'community' && $name === 'Owner' ? 'owner' : strtolower($name)] = (int) $pdo->lastInsertId();
    }

    $partialTreeRejected = false;
    try {
        save_permit_draft(
            $pdo,
            $userIds['owner'],
            new_permit_submission_key(),
            [],
            [['common_name' => '', 'quantity' => '2']]
        );
    } catch (PermitValidationException $e) {
        $partialTreeRejected = str_contains($e->getMessage(), 'common name is required');
    }
    check($partialTreeRejected, 'A partially completed draft tree row is rejected.');

    $unauthorizedRoleRejected = false;
    try {
        save_permit_draft($pdo, $userIds['rps'], new_permit_submission_key(), [], []);
    } catch (RuntimeException $e) {
        $unauthorizedRoleRejected = true;
    }
    check($unauthorizedRoleRejected, 'A non-Community role cannot create a draft.');

    $key = new_permit_submission_key();
    $draft = save_permit_draft(
        $pdo,
        $userIds['owner'],
        $key,
        ['applicant_type' => 'individual'],
        [['common_name' => '', 'quantity' => '']]
    );
    $applicationIds[] = (int) $draft['application_id'];
    check($draft['transaction_id'] === null, 'Draft creation does not generate a transaction ID.');

    $loadedDraft = permit_load_application($pdo, (int) $draft['application_id']);
    check($loadedDraft !== null && $loadedDraft['application_status'] === 'draft', 'Draft status is stored.');
    check($loadedDraft['submitted_at'] === null, 'Draft has no submission timestamp.');
    check(permit_find_application_for_actor($pdo, (int) $draft['application_id'], $userIds['owner']) !== null, 'Owner can access the draft.');
    check(permit_find_application_for_actor($pdo, (int) $draft['application_id'], $userIds['other']) === null, 'Another Community user cannot access the draft.');
    check(permit_find_application_for_actor($pdo, (int) $draft['application_id'], $userIds['rps']) === null, 'Processing roles cannot access an unsubmitted draft.');

    $countForEntity = static function (string $table, int $applicationId) use ($pdo): int {
        $column = $table === 'tbl_notifications' || $table === 'tbl_audit_trail' ? 'entity_id' : 'application_id';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
        $stmt->execute([':id' => $applicationId]);
        return (int) $stmt->fetchColumn();
    };
    check($countForEntity('tbl_permit_status_history', (int) $draft['application_id']) === 1, 'Draft creation records one initial draft history entry.');
    check($countForEntity('tbl_notifications', (int) $draft['application_id']) === 0, 'Draft creation does not create a submission notification.');
    check($countForEntity('tbl_audit_trail', (int) $draft['application_id']) === 1, 'Draft creation creates one permit audit entry.');

    $requiredRejected = false;
    try {
        submit_permit_application($pdo, $userIds['owner'], $key, [], [], (int) $draft['application_id']);
    } catch (PermitValidationException $e) {
        $requiredRejected = str_contains($e->getMessage(), 'District is required')
            && str_contains($e->getMessage(), 'declaration');
    }
    check($requiredRejected, 'Final submission rejects missing required fields and declaration.');

    $invalidLocationRejected = false;
    try {
        submit_permit_application(
            $pdo,
            $userIds['owner'],
            $key,
            [
                'applicant_type' => 'individual',
                'property_relationship' => 'owner',
                'property_classification' => 'private_property',
                'property_owner_name' => 'Owner Validation',
                'property_address' => 'Lot 1',
                'district' => '3',
                'barangay' => 'Poblacion',
                'municipality' => 'Sta. Cruz',
                'province' => 'Laguna',
                'latitude' => '91',
                'cutting_purpose' => 'Safety',
                'declaration_confirmed' => '1',
            ],
            [['common_name' => 'Narra', 'quantity' => '1']],
            (int) $draft['application_id']
        );
    } catch (PermitValidationException $e) {
        $invalidLocationRejected = str_contains($e->getMessage(), 'Latitude');
    }
    check($invalidLocationRejected, 'Invalid location coordinates are rejected.');

    $invalidTreeRejected = false;
    try {
        submit_permit_application(
            $pdo,
            $userIds['owner'],
            $key,
            [
                'applicant_type' => 'individual',
                'property_relationship' => 'owner',
                'property_classification' => 'private_property',
                'property_owner_name' => 'Owner Validation',
                'property_address' => 'Lot 1',
                'district' => '3',
                'barangay' => 'Poblacion',
                'municipality' => 'Sta. Cruz',
                'province' => 'Laguna',
                'cutting_purpose' => 'Safety',
                'declaration_confirmed' => '1',
            ],
            [['common_name' => 'Narra', 'quantity' => '0']],
            (int) $draft['application_id']
        );
    } catch (PermitValidationException $e) {
        $invalidTreeRejected = str_contains($e->getMessage(), 'quantity');
    }
    check($invalidTreeRejected, 'Invalid tree quantities are rejected.');

    $invalidMeasurementRejected = false;
    try {
        $measurementInput = [
            'applicant_type' => 'individual',
            'property_relationship' => 'owner',
            'property_classification' => 'private_property',
            'property_owner_name' => 'Owner Validation',
            'property_address' => 'Lot 1',
            'district' => '3',
            'barangay' => 'Poblacion',
            'municipality' => 'Sta. Cruz',
            'province' => 'Laguna',
            'cutting_purpose' => 'Safety',
            'declaration_confirmed' => '1',
        ];
        submit_permit_application(
            $pdo,
            $userIds['owner'],
            $key,
            $measurementInput,
            [['common_name' => 'Narra', 'quantity' => '1', 'diameter_cm' => '0']],
            (int) $draft['application_id']
        );
    } catch (PermitValidationException $e) {
        $invalidMeasurementRejected = str_contains($e->getMessage(), 'diameter must be a positive number');
    }
    check($invalidMeasurementRejected, 'Invalid tree measurements are rejected.');

    $validApplication = [
        'applicant_type' => 'organization',
        'organization_name' => 'Validation Organization',
        'property_relationship' => 'authorized_representative',
        'authorization_details' => 'Written authority from the owner.',
        'property_classification' => 'private_property',
        'property_owner_name' => 'Property Owner',
        'property_address' => 'Lot 1, Validation Road',
        'lot_number' => 'LOT-1',
        'district' => 'District 3',
        'barangay' => 'Poblacion',
        'municipality' => 'Sta. Cruz',
        'province' => 'Laguna',
        'latitude' => '14.2814',
        'longitude' => '121.4161',
        'cutting_purpose' => 'Remove hazardous trees near a structure.',
        'application_notes' => 'Validation record.',
        'declaration_confirmed' => '1',
    ];
    $validTrees = [
        ['common_name' => 'Narra', 'scientific_name' => 'Pterocarpus indicus', 'quantity' => '2', 'diameter_cm' => '42.5', 'estimated_height_m' => '12.3'],
        ['common_name' => 'Mahogany', 'quantity' => '1', 'condition_notes' => 'Leaning toward structure'],
    ];

    $updatedDraft = save_permit_draft(
        $pdo,
        $userIds['owner'],
        $key,
        $validApplication,
        $validTrees,
        (int) $draft['application_id']
    );
    check($updatedDraft['created'] === false, 'Owner can update an existing draft.');
    check(count(permit_tree_records_for_actor($pdo, (int) $draft['application_id'], $userIds['owner']) ?? []) === 2, 'Multiple trees are stored as related records.');

    $submitted = submit_permit_application(
        $pdo,
        $userIds['owner'],
        $key,
        $validApplication,
        $validTrees,
        (int) $draft['application_id'],
        $year
    );
    check((bool) preg_match('/^TCP-' . $year . '-\d{6}$/', (string) $submitted['transaction_id']), 'Final submission generates the expected transaction ID.');

    $loadedSubmitted = permit_load_application($pdo, (int) $draft['application_id']);
    check($loadedSubmitted !== null && $loadedSubmitted['application_status'] === 'submitted', 'Final submission changes the application status.');
    check($loadedSubmitted['submitted_at'] !== null && $loadedSubmitted['declaration_confirmed_at'] !== null, 'Submission and declaration timestamps are recorded.');
    check($countForEntity('tbl_permit_status_history', (int) $draft['application_id']) === 8, 'Draft plus seven submission status-history entries are present.');
    check($countForEntity('tbl_notifications', (int) $draft['application_id']) === 1, 'Submission creates one applicant notification.');
    check($countForEntity('tbl_audit_trail', (int) $draft['application_id']) === 3, 'Draft create, update, and submit actions are audited.');
    check(permit_find_application_for_actor($pdo, (int) $draft['application_id'], $userIds['rps']) !== null, 'RPS can access the application only after submission.');

    $historyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_permit_status_history
         WHERE application_id = :id AND status_domain = 'application'
           AND previous_status = 'draft' AND new_status = 'submitted'"
    );
    $historyStmt->execute([':id' => (int) $draft['application_id']]);
    check((int) $historyStmt->fetchColumn() === 1, 'Submission records the draft-to-submitted transition.');

    $beforeRetryHistory = $countForEntity('tbl_permit_status_history', (int) $draft['application_id']);
    $beforeRetryAudit = $countForEntity('tbl_audit_trail', (int) $draft['application_id']);
    $beforeRetryNotifications = $countForEntity('tbl_notifications', (int) $draft['application_id']);
    $sequenceStmt->execute([':year' => $year]);
    $beforeRetrySequence = (int) $sequenceStmt->fetchColumn();
    $retry = submit_permit_application(
        $pdo,
        $userIds['owner'],
        $key,
        $validApplication,
        $validTrees,
        (int) $draft['application_id'],
        $year
    );
    $sequenceStmt->execute([':year' => $year]);
    check(!empty($retry['duplicate']) && $retry['transaction_id'] === $submitted['transaction_id'], 'Duplicate submission returns the original transaction ID.');
    check((int) $sequenceStmt->fetchColumn() === $beforeRetrySequence, 'Duplicate submission does not consume another transaction number.');
    check($countForEntity('tbl_permit_status_history', (int) $draft['application_id']) === $beforeRetryHistory, 'Duplicate submission creates no extra status history.');
    check($countForEntity('tbl_audit_trail', (int) $draft['application_id']) === $beforeRetryAudit, 'Duplicate submission creates no extra audit entry.');
    check($countForEntity('tbl_notifications', (int) $draft['application_id']) === $beforeRetryNotifications, 'Duplicate submission creates no extra notification.');

    $submittedEditRejected = false;
    try {
        save_permit_draft($pdo, $userIds['owner'], $key, $validApplication, $validTrees, (int) $draft['application_id']);
    } catch (RuntimeException $e) {
        $submittedEditRejected = true;
    }
    check($submittedEditRejected, 'Submitted applications cannot be saved as drafts.');

    $ownerList = permit_list_applications_for_owner($pdo, $userIds['owner']);
    $otherList = permit_list_applications_for_owner($pdo, $userIds['other']);
    check(count($ownerList) === 1 && (int) $ownerList[0]['id'] === (int) $draft['application_id'], 'Owner registry lists the submitted application.');
    check($otherList === [], 'Another Community user registry does not expose the application.');

    echo "VALIDATION COMPLETE\n";
} finally {
    foreach (array_reverse($applicationIds) as $applicationId) {
        $notificationDelete = $pdo->prepare("DELETE FROM tbl_notifications WHERE entity_type = 'permit_application' AND entity_id = :id");
        $auditDelete = $pdo->prepare("DELETE FROM tbl_audit_trail WHERE entity_type = 'permit_application' AND entity_id = :id");
        $historyDelete = $pdo->prepare('DELETE FROM tbl_permit_status_history WHERE application_id = :id');
        $treeDelete = $pdo->prepare('DELETE FROM tbl_permit_trees WHERE application_id = :id');
        $applicationDelete = $pdo->prepare('DELETE FROM tbl_permit_applications WHERE id = :id');
        foreach ([$notificationDelete, $auditDelete, $historyDelete, $treeDelete, $applicationDelete] as $delete) {
            $delete->execute([':id' => $applicationId]);
        }
    }
    if ($previousSequence === false) {
        $deleteSequence = $pdo->prepare('DELETE FROM tbl_permit_transaction_sequences WHERE sequence_year = :year');
        $deleteSequence->execute([':year' => $year]);
    } else {
        $restoreSequence = $pdo->prepare('UPDATE tbl_permit_transaction_sequences SET last_number = :last_number WHERE sequence_year = :year');
        $restoreSequence->execute([':last_number' => (int) $previousSequence, ':year' => $year]);
    }
    foreach (array_reverse($userIds) as $userId) {
        $deleteUser = $pdo->prepare('DELETE FROM tbl_users WHERE id = :id');
        $deleteUser->execute([':id' => $userId]);
    }
}
