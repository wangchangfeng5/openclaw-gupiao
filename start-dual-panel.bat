@echo off
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\windows\start-manual-panel.ps1"
if errorlevel 1 (
  echo.
  echo Start finished with warnings. Please check the PowerShell output above.
)
echo.
pause
