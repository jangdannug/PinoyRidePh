#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - START (macOS double-click launcher)
#
#  Entry point: runs other-scripts/start_admin.sh (Steps 1-7), then pauses
#  like Windows start-admin.bat's `pause`. Keeps window open so you can see
#  output. Background tunnel + PHP keep running independently (disowned).
# ============================================================================

set -u

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_ROOT" || { echo "ERROR: cannot change to project directory."; exit 1; }

bash "$APP_ROOT/other-scripts/start_admin.sh"

echo ""
read -rp "  Press any key to continue..." -n 1 -r
echo