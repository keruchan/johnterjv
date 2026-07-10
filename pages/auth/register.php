<?php
/**
 * ============================================================
 * File     : pages/auth/register.php
 * Project  : CERTREEFY - Tree Cutting Permit & Environmental
 *            Management System (CENRO Sta. Cruz, Laguna)
 * Purpose  : Public community account registration page.
 *
 * Security notes:
 * - Uses the shared hardened session and PDO connection from config.php.
 * - Validates all required fields server-side; HTML "required" is only
 *   a user convenience and must never be trusted by itself.
 * - Uses prepared statements for every database query to prevent SQL injection.
 * - Hashes passwords with password_hash(PASSWORD_DEFAULT); raw passwords
 *   are never stored, logged, or echoed back to the browser.
 * - Uses a CSRF token because registration changes server-side state.
 * - Stores role/status server-side only so users cannot choose elevated roles.
 * - Logs database details server-side and shows only safe generic messages.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';

// ------------------------------------------------------------
// Helper functions
// ------------------------------------------------------------

/**
 * Escape output before rendering it into HTML.
 * This keeps submitted values display-safe without corrupting the
 * original data that will be validated and stored through PDO.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Count text length in characters when mbstring is available.
 * The fallback still protects the database field sizes on systems
 * where mbstring is not enabled.
 */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

// ------------------------------------------------------------
// Initial page state
// ------------------------------------------------------------

$errors = [];
$successMessage = '';

// Keep form values in one array so fields can be safely repopulated
// after validation errors. Password fields are intentionally excluded.
$formData = [
    'fname'    => '',
    'mname'    => '',
    'lname'    => '',
    'email'    => '',
    'contact'  => '',
    'address'  => '',
    'username' => '',
];

// Create a CSRF token once per session. random_bytes() is suitable for
// security tokens because it uses a cryptographically secure source.
if (empty($_SESSION['csrf_register_token'])) {
    $_SESSION['csrf_register_token'] = bin2hex(random_bytes(32));
}

// Success is stored in the session after a POST redirect, which prevents
// accidental duplicate submissions if the user refreshes the page.
if (!empty($_SESSION['register_success'])) {
    $successMessage = (string) $_SESSION['register_success'];
    unset($_SESSION['register_success']);
}

// ------------------------------------------------------------
// Form submission handling
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim surrounding whitespace from normal text fields. Passwords are
    // not trimmed because spaces may be intentional characters.
    $formData['fname']    = trim((string) ($_POST['fname'] ?? ''));
    $formData['mname']    = trim((string) ($_POST['mname'] ?? ''));
    $formData['lname']    = trim((string) ($_POST['lname'] ?? ''));
    $formData['email']    = trim((string) ($_POST['email'] ?? ''));
    $formData['contact']  = trim((string) ($_POST['contact'] ?? ''));
    $formData['address']  = trim((string) ($_POST['address'] ?? ''));
    $formData['username'] = trim((string) ($_POST['username'] ?? ''));

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    // Validate the CSRF token before doing any database work.
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_register_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    // Required-field checks are explicit so a disabled browser or crafted
    // request cannot bypass them.
    if ($formData['fname'] === '') {
        $errors[] = 'First name is required.';
    } elseif (text_length($formData['fname']) > 100) {
        $errors[] = 'First name must not exceed 100 characters.';
    }

    if ($formData['mname'] !== '' && text_length($formData['mname']) > 100) {
        $errors[] = 'Middle name must not exceed 100 characters.';
    }

    if ($formData['lname'] === '') {
        $errors[] = 'Last name is required.';
    } elseif (text_length($formData['lname']) > 100) {
        $errors[] = 'Last name must not exceed 100 characters.';
    }

    if ($formData['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (text_length($formData['email']) > 150) {
        $errors[] = 'Email address must not exceed 150 characters.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($formData['contact'] === '') {
        $errors[] = 'Contact number is required.';
    } elseif (text_length($formData['contact']) > 20) {
        $errors[] = 'Contact number must not exceed 20 characters.';
    } elseif (!preg_match('/^[0-9+\-\s().]{7,20}$/', $formData['contact'])) {
        $errors[] = 'Contact number may contain only numbers, spaces, +, -, parentheses, and periods.';
    }

    if ($formData['address'] === '') {
        $errors[] = 'Address is required.';
    } elseif (text_length($formData['address']) > 255) {
        $errors[] = 'Address must not exceed 255 characters.';
    }

    if ($formData['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $formData['username'])) {
        $errors[] = 'Username must be 3-50 characters and may contain letters, numbers, underscores, periods, or hyphens.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (strlen($password) > 128) {
        $errors[] = 'Password must not exceed 128 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Password and confirmation password do not match.';
    }

    if (empty($errors)) {
        try {
            // Check username and email together using a prepared statement.
            // The database also has UNIQUE indexes; this pre-check gives a
            // friendly message, while the insert catch below handles races.
            $checkStmt = $pdo->prepare(
                'SELECT username, email
                 FROM tbl_users
                 WHERE username = :username OR email = :email'
            );
            $checkStmt->execute([
                ':username' => $formData['username'],
                ':email'    => $formData['email'],
            ]);

            $existingUsers = $checkStmt->fetchAll();

            foreach ($existingUsers as $existingUser) {
                if (isset($existingUser['username']) && strcasecmp($existingUser['username'], $formData['username']) === 0) {
                    $errors[] = 'Username is already taken.';
                }

                if (isset($existingUser['email']) && strcasecmp($existingUser['email'], $formData['email']) === 0) {
                    $errors[] = 'Email address is already registered.';
                }
            }

            if (empty($errors)) {
                // PASSWORD_DEFAULT lets PHP choose the current recommended
                // one-way hashing algorithm while keeping the column flexible.
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Role and status are hardcoded server-side. The form does
                // not include these fields, preventing client tampering.
                $role = 'community';
                $status = 'pending';

                $insertStmt = $pdo->prepare(
                    'INSERT INTO tbl_users
                        (fname, mname, lname, email, contact, address, username, password, role, status)
                     VALUES
                        (:fname, :mname, :lname, :email, :contact, :address, :username, :password, :role, :status)'
                );

                $insertStmt->execute([
                    ':fname'    => $formData['fname'],
                    ':mname'    => $formData['mname'] !== '' ? $formData['mname'] : null,
                    ':lname'    => $formData['lname'],
                    ':email'    => $formData['email'],
                    ':contact'  => $formData['contact'],
                    ':address'  => $formData['address'],
                    ':username' => $formData['username'],
                    ':password' => $passwordHash,
                    ':role'     => $role,
                    ':status'   => $status,
                ]);

                // Rotate the token after a successful write so the submitted
                // token cannot be reused.
                $_SESSION['csrf_register_token'] = bin2hex(random_bytes(32));
                $_SESSION['register_success'] = 'Registration submitted successfully. Your account is pending approval by CENRO.';

                header('Location: register.php');
                exit;
            }
        } catch (PDOException $e) {
            // Duplicate-key errors can still happen under concurrent requests.
            // Show a safe message and log details for administrators only.
            error_log('[CERTREEFY REGISTER ERROR] ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'Username or email address is already registered.';
            } else {
                $errors[] = 'Unable to submit registration at this time. Please try again later.';
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
    <title>CERTREEFY | Community Registration</title>

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
                linear-gradient(135deg, rgba(46, 125, 50, 0.12), rgba(165, 214, 167, 0.18)),
                var(--certreefy-bg);
        }

        .registration-shell {
            min-height: 100vh;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--certreefy-green-dark);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            letter-spacing: 0;
            box-shadow: 0 12px 24px rgba(46, 125, 50, 0.22);
        }

        .registration-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 18px 45px rgba(38, 50, 56, 0.12);
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
            padding: 0.72rem 0.85rem;
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
            padding: 0.78rem 1rem;
        }

        .login-link {
            color: var(--certreefy-green-dark);
            font-weight: 600;
            text-decoration: none;
        }

        .login-link:hover {
            color: #1b5e20;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="registration-shell d-flex align-items-center py-4 py-lg-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-xl-10">
                    <div class="card registration-card">
                        <div class="card-body p-4 p-md-5">
                            <div class="row g-4 g-lg-5 align-items-start">
                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center gap-3 mb-4">
                                        <div class="brand-mark" aria-hidden="true">CT</div>
                                        <div>
                                            <h1 class="h4 fw-bold mb-1">CERTREEFY</h1>
                                            <p class="small text-secondary mb-0">CENRO Sta. Cruz, Laguna</p>
                                        </div>
                                    </div>

                                    <p class="section-label mb-2">Community access</p>
                                    <h2 class="h5 fw-semibold mb-3">Create your permit portal account</h2>
                                    <p class="text-secondary mb-4">
                                        Submitted accounts are reviewed first. Once approved, you can sign in to track requests and environmental services.
                                    </p>

                                    <div class="p-3 rounded-3" style="background: rgba(165, 214, 167, 0.28);">
                                        <p class="fw-semibold mb-1">Account status</p>
                                        <p class="small text-secondary mb-0">New registrations are saved as community accounts with pending approval.</p>
                                    </div>
                                </div>

                                <div class="col-lg-8">
                                    <?php if ($successMessage !== ''): ?>
                                        <div class="alert alert-success" role="alert">
                                            <?php echo e($successMessage); ?>
                                            <div class="mt-2">
                                                <a class="alert-link" href="login.php">Go to login</a>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($errors)): ?>
                                        <div class="alert alert-danger" role="alert">
                                            <p class="fw-semibold mb-2">Please fix the following:</p>
                                            <ul class="mb-0">
                                                <?php foreach ($errors as $error): ?>
                                                    <li><?php echo e($error); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" action="register.php" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_register_token']); ?>">

                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="fname" class="form-label">First name</label>
                                                <input type="text" class="form-control" id="fname" name="fname" value="<?php echo e($formData['fname']); ?>" maxlength="100" autocomplete="given-name" required>
                                            </div>

                                            <div class="col-md-4">
                                                <label for="mname" class="form-label">Middle name</label>
                                                <input type="text" class="form-control" id="mname" name="mname" value="<?php echo e($formData['mname']); ?>" maxlength="100" autocomplete="additional-name">
                                            </div>

                                            <div class="col-md-4">
                                                <label for="lname" class="form-label">Last name</label>
                                                <input type="text" class="form-control" id="lname" name="lname" value="<?php echo e($formData['lname']); ?>" maxlength="100" autocomplete="family-name" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="email" class="form-label">Email address</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($formData['email']); ?>" maxlength="150" autocomplete="email" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="contact" class="form-label">Contact number</label>
                                                <input type="text" class="form-control" id="contact" name="contact" value="<?php echo e($formData['contact']); ?>" maxlength="20" autocomplete="tel" required>
                                            </div>

                                            <div class="col-12">
                                                <label for="address" class="form-label">Address</label>
                                                <input type="text" class="form-control" id="address" name="address" value="<?php echo e($formData['address']); ?>" maxlength="255" autocomplete="street-address" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="username" class="form-label">Username</label>
                                                <input type="text" class="form-control" id="username" name="username" value="<?php echo e($formData['username']); ?>" maxlength="50" autocomplete="username" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Role</label>
                                                <input type="text" class="form-control" value="Community applicant" disabled>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="password" class="form-label">Password</label>
                                                <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="confirm_password" class="form-label">Confirm password</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                            </div>

                                            <div class="col-12 mt-4">
                                                <button type="submit" class="btn btn-certreefy w-100">Submit registration</button>
                                            </div>
                                        </div>
                                    </form>

                                    <p class="text-center text-secondary small mt-4 mb-0">
                                        Already registered?
                                        <a href="login.php" class="login-link">Login to your account</a>
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
