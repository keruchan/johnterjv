<?php
require_once __DIR__ . '/permit_workflow.php';
require_once __DIR__ . '/view.php';

function permit_next_steps(array $application): array
{
    $stage = permit_stage_status($application)['stage'];
    if (($application['application_status'] ?? '') === 'draft') {
        return ['Client: complete the application, save the draft, and attach the required documents.', 'Submit the application when all required fields and attachments are ready. RPS review starts after submission.'];
    }
    if (($application['donation_status'] ?? '') === 'rps_verified'
        && !in_array($application['application_status'] ?? '', ['released','completed','closed'], true)
        && ($application['validity_status'] ?? '') !== 'expired'
        && ($application['release_status'] ?? '') !== 'withheld') {
        return ['Donation completion has been confirmed by RPS.', 'RPS prepares the signed permit. Follow the recorded release and pickup instructions.'];
    }
    return match ($stage) {
        'Document verification'=>['Client: read the remarks beside each document and replace any scan that needs correction.', 'RPS: review the submitted scans, then verify the required original documents and fee receipt.'],
        'Submission'=>['RPS reviews the submitted application and documents.', 'Client: keep the transaction ID and check the document remarks and status updates.'],
        'Site inspection'=>['RPS schedules or conducts the site visit and records the tree findings.', 'RPS uploads the Excel tally. Replacement quantities are calculated from the validated tree categories.'],
        'RPS decision'=>($application['decision_status'] ?? '') === 'declined'
            ? ['The application was declined. Read the recorded reason and remarks.', 'Client: contact RPS for clarification about any further request.']
            : ['RPS checks the document review, inspection findings, and tally before deciding.', 'Client: wait for the recorded decision and any instructions for additional requirements.'],
        'Seedling donation'=>['Client: review the required quantity and suggested donation species, then coordinate delivery with EMS.', 'EMS records and verifies the seedlings received. RPS then confirms donation completion.'],
        'Final verification'=>['EMS has verified the donation. RPS must confirm donation completion.', 'RPS prepares the permit for release after confirming compliance.'],
        'Final tree verification'=>($application['completion_status'] ?? '') === 'follow_up'
            ? ['Client: review the EMS findings and resolve the outstanding issues, then submit a new completion report.', 'EMS: verify the follow-up report and document the resolution before final closure.']
            : ['Your completion report is awaiting EMS verification.', 'EMS checks whether all approved trees were cut and whether additional trees were affected. Final closure follows resolved findings.'],
        'Permit release'=>($application['release_status'] ?? '') === 'released'
            ? ['Client: review the permit validity dates, approved trees, and conditions. After cutting, submit Mark as done with the completion details.', 'EMS performs final verification. Uncut approved trees or additional affected trees require follow-up before closure.']
            : ['RPS prepares and releases the signed permit. Read any recorded withholding remarks.', 'Client: follow the pickup instructions and wait for the recorded release before cutting.'],
        'Permit validity'=>['Review the recorded permit validity status and dates.', 'Client: contact RPS about an expired permit or any unresolved completion findings.'],
        'Cutting completion'=>['Cutting completion has been recorded. Review the verification findings and remarks.', 'Keep the transaction and permit records for reference.'],
        default=>['Review the current status and recorded remarks.', 'Contact RPS for guidance on the next action.'],
    };
}

function render_permit_next_steps(array $application): void
{
    echo '<section class="docket-panel mb-3" aria-label="Next steps"><div class="section-heading"><h2>Next Steps</h2></div><ol class="mb-0">';
    foreach (permit_next_steps($application) as $instruction) { echo '<li>'.e($instruction).'</li>'; }
    echo '</ol></section>';
}

function permit_reason_options(string $kind): array
{
    return $kind === 'decline' ? [
        'incomplete_documents'=>'Required documents remain incomplete',
        'unverified_authority'=>'Ownership or authorization could not be verified',
        'unverified_details'=>'Application details could not be verified',
        'site_findings'=>'Site assessment did not meet the applicable requirements',
        'outside_scope'=>'Proposed activity is outside the applicable processing scope',
        'other'=>'Other',
    ] : [
        'unreadable'=>'Unclear or unreadable scan', 'missing_pages'=>'Incomplete document or missing pages',
        'wrong_document'=>'Incorrect document uploaded', 'details_mismatch'=>'Document details do not match the application',
        'missing_signature'=>'Required signature or certification is missing',
        'validity_clarification'=>'Document validity needs clarification',
        'original_review'=>'Corrected version required after original-document review', 'other'=>'Other',
    ];
}

function permit_reason_with_remarks(string $kind, mixed $reason, mixed $remarks): string
{
    if (!is_string($reason) || !is_string($remarks)) { throw new InvalidArgumentException('Select a reason and provide valid remarks.'); }
    $reason = trim($reason); $remarks = trim($remarks);
    // Existing service integrations may still supply a complete free-text reason.
    if ($reason === '') { return $remarks; }
    $options = permit_reason_options($kind);
    if (!isset($options[$reason])) { throw new InvalidArgumentException('Select a valid reason.'); }
    if ($reason === 'other' && $remarks === '') { throw new InvalidArgumentException('Explain the reason when selecting Other.'); }
    $result = $options[$reason] . ($remarks !== '' ? ': '.$remarks : '');
    if (strlen($result) > 1000) { throw new InvalidArgumentException('Keep the reason and remarks within 1,000 characters.'); }
    return $result;
}
