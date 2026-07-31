<?php
/**
 * Community profile management. All reads and writes are scoped to the
 * authenticated session user; request data cannot select another account.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/user.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$errors = [];
$successMessage = '';

if (empty($_SESSION['csrf_profile_token'])) {
    $_SESSION['csrf_profile_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['profile_success'])) {
    $successMessage = (string) $_SESSION['profile_success'];
    unset($_SESSION['profile_success']);
}

try {
    $profileStmt = $pdo->prepare(
        'SELECT id, fname, mname, lname, email, contact, address, username, role, status,
                applicant_category, applicant_subtype
         FROM tbl_users
         WHERE id = :id AND role = :role AND status = :status
         LIMIT 1'
    );
    $profileStmt->execute([
        ':id'     => $userId,
        ':role'   => 'community',
        ':status' => 'active',
    ]);
    $profile = $profileStmt->fetch();
} catch (PDOException $e) {
    error_log('[CERTREEFY PROFILE LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load your profile at this time. Please try again later.');
}

if (!$profile) {
    destroy_authentication_session();
    header('Location: ../auth/login.php');
    exit;
}

$formData = user_profile_data_from_input($profile);
// Classification is fixed once set. Accounts created outside registration can
// still choose theirs here, because no permit can be filed without one.
$classificationLocked = (string) ($profile['applicant_category'] ?? '') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = user_profile_data_from_input($_POST);
    if ($classificationLocked) {
        $formData['applicant_category'] = (string) $profile['applicant_category'];
        $formData['applicant_subtype'] = (string) $profile['applicant_subtype'];
    }

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_profile_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    $errors = array_merge($errors, validate_user_profile_data($formData));
    if (!$classificationLocked) {
        $errors = array_merge($errors, validate_applicant_classification($formData));
    }

    if (empty($errors)) {
        try {
            $conflicts = find_user_identity_conflicts(
                $pdo,
                $formData['username'],
                $formData['email'],
                $userId
            );

            if ($conflicts['username']) {
                $errors[] = 'Username is already taken.';
            }

            if ($conflicts['email']) {
                $errors[] = 'Email address is already registered.';
            }

            if (empty($errors)) {
                $updateStmt = $pdo->prepare(
                    'UPDATE tbl_users
                     SET fname = :fname,
                         mname = :mname,
                         lname = :lname,
                         email = :email,
                         contact = :contact,
                         address = :address,
                         username = :username,
                         applicant_category = :applicant_category,
                         applicant_subtype = :applicant_subtype
                     WHERE id = :id AND role = :role AND status = :status'
                );
                $updateStmt->execute([
                    ':fname'    => $formData['fname'],
                    ':mname'    => $formData['mname'] !== '' ? $formData['mname'] : null,
                    ':lname'    => $formData['lname'],
                    ':email'    => $formData['email'],
                    ':contact'  => $formData['contact'],
                    ':address'  => $formData['address'],
                    ':username' => $formData['username'],
                    ':applicant_category' => $formData['applicant_category'],
                    ':applicant_subtype'  => $formData['applicant_subtype'],
                    ':id'       => $userId,
                    ':role'     => 'community',
                    ':status'   => 'active',
                ]);

                $_SESSION['username'] = $formData['username'];
                $_SESSION['name'] = trim($formData['fname'] . ' ' . $formData['lname']);
                $_SESSION['csrf_profile_token'] = bin2hex(random_bytes(32));
                $_SESSION['profile_success'] = 'Profile updated successfully.';

                header('Location: profile.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('[CERTREEFY PROFILE UPDATE ERROR] ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'Username or email address is already registered.';
            } else {
                $errors[] = 'Unable to update your profile at this time. Please try again later.';
            }
        }
    }
}

$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : trim($profile['fname'] . ' ' . $profile['lname']);
$todayLabel = date('l, F j, Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Client Profile</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=7">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'profile'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Client Account &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Profile Management</h1>
                        <p class="meta-copy mb-0">Keep your contact and account information current.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <section class="row g-3">
                <div class="col-xl-8">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Personal Information</h2>
                            <span class="section-note">Required fields</span>
                        </div>

                        <?php if ($successMessage !== ''): ?>
                            <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
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

                        <form method="post" action="profile.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_profile_token']); ?>">

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
                                <div class="col-12">
                                    <hr>
                                    <h3 class="h6 mb-1">Applicant classification</h3>
                                    <p class="small text-secondary">Determines which tree cutting permit, requirements, and fees apply to your applications.</p>
                                </div>
                                <?php if ($classificationLocked): ?>
                                    <?php $lockedCategory = permit_matrix_category($formData['applicant_category']); ?>
                                    <div class="col-md-6">
                                        <label class="form-label">Classification</label>
                                        <input type="text" class="form-control" value="<?php echo e($lockedCategory['label'] ?? $formData['applicant_category']); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php echo e($lockedCategory['subtype_label'] ?? 'Specific applicant type'); ?></label>
                                        <input type="text" class="form-control" value="<?php echo e($formData['applicant_subtype']); ?>" disabled>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-secondary">
                                            Permit: <?php echo e(($lockedCategory['permit_code'] ?? '-') . ' - ' . ($lockedCategory['permit_title'] ?? '')); ?>.
                                            Contact CENRO if this needs to change.
                                        </small>
                                    </div>
                                <?php else: ?>
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
                                        <div class="alert alert-warning py-2 mb-0 small">
                                            <i class="bi bi-exclamation-triangle me-1"></i>This can only be set once. Choose carefully.
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="col-12 d-flex flex-wrap gap-2 mt-4">
                                    <button type="submit" class="btn btn-certreefy">
                                        <i class="bi bi-check2-circle"></i> Save profile
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>Account Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Role</span>
                            <span class="status-ready">Client</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Status</span>
                            <span class="status-ready">Active</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Username</span>
                            <span class="fw-semibold text-break"><?php echo e($formData['username']); ?></span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$classificationLocked): ?>
    <script>
        (function () {
            var matrix = <?php echo json_encode(array_map(
                static fn (array $c): array => ['subtype_label' => $c['subtype_label'], 'subtypes' => $c['subtypes']],
                permit_matrix_categories()
            ), JSON_THROW_ON_ERROR); ?>;
            var selected = <?php echo json_encode($formData['applicant_subtype'], JSON_THROW_ON_ERROR); ?>;
            var categorySelect = document.getElementById('applicant_category');
            var subtypeSelect = document.getElementById('applicant_subtype');

            function render() {
                var category = matrix[categorySelect.value];
                subtypeSelect.innerHTML = '';
                if (!category) {
                    subtypeSelect.appendChild(new Option('Select classification first', ''));
                    return;
                }
                subtypeSelect.appendChild(new Option('Select ' + category.subtype_label.toLowerCase(), ''));
                category.subtypes.forEach(function (subtype) {
                    var option = new Option(subtype, subtype);
                    option.selected = subtype === selected;
                    subtypeSelect.appendChild(option);
                });
            }

            categorySelect.addEventListener('change', function () { selected = ''; render(); });
            render();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
