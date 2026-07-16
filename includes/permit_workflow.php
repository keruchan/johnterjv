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
