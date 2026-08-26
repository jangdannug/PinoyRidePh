# Pinoy Ride DB tunnel - Windows-native auto-reconnect port of other-scripts/tunnel.sh
#
# Forwards local 127.0.0.1:5433 -> postgres-riderapp:5432 over SSH.
# Uses the dedicated SSH key (%USERPROFILE%\.ssh\pinoyride_ed25519), so no
# password prompt ever appears. Auto-reconnects every 5s if dropped.
#
# Run directly:  powershell -NoProfile -ExecutionPolicy Bypass -File tunnel.ps1
# Or just use the root-level start-admin.bat.

$ErrorActionPreference = 'Continue'

$RemoteHost     = 'markangelogonzalespinoyride@54.251.171.207'
$Port           = 2222
$Forward        = '5433:postgres-riderapp:5432'
$KeyFile        = Join-Path $env:USERPROFILE '.ssh\pinoyride_ed25519'
$ReconnectDelay = 5

Write-Host '=== Pinoy Ride DB tunnel ==='

# Refuse to stack a second tunnel if 5433 is already bound
if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) {
    Write-Host 'Port 5433 is already listening - tunnel appears to be running. Nothing to do.'
    exit 0
}

if (-not (Test-Path $KeyFile)) {
    Write-Host "ERROR: SSH key not found: $KeyFile"
    Write-Host 'No problem - just run START-ADMIN.bat in the project folder.'
    Write-Host 'It will create the key and connect everything for you.'
    pause
    exit 1
}

while ($true) {
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] connecting to $RemoteHost ..."
    & ssh -N -L $Forward -p $Port -i $KeyFile `
        -o BatchMode=yes `
        -o StrictHostKeyChecking=accept-new `
        -o ServerAliveInterval=60 `
        -o ServerAliveCountMax=3 `
        -o ExitOnForwardFailure=yes `
        $RemoteHost
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] connection lost - reconnecting in ${ReconnectDelay}s ..."
    Start-Sleep -Seconds $ReconnectDelay
}
