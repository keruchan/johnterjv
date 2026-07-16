$ErrorActionPreference = 'Stop'

function Assert-Check([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Output "PASS: $Message"
}

function Invoke-Login([string] $Username, [string] $CookiePath) {
    $loginHtml = (& curl.exe -s -c $CookiePath "$script:baseUrl/pages/auth/login.php") -join "`n"
    $csrfMatch = [regex]::Match($loginHtml, 'name="csrf_token" value="([^"]+)"')
    if (-not $csrfMatch.Success) { throw 'Unable to extract login CSRF token.' }
    $headers = (& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        --data-urlencode "csrf_token=$($csrfMatch.Groups[1].Value)" `
        --data-urlencode "login_identifier=$Username" `
        --data-urlencode "password=$script:password" `
        "$script:baseUrl/pages/auth/login.php") -join "`n"
    if ($headers -notmatch 'HTTP/1\.1 302') { throw "Login failed for $Username" }
}

function Get-CommunityPage([string] $CookiePath, [int] $ApplicationId) {
    return ((& curl.exe -s -b $CookiePath "$script:baseUrl/pages/community/permit-application.php?id=$ApplicationId") -join "`n")
}

function Get-CommunityUploadToken([string] $Html) {
    $match = [regex]::Match($Html, '<form method="post" action="permit-document-upload\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline')
    if (-not $match.Success) { throw 'Unable to extract Community document CSRF token.' }
    return $match.Groups[1].Value
}

function Invoke-DocumentUpload(
    [string] $CookiePath,
    [int] $ApplicationId,
    [string] $DocumentType,
    [string] $Token,
    [string] $FilePath,
    [string] $Filename,
    [string] $Mime
) {
    $fileSpec = "document_file=@$FilePath;filename=$Filename;type=$Mime"
    return ((& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        -F "csrf_token=$Token" -F "application_id=$ApplicationId" `
        -F "document_type=$DocumentType" -F $fileSpec `
        "$script:baseUrl/pages/community/permit-document-upload.php") -join "`n")
}

function Invoke-Review(
    [string] $CookiePath,
    [int] $DocumentId,
    [string] $Token,
    [string] $Status,
    [string] $Notes
) {
    return ((& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
        --data-urlencode "csrf_token=$Token" `
        --data-urlencode "document_id=$DocumentId" `
        --data-urlencode "review_status=$Status" `
        --data-urlencode "review_notes=$Notes" `
        "$script:baseUrl/pages/cenro/permit-document-review.php") -join "`n")
}

$script:baseUrl = 'http://127.0.0.1/Certreefy'
$script:password = 'DocumentValidation123!'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$ownerUsername = "doc_owner_$suffix"
$otherUsername = "doc_other_$suffix"
$rpsUsername = "doc_rps_$suffix"
$superUsername = "doc_super_$suffix"
$emsUsername = "doc_ems_$suffix"
$ownerCookie = Join-Path $env:TEMP "certreefy_doc_owner_$suffix.cookies"
$otherCookie = Join-Path $env:TEMP "certreefy_doc_other_$suffix.cookies"
$rpsCookie = Join-Path $env:TEMP "certreefy_doc_rps_$suffix.cookies"
$superCookie = Join-Path $env:TEMP "certreefy_doc_super_$suffix.cookies"
$emsCookie = Join-Path $env:TEMP "certreefy_doc_ems_$suffix.cookies"
$tempDirectory = Join-Path $env:TEMP "certreefy_document_validation_$suffix"
$storageRoot = 'C:\xampp\private\certreefy\permit_documents'
$applicationIds = @()
$userIds = @()
$transactionIds = @()

try {
    New-Item -ItemType Directory -Path $tempDirectory | Out-Null
    $pngPath = Join-Path $tempDirectory 'valid.png'
    $validPdfPath = Join-Path $tempDirectory 'valid.pdf'
    $activePdfPath = Join-Path $tempDirectory 'active.pdf'
    $spoofedPdfPath = Join-Path $tempDirectory 'spoofed.pdf'
    $phpPath = Join-Path $tempDirectory 'dangerous.php'
    $oversizedPath = Join-Path $tempDirectory 'oversized.pdf'
    [IO.File]::WriteAllBytes($pngPath, [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))
    [IO.File]::WriteAllText($validPdfPath, "%PDF-1.4`n1 0 obj`n<< /Type /Catalog >>`nendobj`ntrailer`n<< /Root 1 0 R >>`n%%EOF")
    [IO.File]::WriteAllText($activePdfPath, "%PDF-1.4`n1 0 obj`n<< /JavaScript (alert) >>`nendobj`n%%EOF")
    [IO.File]::WriteAllText($spoofedPdfPath, 'This is not a PDF file.')
    [IO.File]::WriteAllText($phpPath, '<?php echo "unsafe"; ?>')
    $stream = [IO.File]::Create($oversizedPath)
    try {
        $header = [Text.Encoding]::ASCII.GetBytes("%PDF-1.4`n")
        $stream.Write($header, 0, $header.Length)
        $stream.SetLength((10 * 1024 * 1024) + 1024)
        $stream.Seek(-6, [IO.SeekOrigin]::End) | Out-Null
        $footer = [Text.Encoding]::ASCII.GetBytes('%%EOF')
        $stream.Write($footer, 0, $footer.Length)
    }
    finally { $stream.Dispose() }

    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $script:password
    $insertUsers = @"
INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES
('Document','Owner','$ownerUsername@example.test','09170000001','Test Address','$ownerUsername','$hash','community','active'),
('Document','Other','$otherUsername@example.test','09170000002','Test Address','$otherUsername','$hash','community','active'),
('Document','RPS','$rpsUsername@example.test','09170000003','Test Address','$rpsUsername','$hash','rps','active'),
('Document','Superadmin','$superUsername@example.test','09170000004','Test Address','$superUsername','$hash','superadmin','active'),
('Document','EMS','$emsUsername@example.test','09170000005','Test Address','$emsUsername','$hash','ems','active');
"@
    & $mysql -u root certreefy_db -e $insertUsers | Out-Null
    $ownerId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$ownerUsername'")
    $otherId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$otherUsername'")
    $rpsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$rpsUsername'")
    $superId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$superUsername'")
    $emsId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_users WHERE username='$emsUsername'")
    $userIds = @($ownerId, $otherId, $rpsId, $superId, $emsId)

    $number = Get-Random -Minimum 700000 -Maximum 899990
    $mainTransaction = 'TCP-2099-' + $number.ToString('000000')
    $lockedTransaction = 'TCP-2099-' + ($number + 1).ToString('000000')
    $transactionIds = @($mainTransaction, $lockedTransaction)
    $key1 = 'a' * 64
    $key2 = 'b' * 64
    $key3 = 'c' * 64
    $insertApplications = @"
INSERT INTO tbl_permit_applications
(transaction_id,submission_key,applicant_user_id,applicant_name,applicant_contact,applicant_address,applicant_type,property_relationship,property_classification,property_owner_name,property_address,district,barangay,municipality,province,cutting_purpose,application_status,document_status,inspection_status,decision_status,donation_status,release_status,validity_status,declaration_confirmed_at,submitted_at)
VALUES
('$mainTransaction','$key1',$ownerId,'Document Owner','09170000001','Test Address','individual','owner','private_property','Document Owner','Test Property','District 3','Poblacion','Sta. Cruz','Laguna','Safety validation','submitted','pending','pending_assessment','pending','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
('$lockedTransaction','$key2',$ownerId,'Document Owner','09170000001','Test Address','individual','owner','private_property','Document Owner','Locked Property','District 3','Poblacion','Sta. Cruz','Laguna','Locked validation','approved','pending','pending_assessment','approved','not_required','not_ready','not_issued',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
(NULL,'$key3',$ownerId,'Document Owner','09170000001','Test Address','individual','owner',NULL,'Document Owner',NULL,NULL,NULL,NULL,'Laguna',NULL,'draft','pending','pending_assessment','pending','not_required','not_ready','not_issued',NULL,NULL);
"@
    & $mysql -u root certreefy_db -e $insertApplications | Out-Null
    $mainApplicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$mainTransaction'")
    $lockedApplicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE transaction_id='$lockedTransaction'")
    $draftApplicationId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_applications WHERE submission_key='$key3' AND applicant_user_id=$ownerId")
    $applicationIds = @($mainApplicationId, $lockedApplicationId, $draftApplicationId)

    Invoke-Login $ownerUsername $ownerCookie
    Invoke-Login $otherUsername $otherCookie
    Invoke-Login $rpsUsername $rpsCookie
    Invoke-Login $superUsername $superCookie
    Invoke-Login $emsUsername $emsCookie

    $communityPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($communityPage -match 'Scanned Documents' -and $communityPage -match 'do not replace required original hardcopy') 'Community detail reuses the permit page and displays the supplemental-scan warning.'
    Assert-Check ($communityPage -match 'class="app-shell"' -and $communityPage -match 'mobile-topbar' -and $communityPage -match 'offcanvas-registry') 'Community document presentation retains the responsive dashboard shell and mobile navigation pattern.'
    Assert-Check ($communityPage -match 'Application or request document' -and $communityPage -match 'Property ownership or authorization') 'Required digital document types are displayed.'
    $token = Get-CommunityUploadToken $communityPage

    $missingHeaders = (& curl.exe -s -D - -o NUL -b $ownerCookie -c $ownerCookie `
        -F "csrf_token=$token" -F "application_id=$mainApplicationId" -F 'document_type=application_request' `
        "$script:baseUrl/pages/community/permit-document-upload.php") -join "`n"
    Assert-Check ($missingHeaders -match 'HTTP/1\.1 302') 'Missing-file upload uses Post/Redirect/Get.'
    $missingPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($missingPage -match 'Select a file to upload') 'Missing file is rejected with a validation message.'

    $token = Get-CommunityUploadToken $missingPage
    $invalidHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $token $phpPath 'dangerous.php' 'application/x-httpd-php'
    Assert-Check ($invalidHeaders -match 'HTTP/1\.1 302') 'Executable-extension upload is rejected through PRG.'
    $invalidPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($invalidPage -match 'Only PDF, JPG, JPEG, and PNG files are allowed') 'Invalid and executable extensions are rejected.'

    $token = Get-CommunityUploadToken $invalidPage
    $spoofHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $token $spoofedPdfPath 'spoofed.pdf' 'application/pdf'
    Assert-Check ($spoofHeaders -match 'HTTP/1\.1 302') 'Spoofed MIME upload is rejected through PRG.'
    $spoofPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($spoofPage -match 'file content does not match its extension') 'Actual file MIME is checked instead of trusting the request type.'

    $token = Get-CommunityUploadToken $spoofPage
    $activeHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $token $activePdfPath 'active.pdf' 'application/pdf'
    Assert-Check ($activeHeaders -match 'HTTP/1\.1 302') 'Active-content PDF is rejected through PRG.'
    $activePage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($activePage -match 'active or embedded content are not allowed') 'PDF active and embedded content tokens are rejected.'

    $token = Get-CommunityUploadToken $activePage
    $oversizedHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $token $oversizedPath 'oversized.pdf' 'application/pdf'
    Assert-Check ($oversizedHeaders -match 'HTTP/1\.1 302') 'Oversized upload is rejected through PRG.'
    $oversizedPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($oversizedPage -match 'exceeds the 10 MB size limit') 'Configured 10 MB application limit is enforced.'

    $otherPage = Get-CommunityPage $otherCookie $mainApplicationId
    Assert-Check ($otherPage -match 'permit application was not found') 'Another Community user cannot view the permit detail.'
    $otherUploadStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $otherCookie `
        -F 'csrf_token=invalid' -F "application_id=$mainApplicationId" -F 'document_type=application_request' `
        -F "document_file=@$pngPath;filename=scan.png;type=image/png" `
        "$script:baseUrl/pages/community/permit-document-upload.php"
    Assert-Check ($otherUploadStatus -eq '404') 'Another Community user cannot upload to the owner transaction.'

    $rpsUploadHeaders = (& curl.exe -s -D - -o NUL -b $rpsCookie `
        -F 'csrf_token=invalid' -F "application_id=$mainApplicationId" -F 'document_type=application_request' `
        -F "document_file=@$pngPath;filename=scan.png;type=image/png" `
        "$script:baseUrl/pages/community/permit-document-upload.php") -join "`n"
    Assert-Check ($rpsUploadHeaders -match 'HTTP/1\.1 302' -and $rpsUploadHeaders -match 'Location:\s*\.\./cenro/dashboard\.php') 'RPS cannot use the Community upload endpoint.'

    $token = Get-CommunityUploadToken $oversizedPage
    $validHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $token $pngPath 'scan.png' 'image/png'
    Assert-Check ($validHeaders -match 'HTTP/1\.1 302') 'Valid image upload uses Post/Redirect/Get.'
    $validPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($validPage -match 'Document scan uploaded successfully' -and $validPage -match 'Pending review') 'Valid upload is shown as pending review and is not auto-verified.'

    $token = Get-CommunityUploadToken $validPage
    $duplicateHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'applicant_identification' $token $pngPath 'scan.png' 'image/png'
    Assert-Check ($duplicateHeaders -match 'HTTP/1\.1 302') 'A duplicate display filename uploads without a server filename collision.'
    $duplicatePage = Get-CommunityPage $ownerCookie $mainApplicationId
    $distinctPaths = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(DISTINCT storage_path) FROM tbl_permit_documents WHERE application_id=$mainApplicationId AND original_filename='scan.png'")
    Assert-Check ($distinctPaths -eq 2) 'Duplicate original filenames have distinct random storage paths.'

    $token = Get-CommunityUploadToken $duplicatePage
    $pdfHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'supporting_document' $token $validPdfPath 'supporting.pdf' 'application/pdf'
    Assert-Check ($pdfHeaders -match 'HTTP/1\.1 302') 'Valid passive PDF scan is accepted.'
    $duplicatePage = Get-CommunityPage $ownerCookie $mainApplicationId

    $applicationRequestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$mainApplicationId AND document_type='application_request' AND is_current=1")
    $identityId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$mainApplicationId AND document_type='applicant_identification' AND is_current=1")
    $storedPath = & $mysql -u root -N -B certreefy_db -e "SELECT storage_path FROM tbl_permit_documents WHERE id=$applicationRequestId"
    $absoluteStoredPath = Join-Path $storageRoot ($storedPath -replace '/', '\')
    Assert-Check ((Test-Path -LiteralPath $absoluteStoredPath) -and -not $absoluteStoredPath.StartsWith('C:\xampp\htdocs', [StringComparison]::OrdinalIgnoreCase)) 'Uploaded scan is stored outside the public web root.'

    $ownerDownload = Join-Path $tempDirectory 'owner-download.png'
    $ownerHeadersFile = Join-Path $tempDirectory 'owner-download.headers'
    & curl.exe -s -D $ownerHeadersFile -o $ownerDownload -b $ownerCookie "$script:baseUrl/pages/community/permit-document-download.php?id=$applicationRequestId"
    $ownerHeaders = Get-Content -Raw $ownerHeadersFile
    Assert-Check ((Get-FileHash $ownerDownload).Hash -eq (Get-FileHash $pngPath).Hash) 'Authorized Community download returns the stored file content.'
    Assert-Check ($ownerHeaders -match 'Content-Disposition: attachment' -and $ownerHeaders -match 'filename="scan.png"' -and $ownerHeaders -match 'X-Content-Type-Options: nosniff' -and $ownerHeaders -match 'Cache-Control: private, no-store') 'Download uses safe attachment, nosniff, and no-store headers.'

    $rpsDownloadStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $rpsCookie "$script:baseUrl/pages/cenro/permit-document-download.php?id=$applicationRequestId"
    Assert-Check ($rpsDownloadStatus -eq '200') 'RPS can download a submitted permit scan through the controlled endpoint.'
    $otherDownloadStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $otherCookie "$script:baseUrl/pages/community/permit-document-download.php?id=$applicationRequestId"
    Assert-Check ($otherDownloadStatus -eq '404') 'Another Community user cannot download the owner scan.'
    $superDownloadHeaders = (& curl.exe -s -D - -o NUL -b $superCookie "$script:baseUrl/pages/cenro/permit-document-download.php?id=$applicationRequestId") -join "`n"
    Assert-Check ($superDownloadHeaders -match 'HTTP/1\.1 404') 'A CENRO Superadmin without the specific original-verification permission is denied the document endpoint.'
    $emsDownloadHeaders = (& curl.exe -s -D - -o NUL -b $emsCookie "$script:baseUrl/pages/cenro/permit-document-download.php?id=$applicationRequestId") -join "`n"
    Assert-Check ($emsDownloadHeaders -match 'HTTP/1\.1 302' -and $emsDownloadHeaders -match 'Location:\s*\.\./ems/dashboard\.php') 'EMS is denied the RPS-only document endpoint.'

    $rpsDetail = (& curl.exe -s -b $rpsCookie "$script:baseUrl/pages/cenro/permit-application.php?id=$mainApplicationId") -join "`n"
    Assert-Check ($rpsDetail -match 'Permit Document Review' -and $rpsDetail -match 'Online scan acceptance does not verify') 'RPS detail keeps online scan review distinct from original verification.'
    Assert-Check ($rpsDetail -match 'class="app-shell"' -and $rpsDetail -match 'mobile-topbar' -and $rpsDetail -match 'class="modal fade"') 'RPS document review retains the responsive CENRO shell and Bootstrap modal pattern.'
    $reviewTokenMatch = [regex]::Match($rpsDetail, 'action="permit-document-review\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline')
    Assert-Check $reviewTokenMatch.Success 'RPS review form includes a CSRF token.'
    $reviewHeaders = Invoke-Review $rpsCookie $applicationRequestId $reviewTokenMatch.Groups[1].Value 'replacement_required' 'Upload a clearer scan.'
    Assert-Check ($reviewHeaders -match 'HTTP/1\.1 302') 'RPS replacement request uses Post/Redirect/Get.'
    $reviewedStatus = & $mysql -u root -N -B certreefy_db -e "SELECT verification_status FROM tbl_permit_documents WHERE id=$applicationRequestId"
    $summaryStatus = & $mysql -u root -N -B certreefy_db -e "SELECT document_status FROM tbl_permit_applications WHERE id=$mainApplicationId"
    Assert-Check ($reviewedStatus -eq 'replacement_required' -and $summaryStatus -eq 'incomplete') 'RPS review stores replacement-required and updates the document summary to incomplete.'

    $rpsDetail = (& curl.exe -s -b $rpsCookie "$script:baseUrl/pages/cenro/permit-application.php?id=$mainApplicationId") -join "`n"
    $reviewToken = [regex]::Match($rpsDetail, 'action="permit-document-review\.php".*?name="csrf_token" value="([^"]+)"', 'Singleline').Groups[1].Value
    $acceptedHeaders = Invoke-Review $rpsCookie $identityId $reviewToken 'accepted' ''
    Assert-Check ($acceptedHeaders -match 'HTTP/1\.1 302') 'RPS can accept an individual online scan.'
    $identityStatus = & $mysql -u root -N -B certreefy_db -e "SELECT verification_status FROM tbl_permit_documents WHERE id=$identityId"
    Assert-Check ($identityStatus -eq 'accepted') 'Accepted status is stored without marking original documents verified.'

    $replacementPage = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($replacementPage -match 'Upload a clearer scan' -and $replacementPage -match 'Upload replacement') 'Community sees RPS notes and a replacement control.'
    $replacementToken = Get-CommunityUploadToken $replacementPage
    $replacementHeaders = Invoke-DocumentUpload $ownerCookie $mainApplicationId 'application_request' $replacementToken $pngPath 'scan.png' 'image/png'
    Assert-Check ($replacementHeaders -match 'HTTP/1\.1 302') 'Permitted replacement upload uses Post/Redirect/Get.'
    $newApplicationRequestId = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT id FROM tbl_permit_documents WHERE application_id=$mainApplicationId AND document_type='application_request' AND is_current=1")
    $replacementLink = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT replaces_document_id FROM tbl_permit_documents WHERE id=$newApplicationRequestId")
    $oldArchiveState = (& $mysql -u root -N -B certreefy_db -e "SELECT CONCAT(is_current,':',IF(archived_at IS NULL,0,1)) FROM tbl_permit_documents WHERE id=$applicationRequestId")
    $reviewHistoryCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_document_reviews WHERE document_id=$applicationRequestId AND review_status='replacement_required'")
    Assert-Check ($replacementLink -eq $applicationRequestId -and $oldArchiveState -eq '0:1' -and $reviewHistoryCount -eq 1) 'Replacement links the new scan and preserves the archived file plus its review history.'
    $replacementView = Get-CommunityPage $ownerCookie $mainApplicationId
    Assert-Check ($replacementView -match 'Replacement History' -and $replacementView -match 'Archived - Replacement Required') 'Replacement history is visible on the permit detail.'

    $lockedPage = Get-CommunityPage $ownerCookie $lockedApplicationId
    Assert-Check ($lockedPage -match 'locked for document uploads') 'Locked transaction explains that uploads are disabled.'
    $lockedTokenMatch = [regex]::Match($lockedPage, 'csrf_permit_document_token')
    $mainToken = Get-CommunityUploadToken $replacementView
    $lockedUploadHeaders = Invoke-DocumentUpload $ownerCookie $lockedApplicationId 'application_request' $mainToken $pngPath 'locked.png' 'image/png'
    Assert-Check ($lockedUploadHeaders -match 'HTTP/1\.1 302') 'Locked upload attempt is handled through PRG.'
    $lockedDocumentCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_documents WHERE application_id=$lockedApplicationId")
    Assert-Check ($lockedDocumentCount -eq 0) 'Locked transaction stores no uploaded document.'

    $draftPage = Get-CommunityPage $ownerCookie $draftApplicationId
    Assert-Check ($draftPage -match 'only after final application submission') 'Draft transaction displays the upload lock reason.'
    $draftUploadHeaders = Invoke-DocumentUpload $ownerCookie $draftApplicationId 'application_request' $mainToken $pngPath 'draft.png' 'image/png'
    Assert-Check ($draftUploadHeaders -match 'HTTP/1\.1 302') 'Draft upload attempt is denied through PRG.'
    $draftDocumentCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_permit_documents WHERE application_id=$draftApplicationId")
    Assert-Check ($draftDocumentCount -eq 0) 'Draft application stores no uploaded document.'

    $identityStoredPath = & $mysql -u root -N -B certreefy_db -e "SELECT storage_path FROM tbl_permit_documents WHERE id=$identityId"
    $identityAbsolutePath = Join-Path $storageRoot ($identityStoredPath -replace '/', '\')
    & $mysql -u root certreefy_db -e "UPDATE tbl_permit_documents SET storage_path='../htdocs/Certreefy/config/config.php' WHERE id=$identityId" | Out-Null
    $traversalDownloadStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $ownerCookie "$script:baseUrl/pages/community/permit-document-download.php?id=$identityId"
    & $mysql -u root certreefy_db -e "UPDATE tbl_permit_documents SET storage_path='$identityStoredPath' WHERE id=$identityId" | Out-Null
    Assert-Check ($traversalDownloadStatus -eq '404') 'A traversal-style stored path is rejected without exposing a public or application file.'
    Remove-Item -LiteralPath $identityAbsolutePath -Force
    $missingDownloadStatus = & curl.exe -s -o NUL -w '%{http_code}' -b $ownerCookie "$script:baseUrl/pages/community/permit-document-download.php?id=$identityId"
    Assert-Check ($missingDownloadStatus -eq '404') 'Missing private file returns a safe unavailable response.'

    $documentAuditCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_audit_trail WHERE actor_user_id IN ($ownerId,$rpsId) AND entity_type='permit_document'")
    $reviewNotificationCount = [int] (& $mysql -u root -N -B certreefy_db -e "SELECT COUNT(*) FROM tbl_notifications WHERE recipient_user_id=$ownerId AND entity_type='permit_application' AND entity_id=$mainApplicationId")
    Assert-Check ($documentAuditCount -ge 6 -and $reviewNotificationCount -eq 2) 'Uploads, replacements, and RPS reviews create responsible-user audit and notification records.'

    Write-Output 'PERMIT DOCUMENT HTTP VALIDATION COMPLETE'
}
finally {
    if ($applicationIds.Count -gt 0) {
        $appList = $applicationIds -join ','
        $storedPaths = @(& $mysql -u root -N -B certreefy_db -e "SELECT storage_path FROM tbl_permit_documents WHERE application_id IN ($appList)")
        foreach ($storedPath in $storedPaths) {
            if ($storedPath) {
                $filePath = Join-Path $storageRoot ($storedPath -replace '/', '\')
                if (Test-Path -LiteralPath $filePath) { Remove-Item -LiteralPath $filePath -Force }
            }
        }
        $cleanupApplications = @"
DELETE FROM tbl_permit_document_reviews WHERE application_id IN ($appList);
UPDATE tbl_permit_documents SET replaces_document_id=NULL WHERE application_id IN ($appList);
DELETE FROM tbl_permit_documents WHERE application_id IN ($appList);
DELETE FROM tbl_permit_status_history WHERE application_id IN ($appList);
DELETE FROM tbl_permit_trees WHERE application_id IN ($appList);
DELETE FROM tbl_permit_applications WHERE id IN ($appList);
"@
        & $mysql -u root certreefy_db -e $cleanupApplications | Out-Null
        foreach ($transactionId in $transactionIds) {
            $directory = Join-Path (Join-Path $storageRoot (Get-Date -Format 'yyyy')) $transactionId
            if (Test-Path -LiteralPath $directory) {
                $resolvedDirectory = (Resolve-Path -LiteralPath $directory).Path
                $resolvedRoot = (Resolve-Path -LiteralPath $storageRoot).Path
                if ($resolvedDirectory.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
                    Remove-Item -LiteralPath $resolvedDirectory -Force -ErrorAction SilentlyContinue
                }
            }
        }
    }
    if ($userIds.Count -gt 0) {
        $userList = $userIds -join ','
        $cleanupUsers = @"
DELETE FROM tbl_notifications WHERE recipient_user_id IN ($userList) OR created_by_user_id IN ($userList);
DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($userList);
DELETE FROM tbl_user_management_audit WHERE actor_user_id IN ($userList) OR target_user_id IN ($userList);
DELETE FROM tbl_users WHERE id IN ($userList);
"@
        & $mysql -u root certreefy_db -e $cleanupUsers | Out-Null
    }
    Remove-Item -LiteralPath $ownerCookie, $otherCookie, $rpsCookie, $superCookie, $emsCookie -Force -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $tempDirectory) {
        $resolvedTemp = (Resolve-Path -LiteralPath $tempDirectory).Path
        $resolvedSystemTemp = (Resolve-Path -LiteralPath $env:TEMP).Path
        if ($resolvedTemp.StartsWith($resolvedSystemTemp + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
        }
    }
}
