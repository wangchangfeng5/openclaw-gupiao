param(
  [string]$PhpExe = "",
  [string]$Slot = "manual",
  [switch]$NoSync
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\\..")
Set-Location $root

function Resolve-PhpExe {
  param([string]$InputPhp)

  $candidates = @()
  if (-not [string]::IsNullOrWhiteSpace($InputPhp)) {
    $candidates += $InputPhp
  }
  if (-not [string]::IsNullOrWhiteSpace($env:PHP_BIN)) {
    $candidates += $env:PHP_BIN
  }
  if (-not [string]::IsNullOrWhiteSpace($env:PHP_PATH)) {
    $candidates += $env:PHP_PATH
  }
  $candidates += @(
    "D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe",
    "D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe",
    "D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"
  )

  foreach ($c in $candidates) {
    if (-not [string]::IsNullOrWhiteSpace($c) -and (Test-Path $c)) {
      return (Resolve-Path $c).Path
    }
  }

  try {
    $cmd = Get-Command php -ErrorAction Stop
    if ($cmd -and $cmd.Source) {
      return $cmd.Source
    }
  } catch {
    # ignore
  }

  return ""
}

$resolvedPhp = Resolve-PhpExe -InputPhp $PhpExe
if ([string]::IsNullOrWhiteSpace($resolvedPhp)) {
  throw "PHP not found. Provide -PhpExe or set env PHP_BIN."
}

$syncArg = if ($NoSync) { "--sync=0" } else { "--sync=1" }
$cmdArgs = @(
  "scripts\\review_snapshot.php",
  "--slot=$Slot",
  $syncArg,
  "--watchlist-limit=360",
  "--insight-limit=36"
)

Write-Host "Running review snapshot slot=$Slot ..." -ForegroundColor Cyan
& $resolvedPhp @cmdArgs
if ($LASTEXITCODE -ne 0) {
  throw "review snapshot failed with exit code $LASTEXITCODE"
}

Write-Host "Review snapshot finished." -ForegroundColor Green
