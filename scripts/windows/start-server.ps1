param(
  [string]$PhpPath = "D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe",
  [string]$BindHost = "127.0.0.1",
  [int]$Port = 8080
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\\..")
Set-Location $root

if (-not (Test-Path $PhpPath)) {
  throw "PHP not found: $PhpPath"
}

$env:APP_URL = "http://$BindHost`:$Port"
Write-Host "Starting server at $env:APP_URL ..." -ForegroundColor Cyan

& $PhpPath -S "$BindHost`:$Port" -t public public/index.php
