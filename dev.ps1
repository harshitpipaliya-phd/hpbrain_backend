# Start the full local stack in one window.
#
# The concurrency that used to live in this script now lives in
# `php artisan serve` itself (app/Console/Commands/ServeCommand.php), because
# that is the command everybody actually types. This script is now just a
# convenience that starts the backend and the frontend together.
#
# MUST NOT spawn workers itself. `php artisan serve --port=8001` would re-enter
# the overridden command and each worker would start four more of its own.
# There is exactly one `artisan serve` below, and it manages its own workers.

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

$logs = Join-Path $root 'storage\logs'
if (-not (Test-Path $logs)) { New-Item -ItemType Directory -Force -Path $logs | Out-Null }

Write-Host 'Starting API on 8000 (4 workers behind a balancer)...' -ForegroundColor Cyan
Start-Process -FilePath 'php' -ArgumentList 'artisan', 'serve' `
  -WorkingDirectory $root -WindowStyle Hidden `
  -RedirectStandardOutput (Join-Path $logs 'api.out') `
  -RedirectStandardError  (Join-Path $logs 'api.err')

Start-Sleep -Seconds 4

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
Write-Host 'Or run them separately:' -ForegroundColor DarkGray
Write-Host '  php artisan serve        (backend)' -ForegroundColor DarkGray
Write-Host '  cd web; npm run dev      (frontend)' -ForegroundColor DarkGray
Write-Host ''
Write-Host 'Stop everything with:  .\dev-stop.ps1' -ForegroundColor DarkGray
