# StockNest

Inventory and approved annual Supply allowance tracking for the **Office of the Vice President for Academic Affairs**, with separate **GAA** and **APPCSE** funds. Built with PHP 8.4+, Laravel 13, React 19, TypeScript, Inertia 3, Tailwind 4, shadcn/ui, PostgreSQL 18, Spatie Permission, Redis, Laravel Excel, Dompdf and Recharts.

**Windows PC: [download the ready-to-run ZIP](https://github.com/sebas011/StockNests/releases/latest/download/StockNest-Windows-x64.zip).** Extract the whole ZIP, then double-click **Start StockNest.cmd**. Your browser opens the first-administrator setup. Docker, Node.js and separate runtime installations are unnecessary. See the [Windows guide](docs/operations/portable-windows.md) for starting, stopping, backups and updates.

For the Docker option on Windows, macOS or Linux, use **Code → Download ZIP** or clone `https://github.com/sebas011/StockNests.git`, then follow [installation instructions](INSTALL.md). GitHub's source ZIP and the ready-to-run Windows release ZIP are different downloads.

Use StockNest after Supply approves your annual projection. Encode approved items, track requests and deliveries, record outside cash purchases, and view monthly item statistics. See the [office workflow guide](docs/office-workflow.md). Official form layouts are pending the office templates.

## Documentation

- [Architecture, business analysis, permissions and roadmap](docs/README.md)
- [Implemented database and ER diagram](docs/database-implemented.md)
- [Executed verification](docs/verification.md)
- [Current office workflow](docs/office-workflow.md)
- [Original institution-wide requirements (historical reference)](docs/MASTER-REQUIREMENTS.md)
- [Windows PC: download, extract and run](docs/operations/portable-windows.md)
- [Local setup](docs/operations/local-setup.md)
- [Deployment, backup and recovery](docs/operations/deployment-and-backup.md)

The proposed architecture/schema documents describe the complete target. They are not claims that every proposed feature is implemented.

## Implemented office workflow

- Fixed Office of the Vice President for Academic Affairs identity; separate GAA/APPCSE funds and role-based account access.
- Shared item catalog, suppliers, units, categories and fiscal years.
- Encode externally approved annual items and immutable amendments; show approved, requested, received and remaining quantities.
- Supply drafts consume no allowance. Submission deducts the quantities once; partial deliveries separately increase stock. Unreceived cancellation restores the allowance.
- Separate cash requests and externally authorized outside purchases, with budget commitments, receiving and actual payments. Outside quantities never consume the Supply allowance.
- Office stock consumption, immutable movement records, physical cutoff counts and explicit variance reconciliation.
- Monthly item statistics, annual allowance, budget and inventory reports with CSV/Excel/PDF exports; audit history and transaction retry protection.
- Administrator-managed accounts, private portable data, session revocation, password controls and scoped fund permissions.

**Supply remaining quantity = externally approved annual quantity − submitted, uncanceled Supply requests.** Receiving and office consumption do not deduct that allowance again.

**Budget availability = recorded authorized allocation − outstanding outside-purchase commitments − actual spending.** Money uses two decimal places and quantities four. Inventory valuation uses catalog standard cost.

The Approval Center and multi-department approval workflow are removed from the office interface. Earlier documents describe the original institutional proposal and remain historical references; [current scope](docs/office-workflow.md) takes precedence.

## Docker development setup

For everyday use on one Windows 10/11 x64 PC, the [portable Windows release](docs/operations/portable-windows.md) includes its runtimes and runs offline after download. Each PC has separate local records; copying the application alone does not transfer or synchronize inventory data. The steps below describe the separate Docker option.

Install Docker Desktop with Linux containers and Node 24.11 or newer in the Node 24 series. From this directory:

```powershell
node scripts/initialize-env.mjs
docker compose up -d --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan stocknest:admin --email=admin@example.test --name="StockNest Administrator"
```

The initializer generates missing local secrets, preserves existing keys and prints no secrets. Run it before creating containers so the web app, queue and scheduler share one `APP_KEY`. Do not rotate an existing key as a setup step. Administrator creation prompts privately for a password. Production seeds create no default-password user or sample financial/stock transactions.

Open [StockNest](http://localhost:8080) and [the local mail inbox](http://localhost:8025). Published development ports bind only to localhost. Configure HTTPS, production credentials, real mail, backups and operational controls before deployment.

## Verification

```powershell
php vendor/bin/pest
npm run types:check
npm test
npm run build
```

The default backend suite uses isolated SQLite memory databases. It does **not** certify PostgreSQL locks or PostgreSQL-only triggers. Separate-process PostgreSQL concurrency tests are included and skip on SQLite. They require `APP_ENV=testing` and a dedicated database ending in `_test`; they deliberately reset that database. The GitHub workflow configures PostgreSQL 18 and PHP 8.4. Never point tests at production credentials.

Browser checks use `npm run test:e2e` after installing Chromium with `npx playwright install chromium` and starting the application. The multi-role request test requires an explicitly marked disposable demo workspace, `E2E_ALLOW_MUTATIONS=true` and `E2E_TEST_PASSWORD` containing its generated password. The portable browser checks cover first-run setup, catalog entry, exports and persistence. Legacy multi-role browser tests require the historical institutional mode. [GitHub Actions](https://github.com/sebas011/StockNests/actions/workflows/tests.yml) also checks a fresh Docker build, startup, migrations and login-page response. See [verification](docs/verification.md) for exact results.

## Physical counting procedure

Encoded physical quantities must correspond to the session's displayed cutoff. Record movements during the manual count and reconcile observations back to that cutoff before encoding. The application applies only the approved variance; it never sets today's stock equal to a stale observation. Per-item observation-time capture and automatic cutoff roll-back are outstanding refinements requiring operational agreement before production use.

## Code organization

`app/Domain/WorkflowService.php` owns transactions and state transitions. Form Requests validate commands; Policies and `Access` constrain actors/objects. Eloquent models and migrations hold relational contracts. `WorkspaceController` supplies server-derived screen/form metadata to the reusable React workspace. Exports reuse scoped queries. PostgreSQL supplies additional constraints and immutable-ledger triggers.

Keep `.env`, `.tools/`, dependencies, databases, private uploads and secrets out of Git. The application began with the [official Laravel React starter kit](https://github.com/laravel/react-starter-kit).
