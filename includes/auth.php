<?php
/**
 * Shared authentication, role-routing, and session helpers.
 * Current callers live one directory below pages/, so routes use ../.
 */

require_once __DIR__ . '/audit.php';

function dashboard_path_for_role(string $role): ?string
{
    $routes = [
        'superadmin' => '../cenro/dashboard.php',
        'rps'        => '../cenro/dashboard.php',
        'community'  => '../community/dashboard.php',
        'ems'        => '../ems/dashboard.php',
    ];

    return $routes[$role] ?? null;
}

function redirect_by_role(string $role): bool
{
    $path = dashboard_path_for_role($role);

    if ($path === null) {
        return false;
    }

    header('Location: ' . $path);
    exit;
}

function require_roles(PDO $pdo, array $allowedRoles): void
{
    if (empty($_SESSION['id'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    $expirationReason = session_expiration_reason();
    if ($expirationReason !== null) {
        destroy_authentication_session();
        header('Location: ../auth/login.php?expired=' . $expirationReason);
        exit;
    }

    $allowedRoles = array_values(array_unique(array_filter(
        $allowedRoles,
        static fn ($role): bool => is_string($role) && $role !== ''
    )));

    if ($allowedRoles === []) {
        error_log('[CERTREEFY RBAC ERROR] A restricted page has no allowed roles configured.');
        http_response_code(500);
        exit('Unable to authorize this request at this time.');
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, fname, lname, username, role, status
             FROM tbl_users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int) $_SESSION['id']]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[CERTREEFY RBAC ERROR] ' . $e->getMessage());
        http_response_code(503);
        exit('Unable to authorize this request at this time. Please try again later.');
    }

    if (!$user || (string) $user['status'] !== 'active') {
        destroy_authentication_session();
        header('Location: ../auth/login.php');
        exit;
    }

    $currentRole = (string) $user['role'];

    // The database remains authoritative if a role, name, or username changes
    // while the user already has an active session.
    $_SESSION['id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['name'] = trim((string) $user['fname'] . ' ' . (string) $user['lname']);
    $_SESSION['role'] = $currentRole;
    if (empty($_SESSION['csrf_logout_token'])) {
        $_SESSION['csrf_logout_token'] = bin2hex(random_bytes(32));
    }

    if (in_array($currentRole, $allowedRoles, true)) {
        session_touch_activity();

        return;
    }

    if (!redirect_by_role($currentRole)) {
        destroy_authentication_session();
        header('Location: ../auth/login.php');
        exit;
    }
}

function require_role(PDO $pdo, string $requiredRole): void
{
    require_roles($pdo, [$requiredRole]);
}

function account_status_error(string $status): ?string
{
    return match ($status) {
        'active' => null,
        'pending' => 'Your account is not yet verified. Please check your email for the verification link.',
        'suspended' => 'Your account is suspended. Please contact CENRO for assistance.',
        'disabled' => 'Your account is deactivated. Please contact CENRO for assistance.',
        default => 'Your account is inactive and cannot sign in. Please contact CENRO for assistance.',
    };
}

function clear_authenticated_user(): void
{
    unset(
        $_SESSION['id'],
        $_SESSION['username'],
        $_SESSION['name'],
        $_SESSION['role'],
        $_SESSION['login_at'],
        $_SESSION['last_activity_at'],
        $_SESSION['csrf_logout_token']
    );
}

/**
 * Idle and absolute session timeouts. An idle session expires after a period
 * of no requests; an absolute session expires a fixed duration after login
 * regardless of activity. Whichever limit is reached first ends the session.
 */

function session_idle_timeout_seconds(): int
{
    return (defined('CERTREEFY_SESSION_IDLE_TIMEOUT_MINUTES') ? (int) CERTREEFY_SESSION_IDLE_TIMEOUT_MINUTES : 30) * 60;
}

function session_absolute_timeout_seconds(): int
{
    return (defined('CERTREEFY_SESSION_ABSOLUTE_TIMEOUT_MINUTES') ? (int) CERTREEFY_SESSION_ABSOLUTE_TIMEOUT_MINUTES : 480) * 60;
}

/** Marks the current request as activity, starting the tracking window if absent. */
function session_touch_activity(): void
{
    $now = time();
    if (empty($_SESSION['login_at'])) {
        $_SESSION['login_at'] = $now;
    }
    $_SESSION['last_activity_at'] = $now;
}

/**
 * Returns 'idle' or 'absolute' if the current authenticated session has
 * expired under the corresponding rule, or null if it is still valid. A
 * session with no tracking timestamps yet (e.g. one predating this feature)
 * is treated as freshly started rather than immediately expired.
 */
function session_expiration_reason(): ?string
{
    $now = time();
    $loginAt = (int) ($_SESSION['login_at'] ?? $now);
    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? $now);

    if ($now - $loginAt > session_absolute_timeout_seconds()) {
        return 'absolute';
    }
    if ($now - $lastActivityAt > session_idle_timeout_seconds()) {
        return 'idle';
    }

    return null;
}

/**
 * Read-only login-attempt registry for the Superadmin audit-history viewer:
 * filterable, paginated listing backed by tbl_login_attempts.
 */

function login_attempts_normalize_filters(array $input): array
{
    $status = trim((string) ($input['login_status'] ?? ''));
    $page = filter_var($input['login_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $dateFrom = trim((string) ($input['login_date_from'] ?? ''));
    $dateTo = trim((string) ($input['login_date_to'] ?? ''));

    return [
        'q' => substr(trim((string) ($input['login_q'] ?? '')), 0, 150),
        'status' => in_array($status, ['success', 'failed'], true) ? $status : '',
        'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '',
        'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '',
        'page' => $page === false ? 1 : (int) $page,
    ];
}

function login_attempts_query_string(array $filters, array $overrides = []): string
{
    $query = [
        'login_q' => $filters['q'],
        'login_status' => $filters['status'],
        'login_date_from' => $filters['date_from'],
        'login_date_to' => $filters['date_to'],
        'login_page' => $filters['page'],
    ];
    foreach ($overrides as $key => $value) {
        $query['login_' . $key] = $value;
    }
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || ($key === 'login_page' && (int) $value <= 1)) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function login_attempts_list(PDO $pdo, array $filters, int $perPage = 20): array
{
    $where = [];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = 'la.identifier LIKE :search';
        $params[':search'] = '%' . $filters['q'] . '%';
    }
    if ($filters['status'] === 'success') {
        $where[] = 'la.was_successful = 1';
    } elseif ($filters['status'] === 'failed') {
        $where[] = 'la.was_successful = 0';
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'la.created_at >= :date_from';
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'la.created_at <= :date_to';
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_login_attempts la' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min((int) $filters['page'], $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        'SELECT la.id, la.identifier, la.user_id, la.ip_address, la.user_agent,
                la.was_successful, la.created_at,
                CASE WHEN u.id IS NULL THEN NULL ELSE CONCAT(u.fname, \' \', u.lname) END AS matched_user_name
         FROM tbl_login_attempts la
         LEFT JOIN tbl_users u ON u.id = la.user_id' . $whereSql . '
         ORDER BY la.created_at DESC, la.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $name => $value) {
        $listStmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    return [
        'attempts' => $listStmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'first' => $total === 0 ? 0 : $offset + 1,
        'last' => min($offset + $perPage, $total),
    ];
}

function destroy_authentication_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParams = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $cookieParams['path'] ?? '/',
            'domain'   => $cookieParams['domain'] ?? '',
            'secure'   => (bool) ($cookieParams['secure'] ?? false),
            'httponly' => (bool) ($cookieParams['httponly'] ?? true),
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Login throttling and failed-login audit. Two independent sliding-window
 * counters are enforced: a tight per-identifier limit (protects one account
 * from repeated guesses) and a looser per-IP limit (slows a single actor
 * spraying many usernames). Neither mutates account status, so a legitimate
 * user who mistyped a password is never locked out beyond the time window.
 */

function login_throttle_max_attempts_per_identifier(): int
{
    return defined('CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IDENTIFIER')
        ? (int) CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IDENTIFIER
        : 5;
}

function login_throttle_max_attempts_per_ip(): int
{
    return defined('CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IP')
        ? (int) CERTREEFY_LOGIN_MAX_ATTEMPTS_PER_IP
        : 20;
}

function login_throttle_window_minutes(): int
{
    return defined('CERTREEFY_LOGIN_LOCKOUT_MINUTES')
        ? (int) CERTREEFY_LOGIN_LOCKOUT_MINUTES
        : 15;
}

/** Normalizes a submitted username/email into a stable throttle/audit key. */
function login_attempt_normalize_identifier(string $identifier): string
{
    return substr(mb_strtolower(trim($identifier)), 0, 150);
}

function login_attempt_identifier_is_throttled(PDO $pdo, string $identifier): bool
{
    if ($identifier === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_login_attempts
         WHERE identifier = :identifier AND was_successful = 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':minutes' => login_throttle_window_minutes(),
    ]);

    return (int) $stmt->fetchColumn() >= login_throttle_max_attempts_per_identifier();
}

function login_attempt_ip_is_throttled(PDO $pdo, ?string $ipAddress): bool
{
    if ($ipAddress === null || $ipAddress === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_login_attempts
         WHERE ip_address = :ip_address AND was_successful = 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
    );
    $stmt->execute([
        ':ip_address' => $ipAddress,
        ':minutes' => login_throttle_window_minutes(),
    ]);

    return (int) $stmt->fetchColumn() >= login_throttle_max_attempts_per_ip();
}

/** Records one login attempt (success or failure) for throttling and audit. */
function record_login_attempt(PDO $pdo, string $identifier, ?int $userId, bool $successful): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_login_attempts
            (identifier, user_id, ip_address, user_agent, was_successful)
         VALUES
            (:identifier, :user_id, :ip_address, :user_agent, :was_successful)'
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':user_id' => $userId,
        ':ip_address' => audit_request_ip_address(),
        ':user_agent' => audit_request_user_agent(),
        ':was_successful' => $successful ? 1 : 0,
    ]);
}
