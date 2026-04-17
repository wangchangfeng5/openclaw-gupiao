param(
  [switch]$Once
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\\..")
Set-Location $root

$python = "python"
$env:PYTHONDONTWRITEBYTECODE = "1"

if ($Once) {
  & $python workers\scheduler.py --once
} else {
  & $python workers\scheduler.py
}
