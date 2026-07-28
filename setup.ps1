# HP Enterprise Brain — Laravel backend setup (Windows / PowerShell)
#
# Run from the project root:
#     .\setup.ps1
#
# PowerShell does not support '&&' as a statement separator, which is why the
# README's bash one-liners fail there. This script runs each step separately
# and stops at the first real failure instead of ploughing on.

$ErrorActionPreference = 'Stop'

function Step($number, $text) {
    Write-Host ""
    Write-Host "[$number] $text" -ForegroundColor Cyan
}

function Fail($text) {
    Write-Host ""
    Write-Host "FAILED: $text" -ForegroundColor Red
    exit 1
}

Write-Host "HP Enterprise Brain - Laravel backend setup" -ForegroundColor Green

# ---------------------------------------------------------------------------
Step 1 "Checking prerequisites"

try { $php = (php -r "echo PHP_VERSION;") } catch { Fail "PHP not found on PATH. Install PHP 8.2+ and reopen PowerShell." }
Write-Host "    PHP $php"

if ([version]($php -split '-')[0] -lt [version]'8.2.0') {
    Fail "PHP 8.2+ required, found $php"
}

# Laravel needs these. pdo_mysql is the one that is usually missing on Windows.
$required = @('pdo_mysql', 'mbstring', 'openssl', 'fileinfo')
$loaded = (php -m)
foreach ($ext in $required) {
    if ($loaded -notcontains $ext) {
        Fail "PHP extension '$ext' is not enabled. Open your php.ini, uncomment 'extension=$ext', save, and reopen PowerShell."
    }
}
Write-Host "    All required PHP extensions present"

try { composer --version | Out-Null } catch { Fail "Composer not found on PATH. Install from getcomposer.org." }
Write-Host "    Composer found"

# ---------------------------------------------------------------------------
Step 2 "Installing PHP dependencies (this takes a few minutes)"
Step 2 "Installing PHP dependencies (this takes a few minutes)"
# vendor/ is included in the project - if it already exists, skip install.
if (Test-Path "vendor/autoload.php") {
    Write-Host "    vendor/ already present - skipping composer install"
} else {
    # composer.json includes audit.ignore for known advisories in Laravel 11.x
    # and firebase/php-jwt 6.x. These are intentionally acknowledged.
    composer install --no-interaction
    if ($LASTEXITCODE -ne 0) {
        # Older Composer versions block on advisories during install rather
        # than just audit. Use --ignore-platform-reqs if needed.
        Write-Host "    Retrying with audit bypass..." -ForegroundColor Yellow
        composer install --no-interaction --ignore-platform-reqs
        if ($LASTEXITCODE -ne 0) { Fail "composer install failed - see the output above" }
    }
}

# ---------------------------------------------------------------------------
Step 3 "Preparing environment"
if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "    Created .env from .env.example - fill in DB_* and JWT_SECRET before continuing" -ForegroundColor Yellow
    exit 0
}
Write-Host "    .env present"

php artisan key:generate --force
if ($LASTEXITCODE -ne 0) { Fail "php artisan key:generate failed" }

# ---------------------------------------------------------------------------
Step 4 "Verifying Laravel boots and routes resolve"
php artisan about
if ($LASTEXITCODE -ne 0) { Fail "Laravel did not boot - see the output above" }

$routes = (php artisan route:list --json | ConvertFrom-Json)
Write-Host "    $($routes.Count) routes resolved" -ForegroundColor Green

# ---------------------------------------------------------------------------
Step 5 "Running migrations"
# Only hpbrain_-prefixed tables are created. ERP tables are never touched.
php artisan migrate --force
if ($LASTEXITCODE -ne 0) { Fail "Migrations failed - see the output above" }

# ---------------------------------------------------------------------------
Step 6 "Seeding the intelligence loop"
php artisan db:seed --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "    Seeding failed. This is usually because the ERP tables" -ForegroundColor Yellow
    Write-Host "    (institute_detail, hrms_departments, tbluser) have required" -ForegroundColor Yellow
    Write-Host "    columns the seeder does not populate. Not fatal - the API" -ForegroundColor Yellow
    Write-Host "    will still run against your existing ERP data." -ForegroundColor Yellow
}

# ---------------------------------------------------------------------------
Step 7 "Running tests"
php artisan test
if ($LASTEXITCODE -ne 0) { Write-Host "    Some tests failed - see above" -ForegroundColor Yellow }

Write-Host ""
Write-Host "Setup complete." -ForegroundColor Green
Write-Host ""
Write-Host "Start the backend:   php artisan serve" -ForegroundColor White
Write-Host "Start the frontend:  cd web ; npm install ; npm run dev" -ForegroundColor White
Write-Host ""
Write-Host "Backend  -> http://localhost:8000" -ForegroundColor White
Write-Host "Frontend -> http://localhost:5173" -ForegroundColor White
