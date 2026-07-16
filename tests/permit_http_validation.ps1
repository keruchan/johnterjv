$ErrorActionPreference = 'Stop'

function Assert-Check([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Output "PASS: $Message"
}

$baseUrl = 'http://127.0.0.1/Certreefy'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$password = 'HttpValidation123!'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$communityUsername = "http_owner_$suffix"
$otherUsername = "http_other_$suffix"
$rpsUsername = "http_rps_$suffix"
$ownerCookie = Join-Path $env:TEMP "certreefy_owner_$suffix.cookies"
$otherCookie = Join-Path $env:TEMP "certreefy_other_$suffix.cookies"
$rpsCookie = Join-Path $env:TEMP "certreefy_rps_$suffix.cookies"
$userIds = @()
$applicationId = $null
$year = (Get-Date).Year
$previousSequence = @(& $mysql -u root -N -B certreefy_db -e "SELECT last_number FROM tbl_permit_transaction_sequences WHERE sequence_year=$year")

try {
    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $password
    $insertSql = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('HTTP','Owner','$communityUsername@example.test','09170000001','HTTP Address','$communityUsername','$hash','community','active'),
('HTTP','Other','$otherUsername@example.test','09170000002','HTTP Address','$otherUsername','$hash','community','active'),
('HTTP','RPS','$rpsUsername@example.test','09170000003','HTTP Address','$rpsUsername','$hash','rps','active');
SELECT id FROM tbl_users WHERE username IN ('$communityUsername','$otherUsername','$rpsUsername') ORDER BY id;
"@
    $userIds = @(& $mysql -u root -N -B certreefy_db -e $insertSql)
    Assert-Check ($userIds.Count -eq 3) 'HTTP validation users were created.'

    $unauthHeaders = & curl.exe -s -D - -o NUL "$baseUrl/pages/community/permit-applications.php"
    Assert-Check (($unauthHeaders -join "`n") -match 'HTTP/1\.1 302') 'Unauthenticated permit-list access redirects to login.'

    function Login-TestUser([string] $Username, [string] $CookiePath) {
        $loginHtml = (& curl.exe -s -c $CookiePath "$baseUrl/pages/auth/login.php") -join "`n"
        $csrfMatch = [regex]::Match($loginHtml, 'name="csrf_token" value="([^"]+)"')
        if (-not $csrfMatch.Success) { throw 'Unable to extract login CSRF token.' }
        $headers = (& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
            --data-urlencode "csrf_token=$($csrfMatch.Groups[1].Value)" `
            --data-urlencode "login_identifier=$Username" `
            --data-urlencode "password=$password" `
            "$baseUrl/pages/auth/login.php") -join "`n"
        if ($headers -notmatch 'HTTP/1\.1 302') { throw "Login failed for $Username" }
    }

    Login-TestUser $communityUsername $ownerCookie
    $listHtml = (& curl.exe -s -b $ownerCookie "$baseUrl/pages/community/permit-applications.php") -join "`n"
    Assert-Check ($listHtml -match 'My Applications' -and $listHtml -match 'New application') 'Authorized Community user can render the owner-scoped registry.'
    Assert-Check ($listHtml -match 'aria-current="page"' -and $listHtml -match 'href="permit-applications.php"') 'Tree Permit navigation is active and linked.'

    $formHtml = (& curl.exe -s -b $ownerCookie "$baseUrl/pages/community/permit-application.php") -join "`n"
    Assert-Check ($formHtml -match 'name="viewport"' -and $formHtml -match '../../css/dashboard.css') 'Permit form preserves the responsive Community dashboard shell.'
    Assert-Check ($formHtml -match 'name="district"' -and $formHtml -match 'name="trees\[0\]\[quantity\]"' -and $formHtml -match 'Save draft') 'Permit form renders location, related-tree, and draft controls.'
    $csrf = [regex]::Match($formHtml, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    $submissionKey = [regex]::Match($formHtml, 'name="submission_key" value="([^"]+)"').Groups[1].Value

    $draftHeaders = (& curl.exe -s -D - -o NUL -b $ownerCookie -c $ownerCookie `
        --data-urlencode "csrf_token=$csrf" `
        --data-urlencode "submission_key=$submissionKey" `
        --data-urlencode 'application_id=' `
        --data-urlencode 'action=save_draft' `
        --data-urlencode 'applicant_type=individual' `
        "$baseUrl/pages/community/permit-application.php") -join "`n"
    $locationMatch = [regex]::Match($draftHeaders, 'Location:\s*permit-application\.php\?id=(\d+)', 'IgnoreCase')
    Assert-Check ($draftHeaders -match 'HTTP/1\.1 302' -and $locationMatch.Success) 'Draft POST uses Post/Redirect/Get.'
    $applicationId = [int] $locationMatch.Groups[1].Value

    $draftHtml = (& curl.exe -s -b $ownerCookie "$baseUrl/pages/community/permit-application.php?id=$applicationId") -join "`n"
    Assert-Check ($draftHtml -match 'Draft saved successfully' -and $draftHtml -match 'Generated on submission') 'Draft success is clear and confirms no transaction ID exists.'
    $csrf = [regex]::Match($draftHtml, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    $submissionKey = [regex]::Match($draftHtml, 'name="submission_key" value="([^"]+)"').Groups[1].Value

    $invalidHtml = (& curl.exe -s -b $ownerCookie -c $ownerCookie `
        --data-urlencode "csrf_token=$csrf" `
        --data-urlencode "submission_key=$submissionKey" `
        --data-urlencode "application_id=$applicationId" `
        --data-urlencode 'action=submit' `
        --data-urlencode 'applicant_type=individual' `
        "$baseUrl/pages/community/permit-application.php") -join "`n"
    Assert-Check ($invalidHtml -match 'District is required' -and $invalidHtml -match 'At least one tree record is required') 'Server validation renders required-field and tree errors.'

    $validHeaders = (& curl.exe -s -D - -o NUL -b $ownerCookie -c $ownerCookie `
        --data-urlencode "csrf_token=$csrf" `
        --data-urlencode "submission_key=$submissionKey" `
        --data-urlencode "application_id=$applicationId" `
        --data-urlencode 'action=submit' `
        --data-urlencode 'applicant_type=individual' `
        --data-urlencode 'property_relationship=owner' `
        --data-urlencode 'property_classification=private_property' `
        --data-urlencode 'property_owner_name=HTTP Owner' `
        --data-urlencode 'district=District 3' `
        --data-urlencode 'province=Laguna' `
        --data-urlencode 'municipality=Sta. Cruz' `
        --data-urlencode 'barangay=Poblacion' `
        --data-urlencode 'property_address=HTTP Validation Property' `
        --data-urlencode 'cutting_purpose=Remove a hazardous tree' `
        --data-urlencode 'declaration_confirmed=1' `
        --data-urlencode 'trees[0][common_name]=Narra' `
        --data-urlencode 'trees[0][quantity]=1' `
        "$baseUrl/pages/community/permit-application.php") -join "`n"
    Assert-Check ($validHeaders -match 'HTTP/1\.1 302' -and $validHeaders -match 'Location:\s*permit-applications\.php') 'Successful final submission uses Post/Redirect/Get.'

    $submittedList = (& curl.exe -s -b $ownerCookie "$baseUrl/pages/community/permit-applications.php") -join "`n"
    Assert-Check ($submittedList -match 'Tree Cutting Permit application submitted successfully' -and $submittedList -match 'TCP-\d{4}-\d{6}') 'Submission success displays the generated transaction ID.'
    $submittedView = (& curl.exe -s -b $ownerCookie "$baseUrl/pages/community/permit-application.php?id=$applicationId") -join "`n"
    Assert-Check ($submittedView -match 'Submitted applications are read-only' -and $submittedView -notmatch 'name="action" value="save_draft"') 'Submitted application detail is read-only.'

    Login-TestUser $otherUsername $otherCookie
    $otherStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $otherCookie "$baseUrl/pages/community/permit-application.php?id=$applicationId"
    Assert-Check ($otherStatus -eq '404') 'Another Community user receives no access to the owner application.'

    Login-TestUser $rpsUsername $rpsCookie
    $rpsHeaders = (& curl.exe -s -D - -o NUL -b $rpsCookie "$baseUrl/pages/community/permit-applications.php") -join "`n"
    Assert-Check ($rpsHeaders -match 'HTTP/1\.1 302' -and $rpsHeaders -match 'Location:\s*\.\./cenro/dashboard\.php') 'Unauthorized RPS route access is redirected by server-side RBAC.'

    Write-Output 'HTTP VALIDATION COMPLETE'
}
finally {
    if ($applicationId) {
        $cleanupApplication = @"
DELETE FROM tbl_notifications WHERE entity_type='permit_application' AND entity_id=$applicationId;
DELETE FROM tbl_audit_trail WHERE entity_type='permit_application' AND entity_id=$applicationId;
DELETE FROM tbl_permit_status_history WHERE application_id=$applicationId;
DELETE FROM tbl_permit_trees WHERE application_id=$applicationId;
DELETE FROM tbl_permit_applications WHERE id=$applicationId;
"@
        & $mysql -u root certreefy_db -e $cleanupApplication | Out-Null
    }
    if ($previousSequence.Count -eq 0) {
        & $mysql -u root certreefy_db -e "DELETE FROM tbl_permit_transaction_sequences WHERE sequence_year=$year" | Out-Null
    }
    else {
        & $mysql -u root certreefy_db -e "UPDATE tbl_permit_transaction_sequences SET last_number=$($previousSequence[0]) WHERE sequence_year=$year" | Out-Null
    }
    if ($userIds.Count -gt 0) {
        $idList = $userIds -join ','
        $cleanupUsers = @"
DELETE FROM tbl_notifications WHERE recipient_user_id IN ($idList) OR created_by_user_id IN ($idList);
DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($idList);
DELETE FROM tbl_user_management_audit WHERE actor_user_id IN ($idList) OR target_user_id IN ($idList);
DELETE FROM tbl_users WHERE id IN ($idList);
"@
        & $mysql -u root certreefy_db -e $cleanupUsers | Out-Null
    }
    Remove-Item -LiteralPath $ownerCookie, $otherCookie, $rpsCookie -Force -ErrorAction SilentlyContinue
}
