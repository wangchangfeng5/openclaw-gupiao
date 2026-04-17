param(
  [string]$PhpPath = "D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe"
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\\..")
Set-Location $root

if (-not (Test-Path $PhpPath)) {
  throw "PHP not found: $PhpPath"
}

if (-not (Test-Path ".env")) {
  Copy-Item ".env.example" ".env"
  Write-Host "Created .env from .env.example"
}

& $PhpPath scripts\migrate.php
if ($LASTEXITCODE -ne 0) {
  throw "Migration failed with exit code $LASTEXITCODE"
}

& $PhpPath scripts\seed.php
if ($LASTEXITCODE -ne 0) {
  throw "Seed failed with exit code $LASTEXITCODE"
}

Write-Host "Initialization completed." -ForegroundColor Green
