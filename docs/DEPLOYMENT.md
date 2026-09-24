# Deployment

## Prerequisites

PHP 8.2+ (`pdo_mysql`, `mbstring`, `openssl`, `json`), MySQL 8, Composer 2,
Node 20+ (frontend build only).

## Backend

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# set DB_*, JWT_SECRET (openssl rand -hex 32), CORS_ORIGIN, APP_DEBUG=false
php artisan migrate --force
php artisan db:seed --force        # first deploy only
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Serve `public/` via nginx or Apache. Never expose the project root — only
`public/`.

## Frontend

```bash
cd web
npm ci
npm run build          # emits web/dist
```

Serve `web/dist` as static files. Set the API base URL to the Laravel host at
build time. React stays React — it is not compiled into Blade.

## Against the company database

Migrations only ever create `hpbrain_`-prefixed tables, so they cannot collide
with ERP tables. **Never run `db:fixtures` against the company server** — those
fixtures create development stand-ins for tables that already exist there. The
loader refuses non-local hosts unless `ALLOW_REMOTE_FIXTURES=yes`.

## Checklist

- [ ] `APP_DEBUG=false`
- [ ] `JWT_SECRET` freshly generated, not the placeholder
- [ ] `CORS_ORIGIN` set to the real frontend origin, not `*`
- [ ] `DB_SSL=true` if MySQL is reachable over a public network
- [ ] Database user limited to the application schema
- [ ] `php artisan queue:work` supervised (systemd / supervisor)
- [ ] Backups scheduled and a restore actually rehearsed, not assumed
- [ ] `/health` monitored
- [ ] TLS terminated; `TrustProxies` configured if behind a load balancer

## Rollback

```bash
php artisan down
php artisan migrate:rollback --step=1
php artisan up
```

Rehearse rollback before you need it. The prior Node build listed a tested
restore as a pilot gate item and never satisfied it.
