@echo off
title Pinoy Ride Admin - Sync / Fix Update
cd /d "%~dp0"

echo.
echo ==================================================
echo    PINOY RIDE ADMIN - SYNC ALL MACHINES
echo ==================================================
echo.
echo This will:
echo   1. Upload this computer's activity log to GitHub,
echo      but ONLY if this computer has new entries.
echo   2. Download everyone else's latest changes.
echo   3. Leave this machine exactly in sync with GitHub.
echo.
echo Your other local files (.env etc.) are never touched
echo and never uploaded.
echo.
pause

set "GIT_TERMINAL_PROMPT=0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0other-scripts\sync_repo.ps1" -Reason "fix-update"

echo.
echo ==================================================
echo    DONE - you can close this window and use
echo    START-ADMIN normally.
echo ==================================================
echo.
pause
