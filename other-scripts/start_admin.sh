#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - start_admin.sh (macOS)
#
#  Implements the macOS equivalent of Windows start-admin.bat -> start_admin.ps1
#  Steps 1-7. Called by start-admin.command.
# ============================================================================

set -u

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$APP_ROOT/.env"
SESSION_FILE="$APP_ROOT/.pinoyride-session.json"
BOOT_ID_FILE="$APP_ROOT/logs/.boot-id"
TUNNEL_LOG="$APP_ROOT/logs/tunnel.log"
PHP_LOG="$APP_ROOT/logs/php.log"

HOST="markangelogonzalespinoyride@54.251.171.207"
PORT=2222
KEY_PRIV="$HOME/.ssh/pinoyride_ed25519"

# ---- colors (only on real terminal) ----
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
fail() { echo "${RED}[X]${NC} $1" >&2; exit 1; }

# ============================================================================
echo ""
echo "  PINOY RIDE ADMIN - STARTING UP (macOS)"
echo ""

# ---------------------------------------------------------------------------
# Step 1 — Prerequisites: PHP, Git, SSH
# ---------------------------------------------------------------------------
echo "  [Step 1/7] Checking prerequisites..."

PHP_BIN=""
for candidate in \
    "/Applications/XAMPP/xamppfiles/bin/php" \
    "/opt/homebrew/bin/php" \
    "/usr/local/bin/php" \
    "/usr/bin/php" \
    "$(command -v php 2>/dev/null)"
do
    if [ -n "$candidate" ] && [ -x "$candidate" 2>/dev/null ]; then
        PHP_BIN="$candidate"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    fail "PHP not found. Install PHP 7.4+ first."
fi
ok "PHP found: $PHP_BIN ($($PHP_BIN -r 'echo PHP_VERSION;'))"

if ! command -v git >/dev/null 2>&1; then
    fail "Git not found in PATH."
fi
ok "Git found: $(git --version)"

if ! command -v ssh >/dev/null 2>&1; then
    fail "SSH client not found in PATH."
fi
ok "SSH found"

# ---------------------------------------------------------------------------
# Step 2 — Enable pdo_pgsql extension in php.ini
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 2/7] Checking pdo_pgsql extension..."

PHP_INI="$($PHP_BIN --ini 2>/dev/null | sed -n 's/.*Loaded Configuration File.*: *//p' | tr -d '\r')"
if [ -z "$PHP_INI" ] || [ ! -f "$PHP_INI" ]; then
    PHP_DIR="$(dirname "$PHP_BIN")"
    if [ -f "$PHP_DIR/php.ini" ]; then
        PHP_INI="$PHP_DIR/php.ini"
    fi
fi

if [ -n "$PHP_INI" ] && [ -f "$PHP_INI" ]; then
    if ! $PHP_BIN -m 2>/dev/null | grep -qi 'pdo_pgsql'; then
        echo "     pdo_pgsql not loaded. Enabling in $PHP_INI..."
        if [ ! -f "${PHP_INI}.bak-pinoyride" ]; then
            cp "$PHP_INI" "${PHP_INI}.bak-pinoyride"
        fi
        if grep -q '^;extension=pdo_pgsql' "$PHP_INI"; then
            sed -i '' 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' "$PHP_INI"
        elif ! grep -q '^extension=pdo_pgsql' "$PHP_INI"; then
            echo "extension=pdo_pgsql" >> "$PHP_INI"
        fi
    fi
fi

if $PHP_BIN -m 2>/dev/null | grep -qi 'pdo_pgsql'; then
    ok "pdo_pgsql extension loaded."
else
    fail "pdo_pgsql extension not available. Check php.ini."
fi

# ---------------------------------------------------------------------------
# Step 3 — Git sync
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 3/7] Syncing repository..."

if command -v pwsh >/dev/null 2>&1; then
    pwsh -NoProfile -ExecutionPolicy Bypass -File "$APP_ROOT/other-scripts/sync_repo.ps1" -Root "$APP_ROOT" -Reason startup 2>&1 || true
else
    git -C "$APP_ROOT" pull --rebase --autostash 2>&1 || true
fi
ok "Repository synced."
# ---------------------------------------------------------------------------
# Step 4 — .env file
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 4/7] Configuring .env..."

REQUIRED_KEYS="DB_HOST DB_PORT DB_NAME DB_USER DB_PASS ADMIN_USER ADMIN_PASS GOOGLE_MAPS_API_KEY SSH_PASS GIT_TOKEN"

if [ ! -f "$ENV_FILE" ]; then
    echo "     Creating .env from template..."
    cat > "$ENV_FILE" << 'ENVEOF'
DB_HOST=127.0.0.1
DB_PORT=5433
DB_NAME=riderapp
DB_USER=markangelogonzales
DB_PASS=l3JvueqsjUPBMhwqTsjsNy6DRe3wBFaNmJcjiVUX2k726QeNen235Bz4FYbPwDMb
ADMIN_USER=admin
ADMIN_PASS=pinoyride2026
GOOGLE_MAPS_API_KEY=AIzaSyBAmVIdu6gq3005jPrljn_UcI035vjCCr4
SSH_PASS=CHANGE_ME
GIT_TOKEN=CHANGE_ME
ENVEOF
    ok ".env created."
else
    for key in $REQUIRED_KEYS; do
        if ! grep -q "^${key}=" "$ENV_FILE"; then
            echo "${key}=CHANGE_ME" >> "$ENV_FILE"
        fi
    done
    ok ".env exists - verified required keys."
fi
# ---------------------------------------------------------------------------
# Step 5 — SSH key setup
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 5/7] Setting up SSH key..."

if [ ! -f "$KEY_PRIV" ]; then
    echo "     Generating SSH key $KEY_PRIV ..."
    mkdir -p "$HOME/.ssh"
    ssh-keygen -q -t ed25519 -N "" -f "$KEY_PRIV" -C pinoyride-admin
fi
PUBKEY=$(cat "${KEY_PRIV}.pub")

# Read password from .env SSH_PASS or prompt
SSH_PASS=""
if [ -f "$ENV_FILE" ]; then
    SSH_PASS=$(grep '^SSH_PASS=' "$ENV_FILE" | cut -d= -f2-)
fi

if [ -z "$SSH_PASS" ] || [ "$SSH_PASS" = "CHANGE_ME" ]; then
    printf "Server SSH password: "
    read -rs SSH_PASS
    echo
fi

# Install key via SSH_ASKPASS (no interactive prompt).
# SSH_ASKPASS is set inline (not exported) so tunnel.sh in Step 6 does not
# inherit a dangling reference to a deleted temp file.
ASKPASS=$(mktemp)
printf '#!/bin/sh\necho "%s"\n' "$SSH_PASS" > "$ASKPASS"
chmod +x "$ASKPASS"

RESULT=$(SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-:0}" ssh -p "$PORT" -o StrictHostKeyChecking=accept-new "$HOST" \
  "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && (grep -qxF '$PUBKEY' ~/.ssh/authorized_keys || echo '$PUBKEY' >> ~/.ssh/authorized_keys) && echo KEY_INSTALLED" \
  2>&1) || true

rm -f "$ASKPASS"
unset SSH_ASKPASS SSH_ASKPASS_REQUIRE DISPLAY 2>/dev/null || true

if echo "$RESULT" | grep -q 'KEY_INSTALLED'; then
    ok "SSH key installed. Tunnels will connect automatically."
else
    warn "Key install may have failed. Output: $RESULT"
fi
# ---------------------------------------------------------------------------
# Step 6 — SSH tunnel
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 6/7] Starting database tunnel..."

# Stop stale tunnel on 5433
if lsof -i :5433 -t >/dev/null 2>&1; then
    echo "     Stopping stale tunnel on port 5433..."
    kill $(lsof -i :5433 -t) 2>/dev/null || true
    sleep 1
fi

if [ -S /tmp/pinoyride_ssh_tunnel.sock ]; then
    rm -f /tmp/pinoyride_ssh_tunnel.sock
fi

bash "$APP_ROOT/other-scripts/tunnel.sh" > "$TUNNEL_LOG" 2>&1 &
TUNNEL_PID=$!
echo "     Tunnel PID: $TUNNEL_PID"

TUNNEL_UP=0
for i in $(seq 1 30); do
    if lsof -i :5433 -t >/dev/null 2>&1; then TUNNEL_UP=1; break; fi
    sleep 1
done

if [ "$TUNNEL_UP" -eq 1 ]; then
    ok "Database tunnel is up!"
else
    echo "     Tunnel output:"
    cat "$TUNNEL_LOG" 2>/dev/null || true
    fail "Database tunnel failed to start."
fi
# ---------------------------------------------------------------------------
# Step 7 — PHP server + browser
# ---------------------------------------------------------------------------
echo ""
echo "  [Step 7/7] Starting admin panel..."

# Stop stale PHP on 8000
if lsof -i :8000 -t >/dev/null 2>&1; then
    echo "     Stopping stale PHP server on port 8000..."
    kill $(lsof -i :8000 -t) 2>/dev/null || true
    sleep 1
fi

"$PHP_BIN" -S 127.0.0.1:8000 > "$PHP_LOG" 2>&1 &
PHP_PID=$!
echo "     PHP PID: $PHP_PID"

PHP_UP=0
for i in $(seq 1 15); do
    if lsof -i :8000 -t >/dev/null 2>&1; then PHP_UP=1; break; fi
    sleep 1
done

if [ "$PHP_UP" -eq 1 ]; then
    ok "Admin panel running at http://localhost:8000"
else
    echo "     PHP output:"
    cat "$PHP_LOG" 2>/dev/null || true
    fail "PHP server failed to start."
fi

# Open browser
open "http://localhost:8000/index.php" 2>/dev/null && echo "     Opening browser..."

# Write boot ID
mkdir -p "$APP_ROOT/logs"
BOOT_ID="$(uuidgen 2>/dev/null || echo "$(date +%s%N)")"
echo "$BOOT_ID" > "$BOOT_ID_FILE"

# Save session for stop script
cat > "$SESSION_FILE" << 'SESSION_EOF'
{
  "startedAt": "STARTSAT",
  "tunnelPid": TUNNELPID,
  "phpPid": PHPPID,
  "browserPid": 0
}
SESSION_EOF

# Patch in dynamic values (avoids heredoc variable expansion issues)
sed -i '' "s/STARTSAT/$(date -u +%Y-%m-%dT%H:%M:%SZ)/" "$SESSION_FILE"
sed -i '' "s/TUNNELPID/$TUNNEL_PID/" "$SESSION_FILE"
sed -i '' "s/PHPPID/$PHP_PID/" "$SESSION_FILE"

ok "Session saved to .pinoyride-session.json"

echo ""
echo "  PINOY RIDE ADMIN IS READY!"
echo "  Browser opened to http://localhost:8000"
echo "  Keep this window open (minimize is fine)."
echo "  To stop: double-click stop-admin.command"
echo ""

# Detach background processes so they keep running after this window closes
# (same as .bat: minimized tunnel/PHP windows keep running independently)
disown $TUNNEL_PID $PHP_PID 2>/dev/null || true