# Pinoy Ride Admin - one-click environment preparation + launcher.
#
# Called by the root-level START-ADMIN.bat so non-technical staff only ever
# see that one file. Safe to re-run any time (every step is idempotent):
#   1. find PHP (XAMPP or PATH)
#   2. enable pdo_pgsql in php.ini if needed (backup taken first)
#   3. create .env with DB credentials if missing
#   4. generate + install the SSH key if needed (asks for the server password
#      ONCE, the very first time; afterwards tunnels connect automatically)
#   5. start tunnel + PHP server + open the browser

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot   # project root (parent of other-scripts)

function Step($msg)  { Write-Host ('==> ' + $msg) }
function Ok($msg)    { Write-Host ('    [ok] '    + $msg) -ForegroundColor Green }
function Warn($msg)  { Write-Host ('    [!] '     + $msg) -ForegroundColor Yellow }
function FailStop($msg) {
    Write-Host ('    [x] ' + $msg) -ForegroundColor Red
    Write-Host ''
    Read-Host 'Press Enter to close this window'
    exit 1
}

Write-Host ''
Write-Host '==============================================' -ForegroundColor Cyan
Write-Host '   Pinoy Ride Admin - starting things up...'   -ForegroundColor Cyan
Write-Host '==============================================' -ForegroundColor Cyan
Write-Host ''

# ------------------------------------------------------------------ 1. PHP --
Step 'Looking for PHP (XAMPP)...'
$Php = $null
$candidates = @(
    'C:\xampp\php\php.exe',
    'D:\xampp\php\php.exe',
    'C:\xampp2\php\php.exe',
    'C:\Program Files\xampp\php\php.exe'
)
$onPath = Get-Command php.exe -ErrorAction SilentlyContinue
if ($onPath) { $candidates += $onPath.Source }
foreach ($c in $candidates) {
    if ($c -and (Test-Path $c)) { $Php = $c; break }
}
if (-not $Php) {
    FailStop ('PHP was not found. Install XAMPP with PHP 8.2 or newer first: https://www.apachefriends.org/  Then run START-ADMIN again.')
}
Ok ("PHP found: $Php")

# ---------------------------------------------------- 2. enable pdo_pgsql ---
Step 'Checking Postgres driver (pdo_pgsql)...'
$IniPath = Join-Path (Split-Path -Parent $Php) 'php.ini'
if (-not (Test-Path $IniPath)) {
    Warn "php.ini not found next to php.exe ($IniPath) - skipping auto-fix."
} else {
    $iniText    = Get-Content $IniPath -Raw
    $hasEnabled = $iniText -match '(?m)^\s*extension\s*=\s*pdo_pgsql'
    if ($hasEnabled) {
        Ok 'pdo_pgsql already enabled.'
    } elseif ($iniText -match '(?m)^\s*;\s*extension\s*=\s*pdo_pgsql') {
        $bak = "$IniPath.bak-pinoyride"
        if (-not (Test-Path $bak)) { Copy-Item $IniPath $bak }
        try {
            $fixed = $iniText -replace '(?m)^(\s*);\s*(extension\s*=\s*pdo_pgsql)', '$1$2'
            Set-Content -Path $IniPath -Value $fixed -Encoding ASCII -NoNewline
            Ok 'Enabled pdo_pgsql in php.ini (backup saved as php.ini.bak-pinoyride).'
        } catch {
            Warn "Could not edit php.ini automatically ($($_.Exception.Message))."
        }
    } else {
        $bak = "$IniPath.bak-pinoyride"
        if (-not (Test-Path $bak)) { Copy-Item $IniPath $bak }
        try {
            Add-Content -Path $IniPath -Value "`nextension=pdo_pgsql" -Encoding ASCII
            Ok 'Appended pdo_pgsql to php.ini (backup saved).'
        } catch {
            Warn "Could not edit php.ini automatically ($($_.Exception.Message))."
        }
    }
    $loaded = (& $Php -m) -contains 'pdo_pgsql'
    if ($loaded) { Ok 'PHP loads pdo_pgsql.' }
    else { FailStop "pdo_pgsql still not loading after the php.ini change. Send a screenshot of this window to your admin." }
}
# ------------------------------------------------------- 3. create .env -----
Step 'Checking database settings (.env)...'
$EnvFile = Join-Path $Root '.env'
if (Test-Path $EnvFile) {
    Ok 'Database settings found.'
} else {
    @'
DB_HOST=127.0.0.1
DB_PORT=5433
DB_NAME=riderapp
DB_USER=markangelogonzales
DB_PASS=l3JvueqsjUPBMhwqTsjsNy6DRe3wBFaNmJcjiVUX2k726QeNen235Bz4FYbPwDMb
'@ | Set-Content -Path $EnvFile -Encoding ASCII
    Ok 'Created .env with the standard database settings.'
}

# ------------------------------------------- 4. SSH key (one-time setup) ----
$SshHost = 'markangelogonzalespinoyride@54.251.171.207'
$SshPort = 2222
$KeyFile = Join-Path $env:USERPROFILE '.ssh\pinoyride_ed25519'

function Test-KeyAuth {
    $out = & ssh -i $KeyFile -o IdentitiesOnly=yes -o BatchMode=yes `
        -o StrictHostKeyChecking=accept-new -p $SshPort $SshHost 'echo KEY_OK' 2>$null
    return ($LASTEXITCODE -eq 0 -and ($out -join ' ') -match 'KEY_OK')
}

Step 'Checking server connection key...'
New-Item -ItemType Directory -Path (Split-Path -Parent $KeyFile) -Force | Out-Null

if (-not (Test-Path $KeyFile)) {
    # ssh-keygen drops empty-string arguments under PowerShell 5.1, so run it
    # through a tiny temp .cmd where the quoting is predictable.
    $kc = Join-Path $env:TEMP ('pr_keygen_' + [guid]::NewGuid().ToString('N') + '.cmd')
    Set-Content -Path $kc -Value "@echo off`r`nssh-keygen -q -t ed25519 -f `"$KeyFile`" -N `"`" -C pinoyride-admin" -Encoding Ascii
    & $kc | Out-Null
    Remove-Item $kc -ErrorAction SilentlyContinue
    if (-not (Test-Path $KeyFile)) { FailStop 'Could not create the security key on this computer.' }
    Ok 'Security key created.'
}

if (Test-KeyAuth) {
    Ok 'Server connection verified (no password needed).'
} else {
    Step 'First-time setup: connecting your computer to the server...'
    Write-Host '    You will be asked for the server password ONCE.'
    Write-Host "    It is the SSH password from the Pinoy Ride instructions document."
    Write-Host ''

    $plain = $env:PR_SSH_PW   # pre-seeded only for automated testing
    if (-not $plain) {
        $sec = Read-Host '    Paste the server password here' 
        $sec = $sec.Trim()
        if (-not $sec) { FailStop 'No password entered. Run START-ADMIN again to retry.' }
        $plain = $sec
    }

    $pub     = Get-Content "$KeyFile.pub" -Raw
    $pub     = $pub.Trim()
    $tmpDir  = Join-Path $env:TEMP ('pr_setup_' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmpDir | Out-Null
    $askpass = Join-Path $tmpDir 'askpass.cmd'
    Set-Content -Path $askpass -Value '@echo %PR_SSH_PW%' -Encoding Ascii

    $env:PR_SSH_PW            = $plain
    $env:SSH_ASKPASS          = $askpass
    $env:SSH_ASKPASS_REQUIRE  = 'force'
    $env:DISPLAY              = ':0'

    $remoteCmd = "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && (grep -qxF '$pub' ~/.ssh/authorized_keys || echo '$pub' >> ~/.ssh/authorized_keys) && echo KEY_INSTALLED"
    $out = & ssh -p $SshPort -o StrictHostKeyChecking=accept-new $SshHost $remoteCmd 2>&1

    Remove-Item Env:PR_SSH_PW, Env:SSH_ASKPASS, Env:SSH_ASKPASS_REQUIRE, Env:DISPLAY -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $tmpDir -ErrorAction SilentlyContinue
    $plain = $null

    if (($out -join ' ') -notmatch 'KEY_INSTALLED') {
        FailStop "Could not connect with that password. Check it against the instructions document and run START-ADMIN again. Details: $($out -join ' ')"
    }
    if (Test-KeyAuth) {
        Ok 'Computer connected to the server. You will never need the password again.'
    } else {
        FailStop 'The key was installed but the test connection still failed. Send a screenshot of this window to your admin.'
    }
}
# Track everything we start so STOP-ADMIN can close exactly these later.
$TunnelPid   = 0
$PhpPid      = 0
$BrowserProc = $null

# ------------------------------------------------------- 5. start tunnel ----
Step 'Starting the secure database tunnel...'
if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) {
    Ok 'Tunnel already running.'
    $TunnelPid = [int](Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue |
                       Select-Object -First 1).OwningProcess
} else {
    $TunnelHost = Start-Process powershell -WindowStyle Minimized -PassThru `
        -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSScriptRoot\tunnel.ps1`""
    $TunnelPid = $TunnelHost.Id
    $up = $false
    foreach ($i in 1..30) {
        Start-Sleep -Seconds 1
        if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    if ($up) { Ok 'Tunnel is up.' }
    else { FailStop "The tunnel did not start within 30 seconds. Check the minimized 'pinoyride-tunnel' window, then run START-ADMIN again." }
}

# ------------------------------------------- 6. start admin panel server ----
Step 'Starting the admin panel...'
if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) {
    Ok 'Admin panel already running.'
    $PhpPid = [int](Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue |
                    Select-Object -First 1).OwningProcess
} else {
    $PhpProc = Start-Process -FilePath $Php -ArgumentList '-S', '127.0.0.1:8000' -WorkingDirectory $Root -WindowStyle Minimized -PassThru
    $PhpPid  = $PhpProc.Id
    $up = $false
    foreach ($i in 1..15) {
        Start-Sleep -Milliseconds 700
        if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    if (-not $up) { FailStop 'The admin panel did not start. Send a screenshot of this window to your admin.' }
    Ok 'Admin panel is running.'
}

# ------------------------------------------------------------ 7. browser ----
Step 'Opening your browser...'
$BrowserProc = Start-Process 'http://localhost:8000/index.php' -PassThru

# -------------------------------------------------- 8. remember for STOP ----
# Save what we opened so STOP-ADMIN can close exactly these again.
@{
    startedAt  = (Get-Date).ToString('o')
    tunnelPid  = $TunnelPid
    phpPid     = $PhpPid
    browserPid = if ($BrowserProc) { $BrowserProc.Id } else { 0 }
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Root '.pinoyride-session.json') -Encoding ASCII
Ok 'Saved run details for STOP-ADMIN.'

Write-Host ''
Write-Host '==============================================' -ForegroundColor Green
Write-Host '   The Pinoy Ride admin panel is open!'        -ForegroundColor Green
Write-Host ''                                                 -ForegroundColor Green
Write-Host '   Keep the two small black windows open,'        -ForegroundColor Green
Write-Host '   you can just minimize them.'                   -ForegroundColor Green
Write-Host '   To close everything later, double-click:'      -ForegroundColor Green
Write-Host '   STOP-ADMIN'                                    -ForegroundColor Green
Write-Host '==============================================' -ForegroundColor Green
Write-Host ''
