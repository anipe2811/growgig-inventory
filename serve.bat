@echo off
setlocal
title Aktifotak Inventory - http://localhost:8090
cd /d "%~dp0"

REM Use PHP from PATH (Laravel Herd); fall back to a known Herd binary if not found.
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\Users\anipe\.config\herd\bin\php84\php.exe"

echo ============================================================
echo   Aktifotak Inventory  ^|  http://localhost:8090
echo   Document root: "%~dp0"
echo   Press Ctrl+C to stop the server.
echo ============================================================
echo.

"%PHP%" -S localhost:8090 -t .

echo.
echo Server stopped.
pause
