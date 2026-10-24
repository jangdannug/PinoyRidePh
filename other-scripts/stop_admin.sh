#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - stop_admin.sh (macOS)
#
#  Implements the macOS equivalent of Windows stop-admin.bat -> stop-admin.ps1.
#  Called by stop-admin.command.
# ============================================================================

set -u

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SESSION_FILE="$APP_ROOT/.pinoyride-session.json"

# ---- colors ----
if [ -t 1 ]; then
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    RED='\033[0;31m'
    NC='\033[0m'
else
    GREEN=''; YELLOW=''; RED=''; NC=''
fi

ok()   { echo "${GREEN}[OK]${NC} $1"; }
warn() { echo "${YELLOW}[!]${NC} $1"; }

# ============================================================================
echo ""
echo "  Pinoy Ride Admin - shutting things down..."
echo ""

# ---------------------------------------------------------------------------
# Load session file
# ---------------------------------------------------------------------------
TUNNEL_PID=0
PHP_PID=0

if [ -f "$SESSION_FILE" ]; then
    TUNNEL_PID=$(python3 -c "import json; print(json.load(open('$SESSION_FILE')).get('tunnelPid', 0))" 2>/dev/null || echo 0)
    PHP_PID=$(python3 -c "import json; print(json.load(open('$SESSION_FILE')).get('phpPid', 0))" 2>/dev/null || echo 0)
    echo "     Loaded session: tunnelPid=$TUNNEL_PID, phpPid=$PHP_PID"
else
    echo "     No session file found - will find processes by port."
fi

# ---------------------------------------------------------------------------
# 1. Close browser windows showing localhost:8000
# ---------------------------------------------------------------------------
echo ""
echo "  Closing browser window..."
if command -v osascript >/dev/null 2>&1; then
    osascript -e 'tell application "Safari" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    osascript -e 'tell application "Google Chrome" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    osascript -e 'tell application "Firefox" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    ok "Closed Safari/Chrome/Firefox windows pointing at localhost:8000"
else
    warn "osascript not available - close browser manually."
fi

# ---------------------------------------------------------------------------
# 2. Stop database tunnel (port 5433)
# ---------------------------------------------------------------------------
echo ""
echo "  Stopping database tunnel..."

KILLED=0

# Stop tunnel HOST process first so its auto-reconnect loop cannot respawn ssh
if [ "$TUNNEL_PID" -gt 0 ]; then
    if kill -0 "$TUNNEL_PID" 2>/dev/null; then
        kill "$TUNNEL_PID" 2>/dev/null || true
        KILLED=1
    fi
fi

# Kill any tunnel.ps1 host windows + their ssh by command-line pattern
if pgrep -f "tunnel.sh" >/dev/null 2>&1; then
    pkill -f "tunnel.sh" 2>/dev/null || true
    KILLED=1
fi

sleep 1

# Kill ssh.exe holding port 5433 (last resort)
if lsof -i :5433 -t >/dev/null 2>&1; then
    kill $(lsof -i :5433 -t) 2>/dev/null || true
    KILLED=1
fi

# Wait up to 10s for port 5433 to close
for i in $(seq 1 20); do
    if ! lsof -i :5433 -t >/dev/null 2>&1; then break; fi
    sleep 0.5
done

if ! lsof -i :5433 -t >/dev/null 2>&1; then
    [ "$KILLED" -eq 1 ] && ok "Tunnel closed." || echo "     Tunnel was not running."
else
    # Force kill
    kill -9 $(lsof -i :5433 -t) 2>/dev/null || true
    sleep 1
    if ! lsof -i :5433 -t >/dev/null 2>&1; then
        ok "Tunnel closed (force)."
    else
        warn "Port 5433 is still busy - a reboot will clear it."
    fi
fi

# ---------------------------------------------------------------------------
# 3. Stop admin panel PHP server (port 8000)
# ---------------------------------------------------------------------------
echo ""
echo "  Stopping admin panel server..."

KILLED=0

if [ "$PHP_PID" -gt 0 ]; then
    if kill -0 "$PHP_PID" 2>/dev/null; then
        kill "$PHP_PID" 2>/dev/null || true
        KILLED=1
    fi
fi

if pgrep -f "php -S 127.0.0.1:8000" >/dev/null 2>&1; then
    pkill -f "php -S 127.0.0.1:8000" 2>/dev/null || true
    KILLED=1
fi

sleep 1

for i in $(seq 1 16); do
    if ! lsof -i :8000 -t >/dev/null 2>&1; then break; fi
    sleep 0.5
done

if ! lsof -i :8000 -t >/dev/null 2>&1; then
    [ "$KILLED" -eq 1 ] && ok "Admin panel server stopped." || echo "     Admin panel server was not running."
else
    kill -9 $(lsof -i :8000 -t) 2>/dev/null || true
    sleep 1
    if ! lsof -i :8000 -t >/dev/null 2>&1; then
        ok "Admin panel server stopped (force)."
    else
        warn "Port 8000 is still busy - something else may be using it."
    fi
fi

# ---------------------------------------------------------------------------
# 4. Clean up session file
# ---------------------------------------------------------------------------
rm -f "$SESSION_FILE"

echo ""
echo "   Everything START-ADMIN opened is now closed!"
echo ""