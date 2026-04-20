param(
  [string]$PhpStartScript = "",
  [string]$OpenClawDir = "D:\coder\openclaw",
  [string]$StreamlitApp = "D:\coder\openclaw\a_share_panel.py",
  [string]$BindHost = "127.0.0.1",
  [int]$WebPort = 8080,
  [int]$StreamlitPort = 8501,
  [switch]$NoWorkers,
  [switch]$NoBrowser
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PhpStartScript)) {
  $PhpStartScript = Join-Path $PSScriptRoot "start-server.ps1"
}

function Test-IsListening {
  param([int]$Port)
  try {
    $pattern = "[:\.]$Port\s+.*LISTENING"
    return $null -ne (netstat -ano | Select-String -Pattern $pattern)
  } catch {
    return $false
  }
}

function Wait-UrlReady {
  param(
    [string]$Url,
    [int]$TimeoutSec = 45
  )

  $deadline = (Get-Date).AddSeconds($TimeoutSec)
  while ((Get-Date) -lt $deadline) {
    try {
      $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 4
      if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500) {
        return $true
      }
    } catch {
      # keep retrying
    }
    Start-Sleep -Milliseconds 800
  }
  return $false
}

$webUrl = "http://$BindHost`:$WebPort/"
$streamlitUrl = "http://$BindHost`:$StreamlitPort/"
$workerScript = Join-Path $PSScriptRoot "run-workers.ps1"

function Test-WorkerSchedulerRunning {
  try {
    $processes = Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
      $cmd = $_.CommandLine
      if ($null -eq $cmd) {
        $cmd = ""
      }
      $cmd -match 'workers[\\/]+scheduler\.py'
    }
    return $processes.Count -gt 0
  } catch {
    return $false
  }
}

if (-not (Test-Path $PhpStartScript)) {
  throw "PHP startup script not found: $PhpStartScript"
}

if (-not $NoWorkers) {
  if (Test-Path $workerScript) {
    if (-not (Test-WorkerSchedulerRunning)) {
      Write-Host "Starting scheduler worker for intraday/close snapshot refresh..." -ForegroundColor Cyan
      Start-Process -FilePath "powershell" -ArgumentList @(
        "-NoProfile",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        "`"$workerScript`""
      ) -WindowStyle Minimized | Out-Null
    } else {
      Write-Host "Scheduler worker already running." -ForegroundColor Yellow
    }
  } else {
    Write-Warning "Worker startup script not found: $workerScript"
  }
}

if (-not (Test-IsListening -Port $WebPort)) {
  Write-Host "Starting PHP panel on $webUrl" -ForegroundColor Cyan
  Start-Process -FilePath "powershell" -ArgumentList @(
    "-NoProfile",
    "-ExecutionPolicy",
    "Bypass",
    "-File",
    "`"$PhpStartScript`"",
    "-BindHost",
    $BindHost,
    "-Port",
    $WebPort
  ) -WindowStyle Minimized | Out-Null
} else {
  Write-Host "PHP panel already running on $webUrl" -ForegroundColor Yellow
}

if (-not (Test-IsListening -Port $StreamlitPort)) {
  if (-not (Test-Path $StreamlitApp)) {
    throw "Streamlit app not found: $StreamlitApp"
  }

  Write-Host "Starting Streamlit panel on $streamlitUrl" -ForegroundColor Cyan
  $streamlitCommand = "$env:STREAMLIT_BROWSER_GATHER_USAGE_STATS='false'; Set-Location '$OpenClawDir'; python -m streamlit run '$StreamlitApp' --server.address $BindHost --server.port $StreamlitPort --server.headless true --browser.gatherUsageStats false"
  Start-Process -FilePath "powershell" -ArgumentList @(
    "-NoProfile",
    "-ExecutionPolicy",
    "Bypass",
    "-Command",
    $streamlitCommand
  ) -WindowStyle Minimized | Out-Null
} else {
  Write-Host "Streamlit panel already running on $streamlitUrl" -ForegroundColor Yellow
}

$webReady = Wait-UrlReady -Url $webUrl -TimeoutSec 45
$streamlitReady = Wait-UrlReady -Url $streamlitUrl -TimeoutSec 60

if ($webReady) {
  Write-Host "Web panel ready: $webUrl" -ForegroundColor Green
} else {
  Write-Warning "Web panel is not reachable: $webUrl"
}

if ($streamlitReady) {
  Write-Host "Streamlit panel ready: $streamlitUrl" -ForegroundColor Green
} else {
  Write-Warning "Streamlit panel is not reachable: $streamlitUrl"
}

if (-not $NoBrowser) {
  Start-Process $webUrl | Out-Null
  Start-Process $streamlitUrl | Out-Null
}

if (-not ($webReady -and $streamlitReady)) {
  exit 1
}
