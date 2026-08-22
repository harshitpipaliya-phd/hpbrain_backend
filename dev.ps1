# Start the full local stack.
#
# Why four PHP workers instead of one `php artisan serve`:
# PHP's built-in server cannot fork on Windows ("forking is not supported on
# this platform"), so a single `artisan serve` handles ONE request at a time.
# The dashboard fires ~15 API calls on boot, they queue, and the page never
# finishes opening. The workers run on 8001-8004 and dev-balancer.mjs spreads
# requests across them from port 8000 -- the origin web/.env already targets.

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

# Each process gets its log redirected to a file. -WindowStyle Minimized on its
# own spawned consoles that exited immediately; redirecting the streams keeps
# them alive AND leaves something to read when one of them refuses to start.
$logs = Join-Path $root 'storage\logs'
if (-not (Test-Path $logs)) { New-Item -ItemType Directory -Force -Path $logs | Out-Null }

Write-Host 'Starting PHP workers on 8001-8004...' -ForegroundColor Cyan
foreach ($port in 8001, 8002, 8003, 8004) {
  Start-Process -FilePath 'php' `
    -ArgumentList 'artisan', 'serve', "--port=$port", '--no-reload' `
    -WorkingDirectory $root -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logs "worker-$port.out") `
    -RedirectStandardError  (Join-Path $logs "worker-$port.err")
}

Start-Sleep -Seconds 4

Write-Host 'Starting API balancer on 8000...' -ForegroundColor Cyan
Start-Process -FilePath 'node' -ArgumentList 'scripts/dev-balancer.mjs' `
  -WorkingDirectory $root -WindowStyle Hidden `
  -RedirectStandardOutput (Join-Path $logs 'balancer.out') `
  -RedirectStandardError  (Join-Path $logs 'balancer.err')

Write-Host 'Starting Vite on 5173...' -ForegroundColor Cyan
# npm is a .cmd shim, so it is launched through cmd rather than directly.
Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', 'npm', 'run', 'dev' `
  -WorkingDirectory (Join-Path $root 'web') -WindowStyle Hidden `
  -RedirectStandardOutput (Join-Path $logs 'vite.out') `
  -RedirectStandardError  (Join-Path $logs 'vite.err')

Start-Sleep -Seconds 3
Write-Host ''
Write-Host 'App:  http://localhost:5173' -ForegroundColor Green
Write-Host 'API:  http://127.0.0.1:8000' -ForegroundColor Green
Write-Host ''
Write-Host 'Stop everything with:  .\dev-stop.ps1' -ForegroundColor DarkGray
