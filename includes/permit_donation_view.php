<?php
/** Shared Bootstrap presentation for a read-only donation requirement. */

require_once __DIR__ . '/permit_donations.php';
require_once __DIR__ . '/view.php';

function render_permit_donation_requirement(
    array $requirement,
    bool $showApplicant = false
): void {
    ?>
    <div class="border rounded p-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
            <div><h3 class="h5 mb-1">Seedling Donation Requirement</h3><div class="small text-secondary">Applied policy <?php echo e((string) ($requirement['policy_code'] ?? 'Legacy')); ?> &middot; version <?php echo e((string) ($requirement['policy_version'] ?? 'Legacy')); ?></div></div>
            <span class="badge text-bg-light border"><?php echo e(permit_status_label((string) $requirement['current_status'])); ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-6"><div class="small text-secondary">Transaction ID</div><div class="fw-semibold text-break"><?php echo e((string) $requirement['transaction_id']); ?></div></div>
            <div class="col-md-6"><div class="small text-secondary">Application reference</div><div class="fw-semibold">Tree Cutting Permit application #<?php echo (int) $requirement['application_id']; ?></div></div>
            <?php if ($showApplicant): ?><div class="col-md-6"><div class="small text-secondary">Applicant</div><div class="fw-semibold"><?php echo e((string) $requirement['applicant_name']); ?></div></div><?php endif; ?>
            <div class="col-md-6"><div class="small text-secondary">Property classification</div><div class="fw-semibold"><?php echo e(permit_status_label((string) $requirement['property_classification'])); ?></div></div>
            <div class="col-sm-4"><div class="small text-secondary">Required</div><div class="fs-5 fw-semibold"><?php echo (int) $requirement['required_seedling_count']; ?></div></div>
            <div class="col-sm-4"><div class="small text-secondary">Currently received</div><div class="fs-5 fw-semibold"><?php echo (int) $requirement['received_seedling_count']; ?></div></div>
            <div class="col-sm-4"><div class="small text-secondary">Remaining</div><div class="fs-5 fw-semibold"><?php echo (int) $requirement['remaining_seedling_count']; ?></div></div>
            <div class="col-12"><div class="small text-secondary">EMS instructions</div><div class="text-break"><?php echo e((string) $requirement['applicant_instructions']); ?></div></div>
            <div class="col-12"><div class="small text-secondary">Important remarks</div><div class="text-break"><?php echo e((string) $requirement['requirement_basis']); ?></div><?php if (!empty($requirement['decision_notes'])): ?><div class="small mt-1 text-break"><strong>Approval remarks:</strong> <?php echo e((string) $requirement['decision_notes']); ?></div><?php endif; ?><?php if (!empty($requirement['decision_conditions'])): ?><div class="small mt-1 text-break"><strong>Conditions:</strong> <?php echo e((string) $requirement['decision_conditions']); ?></div><?php endif; ?></div>
        </div>
        <div class="alert alert-warning mt-3 mb-0" role="note"><i class="bi bi-exclamation-triangle me-1"></i><?php if ((string) $requirement['current_status'] === 'ems_verified'): ?>EMS receipt verification is complete. Final RPS confirmation and the separate permit-release process are still required.<?php elseif ((string) $requirement['current_status'] === 'rps_verified'): ?>Donation verification is complete, but the separate permit-release process has not been completed by this module.<?php else: ?>This requirement does not mean the permit is ready for release. EMS receipt and verification and final RPS confirmation must be completed first.<?php endif; ?></div>
    </div>
    <?php
}
