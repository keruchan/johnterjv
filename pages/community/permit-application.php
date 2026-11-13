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
require_once __DIR__ . '/../../includes/permit_guidance.php';
require_once __DIR__ . '/../../includes/permit_completion.php';
require_once __DIR__ . '/../../includes/permit_tarpaulin.php';
require_once __DIR__ . '/../../includes/locations.php';
require_once __DIR__ . '/../../includes/seedling_locations.php';

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

$applicationStep = (int) filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['application_step'] ?? 0) : ($_GET['step'] ?? 0),
    FILTER_VALIDATE_INT,
    ['options' => ['default' => 0, 'min_range' => 0, 'max_range' => 6]]
);
$submissionKey = $applicationRecord !== null
    ? (string) $applicationRecord['submission_key']
    : new_permit_submission_key();

// A submitted application keeps the classification it was filed under; a draft
// or new application always follows the account's current classification.
$accountCategory = ($applicant['applicant_category'] ?? '') !== ''
    ? (string) $applicant['applicant_category']
    : null;
$permitCategory = $accountCategory;
if ($applicationRecord !== null
    && (string) $applicationRecord['application_status'] !== 'draft'
    && ($applicationRecord['permit_category'] ?? '') !== '') {
    $permitCategory = (string) $applicationRecord['permit_category'];
}
$permitCategoryDefinition = permit_matrix_category($permitCategory);
$treeSpeciesCatalog=permit_intake_species($pdo);

if ($applicationRecord !== null) {
    $formData = permit_normalize_application_data($applicationRecord, $permitCategory);
    $formData['condition_answers'] = permit_matrix_decode_condition_answers(
        $applicationRecord['condition_answers'] ?? null
    );
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
    ], $permitCategory);
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
    $formData = permit_normalize_application_data($_POST, $permitCategory, true);
    try { $postedTrees=permit_tree_input($_POST); $treeData=permit_normalize_tree_records(permit_resolve_species($pdo,$postedTrees),true); }
    catch(InvalidArgumentException $e){$errors[]=$e->getMessage();$postedTrees=[];$treeData=[];}
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
                    $postedTrees,
                    $applicationId
                );
                $_SESSION['csrf_permit_token'] = bin2hex(random_bytes(32));
                $_SESSION['permit_draft_success'] = 'Draft saved successfully. No transaction ID has been generated yet.';
                header('Location: permit-application.php?id=' . (int) $result['application_id'] . '&step=' . $applicationStep);
                exit;
            }

            $result = submit_permit_application(
                $pdo,
                $userId,
                $submissionKey,
                $_POST,
                $postedTrees,
                $applicationId
            );
            $_SESSION['csrf_permit_token'] = bin2hex(random_bytes(32));
            $_SESSION['permit_success'] = [
                'message' => !empty($result['duplicate'])
                    ? 'This application was already submitted. Its original transaction ID is shown below.'
                    : 'Application submitted. RPS will review your information and attached documents. Keep your transaction ID and check the application for updates.',
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
// Draft edits recompute the checklist live from the form so newly-ticked
// declarations reveal their upload slots immediately.
$documentCatalog = $permitCategory === null ? [] : array_map(
    'permit_document_definition_from_requirement',
    permit_matrix_resolved_requirements(
        $permitCategory,
        $formData['condition_answers'] ?? [],
        $formData['area_hectares'] ?? null,
        (bool) ($formData['filed_by_representative'] ?? false)
    )
);
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
    : 'Save the draft to upload the required documents before submission.';
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../../css/dashboard.css?v=<?php echo e(certreefy_asset_version('css/dashboard.css')); ?>">
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

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <p class="fw-semibold mb-2">Please fix the following:</p>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li data-permit-error><?php echo e((string) $error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($applicationRecord !== null && $applicationRecord['transaction_id'] !== null): ?>
                <section class="docket-panel mb-3" aria-labelledby="status-tracker-heading">
                    <div class="section-heading">
                        <h2 id="status-tracker-heading">Where Your Application Is</h2>
                        <span class="section-note"><?php echo e((string) $applicationRecord['transaction_id']); ?></span>
                    </div>
                    <div class="status-tracker">
                        <?php $trackerSteps = permit_status_tracker_steps($applicationRecord); ?>
                        <?php foreach ($trackerSteps as $index => $step): ?>
                            <div class="status-step status-step-<?php echo e($step['state']); ?>">
                                <div class="status-step-circle"><i class="bi <?php echo e($step['icon']); ?>"></i></div>
                                <div class="status-step-label"><?php echo e($step['label']); ?></div>
                            </div>
                            <?php if ($index < count($trackerSteps) - 1): ?>
                                <div class="status-step-line status-step-line-<?php echo e($step['state']); ?>"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php render_permit_next_steps($applicationRecord ?? ['application_status'=>'draft']); ?>
            <form method="post" action="permit-application.php" novalidate id="permit-form">
                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_permit_token']); ?>">
                <input type="hidden" name="submission_key" value="<?php echo e($submissionKey); ?>">
                <input type="hidden" name="application_id" value="<?php echo $applicationId !== null ? e((string) $applicationId) : ''; ?>">
                <?php if ($isEditable): ?>
                    <input type="hidden" name="application_step" id="application-step" value="<?php echo $applicationStep; ?>">
                <?php endif; ?>

                <div class="row g-3 align-items-start">
                    <div class="col-xl-9">
                        <?php if ($isEditable): ?>
                            <section class="docket-panel mb-3" id="application-progress" aria-label="Application progress" hidden>
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2" aria-live="polite">
                                    <span class="fw-semibold" id="application-step-title"></span>
                                    <span class="text-secondary small" id="application-step-count"></span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="Application sections" aria-valuemin="1" aria-valuemax="6" aria-valuenow="1" id="application-step-progress">
                                    <div class="progress-bar bg-success" id="application-step-bar"></div>
                                </div>
                            </section>
                        <?php endif; ?>
                        <div class="alert alert-danger" id="permit-field-errors" role="alert" hidden></div>
                        <section class="docket-panel mb-3" aria-labelledby="applicant-heading" data-permit-step="Applicant information">
                            <div class="section-heading">
                                <h2 id="applicant-heading">Applicant and Contact Information</h2>
                                <span class="section-note">From your active Client profile</span>
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

                        <section class="docket-panel mb-3" aria-labelledby="classification-heading" data-permit-step="Permit classification">
                            <div class="section-heading">
                                <h2 id="classification-heading">Permit Classification</h2>
                                <span class="section-note">Set by your account registration</span>
                            </div>
                            <?php if ($permitCategoryDefinition === null): ?>
                                <div class="alert alert-warning mb-0">
                                    Your account has no applicant classification yet, so the applicable permit,
                                    requirements, and fees cannot be determined.
                                    Set it in <a href="profile.php" class="alert-link">Profile Management</a> before submitting.
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Classification</label>
                                        <input type="text" class="form-control" value="<?php echo e($permitCategoryDefinition['label']); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php echo e($permitCategoryDefinition['subtype_label']); ?></label>
                                        <input type="text" class="form-control" value="<?php echo e((string) ($applicant['applicant_subtype'] ?? '-')); ?>" disabled>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Permit applied for</label>
                                        <input type="text" class="form-control" value="<?php echo e($permitCategoryDefinition['permit_code'] . ' - ' . $permitCategoryDefinition['permit_title']); ?>" disabled>
                                        <small class="text-secondary">
                                            Form <?php echo e($permitCategoryDefinition['form_code']); ?>
                                            &middot; <?php echo e($permitCategoryDefinition['classification']); ?>
                                            &middot; <?php echo e(implode(', ', $permitCategoryDefinition['transaction_types'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="docket-panel mb-3" aria-labelledby="property-heading" data-permit-step="Property ownership and location">
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
                                <div class="col-md-6"><label class="form-label" for="property_owner_name">Property owner name <span class="text-danger">*</span></label><input class="form-control" id="property_owner_name" name="property_owner_name" maxlength="255" value="<?php echo e((string)($formData['property_owner_name']??'')); ?>" required<?php echo $disabled; ?>></div>
                                <div class="col-12" id="authorization-field"><label class="form-label" for="authorization_details">Authorization details</label><textarea class="form-control" id="authorization_details" name="authorization_details" maxlength="1000"<?php echo $disabled; ?>><?php echo e((string)($formData['authorization_details']??'')); ?></textarea></div>
                                <div class="col-12"><label class="form-label" for="property_address">Detailed property location <span class="small text-secondary">(optional)</span></label><textarea class="form-control" id="property_address" name="property_address" maxlength="500"<?php echo $disabled; ?>><?php echo e((string)($formData['property_address']??'')); ?></textarea></div>
                                <div class="col-12"><?php render_laguna_location_fields($formData,['province'=>'province','district'=>'district','municipality'=>'municipality','barangay'=>'barangay'],true,!$isEditable); ?></div>
                                <div class="col-md-6">
                                    <label for="latitude" class="form-label">Latitude</label>
                                    <input type="number" class="form-control" id="latitude" name="latitude" value="<?php echo e((string) ($formData['latitude'] ?? '')); ?>" min="-90" max="90" step="0.0000001"<?php echo $disabled; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="longitude" class="form-label">Longitude</label>
                                    <input type="number" class="form-control" id="longitude" name="longitude" value="<?php echo e((string) ($formData['longitude'] ?? '')); ?>" min="-180" max="180" step="0.0000001"<?php echo $disabled; ?>>
                                </div>
                                <div class="col-12">
                                    <label class="form-label mb-1"><?php echo $isEditable ? 'Pin the property on the map' : 'Property location map'; ?></label>
                                    <?php if ($isEditable): ?>
                                        <p class="text-secondary small mb-2">Select a location within Laguna, Philippines. Click the map (or use &#9737; for your current location) and drag the pin to adjust.</p>
                                    <?php endif; ?>
                                    <div id="propertyMap" class="geo-map geo-map-tall"></div>
                                </div>
                            </div>
                        </section>

                        <section class="docket-panel mb-3" aria-labelledby="purpose-heading" data-permit-step="Purpose and declarations">
                            <div class="section-heading">
                                <h2 id="purpose-heading">Purpose and Declarations</h2>
                                <span class="section-note">Describe the work and review the applicable fees</span>
                            </div>
                            <div class="row g-3 mb-4">
                                <?php if ($permitCategoryDefinition !== null): ?>
                                    <div class="col-md-6">
                                        <label for="purpose_option" class="form-label">Purpose <span class="text-danger">*</span></label>
                                        <select class="form-select" id="purpose_option" name="purpose_option" required<?php echo $disabled; ?>>
                                            <option value="">Select purpose</option>
                                            <?php foreach ($permitCategoryDefinition['purposes'] as $purpose): ?>
                                                <option value="<?php echo e($purpose); ?>"<?php echo ($formData['purpose_option'] ?? null) === $purpose ? ' selected' : ''; ?>><?php echo e($purpose); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-secondary"><?php echo e($permitCategoryDefinition['purpose_group']); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="area_value" class="form-label">Area applied for<?php if ($permitCategoryDefinition['inventory_fee_applies']): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <div class="input-group">
                                            <input type="number" step="any" min="0.00000001" class="form-control" id="area_value" name="area_value" value="<?php echo e((string)($_POST['area_value']??$formData['area_hectares']??'')); ?>"<?php echo $permitCategoryDefinition['inventory_fee_applies']?' required':''; ?><?php echo $disabled; ?>>
                                            <label for="area_unit" class="visually-hidden">Area unit</label><select class="form-select" id="area_unit" name="area_unit"<?php echo $disabled; ?>><?php foreach(['ha'=>'Hectares','sqm'=>'Square metres','sqkm'=>'Square kilometres'] as $unit=>$label):?><option value="<?php echo $unit; ?>"<?php echo ($_POST['area_unit']??'ha')===$unit?' selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
                                        </div>
                                        <input type="hidden" id="area_hectares" name="area_hectares" value="<?php echo e((string)($formData['area_hectares']??'')); ?>">
                                        <div class="small text-secondary mt-1" id="area-conversion" aria-live="polite"></div>
                                        <small class="text-secondary">
                                            <?php echo $permitCategoryDefinition['inventory_fee_applies']
                                                ? 'Inventory fee is assessed at ' . e(permit_matrix_format_peso(PERMIT_FEE_INVENTORY_PER_HECTARE)) . ' per hectare.'
                                                : 'No inventory fee applies to this permit type.'; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            $conditionQuestions = $permitCategory !== null
                                ? permit_matrix_condition_questions_for($permitCategory)
                                : [];
                            $conditionAnswers = $formData['condition_answers'] ?? [];
                            ?>
                            <?php if ($conditionQuestions !== []): ?>
                                <div class="mb-4">
                                    <h3 class="h5 mb-1">Situational declarations</h3>
                                    <p class="small text-secondary">Answering yes adds the matching requirement to your upload checklist.</p>
                                    <?php foreach ($conditionQuestions as $conditionKey => $conditionLabel): ?>
                                        <div class="form-check">
                                            <input type="hidden" name="<?php echo e($conditionKey); ?>" value="0">
                                            <input class="form-check-input" type="checkbox" value="1" id="cond_<?php echo e($conditionKey); ?>" name="<?php echo e($conditionKey); ?>"<?php echo !empty($conditionAnswers[$conditionKey]) ? ' checked' : ''; ?><?php echo $disabled; ?>>
                                            <label class="form-check-label" for="cond_<?php echo e($conditionKey); ?>"><?php echo e($conditionLabel); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>



                        </section>

                        <section class="docket-panel mb-3" aria-labelledby="tree-records-heading" data-permit-step="Tree records">
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                                <h2 class="h5 mb-0" id="tree-records-heading">Tree records</h2>
                                <?php if ($isEditable): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" id="add-tree">
                                        <i class="bi bi-plus-circle" aria-hidden="true"></i> Add tree entry
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if($isEditable): ?>
                            <div class="row g-2 align-items-end mb-3"><div class="col-sm-6 col-lg-4"><label class="form-label" for="estimated-tree-count">Estimated total trees to cut</label><input class="form-control" id="estimated-tree-count" type="number" min="1" max="131070000" step="1" value="<?php echo max(1,array_sum(array_map(static fn($t)=>(int)($t['quantity']??0),$treeData))); ?>"></div><div class="col-auto"><button type="button" class="btn btn-outline-success" id="generate-tree-table">Generate rows</button></div><div class="col-12"><p class="small text-secondary mb-0" id="tree-table-message" aria-live="polite">One row per tree, or use quantities to group trees of the same species. Existing entries are preserved.</p></div></div>
                            <?php endif; ?>
                            <div class="table-responsive permit-tree-scroller"><table class="table align-middle permit-tree-table"><thead><tr><th>#</th><th>Species</th><th>Tree origin</th><th>Scientific name</th><th>Quantity</th><th>Diameter (cm)</th><th>Height (m)</th><th>Action</th></tr></thead><tbody id="tree-records">
                                <?php foreach($treeData as $index=>$tree): render_intake_tree_row($tree,(int)$index,$isEditable,$treeSpeciesCatalog); endforeach; ?>
                            </tbody></table></div>
                            <div class="row g-3 mt-1">
                                <div class="col-12">
                                    <label for="application_notes" class="form-label">Additional application notes</label>
                                    <textarea class="form-control" id="application_notes" name="application_notes" rows="3" maxlength="5000"<?php echo $disabled; ?>><?php echo e((string) ($formData['application_notes'] ?? '')); ?></textarea>
                                </div>
                            </div>
                        </section>

                        <?php if ($isEditable && $applicationRecord === null): ?>
                            <section class="docket-panel mb-3" id="supporting-documents-start" data-permit-step="Supporting documents">
                                <h2 class="h5">Supporting Documents</h2>
                                <p>Save your draft, then upload the required documents before submitting the application.</p>
                                <button class="btn btn-certreefy" type="submit" name="action" value="save_draft" formnovalidate>Save draft and attach documents</button>
                            </section>
                        <?php endif; ?>
                        <section class="docket-panel" aria-labelledby="declaration-heading" data-permit-step="Applicant declaration">
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
                                    <button type="submit" class="btn btn-certreefy" name="action" value="submit">
                                        <i class="bi bi-send-check"></i> Submit application
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light border mt-4 mb-0" role="status">
                                    <i class="bi bi-lock"></i> Locked after submission — editing isn't available.
                                </div>
                                <a href="permit-applications.php" class="btn btn-outline-secondary mt-3">
                                    <i class="bi bi-arrow-left"></i> Back to applications
                                </a>
                            <?php endif; ?>
                        </section>
                        <?php if ($isEditable): ?>
                            <div class="docket-panel mt-3">
                                <div id="application-step-navigation" hidden>
                                    <div class="d-flex justify-content-between gap-2 mb-3">
                                        <button type="button" class="btn btn-outline-secondary" id="application-previous"><i class="bi bi-arrow-left"></i> Previous</button>
                                        <button type="button" class="btn btn-certreefy" id="application-next">Next <i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" form="permit-form" class="btn btn-outline-secondary" name="action" value="save_draft" formnovalidate><i class="bi bi-save" aria-hidden="true"></i> Save draft</button>
                                    <a href="permit-applications.php" class="btn btn-outline-secondary">Back to applications</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-xl-3 align-self-stretch">
                        <aside class="snapshot-panel snapshot-sticky">
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
                            <?php if ($permitCategoryDefinition !== null): ?>
                                <?php $assessedFees = permit_matrix_assess_fees($permitCategory, $formData['area_hectares'] ?? null); ?>
                                <div class="border rounded p-3 mt-3">
                                    <div class="fw-semibold mb-2" id="permit-fee-guide" data-certification="<?php echo $assessedFees['certification_fee']; ?>" data-oath="<?php echo $assessedFees['oath_fee']; ?>" data-inventory-rate="<?php echo $assessedFees['inventory_applies'] ? PERMIT_FEE_INVENTORY_PER_HECTARE : 0; ?>"><i class="bi bi-receipt me-1"></i>Assessment of fees</div>
                                    <div class="d-flex justify-content-between"><span>Certification fee</span><span class="tabular"><?php echo e(permit_matrix_format_peso($assessedFees['certification_fee'])); ?></span></div>
                                    <div class="d-flex justify-content-between"><span>Oath fee</span><span class="tabular"><?php echo e(permit_matrix_format_peso($assessedFees['oath_fee'])); ?></span></div>
                                    <div class="d-flex justify-content-between">
                                        <span>Inventory fee<?php echo $assessedFees['inventory_applies'] ? '' : ' (not applicable)'; ?></span>
                                        <span class="tabular" id="inventory-fee-value"><?php echo e(permit_matrix_format_peso($assessedFees['inventory_fee'])); ?></span>
                                    </div>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between fw-semibold"><span>Total</span><span class="tabular" id="total-fee-value"><?php echo e(permit_matrix_format_peso($assessedFees['total_fee'])); ?></span></div>
                                    <small class="text-secondary">Payable at the CENRO office when you submit your original documents.</small>
                                </div>
                            <?php endif; ?>
                            <?php if ($isEditable): ?><div class="mt-3"><h3 class="h6">Field Completion</h3><ul class="list-unstyled small mb-0" id="application-completion"></ul></div><?php endif; ?>
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
                    <div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>View-only — RPS schedules inspections and records findings. Passing inspection doesn't approve the permit.</div>
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
                    <div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>Approval isn't the final release — it still goes through donation and release.</div>
                    <?php if ($donationRequirement !== null): ?><div class="mb-3"><?php render_permit_donation_requirement($donationRequirement); ?></div><?php endif; ?>
                    <?php if ($permitDecisions === []): ?>
                        <p class="text-secondary mb-0">No RPS review action has been recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Review update</th><th>Remarks</th><th>Decision details</th><th>Date</th></tr></thead><tbody>
                            <?php foreach ($permitDecisions as $decisionEvent): ?><tr><td><span class="badge <?php echo e(permit_decision_event_badge((string) $decisionEvent['decision'])); ?>"><?php echo e(permit_decision_event_label((string) $decisionEvent['decision'])); ?></span></td><td class="text-break"><?php echo e((string) ($decisionEvent['decision_notes'] ?? '-')); ?><?php if (!empty($decisionEvent['decision_conditions'])): ?><div class="small mt-1"><strong>Conditions:</strong> <?php echo e((string) $decisionEvent['decision_conditions']); ?></div><?php endif; ?></td><td class="small"><?php if ($decisionEvent['approved_tree_count'] !== null): ?><div>Approved trees: <strong><?php echo (int) $decisionEvent['approved_tree_count']; ?></strong></div><?php if (!empty($decisionEvent['property_classification'])): ?><div>Property: <?php echo e(permit_status_label((string) $decisionEvent['property_classification'])); ?></div><?php endif; ?><div>Donation: <?php echo (int) $decisionEvent['donation_seedling_count']; ?> seedling(s)</div><?php else: ?>-<?php endif; ?></td><td><?php echo e(date('M j, Y g:i A', strtotime((string) $decisionEvent['decided_at']))); ?></td></tr><?php endforeach; ?>
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
                                <p class="small text-secondary mb-2">For reference only — the official permit is the signed copy claimed at the office.</p>
                                <div class="row g-3 mb-2">
                                    <div class="col-md-4"><div class="small text-secondary">Signed on</div><div class="fw-semibold"><?php echo $permitReleaseRecord['signed_on'] !== null ? e(date('M j, Y', strtotime((string) $permitReleaseRecord['signed_on']))) : '-'; ?></div></div>
                                    <div class="col-md-4"><div class="small text-secondary">Signing personnel</div><div class="fw-semibold"><?php echo $permitReleaseRecord['signed_by_name'] !== null ? e((string) $permitReleaseRecord['signed_by_name']) : '-'; ?></div></div>
                                    <div class="col-md-4"><div class="small text-secondary">Released to</div><div class="fw-semibold"><?php echo $permitReleaseRecord['released_to_recipient'] !== null ? e((string) $permitReleaseRecord['released_to_recipient']) : '-'; ?></div></div>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" href="permit-signed-download.php?id=<?php echo (int) $applicationId; ?>"><i class="bi bi-download"></i> Download reference copy</a>
                            </div>
                        <?php endif; ?>
                        <?php if ((string) $applicationRecord['validity_status'] === 'active' && !empty($permitValiditySnapshot['is_expiring_soon'])): ?>
                            <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>Expires in <?php echo (int) $permitValiditySnapshot['days_remaining']; ?> day(s), no extension — complete cutting before it lapses.</div>
                        <?php elseif ((string) $applicationRecord['validity_status'] === 'expired'): ?>
                            <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>Your permit has expired and cannot be extended or reactivated. If cutting was not completed, a new application and transaction ID are required.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0"><i class="bi bi-info-circle me-1"></i>Donation verified — awaiting permit preparation and release by RPS. No extension once released.</div>
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
                <section class="docket-panel mt-3" id="documents" aria-labelledby="documents-heading"<?php echo $isEditable ? ' data-permit-step="Supporting documents"' : ''; ?>>
                    <div class="section-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div>
                            <h2 id="documents-heading">Scanned Documents</h2>
                            <span class="section-note">Transaction <?php echo e((string) ($applicationRecord['transaction_id'] ?? 'not yet generated')); ?></span>
                        </div>
                        <span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $applicationRecord['document_status'])); ?></span>
                    </div>

                    <div class="alert alert-warning" role="note">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Scans support online screening only — they don't replace original hardcopy documents, wet-ink signatures, or in-person verification.
                    </div>

                    <?php if (is_array($documentFlash)): ?>
                        <div class="alert alert-<?php echo ($documentFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>" role="alert">
                            <?php echo e((string) ($documentFlash['message'] ?? 'The document action could not be completed.')); ?>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required digital scans accepted</span><span class="fw-semibold"><?php echo $acceptedRequiredCount; ?> of <?php echo $requiredDocumentCount; ?></span></div><div class="progress" role="progressbar" aria-label="Required online scans" aria-valuenow="<?php echo $documentProgress; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo $documentProgress; ?>%"><?php echo $documentProgress; ?>%</div></div></div></div>
                        <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between small mb-1"><span>Required originals verified</span><span class="fw-semibold"><?php echo (int) $originalDocumentProgress['verified']; ?> of <?php echo (int) $originalDocumentProgress['required']; ?></span></div><div class="progress" role="progressbar" aria-label="Required original documents" aria-valuenow="<?php echo (int) $originalDocumentProgress['percent']; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?php echo (int) $originalDocumentProgress['percent']; ?>%"><?php echo (int) $originalDocumentProgress['percent']; ?>%</div></div></div></div>
                        <div class="col-12"><div class="form-text">
                            <?php if ($permitCategoryDefinition !== null): ?>
                                Checklist for <?php echo e($permitCategoryDefinition['permit_code']); ?> (form <?php echo e($permitCategoryDefinition['form_code']); ?>).
                                Ticking a situational declaration above adds its requirement here.
                            <?php else: ?>
                                Set your applicant classification to see the requirement checklist.
                            <?php endif; ?>
                        </div></div>
                    </div>

                    <?php if ($documentUploadLockReason !== null): ?>
                        <div class="alert alert-light border" role="status">
                            <i class="bi bi-lock me-1"></i><?php echo e($documentUploadLockReason); ?> Downloads stay available.
                        </div>
                    <?php endif; ?>

                    <p class="text-danger small" id="required-documents-error" role="alert" hidden>Upload all required documents before continuing.</p>
                    <div class="table-responsive">
                        <table class="table align-middle permit-document-table">
                            <caption class="visually-hidden">Required documents, submitted scans, review remarks, and upload actions</caption>
                            <thead><tr><th scope="col">Requirement</th><th scope="col">Attachment and scan review</th><th scope="col">Original verification</th><th scope="col">Upload</th></tr></thead>
                            <tbody>
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
                            <tr data-required-upload="<?php echo !empty($definition['required']) ? '1' : '0'; ?>" data-uploaded="<?php echo $currentDocument !== null ? '1' : '0'; ?>">
                                    <td class="permit-document-cell">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <h3 class="h6 mb-0"><?php echo e((string) $definition['label']); ?></h3>
                                            <span class="badge text-bg-light border text-nowrap">
                                                <?php echo e(match ((string) ($definition['group'] ?? 'mandatory')) {
                                                    'conditional' => 'Conditional',
                                                    'representative' => 'Representative',
                                                    default => 'Mandatory',
                                                }); ?>
                                            </span>
                                        </div>
                                        <p class="small text-secondary mb-0"><?php echo e((string) $definition['description']); ?></p>
                                    </td>
                                    <td class="permit-document-cell">

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
                                                    <div class="small mt-2"><strong>Scan review remarks:</strong> <?php echo e((string) $currentDocument['verification_notes']); ?></div>
                                                <?php endif; ?>
                                                <a class="btn btn-sm btn-outline-secondary mt-2" href="permit-document-download.php?id=<?php echo e((string) $currentDocument['id']); ?>">
                                                    <i class="bi bi-download"></i> Download
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-secondary mb-3"><i class="bi bi-file-earmark"></i> No scan uploaded.</div>
                                        <?php endif; ?>

                                    </td>
                                    <td class="permit-document-cell">
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

                                    </td>
                                    <td class="permit-document-cell">
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
                                            <div class="small text-success"><i class="bi bi-check-circle"></i> Accepted online scans are locked against Client replacement.</div>
                                        <?php endif; ?>
                                    </td>
                            </tr>
                        <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($archivedDocuments !== []): ?>
                        <div class="mt-4">
                            <h3 class="h5">Replacement History</h3>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead><tr><th>Document type</th><th>Filename</th><th>Status before archive</th><th>Review remarks</th><th>Uploaded</th><th class="text-end">File</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($archivedDocuments as $document): ?>
                                            <?php $definition = permit_document_type((string) $document['document_type']); ?>
                                            <tr>
                                                <td><?php echo e((string) ($definition['label'] ?? $document['document_type'])); ?></td>
                                                <td class="text-break"><?php echo e((string) $document['original_filename']); ?></td>
                                                <td><span class="badge <?php echo e(permit_document_status_badge((string) $document['verification_status'], false)); ?>"><?php echo e(permit_document_status_label((string) $document['verification_status'], false)); ?></span></td>
                                                <td class="text-break"><?php echo e((string) ($document['verification_notes'] ?: 'No remarks recorded.')); ?></td>
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
            <?php if ($applicationRecord !== null && $applicationRecord['transaction_id'] !== null): render_permit_tally($pdo, $applicationRecord, false); endif; ?>
            <?php if ($applicationRecord !== null): render_permit_completion_panel($pdo, $applicationRecord, (int)$_SESSION['id']); endif; ?>
            <?php if ($applicationRecord !== null): render_permit_tarpaulin($pdo, $applicationRecord, false); endif; ?>
            <?php if ($donationRequirement !== null): render_seedling_provider_locations($pdo); endif; ?>
        </main>
    </div>

    <?php if ($isEditable): ?>
        <template id="tree-template"><?php render_intake_tree_row([],0,true,$treeSpeciesCatalog); ?></template>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php geo_render_map_script('../../'); ?>
    <script>
        (() => {
            let propertyMap = null;
            const initializeMap = () => {
                if (!document.getElementById('propertyMap').offsetWidth) return;
                if (propertyMap) {
                    propertyMap.invalidateSize();
                    return;
                }
                propertyMap = CertreefyGeo.picker('propertyMap', {
                    latInput: 'latitude',
                    lngInput: 'longitude',
                    boundary: <?php echo json_encode(geo_laguna_boundary(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    readOnly: <?php echo $isEditable ? 'false' : 'true'; ?>
                });
            };
            <?php if ($isEditable): ?>
            document.getElementById('permit-form').addEventListener('permit-step-change', () => requestAnimationFrame(initializeMap));
            <?php else: ?>
            initializeMap();
            <?php endif; ?>
        })();
    </script>
    <?php if ($isEditable): ?>
        <script src="../../js/permit-form-feedback.js?v=<?php echo e(certreefy_asset_version('js/permit-form-feedback.js')); ?>"></script>
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
                        row.querySelector('.tree-title').textContent = String(index + 1);
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

                records.addEventListener('trees:changed',renumber);
                const toggleConditionalFields = () => {
                    organizationField.classList.toggle('d-none', applicantType.value !== 'organization');
                    authorizationField.classList.toggle('d-none', relationship.value !== 'authorized_representative');
                    document.getElementById('organization_name').required = applicantType.value === 'organization';
                    document.getElementById('authorization_details').required = relationship.value === 'authorized_representative';
                };
                applicantType.addEventListener('change', toggleConditionalFields);
                relationship.addEventListener('change', toggleConditionalFields);
                renumber();
                toggleConditionalFields();

                const form = document.getElementById('permit-form');
                const feeGuide = document.getElementById('permit-fee-guide');
                const areaField = document.getElementById('area_hectares');
                if (feeGuide && areaField) areaField.addEventListener('input', () => {
                    const area = Math.max(0, Number(areaField.value) || 0);
                    const inventory = Math.round(area * Number(feeGuide.dataset.inventoryRate) * 100) / 100;
                    const total = inventory + Number(feeGuide.dataset.certification) + Number(feeGuide.dataset.oath);
                    const peso = new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP'});
                    document.getElementById('inventory-fee-value').textContent = peso.format(inventory);
                    document.getElementById('total-fee-value').textContent = peso.format(total);
                });
                const steps = Array.from(form.querySelectorAll('[data-permit-step]'));
                const documents = document.getElementById('documents');
                if (documents?.hasAttribute('data-permit-step')) steps.splice(5, 0, documents);
                const stepInput = document.getElementById('application-step');
                const previous = document.getElementById('application-previous');
                const next = document.getElementById('application-next');
                let current = Math.min(steps.length - 1, Math.max(0, Number(stepInput.value) || 0));
                document.getElementById('declaration_confirmed').required = true;
                const navigation = document.getElementById('application-step-navigation').parentElement;
                const navigationHome = document.createComment('Permit navigation');
                navigation.before(navigationHome);

                function showStep(index, moveFocus = true) {
                    current = index;
                    if (steps[index] === documents) documents.after(navigation);
                    else navigationHome.after(navigation);
                    stepInput.value = String(index);
                    steps.forEach((step, i) => { step.hidden = i !== index; });
                    previous.disabled = index === 0;
                    next.hidden = index === steps.length - 1;
                    document.getElementById('application-step-title').textContent = steps[index].dataset.permitStep;
                    document.getElementById('application-step-count').textContent = `Step ${index + 1} of ${steps.length}`;
                    document.getElementById('application-step-progress').setAttribute('aria-valuenow', String(index + 1));
                    document.getElementById('application-step-bar').style.width = `${(index + 1) / steps.length * 100}%`;
                    document.getElementById('application-step-progress').setAttribute('aria-valuemax', String(steps.length));
                    updateCompletion();
                    form.dispatchEvent(new CustomEvent('permit-step-change', { detail: { index } }));
                    if (moveFocus) {
                        const heading = steps[index].querySelector('h2');
                        heading.tabIndex = -1;
                        heading.focus({ preventScroll: true });
                        (steps[index] === documents ? documents : document.getElementById('application-progress')).scrollIntoView({ block: 'start', behavior: 'instant' });
                    }
                }

                const fieldFeedback = CertreefyPermitFeedback(form, field => {
                    const index = steps.findIndex(step => step.contains(field));
                    if (index >= 0) showStep(index);
                });

                function validateStep(index) {
                    const step = steps[index];
                    if (step.id === 'supporting-documents-start') {
                        showStep(index);
                        step.querySelector('button').focus();
                        return false;
                    }
                    if (step === documents) {
                        const missing = documents.querySelector('[data-required-upload="1"][data-uploaded="0"]');
                        document.getElementById('required-documents-error').hidden = !missing;
                        if (missing) { showStep(index); missing.querySelector('input[type="file"]')?.focus(); return false; }
                        return true;
                    }
                    if (step.contains(records)) {
                        records.setAttribute('tabindex', '-1');
                        if (!records.querySelector('[data-tree-row]')) {
                            showStep(index);
                            document.getElementById('add-tree').focus();
                            treeError.hidden = false;
                            return false;
                        }
                        treeError.hidden = true;
                    }
                    return fieldFeedback.validate(step);
                }

                function updateCompletion() {
                    const list = document.getElementById('application-completion');
                    list.replaceChildren();
                    steps.forEach(step => {
                        let complete;
                        if (step.id === 'supporting-documents-start') complete = false;
                        else if (step === documents) complete = !documents.querySelector('[data-required-upload="1"][data-uploaded="0"]');
                        else complete = [...step.querySelectorAll('input,select,textarea')].every(field => field.form !== form || !field.willValidate || (field.checkValidity() && !field.classList.contains('is-invalid')));
                        if (step.contains(records) && !records.querySelector('[data-tree-row]')) complete = false;
                        const item = document.createElement('li');
                        const icon = document.createElement('i');
                        icon.className = complete ? 'bi bi-check-circle-fill text-success me-2' : 'bi bi-x-circle text-secondary me-2';
                        icon.setAttribute('aria-hidden', 'true');
                        item.append(icon, document.createTextNode(step.dataset.permitStep + (complete ? ' — Complete' : ' — Incomplete')));
                        list.append(item);
                    });
                }
                form.addEventListener('input', updateCompletion);
                form.addEventListener('change', updateCompletion);
                records.addEventListener('click', () => requestAnimationFrame(updateCompletion));
                addButton.addEventListener('click', () => requestAnimationFrame(updateCompletion));

                const treeError = document.createElement('p');
                treeError.className = 'text-danger small mt-2 mb-0';
                treeError.setAttribute('role', 'alert');
                treeError.textContent = 'Add at least one tree entry before continuing.';
                treeError.hidden = true;
                records.after(treeError);
                previous.addEventListener('click', () => showStep(current - 1));
                next.addEventListener('click', () => {
                    if (validateStep(current)) showStep(current + 1);
                });
                form.addEventListener('submit', event => {
                    if (event.submitter?.value === 'save_draft') return;
                    if (current < steps.length - 1) {
                        event.preventDefault();
                        if (validateStep(current)) showStep(current + 1);
                        return;
                    }
                    for (let index = 0; index < steps.length; index++) {
                        if (!validateStep(index)) {
                            event.preventDefault();
                            return;
                        }
                    }
                });
                document.getElementById('application-progress').hidden = false;
                document.getElementById('application-step-navigation').hidden = false;
                showStep(current, false);
                fieldFeedback.serverErrors();
            })();
        </script>
    <?php endif; ?>
<script src="../../js/intake-fields.js?v=<?php echo e(certreefy_asset_version('js/intake-fields.js')); ?>"></script>
<?php render_location_script(); ?>
<script src="../../js/seedling-locations.js?v=<?php echo e(certreefy_asset_version('js/seedling-locations.js')); ?>"></script>
</body>
</html>
