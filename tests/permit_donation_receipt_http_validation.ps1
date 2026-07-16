$ErrorActionPreference = 'Stop'

function Assert-Check([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Output "PASS: $Message"
}

function Invoke-Login([string] $Username, [string] $CookiePath) {
    $html = (& curl.exe -s -c $CookiePath "$script:baseUrl/pages/auth/login.php") -join "`n"
    $match = [regex]::Match($html, 'name="csrf_token" value="([^"]+)"')
    if (-not $match.Success) { throw "Unable to extract login token for $Username" }
    $headers = (& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        --data-urlencode "csrf_token=$($match.Groups[1].Value)" `
        --data-urlencode "login_identifier=$Username" `
        --data-urlencode "password=$script:password" `
        "$script:baseUrl/pages/auth/login.php") -join "`n"
    if ($headers -notmatch 'HTTP/1\.1 302') { throw "Login failed for $Username" }
}

function Get-ReceiptForm([string] $CookiePath, [int] $ApplicationId, [int] $EditReceiptId = 0) {
    $url = "$script:baseUrl/pages/ems/donation-requirements.php?application_id=$ApplicationId"
    if ($EditReceiptId -gt 0) { $url += "&edit_receipt=$EditReceiptId" }
    $html = ((& curl.exe -s -b $CookiePath $url) -join "`n")
    $form = [regex]::Match($html, '<form method="post" action="donation-receipt-action\.php" id="donation-receipt-form".*?</form>', 'Singleline')
    if (-not $form.Success) {
        $preview = ([regex]::Replace($html, '<[^>]+>', ' ') -replace '\s+', ' ').Trim()
        if ($preview.Length -gt 500) { $preview = $preview.Substring(0, 500) }
        $markers = @()
        foreach ($marker in @('Donation Receipt','Receipt entry is locked','selected donation requirement','Unable to load','Record receipt')) {
            if ($html -match [regex]::Escape($marker)) { $markers += $marker }
        }
        throw "Unable to find receipt form for application $ApplicationId. Markers: $($markers -join ', '). Page preview: $preview"
    }
    $csrf = [regex]::Match($form.Value, 'name="csrf_token" value="([^"]+)"')
    $key = [regex]::Match($form.Value, 'name="action_key" value="([a-f0-9]{64})"')
    if (-not $csrf.Success -or -not $key.Success) { throw 'Unable to extract receipt action tokens.' }
    return @{ Html = $html; Csrf = $csrf.Groups[1].Value; ActionKey = $key.Groups[1].Value }
}

function Invoke-ReceiptAction(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $Csrf,
    [string] $ActionKey,
    [string] $Action,
    [array] $Items,
    [int] $ReceiverId,
    [string] $Reference,
    [string] $Remarks,
    [int] $ExpectedReceiptId = 0,
    [bool] $ConfirmPhysical = $false,
    [bool] $ConfirmOverage = $false
) {
    $arguments = @('-s','-D','-','-o','NUL','-b',$CookiePath,'-c',$CookiePath,
        '--data-urlencode',"csrf_token=$Csrf",'--data-urlencode',"action_key=$ActionKey",
        '--data-urlencode',"application_id=$ApplicationId",'--data-urlencode',"action=$Action",
        '--data-urlencode',"expected_verification_id=$ExpectedReceiptId",
        '--data-urlencode',"received_by_user_id=$ReceiverId",'--data-urlencode',"receipt_reference=$Reference",
        '--data-urlencode',"received_on=$((Get-Date).ToString('yyyy-MM-dd'))",
        '--data-urlencode',"verification_notes=$Remarks")
    foreach ($item in $Items) {
        $arguments += @('--data-urlencode', "seedling_type[]=$($item.Type)")
        $arguments += @('--data-urlencode', "quantity_received[]=$($item.Quantity)")
    }
    if ($ConfirmPhysical) { $arguments += @('--data-urlencode', 'confirm_physical_receipt=1') }
    if ($ConfirmOverage) { $arguments += @('--data-urlencode', 'confirm_overage=1') }
    $arguments += "$script:baseUrl/pages/ems/donation-receipt-action.php"
    return ((& curl.exe @arguments) -join "`n")
}

function New-ApprovedDonationApplication(
    [string] $TransactionId,
    [int] $OwnerId,
    [int] $RpsId,
    [int] $RequiredTotal,
    [string] $Classification = 'private_property'
) {
    $key = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    $policyCode = if ($Classification -eq 'public_domain') { 'property_public_domain' } else { 'property_private_property' }
    $sql = @"
INSERT INTO tbl_permit_applications
(transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at)
VALUES
('$TransactionId','$key',$OwnerId,'Donation Validation Applicant','09173000001','Applicant Address','individual','owner','$Classification','Donation Validation Applicant','Validation Property','District 3','Poblacion','Sta. Cruz','Laguna','Donation receipt validation','awaiting_donation','verified','not_required','approved','required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
"@
    & $script:mysql -u root certreefy_db -e $sql | Out-Null
    $applicationId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$TransactionId'")
    $script:applicationIds += $applicationId
    & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_decisions (application_id,decided_by_user_id,decision,decision_notes,approved_tree_count,property_classification,donation_seedling_count) VALUES ($applicationId,$RpsId,'approved','Donation workflow test approval',2,'$Classification',$RequiredTotal)" | Out-Null
    $decisionId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$applicationId AND decision='approved'")
    & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_donation_requirements (application_id,approval_decision_id,property_classification,policy_code,policy_version,required_seedling_count,received_seedling_count,requirement_basis,applicant_instructions,imposed_by_user_id,current_status) VALUES ($applicationId,$decisionId,'$Classification','$policyCode','validation',$RequiredTotal,0,'Validation policy snapshot','Coordinate with EMS using the transaction ID.',$RpsId,'required')" | Out-Null
    return $applicationId
}

$script:baseUrl = 'http://127.0.0.1/Certreefy'
$script:php = 'C:\xampp\php\php.exe'
$script:mysql = 'C:\xampp\mysql\bin\mysql.exe'
$script:password = 'DonationValidation123!'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$ownerUsername = "donation_owner_$suffix"
$otherUsername = "donation_other_$suffix"
$rpsUsername = "donation_rps_$suffix"
$emsUsername = "donation_ems_$suffix"
$cookies = @{}
foreach ($name in @('owner','other','rps','ems')) {
    $cookies[$name] = Join-Path $env:TEMP "certreefy_donation_${name}_$suffix.cookies"
}
$script:applicationIds = @()
$userIds = @()
$triggerName = "trg_donation_rollback_$suffix"

try {
    $hash = & $script:php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $script:password
    $insertUsers = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('Donation','Owner','$ownerUsername@example.test','09173000001','Test Address','$ownerUsername','$hash','community','active'),
('Donation','Other','$otherUsername@example.test','09173000002','Test Address','$otherUsername','$hash','community','active'),
('Donation','RPS','$rpsUsername@example.test','09173000003','Test Address','$rpsUsername','$hash','rps','active'),
('Donation','EMS','$emsUsername@example.test','09173000004','Test Address','$emsUsername','$hash','ems','active');
"@
    & $script:mysql -u root certreefy_db -e $insertUsers | Out-Null
    $ownerId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$ownerUsername'")
    $otherId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$otherUsername'")
    $rpsId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsUsername'")
    $emsId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$emsUsername'")
    $userIds = @($ownerId,$otherId,$rpsId,$emsId)

    $base = Get-Random -Minimum 700000 -Maximum 899980
    $partialTransaction = 'TCP-2097-' + ($base + 1).ToString('000000')
    $historyTransaction = 'TCP-2097-' + ($base + 2).ToString('000000')
    $overageTransaction = 'TCP-2097-' + ($base + 3).ToString('000000')
    $rollbackTransaction = 'TCP-2097-' + ($base + 4).ToString('000000')
    $unapprovedTransaction = 'TCP-2097-' + ($base + 5).ToString('000000')
    $flagTransaction = 'TCP-2097-' + ($base + 6).ToString('000000')
    $partialApp = New-ApprovedDonationApplication $partialTransaction $ownerId $rpsId 50
    $historyApp = New-ApprovedDonationApplication $historyTransaction $ownerId $rpsId 100 'public_domain'
    $overageApp = New-ApprovedDonationApplication $overageTransaction $ownerId $rpsId 50
    $rollbackApp = New-ApprovedDonationApplication $rollbackTransaction $ownerId $rpsId 50
    $flagApp = New-ApprovedDonationApplication $flagTransaction $ownerId $rpsId 50

    $unapprovedKey = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_applications (transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at) VALUES ('$unapprovedTransaction','$unapprovedKey',$ownerId,'Unapproved Applicant','09173000001','Address','individual','owner','private_property','Unapproved Applicant','Property','District 3','Poblacion','Sta. Cruz','Laguna','Validation','submitted','pending','pending_assessment','pending','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)" | Out-Null
    $unapprovedApp = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$unapprovedTransaction'")
    $script:applicationIds += $unapprovedApp

    Invoke-Login $ownerUsername $cookies.owner
    Invoke-Login $otherUsername $cookies.other
    Invoke-Login $rpsUsername $cookies.rps
    Invoke-Login $emsUsername $cookies.ems

    $registry = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?transaction=$partialTransaction") -join "`n")
    Assert-Check ($registry -match [regex]::Escape($partialTransaction) -and $registry -match 'primary reference' -and $registry -match 'table-responsive') 'EMS can locate an eligible requirement by primary transaction ID in the existing responsive table design.'
    $invalidSearch = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?transaction=TCP-0000-000000") -join "`n")
    Assert-Check ($invalidSearch -match 'No matching donation requirements') 'An invalid transaction ID returns no eligible requirement.'
    $applicantSearch = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?applicant=Donation%20Validation&application_reference=$partialApp&donation_status=required") -join "`n")
    Assert-Check ($applicantSearch -match [regex]::Escape($partialTransaction) -and $applicantSearch -notmatch [regex]::Escape($historyTransaction)) 'Applicant, application reference, and donation status filters are server-applied.'
    $today = (Get-Date).ToString('yyyy-MM-dd')
    $dateSearch = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?transaction=$partialTransaction&date_from=$today&date_to=$today") -join "`n")
    Assert-Check ($dateSearch -match [regex]::Escape($partialTransaction)) 'Requirement date-range filtering retains an eligible transaction in range.'

    $communityActionStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $cookies.owner "$script:baseUrl/pages/ems/donation-receipt-action.php"
    $rpsActionStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $cookies.rps "$script:baseUrl/pages/ems/donation-receipt-action.php"
    Assert-Check ($communityActionStatus -eq '302' -and $rpsActionStatus -eq '302') 'Community and RPS Users are denied the EMS receipt endpoint by server role authorization.'

    $form = Get-ReceiptForm $cookies.ems $partialApp
    $unapproved = Invoke-ReceiptAction $cookies.ems $unapprovedApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='50'}) $emsId 'UNAPPROVED-1' 'Attempt unapproved transaction.' 0 $true
    $unapprovedCount = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$unapprovedApp")
    Assert-Check ($unapproved -match 'HTTP/1\.1 303' -and $unapprovedCount -eq 0) 'An unapproved application cannot receive or verify a donation through the EMS endpoint.'
    $genericBypass = & $script:php tests/permit_donation_status_probe.php $partialApp $emsId
    Assert-Check ($genericBypass -match 'donation-receipt workflow') 'The generic status writer cannot bypass receipt totals, items, history, audit, and notification rules.'

    $form = Get-ReceiptForm $cookies.ems $partialApp
    Invoke-ReceiptAction $cookies.ems $partialApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='20'},@{Type='Molave';Quantity='10'}) $emsId 'PARTIAL-1' 'First partial physical receipt.' 0 $true | Out-Null
    $partialState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(a.application_status,':',a.donation_status,':',r.current_status,':',r.received_seedling_count,':',(SELECT COUNT(*) FROM tbl_permit_donation_verification_items i INNER JOIN tbl_permit_donation_verifications v ON v.id=i.donation_verification_id WHERE v.donation_requirement_id=r.id)) FROM tbl_permit_applications a INNER JOIN tbl_permit_donation_requirements r ON r.application_id=a.id WHERE a.id=$partialApp"
    Assert-Check ($partialState -eq 'awaiting_donation:partially_received:partially_received:30:2') 'A multi-species partial receipt records per-item quantities, cumulative total, and remaining incomplete state.'
    $partialPage = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?application_id=$partialApp") -join "`n")
    Assert-Check ($partialPage -match 'Remaining</div><div class="fs-5 fw-semibold">20' -and $partialPage -match 'PARTIAL-1') 'EMS detail displays the remaining quantity and finalized receipt history.'

    $form = Get-ReceiptForm $cookies.ems $partialApp
    Invoke-ReceiptAction $cookies.ems $partialApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Kamagong';Quantity='20'}) $emsId 'FULL-2' 'Completed the physical requirement.' 0 $true | Out-Null
    $fullState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(a.application_status,':',a.donation_status,':',r.current_status,':',r.received_seedling_count,':',(SELECT COUNT(*) FROM tbl_permits p WHERE p.application_id=a.id)) FROM tbl_permit_applications a INNER JOIN tbl_permit_donation_requirements r ON r.application_id=a.id WHERE a.id=$partialApp"
    Assert-Check ($fullState -eq 'awaiting_final_verification:ems_verified:ems_verified:50:0') 'A full verified receipt advances only to final RPS verification and does not create or release a permit.'
    $communityDonationView = ((& curl.exe -s -b $cookies.owner "$script:baseUrl/pages/community/permit-application.php?id=$partialApp") -join "`n")
    Assert-Check ($communityDonationView -match 'EMS Verified' -and $communityDonationView -match 'Final RPS confirmation' -and $communityDonationView -match 'Currently received</div><div class="fs-5 fw-semibold">50') 'The Community owner sees the verified total and the remaining final-RPS requirement without a release claim.'
    $notifications = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id=$partialApp AND notification_type='donation_verification' AND recipient_user_id IN ($ownerId,$rpsId)")
    $auditAndHistory = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT((SELECT COUNT(*) FROM tbl_audit_trail WHERE entity_type='permit_donation_verification' AND action IN ('donation_receipt_partial_recorded','donation_receipt_ems_verified') AND JSON_EXTRACT(details,'$.application_id')=$partialApp),':',(SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$partialApp AND status_domain IN ('donation','application')))"
    Assert-Check ($notifications -ge 4 -and $auditAndHistory -match '2:[4-9]') 'Partial/full verification creates Community and RPS notifications plus audit and status history records.'

    $verificationCountBefore = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$partialApp")
    $lockedPage = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?application_id=$partialApp") -join "`n")
    Assert-Check ($lockedPage -match 'Receipt entry is locked') 'A fully EMS-verified transaction no longer exposes receipt mutation controls.'
    $otherForm = Get-ReceiptForm $cookies.ems $historyApp
    Invoke-ReceiptAction $cookies.ems $partialApp $otherForm.Csrf $otherForm.ActionKey 'finalize' @(@{Type='Narra';Quantity='1'}) $emsId 'DUPLICATE-FULL' 'Duplicate verification attempt.' 0 $true | Out-Null
    $verificationCountAfter = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$partialApp")
    Assert-Check ($verificationCountAfter -eq $verificationCountBefore) 'A duplicate final-verification attempt cannot add another receipt.'

    $form = Get-ReceiptForm $cookies.ems $historyApp
    Invoke-ReceiptAction $cookies.ems $historyApp $form.Csrf $form.ActionKey 'save_draft' @(@{Type='Narra';Quantity='40'}) $emsId 'DRAFT-1' 'Unfinalized receipt.' | Out-Null
    $draftId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT v.id FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$historyApp AND v.is_current=1 AND v.is_finalized=0")
    $draftState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(r.received_seedling_count,':',r.current_status,':',v.verification_status) FROM tbl_permit_donation_requirements r INNER JOIN tbl_permit_donation_verifications v ON v.donation_requirement_id=r.id AND v.id=$draftId WHERE r.application_id=$historyApp"
    Assert-Check ($draftState -eq '0:pending:draft') 'Saving an unfinalized receipt does not count it toward the physical total.'
    $form = Get-ReceiptForm $cookies.ems $historyApp $draftId
    Invoke-ReceiptAction $cookies.ems $historyApp $form.Csrf $form.ActionKey 'save_draft' @(@{Type='Narra';Quantity='35'},@{Type='Molave';Quantity='25'}) $emsId 'DRAFT-1-CORRECTED' 'Corrected before finalization.' $draftId | Out-Null
    $currentDraftId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT v.id FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$historyApp AND v.is_current=1 AND v.is_finalized=0")
    $historyState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(COUNT(*),':',SUM(is_current),':',COUNT(DISTINCT receipt_group_key),':',MAX(version_number)) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$historyApp"
    Assert-Check ($historyState -eq '2:1:1:2' -and $currentDraftId -ne $draftId) 'Correcting an unfinalized receipt appends a new version and preserves the superseded history.'
    $form = Get-ReceiptForm $cookies.ems $historyApp $currentDraftId
    Invoke-ReceiptAction $cookies.ems $historyApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='35'},@{Type='Molave';Quantity='25'}) $emsId 'DRAFT-1-CORRECTED' 'Finalize corrected partial batch.' $currentDraftId $true | Out-Null
    $correctedPartial = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(received_seedling_count,':',current_status) FROM tbl_permit_donation_requirements WHERE application_id=$historyApp"
    Assert-Check ($correctedPartial -eq '60:partially_received') 'Finalizing a corrected draft counts only the current version once.'

    $form = Get-ReceiptForm $cookies.ems $overageApp
    Invoke-ReceiptAction $cookies.ems $overageApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='60'}) $emsId 'OVERAGE-1' 'Overage without confirmation.' 0 $true | Out-Null
    $overageRejected = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(r.received_seedling_count,':',r.current_status,':',(SELECT COUNT(*) FROM tbl_permit_donation_verifications v WHERE v.donation_requirement_id=r.id)) FROM tbl_permit_donation_requirements r WHERE r.application_id=$overageApp"
    Assert-Check ($overageRejected -eq '0:required:0') 'Over-receipt without explicit confirmation rolls back every receipt and status write.'
    $form = Get-ReceiptForm $cookies.ems $overageApp
    Invoke-ReceiptAction $cookies.ems $overageApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='60'}) $emsId 'OVERAGE-1' 'Confirmed physical over-receipt.' 0 $true $true | Out-Null
    $overageAccepted = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(received_seedling_count,':',current_status) FROM tbl_permit_donation_requirements WHERE application_id=$overageApp"
    Assert-Check ($overageAccepted -eq '60:ems_verified') 'An authorized EMS User can explicitly confirm and finalize an over-receipt.'

    $invalidQuantityApp = $rollbackApp
    $invalidQuantityPreState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',donation_status,':',(SELECT current_status FROM tbl_permit_donation_requirements WHERE application_id=$invalidQuantityApp)) FROM tbl_permit_applications WHERE id=$invalidQuantityApp"
    Assert-Check ($invalidQuantityPreState -eq 'awaiting_donation:approved:required:required') 'The untouched rollback fixture remains eligible before invalid-quantity validation.'
    $form = Get-ReceiptForm $cookies.ems $invalidQuantityApp
    $rowsBefore = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$invalidQuantityApp")
    Invoke-ReceiptAction $cookies.ems $invalidQuantityApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Kamagong';Quantity='0'}) $emsId 'ZERO-1' 'Invalid zero quantity.' 0 $true | Out-Null
    $rowsAfter = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_verifications v INNER JOIN tbl_permit_donation_requirements r ON r.id=v.donation_requirement_id WHERE r.application_id=$invalidQuantityApp")
    Assert-Check ($rowsAfter -eq $rowsBefore) 'Zero receipt quantities are rejected without a partial write.'

    $form = Get-ReceiptForm $cookies.ems $flagApp
    Invoke-ReceiptAction $cookies.ems $flagApp $form.Csrf $form.ActionKey 'flag_invalid' @(@{Type='Narra';Quantity='1'}) $emsId '' 'Receipt reference could not be validated against the approved transaction.' | Out-Null
    $flagFeedback = ((& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-requirements.php?application_id=$flagApp") -join "`n")
    $flagAlert = [regex]::Match($flagFeedback, '<div class="alert alert-[^"]+ alert-dismissible[^>]*>(.*?)<button', 'Singleline')
    $flagMessage = if ($flagAlert.Success) { ([regex]::Replace($flagAlert.Groups[1].Value, '<[^>]+>', '') -replace '\s+', ' ').Trim() } else { 'no flash' }
    $flagState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(a.application_status,':',a.donation_status,':',r.current_status,':',r.received_seedling_count,':',(SELECT verification_status FROM tbl_permit_donation_verifications v WHERE v.donation_requirement_id=r.id ORDER BY id DESC LIMIT 1)) FROM tbl_permit_applications a INNER JOIN tbl_permit_donation_requirements r ON r.application_id=a.id WHERE a.id=$flagApp"
    $flagEvents = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT((SELECT COUNT(*) FROM tbl_audit_trail WHERE action='donation_transaction_flagged' AND JSON_EXTRACT(details,'$.application_id')=$flagApp),':',(SELECT COUNT(*) FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id=$flagApp AND notification_type='donation_verification'))"
    Assert-Check ($flagState -eq 'awaiting_donation:flagged:flagged:0:flagged' -and $flagEvents -match '1:[2-9]' -and $flagMessage -match 'blocked from release') 'EMS can flag an invalid transaction with required remarks, preserved history, audit, and notifications while keeping it blocked.'

    $form = Get-ReceiptForm $cookies.ems $rollbackApp
    & $script:mysql -u root certreefy_db -e "CREATE TRIGGER $triggerName BEFORE UPDATE ON tbl_permit_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced donation rollback'" | Out-Null
    Invoke-ReceiptAction $cookies.ems $rollbackApp $form.Csrf $form.ActionKey 'finalize' @(@{Type='Narra';Quantity='50'}) $emsId 'ROLLBACK-1' 'Force transaction rollback.' 0 $true | Out-Null
    & $script:mysql -u root certreefy_db -e "DROP TRIGGER IF EXISTS $triggerName" | Out-Null
    $rollbackState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(r.received_seedling_count,':',r.current_status,':',(SELECT COUNT(*) FROM tbl_permit_donation_verifications v WHERE v.donation_requirement_id=r.id),':',(SELECT COUNT(*) FROM tbl_audit_trail au WHERE JSON_EXTRACT(au.details,'$.application_id')=$rollbackApp),':',(SELECT COUNT(*) FROM tbl_notifications n WHERE n.entity_type='permit_application' AND n.entity_id=$rollbackApp)) FROM tbl_permit_donation_requirements r WHERE r.application_id=$rollbackApp"
    Assert-Check ($rollbackState -eq '0:required:0:0:0') 'A forced downstream failure rolls back receipt, totals, statuses, audit, and notifications atomically.'

    Write-Output 'EMS donation receipt and verification HTTP validation completed.'
}
finally {
    & $script:mysql -u root certreefy_db -e "DROP TRIGGER IF EXISTS $triggerName" 2>$null | Out-Null
    if ($script:applicationIds.Count -gt 0) {
        $appList = ($script:applicationIds -join ',')
        & $script:mysql -u root certreefy_db -e "DELETE FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id IN ($appList); DELETE FROM tbl_audit_trail WHERE entity_type IN ('permit_donation_verification','permit_application') AND (entity_id IN (SELECT id FROM tbl_permit_donation_verifications WHERE donation_requirement_id IN (SELECT id FROM tbl_permit_donation_requirements WHERE application_id IN ($appList))) OR JSON_EXTRACT(details,'$.application_id') IN ($appList)); DELETE FROM tbl_permit_status_history WHERE application_id IN ($appList); DELETE FROM tbl_permit_donation_verification_items WHERE donation_verification_id IN (SELECT id FROM tbl_permit_donation_verifications WHERE donation_requirement_id IN (SELECT id FROM tbl_permit_donation_requirements WHERE application_id IN ($appList))); UPDATE tbl_permit_donation_verifications SET previous_verification_id=NULL WHERE donation_requirement_id IN (SELECT id FROM tbl_permit_donation_requirements WHERE application_id IN ($appList)); DELETE FROM tbl_permit_donation_verifications WHERE donation_requirement_id IN (SELECT id FROM tbl_permit_donation_requirements WHERE application_id IN ($appList)); DELETE FROM tbl_permit_donation_requirements WHERE application_id IN ($appList); DELETE FROM tbl_permit_decisions WHERE application_id IN ($appList); DELETE FROM tbl_permit_trees WHERE application_id IN ($appList); DELETE FROM tbl_permit_applications WHERE id IN ($appList);" 2>$null | Out-Null
    }
    if ($userIds.Count -gt 0) {
        $userList = ($userIds -join ',')
        & $script:mysql -u root certreefy_db -e "DELETE FROM tbl_notifications WHERE recipient_user_id IN ($userList) OR created_by_user_id IN ($userList); DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($userList); DELETE FROM tbl_users WHERE id IN ($userList);" 2>$null | Out-Null
    }
    foreach ($cookie in $cookies.Values) { Remove-Item -LiteralPath $cookie -Force -ErrorAction SilentlyContinue }
}
