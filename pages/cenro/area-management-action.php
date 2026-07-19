<?php
/** PRG endpoint for Area Management zone create/update actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/area_management.php';

require_roles($pdo, ['rps', 'superadmin']);

$userId = (int) $_SESSION['id'];
if (area_management_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to manage area zones.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_area_management_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new AreaManagementValidationException('Security validation failed. Refresh the page and try again.');
    }

    if ($action === 'create_zone') {
        $result = area_zone_create($pdo, $userId, $_POST);
        $_SESSION['area_management_flash'] = [
            'type' => 'success',
            'message' => 'Zone "' . $result['zone_name'] . '" added.',
        ];
    } elseif ($action === 'update_zone') {
        $zoneValue = trim((string) ($_POST['zone_id'] ?? ''));
        $zoneId = ctype_digit($zoneValue) ? (int) $zoneValue : 0;
        if ($zoneId < 1) {
            throw new AreaManagementValidationException('The selected zone is invalid.');
        }
        $result = area_zone_update($pdo, $userId, $zoneId, $_POST);
        $_SESSION['area_management_flash'] = [
            'type' => 'success',
            'message' => 'Zone "' . $result['zone_name'] . '" updated.',
        ];
    } else {
        throw new AreaManagementValidationException('The requested action is not supported.');
    }
    $_SESSION['csrf_area_management_token'] = bin2hex(random_bytes(32));
} catch (AreaManagementValidationException $e) {
    $_SESSION['area_management_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY AREA MANAGEMENT ERROR] ' . $e->getMessage());
    $_SESSION['area_management_flash'] = [
        'type' => 'danger',
        'message' => 'The zone could not be saved at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['area_management_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: area-management.php', true, 303);
exit;
