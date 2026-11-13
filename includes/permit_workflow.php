<?php
/**
 * Central Tree Cutting Permit status vocabulary and transition rules.
 */

function permit_workflow_statuses(): array
{
    return [
        'application' => [
            'draft',
            'submitted',
            'under_review',
            'awaiting_documents',
            'awaiting_inspection',
            'awaiting_decision',
            'approved',
            'declined',
            'awaiting_donation',
            'awaiting_final_verification',
            'ready_for_release',
            'released',
            'completed',
            'closed',
        ],
        'document' => [
            'pending',
            'under_review',
            'incomplete',
            'online_verified',
            'originals_verified',
            'verified',
            'waived',
        ],
        'inspection' => [
            'pending_assessment',
            'not_required',
            'required',
            'scheduled',
            'rescheduled',
            'in_progress',
            'completed',
            'passed',
            'failed',
            'for_further_evaluation',
            'cancelled',
        ],
        'decision' => [
            'pending',
            'under_review',
            'approved',
            'declined',
            'returned',
        ],
        'donation' => [
            'not_required',
            'required',
            'pending',
            'partially_received',
            'flagged',
            'ems_verified',
            'rps_verified',
            'waived',
        ],
        'release' => [
            'not_ready',
            'preparing',
            'ready',
            'released',
            'withheld',
        ],
        'validity' => [
            'not_issued',
            'active',
            'completed',
            'expired',
            'closed',
        ],
    ];
}

function permit_initial_statuses(): array
{
    return [
        'application' => 'submitted',
        'document' => 'pending',
        'inspection' => 'pending_assessment',
        'decision' => 'pending',
        'donation' => 'not_required',
        'release' => 'not_ready',
        'validity' => 'not_issued',
    ];
}

function permit_status_columns(): array
{
    return [
        'application' => 'application_status',
        'document' => 'document_status',
        'inspection' => 'inspection_status',
        'decision' => 'decision_status',
        'donation' => 'donation_status',
        'release' => 'release_status',
        'validity' => 'validity_status',
    ];
}

function permit_status_transitions(): array
{
    return [
        'application' => [
            'draft' => [],
            'submitted' => ['under_review', 'closed'],
            'under_review' => ['awaiting_documents', 'awaiting_inspection', 'awaiting_decision', 'declined', 'closed'],
            'awaiting_documents' => ['under_review', 'awaiting_inspection', 'awaiting_decision', 'declined', 'closed'],
            'awaiting_inspection' => ['under_review', 'awaiting_documents', 'awaiting_decision', 'declined', 'closed'],
            'awaiting_decision' => ['under_review', 'awaiting_documents', 'approved', 'declined', 'closed'],
            'approved' => ['awaiting_donation', 'ready_for_release', 'closed'],
            'declined' => ['closed'],
            'awaiting_donation' => ['awaiting_final_verification', 'closed'],
            'awaiting_final_verification' => ['awaiting_donation', 'ready_for_release', 'closed'],
            'ready_for_release' => ['released', 'closed'],
            'released' => ['completed', 'closed'],
            'completed' => ['closed'],
            'closed' => [],
        ],
        'document' => [
            'pending' => ['under_review', 'waived'],
            'under_review' => ['incomplete', 'online_verified', 'originals_verified', 'verified'],
            'incomplete' => ['under_review'],
            'online_verified' => ['originals_verified', 'verified', 'incomplete'],
            'originals_verified' => ['online_verified', 'verified', 'incomplete'],
            'verified' => ['under_review'],
            'waived' => ['under_review'],
        ],
        'inspection' => [
            'pending_assessment' => ['not_required', 'required'],
            'not_required' => ['required'],
            'required' => ['scheduled', 'cancelled'],
            'scheduled' => ['rescheduled', 'in_progress', 'completed', 'passed', 'failed', 'for_further_evaluation', 'cancelled'],
            'rescheduled' => ['rescheduled', 'in_progress', 'completed', 'passed', 'failed', 'for_further_evaluation', 'cancelled'],
            'in_progress' => ['completed', 'passed', 'failed', 'for_further_evaluation', 'cancelled'],
            'completed' => ['scheduled'],
            'passed' => ['scheduled'],
            'failed' => ['scheduled'],
            'for_further_evaluation' => ['scheduled'],
            'cancelled' => ['scheduled'],
        ],
        'decision' => [
            'pending' => ['under_review'],
            'under_review' => ['approved', 'declined', 'returned'],
            'approved' => [],
            'declined' => [],
            'returned' => ['under_review'],
        ],
        'donation' => [
            'not_required' => ['required', 'waived'],
            'required' => ['pending', 'waived'],
            'pending' => ['partially_received', 'flagged', 'ems_verified', 'waived'],
            'partially_received' => ['pending', 'flagged', 'ems_verified'],
            'flagged' => ['pending', 'partially_received', 'ems_verified'],
            'ems_verified' => ['pending', 'rps_verified'],
            'rps_verified' => [],
            'waived' => [],
        ],
        'release' => [
            'not_ready' => ['preparing', 'withheld'],
            'preparing' => ['ready', 'withheld'],
            'ready' => ['released', 'withheld'],
            'released' => [],
            'withheld' => ['preparing', 'ready'],
        ],
        'validity' => [
            'not_issued' => ['active', 'closed'],
            'active' => ['completed', 'expired', 'closed'],
            'completed' => ['closed'],
            'expired' => ['closed'],
            'closed' => [],
        ],
    ];
}

function permit_status_is_supported(string $domain, string $status): bool
{
    $statuses = permit_workflow_statuses();

    return isset($statuses[$domain]) && in_array($status, $statuses[$domain], true);
}

function permit_status_transition_is_allowed(string $domain, string $fromStatus, string $toStatus): bool
{
    $transitions = permit_status_transitions();

    return isset($transitions[$domain][$fromStatus])
        && in_array($toStatus, $transitions[$domain][$fromStatus], true);
}

function permit_roles_for_status_change(string $domain, string $newStatus): array
{
    if ($domain === 'donation' && in_array($newStatus, ['partially_received', 'flagged', 'ems_verified'], true)) {
        return ['ems'];
    }

    return ['rps'];
}

function permit_status_requires_verified_original_documents(string $domain, string $newStatus): bool
{
    if ($domain === 'decision' && $newStatus === 'approved') {
        return true;
    }

    return $domain === 'application' && in_array($newStatus, [
        'awaiting_inspection',
        'awaiting_decision',
        'approved',
        'awaiting_donation',
        'awaiting_final_verification',
        'ready_for_release',
        'released',
        'completed',
    ], true);
}

function permit_status_requires_passed_inspection(string $domain, string $newStatus): bool
{
    if ($domain === 'decision' && $newStatus === 'approved') {
        return true;
    }

    return $domain === 'application' && in_array($newStatus, [
        'awaiting_decision',
        'approved',
        'awaiting_donation',
        'awaiting_final_verification',
        'ready_for_release',
        'released',
        'completed',
    ], true);
}

function permit_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/** Read-only summary of the current processing stage and its specific status. */
function permit_stage_status(array $application): array
{
    $app = (string) ($application['application_status'] ?? 'draft');
    $documents = (string) ($application['document_status'] ?? 'pending');
    $inspection = (string) ($application['inspection_status'] ?? 'pending_assessment');
    $decision = (string) ($application['decision_status'] ?? 'pending');
    $donation = (string) ($application['donation_status'] ?? 'not_required');
    $release = (string) ($application['release_status'] ?? 'not_ready');
    $validity = (string) ($application['validity_status'] ?? 'not_issued');
    $stage = 'Submission';
    $status = permit_status_label($app);
    $tone = 'primary';
    $statusClass = 'text-secondary';

    // Terminal and post-approval stages take precedence over old review flags.
    if ($app === 'completed' || $validity === 'completed') {
        [$stage, $status, $tone] = ['Cutting completion', 'Completed', 'success'];
        $statusClass = 'text-success';
    } elseif ($validity === 'expired') {
        [$stage, $status, $tone] = ['Permit validity', 'Expired', 'danger'];
        $statusClass = 'text-danger';
    } elseif ($app === 'closed' || $validity === 'closed') {
        [$stage, $status, $tone] = ['Application closure', 'Closed', 'secondary'];
    } elseif ($app === 'declined' || $decision === 'declined') {
        [$stage, $status, $tone] = ['RPS decision', 'Declined', 'danger'];
        $statusClass = 'text-danger';
    } elseif (in_array($application['completion_status'] ?? '', ['pending_ems','follow_up'], true)) {
        $stage = 'Final tree verification';
        $status = $application['completion_status'] === 'follow_up' ? 'Follow-up required' : 'Awaiting EMS verification';
        $tone = $application['completion_status'] === 'follow_up' ? 'warning' : 'info';
    } elseif ($app === 'released' || $release === 'released') {
        [$stage, $status, $tone] = ['Permit release', 'Released', 'success'];
        $statusClass = 'text-success';
    } elseif ($app === 'ready_for_release' || in_array($release, ['preparing', 'ready', 'withheld'], true)) {
        $stage = 'Permit release';
        $status = match ($release) {
            'preparing' => 'Preparing',
            'withheld' => 'Withheld',
            default => 'Ready for release',
        };
        $tone = 'info';
        if ($release === 'withheld') { $statusClass = 'text-danger'; }
    } elseif ($app === 'awaiting_final_verification' || $donation === 'ems_verified') {
        [$stage, $status, $tone] = ['Final verification', 'Pending RPS confirmation', 'info'];
    } elseif ($decision === 'approved' || in_array($app, ['approved', 'awaiting_donation'], true)) {
        $stage = 'Seedling donation';
        $status = match ($donation) {
            'partially_received' => 'Partially received',
            'flagged' => 'Flagged for review',
            'rps_verified' => 'Verified by RPS',
            'waived' => 'Waived',
            default => 'Awaiting receipt',
        };
        $tone = 'success';
        if ($donation === 'flagged') { $statusClass = 'text-danger'; }
    } elseif ($decision === 'returned' || $app === 'awaiting_documents') {
        [$stage, $status, $tone] = ['Document verification', 'Additional requirements requested', 'warning'];
    } elseif ($app !== 'draft' && ($app !== 'submitted' || $documents !== 'pending'
        || $inspection !== 'pending_assessment' || $decision !== 'pending')) {
        if ($documents !== 'verified') {
            $stage = 'Document verification';
            $status = match ($documents) {
                'online_verified' => 'Originals pending',
                'originals_verified' => 'Originals verified',
                'incomplete' => 'Incomplete',
                'under_review' => 'Under review',
                'waived' => 'Waived',
                default => 'Pending review',
            };
        } elseif (!in_array($inspection, ['passed', 'not_required'], true)) {
            $stage = 'Site inspection';
            $status = permit_status_label($inspection);
            $tone = 'warning';
            if ($inspection === 'failed') { $statusClass = 'text-danger'; }
        } else {
            $stage = 'RPS decision';
            $status = $decision === 'under_review' ? 'Under review' : 'Pending decision';
        }
    }

    return [
        'stage' => $stage,
        'status' => $status,
        'badge_class' => 'permit-stage-badge permit-stage-' . $tone,
        'status_class' => 'permit-stage-status' . match ($statusClass) {
            'text-success' => ' permit-stage-status-success',
            'text-danger' => ' permit-stage-status-danger',
            default => '',
        },
    ];
}

/** Summary-card category; terminal/released records remain in Total only. */
function permit_summary_category(array $application): string
{
    $summary = permit_stage_status($application);
    if (in_array($summary['stage'], ['Submission', 'Document verification', 'RPS decision'], true)
        && $summary['status'] !== 'Declined') {
        return 'review';
    }
    if ($summary['stage'] === 'Site inspection') {
        return 'inspection';
    }
    if ($summary['stage'] === 'Seedling donation'
        && !in_array($summary['status'], ['Verified by RPS', 'Waived'], true)) {
        return 'donation';
    }
    return 'all';
}

/**
 * Simplified public "where is my application" tracker, collapsing the full
 * workflow sub-statuses into 6 delivery-style steps. Returns a list of
 * ['label' => string, 'icon' => bootstrap-icon class, 'state' => one of
 * done|current|upcoming|failed|expired|skipped].
 */
function permit_status_tracker_steps(array $application): array
{
    $decision = (string) ($application['decision_status'] ?? 'pending');
    $donation = (string) ($application['donation_status'] ?? 'not_required');
    $release = (string) ($application['release_status'] ?? 'not_ready');
    $validity = (string) ($application['validity_status'] ?? 'not_issued');
    $declined = $decision === 'declined';

    $reviewState = in_array($decision, ['pending', 'under_review', 'returned'], true) ? 'current' : 'done';
    $decisionState = $declined ? 'failed' : ($decision === 'approved' ? 'done' : 'upcoming');

    if ($declined) {
        $donationState = 'skipped';
        $releaseState = 'skipped';
        $completedState = 'skipped';
    } else {
        $donationState = $decision !== 'approved'
            ? 'upcoming'
            : ($donation === 'rps_verified' ? 'done' : 'current');
        $releaseState = $donationState !== 'done'
            ? 'upcoming'
            : ($release === 'released' ? 'done' : 'current');
        $completedState = $releaseState !== 'done'
            ? 'upcoming'
            : match ($validity) {
                'completed' => 'done',
                'expired', 'closed' => 'expired',
                default => 'current',
            };
    }

    return [
        ['label' => 'Submitted', 'icon' => 'bi-send-check', 'state' => 'done'],
        ['label' => 'Under Review', 'icon' => 'bi-search', 'state' => $reviewState],
        ['label' => $declined ? 'Declined' : 'Decision', 'icon' => $declined ? 'bi-x-circle' : 'bi-clipboard-check', 'state' => $decisionState],
        ['label' => 'Donation Verified', 'icon' => 'bi-tree', 'state' => $donationState],
        ['label' => 'Permit Released', 'icon' => 'bi-file-earmark-check', 'state' => $releaseState],
        ['label' => 'Completed', 'icon' => 'bi-check-circle', 'state' => $completedState],
    ];
}
