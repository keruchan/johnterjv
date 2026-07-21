<?php
/**
 * ============================================================
 * File     : pages/auth/resend_verification.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Re-issues a fresh verification token + email for a
 *            Community account whose link expired, was lost, or
 *            never arrived. Invalidates any previously issued token.
 *
 * Security notes:
 * - Always shows the same generic confirmation regardless of whether
 *   the email exists, is already verified, or belongs to a non-
 *   community/staff-managed account — this avoids leaking which
 *   addresses are registered.
 * - Uses a CSRF token because this action mutates server-side state.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/email_verification.php';
require_once __DIR__ . '/../../includes/view.php';

if (empty($_SESSION['csrf_resend_token'])) {
    $_SESSION['csrf_resend_token'] = bin2hex(random_bytes(32));
}

$confirmationMessage = 'If that email is registered and still awaiting verification, a new link has been sent.';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_resend_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, fname, email
                 FROM tbl_users
                 WHERE email = :email AND role = 'community' AND status = 'pending'
                   AND email_verified_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                issueVerificationEmail($pdo, (int) $user['id'], (string) $user['email'], (string) $user['fname']);
            }
        } catch (PDOException $e) {
            error_log('[CERTREEFY RESEND VERIFICATION ERROR] ' . $e->getMessage());
        }

        $_SESSION['csrf_resend_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Resend Verification</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="auth.css">
    <style>
        .verify-shell { min-height: 100vh; animation: authRise 0.5s ease both; }
        @media (prefers-reduced-motion: reduce) { .verify-shell { animation: none; } }
        .verify-card {
            max-width: 460px;
            margin: 0 auto;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: 0 30px 70px -40px rgba(18, 36, 29, 0.5), 0 2px 6px rgba(18, 36, 29, 0.05);
            background: var(--paper);
        }
        .verify-logo {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--canopy-900);
            color: #eaf3ec;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
        }
        .verify-title { font-family: "Fraunces", Georgia, serif; font-weight: 600; }
    </style>
</head>
<body>
    <main class="verify-shell d-flex align-items-center py-4">
        <div class="container">
            <div class="verify-card p-4 p-md-5 text-center">
                <a href="../index.php" class="text-decoration-none">
                    <div class="verify-logo"><i class="bi bi-tree-fill"></i></div>
                </a>
                <p class="section-label mb-3 text-uppercase small text-secondary">Account verification</p>
                <h1 class="verify-title h3 mb-2">Resend verification email</h1>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger text-start" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <form method="post" action="resend_verification.php" class="text-start">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_resend_token']); ?>">
                        <label for="resend_email" class="form-label small">Email address</label>
                        <input type="email" class="form-control mb-3" id="resend_email" name="email" value="<?php echo e((string) ($_POST['email'] ?? '')); ?>" maxlength="150" autocomplete="email" required>
                        <button type="submit" class="btn btn-certreefy w-100"><i class="bi bi-envelope me-1"></i> Resend verification email</button>
                    </form>
                <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                    <div class="alert alert-success" role="alert"><?php echo e($confirmationMessage); ?></div>
                <?php else: ?>
                    <p class="text-secondary mb-4">Enter the email address you registered with and we'll send a fresh verification link.</p>
                    <form method="post" action="resend_verification.php" class="text-start">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_resend_token']); ?>">
                        <label for="resend_email" class="form-label small">Email address</label>
                        <input type="email" class="form-control mb-3" id="resend_email" name="email" maxlength="150" autocomplete="email" required>
                        <button type="submit" class="btn btn-certreefy w-100"><i class="bi bi-envelope me-1"></i> Resend verification email</button>
                    </form>
                <?php endif; ?>

                <p class="text-center text-secondary small mt-4 mb-0">
                    <a href="login.php" class="auth-link">Back to login</a>
                </p>
            </div>
        </div>
    </main>
</body>
</html>
