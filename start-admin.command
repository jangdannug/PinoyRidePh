#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - START (macOS double-click launcher)
#
#  macOS equivalent of start-admin.bat. Double-click to start tunnel + PHP
#  server and open the browser at http://localhost:8000
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
echo "  Pinoy Ride Admin - starting up..."
echo ""
echo "  Checking PHP..."
if ! command -v php >/dev/null 2>&1; then
    say_fail "PHP is not installed or not in PATH. Install PHP 7.4+ first."
fi
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
echo "     PHP found: $PHP_VERSION"

if ! php -m 2>/dev/null | grep -q 'pdo_pgsql'; then
    say_warn "pdo_pgsql extension not found. The app needs it to connect to Postgres."
    read -rp "  Continue anyway? (y/N) " -n 1 -r
echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then say_fail "Aborted."; fi
fi

echo ""
echo "  Starting database tunnel..."

# Stop stale tunnel on 5433
if lsof -i :5433 -t >/dev/null 2>&1; then
    echo "     Stopping stale tunnel on port 5433..."
    kill $(lsof -i :5433 -t) 2>/dev/null || true
    sleep 1
fi

if [ -S /tmp/pinoyride_ssh_tunnel.sock ]; then
    echo "     Removing stale tunnel socket..."
    rm -f /tmp/pinoyride_ssh_tunnel.sock
fi

bash other-scripts/tunnel.sh > /tmp/pinoyride_tunnel.log 2>&1 &
TUNNEL_PID=$!
echo "     Tunnel PID: $TUNNEL_PID"

TUNNEL_UP=0
for i in $(seq 1 30); do
    if lsof -i :5433 -t >/dev/null 2>&1; then TUNNEL_UP=1; break; fi
    sleep 1
done

if [ "$TUNNEL_UP" -eq 1 ]; then
    say_ok "Database tunnel is up!"
else
    echo "     Tunnel output:"
    cat /tmp/pinoyride_tunnel.log 2>/dev/null || true
    say_fail "Database tunnel failed to start."
fi

echo ""
echo "  Starting PHP server..."

# Stop stale PHP on 8000
if lsof -i :8000 -t >/dev/null 2>&1; then
    echo "     Stopping stale PHP server on port 8000..."
    kill $(lsof -i :8000 -t) 2>/dev/null || true
    sleep 1
fi

php -S 127.0.0.1:8000 > /tmp/pinoyride_php.log 2>&1 &
PHP_PID=$!
echo "     PHP PID: $PHP_PID"

PHP_UP=0
for i in $(seq 1 15); do
    if lsof -i :8000 -t >/dev/null 2>&1; then PHP_UP=1; break; fi
    sleep 1
done

if [ "$PHP_UP" -eq 1 ]; then
    say_ok "Admin panel running at http://localhost:8000"
else
    echo "     PHP output:"
    cat /tmp/pinoyride_php.log 2>/dev/null || true
    say_fail "PHP server failed to start."
fi

echo ""
read -rp "  Open http://localhost:8000 in your browser now? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    open "http://localhost:8000/index.php" 2>/dev/null && echo "     Opening browser..."
fi

# Write a boot ID so the app forces staff re-login on each fresh start
BOOT_ID="$(uuidgen 2>/dev/null || echo "$(date +%s%N)")"
echo "$BOOT_ID" > "$APP_ROOT/logs/.boot-id" 2>/dev/null || true

# Save session for the stop script
cat > "$APP_ROOT/.pinoyride-session.json" << SESSION_EOF
{
  "startedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "tunnelPid": $TUNNEL_PID,
  "phpPid": $PHP_PID,
  "browserPid": 0
}
SESSION_EOF

say_ok "Session saved to .pinoyride-session.json"

echo ""
echo "  ${GREEN}Pinoy Ride Admin is ready!${NC}"
echo "  ${YELLOW}Keep this window open (minimize is fine).${NC}"
echo "  ${NC}To stop everything later, double-click: stop-admin.command${NC}"
echo ""

# Detach background processes so they keep running after this window closes
# (same as .bat: minimized tunnel/PHP windows keep running independently)
disown $TUNNEL_PID $PHP_PID 2>/dev/null || true

echo ""
read -rp "  Press any key to continue..." -n 1 -r
echo