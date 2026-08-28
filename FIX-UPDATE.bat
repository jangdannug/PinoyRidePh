@echo off
title Pinoy Ride Admin - Fix Update
cd /d "%~dp0"

echo.
echo ==================================================
echo    PINOY RIDE ADMIN - ONE-TIME UPDATE FIX
echo ==================================================
echo.
echo This will force-sync your app with the latest version.
echo Local activity logs are pushed first, then latest code pulled.
echo.
pause

REM Read GIT_TOKEN from .env
set "GIT_TOKEN="
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if /i "%%A"=="GIT_TOKEN" set "GIT_TOKEN=%%B"
)

set "REPO=https://github.com/jangdannug/PinoyRidePh.git"
if not "%GIT_TOKEN%"=="" if not "%GIT_TOKEN%"=="CHANGE_ME" (
    set "REPO=https://jangdannug:%GIT_TOKEN%@github.com/jangdannug/PinoyRidePh.git"
)

echo.
echo [1/5] Setting up git identity...
git config user.email "pinoyride-admin@local"
git config user.name "PinoyRide Admin"

echo.
echo [2/5] Saving and pushing your local activity logs...
git add logs/
git commit -m "Activity log sync (fix-update)"
git pull --rebase %REPO% main
git push %REPO% main

echo.
echo [3/5] Saving any remaining local changes...
git stash --include-untracked

echo.
echo [4/5] Pulling latest version from GitHub...
git pull --rebase %REPO% main

echo.
echo [5/5] Restoring local changes...
git stash pop

echo.
echo ==================================================
echo    UPDATE COMPLETE
echo    You can now use START-ADMIN normally.
echo ==================================================
echo.
pause
