<?php
/**
 * Community Tree Cutting Permit form. Draft and submitted records are always
 * loaded through actor-aware services so another applicant's identifier cannot
 * be used to read or change an application.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/permit.php';
require_once __DIR__ . '/../../includes/permit_documents.php';
require_once __DIR__ . '/../../includes/permit_inspections.php';
require_once __DIR__ . '/../../includes/permit_decisions.php';
require_once __DIR__ . '/../../includes/permit_donation_view.php';
require_once __DIR__ . '/../../includes/permit_release.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'community');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$errors = [];
$successMessage = '';
$todayLabel = date('l, F j, Y');

if (empty($_SESSION['csrf_permit_token'])) {
    $_SESSION['csrf_permit_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf_permit_document_token'])) {
    $_SESSION['csrf_permit_document_token'] = bin2hex(random_bytes(32));
}
$documentFlash = null;
if (!empty($_SESSION['permit_document_flash']) && is_array($_SESSION['permit_document_flash'])) {
    $documentFlash = $_SESSION['permit_document_flash'];
    unset($_SESSION['permit_document_flash']);
}
if (!empty($_SESSION['permit_draft_success'])) {
    $successMessage = (string) $_SESSION['permit_draft_success'];
    unset($_SESSION['permit_draft_success']);
}

try {
    $applicant = permit_load_community_applicant($pdo, $userId);
} catch (PDOException $e) {
    error_log('[CERTREEFY PERMIT APPLICANT LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load the permit application at this time. Please try again later.');
}
if ($applicant === null) {
    destroy_authentication_session();
    header('Location: ../auth/login.php');
    exit;
}

$requestedId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? trim((string) ($_POST['application_id'] ?? ''))
    : trim((string) ($_GET['id'] ?? ''));
$applicationId = null;
if ($requestedId !== '') {
    if (!ctype_digit($requestedId) || (int) $requestedId < 1) {
        http_response_code(404);
        die('The permit application was not found.');
    }
    $applicationId = (int) $requestedId;
}

$applicationRecord = null;
if ($applicationId !== null) {
    try {
        $applicationRecord = permit_find_application_for_actor($pdo, $applicationId, $userId);
    } catch (PDOException $e) {
        error_log('[CERTREEFY PERMIT LOAD ERROR] ' . $e->getMessage());
        http_response_code(500);
        die('Unable to load the permit application at this time. Please try again later.');
    }
    if ($applicationRecord === null) {
        http_response_code(404);
        die('The permit application was not found.');
    }
}

$submissionKey = $applicationRecord !== null
    ? (string) $applicationRecord['submission_key']
    : new_permit_submission_key();

if ($applicationRecord !== null) {
    $formData = permit_normalize_application_data($applicationRecord);
    $formData['declaration_confirmed'] = $applicationRecord['declaration_confirmed_at'] !== null;
    $treeData = permit_tree_records_for_actor($pdo, $applicationId, $userId) ?? [];
    $permitDocuments = permit_documents_for_actor($pdo, $applicationId, $userId, true) ?? [];
    $originalReviews = permit_original_reviews_for_actor($pdo, $applicationId, $userId) ?? [];
    $permitInspections = permit_inspections_for_actor($pdo, $applicationId, $userId) ?? [];
    $permitDecisions = $applicationRecord['transaction_id'] !== null
        ? (permit_decision_events_for_actor($pdo, $applicationId, $userId) ?? [])
        : [];
    $donationRequirement = $applicationRecord['transaction_id'] !== null
        ? permit_donation_requirement_for_actor($pdo, $applicationId, $userId)
        : null;
    $permitReleaseRecord = $applicationRecord['transaction_id'] !== null
        ? permit_release_record_for_actor($pdo, $applicationId, $userId)
        : null;
    $permitValiditySnapshot = permit_validity_snapshot($permitReleaseRecord, (string) $applicationRecord['validity_status']);
    $permitCuttingCompletion = $applicationRecord['transaction_id'] !== null
        ? permit_cutting_completion_for_actor($pdo, $applicationId, $userId)
        : null;
    $permitCompletionEvidence = $applicationRecord['transaction_id'] !== null
        ? permit_cutting_completion_evidence_for_actor($pdo, $applicationId, $userId)
        : [];
} else {
    $formData = permit_normalize_application_data([
        'applicant_type' => 'individual',
        'property_relationship' => 'owner',
        'property_owner_name' => permit_applicant_name($applicant),
        'province' => 'Laguna',
    ]);
    $treeData = [];
    $permitDocuments = [];
    $originalReviews = [];
    $permitInspections = [];
    $permitDecisions = [];
    $donationRequirement = null;
    $permitReleaseRecord = null;
    $permitValiditySnapshot = permit_validity_snapshot(null, 'not_issued');
    $permitCuttingCompletion = null;
    $permitCompletionEvidence = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = permit_normalize_application_data($_POST);
    $treeData = permit_normalize_tree_records(is_array($_POST['trees'] ?? null) ? $_POST['trees'] : []);
    $submissionKey = trim((string) ($_POST['submission_key'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_permit_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }
    if (!in_array($action, ['save_draft', 'submit'], true)) {
        $errors[] = 'The requested permit action is invalid.';
    }

    if ($errors === []) {
        try {
            if ($action === 'save_draft') {
                $result = save_permit_draft(
                    $pdo,
                    $userId,
                    $submissionKey,
                    $_POST,
                    is_array($_POST['trees'] ?? null) ? $_POST['trees'] : [],
                    $applicationId
                );
                $_SESSION['csrf_permit_token'] = bin2hex(random_bytes(32));
                $_SESSION['permit_draft_success'] = 'Draft saved successfully. No transaction ID has been generated yet.';
                header('Location: permit-application.php?id=' . (int) $result['application_id']);
                exit;
            }

            $result = submit_permit_application(
                $pdo,
                $userId,
                $submissionKey,
                $_POST,
                is_array($_POST['trees'] ?? null) ? $_POST['trees'] : [],
                $applicationId
            );
            $_SESSION['csrf_permit_token'] = bin2hex(random_bytes(32));
            $_SESSION['permit_success'] = [
                'message' => !empty($result['duplicate'])
                    ? 'This application was already submitted. Its original transaction ID is shown below.'
                    : 'Tree Cutting Permit application submitted successfully.',
                'transaction_id' => (string) $result['transaction_id'],
            ];
            header('Location: permit-applications.php');
            exit;
        } catch (PermitValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        } catch (InvalidArgumentException | RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('[CERTREEFY PERMIT SAVE ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to save the permit application at this time. Please try again later.';
        }
    }
}

if ($treeData === []) {
    $treeData[] = [
        'common_name' => '',
        'scientific_name' => null,
        'quantity' => '',
        'diameter_cm' => null,
        'estimated_height_m' => null,
        'condition_notes' => null,
    ];
}

$isEditable = $applicationRecord === null || (string) $applicationRecord['application_status'] === 'draft';
$applicationStatus = $applicationRecord !== null ? (string) $applicationRecord['application_status'] : 'draft';
$displayName = permit_applicant_name($applicant);
$snapshotName = $applicationRecord !== null ? (string) $applicationRecord['applicant_name'] : $displayName;
$snapshotContact = $applicationRecord !== null ? (string) ($applicationRecord['applicant_contact'] ?? '') : (string) ($applicant['contact'] ?? '');
$snapshotAddress = $applicationRecord !== null ? (string) ($applicationRecord['applicant_address'] ?? '') : (string) ($applicant['address'] ?? '');
$disabled = $isEditable ? '' : ' disabled';
$documentCatalog = permit_document_type_catalog();
$currentDocuments = permit_current_documents_by_type($permitDocuments);
$latestOriginalReviews = permit_latest_original_reviews_by_type($originalReviews);
$originalDocumentProgress = permit_original_required_progress(
    $documentCatalog,
    $currentDocuments,
    $latestOriginalReviews
);
$archivedDocuments = array_values(array_filter(
    $permitDocuments,
    static fn (array $document): bool => (int) $document['is_current'] !== 1
));
$requiredDocumentCount = count(array_filter(
    $documentCatalog,
    static fn (array $definition): bool => !empty($definition['required'])
));
$acceptedRequiredCount = 0;
foreach ($documentCatalog as $type => $definition) {
    if (!empty($definition['required'])
        && isset($currentDocuments[$type])
        && (string) $currentDocuments[$type]['verification_status'] === 'accepted') {
        $acceptedRequiredCount++;
    }
}
$documentProgress = $requiredDocumentCount > 0
    ? (int) round(($acceptedRequiredCount / $requiredDocumentCount) * 100)
    : 0;
$documentUploadLockReason = $applicationRecord !== null
    ? permit_document_upload_lock_reason($applicationRecord)
    : 'Submit the application before uploading scans.';
$communityInspectionTrees = [];
foreach ($permitInspections as $inspection) {
    $communityInspectionTrees[(int) $inspection['id']] = permit_inspection_tree_verifications_for_actor(
        $pdo,
        (int) $inspection['id'],
        $userId
    ) ?? [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | <?php echo $isEditable ? 'Permit Application' : 'Permit Application Details'; ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_certreefy_navigation($currentRole, 'tree_permit'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Tree Cutting Permit &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title"><?php echo $isEditable ? 'Permit Application' : 'Application Details'; ?></h1>
                        <p class="meta-copy mb-0">
                            <?php echo $isEditable
                                ? 'Save incomplete information as a draft, then submit when all required fields are ready.'
                                : 'Submitted applications are read-only while they move through the permit workflow.'; ?>
                        </p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="officer-chip">
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

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <p class="fw-semibold mb-2">Please fix the following:</p>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e((string) $error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="permit-application.php" novalidate id="permit-form">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_token']); ?>">
                <input type="hidden" name="submission_key" value="<?php echo e($submissionKey); ?>">
                <input type="hidden" name="application_id" value="<?php echo $applicationId !== null ? e((string) $applicationId) : ''; ?>">

                <div class="row g-3">
                    <div class="col-xl-9">
                        <section class="docket-panel mb-3" aria-labelledby="applicant-heading">
                            <div class="section-heading">
                                <h2 id="applicant-heading">Applicant and Contact Information</h2>
                                <span class="section-note">From your active Community profile</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Applicant name</label>
                                    <input type="text" class="form-control" value="<?php echo e($snapshotName); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email address</label>
                                    <input type="email" class="form-control" value="<?php echo e((string) $applicant['email']); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact number</label>
                                    <input type="text" class="form-control" value="<?php echo e($snapshotContact); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Applicant address</label>
                                    <input type="text" class="form-control" value="<?php echo e($snapshotAddress); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label for="applicant_type" class="form-label">Applicant type <span class="text-danger">*</span></label>
                                    <select class="form-select" id="applicant_type" name="applicant_type" required<?php echo $disabled; ?>>
                                        <option value="">Select applicant type</option>
                                        <option value="individual"<?php echo ($formData['applicant_type'] ?? null) === 'individual' ? ' selected' : ''; ?>>Individual</option>
                                        <option value="organization"<?php echo ($formData['applicant_type'] ?? null) === 'organization' ? ' selected' : ''; ?>>Organization</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="organization-field">
                                    <label for="organization_name" class="form-label">Organization name</label>
                                    <input type="text" class="form-control" id="organization_name" name="organization_name" value="<?php echo e((string) ($formData['organization_name'] ?? '')); ?>" maxlength="255"<?php echo $disabled; ?>>
                                </div>
                                <?php if ($isEditable): ?>
                                    <div class="col-12">
                                        <small class="text-secondary">Update your account contact details from <a href="profile.php">Profile Management</a> before final submission.</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="docket-panel mb-3" aria-labelledby="property-heading">
                            <div class="section-heading">
                                <h2 id="property-heading">Property Ownership and Location</h2>
                                <span class="section-note">Fields marked * are required for submission</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="property_relationship" class="form-label">Relationship to property <span class="text-danger">*</span></label>
                                    <select class="form-select" id="property_relationship" name="property_relationship" required<?php echo $disabled; ?>>
                                        <option value="">Select relationship</option>
                                        <option value="owner"<?php echo ($formData['property_relationship'] ?? null) === 'owner' ? ' selected' : ''; ?>>Property owner</option>
                                        <option value="authorized_representative"<?php echo ($formData['property_relationship'] ?? null) === 'authorized_representative' ? ' selected' : ''; ?>>Authorized representative</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="property_owner_name" class="form-label">Property owner name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="property_owner_name" name="property_owner_name" value="<?php echo e((string) ($formData['property_owner_name'] ?? '')); ?>" maxlength="255" required<?php echo $disabled; ?>>
                                </div>
                                <div class="col-12" id="authorization-field">
                                    <label for="authorization_details" class="form-label">Authorization details</label>
                                    <textarea class="form-control" id="authorization_details" name="authorization_details" rows="2" maxlength="1000"<?php echo $disabled; ?>><?php echo e((string) ($formData['authorization_details'] ?? '')); ?></textarea>
                                    <div class="form-text">Describe the owner's authorization. Documentary requirements remain subject to CENRO verification.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="property_classification" class="form-label">Property classification <span class="text-danger">*</span></label>
                                    <select class="form-select" id="property_classification" name="property_classification" required<?php echo $disabled; ?>>
                                        <option value="">Select classification</option>
                                        <option value="public_domain"<?php echo ($formData['property_classification'] ?? null) === 'public_domain' ? ' selected' : ''; ?>>Public domain</option>
                                        <option value="private_property"<?php echo ($formData['property_classification'] ?? null) === 'private_property' ? ' selected' : ''; ?>>Private property</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="lot_number" class="form-label">Lot or property reference</label>
                                    <input type="text" class="form-control" id="lot_number" name="lot_number" value="<?php echo e((string) ($formData['lot_number'] ?? '')); ?>" maxlength="100"<?php echo $disabled; ?>>
                                </div>
                                <div class="col-md-3">
                                    <label for="district" class="form-label">District <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="district" name="district" value="<?php echo e((string) ($formData['district'] ?? '')); ?>" maxlength="100" required<?php echo $disabled; ?>>
                                    <div class="form-text">District coverage is pending business verification.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="province" class="form-label">Province <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="province" name="province" value="<?php echo e((string) ($formData['province'] ?? '')); ?>" maxlength="100" required<?php echo $disabled; ?>>
                                </div>
                                <div class="col-md-3">
                                    <label for="municipality" class="form-label">Municipality or city <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="municipality" name="municipality" value="<?php echo e((string) ($formData['municipality'] ?? '')); ?>" maxlength="100" required<?php echo $disabled; ?>>
                                </div>
                                <div class="col-md-3">
                                    <label for="barangay" class="form-label">Barangay <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="barangay" name="barangay" value="<?php echo e((string) ($formData['barangay'] ?? '')); ?>" maxlength="100" required<?php echo $disabled; ?>>
                                </div>
                                <div class="col-12">
                                    <label for="property_address" class="form-label">Detailed property location <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="property_address" name="property_address" rows="3" maxlength="500" required<?php echo $disabled; ?>><?php echo e((string) ($formData['property_address'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="latitude" class="form-label">Latitude</label>
                                    <input type="number" class="form-control" id="latitude" name="latitude" value="<?php echo e((string) ($formData['latitude'] ?? '')); ?>" min="-90" max="90" step="0.0000001"<?php echo $disabled; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="longitude" class="form-label">Longitude</label>
                                    <input type="number" class="form-control" id="longitude" name="longitude" value="<?php echo e((string) ($formData['longitude'] ?? '')); ?>" min="-180" max="180" step="0.0000001"<?php echo $disabled; ?>>
                                </div>
                            </div>
                        </section>

                        <section class="docket-panel mb-3" aria-labelledby="purpose-heading">
                            <div class="section-heading">
                                <h2 id="purpose-heading">Purpose and Tree Information</h2>
                                <span class="section-note">Add one related record per tree type or measurement set</span>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <label for="cutting_purpose" class="form-label">Purpose of cutting <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="cutting_purpose" name="cutting_purpose" rows="3" maxlength="500" required<?php echo $disabled; ?>><?php echo e((string) ($formData['cutting_purpose'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label for="application_notes" class="form-label">Additional application notes</label>
                                    <textarea class="form-control" id="application_notes" name="application_notes" rows="3" maxlength="5000"<?php echo $disabled; ?>><?php echo e((string) ($formData['application_notes'] ?? '')); ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                                <h3 class="h5 mb-0">Tree records</h3>
                                <?php if ($isEditable): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="add-tree">
                                        <i class="bi bi-plus-circle"></i> Add tree entry
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="tree-records" class="d-grid gap-3">
                                <?php foreach ($treeData as $index => $tree): ?>
                                    <div class="card border-0 bg-light tree-record" data-tree-row>
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h4 class="h6 mb-0 tree-title">Tree entry <?php echo $index + 1; ?></h4>
                                                <?php if ($isEditable): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-tree" aria-label="Remove tree entry <?php echo $index + 1; ?>">
                                                        <i class="bi bi-trash"></i> Remove
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label" data-tree-label="common_name" for="tree_<?php echo $index; ?>_common_name">Common name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" data-tree-input="common_name" id="tree_<?php echo $index; ?>_common_name" name="trees[<?php echo $index; ?>][common_name]" value="<?php echo e((string) ($tree['common_name'] ?? '')); ?>" maxlength="150" required<?php echo $disabled; ?>>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" data-tree-label="scientific_name" for="tree_<?php echo $index; ?>_scientific_name">Scientific name</label>
                                                    <input type="text" class="form-control" data-tree-input="scientific_name" id="tree_<?php echo $index; ?>_scientific_name" name="trees[<?php echo $index; ?>][scientific_name]" value="<?php echo e((string) ($tree['scientific_name'] ?? '')); ?>" maxlength="150"<?php echo $disabled; ?>>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" data-tree-label="quantity" for="tree_<?php echo $index; ?>_quantity">Number of trees <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control" data-tree-input="quantity" id="tree_<?php echo $index; ?>_quantity" name="trees[<?php echo $index; ?>][quantity]" value="<?php echo e((string) ($tree['quantity'] ?? '')); ?>" min="1" max="65535" step="1" required<?php echo $disabled; ?>>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" data-tree-label="diameter_cm" for="tree_<?php echo $index; ?>_diameter_cm">Diameter (cm)</label>
                                                    <input type="number" class="form-control" data-tree-input="diameter_cm" id="tree_<?php echo $index; ?>_diameter_cm" name="trees[<?php echo $index; ?>][diameter_cm]" value="<?php echo e((string) ($tree['diameter_cm'] ?? '')); ?>" min="0.01" max="999999.99" step="0.01"<?php echo $disabled; ?>>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" data-tree-label="estimated_height_m" for="tree_<?php echo $index; ?>_estimated_height_m">Estimated height (m)</label>
                                                    <input type="number" class="form-control" data-tree-input="estimated_height_m" id="tree_<?php echo $index; ?>_estimated_height_m" name="trees[<?php echo $index; ?>][estimated_height_m]" value="<?php echo e((string) ($tree['estimated_height_m'] ?? '')); ?>" min="0.01" max="999999.99" step="0.01"<?php echo $disabled; ?>>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label" data-tree-label="condition_notes" for="tree_<?php echo $index; ?>_condition_notes">Condition or measurement notes</label>
                                                    <textarea class="form-control" data-tree-input="condition_notes" id="tree_<?php echo $index; ?>_condition_notes" name="trees[<?php echo $index; ?>][condition_notes]" rows="2" maxlength="500"<?php echo $disabled; ?>><?php echo e((string) ($tree['condition_notes'] ?? '')); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-text mt-3 mb-0">Measurements are optional until CENRO confirms they are required for the applicable tree or property classification.</p>
                        </section>

                        <section class="docket-panel" aria-labelledby="declaration-heading">
                            <div class="section-heading">
                                <h2 id="declaration-heading">Applicant Declaration</h2>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="declaration_confirmed" name="declaration_confirmed"<?php echo !empty($formData['declaration_confirmed']) ? ' checked' : ''; ?><?php echo $disabled; ?>>
                                <label class="form-check-label" for="declaration_confirmed">
                                    I confirm that the information supplied in this application is complete and accurate to the best of my knowledge.
                                </label>
                            </div>
                            <?php if ($isEditable): ?>
                                <div class="d-flex flex-wrap gap-2 mt-4">
                                    <button type="submit" class="btn btn-outline-secondary" name="action" value="save_draft" formnovalidate>
                                        <i class="bi bi-save"></i> Save draft
                                    </button>
                                    <button type="submit" class="btn btn-certreefy" name="action" value="submit">
                                        <i class="bi bi-send-check"></i> Submit application
                                    </button>
                                    <a href="permit-applications.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to applications
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light border mt-4 mb-0" role="status">
                                    <i class="bi bi-lock"></i> This application is locked after submission. No correction-editing stage is enabled in the verified workflow.
                                </div>
                                <a href="permit-applications.php" class="btn btn-outline-secondary mt-3">
                                    <i class="bi bi-arrow-left"></i> Back to applications
                                </a>
                            <?php endif; ?>
                        </section>
                    </div>

                    <div class="col-xl-3">
                        <aside class="snapshot-panel">
                            <div class="section-heading">
                                <h2>Application Snapshot</h2>
                            </div>
                            <div class="snapshot-row">
                                <span class="text-secondary"><span class="status-dot"></span>Status</span>
                                <span class="badge <?php echo $applicationStatus === 'draft' ? 'text-bg-secondary' : 'text-bg-warning'; ?>"><?php echo e(permit_status_label($applicationStatus)); ?></span>
                            </div>
                            <div class="snapshot-row">
                                <span class="text-secondary"><span class="status-dot"></span>Transaction ID</span>
                                <span class="fw-semibold text-break"><?php echo $applicationRecord !== null && $applicationRecord['transaction_id'] !== null ? e((string) $applicationRecord['transaction_id']) : 'Generated on submission'; ?></span>
                            </div>
                            <?php if ($applicationRecord !== null && $applicationRecord['submitted_at'] !== null): ?>
                                <div class="snapshot-row">
                                    <span class="text-secondary"><span class="status-dot"></span>Submitted</span>
                                    <span class="fw-semibold"><?php echo e(date('M j, Y', strtotime((string) $applicationRecord['submitted_at']))); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3 small text-secondary">
                                Drafts remain editable only by their owner. Final submission locks the application and records its transaction, status history, notification, and audit event.
                            </div>
                        </aside>
                    </div>
                </div>
            </form>

            <?php if ($applicationRecord !== null && (string) $applicationRecord['application_status'] !== 'draft'): ?>
                <section class="docket-panel mt-3" id="inspection" aria-labelledby="inspection-heading">
                    <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div><h2 id="inspection-heading">Site Inspection</h2><span class="section-note">Schedule and result information</span></div>
                        <span class="badge <?php echo e(permit_inspection_status_badge((string) $applicationRecord['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $applicationRecord['inspection_status'])); ?></span>
                    </div>
                    <div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>You can view inspection updates for this application. Only authorized RPS personnel may schedule an inspection or record findings. A passed inspection does not itself approve the permit.</div>
                    <?php if ($permitInspections === []): ?>
                        <p class="text-secondary mb-0">RPS has not yet recorded an inspection assessment.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Status / schedule</th><th>Assigned personnel &amp; location</th><th>Result</th><th>Updated</th></tr></thead><tbody>
                            <?php foreach ($permitInspections as $inspection): ?>
                                <?php $verifiedTrees = $communityInspectionTrees[(int) $inspection['id']] ?? []; ?>
                                <tr>
                                    <td><span class="badge <?php echo e(permit_inspection_status_badge((string) $inspection['inspection_status'])); ?>"><?php echo e(permit_inspection_status_label((string) $inspection['inspection_status'])); ?></span><?php if ($inspection['scheduled_at'] !== null): ?><div class="small mt-2"><strong>Schedule:</strong><br><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['scheduled_at']))); ?></div><?php endif; ?><?php if ($inspection['inspected_at'] !== null): ?><div class="small"><strong>Inspection:</strong><br><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['inspected_at']))); ?></div><?php endif; ?></td>
                                    <td class="small"><div><strong>Assigned:</strong> <?php echo e((string) ($inspection['inspector_name'] ?? 'Not yet assigned')); ?></div><?php if ($inspection['inspection_location'] !== null): ?><div class="text-break"><strong>Location:</strong> <?php echo e((string) $inspection['inspection_location']); ?></div><?php endif; ?></td>
                                    <td class="small text-break"><?php if ($inspection['findings'] !== null): ?><div><strong>Findings:</strong> <?php echo e((string) $inspection['findings']); ?></div><div><strong>Recommendation:</strong> <?php echo e((string) $inspection['recommendation']); ?></div><?php if ($verifiedTrees !== []): ?><div class="mt-1"><?php echo count($verifiedTrees); ?> tree record<?php echo count($verifiedTrees) === 1 ? '' : 's'; ?> verified.</div><?php endif; ?><?php else: ?>No findings recorded yet.<?php endif; ?><?php if ($inspection['inspection_notes'] !== null): ?><div class="mt-1"><strong>Remarks:</strong> <?php echo e((string) $inspection['inspection_notes']); ?></div><?php endif; ?></td>
                                    <td class="small"><div><?php echo e(date('M j, Y g:i A', strtotime((string) $inspection['created_at']))); ?></div><?php if ($inspection['completer_name'] !== null): ?><div class="text-secondary">Completed by <?php echo e((string) $inspection['completer_name']); ?></div><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($applicationRecord !== null && (string) $applicationRecord['application_status'] !== 'draft'): ?>
                <section class="docket-panel mt-3" id="decision" aria-labelledby="decision-heading">
                    <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div><h2 id="decision-heading">RPS Review &amp; Decision</h2><span class="section-note">Read-only review updates for your application</span></div>
                        <span class="badge <?php echo e(permit_decision_event_badge((string) $applicationRecord['decision_status'])); ?>"><?php echo e(permit_status_label((string) $applicationRecord['decision_status'])); ?></span>
                    </div>
                    <div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>An approval shown here is not the final permit release. Approved applications continue through the required donation and release workflow.</div>
                    <?php if ($donationRequirement !== null): ?><div class="mb-3"><?php render_permit_donation_requirement($donationRequirement); ?></div><?php endif; ?>
                    <?php if ($permitDecisions === []): ?>
                        <p class="text-secondary mb-0">No RPS review action has been recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Review update</th><th>Remarks</th><th>Decision details</th><th>Date</th></tr></thead><tbody>
                            <?php foreach ($permitDecisions as $decisionEvent): ?><tr><td><span class="badge <?php echo e(permit_decision_event_badge((string) $decisionEvent['decision'])); ?>"><?php echo e(permit_decision_event_label((string) $decisionEvent['decision'])); ?></span></td><td class="text-break"><?php echo e((string) ($decisionEvent['decision_notes'] ?? '-')); ?><?php if (!empty($decisionEvent['decision_conditions'])): ?><div class="small mt-1"><strong>Conditions:</strong> <?php echo e((string) $decisionEvent['decision_conditions']); ?></div><?php endif; ?></td><td class="small"><?php if ($decisionEvent['approved_tree_count'] !== null): ?><div>Approved trees: <strong><?php echo (int) $decisionEvent['approved_tree_count']; ?></strong></div><div>Property: <?php echo e(permit_status_label((string) $decisionEvent['property_classification'])); ?></div><div>Donation: <?php echo (int) $decisionEvent['donation_seedling_count']; ?> seedling(s)</div><?php else: ?>-<?php endif; ?></td><td><?php echo e(date('M j, Y g:i A', strtotime((string) $decisionEvent['decided_at']))); ?></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($applicationRecord !== null && (string) $applicationRecord['application_status'] !== 'draft'
                && (in_array((string) $applicationRecord['application_status'], ['awaiting_final_verification', 'ready_for_release', 'released', 'completed'], true)
                    || $permitReleaseRecord !== null || (string) $applicationRecord['validity_status'] !== 'not_issued')): ?>
                <section class="docket-panel mt-3" id="permit-release" aria-labelledby="permit-release-heading">
                    <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div><h2 id="permit-release-heading">Permit Release &amp; Validity</h2><span class="section-note">Read-only release and validity status for your permit</span></div>
                        <span class="badge <?php echo $permitValiditySnapshot['is_expired'] || (string) $applicationRecord['validity_status'] === 'expired' ? 'text-bg-danger' : ((string) $applicationRecord['validity_status'] === 'active' ? 'text-bg-success' : 'text-bg-light border'); ?>"><?php echo e(permit_status_label((string) $applicationRecord['validity_status'])); ?></span>
                    </div>
                    <?php if ($permitReleaseRecord !== null): ?>
                        <div class="row g-3 mb-2">
                            <div class="col-md-3"><div class="small text-secondary">Permit number</div><div class="fw-semibold"><?php echo e((string) $permitReleaseRecord['permit_number']); ?></div></div>
                            <div class="col-md-3"><div class="small text-secondary">Approved duration</div><div class="fw-semibold"><?php echo (int) $permitReleaseRecord['approved_duration_days']; ?> days</div></div>
                            <div class="col-md-3"><div class="small text-secondary">Valid from</div><div class="fw-semibold"><?php echo $permitReleaseRecord['valid_from'] !== null ? e(date('M j, Y', strtotime((string) $permitReleaseRecord['valid_from']))) : '-'; ?></div></div>
                            <div class="col-md-3"><div class="small text-secondary">Valid until</div><div class="fw-semibold"><?php echo $permitReleaseRecord['valid_until'] !== null ? e(date('M j, Y', strtotime((string) $permitReleaseRecord['valid_until']))) : '-'; ?></div></div>
                        </div>
                        <?php if ((string) $applicationRecord['validity_status'] === 'active'): ?>
                            <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                                <i class="bi bi-building-check fs-5 mt-1"></i>
                                <div>
                                    <div class="fw-semibold">Your official permit is ready for pickup.</div>
                                    <div class="small">Claim your signed Tree Cutting Permit in person at <?php echo e(permit_claim_location()); ?> during office hours. Bring a valid ID and your transaction ID (<?php echo e((string) $applicationRecord['transaction_id']); ?>). The official permit is a physical signed document and is not issued digitally.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($permitReleaseRecord['permit_file_path'])): ?>
                            <div class="border rounded p-3 mb-2">
                                <h3 class="h6 mb-2"><i class="bi bi-file-earmark-pdf me-1"></i>Signed permit copy <span class="text-secondary fw-normal">(reference only)</span></h3>
                                <p class="small text-secondary mb-2">A scanned copy for your reference. The official permit remains the physical signed document claimed at the office.</p>
                                <div class="row g-3 mb-2">
                                    <div class="col-md-4"><div class="small text-secondary">Signed on</div><div class="fw-semibold"><?php echo $permitReleaseRecord['signed_on'] !== null ? e(date('M j, Y', strtotime((string) $permitReleaseRecord['signed_on']))) : '-'; ?></div></div>
                                    <div class="col-md-4"><div class="small text-secondary">Signing personnel</div><div class="fw-semibold"><?php echo $permitReleaseRecord['signed_by_name'] !== null ? e((string) $permitReleaseRecord['signed_by_name']) : '-'; ?></div></div>
                                    <div class="col-md-4"><div class="small text-secondary">Released to</div><div class="fw-semibold"><?php echo $permitReleaseRecord['released_to_recipient'] !== null ? e((string) $permitReleaseRecord['released_to_recipient']) : '-'; ?></div></div>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" href="permit-signed-download.php?id=<?php echo (int) $applicationId; ?>"><i class="bi bi-download"></i> Download reference copy</a>
                            </div>
                        <?php endif; ?>
                        <?php if ((string) $applicationRecord['validity_status'] === 'active' && !empty($permitValiditySnapshot['is_expiring_soon'])): ?>
                            <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>Your permit expires in <?php echo (int) $permitValiditySnapshot['days_remaining']; ?> day(s). No extension is possible; complete cutting before it lapses.</div>
                        <?php elseif ((string) $applicationRecord['validity_status'] === 'expired'): ?>
                            <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>Your permit has expired and cannot be extended or reactivated. If cutting was not completed, a new application and transaction ID are required.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0"><i class="bi bi-info-circle me-1"></i>Your application has cleared donation verification and is awaiting permit preparation and release by RPS. No extension applies once released.</div>
                    <?php endif; ?>
                    <?php if ($permitCuttingCompletion !== null): ?>
                        <div class="border rounded p-3 mt-2">
                            <h3 class="h6 mb-2"><i class="bi bi-check2-all me-1"></i>Cutting completion recorded</h3>
                            <div class="row g-3">
                                <div class="col-md-4"><div class="small text-secondary">Status</div><div class="fw-semibold"><?php echo e(permit_status_label((string) $permitCuttingCompletion['completion_status'])); ?></div></div>
                                <div class="col-md-4"><div class="small text-secondary">Trees cut</div><div class="fw-semibold"><?php echo (int) $permitCuttingCompletion['trees_cut_count']; ?></div></div>
                                <div class="col-md-4"><div class="small text-secondary">Completed on</div><div class="fw-semibold"><?php echo e(date('M j, Y', strtotime((string) $permitCuttingCompletion['completed_on']))); ?></div></div>
                                <?php if (!empty($permitCuttingCompletion['remarks'])): ?><div class="col-12"><div class="small text-secondary">Remarks</div><div class="text-break"><?php echo e((string) $permitCuttingCompletion['remarks']); ?></div></div><?php endif; ?>
                            </div>
                            <?php if ($permitCompletionEvidence !== []): ?>
                                <div class="mt-3">
                                    <div class="small text-secondary mb-1">Evidence (<?php echo count($permitCompletionEvidence); ?>)</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($permitCompletionEvidence as $evidence): ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="permit-completion-evidence-download.php?id=<?php echo (int) $evidence['id']; ?>"><i class="bi bi-image"></i> <?php echo e((string) $evidence['original_filename']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($applicationRecord !== null): ?>
                <section class="docket-panel mt-3" id="documents" aria-labelledby="documents-heading">
                    <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div>
                            <h2 id="documents-heading">Scanned Documents</h2>
                            <span class="section-note">Transaction <?php echo e((string) ($applicationRecord['transaction_id'] ?? 'not yet generated')); ?></span>
                        </div>
                        <span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $applicationRecord['document_status'])); ?></span>
                    </div>

                    <div class="alert alert-warning" role="note">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Uploaded scans support online screening only. They do not replace required original hardcopy documents, wet-ink signatures, or in-person verification.
                    </div>

                    <?php if (is_array($documentFlash)): ?>
                        <div class="alert alert-<?php echo ($documentFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert">
                            <?php echo e((string) ($documentFlash['message'] ?? 'The document action could not be completed.')); ?>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required digital scans accepted</span><span class="fw-semibold"><?php echo $acceptedRequiredCount; ?> of <?php echo $requiredDocumentCount; ?></span></div><div class="progress" role="progressbar" aria-label="Required online scans" aria-valuenow="<?php echo $documentProgress; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo $documentProgress; ?>%"><?php echo $documentProgress; ?>%</div></div></div></div>
                        <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required originals verified</span><span class="fw-semibold"><?php echo (int) $originalDocumentProgress['verified']; ?> of <?php echo (int) $originalDocumentProgress['required']; ?></span></div><div class="progress" role="progressbar" aria-label="Required original documents" aria-valuenow="<?php echo (int) $originalDocumentProgress['percent']; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo (int) $originalDocumentProgress['percent']; ?>%"><?php echo (int) $originalDocumentProgress['percent']; ?>%</div></div></div></div>
                        <div class="col-12"><div class="form-text">This checklist is provisional until CENRO/RPS confirms the official document requirements.</div></div>
                    </div>

                    <?php if ($documentUploadLockReason !== null): ?>
                        <div class="alert alert-light border" role="status">
                            <i class="bi bi-lock me-1"></i><?php echo e($documentUploadLockReason); ?> Existing authorized downloads remain available.
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <?php foreach ($documentCatalog as $documentType => $definition): ?>
                            <?php
                            $currentDocument = $currentDocuments[$documentType] ?? null;
                            $latestOriginalReview = $latestOriginalReviews[$documentType] ?? null;
                            $originalReplacementRequested = permit_original_scan_replacement_requested(
                                $latestOriginalReview,
                                $currentDocument
                            );
                            $canUploadDocument = $documentUploadLockReason === null
                                && ($currentDocument === null
                                    || (string) $currentDocument['verification_status'] !== 'accepted'
                                    || $originalReplacementRequested);
                            ?>
                            <div class="col-lg-6">
                                <article class="card h-100 border-0 bg-light">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <h3 class="h6 mb-0"><?php echo e((string) $definition['label']); ?></h3>
                                            <?php if (!empty($definition['required'])): ?>
                                                <span class="badge text-bg-light border">Required</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-light border">Optional</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="small text-secondary"><?php echo e((string) $definition['description']); ?></p>

                                        <?php if ($currentDocument !== null): ?>
                                            <div class="border rounded bg-white p-3 mb-3">
                                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="fw-semibold text-break"><?php echo e((string) $currentDocument['original_filename']); ?></div>
                                                        <small class="text-secondary">
                                                            <?php echo e(number_format((int) $currentDocument['file_size_bytes'] / 1024, 1)); ?> KB
                                                            &middot; <?php echo e(date('M j, Y g:i A', strtotime((string) $currentDocument['created_at']))); ?>
                                                        </small>
                                                    </div>
                                                    <span class="badge align-self-start <?php echo e(permit_document_status_badge((string) $currentDocument['verification_status'])); ?>">
                                                        <?php echo e(permit_document_status_label((string) $currentDocument['verification_status'])); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($currentDocument['verification_notes'])): ?>
                                                    <div class="small mt-2"><strong>RPS note:</strong> <?php echo e((string) $currentDocument['verification_notes']); ?></div>
                                                <?php endif; ?>
                                                <a class="btn btn-sm btn-outline-secondary mt-2" href="permit-document-download.php?id=<?php echo e((string) $currentDocument['id']); ?>">
                                                    <i class="bi bi-download"></i> Download
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-secondary mb-3"><i class="bi bi-file-earmark"></i> No scan uploaded.</div>
                                        <?php endif; ?>

                                        <div class="border rounded bg-white p-3 mb-3">
                                            <div class="small text-secondary mb-1">Original hardcopy verification</div>
                                            <?php if ($latestOriginalReview === null): ?>
                                                <div class="small">No original verification has been recorded.</div>
                                            <?php else: ?>
                                                <span class="badge <?php echo e(permit_original_review_status_badge((string) $latestOriginalReview['review_status'])); ?>"><?php echo e(permit_original_review_status_label((string) $latestOriginalReview['review_status'])); ?></span>
                                                <?php if (!permit_original_review_matches_document($latestOriginalReview, $currentDocument) && $currentDocument !== null): ?><div class="small text-warning-emphasis mt-1"><i class="bi bi-arrow-repeat"></i> This decision is not linked to the current scan; re-verification is required.</div><?php endif; ?>
                                                <div class="small mt-2">Original received: <strong><?php echo (int) $latestOriginalReview['original_received'] === 1 ? 'Yes' : 'No'; ?></strong><?php if ((int) $latestOriginalReview['original_received'] === 1): ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $latestOriginalReview['original_received_on']))); ?><?php endif; ?></div>
                                                <div class="small">Wet-ink signature: <?php echo (int) $latestOriginalReview['wet_ink_required'] === 1 ? ((int) $latestOriginalReview['wet_ink_verified'] === 1 ? 'Verified' : 'Not verified') : 'Not required'; ?></div>
                                                <div class="small">Scan compared: <?php echo (int) $latestOriginalReview['scan_compared_with_original'] === 1 ? 'Yes' : 'No'; ?></div>
                                                <div class="small text-secondary">Recorded by <?php echo e((string) $latestOriginalReview['verifier_name']); ?> on <?php echo e(date('M j, Y g:i A', strtotime((string) $latestOriginalReview['reviewed_at']))); ?></div>
                                                <?php if (!empty($latestOriginalReview['review_notes'])): ?><div class="small mt-1 text-break"><strong>RPS remarks:</strong> <?php echo e((string) $latestOriginalReview['review_notes']); ?></div><?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($canUploadDocument): ?>
                                            <form method="post" action="permit-document-upload.php" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_document_token']); ?>">
                                                <input type="hidden" name="application_id" value="<?php echo e((string) $applicationId); ?>">
                                                <input type="hidden" name="document_type" value="<?php echo e($documentType); ?>">
                                                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo permit_document_max_bytes(); ?>">
                                                <label class="form-label" for="document_<?php echo e($documentType); ?>">
                                                    <?php echo $currentDocument === null ? 'Select scan' : 'Select replacement scan'; ?>
                                                </label>
                                                <input class="form-control" type="file" id="document_<?php echo e($documentType); ?>" name="document_file" accept="<?php echo e(permit_document_accept_attribute()); ?>" required>
                                                <div class="form-text">PDF, JPG, JPEG, or PNG; maximum <?php echo e(permit_document_max_size_label()); ?>.</div>
                                                <button class="btn btn-sm btn-certreefy mt-2" type="submit">
                                                    <i class="bi bi-cloud-arrow-up"></i>
                                                    <?php echo $currentDocument === null ? 'Upload scan' : 'Upload replacement'; ?>
                                                </button>
                                            </form>
                                        <?php elseif ($currentDocument !== null && (string) $currentDocument['verification_status'] === 'accepted'): ?>
                                            <div class="small text-success"><i class="bi bi-check-circle"></i> Accepted online scans are locked against Community replacement.</div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($archivedDocuments !== []): ?>
                        <div class="mt-4">
                            <h3 class="h5">Replacement History</h3>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead><tr><th>Document type</th><th>Filename</th><th>Status before archive</th><th>Uploaded</th><th class="text-end">File</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($archivedDocuments as $document): ?>
                                            <?php $definition = permit_document_type((string) $document['document_type']); ?>
                                            <tr>
                                                <td><?php echo e((string) ($definition['label'] ?? $document['document_type'])); ?></td>
                                                <td class="text-break"><?php echo e((string) $document['original_filename']); ?></td>
                                                <td><span class="badge <?php echo e(permit_document_status_badge((string) $document['verification_status'], false)); ?>"><?php echo e(permit_document_status_label((string) $document['verification_status'], false)); ?></span></td>
                                                <td><?php echo e(date('M j, Y g:i A', strtotime((string) $document['created_at']))); ?></td>
                                                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="permit-document-download.php?id=<?php echo e((string) $document['id']); ?>"><i class="bi bi-download"></i> Download</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($isEditable): ?>
        <template id="tree-template">
            <div class="card border-0 bg-light tree-record" data-tree-row>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="h6 mb-0 tree-title">Tree entry</h4>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-tree" aria-label="Remove tree entry">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" data-tree-label="common_name">Common name <span class="text-danger">*</span></label><input type="text" class="form-control" data-tree-input="common_name" maxlength="150" required></div>
                        <div class="col-md-6"><label class="form-label" data-tree-label="scientific_name">Scientific name</label><input type="text" class="form-control" data-tree-input="scientific_name" maxlength="150"></div>
                        <div class="col-md-4"><label class="form-label" data-tree-label="quantity">Number of trees <span class="text-danger">*</span></label><input type="number" class="form-control" data-tree-input="quantity" min="1" max="65535" step="1" required></div>
                        <div class="col-md-4"><label class="form-label" data-tree-label="diameter_cm">Diameter (cm)</label><input type="number" class="form-control" data-tree-input="diameter_cm" min="0.01" max="999999.99" step="0.01"></div>
                        <div class="col-md-4"><label class="form-label" data-tree-label="estimated_height_m">Estimated height (m)</label><input type="number" class="form-control" data-tree-input="estimated_height_m" min="0.01" max="999999.99" step="0.01"></div>
                        <div class="col-12"><label class="form-label" data-tree-label="condition_notes">Condition or measurement notes</label><textarea class="form-control" data-tree-input="condition_notes" rows="2" maxlength="500"></textarea></div>
                    </div>
                </div>
            </div>
        </template>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($isEditable): ?>
        <script>
            (() => {
                const records = document.getElementById('tree-records');
                const template = document.getElementById('tree-template');
                const addButton = document.getElementById('add-tree');
                const applicantType = document.getElementById('applicant_type');
                const organizationField = document.getElementById('organization-field');
                const relationship = document.getElementById('property_relationship');
                const authorizationField = document.getElementById('authorization-field');

                const renumber = () => {
                    records.querySelectorAll('[data-tree-row]').forEach((row, index) => {
                        row.querySelector('.tree-title').textContent = `Tree entry ${index + 1}`;
                        const removeButton = row.querySelector('.remove-tree');
                        removeButton.setAttribute('aria-label', `Remove tree entry ${index + 1}`);
                        row.querySelectorAll('[data-tree-input]').forEach((input) => {
                            const field = input.dataset.treeInput;
                            const id = `tree_${index}_${field}`;
                            input.name = `trees[${index}][${field}]`;
                            input.id = id;
                            row.querySelector(`[data-tree-label="${field}"]`).htmlFor = id;
                        });
                    });
                };

                records.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('.remove-tree');
                    if (!removeButton) return;
                    removeButton.closest('[data-tree-row]').remove();
                    renumber();
                });
                addButton.addEventListener('click', () => {
                    records.appendChild(template.content.cloneNode(true));
                    renumber();
                });

                const toggleConditionalFields = () => {
                    organizationField.classList.toggle('d-none', applicantType.value !== 'organization');
                    authorizationField.classList.toggle('d-none', relationship.value !== 'authorized_representative');
                };
                applicantType.addEventListener('change', toggleConditionalFields);
                relationship.addEventListener('change', toggleConditionalFields);
                renumber();
                toggleConditionalFields();
            })();
        </script>
    <?php endif; ?>
</body>
</html>
