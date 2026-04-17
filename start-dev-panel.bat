@echo off
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\windows\start-manual-panel.ps1" -WebPort 8081 -StreamlitPort 8502
if errorlevel 1 (
  echo.
  echo Dev start finished with warnings. Please check the PowerShell output above.
)
echo.
pause
