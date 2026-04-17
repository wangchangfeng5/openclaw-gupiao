param(
  [string]$PythonExe = "python",
  [string]$ProjectRoot = "D:\coder\gupiao"
)

$ErrorActionPreference = "Stop"

$taskName = "OpenClawInvestWorkers"
$taskCmd = "$PythonExe"
$taskArgs = "\"$ProjectRoot\\workers\\scheduler.py\""

schtasks /Create /TN $taskName /TR "$taskCmd $taskArgs" /SC MINUTE /MO 1 /F | Out-Null

Write-Host "Task $taskName created (every 1 minute)." -ForegroundColor Green
Write-Host "You can also run manually: powershell -ExecutionPolicy Bypass -File scripts/windows/run-workers.ps1" -ForegroundColor Yellow
