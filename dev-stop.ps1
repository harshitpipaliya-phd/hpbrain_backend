# Stop everything the dev stack started (ports 8000-8004 and 5173).
#
# LOOPS UNTIL THE PORTS ARE ACTUALLY FREE. Windows lets more than one process
# bind the same port — PHP's built-in server does not set SO_EXCLUSIVEADDRUSE —
# so a single pass over the listeners can kill one owner of :8001 and leave
# another behind. The straggler then shadows the next server that starts, and
# the app misbehaves for reasons nothing in the logs explains.

$ports = 8000, 8001, 8002, 8003, 8004, 5173

for ($attempt = 1; $attempt -le 6; $attempt++) {
  $conns = Get-NetTCPConnection -LocalPort $ports -State Listen -ErrorAction SilentlyContinue

  if (-not $conns) { break }

  foreach ($c in $conns) {
    try {
      $name = (Get-Process -Id $c.OwningProcess -ErrorAction Stop).ProcessName
      Stop-Process -Id $c.OwningProcess -Force -ErrorAction Stop
      Write-Host "stopped :$($c.LocalPort) ($name, pid $($c.OwningProcess))"
    } catch {}
  }

  Start-Sleep -Seconds 2
}

$left = Get-NetTCPConnection -LocalPort $ports -State Listen -ErrorAction SilentlyContinue

if ($left) {
  Write-Host ''
  Write-Host 'Still listening (stop these manually):' -ForegroundColor Yellow
  $left | ForEach-Object { Write-Host "  :$($_.LocalPort) pid $($_.OwningProcess)" -ForegroundColor Yellow }
} else {
  Write-Host ''
  Write-Host 'All dev ports released.' -ForegroundColor Green
}
