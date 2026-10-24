#!/bin/bash
# ============================================================================
#  Pinoy Ride Admin - STOP (macOS double-click launcher)
#
#  Entry point: runs other-scripts/stop_admin.sh, then pauses like Windows
#  stop-admin.bat's `pause`.
# ============================================================================

set -u

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_ROOT" || { echo "ERROR: cannot change to project directory."; exit 1; }

bash "$APP_ROOT/other-scripts/stop_admin.sh"

echo ""
read -rp "  Press any key to continue..." -n 1 -r
echo