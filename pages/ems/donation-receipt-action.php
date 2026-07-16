<?php
/** PRG endpoint for authorized EMS donation receipt and verification actions. */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_donation_receipts.php';

require_role($pdo, 'ems');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['id'];
$applicationValue = permit_donation_scalar_value($_POST['application_id'] ?? '');
$applicationId = ctype_digit($applicationValue) ? (int) $applicationValue : 0;
$redirect = $applicationId > 0
    ? 'donation-requirements.php?application_id=' . $applicationId . '#receipt-workflow'
    : 'donation-requirements.php';
$submittedToken = permit_donation_scalar_value($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_permit_donation_receipt_token'] ?? '');

try {
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new PermitDonationReceiptValidationException(
            'Security validation failed. Refresh the page and try again.'
        );
    }
    $action = permit_donation_scalar_value($_POST['action'] ?? '');
    $result = record_permit_donation_receipt(
        $pdo,
        $applicationId,
        $userId,
        $action,
        $_POST
    );
    $message = match ((string) $result['verification_status']) {
        'draft' => 'The unfinalized receipt was saved. You may correct it before finalization.',
        'partially_received' => 'The partial receipt was finalized. '
            . (int) $result['remaining_total'] . ' seedlings remain.',
        'ems_verified' => 'EMS physical receipt verification is complete. The application now awaits final RPS verification; the permit was not released.',
        'flagged' => 'The donation transaction was flagged and remains blocked from release.',
        default => 'The EMS donation action was recorded.',
    };
    if (!empty($result['duplicate'])) {
        $message = 'This action was already recorded; no duplicate receipt was created. ' . $message;
    }
    $_SESSION['permit_donation_receipt_flash'] = [
        'type' => 'success',
        'message' => $message,
    ];
    unset($_SESSION['permit_donation_receipt_old_input']);
    $_SESSION['csrf_permit_donation_receipt_token'] = bin2hex(random_bytes(32));
    $_SESSION['permit_donation_receipt_action_key'] = bin2hex(random_bytes(32));
    $_SESSION['permit_donation_flag_action_key'] = bin2hex(random_bytes(32));
} catch (PermitDonationReceiptValidationException $e) {
    $_SESSION['permit_donation_receipt_flash'] = [
        'type' => 'danger',
        'message' => implode(' ', $e->errors()),
    ];
    $_SESSION['permit_donation_receipt_old_input'] = $_POST;
} catch (PDOException $e) {
    error_log('[CERTREEFY EMS DONATION RECEIPT ERROR] ' . $e->getMessage());
    $_SESSION['permit_donation_receipt_flash'] = [
        'type' => 'danger',
        'message' => 'The EMS receipt action could not be recorded at this time.',
    ];
    $_SESSION['permit_donation_receipt_old_input'] = $_POST;
} catch (InvalidArgumentException | RuntimeException $e) {
    $_SESSION['permit_donation_receipt_flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
    $_SESSION['permit_donation_receipt_old_input'] = $_POST;
}

header('Location: ' . $redirect, true, 303);
exit;
