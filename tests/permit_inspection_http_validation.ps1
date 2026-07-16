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

function Get-RpsDetail([string] $CookiePath, [int] $ApplicationId) {
    return ((& curl.exe -s -b $CookiePath "$script:baseUrl/pages/cenro/permit-application.php?id=$ApplicationId") -join "`n")
}

function Get-CommunityDetail([string] $CookiePath, [int] $ApplicationId) {
    return ((& curl.exe -s -b $CookiePath "$script:baseUrl/pages/community/permit-application.php?id=$ApplicationId") -join "`n")
}

function Get-InspectionToken([string] $Html) {
    $match = [regex]::Match($Html, 'action="permit-inspection-action\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline')
    if (-not $match.Success) { throw 'Unable to extract inspection CSRF token.' }
    return $match.Groups[1].Value
}

function Invoke-InspectionAction(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $Token,
    [int] $ExpectedId,
    [string] $Action,
    [hashtable] $Fields
) {
    $arguments = @('-s','-D','-','-o','NUL','-b',$CookiePath,'-c',$CookiePath,
        '--data-urlencode',"csrf_token=$Token",'--data-urlencode',"application_id=$ApplicationId",
        '--data-urlencode',"expected_inspection_id=$ExpectedId",'--data-urlencode',"action=$Action")
    foreach ($key in $Fields.Keys) {
        $arguments += @('--data-urlencode', "$key=$($Fields[$key])")
    }
    $arguments += "$script:baseUrl/pages/cenro/permit-inspection-action.php"
    return ((& curl.exe @arguments) -join "`n")
}

function Invoke-InspectionCompletion(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $Token,
    [int] $ExpectedId,
    [int] $TreeOneId,
    [int] $TreeTwoId,
    [string] $Result,
    [string] $PropertyConfirmed,
    [string] $OwnershipConfirmed,
    [string] $TreeOneConfirmed,
    [string] $PhotoPath,
    [string] $PhotoName,
    [string] $PhotoMime,
    [bool] $IncludeFindings = $true
) {
    $arguments = @('-s','-D','-','-o','NUL','-b',$CookiePath,'-c',$CookiePath,
        '-F',"csrf_token=$Token",'-F',"application_id=$ApplicationId",'-F',"expected_inspection_id=$ExpectedId",
        '-F','action=complete','-F',"verification_result=$Result",'-F',"inspected_at=$((Get-Date).ToString('yyyy-MM-ddTHH:mm'))",
        '-F',"property_location_confirmed=$PropertyConfirmed",'-F',"ownership_authorization_confirmed=$OwnershipConfirmed",
        '-F',"trees[$TreeOneId][species_confirmed]=$TreeOneConfirmed",'-F',"trees[$TreeOneId][quantity_confirmed]=$TreeOneConfirmed",
        '-F',"trees[$TreeOneId][measurements_confirmed]=$TreeOneConfirmed",'-F',"trees[$TreeOneId][verified_common_name]=Narra",
        '-F',"trees[$TreeOneId][verified_scientific_name]=Pterocarpus indicus",'-F',"trees[$TreeOneId][verified_quantity]=2",
        '-F',"trees[$TreeOneId][verified_diameter_cm]=35.50",'-F',"trees[$TreeOneId][verified_height_m]=8.25",
        '-F',"trees[$TreeOneId][measurement_notes]=Measured on site",
        '-F',"trees[$TreeTwoId][species_confirmed]=1",'-F',"trees[$TreeTwoId][quantity_confirmed]=1",
        '-F',"trees[$TreeTwoId][verified_common_name]=Mango",'-F',"trees[$TreeTwoId][verified_scientific_name]=Mangifera indica",
        '-F',"trees[$TreeTwoId][verified_quantity]=1",'-F',"trees[$TreeTwoId][measurement_notes]=Counted on site",
        '-F','recommendation=Forward findings for RPS decision')
    if ($IncludeFindings) { $arguments += @('-F','findings=Site and tree observations recorded during inspection.') }
    if ($PhotoPath -ne '') {
        $arguments += @('-F', "site_photos[]=@$PhotoPath;filename=$PhotoName;type=$PhotoMime")
    }
    $arguments += "$script:baseUrl/pages/cenro/permit-inspection-action.php"
    return ((& curl.exe @arguments) -join "`n")
}

$script:baseUrl = 'http://127.0.0.1/Certreefy'
$script:password = 'InspectionValidation123!'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$storageRoot = 'C:\xampp\private\certreefy\permit_inspections'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$ownerUsername = "inspect_owner_$suffix"
$otherUsername = "inspect_other_$suffix"
$rpsUsername = "inspect_rps_$suffix"
$assigneeUsername = "inspect_assignee_$suffix"
$authorizedSuperUsername = "inspect_super_yes_$suffix"
$unauthorizedSuperUsername = "inspect_super_no_$suffix"
$emsUsername = "inspect_ems_$suffix"
$ownerCookie = Join-Path $env:TEMP "certreefy_inspect_owner_$suffix.cookies"
$otherCookie = Join-Path $env:TEMP "certreefy_inspect_other_$suffix.cookies"
$rpsCookie = Join-Path $env:TEMP "certreefy_inspect_rps_$suffix.cookies"
$assigneeCookie = Join-Path $env:TEMP "certreefy_inspect_assignee_$suffix.cookies"
$authorizedSuperCookie = Join-Path $env:TEMP "certreefy_inspect_super_yes_$suffix.cookies"
$unauthorizedSuperCookie = Join-Path $env:TEMP "certreefy_inspect_super_no_$suffix.cookies"
$emsCookie = Join-Path $env:TEMP "certreefy_inspect_ems_$suffix.cookies"
$cookies = @($ownerCookie,$otherCookie,$rpsCookie,$assigneeCookie,$authorizedSuperCookie,$unauthorizedSuperCookie,$emsCookie)
$tempDirectory = Join-Path $env:TEMP "certreefy_inspection_validation_$suffix"
$userIds = @()
$applicationIds = @()

try {
    New-Item -ItemType Directory -Path $tempDirectory | Out-Null
    $validPng = Join-Path $tempDirectory 'site.png'
    $spoofedJpg = Join-Path $tempDirectory 'spoofed.jpg'
    $oversizedPng = Join-Path $tempDirectory 'oversized.png'
    [IO.File]::WriteAllBytes($validPng, [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))
    [IO.File]::WriteAllText($spoofedJpg, 'not an image')
    $oversizedStream = [IO.File]::Create($oversizedPng)
    try { $oversizedStream.SetLength((10 * 1024 * 1024) + 1024) } finally { $oversizedStream.Dispose() }

    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $script:password
    $insertUsers = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('Inspection','Owner','$ownerUsername@example.test','09172000001','Test Address','$ownerUsername','$hash','community','active'),
('Inspection','Other','$otherUsername@example.test','09172000002','Test Address','$otherUsername','$hash','community','active'),
('Inspection','RPS','$rpsUsername@example.test','09172000003','Test Address','$rpsUsername','$hash','rps','active'),
('Inspection','Assignee','$assigneeUsername@example.test','09172000004','Test Address','$assigneeUsername','$hash','rps','active'),
('Inspection','Authorized Super','$authorizedSuperUsername@example.test','09172000005','Test Address','$authorizedSuperUsername','$hash','superadmin','active'),
('Inspection','Unauthorized Super','$unauthorizedSuperUsername@example.test','09172000006','Test Address','$unauthorizedSuperUsername','$hash','superadmin','active'),
('Inspection','EMS','$emsUsername@example.test','09172000007','Test Address','$emsUsername','$hash','ems','active');
"@
    & $mysql -u root certreefy_db -e $insertUsers | Out-Null
    $ownerId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$ownerUsername'")
    $otherId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$otherUsername'")
    $rpsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsUsername'")
    $assigneeId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$assigneeUsername'")
    $authorizedSuperId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$authorizedSuperUsername'")
    $unauthorizedSuperId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$unauthorizedSuperUsername'")
    $emsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$emsUsername'")
    $userIds = @($ownerId,$otherId,$rpsId,$assigneeId,$authorizedSuperId,$unauthorizedSuperId,$emsId)
    & $mysql -u root certreefy_db -e "INSERT INTO tbl_user_permissions (user_id,permission_key,is_active,granted_by_user_id) VALUES ($authorizedSuperId,'permit_site_inspection',1,$authorizedSuperId)" | Out-Null

    $number = Get-Random -Minimum 300000 -Maximum 499990
    $transactionId = 'TCP-2097-' + $number.ToString('000000')
    $lockedTransactionId = 'TCP-2097-' + ($number + 1).ToString('000000')
    $keyOne = ([Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N'))
    $keyTwo = ([Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N'))
    $insertApplications = @"
INSERT INTO tbl_permit_applications
(transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,latitude,longitude,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at)
VALUES
('$transactionId','$keyOne',$ownerId,'Inspection Owner','09172000001','Test Address','individual','owner','private_property','Inspection Owner','Inspection Property','District 3','Poblacion','Sta. Cruz','Laguna',14.2790000,121.4160000,'Inspection validation','under_review','verified','pending_assessment','pending','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
('$lockedTransactionId','$keyTwo',$ownerId,'Inspection Owner','09172000001','Test Address','individual','owner','private_property','Inspection Owner','Locked Property','District 3','Poblacion','Sta. Cruz','Laguna',14.2790000,121.4160000,'Locked inspection validation','approved','verified','pending_assessment','approved','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
"@
    & $mysql -u root certreefy_db -e $insertApplications | Out-Null
    $applicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$transactionId'")
    $lockedApplicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$lockedTransactionId'")
    $applicationIds = @($applicationId,$lockedApplicationId)
    & $mysql -u root certreefy_db -e "INSERT INTO tbl_permit_trees (application_id,common_name,scientific_name,quantity,diameter_cm,estimated_height_m,condition_notes) VALUES ($applicationId,'Narra','Pterocarpus indicus',2,35.50,8.25,'Measured record'),($applicationId,'Mango','Mangifera indica',1,NULL,NULL,'Count only')" | Out-Null
    $treeOneId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_trees WHERE application_id=$applicationId ORDER BY id LIMIT 1")
    $treeTwoId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_trees WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")

    Invoke-Login $ownerUsername $ownerCookie
    Invoke-Login $otherUsername $otherCookie
    Invoke-Login $rpsUsername $rpsCookie
    Invoke-Login $assigneeUsername $assigneeCookie
    Invoke-Login $authorizedSuperUsername $authorizedSuperCookie
    Invoke-Login $unauthorizedSuperUsername $unauthorizedSuperCookie
    Invoke-Login $emsUsername $emsCookie

    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    Assert-Check ($rpsPage -match 'Site Inspection &amp; Tree Verification' -and $rpsPage -match 'inspectionScheduleModal' -and $rpsPage -match 'table-responsive') 'RPS sees the inspection workflow in the existing responsive permit-detail design.'
    $authorizedSuperPage = Get-RpsDetail $authorizedSuperCookie $applicationId
    Assert-Check ($authorizedSuperPage -match 'Mark inspection required' -and $authorizedSuperPage -match 'Permit Applications') 'A specifically permitted Superadmin can access the inspection workflow and navigation.'
    $unauthorizedSuperStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $unauthorizedSuperCookie "$script:baseUrl/pages/cenro/permit-application.php?id=$applicationId"
    Assert-Check ($unauthorizedSuperStatus -eq '403') 'A Superadmin without the inspection or document permission is denied.'
    $communityEndpoint = (& curl.exe -s -D - -o NUL -b $ownerCookie "$script:baseUrl/pages/cenro/permit-inspection-action.php") -join "`n"
    Assert-Check ($communityEndpoint -match 'HTTP/1\.1 302' -and $communityEndpoint -match 'Location:\s*\.\./community/dashboard\.php') 'Community users cannot enter the inspection action endpoint.'
    $emsEndpoint = (& curl.exe -s -D - -o NUL -b $emsCookie "$script:baseUrl/pages/cenro/permit-inspection-action.php") -join "`n"
    Assert-Check ($emsEndpoint -match 'HTTP/1\.1 302' -and $emsEndpoint -match 'Location:\s*\.\./ems/dashboard\.php') 'EMS users cannot enter the inspection action endpoint.'
    $ownerPage = Get-CommunityDetail $ownerCookie $applicationId
    Assert-Check ($ownerPage -match 'Site Inspection' -and $ownerPage -match 'Pending assessment' -and $ownerPage -notmatch 'Mark inspection required') 'The Community owner has read-only inspection visibility.'
    $otherStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $otherCookie "$script:baseUrl/pages/community/permit-application.php?id=$applicationId"
    Assert-Check ($otherStatus -eq '404') 'Another Community user cannot view the owner inspection information.'

    $token = Get-InspectionToken $rpsPage
    $marked = Invoke-InspectionAction $rpsCookie $applicationId $token 0 'mark_required' @{}
    Assert-Check ($marked -match 'HTTP/1\.1 303' -and $marked -match 'permit-application\.php\?id=') 'Marking inspection required uses Post/Redirect/Get.'
    $requiredState = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(inspection_status,':',(SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$applicationId)) FROM tbl_permit_applications WHERE id=$applicationId"
    Assert-Check ($requiredState -eq 'required:1') 'The required assessment creates the first immutable inspection event.'

    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    $token = Get-InspectionToken $rpsPage
    $duplicate = Invoke-InspectionAction $rpsCookie $applicationId $token 0 'mark_required' @{}
    $duplicatePage = Get-RpsDetail $rpsCookie $applicationId
    $eventCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$applicationId")
    Assert-Check ($duplicate -match 'HTTP/1\.1 303' -and $duplicatePage -match 'changed before this action' -and $eventCount -eq 1) 'A stale duplicate action is rejected without adding history.'

    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    $token = Get-InspectionToken $rpsPage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $scheduleOne = (Get-Date).AddDays(3).ToString('yyyy-MM-ddTHH:mm')
    $scheduled = Invoke-InspectionAction $rpsCookie $applicationId $token $latestId 'schedule' @{
        scheduled_at=$scheduleOne; inspector_user_id="$assigneeId"; inspection_location='Inspection Property, Poblacion, Sta. Cruz, Laguna'; latitude='14.2790000'; longitude='121.4160000'; inspection_notes='Initial site schedule.'
    }
    Assert-Check ($scheduled -match 'HTTP/1\.1 303') 'Authorized RPS can schedule and assign an inspection.'
    $scheduleStored = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(inspection_status,':',inspector_user_id,':',latitude,':',longitude) FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1"
    Assert-Check ($scheduleStored -eq "scheduled:$assigneeId`:14.2790000:121.4160000") 'Assignment, schedule location, and supported coordinates are stored on the schedule event.'
    $ownerScheduled = Get-CommunityDetail $ownerCookie $applicationId
    Assert-Check ($ownerScheduled -match 'Scheduled' -and $ownerScheduled -match 'Inspection Assignee' -and $ownerScheduled -notmatch 'site\.png') 'The owner sees appropriate schedule information without protected inspection photos.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $scheduleTwo = (Get-Date).AddDays(4).ToString('yyyy-MM-ddTHH:mm')
    $rescheduled = Invoke-InspectionAction $assigneeCookie $applicationId $token $latestId 'reschedule' @{
        scheduled_at=$scheduleTwo; inspector_user_id="$assigneeId"; inspection_location='Inspection Property, Poblacion, Sta. Cruz, Laguna'; latitude='14.2790000'; longitude='121.4160000'; inspection_notes='Applicant requested a later schedule.'
    }
    Assert-Check ($rescheduled -match 'HTTP/1\.1 303') 'Assigned authorized personnel can reschedule the inspection.'
    $scheduleEvents = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$applicationId AND inspection_status IN ('scheduled','rescheduled')")
    Assert-Check ($scheduleEvents -eq 2) 'Rescheduling appends a new event and preserves the original schedule.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $started = Invoke-InspectionAction $assigneeCookie $applicationId $token $latestId 'start' @{}
    Assert-Check ($started -match 'HTTP/1\.1 303') 'Authorized personnel can mark a scheduled inspection in progress.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $missingFindings = Invoke-InspectionCompletion $assigneeCookie $applicationId $token $latestId $treeOneId $treeTwoId 'failed' '0' '1' '0' '' '' '' $false
    $missingPage = Get-RpsDetail $assigneeCookie $applicationId
    $afterMissingCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$applicationId")
    Assert-Check ($missingFindings -match 'HTTP/1\.1 303' -and $missingPage -match 'Inspection findings are required' -and $afterMissingCount -eq 4) 'Completion without required findings fails server-side and writes no partial event.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $spoofed = Invoke-InspectionCompletion $assigneeCookie $applicationId $token $latestId $treeOneId $treeTwoId 'failed' '0' '1' '0' $spoofedJpg 'spoofed.jpg' 'image/jpeg'
    $spoofedPage = Get-RpsDetail $assigneeCookie $applicationId
    Assert-Check ($spoofed -match 'HTTP/1\.1 303' -and $spoofedPage -match 'file content does not match its extension') 'Spoofed site-photo content is rejected by actual MIME validation.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $oversized = Invoke-InspectionCompletion $assigneeCookie $applicationId $token $latestId $treeOneId $treeTwoId 'failed' '0' '1' '0' $oversizedPng 'oversized.png' 'image/png'
    $oversizedPage = Get-RpsDetail $assigneeCookie $applicationId
    Assert-Check ($oversized -match 'HTTP/1\.1 303' -and $oversizedPage -match 'exceeds the 10 MB size limit') 'The configurable per-photo size limit is enforced.'

    $assigneePage = Get-RpsDetail $assigneeCookie $applicationId
    $token = Get-InspectionToken $assigneePage
    $failed = Invoke-InspectionCompletion $assigneeCookie $applicationId $token $latestId $treeOneId $treeTwoId 'failed' '0' '1' '0' $validPng 'site.png' 'image/png'
    Assert-Check ($failed -match 'HTTP/1\.1 303') 'A complete failed verification with a valid site photo uses Post/Redirect/Get.'
    $failedState = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(a.inspection_status,':',i.completed_by_user_id,':',(SELECT COUNT(*) FROM tbl_permit_inspection_tree_verifications tv WHERE tv.inspection_id=i.id),':',(SELECT COUNT(*) FROM tbl_permit_inspection_photos ph WHERE ph.inspection_id=i.id)) FROM tbl_permit_applications a INNER JOIN tbl_permit_inspections i ON i.application_id=a.id WHERE a.id=$applicationId ORDER BY i.id DESC LIMIT 1"
    Assert-Check ($failedState -eq "failed:$assigneeId`:2:1") 'Completion stores the failed result, completing personnel, every tree verification, and photo metadata.'
    $photoId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspection_photos WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $photoStorage = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(original_filename,':',storage_path,':',mime_type) FROM tbl_permit_inspection_photos WHERE id=$photoId"
    Assert-Check ($photoStorage -match '^site\.png:\d{4}/TCP-2097-\d{6}/[a-f0-9]{64}\.png:image/png$') 'The original photo name is display-only and storage uses a random private filename.'

    $rpsPhotoHeaders = (& curl.exe -s -I -b $rpsCookie "$script:baseUrl/pages/cenro/permit-inspection-photo.php?id=$photoId") -join "`n"
    Assert-Check ($rpsPhotoHeaders -match 'HTTP/1\.1 200' -and $rpsPhotoHeaders -match 'Content-Type:\s*image/png' -and $rpsPhotoHeaders -match 'Cache-Control:\s*private, no-store') 'Authorized RPS photo delivery is controlled, no-store, and nosniff.'
    $superPhotoStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $authorizedSuperCookie "$script:baseUrl/pages/cenro/permit-inspection-photo.php?id=$photoId"
    Assert-Check ($superPhotoStatus -eq '200') 'A specifically authorized Superadmin may view protected inspection evidence.'
    $unauthorizedPhotoStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $unauthorizedSuperCookie "$script:baseUrl/pages/cenro/permit-inspection-photo.php?id=$photoId"
    Assert-Check ($unauthorizedPhotoStatus -eq '404') 'An unpermitted Superadmin cannot retrieve inspection photos.'
    $communityPhoto = (& curl.exe -s -D - -o NUL -b $ownerCookie "$script:baseUrl/pages/cenro/permit-inspection-photo.php?id=$photoId") -join "`n"
    Assert-Check ($communityPhoto -match 'HTTP/1\.1 302' -and $communityPhoto -match 'Location:\s*\.\./community/dashboard\.php') 'Community users cannot access the sensitive inspection-photo endpoint.'
    $missingPhotoStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $rpsCookie "$script:baseUrl/pages/cenro/permit-inspection-photo.php?id=999999999"
    Assert-Check ($missingPhotoStatus -eq '404') 'Missing inspection photos return a safe not-found response.'

    $blockedAdvance = & $php tests/permit_inspection_status_probe.php $applicationId $rpsId
    Assert-Check ($blockedAdvance -match 'passed site inspection') 'A failed inspection blocks advancement to the decision step.'

    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    $token = Get-InspectionToken $rpsPage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $followUpDate = (Get-Date).AddDays(5).ToString('yyyy-MM-ddTHH:mm')
    $followUp = Invoke-InspectionAction $rpsCookie $applicationId $token $latestId 'follow_up' @{
        scheduled_at=$followUpDate; inspector_user_id="$rpsId"; inspection_location='Inspection Property, Poblacion, Sta. Cruz, Laguna'; latitude='14.2790000'; longitude='121.4160000'; inspection_notes='Follow-up after failed tree verification.'
    }
    Assert-Check ($followUp -match 'HTTP/1\.1 303') 'Authorized RPS can schedule a linked follow-up inspection.'
    $followUpLink = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(inspection_status,':',follow_up_of_inspection_id,':',previous_inspection_id) FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1"
    Assert-Check ($followUpLink -match '^scheduled:\d+:\d+$') 'The follow-up event is linked to both the prior completion and immediate history.'

    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    $token = Get-InspectionToken $rpsPage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    Invoke-InspectionAction $rpsCookie $applicationId $token $latestId 'start' @{} | Out-Null
    $rpsPage = Get-RpsDetail $rpsCookie $applicationId
    $token = Get-InspectionToken $rpsPage
    $latestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_inspections WHERE application_id=$applicationId ORDER BY id DESC LIMIT 1")
    $passed = Invoke-InspectionCompletion $rpsCookie $applicationId $token $latestId $treeOneId $treeTwoId 'passed' '1' '1' '1' '' '' ''
    Assert-Check ($passed -match 'HTTP/1\.1 303') 'A follow-up may be completed as passed when every required confirmation succeeds.'
    $postInspectionState = & $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(application_status,':',inspection_status,':',decision_status) FROM tbl_permit_applications WHERE id=$applicationId"
    Assert-Check ($postInspectionState -eq 'under_review:passed:pending') 'Passing inspection updates only the inspection domain and does not approve the permit.'
    $advanced = & $php tests/permit_inspection_status_probe.php $applicationId $rpsId
    Assert-Check ($advanced -eq 'awaiting_decision') 'A separate authorized status action may advance a passed application to decision review.'

    $lockedPage = Get-RpsDetail $rpsCookie $lockedApplicationId
    $lockedToken = Get-InspectionToken $lockedPage
    $lockedAction = Invoke-InspectionAction $rpsCookie $lockedApplicationId $lockedToken 0 'mark_required' @{}
    $lockedFeedback = Get-RpsDetail $rpsCookie $lockedApplicationId
    $lockedCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$lockedApplicationId")
    Assert-Check ($lockedAction -match 'HTTP/1\.1 303' -and $lockedFeedback -match 'unavailable or locked' -and $lockedCount -eq 0) 'Approved or otherwise locked transactions reject inspection mutations.'

    $historyCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_status_history WHERE application_id=$applicationId AND status_domain='inspection'")
    $auditCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_audit_trail WHERE actor_user_id IN ($rpsId,$assigneeId) AND entity_type='permit_inspection'")
    $notificationCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$applicationId AND title='Permit site inspection updated'")
    $inspectionCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_inspections WHERE application_id=$applicationId")
    Assert-Check ($historyCount -eq $inspectionCount -and $auditCount -eq $inspectionCount -and $notificationCount -eq $inspectionCount) 'Every persisted inspection action has matching status history, responsible-user audit, and applicant notification.'

    Write-Output 'PERMIT INSPECTION HTTP VALIDATION COMPLETE'
}
finally {
    foreach ($applicationIdToClean in $applicationIds) {
        if ($applicationIdToClean -gt 0) {
            $storedPaths = @(& $mysql -u root -N -B certreefy_db -e "SELECT storage_path FROM tbl_permit_inspection_photos WHERE application_id=$applicationIdToClean")
            foreach ($storedPath in $storedPaths) {
                if ($storedPath) {
                    $filePath = Join-Path $storageRoot ($storedPath -replace '/', '\')
                    if (Test-Path -LiteralPath $filePath) { Remove-Item -LiteralPath $filePath -Force }
                }
            }
            $cleanupApplication = @"
DELETE FROM tbl_permit_inspection_photos WHERE application_id=$applicationIdToClean;
DELETE FROM tbl_permit_inspection_tree_verifications WHERE application_id=$applicationIdToClean;
UPDATE tbl_permit_inspections SET previous_inspection_id=NULL, follow_up_of_inspection_id=NULL WHERE application_id=$applicationIdToClean;
DELETE FROM tbl_permit_inspections WHERE application_id=$applicationIdToClean;
DELETE FROM tbl_permit_trees WHERE application_id=$applicationIdToClean;
DELETE FROM tbl_permit_status_history WHERE application_id=$applicationIdToClean;
DELETE FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id=$applicationIdToClean;
DELETE FROM tbl_audit_trail WHERE (entity_type='permit_application' AND entity_id=$applicationIdToClean) OR (entity_type='permit_inspection' AND JSON_EXTRACT(details,'$.application_id')=$applicationIdToClean);
DELETE FROM tbl_permit_applications WHERE id=$applicationIdToClean;
"@
            & $mysql -u root certreefy_db -e $cleanupApplication | Out-Null
        }
    }
    if ($userIds.Count -gt 0) {
        $ids = $userIds -join ','
        & $mysql -u root certreefy_db -e "DELETE FROM tbl_user_permissions WHERE user_id IN ($ids) OR granted_by_user_id IN ($ids); DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($ids); DELETE FROM tbl_notifications WHERE recipient_user_id IN ($ids) OR created_by_user_id IN ($ids); DELETE FROM tbl_users WHERE id IN ($ids);" | Out-Null
    }
    foreach ($cookie in $cookies) { if (Test-Path -LiteralPath $cookie) { Remove-Item -LiteralPath $cookie -Force } }
    if (Test-Path -LiteralPath $tempDirectory) { Remove-Item -LiteralPath $tempDirectory -Recurse -Force }
}
