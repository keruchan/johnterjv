<?php
/** PRG endpoint for Public Advisory create/update/publish/archive actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/advisory.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (advisory_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to manage advisories.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_advisory_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new AdvisoryValidationException('Security validation failed. Refresh the page and try again.');
    }

    $advisoryValue = trim((string) ($_POST['advisory_id'] ?? ''));
    $advisoryId = ctype_digit($advisoryValue) ? (int) $advisoryValue : 0;

    if ($action === 'create_advisory') {
        $result = advisory_create($pdo, $userId, $_POST, $_FILES);
        $_SESSION['advisory_flash'] = ['type' => 'success', 'message' => 'Announcement saved as a draft.'];
    } elseif ($action === 'update_advisory') {
        if ($advisoryId < 1) {
            throw new AdvisoryValidationException('The selected advisory is invalid.');
        }
        advisory_update($pdo, $userId, $advisoryId, $_POST, $_FILES);
        $_SESSION['advisory_flash'] = ['type' => 'success', 'message' => 'Announcement updated.'];
    } elseif ($action === 'publish_advisory') {
        if ($advisoryId < 1) {
            throw new AdvisoryValidationException('The selected advisory is invalid.');
        }
        advisory_transition($pdo, $userId, $advisoryId, 'publish', $_POST);
        $_SESSION['advisory_flash'] = ['type' => 'success', 'message' => 'Advisory published to Community users.'];
    } elseif ($action === 'archive_advisory') {
        if ($advisoryId < 1) {
            throw new AdvisoryValidationException('The selected advisory is invalid.');
        }
        advisory_transition($pdo, $userId, $advisoryId, 'archive', $_POST);
        $_SESSION['advisory_flash'] = ['type' => 'success', 'message' => 'Advisory archived.'];
    } else {
        throw new AdvisoryValidationException('The requested action is not supported.');
    }
    $_SESSION['csrf_advisory_token'] = bin2hex(random_bytes(32));
} catch (AdvisoryValidationException $e) {
    $_SESSION['advisory_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY ADVISORY ERROR] ' . $e->getMessage());
    $_SESSION['advisory_flash'] = ['type' => 'danger', 'message' => 'The advisory could not be saved at this time.'];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['advisory_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: advisories.php', true, 303);
exit;
