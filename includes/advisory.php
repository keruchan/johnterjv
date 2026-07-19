<?php
/**
 * Public advisories.
 *
 * Independent of the permit, seedling, illegal-logging, and area-management
 * workflows: any active RPS or Superadmin (no dedicated permission gate, same
 * as Area Management) may author a notice/announcement. A post starts as a
 * draft, is explicitly published (making it visible to logged-in Community
 * users), and can be archived to retire it without deleting the record.
 *
 * Lifecycle:
 *   draft -> published -> archived
 *   draft -> archived (discard without ever publishing)
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class AdvisoryValidationException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------

function advisory_statuses(): array
{
    return [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ];
}

function advisory_status_label(string $status): string
{
    return advisory_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function advisory_status_badge(string $status): string
{
    return match ($status) {
        'draft' => 'text-bg-secondary',
        'published' => 'text-bg-success',
        'archived' => 'text-bg-dark',
        default => 'text-bg-light border',
    };
}

function advisory_transition_is_allowed(string $from, string $to): bool
{
    $allowed = [
        'draft' => ['published', 'archived'],
        'published' => ['archived'],
        'archived' => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
}

// ---------------------------------------------------------------------------
// Actor
// ---------------------------------------------------------------------------

/** Authoring/publishing is any active RPS or Superadmin; no permission gate. */
function advisory_actor(PDO $pdo, int $actorUserId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, fname, lname, role, status FROM tbl_users WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $actorUserId]);
    $actor = $stmt->fetch();
    if (!$actor || (string) $actor['status'] !== 'active') {
        return null;
    }

    return in_array((string) $actor['role'], ['rps', 'superadmin'], true) ? $actor : null;
}

// ---------------------------------------------------------------------------
// Internal writers
// ---------------------------------------------------------------------------

function advisory_record_status_history(
    PDO $pdo,
    int $advisoryId,
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
        'INSERT INTO tbl_advisory_status_history
            (advisory_id, previous_status, new_status, changed_by_user_id, remarks)
         VALUES (:advisory_id, :previous_status, :new_status, :actor, :remarks)'
    );
    $stmt->execute([
        ':advisory_id' => $advisoryId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':actor' => $actorUserId,
        ':remarks' => $remarks !== '' ? $remarks : null,
    ]);
}

/** All active Community users, for the publish-time notification broadcast. */
function advisory_active_community_user_ids(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id FROM tbl_users WHERE role = 'community' AND status = 'active'");

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------------------------------------------------------------------
// Public announcement image (stored inside the web root: shown to anonymous
// visitors on the landing page, unlike private permit/inspection evidence)
// ---------------------------------------------------------------------------

function advisory_image_max_bytes(): int
{
    return defined('ADVISORY_IMAGE_MAX_BYTES') ? (int) ADVISORY_IMAGE_MAX_BYTES : 5 * 1024 * 1024;
}

function advisory_image_max_size_label(): string
{
    return number_format(advisory_image_max_bytes() / 1024 / 1024, 0) . ' MB';
}

function advisory_image_storage_root(): string
{
    $configured = defined('ADVISORY_IMAGE_STORAGE_ROOT') ? trim((string) ADVISORY_IMAGE_STORAGE_ROOT) : '';
    if ($configured === '') {
        throw new RuntimeException('Advisory image storage is not configured.');
    }
    if (!is_dir($configured) && !mkdir($configured, 0755, true) && !is_dir($configured)) {
        throw new RuntimeException('Advisory image storage is unavailable.');
    }
    $root = realpath($configured);
    if ($root === false || !is_writable($root)) {
        throw new RuntimeException('Advisory image storage is unavailable.');
    }

    return $root;
}

/** Public URL (relative to the site root) for a stored advisory image, or null. */
function advisory_image_url(?array $advisory): ?string
{
    if ($advisory === null) {
        return null;
    }
    $relative = trim((string) ($advisory['image_path'] ?? ''));
    if ($relative === '') {
        return null;
    }
    $base = defined('ADVISORY_IMAGE_PUBLIC_BASE') ? trim((string) ADVISORY_IMAGE_PUBLIC_BASE) : 'uploads/advisories';

    return $base . '/' . $relative;
}

/**
 * Validates an optional uploaded announcement image (JPEG/PNG/WebP). Returns
 * null when no file was submitted, or a validated descriptor otherwise.
 */
function advisory_validate_image(array $files): ?array
{
    if (!isset($files['image']) || !is_array($files['image'])) {
        return null;
    }
    $file = $files['image'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new AdvisoryValidationException('The image could not be uploaded. Please try again.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > advisory_image_max_bytes()) {
        throw new AdvisoryValidationException('The image must be between 1 byte and ' . advisory_image_max_size_label() . '.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new AdvisoryValidationException('The uploaded image is invalid.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string) $finfo->file($tmp);
    if (!isset($allowed[$detectedMime])) {
        throw new AdvisoryValidationException('The image must be a JPG, PNG, or WebP file.');
    }
    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false) {
        throw new AdvisoryValidationException('The uploaded file is not a valid image.');
    }

    return [
        'tmp_name' => $tmp,
        'extension' => $allowed[$detectedMime],
        'mime_type' => $detectedMime,
        'size' => $size,
        'original_filename' => substr(basename((string) ($file['name'] ?? 'image')), 0, 255),
    ];
}

/** Moves a validated image into public storage, returning its stored metadata. */
function advisory_store_image(array $validated): array
{
    $root = advisory_image_storage_root();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $filename = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
        $absolute = $root . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($absolute)) {
            if (!move_uploaded_file($validated['tmp_name'], $absolute)) {
                throw new RuntimeException('The announcement image could not be stored.');
            }
            @chmod($absolute, 0644);

            return [
                'relative_path' => $filename,
                'absolute_path' => $absolute,
                'original_filename' => $validated['original_filename'],
                'mime_type' => $validated['mime_type'],
                'size' => $validated['size'],
            ];
        }
    }

    throw new RuntimeException('A collision-free image filename could not be generated.');
}

/** Removes a stored advisory image file (best effort; path is a bare filename). */
function advisory_delete_image(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $relativePath)) {
        return;
    }
    try {
        $root = advisory_image_storage_root();
    } catch (RuntimeException $e) {
        return;
    }
    $absolute = $root . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

function advisory_validate_common(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));

    if ($title === '' || strlen($title) > 200) {
        throw new AdvisoryValidationException('Enter a title of up to 200 characters.');
    }
    if ($body === '' || strlen($body) > 5000) {
        throw new AdvisoryValidationException('Enter advisory content of up to 5000 characters.');
    }

    // Optional cutting-schedule date/time (HTML datetime-local or "Y-m-d H:i").
    $eventAt = null;
    $eventRaw = str_replace('T', ' ', trim((string) ($input['event_at'] ?? '')));
    if ($eventRaw !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $eventRaw)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $eventRaw);
        if ($dt === false) {
            throw new AdvisoryValidationException('Enter a valid schedule date and time, or leave it blank.');
        }
        $eventAt = $dt->format('Y-m-d H:i:s');
    }

    // Checkbox: a hidden "0" precedes the checkbox so an unchecked box submits 0.
    $isPublic = trim((string) ($input['is_public'] ?? '0')) === '1' ? 1 : 0;

    return ['title' => $title, 'body' => $body, 'event_at' => $eventAt, 'is_public' => $isPublic];
}

// ---------------------------------------------------------------------------
// Writes (CENRO: RPS or Superadmin)
// ---------------------------------------------------------------------------

function advisory_create(PDO $pdo, int $actorUserId, array $input, array $files = []): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Advisory creation must own its database transaction.');
    }
    $data = advisory_validate_common($input);
    $validatedImage = advisory_validate_image($files);
    $storedImagePath = null;

    try {
        $pdo->beginTransaction();
        $actor = advisory_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to author advisories.');
        }

        $image = $validatedImage !== null ? advisory_store_image($validatedImage) : null;
        if ($image !== null) {
            $storedImagePath = $image['absolute_path'];
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_advisories
                (title, body, current_status, is_public, event_at,
                 image_path, image_original_name, image_mime_type, image_size_bytes, created_by_user_id)
             VALUES
                (:title, :body, \'draft\', :is_public, :event_at,
                 :image_path, :image_name, :image_mime, :image_size, :actor)'
        );
        $insert->execute([
            ':title' => $data['title'],
            ':body' => $data['body'],
            ':is_public' => $data['is_public'],
            ':event_at' => $data['event_at'],
            ':image_path' => $image['relative_path'] ?? null,
            ':image_name' => $image['original_filename'] ?? null,
            ':image_mime' => $image['mime_type'] ?? null,
            ':image_size' => $image['size'] ?? null,
            ':actor' => $actorUserId,
        ]);
        $advisoryId = (int) $pdo->lastInsertId();

        advisory_record_status_history($pdo, $advisoryId, $actorUserId, null, 'draft', 'Advisory drafted.');
        record_audit_event(
            $pdo,
            $actorUserId,
            'advisory',
            'advisory_created',
            'advisory',
            $advisoryId,
            'Drafted an advisory.',
            ['title' => $data['title'], 'has_image' => $image !== null]
        );

        $pdo->commit();

        return ['advisory_id' => $advisoryId, 'status' => 'draft'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($storedImagePath !== null && is_file($storedImagePath)) {
            @unlink($storedImagePath);
        }
        throw $e;
    }
}

function advisory_update(PDO $pdo, int $actorUserId, int $advisoryId, array $input, array $files = []): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Advisory update must own its database transaction.');
    }
    $data = advisory_validate_common($input);
    $validatedImage = advisory_validate_image($files);
    $removeImage = trim((string) ($input['remove_image'] ?? '')) === '1';
    $storedImagePath = null;
    $oldImageToDelete = null;

    try {
        $pdo->beginTransaction();
        $actor = advisory_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to edit advisories.');
        }

        $lock = $pdo->prepare('SELECT id, image_path FROM tbl_advisories WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute([':id' => $advisoryId]);
        $existing = $lock->fetch();
        if ($existing === false) {
            throw new AdvisoryValidationException('The advisory does not exist.');
        }
        $currentImage = trim((string) ($existing['image_path'] ?? ''));

        // Resolve the image change: a new upload replaces any existing image; an
        // explicit remove clears it; otherwise the current image is kept.
        $imageColumns = '';
        $imageParams = [];
        if ($validatedImage !== null) {
            $image = advisory_store_image($validatedImage);
            $storedImagePath = $image['absolute_path'];
            $imageColumns = ', image_path = :image_path, image_original_name = :image_name,'
                . ' image_mime_type = :image_mime, image_size_bytes = :image_size';
            $imageParams = [
                ':image_path' => $image['relative_path'],
                ':image_name' => $image['original_filename'],
                ':image_mime' => $image['mime_type'],
                ':image_size' => $image['size'],
            ];
            if ($currentImage !== '') {
                $oldImageToDelete = $currentImage;
            }
        } elseif ($removeImage && $currentImage !== '') {
            $imageColumns = ', image_path = NULL, image_original_name = NULL,'
                . ' image_mime_type = NULL, image_size_bytes = NULL';
            $oldImageToDelete = $currentImage;
        }

        $update = $pdo->prepare(
            'UPDATE tbl_advisories
             SET title = :title, body = :body, is_public = :is_public, event_at = :event_at,
                 updated_by_user_id = :actor' . $imageColumns . '
             WHERE id = :id'
        );
        $update->execute(array_merge([
            ':title' => $data['title'],
            ':body' => $data['body'],
            ':is_public' => $data['is_public'],
            ':event_at' => $data['event_at'],
            ':actor' => $actorUserId,
            ':id' => $advisoryId,
        ], $imageParams));

        record_audit_event(
            $pdo,
            $actorUserId,
            'advisory',
            'advisory_updated',
            'advisory',
            $advisoryId,
            'Updated an advisory.',
            ['title' => $data['title']]
        );

        $pdo->commit();

        // Only after a successful commit do we remove the superseded file.
        if ($oldImageToDelete !== null) {
            advisory_delete_image($oldImageToDelete);
        }

        return ['advisory_id' => $advisoryId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($storedImagePath !== null && is_file($storedImagePath)) {
            @unlink($storedImagePath);
        }
        throw $e;
    }
}

/** Publishes or archives an advisory. Publishing notifies every active Community user. */
function advisory_transition(PDO $pdo, int $actorUserId, int $advisoryId, string $action, array $input = []): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Advisory transition must own its database transaction.');
    }
    if (!in_array($action, ['publish', 'archive'], true)) {
        throw new AdvisoryValidationException('Unsupported advisory action.');
    }
    $remarks = trim((string) ($input['remarks'] ?? ''));
    if (strlen($remarks) > 500) {
        throw new AdvisoryValidationException('Remarks must not exceed 500 characters.');
    }
    $newStatus = $action === 'publish' ? 'published' : 'archived';

    try {
        $pdo->beginTransaction();
        $actor = advisory_actor($pdo, $actorUserId, true);
        if ($actor === null) {
            throw new RuntimeException('You are not authorized to manage advisories.');
        }

        $stmt = $pdo->prepare('SELECT * FROM tbl_advisories WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $advisoryId]);
        $advisory = $stmt->fetch();
        if (!$advisory) {
            throw new AdvisoryValidationException('The advisory does not exist.');
        }
        $currentStatus = (string) $advisory['current_status'];
        if (!advisory_transition_is_allowed($currentStatus, $newStatus)) {
            throw new AdvisoryValidationException(
                'A ' . advisory_status_label($currentStatus) . ' advisory cannot become '
                . advisory_status_label($newStatus) . '.'
            );
        }

        $extraColumns = $action === 'publish'
            ? ['published_at = NOW()']
            : ['archived_at = NOW()'];
        $update = $pdo->prepare(
            'UPDATE tbl_advisories SET current_status = :new_status, updated_by_user_id = :actor, '
            . implode(', ', $extraColumns)
            . ' WHERE id = :id AND current_status = :expected'
        );
        $update->execute([
            ':new_status' => $newStatus,
            ':actor' => $actorUserId,
            ':id' => $advisoryId,
            ':expected' => $currentStatus,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The advisory changed before the update completed.');
        }

        advisory_record_status_history(
            $pdo,
            $advisoryId,
            $actorUserId,
            $currentStatus,
            $newStatus,
            $remarks !== '' ? $remarks : ('Advisory ' . advisory_status_label($newStatus) . '.')
        );
        record_audit_event(
            $pdo,
            $actorUserId,
            'advisory',
            'advisory_' . $newStatus,
            'advisory',
            $advisoryId,
            'Advisory moved to ' . advisory_status_label($newStatus) . '.',
            ['previous_status' => $currentStatus, 'title' => (string) $advisory['title']]
        );

        if ($action === 'publish') {
            $recipients = advisory_active_community_user_ids($pdo);
            if ($recipients !== []) {
                create_notifications_for_users(
                    $pdo,
                    $recipients,
                    $actorUserId,
                    'system_announcement',
                    'New advisory: ' . (string) $advisory['title'],
                    substr((string) $advisory['body'], 0, 500),
                    'advisory',
                    $advisoryId
                );
            }
        }

        $pdo->commit();

        return ['advisory_id' => $advisoryId, 'status' => $newStatus];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function advisory_find(PDO $pdo, int $advisoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_advisories WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $advisoryId]);
    $advisory = $stmt->fetch();

    return $advisory ?: null;
}

function advisory_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT current_status, COUNT(*) AS total FROM tbl_advisories GROUP BY current_status'
    );
    $counts = ['draft' => 0, 'published' => 0, 'archived' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['current_status']] = (int) $row['total'];
    }
    $counts['total'] = array_sum($counts);

    return $counts;
}

function advisory_history(PDO $pdo, int $advisoryId): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, CONCAT(u.fname, \' \', u.lname) AS changed_by_name
         FROM tbl_advisory_status_history h
         INNER JOIN tbl_users u ON u.id = h.changed_by_user_id
         WHERE h.advisory_id = :id
         ORDER BY h.id'
    );
    $stmt->execute([':id' => $advisoryId]);

    return $stmt->fetchAll();
}

/** Filterable CENRO registry (all statuses). */
function advisory_list(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && array_key_exists($status, advisory_statuses())) {
        $where[] = 'current_status = :status';
        $params[':status'] = $status;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(title LIKE :search1 OR body LIKE :search2)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(
        'SELECT a.*, CONCAT(c.fname, \' \', c.lname) AS created_by_name
         FROM tbl_advisories a
         INNER JOIN tbl_users c ON c.id = a.created_by_user_id'
        . $whereSql
        . ' ORDER BY FIELD(a.current_status, \'draft\', \'published\', \'archived\'), a.created_at DESC, a.id DESC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** Community-facing: published advisories only, newest first. */
function advisory_published_list(PDO $pdo, array $filters = []): array
{
    $where = ["current_status = 'published'"];
    $params = [];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(title LIKE :search1 OR body LIKE :search2)';
        $searchTerm = '%' . substr($search, 0, 100) . '%';
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, body, event_at, image_path, image_original_name, published_at
         FROM tbl_advisories WHERE '
        . implode(' AND ', $where)
        . ' ORDER BY published_at DESC, id DESC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Public landing-page carousel source: published advisories flagged public,
 * newest first, capped. Used by the anonymous-facing home page.
 */
function advisory_public_list(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min($limit, 20));
    $stmt = $pdo->prepare(
        'SELECT id, title, body, event_at, image_path, image_original_name, published_at
         FROM tbl_advisories
         WHERE current_status = \'published\' AND is_public = 1
         ORDER BY published_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
