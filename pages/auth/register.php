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
require_once __DIR__ . '/../../includes/user.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/email_verification.php';

// ------------------------------------------------------------
// Initial page state
// ------------------------------------------------------------

$errors = [];
$successMessage = '';

// Keep form values in one array so fields can be safely repopulated
// after validation errors. Password fields are intentionally excluded.
$formData = empty_user_profile_data();

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
    $formData = user_profile_data_from_input($_POST);

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    // Validate the CSRF token before doing any database work.
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_register_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    $errors = array_merge($errors, validate_user_profile_data($formData));
    $errors = array_merge($errors, validate_applicant_classification($formData));

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
            $conflicts = find_user_identity_conflicts($pdo, $formData['username'], $formData['email']);

            if ($conflicts['username']) {
                $errors[] = 'Username is already taken.';
            }

            if ($conflicts['email']) {
                $errors[] = 'Email address is already registered.';
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
                        (fname, mname, lname, email, contact, address, username, password, role, status,
                         applicant_category, applicant_subtype)
                     VALUES
                        (:fname, :mname, :lname, :email, :contact, :address, :username, :password, :role, :status,
                         :applicant_category, :applicant_subtype)'
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
                    ':applicant_category' => $formData['applicant_category'],
                    ':applicant_subtype'  => $formData['applicant_subtype'],
                ]);

                $newUserId = (int) $pdo->lastInsertId();

                // Self-service email verification: a valid link activates the
                // account instantly. Manual CENRO activation remains available
                // as a fallback if the email never arrives.
                issueVerificationEmail($pdo, $newUserId, $formData['email'], $formData['fname']);

                // Rotate the token after a successful write so the submitted
                // token cannot be reused.
                $_SESSION['csrf_register_token'] = bin2hex(random_bytes(32));
                $_SESSION['register_success'] = 'Registration submitted successfully. Check your email (' . $formData['email'] . ') for a verification link to activate your account.';

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
    <title>CERTREEFY | Client Registration</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="auth.css?v=2">
</head>
<body>
    <main class="auth-shell d-flex align-items-center py-3">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-11 col-xl-10">
                    <div class="card auth-card">
                        <div class="row g-0">
                            <div class="col-lg-5 auth-side p-4 d-flex flex-column justify-content-center text-center">
                                <div class="auth-side-body">
                                    <a href="../index.php" class="d-inline-block mb-3">
                                        <img src="../../assets/img/certreefy-wordmark-light.png" alt="CERTREEFY" class="brand-wordmark" width="260" height="146">
                                    </a>

                                    <h2 class="auth-headline mb-2">Create your community account</h2>
                                    <p class="mb-3 opacity-75">Apply for permits, request seedlings, and report illegal logging — all in one account.</p>

                                    <div class="auth-note p-2 px-3">
                                        <p class="note-title mb-1"><i class="bi bi-envelope-check me-1"></i>Email verification</p>
                                        <p class="small text-secondary mb-0">After registering, check your email for a verification link to activate your account instantly.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-7">
                                <div class="p-4">
                                    <p class="section-label mb-1">Client access</p>
                                    <h2 class="auth-title mb-3">Register a permit portal account</h2>

                                    <?php if ($successMessage !== ''): ?>
                                        <div class="alert alert-success" role="alert">
                                            <?php echo e($successMessage); ?>
                                            <div class="mt-2">
                                                <a class="alert-link" href="login.php">Go to login</a>
                                                &middot;
                                                <a class="alert-link" href="resend_verification.php">Resend verification email</a>
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

                                        <div class="row g-2">
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

                                            <div class="col-md-8">
                                                <label for="address" class="form-label">Address</label>
                                                <input type="text" class="form-control" id="address" name="address" value="<?php echo e($formData['address']); ?>" maxlength="255" autocomplete="street-address" required>
                                            </div>

                                            <div class="col-md-4">
                                                <label for="username" class="form-label">Username</label>
                                                <input type="text" class="form-control" id="username" name="username" value="<?php echo e($formData['username']); ?>" maxlength="50" autocomplete="username" required>
                                            </div>

                                            <div class="col-12">
                                                <hr class="my-1">
                                                <p class="section-label mb-1">Applicant classification</p>
                                                <p class="small text-secondary mb-2">This determines which tree cutting permit, requirements, and fees apply to your applications.</p>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="applicant_category" class="form-label">Classification</label>
                                                <select class="form-select" id="applicant_category" name="applicant_category" required>
                                                    <option value="">Select classification</option>
                                                    <?php foreach (permit_matrix_categories() as $categoryKey => $category): ?>
                                                        <option value="<?php echo e($categoryKey); ?>"<?php echo $formData['applicant_category'] === $categoryKey ? ' selected' : ''; ?>>
                                                            <?php echo e($category['label'] . ' - ' . $category['permit_code']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="applicant_subtype" class="form-label">Specific applicant type</label>
                                                <select class="form-select" id="applicant_subtype" name="applicant_subtype" required>
                                                    <option value="">Select classification first</option>
                                                </select>
                                            </div>

                                            <div class="col-12">
                                                <div class="auth-note p-2 px-3 d-none" id="permitPreview">
                                                    <p class="note-title mb-1"><i class="bi bi-file-earmark-text me-1"></i><span id="permitPreviewTitle"></span></p>
                                                    <p class="small text-secondary mb-0" id="permitPreviewMeta"></p>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="password" class="form-label">Password</label>
                                                <div class="input-affix">
                                                    <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="128" autocomplete="new-password" placeholder="At least 8 characters" required>
                                                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label for="confirm_password" class="form-label">Confirm password</label>
                                                <div class="input-affix">
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                                                </div>
                                            </div>

                                            <div class="col-12 mt-2">
                                                <button type="submit" class="btn btn-certreefy w-100"><i class="bi bi-person-plus me-1"></i> Submit registration</button>
                                            </div>
                                        </div>
                                    </form>

                                    <p class="text-center text-secondary small mt-3 mb-0">
                                        Already registered?
                                        <a href="login.php" class="auth-link">Login to your account</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        var permitMatrix = <?php echo json_encode(array_map(static fn (array $c): array => [
            'label' => $c['label'],
            'permit_code' => $c['permit_code'],
            'permit_title' => $c['permit_title'],
            'form_code' => $c['form_code'],
            'classification' => $c['classification'],
            'subtype_label' => $c['subtype_label'],
            'subtypes' => $c['subtypes'],
        ], permit_matrix_categories()), JSON_THROW_ON_ERROR); ?>;
        var selectedSubtype = <?php echo json_encode($formData['applicant_subtype'], JSON_THROW_ON_ERROR); ?>;

        (function () {
            var categorySelect = document.getElementById('applicant_category');
            var subtypeSelect = document.getElementById('applicant_subtype');
            var preview = document.getElementById('permitPreview');
            var previewTitle = document.getElementById('permitPreviewTitle');
            var previewMeta = document.getElementById('permitPreviewMeta');

            function render() {
                var category = permitMatrix[categorySelect.value];
                subtypeSelect.innerHTML = '';

                if (!category) {
                    subtypeSelect.appendChild(new Option('Select classification first', ''));
                    preview.classList.add('d-none');
                    return;
                }

                subtypeSelect.appendChild(new Option('Select ' + category.subtype_label.toLowerCase(), ''));
                category.subtypes.forEach(function (subtype) {
                    var option = new Option(subtype, subtype);
                    option.selected = subtype === selectedSubtype;
                    subtypeSelect.appendChild(option);
                });

                previewTitle.textContent = category.permit_code + ' - ' + category.permit_title;
                previewMeta.textContent = 'Form ' + category.form_code + ' | ' + category.classification;
                preview.classList.remove('d-none');
            }

            categorySelect.addEventListener('change', function () {
                selectedSubtype = '';
                render();
            });
            render();
        })();

        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.dataset.target);
                var icon = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                }
            });
        });
    </script>
</body>
</html>
