# StockNest

Budget-integrated inventory management for **GAA** and **APPCSE**, built with PHP 8.4+, Laravel 13, React 19, TypeScript, Inertia 3, Tailwind 4, shadcn/ui, PostgreSQL 18, Spatie Permission, Redis, Laravel Excel, Dompdf and Recharts.

**[Install on another device →](INSTALL.md)** Download using **Code → Download ZIP**, or clone `https://github.com/sebas011/StockNests.git`. The installation guide covers setup, administrator creation, updates and moving existing data.

**Development implementation — not approved for production use.** Screens and core workflows use actual database records. The full requested scope is broader than this first integrated implementation. See [implementation status](docs/implementation-status.md) for outstanding work and verification limits.

## Documentation

- [Architecture, business analysis, permissions and roadmap](docs/README.md)
- [Implemented database and ER diagram](docs/database-implemented.md)
- [Executed verification](docs/verification.md)
- [Complete original requirements](docs/MASTER-REQUIREMENTS.md)
- [Local setup](docs/operations/local-setup.md)
- [Deployment, backup and recovery](docs/operations/deployment-and-backup.md)

The proposed architecture/schema documents describe the complete target. They are not claims that every proposed feature is implemented.

## Implemented core

- Administrator-managed accounts, closed public registration, active-account enforcement, session revocation, password reset, email verification, and optional Fortify two-factor authentication/passkeys.
- Nine initial roles, configurable permission grants, explicit fund assignments, department scoping, requester ownership, server-side Policies and Form Requests. Global role changes require `roles.manage` and scope over every configured fund. Self approval is denied.
- Shared item catalog, categories, units, suppliers, warehouses, fiscal years and configurable fund profiles. GAA/APPCSE are seeded records, not separate code paths.
- Draft projections, submission/decisions, original approved baselines and separate revised projections. Allocation changes require independent authorization.
- Multi-item requests, ordered department/fund approval, unplanned-item exception comments, partial quantity approval, returns for revision and controlled cancellation.
- Purchase orders for approved requests, independent approval/commitments, partial goods receipts and explicit payment settlement that releases commitments without counting spending twice.
- Fund-owned stock balances and immutable movement records; reservations, partial issuance, returns and paired same-fund transfers sharing an immutable transfer document. Negative stock is rejected.
- Monthly cutoff counts, incomplete encoding/recounts, variance reasons, independent approval and separate reconciliation. Applying approved variance to the current balance preserves later transactions.
- Database-backed dashboard/workspace screens, scoped queries, search/filtering/pagination, record details/action forms, scheduled monthly projections versus actual spending, and original/revised/authorized/committed/spent/available budget figures.
- Ten core report families with CSV/Excel/PDF exports; queued workflow notifications, notification access rechecks, authentication activity, audit trails, transaction locking and bound idempotency keys.

**Budget availability = authorized allocation − outstanding commitments − actual spending.** Planning approval creates neither stock nor expenditure. Monetary arithmetic uses decimal strings/BCMath; database money uses two decimal places and quantities four. Inventory valuation is explicitly a catalog-cost estimate pending an agreed accounting valuation policy.

## Docker development setup

Install Docker Desktop with Linux containers and Node 24+. From this directory:

```powershell
node scripts/initialize-env.mjs
docker compose up -d --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan stocknest:admin --email=admin@example.test --name="StockNest Administrator"
```

The initializer generates missing local secrets, preserves existing keys and prints no secrets. Run it before creating containers so the web app, queue and scheduler share one `APP_KEY`. Do not rotate an existing key as a setup step. Administrator creation prompts privately for a password. Production seeds create no default-password user or sample financial/stock transactions.

Open [StockNest](http://localhost:8080) and [the local mail inbox](http://localhost:8025). Published development ports bind only to localhost. Configure HTTPS, production credentials, real mail, backups and operational controls before deployment.

### Optional demonstration data

Use a disposable local database. Set `STOCKNEST_DEMO=true` in `.env` and recreate containers, then run:

```powershell
docker compose exec app php artisan db:seed --class=DemoSeeder
```

The seeder refuses production execution and prints a newly generated local demo password. Accounts include `admin@stocknest.test`, `requester@stocknest.test`, `head@stocknest.test`, `approver@stocknest.test` and `custodian@stocknest.test`. Demo plans, allocations, orders, receipts and payments remain separately attributed to GAA/APPCSE. Never reuse demo accounts in production.

## Verification

```powershell
php vendor/bin/pest
npm run types:check
npm test
npm run build
```

The default backend suite uses isolated SQLite memory databases. It does **not** certify PostgreSQL locks or PostgreSQL-only triggers. Separate-process PostgreSQL concurrency tests are included and skip on SQLite. They require `APP_ENV=testing` and a dedicated database ending in `_test`; they deliberately reset that database. The GitHub workflow configures PostgreSQL 18 and PHP 8.4. Never point tests at production credentials.

Browser checks use `npm run test:e2e` after installing Chromium with `npx playwright install chromium` and starting the application. The multi-role request test requires an explicitly marked disposable demo workspace, `E2E_ALLOW_MUTATIONS=true` and `E2E_TEST_PASSWORD` containing its generated password. Both browser tests passed, including two approval stages and unchanged physical stock. [GitHub Actions](https://github.com/sebas011/StockNests/actions/workflows/tests.yml) also checks a fresh Docker build, startup, migrations and login-page response. See [verification](docs/verification.md) for exact results.

## Physical counting procedure

Encoded physical quantities must correspond to the session's displayed cutoff. Record movements during the manual count and reconcile observations back to that cutoff before encoding. The application applies only the approved variance; it never sets today's stock equal to a stale observation. Per-item observation-time capture and automatic cutoff roll-back are outstanding refinements requiring operational agreement before production use.

## Code organization

`app/Domain/WorkflowService.php` owns transactions and state transitions. Form Requests validate commands; Policies and `Access` constrain actors/objects. Eloquent models and migrations hold relational contracts. `WorkspaceController` supplies server-derived screen/form metadata to the reusable React workspace. Exports reuse scoped queries. PostgreSQL supplies additional constraints and immutable-ledger triggers.

Keep `.env`, `.tools/`, dependencies, databases, private uploads and secrets out of Git. The application began with the [official Laravel React starter kit](https://github.com/laravel/react-starter-kit).
