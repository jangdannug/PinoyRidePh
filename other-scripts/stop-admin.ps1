# Stops EVERYTHING that start-admin.bat opened:
#   1. the browser window/tab it opened on http://localhost:8000
#   2. the minimized tunnel PowerShell window + its ssh.exe (port 5433)
#   3. the minimized PHP dev-server window (port 8000)
#   4. the START-ADMIN black window if it is still sitting open
#
# Uses .pinoyride-session.json (written by start_admin.ps1) to find the exact
# processes, plus command-line patterns as a fallback for stale sessions
# (e.g. after a reboot). Safe to run any time - if nothing is running it just
# says so. Never force-kills the whole browser - it only asks the admin panel
# window to close, exactly like clicking its X button.

$ErrorActionPreference = 'Continue'
$Root      = Split-Path -Parent $PSScriptRoot            # project root
$StateFile = Join-Path $Root '.pinoyride-session.json'

function Ok($msg)   { Write-Host ("    [ok] " + $msg) -ForegroundColor Green }
function Warn($msg) { Write-Host ("    [!] "  + $msg) -ForegroundColor Yellow }

Write-Host ''
Write-Host '==============================================' -ForegroundColor Cyan
Write-Host '   Pinoy Ride Admin - shutting things down...'  -ForegroundColor Cyan
Write-Host '==============================================' -ForegroundColor Cyan
Write-Host ''

# ------------------------------------------------------------- load session --
$Session = $null
if (Test-Path $StateFile) {
    try { $Session = Get-Content $StateFile -Raw | ConvertFrom-Json } catch { $Session = $null }
}

# ------------------------------------------------------------------ helpers --
function Stop-Pid {
    # Stops one PID, but only if the process name matches what we expect
    # (protects against stale/reused PIDs from an old session file).
    param([int]$TargetPid, [string[]]$ValidNames)
    if ($TargetPid -le 0) { return $false }
    $p = Get-CimInstance Win32_Process -Filter "ProcessId=$TargetPid" -ErrorAction SilentlyContinue
    if (-not $p) { return $false }
    if ($ValidNames -and ($ValidNames -notcontains $p.Name)) { return $false }
    Write-Host ("    stopping {0} (pid {1})" -f $p.Name, $p.ProcessId)
    Stop-Process -Id $TargetPid -Force -ErrorAction SilentlyContinue
    return $true
}

function Stop-Matching {
    # Stops every process whose command line matches a wildcard pattern.
    param([string[]]$Names, [string]$Pattern)
    $procs = @(Get-CimInstance Win32_Process | Where-Object {
        $_.CommandLine -and ($Names -contains $_.Name) -and ($_.CommandLine -like $Pattern)
    })
    foreach ($p in $procs) {
        Write-Host ("    stopping {0} (pid {1})" -f $p.Name, $p.ProcessId)
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }
    return $procs.Count
}

function Test-PortListening {
    param([int]$Port)
    return [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Wait-PortClosed {
    param([int]$Port, [int]$Seconds = 10)
    foreach ($i in 1..($Seconds * 2)) {
        if (-not (Test-PortListening $Port)) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return (-not (Test-PortListening $Port))
}

# Window tools: politely close just the browser window showing the admin
# panel. Pages here have no <title>, so browsers display the URL instead,
# which makes the window title easy to recognise.
$PrWindowsOk = $true
try {
Add-Type -TypeDefinition @"
using System;
using System.Text;
using System.Collections.Generic;
using System.Runtime.InteropServices;

public static class PrWindows {
    public delegate bool EnumProc(IntPtr hWnd, IntPtr lParam);

    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumProc cb, IntPtr lParam);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder sb, int max);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint pid);
    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, uint msg, IntPtr wParam, IntPtr lParam);

    // Politely ask a window to close (same as clicking its X button).
    public static bool CloseWindow(IntPtr hWnd) {
        return PostMessage(hWnd, 0x0010, IntPtr.Zero, IntPtr.Zero);  // WM_CLOSE
    }

    // Visible top-level windows whose title contains 'needle' (case-insensitive).
    public static List<object[]> FindByTitle(string needle) {
        var hits = new List<object[]>();
        string lower = (needle ?? "").ToLower();
        if (lower.Length == 0) return hits;
        EnumWindows(delegate(IntPtr h, IntPtr l) {
            if (!IsWindowVisible(h)) return true;
            var sb = new StringBuilder(512);
            GetWindowText(h, sb, 512);
            string title = sb.ToString();
            if (title.Length > 0 && title.ToLower().Contains(lower)) {
                uint pid; GetWindowThreadProcessId(h, out pid);
                hits.Add(new object[] { h, title, pid });
            }
            return true;
        }, IntPtr.Zero);
        return hits;
    }
}
"@ } catch { $PrWindowsOk = $false }
# ------------------------------------------------------------- 1. browser ---
Write-Host '==> Closing the admin panel browser window...'
$MyPid        = $PID
$ClosedTitles = New-Object System.Collections.Generic.List[string]
$TitleNeedles = @('localhost:8000', '127.0.0.1:8000', 'pinoy ride admin')

if ($PrWindowsOk) {
    foreach ($needle in $TitleNeedles) {
        foreach ($w in [PrWindows]::FindByTitle($needle)) {
            $hWnd  = $w[0]; $title = [string]$w[1]; $owner = [int]$w[2]
            if ($owner -eq $MyPid)           { continue }   # never touch ourselves
            if ($title -like '*stop-admin*') { continue }   # never close THIS black window
            if ($ClosedTitles -contains $title) { continue }
            Write-Host ("    closing window: {0}" -f $title)
            [void][PrWindows]::CloseWindow($hWnd)
            $ClosedTitles.Add($title)
        }
    }
} else {
    # Fallback: check main-window titles only (only if the Win32 helper failed)
    Get-Process | Where-Object {
        $_.MainWindowTitle -and $_.Id -ne $PID -and
        $_.MainWindowTitle -notlike '*stop-admin*' -and (
            $_.MainWindowTitle -like '*localhost:8000*' -or
            $_.MainWindowTitle -like '*127.0.0.1:8000*' -or
            $_.MainWindowTitle -like '*Pinoy Ride Admin*')
    } | ForEach-Object {
        Write-Host ("    closing window: {0}" -f $_.MainWindowTitle)
        [void]$_.CloseMainWindow()
        $ClosedTitles.Add($_.MainWindowTitle)
    }
}
if ($ClosedTitles.Count -gt 0) { Ok 'Browser window closed.' }
else                           { Warn 'No admin panel browser window found (maybe already closed).' }
# ------------------------------------------------------ 2. database tunnel ---
Write-Host '==> Closing the secure database tunnel...'
$KilledSomething = $false
if ($Session -and $Session.tunnelPid) {
    if (Stop-Pid ([int]$Session.tunnelPid) @('powershell.exe','pwsh.exe')) { $KilledSomething = $true }
}
# Kill the tunnel HOST window first so its auto-reconnect loop cannot
# immediately respawn ssh.exe, then kill the ssh process itself.
if ((Stop-Matching @('powershell.exe','pwsh.exe','cmd.exe') '*other-scripts\tunnel.ps1*') -gt 0) { $KilledSomething = $true }
Start-Sleep -Milliseconds 800
if ((Stop-Matching @('ssh.exe') '*-L 5433:postgres-riderapp:5432*') -gt 0) { $KilledSomething = $true }
if (Wait-PortClosed 5433 10) {
    if ($KilledSomething) { Ok 'Tunnel closed.' } else { Write-Host '    Tunnel was not running.' }
} else {
    Stop-Matching @('ssh.exe') '*-L 5433:*'            # last resort: any ssh holding 5433
    if (Wait-PortClosed 5433 5) { Ok 'Tunnel closed.' }
    else { Warn 'Port 5433 is still busy - a reboot will clear it.' }
}

# -------------------------------------------------- 3. admin panel server ----
Write-Host '==> Stopping the admin panel server...'
$KilledSomething = $false
if ($Session -and $Session.phpPid) {
    if (Stop-Pid ([int]$Session.phpPid) @('php.exe')) { $KilledSomething = $true }
}
if ((Stop-Matching @('php.exe') '*127.0.0.1:8000*') -gt 0) { $KilledSomething = $true }
if (Wait-PortClosed 8000 8) {
    if ($KilledSomething) { Ok 'Admin panel server stopped.' }
    else { Write-Host '    Admin panel server was not running.' }
} else {
    Warn 'Port 8000 is still busy - something else may be using it.'
}

# ------------------------------------------------------------ 4. clean up ----
Remove-Item $StateFile -Force -ErrorAction SilentlyContinue

Write-Host ''
Write-Host '==============================================' -ForegroundColor Green
Write-Host '   Everything START-ADMIN opened is now closed!' -ForegroundColor Green
Write-Host '==============================================' -ForegroundColor Green
Write-Host ''