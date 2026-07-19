<?php
/** PRG endpoint for EMS planting-site create/update actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/planting_sites.php';

require_role($pdo, 'ems');

$userId = (int) $_SESSION['id'];
if (planting_site_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to manage planting sites.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_planting_site_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new PlantingSiteValidationException('Security validation failed. Refresh the page and try again.');
    }

    if ($action === 'create_site') {
        $result = planting_site_create($pdo, $userId, $_POST);
        $_SESSION['planting_site_flash'] = [
            'type' => 'success',
            'message' => 'Planting site "' . $result['site_name'] . '" added.',
        ];
    } elseif ($action === 'update_site') {
        $siteValue = trim((string) ($_POST['site_id'] ?? ''));
        $siteId = ctype_digit($siteValue) ? (int) $siteValue : 0;
        if ($siteId < 1) {
            throw new PlantingSiteValidationException('The selected planting site is invalid.');
        }
        $result = planting_site_update($pdo, $userId, $siteId, $_POST);
        $_SESSION['planting_site_flash'] = [
            'type' => 'success',
            'message' => 'Planting site "' . $result['site_name'] . '" updated.',
        ];
    } else {
        throw new PlantingSiteValidationException('The requested action is not supported.');
    }
    $_SESSION['csrf_planting_site_token'] = bin2hex(random_bytes(32));
} catch (PlantingSiteValidationException $e) {
    $_SESSION['planting_site_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY PLANTING SITE ERROR] ' . $e->getMessage());
    $_SESSION['planting_site_flash'] = [
        'type' => 'danger',
        'message' => 'The planting site could not be saved at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['planting_site_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: planting-sites.php', true, 303);
exit;
