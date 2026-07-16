<?php
/** Configurable Tree Cutting Permit seedling donation policy. */

$publicDomainDonationCount = filter_var(
    getenv('CERTREEFY_PUBLIC_DOMAIN_DONATION_COUNT'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000000]]
);
$privatePropertyDonationCount = filter_var(
    getenv('CERTREEFY_PRIVATE_PROPERTY_DONATION_COUNT'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000000]]
);
if (!defined('PERMIT_PUBLIC_DOMAIN_DONATION_COUNT')) {
    define('PERMIT_PUBLIC_DOMAIN_DONATION_COUNT', $publicDomainDonationCount === false ? 100 : $publicDomainDonationCount);
}
if (!defined('PERMIT_PRIVATE_PROPERTY_DONATION_COUNT')) {
    define('PERMIT_PRIVATE_PROPERTY_DONATION_COUNT', $privatePropertyDonationCount === false ? 50 : $privatePropertyDonationCount);
}

$donationPolicyVersion = trim((string) getenv('CERTREEFY_DONATION_POLICY_VERSION'));
if (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $donationPolicyVersion)) {
    $donationPolicyVersion = '1';
}
if (!defined('PERMIT_DONATION_POLICY_VERSION')) {
    define('PERMIT_DONATION_POLICY_VERSION', $donationPolicyVersion);
}

$donationInstructions = trim((string) getenv('CERTREEFY_DONATION_INSTRUCTIONS'));
if ($donationInstructions === '' || strlen($donationInstructions) > 1000) {
    $donationInstructions = 'Present the Tree Cutting Permit transaction ID to the EMS office and coordinate the seedling delivery schedule. EMS receipt and verification, followed by final RPS confirmation, must be completed before permit release.';
}
if (!defined('PERMIT_DONATION_INSTRUCTIONS')) {
    define('PERMIT_DONATION_INSTRUCTIONS', $donationInstructions);
}

/**
 * Permit validity policy.
 *
 * Office rule: an approved Tree Cutting Permit is valid for a fixed duration
 * between 50 and 90 days with no extension and no reactivation. The exact
 * approved duration is entered at release, stored, and used to derive the
 * expiration date. These bounds are hard limits enforced by the release
 * service; the environment overrides only shift the default within the bounds.
 */
if (!defined('PERMIT_VALIDITY_MIN_DAYS')) {
    define('PERMIT_VALIDITY_MIN_DAYS', 50);
}
if (!defined('PERMIT_VALIDITY_MAX_DAYS')) {
    define('PERMIT_VALIDITY_MAX_DAYS', 90);
}
$defaultValidityDays = filter_var(
    getenv('CERTREEFY_PERMIT_VALIDITY_DEFAULT_DAYS'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => PERMIT_VALIDITY_MIN_DAYS, 'max_range' => PERMIT_VALIDITY_MAX_DAYS]]
);
if (!defined('PERMIT_VALIDITY_DEFAULT_DAYS')) {
    define('PERMIT_VALIDITY_DEFAULT_DAYS', $defaultValidityDays === false ? PERMIT_VALIDITY_MAX_DAYS : $defaultValidityDays);
}

/**
 * Which date starts validity. The official rule (signing, effectivity, or
 * physical release date) is UNVERIFIED and awaits a project-owner decision.
 * The least risky, repository-supported implementation begins validity on the
 * physical release date recorded by the RPS releaser, which is always present
 * for a released permit. The chosen basis is stored on every permit so the
 * decision can be revisited without rewriting history.
 */
$validityStartBasis = trim((string) getenv('CERTREEFY_PERMIT_VALIDITY_START_BASIS'));
if (!in_array($validityStartBasis, ['release_date', 'signing_date', 'effectivity_date'], true)) {
    $validityStartBasis = 'release_date';
}
if (!defined('PERMIT_VALIDITY_START_BASIS')) {
    define('PERMIT_VALIDITY_START_BASIS', $validityStartBasis);
}

/** How many days before expiration an "approaching expiration" reminder fires. */
$expiryWarningDays = filter_var(
    getenv('CERTREEFY_PERMIT_EXPIRY_WARNING_DAYS'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 45]]
);
if (!defined('PERMIT_VALIDITY_EXPIRY_WARNING_DAYS')) {
    define('PERMIT_VALIDITY_EXPIRY_WARNING_DAYS', $expiryWarningDays === false ? 7 : $expiryWarningDays);
}
