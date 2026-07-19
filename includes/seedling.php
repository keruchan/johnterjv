<?php
/**
 * Seedling inventory and the public seedling-request program.
 *
 * This module is intentionally independent of the Tree Cutting Permit workflow:
 * permit donations flow Community -> EMS as an approval requirement, while these
 * requests flow EMS -> Community as a public giveaway. Nothing here reads or
 * writes permit state.
 *
 * Stock is real: it is deducted from tbl_seedling_inventory at fulfilment and
 * every change is recorded in the append-only tbl_seedling_stock_movements
 * ledger. Every mutation is transactional and reuses the shared audit and
 * notification writers.
 *
 * Request lifecycle:
 *   submitted -> under_review -> approved -> ready_for_pickup -> claimed
 *             \-> declined (terminal)          (stock deducted here)
 *   submitted/under_review -> cancelled (by the requesting owner, terminal)
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class SeedlingValidationException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Vocabulary and policy
// ---------------------------------------------------------------------------

function new_seedling_submission_key(): string
{
    return bin2hex(random_bytes(32));
}

function seedling_max_per_request(): int
{
    return defined('CERTREEFY_SEEDLING_MAX_PER_REQUEST')
        ? (int) CERTREEFY_SEEDLING_MAX_PER_REQUEST
        : 50;
}

function seedling_request_statuses(): array
{
    return [
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'approved' => 'Approved',
        'ready_for_pickup' => 'Ready for pickup',
        'claimed' => 'Claimed',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
    ];
}

function seedling_request_status_label(string $status): string
{
    return seedling_request_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function seedling_request_status_badge(string $status): string
{
    return match ($status) {
        'submitted' => 'text-bg-secondary',
        'under_review' => 'text-bg-info',
        'approved' => 'text-bg-primary',
        'ready_for_pickup' => 'text-bg-warning',
        'claimed' => 'text-bg-success',
        'declined', 'cancelled' => 'text-bg-danger',
        default => 'text-bg-light border',
    };
}

/** Allowed request transitions. Stock is deducted on approved -> ready_for_pickup. */
function seedling_request_transition_is_allowed(string $from, string $to): bool
{
    $allowed = [
        'submitted' => ['under_review', 'approved', 'declined', 'cancelled'],
        'under_review' => ['approved', 'declined', 'cancelled'],
        'approved' => ['ready_for_pickup', 'declined'],
        'ready_for_pickup' => ['claimed'],
        'claimed' => [],
        'declined' => [],
        'cancelled' => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
}

function seedling_request_is_terminal(string $status): bool
{
    return in_array($status, ['claimed', 'declined', 'cancelled'], true);
}

function seedling_movement_types(): array
{
    return ['incoming', 'released', 'adjustment'];
}

// ---------------------------------------------------------------------------
// Actors
// ---------------------------------------------------------------------------

/** Inventory and request processing is EMS-only. */
function seedling_ems_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' && (string) $actor['role'] === 'ems'
        ? $actor
        : null;
}

/** Requests may only be created by an active Community user. */
function seedling_community_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, fname, lname, contact, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();

    return $actor && (string) $actor['status'] === 'active' && (string) $actor['role'] === 'community'
        ? $actor
        : null;
}

/** Active EMS users, for notifying the section about new requests. */
function seedling_ems_notification_recipients(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id FROM tbl_users WHERE role = 'ems' AND status = 'active'"
    );

    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

// ---------------------------------------------------------------------------
// Internal writers
// ---------------------------------------------------------------------------

function seedling_record_status_history(
    PDO $pdo,
    int $requestId,
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
        'INSERT INTO tbl_seedling_request_status_history
            (request_id, previous_status, new_status, changed_by_user_id, remarks)
         VALUES (:request_id, :previous_status, :new_status, :actor, :remarks)'
    );
    $stmt->execute([
        ':request_id' => $requestId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':actor' => $actorUserId,
        ':remarks' => $remarks !== '' ? $remarks : null,
    ]);
}

/**
 * Applies a stock change and appends the ledger entry. Callers must already hold
 * a row lock on the inventory record. Negative deltas may not drive stock below
 * zero.
 */
function seedling_apply_stock_movement(
    PDO $pdo,
    int $inventoryId,
    int $actorUserId,
    string $movementType,
    int $quantityDelta,
    ?int $requestId,
    string $reason
): int {
    if (!in_array($movementType, seedling_movement_types(), true)) {
        throw new SeedlingValidationException('Unsupported stock movement type.');
    }
    if ($quantityDelta === 0) {
        throw new SeedlingValidationException('A stock movement must change the quantity.');
    }

    $current = $pdo->prepare(
        'SELECT available_quantity FROM tbl_seedling_inventory WHERE id = :id FOR UPDATE'
    );
    $current->execute([':id' => $inventoryId]);
    $available = $current->fetchColumn();
    if ($available === false) {
        throw new SeedlingValidationException('The seedling species no longer exists.');
    }

    $quantityAfter = (int) $available + $quantityDelta;
    if ($quantityAfter < 0) {
        throw new SeedlingValidationException(
            'Insufficient stock: only ' . (int) $available . ' seedling(s) available.'
        );
    }

    $update = $pdo->prepare(
        'UPDATE tbl_seedling_inventory
         SET available_quantity = :quantity_after
         WHERE id = :id AND available_quantity = :expected'
    );
    $update->execute([
        ':quantity_after' => $quantityAfter,
        ':id' => $inventoryId,
        ':expected' => (int) $available,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The seedling stock changed before the update completed.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO tbl_seedling_stock_movements
            (inventory_id, request_id, movement_type, quantity_delta, quantity_after, reason, recorded_by_user_id)
         VALUES (:inventory_id, :request_id, :movement_type, :delta, :after, :reason, :actor)'
    );
    $insert->execute([
        ':inventory_id' => $inventoryId,
        ':request_id' => $requestId,
        ':movement_type' => $movementType,
        ':delta' => $quantityDelta,
        ':after' => $quantityAfter,
        ':reason' => $reason !== '' ? $reason : null,
        ':actor' => $actorUserId,
    ]);

    return $quantityAfter;
}

/** Reserves the next SR-YYYY-###### reference under an annual row lock. */
function seedling_reserve_request_reference(PDO $pdo, ?int $year = null): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('A seedling request reference must be reserved inside a transaction.');
    }
    $year = $year ?? (int) date('Y');

    $pdo->prepare(
        'INSERT IGNORE INTO tbl_seedling_request_sequences (sequence_year, last_number) VALUES (:year, 0)'
    )->execute([':year' => $year]);

    $select = $pdo->prepare(
        'SELECT last_number FROM tbl_seedling_request_sequences WHERE sequence_year = :year FOR UPDATE'
    );
    $select->execute([':year' => $year]);
    $lastNumber = $select->fetchColumn();
    if ($lastNumber === false) {
        throw new RuntimeException('Unable to reserve a seedling request sequence.');
    }
    $nextNumber = (int) $lastNumber + 1;

    $pdo->prepare(
        'UPDATE tbl_seedling_request_sequences SET last_number = :next WHERE sequence_year = :year'
    )->execute([':next' => $nextNumber, ':year' => $year]);

    return sprintf('SR-%04d-%06d', $year, $nextNumber);
}

// ---------------------------------------------------------------------------
// Inventory: EMS management
// ---------------------------------------------------------------------------

function seedling_inventory_list(PDO $pdo, bool $activeOnly = false): array
{
    $sql =
        'SELECT i.*, CONCAT(u.fname, \' \', u.lname) AS created_by_name,
                (i.available_quantity <= i.low_stock_threshold) AS is_low_stock
         FROM tbl_seedling_inventory i
         INNER JOIN tbl_users u ON u.id = i.created_by_user_id';
    if ($activeOnly) {
        $sql .= ' WHERE i.is_active = 1';
    }
    $sql .= ' ORDER BY i.common_name';

    return $pdo->query($sql)->fetchAll();
}

function seedling_inventory_find(PDO $pdo, int $inventoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_seedling_inventory WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $inventoryId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Aggregate figures for the EMS dashboard. */
function seedling_inventory_summary(PDO $pdo): array
{
    $row = $pdo->query(
        'SELECT COALESCE(SUM(available_quantity), 0) AS total_available,
                SUM(CASE WHEN available_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock_species,
                COUNT(*) AS species_count
         FROM tbl_seedling_inventory
         WHERE is_active = 1'
    )->fetch();
    $pending = (int) $pdo->query(
        "SELECT COUNT(*) FROM tbl_seedling_requests
         WHERE current_status IN ('submitted', 'under_review', 'approved')"
    )->fetchColumn();

    return [
        'total_available' => (int) ($row['total_available'] ?? 0),
        'low_stock_species' => (int) ($row['low_stock_species'] ?? 0),
        'species_count' => (int) ($row['species_count'] ?? 0),
        'pending_requests' => $pending,
    ];
}

function seedling_stock_movements(PDO $pdo, int $limit = 25, ?int $inventoryId = null): array
{
    $sql =
        'SELECT m.*, i.common_name, CONCAT(u.fname, \' \', u.lname) AS recorded_by_name,
                r.request_reference
         FROM tbl_seedling_stock_movements m
         INNER JOIN tbl_seedling_inventory i ON i.id = m.inventory_id
         INNER JOIN tbl_users u ON u.id = m.recorded_by_user_id
         LEFT JOIN tbl_seedling_requests r ON r.id = m.request_id';
    if ($inventoryId !== null) {
        $sql .= ' WHERE m.inventory_id = :inventory_id';
    }
    $sql .= ' ORDER BY m.id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    if ($inventoryId !== null) {
        $stmt->bindValue(':inventory_id', $inventoryId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Filterable read of the stock-movement ledger for the EMS Stock Movement page:
 * optional species, movement type, and inclusive date range, newest first.
 */
function seedling_stock_movement_ledger(PDO $pdo, array $filters = [], int $limit = 500): array
{
    $where = [];
    $params = [];

    $inventoryValue = trim((string) ($filters['inventory_id'] ?? ''));
    if ($inventoryValue !== '' && ctype_digit($inventoryValue)) {
        $where[] = 'm.inventory_id = :inventory_id';
        $params[':inventory_id'] = (int) $inventoryValue;
    }
    $type = trim((string) ($filters['movement_type'] ?? ''));
    if ($type !== '' && in_array($type, seedling_movement_types(), true)) {
        $where[] = 'm.movement_type = :movement_type';
        $params[':movement_type'] = $type;
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'm.created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'm.created_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $sql =
        'SELECT m.*, i.common_name, CONCAT(u.fname, \' \', u.lname) AS recorded_by_name,
                r.request_reference
         FROM tbl_seedling_stock_movements m
         INNER JOIN tbl_seedling_inventory i ON i.id = m.inventory_id
         INNER JOIN tbl_users u ON u.id = m.recorded_by_user_id
         LEFT JOIN tbl_seedling_requests r ON r.id = m.request_id'
        . $whereSql
        . ' ORDER BY m.id DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/** Human label for a stock movement type. */
function seedling_movement_type_label(string $type): string
{
    return match ($type) {
        'incoming' => 'Incoming',
        'released' => 'Released',
        'adjustment' => 'Correction',
        default => ucfirst($type),
    };
}

/** Creates a species record with its opening stock (recorded as an incoming movement). */
function seedling_create_species(PDO $pdo, int $actorUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Species creation must own its database transaction.');
    }
    $commonName = trim((string) ($input['common_name'] ?? ''));
    $scientificName = trim((string) ($input['scientific_name'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    if ($commonName === '' || strlen($commonName) > 150) {
        throw new SeedlingValidationException('Enter a species common name of up to 150 characters.');
    }
    if (strlen($scientificName) > 150) {
        throw new SeedlingValidationException('The scientific name must not exceed 150 characters.');
    }
    if (strlen($notes) > 500) {
        throw new SeedlingValidationException('Notes must not exceed 500 characters.');
    }

    $openingValue = trim((string) ($input['available_quantity'] ?? '0'));
    if (!ctype_digit($openingValue)) {
        throw new SeedlingValidationException('Opening stock must be a whole number of seedlings.');
    }
    $thresholdValue = trim((string) ($input['low_stock_threshold'] ?? '0'));
    if (!ctype_digit($thresholdValue)) {
        throw new SeedlingValidationException('The low-stock threshold must be a whole number.');
    }
    $openingStock = (int) $openingValue;
    $threshold = (int) $thresholdValue;

    try {
        $pdo->beginTransaction();
        if (seedling_ems_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage seedling inventory.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_seedling_inventory
                (common_name, scientific_name, available_quantity, low_stock_threshold, notes, created_by_user_id)
             VALUES (:common_name, :scientific_name, 0, :threshold, :notes, :actor)'
        );
        try {
            $insert->execute([
                ':common_name' => $commonName,
                ':scientific_name' => $scientificName !== '' ? $scientificName : null,
                ':threshold' => $threshold,
                ':notes' => $notes !== '' ? $notes : null,
                ':actor' => $actorUserId,
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new SeedlingValidationException('That species is already in the inventory.');
            }
            throw $e;
        }
        $inventoryId = (int) $pdo->lastInsertId();

        if ($openingStock > 0) {
            seedling_apply_stock_movement(
                $pdo,
                $inventoryId,
                $actorUserId,
                'incoming',
                $openingStock,
                null,
                'Opening stock recorded when the species was added.'
            );
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'seedling_species_added',
            'seedling_inventory',
            $inventoryId,
            'Added a seedling species to the inventory.',
            [
                'common_name' => $commonName,
                'opening_stock' => $openingStock,
                'low_stock_threshold' => $threshold,
            ]
        );

        $pdo->commit();

        return [
            'inventory_id' => $inventoryId,
            'common_name' => $commonName,
            'available_quantity' => $openingStock,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Records an incoming delivery or a manual correction against a species. */
function seedling_adjust_stock(PDO $pdo, int $actorUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Stock adjustment must own its database transaction.');
    }
    $inventoryValue = trim((string) ($input['inventory_id'] ?? ''));
    if (!ctype_digit($inventoryValue)) {
        throw new SeedlingValidationException('Select a seedling species.');
    }
    $inventoryId = (int) $inventoryValue;

    $movementType = trim((string) ($input['movement_type'] ?? ''));
    if (!in_array($movementType, ['incoming', 'adjustment'], true)) {
        throw new SeedlingValidationException('Select whether this is incoming stock or a correction.');
    }

    $quantityValue = trim((string) ($input['quantity'] ?? ''));
    $isNegative = str_starts_with($quantityValue, '-');
    $magnitude = $isNegative ? substr($quantityValue, 1) : $quantityValue;
    if (!ctype_digit($magnitude) || (int) $magnitude < 1) {
        throw new SeedlingValidationException('Enter a non-zero whole quantity.');
    }
    if ($movementType === 'incoming' && $isNegative) {
        throw new SeedlingValidationException('Incoming stock must be a positive quantity.');
    }
    $delta = $isNegative ? -((int) $magnitude) : (int) $magnitude;

    $reason = trim((string) ($input['reason'] ?? ''));
    if (strlen($reason) > 500) {
        throw new SeedlingValidationException('The reason must not exceed 500 characters.');
    }
    if ($movementType === 'adjustment' && $reason === '') {
        throw new SeedlingValidationException('A stock correction requires a reason.');
    }

    try {
        $pdo->beginTransaction();
        if (seedling_ems_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage seedling inventory.');
        }
        $species = $pdo->prepare('SELECT id, common_name FROM tbl_seedling_inventory WHERE id = :id FOR UPDATE');
        $species->execute([':id' => $inventoryId]);
        $speciesRow = $species->fetch();
        if (!$speciesRow) {
            throw new SeedlingValidationException('The selected seedling species does not exist.');
        }

        $quantityAfter = seedling_apply_stock_movement(
            $pdo,
            $inventoryId,
            $actorUserId,
            $movementType,
            $delta,
            null,
            $reason
        );

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            $movementType === 'incoming' ? 'seedling_stock_received' : 'seedling_stock_adjusted',
            'seedling_inventory',
            $inventoryId,
            $movementType === 'incoming'
                ? 'Recorded incoming seedling stock.'
                : 'Recorded a seedling stock correction.',
            [
                'common_name' => (string) $speciesRow['common_name'],
                'quantity_delta' => $delta,
                'quantity_after' => $quantityAfter,
                'reason' => $reason !== '' ? $reason : null,
            ]
        );

        $pdo->commit();

        return [
            'inventory_id' => $inventoryId,
            'quantity_delta' => $delta,
            'quantity_after' => $quantityAfter,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Updates species metadata (not stock; stock only moves through the ledger). */
function seedling_update_species(PDO $pdo, int $actorUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Species update must own its database transaction.');
    }
    $inventoryValue = trim((string) ($input['inventory_id'] ?? ''));
    if (!ctype_digit($inventoryValue)) {
        throw new SeedlingValidationException('Select a seedling species.');
    }
    $inventoryId = (int) $inventoryValue;

    $scientificName = trim((string) ($input['scientific_name'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $thresholdValue = trim((string) ($input['low_stock_threshold'] ?? ''));
    if (!ctype_digit($thresholdValue)) {
        throw new SeedlingValidationException('The low-stock threshold must be a whole number.');
    }
    if (strlen($scientificName) > 150) {
        throw new SeedlingValidationException('The scientific name must not exceed 150 characters.');
    }
    if (strlen($notes) > 500) {
        throw new SeedlingValidationException('Notes must not exceed 500 characters.');
    }
    $isActive = trim((string) ($input['is_active'] ?? '1')) === '1' ? 1 : 0;

    try {
        $pdo->beginTransaction();
        if (seedling_ems_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to manage seedling inventory.');
        }
        $update = $pdo->prepare(
            'UPDATE tbl_seedling_inventory
             SET scientific_name = :scientific_name,
                 low_stock_threshold = :threshold,
                 notes = :notes,
                 is_active = :is_active
             WHERE id = :id'
        );
        $update->execute([
            ':scientific_name' => $scientificName !== '' ? $scientificName : null,
            ':threshold' => (int) $thresholdValue,
            ':notes' => $notes !== '' ? $notes : null,
            ':is_active' => $isActive,
            ':id' => $inventoryId,
        ]);
        if ($update->rowCount() < 1) {
            throw new SeedlingValidationException('No changes were made to the species record.');
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'seedling_species_updated',
            'seedling_inventory',
            $inventoryId,
            'Updated a seedling species record.',
            ['low_stock_threshold' => (int) $thresholdValue, 'is_active' => $isActive === 1]
        );

        $pdo->commit();

        return ['inventory_id' => $inventoryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Requests: Community submission
// ---------------------------------------------------------------------------

/**
 * Submits a seedling request. Idempotent per requester via submission_key, so a
 * double-posted form cannot create two requests.
 */
function seedling_submit_request(PDO $pdo, int $requesterUserId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Request submission must own its database transaction.');
    }
    $purpose = trim((string) ($input['planting_purpose'] ?? ''));
    $location = trim((string) ($input['planting_location'] ?? ''));
    if ($purpose === '' || strlen($purpose) > 500) {
        throw new SeedlingValidationException('Describe the planting purpose in up to 500 characters.');
    }
    if ($location === '' || strlen($location) > 500) {
        throw new SeedlingValidationException('Enter the planting location in up to 500 characters.');
    }

    $pickupDate = trim((string) ($input['preferred_pickup_date'] ?? ''));
    if ($pickupDate !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $pickupDate);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $pickupDate) {
            throw new SeedlingValidationException('Enter a valid preferred pickup date.');
        }
        if ($date < new DateTimeImmutable('today')) {
            throw new SeedlingValidationException('The preferred pickup date cannot be in the past.');
        }
    }

    $submissionKey = trim((string) ($input['submission_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new SeedlingValidationException('The submission is invalid. Refresh the page and try again.');
    }

    // Items: parallel arrays of inventory_id[] and quantity[].
    $inventoryIds = (array) ($input['inventory_id'] ?? []);
    $quantities = (array) ($input['quantity'] ?? []);
    $items = [];
    $total = 0;
    foreach ($inventoryIds as $index => $rawInventoryId) {
        $rawInventoryId = trim((string) $rawInventoryId);
        $rawQuantity = trim((string) ($quantities[$index] ?? ''));
        if ($rawInventoryId === '' && $rawQuantity === '') {
            continue;
        }
        if (!ctype_digit($rawInventoryId)) {
            throw new SeedlingValidationException('Select a valid seedling species for every row.');
        }
        if (!ctype_digit($rawQuantity) || (int) $rawQuantity < 1) {
            throw new SeedlingValidationException('Enter a quantity of at least 1 for every selected species.');
        }
        $inventoryId = (int) $rawInventoryId;
        if (isset($items[$inventoryId])) {
            throw new SeedlingValidationException('Each species may only be listed once per request.');
        }
        $items[$inventoryId] = (int) $rawQuantity;
        $total += (int) $rawQuantity;
    }

    if ($items === []) {
        throw new SeedlingValidationException('Add at least one seedling species to your request.');
    }
    $cap = seedling_max_per_request();
    if ($total > $cap) {
        throw new SeedlingValidationException(
            'A single request may not exceed ' . $cap . ' seedlings in total (you requested ' . $total . ').'
        );
    }

    try {
        $pdo->beginTransaction();
        $requester = seedling_community_actor($pdo, $requesterUserId, true);
        if ($requester === null) {
            throw new RuntimeException('Only an active Community account may request seedlings.');
        }

        // Idempotency: an identical resubmission returns the existing request.
        $existing = $pdo->prepare(
            'SELECT id, request_reference FROM tbl_seedling_requests
             WHERE requester_user_id = :requester AND submission_key = :key
             LIMIT 1'
        );
        $existing->execute([':requester' => $requesterUserId, ':key' => $submissionKey]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            $pdo->commit();

            return [
                'request_id' => (int) $existingRow['id'],
                'request_reference' => (string) $existingRow['request_reference'],
                'total_requested' => $total,
                'duplicate' => true,
            ];
        }

        // Validate every species is active and still exists.
        $speciesNames = [];
        foreach (array_keys($items) as $inventoryId) {
            $stmt = $pdo->prepare(
                'SELECT id, common_name, is_active FROM tbl_seedling_inventory WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $inventoryId]);
            $species = $stmt->fetch();
            if (!$species || (int) $species['is_active'] !== 1) {
                throw new SeedlingValidationException('One of the selected species is unavailable.');
            }
            $speciesNames[$inventoryId] = (string) $species['common_name'];
        }

        $reference = seedling_reserve_request_reference($pdo);
        $requesterName = trim((string) $requester['fname'] . ' ' . (string) $requester['lname']);

        $insert = $pdo->prepare(
            'INSERT INTO tbl_seedling_requests
                (request_reference, submission_key, requester_user_id, requester_name, requester_contact,
                 planting_purpose, planting_location, preferred_pickup_date, current_status)
             VALUES
                (:reference, :key, :requester, :name, :contact,
                 :purpose, :location, :pickup, \'submitted\')'
        );
        $insert->execute([
            ':reference' => $reference,
            ':key' => $submissionKey,
            ':requester' => $requesterUserId,
            ':name' => $requesterName,
            ':contact' => $requester['contact'] !== null ? (string) $requester['contact'] : null,
            ':purpose' => $purpose,
            ':location' => $location,
            ':pickup' => $pickupDate !== '' ? $pickupDate : null,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare(
            'INSERT INTO tbl_seedling_request_items
                (request_id, inventory_id, common_name, quantity_requested)
             VALUES (:request_id, :inventory_id, :common_name, :quantity)'
        );
        foreach ($items as $inventoryId => $quantity) {
            $itemInsert->execute([
                ':request_id' => $requestId,
                ':inventory_id' => $inventoryId,
                ':common_name' => $speciesNames[$inventoryId],
                ':quantity' => $quantity,
            ]);
        }

        seedling_record_status_history($pdo, $requestId, $requesterUserId, null, 'submitted', 'Seedling request submitted.');
        record_audit_event(
            $pdo,
            $requesterUserId,
            'seedling',
            'seedling_request_submitted',
            'seedling_request',
            $requestId,
            'Submitted a seedling request.',
            ['request_reference' => $reference, 'total_requested' => $total, 'species_count' => count($items)]
        );

        // Notify the EMS section so the request surfaces in their queue.
        $emsRecipients = seedling_ems_notification_recipients($pdo);
        if ($emsRecipients !== []) {
            create_notifications_for_users(
                $pdo,
                $emsRecipients,
                $requesterUserId,
                'seedling_request',
                'New seedling request',
                'Seedling request ' . $reference . ' from ' . $requesterName . ' requests '
                    . $total . ' seedling(s) across ' . count($items) . ' species and is awaiting review.',
                'seedling_request',
                $requestId
            );
        }

        $pdo->commit();

        return [
            'request_id' => $requestId,
            'request_reference' => $reference,
            'total_requested' => $total,
            'duplicate' => false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Requests: reads
// ---------------------------------------------------------------------------

/** Owner-scoped for Community; EMS sees everything. Returns null when unauthorized. */
function seedling_request_for_actor(PDO $pdo, int $requestId, int $actorUserId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, CONCAT(u.fname, \' \', u.lname) AS requester_full_name,
                CASE WHEN rev.id IS NULL THEN NULL ELSE CONCAT(rev.fname, \' \', rev.lname) END AS reviewed_by_name,
                CASE WHEN ful.id IS NULL THEN NULL ELSE CONCAT(ful.fname, \' \', ful.lname) END AS fulfilled_by_name,
                CASE WHEN rel.id IS NULL THEN NULL ELSE CONCAT(rel.fname, \' \', rel.lname) END AS released_by_name
         FROM tbl_seedling_requests r
         INNER JOIN tbl_users u ON u.id = r.requester_user_id
         LEFT JOIN tbl_users rev ON rev.id = r.reviewed_by_user_id
         LEFT JOIN tbl_users ful ON ful.id = r.fulfilled_by_user_id
         LEFT JOIN tbl_users rel ON rel.id = r.released_by_user_id
         WHERE r.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }

    $actorStmt = $pdo->prepare('SELECT id, role, status FROM tbl_users WHERE id = :id LIMIT 1');
    $actorStmt->execute([':id' => $actorUserId]);
    $actor = $actorStmt->fetch();
    if (!$actor || (string) $actor['status'] !== 'active') {
        return null;
    }
    $role = (string) $actor['role'];
    if ($role === 'ems') {
        return $request;
    }
    if ($role === 'community' && (int) $request['requester_user_id'] === $actorUserId) {
        return $request;
    }

    return null;
}

function seedling_request_items(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tbl_seedling_request_items WHERE request_id = :id ORDER BY common_name'
    );
    $stmt->execute([':id' => $requestId]);

    return $stmt->fetchAll();
}

function seedling_request_history(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, CONCAT(u.fname, \' \', u.lname) AS changed_by_name
         FROM tbl_seedling_request_status_history h
         INNER JOIN tbl_users u ON u.id = h.changed_by_user_id
         WHERE h.request_id = :id
         ORDER BY h.id'
    );
    $stmt->execute([':id' => $requestId]);

    return $stmt->fetchAll();
}

/** Owner-scoped list for the Community registry. */
function seedling_requests_for_requester(PDO $pdo, int $requesterUserId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*,
                (SELECT COALESCE(SUM(i.quantity_requested), 0) FROM tbl_seedling_request_items i WHERE i.request_id = r.id) AS total_requested,
                (SELECT COALESCE(SUM(i.quantity_approved), 0) FROM tbl_seedling_request_items i WHERE i.request_id = r.id) AS total_approved
         FROM tbl_seedling_requests r
         WHERE r.requester_user_id = :requester
         ORDER BY r.submitted_at DESC, r.id DESC'
    );
    $stmt->execute([':requester' => $requesterUserId]);

    return $stmt->fetchAll();
}

/** Filterable EMS work queue. */
function seedling_requests_for_ems(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && array_key_exists($status, seedling_request_statuses())) {
        $where[] = 'r.current_status = :status';
        $params[':status'] = $status;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(r.request_reference LIKE :search1 OR r.requester_name LIKE :search2)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        'SELECT r.*,
                (SELECT COALESCE(SUM(i.quantity_requested), 0) FROM tbl_seedling_request_items i WHERE i.request_id = r.id) AS total_requested,
                (SELECT COALESCE(SUM(i.quantity_approved), 0) FROM tbl_seedling_request_items i WHERE i.request_id = r.id) AS total_approved
         FROM tbl_seedling_requests r' . $whereSql . '
         ORDER BY FIELD(r.current_status, \'submitted\', \'under_review\', \'approved\', \'ready_for_pickup\', \'claimed\', \'declined\', \'cancelled\'),
                  r.submitted_at DESC, r.id DESC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Claim-slip registry for EMS: requests that are ready for pickup or already
 * claimed. Optional single-status sub-filter and search over reference,
 * requester, or claimant name.
 */
function seedling_claim_slip_requests(PDO $pdo, array $filters = []): array
{
    $where = ["r.current_status IN ('ready_for_pickup', 'claimed')"];
    $params = [];

    $status = trim((string) ($filters['status'] ?? ''));
    if (in_array($status, ['ready_for_pickup', 'claimed'], true)) {
        $where = ['r.current_status = :status'];
        $params[':status'] = $status;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(r.request_reference LIKE :search1 OR r.requester_name LIKE :search2 OR r.claimed_by_name LIKE :search3)';
        $term = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $term;
        $params[':search2'] = $term;
        $params[':search3'] = $term;
    }

    $stmt = $pdo->prepare(
        'SELECT r.*,
                (SELECT COALESCE(SUM(i.quantity_approved), 0) FROM tbl_seedling_request_items i WHERE i.request_id = r.id) AS total_approved
         FROM tbl_seedling_requests r
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY FIELD(r.current_status, \'ready_for_pickup\', \'claimed\'), r.fulfilled_at DESC, r.id DESC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Requests: EMS processing
// ---------------------------------------------------------------------------

/** Internal: locks a request and validates the expected transition. */
function seedling_lock_request_for_transition(PDO $pdo, int $requestId, string $newStatus): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_seedling_requests WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new SeedlingValidationException('The seedling request does not exist.');
    }
    $current = (string) $request['current_status'];
    if (!seedling_request_transition_is_allowed($current, $newStatus)) {
        throw new SeedlingValidationException(
            'A ' . seedling_request_status_label($current) . ' request cannot become '
            . seedling_request_status_label($newStatus) . '.'
        );
    }

    return $request;
}

function seedling_set_request_status(
    PDO $pdo,
    array $request,
    int $actorUserId,
    string $newStatus,
    string $remarks,
    array $extraColumns = []
): void {
    $columns = ['current_status = :new_status'];
    $params = [
        ':new_status' => $newStatus,
        ':id' => (int) $request['id'],
        ':expected' => (string) $request['current_status'],
    ];
    foreach ($extraColumns as $column => $value) {
        $columns[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }
    $update = $pdo->prepare(
        'UPDATE tbl_seedling_requests SET ' . implode(', ', $columns)
        . ' WHERE id = :id AND current_status = :expected'
    );
    $update->execute($params);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The request changed before the update completed.');
    }
    seedling_record_status_history(
        $pdo,
        (int) $request['id'],
        $actorUserId,
        (string) $request['current_status'],
        $newStatus,
        $remarks
    );
}

/**
 * EMS review action: begin_review, approve, decline, fulfil, or claim.
 * Stock is deducted only by fulfil; decline/cancel never touch stock.
 */
function seedling_process_request(PDO $pdo, int $requestId, int $actorUserId, string $action, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Request processing must own its database transaction.');
    }
    if (!in_array($action, ['begin_review', 'approve', 'decline', 'fulfil', 'claim'], true)) {
        throw new SeedlingValidationException('Unsupported request action.');
    }
    $remarks = trim((string) ($input['remarks'] ?? ''));
    if (strlen($remarks) > 1000) {
        throw new SeedlingValidationException('Remarks must not exceed 1000 characters.');
    }
    if ($action === 'decline' && $remarks === '') {
        throw new SeedlingValidationException('A declined request requires a reason.');
    }

    try {
        $pdo->beginTransaction();
        if (seedling_ems_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('You are not authorized to process seedling requests.');
        }

        $targetStatus = match ($action) {
            'begin_review' => 'under_review',
            'approve' => 'approved',
            'decline' => 'declined',
            'fulfil' => 'ready_for_pickup',
            'claim' => 'claimed',
        };
        $request = seedling_lock_request_for_transition($pdo, $requestId, $targetStatus);
        $requesterUserId = (int) $request['requester_user_id'];
        $reference = (string) $request['request_reference'];
        $result = ['request_id' => $requestId, 'request_reference' => $reference, 'status' => $targetStatus];

        if ($action === 'begin_review') {
            seedling_set_request_status($pdo, $request, $actorUserId, 'under_review', 'EMS began reviewing the request.');
        } elseif ($action === 'approve') {
            // EMS may reduce each line; approved quantities are validated against
            // the request but stock is not touched until fulfilment.
            $approved = (array) ($input['quantity_approved'] ?? []);
            $items = seedling_request_items($pdo, $requestId);
            $totalApproved = 0;
            $updateItem = $pdo->prepare(
                'UPDATE tbl_seedling_request_items SET quantity_approved = :approved WHERE id = :id AND request_id = :request_id'
            );
            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $raw = trim((string) ($approved[$itemId] ?? (string) $item['quantity_requested']));
                if (!ctype_digit($raw)) {
                    throw new SeedlingValidationException('Approved quantities must be whole numbers.');
                }
                $value = (int) $raw;
                if ($value > (int) $item['quantity_requested']) {
                    throw new SeedlingValidationException(
                        'Approved quantity for ' . (string) $item['common_name'] . ' cannot exceed the requested amount.'
                    );
                }
                $updateItem->execute([':approved' => $value, ':id' => $itemId, ':request_id' => $requestId]);
                $totalApproved += $value;
            }
            if ($totalApproved < 1) {
                throw new SeedlingValidationException('Approve at least one seedling, or decline the request.');
            }
            seedling_set_request_status(
                $pdo,
                $request,
                $actorUserId,
                'approved',
                $remarks !== '' ? $remarks : 'Request approved (' . $totalApproved . ' seedling(s)).',
                [
                    'reviewed_by_user_id' => $actorUserId,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'review_remarks' => $remarks !== '' ? $remarks : null,
                ]
            );
            $result['total_approved'] = $totalApproved;
        } elseif ($action === 'decline') {
            seedling_set_request_status(
                $pdo,
                $request,
                $actorUserId,
                'declined',
                $remarks,
                [
                    'reviewed_by_user_id' => $actorUserId,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'review_remarks' => $remarks,
                ]
            );
        } elseif ($action === 'fulfil') {
            // Deduct approved quantities from stock; each species is locked and
            // may not go negative.
            $items = seedling_request_items($pdo, $requestId);
            $totalDeducted = 0;
            foreach ($items as $item) {
                $quantity = (int) ($item['quantity_approved'] ?? 0);
                if ($quantity < 1) {
                    continue;
                }
                seedling_apply_stock_movement(
                    $pdo,
                    (int) $item['inventory_id'],
                    $actorUserId,
                    'released',
                    -$quantity,
                    $requestId,
                    'Released for seedling request ' . $reference . '.'
                );
                $totalDeducted += $quantity;
            }
            if ($totalDeducted < 1) {
                throw new SeedlingValidationException('This request has no approved quantities to fulfil.');
            }
            seedling_set_request_status(
                $pdo,
                $request,
                $actorUserId,
                'ready_for_pickup',
                $remarks !== '' ? $remarks : 'Stock reserved; ready for pickup (' . $totalDeducted . ' seedling(s)).',
                ['fulfilled_by_user_id' => $actorUserId, 'fulfilled_at' => date('Y-m-d H:i:s')]
            );
            $result['total_deducted'] = $totalDeducted;
        } elseif ($action === 'claim') {
            $claimant = trim((string) ($input['claimed_by_name'] ?? ''));
            if ($claimant === '' || strlen($claimant) > 150) {
                throw new SeedlingValidationException('Record who collected the seedlings (up to 150 characters).');
            }
            $claimedOn = trim((string) ($input['claimed_on'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $claimedOn) || strtotime($claimedOn) === false) {
                throw new SeedlingValidationException('Enter a valid claim date.');
            }
            if (strtotime($claimedOn) > strtotime('today')) {
                throw new SeedlingValidationException('The claim date cannot be in the future.');
            }
            seedling_set_request_status(
                $pdo,
                $request,
                $actorUserId,
                'claimed',
                $remarks !== '' ? $remarks : 'Seedlings collected by ' . $claimant . '.',
                [
                    'claimed_by_name' => $claimant,
                    'claimed_on' => $claimedOn,
                    'released_by_user_id' => $actorUserId,
                    'claim_remarks' => $remarks !== '' ? $remarks : null,
                ]
            );
            $result['claimed_by_name'] = $claimant;
        }

        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'seedling_request_' . $targetStatus,
            'seedling_request',
            $requestId,
            'Seedling request moved to ' . seedling_request_status_label($targetStatus) . '.',
            array_merge(
                ['request_reference' => $reference, 'previous_status' => (string) $request['current_status']],
                $remarks !== '' ? ['remarks' => $remarks] : []
            )
        );

        // The requester is told about every state change except the internal
        // "EMS opened this" step, which carries no information for them.
        if ($action !== 'begin_review') {
            $messages = [
                'approved' => 'Your seedling request ' . $reference . ' was approved. EMS will prepare your seedlings for pickup.',
                'declined' => 'Your seedling request ' . $reference . ' was declined. Reason: ' . $remarks,
                'ready_for_pickup' => 'Your seedling request ' . $reference . ' is ready for pickup at ' . seedling_claim_location() . '. Bring a valid ID.',
                'claimed' => 'Your seedling request ' . $reference . ' was recorded as collected. Thank you for planting.',
            ];
            create_notification(
                $pdo,
                $requesterUserId,
                $actorUserId,
                'seedling_request',
                'Seedling request ' . seedling_request_status_label($targetStatus),
                $messages[$targetStatus] ?? ('Your seedling request ' . $reference . ' is now ' . seedling_request_status_label($targetStatus) . '.'),
                'seedling_request',
                $requestId
            );
        }

        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The Community owner may withdraw a request before it is approved. */
function seedling_cancel_request(PDO $pdo, int $requestId, int $actorUserId, string $remarks = ''): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Request cancellation must own its database transaction.');
    }
    $remarks = trim($remarks);
    if (strlen($remarks) > 500) {
        throw new SeedlingValidationException('Cancellation remarks must not exceed 500 characters.');
    }

    try {
        $pdo->beginTransaction();
        if (seedling_community_actor($pdo, $actorUserId, true) === null) {
            throw new RuntimeException('Only an active Community account may cancel a request.');
        }
        $request = seedling_lock_request_for_transition($pdo, $requestId, 'cancelled');
        if ((int) $request['requester_user_id'] !== $actorUserId) {
            throw new RuntimeException('You may only cancel your own seedling request.');
        }
        seedling_set_request_status(
            $pdo,
            $request,
            $actorUserId,
            'cancelled',
            $remarks !== '' ? $remarks : 'Withdrawn by the requester.'
        );
        record_audit_event(
            $pdo,
            $actorUserId,
            'seedling',
            'seedling_request_cancelled',
            'seedling_request',
            $requestId,
            'Community user withdrew a seedling request.',
            ['request_reference' => (string) $request['request_reference']]
        );

        $pdo->commit();

        return ['request_id' => $requestId, 'status' => 'cancelled'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Seedlings, like permits, are collected in person at the office. */
function seedling_claim_location(): string
{
    return defined('CERTREEFY_PERMIT_CLAIM_LOCATION')
        ? (string) CERTREEFY_PERMIT_CLAIM_LOCATION
        : 'the CENRO office';
}
