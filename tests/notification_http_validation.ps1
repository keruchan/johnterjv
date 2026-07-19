$ErrorActionPreference = 'Stop'

function Assert-Check([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Output "PASS: $Message"
}

$baseUrl = 'http://127.0.0.1/Certreefy'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$password = 'NotifValidation123!'
$php = 'C:\xampp\php\php.exe'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'

$roles = @(
    @{ Role = 'community';  Landing = 'pages/community/dashboard.php'; Seed = 15 },
    @{ Role = 'rps';        Landing = 'pages/cenro/dashboard.php';     Seed = 4  },
    @{ Role = 'superadmin'; Landing = 'pages/cenro/dashboard.php';     Seed = 4  },
    @{ Role = 'ems';        Landing = 'pages/ems/dashboard.php';       Seed = 3  }
)

$createdUserIds = @()

try {
    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $password

    # --- Create one throwaway user per role -------------------------------
    foreach ($r in $roles) {
        $uname = "notif_$($r.Role)_$suffix"
        $r.Username = $uname
        $sql = "INSERT INTO tbl_users (fname,lname,email,contact,address,username,password,role,status) VALUES ('Notif','$($r.Role)','$uname@example.test','09170000009','Addr','$uname','$hash','$($r.Role)','active'); SELECT id FROM tbl_users WHERE username='$uname';"
        $id = (& $mysql -u root -N -B certreefy_db -e $sql) | Select-Object -Last 1
        $r.UserId = [int]$id
        $createdUserIds += $r.UserId
    }
    Assert-Check ($createdUserIds.Count -eq 4) 'Created one test user per role.'

    # --- Seed notifications for each via the service helper ---------------
    $seedScript = @'
require 'C:/xampp/htdocs/Certreefy/config/config.php';
require 'C:/xampp/htdocs/Certreefy/includes/notifications.php';
$uid = (int)$argv[1]; $n = (int)$argv[2];
$types = ['permit_status','seedling_request','illegal_logging_report','account_status','system_announcement'];
$ents  = [['permit_application',1],['seedling_request',1],['illegal_logging_report',1],['user',$uid],[null,null]];
for ($i=0; $i<$n; $i++) {
    $k = $i % 5;
    create_notification($pdo, $uid, 1, $types[$k], "Test #$i", "Seeded notification number $i for HTTP validation.", $ents[$k][0], $ents[$k][1]);
}
echo "ok";
'@
    $seedFile = Join-Path $env:TEMP "notif_seed_$suffix.php"
    [System.IO.File]::WriteAllText($seedFile, "<?php`n$seedScript", (New-Object System.Text.UTF8Encoding($false)))
    foreach ($r in $roles) {
        $out = & $php $seedFile $r.UserId $r.Seed
        Assert-Check ($out -eq 'ok') "Seeded $($r.Seed) notifications for $($r.Role)."
    }

    # --- Unauthenticated feed access is rejected --------------------------
    $unauth = (& curl.exe -s -D - -o NUL "$baseUrl/pages/notifications/feed.php") -join "`n"
    Assert-Check ($unauth -match 'HTTP/1\.1 (302|401|403)') 'Unauthenticated feed.php access is blocked.'

    function Login-Role([string] $Username, [string] $CookiePath) {
        $loginHtml = (& curl.exe -s -c $CookiePath "$baseUrl/pages/auth/login.php") -join "`n"
        $csrf = [regex]::Match($loginHtml, 'name="csrf_token" value="([^"]+)"')
        if (-not $csrf.Success) { throw 'Unable to extract login CSRF token.' }
        $headers = (& curl.exe -s -D - -o NUL -b $CookiePath -c $CookiePath `
            --data-urlencode "csrf_token=$($csrf.Groups[1].Value)" `
            --data-urlencode "login_identifier=$Username" `
            --data-urlencode "password=$password" `
            "$baseUrl/pages/auth/login.php") -join "`n"
        if ($headers -notmatch 'HTTP/1\.1 302') { throw "Login failed for $Username" }
    }

    foreach ($r in $roles) {
        $cookie = Join-Path $env:TEMP "notif_$($r.Role)_$suffix.cookies"
        Login-Role $r.Username $cookie

        # Landing page carries the bell, badge, panel, and script.
        $page = (& curl.exe -s -b $cookie "$baseUrl/$($r.Landing)") -join "`n"
        Assert-Check ($page -match 'data-notif-toggle' -and $page -match 'data-notif-badge') "[$($r.Role)] Bell + badge render on the dashboard."
        Assert-Check ($page -match 'id="notifPanel"' -and $page -match 'data-notif-list' -and $page -match 'data-notif-markall') "[$($r.Role)] Notification panel markup renders."
        Assert-Check ($page -match 'js/notifications\.js') "[$($r.Role)] notifications.js is included."
        $csrf = [regex]::Match($page, 'data-notif-csrf="([^"]+)"').Groups[1].Value
        Assert-Check ($csrf.Length -gt 0) "[$($r.Role)] Panel exposes a CSRF token."

        # Feed returns JSON with the seeded unread count.
        $feed = (& curl.exe -s -b $cookie "$baseUrl/pages/notifications/feed.php?before=0") -join "`n"
        $feedObj = $feed | ConvertFrom-Json
        Assert-Check ($feedObj.unread_count -eq $r.Seed) "[$($r.Role)] Feed reports unread_count=$($r.Seed)."
        Assert-Check ($feedObj.items.Count -gt 0) "[$($r.Role)] Feed returns items."
        $expectMore = if ($r.Seed -gt 12) { $true } else { $false }
        Assert-Check ($feedObj.has_more -eq $expectMore) "[$($r.Role)] has_more=$expectMore is correct for $($r.Seed) items."

        # Load-more cursor works for the paginated role.
        if ($expectMore) {
            $lastId = $feedObj.items[$feedObj.items.Count - 1].id
            $page2 = (& curl.exe -s -b $cookie "$baseUrl/pages/notifications/feed.php?before=$lastId") -join "`n" | ConvertFrom-Json
            Assert-Check ($page2.items.Count -gt 0 -and -not $page2.has_more) "[$($r.Role)] Load-more returns the final page."
        }

        # mark.php rejects a missing/blank CSRF token.
        $badMark = (& curl.exe -s -o NUL -w '%{http_code}' -b $cookie --data-urlencode 'action=all' "$baseUrl/pages/notifications/mark.php")
        Assert-Check ($badMark -eq '403') "[$($r.Role)] mark.php rejects a request with no CSRF token (HTTP $badMark)."

        # mark.php with a valid token clears unread.
        $mark = (& curl.exe -s -b $cookie --data-urlencode "csrf_token=$csrf" --data-urlencode 'action=all' "$baseUrl/pages/notifications/mark.php") -join "`n" | ConvertFrom-Json
        Assert-Check ($mark.ok -eq $true -and $mark.unread_count -eq 0) "[$($r.Role)] Mark-all-read clears the unread count."

        # A GET to mark.php is not allowed.
        $getMark = (& curl.exe -s -o NUL -w '%{http_code}' -b $cookie "$baseUrl/pages/notifications/mark.php")
        Assert-Check ($getMark -eq '405') "[$($r.Role)] mark.php rejects GET (HTTP $getMark)."

        Remove-Item $cookie -ErrorAction SilentlyContinue
    }

    Write-Output ''
    Write-Output 'ALL NOTIFICATION HTTP CHECKS PASSED'
}
finally {
    if ($createdUserIds.Count -gt 0) {
        $idList = ($createdUserIds -join ',')
        & $mysql -u root certreefy_db -e "DELETE FROM tbl_notifications WHERE recipient_user_id IN ($idList) OR created_by_user_id IN ($idList); DELETE FROM tbl_audit_trail WHERE actor_user_id IN ($idList); DELETE FROM tbl_users WHERE id IN ($idList);" | Out-Null
        Write-Output "Cleaned up test users: $idList"
    }
    Remove-Item (Join-Path $env:TEMP "notif_seed_$suffix.php") -ErrorAction SilentlyContinue
}
