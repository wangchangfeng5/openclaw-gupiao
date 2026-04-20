param(
  [string]$PythonExe = "python",
  [string]$ProjectRoot = "D:\coder\gupiao"
)

$ErrorActionPreference = "Stop"
$schtasksExe = Join-Path $env:WINDIR "System32\\schtasks.exe"
$powershellExe = Join-Path $env:WINDIR "System32\\WindowsPowerShell\\v1.0\\powershell.exe"

if (-not (Test-Path $schtasksExe)) {
  throw "schtasks not found: $schtasksExe"
}

if (-not (Test-Path $powershellExe)) {
  throw "powershell.exe not found: $powershellExe"
}

$taskName = "OpenClawInvestWorkers"
$taskCmd = "$PythonExe"
$taskArgs = "`"$ProjectRoot\workers\scheduler.py`""

& $schtasksExe /Create /TN $taskName /TR "$taskCmd $taskArgs" /SC MINUTE /MO 1 /F | Out-Null
if ($LASTEXITCODE -ne 0) {
  throw "failed creating task: $taskName"
}

$reviewScript = "$ProjectRoot\\scripts\\windows\\run-review-snapshot.ps1"
if (Test-Path $reviewScript) {
  $cmdNoon = "`"$powershellExe`" -NoProfile -ExecutionPolicy Bypass -File `"$reviewScript`" -Slot midday"
  & $schtasksExe /Create /TN "OpenClaw-ReviewSnapshot-Noon" /TR $cmdNoon /SC DAILY /ST 12:10 /F | Out-Null
  if ($LASTEXITCODE -ne 0) {
    throw "failed creating task: OpenClaw-ReviewSnapshot-Noon"
  }

  $cmdClose = "`"$powershellExe`" -NoProfile -ExecutionPolicy Bypass -File `"$reviewScript`" -Slot close"
  & $schtasksExe /Create /TN "OpenClaw-ReviewSnapshot-Close" /TR $cmdClose /SC DAILY /ST 15:12 /F | Out-Null
  if ($LASTEXITCODE -ne 0) {
    throw "failed creating task: OpenClaw-ReviewSnapshot-Close"
  }
}

Write-Host "Task $taskName created (every 1 minute)." -ForegroundColor Green
Write-Host "Review snapshot tasks created: OpenClaw-ReviewSnapshot-Noon / OpenClaw-ReviewSnapshot-Close" -ForegroundColor Green
Write-Host "You can also run manually: powershell -ExecutionPolicy Bypass -File scripts/windows/run-workers.ps1" -ForegroundColor Yellow
