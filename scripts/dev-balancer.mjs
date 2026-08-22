// Round-robin balancer for local dev.
//
// PHP's built-in server (what `php artisan serve` uses) cannot fork on Windows
// -- it prints "forking is not supported on this platform" and handles exactly
// one request at a time. The dashboard fires ~15 API calls on boot, so they
// serialize and the app never finishes loading.
//
// Fix: run several `artisan serve` workers on 8001+ and spread requests across
// them from the single origin (8000) the frontend already targets.
import http from 'node:http';

const PORT = Number(process.env.BALANCER_PORT || 8000);
const WORKERS = (process.env.BALANCER_WORKERS || '8001,8002,8003,8004')
  .split(',')
  .map((p) => Number(p.trim()))
  .filter(Boolean);

let next = 0;

const server = http.createServer((req, res) => {
  const port = WORKERS[next++ % WORKERS.length];

  const upstream = http.request(
    { host: '127.0.0.1', port, path: req.url, method: req.method, headers: req.headers },
    (up) => {
      res.writeHead(up.statusCode || 502, up.headers);
      up.pipe(res);
    },
  );

  upstream.on('error', (err) => {
    if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: `dev balancer: worker :${port} unreachable (${err.code})` }));
  });

  req.pipe(upstream);
});

// Listen on 0.0.0.0 so both 127.0.0.1 and localhost (IPv4 or IPv6) resolve.
server.listen(PORT, () => {
  console.log(`dev balancer :${PORT} -> workers ${WORKERS.join(', ')}`);
});
