@echo off
title Pinoy Ride Admin - Fix Update
cd /d "%~dp0"

echo.
echo ==================================================
echo    PINOY RIDE ADMIN - ONE-TIME UPDATE FIX
echo ==================================================
echo.
echo This will sync your app with the latest version.
echo Any local activity logs will be preserved.
echo.
pause

echo.
echo [1/4] Saving local changes...
git stash --include-untracked

echo.
echo [2/4] Pulling latest version from GitHub...
git pull --rebase

echo.
echo [3/4] Restoring local changes...
git stash pop

echo.
echo [4/4] Done!
echo.
echo ==================================================
echo    UPDATE COMPLETE
echo    You can now use START-ADMIN normally.
echo ==================================================
echo.
pause
