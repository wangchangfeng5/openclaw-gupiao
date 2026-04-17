param(
  [string]$DevBranch = "dev",
  [string]$MainBranch = "main"
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\..")
Set-Location $root

$git = "C:\Program Files\Git\cmd\git.exe"
if (-not (Test-Path $git)) {
  throw "Git not found: $git"
}

& $git checkout $MainBranch

$hasOrigin = (& $git remote) -contains "origin"
if ($hasOrigin) {
  & $git fetch --all --prune
  & $git pull --ff-only origin $MainBranch
}

& $git merge --no-ff $DevBranch -m "merge($DevBranch): promote tested changes to $MainBranch"

if ($hasOrigin) {
  & $git push origin $MainBranch
}

Write-Host "Promoted $DevBranch -> $MainBranch" -ForegroundColor Green
