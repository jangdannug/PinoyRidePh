@echo off
title Pinoy Ride Admin
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0other-scripts\start_admin.ps1"
echo.
pause
