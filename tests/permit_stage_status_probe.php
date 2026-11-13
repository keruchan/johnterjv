<?php
/** Database-free checks for the staff list's current-stage presentation. */
require_once __DIR__ . '/../includes/permit_workflow.php';

$base = array_combine(
    array_map(static fn (string $domain): string => $domain . '_status', array_keys(permit_initial_statuses())),
    array_values(permit_initial_statuses())
);
$cases = [
    ['Fresh submission', [], 'Submission', 'Submitted'],
    ['Draft', ['application_status' => 'draft'], 'Submission', 'Draft'],
    ['Review started', ['application_status' => 'under_review'], 'Document verification', 'Pending review'],
    ['Scans reviewed before begin-review', ['document_status' => 'online_verified'], 'Document verification', 'Originals pending'],
    ['Missing documents', ['application_status' => 'under_review', 'document_status' => 'incomplete'], 'Document verification', 'Incomplete'],
    ['Returned application', ['application_status' => 'awaiting_documents', 'decision_status' => 'returned'], 'Document verification', 'Additional requirements requested'],
    ['Inspection assessment', ['application_status' => 'under_review', 'document_status' => 'verified'], 'Site inspection', 'Pending Assessment'],
    ['Scheduled inspection', ['application_status' => 'awaiting_inspection', 'document_status' => 'verified', 'inspection_status' => 'scheduled'], 'Site inspection', 'Scheduled'],
    ['Failed inspection', ['application_status' => 'awaiting_inspection', 'document_status' => 'verified', 'inspection_status' => 'failed'], 'Site inspection', 'Failed'],
    ['Decision waiting', ['application_status' => 'awaiting_decision', 'document_status' => 'verified', 'inspection_status' => 'passed'], 'RPS decision', 'Pending decision'],
    ['Decision review', ['application_status' => 'under_review', 'document_status' => 'verified', 'inspection_status' => 'not_required', 'decision_status' => 'under_review'], 'RPS decision', 'Under review'],
    ['Donation required', ['application_status' => 'awaiting_donation', 'decision_status' => 'approved', 'donation_status' => 'required'], 'Seedling donation', 'Awaiting receipt'],
    ['Partial donation', ['application_status' => 'awaiting_donation', 'decision_status' => 'approved', 'donation_status' => 'partially_received'], 'Seedling donation', 'Partially received'],
    ['Flagged donation', ['application_status' => 'awaiting_donation', 'decision_status' => 'approved', 'donation_status' => 'flagged'], 'Seedling donation', 'Flagged for review'],
    ['EMS handoff', ['application_status' => 'awaiting_final_verification', 'decision_status' => 'approved', 'donation_status' => 'ems_verified'], 'Final verification', 'Pending RPS confirmation'],
    ['Preparing release', ['application_status' => 'ready_for_release', 'decision_status' => 'approved', 'donation_status' => 'rps_verified', 'release_status' => 'preparing'], 'Permit release', 'Preparing'],
    ['Ready release', ['application_status' => 'ready_for_release', 'release_status' => 'ready'], 'Permit release', 'Ready for release'],
    ['Withheld release', ['application_status' => 'ready_for_release', 'release_status' => 'withheld'], 'Permit release', 'Withheld'],
    ['Released overrides approval history', ['application_status' => 'released', 'decision_status' => 'approved', 'release_status' => 'released', 'validity_status' => 'active'], 'Permit release', 'Released'],
    ['Expired overrides released history', ['application_status' => 'closed', 'decision_status' => 'approved', 'release_status' => 'released', 'validity_status' => 'expired'], 'Permit validity', 'Expired'],
    ['Completed overrides released history', ['application_status' => 'completed', 'decision_status' => 'approved', 'release_status' => 'released', 'validity_status' => 'completed'], 'Cutting completion', 'Completed'],
    ['Declined', ['application_status' => 'declined', 'decision_status' => 'declined'], 'RPS decision', 'Declined'],
    ['Closed', ['application_status' => 'closed'], 'Application closure', 'Closed'],
    ['Client reported completion', ['application_status'=>'released','release_status'=>'released','completion_status'=>'pending_ems'], 'Final tree verification', 'Awaiting EMS verification'],
    ['EMS follow-up findings', ['application_status'=>'released','release_status'=>'released','completion_status'=>'follow_up'], 'Final tree verification', 'Follow-up required'],
];
foreach ($cases as [$label, $changes, $stage, $status]) {
    $actual = permit_stage_status(array_replace($base, $changes));
    if ($actual['stage'] !== $stage || $actual['status'] !== $status) {
        throw new RuntimeException($label . ': ' . json_encode($actual));
    }
    echo 'PASS: ' . $label . PHP_EOL;
}
echo count($cases) . ' stage/status scenarios passed.' . PHP_EOL;
