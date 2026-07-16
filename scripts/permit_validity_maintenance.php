<?php
/**
 * Scheduled Tree Cutting Permit validity maintenance.
 *
 * Marks lapsed permits as expired and sends approaching-expiration reminders
 * without requiring anyone to open a dashboard page. Intended to run from a
 * scheduler (Windows Task Scheduler or cron), for example daily:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Certreefy\scripts\permit_validity_maintenance.php
 *
 * The same sweep also runs opportunistically on privileged page loads, so this
 * task is the authoritative, access-independent trigger.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This maintenance script may only be run from the command line.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/permit_release.php';

try {
    $result = permit_run_validity_maintenance($pdo);
    $timestamp = date('Y-m-d H:i:s');
    fwrite(
        STDOUT,
        '[' . $timestamp . '] Permit validity maintenance complete. '
        . 'Expired: ' . (int) $result['expired']['expired'] . '; '
        . 'Expiry reminders: ' . (int) $result['warned']['notified'] . '.' . PHP_EOL
    );
    exit(0);
} catch (Throwable $e) {
    error_log('[CERTREEFY PERMIT VALIDITY MAINTENANCE ERROR] ' . $e->getMessage());
    fwrite(STDERR, 'Permit validity maintenance failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
