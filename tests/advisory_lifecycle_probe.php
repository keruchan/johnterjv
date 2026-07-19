<?php
/**
 * CLI probe for Public Advisories: authoring validation, RPS/Superadmin
 * authorization (Community/EMS denied), the draft -> published -> archived
 * lifecycle (plus the direct draft -> archived discard), publish-time
 * Community notification broadcast, Community read-only visibility, status
 * history, and transaction rollback.
 *
 * Seeds throwaway rows directly, exercises the real services, asserts the
 * resulting state, and removes everything it created. Run:
 *   C:\xampp\php\php.exe tests\advisory_lifecycle_probe.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/advisory.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$rpsUserId = 0;
$superadminId = 0;
$communityUserId = 0;
$communityUserId2 = 0;
$emsUserId = 0;
$createdAdvisoryIds = [];

function seed_user(PDO $pdo, string $role, string $suffix, string $tag): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_users (fname, lname, email, username, password, contact, role, status)
         VALUES (:fname, :lname, :email, :username, :password, \'09170000000\', :role, \'active\')'
    );
    $stmt->execute([
        ':fname' => ucfirst($tag),
        ':lname' => 'Probe',
        ':email' => $tag . '_' . $suffix . '@certreefy.test',
        ':username' => $tag . '_' . $suffix,
        ':password' => password_hash('probe-' . $suffix, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);

    return (int) $pdo->lastInsertId();
}

try {
    $rpsUserId = seed_user($pdo, 'rps', $suffix, 'rps');
    $superadminId = seed_user($pdo, 'superadmin', $suffix, 'admin');
    $communityUserId = seed_user($pdo, 'community', $suffix, 'community');
    $communityUserId2 = seed_user($pdo, 'community', $suffix, 'community2');
    $emsUserId = seed_user($pdo, 'ems', $suffix, 'ems');

    // ---- Actor checks ----
    echo 'Actors' . PHP_EOL;
    check('RPS can author advisories', advisory_actor($pdo, $rpsUserId) !== null);
    check('Superadmin can author advisories (no special permission needed)', advisory_actor($pdo, $superadminId) !== null);
    check('Community cannot author advisories', advisory_actor($pdo, $communityUserId) === null);
    check('EMS cannot author advisories', advisory_actor($pdo, $emsUserId) === null);

    // ---- Validation ----
    echo 'Validation' . PHP_EOL;

    $emptyTitleRejected = false;
    try {
        advisory_create($pdo, $rpsUserId, ['title' => '', 'body' => 'Body text.']);
    } catch (AdvisoryValidationException $e) {
        $emptyTitleRejected = true;
    }
    check('empty title rejected', $emptyTitleRejected);

    $emptyBodyRejected = false;
    try {
        advisory_create($pdo, $rpsUserId, ['title' => 'Title ' . $suffix, 'body' => '']);
    } catch (AdvisoryValidationException $e) {
        $emptyBodyRejected = true;
    }
    check('empty body rejected', $emptyBodyRejected);

    $communityCreateDenied = false;
    try {
        advisory_create($pdo, $communityUserId, ['title' => 'Illegal Advisory ' . $suffix, 'body' => 'Body text.']);
    } catch (RuntimeException $e) {
        $communityCreateDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot author an advisory', $communityCreateDenied);

    // ---- Valid creation (always starts as draft) ----
    echo 'Valid creation' . PHP_EOL;
    $title = 'Watershed Advisory ' . $suffix;
    $created = advisory_create($pdo, $rpsUserId, ['title' => $title, 'body' => 'Please avoid the watershed area this week.']);
    $createdAdvisoryIds[] = $created['advisory_id'];
    check('advisory created as draft', $created['status'] === 'draft');

    $advisory = advisory_find($pdo, $created['advisory_id']);
    check('advisory persisted with correct title', (string) $advisory['title'] === $title);
    check('published_at is null before publishing', $advisory['published_at'] === null);

    // ---- Listing / summary ----
    echo 'Listing and summary' . PHP_EOL;
    $listAll = advisory_list($pdo, ['q' => $suffix]);
    check('CENRO registry search finds the draft', count($listAll) === 1 && (int) $listAll[0]['id'] === $created['advisory_id']);

    $publishedListBeforePublish = advisory_published_list($pdo, ['q' => $suffix]);
    check('Community list is empty before publishing', $publishedListBeforePublish === []);

    // ---- Update while still a draft ----
    echo 'Update' . PHP_EOL;
    $updatedTitle = $title . ' (revised)';
    $updated = advisory_update($pdo, $superadminId, $created['advisory_id'], ['title' => $updatedTitle, 'body' => 'Revised body text.']);
    check('Superadmin can update a draft advisory', $updated['advisory_id'] === $created['advisory_id']);
    $afterUpdate = advisory_find($pdo, $created['advisory_id']);
    check('title updated', (string) $afterUpdate['title'] === $updatedTitle);

    $communityUpdateDenied = false;
    try {
        advisory_update($pdo, $communityUserId, $created['advisory_id'], ['title' => $title, 'body' => 'Body text.']);
    } catch (RuntimeException $e) {
        $communityUpdateDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot update an advisory', $communityUpdateDenied);

    $updateMissingRejected = false;
    try {
        advisory_update($pdo, $rpsUserId, 999999999, ['title' => 'Nonexistent', 'body' => 'Body text.']);
    } catch (AdvisoryValidationException $e) {
        $updateMissingRejected = str_contains($e->getMessage(), 'does not exist');
    }
    check('updating a nonexistent advisory rejected', $updateMissingRejected);

    // ---- Invalid transitions ----
    echo 'Invalid transitions' . PHP_EOL;
    $archiveThenPublishRejected = false;
    // Publishing straight to archived is not a real action name, so instead
    // verify that publish requires draft by first archiving a *different*
    // throwaway advisory, then trying to publish it.
    $discardable = advisory_create($pdo, $rpsUserId, ['title' => 'Discardable ' . $suffix, 'body' => 'Never goes live.']);
    $createdAdvisoryIds[] = $discardable['advisory_id'];
    advisory_transition($pdo, $rpsUserId, $discardable['advisory_id'], 'archive', ['remarks' => 'Discarded before publishing.']);
    $discardedRow = advisory_find($pdo, $discardable['advisory_id']);
    check('draft -> archived discard shortcut works', (string) $discardedRow['current_status'] === 'archived');

    try {
        advisory_transition($pdo, $rpsUserId, $discardable['advisory_id'], 'publish');
    } catch (AdvisoryValidationException $e) {
        $archiveThenPublishRejected = str_contains($e->getMessage(), 'cannot become');
    }
    check('archived advisory cannot be published', $archiveThenPublishRejected);

    $communityTransitionDenied = false;
    try {
        advisory_transition($pdo, $communityUserId, $created['advisory_id'], 'publish');
    } catch (RuntimeException $e) {
        $communityTransitionDenied = str_contains($e->getMessage(), 'not authorized');
    }
    check('Community cannot publish an advisory', $communityTransitionDenied);

    // ---- Publish (main advisory) + notification broadcast ----
    echo 'Publish' . PHP_EOL;
    $published = advisory_transition($pdo, $rpsUserId, $created['advisory_id'], 'publish', ['remarks' => 'Ready for release.']);
    check('advisory published', $published['status'] === 'published');
    $afterPublish = advisory_find($pdo, $created['advisory_id']);
    check('published_at recorded', $afterPublish['published_at'] !== null);

    $publishedListAfterPublish = advisory_published_list($pdo, ['q' => $suffix]);
    check(
        'Community list now shows the published advisory',
        count($publishedListAfterPublish) === 1 && (int) $publishedListAfterPublish[0]['id'] === $created['advisory_id']
    );

    $notifStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_notifications
         WHERE notification_type = \'system_announcement\' AND entity_type = \'advisory\' AND entity_id = :id
           AND recipient_user_id IN (:c1, :c2)'
    );
    $notifStmt->execute([':id' => $created['advisory_id'], ':c1' => $communityUserId, ':c2' => $communityUserId2]);
    check('both active Community users notified on publish', (int) $notifStmt->fetchColumn() === 2);

    $doublePublishRejected = false;
    try {
        advisory_transition($pdo, $rpsUserId, $created['advisory_id'], 'publish');
    } catch (AdvisoryValidationException $e) {
        $doublePublishRejected = str_contains($e->getMessage(), 'cannot become');
    }
    check('an already-published advisory cannot be published again', $doublePublishRejected);

    // Editing remains possible after publishing (typo fix without unpublishing).
    advisory_update($pdo, $rpsUserId, $created['advisory_id'], ['title' => $updatedTitle, 'body' => 'Corrected a typo after publishing.']);
    $afterPublishedEdit = advisory_find($pdo, $created['advisory_id']);
    check('a published advisory can still be edited in place', (string) $afterPublishedEdit['body'] === 'Corrected a typo after publishing.');
    check('editing does not change its status', (string) $afterPublishedEdit['current_status'] === 'published');

    // ---- Archive after publishing ----
    echo 'Archive' . PHP_EOL;
    $archived = advisory_transition($pdo, $superadminId, $created['advisory_id'], 'archive', ['remarks' => 'No longer current.']);
    check('published advisory archived', $archived['status'] === 'archived');
    $publishedListAfterArchive = advisory_published_list($pdo, ['q' => $suffix]);
    check('archived advisory no longer visible to Community', $publishedListAfterArchive === []);

    // ---- Status history + summary + audit ----
    echo 'History, summary, and audit' . PHP_EOL;
    $history = advisory_history($pdo, $created['advisory_id']);
    $historyStatuses = array_map(static fn (array $row): string => (string) $row['new_status'], $history);
    check('status history records draft -> published -> archived', $historyStatuses === ['draft', 'published', 'archived']);

    $summary = advisory_summary($pdo);
    check('summary counts include the archived advisory', $summary['total'] >= 2);

    $auditCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_audit_trail WHERE category = \'advisory\' AND entity_type = \'advisory\''
        . ' AND entity_id = ' . $created['advisory_id']
    )->fetchColumn();
    check('every mutation audited (create + 2 updates + publish + archive)', $auditCount === 5);

    // ---- Rollback ----
    echo 'Rollback' . PHP_EOL;
    $trigger = 'trg_advisory_probe_' . $suffix;
    $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON tbl_advisories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced rollback'");
    $rolledBack = false;
    try {
        advisory_create($pdo, $rpsUserId, ['title' => 'Rollback Advisory ' . $suffix, 'body' => 'Should not persist.']);
    } catch (Throwable $e) {
        $rolledBack = true;
    }
    $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
    check('forced failure during creation threw', $rolledBack);
    check(
        'no advisory row survived the failed creation',
        (int) $pdo->query("SELECT COUNT(*) FROM tbl_advisories WHERE title = 'Rollback Advisory $suffix'")->fetchColumn() === 0
    );

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    try { $pdo->exec('DROP TRIGGER IF EXISTS trg_advisory_probe_' . $suffix); } catch (Throwable $e) {}
    foreach ($createdAdvisoryIds as $id) {
        try {
            $pdo->prepare('DELETE FROM tbl_notifications WHERE entity_type = \'advisory\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_advisory_status_history WHERE advisory_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_audit_trail WHERE entity_type = \'advisory\' AND entity_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM tbl_advisories WHERE id = :id')->execute([':id' => $id]);
        } catch (Throwable $e) { echo 'cleanup advisory ' . $id . ': ' . $e->getMessage() . PHP_EOL; }
    }
    foreach ([$rpsUserId, $superadminId, $communityUserId, $communityUserId2, $emsUserId] as $uid) {
        if ($uid > 0) {
            try {
                $pdo->prepare('DELETE FROM tbl_notifications WHERE recipient_user_id = :id1 OR created_by_user_id = :id2')->execute([':id1' => $uid, ':id2' => $uid]);
                $pdo->prepare('DELETE FROM tbl_audit_trail WHERE actor_user_id = :id')->execute([':id' => $uid]);
                $pdo->prepare('DELETE FROM tbl_users WHERE id = :id')->execute([':id' => $uid]);
            } catch (Throwable $e) { echo 'cleanup user ' . $uid . ': ' . $e->getMessage() . PHP_EOL; }
        }
    }
}

echo PHP_EOL . 'RESULT: ' . $pass . ' passed, ' . $fail . ' failed.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
