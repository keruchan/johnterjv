<?php
/**
 * CENRO Superadmin user registry and account administration.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/user.php';
require_once __DIR__ . '/../../includes/user_management.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'superadmin');

$currentRole = (string) $_SESSION['role'];
$actorUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'CENRO Superadmin';
$roleLabels = user_management_role_labels();
$editableRoles = user_management_editable_roles();
$statusLabels = user_management_status_labels();
$filters = user_management_normalize_filters($_GET);
$filterQuery = user_management_query_string($filters);
$formAction = 'user-management.php' . ($filterQuery !== '' ? '?' . $filterQuery : '');
$errors = [];
$successMessage = '';
$viewUser = null;
$editUser = null;
$editForm = empty_user_profile_data() + ['role' => 'community'];
$createForm = empty_user_profile_data() + ['role' => 'community'];
$modalToOpen = null;

if (empty($_SESSION['csrf_user_management_token'])) {
    $_SESSION['csrf_user_management_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['user_management_success'])) {
    $successMessage = (string) $_SESSION['user_management_success'];
    unset($_SESSION['user_management_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_user_management_token'] ?? '');
    $csrfValid = $sessionToken !== '' && $submittedToken !== '' && hash_equals($sessionToken, $submittedToken);

    if (!$csrfValid) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $modalToOpen = 'addUserModal';
    $createForm = user_profile_data_from_input($_POST);
    $createForm['role'] = trim((string) ($_POST['role'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

    $errors = array_merge($errors, validate_user_profile_data($createForm));

    if (!array_key_exists($createForm['role'], $editableRoles)) {
        $errors[] = 'Select an authorized user role.';
    }
    if ($newPassword === '') {
        $errors[] = 'Enter a password.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'The password must be at least 8 characters.';
    } elseif (strlen($newPassword) > 128) {
        $errors[] = 'The password must not exceed 128 characters.';
    } elseif ($confirmNewPassword === '') {
        $errors[] = 'Confirm the password.';
    } elseif ($newPassword !== $confirmNewPassword) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if ($errors === []) {
        try {
            $conflicts = find_user_identity_conflicts($pdo, $createForm['username'], $createForm['email']);
            if ($conflicts['username']) {
                $errors[] = 'Username is already taken.';
            }
            if ($conflicts['email']) {
                $errors[] = 'Email address is already registered.';
            }
        } catch (PDOException $e) {
            error_log('[CERTREEFY USER MANAGEMENT CREATE CONFLICT ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to validate the account information at this time.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare(
                'INSERT INTO tbl_users (fname, mname, lname, email, contact, address, username, password, role, status)
                 VALUES (:fname, :mname, :lname, :email, :contact, :address, :username, :password, :role, \'active\')'
            );
            $insertStmt->execute([
                ':fname'    => $createForm['fname'],
                ':mname'    => $createForm['mname'] !== '' ? $createForm['mname'] : null,
                ':lname'    => $createForm['lname'],
                ':email'    => $createForm['email'],
                ':contact'  => $createForm['contact'],
                ':address'  => $createForm['address'],
                ':username' => $createForm['username'],
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':role'     => $createForm['role'],
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            record_audit_event(
                $pdo,
                $actorUserId,
                'user_management',
                'user_created',
                'user',
                $newUserId,
                'Registered a new user account.',
                [
                    'role' => $createForm['role'],
                    'name' => trim($createForm['fname'] . ' ' . $createForm['lname']),
                ]
            );

            $pdo->commit();
            $_SESSION['user_management_success'] = 'Account created for '
                . trim($createForm['fname'] . ' ' . $createForm['lname'])
                . ' (' . $roleLabels[$createForm['role']] . ').';
            header('Location: ' . $formAction);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CERTREEFY USER MANAGEMENT CREATE ERROR] ' . $e->getMessage());
            $errors[] = $e->getCode() === '23000'
                ? 'Username or email address is already registered.'
                : 'Unable to create the account at this time. Please try again.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CERTREEFY USER MANAGEMENT CREATE ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to create the account at this time. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'create_user') {
    $action = (string) ($_POST['action'] ?? '');
    $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($targetUserId === false || $targetUserId === null) {
        $errors[] = 'A valid user account is required.';
    }

    $targetUser = null;
    if ($errors === []) {
        try {
            $targetUser = user_management_find_user($pdo, (int) $targetUserId);
        } catch (PDOException $e) {
            error_log('[CERTREEFY USER MANAGEMENT LOAD ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to load the selected user at this time.';
        }

        if (!$targetUser) {
            $errors[] = 'The selected user account no longer exists.';
        } elseif (!user_management_can_modify($targetUser)) {
            http_response_code(403);
            $errors[] = 'Superadmin accounts cannot be modified from User Management.';
        }
    }

    if ($action === 'edit_user' && $targetUser) {
        $editUser = $targetUser;
        $editForm = user_profile_data_from_input($_POST);
        $editForm['role'] = trim((string) ($_POST['role'] ?? ''));
        $modalToOpen = 'editUserModal';

        $errors = array_merge($errors, validate_user_profile_data($editForm));

        if (!array_key_exists($editForm['role'], $editableRoles)) {
            $errors[] = 'Select an authorized user role.';
        }

        if ($errors === []) {
            try {
                $conflicts = find_user_identity_conflicts(
                    $pdo,
                    $editForm['username'],
                    $editForm['email'],
                    (int) $targetUser['id']
                );

                if ($conflicts['username']) {
                    $errors[] = 'Username is already taken.';
                }
                if ($conflicts['email']) {
                    $errors[] = 'Email address is already registered.';
                }
            } catch (PDOException $e) {
                error_log('[CERTREEFY USER MANAGEMENT CONFLICT ERROR] ' . $e->getMessage());
                $errors[] = 'Unable to validate the account information at this time.';
            }
        }

        if ($errors === []) {
            $editableFields = ['fname', 'mname', 'lname', 'email', 'contact', 'address', 'username', 'role'];
            $changedFields = [];

            foreach ($editableFields as $field) {
                if ((string) ($targetUser[$field] ?? '') !== (string) $editForm[$field]) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields === []) {
                $errors[] = 'No changes were detected.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $updateStmt = $pdo->prepare(
                        'UPDATE tbl_users
                         SET fname = :fname,
                             mname = :mname,
                             lname = :lname,
                             email = :email,
                             contact = :contact,
                             address = :address,
                             username = :username,
                             role = :role
                         WHERE id = :id AND role <> :protected_role'
                    );
                    $updateStmt->execute([
                        ':fname'          => $editForm['fname'],
                        ':mname'          => $editForm['mname'] === '' ? null : $editForm['mname'],
                        ':lname'          => $editForm['lname'],
                        ':email'          => $editForm['email'],
                        ':contact'        => $editForm['contact'],
                        ':address'        => $editForm['address'],
                        ':username'       => $editForm['username'],
                        ':role'           => $editForm['role'],
                        ':id'             => (int) $targetUser['id'],
                        ':protected_role' => 'superadmin',
                    ]);

                    if ($updateStmt->rowCount() !== 1) {
                        throw new RuntimeException('The account became protected before the update completed.');
                    }

                    user_management_record_audit(
                        $pdo,
                        $actorUserId,
                        (int) $targetUser['id'],
                        'user_updated',
                        $targetUser,
                        ['role' => $editForm['role'], 'status' => $targetUser['status']],
                        $changedFields
                    );

                    $pdo->commit();
                    $_SESSION['user_management_success'] = 'User information updated successfully.';
                    header('Location: ' . $formAction);
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[CERTREEFY USER MANAGEMENT UPDATE ERROR] ' . $e->getMessage());
                    $errors[] = 'Unable to update the user at this time. Please try again.';
                }
            }
        }
    } elseif ($action === 'reset_password' && $targetUser) {
        $modalToOpen = 'resetPasswordModal';
        $resetPasswordUserId = (int) $targetUser['id'];
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

        if ($newPassword === '') {
            $errors[] = 'Enter a new password.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'The new password must be at least 8 characters.';
        } elseif (strlen($newPassword) > 128) {
            $errors[] = 'The new password must not exceed 128 characters.';
        } elseif ($confirmNewPassword === '') {
            $errors[] = 'Confirm the new password.';
        } elseif ($newPassword !== $confirmNewPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $passwordStmt = $pdo->prepare(
                    'UPDATE tbl_users
                     SET password = :password
                     WHERE id = :id AND role <> :protected_role'
                );
                $passwordStmt->execute([
                    ':password'       => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id'             => (int) $targetUser['id'],
                    ':protected_role' => 'superadmin',
                ]);

                if ($passwordStmt->rowCount() !== 1) {
                    throw new RuntimeException('The account became protected before the password reset completed.');
                }

                user_management_record_audit(
                    $pdo,
                    $actorUserId,
                    (int) $targetUser['id'],
                    'user_updated',
                    $targetUser,
                    ['role' => $targetUser['role'], 'status' => $targetUser['status']],
                    ['password']
                );
                create_notification(
                    $pdo,
                    (int) $targetUser['id'],
                    $actorUserId,
                    'account_status',
                    'Password reset by an administrator',
                    'Your CERTREEFY account password was reset by a CENRO Superadmin. If you did not expect this, contact CENRO immediately.',
                    'user',
                    (int) $targetUser['id']
                );

                $pdo->commit();
                $_SESSION['user_management_success'] = 'Password reset successfully.';
                header('Location: ' . $formAction);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[CERTREEFY USER MANAGEMENT PASSWORD RESET ERROR] ' . $e->getMessage());
                $errors[] = 'Unable to reset the password at this time. Please try again.';
            }
        }
    } elseif (in_array($action, ['activate', 'suspend', 'deactivate'], true) && $targetUser && $errors === []) {
        $newStatus = user_management_target_status($action, (string) $targetUser['status']);

        if ($newStatus === null) {
            $errors[] = 'That status change is not allowed for the selected account.';
        } else {
            try {
                $pdo->beginTransaction();

                $statusStmt = $pdo->prepare(
                    'UPDATE tbl_users
                     SET status = :new_status
                     WHERE id = :id
                       AND role <> :protected_role
                       AND status = :previous_status'
                );
                $statusStmt->execute([
                    ':new_status'     => $newStatus,
                    ':id'             => (int) $targetUser['id'],
                    ':protected_role' => 'superadmin',
                    ':previous_status'=> (string) $targetUser['status'],
                ]);

                if ($statusStmt->rowCount() !== 1) {
                    throw new RuntimeException('The account changed before the status update completed.');
                }

                user_management_record_audit(
                    $pdo,
                    $actorUserId,
                    (int) $targetUser['id'],
                    'status_changed',
                    $targetUser,
                    ['role' => $targetUser['role'], 'status' => $newStatus],
                    ['status']
                );

                $pdo->commit();
                $_SESSION['user_management_success'] = 'Account status changed to ' . strtolower($statusLabels[$newStatus]) . '.';
                header('Location: ' . $formAction);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[CERTREEFY USER MANAGEMENT STATUS ERROR] ' . $e->getMessage());
                $errors[] = 'Unable to change the account status at this time. Please try again.';
            }
        }
    } elseif ($action !== 'edit_user' && !in_array($action, ['activate', 'suspend', 'deactivate', 'reset_password'], true)) {
        $errors[] = 'The requested account action is not supported.';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $viewUserId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $editUserId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if ($viewUserId !== false && $viewUserId !== null) {
            $viewUser = user_management_find_user($pdo, (int) $viewUserId);
            if ($viewUser) {
                $modalToOpen = 'viewUserModal';
            } else {
                $errors[] = 'The selected user account no longer exists.';
            }
        } elseif ($editUserId !== false && $editUserId !== null) {
            $editUser = user_management_find_user($pdo, (int) $editUserId);
            if (!$editUser) {
                $errors[] = 'The selected user account no longer exists.';
            } elseif (!user_management_can_modify($editUser)) {
                http_response_code(403);
                $errors[] = 'Superadmin accounts cannot be modified from User Management.';
                $editUser = null;
            } else {
                $editForm = user_profile_data_from_input($editUser);
                $editForm['role'] = (string) $editUser['role'];
                $modalToOpen = 'editUserModal';
            }
        }
    } catch (PDOException $e) {
        error_log('[CERTREEFY USER MANAGEMENT DETAIL ERROR] ' . $e->getMessage());
        $errors[] = 'Unable to load the selected user at this time.';
    }
}

try {
    $userList = user_management_list($pdo, $filters, 10);
    $statusCounts = user_management_status_counts($pdo);
} catch (PDOException $e) {
    error_log('[CERTREEFY USER MANAGEMENT LIST ERROR] ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load User Management at this time. Please try again later.');
}

$filters['page'] = $userList['page'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | User Management</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=6">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'user_management'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">CENRO Superadmin &middot; Account Registry</div>
                        <h1 class="page-title">User Management</h1>
                        <p class="text-secondary meta-copy mb-0">Account records and access status for CERTREEFY users.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_certreefy_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#a9c4ac" stroke-width="2"/>
                </svg>
            </section>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo e($successMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($errors !== [] && !in_array($modalToOpen, ['editUserModal', 'addUserModal'], true)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="row g-3 mb-4" aria-label="Account status summary">
                <?php
                $summaryCards = [
                    ['pending', 'hourglass-split', 'Pending approval', ''],
                    ['active', 'person-check', 'Active accounts', 'accent-teal'],
                    ['suspended', 'pause-circle', 'Suspended accounts', 'accent-amber'],
                    ['disabled', 'person-x', 'Deactivated accounts', 'accent-rust'],
                ];
                ?>
                <?php foreach ($summaryCards as [$status, $icon, $label, $tone]): ?>
                    <div class="col-sm-6 col-xl-3">
                        <div class="ledger-card <?php echo e($tone); ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-<?php echo e($icon); ?>"></i></span>
                                <span class="ledger-tag">Accounts</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo e((string) $statusCounts[$status]); ?></div>
                            <div class="ledger-caption"><?php echo e($label); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="docket-panel user-filter-panel mb-4" aria-label="User filters">
                <form method="get" action="user-management.php" class="row g-3 align-items-end">
                    <div class="col-lg-5">
                        <label for="userSearch" class="form-label">Search users</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="userSearch" name="q" value="<?php echo e($filters['q']); ?>" maxlength="100" placeholder="Name, username, email, or contact">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="roleFilter" class="form-label">Role</label>
                        <select class="form-select" id="roleFilter" name="role">
                            <option value="">All roles</option>
                            <?php foreach ($roleLabels as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filters['role'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-certreefy flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a class="btn btn-outline-secondary" href="user-management.php" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </section>

            <section class="docket-panel user-table-panel" aria-labelledby="userTableHeading">
                <div class="section-heading">
                    <h2 id="userTableHeading">User Registry</h2>
                    <div class="d-flex align-items-center gap-3">
                        <span class="section-note tabular">
                            <?php echo e((string) $userList['first']); ?>-<?php echo e((string) $userList['last']); ?> of <?php echo e((string) $userList['total']); ?>
                        </span>
                        <button type="button" class="btn btn-certreefy btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus me-1"></i>Add user</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table user-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Contact</th>
                                <th scope="col">Role</th>
                                <th scope="col">Status</th>
                                <th scope="col">Registered</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($userList['users'] === []): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-secondary py-5">No user accounts found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($userList['users'] as $user): ?>
                                <?php
                                $fullName = trim((string) $user['fname'] . ' ' . (string) ($user['mname'] ?? '') . ' ' . (string) $user['lname']);
                                $isModifiable = user_management_can_modify($user);
                                $detailQuery = user_management_query_string($filters, ['view' => (int) $user['id']]);
                                $editQuery = user_management_query_string($filters, ['edit' => (int) $user['id']]);
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-identity">
                                            <span class="user-avatar" aria-hidden="true"><?php echo e(strtoupper(substr((string) $user['fname'], 0, 1))); ?></span>
                                            <div>
                                                <div class="user-name"><?php echo e($fullName); ?></div>
                                                <div class="user-meta">@<?php echo e((string) $user['username']); ?> &middot; <?php echo e((string) $user['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo e((string) ($user['contact'] ?: 'Not provided')); ?></td>
                                    <td><span class="role-badge"><?php echo e($roleLabels[$user['role']] ?? ucfirst((string) $user['role'])); ?></span></td>
                                    <td><span class="account-status status-<?php echo e((string) $user['status']); ?>"><?php echo e($statusLabels[$user['status']] ?? ucfirst((string) $user['status'])); ?></span></td>
                                    <td class="text-nowrap"><?php echo e(date('M j, Y', strtotime((string) $user['created_at']))); ?></td>
                                    <td>
                                        <div class="user-actions justify-content-end">
                                            <a class="btn btn-sm btn-outline-secondary icon-action" href="user-management.php?<?php echo e($detailQuery); ?>" title="View user" data-bs-toggle="tooltip" aria-label="View <?php echo e($fullName); ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($isModifiable): ?>
                                                <a class="btn btn-sm btn-outline-primary icon-action" href="user-management.php?<?php echo e($editQuery); ?>" title="Edit user" data-bs-toggle="tooltip" aria-label="Edit <?php echo e($fullName); ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary icon-action" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Account actions for <?php echo e($fullName); ?>">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><button class="dropdown-item reset-password-action" type="button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-user-id="<?php echo e((string) $user['id']); ?>" data-user-name="<?php echo e($fullName); ?>"><i class="bi bi-key me-2"></i>Reset password</button></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <?php if ((string) $user['status'] !== 'active'): ?>
                                                            <li><button class="dropdown-item status-action" type="button" data-bs-toggle="modal" data-bs-target="#statusModal" data-action="activate" data-user-id="<?php echo e((string) $user['id']); ?>" data-user-name="<?php echo e($fullName); ?>" data-action-label="Activate"><i class="bi bi-check-circle me-2"></i>Activate</button></li>
                                                        <?php endif; ?>
                                                        <?php if ((string) $user['status'] === 'active'): ?>
                                                            <li><button class="dropdown-item status-action" type="button" data-bs-toggle="modal" data-bs-target="#statusModal" data-action="suspend" data-user-id="<?php echo e((string) $user['id']); ?>" data-user-name="<?php echo e($fullName); ?>" data-action-label="Suspend"><i class="bi bi-pause-circle me-2"></i>Suspend</button></li>
                                                        <?php endif; ?>
                                                        <?php if ((string) $user['status'] !== 'disabled'): ?>
                                                            <li><button class="dropdown-item status-action text-danger" type="button" data-bs-toggle="modal" data-bs-target="#statusModal" data-action="deactivate" data-user-id="<?php echo e((string) $user['id']); ?>" data-user-name="<?php echo e($fullName); ?>" data-action-label="Deactivate"><i class="bi bi-person-x me-2"></i>Deactivate</button></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary icon-action" type="button" disabled title="Protected Superadmin account" aria-label="Protected Superadmin account"><i class="bi bi-lock"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($userList['total_pages'] > 1): ?>
                    <nav class="mt-4" aria-label="User registry pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php for ($pageNumber = 1; $pageNumber <= $userList['total_pages']; $pageNumber++): ?>
                                <?php $pageQuery = user_management_query_string($filters, ['page' => $pageNumber]); ?>
                                <li class="page-item <?php echo $pageNumber === $userList['page'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="user-management.php<?php echo $pageQuery !== '' ? '?' . e($pageQuery) : ''; ?>"><?php echo e((string) $pageNumber); ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <?php if ($viewUser): ?>
        <?php $viewFullName = trim((string) $viewUser['fname'] . ' ' . (string) ($viewUser['mname'] ?? '') . ' ' . (string) $viewUser['lname']); ?>
        <div class="modal fade" id="viewUserModal" tabindex="-1" aria-labelledby="viewUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="eyebrow mb-1">User Record #<?php echo e((string) $viewUser['id']); ?></div>
                            <h2 class="modal-title fs-5" id="viewUserModalLabel"><?php echo e($viewFullName); ?></h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="row user-detail-list mb-0">
                            <dt class="col-sm-4">Username</dt><dd class="col-sm-8">@<?php echo e((string) $viewUser['username']); ?></dd>
                            <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?php echo e((string) $viewUser['email']); ?></dd>
                            <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?php echo e((string) ($viewUser['contact'] ?: 'Not provided')); ?></dd>
                            <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?php echo e((string) ($viewUser['address'] ?: 'Not provided')); ?></dd>
                            <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?php echo e($roleLabels[$viewUser['role']] ?? ucfirst((string) $viewUser['role'])); ?></dd>
                            <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?php echo e($statusLabels[$viewUser['status']] ?? ucfirst((string) $viewUser['status'])); ?></dd>
                            <dt class="col-sm-4">Registered</dt><dd class="col-sm-8"><?php echo e(date('F j, Y g:i A', strtotime((string) $viewUser['created_at']))); ?></dd>
                            <dt class="col-sm-4">Last updated</dt><dd class="col-sm-8"><?php echo $viewUser['updated_at'] ? e(date('F j, Y g:i A', strtotime((string) $viewUser['updated_at']))) : 'No changes recorded'; ?></dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($editUser): ?>
        <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" action="<?php echo e($formAction); ?>" novalidate>
                        <div class="modal-header">
                            <div>
                                <div class="eyebrow mb-1">Account #<?php echo e((string) $editUser['id']); ?></div>
                                <h2 class="modal-title fs-5" id="editUserModalLabel">Edit User Information</h2>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($errors !== []): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php foreach ($errors as $error): ?><div><?php echo e($error); ?></div><?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_user_management_token']); ?>">
                            <input type="hidden" name="action" value="edit_user">
                            <input type="hidden" name="target_user_id" value="<?php echo e((string) $editUser['id']); ?>">

                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="editFname">First name</label><input class="form-control" id="editFname" name="fname" value="<?php echo e($editForm['fname']); ?>" maxlength="100" required></div>
                                <div class="col-md-4"><label class="form-label" for="editMname">Middle name</label><input class="form-control" id="editMname" name="mname" value="<?php echo e($editForm['mname']); ?>" maxlength="100"></div>
                                <div class="col-md-4"><label class="form-label" for="editLname">Last name</label><input class="form-control" id="editLname" name="lname" value="<?php echo e($editForm['lname']); ?>" maxlength="100" required></div>
                                <div class="col-md-6"><label class="form-label" for="editEmail">Email address</label><input type="email" class="form-control" id="editEmail" name="email" value="<?php echo e($editForm['email']); ?>" maxlength="150" required></div>
                                <div class="col-md-6"><label class="form-label" for="editContact">Contact number</label><input class="form-control" id="editContact" name="contact" value="<?php echo e($editForm['contact']); ?>" maxlength="20" required></div>
                                <div class="col-md-6"><label class="form-label" for="editUsername">Username</label><input class="form-control" id="editUsername" name="username" value="<?php echo e($editForm['username']); ?>" maxlength="50" required></div>
                                <div class="col-md-6"><label class="form-label" for="editRole">Role</label><select class="form-select" id="editRole" name="role" required><?php foreach ($editableRoles as $value => $label): ?><option value="<?php echo e($value); ?>" <?php echo $editForm['role'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><label class="form-label" for="editAddress">Address</label><textarea class="form-control" id="editAddress" name="address" rows="3" maxlength="255" required><?php echo e($editForm['address']); ?></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-certreefy"><i class="bi bi-check-lg me-1"></i>Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content account-modal">
                <form method="post" action="<?php echo e($formAction); ?>" novalidate>
                    <div class="modal-header account-modal-header">
                        <div class="d-flex align-items-center gap-3">
                            <span class="account-modal-icon" aria-hidden="true"><i class="bi bi-person-plus"></i></span>
                            <div>
                                <div class="eyebrow mb-1">CENRO Superadmin &middot; Account Registry</div>
                                <h2 class="modal-title fs-5" id="addUserModalLabel">Add User Account</h2>
                                <p class="account-modal-subtitle mb-0">Create an active account for Community, RPS, or EMS access.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($errors !== [] && $modalToOpen === 'addUserModal'): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php foreach ($errors as $error): ?><div><?php echo e($error); ?></div><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p class="small text-secondary">Creates an active RPS, EMS, or Community account. Superadmin accounts can't be created here.</p>

                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_user_management_token']); ?>">
                        <input type="hidden" name="action" value="create_user">

                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="account-form-section">
                                    <div class="account-section-heading">
                                        <span aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
                                        <div>
                                            <h3>Identity</h3>
                                            <p>Use the staff or applicant's official account details.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label" for="addFname">First name</label>
                                            <input class="form-control" id="addFname" name="fname" value="<?php echo e($createForm['fname']); ?>" maxlength="100" autocomplete="given-name" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="addMname">Middle name</label>
                                            <input class="form-control" id="addMname" name="mname" value="<?php echo e($createForm['mname']); ?>" maxlength="100" autocomplete="additional-name">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="addLname">Last name</label>
                                            <input class="form-control" id="addLname" name="lname" value="<?php echo e($createForm['lname']); ?>" maxlength="100" autocomplete="family-name" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="addUsername">Username</label>
                                            <div class="input-group">
                                                <span class="input-group-text" aria-hidden="true">@</span>
                                                <input class="form-control" id="addUsername" name="username" value="<?php echo e($createForm['username']); ?>" maxlength="50" autocomplete="username" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="addEmail">Email address</label>
                                            <div class="input-group">
                                                <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                                                <input type="email" class="form-control" id="addEmail" name="email" value="<?php echo e($createForm['email']); ?>" maxlength="150" autocomplete="email" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="account-form-section">
                                    <div class="account-section-heading">
                                        <span aria-hidden="true"><i class="bi bi-telephone"></i></span>
                                        <div>
                                            <h3>Contact Information</h3>
                                            <p>Keep these details reachable for account notices and verification.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-5">
                                            <label class="form-label" for="addContact">Contact number</label>
                                            <input class="form-control" id="addContact" name="contact" value="<?php echo e($createForm['contact']); ?>" maxlength="20" autocomplete="tel" required>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label" for="addAddress">Address</label>
                                            <textarea class="form-control" id="addAddress" name="address" rows="3" maxlength="255" autocomplete="street-address" required><?php echo e($createForm['address']); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="account-form-section mb-lg-0">
                                    <div class="account-section-heading">
                                        <span aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                                        <div>
                                            <h3>Sign-in Security</h3>
                                            <p>Set the temporary password the user will use on first sign-in.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="addPassword">Password</label>
                                            <input type="password" class="form-control" id="addPassword" name="new_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                            <div class="form-text">Use at least 8 characters.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="addConfirmPassword">Confirm password</label>
                                            <input type="password" class="form-control" id="addConfirmPassword" name="confirm_new_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                            <div class="form-text">Must match the password above.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <aside class="account-side-panel" aria-label="New account setup">
                                    <div class="account-side-status">
                                        <span class="account-status status-active">Active on create</span>
                                    </div>
                                    <h3>Account Access</h3>
                                    <p>Choose the operational role for this user. Superadmin accounts are protected and are not created from this form.</p>

                                    <div class="role-choice-group" role="radiogroup" aria-labelledby="addRoleGroupLabel">
                                        <div class="form-label" id="addRoleGroupLabel">Role</div>
                                        <?php
                                        $roleIcons = [
                                            'community' => 'bi-people',
                                            'rps'       => 'bi-clipboard-check',
                                            'ems'       => 'bi-tree',
                                        ];
                                        $roleDescriptions = [
                                            'community' => 'Permit applications, requests, reports, and tracking.',
                                            'rps'       => 'CENRO review, verification, and processing work queues.',
                                            'ems'       => 'Seedling inventory, donations, requests, and claim slips.',
                                        ];
                                        ?>
                                        <?php foreach ($editableRoles as $value => $label): ?>
                                            <label class="role-choice" for="addRole<?php echo e(ucfirst($value)); ?>">
                                                <input class="form-check-input" type="radio" id="addRole<?php echo e(ucfirst($value)); ?>" name="role" value="<?php echo e($value); ?>" <?php echo $createForm['role'] === $value ? 'checked' : ''; ?> required>
                                                <span class="role-choice-icon" aria-hidden="true"><i class="bi <?php echo e($roleIcons[$value] ?? 'bi-person'); ?>"></i></span>
                                                <span>
                                                    <span class="role-choice-title"><?php echo e($label); ?></span>
                                                    <span class="role-choice-desc"><?php echo e($roleDescriptions[$value] ?? 'Authorized CERTREEFY account access.'); ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="account-side-note">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <span>The account is created as Active and can be suspended or deactivated later from the registry actions.</span>
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer account-modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy"><i class="bi bi-person-plus me-1"></i>Create account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?php echo e($formAction); ?>">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="statusModalLabel">Change Account Status</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0" id="statusModalMessage"></p>
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_user_management_token']); ?>">
                        <input type="hidden" name="action" id="statusAction" value="">
                        <input type="hidden" name="target_user_id" id="statusUserId" value="">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy" id="statusSubmitButton">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?php echo e($formAction); ?>">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="resetPasswordModalLabel">Reset Password</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3" id="resetPasswordModalMessage"></p>
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_user_management_token']); ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="target_user_id" id="resetPasswordUserId" value="<?php echo e((string) ($resetPasswordUserId ?? '')); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="confirm_new_password">Confirm new password</label>
                            <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-certreefy">Reset password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            new bootstrap.Tooltip(element);
        });

        document.querySelectorAll('.status-action').forEach(function (button) {
            button.addEventListener('click', function () {
                var actionLabel = button.dataset.actionLabel;
                document.getElementById('statusAction').value = button.dataset.action;
                document.getElementById('statusUserId').value = button.dataset.userId;
                document.getElementById('statusModalLabel').textContent = actionLabel + ' Account';
                document.getElementById('statusModalMessage').textContent = actionLabel + ' the account for ' + button.dataset.userName + '?';
                document.getElementById('statusSubmitButton').textContent = actionLabel;
            });
        });

        document.querySelectorAll('.reset-password-action').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('resetPasswordUserId').value = button.dataset.userId;
                document.getElementById('resetPasswordModalMessage').textContent = 'Set a new password for ' + button.dataset.userName + '. They will need to sign in with this new password.';
            });
        });

        <?php if ($modalToOpen !== null): ?>
        bootstrap.Modal.getOrCreateInstance(document.getElementById(<?php echo json_encode($modalToOpen); ?>)).show();
        <?php endif; ?>
    </script>
</body>
</html>
