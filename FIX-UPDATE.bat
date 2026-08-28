@echo off
title Pinoy Ride Admin - Fix Update
cd /d "%~dp0"

echo.
echo ==================================================
echo    PINOY RIDE ADMIN - ONE-TIME UPDATE FIX
echo ==================================================
echo.
echo This connects your app to GitHub for auto-updates
echo and syncs to the latest version.
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
echo [1/6] Setting up git identity...
git config --global user.email "pinoyride-admin@local" 2>nul
git config --global user.name "PinoyRide Admin" 2>nul

REM Check if this is a git repo. If not, initialize one.
if not exist ".git" (
    echo.
    echo [2/6] First time - connecting this folder to GitHub...
    git init
    git remote add origin %REPO%
    git fetch origin main
    REM Reset working tree to match GitHub, keeping local files that aren't tracked
    git checkout -f -b main origin/main
    goto :done
)

echo.
echo [2/6] Git repo found. Syncing...

echo.
echo [3/6] Pushing your local activity logs...
git add logs/
git commit -m "Activity log sync (fix-update)" 2>nul
git pull --rebase %REPO% main 2>nul
git push %REPO% main 2>nul

echo.
echo [4/6] Saving any remaining local changes...
git stash --include-untracked 2>nul

echo.
echo [5/6] Pulling latest version...
git pull --rebase %REPO% main

echo.
echo [6/6] Restoring local changes...
git stash pop 2>nul

:done
echo.
echo ==================================================
echo    UPDATE COMPLETE
echo    You can now use START-ADMIN normally.
echo ==================================================
echo.
pause
