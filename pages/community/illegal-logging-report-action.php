<?php
/** PRG endpoint for Community illegal-logging report submission. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/illegal_logging.php';

require_role($pdo, 'community');

$userId = (int) $_SESSION['id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_illegal_logging_submit_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new IllegalLoggingValidationException('Security validation failed. Refresh the page and try again.');
    }

    $photoFiles = $_FILES['evidence_photos'] ?? [];
    $result = illegal_logging_submit_report($pdo, $userId, $_POST, $photoFiles);
    $_SESSION['illegal_logging_submit_flash'] = [
        'type' => 'success',
        'message' => $result['duplicate']
            ? 'This report was already submitted (reference ' . $result['report_reference'] . ').'
            : 'Report ' . $result['report_reference'] . ' submitted. CENRO enforcement has been notified.',
    ];
    $_SESSION['csrf_illegal_logging_submit_token'] = bin2hex(random_bytes(32));
} catch (IllegalLoggingValidationException $e) {
    $_SESSION['illegal_logging_submit_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY ILLEGAL LOGGING SUBMIT ERROR] ' . $e->getMessage());
    $_SESSION['illegal_logging_submit_flash'] = [
        'type' => 'danger',
        'message' => 'The report could not be submitted at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['illegal_logging_submit_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: illegal-logging-reports.php', true, 303);
exit;
