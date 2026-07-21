<?php
/**
 * ============================================================
 * File     : pages/auth/verify_email.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Landing page for the emailed Community account
 *            verification link. Validates the one-time token and,
 *            if valid, activates the account immediately — no CENRO
 *            staff action required. Manual activation via the CENRO
 *            portal remains available as a fallback (e.g. if an
 *            email never arrives).
 *
 * Security notes:
 * - Only a SHA-256 hash of the token is ever stored; the raw token
 *   exists solely inside the emailed link, same principle as a
 *   password-reset token.
 * - The token is single-use: a successful verification clears both
 *   the token hash and its expiry.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/email_verification.php';
require_once __DIR__ . '/../../includes/view.php';

$rawToken = (string) ($_GET['token'] ?? '');
$state = 'invalid';
$prefillEmail = '';

if ($rawToken !== '') {
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'SELECT id, fname, email, email_verify_expires
         FROM tbl_users
         WHERE email_verify_token = :token_hash
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $user = $stmt->fetch();

    if ($user) {
        $prefillEmail = (string) $user['email'];

        if (strtotime((string) $user['email_verify_expires']) < time()) {
            $state = 'expired';
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE tbl_users
                 SET status = 'active', email_verified_at = NOW(),
                     email_verify_token = NULL, email_verify_expires = NULL
                 WHERE id = :id"
            );
            $updateStmt->execute([':id' => (int) $user['id']]);

            record_audit_event(
                $pdo,
                (int) $user['id'],
                'verification',
                'email_verified',
                'user',
                (int) $user['id'],
                'Community account email verified via link.'
            );

            $state = 'success';
        }
    }
}

if (empty($_SESSION['csrf_resend_token'])) {
    $_SESSION['csrf_resend_token'] = bin2hex(random_bytes(32));
}

$statePresentation = [
    'success' => [
        'icon' => 'bi-check-circle-fill',
        'tone' => 'success',
        'title' => 'Email verified!',
        'body' => 'Your Community account is now active. You can sign in right away.',
    ],
    'expired' => [
        'icon' => 'bi-clock-history',
        'tone' => 'warning',
        'title' => 'Link expired',
        'body' => 'This verification link is more than 24 hours old. Request a new one below.',
    ],
    'invalid' => [
        'icon' => 'bi-x-circle-fill',
        'tone' => 'danger',
        'title' => 'Invalid verification link',
        'body' => 'This link is not recognized. It may have already been used. Request a new one below.',
    ],
];
$presentation = $statePresentation[$state];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Verify Email</title>

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
        .verify-status-icon {
            width: 64px; height: 64px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
        .verify-status-icon.tone-success { background: #eaf6ee; color: #1b6e3c; }
        .verify-status-icon.tone-warning { background: #fdf3e3; color: #9a6a12; }
        .verify-status-icon.tone-danger { background: #fbe9ea; color: #b3283d; }
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

                <div class="verify-status-icon tone-<?php echo e($presentation['tone']); ?>">
                    <i class="bi <?php echo e($presentation['icon']); ?>"></i>
                </div>

                <h1 class="verify-title h3 mb-2"><?php echo e($presentation['title']); ?></h1>
                <p class="text-secondary mb-4"><?php echo e($presentation['body']); ?></p>

                <?php if ($state === 'success'): ?>
                    <a href="login.php" class="btn btn-certreefy w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Go to login</a>
                <?php else: ?>
                    <form method="post" action="resend_verification.php" class="text-start">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_resend_token']); ?>">
                        <label for="resend_email" class="form-label small">Email address</label>
                        <input type="email" class="form-control mb-3" id="resend_email" name="email" value="<?php echo e($prefillEmail); ?>" maxlength="150" autocomplete="email" required>
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
