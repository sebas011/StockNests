# Local installation and verification

Use a disposable local database until the business owner confirms opening stock, spending authority and real approvers. This document gives the deployment contract for the delivered foundation; current runtime execution evidence and any unavailable command belong in the root README/handoff. Read the checked-in Compose file and `.env.example` before running commands if they differ from this guide.

## Prerequisites

- Docker Desktop with Compose v2 and a running Linux-container engine; adequate disk space for PostgreSQL, dependencies and images.
- Git. A compatible PHP 8.4+ runtime/Composer 2 and Node/npm are needed only for host-based development or checks outside the image.
- PostgreSQL 18 for authoritative integration/concurrency testing. A SQLite-only test run cannot verify PostgreSQL row-lock behavior.

Docker services are `app` (PHP/Apache, local port 8080), `db` (PostgreSQL 18), `redis` (Redis 8), `queue`, `scheduler`, and `mailpit` (local inbox, port 8025). The queue and scheduler use the same application image and configuration.

## First launch with Docker

Open a terminal in the project folder, initialize the environment and generate missing secrets, then build/start the development services:

```powershell
node scripts/initialize-env.mjs
docker compose up -d --build

docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan stocknest:admin --email=admin@example.test --name="StockNest Administrator"
```

`stocknest:admin` requests its password interactively. Use a unique secret; do not save it in scripts or documentation. Replace the email with the actual local administrator address. Never run `key:generate` over an existing deployed installation: changing `APP_KEY` can make encrypted values unreadable and invalidate authentication material. The initializer preserves existing secrets. Generate the shared host APP_KEY before creating containers, so the web app, queue and scheduler receive the same key.

Before starting, ensure `.env` uses `DB_CONNECTION=pgsql`, `DB_HOST=db`, database/user credentials matching Compose, `REDIS_HOST=redis`, `APP_URL=http://localhost:8080`, organization timezone `Asia/Manila`, and currency `PHP`. Development mail uses the `mailpit` host on its container SMTP port. Use actual variable names in the delivered environment template; administrator settings may override display currency/timezone after bootstrap.

Open [StockNest locally](http://localhost:8080) and [the development inbox](http://localhost:8025). Hostnames `db`, `redis` and `mailpit` resolve inside Compose; host-based tools use the published localhost ports configured in Compose. Do not configure host and container connections interchangeably.

Production seed data should create GAA/APPCSE, stable permissions/role bundles and safe defaults. It must not create fake expenses or a known default-password administrator.

## Optional demonstration data

Only on a disposable database configured with `APP_ENV=local` or `APP_ENV=testing`. Set a unique `DEMO_PASSWORD` of at least 12 characters with upper/lower case, a number and a symbol before seeding. The template defaults to production and the demo seeder refuses production execution:

```powershell
docker compose exec app php artisan db:seed --class=DemoSeeder
```

DemoSeeder must refuse production execution. Inspect its documented records before relying on names or workflow examples; a sample document is not an authorized production transaction. Recreate a disposable development database to reset demo ledgers; do not delete posted rows from a live database.

## Host-based development

Install PHP 8.4+ with `bcmath`, PostgreSQL PDO, mbstring, XML/DOM, fileinfo and the extensions required by the resolved Excel/PDF packages. Install Composer 2 and a Node release supported by the frontend lockfile/toolchain. Prefer locked dependency installation:

```powershell
composer install
npm ci
php artisan key:generate
php artisan migrate --seed
npm run build
composer run dev
```

Copy/configure `.env` first as above; set `APP_ENV=local` for development with hot reloading. Enable `APP_DEBUG=true` only on your private development machine. Hosted installations must retain production mode and debug disabled. With host PHP set database/Redis/mail hosts to their reachable localhost addresses and actual published ports. Do not assume Docker service names resolve on Windows. Use the checked-in lockfile with `npm ci`.

## Meaningful verification

Inspect the installed scripts for exact available checks. The intended baseline includes:

```powershell
docker compose exec app php artisan test
npm run types:check
npm run check
npm run test
npm run build
npm run test:e2e
```

Frontend commands require installed Node dependencies on the host or an appropriate build/test container. Playwright requires its supported browser installation and a configured reachable application/test database. Never point tests that migrate/reset data at a live database. Configure `.env.testing` to a separate PostgreSQL database and preserve test user separation. Record skipped/unavailable checks honestly.

For complete CI checks the delivered Composer scripts may also run formatter/static analysis. Required acceptance scenarios are in [acceptance.md](../acceptance.md); passing a build does not certify financial calculations, permission scopes, or concurrent stock safety.

## Troubleshooting

| Symptom                           | Check                                                                                                          |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Cannot connect to database        | Compose health/status, `.env` host/port/database credentials, cached config and container network              |
| Missing application key           | Generate once on new local install; preserve existing keys                                                     |
| New permission/role not visible   | Active account, correct guard, scope grants, permission cache invalidation and seed result                     |
| Notifications/export pending      | Queue worker configuration, failed job list, Redis connection, private storage permissions                     |
| Due tasks do not run              | Scheduler service is running; only one production scheduler/lock owner where tasks require singleton execution |
| Frontend assets unavailable       | Successful dependency install/build, built manifest in image, compatible Node version, published app URL       |
| PostgreSQL integrity test skipped | Verify real test database/runtime is available; do not substitute a claimed pass                               |
| Port 8080 or 8025 in use          | Change Compose published host port and corresponding app URL; keep internal service port unchanged             |

Stopping local containers normally preserves volumes. Commands that remove volumes or reset migrations erase development data and are not part of routine startup.
