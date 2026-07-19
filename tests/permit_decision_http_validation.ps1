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

function Get-RpsDetail([string] $CookiePath, [int] $ApplicationId) {
    return ((& curl.exe -s -b $CookiePath "$script:baseUrl/pages/cenro/permit-application.php?id=$ApplicationId") -join "`n")
}

function Get-DecisionToken([string] $Html) {
    $match = [regex]::Match($Html, 'action="permit-decision-action\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline')
    if (-not $match.Success) { throw 'Unable to extract permit-decision CSRF token.' }
    return $match.Groups[1].Value
}

function Invoke-DecisionAction(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $Token,
    [int] $ExpectedDecisionId,
    [string] $Action,
    [hashtable] $Fields
) {
    $arguments = @('-s','-D','-','-o','NUL','-b',$CookiePath,'-c',$CookiePath,
        '--data-urlencode',"csrf_token=$Token",'--data-urlencode',"application_id=$ApplicationId",
        '--data-urlencode',"expected_decision_id=$ExpectedDecisionId",'--data-urlencode',"action=$Action")
    foreach ($key in $Fields.Keys) { $arguments += @('--data-urlencode', "$key=$($Fields[$key])") }
    $arguments += "$script:baseUrl/pages/cenro/permit-decision-action.php"
    return ((& curl.exe @arguments) -join "`n")
}

function New-TestApplication(
    [string] $TransactionId,
    [int] $OwnerId,
    [int] $RpsId,
    [string] $ApplicationStatus,
    [string] $DocumentStatus,
    [string] $InspectionStatus,
    [string] $DecisionStatus,
    [bool] $WithVerifiedDocuments,
    [string] $PropertyClassification = 'private_property'
) {
    $key = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    $sql = @"
INSERT INTO tbl_permit_applications
(transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at)
VALUES
('$TransactionId','$key',$OwnerId,'Decision Owner','09173000001','Applicant Address','individual','owner','$PropertyClassification','Decision Owner','Decision Test Property','District 3','Poblacion','Sta. Cruz','Laguna','Decision workflow validation','$ApplicationStatus','$DocumentStatus','$InspectionStatus','$DecisionStatus','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
"@
    & $script:mysql -u root certreefy_db -e $sql | Out-Null
    $applicationId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$TransactionId'")
    $script:applicationIds += $applicationId
    & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_trees (application_id,common_name,scientific_name,quantity,diameter_cm,estimated_height_m) VALUES ($applicationId,'Narra','Pterocarpus indicus',3,30.50,7.25)" | Out-Null

    if ($WithVerifiedDocuments) {
        foreach ($type in @('application_request','applicant_identification','ownership_authorization','tree_location_photos')) {
            $storage = "2098/$TransactionId/$type.pdf"
            & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_documents (application_id,document_type,storage_path,original_filename,mime_type,file_size_bytes,uploaded_by_user_id,is_current,verification_status,verified_by_user_id,verified_at) VALUES ($applicationId,'$type','$storage','$type.pdf','application/pdf',128,$OwnerId,1,'accepted',$RpsId,CURRENT_TIMESTAMP)" | Out-Null
            $documentId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$applicationId AND document_type='$type' ORDER BY id DESC LIMIT 1")
            & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_permit_document_reviews (application_id,document_id,document_type,review_scope,review_status,original_received,original_received_on,received_by_user_id,wet_ink_required,wet_ink_verified,scan_compared_with_original,reviewed_by_user_id,review_notes) VALUES ($applicationId,$documentId,'$type','original','verified',1,CURRENT_DATE,$RpsId,1,1,1,$RpsId,'Verified test original')" | Out-Null
        }
    }
    return $applicationId
}

$script:baseUrl = 'http://127.0.0.1/Certreefy'
$script:password = 'DecisionValidation123!'
$script:php = 'C:\xampp\php\php.exe'
$script:mysql = 'C:\xampp\mysql\bin\mysql.exe'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$ownerUsername = "decision_owner_$suffix"
$otherUsername = "decision_other_$suffix"
$rpsUsername = "decision_rps_$suffix"
$authorizedSuperUsername = "decision_super_yes_$suffix"
$unauthorizedSuperUsername = "decision_super_no_$suffix"
$emsUsername = "decision_ems_$suffix"
$rpsDuplicateCookie = Join-Path $env:TEMP "certreefy_decision_rps_duplicate_$suffix.cookies"
$cookies = @{}
foreach ($name in @('owner','other','rps','superYes','superNo','ems')) {
    $cookies[$name] = Join-Path $env:TEMP "certreefy_decision_${name}_$suffix.cookies"
}
$allCookies = @($cookies.Values) + @($rpsDuplicateCookie)
$script:applicationIds = @()
$script:triggerName = "trg_test_donation_$suffix"
$userIds = @()

try {
    $hash = & $script:php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $script:password
    $insertUsers = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('Decision','Owner','$ownerUsername@example.test','09173000001','Test Address','$ownerUsername','$hash','community','active'),
('Decision','Other','$otherUsername@example.test','09173000002','Test Address','$otherUsername','$hash','community','active'),
('Decision','RPS','$rpsUsername@example.test','09173000003','Test Address','$rpsUsername','$hash','rps','active'),
('Decision','Authorized Super','$authorizedSuperUsername@example.test','09173000004','Test Address','$authorizedSuperUsername','$hash','superadmin','active'),
('Decision','Unauthorized Super','$unauthorizedSuperUsername@example.test','09173000005','Test Address','$unauthorizedSuperUsername','$hash','superadmin','active'),
('Decision','EMS','$emsUsername@example.test','09173000006','Test Address','$emsUsername','$hash','ems','active');
"@
    & $script:mysql -u root certreefy_db -e $insertUsers | Out-Null
    $ownerId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$ownerUsername'")
    $otherId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$otherUsername'")
    $rpsId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsUsername'")
    $authorizedSuperId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$authorizedSuperUsername'")
    $unauthorizedSuperId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$unauthorizedSuperUsername'")
    $emsId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$emsUsername'")
    $userIds = @($ownerId,$otherId,$rpsId,$authorizedSuperId,$unauthorizedSuperId,$emsId)
    & $script:mysql -u root certreefy_db -e "INSERT INTO tbl_user_permissions (user_id,permission_key,is_active,granted_by_user_id) VALUES ($authorizedSuperId,'permit_decision',1,$authorizedSuperId)" | Out-Null

    $base = Get-Random -Minimum 500000 -Maximum 899980
    $newTransaction = 'TCP-2098-' + ($base + 1).ToString('000000')
    $readyTransaction = 'TCP-2098-' + ($base + 2).ToString('000000')
    $missingTransaction = 'TCP-2098-' + ($base + 3).ToString('000000')
    $requirementsTransaction = 'TCP-2098-' + ($base + 4).ToString('000000')
    $inspectionTransaction = 'TCP-2098-' + ($base + 5).ToString('000000')
    $underReviewTransaction = 'TCP-2098-' + ($base + 6).ToString('000000')
    $declineTransaction = 'TCP-2098-' + ($base + 7).ToString('000000')
    $rollbackTransaction = 'TCP-2098-' + ($base + 8).ToString('000000')
    $superTransaction = 'TCP-2098-' + ($base + 9).ToString('000000')
    $publicTransaction = 'TCP-2098-' + ($base + 10).ToString('000000')

    $newApplicationId = New-TestApplication $newTransaction $ownerId $rpsId 'submitted' 'pending' 'pending_assessment' 'pending' $false
    $readyApplicationId = New-TestApplication $readyTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true
    $missingApplicationId = New-TestApplication $missingTransaction $ownerId $rpsId 'submitted' 'pending' 'not_required' 'pending' $false
    $requirementsApplicationId = New-TestApplication $requirementsTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true
    $inspectionApplicationId = New-TestApplication $inspectionTransaction $ownerId $rpsId 'under_review' 'verified' 'required' 'under_review' $true
    $underReviewApplicationId = New-TestApplication $underReviewTransaction $ownerId $rpsId 'under_review' 'verified' 'not_required' 'under_review' $true
    & $script:mysql -u root certreefy_db -e "UPDATE tbl_permit_applications SET applicant_contact=NULL WHERE id=$underReviewApplicationId" | Out-Null
    $declineApplicationId = New-TestApplication $declineTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true
    $rollbackApplicationId = New-TestApplication $rollbackTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true
    $superApplicationId = New-TestApplication $superTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true
    $publicApplicationId = New-TestApplication $publicTransaction $ownerId $rpsId 'submitted' 'verified' 'not_required' 'pending' $true 'public_domain'

    $unapprovedDonationProbe = & $script:php tests/permit_donation_creation_probe.php $newApplicationId $rpsId
    Assert-Check ($unapprovedDonationProbe -match 'only for an approved application' -and ([int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_requirements WHERE application_id=$newApplicationId")) -eq 0) 'An unapproved application cannot receive a donation requirement.'

    Invoke-Login $ownerUsername $cookies.owner
    Invoke-Login $otherUsername $cookies.other
    Invoke-Login $rpsUsername $cookies.rps
    Invoke-Login $rpsUsername $rpsDuplicateCookie
    Invoke-Login $authorizedSuperUsername $cookies.superYes
    Invoke-Login $unauthorizedSuperUsername $cookies.superNo
    Invoke-Login $emsUsername $cookies.ems

    $rpsPage = Get-RpsDetail $cookies.rps $readyApplicationId
    Assert-Check ($rpsPage -match 'RPS Review &amp; Decision' -and $rpsPage -match 'Ready-for-decision checks' -and $rpsPage -match 'table-responsive') 'RPS sees reusable review, readiness, history, and responsive table components.'
    $superPage = Get-RpsDetail $cookies.superYes $superApplicationId
    Assert-Check ($superPage -match 'Begin review' -and $superPage -match 'Permit Document Review') 'A specifically permitted Superadmin can review evidence and use decision controls.'
    $unauthorizedSuperStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $cookies.superNo "$script:baseUrl/pages/cenro/permit-application.php?id=$readyApplicationId"
    Assert-Check ($unauthorizedSuperStatus -eq '403') 'A Superadmin without permit-decision permission is denied.'
    $emsHeaders = (& curl.exe -s -D - -o NUL -b $cookies.ems "$script:baseUrl/pages/cenro/permit-decision-action.php") -join "`n"
    Assert-Check ($emsHeaders -match 'HTTP/1\.1 302') 'EMS is denied the RPS decision endpoint by role authorization.'
    $communityHeaders = (& curl.exe -s -D - -o NUL -b $cookies.owner "$script:baseUrl/pages/cenro/permit-decision-action.php") -join "`n"
    Assert-Check ($communityHeaders -match 'HTTP/1\.1 302') 'Community Users cannot perform decision actions.'
    $otherStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $cookies.other "$script:baseUrl/pages/community/permit-application.php?id=$readyApplicationId"
    Assert-Check ($otherStatus -eq '404') 'Community ownership checks hide another applicant application.'

    $token = Get-DecisionToken $rpsPage
    $begin = Invoke-DecisionAction $cookies.rps $readyApplicationId $token 0 'begin_review' @{}
    Assert-Check ($begin -match 'HTTP/1\.1 303') 'Beginning RPS review uses Post/Redirect/Get.'
    $beginState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',(SELECT decision FROM tbl_permit_decisions WHERE application_id=$readyApplicationId ORDER BY id DESC LIMIT 1)) FROM tbl_permit_applications WHERE id=$readyApplicationId"
    Assert-Check ($beginState -eq 'under_review:under_review:review_started') 'Begin review changes only the review/application workflow and appends a decision event.'
    $genericBypass = & $script:php tests/permit_decision_status_probe.php $readyApplicationId $rpsId
    Assert-Check ($genericBypass -match 'permit-decision workflow') 'The generic status service cannot bypass the transactional decision writer.'

    $requirementsPage = Get-RpsDetail $cookies.rps $requirementsApplicationId
    $token = Get-DecisionToken $requirementsPage
    Invoke-DecisionAction $cookies.rps $requirementsApplicationId $token 0 'begin_review' @{} | Out-Null
    $requirementsPage = Get-RpsDetail $cookies.rps $requirementsApplicationId
    $token = Get-DecisionToken $requirementsPage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$requirementsApplicationId ORDER BY id DESC LIMIT 1")
    $requested = Invoke-DecisionAction $cookies.rps $requirementsApplicationId $token $expectedId 'request_requirements' @{decision_notes='Submit the requested supplemental ownership certification.'}
    $requirementsState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status) FROM tbl_permit_applications WHERE id=$requirementsApplicationId"
    Assert-Check ($requested -match 'HTTP/1\.1 303' -and $requirementsState -eq 'awaiting_documents:returned') 'Additional requirements are recorded with remarks and return the application without losing history.'

    $missingPage = Get-RpsDetail $cookies.rps $missingApplicationId
    $token = Get-DecisionToken $missingPage
    Invoke-DecisionAction $cookies.rps $missingApplicationId $token 0 'begin_review' @{} | Out-Null
    $missingPage = Get-RpsDetail $cookies.rps $missingApplicationId
    $token = Get-DecisionToken $missingPage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$missingApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.rps $missingApplicationId $token $expectedId 'approve' @{decision_notes='Attempt with missing originals'; approved_tree_count='3'} | Out-Null
    $missingFeedback = Get-RpsDetail $cookies.rps $missingApplicationId
    $missingState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',(SELECT COUNT(*) FROM tbl_permit_donation_requirements WHERE application_id=$missingApplicationId)) FROM tbl_permit_applications WHERE id=$missingApplicationId"
    Assert-Check ($missingFeedback -match 'not ready for approval' -and $missingState -eq 'under_review:under_review:0') 'Missing scans/originals/wet-ink verification block approval on the server.'

    $inspectionPage = Get-RpsDetail $cookies.rps $inspectionApplicationId
    $token = Get-DecisionToken $inspectionPage
    Invoke-DecisionAction $cookies.rps $inspectionApplicationId $token 0 'approve' @{decision_notes='Attempt before the required inspection is complete.'; approved_tree_count='3'} | Out-Null
    $inspectionFeedback = Get-RpsDetail $cookies.rps $inspectionApplicationId
    Assert-Check ($inspectionFeedback -match 'not ready for approval' -and ((& $script:mysql -u root -N -B certreefy_db -e "SELECT inspection_status FROM tbl_permit_applications WHERE id=$inspectionApplicationId") -eq 'required')) 'A required but incomplete site inspection blocks approval.'

    $readyPage = Get-RpsDetail $cookies.rps $readyApplicationId
    $token = Get-DecisionToken $readyPage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$readyApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.rps $readyApplicationId $token $expectedId 'approve' @{approved_tree_count='3'} | Out-Null
    $remarksFeedback = Get-RpsDetail $cookies.rps $readyApplicationId
    Assert-Check ($remarksFeedback -match 'Decision remarks are required' -and ([int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_decisions WHERE application_id=$readyApplicationId")) -eq 1) 'Approval remarks are required and a failed validation writes no decision.'
    $token = Get-DecisionToken $remarksFeedback
    Invoke-DecisionAction $cookies.rps $readyApplicationId $token $expectedId 'approve' @{decision_notes='Attempt above the verified tree limit.';approved_tree_count='4'} | Out-Null
    $treeLimitFeedback = Get-RpsDetail $cookies.rps $readyApplicationId
    Assert-Check ($treeLimitFeedback -match 'Approved tree count must be between 1 and 3' -and ([int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_decisions WHERE application_id=$readyApplicationId")) -eq 1) 'Approved tree count is server-bounded by the application or verified inspection total.'

    $readyQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=ready_for_decision") -join "`n"
    $newQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=newly_submitted") -join "`n"
    $originalsQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=originals_pending") -join "`n"
    $requirementsQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=requirements_requested") -join "`n"
    $inspectionQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=inspection_pending") -join "`n"
    $underReviewQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=under_review") -join "`n"
    Assert-Check ($readyQueue -match [regex]::Escape($readyTransaction) -and $readyQueue -notmatch [regex]::Escape($newTransaction)) 'Ready-for-decision filter uses server readiness instead of client status input.'
    Assert-Check ($newQueue -match [regex]::Escape($newTransaction) -and $originalsQueue -match [regex]::Escape($missingTransaction)) 'Newly submitted and original-document-pending queues are distinct.'
    Assert-Check ($requirementsQueue -match [regex]::Escape($requirementsTransaction) -and $inspectionQueue -match [regex]::Escape($inspectionTransaction)) 'Requirements-requested and inspection-pending filters show the correct workflow records.'
    Assert-Check ($underReviewQueue -match [regex]::Escape($underReviewTransaction)) 'Under-review queue retains applications whose evidence is not yet decision-ready.'

    $duplicatePage = Get-RpsDetail $rpsDuplicateCookie $readyApplicationId
    $duplicateToken = Get-DecisionToken $duplicatePage
    $readyPage = Get-RpsDetail $cookies.rps $readyApplicationId
    $token = Get-DecisionToken $readyPage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$readyApplicationId ORDER BY id DESC LIMIT 1")
    $approve = Invoke-DecisionAction $cookies.rps $readyApplicationId $token $expectedId 'approve' @{decision_notes='Evidence reviewed and application approved by authorized RPS personnel.';decision_conditions='Observe the recorded cutting scope and applicable office conditions.';approved_tree_count='2';property_classification='public_domain';donation_seedling_count='999999'}
    Assert-Check ($approve -match 'HTTP/1\.1 303') 'Approval uses Post/Redirect/Get.'
    $configuredDonation = [int] (& $script:php -r "require 'config/config.php'; echo PERMIT_PRIVATE_PROPERTY_DONATION_COUNT;")
    $policyVersion = & $script:php -r "require 'config/config.php'; echo PERMIT_DONATION_POLICY_VERSION;"
    $approvedState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(a.application_status,':',a.decision_status,':',a.donation_status,':',d.approved_tree_count,':',d.property_classification,':',d.donation_seedling_count,':',r.required_seedling_count,':',r.received_seedling_count,':',r.current_status,':',r.property_classification,':',r.policy_code,':',r.policy_version,':',r.approval_decision_id=d.id,':',LENGTH(r.applicant_instructions)>0) FROM tbl_permit_applications a INNER JOIN tbl_permit_decisions d ON d.application_id=a.id AND d.decision='approved' INNER JOIN tbl_permit_donation_requirements r ON r.application_id=a.id WHERE a.id=$readyApplicationId"
    Assert-Check ($approvedState -eq "awaiting_donation:approved:required:2:private_property:$configuredDonation`:$configuredDonation`:0:required:private_property:property_private_property:$policyVersion`:1:1") 'Approval snapshots the classification, applied policy/version, quantity, instructions, and zero received total without releasing a permit.'
    $permitCount = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permits WHERE application_id=$readyApplicationId")
    Assert-Check ($permitCount -eq 0) 'Approval does not create or release the final permit.'

    $publicPage = Get-RpsDetail $cookies.rps $publicApplicationId
    $token = Get-DecisionToken $publicPage
    Invoke-DecisionAction $cookies.rps $publicApplicationId $token 0 'begin_review' @{} | Out-Null
    $publicPage = Get-RpsDetail $cookies.rps $publicApplicationId
    $token = Get-DecisionToken $publicPage
    $publicExpectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$publicApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.rps $publicApplicationId $token $publicExpectedId 'approve' @{decision_notes='Approved public-domain validation application.';approved_tree_count='3'} | Out-Null
    $publicConfiguredDonation = [int] (& $script:php -r "require 'config/config.php'; echo PERMIT_PUBLIC_DOMAIN_DONATION_COUNT;")
    $publicRequirement = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(property_classification,':',policy_code,':',policy_version,':',required_seedling_count,':',received_seedling_count,':',current_status) FROM tbl_permit_donation_requirements WHERE application_id=$publicApplicationId"
    Assert-Check ($publicRequirement -eq "public_domain:property_public_domain:$policyVersion`:$publicConfiguredDonation`:0:required") 'Public-domain approval creates the configured public-property requirement and immutable policy snapshot.'

    $env:CERTREEFY_PRIVATE_PROPERTY_DONATION_COUNT = '75'
    $futureConfiguredDonation = [int] (& $script:php -r "require 'config/config.php'; echo PERMIT_PRIVATE_PROPERTY_DONATION_COUNT;")
    Remove-Item Env:CERTREEFY_PRIVATE_PROPERTY_DONATION_COUNT
    $persistedDonation = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT required_seedling_count FROM tbl_permit_donation_requirements WHERE application_id=$readyApplicationId")
    Assert-Check ($futureConfiguredDonation -eq 75 -and $persistedDonation -eq $configuredDonation) 'A later configuration change affects future policy evaluation without silently recalculating the historical requirement.'

    $invalidPolicy = & $script:php -r "require 'config/config.php'; require 'includes/permit_donations.php'; try { permit_donation_policy_for_classification('invalid_property'); echo 'unexpected'; } catch (RuntimeException `$e) { echo `$e->getMessage(); }"
    Assert-Check ($invalidPolicy -match 'No valid server-side donation policy') 'Invalid property classifications cannot produce a donation policy.'

    $duplicate = Invoke-DecisionAction $rpsDuplicateCookie $readyApplicationId $duplicateToken $expectedId 'decline' @{decision_notes='Conflicting late decision'}
    $duplicateFeedback = Get-RpsDetail $rpsDuplicateCookie $readyApplicationId
    $terminalDecisionCount = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_decisions WHERE application_id=$readyApplicationId AND decision IN ('approved','declined')")
    $requirementCount = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_requirements WHERE application_id=$readyApplicationId")
    Assert-Check ($duplicate -match 'HTTP/1\.1 303' -and $duplicateFeedback -match 'locked for review decisions' -and $terminalDecisionCount -eq 1 -and $requirementCount -eq 1) 'A concurrent duplicate or conflicting terminal decision cannot duplicate the donation requirement.'

    $approvedQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=approved_awaiting_donation") -join "`n"
    Assert-Check ($approvedQueue -match [regex]::Escape($readyTransaction)) 'Approved applications awaiting donation appear in their work queue.'

    $declinePage = Get-RpsDetail $cookies.rps $declineApplicationId
    $token = Get-DecisionToken $declinePage
    Invoke-DecisionAction $cookies.rps $declineApplicationId $token 0 'begin_review' @{} | Out-Null
    $declinePage = Get-RpsDetail $cookies.rps $declineApplicationId
    $token = Get-DecisionToken $declinePage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$declineApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.rps $declineApplicationId $token $expectedId 'decline' @{} | Out-Null
    $declineFeedback = Get-RpsDetail $cookies.rps $declineApplicationId
    Assert-Check ($declineFeedback -match 'Decision remarks are required') 'Decline requires a reason.'
    $token = Get-DecisionToken $declineFeedback
    $decline = Invoke-DecisionAction $cookies.rps $declineApplicationId $token $expectedId 'decline' @{decision_notes='Required legal or technical basis was not established in the submitted record.'}
    $declinedState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',donation_status) FROM tbl_permit_applications WHERE id=$declineApplicationId"
    $declinedRequirementCount = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_requirements WHERE application_id=$declineApplicationId")
    $declinedQueue = (& curl.exe -s -b $cookies.rps "$script:baseUrl/pages/cenro/permit-applications.php?queue=declined") -join "`n"
    Assert-Check ($decline -match 'HTTP/1\.1 303' -and $declinedState -eq 'declined:declined:not_required' -and $declinedRequirementCount -eq 0 -and $declinedQueue -match [regex]::Escape($declineTransaction)) 'Decline records the terminal state, creates no donation, and appears in the declined queue.'
    $declinedDonationProbe = & $script:php tests/permit_donation_creation_probe.php $declineApplicationId $rpsId
    Assert-Check ($declinedDonationProbe -match 'only for an approved application' -and ([int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_donation_requirements WHERE application_id=$declineApplicationId")) -eq 0) 'A declined application cannot receive a donation requirement through the reusable service.'

    $rollbackPage = Get-RpsDetail $cookies.rps $rollbackApplicationId
    $token = Get-DecisionToken $rollbackPage
    Invoke-DecisionAction $cookies.rps $rollbackApplicationId $token 0 'begin_review' @{} | Out-Null
    & $script:mysql -u root certreefy_db -e "CREATE TRIGGER $script:triggerName BEFORE INSERT ON tbl_permit_donation_requirements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Forced donation rollback validation'" | Out-Null
    $historyBefore = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$rollbackApplicationId")
    $rollbackPage = Get-RpsDetail $cookies.rps $rollbackApplicationId
    $token = Get-DecisionToken $rollbackPage
    $expectedId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$rollbackApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.rps $rollbackApplicationId $token $expectedId 'approve' @{decision_notes='This action must roll back after the forced donation conflict.';approved_tree_count='3'} | Out-Null
    & $script:mysql -u root certreefy_db -e "DROP TRIGGER IF EXISTS $script:triggerName" | Out-Null
    $rollbackFeedback = Get-RpsDetail $cookies.rps $rollbackApplicationId
    $rollbackState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',donation_status,':',(SELECT COUNT(*) FROM tbl_permit_decisions WHERE application_id=$rollbackApplicationId),':',(SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$rollbackApplicationId)) FROM tbl_permit_applications WHERE id=$rollbackApplicationId"
    Assert-Check ($rollbackState -eq "under_review:under_review:not_required:1:$historyBefore" -and $rollbackFeedback -match 'could not be recorded at this time' -and $rollbackFeedback -notmatch 'SQLSTATE') 'A forced requirement-write failure rolls back every approval write and exposes no database detail.'

    $superToken = Get-DecisionToken $superPage
    $superBegin = Invoke-DecisionAction $cookies.superYes $superApplicationId $superToken 0 'begin_review' @{}
    Assert-Check ($superBegin -match 'HTTP/1\.1 303' -and ((& $script:mysql -u root -N -B certreefy_db -e "SELECT decision_status FROM tbl_permit_applications WHERE id=$superApplicationId") -eq 'under_review')) 'An explicitly authorized Superadmin may record a review action.'
    $superPage = Get-RpsDetail $cookies.superYes $superApplicationId
    $superToken = Get-DecisionToken $superPage
    $superDecisionId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$superApplicationId ORDER BY id DESC LIMIT 1")
    Invoke-DecisionAction $cookies.superYes $superApplicationId $superToken $superDecisionId 'return_for_correction' @{decision_notes='Correct the identified application information before review resumes.'} | Out-Null
    $superReturnState = & $script:mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',decision_status,':',(SELECT decision FROM tbl_permit_decisions WHERE application_id=$superApplicationId ORDER BY id DESC LIMIT 1)) FROM tbl_permit_applications WHERE id=$superApplicationId"
    Assert-Check ($superReturnState -eq 'awaiting_documents:returned:returned_for_correction') 'Return for correction appends a distinct event and preserves a resumable review state.'

    $approvalDecisionId = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_decisions WHERE application_id=$readyApplicationId AND decision='approved'")
    $approvalAudit = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_audit_trail WHERE actor_user_id=$rpsId AND category='approval' AND action='permit_approved' AND entity_type='permit_decision' AND entity_id=$approvalDecisionId")
    $approvalNotification = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$readyApplicationId AND notification_type='permit_status'")
    $donationNotification = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$readyApplicationId AND title='Seedling donation requirement created' AND message LIKE '%policy property_private_property%'")
    $approvalHistory = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$readyApplicationId AND ((status_domain='decision' AND new_status='approved') OR (status_domain='application' AND new_status IN ('awaiting_decision','approved','awaiting_donation')) OR (status_domain='donation' AND new_status='required'))")
    Assert-Check ($approvalAudit -eq 1 -and $approvalNotification -ge 2 -and $donationNotification -eq 1 -and $approvalHistory -eq 5) 'Approval records responsible-user audit, a policy-specific applicant notification, and complete status history.'
    $declineNotification = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$declineApplicationId AND message LIKE '%declined%'")
    $declineAudit = [int] (& $script:mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_audit_trail WHERE actor_user_id=$rpsId AND category='approval' AND action='permit_declined' AND entity_type='permit_decision'")
    Assert-Check ($declineNotification -eq 1 -and $declineAudit -ge 1) 'Decline records an applicant notification and approval-category audit entry.'

    $ownerPage = (& curl.exe -s -b $cookies.owner "$script:baseUrl/pages/community/permit-application.php?id=$readyApplicationId") -join "`n"
    Assert-Check ($ownerPage -match 'Seedling Donation Requirement' -and $ownerPage -match [regex]::Escape($readyTransaction) -and $ownerPage -match 'Property classification' -and $ownerPage -match 'Currently received' -and $ownerPage -match 'Remaining' -and $ownerPage -match 'EMS instructions' -and $ownerPage -match 'does not mean the permit is ready for release') 'The owning Community User sees the complete read-only donation requirement and release warning.'
    $rpsRequirementPage = Get-RpsDetail $cookies.rps $readyApplicationId
    Assert-Check ($rpsRequirementPage -match 'Seedling Donation Requirement' -and $rpsRequirementPage -match 'property_private_property' -and $rpsRequirementPage -match 'Currently received') 'RPS sees the applied policy, quantity progress, instructions, and requirement status.'

    $emsRegistry = (& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-registry.php?transaction=$readyTransaction") -join "`n"
    $emsPublicRegistry = (& curl.exe -s -b $cookies.ems "$script:baseUrl/pages/ems/donation-registry.php?transaction=$publicTransaction") -join "`n"
    Assert-Check ($emsRegistry -match [regex]::Escape($readyTransaction) -and $emsRegistry -match 'property_private_property' -and $emsRegistry -notmatch [regex]::Escape($newTransaction) -and $emsPublicRegistry -match [regex]::Escape($publicTransaction)) 'EMS can discover approved public and private donation requirements by transaction ID.'
    Assert-Check ($emsRegistry -match 'aria-current="page"' -and $emsRegistry -match 'Open' -and $emsRegistry -notmatch 'name="required_seedling_count"') 'EMS navigation exposes the searchable receipt registry without requirement quantity-editing controls.'
    $emsPostStatus = & curl.exe -s -o NUL -w '%{http_code}' -X POST -b $cookies.ems "$script:baseUrl/pages/ems/donation-registry.php"
    $communityEmsHeaders = (& curl.exe -s -D - -o NUL -b $cookies.owner "$script:baseUrl/pages/ems/donation-registry.php") -join "`n"
    $rpsEmsHeaders = (& curl.exe -s -D - -o NUL -b $cookies.rps "$script:baseUrl/pages/ems/donation-registry.php") -join "`n"
    Assert-Check ($emsPostStatus -eq '405' -and $communityEmsHeaders -match 'HTTP/1\.1 302' -and $rpsEmsHeaders -match 'HTTP/1\.1 302') 'The EMS registry rejects writes and denies non-EMS roles at the server boundary.'

    Write-Output 'PERMIT DECISION HTTP VALIDATION COMPLETE'
}
finally {
    if ($script:triggerName) { & $script:mysql -u root certreefy_db -e "DROP TRIGGER IF EXISTS $script:triggerName" | Out-Null }
    foreach ($applicationId in $script:applicationIds) {
        if ($applicationId -lt 1) { continue }
        $cleanup = @"
DELETE FROM tbl_permit_donation_verifications WHERE donation_requirement_id IN (SELECT id FROM tbl_permit_donation_requirements WHERE application_id=$applicationId);
DELETE FROM tbl_permit_donation_requirements WHERE application_id=$applicationId;
DELETE FROM tbl_permits WHERE application_id=$applicationId;
UPDATE tbl_permit_decisions SET previous_decision_id=NULL WHERE application_id=$applicationId;
DELETE FROM tbl_permit_decisions WHERE application_id=$applicationId;
UPDATE tbl_permit_document_reviews SET previous_review_id=NULL WHERE application_id=$applicationId;
DELETE FROM tbl_permit_document_reviews WHERE application_id=$applicationId;
DELETE FROM tbl_permit_documents WHERE application_id=$applicationId;
DELETE FROM tbl_permit_inspection_photos WHERE application_id=$applicationId;
DELETE FROM tbl_permit_inspection_tree_verifications WHERE application_id=$applicationId;
UPDATE tbl_permit_inspections SET previous_inspection_id=NULL, follow_up_of_inspection_id=NULL WHERE application_id=$applicationId;
DELETE FROM tbl_permit_inspections WHERE application_id=$applicationId;
DELETE FROM tbl_permit_trees WHERE application_id=$applicationId;
DELETE FROM tbl_permit_status_history WHERE application_id=$applicationId;
DELETE FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id=$applicationId;
DELETE FROM tbl_audit_trail WHERE (entity_type='permit_application' AND entity_id=$applicationId) OR (entity_type='permit_decision' AND JSON_EXTRACT(details,'$.application_id')=$applicationId);
DELETE FROM tbl_permit_applications WHERE id=$applicationId;
"@
        & $script:mysql -u root certreefy_db -e $cleanup | Out-Null
    }
    if ($userIds.Count -gt 0) {
        $ids = $userIds -join ','
        & $script:mysql -u root certreefy_db -e "DELETE FROM tbl_user_permissions WHERE user_id IN ($ids) OR granted_by_user_id IN ($ids); DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($ids); DELETE FROM tbl_notifications WHERE recipient_user_id IN ($ids) OR created_by_user_id IN ($ids); DELETE FROM tbl_users WHERE id IN ($ids);" | Out-Null
    }
    foreach ($cookie in $allCookies) { if (Test-Path -LiteralPath $cookie) { Remove-Item -LiteralPath $cookie -Force } }
}
