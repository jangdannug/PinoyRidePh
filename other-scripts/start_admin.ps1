# Pinoy Ride Admin - one-click environment preparation + launcher.
#
# Called by the root-level START-ADMIN.bat so non-technical staff only ever
# see that one file. Safe to re-run any time (every step is idempotent).

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot   # project root (parent of other-scripts)

# ========================== DISPLAY HELPERS ==========================

function Banner {
    Clear-Host
    Write-Host ''
    Write-Host '  +==================================================+' -ForegroundColor Cyan
    Write-Host '  |         PINOY RIDE ADMIN - STARTING UP           |' -ForegroundColor Cyan
    Write-Host '  +==================================================+' -ForegroundColor Cyan
    Write-Host ''
}

function ProgressBar([int]$percent, [string]$label, [string]$status) {
    $barWidth = 30
    $filled   = [math]::Floor($barWidth * $percent / 100)
    $empty    = $barWidth - $filled
    $bar      = ('#' * $filled) + ('-' * $empty)
    if ($percent -ge 100) { $color = 'Green' }
    elseif ($percent -ge 50) { $color = 'Yellow' }
    else { $color = 'White' }
    Write-Host ("  [{0}] {1,3}%  {2}" -f $bar, $percent, $label) -ForegroundColor $color
    if ($status) { Write-Host "             $status" -ForegroundColor DarkGray }
}

function StepHeader([int]$stepNum, [int]$totalSteps, [string]$title) {
    $pct = [math]::Floor(($stepNum - 1) / $totalSteps * 100)
    Write-Host ''
    Write-Host ("  -- Step {0}/{1}: {2} " -f $stepNum, $totalSteps, $title) -ForegroundColor White
    Write-Host ("     Overall progress: {0}%" -f $pct) -ForegroundColor DarkGray
    Write-Host ''
}

function ShowOk([string]$msg)   { Write-Host "     [OK] $msg" -ForegroundColor Green }
function ShowWarn([string]$msg) { Write-Host "     [!]  $msg" -ForegroundColor Yellow }
function ShowFail([string]$msg) {
    Write-Host "     [X]  $msg" -ForegroundColor Red
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}

$TotalSteps = 7

# ========================== START ==========================

Banner

# ---- Step 1: Prerequisites check ----
StepHeader 1 $TotalSteps 'Checking prerequisites'

# -- PHP (XAMPP) --
Write-Host '     Checking PHP (XAMPP)...' -ForegroundColor DarkGray
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
    Write-Host '     [X]  PHP/XAMPP not found!' -ForegroundColor Red
    Write-Host ''
    Write-Host '     Please install XAMPP first:' -ForegroundColor Yellow
    Write-Host '     Download: https://www.apachefriends.org/download.html' -ForegroundColor Cyan
    Write-Host '     Install with default settings (C:\xampp)' -ForegroundColor DarkGray
    Write-Host '     Then run START-ADMIN again.' -ForegroundColor DarkGray
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}
ShowOk "PHP found: $Php"

# -- Git --
Write-Host '     Checking Git...' -ForegroundColor DarkGray
$GitExe = Get-Command git.exe -ErrorAction SilentlyContinue
if (-not $GitExe) {
    Write-Host '     [X]  Git not found!' -ForegroundColor Red
    Write-Host ''
    Write-Host '     Please install Git for Windows:' -ForegroundColor Yellow
    Write-Host '     Download: https://git-scm.com/download/win' -ForegroundColor Cyan
    Write-Host '     Install with default settings (click Next through everything)' -ForegroundColor DarkGray
    Write-Host '     Then run START-ADMIN again.' -ForegroundColor DarkGray
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}
ShowOk "Git found: $($GitExe.Source)"

# -- SSH --
Write-Host '     Checking SSH...' -ForegroundColor DarkGray
$SshExe = Get-Command ssh.exe -ErrorAction SilentlyContinue
if (-not $SshExe) {
    Write-Host '     [X]  SSH not found!' -ForegroundColor Red
    Write-Host ''
    Write-Host '     SSH should come with Git or Windows 10+.' -ForegroundColor Yellow
    Write-Host '     Windows 10: Settings - Apps - Optional Features - OpenSSH Client' -ForegroundColor DarkGray
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}
ShowOk "SSH found: $($SshExe.Source)"

ProgressBar 14 'Prerequisites' 'All tools installed'

# ---- Step 2: PHP pdo_pgsql ----
StepHeader 2 $TotalSteps 'Enabling Postgres driver'

$IniPath = Join-Path (Split-Path -Parent $Php) 'php.ini'
if (-not (Test-Path $IniPath)) {
    ShowWarn "php.ini not found next to php.exe - skipping."
} else {
    $iniText    = Get-Content $IniPath -Raw
    $hasEnabled = $iniText -match '(?m)^\s*extension\s*=\s*pdo_pgsql'
    if ($hasEnabled) {
        ShowOk 'pdo_pgsql already enabled.'
    } elseif ($iniText -match '(?m)^\s*;\s*extension\s*=\s*pdo_pgsql') {
        $bak = "$IniPath.bak-pinoyride"
        if (-not (Test-Path $bak)) { Copy-Item $IniPath $bak }
        $fixed = $iniText -replace '(?m)^(\s*);\s*(extension\s*=\s*pdo_pgsql)', '$1$2'
        Set-Content -Path $IniPath -Value $fixed -Encoding ASCII -NoNewline
        ShowOk 'Enabled pdo_pgsql in php.ini (backup saved).'
    } else {
        $bak = "$IniPath.bak-pinoyride"
        if (-not (Test-Path $bak)) { Copy-Item $IniPath $bak }
        Add-Content -Path $IniPath -Value "`nextension=pdo_pgsql" -Encoding ASCII
        ShowOk 'Appended pdo_pgsql to php.ini.'
    }
    $loaded = (& $Php -m) -contains 'pdo_pgsql'
    if (-not $loaded) {
        ShowFail 'pdo_pgsql still not loading. Send a screenshot to your admin.'
    }
}

ProgressBar 28 'Postgres driver' 'Ready'

# ---- Step 3: Auto-update from GitHub ----
StepHeader 3 $TotalSteps 'Fetching latest updates'

$UpdateLog = Join-Path $Root 'update-log.txt'
if (Test-Path (Join-Path $Root '.git')) {
    Write-Host '     Connecting to GitHub...' -ForegroundColor DarkGray
    try {
        $pullOut = & git -C $Root pull --ff-only 2>&1
        $timestamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        if ($LASTEXITCODE -eq 0) {
            $pullStr = ($pullOut -join ' ')
            if ($pullStr -match 'Already up to date') {
                ShowOk 'Already on the latest version.'
                Add-Content -Path $UpdateLog -Value "[$timestamp] No update needed - already on latest." -Encoding UTF8
            } else {
                $shortLog = & git -C $Root log --oneline -5 2>&1
                ShowOk 'Updated to the latest version!'
                Write-Host "     Changes:" -ForegroundColor DarkGray
                $pullOut | Select-Object -First 5 | ForEach-Object { Write-Host "       $_" -ForegroundColor DarkGray }
                $logEntry = "[$timestamp] UPDATED:`n" + ($pullOut -join "`n") + "`n  Recent: " + ($shortLog -join " | ") + "`n---"
                Add-Content -Path $UpdateLog -Value $logEntry -Encoding UTF8
            }
        } else {
            ShowWarn 'Could not update (network issue?). Using current version.'
            $logEntry = "[$timestamp] FAILED: " + ($pullOut -join ' ')
            Add-Content -Path $UpdateLog -Value $logEntry -Encoding UTF8
        }
    } catch {
        ShowWarn 'Update check failed. Using current version.'
    }
} else {
    ShowWarn 'Not a git repo - skipping update.'
    Write-Host '     To enable auto-updates, run:' -ForegroundColor DarkGray
    Write-Host '     git clone https://github.com/jangdannug/PinoyRidePh.git' -ForegroundColor Cyan
}

ProgressBar 42 'Updates' 'Done'

# ---- Step 4: .env file ----
StepHeader 4 $TotalSteps 'Checking database settings'

$EnvFile = Join-Path $Root '.env'
if (Test-Path $EnvFile) {
    ShowOk 'Database settings found (.env exists).'
} else {
    $envContent = @"
DB_HOST=127.0.0.1
DB_PORT=5433
DB_NAME=riderapp
DB_USER=markangelogonzales
DB_PASS=l3JvueqsjUPBMhwqTsjsNy6DRe3wBFaNmJcjiVUX2k726QeNen235Bz4FYbPwDMb
ADMIN_USER=admin
ADMIN_PASS=pinoyride2026
"@
    Set-Content -Path $EnvFile -Value $envContent -Encoding ASCII
    ShowOk 'Created .env with database settings.'
}

ProgressBar 57 'Database config' 'Ready'

# ---- Step 5: SSH Key ----
StepHeader 5 $TotalSteps 'Setting up server connection'

$SshHost = 'markangelogonzalespinoyride@54.251.171.207'
$SshPort = 2222
$KeyFile = Join-Path $env:USERPROFILE '.ssh\pinoyride_ed25519'

function Test-KeyAuth {
    $out = & ssh -i $KeyFile -o IdentitiesOnly=yes -o BatchMode=yes `
        -o StrictHostKeyChecking=accept-new -p $SshPort $SshHost 'echo KEY_OK' 2>$null
    return ($LASTEXITCODE -eq 0 -and ($out -join ' ') -match 'KEY_OK')
}

New-Item -ItemType Directory -Path (Split-Path -Parent $KeyFile) -Force | Out-Null

if (-not (Test-Path $KeyFile)) {
    Write-Host '     Generating security key...' -ForegroundColor DarkGray
    $kc = Join-Path $env:TEMP ('pr_keygen_' + [guid]::NewGuid().ToString('N') + '.cmd')
    Set-Content -Path $kc -Value "@echo off`r`nssh-keygen -q -t ed25519 -f `"$KeyFile`" -N `"`" -C pinoyride-admin" -Encoding Ascii
    & $kc | Out-Null
    Remove-Item $kc -ErrorAction SilentlyContinue
    if (-not (Test-Path $KeyFile)) { ShowFail 'Could not create security key.' }
    ShowOk 'Security key created.'
}

if (Test-KeyAuth) {
    ShowOk 'Server connection verified (no password needed).'
} else {
    Write-Host ''
    Write-Host '     +---------------------------------------------------+' -ForegroundColor Yellow
    Write-Host '     |  FIRST-TIME SETUP: Server password needed once    |' -ForegroundColor Yellow
    Write-Host '     +---------------------------------------------------+' -ForegroundColor Yellow
    Write-Host '     Get the password from the Pinoy Ride instructions document.' -ForegroundColor DarkGray
    Write-Host ''

    $plain = $env:PR_SSH_PW
    if (-not $plain) {
        $sec = Read-Host '     Paste the server password here'
        $sec = $sec.Trim()
        if (-not $sec) { ShowFail 'No password entered. Run START-ADMIN again.' }
        $plain = $sec
    }

    $pub     = (Get-Content "$KeyFile.pub" -Raw).Trim()
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
        ShowFail "Wrong password or connection failed. Details: $($out -join ' ')"
    }
    if (Test-KeyAuth) {
        ShowOk 'Connected! You will never need the password again.'
    } else {
        ShowFail 'Key installed but test failed. Send a screenshot to your admin.'
    }
}

ProgressBar 71 'Server connection' 'Authenticated'

# ---- Step 6: SSH Tunnel ----
StepHeader 6 $TotalSteps 'Starting database tunnel'

$TunnelPid = 0
if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) {
    ShowOk 'Tunnel already running.'
    $TunnelPid = [int](Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue |
                       Select-Object -First 1).OwningProcess
} else {
    Write-Host '     Connecting to database server...' -ForegroundColor DarkGray
    $TunnelHost = Start-Process powershell -WindowStyle Minimized -PassThru `
        -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSScriptRoot\tunnel.ps1`""
    $TunnelPid = $TunnelHost.Id
    $up = $false
    foreach ($i in 1..30) {
        Start-Sleep -Seconds 1
        $pct = [math]::Min(99, [math]::Floor($i / 30 * 100))
        Write-Host ("`r     Waiting for tunnel... {0}%" -f $pct) -NoNewline -ForegroundColor DarkGray
        if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    Write-Host ''
    if ($up) { ShowOk 'Database tunnel is up!' }
    else { ShowFail 'Tunnel did not start in 30 seconds. Check the minimized tunnel window.' }
}

ProgressBar 85 'Database tunnel' 'Connected'

# ---- Step 7: PHP Server ----
StepHeader 7 $TotalSteps 'Starting admin panel'

$PhpPid = 0
if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) {
    ShowOk 'Admin panel already running.'
    $PhpPid = [int](Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue |
                    Select-Object -First 1).OwningProcess
} else {
    Write-Host '     Starting PHP server...' -ForegroundColor DarkGray
    $PhpProc = Start-Process -FilePath $Php -ArgumentList '-S', '127.0.0.1:8000' -WorkingDirectory $Root -WindowStyle Minimized -PassThru
    $PhpPid  = $PhpProc.Id
    $up = $false
    foreach ($i in 1..15) {
        Start-Sleep -Milliseconds 700
        $pct = [math]::Min(99, [math]::Floor($i / 15 * 100))
        Write-Host ("`r     Starting... {0}%" -f $pct) -NoNewline -ForegroundColor DarkGray
        if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    Write-Host ''
    if (-not $up) { ShowFail 'Admin panel did not start. Send a screenshot to your admin.' }
    ShowOk 'Admin panel running on http://localhost:8000'
}

# Open browser
Start-Process 'http://localhost:8000/index.php' | Out-Null

# Save session for STOP-ADMIN
@{
    startedAt  = (Get-Date).ToString('o')
    tunnelPid  = $TunnelPid
    phpPid     = $PhpPid
    browserPid = 0
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Root '.pinoyride-session.json') -Encoding ASCII

ProgressBar 100 'Complete' 'All systems running'

# ========================== DONE ==========================
Write-Host ''
Write-Host '  +==================================================+' -ForegroundColor Green
Write-Host '  |       PINOY RIDE ADMIN IS READY!                  |' -ForegroundColor Green
Write-Host '  |                                                    |' -ForegroundColor Green
Write-Host '  |   Browser opened to http://localhost:8000          |' -ForegroundColor Green
Write-Host '  |                                                    |' -ForegroundColor Green
Write-Host '  |   Keep this window open (minimize is fine).        |' -ForegroundColor Green
Write-Host '  |   To stop: double-click STOP-ADMIN                |' -ForegroundColor Green
Write-Host '  +==================================================+' -ForegroundColor Green
Write-Host ''
