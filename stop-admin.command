#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - STOP (macOS double-click launcher)
#
#  macOS equivalent of stop-admin.bat. Double-click to stop tunnel + PHP
#  server and close the browser window.
# ============================================================================

set -u

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_ROOT" || { echo "ERROR: cannot change to project directory."; exit 1; }

# --- colors (only if a real terminal) ---
if [ -t 1 ]; then
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    RED='\033[0;31m'
    NC='\033[0m'
else
    GREEN=''
    YELLOW=''
    RED=''
    NC=''
fi

say_ok()  { echo "${GREEN}[OK]${NC} $1"; }
say_warn() { echo "${YELLOW}[!]${NC} $1"; }
say_fail() {
    echo "${RED}[X]${NC} $1"
    exit 1
}

echo ""
echo "  Pinoy Ride Admin - shutting down..."
echo ""

# --- Load session file (if it exists) ---
SESSION_FILE="$APP_ROOT/.pinoyride-session.json"
TUNNEL_PID=0
PHP_PID=0

if [ -f "$SESSION_FILE" ]; then
    TUNNEL_PID=$(python3 -c "import json; print(json.load(open('$SESSION_FILE')).get('tunnelPid', 0))" 2>/dev/null || echo 0)
    PHP_PID=$(python3 -c "import json; print(json.load(open('$SESSION_FILE')).get('phpPid', 0))" 2>/dev/null || echo 0)
    echo "     Loaded session: tunnelPid=$TUNNEL_PID, phpPid=$PHP_PID"
else
    echo "     No session file found - will try to find processes by port."
fi

# --- Step 1: Stop PHP server on port 8000 ---
echo ""
echo "  Stopping PHP server (port 8000)..."
if lsof -i :8000 -t >/dev/null 2>&1; then
    lsof -i :8000 -t 2>/dev/null | xargs -r kill 2>/dev/null || true
    sleep 1
    if lsof -i :8000 -t >/dev/null 2>&1; then
        say_warn "Port 8000 still busy - trying force kill..."
        lsof -i :8000 -t 2>/dev/null | xargs -r kill -9 2>/dev/null || true
    fi
    say_ok "PHP server stopped."
else
    say_warn "No PHP server on port 8000."
fi

# --- Step 2: Stop SSH tunnel on port 5433 ---
echo ""
echo "  Stopping database tunnel (port 5433)..."
if lsof -i :5433 -t >/dev/null 2>&1; then
    lsof -i :5433 -t 2>/dev/null | xargs -r kill 2>/dev/null || true
    sleep 1
    if lsof -i :5433 -t >/dev/null 2>&1; then
        say_warn "Port 5433 still busy - trying force kill..."
        lsof -i :5433 -t 2>/dev/null | xargs -r kill -9 2>/dev/null || true
    fi
    say_ok "Database tunnel stopped."
else
    say_warn "No tunnel on port 5433."
fi

# --- Step 3: Close the browser window ---
echo ""
echo "  Closing browser window..."
# Close browser windows showing the admin panel (macOS only - uses AppleScript)
# Fall back to just telling the user to close it
if command -v osascript >/dev/null 2>&1; then
    osascript -e 'tell application "Safari" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    osascript -e 'tell application "Google Chrome" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    osascript -e 'tell application "Firefox" to close windows where title contains "localhost:8000"' 2>/dev/null || true
    say_ok "Closed Safari/Chrome/Firefox windows pointing at localhost:8000"
fi

# Remove session file so a fresh start creates a new one
rm -f "$SESSION_FILE"

echo ""
echo "  ${GREEN}Pinoy Ride Admin is now stopped.${NC}"
echo "  ${YELLOW}You can re-open it anytime by double-clicking start-admin.command${NC}"
echo ""