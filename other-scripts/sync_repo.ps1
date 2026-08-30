# Pinoy Ride Admin - shared git sync engine (single source of truth).
#
# Every sync path calls THIS script so all machines behave identically:
#   - START-ADMIN        (other-scripts/start_admin.ps1, step 3)
#   - Browser Shutdown   (shutdown.php, before the server is killed)
#   - Fix / Update       (PinoyRideAdmin-Fix-Update.bat)
#
# Guarantees:
#   * Only logs/ is ever committed + pushed automatically. Every other local
#     change (edited/deleted files) is stashed during the sync and put back
#     afterwards. Untracked junk (e.g. " (3).env") is never touched.
#   * Push only happens when this machine has commits GitHub does NOT have.
#     Nothing new -> no push at all (this is what caused the old
#     "rejected (non-fast-forward)" errors when a machine was just behind).
#   * logs/activity-log.csv uses the git "union" merge (see .gitattributes):
#     two machines appending rows at the same time merge cleanly - BOTH
#     sides are kept. The Union-ResolveLogFile fallback below handles the
#     rare machine that has not received .gitattributes yet.
#   * A conflict in a NON-log file (two machines edited the same file) never
#     breaks the repo: rebase is aborted, everything restored, clear message.
#   * If another machine uploads between our fetch and our push: fetch +
#     rebase + retry (up to 3 times) instead of failing.
#   * Never hangs: credential prompts are disabled; auth = GIT_TOKEN in .env.
#
# Exit codes: 0 = in sync (soft warnings allowed), 1 = needs admin attention.

param(
    [string]$Root = (Split-Path -Parent $PSScriptRoot),
    [string]$Reason = 'sync',
    [string]$CommitMessage = ''
)

$ErrorActionPreference = 'Continue'

# ----------------------------- helpers ------------------------------------
$script:HadFailure = $false
$script:DidStash   = $false

function Ok([string]$m)   { Write-Host "     [OK] $m" -ForegroundColor Green }
function Warn([string]$m) { Write-Host "     [!]  $m" -ForegroundColor Yellow }
function Fail([string]$m) { Write-Host "     [X]  $m" -ForegroundColor Red; $script:HadFailure = $true }
function Info([string]$m) { Write-Host "     $m" -ForegroundColor DarkGray }

function Invoke-Git { & git -C $Root @args 2>&1 }

$UpdateLog = Join-Path $Root 'update-log.txt'
function LogEntry([string]$text) {
    try { Add-Content -Path $UpdateLog -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $text" -Encoding UTF8 } catch { }
}

# Puts back whatever we stashed (the machine's other local changes).
function Restore-Stash {
    if (-not $script:DidStash) { return }
    $script:DidStash = $false
    $null = Invoke-Git stash pop
    if ($LASTEXITCODE -eq 0) {
        Info 'Your other local files were put back exactly as they were.'
    } else {
        Warn 'Some held-back local files could not be restored automatically.'
        Warn 'They are SAFE in the git stash - ask your admin to run:  git stash pop'
    }
}

# Merges one conflicted log file by UNION (both machines' lines survive).
# Fallback for machines that have not received .gitattributes yet.
function Union-ResolveLogFile([string]$path) {
    $tmp = Join-Path $env:TEMP ("pr_sync_" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp -Force | Out-Null
    $base   = Join-Path $tmp 'base.txt'
    $ours   = Join-Path $tmp 'ours.txt'
    $theirs = Join-Path $tmp 'theirs.txt'
    # :1: common ancestor, :2: other machine's copy (upstream during rebase),
    # :3: this machine's copy. cmd redirection keeps the exact bytes.
    $null = & cmd /c "git -C ""$Root"" cat-file blob "":1:$path"" > ""$base"" 2>nul"
    $null = & cmd /c "git -C ""$Root"" cat-file blob "":2:$path"" > ""$ours"" 2>nul"
    $null = & cmd /c "git -C ""$Root"" cat-file blob "":3:$path"" > ""$theirs"" 2>nul"
    [void](& git -C $Root merge-file --union $ours $base $theirs 2>$null)
    if (Test-Path $ours) { Copy-Item $ours (Join-Path $Root $path) -Force }
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    Info "Merged shared log file: $path (kept both machines' rows)"
}

# Returns ONLY real conflicted paths. Invoke-Git merges stderr into stdout
# (2>&1), and with core.autocrlf=true git prints a "warning: in the working
# copy of 'x', LF will be replaced by CRLF..." line for every LF file it
# reads. Those warning lines must never be mistaken for conflicted files -
# that made every concurrent log sync abort with a bogus "Two machines
# changed the same file: warning: ..." message instead of union-resolving.
function Get-ConflictedFiles {
    @(Invoke-Git diff --name-only --diff-filter=U) |
        ForEach-Object { [string]$_ } |
        Where-Object { $_ -and ($_ -notmatch '^(warning|error|fatal)\b') -and ($_ -notmatch 'will be replaced by') }
}


if (-not (Test-Path $Root)) {
    Fail "Project folder not found: $Root"
    exit 1
}
if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
    Fail 'Git for Windows is not installed. Install Git, then run this again.'
    exit 1
}

# Never hang waiting for a password / credential dialog - auth comes from
# GIT_TOKEN inside .env, or nothing happens at all.
$env:GIT_TERMINAL_PROMPT = '0'
$env:GCM_INTERACTIVE     = 'never'
$env:GIT_EDITOR          = 'true'   # rebase --continue must never open an editor

$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
if (-not $CommitMessage) { $CommitMessage = "Activity log sync ($Reason) - $timestamp" }

# ------------------- 1. repo, credentials, self-heal ----------------------
$repoUrl = 'https://github.com/jangdannug/PinoyRidePh.git'
$envFile = Join-Path $Root '.env'
if (Test-Path $envFile) {
    foreach ($line in (Get-Content $envFile)) {
        if ($line -match '^\s*GIT_TOKEN\s*=\s*(.+?)\s*$') {
            $tok = $Matches[1]
            if ($tok -and $tok -ne 'CHANGE_ME') {
                $repoUrl = "https://jangdannug:$tok@github.com/jangdannug/PinoyRidePh.git"
            }
            break
        }
    }
}

# Self-heal: a previous crashed sync can leave a half-done rebase behind.
if ((Test-Path (Join-Path $Root '.git\rebase-merge')) -or (Test-Path (Join-Path $Root '.git\rebase-apply'))) {
    Warn 'Found a half-finished sync from before - cleaning it up safely...'
    [void](Invoke-Git rebase --abort)
}

& git -C $Root init 2>&1 | Out-Null                    # no-op if already a repo
& git -C $Root remote set-url origin $repoUrl 2>$null
if ($LASTEXITCODE -ne 0) { [void](& git -C $Root remote add origin $repoUrl 2>&1) }

[void](Invoke-Git config user.email 'pinoyride-admin@local')
[void](Invoke-Git config user.name 'PinoyRide Admin')
[void](Invoke-Git config pull.rebase 'true')                  # plain 'git pull' must rebase, never merge

# First time on this machine (folder has files but no commits yet)?
$null = Invoke-Git rev-parse --verify --quiet HEAD
if ($LASTEXITCODE -ne 0) {
    Info 'First time setup - downloading the latest version from GitHub...'
    $null = Invoke-Git fetch origin main
    if ($LASTEXITCODE -ne 0) {
        Fail 'Could not reach GitHub. Check the internet, then run this again.'
        LogEntry 'FAILED: first-time fetch (offline?)'
        exit 1
    }
    $null = Invoke-Git checkout -f -B main origin/main        # keeps untracked files (.env etc.)
    if ($LASTEXITCODE -eq 0) {
        Ok 'Connected! This machine is now on the latest version.'
        LogEntry 'First-time setup complete.'
    } else {
        Fail 'Setup did not finish - send a screenshot to your admin.'
        LogEntry 'FAILED: first-time checkout'
    }
    if ($script:HadFailure) { exit 1 } else { exit 0 }
}

# ---------------- 2. commit this machine's activity log -------------------
# The ONLY thing that is ever committed + pushed automatically. No -f, so
# gitignored per-machine files (logs/.boot-id) stay out.
[void](Invoke-Git add -- logs/)
$staged = @(Invoke-Git diff --cached --name-only)
if ($staged.Count -gt 0) {
    $null = Invoke-Git commit -m $CommitMessage
    if ($LASTEXITCODE -eq 0) {
        Ok "Saved this machine's activity log ($($staged.Count) file(s))."
    } else {
        Warn 'Could not save the activity log locally (continuing).'
    }
}

# ---------------- 3. hold back all OTHER local changes --------------------
$stashOut = @(Invoke-Git stash push --include-untracked -m "pinoyride-sync ($Reason)")
if ($LASTEXITCODE -eq 0 -and ($stashOut -join ' ') -notmatch 'No local changes') {
    $script:DidStash = $true
    Info 'Held back your other local files during the sync (put back after).'
}

# --------------------------- 4. fetch --------------------------------------
Info 'Fetching the latest from GitHub...'
$null = Invoke-Git fetch origin main
if ($LASTEXITCODE -ne 0) {
    Warn 'Could not reach GitHub (offline?). Keeping the current version.'
    Restore-Stash
    LogEntry 'FAILED: fetch (offline?)'
    exit 1
}

# How far behind are we BEFORE merging (for the report)?
$behind = 0
$behindRaw = @(Invoke-Git rev-list --count HEAD..origin/main)
[void][int]::TryParse("$($behindRaw | Select-Object -First 1)", [ref]$behind)

# --------- 5+6. rebase, then push ONLY if this machine is ahead ------------
$script:PushRetries = 0
$script:Pushed      = 0
while ($true) {
    $rbOut = @(Invoke-Git rebase origin/main)

    # Auto-resolve conflicts that are ONLY inside logs/ (belt-and-suspenders:
    # normally the .gitattributes union rule means there is no conflict at all).
    $rbTries = 0
    while (($LASTEXITCODE -ne 0) -and ($rbTries -lt 5)) {
        $conflicted = Get-ConflictedFiles
        if ($conflicted.Count -eq 0) { break }                # failed, no conflict
        $nonLog = @($conflicted | Where-Object { $_ -notlike 'logs/*' })
        if ($nonLog.Count -gt 0) { break }                    # real conflict
        foreach ($f in $conflicted) { Union-ResolveLogFile $f }
        [void](Invoke-Git add -- logs/)
        $null = Invoke-Git rebase --continue
        if ($LASTEXITCODE -ne 0) {
            # a replayed commit can become EMPTY after the union merge - drop it
            if ((Get-ConflictedFiles).Count -eq 0) {
                $null = Invoke-Git rebase --continue
            }
        }
        $rbTries++
    }

    # Classify the rebase outcome; never leave the repo half-rebased.
    $conflicted = Get-ConflictedFiles
    $inRebase = (Test-Path (Join-Path $Root '.git\rebase-merge')) -or
                (Test-Path (Join-Path $Root '.git\rebase-apply'))
    if ($inRebase -or $conflicted.Count -gt 0) {
        if ($inRebase) { [void](Invoke-Git rebase --abort) }
        $nonLog = @($conflicted | Where-Object { $_ -notlike 'logs/*' })
        if ($nonLog.Count -gt 0) {
            Fail "Two machines changed the same file: $($nonLog -join ', ')"
            Info 'Nothing was lost - the sync stopped safely. Ask your admin.'
        } else {
            Fail ('Sync stopped: ' + (($rbOut -join ' ') + ' ').Trim())
        }
        Restore-Stash
        LogEntry 'FAILED: rebase could not finish'
        exit 1
    }

    $ahead = 0
    $aheadRaw = @(Invoke-Git rev-list --count origin/main..HEAD)
    [void][int]::TryParse("$($aheadRaw | Select-Object -First 1)", [ref]$ahead)

    if ($ahead -eq 0) {
        Info 'Nothing to upload - this machine has no new log entries.'
        break
    }

    $pushOut = @(Invoke-Git push origin main)
    if ($LASTEXITCODE -eq 0) {
        $script:Pushed = $ahead
        Ok "Uploaded $ahead local commit(s) to GitHub."
        break
    }
    $pushStr = ($pushOut -join ' ')
    if ($pushStr -match 'rejected|non-fast-forward|fetch first') {
        $script:PushRetries++
        if ($script:PushRetries -ge 3) {
            Warn 'Another machine keeps uploading first - the next sync finishes it.'
            break
        }
        Info 'Another machine just uploaded first - merging theirs and retrying...'
        $null = Invoke-Git fetch origin main
    } else {
        Fail "Upload failed: $pushStr"
        break
    }
}

# ---------------- 7. put the held-back local files back --------------------
Restore-Stash

# ---------------------- 8. verify + report ---------------------------------
$topLine = "$(Invoke-Git status -sb | Select-Object -First 1)".Trim()
if ($behind -gt 0) { Ok "Downloaded $behind update(s) from other machines." }
if ($topLine -match '\[(ahead|behind)') {
    Warn "Not fully in sync yet ($topLine). Run START-ADMIN or this sync again."
    LogEntry "PARTIAL: $topLine"
} else {
    Ok 'ALL MACHINES IN SYNC.'
    if ($script:Pushed -gt 0) { LogEntry "Pushed $($script:Pushed) commit(s); all machines in sync." }
    elseif ($behind -gt 0)    { LogEntry "Downloaded $behind update(s) from other machines." }
    else                      { LogEntry 'No update needed - already on latest.' }
}

if ($script:HadFailure) { exit 1 } else { exit 0 }
