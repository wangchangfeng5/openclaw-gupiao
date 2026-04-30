param(
  [string]$PhpExe = "",
  [string]$User = "",
  [int]$Limit = 30,
  [int]$HotLimit = 12,
  [string]$Slot = "midday",
  [switch]$SkipIngest,
  [switch]$SkipReconcile,
  [switch]$SkipSnapshot,
  [switch]$Json
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

$limit = [Math]::Max(1, [Math]::Min(60, [int]$Limit))
$hotLimit = [Math]::Max(1, [Math]::Min(60, [int]$HotLimit))
$args = @(
  "scripts\\hot_midday_refresh.php",
  "--limit=$limit",
  "--hot-limit=$hotLimit",
  "--slot=$Slot"
)

if (-not [string]::IsNullOrWhiteSpace($User)) { $args += "--user=$User" }
if ($SkipIngest) { $args += "--skip-ingest" }
if ($SkipReconcile) { $args += "--skip-reconcile" }
if ($SkipSnapshot) { $args += "--skip-snapshot" }
if ($Json) { $args += "--json" }

Write-Host "Running hot midday refresh..." -ForegroundColor Cyan
& $resolvedPhp @args
if ($LASTEXITCODE -ne 0) {
  throw "hot_midday_refresh failed with exit code $LASTEXITCODE"
}

Write-Host "hot midday refresh finished." -ForegroundColor Green

