param(
  [string]$ProjectRoot = "D:\coder\gupiao",
  [string]$TaskNoon = "OpenClaw-ReviewSnapshot-Noon",
  [string]$TaskClose = "OpenClaw-ReviewSnapshot-Close"
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

$scriptPath = Join-Path $ProjectRoot "scripts\\windows\\run-review-snapshot.ps1"
if (-not (Test-Path $scriptPath)) {
  throw "script not found: $scriptPath"
}

function Register-ReviewTask {
  param(
    [string]$TaskName,
    [string]$AtTime,
    [string]$Slot
  )

  $runCmd = "`"$powershellExe`" -NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -Slot $Slot"
  & $schtasksExe /Create /TN $TaskName /TR $runCmd /SC DAILY /ST $AtTime /F | Out-Null
  if ($LASTEXITCODE -ne 0) {
    throw "failed creating task: $TaskName"
  }
}

Register-ReviewTask -TaskName $TaskNoon -AtTime "12:10" -Slot "midday"
Register-ReviewTask -TaskName $TaskClose -AtTime "15:12" -Slot "close"

Write-Host "Scheduled tasks ready:" -ForegroundColor Green
Write-Host " - $TaskNoon at 12:10" -ForegroundColor Green
Write-Host " - $TaskClose at 15:12" -ForegroundColor Green
Write-Host "Manual run example:" -ForegroundColor Yellow
Write-Host " powershell -ExecutionPolicy Bypass -File .\\scripts\\windows\\run-review-snapshot.ps1 -Slot manual" -ForegroundColor Yellow
