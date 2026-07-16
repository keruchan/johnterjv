<?php
/**
 * ============================================================
 * File     : pages/auth/login.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Secure login page for superadmin, RPS, community, and
 *            EMS users.
 *
 * Security notes:
 * - Uses the shared hardened session and PDO connection from config.php.
 * - Accepts either username or email in one field, but uses a prepared
 *   statement with separate placeholders because emulated prepares are off.
 * - Verifies passwords using password_verify() against stored hashes.
 * - Regenerates the session ID after successful authentication to reduce
 *   session fixation risk.
 * - Rejects pending/disabled users only after the password is confirmed,
 *   so strangers cannot enumerate account status without valid credentials.
 * - Uses generic invalid-credential messages for unknown users or wrong
 *   passwords to avoid revealing which part failed.
 * - Throttles repeated failures per identifier and per IP (both sliding
 *   windows) and records every attempt for a failed-login audit trail.
 * - Logs database errors server-side only.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';

// If an authenticated user returns to login.php, send them to the correct
// dashboard instead of showing the login form again.
if (!empty($_SESSION['id']) && !empty($_SESSION['role'])) {
    if (!redirect_by_role((string) $_SESSION['role'])) {
        error_log('[CERTREEFY LOGIN ERROR] Unknown role in existing session.');
        clear_authenticated_user();
        session_regenerate_id(true);
    }
}

// ------------------------------------------------------------
// Initial page state
// ------------------------------------------------------------

$errors = [];
$loginIdentifier = '';

// A session-timeout redirect carries a fixed reason code (never raw user
// input) so the login page can explain why the user was signed out.
$sessionExpiredMessages = [
    'idle' => 'You were signed out after ' . (int) CERTREEFY_SESSION_IDLE_TIMEOUT_MINUTES . ' minutes of inactivity. Please sign in again.',
    'absolute' => 'Your session reached its maximum duration and was signed out. Please sign in again.',
];
$sessionExpiredReason = (string) ($_GET['expired'] ?? '');
$sessionExpiredMessage = $sessionExpiredMessages[$sessionExpiredReason] ?? null;

// Login is a state-changing request because it creates an authenticated
// session, so a CSRF token is used even though the form looks simple.
if (empty($_SESSION['csrf_login_token'])) {
    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------
// Form submission handling
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim only the username/email field. Passwords are not trimmed because
    // spaces may be intentional password characters.
    $loginIdentifier = trim((string) ($_POST['login_identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_login_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($loginIdentifier === '') {
        $errors[] = 'Username or email is required.';
    } elseif (strlen($loginIdentifier) > 150) {
        $errors[] = 'Username or email must not exceed 150 characters.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        $normalizedIdentifier = login_attempt_normalize_identifier($loginIdentifier);
        $requestIpAddress = audit_request_ip_address();

        if (login_attempt_identifier_is_throttled($pdo, $normalizedIdentifier)
            || login_attempt_ip_is_throttled($pdo, $requestIpAddress)) {
            $errors[] = 'Too many failed login attempts. Please wait a few minutes and try again.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, fname, lname, username, email, password, role, status
                     FROM tbl_users
                     WHERE username = :username_login OR email = :email_login
                     LIMIT 1'
                );
                $stmt->execute([
                    ':username_login' => $loginIdentifier,
                    ':email_login'    => $loginIdentifier,
                ]);

                $user = $stmt->fetch();

                if (!$user || !password_verify($password, (string) $user['password'])) {
                    record_login_attempt($pdo, $normalizedIdentifier, $user ? (int) $user['id'] : null, false);
                    // Keep this intentionally generic to avoid confirming whether
                    // a username/email exists in the system.
                    $errors[] = 'Invalid username/email or password.';
                } elseif (($statusError = account_status_error((string) $user['status'])) !== null) {
                    record_login_attempt($pdo, $normalizedIdentifier, (int) $user['id'], false);
                    $errors[] = $statusError;
                } else {
                    $dashboardPath = dashboard_path_for_role((string) $user['role']);

                    if ($dashboardPath === null) {
                        error_log('[CERTREEFY LOGIN ERROR] Unknown role for user ID ' . (int) $user['id']);
                        $errors[] = 'Unable to determine your dashboard. Please contact CENRO for assistance.';
                    } else {
                        record_audit_event(
                            $pdo,
                            (int) $user['id'],
                            'authentication',
                            'login',
                            'user',
                            (int) $user['id'],
                            'Authenticated user login.',
                            ['role' => (string) $user['role']]
                        );
                        record_login_attempt($pdo, $normalizedIdentifier, (int) $user['id'], true);

                        // Rotate the session ID only after valid credentials and
                        // active status are confirmed and the login is audited.
                        session_regenerate_id(true);

                        $_SESSION['id'] = (int) $user['id'];
                        $_SESSION['username'] = (string) $user['username'];
                        $_SESSION['name'] = trim((string) $user['fname'] . ' ' . (string) $user['lname']);
                        $_SESSION['role'] = (string) $user['role'];
                        $_SESSION['login_at'] = time();
                        $_SESSION['last_activity_at'] = time();

                        // A successful login should not leave a reusable token.
                        unset($_SESSION['csrf_login_token']);

                        header('Location: ' . $dashboardPath);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                error_log('[CERTREEFY LOGIN ERROR] ' . $e->getMessage());
                clear_authenticated_user();
                session_regenerate_id(true);
                $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
                $errors[] = 'Unable to sign in at this time. Please try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Login</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --certreefy-green-dark: #2E7D32;
            --certreefy-green: #4CAF50;
            --certreefy-green-soft: #A5D6A7;
            --certreefy-bg: #F5F5F5;
            --certreefy-ink: #263238;
        }

        body {
            min-height: 100vh;
            font-family: "Poppins", Arial, sans-serif;
            color: var(--certreefy-ink);
            background:
                linear-gradient(135deg, rgba(46, 125, 50, 0.12), rgba(165, 214, 167, 0.2)),
                var(--certreefy-bg);
        }

        .login-shell {
            min-height: 100vh;
        }

        .login-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 18px 45px rgba(38, 50, 56, 0.12);
            overflow: hidden;
        }

        .login-side {
            background:
                linear-gradient(160deg, rgba(46, 125, 50, 0.94), rgba(76, 175, 80, 0.86)),
                #2E7D32;
            color: #fff;
        }

        .brand-mark {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            letter-spacing: 0;
        }

        .section-label {
            color: var(--certreefy-green-dark);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .form-control {
            border-color: #d7dfd8;
            border-radius: 8px;
            padding: 0.78rem 0.9rem;
        }

        .form-control:focus {
            border-color: var(--certreefy-green);
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.18);
        }

        .btn-certreefy {
            --bs-btn-color: #fff;
            --bs-btn-bg: var(--certreefy-green-dark);
            --bs-btn-border-color: var(--certreefy-green-dark);
            --bs-btn-hover-color: #fff;
            --bs-btn-hover-bg: #256b2a;
            --bs-btn-hover-border-color: #256b2a;
            --bs-btn-focus-shadow-rgb: 76, 175, 80;
            border-radius: 8px;
            font-weight: 600;
            padding: 0.82rem 1rem;
        }

        .register-link {
            color: var(--certreefy-green-dark);
            font-weight: 600;
            text-decoration: none;
        }

        .register-link:hover {
            color: #1b5e20;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="login-shell d-flex align-items-center py-4 py-lg-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10 col-xl-9">
                    <div class="card login-card">
                        <div class="row g-0">
                            <div class="col-lg-5 login-side p-4 p-md-5 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center gap-3 mb-4">
                                        <div class="brand-mark" aria-hidden="true">CT</div>
                                        <div>
                                            <h1 class="h4 fw-bold mb-1">CERTREEFY</h1>
                                            <p class="small mb-0 opacity-75">CENRO Sta. Cruz, Laguna</p>
                                        </div>
                                    </div>

                                    <h2 class="h4 fw-bold mb-3">Tree Cutting Permit & Environmental Management</h2>
                                    <p class="mb-0 opacity-75">
                                        Sign in to access your assigned dashboard and continue managing approved environmental services.
                                    </p>
                                </div>

                                <div class="mt-4 pt-4 border-top border-white border-opacity-25">
                                    <p class="small mb-1 opacity-75">Authorized roles</p>
                                    <p class="fw-semibold mb-0">CENRO Superadmin, RPS, Community, EMS</p>
                                </div>
                            </div>

                            <div class="col-lg-7">
                                <div class="p-4 p-md-5">
                                    <p class="section-label mb-2">Secure access</p>
                                    <h2 class="h4 fw-bold mb-2">Login to your account</h2>
                                    <p class="text-secondary mb-4">Use your registered username or email address.</p>

                                    <?php if ($sessionExpiredMessage !== null && empty($errors)): ?>
                                        <div class="alert alert-warning" role="alert"><?php echo e($sessionExpiredMessage); ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($errors)): ?>
                                        <div class="alert alert-danger" role="alert">
                                            <ul class="mb-0">
                                                <?php foreach ($errors as $error): ?>
                                                    <li><?php echo e($error); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" action="login.php" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_login_token']); ?>">

                                        <div class="mb-3">
                                            <label for="login_identifier" class="form-label">Username or email</label>
                                            <input type="text" class="form-control" id="login_identifier" name="login_identifier" value="<?php echo e($loginIdentifier); ?>" maxlength="150" autocomplete="username" required>
                                        </div>

                                        <div class="mb-4">
                                            <label for="password" class="form-label">Password</label>
                                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                                        </div>

                                        <button type="submit" class="btn btn-certreefy w-100">Login</button>
                                    </form>

                                    <p class="text-center text-secondary small mt-4 mb-0">
                                        Need a community account?
                                        <a href="register.php" class="register-link">Register here</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
