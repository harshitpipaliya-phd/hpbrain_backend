# Stop everything dev.ps1 started (ports 8000-8004 and 5173).
foreach ($port in 8000, 8001, 8002, 8003, 8004, 5173) {
  $conns = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
  foreach ($c in $conns) {
    try {
      Stop-Process -Id $c.OwningProcess -Force -ErrorAction Stop
      Write-Host "stopped :$port (pid $($c.OwningProcess))"
    } catch {}
  }
}
