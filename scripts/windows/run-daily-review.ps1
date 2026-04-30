param(
  [string]$PhpExe = "",
  [string]$Slot = "manual",
  [string]$Date = "",
  [int]$UserId = 0
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

$args = @(
  "scripts\\daily_review_snapshot.php",
  "--slot=$Slot"
)

if (-not [string]::IsNullOrWhiteSpace($Date)) {
  $args += "--date=$Date"
}
if ($UserId -gt 0) {
  $args += "--user=$UserId"
}

Write-Host "Running daily review snapshot slot=$Slot ..." -ForegroundColor Cyan
& $resolvedPhp @args
if ($LASTEXITCODE -ne 0) {
  throw "daily review snapshot failed with exit code $LASTEXITCODE"
}

Write-Host "Daily review snapshot finished." -ForegroundColor Green
