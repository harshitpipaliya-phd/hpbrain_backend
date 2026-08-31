<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

/**
 * `php artisan serve`, but able to answer more than one request at a time.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY THIS OVERRIDES THE FRAMEWORK'S COMMAND
 *
 * PHP's built-in server cannot fork on Windows. It prints "forking is not
 * supported on this platform" and then handles exactly ONE request at a time,
 * which is fine for a form post and useless for this app: the workspace fires
 * roughly fifteen API calls on boot, they queue behind each other, and one slow
 * aggregate blocks every request behind it — including the session read that
 * login needs. The symptom is a browser sitting on "Stalled" with 0 bytes
 * transferred while the server is perfectly healthy and simply busy.
 *
 * `PHP_CLI_SERVER_WORKERS` is the documented fix and does not exist on Windows,
 * for the same reason: no fork.
 *
 * So this runs SEVERAL single-threaded servers and puts a round-robin balancer
 * in front of them on the port the frontend already targets. The developer
 * still types `php artisan serve`; it simply stops being a bottleneck.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY IT DOES NOT RECURSE
 *
 * The workers are started as `php -S host:port server.php` — the exact command
 * the framework's ServeCommand builds internally — and NOT as `php artisan
 * serve --port=…`. Shelling back into artisan would re-enter this class and each
 * worker would start four more of its own.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * IT DEGRADES RATHER THAN FAILS
 *
 * The balancer is a Node script. Where Node is absent, or a single worker was
 * asked for, this runs one plain PHP server on the requested port — exactly what
 * the framework's command does. Nobody is left without a server because the
 * concurrency layer was unavailable.
 */
final class ServeCommand extends Command
{
    /**
     * Named `serve` on purpose: it replaces the framework's command of the same
     * name in Artisan's registry, so the habit does not have to change.
     */
    protected $signature = 'serve
        {--host=127.0.0.1 : The host to serve on}
        {--port=8000 : The port the application answers on}
        {--workers=4 : How many PHP worker processes to run behind the balancer}
        {--no-reload : Accepted for compatibility; these workers never reload}';

    protected $description = 'Serve the application on the PHP development server, across several workers';

    /** @var array<int, Process> */
    private array $children = [];

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $workers = max(1, (int) $this->option('workers'));

        // Children are orphaned if this process dies any other way, so the
        // cleanup is registered before anything is started.
        $this->trapSignals();

        if (! $this->portsAreFree($host, $port, $workers)) {
            return self::FAILURE;
        }

        if ($workers === 1 || ! $this->hasNode()) {
            if ($workers > 1) {
                $this->warn('Node was not found, so the balancer cannot run. Serving on a single worker.');
                $this->warn('Requests will queue behind each other; install Node to get concurrency back.');
            }

            return $this->serveSingle($host, $port);
        }

        return $this->serveBalanced($host, $port, $workers);
    }

    /* ====================================================================== */

    private function serveSingle(string $host, int $port): int
    {
        $this->line('');
        $this->info("  Server running on http://{$host}:{$port}");
        $this->comment('  One worker — press Ctrl+C to stop.');
        $this->line('');

        $process = $this->phpServer($host, $port);
        $this->children[] = $process;

        $process->wait(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        return self::SUCCESS;
    }

    private function serveBalanced(string $host, int $port, int $workers): int
    {
        // The workers sit on the ports above the public one. The balancer owns
        // the public port, so the frontend's origin never changes.
        $workerPorts = [];

        for ($i = 1; $i <= $workers; $i++) {
            $workerPorts[] = $port + $i;
        }

        foreach ($workerPorts as $workerPort) {
            $this->children[] = $this->phpServer('127.0.0.1', $workerPort);
        }

        // Give the workers a moment to bind before the balancer starts routing
        // to them, so the first request does not meet a closed socket.
        usleep(900_000);

        $balancer = new Process(
            ['node', base_path('scripts/dev-balancer.mjs')],
            base_path(),
            [
                'BALANCER_PORT' => (string) $port,
                'BALANCER_WORKERS' => implode(',', $workerPorts),
            ] + $_ENV,
        );

        $balancer->setTimeout(null);
        $balancer->start();
        $this->children[] = $balancer;

        $this->line('');
        $this->info("  Server running on http://{$host}:{$port}");
        $this->comment('  Workers on '.implode(', ', $workerPorts).' behind a round-robin balancer.');
        $this->comment('  Frontend: run `npm run dev` in web/ — it serves on http://localhost:5173');
        $this->comment('  Press Ctrl+C to stop.');
        $this->line('');

        // The balancer is the process worth watching: if it exits, the public
        // port is dead and staying alive would be pretending otherwise.
        $balancer->wait(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        $this->stopChildren();

        return self::SUCCESS;
    }

    /**
     * One PHP development server, started exactly as the framework starts it.
     */
    private function phpServer(string $host, int $port): Process
    {
        $server = file_exists(base_path('server.php'))
            ? base_path('server.php')
            : base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');

        $process = new Process(
            [php_binary(), '-S', $host.':'.$port, $server],
            public_path(),
            // The server inherits the environment it was launched with, so an
            // APP_ENV or DB_* exported into this shell still reaches the app.
            $_ENV,
        );

        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    /**
     * Refuse to start on top of something already listening.
     *
     * WINDOWS DOES NOT STOP YOU BINDING A PORT TWICE. PHP's server does not set
     * SO_EXCLUSIVEADDRUSE, so a second process binds 8000 happily and the two
     * split the connections at random — and because Windows routes to the most
     * specific bind, a stray `127.0.0.1:8000` silently shadows a balancer on
     * `0.0.0.0:8000`. Every request then lands on a single-threaded server that
     * nobody knows is there, and the app looks broken for reasons no log
     * explains. This has already cost this project an outage.
     *
     * So the ports are checked first and a busy one is a hard stop with the
     * remedy, rather than a start that appears to work.
     */
    private function portsAreFree(string $host, int $port, int $workers): bool
    {
        $busy = [];

        foreach (range($port, $port + $workers) as $candidate) {
            $socket = @fsockopen($host === '0.0.0.0' ? '127.0.0.1' : $host, $candidate, $errno, $errstr, 0.4);

            if ($socket !== false) {
                fclose($socket);
                $busy[] = $candidate;
            }
        }

        if ($busy === []) {
            return true;
        }

        $this->error('  Something is already listening on: '.implode(', ', $busy));
        $this->line('');
        $this->line('  Starting anyway would bind the same ports twice — Windows permits it, and');
        $this->line('  requests would then split between the two servers at random.');
        $this->line('');
        $this->comment('  Stop the old one first:  .\dev-stop.ps1');
        $this->line('');

        return false;
    }

    private function hasNode(): bool
    {
        $probe = new Process(['node', '--version']);
        $probe->setTimeout(10);

        try {
            $probe->run();

            return $probe->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stop the children when this process does.
     *
     * Without this, Ctrl+C returns the prompt while four PHP servers keep
     * holding their ports, and the next `php artisan serve` fails to bind with
     * an error that says nothing about the cause.
     */
    private function trapSignals(): void
    {
        register_shutdown_function(fn () => $this->stopChildren());

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (): void {
                $this->stopChildren();
                exit(0);
            });

            return;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->stopChildren();
                    exit(0);
                });
            }
        }
    }

    private function stopChildren(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop(3);
            }
        }

        $this->children = [];
    }
}
