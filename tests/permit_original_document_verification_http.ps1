$ErrorActionPreference = 'Stop'

function Assert-Check([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Output "PASS: $Message"
}

function Invoke-Login([string] $Username, [string] $CookiePath) {
    $loginHtml = (& curl.exe -s -c $CookiePath "$script:baseUrl/pages/auth/login.php") -join "`n"
    $csrf = [regex]::Match($loginHtml, 'name="csrf_token" value="([^"]+)"')
    if (-not $csrf.Success) { throw "Unable to extract login token for $Username" }
    $headers = (& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        --data-urlencode "csrf_token=$($csrf.Groups[1].Value)" `
        --data-urlencode "login_identifier=$Username" `
        --data-urlencode "password=$script:password" `
        "$script:baseUrl/pages/auth/login.php") -join "`n"
    if ($headers -notmatch 'HTTP/1\.1 302') { throw "Login failed for $Username" }
}

function Get-Detail([string] $CookiePath, [int] $ApplicationId) {
    return ((& curl.exe -s -b $CookiePath "$script:baseUrl/pages/cenro/permit-application.php?id=$ApplicationId") -join "`n")
}

function Get-OriginalToken([string] $Html) {
    $match = [regex]::Match($Html, 'action="permit-original-document-review\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline')
    if (-not $match.Success) { throw 'Unable to extract original verification CSRF token.' }
    return $match.Groups[1].Value
}

function Invoke-OriginalReview(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $Token,
    [string] $DocumentType,
    [string] $Received,
    [string] $ReceivedOn,
    [string] $ReceivedBy,
    [string] $WetRequired,
    [string] $WetVerified,
    [string] $Compared,
    [string] $Status,
    [string] $Notes
) {
    $expectedDocumentId = & $script:mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$ApplicationId AND document_type='$DocumentType' AND is_current=1 ORDER BY id DESC LIMIT 1"
    return ((& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        --data-urlencode "csrf_token=$Token" `
        --data-urlencode "application_id=$ApplicationId" `
        --data-urlencode "document_type=$DocumentType" `
        --data-urlencode "expected_document_id=$expectedDocumentId" `
        --data-urlencode "original_received=$Received" `
        --data-urlencode "original_received_on=$ReceivedOn" `
        --data-urlencode "received_by_user_id=$ReceivedBy" `
        --data-urlencode "wet_ink_required=$WetRequired" `
        --data-urlencode "wet_ink_verified=$WetVerified" `
        --data-urlencode "scan_compared_with_original=$Compared" `
        --data-urlencode "review_status=$Status" `
        --data-urlencode "review_notes=$Notes" `
        "$script:baseUrl/pages/cenro/permit-original-document-review.php") -join "`n")
}

$script:baseUrl = 'http://127.0.0.1/Certreefy'
$script:password = 'OriginalValidation123!'
$php = 'C:\xampp\php\php.exe'
$script:mysql = 'C:\xampp\mysql\bin\mysql.exe'
$mysql = $script:mysql
$storageRoot = 'C:\xampp\private\certreefy\permit_documents'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$ownerUsername = "orig_owner_$suffix"
$otherUsername = "orig_other_$suffix"
$rpsUsername = "orig_rps_$suffix"
$rpsTwoUsername = "orig_rps2_$suffix"
$authorizedSuperUsername = "orig_super_yes_$suffix"
$unauthorizedSuperUsername = "orig_super_no_$suffix"
$emsUsername = "orig_ems_$suffix"
$ownerCookie = Join-Path $env:TEMP "certreefy_orig_owner_$suffix.cookies"
$otherCookie = Join-Path $env:TEMP "certreefy_orig_other_$suffix.cookies"
$rpsCookie = Join-Path $env:TEMP "certreefy_orig_rps_$suffix.cookies"
$rpsTwoCookie = Join-Path $env:TEMP "certreefy_orig_rps2_$suffix.cookies"
$authorizedSuperCookie = Join-Path $env:TEMP "certreefy_orig_super_yes_$suffix.cookies"
$unauthorizedSuperCookie = Join-Path $env:TEMP "certreefy_orig_super_no_$suffix.cookies"
$emsCookie = Join-Path $env:TEMP "certreefy_orig_ems_$suffix.cookies"
$cookies = @($ownerCookie,$otherCookie,$rpsCookie,$rpsTwoCookie,$authorizedSuperCookie,$unauthorizedSuperCookie,$emsCookie)
$userIds = @()
$applicationId = 0
$storageDirectory = $null

try {
    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $script:password
    $insertUsers = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('Original','Owner','$ownerUsername@example.test','09171000001','Test Address','$ownerUsername','$hash','community','active'),
('Original','Other','$otherUsername@example.test','09171000002','Test Address','$otherUsername','$hash','community','active'),
('Original','RPS','$rpsUsername@example.test','09171000003','Test Address','$rpsUsername','$hash','rps','active'),
('Original','Receiver','$rpsTwoUsername@example.test','09171000004','Test Address','$rpsTwoUsername','$hash','rps','active'),
('Original','Authorized Super','$authorizedSuperUsername@example.test','09171000005','Test Address','$authorizedSuperUsername','$hash','superadmin','active'),
('Original','Unauthorized Super','$unauthorizedSuperUsername@example.test','09171000006','Test Address','$unauthorizedSuperUsername','$hash','superadmin','active'),
('Original','EMS','$emsUsername@example.test','09171000007','Test Address','$emsUsername','$hash','ems','active');
"@
    & $mysql -u root certreefy_db -e $insertUsers | Out-Null
    $ownerId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$ownerUsername'")
    $otherId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$otherUsername'")
    $rpsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsUsername'")
    $rpsTwoId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsTwoUsername'")
    $authorizedSuperId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$authorizedSuperUsername'")
    $unauthorizedSuperId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$unauthorizedSuperUsername'")
    $emsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$emsUsername'")
    $userIds = @($ownerId,$otherId,$rpsId,$rpsTwoId,$authorizedSuperId,$unauthorizedSuperId,$emsId)
    & $mysql -u root certreefy_db -e "INSERT INTO tbl_user_permissions (user_id,permission_key,is_active,granted_by_user_id) VALUES ($authorizedSuperId,'permit_original_document_verification',1,$authorizedSuperId)" | Out-Null

    $number = Get-Random -Minimum 500000 -Maximum 699990
    $transactionId = 'TCP-2098-' + $number.ToString('000000')
    $submissionKey = ([Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N'))
    $insertApplication = @"
INSERT INTO tbl_permit_applications
(transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at)
VALUES
('$transactionId','$submissionKey',$ownerId,'Original Owner','09171000001','Test Address','individual','owner','private_property','Original Owner','Test Property','District 3','Poblacion','Sta. Cruz','Laguna','Original verification validation','under_review','online_verified','not_required','pending','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
"@
    & $mysql -u root certreefy_db -e $insertApplication | Out-Null
    $applicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$transactionId'")

    $year = Get-Date -Format 'yyyy'
    $relativeDirectory = "$year/$transactionId"
    $storageDirectory = Join-Path (Join-Path $storageRoot $year) $transactionId
    New-Item -ItemType Directory -Path $storageDirectory -Force | Out-Null
    $png = [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    $types = @('application_request','applicant_identification','ownership_authorization','tree_location_photos')
    foreach ($type in $types) {
        $safeName = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N') + '.png'
        [IO.File]::WriteAllBytes((Join-Path $storageDirectory $safeName), $png)
        & $mysql -u root certreefy_db -e "INSERT INTO tbl_permit_documents (application_id,document_type,storage_path,original_filename,mime_type,file_size_bytes,uploaded_by_user_id,is_current,verification_status,verified_by_user_id,verified_at) VALUES ($applicationId,'$type','$relativeDirectory/$safeName','$type.png','image/png',$($png.Length),$ownerId,1,'accepted',$rpsId,CURRENT_TIMESTAMP)" | Out-Null
    }

    Invoke-Login $ownerUsername $ownerCookie
    Invoke-Login $otherUsername $otherCookie
    Invoke-Login $rpsUsername $rpsCookie
    Invoke-Login $rpsTwoUsername $rpsTwoCookie
    Invoke-Login $authorizedSuperUsername $authorizedSuperCookie
    Invoke-Login $unauthorizedSuperUsername $unauthorizedSuperCookie
    Invoke-Login $emsUsername $emsCookie

    $rpsDetail = Get-Detail $rpsCookie $applicationId
    Assert-Check ($rpsDetail -match 'Original hardcopy verification' -and $rpsDetail -match 'Wet-ink signature required' -and $rpsDetail -match 'Required originals verified') 'RPS can access the existing review layout with original-verification controls.'
    Assert-Check ($rpsDetail -match 'class="modal fade" id="verifyOriginalModal"' -and $rpsDetail -match 'table-responsive') 'Original verification reuses the Bootstrap modal and responsive table patterns.'

    $communityDenied = (& curl.exe -s -D - -o NUL -b $ownerCookie "$script:baseUrl/pages/cenro/permit-original-document-review.php") -join "`n"
    Assert-Check ($communityDenied -match 'HTTP/1\.1 302' -and $communityDenied -match 'Location:\s*\.\./community/dashboard\.php') 'Community users cannot enter the original-verification endpoint.'
    $emsDenied = (& curl.exe -s -D - -o NUL -b $emsCookie "$script:baseUrl/pages/cenro/permit-original-document-review.php") -join "`n"
    Assert-Check ($emsDenied -match 'HTTP/1\.1 302' -and $emsDenied -match 'Location:\s*\.\./ems/dashboard\.php') 'EMS users cannot enter the original-verification endpoint.'
    $unauthorizedSuperStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $unauthorizedSuperCookie "$script:baseUrl/pages/cenro/permit-application.php?id=$applicationId"
    Assert-Check ($unauthorizedSuperStatus -eq '403') 'A Superadmin without the specific permission is denied.'
    $authorizedSuperDetail = Get-Detail $authorizedSuperCookie $applicationId
    Assert-Check ($authorizedSuperDetail -match 'permit-applications\.php' -and $authorizedSuperDetail -match 'Verify original') 'A specifically authorized Superadmin can access original verification and its navigation.'

    $today = Get-Date -Format 'yyyy-MM-dd'
    $superToken = Get-OriginalToken $authorizedSuperDetail
    $superReview = Invoke-OriginalReview $authorizedSuperCookie $applicationId $superToken 'application_request' '1' $today "$rpsTwoId" '1' '1' '1' 'verified' 'Original received and wet-ink signature verified.'
    Assert-Check ($superReview -match 'HTTP/1\.1 302') 'Authorized Superadmin original verification uses Post/Redirect/Get.'
    $superStored = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(review_status,':',original_received,':',original_received_on,':',received_by_user_id,':',wet_ink_required,':',wet_ink_verified,':',scan_compared_with_original,':',reviewed_by_user_id) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='application_request' AND review_scope='original' ORDER BY id DESC LIMIT 1"
    Assert-Check ($superStored -eq "verified:1:$today`:$rpsTwoId`:1:1:1:$authorizedSuperId") 'Receipt date, receiving personnel, wet-ink, comparison, result, and verifier are stored separately.'

    $rpsDetail = Get-Detail $rpsCookie $applicationId
    $rpsToken = Get-OriginalToken $rpsDetail
    $invalidWet = Invoke-OriginalReview $rpsCookie $applicationId $rpsToken 'applicant_identification' '1' $today "$rpsId" '1' '0' '1' 'verified' 'Attempt without wet-ink verification.'
    Assert-Check ($invalidWet -match 'HTTP/1\.1 302') 'Invalid wet-ink verification returns through Post/Redirect/Get.'
    $invalidWetPage = Get-Detail $rpsCookie $applicationId
    $identityCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='applicant_identification' AND review_scope='original'")
    Assert-Check ($invalidWetPage -match 'requires the original hardcopy, any required wet-ink signature' -and $identityCount -eq 0) 'A required but unverified wet-ink signature cannot be recorded as verified.'

    $rpsToken = Get-OriginalToken $invalidWetPage
    $identityReview = Invoke-OriginalReview $rpsCookie $applicationId $rpsToken 'applicant_identification' '1' $today "$rpsId" '1' '1' '1' 'verified' 'Identity original verified.'
    Assert-Check ($identityReview -match 'HTTP/1\.1 302') 'RPS can record a received original and verified wet-ink signature.'

    $rpsDetail = Get-Detail $rpsCookie $applicationId
    $rpsToken = Get-OriginalToken $rpsDetail
    $rejected = Invoke-OriginalReview $rpsCookie $applicationId $rpsToken 'ownership_authorization' '1' $today "$rpsId" '1' '0' '1' 'rejected' 'Wet-ink authorization is not valid.'
    Assert-Check ($rejected -match 'HTTP/1\.1 302') 'RPS can reject a received original with remarks.'
    $rejectedId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='ownership_authorization' AND review_scope='original' ORDER BY id DESC LIMIT 1")
    $incompleteStatus = & $mysql -u root -N -B certreefy_db -e "SELECT document_status FROM tbl_permit_applications WHERE id=$applicationId"
    Assert-Check ($incompleteStatus -eq 'incomplete') 'A rejected mandatory original moves the document workflow to incomplete.'

    $rpsDetail = Get-Detail $rpsCookie $applicationId
    $rpsToken = Get-OriginalToken $rpsDetail
    $replacement = Invoke-OriginalReview $rpsCookie $applicationId $rpsToken 'ownership_authorization' '1' $today "$rpsId" '1' '0' '1' 'replacement_required' 'Submit a replacement scan and corrected signed original.'
    Assert-Check ($replacement -match 'HTTP/1\.1 302') 'RPS can request correction or replacement with remarks.'
    $replacementRow = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(id,':',previous_review_id,':',review_status) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='ownership_authorization' AND review_scope='original' ORDER BY id DESC LIMIT 1"
    Assert-Check ($replacementRow -match "^\d+:$rejectedId`:replacement_required$") 'A changed decision is appended and linked to the previous verification record.'
    $communityPage = (& curl.exe -s -b $ownerCookie "$script:baseUrl/pages/community/permit-application.php?id=$applicationId") -join "`n"
    Assert-Check ($communityPage -match 'Replacement required' -and $communityPage -match 'Submit a replacement scan and corrected signed original' -and $communityPage -match 'Select replacement scan') 'The owner sees status and remarks and may upload an explicitly requested replacement.'

    $blockedAdvance = & $php tests/permit_inspection_status_probe.php $applicationId $rpsId
    Assert-Check ($blockedAdvance -match 'mandatory original hardcopy') 'The application cannot advance while mandatory originals remain unverified.'
    $manualCompletion = & $php -r 'require \"config/config.php\"; require \"includes/permit.php\"; try { permit_change_status($pdo,(int)$argv[1],(int)$argv[2],\"document\",\"verified\"); echo \"UNEXPECTED\"; } catch (Throwable $e) { echo $e->getMessage(); }' $applicationId $rpsId
    Assert-Check ($manualCompletion -match 'must be derived by the original verification workflow') 'The generic status API cannot bypass original-document completeness.'

    $rpsPageOne = Get-Detail $rpsCookie $applicationId
    $rpsPageTwo = Get-Detail $rpsTwoCookie $applicationId
    $tokenOne = Get-OriginalToken $rpsPageOne
    $tokenTwo = Get-OriginalToken $rpsPageTwo
    $concurrentNotes = 'Concurrent original verification decision.'
    $treeDocumentId = & $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$applicationId AND document_type='tree_location_photos' AND is_current=1"
    $jobs = @(
        (Start-Job -ScriptBlock {
            param($base,$cookie,$app,$token,$document,$receiver,$date,$notes)
            (& curl.exe -s -D - -o NUL -b $cookie -c $cookie --data-urlencode "csrf_token=$token" --data-urlencode "application_id=$app" --data-urlencode 'document_type=tree_location_photos' --data-urlencode "expected_document_id=$document" --data-urlencode 'original_received=1' --data-urlencode "original_received_on=$date" --data-urlencode "received_by_user_id=$receiver" --data-urlencode 'wet_ink_required=0' --data-urlencode 'wet_ink_verified=0' --data-urlencode 'scan_compared_with_original=1' --data-urlencode 'review_status=verified' --data-urlencode "review_notes=$notes" "$base/pages/cenro/permit-original-document-review.php") -join "`n"
        } -ArgumentList $script:baseUrl,$rpsCookie,$applicationId,$tokenOne,$treeDocumentId,$rpsTwoId,$today,$concurrentNotes),
        (Start-Job -ScriptBlock {
            param($base,$cookie,$app,$token,$document,$receiver,$date,$notes)
            (& curl.exe -s -D - -o NUL -b $cookie -c $cookie --data-urlencode "csrf_token=$token" --data-urlencode "application_id=$app" --data-urlencode 'document_type=tree_location_photos' --data-urlencode "expected_document_id=$document" --data-urlencode 'original_received=1' --data-urlencode "original_received_on=$date" --data-urlencode "received_by_user_id=$receiver" --data-urlencode 'wet_ink_required=0' --data-urlencode 'wet_ink_verified=0' --data-urlencode 'scan_compared_with_original=1' --data-urlencode 'review_status=verified' --data-urlencode "review_notes=$notes" "$base/pages/cenro/permit-original-document-review.php") -join "`n"
        } -ArgumentList $script:baseUrl,$rpsTwoCookie,$applicationId,$tokenTwo,$treeDocumentId,$rpsTwoId,$today,$concurrentNotes)
    )
    $completedJobs = Wait-Job -Job $jobs -Timeout 30
    if ($completedJobs.Count -ne 2) { throw 'Concurrent original verification requests did not finish in time.' }
    $jobOutput = ($jobs | Receive-Job) -join "`n"
    $jobs | Remove-Job -Force
    $concurrentCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='tree_location_photos' AND review_scope='original'")
    Assert-Check (($jobOutput -split 'HTTP/1\.1 302').Count -ge 3 -and $concurrentCount -eq 1) 'Concurrent identical decisions serialize and create only one verification history record.'

    $rpsDetail = Get-Detail $rpsCookie $applicationId
    $rpsToken = Get-OriginalToken $rpsDetail
    $resolved = Invoke-OriginalReview $rpsCookie $applicationId $rpsToken 'ownership_authorization' '1' $today "$rpsId" '1' '1' '1' 'verified' 'Corrected authorization original verified.'
    Assert-Check ($resolved -match 'HTTP/1\.1 302') 'RPS can record a later corrected verification without overwriting prior decisions.'
    $historyCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='ownership_authorization' AND review_scope='original'")
    $workflowState = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(document_status,':',application_status,':',decision_status) FROM tbl_permit_applications WHERE id=$applicationId"
    $verifiedHistoryCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$applicationId AND status_domain='document' AND new_status IN ('originals_verified','verified')")
    Assert-Check ($historyCount -eq 3 -and $workflowState -eq 'verified:under_review:pending' -and $verifiedHistoryCount -eq 2) 'All required originals complete the document workflow through originals-verified and verified without approving the application.'

    $repeatPage = Get-Detail $rpsCookie $applicationId
    $repeatToken = Get-OriginalToken $repeatPage
    $repeat = Invoke-OriginalReview $rpsCookie $applicationId $repeatToken 'ownership_authorization' '1' $today "$rpsId" '1' '1' '1' 'verified' 'Corrected authorization original verified.'
    Assert-Check ($repeat -match 'HTTP/1\.1 302') 'A repeated verification attempt returns through Post/Redirect/Get.'
    $repeatFeedback = Get-Detail $rpsCookie $applicationId
    $repeatCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_document_reviews WHERE application_id=$applicationId AND document_type='ownership_authorization' AND review_scope='original'")
    Assert-Check ($repeatFeedback -match 'already recorded' -and $repeatCount -eq 3) 'A repeated identical decision does not duplicate verification history.'

    $advanced = & $php tests/permit_inspection_status_probe.php $applicationId $rpsId
    Assert-Check ($advanced -eq 'awaiting_decision') 'The application may advance after mandatory originals are verified and inspection is not required.'

    $ownerDetail = (& curl.exe -s -b $ownerCookie "$script:baseUrl/pages/community/permit-application.php?id=$applicationId") -join "`n"
    Assert-Check ($ownerDetail -match 'Required originals verified' -and $ownerDetail -match 'Corrected authorization original verified' -and $ownerDetail -match 'Original verified') 'The Community owner can view resulting original statuses and remarks.'
    $otherOwnerStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $otherCookie "$script:baseUrl/pages/community/permit-application.php?id=$applicationId"
    Assert-Check ($otherOwnerStatus -eq '404') 'Another Community user cannot view original verification information.'

    $auditCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_audit_trail WHERE actor_user_id IN ($rpsId,$rpsTwoId,$authorizedSuperId) AND entity_type='permit_document_review' AND action LIKE 'permit_original_document_%'")
    $notificationCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$applicationId AND title LIKE 'Original document%'")
    Assert-Check ($auditCount -eq 6 -and $notificationCount -eq 6) 'Every stored verification decision creates a responsible-user audit and applicant notification.'

    Write-Output 'ORIGINAL DOCUMENT VERIFICATION HTTP VALIDATION COMPLETE'
}
finally {
    if ($applicationId -gt 0) {
        $storedPaths = @(& $mysql -u root -N -B certreefy_db -e "SELECT storage_path FROM tbl_permit_documents WHERE application_id=$applicationId")
        foreach ($storedPath in $storedPaths) {
            if ($storedPath) {
                $filePath = Join-Path $storageRoot ($storedPath -replace '/', '\')
                if (Test-Path -LiteralPath $filePath) { Remove-Item -LiteralPath $filePath -Force }
            }
        }
        $cleanupApplication = @"
UPDATE tbl_permit_document_reviews SET previous_review_id=NULL WHERE application_id=$applicationId;
DELETE FROM tbl_permit_document_reviews WHERE application_id=$applicationId;
UPDATE tbl_permit_documents SET replaces_document_id=NULL WHERE application_id=$applicationId;
DELETE FROM tbl_permit_documents WHERE application_id=$applicationId;
DELETE FROM tbl_permit_status_history WHERE application_id=$applicationId;
DELETE FROM tbl_permit_trees WHERE application_id=$applicationId;
DELETE FROM tbl_permit_applications WHERE id=$applicationId;
"@
        & $mysql -u root certreefy_db -e $cleanupApplication | Out-Null
    }
    if ($userIds.Count -gt 0) {
        $userList = $userIds -join ','
        $cleanupUsers = @"
DELETE FROM tbl_notifications WHERE recipient_user_id IN ($userList) OR created_by_user_id IN ($userList);
DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($userList);
DELETE FROM tbl_user_management_audit WHERE actor_user_id IN ($userList) OR target_user_id IN ($userList);
DELETE FROM tbl_user_permissions WHERE user_id IN ($userList) OR granted_by_user_id IN ($userList);
DELETE FROM tbl_users WHERE id IN ($userList);
"@
        & $mysql -u root certreefy_db -e $cleanupUsers | Out-Null
    }
    foreach ($cookie in $cookies) { Remove-Item -LiteralPath $cookie -Force -ErrorAction SilentlyContinue }
    if ($storageDirectory -and (Test-Path -LiteralPath $storageDirectory)) {
        $resolvedDirectory = (Resolve-Path -LiteralPath $storageDirectory).Path
        $resolvedRoot = (Resolve-Path -LiteralPath $storageRoot).Path
        if ($resolvedDirectory.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedDirectory -Force -ErrorAction SilentlyContinue
        }
    }
}
