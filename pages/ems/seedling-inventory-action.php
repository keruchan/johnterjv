<?php
/** PRG endpoint for EMS seedling inventory management. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/seedling.php';

require_role($pdo, 'ems');

$userId = (int) $_SESSION['id'];
if (seedling_ems_actor($pdo, $userId) === null) {
    http_response_code(403);
    exit('You are not authorized to manage seedling inventory.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = (string) ($_POST['action'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_seedling_inventory_token'] ?? '');
$redirect = 'seedling-inventory.php';

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new SeedlingValidationException('Security validation failed. Refresh the page and try again.');
    }

    if ($action === 'create_species') {
        $result = seedling_create_species($pdo, $userId, $_POST);
        $_SESSION['seedling_inventory_flash'] = [
            'type' => 'success',
            'message' => 'Added ' . $result['common_name'] . ' with an opening stock of ' . $result['available_quantity'] . '.',
        ];
    } elseif ($action === 'adjust_stock') {
        $result = seedling_adjust_stock($pdo, $userId, $_POST);
        $_SESSION['seedling_inventory_flash'] = [
            'type' => 'success',
            'message' => 'Stock updated (' . ($result['quantity_delta'] > 0 ? '+' : '') . $result['quantity_delta']
                . '); now ' . $result['quantity_after'] . ' available.',
        ];
    } elseif ($action === 'update_species') {
        seedling_update_species($pdo, $userId, $_POST);
        $_SESSION['seedling_inventory_flash'] = ['type' => 'success', 'message' => 'Species record updated.'];
    } else {
        throw new SeedlingValidationException('The requested inventory action is not supported.');
    }
    $_SESSION['csrf_seedling_inventory_token'] = bin2hex(random_bytes(32));
} catch (SeedlingValidationException $e) {
    $_SESSION['seedling_inventory_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('[CERTREEFY SEEDLING INVENTORY ERROR] ' . $e->getMessage());
    $_SESSION['seedling_inventory_flash'] = [
        'type' => 'danger',
        'message' => 'The inventory action could not be completed at this time.',
    ];
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['seedling_inventory_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: ' . $redirect, true, 303);
exit;
